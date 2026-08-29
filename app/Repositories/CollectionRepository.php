<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\Author;
use App\Models\Book;
use App\Models\Collection;
use App\Support\Slug;
use App\Support\Visibility;

/**
 * The collection tree and the books hanging off it.
 *
 * Every node carries a materialised `path`, so a subtree is one indexed prefix
 * match, and a `root_id`, so anything that applies to a whole collection
 * (publishing it, following it, deleting it) is one query rather than a walk.
 */
final class CollectionRepository
{
    public const MAX_DEPTH = 8;

    private const COLUMNS = 'c.id, c.parent_id, c.root_id, c.owner_id, c.name, c.slug, c.path, c.depth,
        c.description, c.visibility, c.review_status, c.item_count, c.follower_count, c.forked_from_id,
        c.created_at, u.username AS owner_name';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id): ?Collection
    {
        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM collections c LEFT JOIN users u ON u.id = c.owner_id
             WHERE c.id = ?',
            [$id]
        );

        return $row === null ? null : Collection::fromRow($row);
    }

    public function findByPath(string $path): ?Collection
    {
        $path = '/' . trim($path, '/') . '/';

        $row = $this->db->first(
            'SELECT ' . self::COLUMNS . ' FROM collections c LEFT JOIN users u ON u.id = c.owner_id
             WHERE c.path = ?',
            [$path]
        );

        return $row === null ? null : Collection::fromRow($row);
    }

    /**
     * Public collections, most followed first, for the index page.
     *
     * @return array{rows: list<Collection>, total: int, page: int, pages: int}
     */
    public function paginatePublic(int $page = 1, int $perPage = 24): array
    {
        $where = "c.parent_id IS NULL AND c.visibility = 'public' AND c.review_status = 'approved'";
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM collections c WHERE {$where}");

        $perPage = max(1, min(96, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM collections c LEFT JOIN users u ON u.id = c.owner_id
             WHERE {$where} ORDER BY c.follower_count DESC, c.item_count DESC, c.id DESC LIMIT ? OFFSET ?",
            [$perPage, ($page - 1) * $perPage]
        );

        return [
            'rows'  => array_map(static fn (array $row): Collection => Collection::fromRow($row), $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /** @return list<Collection> every root a person owns or maintains */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM collections c
             LEFT JOIN users u ON u.id = c.owner_id
             LEFT JOIN collection_maintainers m ON m.collection_id = c.id AND m.user_id = ?
             WHERE c.parent_id IS NULL AND (c.owner_id = ? OR m.user_id IS NOT NULL)
             ORDER BY c.name',
            [$userId, $userId]
        );

        return array_map(static fn (array $row): Collection => Collection::fromRow($row), $rows);
    }

    /** @return list<Collection> the roots someone follows */
    public function followedBy(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM collection_followers f
             INNER JOIN collections c ON c.id = f.collection_id
             LEFT JOIN users u ON u.id = c.owner_id
             WHERE f.user_id = ? ORDER BY c.name',
            [$userId]
        );

        return array_map(static fn (array $row): Collection => Collection::fromRow($row), $rows);
    }

    /** @return list<Collection> the direct children of a node */
    public function childrenOf(int $id): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM collections c LEFT JOIN users u ON u.id = c.owner_id
             WHERE c.parent_id = ? ORDER BY c.sort_order, c.name',
            [$id]
        );

        return array_map(static fn (array $row): Collection => Collection::fromRow($row), $rows);
    }

    /**
     * A whole tree, nested, from one query.
     *
     * @return list<Collection> the root, with children filled in to any depth
     */
    public function tree(int $rootId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM collections c LEFT JOIN users u ON u.id = c.owner_id
             WHERE c.root_id = ? ORDER BY c.depth, c.sort_order, c.name',
            [$rootId]
        );

        $nodes = [];

        foreach ($rows as $row) {
            $nodes[(int) $row['id']] = Collection::fromRow($row);
        }

        $roots = [];

        foreach ($nodes as $node) {
            if ($node->parentId !== null && isset($nodes[$node->parentId])) {
                $nodes[$node->parentId]->children[] = $node;
            } else {
                $roots[] = $node;
            }
        }

        return $roots;
    }

    /**
     * The ancestors of a node, root first, read out of its path.
     *
     * @return list<Collection>
     */
    public function ancestorsOf(Collection $collection): array
    {
        $paths = [];
        $prefix = '';

        foreach (explode('/', $collection->relativePath()) as $segment) {
            $prefix .= '/' . $segment;
            $paths[] = $prefix . '/';
        }

        array_pop($paths);

        if ($paths === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($paths), '?'));
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM collections c LEFT JOIN users u ON u.id = c.owner_id
             WHERE c.path IN ({$placeholders}) ORDER BY c.depth",
            $paths
        );

        return array_map(static fn (array $row): Collection => Collection::fromRow($row), $rows);
    }

    /** @param array<string, mixed> $values */
    public function insert(array $values): int
    {
        return $this->db->insert('collections', $values);
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

        $this->db->execute("UPDATE collections SET {$assignments} WHERE id = :id", [...$values, 'id' => $id]);
    }

    /**
     * Applies a change to every node of a tree, such as making it all public.
     *
     * @param array<string, mixed> $values
     */
    public function updateTree(int $rootId, array $values): void
    {
        $assignments = implode(', ', array_map(
            static fn (string $column): string => "`{$column}` = :{$column}",
            array_keys($values)
        ));

        $this->db->execute(
            "UPDATE collections SET {$assignments} WHERE root_id = :root_id",
            [...$values, 'root_id' => $rootId]
        );
    }

    public function delete(int $id): void
    {
        // The self referencing foreign key cascades, so the subtree goes too.
        $this->db->execute('DELETE FROM collections WHERE id = ?', [$id]);
    }

    public function pathTaken(string $path): bool
    {
        return $this->db->scalar('SELECT 1 FROM collections WHERE path = ? LIMIT 1', [$path]) !== null;
    }

    /** A free slug for a child of this parent path. */
    public function uniqueSlug(string $name, string $parentPath): string
    {
        return Slug::unique(
            $name,
            fn (string $candidate): bool => $this->pathTaken($parentPath . $candidate . '/'),
            140
        );
    }

    /**
     * The books pinned to one node, in the order the curator put them.
     *
     * @return list<array{book: Book, note: string|null, position: int}>
     */
    public function items(int $collectionId): array
    {
        $rows = $this->db->select(
            'SELECT i.position, i.note, b.*, p.name AS publisher_name FROM collection_items i
             INNER JOIN books b ON b.id = i.book_id
             LEFT JOIN publishers p ON p.id = b.publisher_id
             WHERE i.collection_id = ? AND b.deleted_at IS NULL
             ORDER BY i.position, i.created_at',
            [$collectionId]
        );

        $items = array_map(static fn (array $row): array => [
            'book'     => Book::fromRow($row),
            'note'     => $row['note'] !== null ? (string) $row['note'] : null,
            'position' => (int) $row['position'],
        ], $rows);

        $this->attachAuthors(array_map(static fn (array $item): Book => $item['book'], $items));

        return $items;
    }

    /**
     * One query for the authors of a page of books, rather than none at all:
     * without this every book on a collection page reads "Unknown author".
     *
     * @param list<Book> $books
     */
    private function attachAuthors(array $books): void
    {
        if ($books === []) {
            return;
        }

        $byId = [];

        foreach ($books as $book) {
            $byId[$book->id] = $book;
        }

        $ids = array_keys($byId);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $rows = $this->db->select(
            "SELECT ba.book_id, a.id, a.name, a.slug, a.bio, ba.role FROM book_authors ba
             INNER JOIN authors a ON a.id = ba.author_id
             WHERE ba.book_id IN ({$placeholders}) ORDER BY ba.position, a.name",
            $ids
        );

        foreach ($rows as $row) {
            $byId[(int) $row['book_id']]->authors[] = Author::fromRow($row);
        }
    }

    public function addItem(int $collectionId, int $bookId, ?int $addedBy, ?string $note = null): bool
    {
        $next = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM collection_items WHERE collection_id = ?',
            [$collectionId]
        );

        $added = $this->db->execute(
            'INSERT IGNORE INTO collection_items (collection_id, book_id, position, note, added_by)
             VALUES (?, ?, ?, ?, ?)',
            [$collectionId, $bookId, $next, $note, $addedBy]
        ) > 0;

        if ($added) {
            $this->refreshCounts($collectionId);
        }

        return $added;
    }

    public function removeItem(int $collectionId, int $bookId): void
    {
        $this->db->execute(
            'DELETE FROM collection_items WHERE collection_id = ? AND book_id = ?',
            [$collectionId, $bookId]
        );
        $this->refreshCounts($collectionId);
    }

    /** Swaps a book with its neighbour, which is reordering without JavaScript. */
    public function moveItem(int $collectionId, int $bookId, int $direction): void
    {
        $items = $this->db->select(
            'SELECT book_id FROM collection_items WHERE collection_id = ? ORDER BY position, created_at',
            [$collectionId]
        );

        $order = array_map(static fn (array $row): int => (int) $row['book_id'], $items);
        $index = array_search($bookId, $order, true);

        if ($index === false) {
            return;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($order)) {
            return;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        foreach ($order as $position => $id) {
            $this->db->execute(
                'UPDATE collection_items SET position = ? WHERE collection_id = ? AND book_id = ?',
                [$position, $collectionId, $id]
            );
        }
    }

    public function containsBook(int $collectionId, int $bookId): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM collection_items WHERE collection_id = ? AND book_id = ? LIMIT 1',
            [$collectionId, $bookId]
        ) !== null;
    }

    /** @return list<int> */
    public function maintainerIds(int $rootId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->db->select('SELECT user_id FROM collection_maintainers WHERE collection_id = ?', [$rootId])
        );
    }

    /** @return list<array{id: int, username: string}> */
    public function maintainers(int $rootId): array
    {
        $rows = $this->db->select(
            'SELECT u.id, u.username FROM collection_maintainers m
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.collection_id = ? ORDER BY u.username',
            [$rootId]
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'username' => (string) $row['username']],
            $rows
        );
    }

    public function addMaintainer(int $rootId, int $userId, ?int $invitedBy): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO collection_maintainers (collection_id, user_id, invited_by) VALUES (?, ?, ?)',
            [$rootId, $userId, $invitedBy]
        );
    }

    public function removeMaintainer(int $rootId, int $userId): void
    {
        $this->db->execute(
            'DELETE FROM collection_maintainers WHERE collection_id = ? AND user_id = ?',
            [$rootId, $userId]
        );
    }

    /** @return bool true when the follow was added, false when it was removed */
    public function toggleFollow(int $rootId, int $userId): bool
    {
        $existing = $this->db->scalar(
            'SELECT 1 FROM collection_followers WHERE collection_id = ? AND user_id = ?',
            [$rootId, $userId]
        );

        if ($existing !== null) {
            $this->db->execute(
                'DELETE FROM collection_followers WHERE collection_id = ? AND user_id = ?',
                [$rootId, $userId]
            );
            $this->refreshFollowerCount($rootId);

            return false;
        }

        $this->db->execute(
            'INSERT IGNORE INTO collection_followers (collection_id, user_id) VALUES (?, ?)',
            [$rootId, $userId]
        );
        $this->refreshFollowerCount($rootId);

        return true;
    }

    public function isFollowing(int $rootId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->db->scalar(
            'SELECT 1 FROM collection_followers WHERE collection_id = ? AND user_id = ?',
            [$rootId, $userId]
        ) !== null;
    }

    /** @return list<int> */
    public function followerIds(int $rootId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->db->select('SELECT user_id FROM collection_followers WHERE collection_id = ?', [$rootId])
        );
    }

    /**
     * Every node's `item_count` is its own books plus everything below it, the
     * same rule as category counts: it is what clicking the node shows.
     */
    public function refreshCounts(int $collectionId): void
    {
        $rootId = (int) $this->db->scalar(
            'SELECT COALESCE(root_id, id) FROM collections WHERE id = ?',
            [$collectionId]
        );

        if ($rootId === 0) {
            return;
        }

        $this->db->execute(
            "UPDATE collections c
             INNER JOIN (
                 SELECT node.id, COUNT(DISTINCT i.book_id) AS total
                 FROM collections node
                 LEFT JOIN collections descendant
                     ON descendant.path = node.path OR descendant.path LIKE CONCAT(node.path, '%')
                 LEFT JOIN collection_items i ON i.collection_id = descendant.id
                 WHERE node.root_id = ?
                 GROUP BY node.id
             ) counted ON counted.id = c.id
             SET c.item_count = counted.total",
            [$rootId]
        );
    }

    private function refreshFollowerCount(int $rootId): void
    {
        $this->db->execute(
            'UPDATE collections SET follower_count = (
                 SELECT COUNT(*) FROM collection_followers WHERE collection_id = ?
             ) WHERE id = ?',
            [$rootId, $rootId]
        );
    }
}
