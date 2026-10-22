<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

\App\Core\Env::load(__DIR__ . '/../.env');

$pdo = \App\Config\Database::connection();

$total = $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();

$pending = $pdo->query(
    "SELECT COUNT(*)
    FROM orders
    WHERE payment_status = 'pending'"
)->fetchColumn();

echo "Total orders: {$total}\n";
echo "Pending orders: {$pending}\n";