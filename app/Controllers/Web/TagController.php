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
use App\Repositories\TagRepository;

final class TagController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TagRepository $tags,
        private readonly BookRepository $books,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('pages/tags/index', ['tags' => $this->tags->active(300)]);
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->parameter('slug');
        $tag = $this->tags->findBySlug($slug);

        if ($tag === null) {
            throw HttpException::notFound('No tag with that name.');
        }

        // An alias redirects to the tag it folds into, so there is one address
        // per tag rather than one per spelling.
        if ($tag->slug !== $slug) {
            return $this->redirect('/tags/' . $tag->slug);
        }

        return $this->render('pages/tags/show', [
            'tag'     => $tag,
            'results' => $this->books->search(['tag' => $tag->slug], (int) $request->query('page', 1), 24),
            'filters' => ['tag' => $tag->slug],
        ]);
    }
}
