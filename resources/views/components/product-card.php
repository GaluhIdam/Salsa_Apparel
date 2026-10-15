<?php
/** @var array $product */
$badge = !empty($product['is_best_seller']) ? 'Best Seller' : (!empty($product['is_new_arrival']) ? 'New Arrival' : null);
$onSale = $product['sale_price'] !== null;
$stock = isset($product['total_stock']) ? (int) $product['total_stock'] : null;
$soldOut = !empty($showStock) && $stock !== null && $stock <= 0;
if ($soldOut) { $badge = 'Sold Out'; }
?>
<?php $wishOn = !isset($showWish) || $showWish; ?>
<article class="product-card">
    <?php if ($wishOn): ?>
        <?php component('wish-button', ['productId' => (int) $product['id'], 'name' => $product['name'], 'saved' => wishlist_has((int) $product['id'])]); ?>
    <?php endif; ?>
    <a class="product-card__link" href="<?= e(url('product/' . $product['slug'])) ?>">
        <div class="product-card__media">
            <img src="<?= e(image_url($product['image_path'])) ?>" alt="<?= e($product['image_alt'] ?: $product['name']) ?>" loading="lazy">
            <?php if ($badge): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?>
        </div>
        <p class="product-card__price">
            <?= e(rupiah($onSale ? $product['sale_price'] : $product['price'])) ?>
            <?php if ($onSale): ?><s><?= e(rupiah($product['price'])) ?></s><?php endif; ?>
        </p>
        <h3 class="product-card__name"><?= e($product['name']) ?></h3>
        <p class="product-card__meta"><?= e($product['short_description'] ?: $product['category_name']) ?></p>
        <?php if (!empty($showStock) && $stock !== null): ?>
            <p class="stock stock--<?= $stock <= 0 ? 'out' : ($stock <= 5 ? 'low' : 'in') ?>">
                <?= $stock <= 0 ? 'Out of stock' : ($stock <= 5 ? 'Only ' . $stock . ' left' : 'In stock') ?>
            </p>
        <?php endif; ?>
    </a>
</article>
