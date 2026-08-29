<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * The numbers on the admin dashboard.
 *
 * Everything here is an aggregate query against tables that already exist:
 * there is no analytics pipeline, and there does not need to be one until a
 * library gets large enough for these to hurt.
 */
final class StatisticsRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $row = $this->db->first(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS members,
                (SELECT COUNT(*) FROM users WHERE role = 'librarian') AS librarians,
                (SELECT COUNT(*) FROM books WHERE status = 'published' AND deleted_at IS NULL) AS books,
                (SELECT COUNT(*) FROM book_files WHERE status = 'published') AS files,
                (SELECT COALESCE(SUM(size_bytes), 0) FROM book_files WHERE status = 'published') AS bytes,
                (SELECT COUNT(*) FROM book_requests WHERE status IN ('open', 'claimed')) AS open_requests,
                (SELECT COUNT(*) FROM collections WHERE visibility = 'public' AND parent_id IS NULL) AS collections,
                (SELECT COUNT(*) FROM reviews WHERE status = 'visible') AS reviews,
                (SELECT COALESCE(SUM(download_count), 0) FROM books) AS downloads,
                (SELECT COUNT(*) FROM moderation_requests
                 WHERE status IN ('pending', 'under_review', 'changes_requested')) AS queue
            "
        );

        return array_map(static fn ($value): int => (int) $value, $row ?? []);
    }

    /**
     * A count per day for the last N days, with the empty days filled in so a
     * quiet week does not look like a gap in the data.
     *
     * @return array<string, int> date => count
     */
    public function daily(string $table, string $column, int $days = 30, string $where = ''): array
    {
        $allowed = ['users', 'books', 'book_files', 'reviews', 'moderation_requests', 'book_requests'];

        if (!in_array($table, $allowed, true) || preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('That is not a table this can count.');
        }

        $days = max(1, min(120, $days));
        $clause = $where === '' ? '' : ' AND ' . $where;

        $rows = $this->db->select(
            "SELECT DATE(`{$column}`) AS day, COUNT(*) AS total FROM `{$table}`
             WHERE `{$column}` >= DATE_SUB(CURDATE(), INTERVAL ? DAY){$clause}
             GROUP BY DATE(`{$column}`)",
            [$days]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['day']] = (int) $row['total'];
        }

        $series = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
            $series[$day] = $counts[$day] ?? 0;
        }

        return $series;
    }

    /** @return list<array{name: string, path: string, total: int}> */
    public function topCategories(int $limit = 8): array
    {
        $rows = $this->db->select(
            "SELECT c.name, c.path, COUNT(DISTINCT b.id) AS total
             FROM categories c
             INNER JOIN book_categories bc ON bc.category_id = c.id
             INNER JOIN books b ON b.id = bc.book_id AND b.status = 'published' AND b.deleted_at IS NULL
             GROUP BY c.id ORDER BY total DESC LIMIT ?",
            [max(1, min(50, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'name'  => (string) $row['name'],
            'path'  => (string) $row['path'],
            'total' => (int) $row['total'],
        ], $rows);
    }

    /** @return list<array{title: string, slug: string, downloads: int, views: int}> */
    public function topBooks(int $limit = 8): array
    {
        $rows = $this->db->select(
            "SELECT title, slug, download_count, view_count FROM books
             WHERE status = 'published' AND deleted_at IS NULL
             ORDER BY download_count DESC, view_count DESC LIMIT ?",
            [max(1, min(50, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'title'     => (string) $row['title'],
            'slug'      => (string) $row['slug'],
            'downloads' => (int) $row['download_count'],
            'views'     => (int) $row['view_count'],
        ], $rows);
    }

    /** @return array{decided: int, median_hours: float|null, open_over_a_week: int} */
    public function queueHealth(): array
    {
        $decided = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM moderation_requests WHERE decided_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        );

        $overdue = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM moderation_requests
             WHERE status IN ('pending', 'under_review', 'changes_requested')
               AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
        );

        $rows = $this->db->select(
            'SELECT TIMESTAMPDIFF(MINUTE, created_at, decided_at) AS minutes
             FROM moderation_requests WHERE decided_at IS NOT NULL ORDER BY minutes'
        );

        $median = $rows === [] ? null : round(((int) $rows[(int) floor(count($rows) / 2)]['minutes']) / 60, 1);

        return ['decided' => $decided, 'median_hours' => $median, 'open_over_a_week' => $overdue];
    }
}
