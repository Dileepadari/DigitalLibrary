<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationRepository;

/**
 * In-app notifications. Email digests are M7; everything routes through here so
 * adding them later is one class, not thirty call sites.
 */
final class NotificationService
{
    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function send(?int $userId, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        if ($userId === null) {
            return;
        }

        $this->notifications->create($userId, $type, $title, $body, $url);
    }

    public function unreadCount(?int $userId): int
    {
        return $userId === null ? 0 : $this->notifications->unreadCount($userId);
    }
}
