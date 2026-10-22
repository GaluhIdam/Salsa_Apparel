<?php
declare(strict_types=1);
// Pengujian address book (Phase 8) ke database. Jalankan: php scripts/address-check.php
// Prasyarat: migrasi 005. Semua data uji dibuat dalam satu transaksi dan di-rollback di akhir.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/UserRepository.php';
require __DIR__ . '/../app/Repositories/AuditLogRepository.php';
require __DIR__ . '/../app/Repositories/AddressRepository.php';
require __DIR__ . '/../app/Validators/AuthValidator.php';
require __DIR__ . '/../app/Validators/AddressValidator.php';
require __DIR__ . '/../app/Services/AuthService.php';
require __DIR__ . '/../app/Services/AddressService.php';

use App\Config\Database;
use App\Repositories\UserRepository;
use App\Services\AddressService;

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
$ip = '198.51.100.8';
$svc = new AddressService();
$valid = static fn(array $o = []): array => $o + [
    'recipient_name' => 'Siti Aminah', 'phone' => '0812-3456-7890', 'address_line' => 'Jl. Merdeka No. 10',
    'city' => 'Tangerang Selatan', 'province' => 'Banten', 'postal_code' => '15310',
];
$defaults = static fn(int $u): int => $count('SELECT COUNT(*) AS n FROM addresses WHERE user_id = :u AND is_default', ['u' => $u]);
$total = static fn(int $u): int => $count('SELECT COUNT(*) AS n FROM addresses WHERE user_id = :u', ['u' => $u]);

$pdo->beginTransaction();
try {
    $users = new UserRepository();
    $mk = static fn(string $t): int => (int) $users->create('Addr ' . $t, 'phase8-test-' . $t . '-' . bin2hex(random_bytes(3)) . '@example.invalid', null, 'x');
    $a = $mk('a');
    $b = $mk('b');
    $check('user uji dibuat', $a > 0 && $b > 0);

    // Validasi
    foreach ([
        'recipient_name' => ['A', 'nama'], 'phone' => ['abc', 'telepon'], 'address_line' => ['x', 'alamat'],
        'city' => ['', 'kota'], 'province' => ['', 'provinsi'], 'postal_code' => ['1234', 'kode pos (4 digit)'],
    ] as $field => [$bad, $label]) {
        $r = $svc->create($a, $valid([$field => $bad]), $ip);
        $check("validasi: $label tidak valid ditolak", $r['ok'] === false && isset($r['errors'][$field]));
    }
    $check('validasi: kode pos 6 digit ditolak', isset($svc->create($a, $valid(['postal_code' => '153100']), $ip)['errors']['postal_code']));
    $check('validasi: alamat > 255 karakter ditolak', isset($svc->create($a, $valid(['address_line' => str_repeat('a', 256)]), $ip)['errors']['address_line']));
    $check('validasi gagal tidak menyimpan apa pun', $total($a) === 0);

    // Tambah
    $r1 = $svc->create($a, $valid(['recipient_name' => "  Siti \x00 Aminah  "]), $ip);
    $first = $svc->find($a, (int) $r1['id']);
    $check('alamat pertama berhasil dan otomatis menjadi default', $r1['ok'] && $first['is_default'] === true);
    $check('input dirapikan: spasi/karakter kontrol dibuang, telepon dinormalisasi', $first['recipient_name'] === 'Siti Aminah' && $first['phone'] === '081234567890');
    $r2 = $svc->create($a, $valid(['recipient_name' => 'Budi']), $ip);
    $check('alamat kedua bukan default; tetap 1 default', $r2['ok'] && $defaults($a) === 1 && $svc->find($a, (int) $r2['id'])['is_default'] === false);
    $r3 = $svc->create($a, $valid(['recipient_name' => 'Citra', 'is_default' => '1']), $ip);
    $check('alamat dengan is_default memindahkan default (tetap 1 default)', $r3['ok'] && $defaults($a) === 1 && $svc->find($a, (int) $r3['id'])['is_default'] === true && $svc->find($a, (int) $r1['id'])['is_default'] === false);
    $check('daftar: default tampil pertama', $svc->list($a)[0]['id'] === (int) $r3['id']);

    // Ubah
    $u = $svc->update($a, (int) $r2['id'], $valid(['city' => 'Jakarta']), $ip);
    $check('update milik sendiri berhasil', $u['ok'] && $svc->find($a, (int) $r2['id'])['city'] === 'Jakarta');
    $check('update dengan is_default memindahkan default', $svc->update($a, (int) $r2['id'], $valid(['is_default' => '1']), $ip)['ok'] && $defaults($a) === 1 && $svc->find($a, (int) $r2['id'])['is_default'] === true);
    $svc->update($a, (int) $r2['id'], $valid(), $ip); // tanpa is_default
    $check('tidak mencentang default pada default saat ini tidak menghapusnya', $svc->find($a, (int) $r2['id'])['is_default'] === true && $defaults($a) === 1);
    $check('update dengan input tidak valid tidak mengubah data', !$svc->update($a, (int) $r2['id'], $valid(['postal_code' => 'abc']), $ip)['ok'] && $svc->find($a, (int) $r2['id'])['postal_code'] === '15310');

    // Isolasi / IDOR
    $target = (int) $r1['id'];
    $before = $svc->find($a, $target);
    $check('IDOR: user B tidak bisa melihat alamat user A', $svc->find($b, $target) === null);
    $x = $svc->update($b, $target, $valid(['city' => 'Diretas']), $ip);
    $check('IDOR: user B tidak bisa mengubah alamat user A', $x['ok'] === false && !empty($x['not_found']));
    $check('IDOR: user B tidak bisa menghapus alamat user A', $svc->delete($b, $target, $ip) === false);
    $check('IDOR: user B tidak bisa menjadikan alamat user A sebagai default', $svc->setDefault($b, $target, $ip) === false);
    $check('IDOR: alamat user A tetap utuh', $svc->find($a, $target) === $before && $svc->list($b) === []);
    $bAddr = $svc->create($b, $valid(['recipient_name' => 'Milik B']), $ip);
    $check('user B bisa membuat alamat sendiri (default otomatis), tidak memengaruhi A', $bAddr['ok'] && $defaults($b) === 1 && $defaults($a) === 1);

    // Default / hapus
    $check('setDefault berpindah, tetap 1 default', $svc->setDefault($a, $target, $ip) && $defaults($a) === 1 && $svc->find($a, $target)['is_default'] === true);
    $check('setDefault id tidak ada ditolak', $svc->setDefault($a, 999999999, $ip) === false);
    $svc->delete($a, $target, $ip);
    $check('hapus alamat default: alamat terbaru dipromosikan, tetap 1 default', $total($a) === 2 && $defaults($a) === 1);
    $nonDefault = array_values(array_filter($svc->list($a), static fn($x) => !$x['is_default']))[0]['id'];
    $check('hapus alamat non-default tidak mengubah default', $svc->delete($a, $nonDefault, $ip) && $defaults($a) === 1 && $total($a) === 1);
    $check('hapus alamat yang sudah tidak ada ditolak', $svc->delete($a, $nonDefault, $ip) === false);
    $svc->delete($a, $svc->list($a)[0]['id'], $ip);
    $check('hapus alamat terakhir: kosong tanpa error', $total($a) === 0);

    // Batas jumlah
    for ($i = 1; $i <= AddressService::MAX_ADDRESSES; $i++) { $svc->create($a, $valid(['recipient_name' => "Penerima $i"]), $ip); }
    $over = $svc->create($a, $valid(), $ip);
    $check('batas 10 alamat per akun ditegakkan', $total($a) === 10 && $over['ok'] === false && isset($over['errors']['form']));

    // Constraint database: maksimal satu default per user (indeks unik parsial)
    $threw = false;
    try {
        Database::transaction(static function (PDO $pdo) use ($a): void {
            $pdo->prepare("INSERT INTO addresses (user_id, recipient_name, phone, address_line, city, province, postal_code, is_default)
                           VALUES (:u, 'X Y', '0812345678', 'Jl. Uji 1', 'Kota', 'Provinsi', '12345', TRUE)")->execute(['u' => $a]);
        });
    } catch (Throwable) { $threw = true; }
    $check('database menolak default kedua untuk user yang sama', $threw && $defaults($a) === 1);

    // Audit
    $check('audit log address.create/update/default/delete tercatat tanpa data alamat',
        $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND action LIKE 'address.%' AND new_values IS NULL", ['u' => $a]) >= 4);
} catch (Throwable $e) {
    $fails++;
    echo '[FAIL] exception: ', $e->getMessage(), ' (', basename($e->getFile()), ':', $e->getLine(), ')', PHP_EOL;
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
