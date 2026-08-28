<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'driver'   => 'mysql',
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::get('DB_PORT', '3306'),
    'database' => Env::get('DB_DATABASE', 'digital_library'),
    'username' => Env::get('DB_USERNAME', 'root'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
];
