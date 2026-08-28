<?php
/**
 * @var App\Core\View $this
 * @var array{page: int, pages: int, total: int} $results
 * @var array<string, string> $filters
 * @var string $path
 */
$link = static fn (int $page): string => $path . '?' . http_build_query(array_merge($filters, ['page' => $page]));
?>
<?php if (($results['pages'] ?? 1) > 1) : ?>
    <nav class="pagination" aria-label="Pages">
        <?php if ($results['page'] > 1) : ?>
            <a href="<?= $this->e($link($results['page'] - 1)) ?>">Previous</a>
        <?php endif ?>

        <span>Page <?= (int) $results['page'] ?> of <?= (int) $results['pages'] ?>,
            <?= (int) $results['total'] ?> in total</span>

        <?php if ($results['page'] < $results['pages']) : ?>
            <a href="<?= $this->e($link($results['page'] + 1)) ?>">Next</a>
        <?php endif ?>
    </nav>
<?php endif ?>
