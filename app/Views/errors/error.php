<?php
/**
 * @var App\Core\View $this
 * @var int $status
 * @var string $message
 * @var Throwable|null $exception
 */
$this->layout('layouts/app');
$this->section('title');
echo $this->e($status . ' error');
$this->end();
?>
<section class="page-message">
    <p class="page-message__code"><?= $this->e($status) ?></p>
    <h1><?= $this->e($message) ?></h1>
    <p class="muted">
        Nothing you did caused this. If it keeps happening, tell whoever runs
        this library.
    </p>
    <p class="page-message__actions">
        <a class="button" href="/">Back to the home page</a>
        <a class="button button--quiet" href="<?= $this->url('report') ?>">Report a problem</a>
    </p>
</section>

<?php if ($exception !== null) : ?>
    <section class="panel panel--debug">
        <h2><?= $this->e($exception::class) ?></h2>
        <p><?= $this->e($exception->getMessage()) ?></p>
        <p class="status-item__detail"><?= $this->e($exception->getFile() . ':' . $exception->getLine()) ?></p>
        <pre><?= $this->e($exception->getTraceAsString()) ?></pre>
    </section>
<?php endif ?>
