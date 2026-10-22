<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use DomainException;

final class PaymentRepository
{
    public function createAttempt(
        PDO $pdo,
        array $data
    ): int {
        $st = $pdo->prepare(
            "INSERT INTO payments (
                order_id,
                provider,
                amount,
                status,
                provider_order_id,
                idempotency_key,
                attempt_number,
                expires_at,
                created_at,
                updated_at
            ) VALUES (
                :order_id,
                :provider,
                :amount,
                'pending',
                :provider_order_id,
                :idempotency_key,
                :attempt_number,
                :expires_at,
                NOW(),
                NOW()
            )
            RETURNING id"
        );

        $st->execute([
            'order_id' => $data['order_id'],
            'provider' => $data['provider'],
            'amount' => $data['amount'],
            'provider_order_id' => $data['provider_order_id'],
            'idempotency_key' => $data['idempotency_key'],
            'attempt_number' => $data['attempt_number'],
            'expires_at' => $data['expires_at'],
        ]);

        return (int) $st->fetchColumn();
    }

    public function findById(
        PDO $pdo,
        int $paymentId,
        bool $forUpdate = false
    ): ?array {
        $sql = 'SELECT * FROM payments WHERE id = :id';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $st = $pdo->prepare($sql);
        $st->execute(['id' => $paymentId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findByProviderOrderId(
        PDO $pdo,
        string $provider,
        string $providerOrderId,
        bool $forUpdate = false
    ): ?array {
        $sql = 'SELECT *
                FROM payments
                WHERE provider = :provider
                AND provider_order_id = :provider_order_id';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $st = $pdo->prepare($sql);
        $st->execute([
            'provider' => $provider,
            'provider_order_id' => $providerOrderId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function claimSnapRequest(
        PDO $pdo,
        int $paymentId
    ): bool {
        $st = $pdo->prepare(
            "UPDATE payments
             SET snap_request_state = 'in_progress',
                 snap_request_started_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
             AND status = 'pending'
             AND snap_request_state = 'not_started'
             AND snap_token IS NULL
             AND redirect_url IS NULL"
        );

        $st->execute(['id' => $paymentId]);

        return $st->rowCount() === 1;
    }

    public function markSnapRequestUnknown(
        PDO $pdo,
        int $paymentId
    ): void {
        $st = $pdo->prepare(
            "UPDATE payments
             SET snap_request_state = 'unknown',
                 updated_at = NOW()
             WHERE id = :id
             AND status = 'pending'
             AND snap_request_state = 'in_progress'
             AND snap_token IS NULL"
        );

        $st->execute(['id' => $paymentId]);
    }

    public function saveSnapResult(
        PDO $pdo,
        int $paymentId,
        string $snapToken,
        string $redirectUrl
    ): void {
        $st = $pdo->prepare(
            "UPDATE payments
             SET snap_token = :snap_token,
                 redirect_url = :redirect_url,
                 snap_request_state = 'completed',
                 updated_at = NOW()
             WHERE id = :id
             AND status = 'pending'
             AND snap_request_state = 'in_progress'
             AND snap_token IS NULL
             AND redirect_url IS NULL"
        );

        $st->execute([
            'snap_token' => $snapToken,
            'redirect_url' => $redirectUrl,
            'id' => $paymentId,
        ]);

        if ($st->rowCount() !== 1) {
            throw new DomainException(
                'Hasil pembayaran tidak dapat disimpan.'
            );
        }
    }

    public function saveProviderStatus(
        PDO $pdo,
        int $paymentId,
        ?string $transactionId,
        string $providerStatus,
        array $payload
    ): void {
        $st = $pdo->prepare(
            "UPDATE payments
             SET provider_transaction_id =
                    COALESCE(:transaction_id, provider_transaction_id),
                 provider_status = :provider_status,
                 raw_payload = CAST(:payload AS JSONB),
                 updated_at = NOW()
             WHERE id = :id"
        );

        $st->execute([
            'transaction_id' => $transactionId,
            'provider_status' => $providerStatus,
            'payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'id' => $paymentId,
        ]);
    }

    public function recordWebhookEvent(
        PDO $pdo,
        string $provider,
        string $eventKey,
        array $payload
    ): bool {
        $st = $pdo->prepare(
            "INSERT INTO payment_webhook_events (
                provider,
                event_key,
                payload,
                created_at
            ) VALUES (
                :provider,
                :event_key,
                CAST(:payload AS JSONB),
                NOW()
            )
            ON CONFLICT (provider, event_key) DO NOTHING"
        );

        $st->execute([
            'provider' => $provider,
            'event_key' => $eventKey,
            'payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
        ]);

        return $st->rowCount() === 1;
    }

    public function markWebhookProcessed(
        PDO $pdo,
        string $provider,
        string $eventKey
    ): void {
        $st = $pdo->prepare(
            "UPDATE payment_webhook_events
             SET processed_at = NOW()
             WHERE provider = :provider
             AND event_key = :event_key"
        );

        $st->execute([
            'provider' => $provider,
            'event_key' => $eventKey,
        ]);
    }

    public function lockPayableOrder(
        PDO $pdo,
        int $orderId,
        string $accessTokenHash
    ): ?array {
        $st = $pdo->prepare(
            "SELECT *
             FROM orders
             WHERE id = :id
             AND access_token_hash = :token_hash
             FOR UPDATE"
        );

        $st->execute([
            'id' => $orderId,
            'token_hash' => $accessTokenHash,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findOrderItems(
        PDO $pdo,
        int $orderId
    ): array {
        $st = $pdo->prepare(
            "SELECT product_name, sku, unit_price, quantity, line_total
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id"
        );

        $st->execute(['order_id' => $orderId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPendingAttemptForOrder(
        PDO $pdo,
        int $orderId
    ): ?array {
        $st = $pdo->prepare(
            "SELECT *
             FROM payments
             WHERE order_id = :order_id
             AND provider = 'midtrans'
             AND status = 'pending'
             ORDER BY attempt_number DESC, id DESC
             LIMIT 1
             FOR UPDATE"
        );

        $st->execute(['order_id' => $orderId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findLatestAttemptForOrder(
        PDO $pdo,
        int $orderId
    ): ?array {
        $st = $pdo->prepare(
            "SELECT *
             FROM payments
             WHERE order_id = :order_id
             AND provider = 'midtrans'
             ORDER BY attempt_number DESC, id DESC
             LIMIT 1"
        );

        $st->execute(['order_id' => $orderId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findWebhookEvent(
        PDO $pdo,
        string $provider,
        string $eventKey
    ): ?array {
        $st = $pdo->prepare(
            "SELECT *
             FROM payment_webhook_events
             WHERE provider = :provider
             AND event_key = :event_key
             FOR UPDATE"
        );

        $st->execute([
            'provider' => $provider,
            'event_key' => $eventKey,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function updateVerifiedPaymentStatus(
        PDO $pdo,
        int $paymentId,
        string $currentStatus,
        string $status,
        string $providerStatus,
        ?string $transactionId,
        array $payload
    ): void {
        $allowedTransitions = [
            'pending' => [
                'pending',
                'paid',
                'failed',
                'expired',
            ],
            'paid' => [
                'refunded',
            ],
            'failed' => [],
            'expired' => [],
            'refunded' => [],
        ];

        if (
            !isset($allowedTransitions[$currentStatus])
            || !in_array(
                $status,
                $allowedTransitions[$currentStatus],
                true
            )
        ) {
            throw new DomainException(
                'Transisi status pembayaran tidak valid.'
            );
        }

        $st = $pdo->prepare(
            "UPDATE payments
             SET status = :status,
                 provider_status = :provider_status,
                 provider_transaction_id =
                    COALESCE(:transaction_id, provider_transaction_id),
                 raw_payload = CAST(:payload AS JSONB),
                 verified_at = CASE
                    WHEN :is_paid = 'paid' THEN NOW()
                    ELSE verified_at
                 END,
                 updated_at = NOW()
             WHERE id = :id
             AND status = :current_status"
        );

        $st->execute([
            'status' => $status,
            'current_status' => $currentStatus,
            'provider_status' => $providerStatus,
            'transaction_id' => $transactionId,
            'payload' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            ),
            'is_paid' => $status,
            'id' => $paymentId,
        ]);

        if ($st->rowCount() !== 1) {
            throw new DomainException(
                'Status pembayaran berubah atau gagal diperbarui.'
            );
        }
    }

    public function lockOrderForPaymentUpdate(
        PDO $pdo,
        int $orderId
    ): ?array {
        $st = $pdo->prepare(
            "SELECT *
             FROM orders
             WHERE id = :id
             FOR UPDATE"
        );

        $st->execute(['id' => $orderId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function recordOrderPaymentStatusChange(
        PDO $pdo,
        int $orderId,
        string $fromStatus,
        string $toStatus,
        string $note
    ): void {
        $st = $pdo->prepare(
            "INSERT INTO order_status_history (
                order_id,
                status_type,
                from_status,
                to_status,
                changed_by,
                note,
                created_at
            ) VALUES (
                :order_id,
                'payment',
                :from_status,
                :to_status,
                NULL,
                :note,
                NOW()
            )"
        );

        $st->execute([
            'order_id' => $orderId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }

    public function recordLatePaymentReviewOnce(
        PDO $pdo,
        int $orderId,
        string $orderStatus
    ): void {
        $note = 'PERLU TINJAUAN MANUAL: Midtrans mengonfirmasi pembayaran '
            . 'setelah order berstatus '
            . $orderStatus
            . '. Order tidak diaktifkan kembali secara otomatis.';

        $check = $pdo->prepare(
            "SELECT id
             FROM order_status_history
             WHERE order_id = :order_id
             AND status_type = 'payment'
             AND from_status = :from_status
             AND to_status = :to_status
             AND note = :note
             LIMIT 1"
        );

        $check->execute([
            'order_id' => $orderId,
            'from_status' => $orderStatus,
            'to_status' => $orderStatus,
            'note' => $note,
        ]);

        if ($check->fetchColumn() !== false) {
            return;
        }

        $this->recordOrderPaymentStatusChange(
            $pdo,
            $orderId,
            $orderStatus,
            $orderStatus,
            $note
        );
    }

    public function findOrderForStatus(
    PDO $pdo,
    int $orderId,
    string $accessToken
    ): ?array {
        $st = $pdo->prepare(
            "SELECT
                id,
                order_number,
                payment_status,
                fulfillment_status,
                grand_total,
                payment_expires_at
            FROM orders
            WHERE id = :id
            AND access_token_hash = :token_hash
            LIMIT 1"
        );

        $st->execute([
            'id' => $orderId,
            'token_hash' => hash('sha256', $accessToken),
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
