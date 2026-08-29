<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use Closure;

/**
 * Chooses the language: `?lang=hi` sets it, the session remembers it, and
 * otherwise the site default applies.
 *
 * Deliberately not `Accept-Language`: guessing from the browser and being wrong
 * is worse than being predictable, and the switcher is one click.
 */
final class SetLocale implements Middleware
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$arguments): Response
    {
        $wanted = trim((string) $request->query('lang', ''));

        if ($wanted !== '' && $this->translator->available($wanted)) {
            $this->session->put('locale', $wanted);
        }

        $stored = $this->session->get('locale');

        if (is_string($stored) && $stored !== '') {
            $this->translator->setLocale($stored);
        }

        $this->view->share('localeLinks', $this->links($request));

        return $next($request);
    }

    /**
     * A switcher link per language that keeps the page you are on, filters and
     * all. Without this, changing language halfway through a search throws the
     * search away.
     *
     * @return array<string, string>
     */
    private function links(Request $request): array
    {
        $links = [];

        foreach ($this->translator->locales() as $code) {
            $query = $request->queryParameters();
            $query['lang'] = $code;

            $links[$code] = $request->path() . '?' . http_build_query($query);
        }

        return $links;
    }
}
