<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Db;
use App\Core\Migrator;

/**
 * Answers "is this install wired up correctly?" for the status panel on the
 * home page and for /health. Every check is non-fatal: a broken database must
 * still render a page that says so.
 */
final class SystemStatus
{
    private const MINIMUM_PHP = '8.2';

    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'gd', 'openssl'];

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Migrator $migrator,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     version: string,
     *     php: array{version: string, minimum: string, ok: bool, missing_extensions: list<string>},
     *     database: array{connected: bool, error: string|null, applied: int, pending: list<string>},
     *     storage: array{writable: list<string>, unwritable: list<string>}
     * }
     */
    public function report(): array
    {
        $php = $this->php();
        $database = $this->database();
        $storage = $this->storage();

        return [
            'ok'       => $php['ok'] && $database['connected'] && $database['pending'] === []
                          && $storage['unwritable'] === [],
            'version'  => (string) $this->config->get('app.version'),
            'php'      => $php,
            'database' => $database,
            'storage'  => $storage,
        ];
    }

    /**
     * The minimum PHP version is declared in composer.json; this only reports
     * what is running and which extensions are missing.
     *
     * @return array{version: string, minimum: string, ok: bool, missing_extensions: list<string>}
     */
    private function php(): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        return [
            'version'            => PHP_VERSION,
            'minimum'            => self::MINIMUM_PHP,
            'ok'                 => $missing === [],
            'missing_extensions' => $missing,
        ];
    }

    /** @return array{connected: bool, error: string|null, applied: int, pending: list<string>} */
    private function database(): array
    {
        if (!$this->db->isConnected()) {
            return [
                'connected' => false,
                'error'     => $this->db->connectionError(),
                'applied'   => 0,
                'pending'   => $this->migrator->files(),
            ];
        }

        try {
            return [
                'connected' => true,
                'error'     => null,
                'applied'   => count($this->migrator->applied()),
                'pending'   => $this->migrator->pending(),
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => true,
                'error'     => $e->getMessage(),
                'applied'   => 0,
                'pending'   => $this->migrator->files(),
            ];
        }
    }

    /** @return array{writable: list<string>, unwritable: list<string>} */
    private function storage(): array
    {
        $writable = [];
        $unwritable = [];

        foreach (['library', 'quarantine', 'covers', 'cache', 'logs'] as $name) {
            $path = (string) $this->config->get('storage.' . $name);

            if (is_dir($path) && is_writable($path)) {
                $writable[] = $name;
            } else {
                $unwritable[] = $name;
            }
        }

        return ['writable' => $writable, 'unwritable' => $unwritable];
    }
}
