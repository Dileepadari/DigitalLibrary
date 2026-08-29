<?php
/**
 * @var App\Core\View $this
 * @var array{rows: list<App\Models\User>, total: int, page: int, pages: int} $results
 * @var array<string, int> $counts
 * @var array{search: string, role: string, status: string} $filters
 */

use App\Support\Role;
use App\Support\UserStatus;

$this->layout('layouts/app');
$this->section('title');
echo 'Users';
$this->end();

$me = $this->auth->user();
$query = static fn (array $extra): string => '?' . http_build_query(array_merge($filters, $extra));
?>
<section class="stack-wide">
    <h1>Users</h1>

    <?php $this->include('partials/admin-nav') ?>

    <p class="muted">
        <?= (int) $results['total'] ?> accounts:
        <?php foreach (Role::all() as $role) : ?>
            <?= (int) ($counts[$role->value] ?? 0) ?> <?= $this->e(mb_strtolower($role->label())) ?><?php
            endforeach ?>.
    </p>

    <form method="get" action="<?= $this->url('admin.users') ?>" class="filter-bar">
        <input type="search" name="search" value="<?= $this->e($filters['search']) ?>"
               placeholder="Name, username or email" aria-label="Search users">

        <select name="role" aria-label="Filter by role">
            <option value="">Any role</option>
            <?php foreach (Role::all() as $role) : ?>
                <option value="<?= $this->e($role->value) ?>" <?= $filters['role'] === $role->value ? 'selected' : '' ?>>
                    <?= $this->e($role->label()) ?>
                </option>
            <?php endforeach ?>
        </select>

        <select name="status" aria-label="Filter by status">
            <option value="">Any status</option>
            <?php foreach (UserStatus::cases() as $status) : ?>
                <option value="<?= $this->e($status->value) ?>" <?= $filters['status'] === $status->value ? 'selected' : '' ?>>
                    <?= $this->e($status->label()) ?>
                </option>
            <?php endforeach ?>
        </select>

        <button type="submit" class="button button--small">Filter</button>
    </form>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Account</th>
                    <th scope="col">Joined</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results['rows'] as $row) : ?>
                <tr>
                    <td>
                        <a href="<?= $this->url('profile', ['username' => $row->username]) ?>">
                            <?= $this->e($row->name) ?>
                        </a>
                        <span class="status-item__detail">
                            @<?= $this->e($row->username) ?> &middot; <?= $this->e($row->email) ?>
                            <?= $row->isVerified() ? '' : ' &middot; unconfirmed' ?>
                        </span>
                    </td>
                    <td><?= $this->e($this->date($row->createdAt, 'j M Y')) ?></td>
                    <td>
                        <?php if ($me !== null && $me->id === $row->id) : ?>
                            <span class="tag tag--role"><?= $this->e($row->role->label()) ?></span>
                            <span class="status-item__detail">that is you</span>
                        <?php else : ?>
                            <form method="post" action="<?= $this->url('admin.users.role', ['id' => $row->id]) ?>"
                                  class="inline-form">
                                <?= $this->csrf->field() ?>
                                <select name="role" aria-label="Role for <?= $this->e($row->username) ?>">
                                    <?php foreach (Role::all() as $role) : ?>
                                        <option value="<?= $this->e($role->value) ?>"
                                            <?= $row->role === $role ? 'selected' : '' ?>>
                                            <?= $this->e($role->label()) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                                <button type="submit" class="button button--small">Set</button>
                            </form>
                        <?php endif ?>
                    </td>
                    <td>
                        <?php if ($me !== null && $me->id === $row->id) : ?>
                            <span class="tag"><?= $this->e($row->status->label()) ?></span>
                        <?php else : ?>
                            <form method="post" action="<?= $this->url('admin.users.status', ['id' => $row->id]) ?>"
                                  class="inline-form">
                                <?= $this->csrf->field() ?>
                                <select name="status" aria-label="Status for <?= $this->e($row->username) ?>">
                                    <?php foreach (UserStatus::cases() as $status) : ?>
                                        <option value="<?= $this->e($status->value) ?>"
                                            <?= $row->status === $status ? 'selected' : '' ?>>
                                            <?= $this->e($status->label()) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                                <input type="text" name="reason" placeholder="Reason" maxlength="255"
                                       aria-label="Reason" size="12">
                                <input type="number" name="days" placeholder="Days" min="0" max="3650"
                                       aria-label="Days" size="4">
                                <button type="submit" class="button button--small">Set</button>
                            </form>
                            <?php if ($row->statusReason !== null) : ?>
                                <span class="status-item__detail"><?= $this->e($row->statusReason) ?></span>
                            <?php endif ?>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>

            <?php if ($results['rows'] === []) : ?>
                <tr><td colspan="4" class="muted">No accounts match those filters.</td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php if ($results['pages'] > 1) : ?>
        <nav class="pagination" aria-label="Pages">
            <?php if ($results['page'] > 1) : ?>
                <a href="<?= $this->e($query(['page' => $results['page'] - 1])) ?>">Previous</a>
            <?php endif ?>
            <span>Page <?= (int) $results['page'] ?> of <?= (int) $results['pages'] ?></span>
            <?php if ($results['page'] < $results['pages']) : ?>
                <a href="<?= $this->e($query(['page' => $results['page'] + 1])) ?>">Next</a>
            <?php endif ?>
        </nav>
    <?php endif ?>

    <p class="muted">
        Role and status changes are written to the audit log. Someone who applied
        to be a librarian is decided on the applications screen; this page is for
        promoting, banning and muting directly.
    </p>
</section>
