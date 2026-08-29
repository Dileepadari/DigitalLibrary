<?php

declare(strict_types=1);

use App\Core\Request;

/** @var App\Core\Kernel $kernel */
$kernel = require dirname(__DIR__) . '/bootstrap.php';

$kernel->handle(Request::fromGlobals())->send();
