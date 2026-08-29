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
            <span class="brand__name"><?= $this->e($this->appName) ?></span>
        </a>

        <nav class="site-nav" aria-label="Main">
            <a href="<?= $this->url('books') ?>"><?= $this->e($this->t('Browse')) ?></a>
            <a href="<?= $this->url('categories') ?>"><?= $this->e($this->t('Categories')) ?></a>
            <a href="<?= $this->url('tags') ?>"><?= $this->e($this->t('Tags')) ?></a>
            <?php if ($this->settings->bool('features.requests', true)) : ?>
                <a href="<?= $this->url('requests') ?>"><?= $this->e($this->t('Requests')) ?></a>
            <?php endif ?>
            <a href="<?= $this->url('collections') ?>"><?= $this->e($this->t('Collections')) ?></a>
            <a href="<?= $this->url('contributors') ?>"><?= $this->e($this->t('People')) ?></a>

            <?php if ($user !== null) : ?>
                <?php if ($this->gate->allows('book.upload')) : ?>
                    <a href="<?= $this->url('books.new') ?>"><?= $this->e($this->t('Add')) ?></a>
                <?php endif ?>
                <?php if ($this->gate->allows('moderation.queue')) : ?>
                    <a href="<?= $this->url('queue') ?>"><?= $this->e($this->t('Queue')) ?></a>
                <?php endif ?>
                <?php if ($this->gate->allows('taxonomy.manage')) : ?>
                    <a href="<?= $this->url('librarian.books') ?>"><?= $this->e($this->t('Catalogue')) ?></a>
                <?php endif ?>
                <a href="<?= $this->url('notifications') ?>">
                    <?= $this->e($this->t('Alerts')) ?><?php if ($unread > 0) : ?><span class="badge"><?= (int) $unread ?></span><?php endif ?>
                </a>
                <?php if ($this->gate->allows('settings.manage')) : ?>
                    <a href="<?= $this->url('admin') ?>"><?= $this->e($this->t('Admin')) ?></a>
                <?php endif ?>
                <a href="<?= $this->url('profile', ['username' => $user->username]) ?>">
                    <?= $this->e($user->username) ?>
                </a>
                <a href="<?= $this->url('settings') ?>"><?= $this->e($this->t('Settings')) ?></a>
                <form class="inline-form" method="post" action="<?= $this->url('logout') ?>">
                    <?= $this->csrf->field() ?>
                    <button type="submit" class="link-button"><?= $this->e($this->t('Sign out')) ?></button>
                </form>
            <?php else : ?>
                <a href="<?= $this->url('login') ?>"><?= $this->e($this->t('Sign in')) ?></a>
                <a class="button button--small" href="<?= $this->url('register') ?>"><?= $this->e($this->t('Join')) ?></a>
            <?php endif ?>
        </nav>

        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch between light and dark theme">
            <span data-theme-icon><?= $this->e($this->t('Theme')) ?></span>
        </button>
    </div>
</header>

<?php $this->include('partials/flash') ?>
