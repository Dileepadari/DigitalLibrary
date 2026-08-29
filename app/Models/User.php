<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Role;
use App\Support\UserStatus;

/**
 * A row from `users`, typed. Built by UserRepository and never by hand outside
 * it, so there is one place that knows the column names.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $username,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly Role $role,
        public readonly UserStatus $status,
        public readonly ?string $bio,
        public readonly ?string $avatarPath,
        public readonly int $reputation,
        public readonly int $strikes,
        public readonly int $storageUsed,
        public readonly int $storageQuota,
        public readonly ?string $emailVerifiedAt,
        public readonly ?string $statusReason,
        public readonly ?string $statusUntil,
        public readonly ?string $lastSeenAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['password_hash'],
            Role::from((string) $row['role']),
            UserStatus::from((string) $row['status']),
            $row['bio'] !== null ? (string) $row['bio'] : null,
            $row['avatar_path'] !== null ? (string) $row['avatar_path'] : null,
            (int) $row['reputation'],
            (int) ($row['strikes'] ?? 0),
            (int) $row['storage_used'],
            (int) $row['storage_quota'],
            $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null,
            $row['status_reason'] !== null ? (string) $row['status_reason'] : null,
            $row['status_until'] !== null ? (string) $row['status_until'] : null,
            $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
            (string) $row['created_at'],
        );
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isLibrarian(): bool
    {
        return $this->role === Role::Librarian;
    }

    /** Banned users cannot sign in; muted users can read but not contribute. */
    public function canSignIn(): bool
    {
        return $this->status->canSignIn();
    }

    public function canContribute(): bool
    {
        return $this->status->canContribute() && $this->isVerified();
    }

    public function initials(): string
    {
        $letters = '';

        foreach (preg_split('/\s+/', trim($this->name)) ?: [] as $word) {
            if ($word === '' || mb_strlen($letters) >= 2) {
                continue;
            }

            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters !== '' ? $letters : mb_strtoupper(mb_substr($this->username, 0, 1));
    }

    public function storagePercent(): int
    {
        if ($this->storageQuota <= 0) {
            return 0;
        }

        return (int) min(100, round($this->storageUsed / $this->storageQuota * 100));
    }
}
