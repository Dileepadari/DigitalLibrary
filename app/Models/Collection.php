<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Visibility;

final class Collection
{
    /** @var list<Collection> filled by CollectionRepository::tree() */
    public array $children = [];

    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly ?int $rootId,
        public readonly ?int $ownerId,
        public readonly ?string $ownerName,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $path,
        public readonly int $depth,
        public readonly ?string $description,
        public readonly Visibility $visibility,
        public readonly string $reviewStatus,
        public readonly int $itemCount,
        public readonly int $followerCount,
        public readonly ?int $forkedFromId,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            $row['root_id'] !== null ? (int) $row['root_id'] : null,
            $row['owner_id'] !== null ? (int) $row['owner_id'] : null,
            isset($row['owner_name']) ? (string) $row['owner_name'] : null,
            (string) $row['name'],
            (string) $row['slug'],
            (string) $row['path'],
            (int) $row['depth'],
            $row['description'] !== null ? (string) $row['description'] : null,
            Visibility::from((string) $row['visibility']),
            (string) $row['review_status'],
            (int) $row['item_count'],
            (int) $row['follower_count'],
            $row['forked_from_id'] !== null ? (int) $row['forked_from_id'] : null,
            (string) $row['created_at'],
        );
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /** '/upsc-preparation/prelims/' becomes 'upsc-preparation/prelims'. */
    public function relativePath(): string
    {
        return trim($this->path, '/');
    }

    public function isPublic(): bool
    {
        return $this->visibility === Visibility::Public;
    }

    public function isAwaitingReview(): bool
    {
        return $this->reviewStatus === 'pending';
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && $this->ownerId === $userId;
    }
}
