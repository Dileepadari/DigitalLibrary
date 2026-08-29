<?php
/**
 * @var App\Core\View $this
 * @var array<string, int> $usage
 * @var array<string, int> $counts
 * @var list<array{id: int, storage_path: string, sha256: string, status: string}> $missing
 * @var string|null $renderer
 * @var string $root
 * @var int $graceDays
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Storage';
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
?>
<section class="stack-wide">
    <h1>Storage</h1>
    <p class="muted">Everything lives under <code><?= $this->e($root) ?></code>, outside the webroot.</p>

    <dl class="status-grid">
        <?php foreach ($usage as $directory => $bytes) : ?>
            <div class="status-item">
                <dt><?= $this->e(ucfirst($directory)) ?></dt>
                <dd class="status-item__number"><?= $this->e($size($bytes)) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>

    <div class="panel">
        <h2>Files on record</h2>
        <dl class="status-grid">
            <?php foreach ($counts as $status => $count) : ?>
                <div class="status-item">
                    <dt><?= $this->e(ucfirst($status)) ?></dt>
                    <dd class="status-item__number"><?= (int) $count ?></dd>
                </div>
            <?php endforeach ?>
        </dl>

        <p class="muted">
            Rejected uploads are deleted <?= (int) $graceDays ?> days after the decision by
            <code>php cli/console.php quarantine:prune</code>, which is worth putting on a
            timer along with <code>auth:prune</code>.
        </p>
    </div>

    <div class="panel">
        <h2>Missing files</h2>

        <?php if ($missing === []) : ?>
            <p class="muted">
                Every file on record is where it should be. Run
                <code>php cli/console.php storage:verify</code> to check the contents too.
            </p>
        <?php else : ?>
            <p class="banner banner--error">
                <?= count($missing) ?> file(s) are on record but not on disk. A restore
                is probably in order.
            </p>
            <ul class="facet-list">
                <?php foreach ($missing as $file) : ?>
                    <li><code><?= $this->e($file['storage_path']) ?></code> (<?= $this->e($file['status']) ?>)</li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>

    <div class="panel">
        <h2>Covers</h2>
        <?php if ($renderer === null) : ?>
            <p class="banner banner--warn">
                No PDF renderer on this host, so uploads arrive without covers.
                Install poppler-utils, Ghostscript, or the Imagick extension, then run
                <code>php cli/console.php covers:generate</code>.
            </p>
        <?php else : ?>
            <p class="muted">
                Covers are rendered with <strong><?= $this->e($renderer) ?></strong>.
                <code>php cli/console.php covers:generate</code> fills in any that are missing.
            </p>
        <?php endif ?>
    </div>
</section>
