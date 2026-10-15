<?php
declare(strict_types=1);
// Pengujian lapisan autentikasi/akun (Phase 7) langsung ke database. Jalankan: php scripts/auth-check.php
// Prasyarat: migrasi 003 dan 004 sudah dijalankan; seed bawaan. Seluruh data uji dibuat di dalam satu transaksi
// dan di-rollback di akhir, jadi database Anda tidak berubah. Email uji: phase7-test-*@example.invalid.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Core/Cookie.php';
require __DIR__ . '/../app/Core/Auth.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/UserRepository.php';
require __DIR__ . '/../app/Repositories/AuditLogRepository.php';
require __DIR__ . '/../app/Repositories/CartRepository.php';
require __DIR__ . '/../app/Repositories/WishlistRepository.php';
require __DIR__ . '/../app/Validators/AuthValidator.php';
require __DIR__ . '/../app/Services/CartService.php';
require __DIR__ . '/../app/Services/WishlistService.php';
require __DIR__ . '/../app/Services/AuthService.php';

use App\Config\Database;
use App\Core\Auth;
use App\Repositories\CartRepository;
use App\Repositories\UserRepository;
use App\Repositories\WishlistRepository;
use App\Services\AuthService;
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
$exec = static function (string $sql, array $p = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($p);
};
$count = static fn(string $sql, array $p = []): int => (int) $one($sql, $p)['n'];
$email = static fn(string $tag): string => 'phase7-test-' . $tag . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
$ip = '198.51.100.7';

$auth = new AuthService();
$users = new UserRepository();
$pw = 'correct horse 9';
$reg = static fn(string $e, string $p = 'correct horse 9', array $o = []): array => $auth->register(
    $o + ['full_name' => 'Test User', 'email' => $e, 'phone' => '', 'password' => $p, 'password_confirmation' => $p], '198.51.100.7'
);

$pdo->beginTransaction();
try {
    // ---------- Registrasi ----------
    $e1 = $email('a');
    $r = $reg($e1, $pw, ['phone' => '+62 812-3456-7890']);
    $check('registrasi valid berhasil', $r['ok'] === true && $r['user_id'] > 0);
    $u1 = (int) $r['user_id'];
    $row = $one('SELECT password_hash, phone, role_id, is_active FROM users WHERE id = :i', ['i' => $u1]);
    $check('password disimpan sebagai hash (bukan plaintext)', $row['password_hash'] !== $pw && password_get_info($row['password_hash'])['algoName'] !== 'unknown');
    $check('password_verify: benar lulus, salah gagal', password_verify($pw, $row['password_hash']) && !password_verify($pw . 'x', $row['password_hash']));
    $check('telepon dinormalisasi, role_id NULL, aktif', $row['phone'] === '+6281234567890' && $row['role_id'] === null && ($row['is_active'] === true || $row['is_active'] === 't'));
    $check('audit log user.register tercatat', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND action = 'user.register'", ['u' => $u1]) === 1);

    $dup = $reg($e1);
    $check('email duplikat ditolak (pesan di field email)', $dup['ok'] === false && isset($dup['errors']['email']));
    $check('email duplikat beda huruf besar/kecil ditolak', $reg(strtoupper($e1))['ok'] === false);
    $check('constraint unik: repo->create duplikat -> null (tanpa merusak transaksi)', $users->create('X Y', $e1, null, 'hash') === null);
    $check('setelah pelanggaran unik, transaksi masih berfungsi', $count('SELECT COUNT(*) AS n FROM users WHERE id = :i', ['i' => $u1]) === 1);

    $bad = static fn(array $o): array => $reg($email('v'), 'correct horse 9', $o);
    $check('nama kosong ditolak', isset($bad(['full_name' => ' '])['errors']['full_name']));
    $check('nama 1 karakter ditolak', isset($bad(['full_name' => 'A'])['errors']['full_name']));
    $check('nama > 120 karakter ditolak', isset($bad(['full_name' => str_repeat('a', 121)])['errors']['full_name']));
    $check('email tidak valid ditolak', isset($bad(['email' => 'bukan-email'])['errors']['email']));
    $check('telepon tidak valid ditolak', isset($bad(['phone' => 'abc'])['errors']['phone']));
    $check('password 7 karakter ditolak', isset($reg($email('v'), 'short12')['errors']['password']));
    $check('password 73 byte ditolak (tidak dipotong diam-diam)', isset($reg($email('v'), str_repeat('A', 73))['errors']['password']));
    $check('password 37 karakter multibyte (74 byte) ditolak', isset($reg($email('v'), str_repeat('é', 37))['errors']['password']));
    $check('konfirmasi password beda ditolak', isset($bad(['password_confirmation' => 'lain-lain-123'])['errors']['password_confirmation']));
    $check('password sama dengan email ditolak', isset($auth->register(['full_name' => 'T U', 'email' => 'phase7-test-same@example.invalid', 'password' => 'phase7-test-same@example.invalid', 'password_confirmation' => 'phase7-test-same@example.invalid'], $ip)['errors']['password']));

    // ---------- Login ----------
    $ok = $auth->login($e1, $pw, $ip);
    $check('login benar berhasil', $ok['ok'] === true && $ok['user_id'] === $u1);
    $check('login: email beda huruf besar/kecil tetap berhasil', $auth->login(strtoupper($e1), $pw, $ip)['ok'] === true);
    $wrong = $auth->login($e1, 'salah salah 1', $ip);
    $unknown = $auth->login($email('none'), $pw, $ip);
    $check('login salah: pesan generik', $wrong['ok'] === false && $wrong['error'] === AuthService::GENERIC_LOGIN_ERROR);
    $check('email tidak dikenal: pesan identik (tidak membocorkan akun)', $unknown['error'] === $wrong['error']);
    $users->clearFailures($e1);

    $exec('UPDATE users SET is_active = FALSE WHERE id = :i', ['i' => $u1]);
    $inactive = $auth->login($e1, $pw, $ip);
    $check('akun nonaktif ditolak dengan pesan generik', $inactive['ok'] === false && $inactive['error'] === AuthService::GENERIC_LOGIN_ERROR);
    $exec('UPDATE users SET is_active = TRUE WHERE id = :i', ['i' => $u1]);
    $roleId = (int) $one('SELECT id FROM roles ORDER BY id LIMIT 1')['id'];
    $exec('UPDATE users SET role_id = :r WHERE id = :i', ['r' => $roleId, 'i' => $u1]);
    $admin = $auth->login($e1, $pw, $ip);
    $check('akun ber-role (bukan pelanggan) ditolak dengan pesan generik', $admin['ok'] === false && $admin['error'] === AuthService::GENERIC_LOGIN_ERROR);
    $exec('UPDATE users SET role_id = NULL WHERE id = :i', ['i' => $u1]);
    $users->clearFailures($e1);
    $check('setelah dipulihkan, login berhasil lagi', $auth->login($e1, $pw, $ip)['ok'] === true);

    // Batas 72 byte tanpa pemotongan diam-diam
    $e72 = $email('b72');
    $long = str_repeat('A', 72);
    $reg($e72, $long);
    $check('password 72 byte: login dengan password yang sama berhasil', $auth->login($e72, $long, $ip)['ok'] === true);
    $check('password 73 byte (72 benar + 1) TIDAK diterima (bcrypt tidak memotong)', $auth->login($e72, $long . 'B', $ip)['ok'] === false);
    $users->clearFailures($e72);

    // Spasi pada password tidak dipangkas
    $eSp = $email('sp');
    $spaced = '  spaced pass 1  ';
    $reg($eSp, $spaced);
    $check('password dengan spasi di tepi: persis sama diterima', $auth->login($eSp, $spaced, $ip)['ok'] === true);
    $check('password dengan spasi di tepi: versi trim ditolak', $auth->login($eSp, trim($spaced), $ip)['ok'] === false);
    $users->clearFailures($eSp);

    // Rehash
    $eRh = $email('rh');
    $rh = $reg($eRh, $pw);
    $exec('UPDATE users SET password_hash = :h WHERE id = :i', ['h' => password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]), 'i' => $rh['user_id']]);
    $check('login dengan hash lama (cost rendah) berhasil', $auth->login($eRh, $pw, $ip)['ok'] === true);
    $newHash = (string) $one('SELECT password_hash FROM users WHERE id = :i', ['i' => $rh['user_id']])['password_hash'];
    $check('hash di-rehash otomatis dan tetap valid', !password_needs_rehash($newHash, PASSWORD_DEFAULT) && password_verify($pw, $newHash));

    // ---------- Rate limiting ----------
    $eRl = $email('rl');
    $reg($eRl, $pw);
    $attempts = [$eRl, strtoupper($eRl), $eRl, ucfirst($eRl), $eRl];
    foreach ($attempts as $a) { $auth->login($a, 'salah salah 1', $ip); }
    $check('5 kegagalan (variasi huruf besar/kecil) dihitung sebagai satu email', $users->failuresByIdentifier($eRl, 15) === 5);
    $locked = $auth->login($eRl, $pw, $ip);
    $check('percobaan ke-6 dengan password BENAR tetap terkunci', $locked['ok'] === false && $locked['locked'] === true);
    $check('variasi huruf besar juga terkunci', $auth->login(strtoupper($eRl), $pw, $ip)['locked'] === true);
    $exec("UPDATE login_attempts SET attempted_at = now() - INTERVAL '20 minutes' WHERE identifier = :i", ['i' => $eRl]);
    $check('kegagalan di luar jendela 15 menit tidak dihitung', $auth->login($eRl, $pw, $ip)['ok'] === true);

    $eOk = $email('rl2');
    $reg($eOk, $pw);
    for ($i = 0; $i < 3; $i++) { $auth->login($eOk, 'salah salah 1', $ip); }
    $auth->login($eOk, $pw, $ip);
    $check('login sukses membersihkan hitungan gagal', $users->failuresByIdentifier($eOk, 15) === 0);

    $ipLock = '203.0.113.50';
    for ($i = 0; $i < AuthService::MAX_FAILS_PER_IP; $i++) { $users->recordFailure($email('x' . $i), $ipLock); }
    $check('IP dengan 20 kegagalan terkunci walau email berbeda', $auth->login($eOk, $pw, $ipLock)['locked'] === true);
    $check('IP lain tidak terpengaruh', $auth->login($eOk, $pw, '203.0.113.51')['ok'] === true);

    // ---------- Session / Auth ----------
    $_SESSION = ['_csrf' => 'token-lama', '_old' => ['x' => 1]];
    Auth::login($u1, 1000);
    $check('Auth::login: user_id tersimpan, CSRF lama dibuang', Auth::id(1000) === $u1 && !isset($_SESSION['_csrf']) && !isset($_SESSION['_old']));
    $check('timeout idle: tepat 2 jam masih aktif', Auth::id(1000 + 7200) === $u1);
    $_SESSION['auth']['last_seen'] = 1000;
    $check('timeout idle: lewat 2 jam -> logout otomatis', Auth::id(1000 + 7201) === null && !isset($_SESSION['auth']));
    Auth::login($u1);
    $check('Auth::user() mengembalikan pelanggan (tanpa password_hash)', (Auth::user()['email'] ?? '') === $e1 && !isset(Auth::user()['password_hash']));
    $_SESSION['_csrf'] = 'abc';
    Auth::refresh();
    $check('Auth::refresh: CSRF dirotasi, login tetap', !isset($_SESSION['_csrf']) && Auth::check());
    Auth::login($u1); // reset cache user per-request; request web berikutnya selalu membaca database lagi
    $exec('UPDATE users SET is_active = FALSE WHERE id = :i', ['i' => $u1]);
    $check('akun dinonaktifkan: session dibatalkan', Auth::user() === null && !Auth::check());
    $exec('UPDATE users SET is_active = TRUE WHERE id = :i', ['i' => $u1]);
    Auth::login($u1);
    $exec('UPDATE users SET role_id = :r WHERE id = :i', ['r' => $roleId, 'i' => $u1]);
    $check('akun berubah jadi ber-role: session pelanggan dibatalkan', Auth::user() === null);
    $exec('UPDATE users SET role_id = NULL WHERE id = :i', ['i' => $u1]);
    Auth::login($u1);
    Auth::logout();
    $check('Auth::logout: session dikosongkan', $_SESSION === [] && Auth::id() === null);

    // ---------- Profil & password ----------
    $p = $auth->updateProfile($u1, ['full_name' => '  Nama   Baru ', 'phone' => '0812 3456 7890'], $ip);
    $row = $one('SELECT full_name, phone FROM users WHERE id = :i', ['i' => $u1]);
    $check('update profil: nama dirapikan, telepon dinormalisasi', $p['ok'] && $row['full_name'] === 'Nama Baru' && $row['phone'] === '081234567890');
    $check('update profil: telepon kosong -> NULL', $auth->updateProfile($u1, ['full_name' => 'Nama Baru', 'phone' => ''], $ip)['ok'] && $one('SELECT phone FROM users WHERE id = :i', ['i' => $u1])['phone'] === null);
    $check('update profil: input tidak valid ditolak dan data tidak berubah', !$auth->updateProfile($u1, ['full_name' => 'A', 'phone' => 'zzz'], $ip)['ok'] && $one('SELECT full_name FROM users WHERE id = :i', ['i' => $u1])['full_name'] === 'Nama Baru');
    $check('audit log user.profile_update tercatat', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND action = 'user.profile_update'", ['u' => $u1]) >= 1);

    $cp = static fn(int $id, array $o): array => $auth->changePassword($id, $o + ['current_password' => 'correct horse 9', 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'brand new pw 77'], $ip);
    $check('ganti password: password lama salah ditolak', isset($cp($u1, ['current_password' => 'salah banget 1'])['errors']['current_password']));
    $check('ganti password: sama dengan yang lama ditolak', isset($cp($u1, ['new_password' => 'correct horse 9', 'new_password_confirmation' => 'correct horse 9'])['errors']['new_password']));
    $check('ganti password: konfirmasi beda ditolak', isset($cp($u1, ['new_password_confirmation' => 'beda beda 123'])['errors']['new_password_confirmation']));
    $check('ganti password: terlalu pendek ditolak', isset($cp($u1, ['new_password' => 'pendek1', 'new_password_confirmation' => 'pendek1'])['errors']['new_password']));
    $users->clearFailures('pwd:' . $u1);
    $check('ganti password berhasil', $cp($u1, [])['ok'] === true);
    $users->clearFailures($e1);
    $check('setelah ganti: password baru bisa login, lama tidak', $auth->login($e1, 'brand new pw 77', $ip)['ok'] && !$auth->login($e1, $pw, $ip)['ok']);
    $users->clearFailures($e1);
    $check('audit log user.password_change tercatat tanpa data sensitif', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND action = 'user.password_change' AND new_values IS NULL", ['u' => $u1]) === 1);
    $check('audit log tidak memuat kata password/hash', $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND (new_values::text ILIKE '%password%' OR new_values::text ILIKE '%hash%')", ['u' => $u1]) === 0);

    $eLk = $email('lk');
    $lk = $reg($eLk, $pw);
    for ($i = 0; $i < 5; $i++) { $cp((int) $lk['user_id'], ['current_password' => 'salah banget ' . $i]); }
    $locked = $cp((int) $lk['user_id'], []);
    $check('ganti password: 5 tebakan password lama salah mengunci (walau yang benar)', $locked['ok'] === false && isset($locked['errors']['current_password']));

    // ---------- Gabung cart & wishlist guest -> akun ----------
    $variantOf = static fn(string $slug): array => $one(
        "SELECT v.id, v.product_id FROM product_variants v JOIN products p ON p.id = v.product_id
         WHERE p.slug = :s AND v.is_active AND v.stock > 5 ORDER BY v.id LIMIT 1", ['s' => $slug]);
    $tee = $variantOf('essential-t-shirt');
    $hoodie = $variantOf('everyday-hoodie');
    $sneaker = $variantOf('classic-sneakers');

    $eM = $email('m');
    $uM = (int) $reg($eM, $pw)['user_id'];
    $guestToken = bin2hex(random_bytes(32));
    $guest = new CartService($guestToken);
    $guest->add((int) $tee['id'], 3, (int) $tee['product_id']);
    $guest->add((int) $hoodie['id'], 1, (int) $hoodie['product_id']);
    $guest->add((int) $sneaker['id'], 1, (int) $sneaker['product_id']);
    $userCart = new CartService(null, $uM);
    $userCart->add((int) $hoodie['id'], 2, (int) $hoodie['product_id']);
    $exec("UPDATE products SET status = 'draft' WHERE id = :i", ['i' => $sneaker['product_id']]);   // item tidak lagi tersedia
    $exec('UPDATE product_variants SET stock = 2 WHERE id = :i', ['i' => $tee['id']]);              // stok < kuantitas guest
    $r = $guest->mergeGuestIntoUser($uM);
    $items = [];
    foreach ($userCart->view()['items'] as $it) { $items[$it['variant_id']] = $it['quantity']; }
    $check('merge cart: tee dibatasi stok (3 -> 2)', ($items[(int) $tee['id']] ?? 0) === 2);
    $check('merge cart: varian yang sama digabung (hoodie 2 + 1 = 3), tanpa baris ganda', ($items[(int) $hoodie['id']] ?? 0) === 3 && count($items) === 2);
    $check('merge cart: item produk nonaktif dibuang dan dilaporkan', $r['merged'] === 2 && $r['dropped'] === 1 && !isset($items[(int) $sneaker['id']]));
    $check('merge cart: cart guest dihapus dari database', $count('SELECT COUNT(*) AS n FROM carts WHERE guest_token_hash = :h', ['h' => hash('sha256', $guestToken)]) === 0);
    $check('merge cart: jumlah header = total unit (5)', $userCart->count() === 5);
    $check('merge cart ulang tanpa cart guest: tidak melakukan apa-apa', (new CartService($guestToken))->mergeGuestIntoUser($uM) === ['merged' => 0, 'dropped' => 0]);

    // merge atomik: kegagalan di tengah tidak mengubah cart guest
    $g2 = bin2hex(random_bytes(32));
    $guest2 = new CartService($g2);
    $guest2->add((int) $hoodie['id'], 1, (int) $hoodie['product_id']);
    $threw = false;
    try { (new CartRepository())->mergeGuestCart((int) $one('SELECT id FROM carts WHERE guest_token_hash = :h', ['h' => hash('sha256', $g2)])['id'], 2147483000, 20); } catch (Throwable) { $threw = true; }
    $check('merge cart gagal (user tidak ada): exception dan cart guest utuh', $threw && $guest2->count() === 1);

    // wishlist
    $teeP = (int) $tee['product_id'];
    $hoodieP = (int) $hoodie['product_id'];
    $gw = bin2hex(random_bytes(32));
    $gWish = new WishlistService($gw);
    $gWish->add($teeP);
    $gWish->add($hoodieP);
    $uWish = new WishlistService(null, $uM);
    $uWish->add($teeP);
    $merged = $gWish->mergeIntoUser($uM);
    $check('merge wishlist: tanpa duplikat (2 produk), 1 baru', $uWish->count() === 2 && $merged === 1);
    $check('merge wishlist: wishlist guest dihapus', $count('SELECT COUNT(*) AS n FROM wishlists WHERE guest_token_hash = :h', ['h' => hash('sha256', $gw)]) === 0);
    $gw2 = bin2hex(random_bytes(32));
    (new WishlistService($gw2))->add($teeP);
    $threw = false;
    try { $gid = (int) $one('SELECT id FROM wishlists WHERE guest_token_hash = :h', ['h' => hash('sha256', $gw2)])['id']; (new WishlistRepository())->mergeGuestIntoUser($gid, 2147483000); } catch (Throwable) { $threw = true; }
    $check('merge wishlist gagal: exception dan wishlist guest utuh', $threw && (new WishlistService($gw2))->count() === 1);

        // Reset cookie guest agar tes isolasi berjalan tanpa identitas guest lama.
    unset(
        $_COOKIE[CartService::COOKIE],
        $_COOKIE[WishlistService::COOKIE]
    );  
    // ---------- Isolasi antar pengguna ----------
    $uB = (int) $reg($email('b'), $pw)['user_id'];
    $bCart = new CartService(null, $uB);
    $bWish = new WishlistService(null, $uB);
    $check('isolasi: cart user B kosong, milik A tetap 5', $bCart->count() === 0 && $userCart->count() === 5);
    $check('isolasi: B tidak bisa mengubah/menghapus item A', $bCart->setQuantity((int) $tee['id'], 1)['ok'] === false && $bCart->remove((int) $tee['id'])['ok'] === false && $userCart->count() === 5);
    $check('isolasi: wishlist B kosong dan tidak bisa menghapus milik A', $bWish->count() === 0 && $bWish->remove($teeP)['ok'] === false && $uWish->count() === 2);
    $check('isolasi: guest tanpa token tidak melihat cart/wishlist user', (new CartService())->count() === 0 && (new WishlistService())->count() === 0);

    // forRequest mengikuti status login di session

    $_SESSION = [];
    Auth::login($uM);

    $check(
        'forRequest: user login memakai cart/wishlist akun',
        CartService::forRequest()->count() === 5
            && WishlistService::forRequest()->count() === 2
    );

    Auth::logout();

    unset(
        $_COOKIE[CartService::COOKIE],
        $_COOKIE[WishlistService::COOKIE]
    );

    $check(
        'forRequest: setelah logout kembali ke guest (kosong)',
        CartService::forRequest()->count() === 0
            && WishlistService::forRequest()->count() === 0
    );

} catch (Throwable $e) {
    $fails++;
    echo '[FAIL] exception: ', $e->getMessage(), ' (', basename($e->getFile()), ':', $e->getLine(), ')', PHP_EOL;
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
