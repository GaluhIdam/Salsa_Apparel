<?php
declare(strict_types=1);
namespace App\Core;

use App\Repositories\UserRepository;

/** Status login pelanggan di session. Hanya pelanggan aktif (role_id NULL) yang dianggap login. */
final class Auth
{
    public const IDLE_TIMEOUT = 7200; // 2 jam tanpa aktivitas

    private static ?array $user = null;

    /** ID user yang login, atau null. Menerapkan timeout idle dan memperbarui aktivitas terakhir. */
    public static function id(?int $now = null): ?int
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || !isset($auth['user_id'])) {
            return null;
        }
        $now ??= time();
        if ($now - (int) ($auth['last_seen'] ?? 0) > self::IDLE_TIMEOUT) {
            self::expire();
            return null;
        }
        $_SESSION['auth']['last_seen'] = $now;
        return (int) $auth['user_id'];
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    /** Data user terbaru dari database; session dibatalkan jika akun sudah nonaktif/bukan pelanggan. */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        if (self::$user !== null && (int) self::$user['id'] === $id) {
            return self::$user;
        }
        $user = (new UserRepository())->findCustomerById($id);
        if ($user === null) {
            self::expire();
            return null;
        }
        return self::$user = $user;
    }

    /** Mulai session login: ID session baru (anti fixation) dan token CSRF baru. */
    public static function login(int $userId, ?int $now = null): void
    {
        self::regenerate();
        unset($_SESSION['_csrf'], $_SESSION['_old'], $_SESSION['_errors']);
        $now ??= time();
        $_SESSION['auth'] = ['user_id' => $userId, 'login_at' => $now, 'last_seen' => $now];
        self::$user = null;
    }

    /** Dipakai setelah perubahan kredensial: ID session dan token CSRF diganti, login tetap berlaku. */
    public static function refresh(): void
    {
        self::regenerate();
        unset($_SESSION['_csrf']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        self::$user = null;
        self::regenerate();
    }

    private static function expire(): void
    {
        unset($_SESSION['auth'], $_SESSION['_csrf']);
        self::$user = null;
    }

    private static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
