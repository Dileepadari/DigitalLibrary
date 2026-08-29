<?php
/**
 * @var App\Core\View $this
 * @var list<array<string, mixed>> $notices
 * @var string $status
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Takedowns';
$this->end();
?>
<section class="stack-wide">
    <h1>Takedown notices</h1>

    <?php $this->include('partials/admin-nav') ?>
    <p class="muted">
        Anyone can send one, with or without an account. Upholding a notice hides
        the book at once; the record and the reason stay either way.
    </p>

    <nav class="filter-bar">
        <?php foreach (['open' => 'Open', 'upheld' => 'Upheld', 'rejected' => 'Rejected', 'any' => 'All'] as $value => $label) : ?>
            <a class="tag <?= $status === $value ? 'tag--role' : '' ?>"
               href="<?= $this->url('admin.takedowns') ?>?status=<?= $this->e($value) ?>">
                <?= $this->e($label) ?>
            </a>
        <?php endforeach ?>
    </nav>

    <?php if ($notices === []) : ?>
        <p class="empty">Nothing here.</p>
    <?php endif ?>

    <?php foreach ($notices as $notice) : ?>
        <article class="panel">
            <h2>
                <?php if (($notice['book_slug'] ?? null) !== null) : ?>
                    <a href="<?= $this->url('book', ['slug' => (string) $notice['book_slug']]) ?>">
                        <?= $this->e((string) $notice['book_title']) ?>
                    </a>
                <?php else : ?>
                    Notice about <?= $this->e((string) ($notice['subject_url'] ?? 'something not linked')) ?>
                <?php endif ?>
                <span class="tag"><?= $this->e((string) $notice['status']) ?></span>
            </h2>

            <p class="muted">
                From <?= $this->e((string) $notice['claimant_name']) ?>
                &lt;<?= $this->e((string) $notice['claimant_email']) ?>&gt;
                <?php if (($notice['claimant_role'] ?? null) !== null) : ?>
                    &middot; <?= $this->e((string) $notice['claimant_role']) ?>
                <?php endif ?>
                &middot; <?= $this->e($this->date((string) $notice['created_at'])) ?>
            </p>

            <p><?= nl2br($this->e((string) $notice['basis'])) ?></p>

            <?php if ((string) $notice['status'] === 'open') : ?>
                <form method="post" action="<?= $this->url('admin.takedowns.decide', ['id' => (int) $notice['id']]) ?>"
                      class="stack">
                    <?= $this->csrf->field() ?>

                    <div class="field">
                        <label for="note-<?= (int) $notice['id'] ?>">What you did and why</label>
                        <input type="text" id="note-<?= (int) $notice['id'] ?>" name="note" maxlength="500">
                    </div>

                    <div class="book__actions">
                        <button type="submit" name="decision" value="upheld" class="button">
                            Uphold and hide the book
                        </button>
                        <button type="submit" name="decision" value="rejected" class="button button--quiet">
                            Reject the notice
                        </button>
                    </div>
                </form>
            <?php else : ?>
                <p class="status-item__detail">
                    <?= $this->e((string) $notice['status']) ?>
                    <?php if (($notice['handler_name'] ?? null) !== null) : ?>
                        by <?= $this->e((string) $notice['handler_name']) ?>
                    <?php endif ?>
                    on <?= $this->e((string) ($notice['handled_at'] ?? '')) ?>
                    <?php if (($notice['outcome_note'] ?? null) !== null) : ?>
                        &middot; <?= $this->e((string) $notice['outcome_note']) ?>
                    <?php endif ?>
                </p>
            <?php endif ?>
        </article>
    <?php endforeach ?>
</section>
