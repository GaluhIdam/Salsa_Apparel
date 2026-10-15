<?php
/** @var array $product @var array $colors @var array $sizes @var int $totalStock @var array $related */
$onSale = $product['sale_price'] !== null;
$price = $onSale ? $product['sale_price'] : $product['price'];
$percent = $onSale ? (int) round((1 - (float) $product['sale_price'] / (float) $product['price']) * 100) : 0;
$images = $product['images'] ?: [['path' => null, 'alt_text' => $product['name']]];
$variantData = array_map(static fn(array $v): array => [
    'id' => $v['id'], 'sku' => $v['sku'], 'size' => $v['size'], 'color' => $v['color'], 'stock' => $v['stock'],
], $product['variants']);
$defaultVariant = null;
foreach ($product['variants'] as $v) {
    if ($v['stock'] > 0) { $defaultVariant = $v; break; }
}
$defaultVariant ??= $product['variants'][0] ?? null;
?>
<section class="section section--catalog">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <a href="<?= e(url('shop')) ?>">Shop</a>
            <a href="<?= e(url('category/' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a>
            <span aria-current="page"><?= e($product['name']) ?></span>
        </nav>

        <?php component('flash-message', ['flash' => flash_get('cart')]); ?>
        <div class="pdp" data-product data-variants="<?= e(json_encode($variantData, JSON_THROW_ON_ERROR)) ?>">
            <div class="pdp__gallery" data-gallery>
                <div class="pdp__main">
                    <img src="<?= e(image_url($images[0]['path'])) ?>" alt="<?= e($images[0]['alt_text'] ?: $product['name']) ?>" data-gallery-main>
                </div>
                <?php if (count($images) > 1): ?>
                    <ul class="pdp__thumbs">
                        <?php foreach ($images as $i => $img): ?>
                            <li>
                                <button type="button" class="pdp__thumb" aria-label="Show image <?= $i + 1 ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"
                                        data-gallery-thumb data-src="<?= e(image_url($img['path'])) ?>" data-alt="<?= e($img['alt_text'] ?: $product['name']) ?>">
                                    <img src="<?= e(image_url($img['path'])) ?>" alt="" loading="lazy">
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="pdp__info">
                <p class="pdp__category"><a href="<?= e(url('category/' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a></p>
                <h1 class="pdp__name"><?= e($product['name']) ?></h1>
                <p class="pdp__price">
                    <span><?= e(rupiah($price)) ?></span>
                    <?php if ($onSale): ?><s><?= e(rupiah($product['price'])) ?></s><span class="pdp__save">Save <?= $percent ?>%</span><?php endif; ?>
                </p>
                <?php if ($product['short_description']): ?><p class="pdp__lead"><?= e($product['short_description']) ?></p><?php endif; ?>

                <?php if ($product['variants']): ?>
                    <?php if (count($colors) > 0): ?>
                        <fieldset class="option-group">
                            <legend>Color: <span data-selected-color></span></legend>
                            <div class="chip-row">
                                <?php foreach ($colors as $color): ?>
                                    <button type="button" class="chip" data-color="<?= e($color) ?>" aria-pressed="false"><?= e($color) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>
                    <?php if (count($sizes) > 0): ?>
                        <fieldset class="option-group">
                            <legend>Size: <span data-selected-size></span></legend>
                            <div class="chip-row">
                                <?php foreach ($sizes as $size): ?>
                                    <button type="button" class="chip" data-size="<?= e($size) ?>" aria-pressed="false"><?= e($size) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>
                    <div class="pdp__status" aria-live="polite">
                        <p class="stock stock--<?= $totalStock <= 0 ? 'out' : 'in' ?>" data-stock><?= $totalStock <= 0 ? 'Out of stock' : 'In stock' ?></p>
                        <p class="pdp__sku">SKU: <span data-sku>—</span></p>
                    </div>
                <?php else: ?>
                    <p class="stock stock--out">Currently unavailable</p>
                <?php endif; ?>

                <?php if ($product['variants']): ?>
                    <form class="pdp__buy" method="post" action="<?= e(url('cart/add')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" name="product_slug" value="<?= e($product['slug']) ?>">
                        <input type="hidden" name="variant_id" value="<?= (int) $defaultVariant['id'] ?>" data-variant-input>
                        <div class="field pdp__qty">
                            <label for="pdp-qty">Quantity</label>
                            <input id="pdp-qty" type="number" name="quantity" value="1" min="1" max="<?= max(1, min((int) $defaultVariant['stock'], 20)) ?>" inputmode="numeric" data-qty-input <?= $totalStock <= 0 ? 'disabled' : '' ?>>
                        </div>
                        <button class="btn btn--accent pdp__add" type="submit" data-add-button <?= (int) $defaultVariant['stock'] <= 0 ? 'disabled' : '' ?>><?= (int) $defaultVariant['stock'] <= 0 ? 'Sold out' : 'Add to Bag' ?></button>
                    </form>
                <?php endif; ?>

                <?php component('wish-button', ['productId' => (int) $product['id'], 'name' => $product['name'], 'saved' => wishlist_has((int) $product['id']), 'withLabel' => true]); ?>

                <?php if ($product['description']): ?>
                    <div class="pdp__desc">
                        <h2>Description</h2>
                        <p><?= nl2br(e($product['description'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if ($related): ?>
<section class="section section--surface" aria-labelledby="related-title">
    <div class="container">
        <h2 id="related-title" class="section-title">You may also like</h2>
        <div class="product-grid product-grid--related">
            <?php foreach ($related as $item): ?>
                <?php component('product-card', ['product' => $item, 'showStock' => true]); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
