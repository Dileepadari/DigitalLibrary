<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The three levels of use. Guest is not a stored role: it is the absence of a
 * session, so it lives outside this enum.
 */
enum Role: string
{
    case Member = 'member';
    case Librarian = 'librarian';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Member    => 'Member',
            self::Librarian => 'Librarian',
            self::Admin     => 'Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Member    => 'Reads, requests and contributes. Everything they submit is reviewed.',
            self::Librarian => 'Reviews the queue and publishes without review.',
            self::Admin     => 'Runs the site: roles, settings, takedowns and the audit log.',
        };
    }

    /** Higher outranks lower. Used to stop a librarian editing an admin. */
    public function rank(): int
    {
        return match ($this) {
            self::Member    => 1,
            self::Librarian => 2,
            self::Admin     => 3,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
