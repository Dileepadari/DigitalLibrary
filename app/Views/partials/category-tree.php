<?php
/**
 * Renders a category subtree to any depth. Includes itself for the children,
 * which is why the tree in the database has a depth cap.
 *
 * @var App\Core\View $this
 * @var list<App\Models\Category> $nodes
 * @var bool $showCounts
 */
?>
<ul class="tree">
    <?php foreach ($nodes as $node) : ?>
        <li>
            <a href="<?= $this->url('category', ['path' => $node->relativePath()]) ?>">
                <?= $this->e($node->name) ?>
            </a>
            <?php if (($showCounts ?? true) && $node->bookCount > 0) : ?>
                <span class="tree__count"><?= (int) $node->bookCount ?></span>
            <?php endif ?>
            <?php if ($node->status !== 'active') : ?>
                <span class="tag tag--warn"><?= $this->e($node->status) ?></span>
            <?php endif ?>

            <?php if ($node->children !== []) : ?>
                <?php $this->include('partials/category-tree', [
                    'nodes'      => $node->children,
                    'showCounts' => $showCounts ?? true,
                ]) ?>
            <?php endif ?>
        </li>
    <?php endforeach ?>
</ul>
