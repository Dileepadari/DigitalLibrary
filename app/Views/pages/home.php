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

<div class="hero-band">
    <section class="hero">
        <h1><?= $this->e($siteName) ?></h1>
        <p class="hero__tagline"><?= $this->e($this->config('app.tagline')) ?></p>
        <p class="hero__note">
            <?= $this->e($this->t('Search the catalogue, read in the browser, ask for a book nobody has added yet, and upload the ones you have. Everything a member adds is reviewed by a librarian before it goes in.')) ?>
        </p>
    
        <form class="hero__search" method="get" action="<?= $this->url('books') ?>" role="search">
            <label class="visually-hidden" for="hero-search"><?= $this->e($this->t('Search the library')) ?></label>
            <input id="hero-search" type="search" name="q"
                   placeholder="<?= $this->e($this->t('Title, author, or a phrase from inside a book')) ?>">
            <button class="button" type="submit"><?= $this->e($this->t('Search')) ?></button>
        </form>
    
        <?php if (!$this->auth->check()) : ?>
            <p class="hero__actions">
                <a class="button button--quiet" href="<?= $this->url('register') ?>"><?= $this->e($this->t('Create an account')) ?></a>
                <a class="button button--quiet" href="<?= $this->url('login') ?>"><?= $this->e($this->t('Sign in')) ?></a>
            </p>
        <?php elseif ($this->gate->allows('book.upload')) : ?>
            <p class="hero__actions">
                <a class="button button--quiet" href="<?= $this->url('books.new') ?>"><?= $this->e($this->t('Add a book')) ?></a>
                <a class="button button--quiet" href="<?= $this->url('requests') ?>"><?= $this->e($this->t('Ask for a book')) ?></a>
            </p>
        <?php endif ?>
    
        <dl class="figures">
            <div>
                <dt><?= $this->e($this->t('Books')) ?></dt>
                <dd><?= (int) $bookCount ?></dd>
            </div>
            <div>
                <dt><?= $this->e($this->t('Members')) ?></dt>
                <dd><?= (int) $memberCount ?></dd>
            </div>
        </dl>
    </section>

    <?php if ($recentBooks !== []) : ?>
        <div class="hero__shelf" aria-hidden="true">
            <?php foreach (array_slice($recentBooks, 0, 3) as $index => $shelfBook) : ?>
                <span class="hero__shelf-item cover cover--<?= (int) ($shelfBook->id % 6) ?>">
                    <?php if ($shelfBook->coverPath !== null) : ?>
                        <img src="<?= $this->url('cover', ['id' => $shelfBook->id]) ?>" alt="" loading="lazy">
                    <?php else : ?>
                        <span><?= $this->e(mb_substr($shelfBook->title, 0, 1)) ?></span>
                    <?php endif ?>
                </span>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>

<?php if ($recentBooks !== []) : ?>
    <section aria-labelledby="recent-heading">
        <div class="section-head">
            <h2 id="recent-heading"><?= $this->e($this->t('Recently added')) ?></h2>
            <a href="<?= $this->url('books') ?>"><?= $this->e($this->t('Browse the library')) ?></a>
        </div>
        <?php $this->include('partials/book-grid', ['books' => $recentBooks, 'emptyMessage' => '']) ?>
    </section>
<?php endif ?>

<?php
/*
 * The install panel is for whoever has to fix it: an admin, or anyone at all
 * while something is actually broken. A working public library does not need a
 * checklist on its front page.
 */
$showStatus = !$status['ok'] || $this->gate->allows('settings.manage');
?>
<?php if ($showStatus) : ?>
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
<?php endif ?>

<?php if ($reading !== []) : ?>
    <section aria-labelledby="reading-heading">
        <div class="section-head">
            <h2 id="reading-heading"><?= $this->e($this->t('Carry on reading')) ?></h2>
        </div>

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
        <div class="section-head">
            <h2 id="wanted-heading"><?= $this->e($this->t('Most wanted')) ?></h2>
            <a href="<?= $this->url('requests') ?>"><?= $this->e($this->t('All requests')) ?></a>
        </div>
        <p class="muted">Books people have asked for and nobody has added yet.</p>

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

