<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\SettingsRepository;
use App\Services\Gate;
use Closure;

/**
 * Maintenance mode: everyone but an admin gets a notice.
 *
 * The health check is exempt, because a monitor that cannot tell "down" from
 * "closed for an hour" is not much of a monitor.
 */
final class MaintenanceMode implements Middleware
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Gate $gate,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        if (!$this->settings->bool('site.maintenance')) {
            return $next($request);
        }

        if (str_starts_with($request->path(), '/health') || str_starts_with($request->path(), '/api/')) {
            return $next($request);
        }

        // An admin has to be able to sign in and turn it off again.
        if ($this->gate->allows('settings.manage') || str_starts_with($request->path(), '/login')) {
            return $next($request);
        }

        $message = $this->settings->string('site.maintenance_message');

        return Response::html(
            $this->view->render('errors/maintenance', [
                'message' => $message === '' ? 'The library is closed for a moment. Try again shortly.' : $message,
            ]),
            503
        )->withHeader('Retry-After', '600');
    }
}
