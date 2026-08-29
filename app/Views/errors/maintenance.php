<?php
/**
 * @var App\Core\View $this
 * @var string $message
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Closed for a moment';
$this->end();
?>
<section class="hero">
    <h1>Back shortly</h1>
    <p class="hero__tagline"><?= $this->e($message) ?></p>
    <p class="muted">
        An administrator has put the library into maintenance mode. Nothing is
        lost; it will be here when it reopens.
    </p>
</section>
