<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BookRepository;
use App\Repositories\BookRequestRepository;
use App\Repositories\ReadingRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\Auth;
use App\Support\SystemStatus;

final class HomeController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Config $config,
        private readonly SettingsRepository $settings,
        private readonly UserRepository $users,
        private readonly BookRepository $books,
        private readonly BookRequestRepository $requests,
        private readonly ReadingRepository $reading,
        private readonly Auth $auth,
        private readonly SystemStatus $status,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $report = $this->status->report();

        $connected = $report['database']['connected'] && $report['database']['pending'] === [];

        return $this->render('pages/home', [
            'siteName'    => $this->settings->string('site.name', (string) $this->config->get('app.name')),
            'status'      => $report,
            'memberCount' => $connected ? $this->users->count() : 0,
            'bookCount'   => $connected ? $this->books->countPublished() : 0,
            'recentBooks' => $connected ? $this->books->recent(8) : [],
            'mostWanted'  => $connected ? $this->requests->mostWanted(5) : [],
            'reading'     => $connected && $this->auth->id() !== null
                ? $this->reading->recentlyRead((int) $this->auth->id(), 4)
                : [],
        ]);
    }
}
