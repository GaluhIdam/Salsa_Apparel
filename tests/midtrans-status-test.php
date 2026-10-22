<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$reflection = new ReflectionClass(
    App\Services\MidtransWebhookReconciliationService::class
);

$service = $reflection->newInstanceWithoutConstructor();

$mapStatus = $reflection->getMethod('mapStatus');
$isTerminal = $reflection->getMethod('isTerminal');
$shouldUpdate = $reflection->getMethod('shouldUpdatePaymentStatus');

$tests = [
    'settlement menjadi paid' =>
        $mapStatus->invoke($service, 'settlement', '') === 'paid',

    'capture fraud accept menjadi paid' =>
        $mapStatus->invoke($service, 'capture', 'accept') === 'paid',

    'capture fraud challenge tetap pending' =>
        $mapStatus->invoke($service, 'capture', 'challenge') === 'pending',

    'pending tetap pending' =>
        $mapStatus->invoke($service, 'pending', '') === 'pending',

    'deny menjadi failed' =>
        $mapStatus->invoke($service, 'deny', '') === 'failed',

    'expire menjadi expired' =>
        $mapStatus->invoke($service, 'expire', '') === 'expired',

    'paid adalah status terminal' =>
        $isTerminal->invoke($service, 'paid') === true,

    'failed adalah status terminal' =>
        $isTerminal->invoke($service, 'failed') === true,

    'expired adalah status terminal' =>
        $isTerminal->invoke($service, 'expired') === true,

    'refunded adalah status terminal' =>
        $isTerminal->invoke($service, 'refunded') === true,

    'pending bukan status terminal' =>
        $isTerminal->invoke($service, 'pending') === false,

        'pending menjadi paid diperbolehkan' =>
        $shouldUpdate->invoke($service, 'pending', 'paid') === true,

    'pending menjadi failed diperbolehkan' =>
        $shouldUpdate->invoke($service, 'pending', 'failed') === true,

    'paid tidak boleh kembali pending' =>
        $shouldUpdate->invoke($service, 'paid', 'pending') === false,

    'paid tidak boleh menjadi failed' =>
        $shouldUpdate->invoke($service, 'paid', 'failed') === false,

    'paid menjadi refunded diperbolehkan' =>
        $shouldUpdate->invoke($service, 'paid', 'refunded') === true,

    'failed tidak otomatis menjadi paid' =>
        $shouldUpdate->invoke($service, 'failed', 'paid') === false,

    'expired tidak otomatis menjadi paid' =>
        $shouldUpdate->invoke($service, 'expired', 'paid') === false,

    'refunded tidak boleh menjadi paid' =>
        $shouldUpdate->invoke($service, 'refunded', 'paid') === false,
];

$failed = 0;

foreach ($tests as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;

    if (!$passed) {
        $failed++;
    }
}

echo PHP_EOL . sprintf(
    'Hasil: %d/%d tes lulus.',
    count($tests) - $failed,
    count($tests)
) . PHP_EOL;

exit($failed === 0 ? 0 : 1);