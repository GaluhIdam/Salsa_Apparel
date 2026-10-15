<?php
declare(strict_types=1);
// Pengujian lapisan cart (Phase 5) langsung ke database. Jalankan: php scripts/cart-check.php
// Mengasumsikan seed bawaan. Perubahan sementara (stok/status) dilakukan di transaksi lalu di-rollback;
// cart uji dihapus di akhir.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Core/Csrf.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/CartRepository.php';
require __DIR__ . '/../app/Services/CartService.php';

use App\Config\Database;
use App\Core\Csrf;
use App\Services\CartService;

App\Core\Env::load(__DIR__ . '/../.env');
$pdo = Database::connection();
$fails = 0;
$check = static function (string $label, bool $ok) use (&$fails): void {
    echo ($ok ? '[PASS] ' : '[FAIL] '), $label, PHP_EOL;
    if (!$ok) { $fails++; }
};
$one = static function (string $sql, array $p = []) use ($pdo): array|false {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetch();
};
$variantOf = static fn(string $slug): array => $one(
    "SELECT v.id, v.product_id, v.stock FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE p.slug = :s AND v.is_active = TRUE AND v.stock > 5 ORDER BY v.id LIMIT 1", ['s' => $slug]
);
$priceOf = static fn(int $productId): float => (float) $one(
    'SELECT COALESCE(sale_price, price) AS p FROM products WHERE id = :i', ['i' => $productId]
)['p'];
$cartRows = static fn(string $hash): int => (int) $one(
    'SELECT COUNT(*) AS n FROM carts WHERE guest_token_hash = :h', ['h' => $hash]
)['n'];

$token = bin2hex(random_bytes(32));
$hash = hash('sha256', $token);
$svc = new CartService($token);

try {
    $tee = $variantOf('essential-t-shirt');
    $hoodie = $variantOf('everyday-hoodie');
    $check('seed: varian uji ditemukan', (bool) $tee && (bool) $hoodie);

    // Cart kosong
    $v = $svc->view();
    $check('cart kosong: tanpa item, 0 unit, subtotal 0', $v['items'] === [] && $v['units'] === 0 && $v['subtotal'] === 0.0);
    $check('cart kosong: header 0 dan belum ada baris cart di database', $svc->count() === 0 && $cartRows($hash) === 0);
    $check('token tidak valid diabaikan', (new CartService('bukan-token'))->count() === 0);

    // Tambah varian valid (guest tanpa login)
    $r = $svc->add((int) $tee['id'], 2, (int) $tee['product_id']);
    $check('add varian valid qty 2 berhasil', $r['ok'] === true);
    $check('header = total unit (2)', $svc->count() === 2 && $cartRows($hash) === 1);

    // Gabung kuantitas
    $svc->add((int) $tee['id'], 3, (int) $tee['product_id']);
    $v = $svc->view();
    $check('produk+varian sama digabung: 1 baris, qty 5', count($v['items']) === 1 && $v['items'][0]['quantity'] === 5);
    $check('header = 5', $svc->count() === 5);

    // Penolakan
    $check('tolak variant_id 0', $svc->add(0, 1)['ok'] === false);
    $check('tolak qty 0', $svc->add((int) $tee['id'], 0)['ok'] === false);
    $check('tolak qty negatif', $svc->add((int) $tee['id'], -1)['ok'] === false);
    $check('tolak qty di atas batas maksimum', $svc->add((int) $tee['id'], CartService::MAX_QTY + 1)['ok'] === false);
    $check('tolak varian yang tidak ada', $svc->add(2147483647, 1)['ok'] === false);
    $check('tolak varian yang bukan milik produk', $svc->add((int) $tee['id'], 1, (int) $hoodie['product_id'])['ok'] === false);
    $check('penolakan tidak mengubah cart (tetap 5 unit)', $svc->count() === 5);

    // Stok (di transaksi, di-rollback)
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE product_variants SET stock = 6 WHERE id = :i')->execute(['i' => $tee['id']]);
    $check('tolak melebihi stok (5 + 2 > 6)', $svc->add((int) $tee['id'], 2)['ok'] === false);
    $check('boleh sampai batas stok (5 + 1 = 6)', $svc->add((int) $tee['id'], 1)['ok'] === true && $svc->count() === 6);
    $check('tolak jika sudah maksimum stok', $svc->add((int) $tee['id'], 1)['ok'] === false);
    $pdo->prepare('UPDATE product_variants SET stock = 0 WHERE id = :i')->execute(['i' => $hoodie['id']]);
    $check('tolak produk yang stoknya habis', $svc->add((int) $hoodie['id'], 1)['ok'] === false);
    $pdo->rollBack();
    $check('rollback: stok dan cart kembali (5 unit)', $svc->count() === 5);

    // Ubah kuantitas
    $check('update qty 5 -> 2', $svc->setQuantity((int) $tee['id'], 2)['ok'] === true && $svc->count() === 2);
    $check('update qty 0 ditolak', $svc->setQuantity((int) $tee['id'], 0)['ok'] === false);
    $check('update qty negatif ditolak', $svc->setQuantity((int) $tee['id'], -3)['ok'] === false);
    $check('update qty > maksimum ditolak', $svc->setQuantity((int) $tee['id'], CartService::MAX_QTY + 1)['ok'] === false);
    $check('update item yang tidak ada di cart ditolak', $svc->setQuantity((int) $hoodie['id'], 1)['ok'] === false);
    $check('qty tetap 2 setelah penolakan', $svc->count() === 2);

    // Harga dari database
    $svc->add((int) $hoodie['id'], 1, (int) $hoodie['product_id']);
    $v = $svc->view();
    $expected = $priceOf((int) $tee['product_id']) * 2 + $priceOf((int) $hoodie['product_id']);
    $check('2 baris untuk 2 varian berbeda', count($v['items']) === 2);
    $check('harga satuan = harga di database', abs($v['items'][0]['unit_price'] - $priceOf((int) $tee['product_id'])) < 0.001);
    $check('subtotal = jumlah (harga database x qty)', abs($v['subtotal'] - $expected) < 0.001 && $v['units'] === 3);

    // Produk tidak aktif (rollback)
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE products SET status = 'draft' WHERE id = :i")->execute(['i' => $hoodie['product_id']]);
    $v = $svc->view();
    $hoodieLine = array_values(array_filter($v['items'], static fn($i) => $i['variant_id'] === (int) $hoodie['id']))[0] ?? null;
    $check('produk draft: baris ditandai tidak tersedia + ada alasan', $hoodieLine !== null && $hoodieLine['available'] === false && $hoodieLine['reason'] !== null);
    $check('produk draft tidak dihitung di subtotal/header', abs($v['subtotal'] - $priceOf((int) $tee['product_id']) * 2) < 0.001 && $svc->count() === 2);
    $check('produk draft tidak bisa ditambahkan', $svc->add((int) $hoodie['id'], 1)['ok'] === false);
    $pdo->rollBack();

    // Stok turun di bawah qty cart (rollback)
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE product_variants SET stock = 1 WHERE id = :i')->execute(['i' => $tee['id']]);
    $v = $svc->view();
    $check('stok turun: qty dikurangi ke 1 + ada pemberitahuan', $v['items'][0]['quantity'] === 1 && count($v['notices']) === 1);
    $pdo->rollBack();

    // Isolasi antar guest
    $check('cart guest lain tidak melihat isi cart ini', (new CartService(bin2hex(random_bytes(32))))->count() === 0);

    // Hapus item
    $check('remove item', $svc->remove((int) $hoodie['id'])['ok'] === true);
    $check('remove item yang sudah tidak ada ditolak', $svc->remove((int) $hoodie['id'])['ok'] === false);
    $svc->remove((int) $tee['id']);
    $v = $svc->view();
    $check('setelah semua dihapus: cart kosong, header 0', $v['items'] === [] && $svc->count() === 0);

    // CSRF
    $_SESSION = [];
    $t = Csrf::token();
    $check('CSRF: token valid diterima', Csrf::verify($t) === true);
    $check('CSRF: token salah ditolak', Csrf::verify($t . 'x') === false);
    $check('CSRF: token kosong/null ditolak', Csrf::verify('') === false && Csrf::verify(null) === false);
} catch (Throwable $e) {
    $fails++;
    echo '[FAIL] exception: ', $e->getMessage(), PHP_EOL;
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->prepare('DELETE FROM carts WHERE guest_token_hash = :h')->execute(['h' => $hash]);
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
