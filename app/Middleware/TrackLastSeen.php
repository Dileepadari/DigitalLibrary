<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\Auth;
use App\Core\Session;
use Closure;

/**
 * Writes users.last_seen_at at most once every five minutes per session, so a
 * page view does not cost a write.
 */
final class TrackLastSeen implements Middleware
{
    private const INTERVAL = 300;

    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly UserRepository $users,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        $user = $this->auth->user();
        $last = $this->session->get('last_seen_written', 0);

        if ($user !== null && (!is_int($last) || $last < time() - self::INTERVAL)) {
            $this->users->touchLastSeen($user->id);
            $this->session->put('last_seen_written', time());
        }

        return $next($request);
    }
}
