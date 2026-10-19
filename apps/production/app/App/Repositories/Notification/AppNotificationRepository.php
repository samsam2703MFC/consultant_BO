<?php

namespace App\Repositories\Notification;

use App\Core\Helpers\BaseRepository;

class AppNotificationRepository extends BaseRepository
{
    protected string $table = 'app_notification';
    protected $allowedColumns = ['type', 'severity', 'title', 'body', 'link_url', 'payload_json', 'dedup_key', 'acknowledged_at', 'acknowledged_via', 'expires_at'];

    public function insertOnce(array $data): bool
    {
        $sql = "INSERT IGNORE INTO app_notification
                (type, severity, title, body, link_url, payload_json, dedup_key, expires_at)
                VALUES (:type, :severity, :title, :body, :link_url, :payload_json, :dedup_key, :expires_at)";
        return (bool) $this->query($sql, $data);
    }

    public function getActive(int $limit = 3): array
    {
        $limit = max(1, min(3, $limit));
        return $this->query(
            "SELECT id, type, severity, title, body, link_url, payload_json, created_at
             FROM app_notification
             WHERE acknowledged_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY FIELD(severity, 'danger', 'warning', 'info'), created_at ASC
             LIMIT {$limit}"
        ) ?: [];
    }

    public function acknowledge(int $id, string $via): bool
    {
        return $this->update($id, [
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'acknowledged_via' => $via,
        ]);
    }

    public function acknowledgeAll(string $via): int|false
    {
        return $this->query(
            'UPDATE app_notification
             SET acknowledged_at = :acknowledged_at, acknowledged_via = :acknowledged_via
             WHERE acknowledged_at IS NULL',
            [
                'acknowledged_at' => date('Y-m-d H:i:s'),
                'acknowledged_via' => $via,
            ]
        );
    }
}
