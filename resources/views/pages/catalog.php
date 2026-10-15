<?php
/** @var string $heading @var string $intro @var string $mode @var string $basePath @var array $filters
 *  @var array $query @var array $sorts @var bool $lockedCategory @var bool $searchedEmpty @var array $products
 *  @var int $total @var int $page @var int $totalPages @var int $perPage @var array $navCategories */
$from = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
$to = min($total, $page * $perPage);
?>
<section class="section section--catalog">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <?php if ($mode === 'category'): ?><a href="<?= e(url('shop')) ?>">Shop</a><?php endif; ?>
            <span aria-current="page"><?= e($mode === 'search' ? 'Search' : $heading) ?></span>
        </nav>

        <header class="catalog-head">
            <h1 class="section-title"><?= e($heading) ?></h1>
            <?php if ($intro !== ''): ?><p class="catalog-head__intro"><?= e($intro) ?></p><?php endif; ?>
        </header>

        <?php if ($mode === 'search'): ?>
            <form class="search-box" method="get" action="<?= e(url('search')) ?>" role="search">
                <label class="sr-only" for="search-q">Search products</label>
                <input id="search-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search by name, category, or color" maxlength="100" autocomplete="off" <?= $filters['q'] === '' ? 'autofocus' : '' ?>>
                <button class="btn btn--accent" type="submit">Search</button>
                <?php if ($filters['q'] !== ''): ?>
                    <a class="search-box__clear" href="<?= e(url('search')) ?>" aria-label="Clear search"><?= icon('x', 18) ?></a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($searchedEmpty): ?>
            <div class="empty-state">
                <h2>What are you looking for?</h2>
                <p>Type a product name, category, or color above, or browse a category.</p>
                <div class="chip-row">
                    <?php foreach ($navCategories as $c): ?>
                        <a class="chip" href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <?php component('catalog-filters', [
                'basePath' => $basePath, 'filters' => $filters, 'sorts' => $sorts,
                'navCategories' => $navCategories, 'lockedCategory' => $lockedCategory,
            ]); ?>

            <p class="catalog-count" aria-live="polite">
                <?= $total > 0 ? "Showing {$from}–{$to} of {$total} " . ($total === 1 ? 'product' : 'products') : '0 products' ?>
            </p>

            <?php if ($products): ?>
                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <?php component('product-card', ['product' => $product, 'showStock' => true]); ?>
                    <?php endforeach; ?>
                </div>
                <?php component('pagination', ['page' => $page, 'totalPages' => $totalPages, 'basePath' => $basePath, 'query' => $query]); ?>
            <?php else: ?>
                <div class="empty-state">
                    <h2>No products found</h2>
                    <p><?= $filters['q'] !== '' ? 'Nothing matches your search and filters. Try a different word or loosen the filters.' : 'No products match these filters yet. Try adjusting the price range or category.' ?></p>
                    <a class="btn btn--dark" href="<?= e(url_with_query($basePath, ['q' => $filters['q']])) ?>">Clear filters</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
