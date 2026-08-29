<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

interface Middleware
{
    /**
     * Either return a Response of your own (short circuit) or hand the request
     * on with $next($request).
     *
     * Arguments come from the route declaration: listing a middleware as
     * 'Authorize:user.manage' calls handle() with 'user.manage'.
     */
    public function handle(Request $request, Closure $next, string ...$arguments): Response;
}
