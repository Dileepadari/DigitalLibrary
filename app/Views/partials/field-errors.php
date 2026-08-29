<?php
/**
 * The errors for one field, with an id the input can point at through
 * aria-describedby so a screen reader reads the reason with the field.
 *
 * @var App\Core\View $this
 * @var string $field
 */
?>
<?php foreach ($this->errors($field) as $index => $message) : ?>
    <p class="field__error" id="<?= $this->e($this->errorId($field)) ?><?= $index > 0 ? '-' . $index : '' ?>">
        <?= $this->e($message) ?>
    </p>
<?php endforeach ?>
