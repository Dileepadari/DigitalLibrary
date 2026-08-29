<?php

declare(strict_types=1);

namespace App\Support;

enum UserStatus: string
{
    case Active = 'active';
    case Muted = 'muted';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Muted  => 'Muted',
            self::Banned => 'Banned',
        };
    }

    /** A banned account cannot sign in at all; a muted one can read but not contribute. */
    public function canSignIn(): bool
    {
        return $this !== self::Banned;
    }

    public function canContribute(): bool
    {
        return $this === self::Active;
    }
}
