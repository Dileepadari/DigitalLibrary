<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * Append only. Nothing in the application updates or deletes an audit row; the
 * viewer arrives with M8.
 */
final class AuditLogRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?int $actorId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ipHash = null,
        ?string $userAgent = null,
    ): void {
        $this->db->insert('audit_logs', [
            'actor_id'     => $actorId,
            'action'       => $action,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state'  => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_hash'      => $ipHash,
            'user_agent'   => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select(
            'SELECT a.*, u.username AS actor_username FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_id
             ORDER BY a.id DESC LIMIT ?',
            [max(1, min(500, $limit))]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forSubject(string $subjectType, int $subjectId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT a.*, u.username AS actor_username FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.subject_type = ? AND a.subject_id = ?
             ORDER BY a.id DESC LIMIT ?',
            [$subjectType, $subjectId, max(1, min(500, $limit))]
        );
    }
}
