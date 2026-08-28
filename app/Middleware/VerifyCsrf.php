<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class VerifyCsrf implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->input('_token') ?? $request->header('x-csrf-token');

        if (!$this->csrf->verify(is_string($token) ? $token : null)) {
            throw new HttpException(419);
        }

        return $next($request);
    }
}
