<?php
declare(strict_types=1);
// Pengujian lapisan wishlist (Phase 6) langsung ke database. Jalankan: php scripts/wishlist-check.php
// Prasyarat: migrasi 003 sudah dijalankan. Mengasumsikan seed bawaan. Perubahan sementara (status/stok/user/
// wishlist lama) dilakukan di transaksi lalu di-rollback; wishlist & cart uji dihapus di akhir.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Core/Cookie.php'; // dipakai WishlistService::forgetGuestToken (Phase 7)
require __DIR__ . '/../app/Repositories/CartRepository.php';
require __DIR__ . '/../app/Repositories/WishlistRepository.php';
require __DIR__ . '/../app/Services/CartService.php';
require __DIR__ . '/../app/Services/WishlistService.php';

use App\Config\Database;
use App\Repositories\WishlistRepository;
use App\Services\CartService;
use App\Services\WishlistService;

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
$pid = static fn(string $slug): int => (int) $one('SELECT id FROM products WHERE slug = :s', ['s' => $slug])['id'];
$rows = static fn(string $hash): int => (int) $one('SELECT COUNT(*) AS n FROM wishlists WHERE guest_token_hash = :h', ['h' => $hash])['n'];

$token = bin2hex(random_bytes(32));
$otherToken = bin2hex(random_bytes(32));
$cartToken = bin2hex(random_bytes(32));
$hashes = [hash('sha256', $token), hash('sha256', $otherToken), hash('sha256', $cartToken)];
$svc = new WishlistService($token);
$other = new WishlistService($otherToken);
$repo = new WishlistRepository();

try {
    $tee = $pid('essential-t-shirt');      // banyak varian
    $tote = $pid('minimalist-tote');       // tepat satu varian
    $hoodie = $pid('everyday-hoodie');
    $check('seed: produk uji ditemukan', $tee > 0 && $tote > 0 && $hoodie > 0);
    $check('schema: kolom guest_token_hash ada (migrasi 003 sudah dijalankan)',
        $one("SELECT 1 AS x FROM information_schema.columns WHERE table_name = 'wishlists' AND column_name = 'guest_token_hash'") !== false);

    // Kosong
    $check('wishlist kosong: count 0, items kosong, belum ada baris', $svc->count() === 0 && $svc->items() === [] && $rows($hashes[0]) === 0);
    $check('token tidak valid diabaikan', (new WishlistService('bukan-token'))->count() === 0);

    // Tambah
    $check('tambah produk aktif berhasil', $svc->add($tee)['ok'] === true);
    $check('baris wishlist guest dibuat, count 1, isSaved true', $rows($hashes[0]) === 1 && $svc->count() === 1 && $svc->isSaved($tee));

    // Duplikat
    $svc->add($tee);
    $check('produk yang sama tidak duplikat (count 1)', $svc->count() === 1 && count($svc->items()) === 1);
    $svc->add($tote);
    $check('produk kedua: count 2 = jumlah produk unik', $svc->count() === 2);
    $check('urutan terbaru dulu', $svc->items()[0]['id'] === $tote);

    // Penolakan
    $check('tolak product_id 0', $svc->add(0)['ok'] === false);
    $check('tolak product_id negatif', $svc->add(-4)['ok'] === false);
    $check('tolak produk yang tidak ada', $svc->add(2147483647)['ok'] === false);
    $check('penolakan tidak mengubah wishlist', $svc->count() === 2);

    // Produk draft / kategori nonaktif (rollback)
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE products SET status = 'draft' WHERE id = :i")->execute(['i' => $hoodie]);
    $check('produk draft tidak bisa ditambahkan', $svc->add($hoodie)['ok'] === false);
    $pdo->prepare("UPDATE products SET status = 'draft' WHERE id = :i")->execute(['i' => $tee]);
    $check('produk tersimpan yang jadi draft: tidak dihitung di header', $svc->count() === 1);
    $teeRow = array_values(array_filter($svc->items(), static fn($i) => $i['id'] === $tee))[0] ?? null;
    $check('produk tersimpan yang jadi draft: ditandai tidak tersedia', $teeRow !== null && $teeRow['available'] === false && $teeRow['movable'] === false);
    $pdo->rollBack();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE categories SET is_active = FALSE WHERE id = (SELECT category_id FROM products WHERE id = :i)')->execute(['i' => $hoodie]);
    $check('kategori nonaktif: produk tidak bisa ditambahkan', $svc->add($hoodie)['ok'] === false);
    $pdo->rollBack();
    $check('setelah rollback: count kembali 2', $svc->count() === 2);

    // Persistensi (instance baru dengan token yang sama = buka browser lagi)
    $check('wishlist tetap ada untuk token yang sama', (new WishlistService($token))->count() === 2);

    // Isolasi antar guest
    $other->add($hoodie);
    $check('guest lain hanya melihat miliknya (1)', $other->count() === 1 && !$other->isSaved($tee));
    $check('wishlist kita tidak terpengaruh (2)', $svc->count() === 2 && !$svc->isSaved($hoodie));
    $check('guest lain tidak bisa menghapus item kita', $other->remove($tee)['ok'] === false && $svc->count() === 2);
    $check('kita tidak bisa menghapus item guest lain', $svc->remove($hoodie)['ok'] === false && $other->count() === 1);

    // Hapus
    $check('hapus item berhasil', $svc->remove($tee)['ok'] === true && $svc->count() === 1);
    $check('hapus item yang sudah tidak ada ditolak', $svc->remove($tee)['ok'] === false);

    // Pindah ke cart
    $cart = new CartService($cartToken);
    $svc->add($tee);
    $r = $svc->moveToCart($tee, $cart);
    $check('banyak varian: tidak memilih varian sembarang, diarahkan ke detail produk', $r['ok'] === false && $r['options'] === true && $r['slug'] === 'essential-t-shirt');
    $check('banyak varian: cart tetap kosong, item tetap di wishlist', $cart->count() === 0 && $svc->isSaved($tee));
    $r = $svc->moveToCart($tote, $cart);
    $check('satu varian: berhasil dipindah ke cart', $r['ok'] === true && $cart->count() === 1);
    $check('setelah dipindah: hilang dari wishlist', !$svc->isSaved($tote));
    $check('pindah produk yang tidak ada di wishlist ditolak', $svc->moveToCart($tote, $cart)['ok'] === false && $cart->count() === 1);
    $svc->add($tote);
    $svc->moveToCart($tote, $cart);
    $check('pindah lagi menggabungkan kuantitas di cart (1 baris, qty 2)', $cart->count() === 2 && count($cart->view()['items']) === 1);
    $svc->add($tote);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE product_variants SET stock = 0 WHERE product_id = :i')->execute(['i' => $tote]);
    $r = $svc->moveToCart($tote, $cart);
    $check('stok habis: tidak dipindah, tetap di wishlist', $r['ok'] === false && $svc->isSaved($tote));
    $pdo->rollBack();

    // Persiapan akun: gabung guest -> user (rollback)
    $pdo->beginTransaction();
    $u = (int) $one(
        "INSERT INTO users (email, password_hash, full_name) VALUES (:e, 'x', 'Wishlist Test') RETURNING id",
        ['e' => 'wishlist-test-' . bin2hex(random_bytes(4)) . '@example.invalid']
    )['id'];
    $mergeToken = bin2hex(random_bytes(32));
    $merge = new WishlistService($mergeToken);
    $merge->add($tee);
    $merge->add($hoodie);
    $uw = (int) $one('INSERT INTO wishlists (user_id) VALUES (:u) RETURNING id', ['u' => $u])['id'];
    $pdo->prepare('INSERT INTO wishlist_items (wishlist_id, product_id) VALUES (:w, :p)')->execute(['w' => $uw, 'p' => $tee]);
    $merged = $merge->mergeIntoUser($u);
    $n = (int) $one('SELECT COUNT(*) AS n FROM wishlist_items WHERE wishlist_id = :w', ['w' => $uw])['n'];
    $check('merge: wishlist akun berisi gabungan tanpa duplikat (2)', $n === 2 && $merged === 1);
    $check('merge: wishlist guest dihapus', $rows(hash('sha256', $mergeToken)) === 0);
    $pdo->rollBack();

    // Retensi (rollback)
    $pdo->beginTransaction();
    $u2 = (int) $one(
        "INSERT INTO users (email, password_hash, full_name) VALUES (:e, 'x', 'Wishlist Test') RETURNING id",
        ['e' => 'wishlist-test-' . bin2hex(random_bytes(4)) . '@example.invalid']
    )['id'];
    $oldHash = hash('sha256', 'old-' . bin2hex(random_bytes(8)));
    $freshHash = hash('sha256', 'fresh-' . bin2hex(random_bytes(8)));
    $pdo->prepare("INSERT INTO wishlists (guest_token_hash, updated_at) VALUES (:h, now() - INTERVAL '200 days')")->execute(['h' => $oldHash]);
    $pdo->prepare("INSERT INTO wishlists (guest_token_hash, updated_at) VALUES (:h, now() - INTERVAL '10 days')")->execute(['h' => $freshHash]);
    $pdo->prepare("INSERT INTO wishlists (user_id, updated_at) VALUES (:u, now() - INTERVAL '400 days')")->execute(['u' => $u2]);
    $dry = $repo->purgeExpiredGuests(WishlistService::PURGE_AFTER_DAYS, true);
    $check('retensi dry-run: menghitung tanpa menghapus', $dry >= 1 && $rows($oldHash) === 1);
    $del = $repo->purgeExpiredGuests(WishlistService::PURGE_AFTER_DAYS, false);
    $check('retensi: guest kedaluwarsa dihapus', $del >= 1 && $rows($oldHash) === 0);
    $check('retensi: guest yang masih aktif tidak dihapus', $rows($freshHash) === 1);
    $check('retensi: wishlist akun tidak disentuh',
        (int) $one('SELECT COUNT(*) AS n FROM wishlists WHERE user_id = :u', ['u' => $u2])['n'] === 1);
    $pdo->rollBack();
} catch (Throwable $e) {
    $fails++;
    echo '[FAIL] exception: ', $e->getMessage(), PHP_EOL;
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    foreach ($hashes as $h) {
        $pdo->prepare('DELETE FROM wishlists WHERE guest_token_hash = :h')->execute(['h' => $h]);
        $pdo->prepare('DELETE FROM carts WHERE guest_token_hash = :h')->execute(['h' => $h]);
    }
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
