<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\BookRequest;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\BookRequestRepository;
use App\Repositories\UserRepository;
use App\Support\RequestStatus;

/**
 * Book requests: asking for something the library does not have, and the rules
 * about who may do what to the ask afterwards.
 */
final class BookRequestService
{
    private const REPUTATION_FOR_FULFILMENT = 10;

    public function __construct(
        private readonly BookRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly NotificationService $notifications,
        private readonly Gate $gate,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, User $requester): BookRequest
    {
        $id = $this->requests->create([
            'title'        => mb_substr(trim((string) ($input['title'] ?? '')), 0, 255),
            'author'       => $this->nullable($input['author'] ?? null, 160),
            'isbn'         => $this->isbn($input['isbn'] ?? null),
            'note'         => $this->nullable($input['note'] ?? null, 1000),
            'language'     => $this->language($input['language'] ?? null),
            'requester_id' => $requester->id,
            'status'       => RequestStatus::Open->value,
        ]);

        // Asking for a book is a vote for it; making them click again would only
        // make the demand ordering wrong.
        $this->requests->addVote($id, $requester->id);

        $this->audit->record($requester->id, 'request.created', 'book_request', $id, null, [
            'title' => $input['title'] ?? '',
        ]);

        $request = $this->requests->findById($id, $requester->id);

        if ($request === null) {
            throw new \RuntimeException('The request was created but could not be read back.');
        }

        return $request;
    }

    public function toggleVote(BookRequest $request, User $user): bool
    {
        return $this->requests->toggleVote($request->id, $user->id);
    }

    /** @return array{ok: bool, message: string} */
    public function claim(BookRequest $request, User $user): array
    {
        if ($request->status !== RequestStatus::Open) {
            return ['ok' => false, 'message' => 'That request is not open.'];
        }

        $this->requests->update($request->id, [
            'status'     => RequestStatus::Claimed->value,
            'claimed_by' => $user->id,
            'claimed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->audit->record($user->id, 'request.claimed', 'book_request', $request->id);

        $this->notifications->send(
            $request->requesterId === $user->id ? null : $request->requesterId,
            'request.claimed',
            $user->username . ' is looking for ' . $request->title,
            null,
            '/requests/' . $request->id
        );

        return ['ok' => true, 'message' => 'Yours to find. Say so on the request if you get stuck.'];
    }

    /** @return array{ok: bool, message: string} */
    public function release(BookRequest $request, User $user): array
    {
        if ($request->status !== RequestStatus::Claimed) {
            return ['ok' => false, 'message' => 'That request is not claimed.'];
        }

        if (!$request->isClaimedBy($user->id) && $this->gate->denies('request.close')) {
            return ['ok' => false, 'message' => 'Someone else claimed that one.'];
        }

        $this->requests->update($request->id, [
            'status'     => RequestStatus::Open->value,
            'claimed_by' => null,
            'claimed_at' => null,
        ]);

        return ['ok' => true, 'message' => 'Back in the open list.'];
    }

    /**
     * Ends a request without a book: not available anywhere, already here, or
     * not something this library wants.
     *
     * @return array{ok: bool, message: string}
     */
    public function close(BookRequest $request, RequestStatus $status, string $reason, User $user): array
    {
        if (!in_array($status, RequestStatus::closable(), true)) {
            return ['ok' => false, 'message' => 'That is not an ending a person can choose.'];
        }

        if (!$request->status->isOpen()) {
            return ['ok' => false, 'message' => 'That request is already closed.'];
        }

        $isRequester = $request->requesterId === $user->id;

        if (!$isRequester && $this->gate->denies('request.close')) {
            return ['ok' => false, 'message' => 'Only the person who asked, or a librarian, can close it.'];
        }

        $this->requests->update($request->id, [
            'status'       => $status->value,
            'closed_by'    => $user->id,
            'closed_at'    => gmdate('Y-m-d H:i:s'),
            'close_reason' => $reason === '' ? null : mb_substr($reason, 0, 255),
        ]);

        $this->audit->record($user->id, 'request.closed', 'book_request', $request->id, [
            'status' => $request->status->value,
        ], ['status' => $status->value, 'reason' => $reason]);

        if (!$isRequester) {
            $this->notifications->send(
                $request->requesterId,
                'request.closed',
                'Closed: ' . $request->title,
                $status->label() . ($reason === '' ? '' : ' - ' . $reason),
                '/requests/' . $request->id
            );
        }

        return ['ok' => true, 'message' => 'Closed as ' . mb_strtolower($status->label()) . '.'];
    }

    /** @return array{ok: bool, message: string} */
    public function reopen(BookRequest $request, User $user): array
    {
        if ($request->status->isOpen()) {
            return ['ok' => false, 'message' => 'That request is already open.'];
        }

        if ($request->status === RequestStatus::Fulfilled) {
            return ['ok' => false, 'message' => 'That one was answered with a book.'];
        }

        if ($this->gate->denies('request.close')) {
            return ['ok' => false, 'message' => 'Only a librarian can reopen a request.'];
        }

        $this->requests->update($request->id, [
            'status'       => RequestStatus::Open->value,
            'closed_by'    => null,
            'closed_at'    => null,
            'close_reason' => null,
        ]);

        return ['ok' => true, 'message' => 'Open again.'];
    }

    /**
     * A book has arrived that answers this request. Called when an upload is
     * approved, and when a librarian links a record by hand.
     *
     * Everyone who voted hears about it, which is the reason the votes exist.
     */
    public function fulfil(int $requestId, Book $book, ?User $actor): bool
    {
        $request = $this->requests->findById($requestId);

        if ($request === null || !$request->status->isOpen()) {
            return false;
        }

        $this->requests->update($requestId, [
            'status'               => RequestStatus::Fulfilled->value,
            'fulfilled_by_book_id' => $book->id,
            'closed_by'            => $actor?->id,
            'closed_at'            => gmdate('Y-m-d H:i:s'),
        ]);

        $this->audit->record($actor?->id, 'request.fulfilled', 'book_request', $requestId, null, [
            'book_id' => $book->id,
        ]);

        foreach ($this->requests->voterIds($requestId) as $voterId) {
            $this->notifications->send(
                $voterId,
                'request.fulfilled',
                'It is here: ' . $book->title,
                'The book you asked for has been added to the library.',
                '/books/' . $book->slug
            );
        }

        if ($actor !== null && $actor->id !== $request->requesterId) {
            $this->users->addReputation($actor->id, self::REPUTATION_FOR_FULFILMENT);
        }

        return true;
    }

    private function nullable(mixed $value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function isbn(mixed $value): ?string
    {
        $isbn = preg_replace('/[^0-9Xx]/', '', (string) ($value ?? '')) ?? '';

        return in_array(strlen($isbn), [10, 13], true) ? strtoupper($isbn) : null;
    }

    private function language(mixed $value): string
    {
        $language = strtolower(trim((string) ($value ?? '')));

        return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language) === 1 ? $language : 'en';
    }
}
