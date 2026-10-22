<?php
declare(strict_types=1);
// Uji alur HTTP Tahap A: akses admin, RBAC, CSRF, CRUD produk/varian/stok/gambar/kategori, upload berbahaya, regresi katalog.
//   Terminal 1: php -S localhost:8000 -t public public/index.php
//   Terminal 2: php scripts/admin-http-check.php [http://localhost:8000]
// Menulis ke database development dan ke public/uploads (membuat lalu MENGHAPUS data uji: akun phase9a-test-http-*,
// produk/kategori yang namanya memuat kode uji, file upload, audit log, dan percobaan login uji). Jangan jalankan di produksi.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
App\Core\Env::load(__DIR__ . '/../.env');
$pdo = App\Config\Database::connection();
$sessName = App\Core\Env::get('SESSION_NAME', 'salsa_session');
$uploadsDir = realpath(__DIR__ . '/../public/uploads') ?: (__DIR__ . '/../public/uploads');

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$jar = [];
$jars = [];
$current = 'guest';
$fails = 0;

function as_user(string $name): void
{
    global $jar, $jars, $current;
    $jars[$current] = $jar;
    $current = $name;
    $jar = $jars[$name] ?? [];
}
function http(string $method, string $path, array $post = [], ?array $multipart = null): array
{
    global $base, $jar;
    $h = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($jar), $jar)) . "\r\n";
    $http = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0];
    if ($method === 'POST') {
        if ($multipart !== null) {
            $b = '----salsa' . bin2hex(random_bytes(8));
            $body = '';
            foreach ($post as $k => $v) {
                $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
            }
            foreach ($multipart as $f) {
                $body .= "--$b\r\nContent-Disposition: form-data; name=\"{$f['field']}\"; filename=\"{$f['filename']}\"\r\nContent-Type: {$f['mime']}\r\n\r\n{$f['content']}\r\n";
            }
            $body .= "--$b--\r\n";
            $http['header'] .= "Content-Type: multipart/form-data; boundary=$b\r\n";
            $http['content'] = $body;
        } else {
            $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $http['content'] = http_build_query($post);
        }
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
function csrf(string $path): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', http('GET', $path)['body'], $m) ? $m[1] : '';
}
function admin_login(string $email, string $pw, string $next = ''): array
{
    return http('POST', '/admin/login', ['_csrf' => csrf('/admin/login'), 'email' => $email, 'password' => $pw, 'next' => $next]);
}
function admin_logout(): array
{
    return http('POST', '/admin/logout', ['_csrf' => csrf('/admin')]);
}
function product_fields(array $o = []): array
{
    return $o + ['name' => 'Produk HTTP', 'slug' => '', 'category_id' => '', 'price' => '149000', 'sale_price' => '', 'short_description' => 'Singkat', 'description' => 'Deskripsi', 'status' => 'draft'];
}
function cleanup(PDO $pdo, string $uploadsDir): void
{
    $pids = $pdo->query("SELECT id FROM products WHERE name LIKE '%phase9a-http-%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pids as $pid) {
        foreach ($pdo->query('SELECT path FROM product_images WHERE product_id = ' . (int) $pid)->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $f = $uploadsDir . '/' . substr((string) $path, 8);
            if (is_file($f)) { @unlink($f); }
        }
        $pdo->exec('DELETE FROM inventory_movements WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = ' . (int) $pid . ')');
        $pdo->exec('DELETE FROM audit_logs WHERE entity_type = \'product\' AND entity_id = ' . (int) $pid);
        $pdo->exec('DELETE FROM products WHERE id = ' . (int) $pid);
    }
    foreach ($pdo->query("SELECT id, image_path FROM categories WHERE name LIKE '%phase9a-http-%'")->fetchAll() as $c) {
        if ($c['image_path']) {
            $f = $uploadsDir . '/' . substr((string) $c['image_path'], 8);
            if (is_file($f)) { @unlink($f); }
        }
        $pdo->exec('DELETE FROM audit_logs WHERE entity_type = \'category\' AND entity_id = ' . (int) $c['id']);
        $pdo->exec('DELETE FROM categories WHERE id = ' . (int) $c['id']);
    }
    $ids = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase9a-test-http-%@example.invalid'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->exec('DELETE FROM audit_logs WHERE actor_id = ' . (int) $id);
    }
    $pdo->exec("DELETE FROM audit_logs WHERE entity_type = 'variant' AND new_values->>'sku' LIKE '%PHASE9A-HTTP%'");
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE 'phase9a-test-http-%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'phase9a-test-http-%@example.invalid'");
}

cleanup($pdo, $uploadsDir);
$stamp = bin2hex(random_bytes(3));
$tag = "phase9a-http-$stamp";
$pw = 'Sup3r secret pw';
$mkUser = static function (string $kind, ?string $role) use ($pdo, $pw, $stamp): string {
    $email = "phase9a-test-http-$kind-$stamp@example.invalid";
    $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role_id) VALUES (:e, :h, :n, (SELECT id FROM roles WHERE name = :r))')
        ->execute(['e' => $email, 'h' => password_hash($pw, PASSWORD_DEFAULT), 'n' => 'HTTP ' . $kind, 'r' => $role]);
    return $email;
};
$adminEmail = $mkUser('admin', 'admin');
$omEmail = $mkUser('om', 'order_manager');
$custEmail = $mkUser('cust', null);
$cnt = static function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
};
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
$img = static fn(string $name, string $content, string $mime = 'image/png', string $field = 'images[]') => ['field' => $field, 'filename' => $name, 'content' => $content, 'mime' => $mime];

try {
    // ---------- Akses tanpa login ----------
    foreach (['/admin', '/admin/products', '/admin/products/new', '/admin/categories', '/admin/products/1/edit'] as $path) {
        $r = http('GET', $path);
        check("guest: GET $path -> redirect ke /admin/login", $r['status'] === 303 && str_starts_with($r['location'], '/admin/login'));
    }
    $r = http('POST', '/admin/products', product_fields(['_csrf' => 'x', 'name' => "Guest $tag"]));
    check('guest: POST buat produk ditolak (redirect login) dan tidak membuat data', $r['status'] === 303 && $r['location'] === '/admin/login' && $cnt('SELECT COUNT(*) FROM products WHERE name = :n', ['n' => "Guest $tag"]) === 0);
    check('guest: POST hapus/arsip/stok/unggah ditolak', http('POST', '/admin/products/1/delete', ['_csrf' => 'x'])['location'] === '/admin/login' && http('POST', '/admin/products/1/archive', ['_csrf' => 'x'])['location'] === '/admin/login' && http('POST', '/admin/products/1/variants/1/stock', ['_csrf' => 'x', 'new_stock' => '1'])['location'] === '/admin/login' && http('POST', '/admin/products/1/images', ['_csrf' => 'x'], [$img('a.png', $png)])['location'] === '/admin/login');
    $login = http('GET', '/admin/login');
    check('/admin/login 200 dan tidak memuat navigasi admin', $login['status'] === 200 && str_contains($login['body'], 'Admin sign in') && !str_contains($login['body'], 'admin-sidebar'));

    // ---------- Login admin ----------
    $generic = 'Incorrect email or password.';
    admin_login($adminEmail, 'salah salah 1');
    check('login admin: password salah -> pesan generik', str_contains(http('GET', '/admin/login')['body'], $generic));
    admin_login($custEmail, $pw);
    check('login admin: akun pelanggan (tanpa role) -> pesan generik yang sama', str_contains(http('GET', '/admin/login')['body'], $generic) && http('GET', '/admin')['status'] === 303);
    http('POST', '/admin/login', ['_csrf' => 'salah', 'email' => $adminEmail, 'password' => $pw]);
    check('login admin dengan CSRF salah ditolak', http('GET', '/admin')['status'] === 303);
    $before = $jar[$sessName] ?? '';
    $r = admin_login($adminEmail, $pw);
    check('login admin valid -> redirect /admin dan ID session berganti', $r['status'] === 303 && $r['location'] === '/admin' && ($jar[$sessName] ?? '') !== $before);
    $c = array_values(array_filter($r['cookies'], static fn($x) => str_starts_with($x, $sessName . '=')))[0] ?? '';
    check('cookie session: HttpOnly dan SameSite=Lax', stripos($c, 'HttpOnly') !== false && stripos($c, 'SameSite=Lax') !== false);
    $home = http('GET', '/admin');
    check('/admin 200 untuk admin (navigasi Products & Categories tampil)', $home['status'] === 200 && str_contains($home['body'], 'admin-sidebar') && str_contains($home['body'], '>Products<') && str_contains($home['body'], '>Categories<'));
    check('GET /admin/login saat sudah login -> /admin', http('GET', '/admin/login')['location'] === '/admin');
    check('GET /admin/logout tidak ada (404)', http('GET', '/admin/logout')['status'] === 404);
    http('POST', '/admin/logout', ['_csrf' => 'salah']);
    check('logout dengan CSRF salah ditolak, tetap login', http('GET', '/admin/products')['status'] === 200);
    $sidIn = $jar[$sessName];
    $r = admin_logout();
    check('logout valid -> /admin/login, ID session berganti, akses admin ditutup', $r['status'] === 303 && $r['location'] === '/admin/login' && $jar[$sessName] !== $sidIn && http('GET', '/admin/products')['status'] === 303);
    foreach ([['/admin/products', '/admin/products'], ['//evil.example', '/admin'], ['https://evil.example/', '/admin'], ['/account', '/admin'], ["/admin/x\r\nSet-Cookie: pwn=1", '/admin']] as [$next, $expected]) {
        $r = admin_login($adminEmail, $pw, $next);
        check('login admin: next ' . json_encode(substr($next, 0, 22)) . " -> $expected", $r['location'] === $expected && !isset($jar['pwn']));
        admin_logout();
    }
    check('login pelanggan (/login) menolak akun staf (perilaku lama)', (function () use ($adminEmail, $pw) {
        $t = csrf('/login');
        http('POST', '/login', ['_csrf' => $t, 'email' => $adminEmail, 'password' => $pw, 'next' => '']);
        return http('GET', '/account')['status'] === 303;
    })());
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE 'phase9a-test-http-%'");

    // ---------- Order manager (RBAC) ----------
    as_user('om');
    admin_login($omEmail, $pw);
    check('order manager: /admin 200 tetapi tanpa menu Products/Categories', ($h = http('GET', '/admin'))['status'] === 200 && !str_contains($h['body'], '>Products<') && !str_contains($h['body'], '>Categories<'));
    check('order manager: /admin/products dan /admin/categories -> 403', http('GET', '/admin/products')['status'] === 403 && http('GET', '/admin/categories')['status'] === 403 && http('GET', '/admin/products/new')['status'] === 403);
    $omToken = csrf('/admin');
    $r = http('POST', '/admin/products', product_fields(['_csrf' => $omToken, 'name' => "OM $tag", 'category_id' => '1']));
    check('order manager: POST buat produk (CSRF valid) -> 403 dan tidak membuat data', $r['status'] === 403 && $cnt('SELECT COUNT(*) FROM products WHERE name = :n', ['n' => "OM $tag"]) === 0);
    as_user('cust');
    $t = csrf('/login');
    http('POST', '/login', ['_csrf' => $t, 'email' => $custEmail, 'password' => $pw, 'next' => '']);
    check('pelanggan login biasa: /admin tetap ditutup (redirect ke login admin)', http('GET', '/admin')['status'] === 303 && http('GET', '/admin/products')['status'] === 303);

    // ---------- Admin: kategori & produk ----------
    as_user('admin');
    admin_login($adminEmail, $pw);
    $t = csrf('/admin/categories/new');
    $catName = "Kategori $tag";
    http('POST', '/admin/categories', ['_csrf' => 'salah', 'name' => $catName, 'sort_order' => '1', 'is_active' => '1']);
    check('kategori: create dengan CSRF salah ditolak', $cnt('SELECT COUNT(*) FROM categories WHERE name = :n', ['n' => $catName]) === 0);
    $r = http('POST', '/admin/categories', ['_csrf' => $t, 'name' => $catName, 'description' => 'Uji', 'sort_order' => '3', 'is_active' => '1'], [$img('cat.png', $png, 'image/png', 'image')]);
    $catId = $cnt('SELECT id FROM categories WHERE name = :n', ['n' => $catName]);
    check('kategori: create valid beserta gambar -> tersimpan', $r['status'] === 303 && $catId > 0 && str_starts_with((string) $pdo->query("SELECT image_path FROM categories WHERE id = $catId")->fetchColumn(), 'uploads/categories/'));
    http('POST', '/admin/categories', ['_csrf' => $t, 'name' => strtoupper($catName), 'sort_order' => '1']);
    check('kategori: nama duplikat ditolak (pesan tampil)', str_contains(http('GET', '/admin/categories/new')['body'], 'already exists') && $cnt('SELECT COUNT(*) FROM categories WHERE lower(name) = lower(:n)', ['n' => $catName]) === 1);
    check('kategori: daftar dan edit 200; id tidak valid 404', str_contains(http('GET', '/admin/categories')['body'], $catName) && http('GET', "/admin/categories/$catId/edit")['status'] === 200 && http('GET', '/admin/categories/abc/edit')['status'] === 404 && http('GET', '/admin/categories/2147483000/edit')['status'] === 404);

    $t = csrf('/admin/products/new');
    $name = "Produk $tag <script>alert(1)</script>";
    $r = http('POST', '/admin/products', product_fields(['_csrf' => 'salah', 'name' => $name, 'category_id' => (string) $catId]));
    check('produk: create dengan CSRF salah ditolak', $r['status'] === 303 && $cnt('SELECT COUNT(*) FROM products WHERE name = :n', ['n' => $name]) === 0);
    http('POST', '/admin/products', product_fields(['_csrf' => $t, 'name' => $name, 'category_id' => (string) $catId, 'price' => 'abc']));
    $form = http('GET', '/admin/products/new');
    check('produk: validasi harga -> error tampil, input lama terisi, data tidak dibuat', str_contains($form['body'], 'greater than 0') && str_contains($form['body'], 'alert(1)') && !str_contains($form['body'], '<script>alert(1)</script>') && $cnt('SELECT COUNT(*) FROM products WHERE name = :n', ['n' => $name]) === 0);
    $r = http('POST', '/admin/products', product_fields(['_csrf' => $t, 'name' => $name, 'category_id' => (string) $catId]));
    preg_match('#^/admin/products/(\d+)/edit$#', $r['location'], $m);
    $pid = (int) ($m[1] ?? 0);
    $slug = (string) $pdo->query("SELECT slug FROM products WHERE id = $pid")->fetchColumn();
    check('produk: create valid -> draft, slug otomatis, redirect ke halaman edit', $pid > 0 && $slug !== '' && $pdo->query("SELECT status FROM products WHERE id = $pid")->fetchColumn() === 'draft');
    $list = http('GET', '/admin/products?q=' . urlencode($stamp));
    check('daftar produk: nama dengan HTML di-escape (tidak ada <script> mentah)', $list['status'] === 200 && str_contains($list['body'], '&lt;script&gt;') && !str_contains($list['body'], '<script>alert(1)</script>'));
    check('halaman edit 200; id tidak ada / bukan angka -> 404', http('GET', "/admin/products/$pid/edit")['status'] === 200 && http('GET', '/admin/products/2147483000/edit')['status'] === 404 && http('GET', '/admin/products/abc/edit')['status'] === 404);
    check('produk draft tidak tampil di toko publik (404 detail)', (function () use ($slug) { as_user('guest'); $s = http('GET', '/product/' . $slug)['status']; as_user('admin'); return $s === 404; })());

    $t = csrf("/admin/products/$pid/edit");
    http('POST', "/admin/products/$pid", product_fields(['_csrf' => 'salah', 'name' => $name, 'slug' => $slug, 'category_id' => (string) $catId, 'price' => '999000']));
    check('produk: update dengan CSRF salah tidak mengubah harga', (float) $pdo->query("SELECT price FROM products WHERE id = $pid")->fetchColumn() === 149000.0);
    http('POST', "/admin/products/$pid", product_fields(['_csrf' => $t, 'name' => $name, 'slug' => $slug, 'category_id' => (string) $catId, 'price' => '159000', 'sale_price' => '129000']));
    check('produk: update valid tersimpan (harga dan diskon)', (float) $pdo->query("SELECT price FROM products WHERE id = $pid")->fetchColumn() === 159000.0 && (float) $pdo->query("SELECT sale_price FROM products WHERE id = $pid")->fetchColumn() === 129000.0);
    http('POST', "/admin/products/$pid", product_fields(['_csrf' => $t, 'name' => $name, 'slug' => $slug, 'category_id' => (string) $catId, 'price' => '100', 'sale_price' => '200']));
    check('produk: diskon >= harga ditolak dan data tidak berubah', (float) $pdo->query("SELECT price FROM products WHERE id = $pid")->fetchColumn() === 159000.0);
    http('POST', "/admin/products/$pid", product_fields(['_csrf' => $t, 'name' => $name, 'slug' => $slug, 'category_id' => (string) $catId, 'price' => '159000', 'status' => 'active']));
    check('produk: publikasi tanpa varian ditolak', $pdo->query("SELECT status FROM products WHERE id = $pid")->fetchColumn() === 'draft');

    // varian & stok
    $vf = ['_csrf' => $t, 'size' => 'M', 'color' => 'Black', 'sku' => '', 'stock' => '5', 'is_active' => '1'];
    http('POST', "/admin/products/$pid/variants", ['_csrf' => 'salah'] + $vf);
    check('varian: tambah dengan CSRF salah tidak membuat data', $cnt('SELECT COUNT(*) FROM product_variants WHERE product_id = :p', ['p' => $pid]) === 0);
    http('POST', "/admin/products/$pid/variants", $vf);
    $vid = $cnt('SELECT id FROM product_variants WHERE product_id = :p', ['p' => $pid]);
    check('varian: tambah valid, stok awal tercatat di riwayat stok', $vid > 0 && $cnt('SELECT stock FROM product_variants WHERE id = :v', ['v' => $vid]) === 5 && $cnt("SELECT COUNT(*) FROM inventory_movements WHERE variant_id = :v AND reason = 'initial' AND change_qty = 5", ['v' => $vid]) === 1);
    http('POST', "/admin/products/$pid/variants", $vf);
    check('varian: kombinasi ukuran+warna duplikat ditolak (pesan tampil)', str_contains(http('GET', "/admin/products/$pid/edit")['body'], 'already exists') && $cnt('SELECT COUNT(*) FROM product_variants WHERE product_id = :p', ['p' => $pid]) === 1);
    http('POST', "/admin/products/$pid/variants/$vid/stock", ['_csrf' => $t, 'new_stock' => '12', 'note' => 'Restock uji']);
    check('stok: penyesuaian tersimpan dan tercatat (adjustment +7)', $cnt('SELECT stock FROM product_variants WHERE id = :v', ['v' => $vid]) === 12 && $cnt("SELECT COUNT(*) FROM inventory_movements WHERE variant_id = :v AND reason = 'adjustment' AND change_qty = 7", ['v' => $vid]) === 1);
    http('POST', "/admin/products/$pid/variants/$vid/stock", ['_csrf' => $t, 'new_stock' => '-3']);
    check('stok: nilai negatif ditolak', $cnt('SELECT stock FROM product_variants WHERE id = :v', ['v' => $vid]) === 12);
    http('POST', "/admin/products/$pid/variants/$vid/stock", ['_csrf' => 'salah', 'new_stock' => '99']);
    check('stok: CSRF salah ditolak', $cnt('SELECT stock FROM product_variants WHERE id = :v', ['v' => $vid]) === 12);

    // order manager boleh mengatur stok (inventory.manage) tetapi tidak mengubah detail produk
    as_user('om');
    $omToken = csrf('/admin');
    http('POST', "/admin/products/$pid/variants/$vid/stock", ['_csrf' => $omToken, 'new_stock' => '10', 'note' => 'OM']);
    check('order manager: boleh set stok (inventory.manage) dengan riwayat', $cnt('SELECT stock FROM product_variants WHERE id = :v', ['v' => $vid]) === 10);
    $r = http('POST', "/admin/products/$pid", product_fields(['_csrf' => $omToken, 'name' => 'Diubah OM', 'slug' => $slug, 'category_id' => (string) $catId, 'price' => '1000']));
    check('order manager: ubah detail produk -> 403 dan data tetap', $r['status'] === 403 && $pdo->query("SELECT name FROM products WHERE id = $pid")->fetchColumn() === $name);
    check('order manager: arsip/hapus/varian/unggah -> 403', http('POST', "/admin/products/$pid/archive", ['_csrf' => $omToken])['status'] === 403 && http('POST', "/admin/products/$pid/delete", ['_csrf' => $omToken])['status'] === 403 && http('POST', "/admin/products/$pid/variants", ['_csrf' => $omToken] + $vf)['status'] === 403 && http('POST', "/admin/products/$pid/images", ['_csrf' => $omToken], [$img('a.png', $png)])['status'] === 403);
    as_user('admin');

    // publikasi & toko publik
    $t = csrf("/admin/products/$pid/edit");
    $pub = static fn(string $status) => http('POST', "/admin/products/$pid", product_fields(['_csrf' => $t, 'name' => $name, 'slug' => $slug, 'category_id' => (string) $catId, 'price' => '159000', 'sale_price' => '129000', 'status' => $status]));
    $pub('active');
    $shop = static function () use ($stamp, $slug): array {
        as_user('guest');
        $r = [http('GET', '/product/' . $slug)['status'], str_contains(http('GET', '/shop?q=' . urlencode($stamp))['body'], '/product/' . $slug)];
        as_user('admin');
        return $r;
    };
    check('publikasi: produk tampil di toko publik (detail 200 dan muncul di pencarian)', $shop() === [200, true]);
    $r = http('POST', "/admin/products/$pid/archive", ['_csrf' => 'salah']);
    check('arsip dengan CSRF salah ditolak', $shop() === [200, true]);
    http('POST', "/admin/products/$pid/archive", ['_csrf' => $t]);
    check('arsip: hilang dari toko publik (detail 404, tidak di pencarian), data tetap ada', $shop() === [404, false] && $cnt('SELECT COUNT(*) FROM product_variants WHERE product_id = :p', ['p' => $pid]) === 1);
    http('POST', "/admin/products/$pid/restore", ['_csrf' => $t]);
    check('restore: kembali draft (belum tampil)', $shop() === [404, false] && $pdo->query("SELECT status FROM products WHERE id = $pid")->fetchColumn() === 'draft');
    $pub('active');
    check('terbit ulang: tampil lagi', $shop() === [200, true]);
    http('POST', "/admin/products/$pid/delete", ['_csrf' => $t]);
    check('hapus permanen ditolak karena ada riwayat penyesuaian stok (data utuh)', $cnt('SELECT COUNT(*) FROM products WHERE id = :p', ['p' => $pid]) === 1 && $cnt('SELECT COUNT(*) FROM product_variants WHERE product_id = :p', ['p' => $pid]) === 1);
    http('POST', '/admin/products/2147483000/archive', ['_csrf' => $t]);
    check('arsip id tidak ada tidak menimbulkan error server', http('GET', '/admin/products')['status'] === 200);

    // ---------- Gambar ----------
    $imgUrl = "/admin/products/$pid/images";
    http('POST', $imgUrl, ['_csrf' => 'salah'], [$img('a.png', $png)]);
    check('gambar: upload dengan CSRF salah ditolak', $cnt('SELECT COUNT(*) FROM product_images WHERE product_id = :p', ['p' => $pid]) === 0);
    http('POST', $imgUrl, ['_csrf' => $t, 'alt_text' => 'Alt uji'], [$img('asli.png', $png)]);
    $row = $pdo->query("SELECT id, path FROM product_images WHERE product_id = $pid")->fetch();
    check('gambar: PNG valid tersimpan (nama acak) dan file ada di public/uploads/products', $row !== false && preg_match('#^uploads/products/[a-f0-9]{32}\.png$#', $row['path']) === 1 && is_file($uploadsDir . '/' . substr($row['path'], 8)));
    check('gambar: tampil di halaman produk publik', (function () use ($slug, $row) { as_user('guest'); $b = http('GET', '/product/' . $slug)['body']; as_user('admin'); return str_contains($b, '/' . $row['path']); })());
    $rejects = [
        'file PHP bernama .php' => $img('shell.php', '<?php system($_GET["c"]); ?>', 'image/png'),
        'file PHP berkedok .png' => $img('shell.png', "\x89PNG\r\n\x1a\n<?php system('id'); ?>", 'image/png'),
        'teks berekstensi .jpg' => $img('x.jpg', 'bukan gambar', 'image/jpeg'),
        'SVG berisi script' => $img('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
        'GIF' => $img('x.gif', $gif, 'image/gif'),
        'file > 2 MB' => $img('big.png', $png . str_repeat('A', 2 * 1024 * 1024 + 10), 'image/png'),
    ];
    foreach ($rejects as $label => $file) {
        $n = $cnt('SELECT COUNT(*) FROM product_images WHERE product_id = :p', ['p' => $pid]);
        $files = count(glob($uploadsDir . '/products/*') ?: []);
        http('POST', $imgUrl, ['_csrf' => $t], [$file]);
        check("gambar: $label ditolak (tanpa baris/file baru)", $cnt('SELECT COUNT(*) FROM product_images WHERE product_id = :p', ['p' => $pid]) === $n && count(glob($uploadsDir . '/products/*') ?: []) === $files);
    }
    check('gambar: tidak ada file .php di folder upload', glob($uploadsDir . '/products/*.php') === []);
    http('POST', $imgUrl, ['_csrf' => $t], [$img('2.png', $png), $img('3.png', $png), $img('4.png', $png)]);
    check('gambar: sampai 4 gambar per produk', $cnt('SELECT COUNT(*) FROM product_images WHERE product_id = :p', ['p' => $pid]) === 4);
    $files4 = count(glob($uploadsDir . '/products/*') ?: []);
    http('POST', $imgUrl, ['_csrf' => $t], [$img('5.png', $png)]);
    check('gambar: gambar ke-5 ditolak (batas 4), tidak ada file baru', $cnt('SELECT COUNT(*) FROM product_images WHERE product_id = :p', ['p' => $pid]) === 4 && count(glob($uploadsDir . '/products/*') ?: []) === $files4);
    $ids = $pdo->query("SELECT id FROM product_images WHERE product_id = $pid ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
    http('POST', "$imgUrl/{$ids[3]}/primary", ['_csrf' => $t]);
    check('gambar: jadikan utama', (int) $pdo->query("SELECT id FROM product_images WHERE product_id = $pid ORDER BY sort_order, id LIMIT 1")->fetchColumn() === (int) $ids[3]);
    $victimPath = (string) $pdo->query("SELECT path FROM product_images WHERE id = " . (int) $ids[0])->fetchColumn();
    http('POST', "$imgUrl/{$ids[0]}/delete", ['_csrf' => 'salah']);
    check('gambar: hapus dengan CSRF salah ditolak', is_file($uploadsDir . '/' . substr($victimPath, 8)));
    http('POST', "$imgUrl/{$ids[0]}/delete", ['_csrf' => $t]);
    $rowAfterDelete = $cnt(
    'SELECT COUNT(*) FROM product_images WHERE id = :i',
    ['i' => $ids[0]]
);

$fileAfterDelete = is_file(
    $uploadsDir . '/' . substr($victimPath, 8)
);

$absoluteVictimPath = $uploadsDir . '/' . substr($victimPath, 8);

clearstatcache(true, $absoluteVictimPath);

echo '[DEBUG] Path absolut test: ' . $absoluteVictimPath . PHP_EOL;
echo '[DEBUG] File ada setelah clearstatcache: '
    . (is_file($absoluteVictimPath) ? 'YA' : 'TIDAK')
    . PHP_EOL;
echo '[DEBUG] File ada via file_exists: '
    . (file_exists($absoluteVictimPath) ? 'YA' : 'TIDAK')
    . PHP_EOL;
echo '[DEBUG] Path absolut test: '
    . $uploadsDir . '/' . substr($victimPath, 8)
    . PHP_EOL;

echo '[DEBUG] File ada setelah request: '
    . (is_file($uploadsDir . '/' . substr($victimPath, 8)) ? 'YA' : 'TIDAK')
    . PHP_EOL;

clearstatcache(true, $absoluteVictimPath);

$rowAfterDelete = (int) $cnt(
    'SELECT COUNT(*) FROM product_images WHERE id = :i',
    ['i' => $ids[0]]
);

$fileAfterDelete = file_exists($absoluteVictimPath);

echo '[DEBUG FINAL] rowAfterDelete: ' . $rowAfterDelete . PHP_EOL;
echo '[DEBUG FINAL] fileAfterDelete: ' . ($fileAfterDelete ? 'true' : 'false') . PHP_EOL;

check(
    'gambar: hapus valid menghapus baris dan file fisik',
    $rowAfterDelete === 0 && $fileAfterDelete === false
);
    http('POST', "/admin/products/2147483000/images/{$ids[1]}/delete", ['_csrf' => $t]);
    check('gambar: hapus lewat ID produk yang salah tidak menghapus gambar milik produk lain', $cnt('SELECT COUNT(*) FROM product_images WHERE id = :i', ['i' => $ids[1]]) === 1);

    // ---------- Kategori: integritas ----------
    http('POST', "/admin/categories/$catId/delete", ['_csrf' => $t]);
    check('kategori: hapus ditolak jika masih punya produk', $cnt('SELECT COUNT(*) FROM categories WHERE id = :i', ['i' => $catId]) === 1);
    $emptyName = "Kosong $tag";
    http('POST', '/admin/categories', ['_csrf' => $t, 'name' => $emptyName, 'sort_order' => '1', 'is_active' => '1']);
    $emptyId = $cnt('SELECT id FROM categories WHERE name = :n', ['n' => $emptyName]);
    http('POST', "/admin/categories/$emptyId/delete", ['_csrf' => 'salah']);
    check('kategori: hapus dengan CSRF salah ditolak', $cnt('SELECT COUNT(*) FROM categories WHERE id = :i', ['i' => $emptyId]) === 1);
    http('POST', "/admin/categories/$emptyId/delete", ['_csrf' => $t]);
    check('kategori: hapus kategori kosong berhasil', $cnt('SELECT COUNT(*) FROM categories WHERE id = :i', ['i' => $emptyId]) === 0);

    // ---------- Regresi toko publik ----------
    as_user('guest');
    check('regresi: /, /shop, /category/men, /search, /cart, /wishlist, /login, /register -> 200',
        http('GET', '/')['status'] === 200 && http('GET', '/shop')['status'] === 200 && http('GET', '/category/men')['status'] === 200
        && http('GET', '/search?q=hoodie')['status'] === 200 && http('GET', '/cart')['status'] === 200 && http('GET', '/wishlist')['status'] === 200
        && http('GET', '/login')['status'] === 200 && http('GET', '/register')['status'] === 200);
    check('regresi: produk tidak ada -> 404; rute admin tidak dikenal -> 404', http('GET', '/product/tidak-ada')['status'] === 404 && http('GET', '/admin/tidak-ada')['status'] === 404);
} finally {
    cleanup($pdo, $uploadsDir);
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
