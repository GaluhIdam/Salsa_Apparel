<?php /** @var array $navCategories */ $count = cart_count(); $wishCount = wishlist_count(); $signedIn = auth_user() !== null; ?>
<header class="site-header">
    <div class="header-bar">
        <div class="header-left">
            <button class="icon-btn menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobile-menu" data-menu-open>
                <?= icon('menu') ?>
            </button>
            <?php component('navigation', ['navCategories' => $navCategories]); ?>
        </div>
        <a class="logo" href="<?= e(url('/')) ?>" aria-label="Salsa Apparel home">Salsa Apparel</a>
        <div class="header-actions">
            <a class="icon-btn" href="<?= e(url('search')) ?>" aria-label="Search"><?= icon('search') ?></a>
            <a class="icon-btn" href="<?= e(url('wishlist')) ?>" aria-label="Wishlist, <?= $wishCount ?> items" data-wish-link>
                <?= icon('heart') ?>
                <span class="badge-count" data-wish-count <?= $wishCount > 0 ? '' : 'hidden' ?>><?= $wishCount ?></span>
            </a>
            <a class="icon-btn" href="<?= e(url('cart')) ?>" aria-label="Shopping bag, <?= $count ?> items">
                <?= icon('bag') ?>
                <?php if ($count > 0): ?><span class="badge-count"><?= $count ?></span><?php endif; ?>
            </a>
            <?php if ($signedIn): ?>
                <a class="icon-btn" href="<?= e(url('account')) ?>" aria-label="My account"><?= icon('user') ?></a>
            <?php else: ?>
                <a class="icon-btn" href="<?= e(url('login')) ?>" aria-label="Sign in"><?= icon('user') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="mobile-menu" id="mobile-menu" hidden>
        <div class="mobile-menu__head">
            <span class="logo">Salsa Apparel</span>
            <button class="icon-btn" type="button" aria-label="Close menu" data-menu-close><?= icon('x') ?></button>
        </div>
        <ul class="mobile-menu__list">
            <li><a href="<?= e(url('shop')) ?>">Shop</a></li>
            <?php foreach ($navCategories as $c): ?>
                <li class="is-sub"><a href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(url('page/our-story')) ?>">About</a></li>
            <li><a href="<?= e(url('page/contact')) ?>">Support</a></li>
        </ul>
    </div>
</header>
