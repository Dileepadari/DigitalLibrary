<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BadgeRepository;
use App\Services\ReputationService;

/**
 * The contributor leaderboard: who has done the most for the library, and what
 * the badges are for.
 */
final class ContributorController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ReputationService $reputation,
        private readonly BadgeRepository $badges,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('pages/contributors', [
            'contributors' => $this->reputation->leaderboard(25),
            'badges'       => $this->badges->all(),
        ]);
    }
}
