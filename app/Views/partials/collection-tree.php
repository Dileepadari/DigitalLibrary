<?php
/**
 * Renders a collection subtree to any depth, including itself for the children.
 *
 * @var App\Core\View $this
 * @var list<App\Models\Collection> $nodes
 * @var int $currentId
 */
?>
<ul class="tree">
    <?php foreach ($nodes as $node) : ?>
        <li>
            <a href="<?= $this->url('collection', ['path' => $node->relativePath()]) ?>"
               <?= $node->id === ($currentId ?? 0) ? 'aria-current="page"' : '' ?>>
                <?= $this->e($node->name) ?>
            </a>
            <?php if ($node->itemCount > 0) : ?>
                <span class="tree__count"><?= (int) $node->itemCount ?></span>
            <?php endif ?>

            <?php if ($node->children !== []) : ?>
                <?php $this->include('partials/collection-tree', [
                    'nodes'     => $node->children,
                    'currentId' => $currentId ?? 0,
                ]) ?>
            <?php endif ?>
        </li>
    <?php endforeach ?>
</ul>
