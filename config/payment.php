<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'provider' => strtolower(
        trim((string) Env::get('PAYMENT_PROVIDER', ''))
    ),

    'midtrans' => [
        'server_key' => trim(
            (string) Env::get('MIDTRANS_SERVER_KEY', '')
        ),

        'client_key' => trim(
            (string) Env::get('MIDTRANS_CLIENT_KEY', '')
        ),

        'is_production' => filter_var(
            Env::get('MIDTRANS_IS_PRODUCTION', 'false'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
];