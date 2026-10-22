<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

\App\Core\Env::load(__DIR__ . '/../.env');

$pdo = \App\Config\Database::connection();

$queries = [
    'Total inventory movements' =>
        'SELECT COUNT(*) FROM inventory_movements',

    'Order reservations' =>
        "SELECT COUNT(*) FROM inventory_movements
         WHERE reason = 'order_reserved'
           AND reference_type = 'order'",

    'Expiry restorations' =>
        "SELECT COUNT(*) FROM inventory_movements
         WHERE reason = 'order_expired'
           AND reference_type = 'order'",

    'Cancellation restorations' =>
        "SELECT COUNT(*) FROM inventory_movements
         WHERE reason = 'order_cancelled'
           AND reference_type = 'order'",
];

foreach ($queries as $label => $sql) {
    $count = $pdo->query($sql)->fetchColumn();
    echo "{$label}: {$count}\n";
}