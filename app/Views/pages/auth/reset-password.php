<?php
/**
 * @var App\Core\View $this
 * @var string $token
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Choose a new password';
$this->end();
?>
<section class="form-page">
    <h1>Choose a new password</h1>

    <form method="post" action="<?= $this->url('password.reset', ['token' => $token]) ?>" class="stack">
        <?= $this->csrf->field() ?>

        <div class="field">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required autofocus>
            <p class="field__hint">At least 10 characters.</p>
            <?php $this->include('partials/field-errors', ['field' => 'password']) ?>
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input type="password" id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
            <?php $this->include('partials/field-errors', ['field' => 'password_confirmation']) ?>
        </div>

        <button type="submit" class="button">Change password</button>
    </form>
</section>
