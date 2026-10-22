<?php
declare(strict_types=1);
namespace App\Core;

use App\Repositories\StaffRepository;

/**
 * Status login staf/admin di session (kunci terpisah dari login pelanggan, sehingga tidak memengaruhi
 * pelanggan). Verifikasi kredensial tetap lewat AuthService (audience "admin"). Izin diperiksa di backend.
 */
final class AdminAuth
{
    public const IDLE_TIMEOUT = 3600; // 1 jam tanpa aktivitas

    private static ?array $user = null;
    private static ?array $permissions = null;

    public static function id(?int $now = null): ?int
    {
        $auth = $_SESSION['admin_auth'] ?? null;
        if (!is_array($auth) || !isset($auth['user_id'])) {
            return null;
        }
        $now ??= time();
        if ($now - (int) ($auth['last_seen'] ?? 0) > self::IDLE_TIMEOUT) {
            self::expire();
            return null;
        }
        $_SESSION['admin_auth']['last_seen'] = $now;
        return (int) $auth['user_id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Staf aktif ber-role (dibaca ulang dari database); session dibatalkan jika akun tidak lagi valid. */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        if (self::$user !== null && (int) self::$user['id'] === $id) {
            return self::$user;
        }
        $user = (new StaffRepository())->findActiveStaff($id);
        if ($user === null) {
            self::expire();
            return null;
        }
        self::$permissions = null;
        return self::$user = $user;
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        self::$permissions ??= (new StaffRepository())->permissionCodes((int) $user['role_id']);
        return in_array($permission, self::$permissions, true);
    }

    public static function login(int $userId, ?int $now = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf'], $_SESSION['_old'], $_SESSION['_errors']);
        $now ??= time();
        $_SESSION['admin_auth'] = ['user_id' => $userId, 'login_at' => $now, 'last_seen' => $now];
        self::$user = null;
        self::$permissions = null;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_auth']);
        self::$user = null;
        self::$permissions = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    private static function expire(): void
    {
        unset($_SESSION['admin_auth'], $_SESSION['_csrf']);
        self::$user = null;
        self::$permissions = null;
    }
}
