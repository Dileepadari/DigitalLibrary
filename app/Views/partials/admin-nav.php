<?php
/**
 * The admin sections, on every admin page, so nobody has to go back to the
 * dashboard to reach the next one.
 *
 * @var App\Core\View $this
 */
$here = (string) ($this->currentPath ?? '');
$sections = [
    'admin'              => 'Overview',
    'admin.users'        => 'Users',
    'admin.applications' => 'Applications',
    'admin.takedowns'    => 'Takedowns',
    'admin.settings'     => 'Settings',
    'admin.audit'        => 'Audit log',
    'admin.storage'      => 'Storage',
];
?>
<nav class="subnav" aria-label="Admin sections">
    <?php foreach ($sections as $route => $label) : ?>
        <?php $path = $this->url($route); ?>
        <a href="<?= $path ?>"<?= $here === $path ? ' aria-current="page"' : '' ?>>
            <?= $this->e($this->t($label)) ?>
        </a>
    <?php endforeach ?>
</nav>
