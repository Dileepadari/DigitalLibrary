<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;

final class ProfileController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly UserRepository $users,
    ) {
        parent::__construct($view, $session);
    }

    public function show(Request $request): Response
    {
        $user = $this->users->findByUsername((string) $request->parameter('username'));

        if ($user === null) {
            throw HttpException::notFound('No member with that username.');
        }

        return $this->render('pages/profile', ['profile' => $user]);
    }
}
