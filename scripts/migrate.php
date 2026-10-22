<?php
declare(strict_types=1);

// Usage: php scripts/migrate.php [--status|--help]
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$args = array_slice($argv, 1);
if ($args === ['--help']) {
    echo "Pemakaian: php scripts/migrate.php [--status|--help]\n",
        "Membuat tabel dari schema.sql pada database kosong, lalu menjalankan migrasi berurutan.\n",
        "Database harus sudah dibuat. Koneksi dibaca dari .env. Seed tidak dijalankan.\n",
        "--status: tampilkan status tanpa mengubah database.\n";
    exit(0);
}
if ($args !== [] && $args !== ['--status']) {
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
    $files = glob(__DIR__ . '/../database/migrations/*.sql');
    if ($files === false) {
        throw new RuntimeException('Tidak dapat membaca folder migrasi.');
    }
    sort($files, SORT_STRING);
    array_unshift($files, __DIR__ . '/../database/schema.sql');

    if ($args !== ['--status']) {
        $locked = (bool) $pdo->query('SELECT pg_try_advisory_lock(1935764595, 1)')->fetchColumn();
        if (!$locked) {
            throw new RuntimeException('Migrasi lain sedang berjalan. Coba lagi setelah selesai.');
        }
    }
    $hasHistory = (bool) $pdo->query("SELECT to_regclass('schema_migrations') IS NOT NULL")->fetchColumn();
    $applied = $hasHistory
        ? $pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR)
        : [];

    if ($args === ['--status']) {
        foreach ($files as $file) {
            $name = basename($file);
            $state = isset($applied[$name]) ? 'APPLIED' : 'PENDING';
            if (isset($applied[$name]) && $applied[$name] !== hash_file('sha256', $file)) {
                $state = 'CHANGED';
            }
            echo "$state  $name\n";
        }
        exit(0);
    }

    // Validate every recorded checksum before applying any pending migration.
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name]) && $applied[$name] !== hash_file('sha256', $file)) {
            throw new RuntimeException("File $name berubah setelah diterapkan. Buat migrasi baru.");
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )');

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            echo "SKIP  $name\n";
            continue;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Tidak dapat membaca $name.");
        }
        $checksum = hash('sha256', $sql);
        $baseline = false;
        if ($name === 'schema.sql') {
            preg_match_all('/^CREATE TABLE\s+(\w+)/mi', $sql, $matches);
            $exists = $pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
            $present = 0;
            foreach ($matches[1] as $table) {
                $exists->execute(['name' => $table]);
                $present += (int) (bool) $exists->fetchColumn();
            }
            if ($present > 0 && $present !== count($matches[1])) {
                throw new RuntimeException('Schema awal hanya terpasang sebagian. Periksa tabel sebelum migrasi.');
            }
            $baseline = $present > 0;
        }
        // Existing SQL files wrap themselves in BEGIN/COMMIT. Move that wrapper
        // here so the SQL and its history record commit atomically together.
        $sql = preg_replace('/^BEGIN;\s*$/mi', '', $sql, -1, $begins);
        $sql = preg_replace('/^COMMIT;\s*$/mi', '', $sql, -1, $commits);
        if ($sql === null || $begins !== 1 || $commits !== 1) {
            throw new RuntimeException("$name harus memiliki tepat satu wrapper BEGIN/COMMIT.");
        }
        $pdo->beginTransaction();
        try {
            if (!$baseline) {
                $pdo->exec($sql);
            }
            $record = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
            $record->execute([$name, $checksum]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException("Gagal pada $name: " . $e->getMessage(), 0, $e);
        }
        echo ($baseline ? 'BASELINE  ' : 'OK  ') . $name . PHP_EOL;
    }
    echo "Migrasi selesai.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migrasi gagal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($locked && $pdo instanceof PDO) {
        $pdo->query('SELECT pg_advisory_unlock(1935764595, 1)');
    }
}
