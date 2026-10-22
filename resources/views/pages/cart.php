<?php
/** @var array $cart @var ?array $flash */
$items = $cart['items'];
?>
<section class="section section--catalog">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <span aria-current="page">Shopping Bag</span>
        </nav>
        <header class="catalog-head">
            <h1 class="section-title">Keranjang Belanja</h1>
            <?php if ($cart['units'] > 0): ?>
                <p class="catalog-head__intro"><?= (int) $cart['units'] ?> <?= $cart['units'] === 1 ? 'item' : 'items' ?></p>
            <?php endif; ?>
        </header>

        <?php component('flash-message', ['flash' => $flash]); ?>
        <?php foreach ($cart['notices'] as $notice): ?>
            <p class="flash flash--info" role="status"><?= e($notice) ?></p>
        <?php endforeach; ?>

        <?php if (!$items): ?>
            <div class="empty-state">
                <h2>Keranjang belanja anda kosong</h2>
                <p>Anda belum menambahkan apapun. Ayo cari kebutuhan yang sesuai dengan gayamu.</p>
                <a class="btn btn--dark" href="<?= e(url('shop')) ?>">Continue shopping</a>
            </div>
        <?php else: ?>
            <div class="cart">
                <ul class="cart__lines">
                    <?php foreach ($items as $item): ?>
                        <?php component('cart-item', ['item' => $item]); ?>
                    <?php endforeach; ?>
                </ul>

                <aside class="cart-summary" aria-label="Order summary">
                    <h2>Summary</h2>

                    <dl>
                        <div>
                            <dt>Subtotal</dt>
                            <dd><?= e(rupiah($cart['subtotal'])) ?></dd>
                        </div>
                        <div>
                            <dt>Shipping</dt>
                            <dd class="muted">Calculated at checkout</dd>
                        </div>
                    </dl>

                    <p class="cart-summary__total">
                        <span>Total</span>
                        <strong><?= e(rupiah($cart['subtotal'])) ?></strong>
                    </p>

                    <a class="btn btn--dark btn--block"
                    href="<?= e(url('checkout')) ?>">
                        Checkout
                    </a>

                    <p class="cart-summary__note">
                        Shipping costs and stock availability will be confirmed at checkout.
                    </p>

                    <a class="btn btn--block"
                    href="<?= e(url('shop')) ?>">
                        Continue Shopping
                    </a>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>
