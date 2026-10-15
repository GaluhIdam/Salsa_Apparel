<?php
declare(strict_types=1);
// Uji koneksi + isi tabel: php scripts/db-check.php
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
App\Core\Env::load(__DIR__ . '/../.env');
$pdo = App\Config\Database::connection();
echo "Koneksi OK: ", $pdo->query('SELECT version()')->fetchColumn(), PHP_EOL;
foreach ($pdo->query('SELECT name, slug FROM categories ORDER BY sort_order') as $r) {
    echo " - {$r['name']} ({$r['slug']})", PHP_EOL;
}
