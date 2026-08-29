<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\BookRequest;
use App\Support\RequestStatus;

final class BookRequestRepository
{
    private const COLUMNS = 'r.id, r.title, r.author, r.isbn, r.note, r.language, r.requester_id, r.status,
        r.claimed_by, r.claimed_at, r.fulfilled_by_book_id, r.close_reason, r.vote_count, r.created_at,
        requester.username AS requester_name, claimer.username AS claimer_name,
        b.title AS fulfilled_title, b.slug AS fulfilled_slug';

    private const JOINS = 'LEFT JOIN users requester ON requester.id = r.requester_id
        LEFT JOIN users claimer ON claimer.id = r.claimed_by
        LEFT JOIN books b ON b.id = r.fulfilled_by_book_id';

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        return $this->db->insert('book_requests', $values);
    }

    public function findById(int $id, ?int $viewerId = null): ?BookRequest
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ', ' . $this->votedExpression() . ' FROM book_requests r '
                . self::JOINS . ' WHERE r.id = ?',
            [$viewerId ?? 0, $id]
        );

        return $row === null ? null : BookRequest::fromRow($row);
    }

    /**
     * @param array{status?: string, q?: string, sort?: string, requester?: int, claimed_by?: int} $filters
     *
     * @return array{rows: list<BookRequest>, total: int, page: int, pages: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 25, ?int $viewerId = null): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        $status = (string) ($filters['status'] ?? 'open');

        if ($status === 'open') {
            $where[] = "r.status IN ('open', 'claimed')";
        } elseif ($status !== 'any') {
            $where[] = 'r.status = ?';
            $bindings[] = $status;
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(r.title LIKE ? OR r.author LIKE ?)';
            $bindings[] = '%' . $filters['q'] . '%';
            $bindings[] = '%' . $filters['q'] . '%';
        }

        if (($filters['requester'] ?? 0) > 0) {
            $where[] = 'r.requester_id = ?';
            $bindings[] = (int) $filters['requester'];
        }

        if (($filters['claimed_by'] ?? 0) > 0) {
            $where[] = 'r.claimed_by = ?';
            $bindings[] = (int) $filters['claimed_by'];
        }

        $clause = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM book_requests r WHERE {$clause}", $bindings);

        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        // Demand first by default: the whole point of the votes.
        $order = match ((string) ($filters['sort'] ?? '')) {
            'recent' => 'r.created_at DESC',
            'oldest' => 'r.created_at ASC',
            default  => 'r.vote_count DESC, r.created_at ASC',
        };

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ', ' . $this->votedExpression() . ' FROM book_requests r '
                . self::JOINS . " WHERE {$clause} ORDER BY {$order} LIMIT ? OFFSET ?",
            [$viewerId ?? 0, ...$bindings, $perPage, ($page - 1) * $perPage]
        );

        return [
            'rows'  => array_map(static fn (array $row): BookRequest => BookRequest::fromRow($row), $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
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

        $this->db->execute("UPDATE book_requests SET {$assignments} WHERE id = :id", [...$values, 'id' => $id]);
    }

    /** @return bool true when the vote was added, false when it was taken back */
    public function toggleVote(int $requestId, int $userId): bool
    {
        $existing = $this->db->scalar(
            'SELECT 1 FROM book_request_votes WHERE request_id = ? AND user_id = ?',
            [$requestId, $userId]
        );

        if ($existing !== null) {
            $this->db->execute(
                'DELETE FROM book_request_votes WHERE request_id = ? AND user_id = ?',
                [$requestId, $userId]
            );
            $this->recount($requestId);

            return false;
        }

        $this->db->execute(
            'INSERT IGNORE INTO book_request_votes (request_id, user_id) VALUES (?, ?)',
            [$requestId, $userId]
        );
        $this->recount($requestId);

        return true;
    }

    public function addVote(int $requestId, int $userId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO book_request_votes (request_id, user_id) VALUES (?, ?)',
            [$requestId, $userId]
        );
        $this->recount($requestId);
    }

    /**
     * Everyone who wanted this book, so they can all be told when it arrives.
     *
     * @return list<int>
     */
    public function voterIds(int $requestId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->db->select('SELECT user_id FROM book_request_votes WHERE request_id = ?', [$requestId])
        );
    }

    public function countOpen(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM book_requests WHERE status IN ('open', 'claimed')");
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        $counts = [];
        $rows = $this->db->select('SELECT status, COUNT(*) AS total FROM book_requests GROUP BY status');

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Open requests that look like this title, so someone adding a book can be
     * told they are answering one.
     *
     * @return list<BookRequest>
     */
    public function openMatching(string $title, int $limit = 5): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ', 0 AS viewer_voted FROM book_requests r ' . self::JOINS
                . " WHERE r.status IN ('open', 'claimed') AND (r.title = ? OR r.title LIKE ?)
                   ORDER BY r.vote_count DESC LIMIT ?",
            [$title, mb_substr($title, 0, 12) . '%', max(1, min(20, $limit))]
        );

        return array_map(static fn (array $row): BookRequest => BookRequest::fromRow($row), $rows);
    }

    /** @return list<BookRequest> the most wanted open requests, for the home page */
    public function mostWanted(int $limit = 5): array
    {
        return $this->paginate(['status' => 'open'], 1, $limit)['rows'];
    }

    public function statusOf(int $id): ?RequestStatus
    {
        $status = $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]);

        return $status === null ? null : RequestStatus::from((string) $status);
    }

    private function recount(int $requestId): void
    {
        $this->db->execute(
            'UPDATE book_requests SET vote_count = (
                 SELECT COUNT(*) FROM book_request_votes WHERE request_id = ?
             ) WHERE id = ?',
            [$requestId, $requestId]
        );
    }

    /** Whether the viewer has already voted, as a column rather than a second query. */
    private function votedExpression(): string
    {
        return '(SELECT COUNT(*) FROM book_request_votes v WHERE v.request_id = r.id AND v.user_id = ?)
                AS viewer_voted';
    }
}
