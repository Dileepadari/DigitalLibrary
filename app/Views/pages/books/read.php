<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Book $book
 * @var App\Models\BookFile $file
 * @var array{position: string, percent: int, last_read_at: string}|null $progress
 * @var list<array{id: int, position: string, label: string|null, note: string|null, created_at: string}> $bookmarks
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Reading ' . $this->e($book->title);
$this->end();

$base = '/books/' . $book->slug . '/read/' . $file->id;

// A module, because PDF.js ships as one. Loaded at the end of the body so the
// element it reads its settings from already exists.
$this->section('scripts');
?>
<script type="module" src="<?= $this->asset('/assets/js/reader.js') ?>"></script>
<?php
$this->end();
?>
<section class="reader"
         data-reader
         data-kind="<?= $this->e($file->readerKind()) ?>"
         data-file="<?= $this->url('file', ['id' => $file->id]) ?>?inline"
         data-worker="<?= $this->asset('/assets/vendor/pdf.worker.min.mjs') ?>"
         data-progress-url="<?= $this->e($base) ?>/progress"
         data-position="<?= $this->e($progress['position'] ?? '') ?>"
         data-token="<?= $this->e($this->csrf->token()) ?>">

    <header class="reader__bar">
        <div class="reader__title">
            <a href="<?= $this->url('book', ['slug' => $book->slug]) ?>">&lt; <?= $this->e($book->title) ?></a>
            <span class="status-item__detail">
                <?= $this->e(strtoupper($file->format)) ?>
                <?php if ($file->pageCount !== null) : ?>
                    &middot; <?= (int) $file->pageCount ?> pages
                <?php endif ?>
                <?php if ($progress !== null) : ?>
                    &middot; you were <?= (int) $progress['percent'] ?>% through
                <?php endif ?>
            </span>
        </div>

        <div class="reader__controls">
            <button type="button" class="button button--small button--quiet" data-reader-prev>Previous</button>
            <span class="reader__where" data-reader-where role="status" aria-live="polite">&nbsp;</span>
            <button type="button" class="button button--small button--quiet" data-reader-next>Next</button>
        </div>

        <div class="reader__actions">
            <a class="button button--small button--quiet"
               href="<?= $this->url('file', ['id' => $file->id]) ?>">Download</a>
        </div>
    </header>

    <?php if ($file->readerKind() === 'none') : ?>
        <p class="banner banner--warn">
            <?= $this->e(strtoupper($file->format)) ?> files cannot be opened in the
            browser. Download it and use a reader that understands the format.
        </p>
    <?php else : ?>
        <p class="banner banner--warn" data-reader-error hidden></p>

        <div class="reader__stage">
            <div class="reader__viewport" data-reader-viewport>
                <p class="muted" data-reader-status>Opening the file...</p>
            </div>

            <aside class="reader__aside panel">
                <h2>Bookmarks</h2>

                <form method="post" action="<?= $this->e($base) ?>/bookmarks" class="stack">
                    <?= $this->csrf->field() ?>
                    <input type="hidden" name="position" value="" data-reader-position>

                    <div class="field">
                        <label for="label">Mark this place</label>
                        <input type="text" id="label" name="label" maxlength="120" placeholder="Chapter 3">
                    </div>

                    <div class="field">
                        <label for="note">Note</label>
                        <input type="text" id="note" name="note" maxlength="500">
                    </div>

                    <button type="submit" class="button button--small">Add a bookmark</button>
                </form>

                <?php if ($bookmarks === []) : ?>
                    <p class="muted">None yet.</p>
                <?php endif ?>

                <ul class="facet-list">
                    <?php foreach ($bookmarks as $bookmark) : ?>
                        <li>
                            <a href="#" data-reader-goto="<?= $this->e($bookmark['position']) ?>">
                                <?= $this->e($bookmark['label'] ?? ('Position ' . $bookmark['position'])) ?>
                            </a>
                            <?php if ($bookmark['note'] !== null) : ?>
                                <span class="status-item__detail"><?= $this->e($bookmark['note']) ?></span>
                            <?php endif ?>

                            <form method="post"
                                  action="<?= $this->e($base) ?>/bookmarks/<?= (int) $bookmark['id'] ?>/delete"
                                  class="inline-form">
                                <?= $this->csrf->field() ?>
                                <button type="submit" class="link-button">remove</button>
                            </form>
                        </li>
                    <?php endforeach ?>
                </ul>

                <p class="field__hint">
                    Arrow keys turn pages. Where you got to is saved as you read.
                </p>
            </aside>
        </div>
    <?php endif ?>
</section>
