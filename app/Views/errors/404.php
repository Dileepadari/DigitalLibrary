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
<section class="hero">
    <h1>404</h1>
    <p class="hero__tagline"><?= $this->e($message) ?></p>
    <p><a href="/">Back to the library</a></p>
</section>
