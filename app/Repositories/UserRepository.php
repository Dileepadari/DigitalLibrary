<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\User;
use App\Support\Role;
use App\Support\UserStatus;

/**
 * Every query against `users`. Soft deleted rows are excluded everywhere except
 * findByIdWithTrashed().
 */
final class UserRepository
{
    private const COLUMNS = 'id, name, username, email, password_hash, role, status, bio, avatar_path,
        reputation, strikes, storage_used, storage_quota, email_verified_at, status_reason, status_until,
        last_seen_at, created_at';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE email = ? AND deleted_at IS NULL',
            [mb_strtolower(trim($email))]
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function findByUsername(string $username): ?User
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE username = ? AND deleted_at IS NULL',
            [mb_strtolower(trim($username))]
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function emailTaken(string $email): bool
    {
        return $this->db->scalar('SELECT 1 FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]) !== null;
    }

    public function usernameTaken(string $username): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM users WHERE username = ? LIMIT 1',
            [mb_strtolower(trim($username))]
        ) !== null;
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL');
    }

    public function create(
        string $name,
        string $username,
        string $email,
        string $passwordHash,
        Role $role = Role::Member,
        bool $verified = false,
        ?int $quota = null,
    ): int {
        $values = [
            'name'              => $name,
            'username'          => mb_strtolower($username),
            'email'             => mb_strtolower($email),
            'password_hash'     => $passwordHash,
            'role'              => $role->value,
            'email_verified_at' => $verified ? gmdate('Y-m-d H:i:s') : null,
        ];

        if ($quota !== null) {
            $values['storage_quota'] = $quota;
        }

        return $this->db->insert('users', $values);
    }

    public function updateProfile(int $id, string $name, ?string $bio): void
    {
        $this->db->execute('UPDATE users SET name = ?, bio = ? WHERE id = ?', [$name, $bio, $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$passwordHash, $id]);
    }

    public function markVerified(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public function setRole(int $id, Role $role): void
    {
        $this->db->execute('UPDATE users SET role = ? WHERE id = ?', [$role->value, $id]);
    }

    public function setStatus(int $id, UserStatus $status, ?string $reason = null, ?string $until = null): void
    {
        $this->db->execute(
            'UPDATE users SET status = ?, status_reason = ?, status_until = ? WHERE id = ?',
            [$status->value, $reason, $until, $id]
        );
    }

    /** Storage accounting: the delta may be negative when a file is deleted. */
    public function addStorageUsed(int $id, int $delta): void
    {
        $this->db->execute(
            'UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) + ?) WHERE id = ?',
            [$delta, $id]
        );
    }

    public function addReputation(int $id, int $delta): void
    {
        $this->db->execute('UPDATE users SET reputation = GREATEST(0, reputation + ?) WHERE id = ?', [$delta, $id]);
    }

    /** A copyright rejection counts against the uploader; see PLAN.md section 9. */
    public function addStrike(int $id): int
    {
        $this->db->execute('UPDATE users SET strikes = strikes + 1 WHERE id = ?', [$id]);

        return (int) $this->db->scalar('SELECT strikes FROM users WHERE id = ?', [$id]);
    }

    public function touchLastSeen(int $id): void
    {
        $this->db->execute('UPDATE users SET last_seen_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }

    /**
     * Lifts an expired mute back to active. Called on sign in, which is the only
     * moment the difference matters.
     */
    public function expireStatus(int $id): void
    {
        $this->db->execute(
            "UPDATE users SET status = 'active', status_reason = NULL, status_until = NULL
             WHERE id = ? AND status_until IS NOT NULL AND status_until <= ?",
            [$id, gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * @param array{search?: string, role?: string, status?: string} $filters
     *
     * @return array{rows: list<User>, total: int, page: int, pages: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['deleted_at IS NULL'];
        $bindings = [];

        if (($filters['search'] ?? '') !== '') {
            $where[] = '(name LIKE ? OR username LIKE ? OR email LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (($filters['role'] ?? '') !== '') {
            $where[] = 'role = ?';
            $bindings[] = $filters['role'];
        }

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'status = ?';
            $bindings[] = $filters['status'];
        }

        $clause = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM users {$clause}", $bindings);

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM users {$clause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [...$bindings, $perPage, ($page - 1) * $perPage]
        );

        return [
            'rows'  => array_map(static fn (array $row): User => User::fromRow($row), $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /** @return array<string, int> role => count */
    public function countsByRole(): array
    {
        $counts = [];

        $rows = $this->db->select(
            'SELECT role, COUNT(*) AS total FROM users WHERE deleted_at IS NULL GROUP BY role'
        );

        foreach ($rows as $row) {
            $counts[(string) $row['role']] = (int) $row['total'];
        }

        return $counts;
    }
}
