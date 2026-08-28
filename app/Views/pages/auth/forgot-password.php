<?php
/**
 * @var App\Core\View $this
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Reset your password';
$this->end();
?>
<section class="form-page">
    <h1>Reset your password</h1>
    <p>Give the email address on your account and a reset link comes back to it.</p>

    <form method="post" action="<?= $this->url('password.request') ?>" class="stack">
        <?= $this->csrf->field() ?>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= $this->e($this->old('email')) ?>"
                   autocomplete="email" required autofocus>
            <?php $this->include('partials/field-errors', ['field' => 'email']) ?>
        </div>

        <button type="submit" class="button">Send the link</button>
    </form>

    <p class="form-page__aside"><a href="<?= $this->url('login') ?>">Back to sign in</a></p>
</section>
