<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CollectionRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\ReputationService;

final class ProfileController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly UserRepository $users,
        private readonly ReviewRepository $reviews,
        private readonly CollectionRepository $collections,
        private readonly ReputationService $reputation,
    ) {
        parent::__construct($view, $session);
    }

    public function show(Request $request): Response
    {
        $user = $this->users->findByUsername((string) $request->parameter('username'));

        if ($user === null) {
            throw HttpException::notFound('No member with that username.');
        }

        return $this->render('pages/profile', [
            'profile'     => $user,
            'badges'      => $this->reputation->badgesFor($user->id),
            'tally'       => $this->reputation->tallyFor($user->id),
            'reviews'     => $this->reviews->byUser($user->id, 5),
            'collections' => array_values(array_filter(
                $this->collections->forUser($user->id),
                static fn ($collection): bool => $collection->isPublic()
            )),
        ]);
    }
}
