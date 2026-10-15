<?php
declare(strict_types=1);
// Hapus wishlist GUEST yang tidak aktif lebih dari 120 hari (cookie berlaku 90 hari sejak perubahan terakhir,
// jadi wishlist yang masih bisa diakses tidak tersentuh). Wishlist milik akun tidak pernah dihapus.
//   php scripts/cleanup-guest-wishlists.php --dry-run   (hanya menghitung)
//   php scripts/cleanup-guest-wishlists.php             (menghapus)
// Contoh cron harian: 15 3 * * * php /path/ke/salsa-apparel/scripts/cleanup-guest-wishlists.php
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/WishlistRepository.php';
require __DIR__ . '/../app/Services/WishlistService.php';
require __DIR__ . '/../app/Services/CartService.php';
require __DIR__ . '/../app/Repositories/CartRepository.php';

use App\Repositories\WishlistRepository;
use App\Services\WishlistService;

App\Core\Env::load(__DIR__ . '/../.env');
$dry = in_array('--dry-run', $argv ?? [], true);
$n = (new WishlistRepository())->purgeExpiredGuests(WishlistService::PURGE_AFTER_DAYS, $dry);
echo ($dry ? 'Akan dihapus: ' : 'Dihapus: '), $n, ' wishlist guest kedaluwarsa (> ', WishlistService::PURGE_AFTER_DAYS, " hari).\n";
