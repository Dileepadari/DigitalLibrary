<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\Category;
use App\Support\Slug;

/**
 * The category tree.
 *
 * Every row carries a materialised `path` like `/academics/competitive-exams/`,
 * so a subtree is one indexed prefix match and a breadcrumb is string work
 * rather than a walk up the parent chain.
 */
final class CategoryRepository
{
    public const MAX_DEPTH = 8;

    public function __construct(private readonly Db $db)
    {
    }

    public function findByPath(string $path, bool $activeOnly = true): ?Category
    {
        $path = '/' . trim($path, '/') . '/';
        $sql = 'SELECT * FROM categories WHERE path = ?';

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        $row = $this->db->first($sql, [$path]);

        return $row === null ? null : Category::fromRow($row);
    }

    public function findById(int $id): ?Category
    {
        $row = $this->db->first('SELECT * FROM categories WHERE id = ?', [$id]);

        return $row === null ? null : Category::fromRow($row);
    }

    /** @return list<Category> */
    public function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM categories';

        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }

        return array_map(
            static fn (array $row): Category => Category::fromRow($row),
            $this->db->select($sql . ' ORDER BY path')
        );
    }

    /** @return list<Category> the roots, each with its children filled in, to any depth */
    public function tree(bool $activeOnly = true): array
    {
        $categories = $this->all($activeOnly);
        $byId = [];

        foreach ($categories as $category) {
            $byId[$category->id] = $category;
        }

        $roots = [];

        foreach ($categories as $category) {
            if ($category->parentId !== null && isset($byId[$category->parentId])) {
                $byId[$category->parentId]->children[] = $category;
            } else {
                $roots[] = $category;
            }
        }

        return $roots;
    }

    /** @return list<Category> the direct children of a path */
    public function childrenOf(int $id, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM categories WHERE parent_id = ?';

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        return array_map(
            static fn (array $row): Category => Category::fromRow($row),
            $this->db->select($sql . ' ORDER BY sort_order, name', [$id])
        );
    }

    /**
     * The ancestors of a category, root first, read out of its path in one
     * query rather than one per level.
     *
     * @return list<Category>
     */
    public function ancestorsOf(Category $category): array
    {
        $paths = [];
        $prefix = '';

        foreach (explode('/', $category->relativePath()) as $segment) {
            $prefix .= '/' . $segment;
            $paths[] = $prefix . '/';
        }

        array_pop($paths);

        if ($paths === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($paths), '?'));

        return array_map(
            static fn (array $row): Category => Category::fromRow($row),
            $this->db->select("SELECT * FROM categories WHERE path IN ({$placeholders}) ORDER BY depth", $paths)
        );
    }

    /** @return list<Category> */
    public function pending(): array
    {
        return array_map(
            static fn (array $row): Category => Category::fromRow($row),
            $this->db->select("SELECT * FROM categories WHERE status = 'pending' ORDER BY created_at")
        );
    }

    /**
     * @throws \RuntimeException when the parent is missing or the tree is too deep
     */
    public function create(string $name, ?int $parentId, ?string $description, string $status, ?int $proposedBy): int
    {
        $parent = $parentId === null ? null : $this->findById($parentId);

        if ($parentId !== null && $parent === null) {
            throw new \RuntimeException('That parent category does not exist.');
        }

        $depth = $parent === null ? 0 : $parent->depth + 1;

        if ($depth >= self::MAX_DEPTH) {
            throw new \RuntimeException('Categories cannot nest more than ' . self::MAX_DEPTH . ' deep.');
        }

        $base = $parent === null ? '/' : $parent->path;
        $slug = Slug::unique(
            $name,
            fn (string $candidate): bool => $this->db->scalar(
                'SELECT 1 FROM categories WHERE path = ? LIMIT 1',
                [$base . $candidate . '/']
            ) !== null,
            140
        );

        return $this->db->insert('categories', [
            'parent_id'   => $parentId,
            'name'        => $name,
            'slug'        => $slug,
            'path'        => $base . $slug . '/',
            'depth'       => $depth,
            'description' => $description,
            'status'      => $status,
            'proposed_by' => $proposedBy,
        ]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute('UPDATE categories SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function delete(int $id): void
    {
        // The self referencing foreign key cascades, so the subtree goes too.
        $this->db->execute('DELETE FROM categories WHERE id = ?', [$id]);
    }
}
