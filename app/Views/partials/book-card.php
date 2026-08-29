<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Book $book
 */
?>
<article class="book-card">
    <a class="book-card__cover cover cover--<?= (int) ($book->id % 6) ?>"
       href="<?= $this->url('book', ['slug' => $book->slug]) ?>" aria-hidden="true" tabindex="-1">
        <?php if ($book->coverPath !== null) : ?>
            <img src="<?= $this->url('cover', ['id' => $book->id]) ?>" alt="" loading="lazy">
        <?php else : ?>
            <span><?= $this->e(mb_substr($book->title, 0, 1)) ?></span>
        <?php endif ?>
    </a>

    <div class="book-card__body">
        <h3 class="book-card__title">
            <a href="<?= $this->url('book', ['slug' => $book->slug]) ?>"><?= $this->e($book->title) ?></a>
        </h3>
        <p class="book-card__byline"><?= $this->e($book->byline()) ?></p>

        <p class="book-card__meta">
            <?php if ($book->publishedYear !== null) : ?>
                <span><?= (int) $book->publishedYear ?></span>
            <?php endif ?>
            <span><?= $this->e($book->contentType->label()) ?></span>
            <?php if ($book->formats() !== []) : ?>
                <span><?= $this->e(implode(', ', $book->formats())) ?></span>
            <?php else : ?>
                <span class="muted">no file yet</span>
            <?php endif ?>
        </p>

        <?php if ($book->tags !== []) : ?>
            <p class="book-card__tags">
                <?php foreach (array_slice($book->tags, 0, 3) as $tag) : ?>
                    <a class="tag" href="<?= $this->url('tag', ['slug' => $tag->slug]) ?>">
                        <?= $this->e($tag->name) ?>
                    </a>
                <?php endforeach ?>
            </p>
        <?php endif ?>

        <?php if (!$book->status->isPublic()) : ?>
            <p><span class="tag tag--warn"><?= $this->e($book->status->label()) ?></span></p>
        <?php endif ?>
    </div>
</article>
