<?php
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

App\Core\Env::load(__DIR__ . '/../.env');

$pdo = App\Config\Database::connection();

$sql = "SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'product_variants'
        ORDER BY ordinal_position";

print_r($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
