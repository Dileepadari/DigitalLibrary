<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\ApplicationRepository;
use App\Services\Auth;
use App\Support\Role;

/** A member asking to become a librarian. */
final class ApplyController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ApplicationRepository $applications,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function create(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        return $this->render('pages/apply', [
            'user'    => $user,
            'pending' => $this->applications->openFor($user->id),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($user->role !== Role::Member) {
            return $this->failure('You already have more than a member\'s permissions.', '/me/settings');
        }

        if ($this->applications->openFor($user->id) !== null) {
            return $this->failure('You have an application waiting already.', '/apply');
        }

        $validator = new Validator($request->all(), [
            'statement' => 'required|min:40|max:2000',
        ], [
            'statement' => 'answer',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/apply', $validator->errors(), $request->all());
        }

        $this->applications->create($user->id, (string) $request->input('statement'));

        return $this->success('Sent. An administrator will read it.', '/apply');
    }
}
