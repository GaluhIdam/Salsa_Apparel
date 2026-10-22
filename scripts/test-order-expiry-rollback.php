<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Services/OrderExpiryService.php';

use App\Core\Env;
use App\Config\Database;
use App\Services\OrderExpiryService;

Env::load(__DIR__ . '/../.env');

$pdo = Database::connection();

// Tes hanya boleh berjalan jika tidak ada transaksi lain.
if ($pdo->inTransaction()) {
    fwrite(STDERR, "GAGAL: koneksi sedang dalam transaksi.\n");
    exit(1);
}

try {
    $pdo->beginTransaction();

    $service = new OrderExpiryService();
    $count = $service->countExpiredPendingOrders();

    echo "Order pending kedaluwarsa: {$count}\n";
    echo "Mode: READ-ONLY, belum ada order yang diproses.\n";

    // Batalkan transaksi tes.
    $pdo->rollBack();

    echo "Rollback: berhasil.\n";
    echo "Tidak ada order atau stok yang diubah.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "Tes gagal: {$e->getMessage()}\n");
    exit(1);
}