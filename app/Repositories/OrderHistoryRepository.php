
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

final class OrderHistoryRepository
{
    public function allByUser(int $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT
                o.id,
                o.order_number,
                o.created_at,
                o.grand_total,
                o.payment_status,
                o.fulfillment_status,
                o.payment_expires_at,
                o.shipping_courier,
                o.shipping_service,
                COALESCE(
                    (
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        WHERE oi.order_id = o.id
                    ),
                    0
                ) AS total_items
            FROM orders o
            WHERE o.user_id = :user_id
            ORDER BY o.created_at DESC, o.id DESC
        ");

        $stmt->execute([
            'user_id' => $userId,
        ]);

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($orders === []) {
            return [];
        }

        $ids = array_map(
            static fn(array $order): int => (int) $order['id'],
            $orders
        );

        $placeholders = implode(
            ',',
            array_fill(0, count($ids), '?')
        );

        $itemsStmt = $pdo->prepare("
            SELECT
                order_id,
                product_name,
                sku,
                size,
                color,
                quantity,
                unit_price,
                line_total
            FROM order_items
            WHERE order_id IN ($placeholders)
            ORDER BY id ASC
        ");

        $itemsStmt->execute($ids);

        $itemsByOrder = [];

        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsByOrder[(int) $item['order_id']][] = $item;
        }

        foreach ($orders as &$order) {
            $order['items'] =
                $itemsByOrder[(int) $order['id']] ?? [];
        }

        unset($order);

        return $orders;
    }
}
