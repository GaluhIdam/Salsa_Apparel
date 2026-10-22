<?php
declare(strict_types=1);

namespace Midtrans {
    final class Transaction
    {
        public static object $status;
        public static function status(string $id): object { return self::$status; }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    // Execute the real verifier, reconciliation service and repository against an
    // in-memory PDO double. PostgreSQL integration still needs a running database.
    final class WebhookTestPdo extends PDO
    {
        public array $data;
        private ?array $snapshot = null;
        public function __construct()
        {
            $this->data = ['payment' => ['id' => 1, 'order_id' => 1, 'amount' => '149000.00', 'status' => 'pending'],
                'order' => ['id' => 1, 'grand_total' => '149000.00', 'payment_status' => 'pending'],
                'events' => [], 'stock' => 19, 'movements' => [], 'history' => []];
        }
        public function beginTransaction(): bool { $this->snapshot = $this->data; return true; }
        public function inTransaction(): bool { return $this->snapshot !== null; }
        public function commit(): bool { $this->snapshot = null; return true; }
        public function rollBack(): bool { $this->data = $this->snapshot; $this->snapshot = null; return true; }
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            preg_match_all('/(?<!:):[a-z_]+/i', $query, $matches);
            if (count($matches[0]) !== count(array_unique($matches[0]))) {
                throw new RuntimeException('Repeated PDO placeholder: ' . $query);
            }
            return new WebhookTestStatement($this, preg_replace('/\s+/', ' ', trim($query)));
        }
    }

    final class WebhookTestStatement extends PDOStatement
    {
        private array $rows = [];
        private int $changed = 0;
        public function __construct(private WebhookTestPdo $pdo, private string $sql) {}
        public function execute(?array $params = null): bool
        {
            $p = $params ?? [];
            $d =& $this->pdo->data;
            $s = $this->sql;
            $this->rows = [];
            $this->changed = 0;
            if (str_starts_with($s, 'INSERT INTO payment_webhook_events')) {
                if (!isset($d['events'][$p['event_key']])) {
                    $d['events'][$p['event_key']] = ['processed_at' => null];
                    $this->changed = 1;
                }
            } elseif (str_starts_with($s, 'SELECT * FROM payment_webhook_events')) {
                $this->rows = [$d['events'][$p['event_key']]];
            } elseif (str_starts_with($s, 'SELECT * FROM payments')) {
                $this->rows = [$d['payment']];
            } elseif (str_starts_with($s, 'SELECT * FROM orders')) {
                $this->rows = [$d['order']];
            } elseif (str_starts_with($s, 'UPDATE payments')) {
                if ($d['payment']['status'] === $p['current_status']) {
                    $d['payment']['status'] = $p['status'];
                    $this->changed = 1;
                }
            } elseif (str_starts_with($s, 'UPDATE orders')) {
                $d['order']['payment_status'] = $p['status'] ?? 'refunded';
                $this->changed = 1;
            } elseif (str_starts_with($s, 'INSERT INTO order_status_history')) {
                $d['history'][] = $p;
                $this->changed = 1;
            } elseif (str_starts_with($s, 'SELECT id FROM order_status_history')) {
                foreach ($d['history'] as $row) {
                    if ($row['note'] === $p['note']) { $this->rows = [['id' => 1]]; break; }
                }
            } elseif (str_starts_with($s, 'SELECT variant_id')) {
                $this->rows = [['variant_id' => 1, 'quantity' => 1]];
            } elseif (str_starts_with($s, 'UPDATE product_variants')) {
                $d['stock'] += $p['quantity'];
                $this->changed = 1;
            } elseif (str_starts_with($s, 'INSERT INTO inventory_movements')) {
                $d['movements'][] = $p;
            } elseif (str_starts_with($s, 'UPDATE payment_webhook_events')) {
                $d['events'][$p['event_key']]['processed_at'] = 'processed';
            } else {
                throw new RuntimeException('Unhandled SQL in test: ' . $s);
            }
            return true;
        }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        { return array_shift($this->rows) ?? false; }
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
        public function fetchColumn(int $column = 0): mixed
        { $row = $this->fetch(); return $row === false ? false : array_values($row)[$column]; }
        public function rowCount(): int { return $this->changed; }
    }

    putenv('PAYMENT_PROVIDER=midtrans');
    putenv('MIDTRANS_SERVER_KEY=test-server-key');
    putenv('MIDTRANS_CLIENT_KEY=test-client-key');
    putenv('MIDTRANS_IS_PRODUCTION=false');
    $property = new ReflectionProperty(App\Config\Database::class, 'pdo');
    $results = [];
    $service = new App\Services\MidtransWebhookReconciliationService();
    $notification = ['order_id' => 'SALPAY-TEST', 'status_code' => '200', 'gross_amount' => '149000.00'];
    $notification['signature_key'] = hash('sha512', 'SALPAY-TEST200149000.00test-server-key');

    foreach (['settlement', 'deny', 'cancel', 'expire'] as $status) {
        $pdo = new WebhookTestPdo();
        $property->setValue(null, $pdo);
        Midtrans\Transaction::$status = (object) ($notification + [
            'transaction_status' => $status, 'transaction_id' => 'test-transaction', 'fraud_status' => 'accept']);
        $service->process($notification);
        $expected = match ($status) { 'settlement' => 'paid', 'expire' => 'expired', default => 'failed' };
        $results["$status memperbarui payment dan order"] =
            $pdo->data['payment']['status'] === $expected && $pdo->data['order']['payment_status'] === $expected;
        $results["$status menangani stok dengan benar"] = $pdo->data['stock'] === ($status === 'settlement' ? 19 : 20);
        $before = $pdo->data;
        $service->process($notification);
        $results["$status: webhook duplikat tidak mengubah data"] = $before === $pdo->data;
        if ($status === 'expire') {
            Midtrans\Transaction::$status->transaction_status = 'settlement';
            $service->process($notification);
            $results['pembayaran terlambat dicatat untuk review tanpa mengaktifkan order'] =
                $pdo->data['order']['payment_status'] === 'expired' && count($pdo->data['history']) === 2;
        }
    }

    $pdo = new WebhookTestPdo();
    $property->setValue(null, $pdo);
    Midtrans\Transaction::$status = (object) ($notification + [
        'transaction_status' => 'capture', 'transaction_id' => 'test-transaction', 'fraud_status' => 'challenge']);
    $service->process($notification);
    $results['capture challenge tetap pending'] = $pdo->data['order']['payment_status'] === 'pending';
    Midtrans\Transaction::$status->fraud_status = 'accept';
    $service->process($notification);
    $results['capture accept setelah challenge tidak dianggap duplikat'] = $pdo->data['order']['payment_status'] === 'paid';
    $before = $pdo->data;
    $invalid = $notification;
    $invalid['signature_key'] = 'invalid';
    $rejected = false;
    try { $service->process($invalid); } catch (RuntimeException) { $rejected = true; }
    $results['signature palsu ditolak tanpa mengubah data'] = $rejected && $before === $pdo->data;
    Midtrans\Transaction::$status->gross_amount = '1.00';
    $rejected = false;
    try { $service->process($notification); } catch (RuntimeException) { $rejected = true; }
    $results['nominal berbeda ditolak tanpa mengubah data'] = $rejected && $before === $pdo->data;

    $pdo = new WebhookTestPdo();
    $pdo->data['order']['grand_total'] = '100.00';
    $property->setValue(null, $pdo);
    Midtrans\Transaction::$status->gross_amount = '149000.00';
    $before = $pdo->data;
    $rejected = false;
    try { $service->process($notification); } catch (RuntimeException) { $rejected = true; }
    $results['nominal order berbeda membatalkan transaksi termasuk catatan webhook'] =
        $rejected && $before === $pdo->data && !$pdo->inTransaction();

    foreach ($results as $name => $passed) {
        echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    }
    $failed = count(array_filter($results, static fn($passed) => !$passed));
    echo sprintf("Hasil: %d/%d tes lulus.\n", count($results) - $failed, count($results));
    exit($failed === 0 ? 0 : 1);
}
