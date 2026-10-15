<?php /** @var array $categories */ ?>
<section class="section" aria-labelledby="cat-title">
    <div class="container">
        <h2 id="cat-title" class="section-title section-title--center">Browse By Categories</h2>
        <div class="category-grid">
            <?php foreach ($categories as $category): ?>
                <?php component('category-card', ['category' => $category]); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
