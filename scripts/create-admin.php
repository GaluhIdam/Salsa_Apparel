<?php
declare(strict_types=1);
// Membuat akun admin/staf pertama (hanya lewat CLI; tidak ada registrasi admin publik, tidak ada password default).
//
//   php scripts/create-admin.php --email=admin@contoh.com --name="Nama Admin" [--role=super_admin]
//
// Password diminta lewat prompt (diketik dua kali). Alternatif tanpa prompt, PowerShell:
//   $env:ADMIN_PASSWORD = 'password-anda'; php scripts/create-admin.php --email=... --name="..."; Remove-Item Env:ADMIN_PASSWORD
// Role yang tersedia: super_admin, admin, order_manager (lihat tabel roles). Email tidak boleh sudah terdaftar.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/StaffRepository.php';
require __DIR__ . '/../app/Repositories/AuditLogRepository.php';
require __DIR__ . '/../app/Validators/AuthValidator.php';

use App\Repositories\AuditLogRepository;
use App\Repositories\StaffRepository;
use App\Validators\AuthValidator;

App\Core\Env::load(__DIR__ . '/../.env');
$opts = getopt('', ['email:', 'name:', 'role::', 'help']);
if (isset($opts['help']) || empty($opts['email']) || empty($opts['name'])) {
    fwrite(STDERR, "Penggunaan: php scripts/create-admin.php --email=EMAIL --name=\"NAMA\" [--role=super_admin|admin|order_manager]\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$email = AuthValidator::normalizeEmail((string) $opts['email']);
$name = trim((string) preg_replace('/\s+/u', ' ', (string) $opts['name']));
$roleName = (string) ($opts['role'] ?? 'super_admin');

if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Email tidak valid.\n");
    exit(1);
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
    fwrite(STDERR, "Nama harus 2-120 karakter.\n");
    exit(1);
}

$staff = new StaffRepository();
$role = $staff->findRoleByName($roleName);
if ($role === null) {
    $names = implode(', ', array_column($staff->roles(), 'name'));
    fwrite(STDERR, "Role '$roleName' tidak ada. Pilihan: $names\n");
    exit(1);
}

/** Baca password tanpa menampilkannya jika memungkinkan (Linux/macOS dengan stty); di Windows tampil saat diketik. */
$read = static function (string $prompt): string {
    echo $prompt;
    $hidden = PHP_OS_FAMILY !== 'Windows' && is_callable('shell_exec') && trim((string) @shell_exec('command -v stty')) !== '';
    if ($hidden) {
        @shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hidden) {
        @shell_exec('stty echo');
        echo PHP_EOL;
    }
    return rtrim((string) $line, "\r\n");
};

$password = getenv('ADMIN_PASSWORD');
if ($password === false || $password === '') {
    if (PHP_OS_FAMILY === 'Windows') {
        echo "Catatan: di Windows password terlihat saat diketik. Untuk menghindarinya gunakan variabel lingkungan ADMIN_PASSWORD (lihat bagian atas skrip).\n";
    }
    $password = $read('Password: ');
    $confirm = $read('Ulangi password: ');
} else {
    $confirm = $password;
}

[, $errors] = (new AuthValidator())->newPassword(['new_password' => $password, 'new_password_confirmation' => $confirm]);
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

$id = $staff->createStaff($name, $email, password_hash($password, PASSWORD_DEFAULT), (int) $role['id']);
if ($id === null) {
    fwrite(STDERR, "Email sudah terdaftar. Gunakan email lain (akun yang sudah ada tidak diubah).\n");
    exit(1);
}
(new AuditLogRepository())->log(null, 'admin.create', 'user', $id, ['role' => $roleName], null);

echo "Akun {$role['label']} dibuat untuk $email (ID $id).\n";
echo "Masuk melalui /admin/login. Password tidak disimpan di mana pun selain hash di database.\n";
