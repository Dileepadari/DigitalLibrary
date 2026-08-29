<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * Site settings, read once per request and cached in memory.
 *
 * Values are stored as JSON, so a string setting comes out of the column with
 * its quotes: everything here is decoded before it is returned.
 */
final class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $values = [];

        try {
            foreach ($this->db->select('SELECT `key`, `value` FROM settings') as $row) {
                $values[(string) $row['key']] = json_decode((string) $row['value'], true);
            }
        } catch (\Throwable) {
            // No database or no settings table yet (a fresh clone before the
            // first migrate). Callers fall back to their defaults.
            return $this->cache = [];
        }

        return $this->cache = $values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, json_encode($value, JSON_THROW_ON_ERROR)]
        );

        $this->cache = null;
    }
}
