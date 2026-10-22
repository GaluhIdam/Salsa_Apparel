<?php

/** @var array $orders */
/** @var bool $loadError */

$paymentLabels = [
    'pending' => 'Menunggu Pembayaran',
    'paid' => 'Lunas',
    'failed' => 'Gagal',
    'expired' => 'Kedaluwarsa',
    'refunded' => 'Dikembalikan',
];

$fulfillmentLabels = [
    'unprocessed' => 'Belum Diproses',
    'processing' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan',
];

$totalOrders = count($orders);

$paidOrders = count(array_filter(
    $orders,
    static fn(array $order): bool =>
    $order['payment_status'] === 'paid'
));

$pendingOrders = count(array_filter(
    $orders,
    static fn(array $order): bool =>
    $order['payment_status'] === 'pending'
));
?>

<section class="section section--catalog">
    <div class="container">

        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= e(url('/')) ?>">Home</a>
            <a href="<?= e(url('/account')) ?>">My Account</a>
            <span aria-current="page">My Orders</span>
        </nav>

        <header class="catalog-head">
            <h1 class="section-title">My Orders</h1>

            <p class="catalog-head__intro">
                Lihat seluruh riwayat pembelian
                dan status pesanan kamu.
            </p>
        </header>

        <?php if (!empty($loadError)): ?>

            <div class="account-card">
                <h2>Gagal Memuat Pesanan</h2>

                <p>
                    Riwayat transaksi belum dapat dimuat.
                    Silakan coba lagi.
                </p>

                <a
                    href="<?= e(url('/account/orders')) ?>"
                    class="btn btn--dark">
                    Coba Lagi
                </a>
            </div>

        <?php else: ?>

            <div class="account-grid">

                <article class="account-card">
                    <h2>Total Pesanan</h2>
                    <strong><?= $totalOrders ?></strong>
                    <p>Seluruh transaksi kamu</p>
                </article>

                <article class="account-card">
                    <h2>Sudah Dibayar</h2>
                    <strong><?= $paidOrders ?></strong>
                    <p>Pembayaran berhasil</p>
                </article>

                <article class="account-card">
                    <h2>Menunggu Pembayaran</h2>
                    <strong><?= $pendingOrders ?></strong>
                    <p>Transaksi belum lunas</p>
                </article>

            </div>

            <div style="margin-top: 32px;">

                <?php if ($orders === []): ?>

                    <div class="account-card">
                        <h2>Belum Ada Pesanan</h2>

                        <p>
                            Kamu belum memiliki riwayat
                            transaksi.
                        </p>

                        <a
                            href="<?= e(url('/shop')) ?>"
                            class="btn btn--dark">
                            Mulai Belanja
                        </a>
                    </div>

                <?php else: ?>

                    <?php foreach ($orders as $order): ?>

                        <?php
                        $paymentStatus = (string)
                        $order['payment_status'];

                        $fulfillmentStatus = (string)
                        $order['fulfillment_status'];

                        $createdAt = new DateTimeImmutable(
                            (string) $order['created_at']
                        );
                        ?>

                        <article
                            class="account-card"
                            style="margin-bottom: 20px;">

                            <div
                                style="
                                    display: flex;
                                    justify-content: space-between;
                                    align-items: flex-start;
                                    flex-wrap: wrap;
                                    gap: 16px;
                                ">
                                <div>
                                    <p>
                                        Nomor Pesanan
                                    </p>

                                    <h2>
                                        <?= e($order['order_number']) ?>
                                    </h2>

                                    <p>
                                        <?= e(
                                            $createdAt->format('d M Y H:i')
                                        ) ?>
                                    </p>
                                </div>

                                <div>
                                    <strong>
                                        <?= rupiah(
                                            (int) $order['grand_total']
                                        ) ?>
                                    </strong>
                                </div>
                            </div>

                            <hr style="margin: 20px 0;">

                            <div class="account-details">

                                <div>
                                    <dt>Status Pembayaran</dt>

                                    <dd>
                                        <span
                                            class="status-badge admin-status--<?= e($paymentStatus) ?>">
                                            <?= e(
                                                $paymentLabels[$paymentStatus]
                                                    ?? ucfirst($paymentStatus)
                                            ) ?>
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt>Status Pesanan</dt>

                                    <dd>
                                        <?= e(
                                            $fulfillmentLabels[$fulfillmentStatus]
                                                ?? ucfirst($fulfillmentStatus)
                                        ) ?>
                                    </dd>
                                </div>

                                <div>
                                    <dt>Jumlah Produk</dt>

                                    <dd>
                                        <?= (int) $order['total_items'] ?>
                                        item
                                    </dd>
                                </div>

                                <div>
                                    <dt>Pengiriman</dt>

                                    <dd>
                                        <?= e(
                                            $order['shipping_courier']
                                        ) ?>
                                        -
                                        <?= e(
                                            $order['shipping_service']
                                        ) ?>
                                    </dd>
                                </div>

                            </div>

                            <details style="margin-top: 20px;">
                                <summary
                                    style="cursor: pointer; font-weight: 600;">
                                    Lihat Detail Produk
                                </summary>

                                <div style="margin-top: 16px;">

                                    <?php foreach (
                                        $order['items'] as $item
                                    ): ?>

                                        <div
                                            style="
                                                padding: 12px 0;
                                                border-bottom: 1px solid
                                                var(--color-border, #e5e5e5);
                                            ">
                                            <strong>
                                                <?= e(
                                                    $item['product_name']
                                                ) ?>
                                            </strong>

                                            <p>
                                                SKU:
                                                <?= e($item['sku']) ?>
                                            </p>

                                            <p>
                                                Ukuran:
                                                <?= e($item['size']) ?>

                                                |
                                                Warna:
                                                <?= e($item['color']) ?>
                                            </p>

                                            <p>
                                                <?= (int) $item['quantity'] ?>
                                                ×
                                                <?= rupiah(
                                                    (int) $item['unit_price']
                                                ) ?>
                                            </p>

                                            <strong>
                                                <?= rupiah(
                                                    (int) $item['line_total']
                                                ) ?>
                                            </strong>
                                        </div>

                                    <?php endforeach; ?>

                                </div>
                            </details>

                            <?php if ($paymentStatus === 'pending'): ?>

                                <p style="margin-top: 20px;">
                                    Pembayaran belum dikonfirmasi.
                                    Status akan diperbarui setelah
                                    verifikasi Midtrans berhasil.
                                </p>

                            <?php elseif ($paymentStatus === 'paid'): ?>

                                <p style="margin-top: 20px;">
                                    Pembayaran berhasil.
                                    Terima kasih sudah berbelanja!
                                </p>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <p style="margin-top: 24px;">
            <a
                class="text-link"
                href="<?= e(url('/account')) ?>">
                Kembali ke My Account
            </a>
        </p>

    </div>
</section>