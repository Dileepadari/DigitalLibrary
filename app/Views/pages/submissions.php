<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\ModerationRequest>, total: int, page: int, pages: int} $results
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Your submissions';
$this->end();
?>
<section class="stack-wide">
    <h1>Your submissions</h1>
    <p class="muted">
        Everything you have sent in, and what came back. A librarian reviews each
        one; you get a notification either way.
    </p>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col">Sent</th>
                    <th scope="col">Status</th>
                    <th scope="col">Reason</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results['rows'] as $item) : ?>
                <tr>
                    <td>
                        <a href="<?= $this->url('submission', ['id' => $item->id]) ?>">
                            <?= $this->e($item->title) ?>
                        </a>
                        <span class="status-item__detail"><?= $this->e($item->type->label()) ?></span>
                    </td>
                    <td><?= $this->e(date('j M Y', strtotime($item->createdAt))) ?></td>
                    <td><span class="tag"><?= $this->e($item->status->label()) ?></span></td>
                    <td class="muted"><?= $this->e((string) $item->reason) ?></td>
                </tr>
            <?php endforeach ?>

            <?php if ($results['rows'] === []) : ?>
                <tr>
                    <td colspan="4" class="muted">
                        Nothing yet. <a href="<?= $this->url('books.new') ?>">Add a book</a> to start.
                    </td>
                </tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => [],
        'path'    => '/me/submissions',
    ]) ?>
</section>
