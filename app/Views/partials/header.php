<?php
/**
 * @var App\Core\View $this
 */
$user = $this->auth->user();
$unread = $this->notifications->unreadCount($user?->id);
?>
<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="/">
            <span class="brand__badge">
                <img class="logo-mono" src="<?= $this->asset('/assets/img/logo-mark.png') ?>" alt="" width="20" height="20">
            </span>
            <span class="brand__name"><?= $this->e($this->config('app.name')) ?></span>
        </a>

        <nav class="site-nav" aria-label="Main">
            <!-- Requests and Collections are added in M4 and M5. -->
            <a href="<?= $this->url('books') ?>">Browse</a>
            <a href="<?= $this->url('categories') ?>">Categories</a>
            <a href="<?= $this->url('tags') ?>">Tags</a>
            <a href="<?= $this->url('requests') ?>">Requests</a>
            <a href="<?= $this->url('collections') ?>">Collections</a>

            <?php if ($user !== null) : ?>
                <?php if ($this->gate->allows('book.upload')) : ?>
                    <a href="<?= $this->url('books.new') ?>">Add</a>
                <?php endif ?>
                <?php if ($this->gate->allows('moderation.queue')) : ?>
                    <a href="<?= $this->url('queue') ?>">Queue</a>
                <?php endif ?>
                <?php if ($this->gate->allows('taxonomy.manage')) : ?>
                    <a href="<?= $this->url('librarian.books') ?>">Catalogue</a>
                <?php endif ?>
                <a href="<?= $this->url('notifications') ?>">
                    Alerts<?php if ($unread > 0) : ?><span class="badge"><?= (int) $unread ?></span><?php endif ?>
                </a>
                <?php if ($this->gate->allows('user.manage')) : ?>
                    <a href="<?= $this->url('admin.users') ?>">Admin</a>
                <?php endif ?>
                <a href="<?= $this->url('profile', ['username' => $user->username]) ?>">
                    <?= $this->e($user->username) ?>
                </a>
                <a href="<?= $this->url('settings') ?>">Settings</a>
                <form class="inline-form" method="post" action="<?= $this->url('logout') ?>">
                    <?= $this->csrf->field() ?>
                    <button type="submit" class="link-button">Sign out</button>
                </form>
            <?php else : ?>
                <a href="<?= $this->url('login') ?>">Sign in</a>
                <a class="button button--small" href="<?= $this->url('register') ?>">Join</a>
            <?php endif ?>
        </nav>

        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch between light and dark theme">
            <span data-theme-icon>Theme</span>
        </button>
    </div>
</header>

<?php $this->include('partials/flash') ?>
