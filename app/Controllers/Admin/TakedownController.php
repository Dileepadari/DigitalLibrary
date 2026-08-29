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
use App\Repositories\BookRepository;
use App\Repositories\TakedownRepository;
use App\Services\Auth;
use App\Services\BookService;
use App\Support\BookStatus;

/**
 * The takedown console.
 *
 * Upholding a notice unpublishes the book at once. The record stays, and so
 * does the reason: an operator has to be able to show what they did and when.
 */
final class TakedownController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TakedownRepository $takedowns,
        private readonly BookRepository $books,
        private readonly BookService $bookService,
        private readonly AuditLogRepository $audit,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');

        return $this->render('pages/admin/takedowns', [
            'notices' => $this->takedowns->paginate($status),
            'status'  => $status,
        ]);
    }

    public function decide(Request $request): Response
    {
        $user = $this->auth->user();
        $notice = $this->takedowns->find((int) $request->parameter('id'));

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($notice === null) {
            throw HttpException::notFound('No such notice.');
        }

        if ((string) $notice['status'] !== 'open') {
            return $this->failure('That notice has already been decided.', '/admin/takedowns');
        }

        $decision = (string) $request->input('decision');
        $note = trim((string) $request->input('note', ''));

        if (!in_array($decision, ['upheld', 'rejected'], true)) {
            return $this->failure('That is not a decision.', '/admin/takedowns');
        }

        if ($decision === 'upheld' && $notice['book_id'] !== null) {
            $book = $this->books->findById((int) $notice['book_id'], false);

            if ($book !== null) {
                $this->bookService->setStatus($book, BookStatus::Hidden, $user);
            }
        }

        $this->takedowns->decide((int) $notice['id'], $decision, $note === '' ? null : $note, $user->id);

        $this->audit->record(
            $user->id,
            'takedown.' . $decision,
            'takedown',
            (int) $notice['id'],
            ['status' => 'open'],
            ['status' => $decision, 'note' => $note, 'book_id' => $notice['book_id']]
        );

        return $this->success(
            $decision === 'upheld'
                ? 'Upheld. The book is no longer public.'
                : 'Rejected, and the reason is on the record.',
            '/admin/takedowns'
        );
    }
}
