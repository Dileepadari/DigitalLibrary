<?php

/**
 * Nothing here is inside public/. Files are served by a controller that checks
 * permissions first (see PLAN.md section 6).
 */

declare(strict_types=1);

use App\Core\Env;

$root = rtrim((string) Env::get('STORAGE_ROOT', BASE_PATH . '/storage'), '/');

return [
    // STORAGE_ROOT moves the whole tree elsewhere: another volume in production,
    // a temporary directory in the tests.
    'root'       => $root,
    'library'    => $root . '/library',
    'quarantine' => $root . '/quarantine',
    'covers'     => $root . '/covers',
    'cache'      => $root . '/cache',
    'logs'       => $root . '/logs',
    'backups'    => $root . '/backups',

    // Enforced from M3 onwards, when the upload pipeline lands.
    'max_upload_bytes'  => (int) Env::get('MAX_UPLOAD_BYTES', 209715200),
    'allowed_formats'   => ['pdf', 'epub', 'mobi', 'djvu', 'cbz', 'txt'],
    'quarantine_days'   => 7,
];
