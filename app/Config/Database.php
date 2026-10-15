<?php
declare(strict_types=1);
namespace App\Config;

use App\Core\Env;
use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;options=\'--client_encoding=UTF8\'',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '5432'),
                Env::get('DB_DATABASE', 'salsa_apparel')
            );
            self::$pdo = new PDO($dsn, Env::get('DB_USERNAME'), Env::get('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Jalankan $fn secara atomik. Jika sudah berada dalam transaksi (mis. pengujian), memakai SAVEPOINT
     * sehingga kegagalan hanya membatalkan bagian ini dan transaksi luar tetap utuh.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            $sp = 'sp_' . bin2hex(random_bytes(4));
            $pdo->exec("SAVEPOINT $sp");
            try {
                $result = $fn($pdo);
                $pdo->exec("RELEASE SAVEPOINT $sp");
                return $result;
            } catch (\Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
                throw $e;
            }
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
