<?php

declare(strict_types=1);

namespace App\Controllers\Librarian;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BookRepository;

/**
 * What a librarian needs before the moderation queue proper lands in M3: the
 * records that are not public yet, in one list.
 */
final class CatalogueController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BookRepository $books,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'pending');

        return $this->render('pages/librarian/books', [
            'results' => $this->books->search(
                ['status' => $status, 'sort' => 'oldest'],
                (int) $request->query('page', 1),
                30
            ),
            'status'  => $status,
        ]);
    }
}
