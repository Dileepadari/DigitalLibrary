<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookFile;
use App\Models\Category;
use App\Models\Tag;
use App\Support\BookStatus;

/**
 * Every query against `books` and the tables hanging off it.
 *
 * Lists are hydrated in batches: one query for the books, then one each for
 * authors, tags and files across all of them, rather than three per row.
 */
final class BookRepository
{
    private const COLUMNS = 'b.id, b.title, b.subtitle, b.slug, b.published_year, b.edition, b.language,
        b.isbn10, b.isbn13, b.description, b.content_type, b.licence, b.licence_note, b.source_url,
        b.cover_path, b.page_count, b.status, b.added_by, b.published_at, b.view_count, b.download_count,
        b.rating_average, b.rating_count, b.created_at, p.name AS publisher_name';

    public function __construct(private readonly Db $db)
    {
    }

    public function findBySlug(string $slug, bool $publishedOnly = true): ?Book
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM books b
                LEFT JOIN publishers p ON p.id = b.publisher_id
                WHERE b.slug = ? AND b.deleted_at IS NULL';
        $bindings = [$slug];

        if ($publishedOnly) {
            $sql .= " AND b.status = 'published'";
        }

        $row = $this->db->first($sql, $bindings);

        if ($row === null) {
            return null;
        }

        $books = $this->hydrate([Book::fromRow($row)]);

        return $books[0];
    }

    public function findById(int $id, bool $publishedOnly = false): ?Book
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM books b
                LEFT JOIN publishers p ON p.id = b.publisher_id
                WHERE b.id = ? AND b.deleted_at IS NULL';

        if ($publishedOnly) {
            $sql .= " AND b.status = 'published'";
        }

        $row = $this->db->first($sql, [$id]);

        return $row === null ? null : $this->hydrate([Book::fromRow($row)])[0];
    }

    public function slugTaken(string $slug): bool
    {
        return $this->db->scalar('SELECT 1 FROM books WHERE slug = ? LIMIT 1', [$slug]) !== null;
    }

    /**
     * @param array<string, string> $filters q, category, tag, language, type, year_from, year_to, sort, status
     *
     * @return array{rows: list<Book>, total: int, page: int, pages: int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        [$where, $joins, $bindings] = $this->conditions($filters);

        $total = (int) $this->db->scalar(
            "SELECT COUNT(DISTINCT b.id) FROM books b {$joins} WHERE {$where}",
            $bindings
        );

        $perPage = max(1, min(96, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $order = $this->order((string) ($filters['sort'] ?? ''), (string) ($filters['q'] ?? ''));
        $orderBindings = $order['bindings'];

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM books b
             LEFT JOIN publishers p ON p.id = b.publisher_id
             {$joins}
             WHERE {$where}
             GROUP BY b.id
             ORDER BY {$order['sql']}
             LIMIT ? OFFSET ?",
            [...$bindings, ...$orderBindings, $perPage, ($page - 1) * $perPage]
        );

        return [
            'rows'  => $this->hydrate(array_map(static fn (array $row): Book => Book::fromRow($row), $rows)),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Counts for the browse sidebar, over the same filter as the results but
     * ignoring the facet being counted, so a chosen facet still shows its
     * alternatives.
     *
     * @param array<string, string> $filters
     *
     * @return array{content_type: array<string, int>, language: array<string, int>}
     */
    public function facets(array $filters = []): array
    {
        return [
            'content_type' => $this->facet('b.content_type', array_diff_key($filters, ['type' => ''])),
            'language'     => $this->facet('b.language', array_diff_key($filters, ['language' => ''])),
        ];
    }

    /** @return list<Book> */
    public function recent(int $limit = 8): array
    {
        return $this->search(['sort' => 'recent'], 1, $limit)['rows'];
    }

    /** @return list<Book> */
    public function popular(int $limit = 8): array
    {
        return $this->search(['sort' => 'popular'], 1, $limit)['rows'];
    }

    public function countPublished(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM books WHERE status = 'published' AND deleted_at IS NULL"
        );
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        return $this->db->insert('books', $values);
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

        $this->db->execute(
            "UPDATE books SET {$assignments} WHERE id = :id",
            [...$values, 'id' => $id]
        );
    }

    public function setStatus(int $id, BookStatus $status): void
    {
        $this->db->execute(
            'UPDATE books SET status = ?, published_at = CASE WHEN ? = \'published\' AND published_at IS NULL
                THEN ? ELSE published_at END WHERE id = ?',
            [$status->value, $status->value, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->db->execute('UPDATE books SET deleted_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
    }

    public function incrementViews(int $id): void
    {
        $this->db->execute('UPDATE books SET view_count = view_count + 1 WHERE id = ?', [$id]);
    }

    /** @param list<array{id: int, role: string}> $authors */
    public function syncAuthors(int $bookId, array $authors): void
    {
        $this->db->execute('DELETE FROM book_authors WHERE book_id = ?', [$bookId]);

        $position = 0;

        foreach ($authors as $author) {
            $this->db->execute(
                'INSERT IGNORE INTO book_authors (book_id, author_id, role, position) VALUES (?, ?, ?, ?)',
                [$bookId, $author['id'], $author['role'], $position++]
            );
        }
    }

    /** @param list<int> $categoryIds */
    public function syncCategories(int $bookId, array $categoryIds): void
    {
        $this->db->execute('DELETE FROM book_categories WHERE book_id = ?', [$bookId]);

        foreach (array_unique($categoryIds) as $categoryId) {
            $this->db->execute(
                'INSERT IGNORE INTO book_categories (book_id, category_id) VALUES (?, ?)',
                [$bookId, $categoryId]
            );
        }
    }

    /** @param list<int> $tagIds */
    public function syncTags(int $bookId, array $tagIds): void
    {
        $this->db->execute('DELETE FROM book_tags WHERE book_id = ?', [$bookId]);

        foreach (array_unique($tagIds) as $tagId) {
            $this->db->execute('INSERT IGNORE INTO book_tags (book_id, tag_id) VALUES (?, ?)', [$bookId, $tagId]);
        }
    }

    /**
     * Recounts the denormalised counters. Cheap enough to run after any change
     * that could move a book in or out of a category.
     *
     * A category counts its whole subtree, because that is what clicking it
     * shows: a parent reading 0 next to children reading 12 would be a lie
     * about the same query.
     */
    public function refreshCounters(): void
    {
        // The subtree count has to come from a derived table: MySQL refuses a
        // subquery that reads the same table the UPDATE is writing (error 1093).
        $this->db->execute(
            "UPDATE categories c
             INNER JOIN (
                 SELECT node.id, COUNT(DISTINCT b.id) AS total
                 FROM categories node
                 LEFT JOIN categories descendant
                     ON descendant.path = node.path OR descendant.path LIKE CONCAT(node.path, '%')
                 LEFT JOIN book_categories bc ON bc.category_id = descendant.id
                 LEFT JOIN books b
                     ON b.id = bc.book_id AND b.status = 'published' AND b.deleted_at IS NULL
                 GROUP BY node.id
             ) counted ON counted.id = c.id
             SET c.book_count = counted.total"
        );

        $this->db->execute(
            "UPDATE tags t SET usage_count = (
                SELECT COUNT(*) FROM book_tags bt
                INNER JOIN books b ON b.id = bt.book_id
                WHERE bt.tag_id = t.id AND b.status = 'published' AND b.deleted_at IS NULL
            )"
        );
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{0: string, 1: string, 2: list<mixed>} where, joins, bindings
     */
    private function conditions(array $filters): array
    {
        $where = ['b.deleted_at IS NULL'];
        $joins = '';
        $bindings = [];

        $status = (string) ($filters['status'] ?? 'published');

        if ($status === 'any') {
            $where[] = "b.status <> 'draft'";
        } else {
            $where[] = 'b.status = ?';
            $bindings[] = $status;
        }

        $query = trim((string) ($filters['q'] ?? ''));

        if ($query !== '') {
            $where[] = '(MATCH(b.title, b.subtitle, b.description) AGAINST (? IN BOOLEAN MODE)
                OR b.title LIKE ?
                OR EXISTS (
                    SELECT 1 FROM book_authors ba
                    INNER JOIN authors a ON a.id = ba.author_id
                    WHERE ba.book_id = b.id AND a.name LIKE ?
                ))';
            $bindings[] = self::booleanQuery($query);
            $bindings[] = '%' . $query . '%';
            $bindings[] = '%' . $query . '%';
        }

        if (($filters['category'] ?? '') !== '') {
            $path = '/' . trim((string) $filters['category'], '/') . '/';
            $joins .= ' INNER JOIN book_categories bc ON bc.book_id = b.id
                        INNER JOIN categories c ON c.id = bc.category_id';
            $where[] = '(c.path = ? OR c.path LIKE ?)';
            $bindings[] = $path;
            $bindings[] = $path . '%';
        }

        if (($filters['tag'] ?? '') !== '') {
            $joins .= ' INNER JOIN book_tags bt ON bt.book_id = b.id
                        INNER JOIN tags t ON t.id = bt.tag_id';
            $where[] = 't.slug = ?';
            $bindings[] = (string) $filters['tag'];
        }

        if (($filters['language'] ?? '') !== '') {
            $where[] = 'b.language = ?';
            $bindings[] = (string) $filters['language'];
        }

        if (($filters['type'] ?? '') !== '') {
            $where[] = 'b.content_type = ?';
            $bindings[] = (string) $filters['type'];
        }

        if (($filters['year_from'] ?? '') !== '') {
            $where[] = 'b.published_year >= ?';
            $bindings[] = (int) $filters['year_from'];
        }

        if (($filters['year_to'] ?? '') !== '') {
            $where[] = 'b.published_year <= ?';
            $bindings[] = (int) $filters['year_to'];
        }

        return [implode(' AND ', $where), $joins, $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function order(string $sort, string $query): array
    {
        return match ($sort) {
            'title'   => ['sql' => 'b.title ASC', 'bindings' => []],
            'oldest'  => ['sql' => 'b.created_at ASC', 'bindings' => []],
            'popular' => ['sql' => 'b.view_count DESC, b.id DESC', 'bindings' => []],
            'rating'  => ['sql' => 'b.rating_average DESC, b.rating_count DESC', 'bindings' => []],
            'year'    => ['sql' => 'b.published_year DESC, b.title ASC', 'bindings' => []],
            default   => $query === ''
                ? ['sql' => 'b.published_at DESC, b.id DESC', 'bindings' => []]
                : [
                    'sql'      => 'MATCH(b.title, b.subtitle, b.description) AGAINST (? IN BOOLEAN MODE) DESC,
                                   b.view_count DESC',
                    'bindings' => [self::booleanQuery($query)],
                ],
        };
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<string, int>
     */
    private function facet(string $column, array $filters): array
    {
        [$where, $joins, $bindings] = $this->conditions($filters);

        $counts = [];

        foreach (
            $this->db->select(
                "SELECT {$column} AS value, COUNT(DISTINCT b.id) AS total FROM books b {$joins}
             WHERE {$where} GROUP BY {$column} ORDER BY total DESC",
                $bindings
            ) as $row
        ) {
            $counts[(string) $row['value']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Turns what someone typed into something FULLTEXT boolean mode accepts:
     * operators are stripped and each word gets a trailing * so a prefix
     * matches. Short words fall through to the LIKE clause instead, because
     * MySQL's minimum word length would drop them.
     */
    public static function booleanQuery(string $query): string
    {
        $cleaned = preg_replace('/[+\-><()~*"@]+/', ' ', $query) ?? $query;
        $words = preg_split('/\s+/', trim($cleaned)) ?: [];
        $terms = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $terms[] = $word . '*';
            }
        }

        return implode(' ', $terms);
    }

    /**
     * @param list<Book> $books
     *
     * @return list<Book>
     */
    private function hydrate(array $books): array
    {
        if ($books === []) {
            return [];
        }

        $ids = array_map(static fn (Book $book): int => $book->id, $books);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $byId = [];

        foreach ($books as $book) {
            $byId[$book->id] = $book;
        }

        foreach (
            $this->db->select(
                "SELECT ba.book_id, a.id, a.name, a.slug, a.bio, ba.role FROM book_authors ba
             INNER JOIN authors a ON a.id = ba.author_id
             WHERE ba.book_id IN ({$placeholders}) ORDER BY ba.position, a.name",
                $ids
            ) as $row
        ) {
            $byId[(int) $row['book_id']]->authors[] = Author::fromRow($row);
        }

        foreach (
            $this->db->select(
                "SELECT bc.book_id, c.* FROM book_categories bc
             INNER JOIN categories c ON c.id = bc.category_id
             WHERE bc.book_id IN ({$placeholders}) ORDER BY c.path",
                $ids
            ) as $row
        ) {
            $byId[(int) $row['book_id']]->categories[] = Category::fromRow($row);
        }

        foreach (
            $this->db->select(
                "SELECT bt.book_id, t.* FROM book_tags bt
             INNER JOIN tags t ON t.id = bt.tag_id
             WHERE bt.book_id IN ({$placeholders}) ORDER BY t.name",
                $ids
            ) as $row
        ) {
            $byId[(int) $row['book_id']]->tags[] = Tag::fromRow($row);
        }

        foreach (
            $this->db->select(
                "SELECT * FROM book_files WHERE book_id IN ({$placeholders}) ORDER BY is_primary DESC, id",
                $ids
            ) as $row
        ) {
            $byId[(int) $row['book_id']]->files[] = BookFile::fromRow($row);
        }

        return $books;
    }
}
