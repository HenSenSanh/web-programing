<?php

namespace App\Services;

use PDO;

class AuditLogService
{
    public static function record(
        PDO $db,
        string $action,
        string $entityType,
        $entityId,
        ?array $oldValue,
        array $newValue,
        ?int $actorId = null
    ): bool {
        if ($actorId === null) {
            $sessionUser = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            $actorId = (is_int($sessionUser) && $sessionUser > 0) ? $sessionUser : null;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, old_value, new_value)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $actorId,
                substr(trim($action), 0, 64),
                substr(trim($entityType), 0, 64),
                substr((string)$entityId, 0, 64),
                $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            return true;
        } catch (\Throwable $e) {
            // Non-blocking fallback for minimal test schemas without audit_logs
            return false;
        }
    }

    public static function listRecent(PDO $db, int $limit = 50, ?string $entityType = null): array
    {
        $limit = max(1, min(200, $limit));
        $params = [];
        $where = '';
        if ($entityType !== null && trim($entityType) !== '') {
            $where = 'WHERE a.entity_type = ?';
            $params[] = trim($entityType);
        }

        $stmt = $db->prepare("
            SELECT a.id, a.actor_id, u.full_name AS actor_name, u.email AS actor_email,
                   a.action, a.entity_type, a.entity_id, a.old_value, a.new_value, a.created_at
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.actor_id
            {$where}
            ORDER BY a.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['actor_id'] = $row['actor_id'] !== null ? (int)$row['actor_id'] : null;
            if (is_string($row['old_value']) && $row['old_value'] !== '') {
                $decoded = json_decode($row['old_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['old_value'] = $decoded;
                }
            }
            if (is_string($row['new_value']) && $row['new_value'] !== '') {
                $decoded = json_decode($row['new_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['new_value'] = $decoded;
                }
            }
        }
        unset($row);

        return $rows;
    }
}
