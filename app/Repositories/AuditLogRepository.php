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

    /**
     * The audit log with filters, for the admin viewer.
     *
     * @param array{actor?: string, action?: string, subject?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (($filters['actor'] ?? '') !== '') {
            $where[] = 'u.username = ?';
            $bindings[] = (string) $filters['actor'];
        }

        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action LIKE ?';
            $bindings[] = $filters['action'] . '%';
        }

        if (($filters['subject'] ?? '') !== '') {
            $where[] = 'a.subject_type = ?';
            $bindings[] = (string) $filters['subject'];
        }

        $clause = implode(' AND ', $where);
        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE {$clause}",
            $bindings
        );

        $perPage = max(1, min(200, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = $this->db->select(
            "SELECT a.*, u.username AS actor_username FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE {$clause} ORDER BY a.id DESC LIMIT ? OFFSET ?",
            [...$bindings, $perPage, ($page - 1) * $perPage]
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return list<string> the distinct actions, for the filter dropdown */
    public function actions(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['action'],
            $this->db->select('SELECT DISTINCT action FROM audit_logs ORDER BY action')
        );
    }

    /**
     * Everything matching a filter, for the CSV export. No pagination: an
     * export that stops at page one is not an export.
     *
     * @param array{actor?: string, action?: string, subject?: string} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function export(array $filters = [], int $limit = 10000): array
    {
        $result = $this->paginate($filters, 1, min($limit, 200));
        $rows = $result['rows'];
        $pages = (int) ceil(min($result['total'], $limit) / 200);

        for ($page = 2; $page <= $pages; $page++) {
            $rows = array_merge($rows, $this->paginate($filters, $page, 200)['rows']);
        }

        return $rows;
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
