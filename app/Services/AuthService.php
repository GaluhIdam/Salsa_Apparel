<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Validators\AuthValidator;

/** Registrasi, login (dengan rate limit), perubahan profil/password, dan penggabungan data guest. */
final class AuthService
{
    public const MAX_FAILS_PER_IDENTIFIER = 5;
    public const MAX_FAILS_PER_IP = 20;
    public const WINDOW_MINUTES = 15;
    public const GENERIC_LOGIN_ERROR = 'Incorrect email or password.';
    public const LOCKED_ERROR = 'Too many failed attempts. Please wait a few minutes and try again.';

    private UserRepository $users;
    private AuditLogRepository $audit;
    private AuthValidator $validator;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->audit = new AuditLogRepository();
        $this->validator = new AuthValidator();
    }

    /** REMOTE_ADDR saja (header proxy tidak dipercaya); null jika tidak valid (mis. CLI). */
    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>,user_id:?int} */
    public function register(array $input, ?string $ip = null): array
    {
        $ip ??= self::clientIp();
        [$data, $errors] = $this->validator->register($input);
        $old = [
            'full_name' => $data['name'],
            'email' => $data['email'],
            'phone' => is_string($input['phone'] ?? null) ? trim($input['phone']) : '',
        ];
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'old' => $old, 'user_id' => null];
        }
        if ($this->users->findByEmail($data['email']) !== null) {
            return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists. Try signing in.'], 'old' => $old, 'user_id' => null];
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $userId = $this->users->create($data['name'], $data['email'], $data['phone'], $hash);
        if ($userId === null) { // balapan: email didaftarkan request lain di antara cek dan insert
            return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists. Try signing in.'], 'old' => $old, 'user_id' => null];
        }
        $this->audit->log($userId, 'user.register', 'user', $userId, null, $ip);
        return ['ok' => true, 'errors' => [], 'old' => [], 'user_id' => $userId];
    }

    /** @return array{ok:bool,error:?string,locked:bool,user_id:?int} */
    public function login(string $email, string $password, ?string $ip = null, string $audience = 'customer'): array
    {
        $ip ??= self::clientIp();
        $identifier = $this->identifier($email);

        if ($this->isLocked($identifier, $ip)) {
            return ['ok' => false, 'error' => self::LOCKED_ERROR, 'locked' => true, 'user_id' => null];
        }

        $user = $identifier === '' ? null : $this->users->findByEmail($identifier);
        // Selalu menjalankan verifikasi hash (hash dummy jika user tidak ada) agar waktu respons tidak membocorkan keberadaan akun.
        $hash = $user['password_hash'] ?? self::dummyHash();
        // Password > 72 byte tidak mungkin tersimpan utuh; tolak, jangan biarkan bcrypt memotongnya diam-diam.
        $passwordOk = strlen($password) <= AuthValidator::PASSWORD_MAX_BYTES && password_verify($password, $hash);
        // audience 'customer' (default, perilaku lama): hanya akun TANPA role. audience 'admin': hanya akun BER-role.
        $roleOk = $audience === 'admin' ? ($user !== null && $user['role_id'] !== null) : ($user !== null && $user['role_id'] === null);
        $eligible = $user !== null
            && $this->truthy($user['is_active'])
            && $roleOk;

        if (!$passwordOk || !$eligible) {
            $this->users->recordFailure($identifier, $ip);
            return ['ok' => false, 'error' => self::GENERIC_LOGIN_ERROR, 'locked' => false, 'user_id' => null];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        $this->users->clearFailures($identifier);
        return ['ok' => true, 'error' => null, 'locked' => false, 'user_id' => (int) $user['id']];
    }

    /** @return array{ok:bool,errors:array<string,string>,old:array<string,string>} */
    public function updateProfile(int $userId, array $input, ?string $ip = null): array
    {
        [$data, $errors] = $this->validator->profile($input);
        $old = [
            'full_name' => $data['name'],
            'phone' => is_string($input['phone'] ?? null) ? trim($input['phone']) : '',
        ];
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'old' => $old];
        }
        $this->users->updateProfile($userId, $data['name'], $data['phone']);
        $this->audit->log($userId, 'user.profile_update', 'user', $userId, ['fields' => ['full_name', 'phone']], $ip ?? self::clientIp());
        return ['ok' => true, 'errors' => [], 'old' => []];
    }

    /** @return array{ok:bool,errors:array<string,string>} */
    public function changePassword(int $userId, array $input, ?string $ip = null): array
    {
        $ip ??= self::clientIp();
        [$data, $errors] = $this->validator->passwordChange($input);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $identifier = 'pwd:' . $userId; // rate limit terpisah untuk tebakan password lama
        if ($this->users->failuresByIdentifier($identifier, self::WINDOW_MINUTES) >= self::MAX_FAILS_PER_IDENTIFIER) {
            return ['ok' => false, 'errors' => ['current_password' => self::LOCKED_ERROR]];
        }
        $user = $this->users->findById($userId);
        $valid = $user !== null
            && strlen($data['current']) <= AuthValidator::PASSWORD_MAX_BYTES
            && password_verify($data['current'], $user['password_hash']);
        if (!$valid) {
            $this->users->recordFailure($identifier, $ip);
            return ['ok' => false, 'errors' => ['current_password' => 'Your current password is incorrect.']];
        }

        $this->users->updatePasswordHash($userId, password_hash($data['new'], PASSWORD_DEFAULT));
        $this->users->clearFailures($identifier);
        $this->audit->log($userId, 'user.password_change', 'user', $userId, null, $ip);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Gabungkan cart dan wishlist guest ke akun. Kegagalan satu bagian tidak membatalkan login dan tidak
     * mengubah data guest bagian itu (merge atomik di repository).
     *
     * @return string[] pesan untuk pengguna
     */
    public function mergeGuestData(int $userId): array
    {
        $notices = [];
        try {
            $r = (new CartService())->mergeGuestIntoUser($userId);
            if ($r['dropped'] > 0) {
                $notices[] = $r['dropped'] . ' item(s) from your previous bag are no longer available and were not added.';
            }
        } catch (\Throwable $e) {
            error_log('Cart merge failed for user ' . $userId . ': ' . $e->getMessage());
            $notices[] = 'We could not combine your previous bag with your account just now. It was left untouched.';
        }
        try {
            (new WishlistService())->mergeIntoUser($userId);
        } catch (\Throwable $e) {
            error_log('Wishlist merge failed for user ' . $userId . ': ' . $e->getMessage());
            $notices[] = 'We could not combine your previous wishlist with your account just now. It was left untouched.';
        }
        return $notices;
    }

    private function identifier(string $email): string
    {
        return mb_substr(AuthValidator::normalizeEmail($email), 0, 254);
    }

    private function isLocked(string $identifier, ?string $ip): bool
    {
        if ($this->users->failuresByIdentifier($identifier, self::WINDOW_MINUTES) >= self::MAX_FAILS_PER_IDENTIFIER) {
            return true;
        }
        return $ip !== null && $this->users->failuresByIp($ip, self::WINDOW_MINUTES) >= self::MAX_FAILS_PER_IP;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}
