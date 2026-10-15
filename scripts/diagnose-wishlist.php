<?php
declare(strict_types=1);
ob_start(); // tahan output agar session_start() di bootstrap tidak terblokir "headers already sent" di CLI
// Diagnosa integrasi wishlist TANPA web server: merender halaman lewat kode proyek ini di CLI.
//   php scripts/diagnose-wishlist.php
// Bandingkan hasilnya dengan respons http://localhost:8000. Jika CLI [OK] tetapi HTTP tidak, yang melayani
// port 8000 adalah proses/folder lain (server lama yang belum dimatikan), bukan kode folder ini.
$root = dirname(__DIR__);
echo "Folder proyek : $root\n";
echo 'PHP           : ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n\n";

$fails = 0;
$ok = static function (string $label, bool $pass, string $hint = '') use (&$fails): void {
    echo ($pass ? '[OK]   ' : '[GAGAL] '), $label, ($pass || $hint === '' ? '' : "\n         -> $hint"), "\n";
    if (!$pass) { $fails++; }
};
$read = static fn(string $p): string => is_file("$root/$p") ? (string) file_get_contents("$root/$p") : '';

// 1. File Phase 6 ada dan isinya sesuai
foreach ([
    'app/Controllers/WishlistController.php', 'app/Services/WishlistService.php', 'app/Repositories/WishlistRepository.php',
    'resources/views/pages/wishlist.php', 'resources/views/components/wish-button.php', 'resources/views/components/wishlist-item.php',
] as $f) {
    $ok("file ada: $f", is_file("$root/$f"));
}
$routes = $read('routes/web.php');
$ok('routes/web.php: GET /wishlist -> WishlistController', str_contains($routes, 'WishlistController') && preg_match("#get\('/wishlist',\s*fn\(\)\s*=>\s*\\\$wishlist->show\(\)#", $routes) === 1,
    'routes/web.php masih versi lama. Salin routes/web.php dari paket Phase 6.');
$ok('routes/web.php: POST /wishlist/add|remove|move terdaftar', str_contains($routes, "post('/wishlist/add'") && str_contains($routes, "post('/wishlist/remove'") && str_contains($routes, "post('/wishlist/move'"));
$ok('routes/web.php: tidak ada rute placeholder wishlist', !str_contains($routes, "comingSoon('Wishlist'"), "Hapus baris \$r->get('/wishlist', ... comingSoon('Wishlist' ...).");
$ok('product-card.php memanggil wish-button', str_contains($read('resources/views/components/product-card.php'), "component('wish-button'"));
$ok('helpers: wishlist_has/wishlist_count ada', str_contains($read('app/Helpers/functions.php'), 'function wishlist_has(') && str_contains($read('app/Helpers/functions.php'), 'function wishlist_count('));

// 2. Render halaman memakai bootstrap + router asli (tanpa server HTTP)
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/shop';
$_SERVER['HTTP_HOST'] = 'localhost';
ob_start();
try {
    require "$root/public/index.php";
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage();
}
$shop = (string) ob_get_clean();
$ok('render /shop: ada produk (product-card__link)', str_contains($shop, 'product-card__link'), substr(strip_tags($shop), 0, 200));
$ok('render /shop: ada form wishlist (data-wish-form)', str_contains($shop, 'data-wish-form'));
$ok('render /shop: jumlah tombol wishlist = jumlah kartu produk', substr_count($shop, 'data-wish-form') === substr_count($shop, 'class="product-card"') && substr_count($shop, 'data-wish-form') > 0,
    'kartu=' . substr_count($shop, 'class="product-card"') . ' tombol=' . substr_count($shop, 'data-wish-form'));
$ok('render /shop: form wishlist memuat CSRF + product_id', (bool) preg_match('/name="_csrf" value="[a-f0-9]{64}"/', $shop) && str_contains($shop, 'name="product_id"'));
$ok('render /shop: header wishlist (data-wish-link)', str_contains($shop, 'data-wish-link'));

// 3. /wishlist asli (bukan placeholder)
$router = new App\Core\Router();
$router->setNotFound([new App\Controllers\PageController(), 'notFound']);
(require "$root/routes/web.php")($router);
ob_start();
$router->dispatch('GET', '/wishlist');
$wl = (string) ob_get_clean();
$ok('render /wishlist: halaman wishlist sesungguhnya', str_contains($wl, 'Your wishlist is empty') || str_contains($wl, 'wish-grid'));
$ok('render /wishlist: bukan placeholder', !str_contains($wl, 'opens in the next release'));

echo "\n", $fails === 0
    ? "Kode folder ini BENAR. Jika http://localhost:8000 tetap berbeda, hentikan semua proses php lama lalu jalankan ulang dari folder ini:\n"
      . "  netstat -ano | findstr :8000    (catat PID)    lalu    taskkill /PID <PID> /F\n"
      . "  cd $root\n  php -S localhost:8000 -t public public/index.php\n"
    : "$fails pemeriksaan gagal; perbaiki sesuai petunjuk '->' di atas, lalu jalankan ulang skrip ini.\n";
exit($fails === 0 ? 0 : 1);
