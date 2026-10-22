<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (array_slice($argv, 1) === ['--help']) {
    echo "Pemakaian: php scripts/seed.php\n",
        "Menjalankan database/seed.sql sekali per database setelah migrasi.\n",
        "Data: 3 role, 10 permission, akses role, 7 kategori, 2 tarif kirim,\n",
        "5 produk, 17 varian/stok, teks situs, dan gambar placeholder.\n",
        "Tidak membuat akun admin atau pelanggan.\n";
    exit(0);
}
if (count($argv) !== 1) {
    fwrite(STDERR, "Argumen tidak dikenal. Gunakan --help.\n");
    exit(1);
}

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

$pdo = null;
$locked = false;
try {
    App\Core\Env::load(__DIR__ . '/../.env');
    $pdo = App\Config\Database::connection();
    $locked = (bool) $pdo->query('SELECT pg_try_advisory_lock(1935764595, 1)')->fetchColumn();
    if (!$locked) {
        throw new RuntimeException('Migrasi/seeder lain sedang berjalan. Coba lagi setelah selesai.');
    }
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_seeders (
        seeder VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )');
    $applied = $pdo->query("SELECT 1 FROM schema_seeders WHERE seeder = 'seed.sql'")->fetchColumn();
    if ($applied !== false) {
        $pdo->commit();
        echo "SKIP  database/seed.sql (sudah dijalankan)\n";
    } else {
        // The placeholder migration may have run before the demo products existed.
        // Reapply its idempotent SQL after inserting the seed products.
        foreach (['seed.sql', 'migrations/002_placeholder_images.sql'] as $relative) {
            $sql = file_get_contents(__DIR__ . '/../database/' . $relative);
            if ($sql === false) {
                throw new RuntimeException("Tidak dapat membaca database/$relative.");
            }
            $sql = preg_replace('/^BEGIN;\s*$/mi', '', $sql, -1, $begins);
            $sql = preg_replace('/^COMMIT;\s*$/mi', '', $sql, -1, $commits);
            if ($sql === null || $begins !== 1 || $commits !== 1) {
                throw new RuntimeException("Wrapper transaksi tidak valid: $relative.");
            }
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO schema_seeders (seeder) VALUES ('seed.sql')");
        $pdo->commit();
        echo "OK  database/seed.sql + gambar placeholder\n",
            "Seed: 3 role, 10 permission, akses role, 7 kategori, 2 tarif kirim,\n",
            "5 produk, 17 varian/stok, dan teks situs. Tidak membuat akun login.\n";
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seeder gagal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($locked && $pdo instanceof PDO) {
        $pdo->query('SELECT pg_advisory_unlock(1935764595, 1)');
    }
}
