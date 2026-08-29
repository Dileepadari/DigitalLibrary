<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $results
 * @var array{actor: string, action: string, subject: string} $filters
 * @var list<string> $actions
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Audit log';
$this->end();
?>
<section class="stack-wide">
    <h1>Audit log</h1>

    <?php $this->include('partials/admin-nav') ?>
    <p class="muted">
        <?= (int) $results['total'] ?> entries. Nothing here is ever edited or
        deleted by the application.
    </p>

    <form method="get" action="<?= $this->url('admin.audit') ?>" class="filter-bar">
        <input type="search" name="actor" value="<?= $this->e($filters['actor']) ?>"
               placeholder="Username" aria-label="Actor">

        <select name="action" aria-label="Action">
            <option value="">Any action</option>
            <?php foreach ($actions as $action) : ?>
                <option value="<?= $this->e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>>
                    <?= $this->e($action) ?>
                </option>
            <?php endforeach ?>
        </select>

        <input type="text" name="subject" value="<?= $this->e($filters['subject']) ?>"
               placeholder="Subject type" size="12" aria-label="Subject type">

        <button type="submit" class="button button--small">Filter</button>
        <a class="button button--small button--quiet"
           href="<?= $this->url('admin.audit.export') ?>?<?= $this->e(http_build_query($filters)) ?>">
            Download as CSV
        </a>
    </form>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">What</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Change</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results['rows'] as $row) : ?>
                <tr>
                    <td><?= $this->e($this->date((string) $row['created_at'], 'j M Y, H:i')) ?></td>
                    <td>
                        <?php if (($row['actor_username'] ?? null) !== null) : ?>
                            <a href="<?= $this->url('profile', ['username' => (string) $row['actor_username']]) ?>">
                                <?= $this->e((string) $row['actor_username']) ?>
                            </a>
                        <?php else : ?>
                            <span class="muted">console</span>
                        <?php endif ?>
                    </td>
                    <td><code><?= $this->e((string) $row['action']) ?></code></td>
                    <td>
                        <?= $this->e((string) ($row['subject_type'] ?? '')) ?>
                        <?= $row['subject_id'] !== null ? '#' . (int) $row['subject_id'] : '' ?>
                    </td>
                    <td class="audit-change">
                        <?php if (($row['before_state'] ?? null) !== null) : ?>
                            <span class="status-item__detail">was <?= $this->e((string) $row['before_state']) ?></span>
                        <?php endif ?>
                        <?php if (($row['after_state'] ?? null) !== null) : ?>
                            <span class="status-item__detail"><?= $this->e((string) $row['after_state']) ?></span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>

            <?php if ($results['rows'] === []) : ?>
                <tr><td colspan="5" class="muted">Nothing matches those filters.</td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php $this->include('partials/pagination', [
        'results' => $results,
        'filters' => $filters,
        'path'    => '/admin/audit',
    ]) ?>
</section>
