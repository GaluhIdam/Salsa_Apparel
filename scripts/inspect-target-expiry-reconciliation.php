<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Config\Database;

$pdo = Database::connection();

$sql = "
    SELECT
        o.id AS order_id,
        o.order_number,
        o.payment_status AS order_status,
        p.id AS payment_id,
        p.provider,
        p.provider_order_id,
        p.status AS payment_status,
        p.amount,
        p.provider_transaction_id,
        (
            SELECT COUNT(*)
            FROM inventory_movements im
            WHERE im.reference_type = 'order'
              AND im.reference_id = o.id
              AND im.reason = 'order_expired'
        ) AS expiry_movements,
        (
            SELECT COUNT(*)
            FROM payment_webhook_events e
            WHERE e.provider = p.provider
              AND e.processed_at IS NOT NULL
              AND e.payload->>'order_id' = p.provider_order_id
              AND lower(e.payload->>'transaction_status') = 'expire'
        ) AS processed_expiry_events
    FROM orders o
    JOIN payments p ON p.order_id = o.id
    WHERE o.id IN (7, 8)
    ORDER BY o.id, p.id DESC
";

foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode(
        $row,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    ), PHP_EOL;
}
