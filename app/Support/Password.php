<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Argon2id where the build supports it, bcrypt otherwise. Hashes carry their own
 * algorithm, so a host that gains Argon2id later rehashes on the next sign in.
 */
final class Password
{
    public const MIN_LENGTH = 10;

    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algorithm());
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
