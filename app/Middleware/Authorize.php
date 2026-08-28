<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gate;
use Closure;

/**
 * Declared on a route as 'App\Middleware\Authorize:user.manage'. Throws 401 when
 * signed out and 403 when signed in without the permission.
 */
final class Authorize implements Middleware
{
    public function __construct(private readonly Gate $gate)
    {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        foreach ($arguments as $permission) {
            $this->gate->authorize($permission);
        }

        return $next($request);
    }
}
