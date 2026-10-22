<?php
/** @var array $products @var int $total @var int $page @var int $totalPages @var array $filters @var array $query @var array $categories @var ?array $flash */
?>
<header class="admin-head">
    <h1>Products <small><?= (int) $total ?></small></h1>
    <a class="btn btn--dark btn--sm" href="<?= e(url('admin/products/new')) ?>">New product</a>
</header>
<?php component('flash-message', ['flash' => $flash]); ?>

<form class="catalog-filters admin-filters" method="get" action="<?= e(url('admin/products')) ?>">
    <div class="field"><label for="f-q">Search</label><input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, slug, or SKU" maxlength="100"></div>
    <div class="field"><label for="f-status">Status</label>
        <select id="f-status" name="status">
            <option value="">All</option>
            <?php foreach (['draft', 'active', 'archived'] as $s): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="f-cat">Category</label>
        <select id="f-cat" name="category">
            <option value="">All</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="catalog-filters__actions"><button class="btn btn--dark btn--sm" type="submit">Filter</button>
        <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['category'] > 0): ?><a class="text-link" href="<?= e(url('admin/products')) ?>">Clear</a><?php endif; ?></div>
</form>

<?php if (!$products): ?>
    <div class="admin-empty"><h2>No products found</h2><p>Create your first product or adjust the filters.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Variants</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td class="admin-table__product">
                        <img src="<?= e(image_url($p['image_path'])) ?>" alt="" width="40" height="50" loading="lazy">
                        <span><a href="<?= e(url('admin/products/' . $p['id'] . '/edit')) ?>"><?= e($p['name']) ?></a><small><?= e($p['slug']) ?></small></span>
                    </td>
                    <td><?= e($p['category_name']) ?></td>
                    <td><?= e(rupiah($p['sale_price'] ?? $p['price'])) ?><?php if ($p['sale_price'] !== null): ?> <s><?= e(rupiah($p['price'])) ?></s><?php endif; ?></td>
                    <td><?= (int) $p['total_stock'] ?></td>
                    <td><?= (int) $p['variant_count'] ?></td>
                    <td><?php component('status-badge', ['status' => $p['status']]); ?></td>
                    <td><a class="text-link" href="<?= e(url('admin/products/' . $p['id'] . '/edit')) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php component('pagination', ['page' => $page, 'totalPages' => $totalPages, 'basePath' => '/admin/products', 'query' => $query]); ?>
<?php endif; ?>
