<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\TagRepository;
use App\Support\ModerationType;

/**
 * Categories and tags, and the difference between proposing one and creating
 * one: a librarian's goes live, a member's waits in the queue.
 */
final class TaxonomyService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly BookRepository $books,
        private readonly AuditLogRepository $audit,
        private readonly ModerationRepository $requests,
        private readonly ModerationService $moderation,
        private readonly Gate $gate,
    ) {
    }

    public function proposeCategory(string $name, ?int $parentId, ?string $description, User $actor): int
    {
        $approved = $this->gate->allows('taxonomy.manage');

        $id = $this->categories->create(
            $name,
            $parentId,
            $description,
            $approved ? 'active' : 'pending',
            $actor->id,
        );

        $this->audit->record($actor->id, $approved ? 'category.created' : 'category.proposed', 'category', $id, null, [
            'name'      => $name,
            'parent_id' => $parentId,
        ]);

        // A proposal is a queue item like any other, so it shows up next to the
        // uploads rather than on a screen of its own.
        if (!$approved) {
            $this->moderation->open(
                ModerationType::CategoryProposal,
                $id,
                'Category: ' . $name,
                ['name' => $name, 'parent_id' => $parentId, 'description' => $description],
                $actor,
            );
        }

        return $id;
    }

    /**
     * The taxonomy screen's approve and reject buttons. When the proposal has a
     * queue entry the decision goes through the engine, so the event log and the
     * notification are the same as they would be from the queue screen.
     */
    public function decideCategory(int $id, bool $approve, User $actor): bool
    {
        $category = $this->categories->findById($id);

        if ($category === null || $category->status !== 'pending') {
            return false;
        }

        $request = $this->requests->openForSubject(ModerationType::CategoryProposal->value, $id);

        if ($request !== null) {
            return $this->moderation->decide(
                $request,
                $approve,
                $approve ? '' : 'Not a category this library needs',
                $actor
            )['ok'];
        }

        if ($approve) {
            $this->categories->setStatus($id, 'active');
        } else {
            $this->categories->delete($id);
        }

        $this->audit->record(
            $actor->id,
            $approve ? 'category.approved' : 'category.rejected',
            'category',
            $id,
            ['status' => 'pending'],
            ['status' => $approve ? 'active' : 'deleted']
        );

        return true;
    }

    public function decideTag(int $id, bool $approve, User $actor): bool
    {
        $this->tags->setStatus($id, $approve ? 'active' : 'rejected', $actor->id);
        $this->books->refreshCounters();

        $this->audit->record(
            $actor->id,
            $approve ? 'tag.approved' : 'tag.rejected',
            'tag',
            $id,
            ['status' => 'pending'],
            ['status' => $approve ? 'active' : 'rejected']
        );

        return true;
    }

    public function addAlias(string $alias, int $tagId, User $actor): bool
    {
        if (!$this->tags->addAlias($alias, $tagId)) {
            return false;
        }

        $this->audit->record($actor->id, 'tag.alias_added', 'tag', $tagId, null, ['alias' => $alias]);

        return true;
    }
}
