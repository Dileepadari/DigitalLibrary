<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class NotificationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(int $userId, string $type, string $title, ?string $body, ?string $url): int
    {
        return $this->db->insert('notifications', [
            'user_id' => $userId,
            'type'    => $type,
            'title'   => mb_substr($title, 0, 255),
            'body'    => $body === null ? null : mb_substr($body, 0, 500),
            'url'     => $url,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, max(1, min(200, $limit))]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->execute(
            'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $userId]
        );
    }
}
