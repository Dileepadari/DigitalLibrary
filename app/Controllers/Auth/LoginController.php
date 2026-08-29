<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Auth;
use App\Support\AuthResult;

final class LoginController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function create(Request $request): Response
    {
        return $this->render('pages/auth/login');
    }

    public function store(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/login', $validator->errors(), $request->all());
        }

        $result = $this->auth->attempt(
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request
        );

        if ($result !== AuthResult::Success) {
            $this->session->flash('error', $result->message());

            return $this->backWithErrors('/login', [], $request->all());
        }

        $intended = $this->session->getFlash('intended');
        $to = is_string($intended) && str_starts_with($intended, '/') ? $intended : '/';

        return $this->success('Signed in.', $to);
    }

    public function destroy(Request $request): Response
    {
        $this->auth->logout();

        return $this->success('Signed out.', '/');
    }
}
