<?php

declare(strict_types=1);

namespace App\Controllers\Librarian;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\TagRepository;
use App\Services\Auth;
use App\Services\Gate;
use App\Services\TaxonomyService;

final class TaxonomyController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly TaxonomyService $taxonomy,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        return $this->render('pages/librarian/taxonomy', [
            'tree'             => $this->categories->tree(false),
            'pendingCategories' => $this->categories->pending(),
            'pendingTags'      => $this->tags->pending(),
            'activeTags'       => $this->tags->active(300),
            'allCategories'    => $this->categories->all(false),
        ]);
    }

    /**
     * Also the member-facing proposal endpoint: whether this creates the
     * category or queues it is TaxonomyService's decision, and where the user
     * lands afterwards depends on whether they can see the queue.
     */
    public function storeCategory(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $back = $this->gate->allows('taxonomy.manage') ? '/librarian/taxonomy' : '/categories';

        $validator = new Validator($request->all(), [
            'name'        => 'required|min:2|max:120',
            'description' => 'max:500',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors($back, $validator->errors(), $request->all());
        }

        $parentId = (int) $request->input('parent_id', 0);

        try {
            $this->taxonomy->proposeCategory(
                (string) $request->input('name'),
                $parentId > 0 ? $parentId : null,
                (string) $request->input('description', '') ?: null,
                $user,
            );
        } catch (\RuntimeException $e) {
            return $this->failure($e->getMessage(), $back);
        }

        return $this->success(
            $this->gate->allows('taxonomy.manage')
                ? 'Category created.'
                : 'Proposed. A librarian decides on it before it appears.',
            $back
        );
    }

    public function decideCategory(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $approve = (string) $request->input('decision') === 'approve';
        $decided = $this->taxonomy->decideCategory((int) $request->parameter('id'), $approve, $user);

        if (!$decided) {
            return $this->failure('That category is not waiting for a decision.', '/librarian/taxonomy');
        }

        return $this->success($approve ? 'Category approved.' : 'Category rejected.', '/librarian/taxonomy');
    }

    public function decideTag(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $approve = (string) $request->input('decision') === 'approve';
        $this->taxonomy->decideTag((int) $request->parameter('id'), $approve, $user);

        return $this->success($approve ? 'Tag approved.' : 'Tag rejected.', '/librarian/taxonomy');
    }

    public function storeAlias(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        $added = $this->taxonomy->addAlias(
            (string) $request->input('alias'),
            (int) $request->parameter('id'),
            $user
        );

        if (!$added) {
            return $this->failure('That alias is empty or already a tag of its own.', '/librarian/taxonomy');
        }

        return $this->success('Alias added.', '/librarian/taxonomy');
    }
}
