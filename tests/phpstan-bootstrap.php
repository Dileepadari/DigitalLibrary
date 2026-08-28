<?php

declare(strict_types=1);

// BASE_PATH is defined by bootstrap.php at runtime; PHPStan needs it up front
// because config/storage.php uses it at the top level.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
