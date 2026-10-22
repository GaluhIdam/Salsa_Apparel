<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = \App\Config\Database::connection();

$sql = "
    SELECT
        indexname,
        indexdef
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'inventory_movements'
    ORDER BY indexname
";

foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
