<?php
declare(strict_types=1);
namespace App\Core;

final class Cookie
{
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Hapus cookie guest (HttpOnly, SameSite=Lax) dan buang dari $_COOKIE pada request ini. */
    public static function clear(string $name): void
    {
        unset($_COOKIE[$name]);
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
