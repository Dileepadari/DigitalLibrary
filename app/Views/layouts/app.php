<?php
/**
 * @var App\Core\View $this
 */
?><!doctype html>
<html lang="<?= $this->e($this->config('app.locale', 'en')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->slot('title', $this->e($this->appName)) ?></title>
    <meta name="description" content="<?= $this->e($this->config('app.tagline')) ?>">
    <link rel="icon" href="<?= $this->asset('/assets/img/logo-mark.png') ?>" type="image/png">
    <link rel="stylesheet" href="<?= $this->asset('/assets/css/app.css') ?>">
    <!-- Loaded in the head, and not inline, because the CSP forbids inline script. -->
    <script src="<?= $this->asset('/assets/js/theme.js') ?>"></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?php $this->include('partials/header') ?>

<main id="main" class="container">
    <?= $this->slot('content') ?>
</main>

<?php $this->include('partials/footer') ?>

<?= $this->slot('scripts') ?>
</body>
</html>
