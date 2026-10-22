<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DomainException;
use PDO;
use Throwable;

final class OrderExpiryService
{
    /**
     * Menghitung order pending yang sudah kedaluwarsa.
     * Dry-run tidak mengubah data apa pun.
     */
    public function countExpiredPendingOrders(): int
    {
        $pdo = Database::connection();

        $st = $pdo->query(
            "SELECT COUNT(*)
            FROM orders
            WHERE payment_status = 'pending'
            AND payment_expires_at IS NOT NULL
            AND payment_expires_at <= NOW()"
        );

        return (int) $st->fetchColumn();
    }

    /**
     * Memproses order kedaluwarsa secara atomik.
     */
    public function expireOrder(int $orderId): bool
    {
        return Database::transaction(function (PDO $pdo) use ($orderId): bool {
            $st = $pdo->prepare(
                "SELECT id
                 FROM orders
                 WHERE id = :id
                   AND payment_status = 'pending'
                   AND payment_expires_at IS NOT NULL
                   AND payment_expires_at <= NOW()
                 FOR UPDATE"
            );

            $st->execute(['id' => $orderId]);

            if ($st->fetchColumn() === false) {
                return false;
            }

            $st = $pdo->prepare(
                'SELECT variant_id, SUM(quantity)::int AS quantity
                 FROM order_items
                 WHERE order_id = :id
                 GROUP BY variant_id
                 ORDER BY variant_id'
            );
            $st->execute(['id' => $orderId]);

            $items = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($items === []) {
                throw new DomainException(
                    "Order {$orderId} tidak memiliki item."
                );
            }

            foreach ($items as $item) {
                if ($item['variant_id'] === null) {
                    throw new DomainException(
                        "Order {$orderId} memiliki item tanpa variant_id."
                    );
                }
            }

            // Kembalikan stok dan catat pergerakannya.
            foreach ($items as $item) {
                $variantId = (int) $item['variant_id'];
                $quantity = (int) $item['quantity'];

                $st = $pdo->prepare(
                    'UPDATE product_variants
                     SET stock = stock + :quantity
                     WHERE id = :variant_id'
                );
                $st->execute([
                    'quantity' => $quantity,
                    'variant_id' => $variantId,
                ]);

                if ($st->rowCount() !== 1) {
                    throw new DomainException(
                        "Varian {$variantId} tidak ditemukan."
                    );
                }

                $st = $pdo->prepare(
                    "INSERT INTO inventory_movements
                        (variant_id, change_qty, reason,
                         reference_type, reference_id, note)
                     VALUES
                        (:variant_id, :quantity, 'order_expired',
                         'order', :order_id, :note)"
                );
                $st->execute([
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'order_id' => $orderId,
                    'note' => 'Stock restored after payment expiry',
                ]);
            }

            $st = $pdo->prepare(
                "UPDATE orders
                 SET payment_status = 'expired'
                 WHERE id = :id
                   AND payment_status = 'pending'"
            );
            $st->execute(['id' => $orderId]);

            if ($st->rowCount() !== 1) {
                throw new DomainException(
                    "Status order {$orderId} gagal diperbarui."
                );
            }

            $st = $pdo->prepare(
                "INSERT INTO order_status_history
                    (order_id, status_type, from_status, to_status, note)
                 VALUES
                    (:order_id, 'payment', 'pending', 'expired', :note)"
            );
            $st->execute([
                'order_id' => $orderId,
                'note' => 'Payment expired; reserved stock restored',
            ]);

            return true;
        });
    }

    /**
     * Memproses sejumlah order kedaluwarsa.
     */
    public function expireBatch(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new DomainException('Limit harus antara 1 dan 1000.');
        }

        $pdo = Database::connection();

        $st = $pdo->prepare(
            "SELECT id
            FROM orders
            WHERE payment_status = 'pending'
            AND payment_expires_at IS NOT NULL
            AND payment_expires_at <= NOW()
            ORDER BY payment_expires_at ASC, id ASC
            LIMIT :limit"
        );
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();

        $orderIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $processed = 0;

        foreach ($orderIds as $orderId) {
            if ($this->expireOrder($orderId)) {
                $processed++;
            }
        }

        return $processed;
    }
}