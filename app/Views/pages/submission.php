<?php
/**
 * @var App\Core\View $this
 * @var App\Models\ModerationRequest $request
 * @var list<array<string, mixed>> $events
 * @var list<array<string, mixed>> $comments
 */

use App\Support\ModerationStatus;

$this->layout('layouts/app');
$this->section('title');
echo $this->e($request->title);
$this->end();
?>
<section class="stack-wide">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $this->url('submissions') ?>">Your submissions</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $this->e($request->title) ?></span>
    </nav>

    <h1><?= $this->e($request->title) ?></h1>

    <p>
        <span class="tag tag--role"><?= $this->e($request->type->label()) ?></span>
        <span class="tag"><?= $this->e($request->status->label()) ?></span>
    </p>

    <?php if ($request->reason !== null) : ?>
        <p class="banner banner--warn"><?= $this->e($request->reason) ?></p>
    <?php endif ?>

    <?php if ($request->status->canMoveTo(ModerationStatus::Pending)
        || $request->status->canMoveTo(ModerationStatus::Withdrawn)) : ?>
        <form method="post" action="<?= $this->url('submission.act', ['id' => $request->id]) ?>"
              class="inline-form">
            <?= $this->csrf->field() ?>

            <?php if ($request->status === ModerationStatus::ChangesRequested) : ?>
                <button type="submit" name="action" value="resubmit" class="button">
                    I have fixed it, review again
                </button>
            <?php endif ?>

            <?php if ($request->status->canMoveTo(ModerationStatus::Withdrawn)) : ?>
                <button type="submit" name="action" value="withdraw" class="button button--quiet">Withdraw</button>
            <?php endif ?>
        </form>
    <?php endif ?>

    <?php $this->include('partials/moderation-thread', [
        'request'  => $request,
        'events'   => $events,
        'comments' => $comments,
        'action'   => $this->url('submission.act', ['id' => $request->id]),
    ]) ?>
</section>
