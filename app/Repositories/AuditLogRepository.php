<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Config\Database;
use PDO;

final class AuditLogRepository
{
    /** $newValues hanya boleh berisi metadata non-sensitif (mis. nama field); jangan pernah password/token. */
    public function log(?int $actorId, string $action, string $entityType, ?int $entityId, ?array $newValues, ?string $ip): void
    {
        $st = Database::connection()->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, new_values, ip_address)
             VALUES (:a, :act, :t, :e, CAST(:nv AS jsonb), CAST(:ip AS inet))'
        );
        $st->bindValue(':a', $actorId, $actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':act', $action);
        $st->bindValue(':t', $entityType);
        $st->bindValue(':e', $entityId, $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $json = $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR);
        $st->bindValue(':nv', $json, $json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->execute();
    }
}
