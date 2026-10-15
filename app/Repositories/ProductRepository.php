<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;
use PDOStatement;

final class ProductRepository
{
    private const COLUMNS = "SELECT p.id, p.name, p.slug, p.short_description, p.price, p.sale_price,
            p.is_new_arrival, p.is_best_seller, p.is_featured,
            c.name AS category_name, c.slug AS category_slug,
            (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_path,
            (SELECT i.alt_text FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_alt,
            (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = TRUE) AS total_stock";

    private const FROM_WHERE = " FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active' AND c.is_active = TRUE";

    private const SELECT = self::COLUMNS . self::FROM_WHERE;

    public function newArrivals(int $limit = 8): array
    {
        return $this->fetch(' AND p.is_new_arrival = TRUE', $limit);
    }

    public function featured(int $limit = 3): array
    {
        return $this->fetch(' AND p.is_featured = TRUE', $limit);
    }

    public function findActiveBySlug(string $slug): ?array
    {
        $st = Database::connection()->prepare("SELECT id, name, slug FROM products WHERE slug = :s AND status = 'active'");
        $st->execute(['s' => $slug]);
        return $st->fetch() ?: null;
    }

    /**
     * Daftar produk aktif dengan filter. Semua nilai dari pengguna di-bind (prepared statement);
     * fragmen SQL hanya berasal dari kode ini.
     *
     * @param array{q?:string,category?:?string,min_price?:?int,max_price?:?int,sort?:string,exclude_id?:int} $f
     * @return array{items:array,total:int,page:int,total_pages:int}
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $pdo = Database::connection();
        [$where, $binds] = $this->conditions($f);

        $st = $pdo->prepare('SELECT COUNT(*)' . self::FROM_WHERE . $where);
        $this->bindAll($st, $binds);
        $st->execute();
        $total = (int) $st->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $sort = (string) ($f['sort'] ?? 'newest');
        $q = (string) ($f['q'] ?? '');
        $order = match (true) {
            $sort === 'price_asc' => 'COALESCE(p.sale_price, p.price) ASC, p.id DESC',
            $sort === 'price_desc' => 'COALESCE(p.sale_price, p.price) DESC, p.id DESC',
            $sort === 'relevance' && $q !== '' => '(p.name ILIKE :rel) DESC, p.created_at DESC, p.id DESC',
            default => 'p.created_at DESC, p.id DESC',
        };

        $st = $pdo->prepare(self::SELECT . $where . ' ORDER BY ' . $order . ' LIMIT :lim OFFSET :off');
        $this->bindAll($st, $binds);
        if (str_contains($order, ':rel')) {
            $st->bindValue(':rel', '%' . $this->escapeLike($q) . '%', PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();

        return ['items' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'total_pages' => $totalPages];
    }

    /** Detail produk aktif (kategori aktif) beserta gambar dan varian aktif; null jika tidak tersedia. */
    public function findDetailBySlug(string $slug): ?array
    {
        $pdo = Database::connection();
        $st = $pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.short_description, p.description, p.price, p.sale_price,
                    p.is_new_arrival, p.is_best_seller,
                    c.name AS category_name, c.slug AS category_slug
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.slug = :s AND p.status = 'active' AND c.is_active = TRUE"
        );
        $st->execute(['s' => $slug]);
        $product = $st->fetch();
        if (!$product) {
            return null;
        }

        $st = $pdo->prepare('SELECT path, alt_text FROM product_images WHERE product_id = :id ORDER BY sort_order, id');
        $st->execute(['id' => $product['id']]);
        $product['images'] = $st->fetchAll();

        $st = $pdo->prepare('SELECT id, sku, size, color, stock FROM product_variants WHERE product_id = :id AND is_active = TRUE ORDER BY id');
        $st->execute(['id' => $product['id']]);
        $variants = $st->fetchAll();
        foreach ($variants as &$v) {
            $v['id'] = (int) $v['id'];
            $v['stock'] = (int) $v['stock'];
        }
        unset($v);
        $product['variants'] = $variants;

        return $product;
    }

    /** @return array{0:string,1:array<string,array{0:mixed,1:int}>} */
    private function conditions(array $f): array
    {
        $sql = '';
        $binds = [];

        if (!empty($f['category'])) {
            $sql .= ' AND c.slug = :cat';
            $binds[':cat'] = [(string) $f['category'], PDO::PARAM_STR];
        }
        if (!empty($f['exclude_id'])) {
            $sql .= ' AND p.id <> :ex';
            $binds[':ex'] = [(int) $f['exclude_id'], PDO::PARAM_INT];
        }
        if (isset($f['min_price'])) {
            $sql .= ' AND COALESCE(p.sale_price, p.price) >= :minp';
            $binds[':minp'] = [(int) $f['min_price'], PDO::PARAM_INT];
        }
        if (isset($f['max_price'])) {
            $sql .= ' AND COALESCE(p.sale_price, p.price) <= :maxp';
            $binds[':maxp'] = [(int) $f['max_price'], PDO::PARAM_INT];
        }

        // Setiap kata harus cocok di nama, deskripsi, kategori, atau warna/SKU varian.
        foreach ($this->terms((string) ($f['q'] ?? '')) as $i => $term) {
            $like = '%' . $this->escapeLike($term) . '%';
            $n = ':t' . $i . '_';
            $sql .= " AND (p.name ILIKE {$n}1 OR p.short_description ILIKE {$n}2 OR p.description ILIKE {$n}3"
                . " OR c.name ILIKE {$n}4 OR EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id"
                . " AND v.is_active = TRUE AND (v.color ILIKE {$n}5 OR v.sku ILIKE {$n}6)))";
            for ($k = 1; $k <= 6; $k++) {
                $binds[$n . $k] = [$like, PDO::PARAM_STR];
            }
        }
        return [$sql, $binds];
    }

    /** @return string[] */
    private function terms(string $q): array
    {
        $parts = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(static fn(string $t): string => mb_substr($t, 0, 50), array_slice($parts, 0, 5));
    }

    private function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    private function bindAll(PDOStatement $st, array $binds): void
    {
        foreach ($binds as $name => [$value, $type]) {
            $st->bindValue($name, $value, $type);
        }
    }

    private function fetch(string $where, int $limit): array
    {
        $st = Database::connection()->prepare(self::SELECT . $where . ' ORDER BY p.created_at DESC, p.id DESC LIMIT :n');
        $st->bindValue(':n', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
