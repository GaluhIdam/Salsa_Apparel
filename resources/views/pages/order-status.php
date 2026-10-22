<section class="order-status">
    <div class="container">
        <h1>Order Status</h1>
        <?php component('flash-message', ['flash' => flash_get('order-status')]); ?>

        <?php if (!empty($loadError)): ?>
            <p>Status pesanan belum dapat dimuat. Silakan coba lagi.</p>

        <?php elseif (empty($order)): ?>
            <p>Pesanan tidak ditemukan atau sesi akses sudah berakhir.</p>

        <?php else: ?>
            <p>
                Nomor pesanan:
                <strong><?= e($order['order_number']) ?></strong>
            </p>

            <p>
                Status pembayaran:
                <strong><?= e(ucfirst($order['payment_status'])) ?></strong>
            </p>

            <p>
                Total:
                <strong><?= rupiah((int) $order['grand_total']) ?></strong>
            </p>

            <?php if ($order['payment_status'] === 'paid'): ?>
                <p>Pembayaran berhasil. Terima kasih sudah berbelanja!</p>

            <?php elseif ($order['payment_status'] === 'expired' || $order['payment_status'] === 'failed'): ?>
                <p>Pembayaran berstatus <?= e($order['payment_status']) ?>. Silakan hubungi admin untuk bantuan.</p>

            <?php elseif (strtotime((string) $order['payment_expires_at']) <= time()): ?>
                <p>Batas waktu pembayaran sudah berakhir. Silakan hubungi admin untuk bantuan.</p>

            <?php elseif (($payment['snap_request_state'] ?? '') === 'completed' && !empty($payment['redirect_url'])): ?>
                <p>Pesanan menunggu pembayaran.</p>
                <p>
                    <a href="<?= e($payment['redirect_url']) ?>" rel="noopener">
                        Lanjutkan pembayaran
                    </a>
                </p>

            <?php elseif (($payment['snap_request_state'] ?? '') === 'unknown' || ($payment['snap_request_state'] ?? '') === 'in_progress'): ?>
                <p>Pembuatan pembayaran sedang diperiksa. Jangan membuat pembayaran baru dulu.</p>

            <?php else: ?>
                <p>Pesanan tersimpan dan menunggu pembayaran.</p>
                <form method="post" action="/order-status/pay">
                    <?= csrf_field() ?>
                    <button class="btn btn--dark" type="submit">Lanjutkan pembayaran</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p><a href="/shop">Kembali ke toko</a></p>
    </div>
</section>
