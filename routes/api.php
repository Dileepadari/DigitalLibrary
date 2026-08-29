<?php

/**
 * Machine routes: no sign-in, no CSRF token, no writes.
 *
 * Everything here is what a guest can already read on the website, so there is
 * nothing to authenticate. Rate limits are per IP (or per account, if a session
 * cookie happens to come along), which is the only thing standing between a
 * public catalogue and a scraper.
 */

declare(strict_types=1);

use App\Controllers\Api\CatalogueController;
use App\Controllers\Api\FeedController;
use App\Controllers\Api\HealthController;
use App\Middleware\RateLimit;
use App\Core\Router;

return static function (Router $router): void {
    // Short alias used by the Docker health check.
    $router->get('/health', [HealthController::class, 'show'])->name('health');

    // Feeds live at the top level so they are easy to paste into a reader.
    $router->get('/feed.rss', [FeedController::class, 'rss'])
        ->middleware(RateLimit::class . ':60')
        ->name('feed.rss');
    $router->get('/opds', [FeedController::class, 'opds'])
        ->middleware(RateLimit::class . ':60')
        ->name('feed.opds');

    $router->group([
        'prefix'     => '/api/v1',
        'middleware' => [RateLimit::class . ':120'],
    ], static function (Router $router): void {
        $router->get('/health', [HealthController::class, 'show'])->name('api.health');
        $router->get('/books', [CatalogueController::class, 'books'])->name('api.books');
        $router->get('/books/{slug}', [CatalogueController::class, 'book'])->name('api.book');
        $router->get('/categories', [CatalogueController::class, 'categories'])->name('api.categories');
        $router->get('/tags', [CatalogueController::class, 'tags'])->name('api.tags');
    });
};
