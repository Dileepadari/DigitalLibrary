<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ApplicationRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\Auth;
use App\Services\NotificationService;
use App\Support\Role;

/**
 * Librarian applications. Only an admin decides these, which is why they have
 * their own screen rather than sitting in the queue every librarian can see.
 */
final class ApplicationController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ApplicationRepository $applications,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly NotificationService $notifications,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('pages/admin/applications', [
            'applications' => $this->applications->pending(),
        ]);
    }

    public function decide(Request $request): Response
    {
        $admin = $this->auth->user();
        $application = $this->applications->find((int) $request->parameter('id'));

        if ($admin === null) {
            return $this->redirect('/login');
        }

        if ($application === null) {
            throw HttpException::notFound('No such application.');
        }

        if ((string) $application['status'] !== 'pending') {
            return $this->failure('That application has already been decided.', '/admin/applications');
        }

        $approve = (string) $request->input('decision') === 'approve';
        $userId = (int) $application['user_id'];

        if ($approve) {
            $this->users->setRole($userId, Role::Librarian);
        }

        $this->applications->decide((int) $application['id'], $approve ? 'approved' : 'rejected', $admin->id);

        $this->audit->record(
            $admin->id,
            $approve ? 'librarian.approved' : 'librarian.rejected',
            'user',
            $userId,
            ['role' => 'member'],
            ['role' => $approve ? 'librarian' : 'member']
        );

        $this->notifications->send(
            $userId,
            'librarian.' . ($approve ? 'approved' : 'rejected'),
            $approve ? 'You are a librarian now' : 'Your librarian application was not accepted',
            $approve
                ? 'The moderation queue is open to you. Everything you upload publishes without review.'
                : 'You can apply again later.',
            $approve ? '/librarian/queue' : null
        );

        return $this->success(
            $approve ? $application['username'] . ' is a librarian now.' : 'Application declined.',
            '/admin/applications'
        );
    }
}
