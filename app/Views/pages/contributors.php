<?php
/**
 * @var App\Core\View $this
 * @var list<array<string, mixed>> $contributors
 * @var list<array{key: string, name: string, description: string, action: string, threshold: int}> $badges
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Contributors';
$this->end();
?>
<section class="stack-wide">
    <h1>Contributors</h1>
    <p class="muted">
        Reputation comes from work the library keeps: an upload accepted, a
        request answered, a review other people found useful, a collection
        published, an item decided in the queue.
    </p>

    <?php if ($contributors === []) : ?>
        <p class="empty">Nobody has earned any points yet.</p>
    <?php else : ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Who</th>
                        <th scope="col">Reputation</th>
                        <th scope="col">Badges</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contributors as $index => $contributor) : ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td>
                            <a href="<?= $this->url('profile', ['username' => (string) $contributor['username']]) ?>">
                                <?= $this->e((string) $contributor['name']) ?>
                            </a>
                            <span class="status-item__detail">
                                @<?= $this->e((string) $contributor['username']) ?>
                                <?php if ((string) $contributor['role'] !== 'member') : ?>
                                    &middot; <?= $this->e((string) $contributor['role']) ?>
                                <?php endif ?>
                            </span>
                        </td>
                        <td><?= (int) $contributor['reputation'] ?></td>
                        <td><?= (int) $contributor['badge_count'] ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

    <div class="panel">
        <h2>The badges</h2>
        <p class="muted">Each one is earned by doing the same thing enough times.</p>

        <dl class="status-grid">
            <?php foreach ($badges as $badge) : ?>
                <div class="status-item">
                    <dt><?= $this->e($badge['name']) ?></dt>
                    <dd>
                        <?= $this->e($badge['description']) ?>
                        <span class="status-item__detail">
                            <?= (int) $badge['threshold'] ?> &times; <?= $this->e($badge['action']) ?>
                        </span>
                    </dd>
                </div>
            <?php endforeach ?>
        </dl>
    </div>
</section>
