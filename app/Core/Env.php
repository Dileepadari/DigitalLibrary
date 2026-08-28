<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env reader.
 *
 * Deliberately not vlucas/phpdotenv: the app has to boot from a bare clone with
 * no vendor directory. Supports KEY=value, quoted values, # comments and
 * ${OTHER_KEY} interpolation. Values are never written to $_ENV or putenv() so
 * they cannot leak into child processes.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            } elseif (str_contains($value, ' #')) {
                $value = trim(substr($value, 0, strpos($value, ' #')));
            }

            self::$values[$key] = preg_replace_callback(
                '/\$\{([A-Z0-9_]+)\}/',
                static fn (array $m): string => self::$values[$m[1]] ?? '',
                $value
            ) ?? $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load(BASE_PATH . '/.env');
        }

        $value = self::$values[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            ''                 => $default,
            default            => $value,
        };
    }

    /** Test seam. */
    public static function set(string $key, string $value): void
    {
        self::$loaded = true;
        self::$values[$key] = $value;
    }
}
