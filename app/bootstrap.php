<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Helpers/functions.php';

use App\Core\Env;
use App\Core\Session;

Env::load(BASE_PATH . '/.env');

$debug = (bool) (config('app')['debug'] ?? false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

date_default_timezone_set('Asia/Jakarta');

Session::start((string) config('app')['session_name']);