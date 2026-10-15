<?php /** @var array $item */ ?>
<li class="wish-item<?= $item['available'] ? '' : ' is-unavailable' ?>">
    <?php if ($item['available']): ?>
        <?php component('product-card', ['product' => $item, 'showStock' => true, 'showWish' => false]); ?>
        <div class="wish-item__actions">
            <?php if ($item['movable']): ?>
                <form method="post" action="<?= e(url('wishlist/move')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                    <button class="btn btn--dark btn--sm" type="submit">Move to Bag</button>
                </form>
            <?php elseif ($item['sold_out']): ?>
                <button class="btn btn--dark btn--sm" type="button" disabled>Sold out</button>
            <?php else: ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('product/' . $item['slug'])) ?>">Select options</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('wishlist/remove')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="return" value="/wishlist">
                <button class="text-button" type="submit" aria-label="Remove <?= e($item['name']) ?> from wishlist">Remove</button>
            </form>
        </div>
    <?php else: ?>
        <div class="wish-item__gone">
            <p class="wish-item__gone-name"><?= e($item['name']) ?></p>
            <p class="wish-item__gone-note">This product is no longer available.</p>
            <form method="post" action="<?= e(url('wishlist/remove')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="return" value="/wishlist">
                <button class="text-button" type="submit" aria-label="Remove <?= e($item['name']) ?> from wishlist">Remove</button>
            </form>
        </div>
    <?php endif; ?>
</li>
