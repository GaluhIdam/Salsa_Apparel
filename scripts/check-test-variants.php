<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

\App\Core\Env::load(__DIR__ . '/../.env');

$pdo = \App\Config\Database::connection();

$sql = "
    SELECT
        pv.id,
        pv.sku,
        pv.stock,
        pv.is_active,
        p.name AS product_name
    FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    WHERE pv.is_active = TRUE
    ORDER BY pv.id
    LIMIT 10
";

foreach ($pdo->query($sql) as $row) {
    echo sprintf(
        "ID: %s | SKU: %s | Produk: %s | Stok: %s\n",
        $row['id'],
        $row['sku'],
        $row['product_name'],
        $row['stock']
    );
}