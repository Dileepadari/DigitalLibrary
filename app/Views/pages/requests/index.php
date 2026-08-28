<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\BookRequest>, total: int, page: int, pages: int} $results
 * @var array{status: string, q: string, sort: string} $filters
 * @var array<string, int> $counts
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Book requests';
$this->end();

$link = static fn (array $extra): string => '/requests?' . http_build_query(array_merge($filters, $extra));
?>
<section class="stack-wide">
    <h1>Book requests</h1>
    <p class="muted">
        Ask for something the library does not have. Upvote what you want too:
        the list is worked in order of demand, and everyone who voted hears when
        the book arrives.
    </p>

    <?php if ($this->gate->allows('request.create')) : ?>
        <details class="panel" <?= $this->errors('title') !== [] ? 'open' : '' ?>>
            <summary><strong>Ask for a book</strong></summary>

            <form method="post" action="<?= $this->url('requests') ?>" class="stack">
                <?= $this->csrf->field() ?>

                <div class="field-row">
                    <div class="field">
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" required maxlength="255"
                               value="<?= $this->e($this->old('title')) ?>">
                        <?php $this->include('partials/field-errors', ['field' => 'title']) ?>
                    </div>

                    <div class="field">
                        <label for="author">Author</label>
                        <input type="text" id="author" name="author" maxlength="160"
                               value="<?= $this->e($this->old('author')) ?>">
                    </div>

                    <div class="field">
                        <label for="isbn">ISBN</label>
                        <input type="text" id="isbn" name="isbn" maxlength="17"
                               value="<?= $this->e($this->old('isbn')) ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="note">Anything else</label>
                    <input type="text" id="note" name="note" maxlength="1000"
                           placeholder="Edition, translation, why you want it"
                           value="<?= $this->e($this->old('note')) ?>">
                </div>

                <button type="submit" class="button">Ask</button>
            </form>
        </details>
    <?php endif ?>

    <form method="get" action="<?= $this->url('requests') ?>" class="filter-bar">
        <input type="search" name="q" value="<?= $this->e($filters['q']) ?>"
               placeholder="Title or author" aria-label="Search requests">

        <select name="status" aria-label="Status">
            <option value="open" <?= $filters['status'] === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="fulfilled" <?= $filters['status'] === 'fulfilled' ? 'selected' : '' ?>>Fulfilled</option>
            <option value="any" <?= $filters['status'] === 'any' ? 'selected' : '' ?>>Everything</option>
        </select>

        <select name="sort" aria-label="Sort">
            <option value="">Most wanted</option>
            <option value="recent" <?= $filters['sort'] === 'recent' ? 'selected' : '' ?>>Newest</option>
            <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest</option>
        </select>

        <button type="submit" class="button button--small">Filter</button>
    </form>

    <p class="muted">
        <?= (int) ($counts['open'] ?? 0) + (int) ($counts['claimed'] ?? 0) ?> open,
        <?= (int) ($counts['fulfilled'] ?? 0) ?> fulfilled.
    </p>

    <div class="request-list">
        <?php foreach ($results['rows'] as $item) : ?>
            <article class="request-row">
                <?php $this->include('partials/vote-button', ['request' => $item]) ?>

                <div>
                    <h2 class="request-row__title">
                        <a href="<?= $this->url('request', ['id' => $item->id]) ?>">
                            <?= $this->e($item->title) ?>
                        </a>
                    </h2>
                    <p class="request-row__meta">
                        <?php if ($item->author !== null) : ?>
                            <?= $this->e($item->author) ?> &middot;
                        <?php endif ?>
                        asked by <?= $this->e($item->requesterName ?? 'someone') ?>
                        <?php if ($item->status !== App\Support\RequestStatus::Open) : ?>
                            &middot; <span class="tag"><?= $this->e($item->status->label()) ?></span>
                        <?php endif ?>
                    </p>
                </div>
            </article>
        <?php endforeach ?>

        <?php if ($results['rows'] === []) : ?>
            <p class="muted">Nothing outstanding. Everything anyone asked for is here.</p>
        <?php endif ?>
    </div>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => $filters,
        'path'    => '/requests',
    ]) ?>
</section>
