<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\RateLimiter;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use Closure;

/**
 * Declared on a route as 'App\Middleware\RateLimit:60' for sixty a minute.
 *
 * Signed-in callers are counted by account, everyone else by IP, so one busy
 * office does not lock out the rest of it.
 */
final class RateLimit implements Middleware
{
    private const DEFAULT_LIMIT = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Auth $auth,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        $limit = (int) ($arguments[0] ?? self::DEFAULT_LIMIT);
        $userId = $this->auth->id();
        $key = $userId === null ? 'ip:' . $this->auth->ipHash($request->ip()) : 'user:' . $userId;

        $result = $this->limiter->hit($key . ':' . $request->path(), $limit);

        if (!$result['allowed']) {
            return Response::json([
                'error'       => 'Too many requests. Wait a moment.',
                'retry_after' => $result['resets_in'],
            ], 429)->withHeader('Retry-After', (string) $result['resets_in']);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }
}
