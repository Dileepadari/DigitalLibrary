<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Models\BookRequest;
use App\Repositories\BookRepository;
use App\Repositories\BookRequestRepository;
use App\Services\Auth;
use App\Services\BookRequestService;
use App\Services\Gate;
use App\Support\RequestStatus;

final class RequestController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BookRequestRepository $requests,
        private readonly BookRequestService $service,
        private readonly BookRepository $books,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'q'      => trim((string) $request->query('q', '')),
            'sort'   => (string) $request->query('sort', ''),
        ];

        return $this->render('pages/requests/index', [
            'results' => $this->requests->paginate(
                $filters,
                (int) $request->query('page', 1),
                25,
                $this->auth->id()
            ),
            'filters' => $filters,
            'counts'  => $this->requests->countsByStatus(),
        ]);
    }

    public function show(Request $request): Response
    {
        return $this->render('pages/requests/show', ['request' => $this->find($request)]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        if (!$this->gate->canContribute()) {
            return $this->failure('Confirm your email address before raising a request.', '/verify-email');
        }

        $validator = new Validator($request->all(), [
            'title'  => 'required|min:2|max:255',
            'author' => 'max:160',
            'note'   => 'max:1000',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/requests', $validator->errors(), $request->all());
        }

        $created = $this->service->create($request->all(), $user);

        return $this->success(
            'Asked. Anyone can upvote it, and you will hear when it arrives.',
            '/requests/' . $created->id
        );
    }

    public function vote(Request $request): Response
    {
        $user = $this->auth->user();
        $bookRequest = $this->find($request);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $added = $this->service->toggleVote($bookRequest, $user);

        return $this->success(
            $added ? 'Counted. That is one more voice for it.' : 'Vote taken back.',
            '/requests/' . $bookRequest->id
        );
    }

    /** Claim, release, close or reopen: whichever the button said. */
    public function act(Request $request): Response
    {
        $user = $this->auth->user();
        $bookRequest = $this->find($request);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $action = (string) $request->input('action');

        $result = match ($action) {
            'claim'   => $this->service->claim($bookRequest, $user),
            'release' => $this->service->release($bookRequest, $user),
            'reopen'  => $this->service->reopen($bookRequest, $user),
            'close'   => $this->close($bookRequest, $request, $user),
            'fulfil'  => $this->fulfil($bookRequest, $request, $user),
            default   => ['ok' => false, 'message' => 'That is not something you can do.'],
        };

        $to = '/requests/' . $bookRequest->id;

        return $result['ok'] ? $this->success($result['message'], $to) : $this->failure($result['message'], $to);
    }

    /** @return array{ok: bool, message: string} */
    private function close(BookRequest $bookRequest, Request $request, \App\Models\User $user): array
    {
        $status = RequestStatus::tryFrom((string) $request->input('status'));

        if ($status === null) {
            return ['ok' => false, 'message' => 'That is not a status.'];
        }

        return $this->service->close($bookRequest, $status, trim((string) $request->input('reason', '')), $user);
    }

    /**
     * A librarian linking a record that is already in the catalogue, rather than
     * waiting for an upload to answer it.
     *
     * @return array{ok: bool, message: string}
     */
    private function fulfil(BookRequest $bookRequest, Request $request, \App\Models\User $user): array
    {
        if ($this->gate->denies('request.close')) {
            return ['ok' => false, 'message' => 'Only a librarian can link a record to a request.'];
        }

        $slug = trim((string) $request->input('slug'));
        $book = $slug === '' ? null : $this->books->findBySlug($slug, false);

        if ($book === null) {
            return ['ok' => false, 'message' => 'No book in the catalogue has that address.'];
        }

        if (!$this->service->fulfil($bookRequest->id, $book, $user)) {
            return ['ok' => false, 'message' => 'That request is not open.'];
        }

        return ['ok' => true, 'message' => 'Linked, and everyone who wanted it has been told.'];
    }

    private function find(Request $request): BookRequest
    {
        $bookRequest = $this->requests->findById((int) $request->parameter('id'), $this->auth->id());

        if ($bookRequest === null) {
            throw HttpException::notFound('No such request.');
        }

        return $bookRequest;
    }
}
