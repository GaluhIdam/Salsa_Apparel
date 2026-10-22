<?php
declare(strict_types=1);
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
App\Core\Env::load(__DIR__ . '/../.env');
$pdo = App\Config\Database::connection();
$sessName = App\Core\Env::get('SESSION_NAME', 'salsa_session');

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
    $cookies = [];
    foreach ($resp as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $raw = trim(substr($line, 11));
            $cookies[] = $raw;
        [$pair, $attributes] = array_pad(explode(';', $raw, 2), 2, '');
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');

        if ($v === '' || stripos($attributes, 'expires=') !== false
            && preg_match('/expires=\s*([^;]+)/i', $attributes, $matches)
            && strtotime(trim($matches[1])) < time()) {
            unset($jar[$k]);
        } else {
            $jar[$k] = $v;
        }

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
function count_of(string $label, string $path): ?int
{
    return preg_match('/' . $label . ', (\d+) items/', http('GET', $path)['body'], $m) ? (int) $m[1] : null;
}
function register(string $email, string $pw, string $name = 'HTTP Test'): array
{
    return http('POST', '/register', ['_csrf' => csrf('/register'), 'full_name' => $name, 'email' => $email, 'phone' => '', 'password' => $pw, 'password_confirmation' => $pw]);
}
function login(string $email, string $pw, string $next = ''): array
{
    return http('POST', '/login', ['_csrf' => csrf('/login'), 'email' => $email, 'password' => $pw, 'next' => $next]);
}
function logout(): array
{
    return http('POST', '/logout', ['_csrf' => csrf('/account')]);
}
function purge(PDO $pdo): void
{
    $ids = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase7-test-%@example.invalid'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'user' AND entity_id = :i")->execute(['i' => $id]);
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :i')->execute(['i' => 'pwd:' . $id]);
    }
    $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE 'phase7-test-%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'phase7-test-%@example.invalid'");
}

purge($pdo);
$stamp = bin2hex(random_bytes(3));
$email = "phase7-test-http-$stamp@example.invalid";
$pw = 'Sup3r secret pw';
$userCount = static fn(string $e): int => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = " . $pdo->quote($e))->fetchColumn();
$clearAttempts = static fn() => $pdo->exec("DELETE FROM login_attempts WHERE identifier LIKE 'phase7-test-%'");

try {
    // ---------- Registrasi ----------
    $page = http('GET', '/register');
    check('/register 200 dan header menampilkan "Sign in" untuk guest', $page['status'] === 200 && str_contains($page['body'], 'aria-label="Sign in"'));
    $before = $jar[$sessName] ?? '';
    $token = csrf('/register');

    $r = http('POST', '/register', ['_csrf' => 'salah', 'full_name' => 'X Y', 'email' => $email, 'password' => $pw, 'password_confirmation' => $pw]);
    check('register dengan CSRF salah ditolak (303 /register), akun tidak dibuat', $r['status'] === 303 && $r['location'] === '/register' && $userCount($email) === 0);

    http('POST', '/register', ['_csrf' => $token, 'full_name' => 'HTTP Test', 'email' => $email, 'password' => $pw, 'password_confirmation' => 'tidak sama']);
    $form = http('GET', '/register');
    check('validasi: error tampil, email lama terisi ulang, password TIDAK terisi ulang',
        str_contains($form['body'], 'Passwords do not match.') && str_contains($form['body'], 'value="' . $email . '"') && !str_contains($form['body'], $pw));
    check('akun tidak dibuat saat validasi gagal', $userCount($email) === 0);

    $r = register($email, $pw);
    check('registrasi valid -> 303 ke /account (auto login)', $r['status'] === 303 && $r['location'] === '/account');
    check('ID session berubah setelah registrasi/login (anti fixation)', ($jar[$sessName] ?? '') !== '' && $jar[$sessName] !== $before);
    $sessCookie = array_values(array_filter($r['cookies'], static fn($c) => str_starts_with($c, $sessName . '=')))[0] ?? '';
    check('cookie session: HttpOnly dan SameSite=Lax', stripos($sessCookie, 'HttpOnly') !== false && stripos($sessCookie, 'SameSite=Lax') !== false);
    $acc = http('GET', '/account');
    check('/account 200 menampilkan email, header "My account", tanpa tautan Orders/Addresses',
        $acc['status'] === 200 && str_contains($acc['body'], $email) && str_contains($acc['body'], 'aria-label="My account"') && !str_contains($acc['body'], 'Orders') && !str_contains($acc['body'], 'Addresses'));
    check('GET /login saat sudah login -> /account', http('GET', '/login')['location'] === '/account');

    // ---------- Logout ----------
    check('GET /logout ditolak (404)', http('GET', '/logout')['status'] === 404);
    $r = http('POST', '/logout', ['_csrf' => 'salah']);
    check('logout dengan CSRF salah ditolak, tetap login', $r['status'] === 303 && http('GET', '/account')['status'] === 200);
    $sidLoggedIn = $jar[$sessName];
    $r = logout();
    check('logout valid -> 303 /login dan ID session berganti', $r['status'] === 303 && $r['location'] === '/login' && $jar[$sessName] !== $sidLoggedIn);
    check('flash "signed out" tampil di /login', str_contains(http('GET', '/login')['body'], 'signed out'));
    $guard = http('GET', '/account');
    check('/account tanpa login -> redirect ke /login?next=%2Faccount', $guard['status'] === 303 && $guard['location'] === '/login?next=%2Faccount');
    check('POST /account/profile tanpa login -> redirect /login (tidak mengubah apa pun)', http('POST', '/account/profile', ['_csrf' => csrf('/login'), 'full_name' => 'Hacker', 'phone' => ''])['location'] === '/login');

    // ---------- Duplikat ----------
    http('POST', '/register', ['_csrf' => csrf('/register'), 'full_name' => 'Dup', 'email' => strtoupper($email), 'password' => $pw, 'password_confirmation' => $pw]);
    check('email duplikat (huruf besar) ditolak dengan pesan', str_contains(http('GET', '/register')['body'], 'already exists') && $userCount($email) === 1);

    // ---------- Login gagal ----------
    $generic = 'Incorrect email or password.';
    $fail = static function (string $e, string $p) use ($generic): bool {
        $r = login($e, $p);
        return $r['status'] === 303 && str_starts_with($r['location'], '/login') && str_contains(http('GET', '/login')['body'], $generic);
    };
    check('login: password salah -> pesan generik', $fail($email, 'salah salah 1'));
    check('login: email tidak dikenal -> pesan generik yang sama', $fail("phase7-test-none-$stamp@example.invalid", $pw));
    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE email = :e')->execute(['e' => $email]);
    check('login: akun nonaktif -> pesan generik', $fail($email, $pw));
    $pdo->prepare('UPDATE users SET is_active = TRUE WHERE email = :e')->execute(['e' => $email]);
    $roleId = (int) $pdo->query('SELECT id FROM roles ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE users SET role_id = :r WHERE email = :e')->execute(['r' => $roleId, 'e' => $email]);
    check('login: akun ber-role (bukan pelanggan) -> pesan generik', $fail($email, $pw));
    $pdo->prepare('UPDATE users SET role_id = NULL WHERE email = :e')->execute(['e' => $email]);
    $clearAttempts();
    $r = http('POST', '/login', ['_csrf' => 'salah', 'email' => $email, 'password' => $pw]);
    check('login dengan CSRF salah ditolak, tidak login', $r['status'] === 303 && http('GET', '/account')['status'] === 303);

    // ---------- Parameter next ----------
    $cases = [
        ['//evil.example.com', '/account', 'next //host ditolak'],
        ['https://evil.example/', '/account', 'next URL absolut ditolak'],
        ["/x\r\nSet-Cookie: pwn=1", '/account', 'next dengan CRLF ditolak'],
        ['/wishlist', '/wishlist', 'next path internal diterima'],
    ];
    foreach ($cases as [$next, $expected, $label]) {
        $r = login($email, $pw, $next);
        check("login: $label", $r['status'] === 303 && $r['location'] === $expected && !isset($jar['pwn']));
        logout();
    }
    $g = http('GET', '/account');
    parse_str((string) parse_url($g['location'], PHP_URL_QUERY), $q);
    $r = login($email, $pw, (string) ($q['next'] ?? ''));
    check('alur proteksi: /account -> login (next dari redirect) -> kembali ke /account', $g['location'] === '/login?next=%2Faccount' && $r['location'] === '/account');
    logout();

    // ---------- Rate limit ----------
    $clearAttempts();
    for ($i = 0; $i < 5; $i++) { login(strtoupper($email), 'salah salah ' . $i); }
    $r = login($email, $pw);
    check('rate limit: setelah 5 gagal (beda huruf besar/kecil), login BENAR tetap ditolak', $r['status'] === 303 && str_contains(http('GET', '/login')['body'], 'Too many failed attempts') && http('GET', '/account')['status'] === 303);
    $clearAttempts();

    // ---------- Gabung cart & wishlist guest ----------
    $jar = [];
    $shop = http('GET', '/shop');
    preg_match('/name="product_id" value="(\d+)"/', $shop['body'], $pidM);
    $tee = http('GET', '/product/essential-t-shirt');
    preg_match('/name="variant_id" value="(\d+)"/', $tee['body'], $varM);
    preg_match('/name="product_id" value="(\d+)"/', $tee['body'], $teeIdM);
    $tok = csrf('/shop');
    http('POST', '/wishlist/add', ['_csrf' => $tok, 'product_id' => $pidM[1], 'return' => '/shop']);
    http('POST', '/cart/add', ['_csrf' => $tok, 'variant_id' => $varM[1], 'product_id' => $teeIdM[1], 'product_slug' => 'essential-t-shirt', 'quantity' => '2']);
    $guestCartToken = $jar['salsa_cart'] ?? '';
    check('guest: cart 2 unit dan wishlist 1 produk', count_of('Shopping bag', '/cart') === 2 && count_of('Wishlist', '/wishlist') === 1 && strlen($guestCartToken) === 64);
    $r = login($email, $pw);
    check('login guest -> /account', $r['status'] === 303 && $r['location'] === '/account');
    check('cart dan wishlist guest tergabung ke akun (2 unit, 1 produk)', count_of('Shopping bag', '/cart') === 2 && count_of('Wishlist', '/wishlist') === 1);
    check(
        'cookie guest salsa_cart dan salsa_wish dibersihkan',
        !isset($jar['salsa_cart']) && !isset($jar['salsa_wish'])
    );

    $st = $pdo->prepare('SELECT COUNT(*) FROM carts WHERE guest_token_hash = :h');
    $st->execute(['h' => hash('sha256', $guestCartToken)]);
    check('cart guest dihapus dari database, cart akun ada', (int) $st->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM carts c JOIN users u ON u.id = c.user_id WHERE u.email = " . $pdo->quote($email))->fetchColumn() === 1);
    $savedJar = $jar;

    // Isolasi: guest baru dan user lain tidak melihat data akun
    $jar = [];
    check('isolasi: guest baru melihat cart 0 dan wishlist 0', count_of('Shopping bag', '/cart') === 0 && count_of('Wishlist', '/wishlist') === 0);
    $email2 = "phase7-test-http2-$stamp@example.invalid";
    register($email2, $pw, 'Other User');
    check('isolasi: user lain (baru) melihat cart 0 dan wishlist 0', count_of('Shopping bag', '/cart') === 0 && count_of('Wishlist', '/wishlist') === 0);
    $jar = $savedJar;
    check('user pertama tetap melihat datanya (cart 2, wishlist 1)', count_of('Shopping bag', '/cart') === 2 && count_of('Wishlist', '/wishlist') === 1);

    // ---------- Profil & password ----------
    $tok = csrf('/account');
    http('POST', '/account/profile', ['_csrf' => 'salah', 'full_name' => 'Diubah Paksa', 'phone' => '']);
    check('update profil dengan CSRF salah tidak mengubah data', !str_contains(http('GET', '/account')['body'], 'Diubah Paksa'));
    http('POST', '/account/profile', ['_csrf' => $tok, 'full_name' => 'Nama Valid', 'phone' => 'abc']);
    $body = http('GET', '/account')['body'];
    check('profil: telepon tidak valid menampilkan error dan data tidak berubah', str_contains($body, 'valid phone number') && !str_contains($body, 'Nama Valid</dd>'));
    http('POST', '/account/profile', ['_csrf' => $tok, 'full_name' => 'Nama Valid', 'phone' => '0812-3456-7890']);
    $body = http('GET', '/account')['body'];
    check('profil: update valid tersimpan dan ditampilkan', str_contains($body, 'Nama Valid') && str_contains($body, '081234567890') && str_contains($body, 'Your profile has been updated'));

    $tok = csrf('/account');
    http('POST', '/account/password', ['_csrf' => $tok, 'current_password' => 'salah salah 1', 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'brand new pw 77']);
    check('ganti password: password lama salah ditolak', str_contains(http('GET', '/account')['body'], 'current password is incorrect'));
    http('POST', '/account/password', ['_csrf' => $tok, 'current_password' => $pw, 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'beda']);
    check('ganti password: konfirmasi beda ditolak', str_contains(http('GET', '/account')['body'], 'Passwords do not match'));
    $clearAttempts();
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :i')->execute(['i' => 'pwd:' . (int) $pdo->query('SELECT id FROM users WHERE email = ' . $pdo->quote($email))->fetchColumn()]);
    $sidBefore = $jar[$sessName];
    $tok = csrf('/account');
    $r = http('POST', '/account/password', ['_csrf' => $tok, 'current_password' => $pw, 'new_password' => 'brand new pw 77', 'new_password_confirmation' => 'brand new pw 77']);
    check('ganti password valid: ID session berganti, tetap login', $r['status'] === 303 && ($jar[$sessName] ?? '') !== $sidBefore && http('GET', '/account')['status'] === 200);
    logout();
    check('password lama tidak bisa login lagi', login($email, $pw)['location'] !== '/account');
    $clearAttempts();
    check('password baru bisa login', login($email, 'brand new pw 77')['location'] === '/account');
    logout();

    // ---------- Regresi ----------
    $jar = [];
    check('regresi: /shop, /category/men, /search, /cart, /wishlist, /login, /register -> 200',
        http('GET', '/shop')['status'] === 200 && http('GET', '/category/men')['status'] === 200 && http('GET', '/search?q=hoodie')['status'] === 200
        && http('GET', '/cart')['status'] === 200 && http('GET', '/wishlist')['status'] === 200 && http('GET', '/login')['status'] === 200 && http('GET', '/register')['status'] === 200);
    check('regresi: produk tidak ada -> 404', http('GET', '/product/tidak-ada')['status'] === 404);
} finally {
    purge($pdo);
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
