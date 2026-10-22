<?php
/**
 * @var ?array $product @var array $images @var array $variants @var array $categories
 * @var bool $canInventory @var int $maxImages @var ?array $flash
 */
$bool = static fn($v): bool => $v === true || $v === 't' || $v === '1' || $v === 1;
$isEdit = $product !== null;
$hasState = old('name') !== '' || old('price') !== '' || field_error('_section') !== null
    || field_error('name') !== null || field_error('price') !== null || field_error('slug') !== null || field_error('category_id') !== null;
$v = static fn(string $k, string $d = ''): string => $hasState ? old($k, $d) : ($isEdit ? (string) ($product[$k] ?? $d) : $d);
$flag = static fn(string $k): bool => $hasState ? old($k) === '1' : ($isEdit && $bool($product[$k] ?? false));
$action = $isEdit ? 'admin/products/' . (int) $product['id'] : 'admin/products';
$archived = $isEdit && $product['status'] === 'archived';
$slotsLeft = max(0, $maxImages - count($images));
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(url('admin/products')) ?>">Products</a>
    <span aria-current="page"><?= $isEdit ? e($product['name']) : 'New product' ?></span>
</nav>
<header class="admin-head">
    <h1><?= $isEdit ? 'Edit product' : 'New product' ?></h1>
    <?php if ($isEdit): ?>
        <div class="admin-head__meta">
            <?php component('status-badge', ['status' => $product['status']]); ?>
            <?php if ($product['status'] === 'active'): ?><a class="text-link" href="<?= e(url('product/' . $product['slug'])) ?>" target="_blank" rel="noopener">View in shop</a><?php endif; ?>
        </div>
    <?php endif; ?>
</header>
<?php component('flash-message', ['flash' => $flash]); ?>

<section class="admin-panel" aria-labelledby="details-title">
    <h2 id="details-title">Details</h2>
    <form class="form-stack" method="post" action="<?= e(url($action)) ?>" novalidate>
        <?= csrf_field() ?>
        <?php component('form-field', ['name' => 'name', 'label' => 'Name', 'value' => $v('name'), 'error' => field_error('name'), 'maxlength' => 160]); ?>
        <?php component('form-field', ['name' => 'slug', 'label' => 'Slug', 'value' => $v('slug'), 'error' => field_error('slug'), 'required' => false, 'maxlength' => 180, 'hint' => 'Leave empty to generate from the name. Changing it changes the product URL.']); ?>
        <div class="field field--form">
            <label for="f-category-id">Category</label>
            <select id="f-category-id" name="category_id" required <?= field_error('category_id') ? 'aria-invalid="true"' : '' ?>>
                <option value="">Choose a category</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $v('category_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $bool($c['is_active']) ? '' : ' (inactive)' ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (field_error('category_id')): ?><p class="field__error" role="alert"><?= e(field_error('category_id')) ?></p><?php endif; ?>
        </div>
        <div class="form-row">
            <?php component('form-field', ['name' => 'price', 'label' => 'Price (Rp)', 'value' => $hasState ? old('price') : ($isEdit ? number_format((float) $product['price'], 0, '', '') : ''), 'error' => field_error('price'), 'inputmode' => 'decimal', 'maxlength' => 13, 'hint' => 'Digits only, e.g. 149000']); ?>
            <?php component('form-field', ['name' => 'sale_price', 'label' => 'Sale price (Rp)', 'value' => $hasState ? old('sale_price') : ($isEdit && $product['sale_price'] !== null ? number_format((float) $product['sale_price'], 0, '', '') : ''), 'error' => field_error('sale_price'), 'required' => false, 'inputmode' => 'decimal', 'maxlength' => 13, 'hint' => 'Optional. Must be lower than the price.']); ?>
        </div>
        <?php component('form-field', ['name' => 'short_description', 'label' => 'Short description', 'value' => $v('short_description'), 'error' => field_error('short_description'), 'required' => false, 'maxlength' => 255]); ?>
        <div class="field field--form">
            <label for="f-description">Description <span class="field__optional">(optional)</span></label>
            <textarea id="f-description" name="description" rows="6" maxlength="5000" <?= field_error('description') ? 'aria-invalid="true"' : '' ?>><?= e($v('description')) ?></textarea>
            <?php if (field_error('description')): ?><p class="field__error" role="alert"><?= e(field_error('description')) ?></p><?php endif; ?>
        </div>
        <?php if ($isEdit && !$archived): ?>
            <div class="field field--form">
                <label for="f-status">Status</label>
                <select id="f-status" name="status" <?= field_error('status') ? 'aria-invalid="true"' : '' ?>>
                    <option value="draft" <?= $v('status', 'draft') === 'draft' ? 'selected' : '' ?>>Draft (hidden from the shop)</option>
                    <option value="active" <?= $v('status') === 'active' ? 'selected' : '' ?>>Active (visible in the shop)</option>
                </select>
                <?php if (field_error('status')): ?><p class="field__error" role="alert"><?= e(field_error('status')) ?></p><?php endif; ?>
            </div>
        <?php elseif (!$isEdit): ?>
            <p class="field__hint">New products start as a draft. Add variants and images, then publish.</p>
        <?php endif; ?>
        <fieldset class="check-group">
            <legend>Highlights</legend>
            <label class="check"><input type="checkbox" name="is_new_arrival" value="1" <?= $flag('is_new_arrival') ? 'checked' : '' ?>><span>New arrival</span></label>
            <label class="check"><input type="checkbox" name="is_best_seller" value="1" <?= $flag('is_best_seller') ? 'checked' : '' ?>><span>Best seller</span></label>
            <label class="check"><input type="checkbox" name="is_featured" value="1" <?= $flag('is_featured') ? 'checked' : '' ?>><span>Featured</span></label>
        </fieldset>
        <div><button class="btn btn--dark" type="submit"><?= $isEdit ? 'Save changes' : 'Create product' ?></button></div>
    </form>
</section>

<?php if ($isEdit): ?>
<?php $pid = (int) $product['id']; ?>
<section class="admin-panel" id="variants" aria-labelledby="variants-title">
    <h2 id="variants-title">Variants and stock</h2>
    <?php if (!$variants): ?><p class="field__hint">No variants yet. Customers can only buy products that have at least one active variant in stock.</p><?php endif; ?>
    <?php foreach ($variants as $var): ?>
        <div class="variant-card<?= $bool($var['is_active']) ? '' : ' is-inactive' ?>">
            <form class="variant-form" method="post" action="<?= e(url('admin/products/' . $pid . '/variants/' . (int) $var['id'])) ?>">
                <?= csrf_field() ?>
                <div class="field"><label for="v<?= (int) $var['id'] ?>-size">Size</label><input id="v<?= (int) $var['id'] ?>-size" name="size" value="<?= e($var['size']) ?>" maxlength="20" required></div>
                <div class="field"><label for="v<?= (int) $var['id'] ?>-color">Color</label><input id="v<?= (int) $var['id'] ?>-color" name="color" value="<?= e($var['color']) ?>" maxlength="40" required></div>
                <div class="field"><label for="v<?= (int) $var['id'] ?>-sku">SKU</label><input id="v<?= (int) $var['id'] ?>-sku" name="sku" value="<?= e($var['sku']) ?>" maxlength="60"></div>
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= $bool($var['is_active']) ? 'checked' : '' ?>><span>Active</span></label>
                <button class="btn btn--ghost btn--sm" type="submit">Save variant</button>
            </form>
            <?php if ($canInventory): ?>
                <form class="variant-stock" method="post" action="<?= e(url('admin/products/' . $pid . '/variants/' . (int) $var['id'] . '/stock')) ?>">
                    <?= csrf_field() ?>
                    <p class="variant-stock__now">Stock now: <strong><?= (int) $var['stock'] ?></strong></p>
                    <div class="field"><label for="v<?= (int) $var['id'] ?>-stock">Set stock to</label><input id="v<?= (int) $var['id'] ?>-stock" name="new_stock" type="number" min="0" max="100000" step="1" value="<?= (int) $var['stock'] ?>" inputmode="numeric" required></div>
                    <div class="field"><label for="v<?= (int) $var['id'] ?>-note">Note</label><input id="v<?= (int) $var['id'] ?>-note" name="note" maxlength="255" placeholder="Optional reason"></div>
                    <button class="btn btn--dark btn--sm" type="submit">Update stock</button>
                </form>
            <?php else: ?>
                <p class="variant-stock__now">Stock: <strong><?= (int) $var['stock'] ?></strong></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <h3>Add variant</h3>
    <form class="variant-form variant-form--new" method="post" action="<?= e(url('admin/products/' . $pid . '/variants')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="nv-size">Size</label><input id="nv-size" name="size" maxlength="20" required placeholder="M"></div>
        <div class="field"><label for="nv-color">Color</label><input id="nv-color" name="color" maxlength="40" required placeholder="Black"></div>
        <div class="field"><label for="nv-sku">SKU (optional)</label><input id="nv-sku" name="sku" maxlength="60" placeholder="Auto-generated"></div>
        <div class="field"><label for="nv-stock">Initial stock</label><input id="nv-stock" name="stock" type="number" min="0" max="100000" value="0" <?= $canInventory ? '' : 'disabled' ?>></div>
        <label class="check"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
        <button class="btn btn--dark btn--sm" type="submit">Add variant</button>
    </form>
</section>

<section class="admin-panel" id="images" aria-labelledby="images-title">
    <h2 id="images-title">Images <small><?= count($images) ?>/<?= (int) $maxImages ?></small></h2>
    <?php if ($images): ?>
        <ul class="image-grid">
            <?php foreach ($images as $i => $img): ?>
                <li class="image-tile">
                    <img src="<?= e(image_url($img['path'])) ?>" alt="<?= e($img['alt_text'] ?? '') ?>" loading="lazy">
                    <?php if ($i === 0): ?><span class="image-tile__badge">Primary</span><?php endif; ?>
                    <div class="image-tile__actions">
                        <?php if ($i !== 0): ?>
                            <form method="post" action="<?= e(url('admin/products/' . $pid . '/images/' . (int) $img['id'] . '/primary')) ?>"><?= csrf_field() ?><button class="text-button" type="submit">Make primary</button></form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('admin/products/' . $pid . '/images/' . (int) $img['id'] . '/delete')) ?>" data-confirm="Delete this image?"><?= csrf_field() ?><button class="text-button" type="submit">Delete</button></form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?><p class="field__hint">No images yet. The shop shows a neutral placeholder.</p><?php endif; ?>

    <?php if ($slotsLeft > 0): ?>
        <form class="form-stack" method="post" action="<?= e(url('admin/products/' . $pid . '/images')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field field--form">
                <label for="f-images">Upload images (<?= $slotsLeft ?> slot<?= $slotsLeft === 1 ? '' : 's' ?> left)</label>
                <input id="f-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required>
                <p class="field__hint">JPG, PNG, or WebP; up to 2 MB each. The first image is the primary one.</p>
            </div>
            <?php component('form-field', ['name' => 'alt_text', 'label' => 'Alt text', 'required' => false, 'maxlength' => 160, 'hint' => 'Optional. Defaults to the product name.']); ?>
            <div><button class="btn btn--dark btn--sm" type="submit">Upload</button></div>
        </form>
    <?php else: ?><p class="field__hint">Maximum number of images reached. Delete one to upload another.</p><?php endif; ?>
</section>

<section class="admin-panel admin-panel--danger" aria-labelledby="danger-title">
    <h2 id="danger-title">Archive or delete</h2>
    <?php if ($archived): ?>
        <p>This product is archived: hidden from the shop but kept for past orders.</p>
        <form method="post" action="<?= e(url('admin/products/' . $pid . '/restore')) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit">Restore as draft</button></form>
    <?php else: ?>
        <p>Archiving hides the product from the shop and keeps all order and stock history.</p>
        <form method="post" action="<?= e(url('admin/products/' . $pid . '/archive')) ?>" data-confirm="Archive this product? It will disappear from the shop."><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit">Archive product</button></form>
    <?php endif; ?>
    <p class="field__hint">Permanent deletion is only possible if the product was never ordered and has no stock adjustment history.</p>
    <form method="post" action="<?= e(url('admin/products/' . $pid . '/delete')) ?>" data-confirm="Delete this product permanently? This cannot be undone."><?= csrf_field() ?><button class="btn btn--accent btn--sm" type="submit">Delete permanently</button></form>
</section>
<?php endif; ?>