<?php
declare(strict_types=1);
// Uji alur HTTP address book dan reset password (Phase 8) terhadap server yang sedang berjalan.
//   Terminal 1: php -S localhost:8000 -t public public/index.php     (APP_ENV=local, MAIL_DRIVER=log di .env)
//   Terminal 2: php scripts/account-http-check.php [http://localhost:8000]
// Tidak ada email sungguhan: tautan reset dibaca dari storage/logs/mail.log (driver log, hanya APP_ENV=local).
// Membuat akun phase8-test-*@example.invalid dan MENGHAPUSNYA di akhir (beserta alamat, token, percobaan).
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
App\Core\Env::load(__DIR__ . '/../.env');
$pdo = App\Config\Database::connection();
$mailLog = __DIR__ . '/../storage/logs/mail.log';

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$jar = [];
$fails = 0;

function http(string $method, string $path, array $post = []): array
{
    global $base, $jar;
    $h = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($jar), $jar)) . "\r\n";
    $http = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0];
    if ($method === 'POST') {
        $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $http['content'] = http_build_query($post);
    }
    $body = (string) @file_get_contents($base . $path, false, stream_context_create(['http' => $http]));
    $resp = $http_response_header ?? [];
    $status = isset($resp[0]) ? (int) substr($resp[0], 9, 3) : 0;
    $location = '';
    foreach ($resp as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            [$pair] = explode(';', trim(substr($line, 11)), 2);
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $jar[$k] = $v;
        } elseif (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
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
function csrf(string $path): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', http('GET', $path)['body'], $m) ? $m[1] : '';
}
function register(string $email, string $pw): array
{
    return http('POST', '/register', ['_csrf' => csrf('/register'), 'full_name' => 'Phase8 Test', 'email' => $email, 'phone' => '', 'password' => $pw, 'password_confirmation' => $pw]);
}
function login(string $email, string $pw): array
{
    return http('POST', '/login', ['_csrf' => csrf('/login'), 'email' => $email, 'password' => $pw, 'next' => '']);
}
function addr(array $o = []): array
{
    return $o + ['recipient_name' => 'Siti Aminah', 'phone' => '081234567890', 'address_line' => 'Jl. Merdeka No. 10', 'city' => 'Tangerang Selatan', 'province' => 'Banten', 'postal_code' => '15310'];
}
function purge(PDO $pdo): void
{
    $rows = $pdo->query("SELECT id, email FROM users WHERE email LIKE 'phase8-test-%@example.invalid'")->fetchAll();
    foreach ($rows as $r) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_id = :i AND entity_type IN ('user','address')")->execute(['i' => $r['id']]);
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier IN (:a, :b, :c)')->execute(['a' => 'reset:' . hash('sha256', $r['email']), 'b' => $r['email'], 'c' => 'pwd:' . $r['id']]);
    }
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE 'resetip:%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'phase8-test-%@example.invalid'");
}
function mailTokenFor(string $email, string $log): ?string
{
    $text = is_file($log) ? (string) file_get_contents($log) : '';
    $blocks = array_reverse(explode("=====\n", $text));
    foreach ($blocks as $b) {
        if (str_contains($b, '<' . $email . '>') && preg_match('#/reset-password\?token=([a-f0-9]{64})#', $b, $m)) {
            return $m[1];
        }
    }
    return null;
}
function mailCount(string $email, string $log): int
{
    return is_file($log) ? substr_count((string) file_get_contents($log), '<' . $email . '>') : 0;
}

purge($pdo);
$stamp = bin2hex(random_bytes(3));
$email = "phase8-test-a-$stamp@example.invalid";
$email2 = "phase8-test-b-$stamp@example.invalid";
$pw = 'Sup3r secret pw';
$cnt = static fn(string $sql, array $p = []): int => (function () use ($pdo, $sql, $p): int { $st = $pdo->prepare($sql); $st->execute($p); return (int) $st->fetchColumn(); })();
$uid = static fn(string $e): int => $cnt('SELECT id FROM users WHERE email = :e', ['e' => $e]);
$devMail = App\Core\Env::get('APP_ENV', 'production') === 'local' && App\Core\Env::get('MAIL_DRIVER', 'log') === 'log';

try {
    // ---------- Address book ----------
    $g = http('GET', '/account/addresses');
    check('address book tanpa login -> redirect ke /login?next=', $g['status'] === 303 && str_starts_with($g['location'], '/login?next='));
    check('POST alamat tanpa login ditolak (redirect /login)', http('POST', '/account/addresses', addr(['_csrf' => 'x']))['location'] === '/login');

    register($email, $pw);
    $u1 = $uid($email);
    check('akun uji dibuat dan login', $u1 > 0 && http('GET', '/account')['status'] === 200);
    $empty = http('GET', '/account/addresses');
    check('/account/addresses 200 dengan empty state', $empty['status'] === 200 && str_contains($empty['body'], 'No saved addresses'));
    check('overview akun memuat tautan Address book', str_contains(http('GET', '/account')['body'], 'account/addresses'));
    check('form tambah alamat 200', http('GET', '/account/addresses/new')['status'] === 200);

    $t = csrf('/account/addresses/new');
    $r = http('POST', '/account/addresses', addr(['_csrf' => 'salah']));
    check('tambah alamat dengan CSRF salah ditolak, tidak tersimpan', $r['status'] === 303 && $cnt('SELECT COUNT(*) FROM addresses WHERE user_id = :u', ['u' => $u1]) === 0);
    http('POST', '/account/addresses', addr(['_csrf' => $t, 'postal_code' => '12']));
    $form = http('GET', '/account/addresses/new');
    check('validasi: error kode pos tampil dan input lama terisi ulang', str_contains($form['body'], '5-digit postal code') && str_contains($form['body'], 'Siti Aminah'));
    check('validasi gagal tidak menyimpan', $cnt('SELECT COUNT(*) FROM addresses WHERE user_id = :u', ['u' => $u1]) === 0);

    $t = csrf('/account/addresses/new');
    $r = http('POST', '/account/addresses', addr(['_csrf' => $t]));
    $list = http('GET', '/account/addresses');
    check('tambah alamat valid -> tersimpan, tampil, dan menjadi default', $r['status'] === 303 && str_contains($list['body'], 'Siti Aminah') && str_contains($list['body'], 'Default'));
    $t = csrf('/account/addresses/new');
    http('POST', '/account/addresses', addr(['_csrf' => $t, 'recipient_name' => 'Budi Santoso', 'is_default' => '1']));
    $defaultName = $pdo->query('SELECT recipient_name FROM addresses WHERE user_id = ' . $u1 . ' AND is_default')->fetchColumn();
    check('alamat kedua dengan is_default memindahkan default (tepat 1 default)', $defaultName === 'Budi Santoso' && $cnt('SELECT COUNT(*) FROM addresses WHERE user_id = :u AND is_default', ['u' => $u1]) === 1);
    check('form hapus memakai konfirmasi (data-confirm)', str_contains(http('GET', '/account/addresses')['body'], 'data-confirm'));

    // IDOR: user kedua
    $first = (int) $pdo->query('SELECT id FROM addresses WHERE user_id = ' . $u1 . " AND recipient_name = 'Siti Aminah'")->fetchColumn();
    $savedJar = $jar;
    $jar = [];
    register($email2, $pw);
    $u2 = $uid($email2);
    $t2 = csrf('/account/addresses/new');
    check('IDOR: GET edit alamat milik user lain -> 404', http('GET', "/account/addresses/$first/edit")['status'] === 404);
    $r = http('POST', "/account/addresses/$first", addr(['_csrf' => $t2, 'city' => 'Diretas']));
    check('IDOR: POST update alamat milik user lain -> 404 dan data tidak berubah', $r['status'] === 404 && $pdo->query("SELECT city FROM addresses WHERE id = $first")->fetchColumn() === 'Tangerang Selatan');
    http('POST', "/account/addresses/$first/default", ['_csrf' => $t2]);
    http('POST', "/account/addresses/$first/delete", ['_csrf' => $t2]);
    check('IDOR: default/hapus alamat user lain tidak berpengaruh', $cnt('SELECT COUNT(*) FROM addresses WHERE id = :i AND user_id = :u AND NOT is_default', ['i' => $first, 'u' => $u1]) === 1);
    check('user kedua melihat daftar alamat kosong', str_contains(http('GET', '/account/addresses')['body'], 'No saved addresses'));
    $jar = $savedJar;

    // Ubah, default, hapus (user pertama)
    $t = csrf('/account/addresses/new');
    http('POST', "/account/addresses/$first", addr(['_csrf' => $t, 'city' => 'Jakarta Selatan']));
    check('update milik sendiri tersimpan', $pdo->query("SELECT city FROM addresses WHERE id = $first")->fetchColumn() === 'Jakarta Selatan');
    http('POST', "/account/addresses/$first/default", ['_csrf' => 'salah']);
    check('set default dengan CSRF salah tidak berpengaruh', $cnt('SELECT COUNT(*) FROM addresses WHERE id = :i AND is_default', ['i' => $first]) === 0);
    http('POST', "/account/addresses/$first/default", ['_csrf' => $t]);
    check('set default valid memindahkan default', $cnt('SELECT COUNT(*) FROM addresses WHERE id = :i AND is_default', ['i' => $first]) === 1 && $cnt('SELECT COUNT(*) FROM addresses WHERE user_id = :u AND is_default', ['u' => $u1]) === 1);
    http('POST', "/account/addresses/$first/delete", ['_csrf' => 'salah']);
    check('hapus dengan CSRF salah tidak menghapus', $cnt('SELECT COUNT(*) FROM addresses WHERE id = :i', ['i' => $first]) === 1);
    http('POST', "/account/addresses/$first/delete", ['_csrf' => $t]);
    check('hapus alamat default: alamat lain dipromosikan menjadi default', $cnt('SELECT COUNT(*) FROM addresses WHERE user_id = :u', ['u' => $u1]) === 1 && $cnt('SELECT COUNT(*) FROM addresses WHERE user_id = :u AND is_default', ['u' => $u1]) === 1);
    check('id alamat tidak valid (huruf) -> 404', http('GET', '/account/addresses/abc/edit')['status'] === 404);

    // ---------- Reset password ----------
    $jar = [];
    check('/forgot-password 200', http('GET', '/forgot-password')['status'] === 200);
    check('tautan "Forgot your password?" ada di halaman login', str_contains(http('GET', '/login')['body'], 'forgot-password'));
    $before = mailCount($email, $mailLog);
    $r = http('POST', '/forgot-password', ['_csrf' => 'salah', 'email' => $email]);
    check('forgot dengan CSRF salah ditolak, tidak ada email', $r['status'] === 303 && mailCount($email, $mailLog) === $before);

    $msgKnown = '';
    $msgUnknown = '';
    http('POST', '/forgot-password', ['_csrf' => csrf('/forgot-password'), 'email' => $email]);
    $page = http('GET', '/forgot-password')['body'];
    preg_match('/class="flash flash--success"[^>]*>([^<]+)</', $page, $m1);
    $msgKnown = $m1[1] ?? '';
    http('POST', '/forgot-password', ['_csrf' => csrf('/forgot-password'), 'email' => "phase8-test-none-$stamp@example.invalid"]);
    $page = http('GET', '/forgot-password')['body'];
    preg_match('/class="flash flash--success"[^>]*>([^<]+)</', $page, $m2);
    $msgUnknown = $m2[1] ?? '';
    check('respons generik identik untuk email terdaftar dan tidak terdaftar', $msgKnown !== '' && $msgKnown === $msgUnknown);

    if ($devMail) {
        $token = mailTokenFor($email, $mailLog);
        check('log email memuat tautan reset untuk email terdaftar (driver log)', $token !== null && mailCount($email, $mailLog) === $before + 1);
        check('email tidak terdaftar tidak menghasilkan entri log', mailCount("phase8-test-none-$stamp@example.invalid", $mailLog) === 0);
        $jar = [];
        $r = http('GET', '/reset-password?token=' . $token);
        check('GET tautan reset -> redirect ke /reset-password tanpa token di URL', $r['status'] === 303 && $r['location'] === '/reset-password');
        $form = http('GET', '/reset-password');
        check('form reset 200 dan tidak memuat token di HTML', $form['status'] === 200 && !str_contains($form['body'], (string) $token));
        $t = csrf('/reset-password');
        http('POST', '/reset-password', ['_csrf' => 'salah', 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'brand new pw 77']);
        check('reset dengan CSRF salah tidak mengubah password', login($email, 'brand new pw 77')['location'] !== '/account');
        $jar = [];
        http('GET', '/reset-password?token=' . $token);
        $t = csrf('/reset-password');
        http('POST', '/reset-password', ['_csrf' => $t, 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'beda beda 12']);
        check('reset: konfirmasi beda ditolak, form tetap tersedia', str_contains(http('GET', '/reset-password')['body'], 'Passwords do not match'));
        $r = http('POST', '/reset-password', ['_csrf' => csrf('/reset-password'), 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'brand new pw 77']);
        check('reset valid -> redirect ke /login', $r['status'] === 303 && $r['location'] === '/login');
        $jar = [];
        check('password baru bisa login, password lama tidak', login($email, 'brand new pw 77')['location'] === '/account');
        http('POST', '/logout', ['_csrf' => csrf('/account')]);
        $pdo->exec("DELETE FROM login_attempts WHERE identifier = " . $pdo->quote($email));
        check('password lama tidak bisa login lagi', login($email, $pw)['location'] !== '/account');
        $jar = [];
        $r = http('GET', '/reset-password?token=' . $token);
        check('tautan yang sudah dipakai ditolak -> /forgot-password', $r['status'] === 303 && $r['location'] === '/forgot-password');
        check('/reset-password tanpa token/session -> /forgot-password', http('GET', '/reset-password')['location'] === '/forgot-password');
        check('token acak ditolak', http('GET', '/reset-password?token=' . bin2hex(random_bytes(32)))['location'] === '/forgot-password');
        check('token dengan format salah ditolak', http('GET', '/reset-password?token=abc')['location'] === '/forgot-password');
        $r = http('POST', '/reset-password', ['_csrf' => csrf('/forgot-password'), 'new_password' => 'x pw 12345', 'new_password_confirmation' => 'x pw 12345']);
        check('POST reset tanpa token di session ditolak', $r['location'] === '/forgot-password');

        // Rate limit
        $jar = [];
        $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE 'resetip:%'");
        $before2 = mailCount($email2, $mailLog);
        for ($i = 0; $i < 4; $i++) {
            http('POST', '/forgot-password', ['_csrf' => csrf('/forgot-password'), 'email' => $email2]);
        }
        check('rate limit: 4 permintaan -> hanya 3 email untuk akun yang sama', mailCount($email2, $mailLog) - $before2 === 3);
    } else {
        echo "[SKIP] pemeriksaan tautan reset dilewati: butuh APP_ENV=local dan MAIL_DRIVER=log di .env (driver log hanya mencatat isi email di lingkungan local)\n";
    }

    // ---------- Regresi ----------
    $jar = [];
    check('regresi: /shop, /category/men, /search, /cart, /wishlist, /login, /register, /forgot-password -> 200',
        http('GET', '/shop')['status'] === 200 && http('GET', '/category/men')['status'] === 200 && http('GET', '/search?q=hoodie')['status'] === 200
        && http('GET', '/cart')['status'] === 200 && http('GET', '/wishlist')['status'] === 200 && http('GET', '/login')['status'] === 200
        && http('GET', '/register')['status'] === 200 && http('GET', '/forgot-password')['status'] === 200);
    check('regresi: produk tidak ada -> 404', http('GET', '/product/tidak-ada')['status'] === 404);
} finally {
    purge($pdo);
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
