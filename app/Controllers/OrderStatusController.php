
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Repositories\PaymentRepository;
use App\Services\MidtransWebhookReconciliationService;
use Throwable;

final class OrderStatusController extends Controller
{
    public function show(): void
    {
        $pending = $_SESSION['pending_order'] ?? null;

        if (
            !is_array($pending)
            || !isset(
                $pending['order_id'],
                $pending['access_token']
            )
            || !is_int($pending['order_id'])
            || !is_string($pending['access_token'])
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                $pending['access_token']
            )
        ) {
            http_response_code(404);

            $this->view('pages/order-status', [
                'pageTitle' => 'Order Status',
                'order' => null,
                'payment' => null,
            ]);

            return;
        }

        try {
            $pdo = Database::connection();
            $repository = new PaymentRepository();

            $orderId = $pending['order_id'];
            $accessToken = $pending['access_token'];

            $order = $repository->findOrderForStatus(
                $pdo,
                $orderId,
                $accessToken
            );

            if ($order === null) {
                http_response_code(404);

                $this->view('pages/order-status', [
                    'pageTitle' => 'Order Status',
                    'order' => null,
                    'payment' => null,
                ]);

                return;
            }

            $payment = $repository->findLatestAttemptForOrder(
                $pdo,
                $orderId
            );

            // Sinkronisasi dengan Midtrans jika pembayaran pending.
            if (
                $order['payment_status'] === 'pending'
                && is_array($payment)
                && ($payment['provider'] ?? '') === 'midtrans'
                && is_string(
                    $payment['provider_order_id'] ?? null
                )
                && $payment['provider_order_id'] !== ''
            ) {
                $lastSync = (int) (
                    $_SESSION['midtrans_last_sync'][$orderId]
                    ?? 0
                );

                // Batasi request ke Midtrans setiap 15 detik.
                if (time() - $lastSync >= 15) {
                    $_SESSION['midtrans_last_sync'][$orderId] =
                        time();

                    try {
                        $service =
                            new MidtransWebhookReconciliationService();

                        // Ambil status transaksi resmi dari Midtrans.
                        $service->synchronize(
                            $payment['provider_order_id']
                        );

                        // Baca ulang order setelah sinkronisasi.
                        $updatedOrder =
                            $repository->findOrderForStatus(
                                $pdo,
                                $orderId,
                                $accessToken
                            );

                        if ($updatedOrder !== null) {
                            $order = $updatedOrder;
                        }

                        // Baca ulang data pembayaran.
                        $payment =
                            $repository->findLatestAttemptForOrder(
                                $pdo,
                                $orderId
                            );

                    } catch (Throwable $e) {
                        // Jangan mengubah status menjadi paid
                        // jika verifikasi Midtrans gagal.
                        error_log(
                            'Midtrans status sync failed: '
                            . $e->getMessage()
                        );
                    }
                }
            }

            // Hapus throttle saat pembayaran sudah selesai.
            if ($order['payment_status'] !== 'pending') {
                unset(
                    $_SESSION['midtrans_last_sync'][$orderId]
                );
            }

            $this->view('pages/order-status', [
                'pageTitle' => 'Order Status',
                'order' => $order,
                'payment' => $payment,
            ]);

        } catch (Throwable $e) {
            error_log(
                'Order status error: '
                . $e->getMessage()
            );

            http_response_code(500);

            $this->view('pages/order-status', [
                'pageTitle' => 'Order Status',
                'order' => null,
                'payment' => null,
                'loadError' => true,
            ]);
        }
    }
}
