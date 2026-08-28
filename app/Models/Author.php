<?php

declare(strict_types=1);

namespace App\Models;

final class Author
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $bio = null,
        public readonly string $role = 'author',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            isset($row['bio']) ? (string) $row['bio'] : null,
            isset($row['role']) ? (string) $row['role'] : 'author',
        );
    }
}
