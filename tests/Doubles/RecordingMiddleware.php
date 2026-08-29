<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Records the argument it was declared with, then continues. */
final class RecordingMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        self::$seen[] = $arguments[0] ?? '';

        return $next($request);
    }
}
