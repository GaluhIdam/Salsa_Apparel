<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

final class CheckoutRepository
{
    /**
     * Ambil cart aktif beserta data terbaru.
     * Cart akan dikunci selama proses pembuatan order.
     */
    public function lockCart(PDO $pdo, int $cartId): array
    {
        $st = $pdo->prepare(
            'SELECT id FROM carts WHERE id = :id FOR UPDATE'
        );
        $st->execute(['id' => $cartId]);

        if (!$st->fetchColumn()) {
            return [];
        }

        $st = $pdo->prepare(
            "SELECT
                ci.variant_id,
                ci.quantity,
                v.sku,
                v.size,
                v.color,
                v.stock,
                v.is_active AS variant_active,
                p.id AS product_id,
                p.name,
                p.price,
                p.sale_price,
                p.status AS product_status,
                c.is_active AS category_active
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            JOIN categories c ON c.id = p.category_id
            WHERE ci.cart_id = :cart_id
            ORDER BY ci.id
            FOR UPDATE OF ci, v"
        );
        $st->execute(['cart_id' => $cartId]);

        return $st->fetchAll();
    }

    public function insertOrder(
        PDO $pdo,
        array $data
    ): int {
        $st = $pdo->prepare(
            "INSERT INTO orders (
                order_number,
                user_id,
                access_token_hash,
                customer_email,
                recipient_name,
                recipient_phone,
                ship_address,
                ship_city,
                ship_province,
                ship_postal_code,
                shipping_courier,
                shipping_service,
                subtotal,
                discount_total,
                shipping_cost,
                grand_total,
                payment_status,
                fulfillment_status,
                payment_expires_at
            ) VALUES (
                :order_number,
                :user_id,
                :access_token_hash,
                :customer_email,
                :recipient_name,
                :recipient_phone,
                :ship_address,
                :ship_city,
                :ship_province,
                :ship_postal_code,
                :shipping_courier,
                :shipping_service,
                :subtotal,
                0,
                :shipping_cost,
                :grand_total,
                'pending',
                'unprocessed',
                now() + INTERVAL '24 hours'
            )
            RETURNING id"
        );

        $st->execute($data);

        return (int) $st->fetchColumn();
    }

    public function insertItem(
        PDO $pdo,
        int $orderId,
        array $item
    ): void {
        $st = $pdo->prepare(
            "INSERT INTO order_items (
                order_id,
                variant_id,
                product_name,
                sku,
                size,
                color,
                unit_price,
                quantity,
                line_total
            ) VALUES (
                :order_id,
                :variant_id,
                :product_name,
                :sku,
                :size,
                :color,
                :unit_price,
                :quantity,
                :line_total
            )"
        );

        $st->execute([
            'order_id' => $orderId,
            'variant_id' => $item['variant_id'],
            'product_name' => $item['product_name'],
            'sku' => $item['sku'],
            'size' => $item['size'],
            'color' => $item['color'],
            'unit_price' => $item['unit_price'],
            'quantity' => $item['quantity'],
            'line_total' => $item['line_total'],
        ]);
    }

    public function recordInitialStatus(
        PDO $pdo,
        int $orderId
    ): void {
        $st = $pdo->prepare(
            "INSERT INTO order_status_history (
                order_id,
                status_type,
                from_status,
                to_status,
                note
            ) VALUES (
                :order_id,
                'payment',
                NULL,
                'pending',
                'Order created'
            )"
        );

        $st->execute(['order_id' => $orderId]);
    }

    public function clearCart(PDO $pdo, int $cartId): void
    {
        $st = $pdo->prepare(
            'DELETE FROM cart_items WHERE cart_id = :cart_id'
        );
        $st->execute(['cart_id' => $cartId]);

        $st = $pdo->prepare(
            'UPDATE carts SET updated_at = now() WHERE id = :cart_id'
        );
        $st->execute(['cart_id' => $cartId]);
    }

    public function findCartIdByGuestHash(
        PDO $pdo,
        string $hash
    ): ?int {
        $st = $pdo->prepare(
            'SELECT id FROM carts
            WHERE guest_token_hash = :hash AND user_id IS NULL'
        );
        $st->execute(['hash' => $hash]);

        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function findCartIdByUser(
        PDO $pdo,
        int $userId
    ): ?int {
        $st = $pdo->prepare(
            'SELECT id FROM carts WHERE user_id = :user_id'
        );
        $st->execute(['user_id' => $userId]);

        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function reserveStock(
        PDO $pdo,
        int $variantId,
        int $quantity,
        int $orderId
    ): void {
        $st = $pdo->prepare(
            'UPDATE product_variants
            SET stock = stock - :quantity
            WHERE id = :variant_id
            AND stock >= :quantity
            AND is_active = TRUE'
        );

        $st->execute([
            'quantity' => $quantity,
            'variant_id' => $variantId,
        ]);

        if ($st->rowCount() !== 1) {
            throw new \DomainException(
                'Stok berubah atau tidak mencukupi. Silakan periksa keranjang.'
            );
        }

        $st = $pdo->prepare(
            "INSERT INTO inventory_movements (
                variant_id,
                change_qty,
                reason,
                reference_type,
                reference_id,
                note
            ) VALUES (
                :variant_id,
                :change_qty,
                'order_reserved',
                'order',
                :order_id,
                'Stock reserved during checkout'
            )"
        );

        $st->execute([
            'variant_id' => $variantId,
            'change_qty' => -$quantity,
            'order_id' => $orderId,
        ]);
    }
}