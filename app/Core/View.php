<?php
declare(strict_types=1);
namespace App\Core;

final class View
{
    /** Render view di dalam layout. */
    public static function render(string $view, array $vars = [], string $layout = 'layouts/main'): void
    {
        ob_start();
        self::partial($view, $vars);
        $content = (string) ob_get_clean();
        self::partial($layout, $vars + ['content' => $content]);
    }

    /** Cetak satu view/komponen tanpa layout. */
    public static function partial(string $view, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        require BASE_PATH . '/resources/views/' . $view . '.php';
    }
}
