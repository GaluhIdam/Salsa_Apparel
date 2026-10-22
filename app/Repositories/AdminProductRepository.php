<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

/** Operasi tulis/daftar admin untuk produk, varian, gambar, dan riwayat stok. Katalog publik tetap di ProductRepository. */
final class AdminProductRepository
{
    private function pdo(): PDO
    {
        return Database::connection();
    }

    /** @return array{items:array,total:int} */
    public function list(array $f, int $page, int $perPage): array
    {
        $sql = '';
        $binds = [];
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $f['q'], '\\%_') . '%';
            $sql .= ' AND (p.name ILIKE :q1 OR p.slug ILIKE :q2 OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.sku ILIKE :q3))';
            $binds[':q1'] = $binds[':q2'] = $binds[':q3'] = [$like, PDO::PARAM_STR];
        }
        if (in_array($f['status'] ?? '', ['draft', 'active', 'archived'], true)) {
            $sql .= ' AND p.status = :st';
            $binds[':st'] = [$f['status'], PDO::PARAM_STR];
        }
        if (($f['category_id'] ?? 0) > 0) {
            $sql .= ' AND p.category_id = :cat';
            $binds[':cat'] = [(int) $f['category_id'], PDO::PARAM_INT];
        }
        $from = ' FROM products p JOIN categories c ON c.id = p.category_id WHERE TRUE' . $sql;

        $st = $this->pdo()->prepare('SELECT COUNT(*)' . $from);
        foreach ($binds as $k => [$v, $t]) { $st->bindValue($k, $v, $t); }
        $st->execute();
        $total = (int) $st->fetchColumn();

        $st = $this->pdo()->prepare(
            'SELECT p.id, p.name, p.slug, p.price, p.sale_price, p.status, p.updated_at, c.name AS category_name,
                    (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_path,
                    (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = TRUE) AS total_stock,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count'
            . $from . ' ORDER BY p.updated_at DESC, p.id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($binds as $k => [$v, $t]) { $st->bindValue($k, $v, $t); }
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
        $st->execute();
        return ['items' => $st->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo()->prepare(
            'SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = :i'
        );
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function lockProduct(int $id): ?array
    {
        $st = $this->pdo()->prepare('SELECT id, name, slug, status FROM products WHERE id = :i FOR UPDATE');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function images(int $productId): array
    {
        $st = $this->pdo()->prepare('SELECT id, path, alt_text, sort_order FROM product_images WHERE product_id = :p ORDER BY sort_order, id');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function variants(int $productId): array
    {
        $st = $this->pdo()->prepare('SELECT id, sku, size, color, stock, is_active FROM product_variants WHERE product_id = :p ORDER BY id');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function categoriesForSelect(): array
    {
        return $this->pdo()->query('SELECT id, name, is_active FROM categories ORDER BY sort_order, id')->fetchAll();
    }

    public function categoryExists(int $id): bool
    {
        $st = $this->pdo()->prepare('SELECT 1 FROM categories WHERE id = :i');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $st = $this->pdo()->prepare('SELECT 1 FROM products WHERE slug = :s AND (CAST(:x AS bigint) IS NULL OR id <> CAST(:x2 AS bigint))');
        $st->bindValue(':s', $slug);
        $st->bindValue(':x', $excludeId, $excludeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':x2', $excludeId, $excludeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function insert(array $d): int
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO products (category_id, name, slug, short_description, description, price, sale_price, status, is_new_arrival, is_best_seller, is_featured)
             VALUES (:c, :n, :s, :sd, :d, :p, :sp, :st, CAST(:na AS boolean), CAST(:bs AS boolean), CAST(:f AS boolean)) RETURNING id'
        );
        $this->bindProduct($st, $d);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function update(int $id, array $d): void
    {
        $st = $this->pdo()->prepare(
            'UPDATE products SET category_id = :c, name = :n, slug = :s, short_description = :sd, description = :d, price = :p, sale_price = :sp,
                    status = :st, is_new_arrival = CAST(:na AS boolean), is_best_seller = CAST(:bs AS boolean), is_featured = CAST(:f AS boolean)
             WHERE id = :i'
        );
        $this->bindProduct($st, $d);
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->pdo()->prepare('UPDATE products SET status = :s WHERE id = :i');
        $st->bindValue(':s', $status);
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function activeVariantCount(int $productId): int
    {
        $st = $this->pdo()->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = :p AND is_active = TRUE');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function hasOrderHistory(int $productId): bool
    {
        $st = $this->pdo()->prepare(
            'SELECT 1 FROM order_items oi JOIN product_variants v ON v.id = oi.variant_id WHERE v.product_id = :p LIMIT 1'
        );
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    /** Riwayat stok selain pencatatan awal ('initial') dianggap riwayat yang harus dipertahankan. */
    public function hasStockHistory(int $productId): bool
    {
        $st = $this->pdo()->prepare(
            "SELECT 1 FROM inventory_movements m JOIN product_variants v ON v.id = m.variant_id
             WHERE v.product_id = :p AND m.reason <> 'initial' LIMIT 1"
        );
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function deleteInitialMovements(int $productId): void
    {
        $st = $this->pdo()->prepare(
            "DELETE FROM inventory_movements WHERE reason = 'initial' AND variant_id IN (SELECT id FROM product_variants WHERE product_id = :p)"
        );
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
    }

    public function delete(int $id): void
    {
        $st = $this->pdo()->prepare('DELETE FROM products WHERE id = :i');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    // ---- varian ----

    public function findVariant(int $id, int $productId, bool $lock = false): ?array
    {
        $st = $this->pdo()->prepare('SELECT id, product_id, sku, size, color, stock, is_active FROM product_variants WHERE id = :i AND product_id = :p' . ($lock ? ' FOR UPDATE' : ''));
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function skuExists(string $sku, ?int $excludeVariantId = null): bool
    {
        $st = $this->pdo()->prepare('SELECT 1 FROM product_variants WHERE sku = :s AND (CAST(:x AS bigint) IS NULL OR id <> CAST(:x2 AS bigint))');
        $st->bindValue(':s', $sku);
        $st->bindValue(':x', $excludeVariantId, $excludeVariantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':x2', $excludeVariantId, $excludeVariantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function sizeColorExists(int $productId, string $size, string $color, ?int $excludeVariantId = null): bool
    {
        $st = $this->pdo()->prepare(
            'SELECT 1 FROM product_variants WHERE product_id = :p AND size = :s AND color = :c AND (CAST(:x AS bigint) IS NULL OR id <> CAST(:x2 AS bigint))'
        );
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->bindValue(':s', $size);
        $st->bindValue(':c', $color);
        $st->bindValue(':x', $excludeVariantId, $excludeVariantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':x2', $excludeVariantId, $excludeVariantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function insertVariant(int $productId, array $d, string $sku): int
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO product_variants (product_id, sku, size, color, stock, is_active) VALUES (:p, :sku, :s, :c, :st, CAST(:a AS boolean)) RETURNING id'
        );
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->bindValue(':sku', $sku);
        $st->bindValue(':s', $d['size']);
        $st->bindValue(':c', $d['color']);
        $st->bindValue(':st', (int) $d['stock'], PDO::PARAM_INT);
        $st->bindValue(':a', $d['is_active'] ? '1' : '0');
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function updateVariant(int $id, array $d, string $sku): void
    {
        $st = $this->pdo()->prepare('UPDATE product_variants SET sku = :sku, size = :s, color = :c, is_active = CAST(:a AS boolean) WHERE id = :i');
        $st->bindValue(':sku', $sku);
        $st->bindValue(':s', $d['size']);
        $st->bindValue(':c', $d['color']);
        $st->bindValue(':a', $d['is_active'] ? '1' : '0');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function setVariantStock(int $id, int $stock): void
    {
        $st = $this->pdo()->prepare('UPDATE product_variants SET stock = :s WHERE id = :i');
        $st->bindValue(':s', $stock, PDO::PARAM_INT);
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function insertMovement(int $variantId, int $delta, string $reason, ?string $note, ?int $staffId): void
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO inventory_movements (variant_id, change_qty, reason, note, created_by) VALUES (:v, :d, :r, :n, :u)'
        );
        $st->bindValue(':v', $variantId, PDO::PARAM_INT);
        $st->bindValue(':d', $delta, PDO::PARAM_INT);
        $st->bindValue(':r', $reason);
        $st->bindValue(':n', $note === '' ? null : $note, $note === null || $note === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':u', $staffId, $staffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
    }

    public function movements(int $variantId, int $limit = 10): array
    {
        $st = $this->pdo()->prepare('SELECT change_qty, reason, note, created_at FROM inventory_movements WHERE variant_id = :v ORDER BY id DESC LIMIT :l');
        $st->bindValue(':v', $variantId, PDO::PARAM_INT);
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    // ---- gambar ----

    public function imageCount(int $productId): int
    {
        $st = $this->pdo()->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :p');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function nextImageSort(int $productId): int
    {
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = :p');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function insertImage(int $productId, string $path, ?string $alt, int $sort): int
    {
        $st = $this->pdo()->prepare('INSERT INTO product_images (product_id, path, alt_text, sort_order) VALUES (:p, :path, :a, :s) RETURNING id');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->bindValue(':path', $path);
        $st->bindValue(':a', $alt, $alt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':s', $sort, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function findImage(int $id, int $productId): ?array
    {
        $st = $this->pdo()->prepare('SELECT id, path, sort_order FROM product_images WHERE id = :i AND product_id = :p');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function deleteImage(int $id): void
    {
        $st = $this->pdo()->prepare('DELETE FROM product_images WHERE id = :i');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function imagePaths(int $productId): array
    {
        return array_column($this->images($productId), 'path');
    }

    /** Jadikan gambar utama (sort_order terkecil); gambar lain bergeser satu urutan. */
    public function makePrimary(int $id, int $productId): void
    {
        $st = $this->pdo()->prepare('UPDATE product_images SET sort_order = sort_order + 1 WHERE product_id = :p');
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
        $st = $this->pdo()->prepare('UPDATE product_images SET sort_order = 0 WHERE id = :i AND product_id = :p');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':p', $productId, PDO::PARAM_INT);
        $st->execute();
    }

    private function bindProduct(\PDOStatement $st, array $d): void
    {
        $st->bindValue(':c', (int) $d['category_id'], PDO::PARAM_INT);
        $st->bindValue(':n', $d['name']);
        $st->bindValue(':s', $d['slug']);
        $st->bindValue(':sd', $d['short_description'] === '' ? null : $d['short_description'], $d['short_description'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':d', $d['description'] === '' ? null : $d['description'], $d['description'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':p', $d['price']);
        $st->bindValue(':sp', $d['sale_price'], $d['sale_price'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':st', $d['status']);
        $st->bindValue(':na', $d['is_new_arrival'] ? '1' : '0');
        $st->bindValue(':bs', $d['is_best_seller'] ? '1' : '0');
        $st->bindValue(':f', $d['is_featured'] ? '1' : '0');
    }
}
