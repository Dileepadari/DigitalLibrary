<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\ModerationRequest>, total: int, page: int, pages: int} $results
 * @var array{status: string, type: string, mine: int} $filters
 * @var array<string, int> $counts
 * @var float|null $median
 */

use App\Support\ModerationType;

$this->layout('layouts/app');
$this->section('title');
echo 'Queue';
$this->end();

$me = $this->auth->user();
$link = static fn (array $extra): string => '/librarian/queue?' . http_build_query(array_merge(
    ['status' => $filters['status'], 'type' => $filters['type']],
    $extra
));
?>
<section class="stack-wide">
    <h1>Moderation queue</h1>

    <p class="muted">
        <?= (int) $results['total'] ?> item<?= $results['total'] === 1 ? '' : 's' ?> here.
        <?php if ($median !== null) : ?>
            Median time to a decision so far: <?= $this->e((string) $median) ?> hours.
        <?php endif ?>
        Oldest first, because a queue that is not first in first out is a pile.
    </p>

    <nav class="filter-bar">
        <?php foreach (['open' => 'Open', 'approved' => 'Approved', 'rejected' => 'Rejected', 'any' => 'Everything'] as $value => $label) : ?>
            <a class="tag <?= $filters['status'] === $value ? 'tag--role' : '' ?>"
               href="<?= $this->e($link(['status' => $value])) ?>"><?= $this->e($label) ?></a>
        <?php endforeach ?>

        <span class="muted">&middot;</span>

        <a class="tag <?= $filters['type'] === '' ? 'tag--role' : '' ?>"
           href="<?= $this->e($link(['type' => ''])) ?>">All kinds</a>
        <?php foreach (ModerationType::all() as $type) : ?>
            <a class="tag <?= $filters['type'] === $type->value ? 'tag--role' : '' ?>"
               href="<?= $this->e($link(['type' => $type->value])) ?>"><?= $this->e($type->label()) ?></a>
        <?php endforeach ?>

        <a class="tag <?= $filters['mine'] > 0 ? 'tag--role' : '' ?>"
           href="<?= $this->e($link(['mine' => $filters['mine'] > 0 ? '' : '1'])) ?>">Claimed by me</a>
    </nav>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col">From</th>
                    <th scope="col">Waiting</th>
                    <th scope="col">Status</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results['rows'] as $item) : ?>
                <tr class="<?= $item->ageInDays() >= 7 && $item->status->isOpen() ? 'row--overdue' : '' ?>">
                    <td>
                        <a href="<?= $this->url('queue.show', ['id' => $item->id]) ?>">
                            <?= $this->e($item->title) ?>
                        </a>
                        <span class="status-item__detail"><?= $this->e($item->type->label()) ?></span>
                    </td>
                    <td>
                        <?php if ($item->submitterName !== null) : ?>
                            <a href="<?= $this->url('profile', ['username' => $item->submitterName]) ?>">
                                <?= $this->e($item->submitterName) ?>
                            </a>
                        <?php else : ?>
                            <span class="muted">gone</span>
                        <?php endif ?>
                    </td>
                    <td>
                        <?= $item->ageInDays() ?> day<?= $item->ageInDays() === 1 ? '' : 's' ?>
                    </td>
                    <td>
                        <span class="tag"><?= $this->e($item->status->label()) ?></span>
                        <?php if ($item->isClaimed()) : ?>
                            <span class="status-item__detail">
                                claimed by <?= $this->e((string) $item->assigneeName) ?>
                            </span>
                        <?php endif ?>
                    </td>
                    <td>
                        <?php if ($item->status->isOpen() && !$item->isClaimed()) : ?>
                            <form method="post" action="<?= $this->url('queue.claim', ['id' => $item->id]) ?>"
                                  class="inline-form">
                                <?= $this->csrf->field() ?>
                                <button type="submit" class="button button--small">Claim</button>
                            </form>
                        <?php elseif ($item->isClaimedBy($me?->id)) : ?>
                            <a class="button button--small"
                               href="<?= $this->url('queue.show', ['id' => $item->id]) ?>">Review</a>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>

            <?php if ($results['rows'] === []) : ?>
                <tr><td colspan="5" class="muted">Nothing here. The shelves are tidy.</td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => ['status' => $filters['status'], 'type' => $filters['type']],
        'path'    => '/librarian/queue',
    ]) ?>
</section>
