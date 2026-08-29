<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Category $category
 * @var list<App\Models\Category> $ancestors
 * @var list<App\Models\Category> $children
 * @var array{rows: list<App\Models\Book>, total: int, page: int, pages: int} $results
 * @var array<string, string> $filters
 */
$this->layout('layouts/app');
$this->section('title');
echo $this->e($category->name);
$this->end();
?>
<section class="stack-wide">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $this->url('categories') ?>">Categories</a>
        <?php foreach ($ancestors as $ancestor) : ?>
            <span aria-hidden="true">/</span>
            <a href="<?= $this->url('category', ['path' => $ancestor->relativePath()]) ?>">
                <?= $this->e($ancestor->name) ?>
            </a>
        <?php endforeach ?>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $this->e($category->name) ?></span>
    </nav>

    <h1><?= $this->e($category->name) ?></h1>

    <?php if ($category->description !== null) : ?>
        <p class="muted"><?= $this->e($category->description) ?></p>
    <?php endif ?>

    <?php if ($children !== []) : ?>
        <div class="panel">
            <h2>Inside this category</h2>
            <?php $this->include('partials/category-tree', ['nodes' => $children]) ?>
        </div>
    <?php endif ?>

    <p class="muted">
        <?= (int) $results['total'] ?> book<?= $results['total'] === 1 ? '' : 's' ?> here and in everything
        below it.
    </p>

    <?php $this->include('partials/book-grid', [
        'books'        => $results['rows'],
        'emptyMessage' => 'Nothing shelved here yet.',
    ]) ?>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => [],
        'path'    => '/categories/' . $category->relativePath(),
    ]) ?>
</section>
