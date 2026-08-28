<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Storage;
use App\Models\Book;
use App\Models\BookFile;
use App\Models\ModerationRequest;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\UserRepository;
use App\Support\BookStatus;
use App\Support\ModerationStatus;
use App\Support\ModerationType;
use App\Support\RejectionReason;
use App\Support\ReputationAction;

/**
 * The approval engine: one state machine for every kind of submission.
 *
 * Rules that keep the queue honest, all enforced here rather than in the
 * controllers:
 *
 *  - a reviewer cannot decide their own submission unless they are an admin,
 *    and when an admin does it the audit entry says so
 *  - claiming locks an item for 30 minutes so two librarians do not review the
 *    same thing, and the lock expires so an abandoned review does not stick
 *  - a rejection needs a reason
 *  - every transition writes an event and notifies the submitter
 */
final class ModerationService
{
    public const CLAIM_SECONDS = 1800;


    public function __construct(
        private readonly ModerationRepository $requests,
        private readonly BookRepository $books,
        private readonly BookFileRepository $files,
        private readonly CategoryRepository $categories,
        private readonly CollectionRepository $collectionRepository,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly NotificationService $notifications,
        private readonly ReputationService $reputation,
        private readonly BookRequestService $bookRequests,
        private readonly CollectionService $collections,
        private readonly Storage $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function open(
        ModerationType $type,
        ?int $subjectId,
        string $title,
        array $payload,
        ?User $submitter,
    ): int {
        $id = $this->requests->create([
            'subject_type' => $type->value,
            'subject_id'   => $subjectId,
            'submitter_id' => $submitter?->id,
            'status'       => ModerationStatus::Pending->value,
            'title'        => mb_substr($title, 0, 255),
            'payload'      => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $this->requests->addEvent($id, $submitter?->id, null, ModerationStatus::Pending->value, 'Submitted');
        $this->audit->record($submitter?->id, 'moderation.opened', 'moderation_request', $id, null, [
            'type'  => $type->value,
            'title' => $title,
        ]);

        return $id;
    }

    /**
     * A file has just been stored in quarantine. Someone with `book.publish`
     * puts it straight into the library; anyone else gets a queue entry.
     *
     * @return int|null the request id, or null when it was published outright
     */
    public function submitUpload(
        Book $book,
        BookFile $file,
        User $uploader,
        bool $publishNow,
        ?int $requestId = null,
    ): ?int {
        if ($publishNow) {
            $this->publishFile($file);

            if (!$book->status->isPublic()) {
                $this->books->setStatus($book->id, BookStatus::Published);
                $this->books->refreshCounters();
            }

            $this->audit->record($uploader->id, 'book.file_published', 'book', $book->id, null, [
                'file_id' => $file->id,
                'format'  => $file->format,
            ]);

            if ($requestId !== null) {
                $this->bookRequests->fulfil($requestId, $book, $uploader);
            }

            return null;
        }

        return $this->open(
            ModerationType::BookUpload,
            $book->id,
            $book->title . ' (' . strtoupper($file->format) . ')',
            [
                'file_id'       => $file->id,
                'size_bytes'    => $file->sizeBytes,
                'original_name' => $file->originalName,
                'sha256'        => $file->sha256,
                'request_id'    => $requestId,
            ],
            $uploader,
        );
    }

    /**
     * A record with no file yet: the metadata still has to be reviewed before it
     * appears in the catalogue, and without a queue item nothing would point a
     * librarian at it.
     */
    public function submitRecord(Book $book, User $submitter, ?int $requestId = null): int
    {
        return $this->open(
            ModerationType::BookRecord,
            $book->id,
            $book->title,
            ['request_id' => $requestId],
            $submitter,
        );
    }

    /** @return bool false when someone else holds a live claim */
    public function claim(ModerationRequest $request, User $reviewer): bool
    {
        if ($request->isClaimed() && !$request->isClaimedBy($reviewer->id)) {
            return false;
        }

        if (!$this->requests->claim($request->id, $reviewer->id, self::CLAIM_SECONDS)) {
            return false;
        }

        if ($request->status !== ModerationStatus::UnderReview) {
            $this->requests->addEvent(
                $request->id,
                $reviewer->id,
                $request->status->value,
                ModerationStatus::UnderReview->value,
                'Claimed for review'
            );
        }

        return true;
    }

    public function release(ModerationRequest $request, User $reviewer): void
    {
        $this->requests->update($request->id, [
            'assignee_id'   => null,
            'claimed_until' => null,
            'status'        => ModerationStatus::Pending->value,
        ]);

        $this->requests->addEvent(
            $request->id,
            $reviewer->id,
            $request->status->value,
            ModerationStatus::Pending->value,
            'Returned to the queue'
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function decide(ModerationRequest $request, bool $approve, string $reason, User $reviewer): array
    {
        if ($request->status->isFinal()) {
            return ['ok' => false, 'message' => 'That has already been decided.'];
        }

        if ($request->isClaimed() && !$request->isClaimedBy($reviewer->id)) {
            return ['ok' => false, 'message' => 'Someone else is reviewing that right now.'];
        }

        $isOwn = $request->submitterId !== null && $request->submitterId === $reviewer->id;

        if ($isOwn && !$reviewer->isAdmin()) {
            return ['ok' => false, 'message' => 'You cannot decide on your own submission.'];
        }

        $reason = trim($reason);

        if (!$approve && $reason === '') {
            return ['ok' => false, 'message' => 'A rejection needs a reason.'];
        }

        $next = $approve ? ModerationStatus::Approved : ModerationStatus::Rejected;
        $this->apply($request, $approve, $reason, $reviewer);

        $this->requests->update($request->id, [
            'status'        => $next->value,
            'reason'        => $reason === '' ? null : mb_substr($reason, 0, 255),
            'decided_at'    => gmdate('Y-m-d H:i:s'),
            'decided_by'    => $reviewer->id,
            'assignee_id'   => $reviewer->id,
            'claimed_until' => null,
        ]);

        $this->requests->addEvent($request->id, $reviewer->id, $request->status->value, $next->value, $reason);

        // Reviewing is work, and the queue only moves if someone does it.
        $this->reputation->award(
            $reviewer->id,
            ReputationAction::ModerationDecided,
            'moderation_request',
            $request->id
        );

        $this->audit->record(
            $reviewer->id,
            $approve ? 'moderation.approved' : 'moderation.rejected',
            'moderation_request',
            $request->id,
            ['status' => $request->status->value],
            ['status' => $next->value, 'reason' => $reason, 'own_submission' => $isOwn]
        );

        $this->notifications->send(
            $request->submitterId,
            $approve ? 'moderation.approved' : 'moderation.rejected',
            $approve
                ? 'Approved: ' . $request->title
                : 'Not accepted: ' . $request->title,
            $reason === '' ? null : $reason,
            $this->subjectUrl($request)
        );

        return ['ok' => true, 'message' => $approve ? 'Approved.' : 'Rejected.'];
    }

    /**
     * Sends it back to the submitter instead of refusing it outright. The
     * request stays open and can be resubmitted.
     *
     * @return array{ok: bool, message: string}
     */
    public function requestChanges(ModerationRequest $request, string $note, User $reviewer): array
    {
        if ($request->status->isFinal()) {
            return ['ok' => false, 'message' => 'That has already been decided.'];
        }

        if (trim($note) === '') {
            return ['ok' => false, 'message' => 'Say what needs changing.'];
        }

        $this->requests->update($request->id, [
            'status'        => ModerationStatus::ChangesRequested->value,
            'reason'        => mb_substr(trim($note), 0, 255),
            'claimed_until' => null,
        ]);

        $this->requests->addEvent(
            $request->id,
            $reviewer->id,
            $request->status->value,
            ModerationStatus::ChangesRequested->value,
            $note
        );

        $this->notifications->send(
            $request->submitterId,
            'moderation.changes_requested',
            'Changes needed: ' . $request->title,
            $note,
            '/me/submissions'
        );

        return ['ok' => true, 'message' => 'Sent back to the submitter.'];
    }

    /** @return array{ok: bool, message: string} */
    public function resubmit(ModerationRequest $request, User $submitter): array
    {
        if ($request->submitterId !== $submitter->id) {
            return ['ok' => false, 'message' => 'That is not your submission.'];
        }

        if (!$request->status->canMoveTo(ModerationStatus::Pending)) {
            return ['ok' => false, 'message' => 'That cannot be resubmitted.'];
        }

        $this->requests->update($request->id, [
            'status'        => ModerationStatus::Pending->value,
            'assignee_id'   => null,
            'claimed_until' => null,
        ]);

        $this->requests->addEvent(
            $request->id,
            $submitter->id,
            $request->status->value,
            ModerationStatus::Pending->value,
            'Resubmitted'
        );

        return ['ok' => true, 'message' => 'Back in the queue.'];
    }

    /** @return array{ok: bool, message: string} */
    public function withdraw(ModerationRequest $request, User $submitter): array
    {
        if ($request->submitterId !== $submitter->id) {
            return ['ok' => false, 'message' => 'That is not your submission.'];
        }

        if (!$request->status->canMoveTo(ModerationStatus::Withdrawn)) {
            return ['ok' => false, 'message' => 'That cannot be withdrawn now.'];
        }

        $this->apply($request, false, 'Withdrawn by the submitter', $submitter);

        $this->requests->update($request->id, [
            'status'        => ModerationStatus::Withdrawn->value,
            'decided_at'    => gmdate('Y-m-d H:i:s'),
            'claimed_until' => null,
        ]);

        $this->requests->addEvent(
            $request->id,
            $submitter->id,
            $request->status->value,
            ModerationStatus::Withdrawn->value,
            'Withdrawn'
        );

        return ['ok' => true, 'message' => 'Withdrawn.'];
    }

    public function comment(ModerationRequest $request, string $body, User $author): void
    {
        $body = trim($body);

        if ($body === '') {
            return;
        }

        $this->requests->addComment($request->id, $author->id, $body);

        // Tell the other party, whichever side wrote it.
        $recipient = $author->id === $request->submitterId ? $request->assigneeId : $request->submitterId;

        $this->notifications->send(
            $recipient,
            'moderation.comment',
            'New comment on ' . $request->title,
            mb_substr($body, 0, 200),
            '/librarian/queue/' . $request->id
        );
    }

    /**
     * What approval or rejection actually does to the subject.
     */
    private function apply(ModerationRequest $request, bool $approve, string $reason, User $actor): void
    {
        match ($request->type) {
            ModerationType::BookUpload       => $this->applyBookUpload($request, $approve, $reason),
            ModerationType::BookRecord       => $this->applyBookRecord($request, $approve),
            ModerationType::CategoryProposal => $this->applyCategoryProposal($request, $approve),
            ModerationType::CollectionPublish => $this->applyCollectionPublish($request, $approve),
        };
    }

    private function applyBookUpload(ModerationRequest $request, bool $approve, string $reason): void
    {
        $fileId = (int) ($request->payload['file_id'] ?? 0);
        $file = $fileId > 0 ? $this->files->findById($fileId) : null;

        if ($file === null) {
            return;
        }

        if (!$approve) {
            $this->files->setStatus($file->id, 'rejected');

            if (RejectionReason::isCopyright($reason) && $file->uploadedBy !== null) {
                $this->users->addStrike($file->uploadedBy);
            }

            return;
        }

        $this->publishFile($file);

        $book = $request->subjectId === null ? null : $this->books->findById($request->subjectId);

        if ($book === null) {
            return;
        }

        if (!$book->status->isPublic()) {
            $this->books->setStatus($book->id, BookStatus::Published);
            $this->books->refreshCounters();
        }

        $this->fulfilLinkedRequest($request, $book);
    }

    /**
     * Hard link out of quarantine into the library, point the row at the new
     * path, and charge the bytes to the uploader's quota.
     */
    private function publishFile(BookFile $file): void
    {
        $published = $this->storage->publish($file->storagePath, $file->sha256, $file->format);
        $this->files->setPath($file->id, $published);
        $this->files->setStatus($file->id, 'published');

        if ($file->uploadedBy !== null) {
            $this->users->addStorageUsed($file->uploadedBy, $file->sizeBytes);
            $this->reputation->award(
                $file->uploadedBy,
                ReputationAction::UploadAccepted,
                'book_file',
                $file->id
            );
        }
    }

    private function applyBookRecord(ModerationRequest $request, bool $approve): void
    {
        $book = $request->subjectId === null ? null : $this->books->findById($request->subjectId);

        if ($book === null) {
            return;
        }

        if (!$approve) {
            $this->books->setStatus($book->id, BookStatus::Rejected);

            return;
        }

        $this->books->setStatus($book->id, BookStatus::Published);
        $this->books->refreshCounters();
        $this->fulfilLinkedRequest($request, $book);
    }

    /**
     * The submission was offered against a book request: answering it is part of
     * the approval, not a second thing someone has to remember to do.
     */
    private function fulfilLinkedRequest(ModerationRequest $request, Book $book): void
    {
        $requestId = (int) ($request->payload['request_id'] ?? 0);

        if ($requestId > 0 && $request->submitterId !== null) {
            $this->bookRequests->fulfil($requestId, $book, $this->users->findById($request->submitterId));
        }
    }

    private function applyCollectionPublish(ModerationRequest $request, bool $approve): void
    {
        if ($request->subjectId === null) {
            return;
        }

        $this->collections->applyPublicationDecision($request->subjectId, $approve);

        if ($approve) {
            $this->reputation->award(
                $request->submitterId,
                ReputationAction::CollectionPublished,
                'collection',
                $request->subjectId
            );
        }
    }

    private function applyCategoryProposal(ModerationRequest $request, bool $approve): void
    {
        if ($request->subjectId === null) {
            return;
        }

        if ($approve) {
            $this->categories->setStatus($request->subjectId, 'active');

            return;
        }

        $category = $this->categories->findById($request->subjectId);

        // Only remove it if it is still a proposal: an approved category that is
        // later rejected by a second reviewer should not take its books with it.
        if ($category !== null && $category->status === 'pending') {
            $this->categories->delete($request->subjectId);
        }
    }

    private function subjectUrl(ModerationRequest $request): ?string
    {
        if ($request->type === ModerationType::CollectionPublish && $request->subjectId !== null) {
            $collection = $this->collectionRepository->findById($request->subjectId);

            return $collection === null ? null : '/collections/' . $collection->relativePath();
        }

        if ($request->type === ModerationType::BookUpload && $request->subjectId !== null) {
            $book = $this->books->findById($request->subjectId);

            return $book === null ? null : '/books/' . $book->slug;
        }

        return '/me/submissions';
    }
}
