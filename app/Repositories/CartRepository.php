<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

final class CartRepository
{
    public function findIdByTokenHash(string $hash): ?int
    {
        $st = Database::connection()->prepare('SELECT id FROM carts WHERE guest_token_hash = :h');
        $st->execute(['h' => $hash]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function create(string $tokenHash): int
    {
        $st = Database::connection()->prepare('INSERT INTO carts (guest_token_hash) VALUES (:h) RETURNING id');
        $st->execute(['h' => $tokenHash]);
        return (int) $st->fetchColumn();
    }

    /** Varian yang boleh dibeli: varian, produk, dan kategori semuanya aktif. */
    public function purchasableVariant(int $variantId): ?array
    {
        $st = Database::connection()->prepare(
            "SELECT v.id, v.stock, v.product_id, p.slug
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             JOIN categories c ON c.id = p.category_id
             WHERE v.id = :id AND v.is_active = TRUE AND p.status = 'active' AND c.is_active = TRUE"
        );
        $st->bindValue(':id', $variantId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function itemQuantity(int $cartId, int $variantId): int
    {
        $st = Database::connection()->prepare('SELECT quantity FROM cart_items WHERE cart_id = :c AND variant_id = :v');
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->bindValue(':v', $variantId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    /** Tambah ke baris yang sama (gabung kuantitas), dibatasi $cap. */
    public function addQuantity(int $cartId, int $variantId, int $qty, int $cap): void
    {
        $st = Database::connection()->prepare(
            'INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (:c, :v, :q)
             ON CONFLICT (cart_id, variant_id)
             DO UPDATE SET quantity = LEAST(cart_items.quantity + EXCLUDED.quantity, :cap)'
        );
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->bindValue(':v', $variantId, PDO::PARAM_INT);
        $st->bindValue(':q', $qty, PDO::PARAM_INT);
        $st->bindValue(':cap', $cap, PDO::PARAM_INT);
        $st->execute();
        $this->touch($cartId);
    }

    public function setQuantity(int $cartId, int $variantId, int $qty): bool
    {
        $st = Database::connection()->prepare('UPDATE cart_items SET quantity = :q WHERE cart_id = :c AND variant_id = :v');
        $st->bindValue(':q', $qty, PDO::PARAM_INT);
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->bindValue(':v', $variantId, PDO::PARAM_INT);
        $st->execute();
        $this->touch($cartId);
        return $st->rowCount() === 1;
    }

    public function remove(int $cartId, int $variantId): bool
    {
        $st = Database::connection()->prepare('DELETE FROM cart_items WHERE cart_id = :c AND variant_id = :v');
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->bindValue(':v', $variantId, PDO::PARAM_INT);
        $st->execute();
        $this->touch($cartId);
        return $st->rowCount() === 1;
    }

    /** Semua baris cart beserta data terbaru produk/varian (harga dan stok selalu dari database). */
    public function items(int $cartId): array
    {
        $st = Database::connection()->prepare(
            "SELECT ci.variant_id, ci.quantity, v.sku, v.size, v.color, v.stock,
                    v.is_active AS variant_active, v.product_id,
                    p.name, p.slug, p.price, p.sale_price, p.status AS product_status,
                    c.is_active AS category_active,
                    (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_path,
                    (SELECT i.alt_text FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_alt
             FROM cart_items ci
             JOIN product_variants v ON v.id = ci.variant_id
             JOIN products p ON p.id = v.product_id
             JOIN categories c ON c.id = p.category_id
             WHERE ci.cart_id = :c
             ORDER BY ci.id"
        );
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function findIdByUserId(int $userId): ?int
    {
        $st = Database::connection()->prepare('SELECT id FROM carts WHERE user_id = :u');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Cart milik user; dibuat jika belum ada (aman terhadap request bersamaan). */
    public function ensureUserCart(int $userId): int
    {
        $st = Database::connection()->prepare('INSERT INTO carts (user_id) VALUES (:u) ON CONFLICT (user_id) DO NOTHING');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return (int) $this->findIdByUserId($userId);
    }

    /** Total unit yang masih bisa dibeli (varian/produk/kategori aktif, dibatasi stok dan $maxPerLine). */
    public function purchasableUnitsByCartId(int $cartId, int $maxPerLine): int
    {
        $st = Database::connection()->prepare(
            "SELECT COALESCE(SUM(LEAST(ci.quantity, v.stock, :max)), 0)
             FROM cart_items ci
             JOIN product_variants v ON v.id = ci.variant_id AND v.is_active = TRUE
             JOIN products p ON p.id = v.product_id AND p.status = 'active'
             JOIN categories c ON c.id = p.category_id AND c.is_active = TRUE
             WHERE ci.cart_id = :c"
        );
        $st->bindValue(':max', $maxPerLine, PDO::PARAM_INT);
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    /**
     * Gabungkan cart guest ke cart user secara atomik: kuantitas dijumlahkan dan dibatasi min(stok, $maxQty),
     * item yang tidak lagi tersedia dilewati, lalu cart guest dihapus. Jika gagal, tidak ada yang berubah.
     *
     * @return array{merged:int,dropped:int}
     */
    public function mergeGuestCart(int $guestCartId, int $userId, int $maxQty): array
    {
        return Database::transaction(function (PDO $pdo) use ($guestCartId, $userId, $maxQty): array {
            $userCartId = $this->ensureUserCart($userId);

            $st = $pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE cart_id = :g');
            $st->bindValue(':g', $guestCartId, PDO::PARAM_INT);
            $st->execute();
            $total = (int) $st->fetchColumn();

            $st = $pdo->prepare(
                "SELECT ci.variant_id, ci.quantity, v.stock
                 FROM cart_items ci
                 JOIN product_variants v ON v.id = ci.variant_id AND v.is_active = TRUE
                 JOIN products p ON p.id = v.product_id AND p.status = 'active'
                 JOIN categories c ON c.id = p.category_id AND c.is_active = TRUE
                 WHERE ci.cart_id = :g
                 ORDER BY ci.id
                 FOR UPDATE OF ci"
            );
            $st->bindValue(':g', $guestCartId, PDO::PARAM_INT);
            $st->execute();

            $up = $pdo->prepare(
                'INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (:c, :v, :q)
                 ON CONFLICT (cart_id, variant_id)
                 DO UPDATE SET quantity = LEAST(cart_items.quantity + EXCLUDED.quantity, :cap)'
            );
            $merged = 0;
            foreach ($st->fetchAll() as $row) {
                $cap = min((int) $row['stock'], $maxQty);
                if ($cap <= 0) {
                    continue;
                }
                $up->bindValue(':c', $userCartId, PDO::PARAM_INT);
                $up->bindValue(':v', (int) $row['variant_id'], PDO::PARAM_INT);
                $up->bindValue(':q', min((int) $row['quantity'], $cap), PDO::PARAM_INT);
                $up->bindValue(':cap', $cap, PDO::PARAM_INT);
                $up->execute();
                $merged++;
            }

            $del = $pdo->prepare('DELETE FROM carts WHERE id = :g AND user_id IS NULL');
            $del->bindValue(':g', $guestCartId, PDO::PARAM_INT);
            $del->execute();
            $this->touch($userCartId);

            return ['merged' => $merged, 'dropped' => $total - $merged];
        });
    }

    private function touch(int $cartId): void
    {
        $st = Database::connection()->prepare('UPDATE carts SET updated_at = now() WHERE id = :c');
        $st->bindValue(':c', $cartId, PDO::PARAM_INT);
        $st->execute();
    }
}
