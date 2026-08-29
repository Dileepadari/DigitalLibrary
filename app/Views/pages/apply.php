<?php
/**
 * @var App\Core\View $this
 * @var App\Models\User $user
 * @var array<string, mixed>|null $pending
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Become a librarian';
$this->end();
?>
<section class="form-page">
    <h1>Become a librarian</h1>

    <p>
        Librarians review what other people submit, publish without review, and
        keep the categories and tags in order. It is work, and it is what keeps
        the library usable.
    </p>

    <?php if ($user->role->value !== 'member') : ?>
        <p class="banner banner--ok">You already have more than a member's permissions.</p>
    <?php elseif ($pending !== null) : ?>
        <p class="banner banner--warn">
            Your application is with the administrators. You will hear either way.
        </p>
        <p><?= nl2br($this->e((string) $pending['statement'])) ?></p>
    <?php else : ?>
        <form method="post" action="<?= $this->url('apply') ?>" class="stack">
            <?= $this->csrf->field() ?>

            <div class="field">
                <label for="statement">Why you, and what you would work on</label>
                <textarea id="statement" name="statement"<?= $this->errorAttributes('statement') ?> rows="6" required maxlength="2000"><?=
                    $this->e($this->old('statement'))
                ?></textarea>
                <p class="field__hint">
                    Forty characters at least. What you have contributed so far is
                    already visible on your profile, so this is for what the numbers
                    do not say.
                </p>
                <?php $this->include('partials/field-errors', ['field' => 'statement']) ?>
            </div>

            <button type="submit" class="button">Send the application</button>
        </form>
    <?php endif ?>
</section>
