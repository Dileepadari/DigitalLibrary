<?php
/**
 * @var App\Core\View $this
 * @var list<App\Models\Category> $tree
 * @var list<App\Models\Category> $pendingCategories
 * @var list<App\Models\Tag> $pendingTags
 * @var list<App\Models\Tag> $activeTags
 * @var list<App\Models\Category> $allCategories
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Taxonomy';
$this->end();
?>
<section class="stack-wide">
    <h1>Categories and tags</h1>

    <div class="panel">
        <h2>Waiting for a decision</h2>

        <?php if ($pendingCategories === [] && $pendingTags === []) : ?>
            <p class="empty">Nothing is waiting.</p>
        <?php endif ?>

        <?php foreach ($pendingCategories as $category) : ?>
            <div class="decision-row">
                <div>
                    <strong><?= $this->e($category->name) ?></strong>
                    <span class="status-item__detail">
                        category at <?= $this->e($category->path) ?>
                        <?php if ($category->description !== null) : ?>
                            &middot; <?= $this->e($category->description) ?>
                        <?php endif ?>
                    </span>
                </div>
                <form method="post"
                      action="<?= $this->url('librarian.categories.decide', ['id' => $category->id]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <button type="submit" name="decision" value="approve" class="button button--small">Approve</button>
                    <button type="submit" name="decision" value="reject"
                            class="button button--small button--quiet">Reject</button>
                </form>
            </div>
        <?php endforeach ?>

        <?php foreach ($pendingTags as $tag) : ?>
            <div class="decision-row">
                <div>
                    <strong><?= $this->e($tag->name) ?></strong>
                    <span class="status-item__detail">tag &middot; <?= $this->e($tag->slug) ?></span>
                </div>
                <form method="post" action="<?= $this->url('librarian.tags.decide', ['id' => $tag->id]) ?>"
                      class="inline-form">
                    <?= $this->csrf->field() ?>
                    <button type="submit" name="decision" value="approve" class="button button--small">Approve</button>
                    <button type="submit" name="decision" value="reject"
                            class="button button--small button--quiet">Reject</button>
                </form>
            </div>
        <?php endforeach ?>
    </div>

    <div class="panel">
        <h2>Add a category</h2>

        <form method="post" action="<?= $this->url('librarian.categories.store') ?>" class="stack">
            <?= $this->csrf->field() ?>

            <div class="field-row">
                <div class="field">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required maxlength="120"
                           value="<?= $this->e($this->old('name')) ?>">
                    <?php $this->include('partials/field-errors', ['field' => 'name']) ?>
                </div>

                <div class="field">
                    <label for="parent_id">Inside</label>
                    <select id="parent_id" name="parent_id">
                        <option value="0">Top level</option>
                        <?php foreach ($allCategories as $option) : ?>
                            <option value="<?= (int) $option->id ?>">
                                <?= $this->e(str_repeat('- ', $option->depth) . $option->name) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="description">What belongs in it</label>
                <input type="text" id="description" name="description" maxlength="500"
                       value="<?= $this->e($this->old('description')) ?>">
            </div>

            <button type="submit" class="button">Create</button>
        </form>
    </div>

    <div class="panel">
        <h2>The tree</h2>
        <?php $this->include('partials/category-tree', ['nodes' => $tree]) ?>
    </div>

    <div class="panel">
        <h2>Tags in use</h2>
        <p class="muted">Add an alias to fold a synonym into an existing tag.</p>

        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th scope="col">Tag</th><th scope="col">Books</th><th scope="col">Alias</th></tr>
                </thead>
                <tbody>
                <?php foreach ($activeTags as $tag) : ?>
                    <tr>
                        <td>
                            <a href="<?= $this->url('tag', ['slug' => $tag->slug]) ?>"><?= $this->e($tag->name) ?></a>
                        </td>
                        <td><?= (int) $tag->usageCount ?></td>
                        <td>
                            <form method="post" action="<?= $this->url('librarian.tags.alias', ['id' => $tag->id]) ?>"
                                  class="inline-form">
                                <?= $this->csrf->field() ?>
                                <input type="text" name="alias" placeholder="another spelling" size="14"
                                       aria-label="Alias for <?= $this->e($tag->name) ?>">
                                <button type="submit" class="button button--small">Add</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
