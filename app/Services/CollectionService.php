<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\Collection;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\UserRepository;
use App\Support\ModerationType;
use App\Support\Visibility;

/**
 * Building and sharing a collection tree.
 *
 * The rules about who may do what live here rather than in the controller,
 * because the same questions get asked from the node page, the book page and
 * the approval path.
 */
final class CollectionService
{
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $audit,
        private readonly NotificationService $notifications,
        private readonly Gate $gate,
    ) {
    }

    public function createRoot(string $name, ?string $description, Visibility $visibility, User $owner): Collection
    {
        $slug = $this->collections->uniqueSlug($name, '/');

        $id = $this->collections->insert([
            'parent_id'   => null,
            'root_id'     => null,
            'owner_id'    => $owner->id,
            'name'        => mb_substr(trim($name), 0, 120),
            'slug'        => $slug,
            'path'        => '/' . $slug . '/',
            'depth'       => 0,
            'description' => $description,
            // A new collection is never born public: it goes through the queue
            // like everything else, and until then it is the owner's own.
            'visibility'  => $visibility === Visibility::Public ? Visibility::Private->value : $visibility->value,
        ]);

        // A root is its own root, which keeps every "whole tree" query the same
        // shape whether it runs on a root or on a leaf.
        $this->collections->update($id, ['root_id' => $id]);
        $this->audit->record($owner->id, 'collection.created', 'collection', $id, null, ['name' => $name]);

        $created = $this->collections->findById($id);

        if ($created === null) {
            throw new \RuntimeException('The collection was created but could not be read back.');
        }

        return $created;
    }

    /** @return array{ok: bool, message: string, collection: Collection|null} */
    public function createChild(Collection $parent, string $name, ?string $description, User $actor): array
    {
        if (!$this->canEdit($parent, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.', 'collection' => null];
        }

        if ($parent->depth + 1 >= CollectionRepository::MAX_DEPTH) {
            return [
                'ok'         => false,
                'message'    => 'Collections cannot nest more than ' . CollectionRepository::MAX_DEPTH . ' deep.',
                'collection' => null,
            ];
        }

        $slug = $this->collections->uniqueSlug($name, $parent->path);

        $id = $this->collections->insert([
            'parent_id'   => $parent->id,
            'root_id'     => $parent->rootId ?? $parent->id,
            'owner_id'    => $parent->ownerId,
            'name'        => mb_substr(trim($name), 0, 120),
            'slug'        => $slug,
            'path'        => $parent->path . $slug . '/',
            'depth'       => $parent->depth + 1,
            'description' => $description,
            'visibility'  => $parent->visibility->value,
            'review_status' => $parent->reviewStatus,
        ]);

        $created = $this->collections->findById($id);

        return ['ok' => true, 'message' => 'Folder added.', 'collection' => $created];
    }

    /** @return array{ok: bool, message: string} */
    public function addBook(Collection $node, Book $book, User $actor, ?string $note = null): array
    {
        if (!$this->canEdit($node, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        if (!$this->collections->addItem($node->id, $book->id, $actor->id, $note)) {
            return ['ok' => false, 'message' => 'That book is already in this folder.'];
        }

        $this->notifyFollowers($node, $book, $actor);

        return ['ok' => true, 'message' => 'Added to ' . $node->name . '.'];
    }

    /** @return array{ok: bool, message: string} */
    public function removeBook(Collection $node, int $bookId, User $actor): array
    {
        if (!$this->canEdit($node, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        $this->collections->removeItem($node->id, $bookId);

        return ['ok' => true, 'message' => 'Removed.'];
    }

    /** @return array{ok: bool, message: string} */
    public function moveBook(Collection $node, int $bookId, int $direction, User $actor): array
    {
        if (!$this->canEdit($node, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        $this->collections->moveItem($node->id, $bookId, $direction);

        return ['ok' => true, 'message' => 'Reordered.'];
    }

    /** @return array{ok: bool, message: string} */
    public function rename(Collection $node, string $name, ?string $description, User $actor): array
    {
        if (!$this->canEdit($node, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        // The name changes; the address does not, because links to it exist.
        $this->collections->update($node->id, [
            'name'        => mb_substr(trim($name), 0, 120),
            'description' => $description,
        ]);

        return ['ok' => true, 'message' => 'Saved.'];
    }

    /** @return array{ok: bool, message: string} */
    public function setVisibility(Collection $root, Visibility $visibility, User $actor): array
    {
        if (!$root->isRoot()) {
            return ['ok' => false, 'message' => 'Visibility is set on the whole collection, not one folder.'];
        }

        if (!$this->canEdit($root, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        if ($visibility === Visibility::Public) {
            return ['ok' => false, 'message' => 'Publishing goes through the queue; use the publish button.'];
        }

        $this->collections->updateTree($root->rootId ?? $root->id, [
            'visibility'    => $visibility->value,
            'review_status' => 'none',
        ]);

        return ['ok' => true, 'message' => 'Now ' . mb_strtolower($visibility->label()) . '.'];
    }

    /** @return array{ok: bool, message: string} */
    public function delete(Collection $node, User $actor): array
    {
        if (!$this->canEdit($node, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        $this->collections->delete($node->id);
        $this->audit->record($actor->id, 'collection.deleted', 'collection', $node->id, [
            'name' => $node->name,
        ], null);

        return ['ok' => true, 'message' => 'Deleted, and everything inside it.'];
    }

    /**
     * Copies a public collection into the person's own private tree, books and
     * folders and all, so they can take it somewhere else.
     */
    public function fork(Collection $root, User $actor): Collection
    {
        $copy = $this->createRoot($root->name, $root->description, Visibility::Private, $actor);
        $this->collections->update($copy->id, ['forked_from_id' => $root->id]);

        $map = [$root->id => $copy->id];

        // Ordered by depth, so a node's parent is always already copied.
        foreach ($this->flatten($this->collections->tree($root->rootId ?? $root->id)) as $node) {
            if ($node->id !== $root->id) {
                $parentId = $map[$node->parentId] ?? $copy->id;
                $parent = $this->collections->findById($parentId);

                if ($parent === null) {
                    continue;
                }

                $child = $this->createChild($parent, $node->name, $node->description, $actor);

                if ($child['collection'] !== null) {
                    $map[$node->id] = $child['collection']->id;
                }
            }

            foreach ($this->collections->items($node->id) as $item) {
                $this->collections->addItem($map[$node->id] ?? $copy->id, $item['book']->id, $actor->id);
            }
        }

        $this->audit->record($actor->id, 'collection.forked', 'collection', $copy->id, null, [
            'from' => $root->id,
        ]);

        $forked = $this->collections->findById($copy->id);

        return $forked ?? $copy;
    }

    /**
     * Asks for the whole tree to be made public. The queue item is the same kind
     * of thing as an upload, so it lands in the same list with the same rules.
     *
     * @return array{ok: bool, message: string}
     */
    public function requestPublication(Collection $root, User $actor, ModerationService $moderation): array
    {
        if (!$root->isRoot()) {
            return ['ok' => false, 'message' => 'A collection is published whole, not folder by folder.'];
        }

        if (!$this->canEdit($root, $actor)) {
            return ['ok' => false, 'message' => 'That is not your collection.'];
        }

        if ($this->gate->denies('collection.propose.public')) {
            return ['ok' => false, 'message' => 'You are not allowed to publish a collection.'];
        }

        if ($root->isPublic()) {
            return ['ok' => false, 'message' => 'It is already public.'];
        }

        if ($root->isAwaitingReview()) {
            return ['ok' => false, 'message' => 'It is already waiting for a librarian.'];
        }

        if ($root->itemCount === 0) {
            return ['ok' => false, 'message' => 'Put something in it first.'];
        }

        $rootId = $root->rootId ?? $root->id;
        $this->collections->updateTree($rootId, ['review_status' => 'pending']);

        $moderation->open(
            ModerationType::CollectionPublish,
            $rootId,
            'Collection: ' . $root->name,
            ['books' => $root->itemCount],
            $actor,
        );

        return ['ok' => true, 'message' => 'Sent for review. A librarian looks at the whole tree.'];
    }

    /** Called by the approval engine, which owns the decision itself. */
    public function applyPublicationDecision(int $rootId, bool $approve): void
    {
        $this->collections->updateTree($rootId, $approve
            ? ['visibility' => Visibility::Public->value, 'review_status' => 'approved']
            : ['review_status' => 'rejected']);
    }

    public function toggleFollow(Collection $root, User $actor): bool
    {
        return $this->collections->toggleFollow($root->rootId ?? $root->id, $actor->id);
    }

    /** @return array{ok: bool, message: string} */
    public function addMaintainer(Collection $root, string $username, User $actor): array
    {
        if (!$root->isOwnedBy($actor->id)) {
            return ['ok' => false, 'message' => 'Only the owner can invite maintainers.'];
        }

        $user = $this->users->findByUsername($username);

        if ($user === null) {
            return ['ok' => false, 'message' => 'No member with that username.'];
        }

        if ($user->id === $actor->id) {
            return ['ok' => false, 'message' => 'You already own it.'];
        }

        $this->collections->addMaintainer($root->rootId ?? $root->id, $user->id, $actor->id);

        $this->notifications->send(
            $user->id,
            'collection.invited',
            $actor->username . ' asked you to help with ' . $root->name,
            null,
            '/collections/' . $root->relativePath()
        );

        return ['ok' => true, 'message' => $user->username . ' can edit it now.'];
    }

    /** @return array{ok: bool, message: string} */
    public function removeMaintainer(Collection $root, int $userId, User $actor): array
    {
        if (!$root->isOwnedBy($actor->id) && $actor->id !== $userId) {
            return ['ok' => false, 'message' => 'Only the owner can remove a maintainer.'];
        }

        $this->collections->removeMaintainer($root->rootId ?? $root->id, $userId);

        return ['ok' => true, 'message' => 'Removed.'];
    }

    /** Owner or invited maintainer. A librarian does not get to edit someone's shelf. */
    public function canEdit(Collection $collection, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($collection->isOwnedBy($user->id)) {
            return true;
        }

        return in_array(
            $user->id,
            $this->collections->maintainerIds($collection->rootId ?? $collection->id),
            true
        );
    }

    /** Public and unlisted are readable by anyone with the address. */
    public function canView(Collection $collection, ?User $user): bool
    {
        if ($collection->visibility !== Visibility::Private) {
            return true;
        }

        return $this->canEdit($collection, $user) || $this->gate->allows('collection.approve');
    }

    /**
     * @param list<Collection> $nodes
     *
     * @return list<Collection> the tree as a flat list, parents before children
     */
    private function flatten(array $nodes): array
    {
        $flat = [];

        foreach ($nodes as $node) {
            $flat[] = $node;
            $flat = array_merge($flat, $this->flatten($node->children));
        }

        return $flat;
    }

    private function notifyFollowers(Collection $node, Book $book, User $actor): void
    {
        $rootId = $node->rootId ?? $node->id;
        $root = $this->collections->findById($rootId);

        if ($root === null || $root->visibility === Visibility::Private) {
            return;
        }

        foreach ($this->collections->followerIds($rootId) as $followerId) {
            if ($followerId === $actor->id) {
                continue;
            }

            $this->notifications->send(
                $followerId,
                'collection.item_added',
                $book->title . ' was added to ' . $root->name,
                $node->id === $rootId ? null : 'In ' . $node->name,
                '/collections/' . $node->relativePath()
            );
        }
    }
}
