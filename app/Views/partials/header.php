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

        <?php
        /*
         * One destination in the bar and the rest behind the menu on the right.
         * Browse is what a visitor came for; categories, shelves and requests
         * are ways to keep going, and they are one click away in the menu.
         */
        $browse = ['url' => $this->url('books'), 'path' => '/books', 'label' => $this->t('Browse')];

        $menu = [
            ['url' => $this->url('categories'), 'path' => '/categories', 'label' => $this->t('Categories')],
            ['url' => $this->url('collections'), 'path' => '/collections', 'label' => $this->t('Collections')],
        ];

        if ($this->settings->bool('features.requests', true)) {
            $menu[] = ['url' => $this->url('requests'), 'path' => '/requests', 'label' => $this->t('Requests')];
        }

        $menu[] = ['url' => $this->url('tags'), 'path' => '/tags', 'label' => $this->t('Tags')];
        $menu[] = ['url' => $this->url('contributors'), 'path' => '/contributors', 'label' => $this->t('People')];
        $menu[] = ['url' => $this->url('report'), 'path' => '/report', 'label' => $this->t('Report a problem')];
        ?>

        <nav class="site-nav" aria-label="<?= $this->e($this->t('Main')) ?>">
            <a href="<?= $browse['url'] ?>"<?= $current($browse['path']) ?>><?= $this->e($browse['label']) ?></a>
        </nav>

        <form class="site-search" method="get" action="<?= $this->url('books') ?>" role="search">
            <label class="visually-hidden" for="site-search"><?= $this->e($this->t('Search the library')) ?></label>
            <input id="site-search" type="search" name="q" placeholder="<?= $this->e($this->t('Search the library')) ?>">
        </form>

        <div class="site-actions">
            <?php if ($user !== null) : ?>
                <?php if ($this->gate->allows('book.upload')) : ?>
                    <a class="button button--small" href="<?= $this->url('books.new') ?>"><?= $this->e($this->t('Add a book')) ?></a>
                <?php endif ?>

            <button type="button" class="icon-button" data-theme-toggle
                    aria-label="<?= $this->e($this->t('Switch to the dark theme')) ?>"
                    data-label-dark="<?= $this->e($this->t('Switch to the dark theme')) ?>"
                    data-label-light="<?= $this->e($this->t('Switch to the light theme')) ?>">
                <svg class="icon icon--sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="4.2"/>
                    <path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/>
                </svg>
                <svg class="icon icon--moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M20 14.2A8.2 8.2 0 0 1 9.8 4a8.2 8.2 0 1 0 10.2 10.2z"/>
                </svg>
            </button>

                <a class="icon-button" href="<?= $this->url('notifications') ?>"
                   aria-label="<?= $this->e($this->t('Alerts')) ?>"<?= $current('/notifications') ?>>
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M18 15.5V10a6 6 0 1 0-12 0v5.5L4.5 18h15z"/>
                        <path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>
                    </svg>
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
            <button type="button" class="icon-button" data-theme-toggle
                    aria-label="<?= $this->e($this->t('Switch to the dark theme')) ?>"
                    data-label-dark="<?= $this->e($this->t('Switch to the dark theme')) ?>"
                    data-label-light="<?= $this->e($this->t('Switch to the light theme')) ?>">
                <svg class="icon icon--sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="4.2"/>
                    <path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/>
                </svg>
                <svg class="icon icon--moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M20 14.2A8.2 8.2 0 0 1 9.8 4a8.2 8.2 0 1 0 10.2 10.2z"/>
                </svg>
            </button>

                <a class="site-actions__link" href="<?= $this->url('login') ?>"><?= $this->e($this->t('Sign in')) ?></a>
                <a class="button button--small" href="<?= $this->url('register') ?>"><?= $this->e($this->t('Join')) ?></a>
            <?php endif ?>

            <details class="menu menu--nav" data-menu>
                <summary class="menu__trigger icon-button" aria-label="<?= $this->e($this->t('More pages')) ?>">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M4 7h16M4 12h16M4 17h16"/>
                    </svg>
                </summary>
                <div class="menu__panel">
                    <div class="menu__primary">
                        <a href="<?= $browse['url'] ?>"<?= $current($browse['path']) ?>><?= $this->e($browse['label']) ?></a>
                    </div>
                    <?php foreach ($menu as $link) : ?>
                        <a href="<?= $link['url'] ?>"<?= $current($link['path']) ?>><?= $this->e($link['label']) ?></a>
                    <?php endforeach ?>
                </div>
            </details>
        </div>
    </div>
</header>

<?php $this->include('partials/flash') ?>
