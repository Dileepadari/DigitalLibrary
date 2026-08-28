<?php
/**
 * @var App\Core\View $this
 * @var string $field
 */
?>
<?php foreach ($this->errors($field) as $message) : ?>
    <p class="field__error"><?= $this->e($message) ?></p>
<?php endforeach ?>
