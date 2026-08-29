<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Book;
use App\Repositories\BookRepository;
use App\Services\Auth;
use App\Services\Gate;
use App\Services\ModerationService;
use App\Services\UploadPipeline;

/**
 * Attaching a file to a record that already exists. The same pipeline runs for
 * the file field on the "add a book" form.
 */
final class UploadController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BookRepository $books,
        private readonly UploadPipeline $pipeline,
        private readonly ModerationService $moderation,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        $book = $this->books->findBySlug((string) $request->parameter('slug'), false);

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($book === null) {
            throw HttpException::notFound('No book with that address.');
        }

        if (!$this->gate->canContribute()) {
            return $this->failure('Confirm your email address before uploading.', '/verify-email');
        }

        if (!$this->canAttachTo($book, $user->id)) {
            throw HttpException::forbidden('That is not your record to add to.');
        }

        $upload = $request->file('book_file');

        if ($upload === null) {
            return $this->failure('No file was chosen.', '/books/' . $book->slug);
        }

        $result = $this->pipeline->receive($upload, $book, $user);

        if (!$result->ok) {
            return $this->failure((string) $result->error, '/books/' . $book->slug);
        }

        $file = $result->file;

        if ($file === null) {
            return $this->failure('The upload did not complete.', '/books/' . $book->slug);
        }

        $requestId = $this->moderation->submitUpload($book, $file, $user, $this->gate->allows('book.publish'));

        foreach ($result->warnings as $warning) {
            $this->session->flash('error', $warning);
        }

        if ($requestId === null) {
            return $this->success('File added.', '/books/' . $book->slug);
        }

        return $this->success(
            'Uploaded. A librarian reviews it before it appears on the book.',
            '/me/submissions'
        );
    }

    private function canAttachTo(Book $book, int $userId): bool
    {
        if ($this->gate->allows('book.edit.any')) {
            return true;
        }

        return $book->addedBy === $userId && $this->gate->allows('book.edit.own');
    }
}
