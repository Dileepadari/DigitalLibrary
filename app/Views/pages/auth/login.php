<?php
/**
 * @var App\Core\View $this
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Sign in';
$this->end();
?>
<section class="form-page">
    <h1>Sign in</h1>

    <form method="post" action="<?= $this->url('login') ?>" class="stack">
        <?= $this->csrf->field() ?>

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"<?= $this->errorAttributes('email') ?> value="<?= $this->e($this->old('email')) ?>"
                   autocomplete="email" required autofocus>
            <?php $this->include('partials/field-errors', ['field' => 'email']) ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"<?= $this->errorAttributes('password') ?> autocomplete="current-password" required>
            <?php $this->include('partials/field-errors', ['field' => 'password']) ?>
        </div>

        <button type="submit" class="button">Sign in</button>
    </form>

    <p class="form-page__aside">
        <a href="<?= $this->url('password.request') ?>">Forgotten your password?</a>
        &middot;
        <a href="<?= $this->url('register') ?>">Create an account</a>
    </p>
</section>
