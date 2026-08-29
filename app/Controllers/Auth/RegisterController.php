<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\Auth;
use App\Support\Password;

final class RegisterController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function create(Request $request): Response
    {
        $this->guardRegistrationIsOpen();

        return $this->render('pages/auth/register', [
            'isFirstAccount' => $this->users->count() === 0,
        ]);
    }

    public function store(Request $request): Response
    {
        $this->guardRegistrationIsOpen();

        $validator = new Validator($request->all(), [
            'name'                  => 'required|min:2|max:120',
            'username'              => 'required|slug|min:3|max:40',
            'email'                 => 'required|email|max:191',
            'password'              => 'required|min:' . Password::MIN_LENGTH . '|max:200',
            'password_confirmation' => 'required|matches:password',
        ], [
            'password_confirmation' => 'password confirmation',
        ]);

        $errors = $validator->errors();

        // Uniqueness is a database question, so it is not a Validator rule.
        if ($validator->first('email') === null && $this->users->emailTaken((string) $request->input('email'))) {
            $errors['email'][] = 'An account already uses that email address.';
        }

        $username = (string) $request->input('username');

        if ($validator->first('username') === null && $this->users->usernameTaken($username)) {
            $errors['username'][] = 'That username is taken.';
        }

        if ($errors !== []) {
            return $this->backWithErrors('/register', $errors, $request->all());
        }

        $user = $this->accounts->register(
            (string) $request->input('name'),
            (string) $request->input('username'),
            (string) $request->input('email'),
            (string) $request->input('password'),
        );

        $this->auth->login($user);

        if ($user->isVerified()) {
            return $this->success(
                'Welcome. This is the first account, so it is the administrator.',
                '/'
            );
        }

        return $this->success('Account created. Check your email to confirm the address.', '/verify-email');
    }

    private function guardRegistrationIsOpen(): void
    {
        // The first account is always allowed: an install with no admin and
        // closed registration could never be set up.
        if ($this->users->count() === 0) {
            return;
        }

        if ($this->settings->string('registration.mode', 'open') !== 'open') {
            throw HttpException::forbidden('Registration is closed on this library.');
        }
    }
}
