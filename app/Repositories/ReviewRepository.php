<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ReviewRepository
{
    private const COLUMNS = 'r.id, r.book_id, r.user_id, r.rating, r.body, r.status, r.hidden_reason,
        r.helpful_count, r.created_at, u.username, u.name AS author_name, u.reputation';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM reviews r INNER JOIN users u ON u.id = r.user_id WHERE r.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByUserAndBook(int $userId, int $bookId): ?array
    {
        return $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM reviews r INNER JOIN users u ON u.id = r.user_id
             WHERE r.user_id = ? AND r.book_id = ?',
            [$userId, $bookId]
        );
    }

    /**
     * The reviews shown on a book page: most helpful first, and a hidden one
     * appears only for the person who wrote it and for a moderator.
     *
     * @return list<array<string, mixed>>
     */
    public function forBook(int $bookId, ?int $viewerId, bool $includeHidden = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ',
                (SELECT COUNT(*) FROM review_votes v WHERE v.review_id = r.id AND v.user_id = ?) AS viewer_voted
                FROM reviews r INNER JOIN users u ON u.id = r.user_id
                WHERE r.book_id = ? AND r.status <> \'removed\'';
        $bindings = [$viewerId ?? 0, $bookId];

        if (!$includeHidden) {
            $sql .= " AND (r.status = 'visible'" . ($viewerId === null ? ')' : ' OR r.user_id = ?)');

            if ($viewerId !== null) {
                $bindings[] = $viewerId;
            }
        }

        return $this->db->select($sql . ' ORDER BY r.helpful_count DESC, r.created_at DESC', $bindings);
    }

    /** @return list<array<string, mixed>> */
    public function byUser(int $userId, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT r.id, r.rating, r.body, r.created_at, b.title, b.slug FROM reviews r
             INNER JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? AND r.status = 'visible' AND b.deleted_at IS NULL
             ORDER BY r.created_at DESC LIMIT ?",
            [$userId, max(1, min(100, $limit))]
        );
    }

    public function create(int $bookId, int $userId, int $rating, ?string $body): int
    {
        return $this->db->insert('reviews', [
            'book_id' => $bookId,
            'user_id' => $userId,
            'rating'  => $rating,
            'body'    => $body,
        ]);
    }

    public function update(int $id, int $rating, ?string $body): void
    {
        $this->db->execute('UPDATE reviews SET rating = ?, body = ? WHERE id = ?', [$rating, $body, $id]);
    }

    public function setStatus(int $id, string $status, ?string $reason = null): void
    {
        $this->db->execute(
            'UPDATE reviews SET status = ?, hidden_reason = ? WHERE id = ?',
            [$status, $reason, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM reviews WHERE id = ?', [$id]);
    }

    /** @return bool true when the vote was added, false when it was taken back */
    public function toggleVote(int $reviewId, int $userId): bool
    {
        $existing = $this->db->scalar(
            'SELECT 1 FROM review_votes WHERE review_id = ? AND user_id = ?',
            [$reviewId, $userId]
        );

        if ($existing !== null) {
            $this->db->execute('DELETE FROM review_votes WHERE review_id = ? AND user_id = ?', [$reviewId, $userId]);
            $this->recountVotes($reviewId);

            return false;
        }

        $this->db->execute(
            'INSERT IGNORE INTO review_votes (review_id, user_id) VALUES (?, ?)',
            [$reviewId, $userId]
        );
        $this->recountVotes($reviewId);

        return true;
    }

    /**
     * Recalculates a book's rating from the reviews that count: hidden and
     * removed ones do not.
     */
    public function refreshBookRating(int $bookId): void
    {
        $this->db->execute(
            "UPDATE books b SET
                b.rating_count = (
                    SELECT COUNT(*) FROM reviews r WHERE r.book_id = b.id AND r.status = 'visible'
                ),
                b.rating_average = COALESCE((
                    SELECT AVG(r.rating) FROM reviews r WHERE r.book_id = b.id AND r.status = 'visible'
                ), 0)
             WHERE b.id = ?",
            [$bookId]
        );
    }

    /** @return array<int, int> rating => how many, for the histogram */
    public function distribution(int $bookId): array
    {
        $counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $rows = $this->db->select(
            "SELECT rating, COUNT(*) AS total FROM reviews WHERE book_id = ? AND status = 'visible' GROUP BY rating",
            [$bookId]
        );

        foreach ($rows as $row) {
            $counts[(int) $row['rating']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countFor(int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM reviews WHERE user_id = ? AND status = 'visible'",
            [$userId]
        );
    }

    private function recountVotes(int $reviewId): void
    {
        $this->db->execute(
            'UPDATE reviews SET helpful_count = (
                 SELECT COUNT(*) FROM review_votes WHERE review_id = ?
             ) WHERE id = ?',
            [$reviewId, $reviewId]
        );
    }
}
