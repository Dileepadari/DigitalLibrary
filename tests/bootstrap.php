<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register([
    'App\\'   => BASE_PATH . '/app/',
    'Tests\\' => BASE_PATH . '/tests/',
]);
