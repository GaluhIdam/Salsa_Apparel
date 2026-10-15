<?php
declare(strict_types=1);
use App\Core\Env;

return [
    'env'   => Env::get('APP_ENV', 'production'),
    'debug' => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url'   => rtrim((string) Env::get('APP_URL', ''), '/'),
    'session_name' => Env::get('SESSION_NAME', 'salsa_session'),
];
