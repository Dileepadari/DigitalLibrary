<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use Closure;

final class Authenticate implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            throw HttpException::unauthorized();
        }

        // Remembered so the sign-in form can send them where they were going.
        $this->session->flash('intended', $request->path());
        $this->session->flash('error', 'Sign in to continue.');

        return Response::redirect('/login');
    }
}
