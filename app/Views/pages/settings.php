<?php
/**
 * @var App\Core\View $this
 * @var App\Models\User $user
 * @var list<string> $permissions
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Settings';
$this->end();
?>
<section class="stack-wide">
    <h1>Settings</h1>

    <div class="panel">
        <h2>Profile</h2>

        <form method="post" action="<?= $this->url('settings') ?>" class="stack">
            <?= $this->csrf->field() ?>

            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name"
                       value="<?= $this->e($this->old('name') !== '' ? $this->old('name') : $user->name) ?>" required>
                <?php $this->include('partials/field-errors', ['field' => 'name']) ?>
            </div>

            <div class="field">
                <label for="bio">Bio</label>
                <textarea id="bio" name="bio" rows="3" maxlength="500"><?=
                    $this->e($this->old('bio') !== '' ? $this->old('bio') : (string) $user->bio)
                ?></textarea>
                <p class="field__hint">Shown on your public profile. 500 characters.</p>
                <?php $this->include('partials/field-errors', ['field' => 'bio']) ?>
            </div>

            <button type="submit" class="button">Save profile</button>
        </form>
    </div>

    <div class="panel">
        <h2>Password</h2>

        <form method="post" action="<?= $this->url('settings.password') ?>" class="stack">
            <?= $this->csrf->field() ?>

            <div class="field">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password"
                       autocomplete="current-password" required>
                <?php $this->include('partials/field-errors', ['field' => 'current_password']) ?>
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" required>
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
    </div>

    <div class="panel">
        <h2>Account</h2>

        <dl class="status-grid">
            <div class="status-item">
                <dt>Email</dt>
                <dd>
                    <?= $this->e($user->email) ?>
                    <span class="status-item__detail">
                        <?= $user->isVerified() ? 'confirmed' : 'not confirmed yet' ?>
                    </span>
                </dd>
            </div>
            <div class="status-item">
                <dt>Role</dt>
                <dd>
                    <?= $this->e($user->role->label()) ?>
                    <span class="status-item__detail"><?= $this->e($user->role->description()) ?></span>
                </dd>
            </div>
            <div class="status-item">
                <dt>Profile address</dt>
                <dd>
                    <a href="<?= $this->url('profile', ['username' => $user->username]) ?>">
                        /u/<?= $this->e($user->username) ?>
                    </a>
                </dd>
            </div>
            <div class="status-item">
                <dt>Upload quota</dt>
                <dd>
                    <?= $this->e(number_format($user->storageQuota / 1073741824, 1)) ?> GB
                    <span class="status-item__detail"><?= (int) $user->storagePercent() ?>% used</span>
                </dd>
            </div>
        </dl>
    </div>

    <?php if ($user->role->value === 'member' && $this->gate->allows('librarian.apply')) : ?>
        <p class="muted">
            Want to help review what people upload?
            <a href="<?= $this->url('apply') ?>">Apply to be a librarian</a>.
        </p>
    <?php endif ?>

    <details class="panel">
        <summary><strong>What this account may do</strong> (<?= count($permissions) ?> permissions)</summary>
        <ul class="permission-list">
            <?php foreach ($permissions as $permission) : ?>
                <li><code><?= $this->e($permission) ?></code></li>
            <?php endforeach ?>
        </ul>
    </details>
</section>
