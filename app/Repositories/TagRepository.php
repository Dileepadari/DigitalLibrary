<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Models\Tag;
use App\Support\Slug;

final class TagRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findBySlug(string $slug, bool $activeOnly = true): ?Tag
    {
        $slug = Slug::make($slug, 70);
        $sql = 'SELECT * FROM tags WHERE slug = ?';

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        $row = $this->db->first($sql, [$slug]);

        if ($row !== null) {
            return Tag::fromRow($row);
        }

        return $this->followAlias($slug, $activeOnly);
    }

    /** An alias such as "sci-fi" resolves to the tag it folds into. */
    private function followAlias(string $slug, bool $activeOnly): ?Tag
    {
        $sql = 'SELECT t.* FROM tag_aliases a INNER JOIN tags t ON t.id = a.tag_id WHERE a.alias = ?';

        if ($activeOnly) {
            $sql .= " AND t.status = 'active'";
        }

        $row = $this->db->first($sql, [$slug]);

        return $row === null ? null : Tag::fromRow($row);
    }

    /** @return list<Tag> */
    public function active(int $limit = 200): array
    {
        return array_map(
            static fn (array $row): Tag => Tag::fromRow($row),
            $this->db->select(
                "SELECT * FROM tags WHERE status = 'active' ORDER BY usage_count DESC, name LIMIT ?",
                [max(1, min(500, $limit))]
            )
        );
    }

    /** @return list<Tag> */
    public function pending(): array
    {
        return array_map(
            static fn (array $row): Tag => Tag::fromRow($row),
            $this->db->select("SELECT * FROM tags WHERE status = 'pending' ORDER BY created_at")
        );
    }

    /**
     * Resolves free text to tag ids, creating what does not exist yet.
     *
     * A tag nobody has approved is created as 'pending': it attaches to the book
     * straight away, but it stays out of the tag list and the suggestions until
     * a librarian activates it. That is what stops the catalogue fragmenting
     * into sci-fi, scifi and science fiction.
     *
     * @param list<string> $names
     *
     * @return list<int>
     */
    public function resolveMany(array $names, ?int $proposedBy, bool $autoApprove = false): array
    {
        $ids = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $slug = Slug::make($name, 70);

            if ($slug === '') {
                continue;
            }

            $existing = $this->findBySlug($slug, false);

            if ($existing !== null) {
                $ids[] = $existing->id;

                continue;
            }

            $ids[] = $this->db->insert('tags', [
                'name'        => $name,
                'slug'        => $slug,
                'status'      => $autoApprove ? 'active' : 'pending',
                'proposed_by' => $proposedBy,
                'approved_by' => $autoApprove ? $proposedBy : null,
            ]);
        }

        return $ids;
    }

    public function setStatus(int $id, string $status, ?int $approvedBy = null): void
    {
        $this->db->execute(
            'UPDATE tags SET status = ?, approved_by = ? WHERE id = ?',
            [$status, $approvedBy, $id]
        );
    }

    public function addAlias(string $alias, int $tagId): bool
    {
        $alias = Slug::make($alias, 70);

        if ($alias === '' || $this->db->scalar('SELECT 1 FROM tags WHERE slug = ?', [$alias]) !== null) {
            return false;
        }

        $this->db->execute(
            'INSERT INTO tag_aliases (alias, tag_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE tag_id = VALUES(tag_id)',
            [$alias, $tagId]
        );

        return true;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM tags WHERE id = ?', [$id]);
    }
}
