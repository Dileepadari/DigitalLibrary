<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class TakedownRepository
{
    private const COLUMNS = 't.id, t.book_id, t.subject_url, t.claimant_name, t.claimant_email, t.claimant_role,
        t.basis, t.status, t.outcome_note, t.handled_by, t.handled_at, t.created_at,
        b.title AS book_title, b.slug AS book_slug, u.username AS handler_name';

    private const JOINS = 'LEFT JOIN books b ON b.id = t.book_id
        LEFT JOIN users u ON u.id = t.handled_by';

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        return $this->db->insert('takedowns', $values);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM takedowns t ' . self::JOINS . ' WHERE t.id = ?',
            [$id]
        );
    }

    /** @return list<array<string, mixed>> */
    public function paginate(string $status = 'open', int $limit = 50): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM takedowns t ' . self::JOINS;
        $bindings = [];

        if ($status !== 'any') {
            $sql .= ' WHERE t.status = ?';
            $bindings[] = $status;
        }

        $bindings[] = max(1, min(200, $limit));

        return $this->db->select($sql . ' ORDER BY t.created_at ASC LIMIT ?', $bindings);
    }

    public function decide(int $id, string $status, ?string $note, int $handledBy): void
    {
        $this->db->execute(
            'UPDATE takedowns SET status = ?, outcome_note = ?, handled_by = ?, handled_at = ? WHERE id = ?',
            [$status, $note, $handledBy, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public function countOpen(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM takedowns WHERE status = 'open'");
    }

    /** How long open notices have been waiting, which is the number that matters. */
    public function oldestOpenDays(): ?int
    {
        $oldest = $this->db->scalar("SELECT MIN(created_at) FROM takedowns WHERE status = 'open'");

        if ($oldest === null) {
            return null;
        }

        return (int) floor((time() - strtotime((string) $oldest)) / 86400);
    }
}
