<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

final class AdminCategoryRepository
{
    public function list(): array
    {
        return Database::connection()->query(
            'SELECT c.id, c.name, c.slug, c.description, c.image_path, c.sort_order, c.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
             FROM categories c ORDER BY c.sort_order, c.id'
        )->fetchAll();
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT c.id, c.name, c.slug, c.description, c.image_path, c.sort_order, c.is_active,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
             FROM categories c WHERE c.id = :i' . ($lock ? ' FOR UPDATE OF c' : '')
        );
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $st = Database::connection()->prepare('SELECT 1 FROM categories WHERE lower(name) = lower(:n) AND (CAST(:x AS bigint) IS NULL OR id <> CAST(:x2 AS bigint))');
        $st->bindValue(':n', $name);
        $st->bindValue(':x', $excludeId, $excludeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':x2', $excludeId, $excludeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $st = Database::connection()->prepare('SELECT 1 FROM categories WHERE slug = :s AND (CAST(:x AS bigint) IS NULL OR id <> CAST(:x2 AS bigint))');
        $st->bindValue(':s', $slug);
        $st->bindValue(':x', $excludeId, $excludeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':x2', $excludeId, $excludeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->execute();
        return $st->fetchColumn() !== false;
    }

    public function insert(array $d, ?string $imagePath): int
    {
        $st = Database::connection()->prepare(
            'INSERT INTO categories (name, slug, description, image_path, sort_order, is_active)
             VALUES (:n, :s, :d, :img, :o, CAST(:a AS boolean)) RETURNING id'
        );
        $this->bind($st, $d);
        $st->bindValue(':img', $imagePath, $imagePath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function update(int $id, array $d, ?string $imagePath): void
    {
        $st = Database::connection()->prepare(
            'UPDATE categories SET name = :n, slug = :s, description = :d, image_path = :img, sort_order = :o, is_active = CAST(:a AS boolean) WHERE id = :i'
        );
        $this->bind($st, $d);
        $st->bindValue(':img', $imagePath, $imagePath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function delete(int $id): void
    {
        $st = Database::connection()->prepare('DELETE FROM categories WHERE id = :i');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    private function bind(\PDOStatement $st, array $d): void
    {
        $st->bindValue(':n', $d['name']);
        $st->bindValue(':s', $d['slug']);
        $st->bindValue(':d', $d['description'] === '' ? null : $d['description'], $d['description'] === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':o', (int) $d['sort_order'], PDO::PARAM_INT);
        $st->bindValue(':a', $d['is_active'] ? '1' : '0');
    }
}