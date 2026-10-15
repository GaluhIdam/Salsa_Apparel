<?php /** @var array $items @var ?array $flash */ 
$active = count(array_filter($items, static fn($i) => $i['available']));
?>
<section class="section section--catalog">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <span aria-current="page">Wishlist</span>
        </nav>
        <header class="catalog-head">
            <h1 class="section-title">Wishlist</h1>
            <?php if ($active > 0): ?>
                <p class="catalog-head__intro"><?= $active ?> <?= $active === 1 ? 'product' : 'products' ?> saved</p>
            <?php endif; ?>
        </header>

        <?php component('flash-message', ['flash' => $flash]); ?>

        <?php if (!$items): ?>
            <div class="empty-state">
                <h2>Your wishlist is empty</h2>
                <p>Tap the heart on any product to save it here for later.</p>
                <a class="btn btn--dark" href="<?= e(url('shop')) ?>">Continue shopping</a>
            </div>
        <?php else: ?>
            <ul class="product-grid wish-grid">
                <?php foreach ($items as $item): ?>
                    <?php component('wishlist-item', ['item' => $item]); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
