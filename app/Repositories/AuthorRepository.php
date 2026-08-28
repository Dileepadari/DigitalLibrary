<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\Author;
use App\Support\Slug;

final class AuthorRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findBySlug(string $slug): ?Author
    {
        $row = $this->db->first('SELECT * FROM authors WHERE slug = ?', [$slug]);

        return $row === null ? null : Author::fromRow($row);
    }

    /**
     * Finds an author by name or creates one. Names arrive as free text from a
     * form, so this is the only place an author row is made.
     */
    public function findOrCreate(string $name): Author
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $slug = Slug::make($name, 180);

        if ($slug === '') {
            $slug = 'author-' . substr(hash('sha256', $name), 0, 12);
        }

        $existing = $this->findBySlug($slug);

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->db->insert('authors', ['name' => $name, 'slug' => $slug]);

        return new Author($id, $name, $slug);
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{id: int, role: string}>
     */
    public function resolveMany(array $names, string $role = 'author'): array
    {
        $resolved = [];

        foreach ($names as $name) {
            if (trim($name) === '') {
                continue;
            }

            $resolved[] = ['id' => $this->findOrCreate($name)->id, 'role' => $role];
        }

        return $resolved;
    }

    /** @return list<Author> */
    public function search(string $term, int $limit = 20): array
    {
        return array_map(
            static fn (array $row): Author => Author::fromRow($row),
            $this->db->select(
                'SELECT * FROM authors WHERE name LIKE ? ORDER BY name LIMIT ?',
                ['%' . $term . '%', max(1, min(100, $limit))]
            )
        );
    }
}
