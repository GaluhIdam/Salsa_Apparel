<?php
declare(strict_types=1);
namespace App\Core;

/** Pembaca .env sederhana tanpa dependency. Variabel OS tetap diprioritaskan. */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\"'");
            if (getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }
}
