<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Support\Slug;

final class PublisherRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findOrCreate(string $name): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $slug = Slug::make($name, 180);

        if ($slug === '') {
            $slug = 'publisher-' . substr(hash('sha256', $name), 0, 12);
        }

        $id = $this->db->scalar('SELECT id FROM publishers WHERE slug = ?', [$slug]);

        if ($id !== null) {
            return (int) $id;
        }

        return $this->db->insert('publishers', ['name' => $name, 'slug' => $slug]);
    }
}
