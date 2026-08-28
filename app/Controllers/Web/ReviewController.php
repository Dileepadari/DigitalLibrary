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
use App\Repositories\BookRepository;
use App\Repositories\ReviewRepository;
use App\Services\Auth;
use App\Services\ReviewService;

final class ReviewController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BookRepository $books,
        private readonly ReviewRepository $reviews,
        private readonly ReviewService $service,
        private readonly Auth $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        $book = $this->books->findBySlug((string) $request->parameter('slug'));

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($book === null) {
            throw HttpException::notFound('No book with that address.');
        }

        $validator = new Validator($request->all(), [
            'rating' => 'required|integer',
            'body'   => 'max:4000',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/books/' . $book->slug, $validator->errors(), $request->all());
        }

        $result = $this->service->submit(
            $book,
            $user,
            (int) $request->input('rating'),
            $this->nullable($request->input('body')),
        );

        $to = '/books/' . $book->slug;

        return $result['ok'] ? $this->success($result['message'], $to) : $this->failure($result['message'], $to);
    }

    /** Your own review, taken back. */
    public function destroy(Request $request): Response
    {
        return $this->act($request, fn ($user, $id): array => $this->service->remove($id, $user));
    }

    public function vote(Request $request): Response
    {
        return $this->act($request, fn ($user, $id): array => $this->service->vote($id, $user));
    }

    public function moderate(Request $request): Response
    {
        return $this->act($request, fn ($user, $id) => $this->service->moderate(
            $id,
            (string) $request->input('status'),
            (string) $request->input('reason', ''),
            $user
        ));
    }

    /**
     * The three actions differ only in what they call, and all three need the
     * same "who is this, which review, where do they go back to" work.
     */
    private function act(Request $request, callable $work): Response
    {
        $user = $this->auth->user();
        $id = (int) $request->parameter('id');
        $review = $this->reviews->find($id);

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($review === null) {
            throw HttpException::notFound('No such review.');
        }

        $book = $this->books->findById((int) $review['book_id'], false);
        $to = $book === null ? '/books' : '/books/' . $book->slug;

        /** @var array{ok: bool, message: string} $result */
        $result = $work($user, $id);

        return $result['ok'] ? $this->success($result['message'], $to) : $this->failure($result['message'], $to);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
