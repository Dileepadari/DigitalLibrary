<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli' || headers_sent()) {
            // The CLI and the test suite get an array-backed session so the rest
            // of the code can read and write it without checking the SAPI.
            $_SESSION ??= [];
            $this->started = true;
            $this->expireFlash();

            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => (int) $this->config->get('app.session_lifetime', 7200),
                'path'     => '/',
                'httponly' => true,
                'secure'   => (bool) $this->config->get('app.force_https', false),
                'samesite' => 'Lax',
            ]);
            session_name((string) $this->config->get('app.session_name', 'dl_session'));
            session_start();
        }

        $this->started = true;
        $this->expireFlash();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION ??= [];
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION ??= [];
        $_SESSION['_flash'][$key] = $value;
        unset($_SESSION['_flash_old'][$key]);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_old'][$key] ?? $_SESSION['_flash'][$key] ?? $default;
    }

    /** Guards against session fixation: call after any privilege change. */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Flash data survives exactly one further request. */
    private function expireFlash(): void
    {
        $_SESSION['_flash_old'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
    }
}
