<?php
/**
 * @var App\Core\View $this
 * @var App\Models\User $profile
 * @var list<array{key: string, name: string, description: string, awarded_at: string}> $badges
 * @var array<string, int> $tally
 * @var list<array<string, mixed>> $reviews
 * @var list<App\Models\Collection> $collections
 */

use App\Support\ReputationAction;
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
            <dd><?= $this->e($this->date($profile->createdAt, 'j M Y')) ?></dd>
        </div>
        <div>
            <dt>Last seen</dt>
            <dd>
                <?= $profile->lastSeenAt === null
                    ? 'never'
                    : $this->e($this->date($profile->lastSeenAt, 'j M Y')) ?>
            </dd>
        </div>
    </dl>

    <?php if ($badges !== []) : ?>
        <p class="badge-row">
            <?php foreach ($badges as $badge) : ?>
                <span class="tag tag--role" title="<?= $this->e($badge['description']) ?>">
                    <?= $this->e($badge['name']) ?>
                </span>
            <?php endforeach ?>
        </p>
    <?php endif ?>

    <div class="page-body">
        <div class="page-main">
    <?php if ($tally !== []) : ?>
        <div class="panel">
            <h2>What they have done</h2>
            <dl class="status-grid">
                <?php foreach (ReputationAction::all() as $action) : ?>
                    <?php if (($tally[$action->value] ?? 0) > 0) : ?>
                        <div class="status-item">
                            <dt><?= $this->e($action->label()) ?></dt>
                            <dd><?= (int) $tally[$action->value] ?> times</dd>
                        </div>
                    <?php endif ?>
                <?php endforeach ?>
            </dl>
        </div>
    <?php endif ?>

    <?php if ($reviews !== []) : ?>
        <div class="panel">
            <h2>Recent reviews</h2>
            <?php foreach ($reviews as $review) : ?>
                <div class="review">
                    <p class="review__head">
                        <span class="rating-stars" aria-hidden="true"><?=
                            str_repeat('&#9733;', (int) $review['rating'])
                            . str_repeat('&#9734;', 5 - (int) $review['rating'])
                        ?></span>
                        <a href="<?= $this->url('book', ['slug' => (string) $review['slug']]) ?>">
                            <?= $this->e((string) $review['title']) ?>
                        </a>
                    </p>
                    <?php if (($review['body'] ?? null) !== null) : ?>
                        <p><?= $this->e(mb_substr((string) $review['body'], 0, 240)) ?></p>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
        </div>

        <aside class="page-aside">
    <?php if ($collections !== []) : ?>
        <div class="panel">
            <h2>Public collections</h2>
            <ul class="collection-list">
                <?php foreach ($collections as $collection) : ?>
                    <li>
                        <a href="<?= $this->url('collection', ['path' => $collection->relativePath()]) ?>">
                            <?= $this->e($collection->name) ?>
                        </a>
                        <span class="status-item__detail">
                            <?= (int) $collection->itemCount ?> book<?= $collection->itemCount === 1 ? '' : 's' ?>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

        </aside>
    </div>
</section>
