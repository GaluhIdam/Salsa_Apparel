
<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\PaymentRepository;
use RuntimeException;
use Throwable;

final class MidtransWebhookReconciliationService
{
    public function __construct(
        private readonly MidtransWebhookService $verifier = new MidtransWebhookService(),
        private readonly PaymentRepository $payments = new PaymentRepository(),
    ) {}

    public function process(array $notification): void
    {
        $status = $this->verifier->verifyAndFetchStatus($notification);

        $this->processVerifiedStatus($status);
    }

    public function synchronize(string $providerOrderId): void
    {
        $status = (new MidtransService())
            ->getTransactionStatus($providerOrderId);

        if (
            !isset($status->order_id)
            || (string) $status->order_id !== $providerOrderId
        ) {
            throw new RuntimeException(
                'Order ID dari Midtrans tidak cocok.'
            );
        }

        $this->processVerifiedStatus($status);
    }

    private function processVerifiedStatus(object $status): void
    {
        $orderId = (string) $status->order_id;

        $providerStatus = strtolower(
            (string) ($status->transaction_status ?? '')
        );

        $fraudStatus = strtolower(
            (string) ($status->fraud_status ?? '')
        );

        $transactionId = isset($status->transaction_id)
            ? (string) $status->transaction_id
            : null;

        $paymentStatus = $this->mapStatus(
            $providerStatus,
            $fraudStatus
        );

        $payload = json_decode(
            json_encode($status, JSON_THROW_ON_ERROR),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($payload)) {
            throw new RuntimeException(
                'Status transaksi tidak valid.'
            );
        }

        $eventKey = substr(hash('sha256', implode('|', [
            $orderId,
            $providerStatus,
            $fraudStatus,
            (string) ($status->status_code ?? ''),
            $transactionId ?? '',
        ])), 0, 64);

        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            $this->payments->recordWebhookEvent(
                $pdo,
                'midtrans',
                $eventKey,
                $payload
            );

            $event = $this->payments->findWebhookEvent(
                $pdo,
                'midtrans',
                $eventKey
            );

            if ($event === null) {
                throw new RuntimeException(
                    'Event webhook gagal disimpan.'
                );
            }

            if ($event['processed_at'] !== null) {
                $pdo->commit();
                return;
            }

            $payment = $this->payments->findByProviderOrderId(
                $pdo,
                'midtrans',
                $orderId,
                true
            );

            if ($payment === null) {
                throw new RuntimeException(
                    'Pembayaran tidak ditemukan.'
                );
            }

            $order = $this->payments->lockOrderForPaymentUpdate(
                $pdo,
                (int) $payment['order_id']
            );

            if ($order === null) {
                throw new RuntimeException(
                    'Pesanan tidak ditemukan.'
                );
            }

            if (
                !isset($status->gross_amount)
                || !$this->sameAmount(
                    (string) $payment['amount'],
                    (string) $status->gross_amount
                )
                || !$this->sameAmount(
                    (string) $order['grand_total'],
                    (string) $status->gross_amount
                )
            ) {
                throw new RuntimeException(
                    'Nominal pembayaran tidak cocok.'
                );
            }

            $currentPaymentStatus = strtolower(
                (string) $payment['status']
            );

            $currentOrderStatus = strtolower(
                (string) $order['payment_status']
            );

            if (!in_array($currentPaymentStatus, [
                'pending',
                'paid',
                'failed',
                'expired',
                'refunded',
            ], true)) {
                throw new RuntimeException(
                    'Status pembayaran di database tidak valid.'
                );
            }

            $shouldUpdatePayment = $this->shouldUpdatePaymentStatus(
                $currentPaymentStatus,
                $paymentStatus
            );

            if ($shouldUpdatePayment) {
                $this->payments->updateVerifiedPaymentStatus(
                    $pdo,
                    (int) $payment['id'],
                    $currentPaymentStatus,
                    $paymentStatus,
                    $providerStatus,
                    $transactionId,
                    $payload
                );
            }

            if (
                $currentOrderStatus === 'pending'
                && $paymentStatus !== 'pending'
                && (
                    $shouldUpdatePayment
                    || $currentPaymentStatus === $paymentStatus
                )
            ) {
                $st = $pdo->prepare(
                    "UPDATE orders
                     SET payment_status = :status,
                         updated_at = NOW()
                     WHERE id = :id
                       AND payment_status = 'pending'"
                );

                $st->execute([
                    'status' => $paymentStatus,
                    'id' => (int) $order['id'],
                ]);

                if ($st->rowCount() === 1) {
                    $this->payments->recordOrderPaymentStatusChange(
                        $pdo,
                        (int) $order['id'],
                        'pending',
                        $paymentStatus,
                        'Status pembayaran diperbarui berdasarkan verifikasi Midtrans.'
                    );

                    if (in_array(
                        $paymentStatus,
                        ['expired', 'failed'],
                        true
                    )) {
                        $items = $pdo->prepare(
                            "SELECT
                                variant_id,
                                SUM(quantity)::int AS quantity
                             FROM order_items
                             WHERE order_id = :order_id
                             GROUP BY variant_id
                             ORDER BY variant_id"
                        );

                        $items->execute([
                            'order_id' => (int) $order['id']
                        ]);

                        $itemRows = $items->fetchAll(
                            \PDO::FETCH_ASSOC
                        );

                        if ($itemRows === []) {
                            throw new RuntimeException(
                                'Order tidak memiliki item untuk pemulihan stok.'
                            );
                        }

                        foreach ($itemRows as $item) {
                            if (
                                $item['variant_id'] === null
                                || (int) $item['quantity'] <= 0
                            ) {
                                throw new RuntimeException(
                                    'Item order tidak valid; pemulihan stok dibatalkan.'
                                );
                            }
                        }

                        $restoreStock = $pdo->prepare(
                            "UPDATE product_variants
                             SET stock = stock + :quantity
                             WHERE id = :variant_id"
                        );

                        $movement = $pdo->prepare(
                            "INSERT INTO inventory_movements
                                (
                                    variant_id,
                                    change_qty,
                                    reason,
                                    reference_type,
                                    reference_id,
                                    note,
                                    created_at
                                )
                             VALUES
                                (
                                    :variant_id,
                                    :quantity,
                                    :reason,
                                    'order',
                                    :order_id,
                                    :note,
                                    NOW()
                                )"
                        );

                        foreach ($itemRows as $item) {
                            $variantId = (int) $item['variant_id'];
                            $quantity = (int) $item['quantity'];

                            if ($quantity <= 0) {
                                throw new RuntimeException(
                                    'Jumlah pemulihan stok tidak valid.'
                                );
                            }

                            $restoreStock->execute([
                                'quantity' => $quantity,
                                'variant_id' => $variantId,
                            ]);

                            if ($restoreStock->rowCount() !== 1) {
                                throw new RuntimeException(
                                    'Varian produk tidak ditemukan saat pemulihan stok.'
                                );
                            }

                            $movement->execute([
                                'variant_id' => $variantId,
                                'quantity' => $quantity,
                                'order_id' => (int) $order['id'],
                                'reason' => $paymentStatus === 'expired'
                                    ? 'order_expired'
                                    : 'order_cancelled',
                                'note' => 'Stok dipulihkan setelah status '
                                    . $paymentStatus
                                    . ' terverifikasi dari Midtrans.',
                            ]);
                        }
                    }
                }
            } elseif (
                in_array(
                    $currentOrderStatus,
                    ['expired', 'failed'],
                    true
                )
                && $paymentStatus === 'paid'
                && in_array(
                    $currentPaymentStatus,
                    ['pending', 'failed', 'expired', 'paid'],
                    true
                )
            ) {
                $this->payments->recordLatePaymentReviewOnce(
                    $pdo,
                    (int) $order['id'],
                    $currentOrderStatus
                );
            } elseif (
                $currentOrderStatus === 'paid'
                && $paymentStatus === 'refunded'
                && (
                    $shouldUpdatePayment
                    || $currentPaymentStatus === 'refunded'
                )
            ) {
                $st = $pdo->prepare(
                    "UPDATE orders
                     SET payment_status = 'refunded',
                         updated_at = NOW()
                     WHERE id = :id
                       AND payment_status = 'paid'"
                );

                $st->execute([
                    'id' => (int) $order['id'],
                ]);

                if ($st->rowCount() === 1) {
                    $this->payments->recordOrderPaymentStatusChange(
                        $pdo,
                        (int) $order['id'],
                        'paid',
                        'refunded',
                        'Status refund terverifikasi dari Midtrans; fulfillment perlu ditinjau.'
                    );
                }
            }

            $this->payments->markWebhookProcessed(
                $pdo,
                'midtrans',
                $eventKey
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function isTerminal(string $status): bool
    {
        return in_array(
            $status,
            ['paid', 'failed', 'expired', 'refunded'],
            true
        );
    }

    private function shouldUpdatePaymentStatus(
        string $currentStatus,
        string $newStatus
    ): bool {
        return match ($currentStatus) {
            'pending' => in_array(
                $newStatus,
                ['pending', 'paid', 'failed', 'expired'],
                true
            ),

            'paid' => $newStatus === 'refunded',

            'failed', 'expired', 'refunded' => false,

            default => false,
        };
    }

    private function mapStatus(
        string $status,
        string $fraudStatus
    ): string {
        return match ($status) {
            'settlement' => 'paid',

            'capture' => $fraudStatus === 'accept'
                ? 'paid'
                : 'pending',

            'pending' => 'pending',

            'deny', 'cancel' => 'failed',

            'expire' => 'expired',

            'refund', 'partial_refund' => 'refunded',

            default => throw new RuntimeException(
                'Status transaksi Midtrans tidak didukung.'
            ),
        };
    }

    private function sameAmount(string $a, string $b): bool
    {
        $normalize = static function (string $amount): ?string {
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
                return null;
            }

            [$whole, $fraction] = array_pad(
                explode('.', $amount, 2),
                2,
                ''
            );

            $whole = ltrim($whole, '0');
            $whole = $whole === '' ? '0' : $whole;

            return $whole . '.' . str_pad($fraction, 2, '0');
        };

        $left = $normalize($a);
        $right = $normalize($b);

        return $left !== null
            && $right !== null
            && hash_equals($left, $right);
    }
}
