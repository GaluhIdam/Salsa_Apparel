<?php
declare(strict_types=1);
namespace App\Core;

final class Str
{
    /** Slug URL: huruf kecil, angka, dan tanda hubung. */
    public static function slug(string $text, int $max = 180): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($t) && $t !== '') {
                $text = $t;
            }
        }
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return rtrim(substr($text, 0, $max), '-');
    }
}
