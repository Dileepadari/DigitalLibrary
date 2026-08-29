<?php
/**
 * The site chrome: brand, the six primary destinations, and everything about
 * the signed in person folded into one menu so the bar does not grow a link per
 * permission.
 *
 * @var App\Core\View $this
 */
$user = $this->auth->user();
$unread = $this->notifications->unreadCount($user?->id);
$here = (string) ($this->currentPath ?? '');

/** A nav link is current when the page is it, or lives under it. */
$current = static function (string $path) use ($here): string {
    $isHere = $path === '/' ? $here === '/' : ($here === $path || str_starts_with($here, $path . '/'));

    return $isHere ? ' aria-current="page"' : '';
};
?>
<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="/">
            <span class="brand__badge">
                <img class="logo-mono" src="<?= $this->asset('/assets/img/logo-mark.png') ?>" alt="" width="20" height="20">
            </span>
            <span class="brand__name"><?= $this->e($this->appName) ?></span>
        </a>

        <nav class="site-nav" aria-label="<?= $this->e($this->t('Main')) ?>">
            <a href="<?= $this->url('books') ?>"<?= $current('/books') ?>><?= $this->e($this->t('Browse')) ?></a>
            <a href="<?= $this->url('categories') ?>"<?= $current('/categories') ?>><?= $this->e($this->t('Categories')) ?></a>
            <a href="<?= $this->url('tags') ?>"<?= $current('/tags') ?>><?= $this->e($this->t('Tags')) ?></a>
            <?php if ($this->settings->bool('features.requests', true)) : ?>
                <a href="<?= $this->url('requests') ?>"<?= $current('/requests') ?>><?= $this->e($this->t('Requests')) ?></a>
            <?php endif ?>
            <a href="<?= $this->url('collections') ?>"<?= $current('/collections') ?>><?= $this->e($this->t('Collections')) ?></a>
            <a href="<?= $this->url('contributors') ?>"<?= $current('/contributors') ?>><?= $this->e($this->t('People')) ?></a>
        </nav>

        <form class="site-search" method="get" action="<?= $this->url('books') ?>" role="search">
            <label class="visually-hidden" for="site-search"><?= $this->e($this->t('Search the library')) ?></label>
            <input id="site-search" type="search" name="q" placeholder="<?= $this->e($this->t('Search the library')) ?>">
        </form>

        <div class="site-actions">
            <button type="button" class="icon-button" data-theme-toggle
                    aria-label="<?= $this->e($this->t('Switch to the dark theme')) ?>"
                    data-label-dark="<?= $this->e($this->t('Switch to the dark theme')) ?>"
                    data-label-light="<?= $this->e($this->t('Switch to the light theme')) ?>">
                <span data-theme-icon aria-hidden="true">◐</span>
            </button>

            <?php if ($user !== null) : ?>
                <?php if ($this->gate->allows('book.upload')) : ?>
                    <a class="button button--small" href="<?= $this->url('books.new') ?>"><?= $this->e($this->t('Add a book')) ?></a>
                <?php endif ?>

                <a class="icon-button" href="<?= $this->url('notifications') ?>"
                   aria-label="<?= $this->e($this->t('Alerts')) ?>"<?= $current('/notifications') ?>>
                    <span aria-hidden="true">✦</span>
                    <?php if ($unread > 0) : ?>
                        <span class="badge"><?= (int) $unread ?></span>
                        <span class="visually-hidden"><?= $this->e($this->t(':count unread', ['count' => $unread])) ?></span>
                    <?php endif ?>
                </a>

                <details class="menu" data-menu>
                    <summary class="menu__trigger">
                        <span class="avatar avatar--small" aria-hidden="true"><?= $this->e(mb_strtoupper(mb_substr($user->name, 0, 1))) ?></span>
                        <span class="menu__name"><?= $this->e($user->username) ?></span>
                    </summary>
                    <div class="menu__panel">
                        <a href="<?= $this->url('profile', ['username' => $user->username]) ?>"><?= $this->e($this->t('Your profile')) ?></a>
                        <a href="<?= $this->url('submissions') ?>"><?= $this->e($this->t('Your submissions')) ?></a>
                        <?php if ($this->gate->allows('moderation.queue')) : ?>
                            <a href="<?= $this->url('queue') ?>"><?= $this->e($this->t('Review queue')) ?></a>
                        <?php endif ?>
                        <?php if ($this->gate->allows('taxonomy.manage')) : ?>
                            <a href="<?= $this->url('librarian.books') ?>"><?= $this->e($this->t('Catalogue')) ?></a>
                        <?php endif ?>
                        <?php if ($this->gate->allows('settings.manage')) : ?>
                            <a href="<?= $this->url('admin') ?>"><?= $this->e($this->t('Admin')) ?></a>
                        <?php endif ?>
                        <a href="<?= $this->url('settings') ?>"><?= $this->e($this->t('Settings')) ?></a>
                        <form class="menu__form" method="post" action="<?= $this->url('logout') ?>">
                            <?= $this->csrf->field() ?>
                            <button type="submit" class="menu__signout"><?= $this->e($this->t('Sign out')) ?></button>
                        </form>
                    </div>
                </details>
            <?php else : ?>
                <a class="site-actions__link" href="<?= $this->url('login') ?>"><?= $this->e($this->t('Sign in')) ?></a>
                <a class="button button--small" href="<?= $this->url('register') ?>"><?= $this->e($this->t('Join')) ?></a>
            <?php endif ?>
        </div>
    </div>
</header>

<?php $this->include('partials/flash') ?>
