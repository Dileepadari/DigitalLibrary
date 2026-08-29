<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\Book>, total: int, page: int, pages: int} $results
 * @var string $status
 */

use App\Support\BookStatus;

$this->layout('layouts/app');
$this->section('title');
echo 'Catalogue';
$this->end();
?>
<section class="stack-wide">
    <h1>Catalogue</h1>
    <p class="muted">
        Every record in the library by status. Uploads waiting on a decision are
        in the review queue.
    </p>

    <nav class="filter-bar">
        <?php foreach (BookStatus::all() as $option) : ?>
            <a class="tag <?= $status === $option->value ? 'tag--role' : '' ?>"
               href="<?= $this->url('librarian.books') ?>?status=<?= $this->e($option->value) ?>">
                <?= $this->e($option->label()) ?>
            </a>
        <?php endforeach ?>
        <a class="tag <?= $status === 'any' ? 'tag--role' : '' ?>"
           href="<?= $this->url('librarian.books') ?>?status=any">Everything</a>
    </nav>

    <?php $this->include('partials/book-grid', [
        'books'        => $results['rows'],
        'emptyMessage' => 'Nothing with that status.',
    ]) ?>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => ['status' => $status],
        'path'    => '/librarian/books',
    ]) ?>
</section>
