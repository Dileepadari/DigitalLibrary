<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query goes through a prepared statement; there is no
 * method that accepts an interpolated value, on purpose.
 *
 * The connection is opened on first use so the app can render pages (and the
 * health check can report a failure) with the database down.
 */
final class Db
{
    private ?PDO $pdo = null;

    private ?string $connectionError = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $this->config['driver'] ?? 'mysql',
            $this->config['host'] ?? '127.0.0.1',
            $this->config['port'] ?? '3306',
            $this->config['database'] ?? '',
            $this->config['charset'] ?? 'utf8mb4',
        );

        $this->pdo = new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );

        // Every timestamp in the database is UTC, whatever the server is set to.
        // Views convert to config('app.timezone') on the way out.
        $this->pdo->exec("SET time_zone = '+00:00'");

        return $this->pdo;
    }

    /** True when a connection can be opened; never throws. */
    public function isConnected(): bool
    {
        try {
            $this->pdo();

            return true;
        } catch (\Throwable $e) {
            $this->connectionError = $e->getMessage();

            return false;
        }
    }

    public function connectionError(): ?string
    {
        return $this->connectionError;
    }

    /**
     * Bindings are bound by PHP type rather than passed to execute(), which
     * would send everything as a string. MySQL rejects `LIMIT '25'`, so an int
     * has to arrive as an int.
     *
     * @param array<string|int, mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                }
            );
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string|int, mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /** @param array<string, mixed> $values */
    public function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $this->run(
            sprintf(
                'INSERT INTO `%s` (`%s`) VALUES (%s)',
                $table,
                implode('`, `', $columns),
                implode(', ', $placeholders)
            ),
            $values
        );

        return (int) $this->pdo()->lastInsertId();
    }

    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $work($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
