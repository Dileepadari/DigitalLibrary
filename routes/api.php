<?php

/**
 * Machine routes. No session cookie is required and no CSRF token is checked;
 * from M9 these are authenticated with a bearer token instead.
 */

declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Core\Router;

return static function (Router $router): void {
    // Short alias used by the Docker health check.
    $router->get('/health', [HealthController::class, 'show'])->name('health');

    $router->group(['prefix' => '/api/v1'], static function (Router $router): void {
        $router->get('/health', [HealthController::class, 'show'])->name('api.health');
    });
};
