<?php
/**
 * @var App\Core\View $this
 * @var App\Models\BookRequest $request
 */
?>
<?php if ($this->gate->allows('request.vote') && $request->status->isOpen()) : ?>
    <form method="post" action="<?= $this->url('request.vote', ['id' => $request->id]) ?>" class="vote">
        <?= $this->csrf->field() ?>
        <button type="submit" class="vote__button <?= $request->viewerHasVoted ? 'vote__button--on' : '' ?>"
                aria-label="<?= $request->viewerHasVoted ? 'Take back your vote' : 'Vote for this' ?>">
            <span aria-hidden="true">&#9650;</span>
            <span class="vote__count"><?= (int) $request->voteCount ?></span>
        </button>
    </form>
<?php else : ?>
    <div class="vote">
        <div class="vote__button vote__button--static">
            <span aria-hidden="true">&#9650;</span>
            <span class="vote__count"><?= (int) $request->voteCount ?></span>
        </div>
    </div>
<?php endif ?>
