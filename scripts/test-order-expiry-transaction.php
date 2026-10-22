<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Env.php';
require __DIR__ . '/../app/Config/Database.php';
require __DIR__ . '/../app/Services/OrderExpiryService.php';

use App\Core\Env;
use App\Config\Database;
use App\Services\OrderExpiryService;

Env::load(__DIR__ . '/../.env');

$pdo = Database::connection();

if ($pdo->inTransaction()) {
    fwrite(STDERR, "GAGAL: koneksi sudah memiliki transaksi.\n");
    exit(1);
}

$variantId = 2; // Essential T-Shirt S
$quantity = 1;
$testOrderId = null;
$originalStock = null;

try {
    $pdo->beginTransaction();

    // Kunci varian dan simpan stok awal untuk verifikasi.
    $st = $pdo->prepare(
        'SELECT stock, sku, size, color
         FROM product_variants
         WHERE id = :id AND is_active = TRUE
         FOR UPDATE'
    );
    $st->execute(['id' => $variantId]);
    $variant = $st->fetch(PDO::FETCH_ASSOC);

    if (!$variant || (int) $variant['stock'] < $quantity) {
        throw new RuntimeException('Varian uji tidak tersedia atau stok kurang.');
    }

    $originalStock = (int) $variant['stock'];

    // Buat order uji yang langsung kedaluwarsa.
    $orderNumber = 'TESTEXP-' . bin2hex(random_bytes(8));
    $tokenHash = hash('sha256', random_bytes(32));

    $st = $pdo->prepare(
        "INSERT INTO orders (
            order_number, access_token_hash,
            customer_email, recipient_name, recipient_phone,
            ship_address, ship_city, ship_province, ship_postal_code,
            shipping_courier, shipping_service,
            subtotal, discount_total, shipping_cost, grand_total,
            payment_status, fulfillment_status, payment_expires_at
        ) VALUES (
            :order_number, :token_hash,
            'expiry-test@example.invalid', 'Expiry Test', '0000000000',
            'Test Address', 'Test City', 'Test Province', '00000',
            'TEST', 'TEST',
            10000, 0, 0, 10000,
            'pending', 'unprocessed', NOW() - INTERVAL '1 minute'
        )
        RETURNING id"
    );
    $st->execute([
        'order_number' => $orderNumber,
        'token_hash' => $tokenHash,
    ]);
    $testOrderId = (int) $st->fetchColumn();

    // Reservasi stok uji dan catat pergerakan inventory.
    $st = $pdo->prepare(
        'UPDATE product_variants
         SET stock = stock - :quantity
         WHERE id = :id AND stock >= :quantity'
    );
    $st->execute(['quantity' => $quantity, 'id' => $variantId]);

    if ($st->rowCount() !== 1) {
        throw new RuntimeException('Reservasi stok uji gagal.');
    }

    $st = $pdo->prepare(
        "INSERT INTO order_items (
            order_id, variant_id, product_name, sku, size, color,
            unit_price, quantity, line_total
        ) VALUES (
            :order_id, :variant_id, 'Expiry Test Product', :sku, :size, :color,
            10000, :quantity, 10000
        )"
    );
    $st->execute([
        'order_id' => $testOrderId,
        'variant_id' => $variantId,
        'sku' => $variant['sku'],
        'size' => $variant['size'],
        'color' => $variant['color'],
        'quantity' => $quantity,
    ]);

    $st = $pdo->prepare(
        "INSERT INTO inventory_movements (
            variant_id, change_qty, reason, reference_type, reference_id, note
        ) VALUES (
            :variant_id, :change_qty, 'order_reserved', 'order', :order_id, :note
        )"
    );
    $st->execute([
        'variant_id' => $variantId,
        'change_qty' => -$quantity,
        'order_id' => $testOrderId,
        'note' => 'Temporary expiry integration test',
    ]);

    $st = $pdo->prepare(
        "INSERT INTO order_status_history (
            order_id, status_type, from_status, to_status, note
        ) VALUES (
            :order_id, 'payment', NULL, 'pending', 'Temporary expiry integration test'
        )"
    );
    $st->execute(['order_id' => $testOrderId]);


    // Savepoint internal service menjaga transaksi luar tetap berjalan.
    $service = new OrderExpiryService();

    // Panggilan pertama: order harus kedaluwarsa dan stok dipulihkan.
    $processedFirst = $service->expireOrder($testOrderId);

    $st = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id');
    $st->execute(['id' => $variantId]);
    $stockAfterFirst = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT payment_status FROM orders WHERE id = :id');
    $st->execute(['id' => $testOrderId]);
    $status = $st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM inventory_movements
         WHERE reference_type = 'order'
           AND reference_id = :id
           AND reason = 'order_expired'"
    );
    $st->execute(['id' => $testOrderId]);
    $restorationsAfterFirst = (int) $st->fetchColumn();

    if (
        !$processedFirst ||
        $stockAfterFirst !== $originalStock ||
        $status !== 'expired' ||
        $restorationsAfterFirst !== 1
    ) {
        throw new RuntimeException(
            'Expiry pertama gagal: stock=' . $stockAfterFirst .
            ', status=' . $status .
            ', restorations=' . $restorationsAfterFirst
        );
    }

    echo "PASS: expiry pertama memulihkan stok tepat satu kali.\n";

    // Panggilan kedua: tidak boleh memulihkan stok lagi.
    $processedSecond = $service->expireOrder($testOrderId);

    $st = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id');
    $st->execute(['id' => $variantId]);
    $stockAfterSecond = (int) $st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM inventory_movements
        WHERE reference_type = 'order'
        AND reference_id = :id
        AND reason = 'order_expired'"
    );
    $st->execute(['id' => $testOrderId]);
    $restorationsAfterSecond = (int) $st->fetchColumn();

    if (
        $processedSecond !== false ||
        $stockAfterSecond !== $stockAfterFirst ||
        $restorationsAfterSecond !== $restorationsAfterFirst
    ) {
        throw new RuntimeException(
            'Idempotensi gagal: processed=' . var_export($processedSecond, true) .
            ', stock=' . $stockAfterSecond .
            ', restorations=' . $restorationsAfterSecond
        );
    }

    echo "PASS: expiry kedua ditolak karena order sudah expired.\n";
    echo "PASS: stok tidak bertambah pada pemanggilan kedua.\n";
    echo "PASS: inventory movement tetap satu kali.\n";
    echo "Order uji: {$testOrderId}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "TES GAGAL: {$e->getMessage()}\n");
    $pdo->rollBack();
    exit(1);
}

// Selalu batalkan seluruh data uji.
if ($pdo->inTransaction()) {
    $pdo->rollBack();
}

echo "ROLLBACK: seluruh perubahan tes dibatalkan.\n";