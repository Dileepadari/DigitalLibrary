<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // 'log' writes the message to storage/logs instead of sending it, which is
    // the default so a fresh install never needs SMTP credentials to work.
    'driver'       => Env::get('MAIL_DRIVER', 'log'),
    'host'         => Env::get('MAIL_HOST', 'localhost'),
    'port'         => (int) Env::get('MAIL_PORT', 1025),
    'username'     => Env::get('MAIL_USERNAME', ''),
    'password'     => Env::get('MAIL_PASSWORD', ''),
    'encryption'   => Env::get('MAIL_ENCRYPTION', ''),
    'from_address' => Env::get('MAIL_FROM_ADDRESS', 'library@localhost'),
    'from_name'    => Env::get('MAIL_FROM_NAME', 'Digital Library'),
];
