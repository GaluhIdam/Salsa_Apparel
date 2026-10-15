<?php /** @var string $title @var array $products */ ?>
<?php if ($products): ?>
<section class="section" aria-labelledby="carousel-title" data-carousel>
    <div class="container">
        <div class="section-head">
            <h2 id="carousel-title" class="section-title"><?= e($title) ?></h2>
            <div class="carousel-nav">
                <button class="round-btn" type="button" aria-label="Previous products" data-carousel-prev><?= icon('chevron-left', 18) ?></button>
                <button class="round-btn round-btn--solid" type="button" aria-label="Next products" data-carousel-next><?= icon('chevron-right', 18) ?></button>
            </div>
        </div>
        <div class="carousel-track" data-carousel-track tabindex="0" aria-label="<?= e($title) ?>">
            <?php foreach ($products as $product): ?>
                <?php component('product-card', ['product' => $product]); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
