<?php
/**
 * @var App\Core\View $this
 * @var App\Models\User $profile
 */
$this->layout('layouts/app');
$this->section('title');
echo $this->e($profile->name);
$this->end();
?>
<section class="profile">
    <div class="profile__identity">
        <span class="avatar" aria-hidden="true"><?= $this->e($profile->initials()) ?></span>
        <div>
            <h1><?= $this->e($profile->name) ?></h1>
            <p class="profile__handle">
                @<?= $this->e($profile->username) ?>
                <span class="tag tag--role"><?= $this->e($profile->role->label()) ?></span>
                <?php if ($profile->status->value !== 'active') : ?>
                    <span class="tag tag--warn"><?= $this->e($profile->status->label()) ?></span>
                <?php endif ?>
            </p>
        </div>
    </div>

    <?php if ($profile->bio !== null) : ?>
        <p class="profile__bio"><?= $this->e($profile->bio) ?></p>
    <?php endif ?>

    <dl class="stat-row">
        <div>
            <dt>Reputation</dt>
            <dd><?= (int) $profile->reputation ?></dd>
        </div>
        <div>
            <dt>Joined</dt>
            <dd><?= $this->e(date('j M Y', strtotime($profile->createdAt))) ?></dd>
        </div>
        <div>
            <dt>Last seen</dt>
            <dd>
                <?= $profile->lastSeenAt === null
                    ? 'never'
                    : $this->e(date('j M Y', strtotime($profile->lastSeenAt))) ?>
            </dd>
        </div>
    </dl>

    <p class="muted">
        Uploads, collections and reviews appear here as those parts of the library are built.
    </p>
</section>
