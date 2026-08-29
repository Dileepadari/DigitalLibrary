<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'             => Env::get('APP_NAME', 'Digital Library'),

    // Keys the IP hashes in login_attempts and audit_logs. Generate one with
    // `php cli/console.php key:generate`. Changing it only resets those hashes.
    'key'              => Env::get('APP_KEY', ''),
    'tagline'          => Env::get('APP_TAGLINE', 'A community library anyone can add to.'),
    'url'              => rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/'),
    'env'              => Env::get('APP_ENV', 'local'),
    'debug'            => (bool) Env::get('APP_DEBUG', true),
    'timezone'         => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'locale'           => Env::get('APP_LOCALE', 'en'),
    'force_https'      => (bool) Env::get('APP_FORCE_HTTPS', false),
    'session_name'     => Env::get('SESSION_NAME', 'dl_session'),
    'session_lifetime' => (int) Env::get('SESSION_LIFETIME', 7200),

    // Bumped by hand at each milestone; shown on the status panel and /health.
    'version'          => '0.9.0-m8',
];
