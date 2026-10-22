<?php
declare(strict_types=1);
// Pengujian Tahap A (admin: auth/RBAC, produk, varian, stok, gambar, kategori) langsung ke database.
//   php scripts/admin-check.php
// Prasyarat: schema + seed (role/permission) dan migrasi sebelumnya. Seluruh data uji dibuat dalam satu transaksi
// dan di-rollback; file upload uji ditulis ke folder sementara (bukan public/uploads) lalu dihapus.
foreach ([
    'Core/Env', 'Core/Str', 'Core/AdminAuth', 'Config/Database',
    'Repositories/StaffRepository', 'Repositories/UserRepository', 'Repositories/AuditLogRepository',
    'Repositories/AdminProductRepository', 'Repositories/AdminCategoryRepository', 'Repositories/ProductRepository',
    'Validators/AuthValidator', 'Validators/ProductValidator', 'Validators/CategoryValidator',
    'Services/ImageUploadService', 'Services/ProductAdminService', 'Services/CategoryAdminService', 'Services/AuthService',
] as $f) {
    require __DIR__ . '/../app/' . $f . '.php';
}

use App\Config\Database;
use App\Core\AdminAuth;
use App\Core\Str;
use App\Repositories\ProductRepository;
use App\Repositories\StaffRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryAdminService;
use App\Services\ImageUploadService;
use App\Services\ProductAdminService;

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
$count = static fn(string $sql, array $p = []): int => (int) $one($sql, $p)['n'];
$rm = static function (string $dir) use (&$rm): void {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        is_dir("$dir/$e") ? $rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
};
$files = static fn(string $dir): int => is_dir($dir) ? count(array_diff(scandir($dir) ?: [], ['.', '..'])) : 0;

$tmpRoot = sys_get_temp_dir() . '/salsa-admin-check-' . bin2hex(random_bytes(4));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
$mk = static function (string $content, string $name = 'a.png') {
    $tmp = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmp, $content);
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
};
$ip = '198.51.100.9';
$img = new ImageUploadService($tmpRoot);
$svc = new ProductAdminService($img);
$cats = new CategoryAdminService($img);
$staffRepo = new StaffRepository();
$auth = new AuthService();

$pdo->beginTransaction();
try {
    $tag = bin2hex(random_bytes(3));
    $adminRole = (int) $staffRepo->findRoleByName('admin')['id'];
    $omRole = (int) $staffRepo->findRoleByName('order_manager')['id'];
    $pw = 'admin pass 123';
    $A = (int) $staffRepo->createStaff('Test Admin', "phase9a-test-admin-$tag@example.invalid", password_hash($pw, PASSWORD_DEFAULT), $adminRole);
    $OM = (int) $staffRepo->createStaff('Test OM', "phase9a-test-om-$tag@example.invalid", password_hash($pw, PASSWORD_DEFAULT), $omRole);
    $C = (int) (new UserRepository())->create('Test Customer', "phase9a-test-cust-$tag@example.invalid", null, password_hash($pw, PASSWORD_DEFAULT));
    $check('data uji: admin, order manager, pelanggan dibuat', $A > 0 && $OM > 0 && $C > 0);

    // ---------- Autentikasi & RBAC ----------
    $adminEmail = "phase9a-test-admin-$tag@example.invalid";
    $r = $auth->login($adminEmail, $pw, $ip, 'admin');
    $check('login audience admin: akun ber-role diterima', $r['ok'] === true && $r['user_id'] === $A);
    $generic = AuthService::GENERIC_LOGIN_ERROR;
    $bad = $auth->login($adminEmail, 'salah salah 1', $ip, 'admin');
    $cust = $auth->login("phase9a-test-cust-$tag@example.invalid", $pw, $ip, 'admin');
    $check('login admin: password salah dan akun pelanggan -> pesan generik yang sama', $bad['error'] === $generic && $cust['error'] === $generic && $cust['ok'] === false);
    $check('perilaku lama tidak berubah: akun ber-role ditolak di login pelanggan (audience default)', $auth->login($adminEmail, $pw, $ip)['ok'] === false);
    $check('perilaku lama tidak berubah: pelanggan diterima di login pelanggan', $auth->login("phase9a-test-cust-$tag@example.invalid", $pw, $ip)['ok'] === true);
    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = :i')->execute(['i' => $A]);
    $check('staf nonaktif ditolak', $auth->login($adminEmail, $pw, $ip, 'admin')['ok'] === false);
    $pdo->prepare('UPDATE users SET is_active = TRUE WHERE id = :i')->execute(['i' => $A]);
    (new UserRepository())->clearFailures($adminEmail);

    $_SESSION = [];
    AdminAuth::login($A);
    $check('AdminAuth: admin punya products/categories/inventory.manage', AdminAuth::can('products.manage') && AdminAuth::can('categories.manage') && AdminAuth::can('inventory.manage'));
    $check('AdminAuth: admin tidak punya izin yang tidak ada di role-nya (users.manage)', !AdminAuth::can('users.manage'));
    AdminAuth::login($OM);
    $check('AdminAuth: order manager hanya inventory (tanpa products/categories)', AdminAuth::can('inventory.manage') && !AdminAuth::can('products.manage') && !AdminAuth::can('categories.manage'));
    AdminAuth::login($C);
    $check('AdminAuth: pelanggan (tanpa role) bukan staf dan session dibatalkan', AdminAuth::user() === null && !isset($_SESSION['admin_auth']));
    AdminAuth::login($A, 1000);
    $_SESSION['admin_auth']['last_seen'] = 1000;
    $check('AdminAuth: timeout idle 1 jam', AdminAuth::id(1000 + AdminAuth::IDLE_TIMEOUT + 1) === null && !isset($_SESSION['admin_auth']));
    AdminAuth::login($A);
    AdminAuth::logout();
    $check('AdminAuth::logout membersihkan session admin', AdminAuth::id() === null);

    // ---------- Utilitas ----------
    $check('Str::slug', Str::slug('Kaos Premium & Nyaman!') === 'kaos-premium-nyaman' && Str::slug('  ') === '' && Str::slug('A--B') === 'a-b');
    $norm = ImageUploadService::normalize(['name' => ['a.png', ''], 'tmp_name' => ['/x', ''], 'error' => [0, UPLOAD_ERR_NO_FILE], 'size' => [10, 0]]);
    $check('normalize($_FILES multiple): entri kosong dibuang', count($norm) === 1 && $norm[0]['name'] === 'a.png');

    // ---------- Kategori ----------
    $c1 = $cats->create($A, ['name' => "Kategori Uji $tag", 'slug' => '', 'description' => 'Deskripsi', 'sort_order' => '5', 'is_active' => '1'], null, $ip);
    $catId = (int) $c1['id'];
    $catRow = $one('SELECT slug, is_active FROM categories WHERE id = :i', ['i' => $catId]);
    $check('kategori: create berhasil, slug otomatis dari nama', $c1['ok'] && $catRow['slug'] === 'kategori-uji-' . $tag);
    $check('kategori: nama duplikat (beda huruf besar/kecil) ditolak', isset($cats->create($A, ['name' => strtoupper("Kategori Uji $tag"), 'sort_order' => '0'], null, $ip)['errors']['name']));
    $check('kategori: slug eksplisit duplikat ditolak', isset($cats->create($A, ['name' => "Lain $tag", 'slug' => 'kategori-uji-' . $tag, 'sort_order' => '0'], null, $ip)['errors']['slug']));
    $check('kategori: sort order tidak valid ditolak', isset($cats->create($A, ['name' => "Lain2 $tag", 'sort_order' => 'abc'], null, $ip)['errors']['sort_order']));
    $check('kategori: update berhasil dan tidak bentrok dengan dirinya sendiri', $cats->update($A, $catId, ['name' => "Kategori Uji $tag", 'slug' => 'kategori-uji-' . $tag, 'description' => 'Baru', 'sort_order' => '6', 'is_active' => '1'], null, $ip)['ok']);
    $check('kategori: update id tidak ada -> not_found', !empty($cats->update($A, 2147483000, ['name' => 'X Y', 'sort_order' => '0'], null, $ip)['not_found']));
    $upImg = $cats->update($A, $catId, ['name' => "Kategori Uji $tag", 'slug' => 'kategori-uji-' . $tag, 'sort_order' => '6', 'is_active' => '1'], $mk($png), $ip, false);
    $catPath = (string) $one('SELECT image_path FROM categories WHERE id = :i', ['i' => $catId])['image_path'];
    $check('kategori: upload gambar tersimpan di uploads/categories', $upImg['ok'] && str_starts_with($catPath, 'uploads/categories/') && is_file($tmpRoot . '/' . substr($catPath, 8)));

    // ---------- Produk: validasi ----------
    $base = ['name' => "Produk Uji $tag", 'slug' => '', 'category_id' => (string) $catId, 'price' => '149000', 'sale_price' => '', 'short_description' => 'Singkat', 'description' => "Baris 1\nBaris 2"];
    $bad = static fn(array $o) => $svc->create($A, $o + $base, $ip);
    $check('produk: nama terlalu pendek ditolak', isset($bad(['name' => 'A'])['errors']['name']));
    $check('produk: harga tidak valid ditolak', isset($bad(['price' => '12a'])['errors']['price']) && isset($bad(['price' => ''])['errors']['price']));
    $check('produk: harga 0 / negatif ditolak', isset($bad(['price' => '0'])['errors']['price']) && isset($bad(['price' => '-5'])['errors']['price']));
    $check('produk: harga diskon >= harga ditolak', isset($bad(['sale_price' => '149000'])['errors']['sale_price']) && isset($bad(['sale_price' => '200000'])['errors']['sale_price']));
    $check('produk: kategori tidak dipilih / tidak ada ditolak', isset($bad(['category_id' => ''])['errors']['category_id']) && isset($bad(['category_id' => '2147483000'])['errors']['category_id']));
    $check('produk: slug dengan huruf besar/spasi ditolak', isset($bad(['slug' => 'Bad Slug'])['errors']['slug']));
    $check('produk: deskripsi > 5000 karakter ditolak', isset($bad(['description' => str_repeat('a', 5001)])['errors']['description']));
    $check('validasi gagal tidak menyimpan produk', $count('SELECT COUNT(*) AS n FROM products WHERE name = :n', ['n' => "Produk Uji $tag"]) === 0);

    // ---------- Produk: create/update ----------
    $p1 = $svc->create($A, $base, $ip);
    $pid = (int) $p1['id'];
    $row = $one('SELECT slug, status, price, sale_price FROM products WHERE id = :i', ['i' => $pid]);
    $check('produk: create berhasil sebagai draft dengan slug otomatis', $p1['ok'] && $row['status'] === 'draft' && $row['slug'] === 'produk-uji-' . $tag && (float) $row['price'] === 149000.0 && $row['sale_price'] === null);
    $p2 = $svc->create($A, $base, $ip);
    $check('produk: nama sama -> slug unik otomatis (-2)', $p2['ok'] && $one('SELECT slug FROM products WHERE id = :i', ['i' => $p2['id']])['slug'] === 'produk-uji-' . $tag . '-2');
    $check('produk: slug eksplisit duplikat ditolak', isset($svc->create($A, ['slug' => 'produk-uji-' . $tag] + $base, $ip)['errors']['slug']));
    $check('produk: status "active" saat create dipaksa draft', $one('SELECT status FROM products WHERE id = :i', ['i' => $svc->create($A, ['status' => 'active'] + $base, $ip)['id']])['status'] === 'draft');

    $upd = static fn(int $id, array $o) => $svc->update($A, $id, $o + $base + ['status' => 'draft'], $ip);
    $u = $upd($pid, ['slug' => 'produk-uji-' . $tag, 'price' => '159000', 'sale_price' => '129000', 'is_featured' => '1']);
    $row = $one('SELECT price, sale_price, is_featured FROM products WHERE id = :i', ['i' => $pid]);
    $check('produk: update harga, diskon, dan flag berhasil (slug sendiri tidak bentrok)', $u['ok'] && (float) $row['price'] === 159000.0 && (float) $row['sale_price'] === 129000.0 && ($row['is_featured'] === true || $row['is_featured'] === 't'));
    $aud = $one("SELECT old_values->>'price' AS o, new_values->>'price' AS n FROM audit_logs WHERE entity_type = 'product' AND entity_id = :i AND action = 'product.update' ORDER BY id DESC LIMIT 1", ['i' => $pid]);
    $check('audit: perubahan harga mencatat nilai lama dan baru', $aud !== false && $aud['o'] === '149000.00' && $aud['n'] === '159000.00');
    $check('produk: update dengan input tidak valid tidak mengubah data', !$upd($pid, ['price' => 'abc'])['ok'] && (float) $one('SELECT price FROM products WHERE id = :i', ['i' => $pid])['price'] === 159000.0);
    $check('produk: update slug milik produk lain ditolak', isset($upd($pid, ['slug' => 'produk-uji-' . $tag . '-2'])['errors']['slug']));
    $check('produk: update id tidak ada -> not_found', !empty($upd(2147483000, [])['not_found']));
    $check('produk: publikasi tanpa varian ditolak', isset($upd($pid, ['slug' => 'produk-uji-' . $tag, 'status' => 'active'])['errors']['status']));

    // ---------- Varian & stok ----------
    $vin = ['size' => 'M', 'color' => 'Black', 'sku' => '', 'stock' => '5', 'is_active' => '1'];
    $check('varian: stok awal tanpa izin inventory ditolak', isset($svc->addVariant($A, $pid, $vin, false, $ip)['errors']['stock']));
    $v1 = $svc->addVariant($A, $pid, $vin, true, $ip);
    $vid1 = (int) $v1['id'];
    $vrow = $one('SELECT sku, stock FROM product_variants WHERE id = :i', ['i' => $vid1]);
    $check('varian: berhasil, SKU otomatis valid, stok awal tersimpan', $v1['ok'] && preg_match('/^[A-Z0-9-]{3,60}$/', $vrow['sku']) === 1 && (int) $vrow['stock'] === 5);
    $mv = $one("SELECT change_qty, reason, created_by FROM inventory_movements WHERE variant_id = :v", ['v' => $vid1]);
    $check('stok awal tercatat di inventory_movements (initial, oleh admin)', $mv !== false && (int) $mv['change_qty'] === 5 && $mv['reason'] === 'initial' && (int) $mv['created_by'] === $A);
    $check('varian: kombinasi ukuran+warna duplikat ditolak', isset($svc->addVariant($A, $pid, $vin, true, $ip)['errors']['size']));
    $check('varian: SKU duplikat ditolak', isset($svc->addVariant($A, $pid, ['size' => 'L', 'sku' => strtolower($vrow['sku'])] + $vin, true, $ip)['errors']['sku']));
    $check('varian: stok negatif / bukan angka ditolak', isset($svc->addVariant($A, $pid, ['size' => 'S', 'stock' => '-1'] + $vin, true, $ip)['errors']['stock']) && isset($svc->addVariant($A, $pid, ['size' => 'S', 'stock' => 'x'] + $vin, true, $ip)['errors']['stock']));
    $check('varian: ukuran kosong ditolak', isset($svc->addVariant($A, $pid, ['size' => ''] + $vin, true, $ip)['errors']['size']));
    $check('varian: update ke SKU milik varian lain ditolak', ($v2 = $svc->addVariant($A, $pid, ['size' => 'L', 'sku' => 'UJI-' . strtoupper($tag) . '-L', 'stock' => '2'] + $vin, true, $ip))['ok'] && isset($svc->updateVariant($A, $pid, $vid1, ['size' => 'M', 'color' => 'Black', 'sku' => 'UJI-' . strtoupper($tag) . '-L', 'is_active' => '1'], $ip)['errors']['sku']));
    $vid2 = (int) $v2['id'];

    $s = $svc->setStock($A, $pid, $vid1, ['new_stock' => '8', 'note' => 'Restock'], $ip);
    $mv2 = $one("SELECT change_qty, reason, note FROM inventory_movements WHERE variant_id = :v AND reason = 'adjustment' ORDER BY id DESC LIMIT 1", ['v' => $vid1]);
    $check('stok: set ke 8 -> selisih +3 tercatat (adjustment + catatan)', $s['ok'] && $s['changed'] && (int) $one('SELECT stock FROM product_variants WHERE id = :i', ['i' => $vid1])['stock'] === 8 && (int) $mv2['change_qty'] === 3 && $mv2['note'] === 'Restock');
    $nBefore = $count('SELECT COUNT(*) AS n FROM inventory_movements WHERE variant_id = :v', ['v' => $vid1]);
    $s = $svc->setStock($A, $pid, $vid1, ['new_stock' => '8'], $ip);
    $check('stok: nilai sama tidak membuat catatan baru', $s['ok'] && !$s['changed'] && $count('SELECT COUNT(*) AS n FROM inventory_movements WHERE variant_id = :v', ['v' => $vid1]) === $nBefore);
    $svc->setStock($A, $pid, $vid1, ['new_stock' => '2'], $ip);
    $check('stok: pengurangan -6 tercatat', (int) $one("SELECT change_qty FROM inventory_movements WHERE variant_id = :v ORDER BY id DESC LIMIT 1", ['v' => $vid1])['change_qty'] === -6);
    $check('stok: negatif, bukan angka, dan > batas ditolak', isset($svc->setStock($A, $pid, $vid1, ['new_stock' => '-1'], $ip)['errors']['new_stock']) && isset($svc->setStock($A, $pid, $vid1, ['new_stock' => 'abc'], $ip)['errors']['new_stock']) && isset($svc->setStock($A, $pid, $vid1, ['new_stock' => '100001'], $ip)['errors']['new_stock']) && (int) $one('SELECT stock FROM product_variants WHERE id = :i', ['i' => $vid1])['stock'] === 2);
    $check('stok: varian milik produk lain / tidak ada -> ditolak', !$svc->setStock($A, (int) $p2['id'], $vid1, ['new_stock' => '9'], $ip)['ok'] && !$svc->setStock($A, $pid, 2147483000, ['new_stock' => '9'], $ip)['ok'] && (int) $one('SELECT stock FROM product_variants WHERE id = :i', ['i' => $vid1])['stock'] === 2);
    $check('audit inventory.adjust mencatat stok lama dan baru', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'inventory.adjust' AND entity_id = :v AND jsonb_exists(old_values, 'stock') AND jsonb_exists(new_values, 'stock')", ['v' => $vid1]) >= 2);

    // ---------- Publikasi & katalog publik ----------
    $pub = $upd($pid, ['slug' => 'produk-uji-' . $tag, 'status' => 'active', 'price' => '159000', 'sale_price' => '129000']);
    $catalog = new ProductRepository();
    $inShop = static fn(string $slug): bool => in_array($slug, array_column($catalog->search(['q' => 'Produk Uji ' . $tag], 1, 50)['items'], 'slug'), true);
    $check('publikasi dengan varian aktif berhasil dan produk muncul di katalog publik', $pub['ok'] && $inShop('produk-uji-' . $tag) && $catalog->findDetailBySlug('produk-uji-' . $tag) !== null);
    $check('varian: menonaktifkan varian aktif terakhir pada produk terbit ditolak', ($svc->updateVariant($A, $pid, $vid2, ['size' => 'L', 'color' => 'Black', 'sku' => 'UJI-' . strtoupper($tag) . '-L', 'is_active' => ''], $ip)['ok']) && isset($svc->updateVariant($A, $pid, $vid1, ['size' => 'M', 'color' => 'Black', 'sku' => $vrow['sku']], $ip)['errors']['is_active']));

    $a1 = $svc->archive($A, $pid, $ip);
    $check('arsip: produk hilang dari katalog publik dan detail 404, data tetap ada', $a1['ok'] && !$inShop('produk-uji-' . $tag) && $catalog->findDetailBySlug('produk-uji-' . $tag) === null && $count('SELECT COUNT(*) AS n FROM product_variants WHERE product_id = :p', ['p' => $pid]) === 2);
    $check('arsip dua kali ditolak; restore produk non-arsip ditolak', !$svc->archive($A, $pid, $ip)['ok'] && !$svc->restore($A, (int) $p2['id'], $ip)['ok']);
    $check('produk terarsip: update tidak dapat mengubah status ke active', ($svc->update($A, $pid, ['status' => 'active', 'slug' => 'produk-uji-' . $tag] + $base, $ip)['ok']) && $one('SELECT status FROM products WHERE id = :i', ['i' => $pid])['status'] === 'archived');
    $r = $svc->restore($A, $pid, $ip);
    $check('restore: kembali sebagai draft (tidak otomatis tayang)', $r['ok'] && $one('SELECT status FROM products WHERE id = :i', ['i' => $pid])['status'] === 'draft' && !$inShop('produk-uji-' . $tag));
    $upd($pid, ['slug' => 'produk-uji-' . $tag, 'status' => 'active', 'price' => '159000', 'sale_price' => '129000']);
    $check('terbit ulang: tampil di katalog lagi', $inShop('produk-uji-' . $tag));
    $check('audit archive/restore tercatat', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE entity_type = 'product' AND entity_id = :i AND action IN ('product.archive','product.restore')", ['i' => $pid]) === 2);

    // ---------- Gambar ----------
    $dir = $tmpRoot . '/products';
    $one1 = $svc->addImages($A, $pid, [$mk($png)], 'Alt uji', $ip, false);
    $firstPath = (string) $one('SELECT path FROM product_images WHERE product_id = :p ORDER BY sort_order, id LIMIT 1', ['p' => $pid])['path'];
    $check('gambar: upload PNG valid tersimpan (nama acak, di folder products)', $one1['ok'] && $one1['added'] === 1 && preg_match('#^uploads/products/[a-f0-9]{32}\.png$#', $firstPath) === 1 && is_file($tmpRoot . '/' . substr($firstPath, 8)));
    $check('gambar: tambah 3 sekaligus sampai batas 4', $svc->addImages($A, $pid, [$mk($png), $mk($png), $mk($png)], '', $ip, false)['ok'] && $count('SELECT COUNT(*) AS n FROM product_images WHERE product_id = :p', ['p' => $pid]) === 4);
    $filesBefore = $files($dir);
    $over = $svc->addImages($A, $pid, [$mk($png)], '', $ip, false);
    $check('gambar: batas 4 per produk ditegakkan (tidak ada file/baris baru)', !$over['ok'] && $files($dir) === $filesBefore && $count('SELECT COUNT(*) AS n FROM product_images WHERE product_id = :p', ['p' => $pid]) === 4);

    $p3 = (int) $svc->create($A, ['name' => "Gambar Uji $tag"] + $base, $ip)['id'];
    $f0 = $files($dir);
    $reject = static fn(array $file) => $svc->addImages($A, $p3, [$file], '', $ip, false);
    $check('gambar: file PHP berkedok PNG ditolak', !$reject($mk("\x89PNG\r\n\x1a\n<?php system('id'); ?>", 'evil.png'))['ok']);
    $check('gambar: file teks berekstensi .jpg ditolak', !$reject($mk('hello world', 'x.jpg'))['ok']);
    $check('gambar: SVG ditolak', !$reject($mk('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg'))['ok']);
    $check('gambar: GIF (bukan JPG/PNG/WebP) ditolak', !$reject($mk($gif, 'x.gif'))['ok']);
    $check('gambar: PNG valid yang menyisipkan kode PHP ditolak', !$reject($mk(substr($png, 0, -12) . '<?php echo 1; ?>' . substr($png, -12), 'poly.png'))['ok']);
    $check('gambar: file > 2 MB ditolak', !$reject($mk($png . str_repeat('A', ImageUploadService::MAX_BYTES), 'big.png'))['ok']);
    $check('gambar: tanpa file / error upload ditolak', !$svc->addImages($A, $p3, [], '', $ip, false)['ok'] && !$reject(['name' => 'a.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0])['ok']);
    $check('gambar: alt text > 160 karakter ditolak', !$svc->addImages($A, $p3, [$mk($png)], str_repeat('a', 161), $ip, false)['ok']);
    $check('gambar: tidak ada file/baris tersisa dari upload yang ditolak', $files($dir) === $f0 && $count('SELECT COUNT(*) AS n FROM product_images WHERE product_id = :p', ['p' => $p3]) === 0);
    $check('gambar: upload langsung (bukan lewat HTTP) ditolak secara default', !$svc->addImages($A, $p3, [$mk($png)], '', $ip)['ok']);
    $mixed = $svc->addImages($A, $p3, [$mk($png), $mk('bukan gambar', 'x.png')], '', $ip, false);
    $check('gambar: batch dengan satu file tidak valid -> semua dibatalkan, tanpa file yatim', !$mixed['ok'] && $files($dir) === $f0 && $count('SELECT COUNT(*) AS n FROM product_images WHERE product_id = :p', ['p' => $p3]) === 0);

    $imgs = $pdo->query('SELECT id, path FROM product_images WHERE product_id = ' . $pid . ' ORDER BY sort_order, id')->fetchAll();
    $last = $imgs[3];
    $check('gambar: jadikan utama memindahkan ke urutan pertama', $svc->makePrimaryImage($A, $pid, (int) $last['id'], $ip)['ok'] && (int) $one('SELECT id FROM product_images WHERE product_id = :p ORDER BY sort_order, id LIMIT 1', ['p' => $pid])['id'] === (int) $last['id']);
    $check('gambar: ID gambar milik produk lain -> ditolak', !$svc->makePrimaryImage($A, $p3, (int) $last['id'], $ip)['ok'] && !$svc->deleteImage($A, $p3, (int) $last['id'], $ip)['ok'] && is_file($tmpRoot . '/' . substr($last['path'], 8)));
    $check('gambar: hapus menghapus baris dan file fisik', $svc->deleteImage($A, $pid, (int) $last['id'], $ip)['ok'] && !is_file($tmpRoot . '/' . substr($last['path'], 8)) && $count('SELECT COUNT(*) AS n FROM product_images WHERE product_id = :p', ['p' => $pid]) === 3);
    $check('gambar: ImageUploadService::delete menolak path di luar uploads', (function () use ($img, $tmpRoot) { $x = $tmpRoot . '/../outside-' . bin2hex(random_bytes(3)) . '.txt'; file_put_contents($x, 'x'); $img->delete('uploads/../' . basename($x)); $ok = is_file($x); @unlink($x); return $ok; })());

    // ---------- Hapus permanen vs arsip ----------
    $check('hapus: produk dengan riwayat penyesuaian stok ditolak (data utuh)', !$svc->delete($A, $pid, $ip)['ok'] && $count('SELECT COUNT(*) AS n FROM products WHERE id = :i', ['i' => $pid]) === 1);
    $pDel = (int) $svc->create($A, ['name' => "Hapus Uji $tag"] + $base, $ip)['id'];
    $vDel = (int) $svc->addVariant($A, $pDel, ['size' => 'XL', 'color' => 'Red', 'sku' => '', 'stock' => '3', 'is_active' => '1'], true, $ip)['id'];
    $svc->addImages($A, $pDel, [$mk($png)], '', $ip, false);
    $delPath = (string) $one('SELECT path FROM product_images WHERE product_id = :p', ['p' => $pDel])['path'];
    $pdo->prepare('WITH c AS (INSERT INTO carts (guest_token_hash) VALUES (:h) RETURNING id) INSERT INTO cart_items (cart_id, variant_id, quantity) SELECT id, CAST(:v AS bigint), 1 FROM c')->execute(['v' => $vDel, 'h' => hash('sha256', 'x' . $tag)]);
    $d = $svc->delete($A, $pDel, $ip);
    $check('hapus: produk tanpa pesanan dan hanya punya stok awal berhasil dihapus beserta varian, gambar, riwayat awal', $d['ok'] && $count('SELECT COUNT(*) AS n FROM products WHERE id = :i', ['i' => $pDel]) === 0 && $count('SELECT COUNT(*) AS n FROM product_variants WHERE id = :v', ['v' => $vDel]) === 0 && $count('SELECT COUNT(*) AS n FROM inventory_movements WHERE variant_id = :v', ['v' => $vDel]) === 0 && !is_file($tmpRoot . '/' . substr($delPath, 8)));
    $check('hapus: audit product.delete mencatat nama lama', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'product.delete' AND entity_id = :i AND old_values->>'name' = :n", ['i' => $pDel, 'n' => "Hapus Uji $tag"]) === 1);

    $pOrd = (int) $svc->create($A, ['name' => "Pesanan Uji $tag"] + $base, $ip)['id'];
    $vOrd = (int) $svc->addVariant($A, $pOrd, ['size' => 'S', 'color' => 'Blue', 'sku' => '', 'stock' => '0', 'is_active' => '1'], true, $ip)['id'];
    $oid = (int) $one(
        "INSERT INTO orders (order_number, access_token_hash, customer_email, recipient_name, recipient_phone, ship_address, ship_city, ship_province, ship_postal_code, shipping_courier, shipping_service, subtotal, shipping_cost, grand_total)
         VALUES (:n, :h, 'phase9a-test@example.invalid', 'X', '0812345678', 'Jl. Uji', 'Kota', 'Prov', '12345', 'K', 'S', 1000, 0, 1000) RETURNING id",
        ['n' => 'TEST-' . $tag, 'h' => hash('sha256', 'o' . $tag)]
    )['id'];
    $pdo->prepare("INSERT INTO order_items (order_id, variant_id, product_name, sku, size, color, unit_price, quantity, line_total) VALUES (:o, :v, 'Snap', 'SKU', 'S', 'Blue', 1000, 1, 1000)")->execute(['o' => $oid, 'v' => $vOrd]);
    $del = $svc->delete($A, $pOrd, $ip);
    $check('hapus: produk yang pernah dipesan ditolak; pesanan lama utuh; arsip tetap bisa', !$del['ok'] && $count('SELECT COUNT(*) AS n FROM order_items WHERE order_id = :o AND variant_id = :v', ['o' => $oid, 'v' => $vOrd]) === 1 && $svc->archive($A, $pOrd, $ip)['ok']);
    $check('hapus: id tidak ada -> ditolak', !$svc->delete($A, 2147483000, $ip)['ok']);

    // ---------- Kategori: integritas ----------
    $dc = $cats->delete($A, $catId, $ip);
    $check('kategori: hapus ditolak jika masih punya produk (dengan pesan)', !$dc['ok'] && str_contains((string) $dc['error'], 'product') && $count('SELECT COUNT(*) AS n FROM categories WHERE id = :i', ['i' => $catId]) === 1);
    $emptyCat = (int) $cats->create($A, ['name' => "Kosong $tag", 'sort_order' => '1', 'is_active' => '1'], $mk($png), $ip, false)['id'];
    $ePath = (string) $one('SELECT image_path FROM categories WHERE id = :i', ['i' => $emptyCat])['image_path'];
    $check('kategori: hapus kategori kosong berhasil dan file gambarnya ikut terhapus', $cats->delete($A, $emptyCat, $ip)['ok'] && !is_file($tmpRoot . '/' . substr($ePath, 8)));
    $check('kategori: nonaktif menyembunyikan produknya dari katalog publik', $cats->update($A, $catId, ['name' => "Kategori Uji $tag", 'slug' => 'kategori-uji-' . $tag, 'sort_order' => '6'], null, $ip)['ok'] && !$inShop('produk-uji-' . $tag));

    // ---------- Audit ----------
    $acts = $pdo->query("SELECT DISTINCT action FROM audit_logs WHERE actor_id = " . $A)->fetchAll(PDO::FETCH_COLUMN);
    $check('audit log: aksi admin utama tercatat', count(array_diff(['category.create', 'category.update', 'product.create', 'product.update', 'product.archive', 'product.restore', 'product.delete', 'variant.create', 'variant.update', 'inventory.adjust', 'product.images_add', 'product.image_delete', 'product.image_primary'], $acts)) === 0);
    $check('audit log tidak memuat data sensitif (password/hash/token)', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :a AND (coalesce(new_values::text,'') ~* '(password|hash|token)' OR coalesce(old_values::text,'') ~* '(password|hash|token)')", ['a' => $A]) === 0);
} catch (Throwable $e) {
    $fails++;
    echo '[FAIL] exception: ', $e->getMessage(), ' (', basename($e->getFile()), ':', $e->getLine(), ')', PHP_EOL;
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $rm($tmpRoot);
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
