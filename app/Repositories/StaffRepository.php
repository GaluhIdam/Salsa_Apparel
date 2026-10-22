<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;
use PDOException;

final class StaffRepository
{
    /** Staf aktif (users.role_id terisi) beserta nama role. */
    public function findActiveStaff(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT u.id, u.email, u.full_name, u.role_id, r.name AS role_name, r.label AS role_label
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = :i AND u.is_active = TRUE'
        );
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    /** @return string[] kode permission milik role. */
    public function permissionCodes(int $roleId): array
    {
        $st = Database::connection()->prepare(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r'
        );
        $st->bindValue(':r', $roleId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public function roles(): array
    {
        return Database::connection()->query('SELECT id, name, label FROM roles ORDER BY id')->fetchAll();
    }

    public function findRoleByName(string $name): ?array
    {
        $st = Database::connection()->prepare('SELECT id, name, label FROM roles WHERE name = :n');
        $st->execute(['n' => $name]);
        return $st->fetch() ?: null;
    }

    /** @return ?int ID baru, atau null jika email sudah terdaftar. */
    public function createStaff(string $name, string $email, string $passwordHash, int $roleId): ?int
    {
        try {
            return (int) Database::transaction(static function (PDO $pdo) use ($name, $email, $passwordHash, $roleId): int {
                $st = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role_id) VALUES (:e, :h, :n, :r) RETURNING id');
                $st->bindValue(':e', $email);
                $st->bindValue(':h', $passwordHash);
                $st->bindValue(':n', $name);
                $st->bindValue(':r', $roleId, PDO::PARAM_INT);
                $st->execute();
                return (int) $st->fetchColumn();
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                return null;
            }
            throw $e;
        }
    }
}