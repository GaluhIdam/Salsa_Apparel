<?php /** @var array $products */ ?>
<?php if ($products): ?>
<section class="section" aria-labelledby="featured-title">
    <div class="container">
        <div class="section-head">
            <h2 id="featured-title" class="section-title">Featured Collection</h2>
            <a class="text-link" href="<?= e(url('shop')) ?>">View all <?= icon('arrow-right', 16) ?></a>
        </div>
        <div class="featured-grid">
            <?php foreach ($products as $product): ?>
                <?php component('product-card', ['product' => $product]); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
