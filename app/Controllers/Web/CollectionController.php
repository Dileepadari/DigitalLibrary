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
use App\Models\Collection;
use App\Models\User;
use App\Repositories\BookRepository;
use App\Repositories\CollectionRepository;
use App\Services\Auth;
use App\Services\CollectionService;
use App\Services\Gate;
use App\Services\ModerationService;
use App\Support\Visibility;

final class CollectionController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly CollectionRepository $collections,
        private readonly CollectionService $service,
        private readonly ModerationService $moderation,
        private readonly BookRepository $books,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();

        return $this->render('pages/collections/index', [
            'results'   => $this->collections->paginatePublic((int) $request->query('page', 1)),
            'mine'      => $user === null ? [] : $this->collections->forUser($user->id),
            'following' => $user === null ? [] : $this->collections->followedBy($user->id),
        ]);
    }

    /** One node at any depth: /collections/upsc-preparation/prelims/history. */
    public function show(Request $request): Response
    {
        $node = $this->collections->findByPath((string) $request->parameter('path'));

        if ($node === null) {
            throw HttpException::notFound('No collection at that address.');
        }

        $user = $this->auth->user();

        // A private collection is nobody else's business: it is not there.
        if (!$this->service->canView($node, $user)) {
            throw HttpException::notFound('No collection at that address.');
        }

        $rootId = $node->rootId ?? $node->id;
        $root = $this->collections->findById($rootId);

        return $this->render('pages/collections/show', [
            'node'        => $node,
            'root'        => $root ?? $node,
            'ancestors'   => $this->collections->ancestorsOf($node),
            'children'    => $this->collections->childrenOf($node->id),
            'items'       => $this->collections->items($node->id),
            'tree'        => $this->collections->tree($rootId),
            'canEdit'     => $this->service->canEdit($node, $user),
            'isFollowing' => $this->collections->isFollowing($rootId, $user?->id),
            'maintainers' => $this->collections->maintainers($rootId),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->redirect('/login');
        }

        if (!$this->gate->canContribute()) {
            return $this->failure('Confirm your email address before building a collection.', '/verify-email');
        }

        $validator = new Validator($request->all(), [
            'name'        => 'required|min:2|max:120',
            'description' => 'max:500',
        ]);

        if ($validator->fails()) {
            return $this->backWithErrors('/collections', $validator->errors(), $request->all());
        }

        $collection = $this->service->createRoot(
            (string) $request->input('name'),
            $this->nullable($request->input('description')),
            Visibility::tryFrom((string) $request->input('visibility', 'private')) ?? Visibility::Private,
            $user,
        );

        return $this->success(
            'Collection started. Add folders and books, then publish it when it is ready.',
            '/collections/' . $collection->relativePath()
        );
    }

    /**
     * The "add to a collection" control on a book page. A separate route because
     * the collection is chosen in the form: a select cannot change where the
     * form posts to without JavaScript, and the CSP does not allow any.
     */
    public function addFromBook(Request $request): Response
    {
        $user = $this->auth->user();
        $node = $this->collections->findById((int) $request->input('collection_id'));
        $slug = trim((string) $request->input('slug'));

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($node === null) {
            return $this->failure('No such collection.', '/books/' . $slug);
        }

        $result = $this->addBook($node, $request, $user);
        $to = '/books/' . $slug;

        return $result['ok'] ? $this->success($result['message'], $to) : $this->failure($result['message'], $to);
    }

    /**
     * Everything else a collection can have done to it. One endpoint with an
     * action, so the many small buttons on the page do not each need a route.
     */
    public function act(Request $request): Response
    {
        $user = $this->auth->user();
        $node = $this->collections->findById((int) $request->parameter('id'));

        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($node === null) {
            throw HttpException::notFound('No such collection.');
        }

        $result = match ((string) $request->input('action')) {
            'child'             => $this->addChild($node, $request, $user),
            'rename'            => $this->service->rename(
                $node,
                (string) $request->input('name'),
                $this->nullable($request->input('description')),
                $user
            ),
            'visibility'        => $this->service->setVisibility(
                $node,
                Visibility::tryFrom((string) $request->input('visibility')) ?? Visibility::Private,
                $user
            ),
            'publish'           => $this->service->requestPublication($node, $user, $this->moderation),
            'follow'            => $this->follow($node, $user),
            'fork'              => $this->fork($node, $user),
            'delete'            => $this->delete($node, $user),
            'add-book'          => $this->addBook($node, $request, $user),
            'remove-book'       => $this->service->removeBook($node, (int) $request->input('book_id'), $user),
            'move-book-up'      => $this->service->moveBook($node, (int) $request->input('book_id'), -1, $user),
            'move-book-down'    => $this->service->moveBook($node, (int) $request->input('book_id'), 1, $user),
            'add-maintainer'    => $this->service->addMaintainer(
                $node,
                (string) $request->input('username'),
                $user
            ),
            'remove-maintainer' => $this->service->removeMaintainer(
                $node,
                (int) $request->input('user_id'),
                $user
            ),
            default             => ['ok' => false, 'message' => 'That is not something you can do.'],
        };

        $to = $result['to'] ?? '/collections/' . $node->relativePath();

        return $result['ok'] ? $this->success($result['message'], $to) : $this->failure($result['message'], $to);
    }

    /** @return array{ok: bool, message: string, to?: string} */
    private function addChild(Collection $node, Request $request, User $user): array
    {
        $name = trim((string) $request->input('name'));

        if (mb_strlen($name) < 2) {
            return ['ok' => false, 'message' => 'Give the folder a name.'];
        }

        $created = $this->service->createChild($node, $name, $this->nullable($request->input('description')), $user);

        if ($created['ok'] && $created['collection'] !== null) {
            return [
                'ok'      => true,
                'message' => $created['message'],
                'to'      => '/collections/' . $created['collection']->relativePath(),
            ];
        }

        return ['ok' => $created['ok'], 'message' => $created['message']];
    }

    /** @return array{ok: bool, message: string} */
    private function addBook(Collection $node, Request $request, User $user): array
    {
        $slug = trim((string) $request->input('slug'));
        $book = $slug === '' ? null : $this->books->findBySlug($slug);

        if ($book === null) {
            return ['ok' => false, 'message' => 'No book in the catalogue has that address.'];
        }

        return $this->service->addBook($node, $book, $user, $this->nullable($request->input('note')));
    }

    /** @return array{ok: bool, message: string} */
    private function follow(Collection $node, User $user): array
    {
        $rootId = $node->rootId ?? $node->id;
        $root = $this->collections->findById($rootId);

        if ($root === null || $root->visibility === Visibility::Private) {
            return ['ok' => false, 'message' => 'You cannot follow a private collection.'];
        }

        $added = $this->service->toggleFollow($root, $user);

        return [
            'ok'      => true,
            'message' => $added
                ? 'Following. You will hear when a book is added.'
                : 'Not following any more.',
        ];
    }

    /** @return array{ok: bool, message: string, to?: string} */
    private function fork(Collection $node, User $user): array
    {
        $rootId = $node->rootId ?? $node->id;
        $root = $this->collections->findById($rootId);

        if ($root === null || !$root->isPublic()) {
            return ['ok' => false, 'message' => 'Only a public collection can be forked.'];
        }

        if (!$this->gate->canContribute()) {
            return ['ok' => false, 'message' => 'Confirm your email address first.'];
        }

        $copy = $this->service->fork($root, $user);

        return [
            'ok'      => true,
            'message' => 'Copied into your own collections. Do what you like with it.',
            'to'      => '/collections/' . $copy->relativePath(),
        ];
    }

    /** @return array{ok: bool, message: string, to?: string} */
    private function delete(Collection $node, User $user): array
    {
        $result = $this->service->delete($node, $user);

        if (!$result['ok']) {
            return $result;
        }

        $parent = $node->parentId === null ? null : $this->collections->findById($node->parentId);

        return [
            'ok'      => true,
            'message' => $result['message'],
            'to'      => $parent === null ? '/collections' : '/collections/' . $parent->relativePath(),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
