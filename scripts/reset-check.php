<?php
declare(strict_types=1);
// Pengujian lupa/reset password dan driver email log (Phase 8). Jalankan: php scripts/reset-check.php
// Tidak mengirim email sungguhan dan tidak memerlukan SMTP: email ditangkap oleh mailer uji. Semua data uji
// dibuat dalam satu transaksi dan di-rollback di akhir.
require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Repositories/UserRepository.php';
require __DIR__ . '/../app/Repositories/AuditLogRepository.php';
require __DIR__ . '/../app/Repositories/PasswordResetRepository.php';
require __DIR__ . '/../app/Validators/AuthValidator.php';
require __DIR__ . '/../app/Services/Mail/MailerInterface.php';
require __DIR__ . '/../app/Services/Mail/LogMailer.php';
require __DIR__ . '/../app/Services/Mail/Mailer.php';
require __DIR__ . '/../app/Services/AuthService.php';
require __DIR__ . '/../app/Services/PasswordResetService.php';

use App\Config\Database;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\Mail\LogMailer;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailerInterface;
use App\Services\PasswordResetService;

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

$mailer = new class implements MailerInterface {
    /** @var array<int,array{to:string,subject:string,body:string}> */
    public array $sent = [];
    public function send(string $toEmail, string $toName, string $subject, string $textBody): bool
    {
        $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody];
        return true;
    }
};
$base = 'https://shop.example.test';
$svc = new PasswordResetService($mailer, $base);
$auth = new AuthService();
$users = new UserRepository();
$ip = '198.51.100.20';
$email = static fn(string $t): string => 'phase8-test-' . $t . '-' . bin2hex(random_bytes(3)) . '@example.invalid';
$tokenFrom = static function (array $msg) use ($base): ?string {
    return preg_match('#' . preg_quote($base, '#') . '/reset-password\?token=([a-f0-9]{64})#', $msg['body'], $m) === 1 ? $m[1] : null;
};
$last = static fn() => end($mailer->sent) ?: null;
$envBackup = ['APP_ENV' => getenv('APP_ENV'), 'APP_URL' => getenv('APP_URL'), 'MAIL_DRIVER' => getenv('MAIL_DRIVER')];

$pdo->beginTransaction();
try {
    $pw = 'old password 11';
    $e1 = $email('a');
    $u1 = (int) $users->create('Reset A', $e1, null, password_hash($pw, PASSWORD_DEFAULT));

    // Permintaan reset
    $r = $svc->requestReset($e1, $ip);
    $msg = $last();
    $token = $msg ? $tokenFrom($msg) : null;
    $check('email terdaftar: reset diminta, email dikirim ke penerima yang benar', $r['sent'] === true && $msg !== null && $msg['to'] === $e1);
    $check('email memuat tautan reset dengan token 64 hex', $token !== null);
    $check('database hanya menyimpan hash token, bukan token mentah',
        $count('SELECT COUNT(*) AS n FROM password_reset_tokens WHERE token_hash = :h', ['h' => hash('sha256', (string) $token)]) === 1
        && $count('SELECT COUNT(*) AS n FROM password_reset_tokens WHERE token_hash = :h', ['h' => (string) $token]) === 0);
    $check('token berlaku sekitar 60 menit', $count("SELECT COUNT(*) AS n FROM password_reset_tokens WHERE user_id = :u AND expires_at BETWEEN now() + INTERVAL '59 minutes' AND now() + INTERVAL '61 minutes'", ['u' => $u1]) === 1);

    $n = count($mailer->sent);
    $unknown = $svc->requestReset($email('none'), $ip);
    $check('email tidak terdaftar: tidak ada email, tidak ada token, hasil tidak melempar error', $unknown['sent'] === false && count($mailer->sent) === $n);
    $eInactive = $email('off');
    $uOff = (int) $users->create('Off', $eInactive, null, 'x');
    $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = :i')->execute(['i' => $uOff]);
    $svc->requestReset($eInactive, $ip);
    $eAdmin = $email('adm');
    $uAdm = (int) $users->create('Adm', $eAdmin, null, 'x');
    $pdo->prepare('UPDATE users SET role_id = (SELECT id FROM roles ORDER BY id LIMIT 1) WHERE id = :i')->execute(['i' => $uAdm]);
    $svc->requestReset($eAdmin, $ip);
    $check('akun nonaktif dan akun ber-role tidak menerima email/token', count($mailer->sent) === $n
        && $count('SELECT COUNT(*) AS n FROM password_reset_tokens WHERE user_id IN (:a, :b)', ['a' => $uOff, 'b' => $uAdm]) === 0);
    $check('email dengan format tidak valid diabaikan', $svc->requestReset('bukan-email', $ip) === ['sent' => false, 'throttled' => false]);

    // Validasi token
    $check('isTokenValid: token benar valid', $svc->isTokenValid((string) $token));
    $check('isTokenValid: token acak/format salah tidak valid', !$svc->isTokenValid(bin2hex(random_bytes(32))) && !$svc->isTokenValid('abc') && !$svc->isTokenValid(''));

    // Permintaan baru membatalkan tautan lama
    $svc->requestReset($e1, $ip);
    $token2 = $tokenFrom($last());
    $check('permintaan baru membatalkan tautan lama; hanya yang terbaru valid', $token2 !== null && $token2 !== $token && !$svc->isTokenValid((string) $token) && $svc->isTokenValid($token2));

    // Validasi password baru: token tidak terpakai
    $bad = static fn(array $o): array => $svc->resetPassword((string) $token2, $o + ['new_password' => 'brand new pw 77', 'new_password_confirmation' => 'brand new pw 77'], $ip);
    $check('reset: password pendek ditolak', isset($bad(['new_password' => 'short1', 'new_password_confirmation' => 'short1'])['errors']['new_password']));
    $check('reset: password > 72 byte ditolak', isset($bad(['new_password' => str_repeat('A', 73), 'new_password_confirmation' => str_repeat('A', 73)])['errors']['new_password']));
    $check('reset: konfirmasi beda ditolak', isset($bad(['new_password_confirmation' => 'beda beda 12'])['errors']['new_password_confirmation']));
    $check('validasi gagal tidak mengonsumsi token', $svc->isTokenValid($token2));

    // Reset sukses, tautan sekali pakai
    for ($i = 0; $i < 5; $i++) { $users->recordFailure($e1, $ip); }
    $ok = $bad([]);
    $check('reset dengan token valid berhasil', $ok['ok'] === true);
    $check('reset menghapus hitungan login gagal', $users->failuresByIdentifier($e1, 15) === 0);
    $check('login: password baru diterima, password lama ditolak', $auth->login($e1, 'brand new pw 77', $ip)['ok'] === true && $auth->login($e1, $pw, $ip)['ok'] === false);
    $users->clearFailures($e1);
    $again = $bad([]);
    $check('token tidak bisa dipakai dua kali', $again['ok'] === false && isset($again['errors']['token']));
    $check('token terpakai tidak lagi valid', !$svc->isTokenValid($token2));

    // Kedaluwarsa
    $svc->requestReset($e1, $ip);
    $token3 = (string) $tokenFrom($last());
    $pdo->prepare("UPDATE password_reset_tokens SET expires_at = now() - INTERVAL '1 minute' WHERE user_id = :u AND used_at IS NULL")->execute(['u' => $u1]);
    $exp = $svc->resetPassword($token3, ['new_password' => 'another pw 99', 'new_password_confirmation' => 'another pw 99'], $ip);
    $check('token kedaluwarsa ditolak dan password tidak berubah', !$svc->isTokenValid($token3) && $exp['ok'] === false && isset($exp['errors']['token']) && $auth->login($e1, 'brand new pw 77', $ip)['ok'] === true);
    $users->clearFailures($e1);

    // Rate limit
    $eRl = $email('rl');
    $users->create('RL', $eRl, null, password_hash($pw, PASSWORD_DEFAULT));
    $nBefore = count($mailer->sent);
    $res = [];
    foreach ([$eRl, strtoupper($eRl), $eRl, $eRl] as $addr) { $res[] = $svc->requestReset($addr, '198.51.100.21'); }
    $check('rate limit email: 3 permintaan pertama diproses (huruf besar/kecil dianggap sama)', count($mailer->sent) - $nBefore === 3 && $res[0]['sent'] && $res[1]['sent'] && $res[2]['sent']);
    $check('rate limit email: permintaan ke-4 ditolak diam-diam (tanpa email)', $res[3]['throttled'] === true && $res[3]['sent'] === false && count($mailer->sent) - $nBefore === 3);

    $eIp = $email('ip');
    $users->create('IP', $eIp, null, password_hash($pw, PASSWORD_DEFAULT));
    for ($i = 0; $i < PasswordResetService::MAX_REQUESTS_PER_IP; $i++) { $svc->requestReset($email('x' . $i), '198.51.100.22'); }
    $nIp = count($mailer->sent);
    $thr = $svc->requestReset($eIp, '198.51.100.22');
    $check('rate limit IP: setelah 10 permintaan, IP yang sama dibatasi walau email berbeda', $thr['throttled'] === true && count($mailer->sent) === $nIp);
    $check('rate limit IP: IP lain tidak terpengaruh', $svc->requestReset($eIp, '198.51.100.23')['sent'] === true);
    $check('rate limit reset tidak ikut menghitung kunci login per IP', $users->failuresByIp('198.51.100.22', 15) === 0);

    // Audit
    $check('audit log: permintaan dan reset tercatat tanpa token',
        $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND action = 'user.password_reset_request'", ['u' => $u1]) >= 1
        && $count("SELECT COUNT(*) AS n FROM audit_logs WHERE actor_id = :u AND action = 'user.password_reset' AND new_values IS NULL", ['u' => $u1]) === 1);

    // Ketahanan mailer
    $boom = new class implements MailerInterface {
        public function send(string $toEmail, string $toName, string $subject, string $textBody): bool { throw new RuntimeException('smtp down'); }
    };
    $eB = $email('boom');
    $users->create('Boom', $eB, null, 'x');
    $survive = (new PasswordResetService($boom, $base))->requestReset($eB, '198.51.100.24');
    $check('mailer gagal: tidak melempar exception ke pengguna, sent=false', $survive['sent'] === false);

    // Driver log
    $tmp = sys_get_temp_dir() . '/salsa-mail-' . bin2hex(random_bytes(4)) . '.log';
    putenv('APP_ENV=local');
    $okLocal = (new LogMailer($tmp))->send('t@example.invalid', 'T', 'Subjek', "isi rahasia https://x/reset-password?token=abc");
    $check('log driver (local): menulis isi penuh ke file dan mengembalikan true', $okLocal === true && str_contains((string) file_get_contents($tmp), 'isi rahasia'));
    @unlink($tmp);
    putenv('APP_ENV=production');
    $okProd = (new LogMailer($tmp))->send('t@example.invalid', 'T', 'Subjek', "isi rahasia https://x/reset-password?token=abc");
    $content = (string) file_get_contents($tmp);
    $check('log driver (produksi): tidak mencatat isi/token dan mengembalikan false (tidak mengaku terkirim)', $okProd === false && !str_contains($content, 'isi rahasia') && str_contains($content, 'Subjek'));
    @unlink($tmp);
    putenv('MAIL_DRIVER=log');
    $check('Mailer::make: driver log tersedia', Mailer::make() instanceof LogMailer);
    putenv('MAIL_DRIVER=smtp');
    $threw = false;
    try { Mailer::make(); } catch (RuntimeException) { $threw = true; }
    $check('Mailer::make: driver smtp belum diimplementasikan -> error jelas (tidak diam-diam)', $threw);
    putenv('MAIL_DRIVER=log');

    // Tanpa APP_URL di produksi: tautan tidak boleh dibuat dari header Host
    putenv('APP_ENV=production');
    putenv('APP_URL=');
    $eU = $email('url');
    $users->create('URL', $eU, null, 'x');
    $m2 = new class implements MailerInterface {
        public int $n = 0;
        public function send(string $toEmail, string $toName, string $subject, string $textBody): bool { $this->n++; return true; }
    };
    $noUrl = (new PasswordResetService($m2))->requestReset($eU, '198.51.100.25');
    $check('produksi tanpa APP_URL: tidak mengirim tautan dari Host header (sent=false, 0 email)', $noUrl['sent'] === false && $m2->n === 0);
} catch (Throwable $e) {
    $fails++;
    echo '[FAIL] exception: ', $e->getMessage(), ' (', basename($e->getFile()), ':', $e->getLine(), ')', PHP_EOL;
} finally {
    foreach ($envBackup as $k => $v) { putenv($v === false ? $k : "$k=$v"); }
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

echo PHP_EOL, $fails === 0 ? 'Semua cek lulus.' : "$fails cek gagal.", PHP_EOL;
exit($fails === 0 ? 0 : 1);
