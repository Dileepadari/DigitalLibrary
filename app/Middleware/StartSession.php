<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

final class StartSession implements Middleware
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        $this->session->start();

        return $next($request);
    }
}
