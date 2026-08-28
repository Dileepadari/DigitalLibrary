<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * Feeds the sign-in throttle. The IP is stored as a salted hash: it is enough to
 * count attempts from one source without keeping a log of who was where.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function record(string $identifier, string $ipHash, bool $successful): void
    {
        $this->db->insert('login_attempts', [
            'identifier' => mb_strtolower($identifier),
            'ip_hash'    => $ipHash,
            'successful' => $successful ? 1 : 0,
        ]);
    }

    /** Failures for this email or this IP since the given number of seconds ago. */
    public function recentFailures(string $identifier, string $ipHash, int $withinSeconds): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE successful = 0 AND created_at > ? AND (identifier = ? OR ip_hash = ?)',
            [gmdate('Y-m-d H:i:s', time() - $withinSeconds), mb_strtolower($identifier), $ipHash]
        );
    }

    public function clear(string $identifier, string $ipHash): void
    {
        $this->db->execute(
            'DELETE FROM login_attempts WHERE successful = 0 AND (identifier = ? OR ip_hash = ?)',
            [mb_strtolower($identifier), $ipHash]
        );
    }

    public function prune(int $olderThanSeconds): int
    {
        return $this->db->execute(
            'DELETE FROM login_attempts WHERE created_at < ?',
            [gmdate('Y-m-d H:i:s', time() - $olderThanSeconds)]
        );
    }
}
