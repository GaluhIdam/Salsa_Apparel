<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MidtransWebhookService
{
    private array $config;

    public function __construct(
        private readonly MidtransService $midtrans = new MidtransService()
    ) {
        $configPath = dirname(__DIR__, 2) . '/config/payment.php';

        $config = require $configPath;

        if (
            !is_array($config)
            || ($config['provider'] ?? '') !== 'midtrans'
            || !is_string($config['midtrans']['server_key'] ?? null)
            || $config['midtrans']['server_key'] === ''
        ) {
            throw new RuntimeException(
                'Konfigurasi webhook Midtrans tidak valid.'
            );
        }

        $this->config = $config['midtrans'];
    }

    public function verifyAndFetchStatus(array $notification): object
    {
        foreach (
            ['order_id', 'status_code', 'gross_amount', 'signature_key']
            as $field
        ) {
            if (
                !isset($notification[$field])
                || !is_string($notification[$field])
                && !is_numeric($notification[$field])
            ) {
                throw new RuntimeException(
                    'Notifikasi Midtrans tidak lengkap.'
                );
            }
        }

        $orderId = (string) $notification['order_id'];
        $statusCode = (string) $notification['status_code'];
        $grossAmount = (string) $notification['gross_amount'];
        $signature = (string) $notification['signature_key'];

        $expectedSignature = hash(
            'sha512',
            $orderId
            . $statusCode
            . $grossAmount
            . $this->config['server_key']
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException(
                'Signature notifikasi Midtrans tidak valid.'
            );
        }

        // Ambil status langsung dari API Midtrans.
        // Jangan mengubah status order hanya dari payload webhook.
        $status = $this->midtrans->getTransactionStatus($orderId);

        if (
            !isset($status->order_id, $status->gross_amount)
            || (string) $status->order_id !== $orderId
            || !$this->sameAmount(
                (string) $status->gross_amount,
                $grossAmount
            )
        ) {
            throw new RuntimeException('Data transaksi Midtrans tidak cocok.');
        }

        return $status;
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
            $fraction = str_pad($fraction, 2, '0');

            return $whole . '.' . $fraction;
        };

        $normalizedA = $normalize($a);
        $normalizedB = $normalize($b);

        return $normalizedA !== null
            && $normalizedB !== null
            && hash_equals($normalizedA, $normalizedB);
    }
}