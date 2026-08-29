<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Book|null $book
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Report a problem';
$this->end();
?>
<section class="form-page">
    <h1>Report a problem</h1>

    <p>
        If something here should not be, tell us. You do not need an account: a
        rights holder should not have to join a library to ask it to stop hosting
        their book. An administrator reads every notice.
    </p>

    <?php if ($book !== null) : ?>
        <p class="banner banner--warn">
            About <strong><?= $this->e($book->title) ?></strong>.
        </p>
    <?php endif ?>

    <form method="post" action="<?= $this->url('report') ?>" class="stack">
        <?= $this->csrf->field() ?>
        <?php if ($book !== null) : ?>
            <input type="hidden" name="book_slug" value="<?= $this->e($book->slug) ?>">
        <?php endif ?>

        <div class="field">
            <label for="claimant_name">Your name</label>
            <input type="text" id="claimant_name" name="claimant_name" required maxlength="160"
                   value="<?= $this->e($this->old('claimant_name')) ?>">
            <?php $this->include('partials/field-errors', ['field' => 'claimant_name']) ?>
        </div>

        <div class="field">
            <label for="claimant_email">Your email</label>
            <input type="email" id="claimant_email" name="claimant_email" required maxlength="191"
                   value="<?= $this->e($this->old('claimant_email')) ?>">
            <p class="field__hint">The only place the outcome is sent.</p>
            <?php $this->include('partials/field-errors', ['field' => 'claimant_email']) ?>
        </div>

        <div class="field">
            <label for="claimant_role">Who you are to the work</label>
            <input type="text" id="claimant_role" name="claimant_role" maxlength="160"
                   placeholder="The author, the publisher, an agent, a reader"
                   value="<?= $this->e($this->old('claimant_role')) ?>">
        </div>

        <?php if ($book === null) : ?>
            <div class="field">
                <label for="subject_url">Address of the page</label>
                <input type="text" id="subject_url" name="subject_url" maxlength="500"
                       value="<?= $this->e($this->old('subject_url')) ?>">
                <?php $this->include('partials/field-errors', ['field' => 'subject_url']) ?>
            </div>
        <?php endif ?>

        <div class="field">
            <label for="basis">What is wrong</label>
            <textarea id="basis" name="basis" rows="5" required maxlength="2000"><?=
                $this->e($this->old('basis'))
            ?></textarea>
            <p class="field__hint">
                If this is a copyright claim, say what right you hold and how you know.
            </p>
            <?php $this->include('partials/field-errors', ['field' => 'basis']) ?>
        </div>

        <button type="submit" class="button">Send the notice</button>
    </form>
</section>
