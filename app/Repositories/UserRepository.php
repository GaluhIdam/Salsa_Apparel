<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;
use PDOException;

final class UserRepository
{
    /** Termasuk password_hash; hanya untuk proses autentikasi. */
    public function findByEmail(string $email): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT id, email, password_hash, full_name, phone, role_id, is_active FROM users WHERE email = :e'
        );
        $st->execute(['e' => $email]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT id, email, password_hash, full_name, phone, role_id, is_active FROM users WHERE id = :i'
        );
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    /** Pelanggan aktif tanpa password_hash. Akun ber-role (admin) tidak dikembalikan. */
    public function findCustomerById(int $id): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT id, email, full_name, phone, created_at FROM users WHERE id = :i AND is_active = TRUE AND role_id IS NULL'
        );
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
        return $st->fetch() ?: null;
    }

    /** @return ?int ID user baru, atau null jika email sudah terdaftar (unique violation). */
    public function create(string $name, string $email, ?string $phone, string $passwordHash): ?int
    {
        try {
            return (int) Database::transaction(static function (PDO $pdo) use ($name, $email, $phone, $passwordHash): int {
                $st = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, full_name, phone) VALUES (:e, :h, :n, :p) RETURNING id'
                );
                $st->bindValue(':e', $email);
                $st->bindValue(':h', $passwordHash);
                $st->bindValue(':n', $name);
                $st->bindValue(':p', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
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

    public function updateProfile(int $id, string $name, ?string $phone): void
    {
        $st = Database::connection()->prepare('UPDATE users SET full_name = :n, phone = :p WHERE id = :i');
        $st->bindValue(':n', $name);
        $st->bindValue(':p', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $st = Database::connection()->prepare('UPDATE users SET password_hash = :h WHERE id = :i');
        $st->bindValue(':h', $hash);
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    // --- rate limiting (tabel login_attempts; hanya kegagalan yang dicatat) ---

    public function recordFailure(string $identifier, ?string $ip): void
    {
        $st = Database::connection()->prepare(
            'INSERT INTO login_attempts (identifier, ip_address) VALUES (:i, CAST(:ip AS inet))'
        );
        $st->bindValue(':i', $identifier);
        $st->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->execute();
    }

    public function failuresByIdentifier(string $identifier, int $minutes): int
    {
        $st = Database::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :i AND attempted_at > now() - CAST(:m AS integer) * INTERVAL '1 minute'"
        );
        $st->bindValue(':i', $identifier);
        $st->bindValue(':m', $minutes, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function failuresByIp(string $ip, int $minutes): int
    {
        $st = Database::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = CAST(:ip AS inet) AND attempted_at > now() - CAST(:m AS integer) * INTERVAL '1 minute'"
        );
        $st->bindValue(':ip', $ip);
        $st->bindValue(':m', $minutes, PDO::PARAM_INT);
        $st->execute();
        return (int) $st->fetchColumn();
    }

    public function clearFailures(string $identifier): void
    {
        $st = Database::connection()->prepare('DELETE FROM login_attempts WHERE identifier = :i');
        $st->execute(['i' => $identifier]);
    }

    public function purgeOldAttempts(int $days): int
    {
        $st = Database::connection()->prepare(
            "DELETE FROM login_attempts WHERE attempted_at < now() - CAST(:d AS integer) * INTERVAL '1 day'"
        );
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount();
    }
}
