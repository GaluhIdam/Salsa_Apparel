<?php
declare(strict_types=1);
// Hapus catatan percobaan login gagal yang lebih tua dari 30 hari (jendela rate limit hanya 15 menit).
//   php scripts/cleanup-login-attempts.php   (jadwalkan harian via cron / Task Scheduler)
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/UserRepository.php';

App\Core\Env::load(__DIR__ . '/../.env');
echo 'Dihapus: ', (new App\Repositories\UserRepository())->purgeOldAttempts(30), " catatan login gagal (> 30 hari).\n";
