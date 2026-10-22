<?php
/** @var ?array $category @var ?array $flash */
$bool = static fn($v): bool => $v === true || $v === 't' || $v === '1' || $v === 1;
$isEdit = $category !== null;
$hasState = old('name') !== '' || field_error('_section') !== null || field_error('name') !== null || field_error('slug') !== null;
$v = static fn(string $k, string $d = ''): string => $hasState ? old($k, $d) : ($isEdit ? (string) ($category[$k] ?? $d) : $d);
$active = $hasState ? old('is_active') === '1' : (!$isEdit || $bool($category['is_active']));
$action = $isEdit ? 'admin/categories/' . (int) $category['id'] : 'admin/categories';
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(url('admin/categories')) ?>">Categories</a>
    <span aria-current="page"><?= $isEdit ? e($category['name']) : 'New category' ?></span>
</nav>
<header class="admin-head"><h1><?= $isEdit ? 'Edit category' : 'New category' ?></h1></header>
<?php component('flash-message', ['flash' => $flash]); ?>
<section class="admin-panel">
    <form class="form-stack" method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <?php component('form-field', ['name' => 'name', 'label' => 'Name', 'value' => $v('name'), 'error' => field_error('name'), 'maxlength' => 80]); ?>
        <?php component('form-field', ['name' => 'slug', 'label' => 'Slug', 'value' => $v('slug'), 'error' => field_error('slug'), 'required' => false, 'maxlength' => 100, 'hint' => 'Leave empty to generate from the name.']); ?>
        <div class="field field--form">
            <label for="f-description">Description <span class="field__optional">(optional)</span></label>
            <textarea id="f-description" name="description" rows="3" maxlength="500"><?= e($v('description')) ?></textarea>
            <?php if (field_error('description')): ?><p class="field__error" role="alert"><?= e(field_error('description')) ?></p><?php endif; ?>
        </div>
        <?php component('form-field', ['name' => 'sort_order', 'label' => 'Sort order', 'value' => $v('sort_order', '0'), 'error' => field_error('sort_order'), 'required' => false, 'inputmode' => 'numeric', 'maxlength' => 4]); ?>
        <div class="field field--form">
            <label for="f-image">Image <span class="field__optional">(optional)</span></label>
            <?php if ($isEdit && $category['image_path']): ?><img class="category-thumb" src="<?= e(image_url($category['image_path'])) ?>" alt="" width="80" height="100"><?php endif; ?>
            <input id="f-image" type="file" name="image" accept="image/jpeg,image/png,image/webp">
            <p class="field__hint">JPG, PNG, or WebP up to 2 MB. Uploading replaces the current image.</p>
            <?php if (field_error('image')): ?><p class="field__error" role="alert"><?= e(field_error('image')) ?></p><?php endif; ?>
        </div>
        <label class="check"><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>><span>Active (inactive categories and their products are hidden from the shop)</span></label>
        <div><button class="btn btn--dark" type="submit"><?= $isEdit ? 'Save changes' : 'Create category' ?></button></div>
    </form>
</section>
