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
use App\Models\Book;
use App\Repositories\BookRepository;
use App\Repositories\BookRequestRepository;
use App\Repositories\CategoryRepository;
use App\Services\Auth;
use App\Services\BookRequestService;
use App\Services\BookService;
use App\Services\Gate;
use App\Services\ModerationService;
use App\Services\UploadPipeline;
use App\Support\BookStatus;

/**
 * Adding and editing a catalogue record.
 *
 * A member with `book.upload` may add one, and it lands in `pending`. A
 * librarian's goes straight to `published`; the difference is decided in
 * BookService, not here.
 */
final class BookFormController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly BookService $service,
        private readonly BookRequestRepository $requests,
        private readonly BookRequestService $requestService,
        private readonly UploadPipeline $pipeline,
        private readonly ModerationService $moderation,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    public function create(Request $request): Response
    {
        if (!$this->gate->canContribute()) {
            return $this->failure('Confirm your email address before adding to the library.', '/verify-email');
        }

        $requestId = (int) $request->query('request', 0);

        return $this->render('pages/books/form', [
            'book'        => null,
            'categories'  => $this->categories->all(),
            'bookRequest' => $requestId > 0 ? $this->requests->findById($requestId) : null,
        ]);
    }

    public function store(Request $request): Response
    {
        if (!$this->gate->canContribute()) {
            return $this->failure('Confirm your email address before adding to the library.', '/verify-email');
        }

        $validator = $this->validate($request);

        if ($validator->fails()) {
            return $this->backWithErrors('/books/new', $validator->errors(), $request->all());
        }

        $user = $this->auth->user();
        $book = $this->service->create($request->all(), $user);
        $upload = $request->file('book_file');

        // An open request this record answers, if the form came from one.
        $requestId = (int) $request->input('request_id', 0);
        $answers = $requestId > 0 && $this->requests->findById($requestId) !== null ? $requestId : null;

        // The file field is optional: a record with no file is a perfectly good
        // catalogue entry, and someone else can attach one later.
        $chosen = $upload !== null && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ? $upload
            : null;

        if ($user !== null && $chosen !== null) {
            $result = $this->pipeline->receive($chosen, $book, $user);

            if (!$result->ok) {
                $this->session->flash('error', 'The record was saved, but the file was not: ' . $result->error);
            } elseif ($result->file !== null) {
                $this->moderation->submitUpload(
                    $book,
                    $result->file,
                    $user,
                    $this->gate->allows('book.publish'),
                    $answers,
                );
                $answers = null;

                foreach ($result->warnings as $warning) {
                    $this->session->flash('error', $warning);
                }
            }
        }

        // A record that is public straight away answers its request now; one
        // that is waiting answers it when its queue item is approved.
        if ($answers !== null && $book->status->isPublic()) {
            $this->requestService->fulfil($answers, $book, $user);
        }

        // No file was attached, so nothing else has queued this record for
        // review. Queue it here, or it would wait in `pending` unnoticed.
        if ($user !== null && $chosen === null && !$book->status->isPublic()) {
            $this->moderation->submitRecord($book, $user, $answers);
        }

        if ($book->status->isPublic()) {
            return $this->success('Added to the catalogue.', '/books/' . $book->slug);
        }

        return $this->success(
            'Submitted. A librarian reviews it before it appears in the catalogue.',
            $user === null ? '/books' : '/me/submissions'
        );
    }

    public function edit(Request $request): Response
    {
        $book = $this->editable($request);

        return $this->render('pages/books/form', [
            'book'        => $book,
            'categories'  => $this->categories->all(),
            'bookRequest' => null,
        ]);
    }

    public function update(Request $request): Response
    {
        $book = $this->editable($request);
        $validator = $this->validate($request);

        if ($validator->fails()) {
            return $this->backWithErrors('/books/' . $book->slug . '/edit', $validator->errors(), $request->all());
        }

        $updated = $this->service->update($book, $request->all(), $this->auth->user());

        return $this->success('Saved.', '/books/' . $updated->slug);
    }

    /** Publish, hide or reject a record. Needs `book.publish`. */
    public function status(Request $request): Response
    {
        $this->gate->authorize('book.publish');

        $book = $this->books->findBySlug((string) $request->parameter('slug'), false);
        $status = BookStatus::tryFrom((string) $request->input('status'));

        if ($book === null) {
            throw HttpException::notFound('No book with that address.');
        }

        if ($status === null) {
            return $this->failure('That is not a status.', '/books/' . $book->slug);
        }

        $this->service->setStatus($book, $status, $this->auth->user());

        return $this->success('Now ' . mb_strtolower($status->label()) . '.', '/books/' . $book->slug);
    }

    private function validate(Request $request): Validator
    {
        return new Validator($request->all(), [
            'title'          => 'required|min:2|max:255',
            'subtitle'       => 'max:255',
            'authors'        => 'max:500',
            'publisher'      => 'max:160',
            'edition'        => 'max:60',
            'description'    => 'max:20000',
            'source_url'     => 'url',
            'licence'        => 'required|in:public_domain,cc_by,cc_by_sa,cc_other,author_permission,own_work,unknown',
            'content_type'   => 'in:book,magazine,comic,academic_paper,notes,audiobook,video',
            'published_year' => 'integer',
            'page_count'     => 'integer',
        ], [
            'authors' => 'author list',
        ]);
    }

    /**
     * The record this request may edit: your own, or anyone's with
     * `book.edit.any`.
     */
    private function editable(Request $request): Book
    {
        $book = $this->books->findBySlug((string) $request->parameter('slug'), false);

        if ($book === null) {
            throw HttpException::notFound('No book with that address.');
        }

        if ($this->gate->allows('book.edit.any')) {
            return $book;
        }

        $user = $this->auth->user();

        if ($user !== null && $book->addedBy === $user->id && $this->gate->allows('book.edit.own')) {
            return $book;
        }

        throw HttpException::forbidden('That is not your record to edit.');
    }
}
