<?php
/**
 * @var App\Core\View $this
 * @var App\Models\ModerationRequest $request
 * @var App\Models\Book|null $book
 * @var App\Models\BookFile|null $file
 * @var list<array{id: int, title: string, slug: string, status: string}> $duplicates
 * @var list<array<string, mixed>> $events
 * @var list<array<string, mixed>> $comments
 * @var array{approved: int, rejected: int, open: int}|null $record
 * @var list<string> $reasons
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Review: ' . $this->e($request->title);
$this->end();

$me = $this->auth->user();
$isMine = $request->isClaimedBy($me?->id);
$isOwnSubmission = $me !== null && $request->submitterId === $me->id;
?>
<section class="stack-wide">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $this->url('queue') ?>">Queue</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $this->e($request->title) ?></span>
    </nav>

    <h1><?= $this->e($request->title) ?></h1>

    <p>
        <span class="tag tag--role"><?= $this->e($request->type->label()) ?></span>
        <span class="tag"><?= $this->e($request->status->label()) ?></span>
        <?php if ($request->isClaimed()) : ?>
            <span class="tag tag--warn">claimed by <?= $this->e((string) $request->assigneeName) ?></span>
        <?php endif ?>
    </p>

    <div class="review">
        <div class="review__main">
            <?php if ($file !== null) : ?>
                <div class="panel">
                    <h2>The file</h2>
                    <dl class="status-grid">
                        <div class="status-item">
                            <dt>Name</dt>
                            <dd><?= $this->e((string) $file->originalName) ?></dd>
                        </div>
                        <div class="status-item">
                            <dt>Format and size</dt>
                            <dd><?= $this->e(strtoupper($file->format)) ?> &middot; <?= $this->e($file->humanSize()) ?></dd>
                        </div>
                        <div class="status-item">
                            <dt>Pages</dt>
                            <dd><?= $file->pageCount === null ? 'not read' : (int) $file->pageCount ?></dd>
                        </div>
                        <div class="status-item">
                            <dt>Detected type</dt>
                            <dd><?= $this->e((string) $file->mimeType) ?></dd>
                        </div>
                        <div class="status-item">
                            <dt>SHA-256</dt>
                            <dd><code><?= $this->e(substr($file->sha256, 0, 24)) ?>…</code></dd>
                        </div>
                        <div class="status-item">
                            <dt>Where it is</dt>
                            <dd><?= $this->e($file->status) ?></dd>
                        </div>
                    </dl>

                    <p>
                        <a class="button button--small button--quiet"
                           href="<?= $this->url('file', ['id' => $file->id]) ?>?inline">Open the file</a>
                    </p>
                </div>
            <?php endif ?>

            <?php if ($book !== null) : ?>
                <div class="panel">
                    <h2>The record</h2>
                    <p>
                        <a href="<?= $this->url('book', ['slug' => $book->slug]) ?>"><?= $this->e($book->title) ?></a>
                        by <?= $this->e($book->byline()) ?>
                    </p>
                    <p class="muted">
                        <?= $this->e($book->contentType->label()) ?> &middot;
                        licence: <?= $this->e($book->licence->label()) ?>
                        <?php if ($book->licenceNote !== null) : ?>
                            &middot; <?= $this->e($book->licenceNote) ?>
                        <?php endif ?>
                    </p>
                    <?php if ($book->description !== null) : ?>
                        <p><?= $this->e(mb_substr($book->description, 0, 400)) ?></p>
                    <?php endif ?>
                </div>
            <?php endif ?>

            <?php if ($duplicates !== []) : ?>
                <div class="panel">
                    <h2>Possible duplicates</h2>
                    <p class="muted">Same title or a shared author. Worth a look before approving.</p>
                    <ul>
                        <?php foreach ($duplicates as $duplicate) : ?>
                            <li>
                                <a href="<?= $this->url('book', ['slug' => $duplicate['slug']]) ?>">
                                    <?= $this->e($duplicate['title']) ?>
                                </a>
                                <span class="status-item__detail"><?= $this->e($duplicate['status']) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>

            <?php if ($request->status->isOpen()) : ?>
                <div class="panel">
                    <h2>Decision</h2>

                    <?php if ($isOwnSubmission && !($me?->isAdmin() ?? false)) : ?>
                        <p class="banner banner--warn">
                            This is your own submission, so someone else has to decide on it.
                        </p>
                    <?php elseif (!$isMine && $request->isClaimed()) : ?>
                        <p class="banner banner--warn">
                            Someone else holds the claim. It frees up when their 30 minutes are up.
                        </p>
                    <?php else : ?>
                        <?php if (!$isMine) : ?>
                            <form method="post" action="<?= $this->url('queue.claim', ['id' => $request->id]) ?>">
                                <?= $this->csrf->field() ?>
                                <button type="submit" class="button">Claim it first</button>
                            </form>
                        <?php else : ?>
                            <form method="post" action="<?= $this->url('queue.decide', ['id' => $request->id]) ?>"
                                  class="stack">
                                <?= $this->csrf->field() ?>

                                <div class="field">
                                    <label for="canned_reason">Reason</label>
                                    <select id="canned_reason" name="canned_reason">
                                        <option value="">No canned reason</option>
                                        <?php foreach ($reasons as $reason) : ?>
                                            <option value="<?= $this->e($reason) ?>"><?= $this->e($reason) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                    <p class="field__hint">
                                        A rejection needs a reason. The submitter sees it.
                                    </p>
                                </div>

                                <div class="field">
                                    <label for="reason">Anything to add</label>
                                    <textarea id="reason" name="reason" rows="2" maxlength="255"></textarea>
                                </div>

                                <div class="book__actions">
                                    <button type="submit" name="decision" value="approve" class="button">Approve</button>
                                    <button type="submit" name="decision" value="changes"
                                            class="button button--quiet">Ask for changes</button>
                                    <button type="submit" name="decision" value="reject"
                                            class="button button--quiet">Reject</button>
                                </div>
                            </form>

                            <form method="post" action="<?= $this->url('queue.release', ['id' => $request->id]) ?>">
                                <?= $this->csrf->field() ?>
                                <button type="submit" class="link-button">Give up the claim</button>
                            </form>
                        <?php endif ?>
                    <?php endif ?>
                </div>
            <?php else : ?>
                <div class="panel">
                    <h2>Decided</h2>
                    <p>
                        <?= $this->e($request->status->label()) ?>
                        <?php if ($request->reason !== null) : ?>
                            &middot; <?= $this->e($request->reason) ?>
                        <?php endif ?>
                    </p>
                </div>
            <?php endif ?>

            <?php $this->include('partials/moderation-thread', [
                'request'  => $request,
                'events'   => $events,
                'comments' => $comments,
                'action'   => $this->url('queue.comment', ['id' => $request->id]),
            ]) ?>
        </div>

        <aside class="review__aside panel">
            <h2>Submitter</h2>

            <?php if ($request->submitterName !== null) : ?>
                <p>
                    <a href="<?= $this->url('profile', ['username' => $request->submitterName]) ?>">
                        <?= $this->e($request->submitterName) ?>
                    </a>
                </p>
            <?php else : ?>
                <p class="muted">The account is gone.</p>
            <?php endif ?>

            <?php if ($record !== null) : ?>
                <dl class="stat-row">
                    <div>
                        <dt>Approved</dt>
                        <dd><?= (int) $record['approved'] ?></dd>
                    </div>
                    <div>
                        <dt>Rejected</dt>
                        <dd><?= (int) $record['rejected'] ?></dd>
                    </div>
                    <div>
                        <dt>Open</dt>
                        <dd><?= (int) $record['open'] ?></dd>
                    </div>
                </dl>
            <?php endif ?>

            <p class="muted">Submitted <?= $request->ageInDays() ?> day(s) ago.</p>
        </aside>
    </div>
</section>
