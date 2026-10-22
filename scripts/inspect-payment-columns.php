<?php
require __DIR__ . '/../app/bootstrap.php';

$pdo = App\Config\Database::connection();

$sql = "SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payments'
        ORDER BY ordinal_position";

foreach ($pdo->query($sql) as $row) {
    echo $row['column_name'], PHP_EOL;
}
