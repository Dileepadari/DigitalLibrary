<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Tag $tag
 * @var array{rows: list<App\Models\Book>, total: int, page: int, pages: int} $results
 * @var array<string, string> $filters
 */
$this->layout('layouts/app');
$this->section('title');
echo $this->e($tag->name);
$this->end();
?>
<section class="stack-wide">
    <h1>Tagged <?= $this->e($tag->name) ?></h1>
    <p class="muted"><?= (int) $results['total'] ?> book<?= $results['total'] === 1 ? '' : 's' ?>.</p>

    <?php $this->include('partials/book-grid', [
        'books'        => $results['rows'],
        'emptyMessage' => 'Nothing carries this tag yet.',
    ]) ?>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => [],
        'path'    => '/tags/' . $tag->slug,
    ]) ?>
</section>
