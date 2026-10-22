<?php
declare(strict_types=1);

// Dukungan `php -S`: biarkan server bawaan menyajikan file statis di public/.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PageController;
use App\Core\Router;

try {
    $router = new Router();
    $router->setNotFound([new PageController(), 'notFound']);

    (require BASE_PATH . '/routes/web.php')($router);
    (require BASE_PATH . '/routes/admin.php')($router);

    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} 

catch (Throwable $e) {
    @file_put_contents(
        BASE_PATH . '/storage/logs/app.log',
        sprintf("[%s] %s: %s in %s:%d\n", date('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()),
        FILE_APPEND
    );
    if (!headers_sent()) {
        http_response_code(500);
    }
    $debug = (bool) (config('app')['debug'] ?? false);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>';
    echo '<body style="font-family:system-ui;padding:3rem;max-width:40rem;margin:auto">';
    echo '<h1>Something went wrong</h1><p>Please try again in a moment.</p>';
    if ($debug) {
        echo '<pre style="white-space:pre-wrap;background:#f5f2ee;padding:1rem">' . e(get_class($e) . ': ' . $e->getMessage()) . '</pre>';
    }
    echo '</body>';
}
