<?php
declare(strict_types=1);
// Uji alur HTTP cart + CSRF terhadap server yang sedang berjalan.
//   Terminal 1: php -S localhost:8000 -t public public/index.php
//   Terminal 2: php scripts/cart-http-check.php [http://localhost:8000]
// Membuat satu cart guest uji lalu mengosongkannya kembali.
$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$jar = [];
$fails = 0;

function http(string $method, string $path, array $post = []): array
{
    global $base, $jar;
    $header = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($jar), $jar)) . "\r\n";
    $http = ['method' => $method, 'header' => $header, 'ignore_errors' => true, 'follow_location' => 0];
    if ($method === 'POST') {
        $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $http['content'] = http_build_query($post);
    }
    $body = (string) @file_get_contents($base . $path, false, stream_context_create(['http' => $http]));
    $headers = $http_response_header ?? [];
    $status = isset($headers[0]) ? (int) substr($headers[0], 9, 3) : 0;
    $location = '';
    foreach ($headers as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            [$pair] = explode(';', trim(substr($h, 11)), 2);
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $jar[$k] = $v;
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    return ['status' => $status, 'body' => $body, 'location' => $location];
}
function check(string $label, bool $ok): void
{
    global $fails;
    echo ($ok ? '[PASS] ' : '[FAIL] '), $label, PHP_EOL;
    if (!$ok) { $fails++; }
}
function bagCount(): ?int
{
    $r = http('GET', '/cart');
    return preg_match('/Shopping bag, (\d+) items/', $r['body'], $m) ? (int) $m[1] : null;
}

$page = http('GET', '/product/essential-t-shirt');
check('detail produk 200', $page['status'] === 200);
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page['body'], $csrf);
preg_match('/name="variant_id" value="(\d+)"/', $page['body'], $variant);
preg_match('/name="product_id" value="(\d+)"/', $page['body'], $product);
check('form Add to Bag memuat CSRF, variant_id, product_id', isset($csrf[1], $variant[1], $product[1]));
if (!isset($csrf[1], $variant[1], $product[1])) { exit(1); }
$token = $csrf[1];
$add = static fn(string $t, string $qty) => http('POST', '/cart/add', [
    '_csrf' => $t, 'variant_id' => $variant[1], 'product_id' => $product[1],
    'product_slug' => 'essential-t-shirt', 'quantity' => $qty,
]);

$empty = http('GET', '/cart');
check('/cart kosong: 200 + empty state + header 0', $empty['status'] === 200 && str_contains($empty['body'], 'Your bag is empty') && bagCount() === 0);

$r = $add('salah', '2');
check('CSRF salah ditolak (303) dan cart tetap kosong', $r['status'] === 303 && bagCount() === 0);

$r = $add($token, '2');
check('add valid qty 2 -> redirect ke /cart', $r['status'] === 303 && $r['location'] === '/cart');
check('cookie cart guest diterima (HttpOnly diatur server)', isset($jar['salsa_cart']) && strlen($jar['salsa_cart']) === 64);
check('header = 2 unit', bagCount() === 2);

$add($token, '1');
check('tambah varian yang sama menggabungkan (3 unit)', bagCount() === 3);

$r = $add($token, '999');
check('qty 999 ditolak, cart tetap 3', $r['status'] === 303 && bagCount() === 3);
$r = $add($token, '0');
check('qty 0 ditolak, cart tetap 3', bagCount() === 3);
$r = $add($token, '-2');
check('qty negatif ditolak, cart tetap 3', bagCount() === 3);

$cart = http('GET', '/cart');
check('halaman cart menampilkan Subtotal dan harga Rupiah', str_contains($cart['body'], 'Subtotal') && str_contains($cart['body'], 'Rp'));

http('POST', '/cart/update', ['_csrf' => 'salah', 'variant_id' => $variant[1], 'quantity' => '1']);
check('update dengan CSRF salah tidak mengubah cart', bagCount() === 3);
http('POST', '/cart/update', ['_csrf' => $token, 'variant_id' => $variant[1], 'quantity' => '1']);
check('update qty -> 1', bagCount() === 1);
http('POST', '/cart/update', ['_csrf' => $token, 'variant_id' => $variant[1], 'quantity' => '0']);
check('update qty 0 ditolak (tetap 1)', bagCount() === 1);

http('POST', '/cart/remove', ['_csrf' => 'salah', 'variant_id' => $variant[1]]);
check('remove dengan CSRF salah tidak menghapus', bagCount() === 1);
http('POST', '/cart/remove', ['_csrf' => $token, 'variant_id' => $variant[1]]);
$after = http('GET', '/cart');
check('remove -> cart kosong lagi', str_contains($after['body'], 'Your bag is empty') && bagCount() === 0);

// Regresi katalog
check('regresi: /shop 200', http('GET', '/shop')['status'] === 200);
check('regresi: /category/men 200', http('GET', '/category/men')['status'] === 200);
check('regresi: /search?q=hoodie 200', http('GET', '/search?q=hoodie')['status'] === 200);
check('regresi: slug produk tidak ada -> 404', http('GET', '/product/tidak-ada')['status'] === 404);
check('GET /cart/add tidak ada (hanya POST) -> 404', http('GET', '/cart/add')['status'] === 404);

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
