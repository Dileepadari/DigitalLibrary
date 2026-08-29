<?php
/**
 * @var App\Core\View $this
 * @var list<array<string, mixed>> $applications
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Librarian applications';
$this->end();
?>
<section class="stack-wide">
    <h1>Librarian applications</h1>

    <?php $this->include('partials/admin-nav') ?>
    <p class="muted">
        A librarian can publish without review and decide anything in the queue,
        so only an admin can hand that out.
    </p>

    <?php if ($applications === []) : ?>
        <p class="empty">Nobody is waiting.</p>
    <?php endif ?>

    <?php foreach ($applications as $application) : ?>
        <article class="panel">
            <h2>
                <a href="<?= $this->url('profile', ['username' => (string) $application['username']]) ?>">
                    <?= $this->e((string) $application['name']) ?>
                </a>
                <span class="status-item__detail">
                    @<?= $this->e((string) $application['username']) ?>
                    &middot; <?= (int) $application['reputation'] ?> reputation
                    &middot; applied <?= $this->e($this->date((string) $application['created_at'])) ?>
                </span>
            </h2>

            <p><?= nl2br($this->e((string) $application['statement'])) ?></p>

            <form method="post"
                  action="<?= $this->url('admin.applications.decide', ['id' => (int) $application['id']]) ?>"
                  class="inline-form">
                <?= $this->csrf->field() ?>
                <button type="submit" name="decision" value="approve" class="button button--small">
                    Make them a librarian
                </button>
                <button type="submit" name="decision" value="reject" class="button button--small button--quiet">
                    Not yet
                </button>
            </form>
        </article>
    <?php endforeach ?>
</section>
