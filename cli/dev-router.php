<?php

/**
 * Router for PHP's built-in server (`console.php serve`).
 *
 * Without one, the built-in server treats any URI that looks like a file as a
 * static request and answers 404 when it does not exist, which is how
 * /admin/audit.csv came to 404 in development while working under Apache and
 * nginx. Returning false lets it serve a real file; everything else goes to the
 * front controller, exactly as the .htaccess and the nginx config do.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/../public' . (is_string($path) ? $path : '/');

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../public/index.php';
