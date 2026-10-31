<?php

namespace App\Repositories\Notification;

use App\Core\Helpers\BaseRepository;

class AppNotificationRepository extends BaseRepository
{
    protected string $table = 'app_notification';
    protected $allowedColumns = ['type', 'severity', 'title', 'body', 'link_url', 'payload_json', 'id_user', 'dedup_key', 'acknowledged_at', 'acknowledged_via', 'expires_at'];

    public function insertOnce(array $data): bool
    {
        $sql = "INSERT IGNORE INTO app_notification
                (type, severity, title, body, link_url, payload_json, dedup_key, expires_at)
                VALUES (:type, :severity, :title, :body, :link_url, :payload_json, :dedup_key, :expires_at)";
        return (bool) $this->query($sql, $data);
    }

    /** Dzwonek jednej osoby [232] (przypomnienie zadania) : id powiadomienia (nowego albo istniejącego o tym kluczu). */
    public function insertForUser(array $data, int $userId): ?int
    {
        $this->query(
            "INSERT IGNORE INTO app_notification (type, severity, title, body, link_url, payload_json, id_user, dedup_key, expires_at)
             VALUES (:type, :severity, :title, :body, :link_url, :payload_json, :id_user, :dedup_key, :expires_at)",
            $data + ['id_user' => $userId]
        );
        $row = $this->query('SELECT id FROM app_notification WHERE dedup_key = :k', ['k' => $data['dedup_key']]);
        return isset($row[0]['id']) ? (int) $row[0]['id'] : null;
    }

    /** Widoczne dla użytkownika : wspólne (id_user NULL) i jego własne. */
    private function audience(?int $userId): string
    {
        return $userId === null ? 'id_user IS NULL' : '(id_user IS NULL OR id_user = ' . (int) $userId . ')';
    }

    public function getActive(int $limit = 3, ?int $userId = null): array
    {
        $limit = max(1, min(20, $limit));
        return $this->query(
            "SELECT id, type, severity, title, body, link_url, payload_json, id_user, created_at
             FROM app_notification
             WHERE acknowledged_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
               AND {$this->audience($userId)}
             ORDER BY FIELD(severity, 'danger', 'warning', 'info'), created_at ASC
             LIMIT {$limit}"
        ) ?: [];
    }

    public function countActive(?int $userId = null): int
    {
        $rows = $this->query(
            "SELECT COUNT(*) AS total
             FROM app_notification
             WHERE acknowledged_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
               AND {$this->audience($userId)}"
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    public function visibleTo(int $id, ?int $userId): bool
    {
        $row = $this->query("SELECT id FROM app_notification WHERE id = :id AND {$this->audience($userId)}", ['id' => $id]);
        return !empty($row);
    }

    public function acknowledge(int $id, string $via): bool
    {
        return $this->update($id, [
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'acknowledged_via' => $via,
        ]);
    }

    public function acknowledgeAll(string $via, ?int $userId = null): int|false
    {
        return $this->query(
            "UPDATE app_notification
             SET acknowledged_at = :acknowledged_at, acknowledged_via = :acknowledged_via
             WHERE acknowledged_at IS NULL AND {$this->audience($userId)}",
            [
                'acknowledged_at' => date('Y-m-d H:i:s'),
                'acknowledged_via' => $via,
            ]
        );
    }
}
