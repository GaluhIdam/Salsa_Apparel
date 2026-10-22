<?php
declare(strict_types=1);
// Hapus catatan percobaan login/reset yang lebih tua dari 30 hari (jendela rate limit maksimal 60 menit)
// dan token reset password yang sudah kedaluwarsa/terpakai lebih dari 7 hari.
//   php scripts/cleanup-login-attempts.php   (jadwalkan harian via cron / Task Scheduler)
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/UserRepository.php';
require __DIR__ . '/../app/Repositories/PasswordResetRepository.php';

App\Core\Env::load(__DIR__ . '/../.env');
echo 'Dihapus: ', (new App\Repositories\UserRepository())->purgeOldAttempts(30), " catatan login gagal (> 30 hari).\n";
echo 'Dihapus: ', (new App\Repositories\PasswordResetRepository())->purgeOld(7), " token reset kedaluwarsa/terpakai (> 7 hari).\n";
