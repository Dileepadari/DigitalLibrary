<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use Closure;

/**
 * Hands the view the few facts about the current request that every page needs:
 * where we are, so navigation can mark itself, and the full URL, so a form can
 * come back to the page it was on.
 *
 * Doing it here rather than in each controller means no page can forget.
 */
final class ViewContext implements Middleware
{
    public function __construct(private readonly View $view)
    {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        $query = $request->queryParameters();

        $this->view->share('currentPath', $request->path());
        $this->view->share('currentUrl', $request->path() . ($query === [] ? '' : '?' . http_build_query($query)));

        return $next($request);
    }
}
