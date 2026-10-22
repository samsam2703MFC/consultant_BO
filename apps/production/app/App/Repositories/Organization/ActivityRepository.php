<?php
declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/** Aktywność w ERP : sesje, wyświetlenia stron (czas aktywny, kliknięcia), kliknięcia według elementu, koszt godziny. */
final class ActivityRepository extends WorkshopRepository
{
    protected string $table = 'tfb_activity_view';

    public function currentSession(int $userId, string $since): ?array
    {
        return $this->all('SELECT * FROM tfb_activity_session WHERE id_user = ? AND last_seen_at >= ? ORDER BY last_seen_at DESC LIMIT 1', [$userId, $since])[0] ?? null;
    }

    public function startSession(int $userId, string $at, ?string $agent): int
    {
        $this->db->prepare('INSERT INTO tfb_activity_session (id_user, started_at, last_seen_at, user_agent) VALUES (?, ?, ?, ?)')->execute([$userId, $at, $at, $agent]);
        return (int) $this->db->lastInsertId();
    }

    public function touchSession(int $sessionId, string $at): void
    {
        $this->db->prepare('UPDATE tfb_activity_session SET last_seen_at = GREATEST(last_seen_at, ?) WHERE id = ?')->execute([$at, $sessionId]);
    }

    public function addView(array $v): int
    {
        $this->db->prepare('INSERT INTO tfb_activity_view (id_session, id_user, route, path, page_key, title, started_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$v['id_session'], $v['id_user'], $v['route'], $v['path'], $v['page_key'], $v['title'], $v['at'], $v['at']]);
        return (int) $this->db->lastInsertId();
    }

    public function view(int $id): ?array
    {
        return $this->all('SELECT * FROM tfb_activity_view WHERE id = ?', [$id])[0] ?? null;
    }

    public function updateView(int $id, int $activeS, int $clicks, string $at): void
    {
        $this->db->prepare('UPDATE tfb_activity_view SET active_s = GREATEST(active_s, ?), clicks = GREATEST(clicks, ?), last_seen_at = GREATEST(last_seen_at, ?) WHERE id = ?')
            ->execute([$activeS, $clicks, $at, $id]);
    }

    public function addClicks(string $day, string $route, array $targets): void
    {
        $st = $this->db->prepare('INSERT INTO tfb_activity_click (day, route, target, clicks) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks)');
        foreach ($targets as $target => $n) $st->execute([$day, $route, $target, $n]);
    }

    /** Dane starsze niż $before usuwane (przechowywanie ograniczone). */
    public function prune(string $before): void
    {
        $this->db->prepare('DELETE FROM tfb_activity_session WHERE last_seen_at < ?')->execute([$before]);
        $this->db->prepare('DELETE FROM tfb_activity_click WHERE day < ?')->execute([substr($before, 0, 10)]);
    }

    // --- Raport ------------------------------------------------------------------------------------

    /** Wyświetlenia w okresie (opcjonalnie jednego użytkownika). */
    public function views(string $from, string $to, ?int $userId = null): array
    {
        $sql = 'SELECT v.id, v.id_session, v.id_user, v.route, v.path, v.page_key, v.title, v.started_at, v.last_seen_at, v.active_s, v.clicks
                FROM tfb_activity_view v WHERE v.started_at >= ? AND v.started_at < ?';
        $params = [$from, $to];
        if ($userId !== null) { $sql .= ' AND v.id_user = ?'; $params[] = $userId; }
        return $this->all($sql . ' ORDER BY v.started_at, v.id', $params);
    }

    public function sessions(string $from, string $to, ?int $userId = null): array
    {
        $sql = 'SELECT s.id, s.id_user, s.started_at, s.last_seen_at, s.user_agent FROM tfb_activity_session s WHERE s.started_at >= ? AND s.started_at < ?';
        $params = [$from, $to];
        if ($userId !== null) { $sql .= ' AND s.id_user = ?'; $params[] = $userId; }
        return $this->all($sql . ' ORDER BY s.started_at DESC, s.id DESC', $params);
    }

    public function clicks(string $fromDay, string $toDay, int $limit = 40): array
    {
        return $this->all('SELECT route, target, SUM(clicks) AS clicks FROM tfb_activity_click WHERE day BETWEEN ? AND ? GROUP BY route, target ORDER BY clicks DESC LIMIT ' . max(1, $limit), [$fromDay, $toDay]);
    }

    /** Użytkownicy ERP z kosztem godziny. */
    public function users(): array
    {
        return $this->all("SELECT u.id, COALESCE(NULLIF(TRIM(u.displayed_name), ''), TRIM(CONCAT(u.name, ' ', u.surname)), u.login) AS name, u.login,
                                  c.hourly_cost, p.name_pl AS position
                           FROM user u LEFT JOIN tfb_user_cost c ON c.id_user = u.id
                           LEFT JOIN tfb_user_position up ON up.id_user = u.id LEFT JOIN tfb_position p ON p.id = up.id_position
                           ORDER BY name");
    }

    public function userExists(int $id): bool
    {
        return (bool) $this->all('SELECT 1 FROM user WHERE id = ?', [$id]);
    }

    public function setCost(int $userId, ?float $cost, ?int $by): void
    {
        if ($cost === null) {
            $this->db->prepare('DELETE FROM tfb_user_cost WHERE id_user = ?')->execute([$userId]);
            return;
        }
        $this->db->prepare('INSERT INTO tfb_user_cost (id_user, hourly_cost, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hourly_cost = VALUES(hourly_cost), updated_by = VALUES(updated_by)')
            ->execute([$userId, $cost, $by]);
    }
}
