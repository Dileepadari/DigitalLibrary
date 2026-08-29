<?php
/**
 * @var App\Core\View $this
 * @var App\Models\Collection $node
 * @var App\Models\Collection $root
 * @var list<App\Models\Collection> $ancestors
 * @var list<App\Models\Collection> $children
 * @var list<array{book: App\Models\Book, note: string|null, position: int}> $items
 * @var list<App\Models\Collection> $tree
 * @var bool $canEdit
 * @var bool $isFollowing
 * @var list<array{id: int, username: string}> $maintainers
 */

use App\Support\Visibility;

$this->layout('layouts/app');
$this->section('title');
echo $this->e($node->name);
$this->end();

$me = $this->auth->user();
$act = $this->url('collection.act', ['id' => $node->id]);
$rootAct = $this->url('collection.act', ['id' => $root->id]);
?>
<section class="stack-wide">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $this->url('collections') ?>">Collections</a>
        <?php foreach ($ancestors as $ancestor) : ?>
            <span aria-hidden="true">/</span>
            <a href="<?= $this->url('collection', ['path' => $ancestor->relativePath()]) ?>">
                <?= $this->e($ancestor->name) ?>
            </a>
        <?php endforeach ?>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $this->e($node->name) ?></span>
    </nav>

    <h1><?= $this->e($node->name) ?></h1>

    <p>
        <span class="tag tag--role"><?= $this->e($root->visibility->label()) ?></span>
        <?php if ($root->isAwaitingReview()) : ?>
            <span class="tag tag--warn">waiting for a librarian</span>
        <?php endif ?>
        <?php if ($root->reviewStatus === 'rejected' && $canEdit) : ?>
            <span class="tag tag--warn">not accepted for publication</span>
        <?php endif ?>
        <span class="muted">
            by <?= $this->e($root->ownerName ?? 'someone') ?>
            &middot; <?= (int) $node->itemCount ?> book<?= $node->itemCount === 1 ? '' : 's' ?> here and below
            <?php if ($root->forkedFromId !== null) : ?>
                &middot; forked from another collection
            <?php endif ?>
        </span>
    </p>

    <?php if ($node->description !== null) : ?>
        <p><?= $this->e($node->description) ?></p>
    <?php endif ?>

    <?php if ($me !== null && !$canEdit && $root->visibility !== Visibility::Private) : ?>
        <form method="post" action="<?= $rootAct ?>" class="inline-form">
            <?= $this->csrf->field() ?>
            <button type="submit" name="action" value="follow" class="button button--small">
                <?= $isFollowing ? 'Stop following' : 'Follow' ?>
            </button>
            <?php if ($root->isPublic() && $this->gate->allows('collection.create.private')) : ?>
                <button type="submit" name="action" value="fork" class="button button--small button--quiet">
                    Fork it
                </button>
            <?php endif ?>
        </form>
    <?php endif ?>

    <div class="browse__layout">
        <aside class="browse__facets">
            <section>
                <h2>The whole collection</h2>
                <?php $this->include('partials/collection-tree', ['nodes' => $tree, 'currentId' => $node->id]) ?>
            </section>

            <?php if ($maintainers !== []) : ?>
                <section>
                    <h2>Maintainers</h2>
                    <ul class="facet-list">
                        <?php foreach ($maintainers as $maintainer) : ?>
                            <li>
                                <a href="<?= $this->url('profile', ['username' => $maintainer['username']]) ?>">
                                    <?= $this->e($maintainer['username']) ?>
                                </a>
                                <?php if ($root->isOwnedBy($me?->id)) : ?>
                                    <form method="post" action="<?= $rootAct ?>" class="inline-form">
                                        <?= $this->csrf->field() ?>
                                        <input type="hidden" name="action" value="remove-maintainer">
                                        <input type="hidden" name="user_id" value="<?= (int) $maintainer['id'] ?>">
                                        <button type="submit" class="link-button">remove</button>
                                    </form>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </section>
            <?php endif ?>
        </aside>

        <div class="browse__results">
            <?php if ($children !== []) : ?>
                <div class="panel">
                    <h2>Folders</h2>
                    <ul class="collection-list">
                        <?php foreach ($children as $child) : ?>
                            <li>
                                <a href="<?= $this->url('collection', ['path' => $child->relativePath()]) ?>">
                                    <?= $this->e($child->name) ?>
                                </a>
                                <span class="status-item__detail">
                                    <?= (int) $child->itemCount ?> book<?= $child->itemCount === 1 ? '' : 's' ?>
                                </span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>

            <h2>Books in <?= $this->e($node->name) ?></h2>

            <?php if ($items === []) : ?>
                <p class="empty">Nothing pinned to this folder yet.</p>
            <?php else : ?>
                <ol class="item-list">
                    <?php foreach ($items as $item) : ?>
                        <li>
                            <div>
                                <a href="<?= $this->url('book', ['slug' => $item['book']->slug]) ?>">
                                    <?= $this->e($item['book']->title) ?>
                                </a>
                                <span class="status-item__detail">
                                    <?= $this->e($item['book']->byline()) ?>
                                    <?php if ($item['note'] !== null) : ?>
                                        &middot; <?= $this->e($item['note']) ?>
                                    <?php endif ?>
                                </span>
                            </div>

                            <?php if ($canEdit) : ?>
                                <form method="post" action="<?= $act ?>" class="inline-form row-actions">
                                    <?= $this->csrf->field() ?>
                                    <input type="hidden" name="book_id" value="<?= (int) $item['book']->id ?>">
                                    <button type="submit" name="action" value="move-book-up"
                                            class="button button--small button--quiet"
                                            aria-label="Move up"><span aria-hidden="true">&uarr;</span></button>
                                    <button type="submit" name="action" value="move-book-down"
                                            class="button button--small button--quiet"
                                            aria-label="Move down"><span aria-hidden="true">&darr;</span></button>
                                    <button type="submit" name="action" value="remove-book"
                                            class="button button--small button--danger"
                                            aria-label="Remove from this collection">Remove</button>
                                </form>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ol>
            <?php endif ?>

            <?php if ($canEdit) : ?>
                <div class="panel curate">
                    <h2>Curate</h2>

                    <form method="post" action="<?= $act ?>" class="stack">
                        <?= $this->csrf->field() ?>
                        <input type="hidden" name="action" value="add-book">

                        <div class="field-row">
                            <div class="field">
                                <label for="slug">Add a book by its address</label>
                                <input type="text" id="slug" name="slug" placeholder="pride-and-prejudice">
                                <p class="field__hint">
                                    The last part of the book's URL. Copy it from the book page.
                                </p>
                            </div>

                            <div class="field">
                                <label for="note">Why it is here</label>
                                <input type="text" id="note" name="note" maxlength="255">
                            </div>
                        </div>

                        <button type="submit" class="button button--small">Add the book</button>
                    </form>

                    <form method="post" action="<?= $act ?>" class="stack">
                        <?= $this->csrf->field() ?>
                        <input type="hidden" name="action" value="child">

                        <div class="field">
                            <label for="child-name">Add a folder inside this one</label>
                            <input type="text" id="child-name" name="name" maxlength="120" placeholder="History">
                        </div>

                        <button type="submit" class="button button--small">Add the folder</button>
                    </form>
                </div>

                <div class="panel">
                    <h2>This folder</h2>

                    <form method="post" action="<?= $act ?>" class="stack">
                        <?= $this->csrf->field() ?>
                        <input type="hidden" name="action" value="rename">

                        <div class="field-row">
                            <div class="field">
                                <label for="name">Name</label>
                                <input type="text" id="name" name="name" maxlength="120"
                                       value="<?= $this->e($node->name) ?>">
                                <p class="field__hint">The address stays as it is, so existing links keep working.</p>
                            </div>

                            <div class="field">
                                <label for="description">Description</label>
                                <input type="text" id="description" name="description" maxlength="500"
                                       value="<?= $this->e((string) $node->description) ?>">
                            </div>
                        </div>

                        <button type="submit" class="button button--small">Save</button>
                    </form>

                    <form method="post" action="<?= $act ?>" class="inline-form">
                        <?= $this->csrf->field() ?>
                        <button type="submit" name="action" value="delete" class="button button--small button--danger">
                            Delete this folder and everything in it
                        </button>
                    </form>
                </div>

                <?php if ($node->isRoot()) : ?>
                    <div class="panel">
                        <h2>Sharing</h2>

                        <form method="post" action="<?= $rootAct ?>" class="inline-form">
                            <?= $this->csrf->field() ?>
                            <input type="hidden" name="action" value="visibility">
                            <select name="visibility" aria-label="Visibility">
                                <?php foreach ([Visibility::Private, Visibility::Unlisted] as $option) : ?>
                                    <option value="<?= $this->e($option->value) ?>"
                                        <?= $root->visibility === $option ? 'selected' : '' ?>>
                                        <?= $this->e($option->label()) ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                            <button type="submit" class="button button--small">Set</button>
                        </form>

                        <?php if (!$root->isPublic() && !$root->isAwaitingReview()) : ?>
                            <form method="post" action="<?= $rootAct ?>" class="inline-form">
                                <?= $this->csrf->field() ?>
                                <button type="submit" name="action" value="publish" class="button button--small">
                                    Ask for it to be published
                                </button>
                            </form>
                            <p class="field__hint">
                                A librarian reviews the whole tree before it is listed publicly.
                            </p>
                        <?php endif ?>

                        <?php if ($root->isOwnedBy($me?->id)) : ?>
                            <form method="post" action="<?= $rootAct ?>" class="inline-form">
                                <?= $this->csrf->field() ?>
                                <input type="hidden" name="action" value="add-maintainer">
                                <input type="text" name="username" placeholder="username" size="14"
                                       aria-label="Invite a maintainer">
                                <button type="submit" class="button button--small">Invite</button>
                            </form>
                        <?php endif ?>
                    </div>
                <?php endif ?>
            <?php endif ?>
        </div>
    </div>
</section>
