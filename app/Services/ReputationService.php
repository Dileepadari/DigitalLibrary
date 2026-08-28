<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BadgeRepository;
use App\Repositories\ReputationRepository;
use App\Repositories\UserRepository;
use App\Support\ReputationAction;

/**
 * Reputation and the badges that come with it.
 *
 * Everything that pays points goes through award(): the running total on the
 * user row is a cache, and `reputation_events` is the record it is made of. A
 * badge is then just a count of events of one kind, so adding one is a row in
 * the badges table rather than code.
 */
final class ReputationService
{
    public function __construct(
        private readonly ReputationRepository $events,
        private readonly BadgeRepository $badges,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param string|null $subjectType with $subjectId, makes the award idempotent:
     *                                 approving the same upload twice pays once
     */
    public function award(
        ?int $userId,
        ReputationAction $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        if ($userId === null) {
            return;
        }

        $repeat = $subjectType !== null
            && $subjectId !== null
            && $this->events->alreadyRecorded($userId, $action, $subjectType, $subjectId);

        if ($repeat) {
            return;
        }

        $this->events->record($userId, $action, $action->points(), $subjectType, $subjectId);
        $this->users->addReputation($userId, $action->points());
        $this->awardBadgesFor($userId, $action);
    }

    /**
     * Takes points back, for something that was undone. The event is recorded
     * with negative points rather than deleted: the history is the point.
     */
    public function revoke(?int $userId, ReputationAction $action, ?string $subjectType, ?int $subjectId): void
    {
        if ($userId === null) {
            return;
        }

        $this->events->record($userId, $action, -$action->points(), $subjectType, $subjectId);
        $this->users->addReputation($userId, -$action->points());
    }

    /** @return list<array{key: string, name: string, description: string, awarded_at: string}> */
    public function badgesFor(int $userId): array
    {
        return $this->badges->forUser($userId);
    }

    /** @return array<string, int> */
    public function tallyFor(int $userId): array
    {
        return $this->events->tallyFor($userId);
    }

    /** @return list<array<string, mixed>> */
    public function leaderboard(int $limit = 25): array
    {
        return $this->events->leaderboard($limit);
    }

    private function awardBadgesFor(int $userId, ReputationAction $action): void
    {
        $count = $this->events->countFor($userId, $action);

        foreach ($this->badges->forAction($action->value) as $badge) {
            if ($count < $badge['threshold'] || $this->badges->has($userId, $badge['id'])) {
                continue;
            }

            if ($this->badges->award($userId, $badge['id'])) {
                $this->notifications->send(
                    $userId,
                    'badge.awarded',
                    'You earned a badge: ' . $badge['name'],
                    $badge['description'],
                    '/contributors'
                );
            }
        }
    }
}
