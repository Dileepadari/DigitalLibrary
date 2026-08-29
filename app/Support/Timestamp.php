<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Database timestamps are UTC (see Db::pdo, which sets time_zone to +00:00).
 * The application's own timezone is whatever `app.timezone` says, so a bare
 * `strtotime('2026-08-29 06:54:50')` reads a UTC row as local time and lands
 * hours away: long enough to make a live claim look expired.
 *
 * Everything that turns a stored timestamp into a number or a date goes
 * through here.
 */
final class Timestamp
{
    /** Seconds since the epoch for a database timestamp. */
    public static function epoch(string $value): int
    {
        return strtotime($value . ' UTC') ?: 0;
    }

    /** Formatted in the application timezone, which is what a reader expects. */
    public static function format(string $value, string $format = 'j M Y'): string
    {
        return date($format, self::epoch($value));
    }

    /** How long ago it was, in seconds. Negative for a time still to come. */
    public static function since(string $value): int
    {
        return time() - self::epoch($value);
    }

    public static function daysSince(string $value): int
    {
        return (int) floor(self::since($value) / 86400);
    }

    public static function isPast(string $value): bool
    {
        return self::epoch($value) <= time();
    }
}
