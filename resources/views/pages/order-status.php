<section class="order-status">
    <div class="container">
        <h1>Order Status</h1>

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
                <p>Status pembayaran sedang diproses. Silakan periksa kembali nanti.</p>
            <?php endif; ?>
        <?php endif; ?>

        <p><a href="/shop">Kembali ke toko</a></p>
    </div>
</section>