<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\ReviewRepository;
use App\Support\ReputationAction;

/**
 * Reviews and ratings, and the rules about them:
 *
 *  - one review per person per book, editable afterwards
 *  - you cannot mark your own review helpful
 *  - hiding a review is a moderator's job and needs a reason; the person who
 *    wrote it still sees it, so they know what happened
 */
final class ReviewService
{
    public function __construct(
        private readonly ReviewRepository $reviews,
        private readonly ReputationService $reputation,
        private readonly NotificationService $notifications,
        private readonly AuditLogRepository $audit,
        private readonly Gate $gate,
    ) {
    }

    /** @return array{ok: bool, message: string} */
    public function submit(Book $book, User $user, int $rating, ?string $body): array
    {
        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'message' => 'A rating runs from one star to five.'];
        }

        if (!$this->gate->canContribute()) {
            return ['ok' => false, 'message' => 'Confirm your email address before writing a review.'];
        }

        $existing = $this->reviews->findByUserAndBook($user->id, $book->id);

        if ($existing !== null) {
            $this->reviews->update((int) $existing['id'], $rating, $body);
            $this->reviews->refreshBookRating($book->id);

            return ['ok' => true, 'message' => 'Your review has been updated.'];
        }

        $id = $this->reviews->create($book->id, $user->id, $rating, $body);
        $this->reviews->refreshBookRating($book->id);

        // Points for the first version only: editing is not a second review.
        $this->reputation->award($user->id, ReputationAction::ReviewWritten, 'review', $id);

        return ['ok' => true, 'message' => 'Thank you. Your review is on the book now.'];
    }

    /** @return array{ok: bool, message: string} */
    public function remove(int $reviewId, User $user): array
    {
        $review = $this->reviews->find($reviewId);

        if ($review === null) {
            return ['ok' => false, 'message' => 'No such review.'];
        }

        if ((int) $review['user_id'] !== $user->id) {
            return ['ok' => false, 'message' => 'That is not your review.'];
        }

        $this->reviews->delete($reviewId);
        $this->reviews->refreshBookRating((int) $review['book_id']);
        $this->reputation->revoke($user->id, ReputationAction::ReviewWritten, 'review', $reviewId);

        return ['ok' => true, 'message' => 'Review removed.'];
    }

    /** @return array{ok: bool, message: string} */
    public function vote(int $reviewId, User $user): array
    {
        $review = $this->reviews->find($reviewId);

        if ($review === null) {
            return ['ok' => false, 'message' => 'No such review.'];
        }

        if ((int) $review['user_id'] === $user->id) {
            return ['ok' => false, 'message' => 'You cannot vote for your own review.'];
        }

        if ((string) $review['status'] !== 'visible') {
            return ['ok' => false, 'message' => 'That review is not visible.'];
        }

        $added = $this->reviews->toggleVote($reviewId, $user->id);

        // Once per voter per review: toggling the vote off and on again does
        // not pay the author twice.
        if ($added) {
            $this->reputation->award(
                (int) $review['user_id'],
                ReputationAction::ReviewHelpful,
                'review_vote:' . $user->id,
                $reviewId
            );
        }

        return ['ok' => true, 'message' => $added ? 'Marked as helpful.' : 'Vote taken back.'];
    }

    /**
     * Hide or restore a review. Hidden means still there for its author and for
     * moderators, and out of the rating: a review nobody else can see should not
     * be moving the average.
     *
     * @return array{ok: bool, message: string}
     */
    public function moderate(int $reviewId, string $status, string $reason, User $moderator): array
    {
        if ($this->gate->denies('review.moderate')) {
            return ['ok' => false, 'message' => 'That is a librarian\'s job.'];
        }

        if (!in_array($status, ['visible', 'hidden', 'removed'], true)) {
            return ['ok' => false, 'message' => 'That is not a status.'];
        }

        $review = $this->reviews->find($reviewId);

        if ($review === null) {
            return ['ok' => false, 'message' => 'No such review.'];
        }

        if ($status !== 'visible' && trim($reason) === '') {
            return ['ok' => false, 'message' => 'Say why. The person who wrote it sees the reason.'];
        }

        $this->reviews->setStatus($reviewId, $status, $status === 'visible' ? null : trim($reason));
        $this->reviews->refreshBookRating((int) $review['book_id']);

        $this->audit->record(
            $moderator->id,
            'review.moderated',
            'review',
            $reviewId,
            ['status' => $review['status']],
            ['status' => $status, 'reason' => $reason]
        );

        if ($status !== 'visible') {
            $this->notifications->send(
                (int) $review['user_id'],
                'review.hidden',
                'A review of yours was hidden',
                trim($reason),
                null
            );
        }

        return ['ok' => true, 'message' => 'Review is now ' . $status . '.'];
    }
}
