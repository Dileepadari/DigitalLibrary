<?php
/**
 * @var App\Core\View $this
 * @var string $siteName
 * @var array<string, mixed> $status
 * @var int $memberCount
 * @var int $bookCount
 * @var list<App\Models\Book> $recentBooks
 * @var list<App\Models\BookRequest> $mostWanted
 * @var list<array<string, mixed>> $reading
 */
$this->layout('layouts/app');
$this->section('title');
echo $this->e($siteName);
$this->end();

$check = static fn (bool $ok): string => $ok
    ? '<span class="pill pill--ok">ready</span>'
    : '<span class="pill pill--warn">todo</span>';
?>

<section class="hero">
    <h1><?= $this->e($siteName) ?></h1>
    <p class="hero__tagline"><?= $this->e($this->config('app.tagline')) ?></p>
    <p class="hero__note">
        Milestones 0 to 6 are in place: the application core, accounts with roles
        and permissions, the catalogue, uploads with the review queue behind them,
        book requests, collections, and reading in the browser. See PLAN.md for
        what comes next.
    </p>

    <?php if (!$this->auth->check()) : ?>
        <p class="hero__actions">
            <a class="button" href="<?= $this->url('register') ?>">Create an account</a>
            <a class="button button--quiet" href="<?= $this->url('login') ?>">Sign in</a>
        </p>
    <?php else : ?>
        <p class="hero__actions">
            <a class="button" href="<?= $this->url('books') ?>">Browse the library</a>
            <?php if ($this->gate->allows('book.upload')) : ?>
                <a class="button button--quiet" href="<?= $this->url('books.new') ?>">Add a book</a>
            <?php endif ?>
        </p>
        <p class="hero__note">
            <?= (int) $bookCount ?> book<?= $bookCount === 1 ? '' : 's' ?>,
            <?= (int) $memberCount ?> <?= $memberCount === 1 ? 'account' : 'accounts' ?> so far.
        </p>
    <?php endif ?>
</section>

<?php if ($recentBooks !== []) : ?>
    <section aria-labelledby="recent-heading">
        <h2 id="recent-heading">Recently added</h2>
        <?php $this->include('partials/book-grid', ['books' => $recentBooks, 'emptyMessage' => '']) ?>
    </section>
<?php endif ?>

<section class="panel" aria-labelledby="status-heading">
    <h2 id="status-heading">Install status</h2>

    <dl class="status-grid">
        <div class="status-item">
            <dt>PHP <?= $this->e($status['php']['version']) ?></dt>
            <dd>
                <?= $check($status['php']['ok']) ?>
                <?php if ($status['php']['missing_extensions'] !== []) : ?>
                    <span class="status-item__detail">
                        missing: <?= $this->e(implode(', ', $status['php']['missing_extensions'])) ?>
                    </span>
                <?php endif ?>
            </dd>
        </div>

        <div class="status-item">
            <dt>Database</dt>
            <dd>
                <?= $check($status['database']['connected']) ?>
                <?php if ($status['database']['error'] !== null) : ?>
                    <span class="status-item__detail"><?= $this->e($status['database']['error']) ?></span>
                <?php endif ?>
            </dd>
        </div>

        <div class="status-item">
            <dt>Migrations</dt>
            <dd>
                <?= $check($status['database']['pending'] === [] && $status['database']['connected']) ?>
                <span class="status-item__detail">
                    <?= (int) $status['database']['applied'] ?> applied,
                    <?= count($status['database']['pending']) ?> pending
                    <?php if ($status['database']['pending'] !== []) : ?>
                        - run <code>php cli/console.php migrate</code>
                    <?php endif ?>
                </span>
            </dd>
        </div>

        <div class="status-item">
            <dt>Storage</dt>
            <dd>
                <?= $check($status['storage']['unwritable'] === []) ?>
                <?php if ($status['storage']['unwritable'] !== []) : ?>
                    <span class="status-item__detail">
                        not writable: <?= $this->e(implode(', ', $status['storage']['unwritable'])) ?>
                    </span>
                <?php endif ?>
            </dd>
        </div>
    </dl>
</section>

<?php if ($reading !== []) : ?>
    <section aria-labelledby="reading-heading">
        <h2 id="reading-heading">Carry on reading</h2>

        <ul class="collection-list">
            <?php foreach ($reading as $entry) : ?>
                <li>
                    <a href="/books/<?= $this->e((string) $entry['slug']) ?>/read/<?= (int) $entry['book_file_id'] ?>">
                        <?= $this->e((string) $entry['title']) ?>
                    </a>
                    <span class="status-item__detail">
                        <?= (int) $entry['percent'] ?>% through
                        &middot; <?= $this->e(strtoupper((string) $entry['format'])) ?>
                    </span>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>

<?php if ($mostWanted !== []) : ?>
    <section aria-labelledby="wanted-heading">
        <h2 id="wanted-heading">Most wanted</h2>
        <p class="muted">
            Books people have asked for. <a href="<?= $this->url('requests') ?>">See them all</a>
            or add one of these to the library.
        </p>

        <div class="request-list">
            <?php foreach ($mostWanted as $wanted) : ?>
                <article class="request-row">
                    <?php $this->include('partials/vote-button', ['request' => $wanted]) ?>
                    <div>
                        <h3 class="request-row__title">
                            <a href="<?= $this->url('request', ['id' => $wanted->id]) ?>">
                                <?= $this->e($wanted->title) ?>
                            </a>
                        </h3>
                        <p class="request-row__meta">
                            <?php if ($wanted->author !== null) : ?>
                                <?= $this->e($wanted->author) ?>
                            <?php endif ?>
                        </p>
                    </div>
                </article>
            <?php endforeach ?>
        </div>
    </section>
<?php endif ?>

<section class="panel" aria-labelledby="next-heading">
    <h2 id="next-heading">What lands next</h2>
    <ol class="roadmap">
        <li><strong>M7 Community</strong> - reviews, ratings, reputation and badges</li>
        <li><strong>M8 Admin</strong> - settings, the audit log viewer, storage and analytics</li>
        <li><strong>M9 Polish</strong> - full text search, the public API, OPDS, Hindi, accessibility</li>
    </ol>
</section>
