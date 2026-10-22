<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

\App\Core\Env::load(__DIR__ . '/../.env');
$pdo = \App\Config\Database::connection();

$sql = "
    SELECT
        o.id,
        o.order_number,
        o.payment_status,
        o.payment_expires_at,
        NOW() AS db_now,
        p.status AS payment_record_status,
        p.provider_status,
        p.provider_order_id,
        p.expires_at AS payment_expires_at_record
    FROM orders o
    LEFT JOIN LATERAL (
        SELECT status, provider_status, provider_order_id, expires_at
        FROM payments
        WHERE order_id = o.id
        ORDER BY id DESC
        LIMIT 1
    ) p ON TRUE
    WHERE o.payment_status IN ('pending', 'expired', 'failed')
    ORDER BY o.id DESC
    LIMIT 20
";

foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
