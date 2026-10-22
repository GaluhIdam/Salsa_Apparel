<?php
declare(strict_types=1);

namespace App\Services;

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;
use RuntimeException;
use Throwable;

final class MidtransService
{
    private array $config;

    public function __construct()
    {
        $configPath = dirname(__DIR__, 2) . '/config/payment.php';

        if (!is_file($configPath)) {
            throw new RuntimeException(
                'File konfigurasi pembayaran tidak ditemukan.'
            );
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new RuntimeException(
                'Konfigurasi pembayaran tidak valid.'
            );
        }

        $this->config = $config;

        if (($this->config['provider'] ?? '') !== 'midtrans') {
            throw new RuntimeException(
                'Payment provider bukan Midtrans.'
            );
        }

        $midtrans = $this->config['midtrans'] ?? [];

        if (
            !is_array($midtrans)
            || !is_string($midtrans['server_key'] ?? null)
            || $midtrans['server_key'] === ''
            || !is_string($midtrans['client_key'] ?? null)
            || $midtrans['client_key'] === ''
        ) {
            throw new RuntimeException(
                'Konfigurasi Midtrans belum lengkap.'
            );
        }
    }

    /**
     * Membuat transaksi Snap.
     *
     * Panggil setelah order dan payment attempt tersimpan
     * serta transaksi database sudah commit.
     *
     * @return array{token: string, redirect_url: string}
     */
    public function createSnapTransaction(
        array $params,
        string $idempotencyKey
    ): array {
        if (!preg_match('/^[a-f0-9]{32}$/', $idempotencyKey)) {
            throw new RuntimeException(
                'Idempotency key tidak valid.'
            );
        }

        return $this->withSdkConfig(
            $idempotencyKey,
            static function () use ($params): array {
                $transaction = Snap::createTransaction($params);

                $token = $transaction->token ?? null;
                $redirectUrl = $transaction->redirect_url ?? null;

                if (
                    !is_string($token)
                    || $token === ''
                    || !is_string($redirectUrl)
                    || $redirectUrl === ''
                ) {
                    throw new RuntimeException(
                        'Respons Snap tidak memiliki token atau URL.'
                    );
                }

                return [
                    'token' => $token,
                    'redirect_url' => $redirectUrl,
                ];
            }
        );
    }

    /**
     * Memeriksa status transaksi berdasarkan provider order ID.
     *
     * Hasil pemeriksaan harus direkonsiliasi dengan database
     * sebelum status order atau stok diubah.
     */
    public function getTransactionStatus(
        string $providerOrderId
    ): object {
        $providerOrderId = trim($providerOrderId);

        if ($providerOrderId === '') {
            throw new RuntimeException(
                'Provider order ID wajib diisi.'
            );
        }

        return $this->withSdkConfig(
            null,
            static function () use ($providerOrderId): object {
                $status = Transaction::status($providerOrderId);

                if (!is_object($status)) {
                    throw new RuntimeException(
                        'Respons status transaksi tidak valid.'
                    );
                }

                return $status;
            }
        );
    }

    /**
     * Mengisolasi konfigurasi SDK static untuk setiap request.
     * Konfigurasi sebelumnya dipulihkan meskipun request gagal.
     */
    private function withSdkConfig(
        ?string $idempotencyKey,
        callable $callback
    ): mixed {
        $previous = [
            'serverKey' => Config::$serverKey,
            'clientKey' => Config::$clientKey,
            'isProduction' => Config::$isProduction,
            'paymentIdempotencyKey' => Config::$paymentIdempotencyKey,
            'curlOptions' => Config::$curlOptions,
        ];

        try {
            Config::$serverKey =
                $this->config['midtrans']['server_key'];

            Config::$clientKey =
                $this->config['midtrans']['client_key'];

            Config::$isProduction = (bool) ($this->config['midtrans']['is_production'] ?? false);
            Config::$paymentIdempotencyKey = $idempotencyKey;

            // Hindari membawa opsi request sebelumnya.
            Config::$curlOptions = [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [],
            ];

            return $callback();
        } finally {
            Config::$serverKey = $previous['serverKey'];
            Config::$clientKey = $previous['clientKey'];
            Config::$isProduction = $previous['isProduction'];

            Config::$paymentIdempotencyKey =
                $previous['paymentIdempotencyKey'];

            Config::$curlOptions = $previous['curlOptions'];
        }
    }
}
