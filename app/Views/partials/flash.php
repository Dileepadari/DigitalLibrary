<?php
/**
 * @var App\Core\View $this
 */
$success = $this->flash('success');
$error = $this->flash('error');
$user = $this->auth->user();
?>
<?php if (is_string($success) && $success !== '') : ?>
    <div class="container">
        <p class="banner banner--ok" role="status" aria-live="polite"><?= $this->e($success) ?></p>
    </div>
<?php endif ?>

<?php if (is_string($error) && $error !== '') : ?>
    <div class="container"><p class="banner banner--error" role="alert"><?= $this->e($error) ?></p></div>
<?php endif ?>

<?php if ($user !== null && !$user->isVerified()) : ?>
    <div class="container">
        <p class="banner banner--warn">
            Confirm your email address to upload, request books and build collections.
            <a href="<?= $this->url('verify.notice') ?>">Resend the link</a>.
        </p>
    </div>
<?php endif ?>
