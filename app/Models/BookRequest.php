<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RequestStatus;

final class BookRequest
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $author,
        public readonly ?string $isbn,
        public readonly ?string $note,
        public readonly string $language,
        public readonly ?int $requesterId,
        public readonly ?string $requesterName,
        public readonly RequestStatus $status,
        public readonly ?int $claimedBy,
        public readonly ?string $claimedByName,
        public readonly ?string $claimedAt,
        public readonly ?int $fulfilledByBookId,
        public readonly ?string $fulfilledBookTitle,
        public readonly ?string $fulfilledBookSlug,
        public readonly ?string $closeReason,
        public readonly int $voteCount,
        public readonly string $createdAt,
        public readonly bool $viewerHasVoted = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['title'],
            $row['author'] !== null ? (string) $row['author'] : null,
            $row['isbn'] !== null ? (string) $row['isbn'] : null,
            $row['note'] !== null ? (string) $row['note'] : null,
            (string) $row['language'],
            $row['requester_id'] !== null ? (int) $row['requester_id'] : null,
            isset($row['requester_name']) ? (string) $row['requester_name'] : null,
            RequestStatus::from((string) $row['status']),
            $row['claimed_by'] !== null ? (int) $row['claimed_by'] : null,
            isset($row['claimer_name']) ? (string) $row['claimer_name'] : null,
            $row['claimed_at'] !== null ? (string) $row['claimed_at'] : null,
            $row['fulfilled_by_book_id'] !== null ? (int) $row['fulfilled_by_book_id'] : null,
            isset($row['fulfilled_title']) ? (string) $row['fulfilled_title'] : null,
            isset($row['fulfilled_slug']) ? (string) $row['fulfilled_slug'] : null,
            $row['close_reason'] !== null ? (string) $row['close_reason'] : null,
            (int) $row['vote_count'],
            (string) $row['created_at'],
            (bool) ($row['viewer_voted'] ?? false),
        );
    }

    public function isClaimedBy(?int $userId): bool
    {
        return $userId !== null && $this->claimedBy === $userId;
    }

    /** What to show as the ask: "Dune by Frank Herbert". */
    public function summary(): string
    {
        return $this->author === null || $this->author === ''
            ? $this->title
            : $this->title . ' by ' . $this->author;
    }
}
