<?php
/**
 * @var App\Core\View $this
 * @var list<App\Models\Tag> $tags
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Tags';
$this->end();
?>
<section class="stack-wide">
    <h1>Tags</h1>
    <p class="muted">
        Anyone can put a new tag on a book. It stays out of this list until a
        librarian approves it, which is what keeps sci-fi, scifi and science
        fiction from becoming three different things.
    </p>

    <p class="tag-cloud">
        <?php foreach ($tags as $tag) : ?>
            <a class="tag" href="<?= $this->url('tag', ['slug' => $tag->slug]) ?>">
                <?= $this->e($tag->name) ?>
                <?php if ($tag->usageCount > 0) : ?>
                    <span class="tree__count"><?= (int) $tag->usageCount ?></span>
                <?php endif ?>
            </a>
        <?php endforeach ?>
    </p>
</section>
