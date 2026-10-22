<section class="order-status">
    <div class="container">
        <h1>Order Status</h1>

        <?php component('flash-message', [
            'flash' => flash_get('order-status')
        ]); ?>

        <?php if (!empty($loadError)): ?>
            <p>
                Status pesanan belum dapat dimuat.
                Silakan coba lagi.
            </p>

        <?php elseif (empty($order)): ?>
            <p>
                Pesanan tidak ditemukan atau sesi akses
                sudah berakhir.
            </p>

        <?php else: ?>
            <?php
            $paymentStatus = strtolower(
                (string) ($order['payment_status'] ?? 'pending')
            );

            $expiresAt = strtotime(
                (string) ($order['payment_expires_at'] ?? '')
            );

            $isExpired = $expiresAt !== false
                && $expiresAt <= time();

            $isPending = $paymentStatus === 'pending';

            $snapState = (string) (
                $payment['snap_request_state'] ?? ''
            );

            $redirectUrl = (string) (
                $payment['redirect_url'] ?? ''
            );
            ?>

            <p>
                Nomor pesanan:
                <strong>
                    <?= e($order['order_number']) ?>
                </strong>
            </p>

            <p>
                Status pembayaran:
                <strong id="payment-status" aria-live="polite">
                    <?= e(ucfirst($paymentStatus)) ?>
                </strong>
            </p>

            <p>
                Total:
                <strong>
                    <?= rupiah((int) $order['grand_total']) ?>
                </strong>
            </p>

            <?php if ($paymentStatus === 'paid'): ?>

                <p>
                    Pembayaran berhasil.
                    Terima kasih sudah berbelanja!
                </p>

            <?php elseif ($paymentStatus === 'refunded'): ?>

                <p>
                    Pembayaran telah dikembalikan.
                    Silakan hubungi admin untuk informasi
                    lebih lanjut.
                </p>

            <?php elseif (
                $paymentStatus === 'expired'
                || $paymentStatus === 'failed'
            ): ?>

                <p>
                    Pembayaran berstatus
                    <?= e($paymentStatus) ?>.
                    Silakan hubungi admin untuk bantuan.
                </p>

            <?php elseif ($isExpired): ?>

                <p>
                    Batas waktu pembayaran sudah berakhir.
                    Silakan hubungi admin untuk bantuan.
                </p>

            <?php elseif (
                $snapState === 'completed'
                && $redirectUrl !== ''
            ): ?>

                <p>
                    Pesanan sedang menunggu konfirmasi
                    pembayaran dari Midtrans.
                </p>

                <p>
                    Jika sudah melakukan pembayaran,
                    status akan diperbarui secara otomatis.
                </p>

                <p>
                    <a
                        href="<?= e($redirectUrl) ?>"
                        rel="noopener noreferrer">
                        Lanjutkan pembayaran
                    </a>
                </p>

            <?php elseif (
                $snapState === 'unknown'
                || $snapState === 'in_progress'
            ): ?>

                <p>
                    Pembuatan pembayaran sedang diperiksa.
                    Jangan membuat pembayaran baru dulu.
                </p>

                <p>
                    Halaman akan memeriksa kembali
                    status pembayaran secara otomatis.
                </p>

            <?php else: ?>

                <p>
                    Pesanan tersimpan dan sedang
                    menunggu pembayaran.
                </p>

                <form
                    method="post"
                    action="/order-status/pay">
                    <?= csrf_field() ?>

                    <button
                        class="btn btn--dark"
                        type="submit">
                        Lanjutkan pembayaran
                    </button>
                </form>

            <?php endif; ?>

            <?php if ($isPending && !$isExpired): ?>

                <div
                    id="payment-sync"
                    role="status"
                    aria-live="polite"
                    style="margin-top: 20px;">
                    <p id="payment-sync-message">
                        Memeriksa status pembayaran
                        secara otomatis setiap 15 detik...
                    </p>
                </div>

                <script>
                    (() => {
                        "use strict";

                        const refreshInterval = 15000;

                        let refreshTimer = null;

                        function refreshPaymentStatus() {
                            if (document.visibilityState === "hidden") {
                                return;
                            }

                            const message = document.getElementById(
                                "payment-sync-message"
                            );

                            if (message) {
                                message.textContent =
                                    "Memperbarui status pembayaran...";
                            }

                            window.location.reload();
                        }

                        function scheduleRefresh() {
                            if (refreshTimer !== null) {
                                window.clearTimeout(refreshTimer);
                            }

                            refreshTimer = window.setTimeout(
                                refreshPaymentStatus,
                                refreshInterval
                            );
                        }

                        document.addEventListener(
                            "visibilitychange",
                            () => {
                                if (
                                    document.visibilityState === "visible"
                                ) {
                                    scheduleRefresh();
                                } else if (refreshTimer !== null) {
                                    window.clearTimeout(refreshTimer);
                                    refreshTimer = null;
                                }
                            }
                        );

                        scheduleRefresh();
                    })();
                </script>

            <?php endif; ?>

        <?php endif; ?>

        <p>
            <a href="/shop">
                Kembali ke toko
            </a>
        </p>
    </div>
</section>