<?php
/**
 * @var App\Core\View $this
 * @var string $message
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Not found';
$this->end();
?>
<section class="page-message">
    <p class="page-message__code">404</p>
    <h1><?= $this->e($message) ?></h1>
    <p class="muted">
        The address may be old, or the record may have been withdrawn. The
        catalogue is still the best place to look.
    </p>
    <p class="page-message__actions">
        <a class="button" href="<?= $this->url('books') ?>">Browse the library</a>
        <a class="button button--quiet" href="/">Back to the home page</a>
    </p>
</section>
