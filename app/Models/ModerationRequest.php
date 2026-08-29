<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ModerationStatus;
use App\Support\ModerationType;
use App\Support\Timestamp;

final class ModerationRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly ModerationType $type,
        public readonly ?int $subjectId,
        public readonly ?int $submitterId,
        public readonly ?string $submitterName,
        public readonly ?int $assigneeId,
        public readonly ?string $assigneeName,
        public readonly ModerationStatus $status,
        public readonly string $title,
        public readonly array $payload,
        public readonly ?string $reason,
        public readonly ?string $claimedUntil,
        public readonly ?string $decidedAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $payload = [];

        if (isset($row['payload']) && is_string($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return new self(
            (int) $row['id'],
            ModerationType::from((string) $row['subject_type']),
            $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
            $row['submitter_id'] !== null ? (int) $row['submitter_id'] : null,
            isset($row['submitter_name']) ? (string) $row['submitter_name'] : null,
            $row['assignee_id'] !== null ? (int) $row['assignee_id'] : null,
            isset($row['assignee_name']) ? (string) $row['assignee_name'] : null,
            ModerationStatus::from((string) $row['status']),
            (string) $row['title'],
            $payload,
            $row['reason'] !== null ? (string) $row['reason'] : null,
            $row['claimed_until'] !== null ? (string) $row['claimed_until'] : null,
            $row['decided_at'] !== null ? (string) $row['decided_at'] : null,
            (string) $row['created_at'],
        );
    }

    /** A claim expires so an abandoned review does not block the item forever. */
    public function isClaimed(): bool
    {
        return $this->assigneeId !== null
            && $this->claimedUntil !== null
            && !Timestamp::isPast($this->claimedUntil);
    }

    public function isClaimedBy(?int $userId): bool
    {
        return $userId !== null && $this->isClaimed() && $this->assigneeId === $userId;
    }

    public function ageInDays(): int
    {
        return Timestamp::daysSince($this->createdAt);
    }
}
