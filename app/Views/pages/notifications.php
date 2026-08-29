<?php
/**
 * @var App\Core\View $this
 * @var list<array<string, mixed>> $notifications
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Notifications';
$this->end();
?>
<section class="stack-wide">
    <h1>Notifications</h1>

    <?php if ($notifications === []) : ?>
        <p class="empty">Nothing to report.</p>
    <?php else : ?>
    <div class="panel">
        <?php foreach ($notifications as $notification) : ?>
            <div class="decision-row">
                <div>
                    <strong>
                        <?php if (($notification['url'] ?? null) !== null) : ?>
                            <a href="<?= $this->e((string) $notification['url']) ?>">
                                <?= $this->e((string) $notification['title']) ?>
                            </a>
                        <?php else : ?>
                            <?= $this->e((string) $notification['title']) ?>
                        <?php endif ?>
                    </strong>
                    <?php if (($notification['body'] ?? null) !== null) : ?>
                        <span class="status-item__detail"><?= $this->e((string) $notification['body']) ?></span>
                    <?php endif ?>
                </div>
                <span class="status-item__detail"><?= $this->e($this->date((string) $notification['created_at'], 'j M Y, H:i')) ?></span>
            </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</section>
