<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\Book>, total: int, page: int, pages: int} $results
 * @var array<string, string> $filters
 * @var array{content_type: array<string, int>, language: array<string, int>} $facets
 * @var list<App\Models\Category> $categories
 * @var list<App\Models\Tag> $popularTags
 */

use App\Support\ContentType;

$this->layout('layouts/app');
$this->section('title');
echo ($filters['q'] ?? '') !== '' ? 'Search: ' . $this->e($filters['q']) : 'Browse';
$this->end();

$without = static fn (string $key): string => '/books?' . http_build_query(array_diff_key($filters, [$key => '']));
$with = static fn (string $key, string $value): string
    => '/books?' . http_build_query(array_merge($filters, [$key => $value]));
?>
<section class="browse">
    <header class="browse__header">
        <h1><?= ($filters['q'] ?? '') !== '' ? 'Search results' : 'Browse the library' ?></h1>

        <form method="get" action="<?= $this->url('books') ?>" class="filter-bar">
            <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>"
                   placeholder="Title, author or description" aria-label="Search the catalogue">

            <?php foreach (['category'] as $carry) : ?>
                <?php if (($filters[$carry] ?? '') !== '') : ?>
                    <input type="hidden" name="<?= $this->e($carry) ?>" value="<?= $this->e($filters[$carry]) ?>">
                <?php endif ?>
            <?php endforeach ?>

            <?php if ($facets['content_type'] !== []) : ?>
                <select name="type" aria-label="Kind">
                    <option value="">Any kind</option>
                    <?php foreach ($facets['content_type'] as $value => $count) : ?>
                        <?php $type = ContentType::tryFrom((string) $value) ?>
                        <option value="<?= $this->e((string) $value) ?>"
                            <?= ($filters['type'] ?? '') === (string) $value ? 'selected' : '' ?>>
                            <?= $this->e($type?->label() ?? $value) ?> (<?= (int) $count ?>)
                        </option>
                    <?php endforeach ?>
                </select>
            <?php endif ?>

            <?php if ($popularTags !== []) : ?>
                <select name="tag" aria-label="Tag">
                    <option value="">Any tag</option>
                    <?php
                    $tagOptions = $popularTags;
                    $chosenTag = $filters['tag'] ?? '';

                    // A tag that is filtered on but not popular still has to be
                    // in the list, or choosing it would clear it.
                    if ($chosenTag !== '' && !in_array($chosenTag, array_map(
                        static fn (App\Models\Tag $tag): string => $tag->slug,
                        $tagOptions
                    ), true)) {
                        array_unshift($tagOptions, new App\Models\Tag(0, $chosenTag, $chosenTag, 'active', 0));
                    }
                    ?>
                    <?php foreach ($tagOptions as $tag) : ?>
                        <option value="<?= $this->e($tag->slug) ?>"
                            <?= $chosenTag === $tag->slug ? 'selected' : '' ?>>
                            <?= $this->e($tag->name) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            <?php endif ?>

            <?php if ($facets['language'] !== []) : ?>
                <select name="language" aria-label="Language">
                    <option value="">Any language</option>
                    <?php foreach ($facets['language'] as $value => $count) : ?>
                        <option value="<?= $this->e((string) $value) ?>"
                            <?= ($filters['language'] ?? '') === (string) $value ? 'selected' : '' ?>>
                            <?= $this->e(strtoupper((string) $value)) ?> (<?= (int) $count ?>)
                        </option>
                    <?php endforeach ?>
                </select>
            <?php endif ?>

            <select name="sort" aria-label="Sort">
                <?php foreach ([
                    ''        => 'Most recent',
                    'title'   => 'Title',
                    'year'    => 'Year',
                    'popular' => 'Most read',
                    'rating'  => 'Best rated',
                ] as $value => $label) : ?>
                    <option value="<?= $this->e($value) ?>"
                        <?= ($filters['sort'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= $this->e($label) ?>
                    </option>
                <?php endforeach ?>
            </select>

            <button type="submit" class="button button--small">Search</button>
        </form>

        <?php if ($filters !== []) : ?>
            <p class="active-filters">
                <?php foreach ($filters as $key => $value) : ?>
                    <?php if ($key !== 'sort') : ?>
                        <a class="tag tag--filter" href="<?= $this->e($without($key)) ?>">
                            <?= $this->e($key) ?>: <?= $this->e($value) ?> &times;
                        </a>
                    <?php endif ?>
                <?php endforeach ?>
            </p>
        <?php endif ?>
    </header>

    <div class="browse__layout">
        <aside class="browse__facets">
            <section>
                <h2>Categories</h2>
                <?php $this->include('partials/category-tree', ['nodes' => $categories]) ?>
            </section>

        </aside>

        <div class="browse__results">
            <?php $this->include('partials/book-grid', [
                'books'        => $results['rows'],
                'emptyMessage' => ($filters['q'] ?? '') !== ''
                    ? 'Nothing matched that search. Try fewer words, or raise a book request.'
                    : 'No books in the catalogue yet.',
            ]) ?>

            <?php $this->include('partials/pagination', [
                'results' => $results,
                'filters' => $filters,
                'path'    => '/books',
            ]) ?>
        </div>
    </div>
</section>
