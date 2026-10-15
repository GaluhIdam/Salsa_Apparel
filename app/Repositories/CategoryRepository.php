<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;

final class CategoryRepository
{
    /** Kategori aktif beserta jumlah produk aktif. */
    public function allWithCounts(): array
    {
        $sql = "SELECT c.id, c.name, c.slug, c.description, c.image_path,
                       COUNT(p.id) FILTER (WHERE p.status = 'active') AS product_count
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id
                WHERE c.is_active = TRUE
                GROUP BY c.id
                ORDER BY c.sort_order, c.id";
        return Database::connection()->query($sql)->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $st = Database::connection()->prepare('SELECT id, name, slug, description FROM categories WHERE slug = :s AND is_active = TRUE');
        $st->execute(['s' => $slug]);
        return $st->fetch() ?: null;
    }
}
