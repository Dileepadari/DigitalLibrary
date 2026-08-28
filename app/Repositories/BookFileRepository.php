<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\BookFile;

final class BookFileRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        return $this->db->insert('book_files', $values);
    }

    public function findById(int $id): ?BookFile
    {
        $row = $this->db->first('SELECT * FROM book_files WHERE id = ?', [$id]);

        return $row === null ? null : BookFile::fromRow($row);
    }

    /** @return array{id: int, book_id: int, status: string}|null */
    public function findByHash(string $sha256): ?array
    {
        $row = $this->db->first('SELECT id, book_id, status FROM book_files WHERE sha256 = ?', [$sha256]);

        return $row === null ? null : [
            'id'      => (int) $row['id'],
            'book_id' => (int) $row['book_id'],
            'status'  => (string) $row['status'],
        ];
    }

    /** @return list<BookFile> */
    public function forBook(int $bookId, bool $publishedOnly = true): array
    {
        $sql = 'SELECT * FROM book_files WHERE book_id = ?';

        if ($publishedOnly) {
            $sql .= " AND status = 'published'";
        }

        return array_map(
            static fn (array $row): BookFile => BookFile::fromRow($row),
            $this->db->select($sql . ' ORDER BY is_primary DESC, id', [$bookId])
        );
    }

    public function countFor(int $bookId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM book_files WHERE book_id = ?', [$bookId]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute(
            'UPDATE book_files SET status = ?, rejected_at = ? WHERE id = ?',
            [$status, $status === 'rejected' ? gmdate('Y-m-d H:i:s') : null, $id]
        );
    }

    public function setPath(int $id, string $path): void
    {
        $this->db->execute('UPDATE book_files SET storage_path = ? WHERE id = ?', [$path, $id]);
    }

    public function incrementDownloads(int $id): void
    {
        $this->db->execute('UPDATE book_files SET download_count = download_count + 1 WHERE id = ?', [$id]);
        $this->db->execute(
            'UPDATE books b SET download_count = download_count + 1
             WHERE b.id = (SELECT book_id FROM book_files WHERE id = ?)',
            [$id]
        );
    }

    /** The extracted text feeds the FULLTEXT index on book_texts. */
    public function storeText(int $bookId, string $content): void
    {
        $this->db->execute(
            'INSERT INTO book_texts (book_id, content) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content)',
            [$bookId, $content]
        );
    }

    /**
     * Rejected files past the grace window, for the quarantine sweep.
     *
     * @return list<array{id: int, storage_path: string, size_bytes: int, uploaded_by: int|null}>
     */
    public function rejectedBefore(string $timestamp): array
    {
        $rows = $this->db->select(
            "SELECT id, storage_path, size_bytes, uploaded_by FROM book_files
             WHERE status = 'rejected' AND rejected_at IS NOT NULL AND rejected_at < ?",
            [$timestamp]
        );

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'storage_path' => (string) $row['storage_path'],
            'size_bytes'   => (int) $row['size_bytes'],
            'uploaded_by'  => $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null,
        ], $rows);
    }

    /**
     * PDFs on records that have no cover yet, for the backfill command.
     *
     * @return list<array{book_id: int, storage_path: string, sha256: string}>
     */
    public function pdfsWithoutCover(int $limit = 200): array
    {
        $rows = $this->db->select(
            "SELECT f.book_id, f.storage_path, f.sha256 FROM book_files f
             INNER JOIN books b ON b.id = f.book_id
             WHERE f.format = 'pdf' AND b.cover_path IS NULL AND b.deleted_at IS NULL
             GROUP BY f.book_id
             ORDER BY f.book_id LIMIT ?",
            [max(1, min(1000, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'book_id'      => (int) $row['book_id'],
            'storage_path' => (string) $row['storage_path'],
            'sha256'       => (string) $row['sha256'],
        ], $rows);
    }

    /** @return list<array{id: int, storage_path: string, sha256: string, status: string}> */
    public function all(): array
    {
        $rows = $this->db->select('SELECT id, storage_path, sha256, status FROM book_files ORDER BY id');

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'storage_path' => (string) $row['storage_path'],
            'sha256'       => (string) $row['sha256'],
            'status'       => (string) $row['status'],
        ], $rows);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM book_files WHERE id = ?', [$id]);
    }

    /**
     * Duplicate candidates for the reviewer: same title, or the same first
     * author, on a different record.
     *
     * @return list<array{id: int, title: string, slug: string, status: string}>
     */
    public function similarBooks(int $bookId, string $title, int $limit = 5): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT b.id, b.title, b.slug, b.status FROM books b
             LEFT JOIN book_authors ba ON ba.book_id = b.id
             WHERE b.id <> ? AND b.deleted_at IS NULL AND (
                 b.title = ?
                 OR b.title LIKE ?
                 OR ba.author_id IN (SELECT author_id FROM book_authors WHERE book_id = ?)
             )
             ORDER BY b.id LIMIT ?',
            [$bookId, $title, mb_substr($title, 0, 12) . '%', $bookId, max(1, min(20, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'id'     => (int) $row['id'],
            'title'  => (string) $row['title'],
            'slug'   => (string) $row['slug'],
            'status' => (string) $row['status'],
        ], $rows);
    }
}
