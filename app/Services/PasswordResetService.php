<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Database;
use App\Core\Env;
use App\Repositories\AuditLogRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailerInterface;
use App\Validators\AuthValidator;

/**
 * Lupa/reset password. Token acak 32 byte; hanya hash SHA-256 yang disimpan. Berlaku 60 menit, sekali pakai.
 * Respons ke pengguna selalu generik (tidak membocorkan apakah email terdaftar).
 */
final class PasswordResetService
{
    public const TOKEN_TTL_MINUTES = 60;
    public const MAX_REQUESTS_PER_EMAIL = 3;
    public const MAX_REQUESTS_PER_IP = 10;
    public const WINDOW_MINUTES = 60;
    public const INVALID_TOKEN = 'This reset link is invalid or has expired. Please request a new one.';

    private UserRepository $users;
    private PasswordResetRepository $tokens;
    private AuditLogRepository $audit;
    private AuthValidator $validator;

    public function __construct(private ?MailerInterface $mailer = null, private ?string $baseUrl = null)
    {
        $this->users = new UserRepository();
        $this->tokens = new PasswordResetRepository();
        $this->audit = new AuditLogRepository();
        $this->validator = new AuthValidator();
    }

    /**
     * Minta tautan reset. Hasil dipakai pengujian; controller selalu menampilkan pesan generik.
     *
     * @return array{sent:bool,throttled:bool}
     */
    public function requestReset(string $email, ?string $ip = null): array
    {
        $ip ??= AuthService::clientIp();
        $email = AuthValidator::normalizeEmail($email);
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['sent' => false, 'throttled' => false];
        }

        // Rate limit memakai login_attempts (identifier berupa hash agar tidak menyimpan email mentah).
        $emailKey = 'reset:' . hash('sha256', $email);
        $ipKey = $ip !== null ? 'resetip:' . $ip : null;
        $throttled = $this->users->failuresByIdentifier($emailKey, self::WINDOW_MINUTES) >= self::MAX_REQUESTS_PER_EMAIL
            || ($ipKey !== null && $this->users->failuresByIdentifier($ipKey, self::WINDOW_MINUTES) >= self::MAX_REQUESTS_PER_IP);
        // ip_address sengaja NULL: identifier sudah memuat IP, dan baris ini tidak boleh ikut menghitung kunci login per IP.
        $this->users->recordFailure($emailKey, null);
        if ($ipKey !== null) {
            $this->users->recordFailure($ipKey, null);
        }
        if ($throttled) {
            return ['sent' => false, 'throttled' => true];
        }

        $user = $this->users->findByEmail($email);
        $eligible = $user !== null && $this->truthy($user['is_active']) && $user['role_id'] === null;
        if (!$eligible) {
            return ['sent' => false, 'throttled' => false];
        }

        $token = bin2hex(random_bytes(32));
        $userId = (int) $user['id'];
        Database::transaction(function () use ($userId, $token): void {
            $this->tokens->invalidateAllForUser($userId); // hanya tautan terbaru yang berlaku
            $this->tokens->create($userId, hash('sha256', $token), self::TOKEN_TTL_MINUTES);
        });
        $this->audit->log($userId, 'user.password_reset_request', 'user', $userId, null, $ip);

        try {
            $link = $this->baseUrl() . '/reset-password?token=' . $token;
            $body = "Hello {$user['full_name']},\n\nWe received a request to reset your Salsa Apparel password.\n"
                . "Use the link below within " . self::TOKEN_TTL_MINUTES . " minutes:\n\n{$link}\n\n"
                . "If you did not request this, you can ignore this email; your password will not change.\n";
            $mailer = $this->mailer ?? Mailer::make();
            $sent = $mailer->send($email, (string) $user['full_name'], 'Reset your Salsa Apparel password', $body);
        } catch (\Throwable $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
            $sent = false;
        }
        return ['sent' => $sent, 'throttled' => false];
    }

    public function isTokenValid(string $token): bool
    {
        return $this->tokenFormatOk($token) && $this->tokens->findValid(hash('sha256', $token)) !== null;
    }

    /** @return array{ok:bool,errors:array<string,string>} */
    public function resetPassword(string $token, array $input, ?string $ip = null): array
    {
        $ip ??= AuthService::clientIp();
        [$new, $errors] = $this->validator->newPassword($input);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        if (!$this->tokenFormatOk($token)) {
            return ['ok' => false, 'errors' => ['token' => self::INVALID_TOKEN]];
        }

        $hash = hash('sha256', $token);
        $result = Database::transaction(function () use ($hash, $new, $ip): ?array {
            $row = $this->tokens->findValid($hash, true); // dikunci: dua permintaan bersamaan tidak bisa memakai token yang sama
            if ($row === null) {
                return null;
            }
            $userId = (int) $row['user_id'];
            $this->users->updatePasswordHash($userId, password_hash($new, PASSWORD_DEFAULT));
            $this->tokens->markUsed((int) $row['id']);
            $this->tokens->invalidateAllForUser($userId);
            $this->users->clearFailures((string) $row['email']);
            $this->users->clearFailures('pwd:' . $userId);
            $this->audit->log($userId, 'user.password_reset', 'user', $userId, null, $ip);
            return ['user_id' => $userId];
        });
        return $result === null
            ? ['ok' => false, 'errors' => ['token' => self::INVALID_TOKEN]]
            : ['ok' => true, 'errors' => []];
    }

    private function tokenFormatOk(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    private function baseUrl(): string
    {
        $url = rtrim($this->baseUrl ?? (string) Env::get('APP_URL', ''), '/');
        if ($url !== '') {
            return $url;
        }
        // Tanpa APP_URL, Host header tidak boleh dipercaya untuk tautan di email; hanya dev lokal yang diizinkan.
        if (Env::get('APP_ENV', 'production') === 'local') {
            return 'http://' . (preg_match('/^[A-Za-z0-9.\-:]{1,100}$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) === 1 ? $_SERVER['HTTP_HOST'] : 'localhost:8000');
        }
        throw new \RuntimeException('APP_URL is not configured; cannot build the reset link.');
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}
