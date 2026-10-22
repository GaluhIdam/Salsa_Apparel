<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Config\Database;
use App\Services\MidtransService;

$pdo = Database::connection();

$sql = "
    SELECT
        o.id,
        o.order_number,
        o.payment_status,
        o.payment_expires_at,
        p.provider_order_id
    FROM orders o
    JOIN LATERAL (
        SELECT provider_order_id
        FROM payments
        WHERE order_id = o.id
        ORDER BY id DESC
        LIMIT 1
    ) p ON TRUE
    WHERE o.id IN (7, 8)
    ORDER BY o.id
";

$midtrans = new MidtransService();

foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $order) {
    echo "\nOrder: {$order['order_number']}\n";
    echo "Status database: {$order['payment_status']}\n";
    echo "Provider order ID: {$order['provider_order_id']}\n";

    try {
        $status = $midtrans->getTransactionStatus(
            (string) $order['provider_order_id']
        );

        echo 'Status Midtrans: ',
            json_encode($status, JSON_UNESCAPED_UNICODE),
            PHP_EOL;
    } catch (Throwable $e) {
        echo 'Gagal memeriksa Midtrans: ',
            $e->getMessage(),
            PHP_EOL;
    }
}
