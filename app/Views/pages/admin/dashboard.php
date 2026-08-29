<?php
/**
 * @var App\Core\View $this
 * @var array<string, int> $totals
 * @var int $days
 * @var array<string, int> $signups
 * @var array<string, int> $uploads
 * @var array<string, int> $reviews
 * @var list<array{name: string, path: string, total: int}> $categories
 * @var list<array{title: string, slug: string, downloads: int, views: int}> $books
 * @var array{decided: int, median_hours: float|null, open_over_a_week: int} $queue
 * @var int $takedowns
 * @var int|null $oldestNotice
 * @var int $applications
 * @var int $library
 * @var int $quarantine
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Admin';
$this->end();

$size = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;

    while ($bytes >= 1024 && $unit < count($units) - 1) {
        $bytes = (int) ($bytes / 1024);
        $unit++;
    }

    return $bytes . ' ' . $units[$unit];
};

$sparkline = function (array $series): string {
    $max = max(1, max($series));
    $bars = '';

    foreach ($series as $day => $count) {
        $height = (int) round(($count / $max) * 100);
        $bars .= '<span class="spark__bar" style="height: ' . max(2, $height) . '%" title="'
            . $this->e($day . ': ' . $count) . '"></span>';
    }

    return $bars;
};
?>
<section class="stack-wide">
    <h1>Admin</h1>

    <?php $this->include('partials/admin-nav') ?>

    <?php if ($takedowns > 0 || $applications > 0) : ?>
        <p class="banner banner--warn">
            <?php if ($takedowns > 0) : ?>
                <a href="<?= $this->url('admin.takedowns') ?>"><?= (int) $takedowns ?> takedown notice(s)</a>
                waiting<?= $oldestNotice !== null ? ', the oldest for ' . (int) $oldestNotice . ' days' : '' ?>.
            <?php endif ?>
            <?php if ($applications > 0) : ?>
                <a href="<?= $this->url('admin.applications') ?>"><?= (int) $applications ?> librarian
                application(s)</a> to read.
            <?php endif ?>
        </p>
    <?php endif ?>

    <dl class="status-grid">
        <?php foreach ([
            'Members'      => $totals['members'] ?? 0,
            'Librarians'   => $totals['librarians'] ?? 0,
            'Books'        => $totals['books'] ?? 0,
            'Files'        => $totals['files'] ?? 0,
            'Downloads'    => $totals['downloads'] ?? 0,
            'Reviews'      => $totals['reviews'] ?? 0,
            'Collections'  => $totals['collections'] ?? 0,
            'Open requests' => $totals['open_requests'] ?? 0,
        ] as $label => $value) : ?>
            <div class="status-item">
                <dt><?= $this->e($label) ?></dt>
                <dd class="status-item__number"><?= (int) $value ?></dd>
            </div>
        <?php endforeach ?>
    </dl>

    <div class="panel">
        <h2>The last <?= (int) $days ?> days</h2>

        <div class="spark-row">
            <div>
                <h3>New accounts</h3>
                <div class="spark"><?= $sparkline($signups) ?></div>
                <p class="status-item__detail"><?= array_sum($signups) ?> in total</p>
            </div>
            <div>
                <h3>Files uploaded</h3>
                <div class="spark"><?= $sparkline($uploads) ?></div>
                <p class="status-item__detail"><?= array_sum($uploads) ?> in total</p>
            </div>
            <div>
                <h3>Reviews written</h3>
                <div class="spark"><?= $sparkline($reviews) ?></div>
                <p class="status-item__detail"><?= array_sum($reviews) ?> in total</p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>The queue</h2>
        <dl class="status-grid">
            <div class="status-item">
                <dt>Waiting now</dt>
                <dd class="status-item__number"><?= (int) ($totals['queue'] ?? 0) ?></dd>
            </div>
            <div class="status-item">
                <dt>Decided in 30 days</dt>
                <dd class="status-item__number"><?= (int) $queue['decided'] ?></dd>
            </div>
            <div class="status-item">
                <dt>Median time to a decision</dt>
                <dd><?= $queue['median_hours'] === null ? 'no data yet' : $queue['median_hours'] . ' hours' ?></dd>
            </div>
            <div class="status-item">
                <dt>Waiting over a week</dt>
                <dd class="status-item__number"><?= (int) $queue['open_over_a_week'] ?></dd>
            </div>
        </dl>
        <p class="muted"><a href="<?= $this->url('queue') ?>">Open the queue</a></p>
    </div>

    <div class="panel">
        <h2>Storage</h2>
        <dl class="status-grid">
            <div class="status-item">
                <dt>Library</dt>
                <dd><?= $this->e($size($library)) ?></dd>
            </div>
            <div class="status-item">
                <dt>Quarantine</dt>
                <dd><?= $this->e($size($quarantine)) ?></dd>
            </div>
            <div class="status-item">
                <dt>Published bytes on record</dt>
                <dd><?= $this->e($size($totals['bytes'] ?? 0)) ?></dd>
            </div>
        </dl>
        <p class="muted"><a href="<?= $this->url('admin.storage') ?>">The storage dashboard</a></p>
    </div>

    <div class="browse__layout">
        <div class="panel">
            <h2>Busiest categories</h2>
            <ul class="facet-list">
                <?php foreach ($categories as $category) : ?>
                    <li>
                        <a href="<?= $this->url('category', ['path' => trim($category['path'], '/')]) ?>">
                            <?= $this->e($category['name']) ?>
                        </a>
                        <span class="tree__count"><?= (int) $category['total'] ?></span>
                    </li>
                <?php endforeach ?>
                <?php if ($categories === []) : ?>
                    <li class="muted">Nothing shelved yet.</li>
                <?php endif ?>
            </ul>
        </div>

        <div class="panel">
            <h2>Most downloaded</h2>
            <ul class="facet-list">
                <?php foreach ($books as $book) : ?>
                    <li>
                        <a href="<?= $this->url('book', ['slug' => $book['slug']]) ?>">
                            <?= $this->e($book['title']) ?>
                        </a>
                        <span class="tree__count"><?= (int) $book['downloads'] ?></span>
                    </li>
                <?php endforeach ?>
                <?php if ($books === []) : ?>
                    <li class="muted">No books yet.</li>
                <?php endif ?>
            </ul>
        </div>
    </div>
</section>
