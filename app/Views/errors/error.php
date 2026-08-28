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
<section class="hero">
    <h1><?= $this->e($status) ?></h1>
    <p class="hero__tagline"><?= $this->e($message) ?></p>
    <p><a href="/">Back to the library</a></p>
</section>

<?php if ($exception !== null) : ?>
    <section class="panel panel--debug">
        <h2><?= $this->e($exception::class) ?></h2>
        <p><?= $this->e($exception->getMessage()) ?></p>
        <p class="status-item__detail"><?= $this->e($exception->getFile() . ':' . $exception->getLine()) ?></p>
        <pre><?= $this->e($exception->getTraceAsString()) ?></pre>
    </section>
<?php endif ?>
