<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\Session;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Helpers/functions.php';

Env::load(BASE_PATH . '/.env');

$debug = (bool) (config('app')['debug'] ?? false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

date_default_timezone_set('Asia/Jakarta');
Session::start((string) config('app')['session_name']);
