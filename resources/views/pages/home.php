<?php /** @var array $navCategories @var array $newArrivals @var array $featured */ ?>
<?php component('hero', ['navCategories' => $navCategories]); ?>
<?php component('category-section', ['categories' => $navCategories]); ?>
<?php component('product-carousel', ['title' => 'New Arrivals', 'products' => $newArrivals]); ?>
<?php component('editorial-section'); ?>
<?php component('featured-collection', ['products' => $featured]); ?>
<?php component('newsletter'); ?>
