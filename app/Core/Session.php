<?php
declare(strict_types=1);
namespace App\Core;

final class Session
{
    public static function start(string $name): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // Sesi server-side harus hidup minimal sepanjang timeout idle login (2 jam); default PHP hanya ~24 menit.
        ini_set('session.gc_maxlifetime', '7200');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
