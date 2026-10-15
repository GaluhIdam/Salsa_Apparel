<?php
/**
 * @var string $name  @var string $label
 * Opsional: $type (text), $value, $error, $autocomplete, $required (true), $hint, $maxlength, $inputmode, $id
 */
$type = $type ?? 'text';
$value = $value ?? '';
$error = $error ?? null;
$required = $required ?? true;
$id = $id ?? ('f-' . str_replace('_', '-', $name));
$describedBy = trim(($error ? $id . '-error ' : '') . (!empty($hint) ? $id . '-hint' : ''));
?>
<div class="field field--form">
    <label for="<?= e($id) ?>"><?= e($label) ?><?= $required ? '' : ' <span class="field__optional">(optional)</span>' ?></label>
    <input id="<?= e($id) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>"
           <?= $type === 'password' ? '' : 'value="' . e($value) . '"' ?>
           <?= !empty($autocomplete) ? 'autocomplete="' . e($autocomplete) . '"' : '' ?>
           <?= !empty($maxlength) ? 'maxlength="' . (int) $maxlength . '"' : '' ?>
           <?= !empty($inputmode) ? 'inputmode="' . e($inputmode) . '"' : '' ?>
           <?= $required ? 'required' : '' ?>
           <?= $error ? 'aria-invalid="true"' : '' ?>
           <?= $describedBy !== '' ? 'aria-describedby="' . e($describedBy) . '"' : '' ?>>
    <?php if (!empty($hint)): ?><p class="field__hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="field__error" id="<?= e($id) ?>-error" role="alert"><?= e($error) ?></p><?php endif; ?>
</div>
