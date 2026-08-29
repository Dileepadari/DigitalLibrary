<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * Email verification and password reset tokens.
 *
 * The plain token goes in the link and is never stored: the table holds its
 * SHA-256, so a database leak does not hand out working links. Issuing a new
 * token of a type invalidates the previous ones for that user.
 */
final class AuthTokenRepository
{
    public const EMAIL_VERIFICATION = 'email_verification';
    public const PASSWORD_RESET = 'password_reset';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return string the plain token to put in the link */
    public function issue(int $userId, string $type, int $ttlSeconds): string
    {
        $this->deleteFor($userId, $type);

        $token = bin2hex(random_bytes(32));

        $this->db->insert('auth_tokens', [
            'user_id'    => $userId,
            'type'       => $type,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);

        return $token;
    }

    /** @return array{id: int, user_id: int}|null */
    public function findValid(string $token, string $type): ?array
    {
        $row = $this->db->first(
            'SELECT id, user_id FROM auth_tokens
             WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), $type, gmdate('Y-m-d H:i:s')]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
    }

    public function markUsed(int $id): void
    {
        $this->db->execute('UPDATE auth_tokens SET used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }

    public function deleteFor(int $userId, string $type): void
    {
        $this->db->execute('DELETE FROM auth_tokens WHERE user_id = ? AND type = ?', [$userId, $type]);
    }

    /** Housekeeping for the console; expired rows are useless. */
    public function pruneExpired(): int
    {
        return $this->db->execute('DELETE FROM auth_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s')]);
    }
}
