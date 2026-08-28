<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies the .sql files in database/migrations in filename order and records
 * them in the `migrations` table.
 *
 * Each file holds an `-- @up` section and an optional `-- @down` section.
 * Statements are separated by a semicolon at the end of a line, so a semicolon
 * inside a string literal or a trigger body needs the statement to be kept on
 * one line.
 */
final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $directory,
    ) {
    }

    public function ensureTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS `migrations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT UNSIGNED NOT NULL,
                `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migrations_migration_unique` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);

        return array_map(
            static fn (string $path): string => basename($path, '.sql'),
            $files
        );
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->db->select('SELECT migration FROM migrations ORDER BY id')
        );
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }

    /**
     * @return list<string> the migrations that were applied
     */
    public function migrate(?callable $report = null): array
    {
        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = (int) ($this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') ?? 0) + 1;

        foreach ($pending as $name) {
            foreach ($this->statements($name, 'up') as $sql) {
                $this->db->execute($sql);
            }

            $this->db->insert('migrations', ['migration' => $name, 'batch' => $batch]);

            if ($report !== null) {
                $report($name);
            }
        }

        return $pending;
    }

    /**
     * Rolls back the most recent batch.
     *
     * @return list<string>
     */
    public function rollback(?callable $report = null): array
    {
        $this->ensureTable();
        $batch = (int) ($this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') ?? 0);

        if ($batch === 0) {
            return [];
        }

        $names = array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->db->select('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch])
        );

        foreach ($names as $name) {
            $statements = $this->statements($name, 'down');

            if ($statements === []) {
                throw new \RuntimeException("Migration [{$name}] has no @down section, cannot roll back.");
            }

            foreach ($statements as $sql) {
                $this->db->execute($sql);
            }

            $this->db->execute('DELETE FROM migrations WHERE migration = ?', [$name]);

            if ($report !== null) {
                $report($name);
            }
        }

        return $names;
    }

    /** @return list<string> */
    public function statements(string $migration, string $section): array
    {
        $path = $this->directory . '/' . $migration . '.sql';

        if (!is_file($path)) {
            throw new \RuntimeException("Migration file [{$migration}.sql] not found.");
        }

        return self::parse((string) file_get_contents($path), $section);
    }

    /** @return list<string> */
    public static function parse(string $contents, string $section): array
    {
        $wanted = '@' . strtolower($section);
        $current = null;
        $buffer = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $marker = trim($line);

            if (preg_match('/^--\s*(@up|@down)\s*$/i', $marker, $m) === 1) {
                $current = strtolower($m[1]);

                continue;
            }

            if ($current === $wanted) {
                $buffer[] = $line;
            }
        }

        $sql = implode("\n", $buffer);
        $statements = [];

        foreach (explode(";\n", $sql . "\n") as $statement) {
            $statement = trim($statement, " \t\n\r\0\x0B;");

            // Drop comment-only fragments.
            $code = trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? '');

            if ($code !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
