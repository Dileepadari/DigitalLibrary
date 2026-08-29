<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AccountService;
use App\Services\Auth;

final class EmailVerificationController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function notice(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user !== null && $user->isVerified()) {
            return $this->redirect('/');
        }

        return $this->render('pages/auth/verify-email');
    }

    public function consume(Request $request): Response
    {
        $user = $this->accounts->verify((string) $request->parameter('token'));

        if ($user === null) {
            return $this->failure('That confirmation link has expired or has already been used.', '/verify-email');
        }

        if (!$this->auth->check()) {
            $this->auth->login($user);
        }

        return $this->success('Email confirmed. You can contribute now.', '/');
    }

    public function resend(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null || $user->isVerified()) {
            return $this->redirect('/');
        }

        $this->accounts->sendVerificationLink($user);

        return $this->success('A new confirmation link is on its way.', '/verify-email');
    }
}
