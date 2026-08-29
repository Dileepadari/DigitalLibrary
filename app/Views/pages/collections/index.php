<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\Collection>, total: int, page: int, pages: int} $results
 * @var list<App\Models\Collection> $mine
 * @var list<App\Models\Collection> $following
 */

use App\Support\Visibility;

$this->layout('layouts/app');
$this->section('title');
echo 'Collections';
$this->end();
?>
<section class="stack-wide">
    <h1>Collections</h1>
    <p class="muted">
        Folders anyone can build, to any depth: a reading list, a syllabus, a
        shelf. Yours are private until you ask for them to be published.
    </p>

    <?php if ($this->gate->allows('collection.create.private')) : ?>
        <details class="panel" <?= $this->errors('name') !== [] ? 'open' : '' ?>>
            <summary><strong>Start a collection</strong></summary>

            <form method="post" action="<?= $this->url('collections') ?>" class="stack">
                <?= $this->csrf->field() ?>

                <div class="field">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name"<?= $this->errorAttributes('name') ?> required maxlength="120"
                           placeholder="UPSC Preparation" value="<?= $this->e($this->old('name')) ?>">
                    <?php $this->include('partials/field-errors', ['field' => 'name']) ?>
                </div>

                <div class="field">
                    <label for="description">What is it for</label>
                    <input type="text" id="description" name="description" maxlength="500"
                           value="<?= $this->e($this->old('description')) ?>">
                </div>

                <div class="field">
                    <label for="visibility">Who can see it</label>
                    <select id="visibility" name="visibility">
                        <?php foreach ([Visibility::Private, Visibility::Unlisted] as $option) : ?>
                            <option value="<?= $this->e($option->value) ?>"><?= $this->e($option->label()) ?></option>
                        <?php endforeach ?>
                    </select>
                    <p class="field__hint">
                        Making it public goes through the review queue, once it has
                        something in it.
                    </p>
                </div>

                <button type="submit" class="button">Start it</button>
            </form>
        </details>
    <?php endif ?>

    <?php if ($mine !== []) : ?>
        <div class="panel">
            <h2>Yours</h2>
            <ul class="collection-list">
                <?php foreach ($mine as $collection) : ?>
                    <li>
                        <a href="<?= $this->url('collection', ['path' => $collection->relativePath()]) ?>">
                            <?= $this->e($collection->name) ?>
                        </a>
                        <span class="tag"><?= $this->e($collection->visibility->label()) ?></span>
                        <?php if ($collection->isAwaitingReview()) : ?>
                            <span class="tag tag--warn">waiting for review</span>
                        <?php endif ?>
                        <span class="status-item__detail">
                            <?= (int) $collection->itemCount ?> book<?= $collection->itemCount === 1 ? '' : 's' ?>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

    <?php if ($following !== []) : ?>
        <div class="panel">
            <h2>You follow</h2>
            <ul class="collection-list">
                <?php foreach ($following as $collection) : ?>
                    <li>
                        <a href="<?= $this->url('collection', ['path' => $collection->relativePath()]) ?>">
                            <?= $this->e($collection->name) ?>
                        </a>
                        <span class="status-item__detail">by <?= $this->e($collection->ownerName ?? 'someone') ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

    <h2>Public collections</h2>

    <?php if ($results['rows'] === []) : ?>
        <p class="empty">None yet. The first published collection will be here.</p>
    <?php endif ?>

    <div class="collection-grid">
        <?php foreach ($results['rows'] as $collection) : ?>
            <article class="collection-card">
                <h3>
                    <a href="<?= $this->url('collection', ['path' => $collection->relativePath()]) ?>">
                        <?= $this->e($collection->name) ?>
                    </a>
                </h3>
                <?php if ($collection->description !== null) : ?>
                    <p class="muted"><?= $this->e($collection->description) ?></p>
                <?php endif ?>
                <p class="book-card__meta">
                    <span><?= (int) $collection->itemCount ?> book<?= $collection->itemCount === 1 ? '' : 's' ?></span>
                    <span><?= (int) $collection->followerCount ?> followers</span>
                    <span>by <?= $this->e($collection->ownerName ?? 'someone') ?></span>
                </p>
            </article>
        <?php endforeach ?>
    </div>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => [],
        'path'    => '/collections',
    ]) ?>
</section>
