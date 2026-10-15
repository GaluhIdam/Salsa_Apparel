<?php
declare(strict_types=1);
// Uji alur HTTP wishlist + CSRF + cookie terhadap server yang sedang berjalan.
//   Terminal 1: php -S localhost:8000 -t public public/index.php
//   Terminal 2: php scripts/wishlist-http-check.php [http://localhost:8000]
// Prasyarat: migrasi 003 sudah dijalankan. Mengosongkan wishlist/cart ujinya sendiri di akhir.
$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$jar = [];
$fails = 0;

function http(string $method, string $path, array $post = [], array $headers = []): array
{
    global $base, $jar;
    $h = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($jar), $jar)) . "\r\n";
    foreach ($headers as $name => $value) { $h .= "$name: $value\r\n"; }
    $http = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0];
    if ($method === 'POST') {
        $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $http['content'] = http_build_query($post);
    }
    $body = (string) @file_get_contents($base . $path, false, stream_context_create(['http' => $http]));
    $resp = $http_response_header ?? [];
    $status = isset($resp[0]) ? (int) substr($resp[0], 9, 3) : 0;
    $location = '';
    $cookies = [];
    foreach ($resp as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $raw = trim(substr($line, 11));
            $cookies[] = $raw;
            [$pair] = explode(';', $raw, 2);
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $jar[$k] = $v;
        } elseif (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    return ['status' => $status, 'body' => $body, 'location' => $location, 'cookies' => $cookies];
}
function check(string $label, bool $ok): void
{
    global $fails;
    echo ($ok ? '[PASS] ' : '[FAIL] '), $label, PHP_EOL;
    if (!$ok) { $fails++; }
}
function wishCount(): ?int
{
    return preg_match('/Wishlist, (\d+) items/', http('GET', '/wishlist')['body'], $m) ? (int) $m[1] : null;
}
function bagCount(): ?int
{
    return preg_match('/Shopping bag, (\d+) items/', http('GET', '/cart')['body'], $m) ? (int) $m[1] : null;
}
function productId(string $slug): int
{
    $r = http('GET', '/product/' . $slug);
    return preg_match('/name="product_id" value="(\d+)"/', $r['body'], $m) ? (int) $m[1] : 0;
}

$shop = http('GET', '/shop');
check('/shop 200 dan kartu produk memuat tombol wishlist', $shop['status'] === 200 && str_contains($shop['body'], 'data-wish-form'));
if (!str_contains($shop['body'], 'data-wish-form')) {
    echo "  Petunjuk: jalankan `php scripts/diagnose-wishlist.php`. Jika kode di folder proyek benar tetapi HTTP tidak,\n"
        . "  port ini dilayani proses/folder lain (server lama). Matikan lalu jalankan ulang dari folder proyek ini.\n";
}
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $shop['body'], $c);
preg_match_all('/name="product_id" value="(\d+)"/', $shop['body'], $ids);
$csrf = $c[1] ?? '';
$idList = array_values(array_unique($ids[1] ?? []));
check('CSRF token dan >= 2 produk ditemukan', $csrf !== '' && count($idList) >= 2);
if ($csrf === '' || count($idList) < 2) { exit(1); }
[$a, $b] = $idList;

check('wishlist awal kosong (header 0, empty state)', wishCount() === 0 && str_contains(http('GET', '/wishlist')['body'], 'Your wishlist is empty'));

$add = static fn(string $t, string $id, string $return = '/shop') => http('POST', '/wishlist/add', ['_csrf' => $t, 'product_id' => $id, 'return' => $return]);

$r = $add('salah', $a);
check('CSRF salah ditolak (303) dan wishlist tetap kosong', $r['status'] === 303 && wishCount() === 0);

$r = $add($csrf, $a, '/shop?sort=price_asc');
check('tambah valid -> redirect kembali ke halaman asal', $r['status'] === 303 && $r['location'] === '/shop?sort=price_asc');
$wishCookie = array_values(array_filter($r['cookies'], static fn($x) => str_starts_with($x, 'salsa_wish=')))[0] ?? '';
check('cookie salsa_wish: token 64 hex', isset($jar['salsa_wish']) && strlen($jar['salsa_wish']) === 64);
check('cookie salsa_wish: HttpOnly + SameSite=Lax + Expires/Max-Age', stripos($wishCookie, 'HttpOnly') !== false && stripos($wishCookie, 'SameSite=Lax') !== false && (stripos($wishCookie, 'expires=') !== false || stripos($wishCookie, 'Max-Age=') !== false));
check('header = 1 produk unik', wishCount() === 1);

$add($csrf, $a);
check('produk yang sama tidak duplikat', wishCount() === 1);

$j = http('POST', '/wishlist/add', ['_csrf' => $csrf, 'product_id' => $b], ['Accept' => 'application/json']);
$data = json_decode($j['body'], true) ?: [];
check('JSON: status 200, saved=true, count=2', $j['status'] === 200 && ($data['saved'] ?? null) === true && ($data['count'] ?? null) === 2);
check('header = 2', wishCount() === 2);
$j = http('POST', '/wishlist/add', ['_csrf' => 'salah', 'product_id' => $b], ['Accept' => 'application/json']);
check('JSON: CSRF salah -> 403', $j['status'] === 403);

$r = $add($csrf, '999999999');
check('product_id tidak ada ditolak (wishlist tetap 2)', $r['status'] === 303 && wishCount() === 2);
$r = $add($csrf, '-1');
check('product_id negatif ditolak', wishCount() === 2);

$r = $add($csrf, $a, '//evil.example/x');
check('open redirect //host ditolak', $r['location'] === '/wishlist' || $r['location'] === '/shop');
$r = $add($csrf, $a, 'https://evil.example/');
check('open redirect URL absolut ditolak', !str_contains($r['location'], 'evil.example'));
$r = http('POST', '/wishlist/add', ['_csrf' => $csrf, 'product_id' => $a, 'return' => "/x\r\nSet-Cookie: pwn=1"]);
check('return dengan CRLF ditolak', $r['location'] === '/wishlist' && !isset($jar['pwn']));

http('POST', '/wishlist/remove', ['_csrf' => 'salah', 'product_id' => $a]);
check('hapus dengan CSRF salah tidak mengubah wishlist', wishCount() === 2);
http('POST', '/wishlist/remove', ['_csrf' => $csrf, 'product_id' => $a, 'return' => '/wishlist']);
check('hapus valid -> 1', wishCount() === 1);

// Buka browser lagi: session cookie hilang, cookie wishlist masih ada.
$wish = $jar['salsa_wish'];
$jar = ['salsa_wish' => $wish];
check('setelah session hilang, wishlist tetap ada (cookie masih berlaku)', wishCount() === 1);
// Cookie dihapus: wishlist lama tidak dapat diakses.
$jar = [];
check('tanpa cookie, wishlist lama tidak dapat diakses', wishCount() === 0);
$jar = ['salsa_wish' => $wish];

// Pindah ke cart
$page = http('GET', '/wishlist');
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page['body'], $c2);
$csrf = $c2[1] ?? '';
$tote = productId('minimalist-tote');
$tee = productId('essential-t-shirt');
$jar = ['salsa_wish' => $wish] + $jar;
http('POST', '/wishlist/add', ['_csrf' => $csrf, 'product_id' => (string) $tote, 'return' => '/wishlist']);
http('POST', '/wishlist/add', ['_csrf' => $csrf, 'product_id' => (string) $tee, 'return' => '/wishlist']);
$before = wishCount();
$r = http('POST', '/wishlist/move', ['_csrf' => 'salah', 'product_id' => (string) $tote]);
check('move dengan CSRF salah ditolak', $r['status'] === 303 && $r['location'] === '/wishlist' && wishCount() === $before && bagCount() === 0);
$r = http('POST', '/wishlist/move', ['_csrf' => $csrf, 'product_id' => (string) $tee]);
check('move produk banyak varian -> diarahkan ke detail, cart tetap kosong', $r['status'] === 303 && $r['location'] === '/product/essential-t-shirt' && bagCount() === 0);
$r = http('POST', '/wishlist/move', ['_csrf' => $csrf, 'product_id' => (string) $tote]);
check('move produk satu varian -> /cart', $r['status'] === 303 && $r['location'] === '/cart');
check('cart = 1 unit dan wishlist berkurang 1', bagCount() === 1 && wishCount() === $before - 1);

// Bersihkan
$cart = http('GET', '/cart');
if (preg_match('/name="variant_id" value="(\d+)"/', $cart['body'], $v)) {
    http('POST', '/cart/remove', ['_csrf' => $csrf, 'variant_id' => $v[1]]);
}
foreach ([(string) $tee, $a, $b, (string) $tote] as $id) {
    http('POST', '/wishlist/remove', ['_csrf' => $csrf, 'product_id' => $id, 'return' => '/wishlist']);
}
check('pembersihan: wishlist dan cart uji kosong', wishCount() === 0 && bagCount() === 0);

// Regresi
check('regresi: /shop, /category/men, /search 200', http('GET', '/shop')['status'] === 200 && http('GET', '/category/men')['status'] === 200 && http('GET', '/search?q=hoodie')['status'] === 200);
check('regresi: produk tidak ada -> 404', http('GET', '/product/tidak-ada')['status'] === 404);
check('regresi: /cart 200', http('GET', '/cart')['status'] === 200);
check('GET /wishlist/add tidak ada (hanya POST) -> 404', http('GET', '/wishlist/add')['status'] === 404);

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
