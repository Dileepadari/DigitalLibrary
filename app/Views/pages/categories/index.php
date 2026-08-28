<?php
/**
 * @var App\Core\View $this
 * @var list<App\Models\Category> $tree
 * @var list<App\Models\Category> $options
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Categories';
$this->end();
?>
<section class="stack-wide">
    <h1>Categories</h1>
    <p class="muted">
        The fixed shelf structure, curated by librarians. Tags describe a book;
        categories say where it lives.
    </p>

    <div class="panel">
        <?php $this->include('partials/category-tree', ['nodes' => $tree]) ?>
    </div>

    <?php if ($this->gate->allows('taxonomy.propose')) : ?>
        <details class="panel">
            <summary><strong>Propose a category</strong></summary>

            <form method="post" action="<?= $this->url('categories.propose') ?>" class="stack">
                <?= $this->csrf->field() ?>

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
                        <?php foreach ($options as $option) : ?>
                            <option value="<?= (int) $option->id ?>">
                                <?= $this->e(str_repeat('- ', $option->depth) . $option->name) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>

                <div class="field">
                    <label for="description">What belongs in it</label>
                    <input type="text" id="description" name="description" maxlength="500"
                           value="<?= $this->e($this->old('description')) ?>">
                </div>

                <button type="submit" class="button">Propose</button>
            </form>
        </details>
    <?php endif ?>
</section>
