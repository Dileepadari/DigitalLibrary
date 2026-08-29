<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Support\ReputationAction;

final class ReputationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function record(
        int $userId,
        ReputationAction $action,
        int $points,
        ?string $subjectType,
        ?int $subjectId,
    ): void {
        $this->db->insert('reputation_events', [
            'user_id'      => $userId,
            'action'       => $action->value,
            'points'       => $points,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
        ]);
    }

    /**
     * Whether this exact thing has already been counted, so a second approval of
     * the same upload does not pay twice.
     */
    public function alreadyRecorded(int $userId, ReputationAction $action, string $subjectType, int $subjectId): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM reputation_events
             WHERE user_id = ? AND action = ? AND subject_type = ? AND subject_id = ? LIMIT 1',
            [$userId, $action->value, $subjectType, $subjectId]
        ) !== null;
    }

    public function countFor(int $userId, ReputationAction $action): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM reputation_events WHERE user_id = ? AND action = ?',
            [$userId, $action->value]
        );
    }

    /** @return array<string, int> action => how many times */
    public function tallyFor(int $userId): array
    {
        $tally = [];
        $rows = $this->db->select(
            'SELECT action, COUNT(*) AS total FROM reputation_events WHERE user_id = ? GROUP BY action',
            [$userId]
        );

        foreach ($rows as $row) {
            $tally[(string) $row['action']] = (int) $row['total'];
        }

        return $tally;
    }

    /** @return list<array<string, mixed>> */
    public function recentFor(int $userId, int $limit = 20): array
    {
        return $this->db->select(
            'SELECT action, points, subject_type, subject_id, created_at FROM reputation_events
             WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, max(1, min(100, $limit))]
        );
    }

    /**
     * The contributor leaderboard: people with points, most first.
     *
     * @return list<array<string, mixed>>
     */
    public function leaderboard(int $limit = 25): array
    {
        return $this->db->select(
            "SELECT u.id, u.username, u.name, u.role, u.reputation,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.user_id = u.id) AS badge_count
             FROM users u
             WHERE u.reputation > 0 AND u.deleted_at IS NULL AND u.status <> 'banned'
             ORDER BY u.reputation DESC, u.username LIMIT ?",
            [max(1, min(100, $limit))]
        );
    }
}
