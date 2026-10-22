<?php
use App\Core\AdminAuth;

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH);
$items = [
    ['/admin', 'Overview', true],
    ['/admin/products', 'Products', AdminAuth::can('products.manage')],
    ['/admin/categories', 'Categories', AdminAuth::can('categories.manage')],
];
?>
<aside class="admin-sidebar" aria-label="Admin navigation">
    <a class="admin-sidebar__brand" href="<?= e(url('admin')) ?>">Salsa Apparel <span>Admin</span></a>
    <nav>
        <ul>
            <?php foreach ($items as [$href, $label, $allowed]): ?>
                <?php if ($allowed): ?>
                    <?php $active = $href === '/admin' ? $path === '/admin' : str_starts_with($path, $href); ?>
                    <li><a href="<?= e(url($href)) ?>" <?= $active ? 'aria-current="page" class="is-active"' : '' ?>><?= e($label) ?></a></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>
    <a class="admin-sidebar__shop" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">View shop <?= icon('arrow-right', 14) ?></a>
</aside>