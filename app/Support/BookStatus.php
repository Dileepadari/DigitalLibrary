<?php

declare(strict_types=1);

namespace App\Support;

enum BookStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Pending   => 'Awaiting review',
            self::Published => 'Published',
            self::Rejected  => 'Rejected',
            self::Hidden    => 'Hidden',
        };
    }

    /** Only published records appear in the catalogue. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
