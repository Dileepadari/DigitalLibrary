<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads config/*.php once and exposes them by dot path: config('app.name')
 * resolves to config/app.php ['name'].
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if (!array_key_exists($file, $this->items)) {
            $path = $this->directory . '/' . $file . '.php';
            $this->items[$file] = is_file($path) ? require $path : [];
        }

        $value = $this->items[$file];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        $this->get($file);
        $target = &$this->items[$file];

        foreach ($segments as $segment) {
            if (!is_array($target)) {
                $target = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }
}
