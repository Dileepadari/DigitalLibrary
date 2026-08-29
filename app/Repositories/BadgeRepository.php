<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class BadgeRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Badges earned by doing this kind of thing, hardest first, so the check
     * can stop at the first one that is already held.
     *
     * @return list<array{id: int, key: string, name: string, description: string, threshold: int}>
     */
    public function forAction(string $action): array
    {
        $rows = $this->db->select(
            'SELECT id, `key`, name, description, threshold FROM badges WHERE action = ? ORDER BY threshold DESC',
            [$action]
        );

        return array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'key'         => (string) $row['key'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'threshold'   => (int) $row['threshold'],
        ], $rows);
    }

    /** @return list<array{key: string, name: string, description: string, awarded_at: string}> */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT b.`key`, b.name, b.description, ub.awarded_at FROM user_badges ub
             INNER JOIN badges b ON b.id = ub.badge_id
             WHERE ub.user_id = ? ORDER BY b.sort_order',
            [$userId]
        );

        return array_map(static fn (array $row): array => [
            'key'         => (string) $row['key'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'awarded_at'  => (string) $row['awarded_at'],
        ], $rows);
    }

    public function has(int $userId, int $badgeId): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM user_badges WHERE user_id = ? AND badge_id = ? LIMIT 1',
            [$userId, $badgeId]
        ) !== null;
    }

    /** @return bool true when this is the first time it has been awarded */
    public function award(int $userId, int $badgeId): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)',
            [$userId, $badgeId]
        ) > 0;
    }

    /** @return list<array{key: string, name: string, description: string, action: string, threshold: int}> */
    public function all(): array
    {
        $rows = $this->db->select(
            'SELECT `key`, name, description, action, threshold FROM badges ORDER BY sort_order'
        );

        return array_map(static fn (array $row): array => [
            'key'         => (string) $row['key'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'action'      => (string) $row['action'],
            'threshold'   => (int) $row['threshold'],
        ], $rows);
    }
}
