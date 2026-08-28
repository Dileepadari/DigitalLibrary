<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\TagRepository;
use App\Services\Auth;
use App\Services\Gate;

final class BookController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(
        View $view,
        Session $session,
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly CollectionRepository $collections,
        private readonly TagRepository $tags,
        private readonly ReviewRepository $reviews,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    /** The browse and search page: /books and /search are the same screen. */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $results = $this->books->search($filters, (int) $request->query('page', 1), self::PER_PAGE);

        return $this->render('pages/books/index', [
            'results'    => $results,
            'filters'    => $filters,
            'facets'     => $this->books->facets($filters),
            'categories' => $this->categories->tree(),
            'popularTags' => $this->tags->active(24),
        ]);
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->parameter('slug');

        // A librarian can open a record that is not public yet; nobody else can.
        $book = $this->books->findBySlug($slug, !$this->gate->allows('moderation.queue'));

        if ($book === null) {
            throw HttpException::notFound('No book with that address.');
        }

        $this->books->incrementViews($book->id);

        $related = $book->tags === [] ? [] : $this->books->search(
            ['tag' => $book->tags[0]->slug],
            1,
            5
        )['rows'];

        $user = $this->auth->user();

        return $this->render('pages/books/show', [
            'book'        => $book,
            'collections' => $user === null ? [] : $this->collections->forUser($user->id),
            'reviews'     => $this->reviews->forBook(
                $book->id,
                $user?->id,
                $this->gate->allows('review.moderate')
            ),
            'mine'        => $user === null ? null : $this->reviews->findByUserAndBook($user->id, $book->id),
            'distribution' => $this->reviews->distribution($book->id),
            'related'     => array_values(array_filter(
                $related,
                static fn ($candidate): bool => $candidate->id !== $book->id
            )),
        ]);
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['q', 'category', 'tag', 'language', 'type', 'year_from', 'year_to', 'sort'] as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
