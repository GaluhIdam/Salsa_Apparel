<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

/** Semua query dibatasi user_id pemilik (anti IDOR). */
final class AddressRepository
{
    private const COLUMNS = 'id, recipient_name, phone, address_line, city, province, postal_code, is_default';

    public function listByUser(int $userId): array
    {
        $st = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM addresses WHERE user_id = :u ORDER BY is_default DESC, id DESC');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $st = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM addresses WHERE id = :i AND user_id = :u');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function countByUser(int $userId): int
    {
        $st = Database::connection()->prepare('SELECT COUNT(*) FROM addresses WHERE user_id = :u');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function lockUser(int $userId): void
    {
        $st = Database::connection()->prepare('SELECT id FROM users WHERE id = :u FOR UPDATE');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    public function insert(int $userId, array $d, bool $default): int
    {
        $st = Database::connection()->prepare(
            'INSERT INTO addresses (user_id, recipient_name, phone, address_line, city, province, postal_code, is_default)
             VALUES (:u, :n, :p, :a, :c, :pr, :z, CAST(:d AS boolean)) RETURNING id'
        );
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $this->bindFields($st, $d);
        $st->bindValue(':d', $default ? '1' : '0');
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function update(int $id, int $userId, array $d, bool $makeDefault): bool
    {
        $st = Database::connection()->prepare(
            'UPDATE addresses SET recipient_name = :n, phone = :p, address_line = :a, city = :c, province = :pr, postal_code = :z,
                    is_default = CASE WHEN CAST(:d AS boolean) THEN TRUE ELSE is_default END
             WHERE id = :i AND user_id = :u'
        );
        $this->bindFields($st, $d);
        $st->bindValue(':d', $makeDefault ? '1' : '0');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() === 1;
    }

    public function delete(int $id, int $userId): bool
    {
        $st = Database::connection()->prepare('DELETE FROM addresses WHERE id = :i AND user_id = :u');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() === 1;
    }

    public function clearDefault(int $userId): void
    {
        $st = Database::connection()->prepare('UPDATE addresses SET is_default = FALSE WHERE user_id = :u AND is_default = TRUE');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    public function setDefault(int $id, int $userId): bool
    {
        $st = Database::connection()->prepare('UPDATE addresses SET is_default = TRUE WHERE id = :i AND user_id = :u');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() === 1;
    }

    /** Jadikan alamat terbaru sebagai default (dipakai setelah alamat default dihapus). */
    public function promoteNewest(int $userId): void
    {
        $st = Database::connection()->prepare(
            'UPDATE addresses SET is_default = TRUE
             WHERE id = (SELECT id FROM addresses WHERE user_id = :u ORDER BY id DESC LIMIT 1)'
        );
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    private function bindFields(\PDOStatement $st, array $d): void
    {
        $st->bindValue(':n', $d['recipient_name']);
        $st->bindValue(':p', $d['phone']);
        $st->bindValue(':a', $d['address_line']);
        $st->bindValue(':c', $d['city']);
        $st->bindValue(':pr', $d['province']);
        $st->bindValue(':z', $d['postal_code']);
    }
}
