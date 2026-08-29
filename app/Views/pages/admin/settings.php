<?php
/**
 * @var App\Core\View $this
 * @var array<string, array{label: string, type: string, hint: string, options?: array<string, string>}> $fields
 * @var array<string, mixed> $values
 */
$this->layout('layouts/app');
$this->section('title');
echo 'Settings';
$this->end();
?>
<section class="stack-wide">
    <h1>Site settings</h1>
    <p class="muted">
        These take effect immediately for everyone. Every change is written to the
        audit log with what it was before.
    </p>

    <form method="post" action="<?= $this->url('admin.settings') ?>" class="stack panel">
        <?= $this->csrf->field() ?>

        <?php foreach ($fields as $key => $field) : ?>
            <?php $name = str_replace('.', '_', $key); ?>
            <?php $value = $values[$key] ?? null; ?>

            <div class="field">
                <?php if ($field['type'] === 'bool') : ?>
                    <label class="checkbox">
                        <input type="checkbox" name="<?= $this->e($name) ?>" value="1"
                            <?= $value === true ? 'checked' : '' ?>>
                        <?= $this->e($field['label']) ?>
                    </label>
                <?php elseif ($field['type'] === 'choice') : ?>
                    <label for="<?= $this->e($name) ?>"><?= $this->e($field['label']) ?></label>
                    <select id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>">
                        <?php foreach ($field['options'] ?? [] as $option => $label) : ?>
                            <option value="<?= $this->e($option) ?>" <?= $value === $option ? 'selected' : '' ?>>
                                <?= $this->e($label) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                <?php else : ?>
                    <label for="<?= $this->e($name) ?>"><?= $this->e($field['label']) ?></label>
                    <input type="<?= $field['type'] === 'int' ? 'number' : 'text' ?>"
                           id="<?= $this->e($name) ?>" name="<?= $this->e($name) ?>"
                           value="<?= $this->e(is_scalar($value) ? (string) $value : '') ?>">
                <?php endif ?>

                <p class="field__hint"><code><?= $this->e($key) ?></code> &middot; <?= $this->e($field['hint']) ?></p>
            </div>
        <?php endforeach ?>

        <button type="submit" class="button">Save the settings</button>
    </form>
</section>
