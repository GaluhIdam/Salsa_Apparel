<?php
/** @var array $item */
$onSale = $item['unit_price'] < $item['regular_price'];
$canInc = $item['available'] && $item['quantity'] < $item['max_quantity'];
?>
<li class="cart-item<?= $item['available'] ? '' : ' is-unavailable' ?>">
    <a class="cart-item__media" href="<?= e(url('product/' . $item['slug'])) ?>">
        <img src="<?= e(image_url($item['image_path'])) ?>" alt="<?= e($item['image_alt'] ?: $item['name']) ?>" loading="lazy">
    </a>
    <div class="cart-item__body">
        <h3 class="cart-item__name"><a href="<?= e(url('product/' . $item['slug'])) ?>"><?= e($item['name']) ?></a></h3>
        <p class="cart-item__variant"><?= e($item['color']) ?> / <?= e($item['size']) ?> · SKU <?= e($item['sku']) ?></p>
        <p class="cart-item__price">
            <?= e(rupiah($item['unit_price'])) ?>
            <?php if ($onSale): ?><s><?= e(rupiah($item['regular_price'])) ?></s><?php endif; ?>
        </p>
        <?php if ($item['reason'] !== null): ?>
            <p class="cart-item__warn" role="alert"><?= e($item['reason']) ?> It is not included in your total.</p>
        <?php elseif ($item['quantity'] >= $item['max_quantity']): ?>
            <p class="cart-item__hint">Maximum available quantity reached.</p>
        <?php endif; ?>
    </div>
    <div class="cart-item__controls">
        <?php if ($item['available']): ?>
            <form class="qty-form" method="post" action="<?= e(url('cart/update')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
                <button class="qty-form__btn" type="submit" name="quantity" value="<?= $item['quantity'] - 1 ?>" aria-label="Decrease quantity of <?= e($item['name']) ?>" <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>><?= icon('minus', 16) ?></button>
                <span class="qty-form__value" aria-live="polite"><?= (int) $item['quantity'] ?></span>
                <button class="qty-form__btn" type="submit" name="quantity" value="<?= $item['quantity'] + 1 ?>" aria-label="Increase quantity of <?= e($item['name']) ?>" <?= $canInc ? '' : 'disabled' ?>><?= icon('plus', 16) ?></button>
            </form>
        <?php endif; ?>
        <p class="cart-item__total"><?= $item['available'] ? e(rupiah($item['line_total'])) : '—' ?></p>
        <form method="post" action="<?= e(url('cart/remove')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
            <button class="text-button" type="submit" aria-label="Remove <?= e($item['name']) ?> from your bag">Remove</button>
        </form>
    </div>
</li>
