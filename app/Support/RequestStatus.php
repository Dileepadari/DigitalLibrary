<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A book request's life:
 *
 *   open -> claimed -> fulfilled
 *        |          |-> unavailable
 *        |-> duplicate / rejected / unavailable
 *
 * "Unavailable" is the honest ending for a book nobody can legally supply, and
 * it is not the same as "rejected", which means the request itself was not
 * something this library wants.
 */
enum RequestStatus: string
{
    case Open = 'open';
    case Claimed = 'claimed';
    case Fulfilled = 'fulfilled';
    case Unavailable = 'unavailable';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open        => 'Open',
            self::Claimed     => 'Someone is on it',
            self::Fulfilled   => 'Fulfilled',
            self::Unavailable => 'Not available',
            self::Duplicate   => 'Already here',
            self::Rejected    => 'Declined',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::Claimed;
    }

    /**
     * The endings a person can choose by hand. Fulfilment is not among them: it
     * comes from a book arriving, never from a dropdown.
     *
     * @return list<self>
     */
    public static function closable(): array
    {
        return [self::Unavailable, self::Duplicate, self::Rejected];
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
