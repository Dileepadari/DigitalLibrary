<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\Auth;
use App\Support\Role;
use App\Support\UserStatus;

/**
 * The admin user list. Guarded by the `user.manage` permission, which only the
 * admin role carries.
 */
final class UserController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $page = (int) $request->query('page', 1);

        $results = $this->users->paginate([
            'search' => (string) $request->query('search', ''),
            'role'   => (string) $request->query('role', ''),
            'status' => (string) $request->query('status', ''),
        ], max(1, $page));

        return $this->render('pages/admin/users', [
            'results' => $results,
            'counts'  => $this->users->countsByRole(),
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'role'   => (string) $request->query('role', ''),
                'status' => (string) $request->query('status', ''),
            ],
        ]);
    }

    public function updateRole(Request $request): Response
    {
        $actor = $this->auth->user();
        $target = $this->target($request);
        $role = Role::tryFrom((string) $request->input('role'));

        if ($actor === null || $role === null) {
            return $this->failure('That is not a role.', '/admin/users');
        }

        if ($target->id === $actor->id) {
            return $this->failure('You cannot change your own role.', '/admin/users');
        }

        if ($target->role === Role::Admin && $role !== Role::Admin && $this->adminCount() <= 1) {
            return $this->failure('This is the only admin. Promote someone else first.', '/admin/users');
        }

        if ($target->role === $role) {
            return $this->failure($target->username . ' is already a ' . $role->label() . '.', '/admin/users');
        }

        $this->users->setRole($target->id, $role);
        $this->audit->record(
            $actor->id,
            'user.role_changed',
            'user',
            $target->id,
            ['role' => $target->role->value],
            ['role' => $role->value],
            $this->auth->ipHash($request->ip()),
            $request->userAgent()
        );

        return $this->success($target->username . ' is now a ' . $role->label() . '.', '/admin/users');
    }

    public function updateStatus(Request $request): Response
    {
        $actor = $this->auth->user();
        $target = $this->target($request);
        $status = UserStatus::tryFrom((string) $request->input('status'));

        if ($actor === null || $status === null) {
            return $this->failure('That is not a status.', '/admin/users');
        }

        if ($target->id === $actor->id) {
            return $this->failure('You cannot change your own status.', '/admin/users');
        }

        if ($target->role === Role::Admin && $status !== UserStatus::Active && $this->adminCount() <= 1) {
            return $this->failure('This is the only admin. Promote someone else first.', '/admin/users');
        }

        $days = (int) $request->input('days', 0);
        $reason = trim((string) $request->input('reason', ''));

        $this->users->setStatus(
            $target->id,
            $status,
            $reason === '' ? null : mb_substr($reason, 0, 255),
            $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null,
        );

        $this->audit->record(
            $actor->id,
            'user.status_changed',
            'user',
            $target->id,
            ['status' => $target->status->value],
            ['status' => $status->value, 'reason' => $reason, 'days' => $days],
            $this->auth->ipHash($request->ip()),
            $request->userAgent()
        );

        return $this->success($target->username . ' is now ' . $status->label() . '.', '/admin/users');
    }

    private function target(Request $request): \App\Models\User
    {
        $user = $this->users->findById((int) $request->parameter('id'));

        if ($user === null) {
            throw HttpException::notFound('No such user.');
        }

        return $user;
    }

    private function adminCount(): int
    {
        return $this->users->countsByRole()[Role::Admin->value] ?? 0;
    }
}
