<?php

declare(strict_types=1);

namespace App\Models;

final class Tag
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $status,
        public readonly int $usageCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['status'],
            (int) ($row['usage_count'] ?? 0),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
