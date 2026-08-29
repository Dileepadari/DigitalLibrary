<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\BookFile;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\ReadingRepository;
use App\Services\Auth;
use App\Services\Gate;

/**
 * Reading in the browser, and the two small endpoints the reader talks to.
 *
 * The reader itself is JavaScript (PDF.js or epub.js, both vendored), but every
 * decision about what may be read and whose progress is whose is made here.
 */
final class ReaderController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BookRepository $books,
        private readonly BookFileRepository $files,
        private readonly ReadingRepository $reading,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    public function show(Request $request): Response
    {
        $this->gate->authorize('book.read');

        $user = $this->auth->user();
        $file = $this->readable($request);
        $book = $this->books->findById($file->bookId, false);

        if ($book === null) {
            throw HttpException::notFound('No book for that file.');
        }

        return $this->render('pages/books/read', [
            'book'      => $book,
            'file'      => $file,
            'progress'  => $user === null ? null : $this->reading->progress($user->id, $file->id),
            'bookmarks' => $user === null ? [] : $this->reading->bookmarks($user->id, $file->id),
        ]);
    }

    /**
     * Called by the reader every few pages. Answers JSON, because the page it
     * came from is not being replaced.
     */
    public function saveProgress(Request $request): Response
    {
        $user = $this->auth->user();
        $file = $this->readable($request);

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $position = trim((string) $request->input('position', ''));

        if ($position === '') {
            return Response::json(['ok' => false, 'error' => 'No position given.'], 422);
        }

        $this->reading->saveProgress($user->id, $file->id, $position, (int) $request->input('percent', 0));

        return Response::json(['ok' => true]);
    }

    public function addBookmark(Request $request): Response
    {
        $user = $this->auth->user();
        $file = $this->readable($request);

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $position = trim((string) $request->input('position', ''));

        if ($position === '') {
            return $this->failure('A bookmark needs a place to point at.', $this->readerUrl($request));
        }

        $this->reading->addBookmark(
            $user->id,
            $file->id,
            $position,
            $this->nullable($request->input('label')),
            $this->nullable($request->input('note')),
        );

        return $this->success('Bookmarked.', $this->readerUrl($request));
    }

    public function deleteBookmark(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        // The user id is part of the delete, so one person cannot remove
        // another's bookmark by guessing its id.
        $removed = $this->reading->deleteBookmark($user->id, (int) $request->parameter('bookmark'));

        return $removed
            ? $this->success('Bookmark removed.', $this->readerUrl($request))
            : $this->failure('That is not your bookmark.', $this->readerUrl($request));
    }

    /**
     * The file this request is allowed to read: published, on a book that is
     * public, unless the reader is a reviewer or the person who uploaded it.
     */
    private function readable(Request $request): BookFile
    {
        $file = $this->files->findById((int) $request->parameter('file'));

        if ($file === null) {
            throw HttpException::notFound('No such file.');
        }

        $book = $this->books->findById($file->bookId, false);

        if ($book === null) {
            throw HttpException::notFound('No book for that file.');
        }

        if ($book->slug !== (string) $request->parameter('slug')) {
            throw HttpException::notFound('That file is not on that book.');
        }

        if (!$file->isPublished() || !$book->status->isPublic()) {
            $user = $this->auth->user();
            $isUploader = $user !== null && $file->uploadedBy === $user->id;

            if (!$this->gate->allows('moderation.queue') && !$isUploader) {
                throw HttpException::notFound('No such file.');
            }
        }

        return $file;
    }

    private function readerUrl(Request $request): string
    {
        return '/books/' . $request->parameter('slug') . '/read/' . $request->parameter('file');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
