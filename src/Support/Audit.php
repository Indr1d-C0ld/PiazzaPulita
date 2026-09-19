<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

/** Registro delle azioni rilevanti. Non deve mai far fallire l'azione che sta tracciando. */
final class Audit
{
    public static function log(string $action, ?int $actorUserId = null, ?string $targetType = null, ?int $targetId = null, array $meta = [], ?string $ip = null): void
    {
        try {
            Database::run(
                'INSERT INTO audit_log (actor_user_id, action, target_type, target_id, meta, ip)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $actorUserId,
                    $action,
                    $targetType,
                    $targetId,
                    $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                    $ip !== null ? @inet_pton($ip) ?: null : null,
                ]
            );
        } catch (\Throwable $e) {
            logger('audit fallito (' . $action . '): ' . $e->getMessage(), 'warning');
        }
    }
}
