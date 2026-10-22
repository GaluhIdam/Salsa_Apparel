<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\PaymentRepository;
use DomainException;
use RuntimeException;
use Throwable;

final class PaymentAttemptService
{
    public function __construct(
        private readonly PaymentRepository $payments = new PaymentRepository(),
        private readonly MidtransService $midtrans = new MidtransService()
    ) {
    }

    public function startPayment(
        int $orderId,
        string $accessToken
    ): array {
        if (
            $orderId < 1
            || !preg_match('/^[a-f0-9]{64}$/', $accessToken)
        ) {
            throw new DomainException('Data order tidak valid.');
        }

        $pdo = Database::connection();

        // Transaksi pertama: validasi order dan siapkan attempt.
        $attempt = Database::transaction(
            function () use ($pdo, $orderId, $accessToken): array {
                $order = $this->payments->lockPayableOrder(
                    $pdo,
                    $orderId,
                    hash('sha256', $accessToken)
                );

                if ($order === null) {
                    throw new DomainException(
                        'Order tidak ditemukan.'
                    );
                }

                if ($order['payment_status'] !== 'pending') {
                    throw new DomainException(
                        'Order tidak lagi menunggu pembayaran.'
                    );
                }

                $expiry = strtotime(
                    (string) $order['payment_expires_at']
                );

                if ($expiry === false || $expiry <= time()) {
                    throw new DomainException(
                        'Masa pembayaran order sudah berakhir.'
                    );
                }

                $items = $this->payments->findOrderItems(
                    $pdo,
                    $orderId
                );

                if ($items === []) {
                    throw new DomainException(
                        'Item order tidak ditemukan.'
                    );
                }

                $grossAmount = 0;
                $itemDetails = [];

                foreach ($items as $item) {
                    $price = (int) $item['unit_price'];
                    $quantity = (int) $item['quantity'];
                    $lineTotal = (int) $item['line_total'];

                    if (
                        $price < 0
                        || $quantity < 1
                        || $price * $quantity !== $lineTotal
                    ) {
                        throw new RuntimeException(
                            'Snapshot harga order tidak konsisten.'
                        );
                    }

                    $grossAmount += $lineTotal;

                    $itemDetails[] = [
                        'id' => substr(
                            preg_replace(
                                '/[^a-zA-Z0-9_-]/',
                                '',
                                (string) $item['sku']
                            ) ?: 'ITEM',
                            0,
                            50
                        ),
                        'price' => $price,
                        'quantity' => $quantity,
                        'name' => mb_substr(
                            (string) $item['product_name'],
                            0,
                            50
                        ),
                    ];
                }

                // Ongkir gratis sementara; diskon belum diterapkan.
                $expectedTotal =
                    (int) $order['subtotal']
                    - (int) $order['discount_total']
                    + (int) $order['shipping_cost'];

                if (
                    $grossAmount !== (int) $order['subtotal']
                    || $grossAmount !== $expectedTotal
                    || $grossAmount !== (int) $order['grand_total']
                    || $grossAmount < 1
                ) {
                    throw new RuntimeException(
                        'Total order tidak cocok dengan detail pembayaran.'
                    );
                }

                $attempt = $this->payments->findPendingAttemptForOrder(
                    $pdo,
                    $orderId
                );

                if ($attempt !== null) {
                    if (
                        (int) $attempt['amount'] !== $grossAmount
                        || $attempt['provider_order_id'] === null
                        || $attempt['idempotency_key'] === null
                    ) {
                        throw new RuntimeException(
                            'Payment attempt tersimpan tidak konsisten.'
                        );
                    }

                    return [
                        'attempt' => $attempt,
                        'order' => $order,
                        'items' => $itemDetails,
                    ];
                }

                // Order ID provider dan idempotency key dibuat sekali,
                // lalu digunakan kembali jika request mengalami timeout.
                $attemptNumber = 1;
                $providerOrderId = 'SALPAY-'
                    . $order['order_number']
                    . '-' . $attemptNumber;

                $idempotencyKey = bin2hex(random_bytes(16));

                $paymentId = $this->payments->createAttempt(
                    $pdo,
                    [
                        'order_id' => $orderId,
                        'provider' => 'midtrans',
                        'amount' => $grossAmount,
                        'provider_order_id' => $providerOrderId,
                        'idempotency_key' => $idempotencyKey,
                        'attempt_number' => $attemptNumber,
                        'expires_at' => $order['payment_expires_at'],
                    ]
                );

                $attempt = $this->payments->findById(
                    $pdo,
                    $paymentId
                );

                if ($attempt === null) {
                    throw new RuntimeException(
                        'Payment attempt gagal dibaca kembali.'
                    );
                }

                return [
                    'attempt' => $attempt,
                    'order' => $order,
                    'items' => $itemDetails,
                ];
            }
        );

        $payment = $attempt['attempt'];


        $paymentId = (int) $attempt['attempt']['id'];
        $pdo = Database::connection();

        // Klaim request secara atomik sebelum menghubungi Midtrans.
        $claim = Database::transaction(
            function () use ($pdo, $paymentId): array {
                $current = $this->payments->findById(
                    $pdo,
                    $paymentId,
                    true
                );

                if ($current === null) {
                    throw new RuntimeException(
                        'Payment attempt tidak ditemukan.'
                    );
                }

                // Request lain mungkin sudah berhasil menyimpan token.
                if (
                    ($current['snap_request_state'] ?? '') === 'completed'
                    && is_string($current['snap_token'] ?? null)
                    && $current['snap_token'] !== ''
                    && is_string($current['redirect_url'] ?? null)
                    && $current['redirect_url'] !== ''
                ) {
                    return [
                        'ready' => true,
                        'payment' => $current,
                    ];
                }

                if ($current['status'] !== 'pending') {
                    throw new RuntimeException(
                        'Status payment sudah berubah.'
                    );
                }

                if (
                    ($current['snap_request_state'] ?? 'not_started')
                    !== 'not_started'
                ) {
                    throw new RuntimeException(
                        'Request Snap belum aman untuk dicoba ulang. '
                        . 'Status perlu diperiksa terlebih dahulu.'
                    );
                }

                if (!$this->payments->claimSnapRequest($pdo, $paymentId)) {
                    throw new RuntimeException(
                        'Request Snap gagal diklaim.'
                    );
                }

                return [
                    'ready' => false,
                    'payment' => $current,
                ];
            }
        );

        $payment = $claim['payment'];

        if ($claim['ready']) {
            return [
                'payment_id' => (int) $payment['id'],
                'provider_order_id' => $payment['provider_order_id'],
                'snap_token' => $payment['snap_token'],
                'redirect_url' => $payment['redirect_url'],
            ];
        }

        $order = $attempt['order'];

        try {
            $snap = $this->midtrans->createSnapTransaction(
                [
                    'transaction_details' => [
                        'order_id' => $payment['provider_order_id'],
                        'gross_amount' => (int) $payment['amount'],
                    ],
                    'item_details' => $attempt['items'],
                    'customer_details' => [
                        'first_name' => mb_substr(
                            (string) $order['recipient_name'],
                            0,
                            50
                        ),
                        'email' => (string) $order['customer_email'],
                        'phone' => (string) $order['recipient_phone'],
                        'shipping_address' => [
                            'first_name' => mb_substr(
                                (string) $order['recipient_name'],
                                0,
                                50
                            ),
                            'phone' => (string) $order['recipient_phone'],
                            'address' => mb_substr(
                                (string) $order['ship_address'],
                                0,
                                200
                            ),
                            'city' => (string) $order['ship_city'],
                            'postal_code' => (string) $order['ship_postal_code'],
                            'country_code' => 'IDN',
                        ],
                    ],
                    'callbacks' => [
                        'finish' => rtrim(
                            (string) \App\Core\Env::get('APP_URL', ''),
                            '/'
                        ) . '/order-status',
                        'unfinish' => rtrim(
                            (string) \App\Core\Env::get('APP_URL', ''),
                            '/'
                        ) . '/order-status',
                        'error' => rtrim(
                            (string) \App\Core\Env::get('APP_URL', ''),
                            '/'
                        ) . '/order-status',
                    ],
                    'expiry' => [
                        // Anchor the deadline to the order, not payment-method selection.
                        'start_time' => date('Y-m-d H:i:s O', strtotime((string) $order['payment_expires_at']) - 86400),
                        'duration' => 24,
                        'unit' => 'hour',
                    ],
                ],
                (string) $payment['idempotency_key']
            );

            if (
                !is_string($snap['token'] ?? null)
                || $snap['token'] === ''
                || !is_string($snap['redirect_url'] ?? null)
                || $snap['redirect_url'] === ''
            ) {
                throw new RuntimeException(
                    'Respons Snap tidak lengkap.'
                );
            }

            // Simpan hasil dan baca kembali dari database.
            $savedPayment = Database::transaction(
                function () use ($pdo, $paymentId, $snap): array {
                    $current = $this->payments->findById(
                        $pdo,
                        $paymentId,
                        true
                    );

                    if ($current === null) {
                        throw new RuntimeException(
                            'Payment attempt tidak ditemukan.'
                        );
                    }

                    if (
                        ($current['snap_request_state'] ?? '') === 'completed'
                        && is_string($current['snap_token'] ?? null)
                        && $current['snap_token'] !== ''
                        && is_string($current['redirect_url'] ?? null)
                        && $current['redirect_url'] !== ''
                    ) {
                        return $current;
                    }

                    if (
                        $current['status'] !== 'pending'
                        || ($current['snap_request_state'] ?? '') !== 'in_progress'
                    ) {
                        throw new RuntimeException(
                            'Status payment berubah sebelum hasil Snap tersimpan.'
                        );
                    }

                    $this->payments->saveSnapResult(
                        $pdo,
                        $paymentId,
                        $snap['token'],
                        $snap['redirect_url']
                    );

                    $saved = $this->payments->findById(
                        $pdo,
                        $paymentId
                    );

                    if (
                        $saved === null
                        || ($saved['snap_request_state'] ?? '') !== 'completed'
                        || !is_string($saved['snap_token'] ?? null)
                        || $saved['snap_token'] === ''
                        || !is_string($saved['redirect_url'] ?? null)
                        || $saved['redirect_url'] === ''
                    ) {
                        throw new RuntimeException(
                            'Hasil Snap belum terkonfirmasi tersimpan.'
                        );
                    }

                    return $saved;
                }
            );

            return [
                'payment_id' => (int) $savedPayment['id'],
                'provider_order_id' => $savedPayment['provider_order_id'],
                'snap_token' => $savedPayment['snap_token'],
                'redirect_url' => $savedPayment['redirect_url'],
            ];
        } catch (Throwable $e) {
            // Hasil request tidak pasti: jangan otomatis mengulang Snap.
            try {
                Database::transaction(
                    function () use ($pdo, $paymentId): void {
                        $this->payments->markSnapRequestUnknown(
                            $pdo,
                            $paymentId
                        );
                    }
                );
            } catch (Throwable $markError) {
                // Pertahankan exception awal. Status harus diperiksa manual.
            }

            throw new RuntimeException(
                'Pembayaran belum dapat dipastikan. '
                . 'Order tetap tersimpan; status perlu direkonsiliasi '
                . 'sebelum request Snap dicoba ulang.',
                0,
                $e
            );
        }
    }
}
