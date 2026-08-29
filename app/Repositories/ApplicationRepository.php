<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/** Applications to become a librarian. Only an admin decides on these. */
final class ApplicationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(int $userId, string $statement): int
    {
        return $this->db->insert('librarian_applications', [
            'user_id'   => $userId,
            'statement' => mb_substr($statement, 0, 2000),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT a.*, u.username, u.name, u.reputation FROM librarian_applications a
             INNER JOIN users u ON u.id = a.user_id WHERE a.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null the applicant's open application, if any */
    public function openFor(int $userId): ?array
    {
        return $this->db->first(
            "SELECT * FROM librarian_applications WHERE user_id = ? AND status = 'pending'",
            [$userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        return $this->db->select(
            "SELECT a.*, u.username, u.name, u.reputation FROM librarian_applications a
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.status = 'pending' ORDER BY a.created_at"
        );
    }

    public function decide(int $id, string $status, int $decidedBy): void
    {
        $this->db->execute(
            'UPDATE librarian_applications SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?',
            [$status, $decidedBy, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public function countPending(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM librarian_applications WHERE status = 'pending'");
    }
}
