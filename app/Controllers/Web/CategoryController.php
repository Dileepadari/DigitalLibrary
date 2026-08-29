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

final class CategoryController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly CategoryRepository $categories,
        private readonly BookRepository $books,
    ) {
        parent::__construct($view, $session);
    }

    /** The whole tree, at /categories. */
    public function index(Request $request): Response
    {
        return $this->render('pages/categories/index', [
            'tree'    => $this->categories->tree(),
            'options' => $this->categories->all(),
        ]);
    }

    /**
     * One category at any depth: /categories/academics/competitive-exams/upsc.
     * The books listed are the subtree's, not just this node's.
     */
    public function show(Request $request): Response
    {
        $path = (string) $request->parameter('path');
        $category = $this->categories->findByPath($path);

        if ($category === null) {
            throw HttpException::notFound('No category at that address.');
        }

        $filters = ['category' => $category->relativePath()];
        $sort = trim((string) $request->query('sort', ''));

        if ($sort !== '') {
            $filters['sort'] = $sort;
        }

        return $this->render('pages/categories/show', [
            'category'  => $category,
            'ancestors' => $this->categories->ancestorsOf($category),
            'children'  => $this->categories->childrenOf($category->id),
            'results'   => $this->books->search($filters, (int) $request->query('page', 1), 24),
            'filters'   => $filters,
        ]);
    }
}
