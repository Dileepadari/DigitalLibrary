<?php
/**
 * @var App\Core\View $this
 * @var bool $isFirstAccount
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Create an account';
$this->end();
?>
<section class="form-page">
    <h1>Create an account</h1>

    <?php if ($isFirstAccount) : ?>
        <p class="banner banner--warn">
            This is the first account on this library, so it becomes the administrator
            and skips email confirmation.
        </p>
    <?php endif ?>

    <form method="post" action="<?= $this->url('register') ?>" class="stack">
        <?= $this->csrf->field() ?>

        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name"<?= $this->errorAttributes('name') ?> value="<?= $this->e($this->old('name')) ?>"
                   autocomplete="name" required autofocus>
            <?php $this->include('partials/field-errors', ['field' => 'name']) ?>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username"<?= $this->errorAttributes('username') ?> value="<?= $this->e($this->old('username')) ?>"
                   autocomplete="username" required>
            <p class="field__hint">Lowercase letters, numbers and hyphens. This is your profile address.</p>
            <?php $this->include('partials/field-errors', ['field' => 'username']) ?>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"<?= $this->errorAttributes('email') ?> value="<?= $this->e($this->old('email')) ?>"
                   autocomplete="email" required>
            <?php $this->include('partials/field-errors', ['field' => 'email']) ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"<?= $this->errorAttributes('password') ?> autocomplete="new-password" required>
            <p class="field__hint">At least 10 characters.</p>
            <?php $this->include('partials/field-errors', ['field' => 'password']) ?>
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation"<?= $this->errorAttributes('password_confirmation') ?>
                   autocomplete="new-password" required>
            <?php $this->include('partials/field-errors', ['field' => 'password_confirmation']) ?>
        </div>

        <button type="submit" class="button">Create account</button>
    </form>

    <p class="form-page__aside">
        Already have an account? <a href="<?= $this->url('login') ?>">Sign in</a>.
    </p>
</section>
