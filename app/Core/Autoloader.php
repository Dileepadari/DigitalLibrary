<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PSR-4 autoloader.
 *
 * The application runs without `composer install`; Composer's autoloader is
 * layered on top only when vendor/ exists (see bootstrap.php).
 */
final class Autoloader
{
    /** @var array<string, string> prefix => base directory */
    private static array $prefixes = [];

    private static bool $registered = false;

    /** @param array<string, string> $prefixes */
    public static function register(array $prefixes): void
    {
        foreach ($prefixes as $prefix => $dir) {
            self::$prefixes[trim($prefix, '\\') . '\\'] = rtrim($dir, '/') . '/';
        }

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load']);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $dir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
