<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = \App\Config\Database::connection();

$sql = "
    SELECT
        o.id AS order_id,
        o.order_number,
        o.payment_status,
        oi.variant_id,
        oi.product_name,
        oi.quantity AS ordered_quantity,
        v.stock AS current_stock,
        COALESCE(m.reserved_qty, 0) AS reserved_qty,
        COALESCE(m.expired_restored_qty, 0) AS expired_restored_qty,
        COALESCE(m.cancelled_restored_qty, 0) AS cancelled_restored_qty
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN product_variants v ON v.id = oi.variant_id
    LEFT JOIN LATERAL (
        SELECT
            SUM(CASE WHEN reason = 'order_reserved'
                THEN -change_qty ELSE 0 END) AS reserved_qty,
            SUM(CASE WHEN reason = 'order_expired'
                THEN change_qty ELSE 0 END) AS expired_restored_qty,
            SUM(CASE WHEN reason = 'order_cancelled'
                THEN change_qty ELSE 0 END) AS cancelled_restored_qty
        FROM inventory_movements
        WHERE reference_type = 'order'
          AND reference_id = o.id
          AND variant_id = oi.variant_id
    ) m ON TRUE
    WHERE o.id IN (7, 8)
    ORDER BY o.id, oi.variant_id
";

foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
