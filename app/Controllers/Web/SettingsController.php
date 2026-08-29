<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\Auth;
use App\Services\Gate;
use App\Support\Password;

final class SettingsController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly UserRepository $users,
        private readonly AccountService $accounts,
    ) {
        parent::__construct($view, $session);
    }

    public function edit(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        return $this->render('pages/settings', [
            'user'        => $user,
            'permissions' => $this->gate->permissionsFor($user),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $validator = new Validator($request->all(), [
            'name' => 'required|min:2|max:120',
            'bio'  => 'max:500',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/me/settings', $validator->errors(), $request->all());
        }

        $bio = (string) $request->input('bio', '');
        $this->users->updateProfile($user->id, (string) $request->input('name'), $bio === '' ? null : $bio);

        return $this->success('Profile updated.', '/me/settings');
    }

    public function password(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $validator = new Validator($request->all(), [
            'current_password'      => 'required',
            'password'              => 'required|min:' . Password::MIN_LENGTH . '|max:200',
            'password_confirmation' => 'required|matches:password',
        ], [
            'current_password'      => 'current password',
            'password_confirmation' => 'password confirmation',
        ]);

        $errors = $validator->errors();

        if (
            $validator->first('current_password') === null
            && !Password::verify((string) $request->input('current_password'), $user->passwordHash)
        ) {
            $errors['current_password'][] = 'That is not your current password.';
        }

        if ($errors !== []) {
            return $this->backWithErrors('/me/settings', $errors);
        }

        $this->accounts->changePassword($user, (string) $request->input('password'));

        return $this->success('Password changed.', '/me/settings');
    }
}
