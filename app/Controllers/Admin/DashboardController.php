<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Storage;
use App\Core\View;
use App\Repositories\ApplicationRepository;
use App\Repositories\StatisticsRepository;
use App\Repositories\TakedownRepository;

/**
 * The admin dashboard: the numbers, and anything waiting for a person.
 */
final class DashboardController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly StatisticsRepository $statistics,
        private readonly TakedownRepository $takedowns,
        private readonly ApplicationRepository $applications,
        private readonly Storage $storage,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $days = max(7, min(90, (int) $request->query('days', 30)));

        return $this->render('pages/admin/dashboard', [
            'totals'      => $this->statistics->totals(),
            'days'        => $days,
            'signups'     => $this->statistics->daily('users', 'created_at', $days),
            'uploads'     => $this->statistics->daily('book_files', 'created_at', $days),
            'reviews'     => $this->statistics->daily('reviews', 'created_at', $days),
            'categories'  => $this->statistics->topCategories(),
            'books'       => $this->statistics->topBooks(),
            'queue'       => $this->statistics->queueHealth(),
            'takedowns'   => $this->takedowns->countOpen(),
            'oldestNotice' => $this->takedowns->oldestOpenDays(),
            'applications' => $this->applications->countPending(),
            'library'     => $this->storage->usage('library'),
            'quarantine'  => $this->storage->usage('quarantine'),
        ]);
    }
}
