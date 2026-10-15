<?php /** @var array $category */ $n = (int) $category['product_count']; ?>
<a class="category-card" href="<?= e(url('category/' . $category['slug'])) ?>">
    <img src="<?= e(image_url($category['image_path'])) ?>" alt="" loading="lazy">
    <span class="category-card__label">
        <strong><?= e($category['name']) ?></strong>
        <small><?= $n ?> <?= $n === 1 ? 'product' : 'products' ?></small>
    </span>
</a>
