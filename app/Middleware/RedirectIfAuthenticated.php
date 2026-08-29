<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use Closure;

/** Keeps a signed-in user off the sign-in and registration pages. */
final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }

        return $next($request);
    }
}
