<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ModerationRepository;
use App\Repositories\NotificationRepository;
use App\Services\Auth;
use App\Services\ModerationService;

/**
 * The submitter's side of the queue: what you sent in, what came back, and the
 * notifications about it.
 */
final class SubmissionController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ModerationRepository $requests,
        private readonly NotificationRepository $notifications,
        private readonly ModerationService $moderation,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        return $this->render('pages/submissions', [
            'results' => $this->requests->paginate(
                ['status' => 'any', 'submitter' => $user->id],
                (int) $request->query('page', 1),
                25
            ),
        ]);
    }

    public function show(Request $request): Response
    {
        $user = $this->auth->user();
        $submission = $this->requests->findById((int) $request->parameter('id'));

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($submission === null || $submission->submitterId !== $user->id) {
            throw HttpException::notFound('No such submission.');
        }

        return $this->render('pages/submission', [
            'request'  => $submission,
            'events'   => $this->requests->events($submission->id),
            'comments' => $this->requests->comments($submission->id),
        ]);
    }

    public function act(Request $request): Response
    {
        $user = $this->auth->user();
        $submission = $this->requests->findById((int) $request->parameter('id'));

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($submission === null || $submission->submitterId !== $user->id) {
            throw HttpException::notFound('No such submission.');
        }

        $result = match ((string) $request->input('action')) {
            'resubmit' => $this->moderation->resubmit($submission, $user),
            'withdraw' => $this->moderation->withdraw($submission, $user),
            'comment'  => $this->addComment($submission, $request, $user),
            default    => ['ok' => false, 'message' => 'That is not something you can do.'],
        };

        $to = '/me/submissions/' . $submission->id;

        return $result['ok'] ? $this->success($result['message'], $to) : $this->failure($result['message'], $to);
    }

    public function notifications(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $rows = $this->notifications->forUser($user->id);
        $this->notifications->markAllRead($user->id);

        return $this->render('pages/notifications', ['notifications' => $rows]);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function addComment(
        \App\Models\ModerationRequest $submission,
        Request $request,
        \App\Models\User $user,
    ): array {
        $this->moderation->comment($submission, (string) $request->input('body', ''), $user);

        return ['ok' => true, 'message' => 'Comment added.'];
    }
}
