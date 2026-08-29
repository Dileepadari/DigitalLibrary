<?php
/**
 * @var App\Core\View $this
 */
?>
<footer class="site-footer">
    <div class="container site-footer__inner">
        <p><?= $this->e($this->appName) ?> <?= $this->e($this->config('app.version')) ?></p>
        <p>
            Open source. Built with PHP <?= $this->e(PHP_VERSION) ?>.
            <a href="<?= $this->url('report') ?>">Report a problem</a>.
        </p>
    </div>
</footer>
