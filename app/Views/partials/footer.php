<?php
/**
 * @var App\Core\View $this
 */
?>
<footer class="site-footer">
    <div class="container site-footer__inner">
        <p><?= $this->e($this->config('app.name')) ?> <?= $this->e($this->config('app.version')) ?></p>
        <p>Open source. Built with PHP <?= $this->e(PHP_VERSION) ?>.</p>
    </div>
</footer>
