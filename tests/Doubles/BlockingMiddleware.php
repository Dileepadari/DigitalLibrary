<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Never calls $next, so the destination must not run. */
final class BlockingMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        return Response::html('blocked');
    }
}
