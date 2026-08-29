<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canned reasons keep decisions consistent between reviewers and make the
 * rejection statistics mean something. A reviewer can always write their own.
 */
final class RejectionReason
{
    public const COPYRIGHT = 'Copyright: no basis to host this';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'Duplicate of something already here',
            'Poor scan quality',
            'Wrong or misleading metadata',
            self::COPYRIGHT,
            'Off topic for this library',
            'Spam',
        ];
    }

    /** A copyright rejection counts against the uploader. */
    public static function isCopyright(string $reason): bool
    {
        return str_starts_with($reason, 'Copyright');
    }
}
