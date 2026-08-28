<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Who can see a collection.
 *
 * Private and unlisted need no permission from anyone: they are one person's
 * shelf and one person's link. Public is the only one that goes through the
 * queue, because it is the only one that puts something in front of everybody.
 */
enum Visibility: string
{
    case Private = 'private';
    case Unlisted = 'unlisted';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private  => 'Private',
            self::Unlisted => 'Anyone with the link',
            self::Public   => 'Public',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Private  => 'Only you and anyone you invite to maintain it.',
            self::Unlisted => 'Not listed anywhere, but the address works for anyone you give it to.',
            self::Public   => 'Listed for everyone. A librarian approves it first.',
        };
    }

    public function needsApproval(): bool
    {
        return $this === self::Public;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
