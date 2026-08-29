<?php
/**
 * @var App\Core\View $this
 */
?>
<footer class="site-footer">
    <div class="container site-footer__inner">
        <p><?= $this->e($this->appName) ?> <?= $this->e($this->config('app.version')) ?></p>
        <p>
            <?= $this->e($this->t('Open source. Built with PHP :version.', ['version' => PHP_VERSION])) ?>
            <a href="<?= $this->url('report') ?>"><?= $this->e($this->t('Report a problem')) ?></a>.
        </p>

        <p class="site-footer__languages">
            <?= $this->e($this->t('Language')) ?>:
            <?php foreach ($this->localeOptions as $code => $name) : ?>
                <?php $href = $this->localeLinks[$code] ?? ('?lang=' . $code); ?>
                <a href="<?= $this->e($href) ?>" <?= $this->locale() === $code ? 'aria-current="true"' : '' ?>>
                    <?= $this->e($name) ?>
                </a>
            <?php endforeach ?>
        </p>
    </div>
</footer>
