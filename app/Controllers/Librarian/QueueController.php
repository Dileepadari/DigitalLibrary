<?php

declare(strict_types=1);

namespace App\Controllers\Librarian;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ModerationRequest;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\ModerationRepository;
use App\Services\Auth;
use App\Services\ModerationService;
use App\Support\ModerationType;
use App\Support\RejectionReason;

/**
 * The moderation queue and the review screen. Guarded by `moderation.queue`.
 */
final class QueueController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ModerationRepository $requests,
        private readonly ModerationService $moderation,
        private readonly BookRepository $books,
        private readonly BookFileRepository $files,
        private readonly CollectionRepository $collections,
        private readonly CategoryRepository $categories,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();

        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'type'   => (string) $request->query('type', ''),
            'mine'   => $request->query('mine') !== null && $user !== null ? $user->id : 0,
        ];

        return $this->render('pages/librarian/queue', [
            'results' => $this->requests->paginate($filters, (int) $request->query('page', 1), 25),
            'filters' => $filters,
            'counts'  => $this->requests->countsByStatus(),
            'median'  => $this->requests->medianDecisionHours(),
        ]);
    }

    public function show(Request $request): Response
    {
        $moderationRequest = $this->find($request);
        $book = null;
        $file = null;
        $duplicates = [];

        $aboutABook = in_array(
            $moderationRequest->type,
            [ModerationType::BookUpload, ModerationType::BookRecord],
            true
        );

        if ($aboutABook && $moderationRequest->subjectId !== null) {
            $book = $this->books->findById($moderationRequest->subjectId);
            $fileId = (int) ($moderationRequest->payload['file_id'] ?? 0);
            $file = $fileId > 0 ? $this->files->findById($fileId) : null;

            if ($book !== null) {
                $duplicates = $this->files->similarBooks($book->id, $book->title);
            }
        }

        /*
         * A reviewer of a collection or a proposed category needs to see the
         * thing itself, not only its title in the queue.
         */
        $subject = null;

        if ($moderationRequest->subjectId !== null) {
            $subject = match ($moderationRequest->type) {
                ModerationType::CollectionPublish => $this->collections->findById($moderationRequest->subjectId),
                ModerationType::CategoryProposal  => $this->categories->findById($moderationRequest->subjectId),
                default                           => null,
            };
        }

        return $this->render('pages/librarian/review', [
            'request'    => $moderationRequest,
            'subject'    => $subject,
            'book'       => $book,
            'file'       => $file,
            'duplicates' => $duplicates,
            'events'     => $this->requests->events($moderationRequest->id),
            'comments'   => $this->requests->comments($moderationRequest->id),
            'record'     => $moderationRequest->submitterId === null
                ? null
                : $this->requests->submitterRecord($moderationRequest->submitterId),
            'reasons'    => RejectionReason::all(),
        ]);
    }

    public function claim(Request $request): Response
    {
        $user = $this->auth->user();
        $moderationRequest = $this->find($request);

        if ($user === null) {
            return $this->redirect('/login');
        }

        if (!$this->moderation->claim($moderationRequest, $user)) {
            return $this->failure(
                'Someone else is reviewing that. It frees up when their claim expires.',
                '/librarian/queue'
            );
        }

        return $this->success('Claimed. It is yours for 30 minutes.', '/librarian/queue/' . $moderationRequest->id);
    }

    public function release(Request $request): Response
    {
        $user = $this->auth->user();
        $moderationRequest = $this->find($request);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $this->moderation->release($moderationRequest, $user);

        return $this->success('Back in the queue.', '/librarian/queue');
    }

    public function decide(Request $request): Response
    {
        $user = $this->auth->user();
        $moderationRequest = $this->find($request);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $decision = (string) $request->input('decision');
        $reason = trim((string) $request->input('reason', ''));
        $canned = trim((string) $request->input('canned_reason', ''));

        if ($canned !== '') {
            $reason = $reason === '' ? $canned : $canned . ': ' . $reason;
        }

        $result = match ($decision) {
            'approve' => $this->moderation->decide($moderationRequest, true, $reason, $user),
            'reject'  => $this->moderation->decide($moderationRequest, false, $reason, $user),
            'changes' => $this->moderation->requestChanges($moderationRequest, $reason, $user),
            default   => ['ok' => false, 'message' => 'That is not a decision.'],
        };

        if (!$result['ok']) {
            return $this->failure($result['message'], '/librarian/queue/' . $moderationRequest->id);
        }

        return $this->success($result['message'], '/librarian/queue');
    }

    public function comment(Request $request): Response
    {
        $user = $this->auth->user();
        $moderationRequest = $this->find($request);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $this->moderation->comment($moderationRequest, (string) $request->input('body', ''), $user);

        return $this->success('Comment added.', '/librarian/queue/' . $moderationRequest->id);
    }

    private function find(Request $request): ModerationRequest
    {
        $moderationRequest = $this->requests->findById((int) $request->parameter('id'));

        if ($moderationRequest === null) {
            throw HttpException::notFound('No such queue item.');
        }

        return $moderationRequest;
    }
}
