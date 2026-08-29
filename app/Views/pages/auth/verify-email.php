<?php
/**
 * @var App\Core\View $this
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Confirm your email';
$this->end();
$user = $this->auth->user();
?>
<section class="form-page">
    <h1>Confirm your email</h1>

    <p>
        A confirmation link went to
        <strong><?= $this->e($user?->email ?? 'your address') ?></strong>.
        Until it is confirmed you can read the library but not add to it.
    </p>

    <form method="post" action="<?= $this->url('verify.resend') ?>">
        <?= $this->csrf->field() ?>
        <button type="submit" class="button">Send it again</button>
    </form>
</section>
