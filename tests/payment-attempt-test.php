<?php
declare(strict_types=1);

// Isolated service regression tests: no network calls or real database writes.
namespace Midtrans {
    final class Snap
    {
        public static array $requests = [];
        public static bool $fail = false;
        public static function createTransaction(array $params): object
        {
            self::$requests[] = ['params' => $params, 'production' => Config::$isProduction];
            if (self::$fail) {
                throw new \RuntimeException('Simulated timeout');
            }
            return (object) ['token' => 'test-token', 'redirect_url' => 'https://example.test/pay'];
        }
    }
}

namespace App\Repositories {
    final class PaymentRepository
    {
        public array $order;
        public ?array $payment = null;
        public function lockPayableOrder($pdo, $id, $hash): ?array { return $this->order; }
        public function findOrderItems($pdo, $id): array
        {
            return [['sku' => 'TEST-M', 'unit_price' => '149000.00', 'quantity' => 1,
                'line_total' => '149000.00', 'product_name' => 'Test Shirt']];
        }
        public function findPendingAttemptForOrder($pdo, $id): ?array { return $this->payment; }
        public function createAttempt($pdo, array $data): int
        {
            $this->payment = $data + ['id' => 1, 'status' => 'pending',
                'snap_request_state' => 'not_started', 'snap_token' => null, 'redirect_url' => null];
            return 1;
        }
        public function findById($pdo, $id, $lock = false): ?array { return $this->payment; }
        public function claimSnapRequest($pdo, $id): bool
        {
            $this->payment['snap_request_state'] = 'in_progress';
            return true;
        }
        public function saveSnapResult($pdo, $id, $token, $url): void
        {
            $this->payment['snap_request_state'] = 'completed';
            $this->payment['snap_token'] = $token;
            $this->payment['redirect_url'] = $url;
        }
        public function markSnapRequestUnknown($pdo, $id): void
        {
            $this->payment['snap_request_state'] = 'unknown';
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    final class PaymentTestPdo extends PDO
    {
        private bool $active = false;
        public function __construct() {}
        public function beginTransaction(): bool { $this->active = true; return true; }
        public function inTransaction(): bool { return $this->active; }
        public function commit(): bool { $this->active = false; return true; }
        public function rollBack(): bool { $this->active = false; return true; }
    }

    $pdoProperty = new ReflectionProperty(App\Config\Database::class, 'pdo');
    $pdoProperty->setValue(null, new PaymentTestPdo());
    putenv('PAYMENT_PROVIDER=midtrans');
    putenv('MIDTRANS_SERVER_KEY=test-server-key');
    putenv('MIDTRANS_CLIENT_KEY=test-client-key');
    putenv('APP_URL=https://shop.example.test');
    $results = [];
    $token = str_repeat('a', 64);

    foreach ([false, true] as $production) {
        putenv('MIDTRANS_IS_PRODUCTION=' . ($production ? 'true' : 'false'));
        $repo = new App\Repositories\PaymentRepository();
        $repo->order = [
            'order_number' => 'SAL-TEST', 'payment_status' => 'pending',
            'payment_expires_at' => date('c', time() + 7200),
            'subtotal' => 149000, 'discount_total' => 0, 'shipping_cost' => 0,
            'grand_total' => 149000, 'recipient_name' => 'Test Customer',
            'customer_email' => 'customer@example.test', 'recipient_phone' => '081234567890',
            'ship_address' => 'Test Street', 'ship_city' => 'Jakarta', 'ship_postal_code' => '12345',
        ];
        $service = new App\Services\PaymentAttemptService($repo, new App\Services\MidtransService());
        Midtrans\Config::$isProduction = !$production;
        Midtrans\Config::$serverKey = 'previous-server-key';
        $result = $service->startPayment(1, $token);
        $request = Midtrans\Snap::$requests[array_key_last(Midtrans\Snap::$requests)];
        $label = $production ? 'production' : 'sandbox';
        $results["$label: token tersimpan sebelum dikembalikan"] =
            $result['snap_token'] === 'test-token' && $repo->payment['snap_request_state'] === 'completed';
        $results["$label: SDK memakai lingkungan yang dipilih"] = $request['production'] === $production;
        $results["$label: konfigurasi SDK sebelumnya dipulihkan"] =
            Midtrans\Config::$isProduction === !$production && Midtrans\Config::$serverKey === 'previous-server-key';
        $expiry = $request['params']['expiry'];
        $results["$label: batas Snap sama dengan batas pesanan"] =
            strtotime($expiry['start_time']) + $expiry['duration'] * 3600 === strtotime($repo->order['payment_expires_at']);
        $count = count(Midtrans\Snap::$requests);
        $again = $service->startPayment(1, $token);
        $results["$label: klik ulang memakai token sama tanpa request baru"] =
            count(Midtrans\Snap::$requests) === $count && $again === $result;

        $repo->payment = null;
        Midtrans\Snap::$fail = true;
        try { $service->startPayment(1, $token); } catch (RuntimeException) {}
        $results["$label: timeout ditandai unknown"] = $repo->payment['snap_request_state'] === 'unknown';
        $results["$label: konfigurasi SDK dipulihkan saat timeout"] =
            Midtrans\Config::$isProduction === !$production && Midtrans\Config::$serverKey === 'previous-server-key';
        $count = count(Midtrans\Snap::$requests);
        try { $service->startPayment(1, $token); } catch (RuntimeException) {}
        $results["$label: timeout tidak mengirim transaksi ganda"] = count(Midtrans\Snap::$requests) === $count;
        Midtrans\Snap::$fail = false;
        $repo->payment = null;
        $repo->order['payment_expires_at'] = date('c', time() - 60);
        $rejected = false;
        try { $service->startPayment(1, $token); } catch (DomainException) { $rejected = true; }
        $results["$label: pesanan kedaluwarsa ditolak sebelum API"] =
            $rejected && count(Midtrans\Snap::$requests) === $count;
    }

    putenv('MIDTRANS_SERVER_KEY=');
    $rejected = false;
    try { new App\Services\PaymentAttemptService(); } catch (RuntimeException) { $rejected = true; }
    $results['konfigurasi gateway kosong ditolak sebelum memulai pembayaran'] = $rejected;

    foreach ($results as $name => $passed) {
        echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    }
    $failed = count(array_filter($results, static fn($passed) => !$passed));
    echo sprintf("Hasil: %d/%d tes lulus.\n", count($results) - $failed, count($results));
    exit($failed === 0 ? 0 : 1);
}
