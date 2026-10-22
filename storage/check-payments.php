<?php
require "app/bootstrap.php";

$pdo = App\Config\Database::connection();

$sql = "SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = :table
        ORDER BY ordinal_position";

$stmt = $pdo->prepare($sql);
$stmt->execute(["table" => "payments"]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo implode(" | ", $row) . PHP_EOL;
}
