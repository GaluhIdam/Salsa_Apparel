<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Services/OrderExpiryService.php';

use App\Core\Env;
use App\Services\OrderExpiryService;

Env::load(__DIR__ . '/../.env');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$run = in_array('--run', $argv ?? [], true);

$service = new OrderExpiryService();

try {
    if ($dryRun) {
        $count = $service->countExpiredPendingOrders();

        echo "Mode: DRY RUN\n";
        echo "Order pending kedaluwarsa: {$count}\n";
        echo "Database tidak diubah.\n";
        exit(0);
    }

    if (!$run) {
        echo "Tidak ada perubahan dilakukan.\n";
        echo "Gunakan --dry-run untuk pengecekan.\n";
        echo "Gunakan --run untuk memproses expiry.\n";
        exit(0);
    }

    $count = $service->expireBatch();

    echo "Order berhasil diproses: {$count}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Expiry gagal: {$e->getMessage()}\n");
    exit(1);
}