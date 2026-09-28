<?php
/**
 * The event log and the comment thread for one queue item, shown to both sides.
 *
 * @var App\Core\View $this
 * @var App\Models\ModerationRequest $request
 * @var list<array<string, mixed>> $events
 * @var list<array<string, mixed>> $comments
 * @var string $action
 */
?>
<div class="panel">
    <h2>History</h2>

    <ol class="timeline">
        <?php foreach ($events as $event) : ?>
            <li>
                <strong><?= $this->e((string) ($event['actor_name'] ?? 'someone')) ?></strong>
                <?= $this->e((string) ($event['from_status'] ?? 'new')) ?>
                &gt; <?= $this->e((string) $event['to_status']) ?>
                <?php if (($event['note'] ?? null) !== null) : ?>
                    <span class="status-item__detail"><?= $this->e((string) $event['note']) ?></span>
                <?php endif ?>
                <span class="status-item__detail"><?= $this->e($this->date((string) $event['created_at'], 'j M Y, H:i')) ?></span>
            </li>
        <?php endforeach ?>
    </ol>
</div>

<div class="panel">
    <h2>Comments</h2>

    <?php if ($comments === []) : ?>
        <p class="muted">Nothing said yet.</p>
    <?php endif ?>

    <?php foreach ($comments as $comment) : ?>
        <div class="comment">
            <p class="comment__meta">
                <strong><?= $this->e((string) ($comment['author_name'] ?? 'someone')) ?></strong>
                <span class="status-item__detail"><?= $this->e($this->date((string) $comment['created_at'], 'j M Y, H:i')) ?></span>
            </p>
            <p><?= nl2br($this->e((string) $comment['body'])) ?></p>
        </div>
    <?php endforeach ?>

    <form method="post" action="<?= $this->e($action) ?>" class="stack">
        <?= $this->csrf->field() ?>
        <input type="hidden" name="action" value="comment">

        <div class="field">
            <label for="body">Add a comment</label>
            <textarea id="body" name="body" rows="2" required></textarea>
        </div>

        <button type="submit" class="button button--small">Comment</button>
    </form>
</div>
