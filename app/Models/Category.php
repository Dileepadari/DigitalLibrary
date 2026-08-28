<?php

declare(strict_types=1);

namespace App\Models;

final class Category
{
    /** @var list<Category> filled by CategoryRepository::tree() */
    public array $children = [];

    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $path,
        public readonly int $depth,
        public readonly ?string $description,
        public readonly string $status,
        public readonly int $bookCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['path'],
            (int) $row['depth'],
            $row['description'] !== null ? (string) $row['description'] : null,
            (string) $row['status'],
            (int) ($row['book_count'] ?? 0),
        );
    }

    /** The path without its wrapping slashes: '/academics/upsc/' becomes 'academics/upsc'. */
    public function relativePath(): string
    {
        return trim($this->path, '/');
    }

    /**
     * Ancestor slugs from the root down, for breadcrumbs, taken from the path
     * rather than from more queries.
     *
     * @return list<string>
     */
    public function ancestorSlugs(): array
    {
        $segments = explode('/', $this->relativePath());
        array_pop($segments);

        return array_values(array_filter($segments));
    }
}
