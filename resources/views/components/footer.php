<?php
/** @var array $navCategories */
$socials = array_filter([
    'Instagram' => ['instagram', setting('social_instagram')],
    'TikTok' => ['tiktok', setting('social_tiktok')],
], static fn($s) => $s[1] !== '');
?>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a class="logo logo--light" href="<?= e(url('/')) ?>">Salsa Apparel</a>
            <p>Premium everyday fashion. Modern, minimal, and made to last beyond the season.</p>
        </div>
        <div>
            <h3>Shop</h3>
            <ul>
                <?php foreach ($navCategories as $c): ?>
                    <li><a href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h3>Customer Care</h3>
            <ul>
                <li><a href="<?= e(url('page/contact')) ?>">Contact Us</a></li>
                <li><a href="<?= e(url('page/faq')) ?>">FAQ</a></li>
                <li><a href="<?= e(url('page/shipping')) ?>">Shipping Information</a></li>
                <li><a href="<?= e(url('page/returns')) ?>">Returns &amp; Exchanges</a></li>
                <li><a href="<?= e(url('page/size-guide')) ?>">Size Guide</a></li>
            </ul>
        </div>
        <div>
            <h3>About</h3>
            <ul>
                <li><a href="<?= e(url('page/our-story')) ?>">Our Story</a></li>
                <li><a href="<?= e(url('page/privacy')) ?>">Privacy Policy</a></li>
                <li><a href="<?= e(url('page/terms')) ?>">Terms &amp; Conditions</a></li>
            </ul>
        </div>
        <?php if ($socials): ?>
        <div>
            <h3>Social</h3>
            <ul>
                <?php foreach ($socials as $label => [$ico, $href]): ?>
                    <li><a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer"><?= icon($ico, 16) ?> <?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <div class="container footer-bottom">
        <p>&copy; <?= date('Y') ?> Salsa Apparel. All rights reserved.</p>
    </div>
</footer>
