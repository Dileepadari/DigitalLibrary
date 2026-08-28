<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\ModerationRequest;
use App\Support\ModerationStatus;

final class ModerationRepository
{
    private const COLUMNS = 'r.id, r.subject_type, r.subject_id, r.submitter_id, r.assignee_id, r.status,
        r.title, r.payload, r.reason, r.claimed_until, r.decided_at, r.created_at,
        submitter.username AS submitter_name, assignee.username AS assignee_name';

    private const JOINS = 'LEFT JOIN users submitter ON submitter.id = r.submitter_id
        LEFT JOIN users assignee ON assignee.id = r.assignee_id';

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        return $this->db->insert('moderation_requests', $values);
    }

    public function findById(int $id): ?ModerationRequest
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM moderation_requests r ' . self::JOINS . ' WHERE r.id = ?',
            [$id]
        );

        return $row === null ? null : ModerationRequest::fromRow($row);
    }

    /** The open request for a subject, if there is one. */
    public function openForSubject(string $type, int $subjectId): ?ModerationRequest
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM moderation_requests r ' . self::JOINS . "
             WHERE r.subject_type = ? AND r.subject_id = ?
               AND r.status IN ('draft', 'pending', 'under_review', 'changes_requested')
             ORDER BY r.id DESC LIMIT 1",
            [$type, $subjectId]
        );

        return $row === null ? null : ModerationRequest::fromRow($row);
    }

    /**
     * @param array{status?: string, type?: string, mine?: int, submitter?: int} $filters
     *
     * @return array{rows: list<ModerationRequest>, total: int, page: int, pages: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        $status = (string) ($filters['status'] ?? 'open');

        if ($status === 'open') {
            $where[] = "r.status IN ('pending', 'under_review', 'changes_requested')";
        } elseif ($status !== 'any') {
            $where[] = 'r.status = ?';
            $bindings[] = $status;
        }

        if (($filters['type'] ?? '') !== '') {
            $where[] = 'r.subject_type = ?';
            $bindings[] = (string) $filters['type'];
        }

        if (($filters['mine'] ?? 0) > 0) {
            $where[] = 'r.assignee_id = ?';
            $bindings[] = (int) $filters['mine'];
        }

        if (($filters['submitter'] ?? 0) > 0) {
            $where[] = 'r.submitter_id = ?';
            $bindings[] = (int) $filters['submitter'];
        }

        $clause = implode(' AND ', $where);
        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM moderation_requests r WHERE {$clause}",
            $bindings
        );

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        // Oldest first: the queue is a queue, and the SLA view depends on it.
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM moderation_requests r ' . self::JOINS . "
             WHERE {$clause} ORDER BY r.created_at ASC, r.id ASC LIMIT ? OFFSET ?",
            [...$bindings, $perPage, ($page - 1) * $perPage]
        );

        return [
            'rows'  => array_map(
                static fn (array $row): ModerationRequest => ModerationRequest::fromRow($row),
                $rows
            ),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        $counts = [];

        $rows = $this->db->select('SELECT status, COUNT(*) AS total FROM moderation_requests GROUP BY status');

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function openCount(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM moderation_requests
             WHERE status IN ('pending', 'under_review', 'changes_requested')"
        );
    }

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values): void
    {
        if ($values === []) {
            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => "`{$column}` = :{$column}",
            array_keys($values)
        ));

        $this->db->execute("UPDATE moderation_requests SET {$assignments} WHERE id = :id", [...$values, 'id' => $id]);
    }

    /**
     * Claims the request for a reviewer, but only if nobody else holds a live
     * claim: the WHERE clause is the lock, so two reviewers pressing at once
     * cannot both win.
     */
    public function claim(int $id, int $reviewerId, int $seconds): bool
    {
        return $this->db->execute(
            "UPDATE moderation_requests
             SET assignee_id = ?, claimed_until = ?, status = 'under_review'
             WHERE id = ?
               AND status IN ('pending', 'under_review', 'changes_requested')
               AND (assignee_id IS NULL OR assignee_id = ? OR claimed_until IS NULL OR claimed_until < ?)",
            [$reviewerId, gmdate('Y-m-d H:i:s', time() + $seconds), $id, $reviewerId, gmdate('Y-m-d H:i:s')]
        ) > 0;
    }

    public function addEvent(int $requestId, ?int $actorId, ?string $from, string $to, ?string $note): void
    {
        $this->db->insert('moderation_events', [
            'request_id'  => $requestId,
            'actor_id'    => $actorId,
            'from_status' => $from,
            'to_status'   => $to,
            'note'        => $note === null ? null : mb_substr($note, 0, 255),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $requestId): array
    {
        return $this->db->select(
            'SELECT e.*, u.username AS actor_name FROM moderation_events e
             LEFT JOIN users u ON u.id = e.actor_id
             WHERE e.request_id = ? ORDER BY e.id',
            [$requestId]
        );
    }

    public function addComment(int $requestId, ?int $authorId, string $body): void
    {
        $this->db->insert('moderation_comments', [
            'request_id' => $requestId,
            'author_id'  => $authorId,
            'body'       => $body,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function comments(int $requestId): array
    {
        return $this->db->select(
            'SELECT c.*, u.username AS author_name FROM moderation_comments c
             LEFT JOIN users u ON u.id = c.author_id
             WHERE c.request_id = ? ORDER BY c.id',
            [$requestId]
        );
    }

    /** Median hours from submission to decision, for the queue header. */
    public function medianDecisionHours(): ?float
    {
        $rows = $this->db->select(
            'SELECT TIMESTAMPDIFF(MINUTE, created_at, decided_at) AS minutes
             FROM moderation_requests WHERE decided_at IS NOT NULL ORDER BY minutes'
        );

        if ($rows === []) {
            return null;
        }

        $middle = (int) floor(count($rows) / 2);

        return round(((int) $rows[$middle]['minutes']) / 60, 1);
    }

    /**
     * How many of this submitter's requests ended each way, for the review
     * screen.
     *
     * @return array{approved: int, rejected: int, open: int}
     */
    public function submitterRecord(int $userId): array
    {
        $record = ['approved' => 0, 'rejected' => 0, 'open' => 0];

        foreach (
            $this->db->select(
                'SELECT status, COUNT(*) AS total FROM moderation_requests WHERE submitter_id = ? GROUP BY status',
                [$userId]
            ) as $row
        ) {
            $status = ModerationStatus::from((string) $row['status']);
            $key = match ($status) {
                ModerationStatus::Approved => 'approved',
                ModerationStatus::Rejected => 'rejected',
                default                    => 'open',
            };

            $record[$key] += (int) $row['total'];
        }

        return $record;
    }
}
