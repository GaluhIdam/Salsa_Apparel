<?php /** @var array $categories @var ?array $flash */ ?>
<header class="admin-head">
    <h1>Categories <small><?= count($categories) ?></small></h1>
    <a class="btn btn--dark btn--sm" href="<?= e(url('admin/categories/new')) ?>">New category</a>
</header>
<?php component('flash-message', ['flash' => $flash]); ?>
<?php if (!$categories): ?>
    <div class="admin-empty"><h2>No categories yet</h2><p>Create a category before adding products.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Category</th><th>Slug</th><th>Products</th><th>Order</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <?php $active = $c['is_active'] === true || $c['is_active'] === 't'; ?>
                <tr>
                    <td class="admin-table__product">
                        <img src="<?= e(image_url($c['image_path'])) ?>" alt="" width="40" height="50" loading="lazy">
                        <span><a href="<?= e(url('admin/categories/' . $c['id'] . '/edit')) ?>"><?= e($c['name']) ?></a></span>
                    </td>
                    <td><?= e($c['slug']) ?></td>
                    <td><?= (int) $c['product_count'] ?></td>
                    <td><?= (int) $c['sort_order'] ?></td>
                    <td><?php component('status-badge', ['status' => $active ? 'active' : 'draft']); ?></td>
                    <td class="admin-table__actions">
                        <a class="text-link" href="<?= e(url('admin/categories/' . $c['id'] . '/edit')) ?>">Edit</a>
                        <form method="post" action="<?= e(url('admin/categories/' . $c['id'] . '/delete')) ?>" data-confirm="Delete this category?"><?= csrf_field() ?><button class="text-button" type="submit">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>