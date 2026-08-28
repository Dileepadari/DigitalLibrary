<?php
/**
 * @var App\Core\View $this
 * @var list<App\Models\Book> $books
 * @var string $emptyMessage
 */
?>
<?php if ($books === []) : ?>
    <p class="muted"><?= $this->e($emptyMessage ?? 'Nothing here yet.') ?></p>
<?php else : ?>
    <div class="book-grid">
        <?php foreach ($books as $book) : ?>
            <?php $this->include('partials/book-card', ['book' => $book]) ?>
        <?php endforeach ?>
    </div>
<?php endif ?>
