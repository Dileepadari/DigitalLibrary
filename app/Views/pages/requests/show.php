<?php
/**
 * @var App\Core\View $this
 * @var App\Models\BookRequest $request
 */

use App\Support\RequestStatus;

$this->layout('layouts/app');
$this->section('title');
echo $this->e($request->title);
$this->end();

$me = $this->auth->user();
$isRequester = $me !== null && $request->requesterId === $me->id;
$canClose = $isRequester || $this->gate->allows('request.close');
?>
<section class="stack-wide">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $this->url('requests') ?>">Requests</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $this->e($request->title) ?></span>
    </nav>

    <div class="request-head">
        <?php $this->include('partials/vote-button', ['request' => $request]) ?>

        <div>
            <h1><?= $this->e($request->title) ?></h1>
            <p class="muted">
                <?php if ($request->author !== null) : ?>
                    by <?= $this->e($request->author) ?> &middot;
                <?php endif ?>
                asked by <?= $this->e($request->requesterName ?? 'someone') ?>
                on <?= $this->e($this->date($request->createdAt, 'j M Y')) ?>
                <?php if ($request->isbn !== null) : ?>
                    &middot; ISBN <?= $this->e($request->isbn) ?>
                <?php endif ?>
            </p>
            <p><span class="tag tag--role"><?= $this->e($request->status->label()) ?></span>
                <?php if ($request->claimedByName !== null && $request->status === RequestStatus::Claimed) : ?>
                    <span class="muted">by <?= $this->e($request->claimedByName) ?></span>
                <?php endif ?>
            </p>
        </div>
    </div>

    <?php if ($request->note !== null) : ?>
        <p><?= $this->e($request->note) ?></p>
    <?php endif ?>

    <?php if ($request->status === RequestStatus::Fulfilled && $request->fulfilledBookSlug !== null) : ?>
        <p class="banner banner--ok">
            Answered by
            <a href="<?= $this->url('book', ['slug' => $request->fulfilledBookSlug]) ?>">
                <?= $this->e((string) $request->fulfilledBookTitle) ?>
            </a>.
        </p>
    <?php endif ?>

    <?php if ($request->closeReason !== null) : ?>
        <p class="banner banner--warn"><?= $this->e($request->closeReason) ?></p>
    <?php endif ?>

    <?php if ($request->status->isOpen() && $this->gate->allows('book.upload')) : ?>
        <p>
            <a class="button" href="<?= $this->url('books.new') ?>?request=<?= (int) $request->id ?>">
                I can add this book
            </a>
        </p>
    <?php endif ?>

    <?php if ($me === null && $request->status->isOpen()) : ?>
        <div class="panel">
            <h2>Want this too?</h2>
            <p class="muted">
                <a href="<?= $this->url('login') ?>">Sign in</a> to upvote this
                request, or <a href="<?= $this->url('register') ?>">create an
                account</a> and add the book yourself. Everyone who voted hears
                when it arrives.
            </p>
        </div>
    <?php endif ?>

    <?php if ($me !== null && $request->status->isOpen()) : ?>
        <div class="panel">
            <h2>Working on it</h2>

            <?php if ($request->status === RequestStatus::Open) : ?>
                <form method="post" action="<?= $this->url('request.act', ['id' => $request->id]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <button type="submit" name="action" value="claim" class="button button--small">
                        I am looking for it
                    </button>
                </form>
                <p class="field__hint">
                    Claiming it tells everyone else not to duplicate the effort.
                </p>
            <?php elseif ($request->isClaimedBy($me->id) || $this->gate->allows('request.close')) : ?>
                <form method="post" action="<?= $this->url('request.act', ['id' => $request->id]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <button type="submit" name="action" value="release" class="button button--small button--quiet">
                        Give it up
                    </button>
                </form>
            <?php endif ?>
        </div>
    <?php endif ?>

    <?php if ($canClose && $request->status->isOpen()) : ?>
        <div class="panel">
            <h2>Close it</h2>

            <form method="post" action="<?= $this->url('request.act', ['id' => $request->id]) ?>" class="stack">
                <?= $this->csrf->field() ?>
                <input type="hidden" name="action" value="close">

                <div class="field-row">
                    <div class="field">
                        <label for="status">Because</label>
                        <select id="status" name="status">
                            <?php foreach (RequestStatus::closable() as $option) : ?>
                                <option value="<?= $this->e($option->value) ?>">
                                    <?= $this->e($option->label()) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="reason">Anything to add</label>
                        <input type="text" id="reason" name="reason" maxlength="255">
                    </div>
                </div>

                <button type="submit" class="button button--quiet">Close the request</button>
            </form>
        </div>
    <?php endif ?>

    <?php if ($this->gate->allows('request.close')) : ?>
        <div class="panel">
            <h2>Librarian</h2>

            <?php if ($request->status->isOpen()) : ?>
                <form method="post" action="<?= $this->url('request.act', ['id' => $request->id]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <input type="hidden" name="action" value="fulfil">
                    <input type="text" name="slug" placeholder="book address, such as dune" size="20"
                           aria-label="Address of the book that answers this">
                    <button type="submit" class="button button--small">Link a record</button>
                </form>
                <p class="field__hint">
                    Use this when the book is already in the catalogue. Everyone who
                    voted is told.
                </p>
            <?php elseif ($request->status !== RequestStatus::Fulfilled) : ?>
                <form method="post" action="<?= $this->url('request.act', ['id' => $request->id]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <button type="submit" name="action" value="reopen" class="button button--small">Reopen</button>
                </form>
            <?php endif ?>
        </div>
    <?php endif ?>
</section>
