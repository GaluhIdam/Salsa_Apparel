<?php
/** @var int $productId @var string $name @var bool $saved  Opsional: $withLabel */
$withLabel = !empty($withLabel);
$returnTo = (string) ($_SERVER['REQUEST_URI'] ?? '/wishlist');
$label = ($saved ? 'Remove ' : 'Add ') . $name . ($saved ? ' from' : ' to') . ' wishlist';
?>
<form class="wish-form<?= $withLabel ? ' wish-form--label' : '' ?>" method="post"
      action="<?= e(url($saved ? 'wishlist/remove' : 'wishlist/add')) ?>"
      data-wish-form data-add-action="<?= e(url('wishlist/add')) ?>" data-remove-action="<?= e(url('wishlist/remove')) ?>" data-name="<?= e($name) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <button class="wish-btn<?= $saved ? ' is-saved' : '' ?>" type="submit" aria-pressed="<?= $saved ? 'true' : 'false' ?>" aria-label="<?= e($label) ?>" data-wish-button>
        <?= icon('heart', 18) ?>
        <?php if ($withLabel): ?><span data-wish-label><?= $saved ? 'Saved to Wishlist' : 'Save to Wishlist' ?></span><?php endif; ?>
    </button>
</form>
