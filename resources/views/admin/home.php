<?php
use App\Core\AdminAuth;

/** @var array $user */
/** @var array $stats */
/** @var array $sales */
/** @var array $recentOrders */
/** @var array $topProducts */
/** @var float $maxSales */
/** @var ?array $flash */

$money = static fn ($value): string =>
    'Rp ' . number_format((float) $value, 0, ',', '.');

$paymentLabels = [
    'pending' => 'Menunggu',
    'paid' => 'Lunas',
    'failed' => 'Gagal',
    'expired' => 'Kedaluwarsa',
    'refunded' => 'Dikembalikan',
];

$fulfillmentLabels = [
    'unprocessed' => 'Belum diproses',
    'processing' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan',
];

$sections = [
    ['Products', 'Kelola produk, varian, stok, dan gambar.', '/admin/products', AdminAuth::can('products.manage')],
    ['Categories', 'Kelola kategori dan gambar kategori.', '/admin/categories', AdminAuth::can('categories.manage')],
];
?>

<header class="admin-head admin-dashboard-head">
    <div>
        <p class="admin-eyebrow">SALSA APPAREL / ADMINISTRATION</p>
        <h1>Welcome back, <?= e($user['full_name']) ?></h1>
        <p class="admin-dashboard-subtitle">Berikut ringkasan aktivitas toko kamu.</p>
    </div>
    <a class="admin-dashboard-view-shop" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">
        View storefront <?= icon('arrow-up-right', 16) ?>
    </a>
</header>

<?php component('flash-message', ['flash' => $flash]); ?>

<section class="admin-stat-grid" aria-label="Ringkasan toko">
    <article class="admin-stat-card">
        <div class="admin-stat-card__top">
            <span>Total Customers</span>
            <span class="admin-stat-icon"><?= icon('users', 20) ?></span>
        </div>
        <strong><?= number_format((int) $stats['customers']) ?></strong>
        <p>Akun pelanggan terdaftar</p>
    </article>

    <article class="admin-stat-card">
        <div class="admin-stat-card__top">
            <span>Active Products</span>
            <span class="admin-stat-icon"><?= icon('package', 20) ?></span>
        </div>
        <strong><?= number_format((int) $stats['products']) ?></strong>
        <p>Produk yang tampil di toko</p>
    </article>

    <article class="admin-stat-card">
        <div class="admin-stat-card__top">
            <span>Total Orders</span>
            <span class="admin-stat-icon"><?= icon('shopping-bag', 20) ?></span>
        </div>
        <strong><?= number_format((int) $stats['orders']) ?></strong>
        <p><?= number_format((int) $stats['paid_orders']) ?> pesanan sudah dibayar</p>
    </article>

    <article class="admin-stat-card admin-stat-card--revenue">
        <div class="admin-stat-card__top">
            <span>Paid Revenue</span>
            <span class="admin-stat-icon"><?= icon('wallet', 20) ?></span>
        </div>
        <strong class="admin-stat-card__money"><?= e($money($stats['revenue'])) ?></strong>
        <p>Total pesanan berstatus lunas</p>
    </article>
</section>

<section class="admin-dashboard-grid">
    <article class="admin-panel admin-sales-panel">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">PERFORMANCE</p>
                <h2>Sales overview</h2>
                <p class="admin-muted">Pendapatan 7 hari terakhir</p>
            </div>
            <span class="admin-period-label">7 hari</span>
        </div>

        <?php if ($maxSales <= 0): ?>
            <div class="admin-chart-empty">
                <span><?= icon('chart-no-axes-combined', 28) ?></span>
                <p>Belum ada pendapatan dari pesanan lunas.</p>
            </div>
        <?php else: ?>
            <div class="admin-sales-chart">
                <?php foreach ($sales as $sale): ?>
                    <?php
                    $amount = (float) $sale['revenue'];
                    $height = $maxSales > 0 ? ($amount / $maxSales) * 100 : 0;
                    $date = new DateTimeImmutable($sale['sale_date']);
                    ?>
                    <div class="admin-sales-chart__item">
                        <div class="admin-sales-chart__bar-area">
                            <span class="admin-sales-chart__amount">
                                <?= e($money($amount)) ?>
                            </span>
                            <div
                                class="admin-sales-chart__bar<?= $amount > 0 && $amount === $maxSales ? ' is-highest' : '' ?>"
                                style="height: <?= e((string) max(2, $height)) ?>%"
                                title="<?= e($money($amount)) ?>"
                            ></div>
                        </div>
                        <span class="admin-sales-chart__label"><?= e($date->format('d/m')) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="admin-panel admin-activity-panel">
        <div class="admin-section-heading">
            <div>
                <p class="admin-eyebrow">NEEDS ATTENTION</p>
                <h2>Order status</h2>
                <p class="admin-muted">Ringkasan yang perlu dipantau</p>
            </div>
        </div>

        <div class="admin-activity-row">
            <span class="admin-activity-icon admin-activity-icon--amber"><?= icon('clock-3', 19) ?></span>
            <div>
                <strong>Menunggu pembayaran</strong>
                <p>Pesanan belum lunas</p>
            </div>
            <b><?= number_format((int) $stats['pending_payments']) ?></b>
        </div>

        <div class="admin-activity-row">
            <span class="admin-activity-icon"><?= icon('package-check', 19) ?></span>
            <div>
                <strong>Perlu diproses</strong>
                <p>Pesanan belum dikirim atau diselesaikan</p>
            </div>
            <b><?= number_format((int) $stats['pending_fulfillment']) ?></b>
        </div>

        <div class="admin-quick-links">
            <?php foreach ($sections as [$title, $description, $href, $allowed]): ?>
                <?php if ($allowed): ?>
                    <a href="<?= e(url($href)) ?>">
                        <span><?= e($title) ?></span>
                        <?= icon('arrow-up-right', 16) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="admin-panel admin-recent-panel">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">LATEST ACTIVITY</p>
            <h2>Recent orders</h2>
            <p class="admin-muted">Pesanan terbaru yang masuk ke toko.</p>
        </div>
    </div>

    <?php if (!$recentOrders): ?>
        <div class="admin-empty">
            <span><?= icon('shopping-bag', 28) ?></span>
            <h3>Belum ada pesanan</h3>
            <p>Pesanan baru akan muncul di bagian ini.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table admin-orders-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Fulfillment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <?php
                        $payment = (string) $order['payment_status'];
                        $fulfillment = (string) $order['fulfillment_status'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($order['order_number']) ?></strong>
                            </td>
                            <td>
                                <span class="admin-customer-name"><?= e($order['recipient_name']) ?></span>
                                <small class="admin-customer-email"><?= e($order['customer_email']) ?></small>
                            </td>
                            <td>
                                <?= e((new DateTimeImmutable($order['created_at']))->format('d M Y')) ?>
                            </td>
                            <td><strong><?= e($money($order['grand_total'])) ?></strong></td>
                            <td>
                                <span class="status-badge admin-status--<?= e($payment) ?>">
                                    <?= e($paymentLabels[$payment] ?? ucfirst($payment)) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge admin-status--fulfillment">
                                    <?= e($fulfillmentLabels[$fulfillment] ?? ucfirst($fulfillment)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-panel admin-top-products">
    <div class="admin-section-heading">
        <div>
            <p class="admin-eyebrow">PRODUCT PERFORMANCE</p>
            <h2>Best-selling products</h2>
            <p class="admin-muted">Berdasarkan kuantitas dari pesanan yang sudah dibayar.</p>
        </div>
    </div>

    <?php if (!$topProducts): ?>
        <p class="admin-muted">Belum ada data produk terjual dari pesanan lunas.</p>
    <?php else: ?>
        <div class="admin-top-products__list">
            <?php foreach ($topProducts as $index => $product): ?>
                <div class="admin-top-product">
                    <span class="admin-top-product__rank"><?= $index + 1 ?></span>
                    <div class="admin-top-product__details">
                        <strong><?= e($product['product_name']) ?></strong>
                        <span><?= number_format((int) $product['units_sold']) ?> item terjual</span>
                    </div>
                    <strong><?= e($money($product['sales_total'])) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
