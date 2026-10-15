<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

final class WishlistRepository
{
    public function findIdByTokenHash(string $hash): ?int
    {
        $st = Database::connection()->prepare('SELECT id FROM wishlists WHERE guest_token_hash = :h');
        $st->execute(['h' => $hash]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function create(string $tokenHash): int
    {
        $st = Database::connection()->prepare('INSERT INTO wishlists (guest_token_hash) VALUES (:h) RETURNING id');
        $st->execute(['h' => $tokenHash]);
        return (int) $st->fetchColumn();
    }

    /** Produk boleh disimpan hanya jika produk dan kategorinya aktif. */
    public function isSavableProduct(int $productId): bool
    {
        $st = Database::connection()->prepare(
            "SELECT 1 FROM products p JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id AND p.status = 'active' AND c.is_active = TRUE"
        );
        $st->bindValue(':id', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function addItem(int $wishlistId, int $productId): bool
    {
        $st = Database::connection()->prepare(
            'INSERT INTO wishlist_items (wishlist_id, product_id) VALUES (:w, :p) ON CONFLICT DO NOTHING'
        );
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() === 1;
    }

    public function removeItem(int $wishlistId, int $productId): bool
    {
        $st = Database::connection()->prepare('DELETE FROM wishlist_items WHERE wishlist_id = :w AND product_id = :p');
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() === 1;
    }

    public function hasItem(int $wishlistId, int $productId): bool
    {
        $st = Database::connection()->prepare('SELECT 1 FROM wishlist_items WHERE wishlist_id = :w AND product_id = :p');
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function itemCount(int $wishlistId): int
    {
        $st = Database::connection()->prepare('SELECT COUNT(*) FROM wishlist_items WHERE wishlist_id = :w');
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function findIdByUserId(int $userId): ?int
    {
        $st = Database::connection()->prepare('SELECT id FROM wishlists WHERE user_id = :u');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Wishlist milik user; dibuat jika belum ada (aman terhadap request bersamaan). */
    public function ensureUserWishlist(int $userId): int
    {
        $st = Database::connection()->prepare('INSERT INTO wishlists (user_id) VALUES (:u) ON CONFLICT (user_id) DO NOTHING');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return (int) $this->findIdByUserId($userId);
    }

    /** ID produk aktif yang tersimpan (untuk status ikon dan jumlah di header). */
    public function activeProductIdsByWishlistId(int $wishlistId): array
    {
        $st = Database::connection()->prepare(
            "SELECT wi.product_id
             FROM wishlist_items wi
             JOIN products p ON p.id = wi.product_id AND p.status = 'active'
             JOIN categories c ON c.id = p.category_id AND c.is_active = TRUE
             WHERE wi.wishlist_id = :w"
        );
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->execute();
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Semua item (termasuk yang produknya sudah tidak aktif) dengan data terbaru dari database. */
    public function items(int $wishlistId): array
    {
        $st = Database::connection()->prepare(
            "SELECT p.id, p.name, p.slug, p.short_description, p.price, p.sale_price,
                    p.is_new_arrival, p.is_best_seller, p.status AS product_status,
                    c.name AS category_name, c.slug AS category_slug, c.is_active AS category_active,
                    (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_path,
                    (SELECT i.alt_text FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_alt,
                    (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = TRUE) AS total_stock,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = TRUE) AS variant_count,
                    (SELECT v.id FROM product_variants v WHERE v.product_id = p.id AND v.is_active = TRUE ORDER BY v.id LIMIT 1) AS first_variant_id
             FROM wishlist_items wi
             JOIN products p ON p.id = wi.product_id
             JOIN categories c ON c.id = p.category_id
             WHERE wi.wishlist_id = :w
             ORDER BY wi.added_at DESC, p.id DESC"
        );
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Varian aktif dari produk aktif (kosong jika produk/kategori tidak aktif). */
    public function activeVariants(int $productId): array
    {
        $st = Database::connection()->prepare(
            "SELECT v.id, v.stock, p.slug
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             JOIN categories c ON c.id = p.category_id
             WHERE p.id = :p AND p.status = 'active' AND c.is_active = TRUE AND v.is_active = TRUE
             ORDER BY v.id"
        );
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function touch(int $wishlistId): void
    {
        $st = Database::connection()->prepare('UPDATE wishlists SET updated_at = now() WHERE id = :w');
        $st->bindValue(':w', $wishlistId, PDO::PARAM_INT);
        $st->execute();
    }

    /**
     * Gabungkan wishlist guest ke wishlist user secara atomik: hanya produk aktif, tanpa duplikat,
     * dibatasi $maxItems per wishlist; wishlist guest dihapus. Jika gagal, tidak ada yang berubah.
     *
     * @return int jumlah produk baru yang ditambahkan ke wishlist user
     */
    public function mergeGuestIntoUser(int $guestWishlistId, int $userId, int $maxItems = 100): int
    {
        return (int) Database::transaction(function (PDO $pdo) use ($guestWishlistId, $userId, $maxItems): int {
            $userWishlist = $this->ensureUserWishlist($userId);
            $room = max(0, $maxItems - $this->itemCount($userWishlist));

            $st = $pdo->prepare(
                "INSERT INTO wishlist_items (wishlist_id, product_id, added_at)
                 SELECT CAST(:uw AS bigint), wi.product_id, wi.added_at
                 FROM wishlist_items wi
                 JOIN products p ON p.id = wi.product_id AND p.status = 'active'
                 JOIN categories c ON c.id = p.category_id AND c.is_active = TRUE
                 WHERE wi.wishlist_id = :gw
                 ORDER BY wi.added_at DESC
                 LIMIT :room
                 ON CONFLICT DO NOTHING"
            );
            $st->bindValue(':uw', $userWishlist, PDO::PARAM_INT);
            $st->bindValue(':gw', $guestWishlistId, PDO::PARAM_INT);
            $st->bindValue(':room', $room, PDO::PARAM_INT);
            $st->execute();
            $merged = $st->rowCount();

            $st = $pdo->prepare('DELETE FROM wishlists WHERE id = :gw AND user_id IS NULL');
            $st->bindValue(':gw', $guestWishlistId, PDO::PARAM_INT);
            $st->execute();
            $this->touch($userWishlist);

            return $merged;
        });
    }

    /** Hapus wishlist GUEST yang tidak aktif lebih dari $days hari. Wishlist milik user tidak disentuh. */
    public function purgeExpiredGuests(int $days, bool $dryRun = false): int
    {
        $where = 'user_id IS NULL AND updated_at < now() - CAST(:d AS integer) * INTERVAL \'1 day\'';
        $sql = $dryRun ? "SELECT COUNT(*) FROM wishlists WHERE $where" : "DELETE FROM wishlists WHERE $where";
        $st = Database::connection()->prepare($sql);
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();
        return $dryRun ? (int) $st->fetchColumn() : $st->rowCount();
    }
}
