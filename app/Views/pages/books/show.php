<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Book $book
 * @var list<App\Models\Book> $related
 * @var list<App\Models\Collection> $collections
 */

use App\Support\BookStatus;

$this->layout('layouts/app');
$this->section('title');
echo $this->e($book->title);
$this->end();

$user = $this->auth->user();
$canEdit = $this->gate->allows('book.edit.any')
    || ($user !== null && $book->addedBy === $user->id && $this->gate->allows('book.edit.own'));
?>
<article class="book">
    <div class="book__head">
        <div class="book__cover cover cover--<?= (int) ($book->id % 6) ?>">
            <?php if ($book->coverPath !== null) : ?>
                <img src="<?= $this->e($book->coverPath) ?>" alt="Cover of <?= $this->e($book->title) ?>">
            <?php else : ?>
                <span><?= $this->e(mb_substr($book->title, 0, 1)) ?></span>
            <?php endif ?>
        </div>

        <div class="book__identity">
            <?php if (!$book->status->isPublic()) : ?>
                <p><span class="tag tag--warn"><?= $this->e($book->status->label()) ?></span></p>
            <?php endif ?>

            <h1><?= $this->e($book->title) ?></h1>

            <?php if ($book->subtitle !== null) : ?>
                <p class="book__subtitle"><?= $this->e($book->subtitle) ?></p>
            <?php endif ?>

            <p class="book__byline"><?= $this->e($book->byline()) ?></p>

            <?php if ($book->tags !== []) : ?>
                <p class="book__tags">
                    <?php foreach ($book->tags as $tag) : ?>
                        <a class="tag" href="<?= $this->url('tag', ['slug' => $tag->slug]) ?>">
                            <?= $this->e($tag->name) ?>
                        </a>
                    <?php endforeach ?>
                </p>
            <?php endif ?>

            <div class="book__actions">
                <?php if ($book->hasFiles()) : ?>
                    <?php foreach ($book->publishedFiles() as $file) : ?>
                        <?php if ($this->gate->allows('book.download')) : ?>
                            <a class="button" href="<?= $this->url('file', ['id' => $file->id]) ?>">
                                Download <?= $this->e(strtoupper($file->format)) ?>
                                &middot; <?= $this->e($file->humanSize()) ?>
                            </a>
                        <?php else : ?>
                            <span class="button button--quiet">
                                <?= $this->e(strtoupper($file->format)) ?>
                                &middot; <?= $this->e($file->humanSize()) ?>
                            </span>
                        <?php endif ?>
                    <?php endforeach ?>

                    <?php if (!$this->gate->allows('book.download')) : ?>
                        <p class="muted">
                            <a href="<?= $this->url('login') ?>">Sign in</a> to download.
                        </p>
                    <?php endif ?>
                <?php else : ?>
                    <p class="muted">No file is attached to this record yet.</p>
                <?php endif ?>

                <?php if ($canEdit) : ?>
                    <a class="button button--small button--quiet"
                       href="<?= $this->url('books.edit', ['slug' => $book->slug]) ?>">Edit</a>
                <?php endif ?>
            </div>

            <?php if ($canEdit && $this->gate->allows('book.upload')) : ?>
                <form method="post" action="<?= $this->url('books.files', ['slug' => $book->slug]) ?>"
                      enctype="multipart/form-data" class="inline-form">
                    <?= $this->csrf->field() ?>
                    <input type="file" name="book_file" required
                           accept=".pdf,.epub,.mobi,.djvu,.cbz,.txt"
                           aria-label="File to attach">
                    <button type="submit" class="button button--small">Attach a file</button>
                </form>
                <p class="muted">
                    <?= $this->gate->allows('book.publish')
                        ? 'Yours goes straight into the library.'
                        : 'A librarian reviews it before it appears here.' ?>
                </p>
            <?php endif ?>

            <?php if ($this->gate->allows('book.publish')) : ?>
                <form method="post" action="<?= $this->url('books.status', ['slug' => $book->slug]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <select name="status" aria-label="Status">
                        <?php foreach (BookStatus::all() as $status) : ?>
                            <option value="<?= $this->e($status->value) ?>"
                                <?= $book->status === $status ? 'selected' : '' ?>>
                                <?= $this->e($status->label()) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                    <button type="submit" class="button button--small">Set status</button>
                </form>
            <?php endif ?>
        </div>
    </div>

    <?php if ($collections !== []) : ?>
        <section class="panel">
            <h2>Add to a collection</h2>

            <form method="post" action="<?= $this->url('collections.add') ?>" class="inline-form">
                <?= $this->csrf->field() ?>
                <input type="hidden" name="slug" value="<?= $this->e($book->slug) ?>">

                <select name="collection_id" aria-label="Collection">
                    <?php foreach ($collections as $collection) : ?>
                        <option value="<?= (int) $collection->id ?>"><?= $this->e($collection->name) ?></option>
                    <?php endforeach ?>
                </select>

                <button type="submit" class="button button--small">Add</button>
            </form>
            <p class="field__hint">
                It goes into the top of that collection. Move it into a folder from
                the collection page.
            </p>
        </section>
    <?php endif ?>

    <?php if ($book->description !== null) : ?>
        <section class="book__description">
            <h2>About</h2>
            <p><?= nl2br($this->e($book->description)) ?></p>
        </section>
    <?php endif ?>

    <section class="panel">
        <h2>Details</h2>
        <dl class="status-grid">
            <?php
            $details = [
                'Kind'      => $book->contentType->label(),
                'Language'  => strtoupper($book->language),
                'Published' => $book->publishedYear !== null ? (string) $book->publishedYear : null,
                'Publisher' => $book->publisherName,
                'Edition'   => $book->edition,
                'Pages'     => $book->pageCount !== null ? (string) $book->pageCount : null,
                'ISBN'      => $book->isbn13 ?? $book->isbn10,
                'Licence'   => $book->licence->label(),
                'Views'     => (string) $book->viewCount,
            ];
            ?>
            <?php foreach ($details as $label => $value) : ?>
                <?php if ($value !== null && $value !== '') : ?>
                    <div class="status-item">
                        <dt><?= $this->e($label) ?></dt>
                        <dd><?= $this->e($value) ?></dd>
                    </div>
                <?php endif ?>
            <?php endforeach ?>
        </dl>

        <?php if ($book->licenceNote !== null) : ?>
            <p class="muted">Licence note: <?= $this->e($book->licenceNote) ?></p>
        <?php endif ?>

        <?php if ($book->sourceUrl !== null) : ?>
            <p class="muted">Source: <a href="<?= $this->e($book->sourceUrl) ?>"
                rel="nofollow noopener"><?= $this->e($book->sourceUrl) ?></a></p>
        <?php endif ?>
    </section>

    <?php if ($book->categories !== []) : ?>
        <section class="panel">
            <h2>Shelved under</h2>
            <ul class="breadcrumb-list">
                <?php foreach ($book->categories as $category) : ?>
                    <li>
                        <a href="<?= $this->url('category', ['path' => $category->relativePath()]) ?>">
                            <?= $this->e(str_replace('/', ' / ', $category->relativePath())) ?>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>

    <?php if ($related !== []) : ?>
        <section>
            <h2>Related</h2>
            <?php $this->include('partials/book-grid', ['books' => $related, 'emptyMessage' => '']) ?>
        </section>
    <?php endif ?>
</article>
