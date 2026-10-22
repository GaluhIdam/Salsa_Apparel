<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';

use App\Core\Env;
use App\Config\Database;

if (PHP_SAPI !== 'cli') {
    exit("Script hanya boleh dijalankan melalui CLI.\n");
}

Env::load(__DIR__ . '/../.env');

$email = 'admin@salsa-apparel.com';

echo "Reset password untuk: {$email}\n";
echo "Masukkan password baru (minimal 12 karakter): ";

if (PHP_OS_FAMILY === 'Windows') {
    // Input tersembunyi melalui PowerShell tidak tersedia secara portabel.
    // Password akan diminta melalui input biasa di bawah.
}

$newPassword = trim((string) fgets(STDIN));

if (strlen($newPassword) < 12) {
    exit("Password minimal 12 karakter. Tidak ada perubahan.\n");
}

$pdo = Database::connection();

$stmt = $pdo->prepare(
    'UPDATE users
     SET password_hash = :password_hash
     WHERE email = :email AND is_active = TRUE'
);

$stmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'email' => $email,
]);

if ($stmt->rowCount() !== 1) {
    exit("Gagal: akun tidak ditemukan, tidak aktif, atau tidak berhasil diperbarui.\n");
}

echo "Password berhasil diperbarui. Silakan login kembali.\n";