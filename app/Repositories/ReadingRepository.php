<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * Reading progress and bookmarks. Both belong to one person and one file, and
 * nothing here ever reads another person's row: the user id is part of every
 * query rather than something a caller is trusted to check.
 */
final class ReadingRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{position: string, percent: int, last_read_at: string}|null */
    public function progress(int $userId, int $fileId): ?array
    {
        $row = $this->db->first(
            'SELECT position, percent, last_read_at FROM reading_progress WHERE user_id = ? AND book_file_id = ?',
            [$userId, $fileId]
        );

        return $row === null ? null : [
            'position'     => (string) $row['position'],
            'percent'      => (int) $row['percent'],
            'last_read_at' => (string) $row['last_read_at'],
        ];
    }

    public function saveProgress(int $userId, int $fileId, string $position, int $percent): void
    {
        $this->db->execute(
            'INSERT INTO reading_progress (user_id, book_file_id, position, percent)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position), percent = VALUES(percent),
                 last_read_at = CURRENT_TIMESTAMP',
            [$userId, $fileId, mb_substr($position, 0, 255), max(0, min(100, $percent))]
        );
    }

    /**
     * What to offer as "carry on where you left off".
     *
     * @return list<array<string, mixed>>
     */
    public function recentlyRead(int $userId, int $limit = 5): array
    {
        return $this->db->select(
            "SELECT p.percent, p.last_read_at, p.book_file_id, f.format, b.title, b.slug, b.id AS book_id,
                    b.cover_path
             FROM reading_progress p
             INNER JOIN book_files f ON f.id = p.book_file_id
             INNER JOIN books b ON b.id = f.book_id
             WHERE p.user_id = ? AND f.status = 'published' AND b.status = 'published' AND b.deleted_at IS NULL
             ORDER BY p.last_read_at DESC LIMIT ?",
            [$userId, max(1, min(50, $limit))]
        );
    }

    /** @return list<array{id: int, position: string, label: string|null, note: string|null, created_at: string}> */
    public function bookmarks(int $userId, int $fileId): array
    {
        $rows = $this->db->select(
            'SELECT id, position, label, note, created_at FROM bookmarks
             WHERE user_id = ? AND book_file_id = ? ORDER BY id',
            [$userId, $fileId]
        );

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'position'   => (string) $row['position'],
            'label'      => $row['label'] !== null ? (string) $row['label'] : null,
            'note'       => $row['note'] !== null ? (string) $row['note'] : null,
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    public function addBookmark(int $userId, int $fileId, string $position, ?string $label, ?string $note): int
    {
        return $this->db->insert('bookmarks', [
            'user_id'      => $userId,
            'book_file_id' => $fileId,
            'position'     => mb_substr($position, 0, 255),
            'label'        => $label === null ? null : mb_substr($label, 0, 120),
            'note'         => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }

    /** @return bool false when the bookmark is not this person's */
    public function deleteBookmark(int $userId, int $bookmarkId): bool
    {
        return $this->db->execute(
            'DELETE FROM bookmarks WHERE id = ? AND user_id = ?',
            [$bookmarkId, $userId]
        ) > 0;
    }

    public function countBookmarks(int $userId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM bookmarks WHERE user_id = ?', [$userId]);
    }
}
