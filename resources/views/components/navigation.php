<?php /** @var array $navCategories */ ?>
<nav class="nav-main" aria-label="Primary">
    <a class="nav-link" href="<?= e(url('shop')) ?>">Shop</a>
    <div class="nav-item">
        <button class="nav-link nav-trigger" type="button" aria-expanded="false" aria-controls="category-dropdown" data-dropdown-toggle>
            Category <?= icon('chevron-down', 14) ?>
        </button>
        <ul class="dropdown" id="category-dropdown" hidden>
            <?php foreach ($navCategories as $c): ?>
                <li><a href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <a class="nav-link" href="<?= e(url('page/our-story')) ?>">About</a>
    <a class="nav-link" href="<?= e(url('page/contact')) ?>">Support</a>
</nav>
