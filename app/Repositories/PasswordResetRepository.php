<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

final class PasswordResetRepository
{
    public function create(int $userId, string $tokenHash, int $ttlMinutes): void
    {
        $st = Database::connection()->prepare(
            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:u, :h, now() + CAST(:m AS integer) * INTERVAL '1 minute')"
        );
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->bindValue(':h', $tokenHash);
        $st->bindValue(':m', $ttlMinutes, PDO::PARAM_INT);
        $st->execute();
    }

    /** Token yang belum dipakai, belum kedaluwarsa, milik pelanggan aktif. $lock mengunci baris (dalam transaksi). */
    public function findValid(string $tokenHash, bool $lock = false): ?array
    {
        $st = Database::connection()->prepare(
            'SELECT t.id, t.user_id, u.email
             FROM password_reset_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :h AND t.used_at IS NULL AND t.expires_at > now()
               AND u.is_active = TRUE AND u.role_id IS NULL'
            . ($lock ? ' FOR UPDATE OF t' : '')
        );
        $st->bindValue(':h', $tokenHash);
        $st->execute();
        return $st->fetch() ?: null;
    }

    public function markUsed(int $id): void
    {
        $st = Database::connection()->prepare('UPDATE password_reset_tokens SET used_at = now() WHERE id = :i');
        $st->bindValue(':i', $id, PDO::PARAM_INT);
        $st->execute();
    }

    /** Batalkan semua token yang masih terbuka milik user. */
    public function invalidateAllForUser(int $userId): void
    {
        $st = Database::connection()->prepare('UPDATE password_reset_tokens SET used_at = now() WHERE user_id = :u AND used_at IS NULL');
        $st->bindValue(':u', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    public function purgeOld(int $days): int
    {
        $st = Database::connection()->prepare(
            "DELETE FROM password_reset_tokens
             WHERE expires_at < now() - CAST(:d AS integer) * INTERVAL '1 day'
                OR used_at < now() - CAST(:d2 AS integer) * INTERVAL '1 day'"
        );
        $st->bindValue(':d', $days, PDO::PARAM_INT);
        $st->bindValue(':d2', $days, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount();
    }
}
