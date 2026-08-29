<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\AccountService;
use App\Support\Password;

final class PasswordResetController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
    ) {
        parent::__construct($view, $session);
    }

    public function request(Request $request): Response
    {
        return $this->render('pages/auth/forgot-password');
    }

    public function send(Request $request): Response
    {
        $validator = new Validator($request->all(), ['email' => 'required|email']);

        if ($validator->fails()) {
            return $this->backWithErrors('/forgot-password', $validator->errors(), $request->all());
        }

        $this->accounts->sendPasswordResetLink((string) $request->input('email'));

        // Same answer whether or not the address has an account here.
        return $this->success(
            'If that address has an account, a reset link is on its way.',
            '/login'
        );
    }

    public function edit(Request $request): Response
    {
        return $this->render('pages/auth/reset-password', [
            'token' => (string) $request->parameter('token'),
        ]);
    }

    public function update(Request $request): Response
    {
        $token = (string) $request->parameter('token');

        $validator = new Validator($request->all(), [
            'password'              => 'required|min:' . Password::MIN_LENGTH . '|max:200',
            'password_confirmation' => 'required|matches:password',
        ], [
            'password_confirmation' => 'password confirmation',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/reset-password/' . $token, $validator->errors());
        }

        if (!$this->accounts->resetPassword($token, (string) $request->input('password'))) {
            return $this->failure('That reset link has expired or has already been used.', '/forgot-password');
        }

        return $this->success('Password changed. Sign in with it.', '/login');
    }
}
