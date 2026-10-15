<?php
/** @var string $basePath @var array $filters @var array $sorts @var array $navCategories @var bool $lockedCategory */
$hasFilters = $filters['category'] !== null && !$lockedCategory
    || $filters['min_price'] !== null || $filters['max_price'] !== null;
?>
<form class="catalog-filters" method="get" action="<?= e(url($basePath)) ?>">
    <?php if ($filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>
    <?php if (!$lockedCategory): ?>
        <div class="field">
            <label for="f-category">Category</label>
            <select id="f-category" name="category">
                <option value="">All categories</option>
                <?php foreach ($navCategories as $c): ?>
                    <option value="<?= e($c['slug']) ?>" <?= $filters['category'] === $c['slug'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="field">
        <label for="f-min">Min price (Rp)</label>
        <input id="f-min" type="number" name="min_price" inputmode="numeric" min="0" max="999999999" step="1000" value="<?= e($filters['min_price'] ?? '') ?>" placeholder="0">
    </div>
    <div class="field">
        <label for="f-max">Max price (Rp)</label>
        <input id="f-max" type="number" name="max_price" inputmode="numeric" min="0" max="999999999" step="1000" value="<?= e($filters['max_price'] ?? '') ?>" placeholder="No limit">
    </div>
    <div class="field">
        <label for="f-sort">Sort by</label>
        <select id="f-sort" name="sort">
            <?php foreach ($sorts as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="catalog-filters__actions">
        <button class="btn btn--dark" type="submit">Apply</button>
        <?php if ($hasFilters || $filters['min_price'] !== null): ?>
            <a class="text-link" href="<?= e(url_with_query($basePath, ['q' => $filters['q']])) ?>">Clear filters</a>
        <?php endif; ?>
    </div>
</form>
