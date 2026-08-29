<?php
/**
 * The end of every page: where to go next, and how to get help. No build
 * details, no version numbers; this is a library, not a status page.
 *
 * @var App\Core\View $this
 */
$columns = [
    $this->t('Explore') => [
        [$this->url('books'), $this->t('Browse')],
        [$this->url('categories'), $this->t('Categories')],
        [$this->url('collections'), $this->t('Collections')],
        [$this->url('tags'), $this->t('Tags')],
    ],
    $this->t('Take part') => [
        [$this->url('contributors'), $this->t('People')],
        [$this->url('apply'), $this->t('Become a librarian')],
        [$this->url('report'), $this->t('Report a problem')],
    ],
];

// The footer follows the feature flags, or turning requests off would leave a
// link to a page nobody can use.
if ($this->settings->bool('features.requests', true)) {
    array_unshift($columns[$this->t('Take part')], [$this->url('requests'), $this->t('Requests')]);
}
?>
<footer class="site-footer">
    <div class="container site-footer__inner">
        <div class="site-footer__brand">
            <a class="brand" href="/">
                <span class="brand__badge">
                    <img class="logo-mono" src="<?= $this->asset('/assets/img/logo-mark.png') ?>"
                         alt="" width="20" height="20">
                </span>
                <span class="brand__name"><?= $this->e($this->appName) ?></span>
            </a>
            <p><?= $this->e($this->settings->string('site.tagline', (string) $this->config('app.tagline'))) ?></p>
        </div>

        <?php foreach ($columns as $heading => $links) : ?>
            <nav class="site-footer__column" aria-label="<?= $this->e($heading) ?>">
                <h2><?= $this->e($heading) ?></h2>
                <?php foreach ($links as [$href, $label]) : ?>
                    <a href="<?= $href ?>"><?= $this->e($label) ?></a>
                <?php endforeach ?>
            </nav>
        <?php endforeach ?>

        <div class="site-footer__column site-footer__languages">
            <h2><?= $this->e($this->t('Language')) ?></h2>
            <?php foreach ($this->localeOptions as $code => $name) : ?>
                <?php $href = $this->localeLinks[$code] ?? ('?lang=' . $code); ?>
                <a href="<?= $this->e($href) ?>"<?= $this->locale() === $code ? ' aria-current="true"' : '' ?>>
                    <?= $this->e($name) ?>
                </a>
            <?php endforeach ?>
        </div>
    </div>

    <div class="container site-footer__base">
        <p><?= $this->e($this->appName) ?></p>
        <p><?= $this->e($this->t('An open source library anyone can run.')) ?></p>
    </div>
</footer>
