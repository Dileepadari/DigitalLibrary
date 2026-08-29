<?php
/**
 * @var App\Core\View $this
 * @var string $siteName
 * @var int $memberCount
 * @var int $bookCount
 * @var array<string, int> $totals
 * @var list<App\Models\Book> $recentBooks
 * @var list<App\Models\BookRequest> $mostWanted
 * @var list<array<string, mixed>> $reading
 */
$this->layout('layouts/app');
$this->section('title');
echo $this->e($siteName);
$this->end();

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

<?php
/*
 * What the library actually holds, spread across the page rather than stacked
 * in the corner of the hero.
 */
$figures = [
    ['label' => $this->t('Books'), 'value' => $bookCount, 'note' => $this->t('published and readable')],
    ['label' => $this->t('Files'), 'value' => (int) ($totals['files'] ?? 0), 'note' => $this->t('PDFs, EPUBs and text')],
    ['label' => $this->t('Members'), 'value' => $memberCount, 'note' => $this->t('people who joined')],
    ['label' => $this->t('Collections'), 'value' => (int) ($totals['collections'] ?? 0), 'note' => $this->t('published shelves')],
    ['label' => $this->t('Requests open'), 'value' => (int) ($totals['open_requests'] ?? 0), 'note' => $this->t('books nobody has added yet')],
    ['label' => $this->t('Reviews'), 'value' => (int) ($totals['reviews'] ?? 0), 'note' => $this->t('written by members')],
];
?>
<dl class="figures">
    <?php foreach ($figures as $figure) : ?>
        <div class="figure">
            <dd><?= number_format((int) $figure['value']) ?></dd>
            <dt><?= $this->e($figure['label']) ?></dt>
            <p class="figure__note"><?= $this->e($figure['note']) ?></p>
        </div>
    <?php endforeach ?>
</dl>


<?php if ($recentBooks !== []) : ?>
    <section aria-labelledby="recent-heading">
        <div class="section-head">
            <h2 id="recent-heading"><?= $this->e($this->t('Recently added')) ?></h2>
            <a href="<?= $this->url('books') ?>"><?= $this->e($this->t('Browse the library')) ?></a>
        </div>
        <?php $this->include('partials/book-grid', ['books' => $recentBooks, 'emptyMessage' => '']) ?>
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

