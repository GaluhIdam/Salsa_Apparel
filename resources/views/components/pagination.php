<?php
/** @var int $page @var int $totalPages @var string $basePath @var array $query */
if ($totalPages > 1):
    $link = static fn(int $n): string => url_with_query($basePath, $query + ($n > 1 ? ['page' => $n] : []));
    $items = [];
    $last = 0;
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) {
            if ($last && $i - $last > 1) { $items[] = null; }
            $items[] = $i;
            $last = $i;
        }
    }
?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a class="pagination__step" href="<?= e($link($page - 1)) ?>" rel="prev"><?= icon('chevron-left', 16) ?> Previous</a>
    <?php endif; ?>
    <ul class="pagination__pages">
        <?php foreach ($items as $n): ?>
            <?php if ($n === null): ?>
                <li aria-hidden="true">…</li>
            <?php else: ?>
                <li><a href="<?= e($link($n)) ?>" <?= $n === $page ? 'aria-current="page" class="is-current"' : '' ?> aria-label="Page <?= $n ?>"><?= $n ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <?php if ($page < $totalPages): ?>
        <a class="pagination__step" href="<?= e($link($page + 1)) ?>" rel="next">Next <?= icon('chevron-right', 16) ?></a>
    <?php endif; ?>
</nav>
<?php endif; ?>
