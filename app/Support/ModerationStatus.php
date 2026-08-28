<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The state machine from PLAN.md section 5.
 *
 *   draft -> pending -> under_review -> approved
 *                    |               |-> rejected
 *                    |               |-> changes_requested -> pending
 *                    |-> withdrawn
 */
enum ModerationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft            => 'Draft',
            self::Pending          => 'Waiting',
            self::UnderReview      => 'Being reviewed',
            self::ChangesRequested => 'Changes requested',
            self::Approved         => 'Approved',
            self::Rejected         => 'Rejected',
            self::Withdrawn        => 'Withdrawn',
        };
    }

    /** Still in the queue: something has to happen to it. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Draft, self::Pending, self::UnderReview, self::ChangesRequested => true,
            default                                                              => false,
        };
    }

    public function isFinal(): bool
    {
        return !$this->isOpen();
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft            => [self::Pending, self::Withdrawn],
            self::Pending          => [self::UnderReview, self::Withdrawn],
            self::UnderReview      => [self::Approved, self::Rejected, self::ChangesRequested, self::Pending],
            self::ChangesRequested => [self::Pending, self::Withdrawn],
            default                => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
