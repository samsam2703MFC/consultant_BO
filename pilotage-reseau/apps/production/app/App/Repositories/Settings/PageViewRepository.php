<?php
namespace App\Repositories\Settings;

use App\Core\Helpers\BaseRepository;

/**
 * Licznik stron : wyświetlenia stron ERP na dzień, trasę, użytkownika i profil [314] (n_page_view).
 * Profil : kod stanowiska, « ceo », « admin » (pełny widok administratora) albo « none ».
 */
class PageViewRepository extends BaseRepository
{
    protected string $table = 'n_page_view';
    private ?bool $hasProfile = null;

    public function hit(string $route, string $path, int $userId, string $profile = 'none'): void
    {
        if (!$this->hasProfile()) {
            $this->db->prepare('INSERT INTO n_page_view (day, route, id_user, views, last_path, last_at) VALUES (CURDATE(), ?, ?, 1, ?, NOW())
                                ON DUPLICATE KEY UPDATE views = views + 1, last_path = VALUES(last_path), last_at = VALUES(last_at)')
                ->execute([mb_substr($route, 0, 190), $userId, mb_substr($path, 0, 255)]);
            return;
        }
        $this->db->prepare('INSERT INTO n_page_view (day, route, id_user, profile, views, last_path, last_at) VALUES (CURDATE(), ?, ?, ?, 1, ?, NOW())
                            ON DUPLICATE KEY UPDATE views = views + 1, last_path = VALUES(last_path), last_at = VALUES(last_at)')
            ->execute([mb_substr($route, 0, 190), $userId, mb_substr($profile ?: 'none', 0, 40), mb_substr($path, 0, 255)]);
    }

    /**
     * Trasa => wyświetlenia, użytkownicy, dni z użyciem, ostatnia wizyta, kto, podział na profile (od $from, opcjonalnie jeden profil).
     * @return array<int, array>
     */
    public function report(?string $from, ?string $profile = null): array
    {
        [$where, $params] = $this->where($from, $profile, 'v.');
        $stmt = $this->db->prepare("SELECT v.route, SUM(v.views) AS views, COUNT(DISTINCT v.id_user) AS users, COUNT(DISTINCT v.day) AS days,
                                           MAX(v.last_at) AS last_at,
                                           SUBSTRING_INDEX(GROUP_CONCAT(v.last_path ORDER BY v.last_at DESC SEPARATOR '\n'), '\n', 1) AS last_path
                                    FROM n_page_view v {$where} GROUP BY v.route ORDER BY views DESC, v.route");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT v.route, COALESCE(NULLIF(u.displayed_name, ''), u.login, CONCAT('#', v.id_user)) AS name, SUM(v.views) AS views
                                    FROM n_page_view v LEFT JOIN user u ON u.id = v.id_user {$where}
                                    GROUP BY v.route, v.id_user, u.displayed_name, u.login ORDER BY views DESC");
        $stmt->execute($params);
        $who = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) $who[$r['route']][] = $r['name'] . ' (' . (int) $r['views'] . ')';

        $profiles = [];
        if ($this->hasProfile()) {
            $stmt = $this->db->prepare("SELECT v.route, v.profile, SUM(v.views) AS views FROM n_page_view v {$where} GROUP BY v.route, v.profile ORDER BY views DESC");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) $profiles[$r['route']][$r['profile']] = (int) $r['views'];
        }

        foreach ($rows as &$r) {
            $r['views'] = (int) $r['views'];
            $r['users'] = (int) $r['users'];
            $r['days'] = (int) $r['days'];
            $r['who'] = $who[$r['route']] ?? [];
            $r['profiles'] = $profiles[$r['route']] ?? [];
        }
        return $rows;
    }

    /**
     * [314] Profile : osoby, wyświetlenia, dni z użyciem, liczba stron i najczęściej otwierana strona (od $from).
     * @return list<array{profile:string, views:int, users:int, days:int, pages:int, top_route:?string, top_views:int, last_at:?string}>
     */
    public function profiles(?string $from): array
    {
        if (!$this->hasProfile()) return [];
        [$where, $params] = $this->where($from, null, '');
        $stmt = $this->db->prepare("SELECT profile, SUM(views) AS views, COUNT(DISTINCT id_user) AS users, COUNT(DISTINCT day) AS days,
                                           COUNT(DISTINCT route) AS pages, MAX(last_at) AS last_at
                                    FROM n_page_view {$where} GROUP BY profile ORDER BY views DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt = $this->db->prepare("SELECT profile, route, SUM(views) AS views FROM n_page_view {$where} GROUP BY profile, route ORDER BY views DESC");
        $stmt->execute($params);
        $top = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) $top[$r['profile']] ??= ['route' => $r['route'], 'views' => (int) $r['views']];
        return array_map(fn($r) => ['profile' => (string) $r['profile'], 'views' => (int) $r['views'], 'users' => (int) $r['users'], 'days' => (int) $r['days'],
            'pages' => (int) $r['pages'], 'top_route' => $top[$r['profile']]['route'] ?? null, 'top_views' => $top[$r['profile']]['views'] ?? 0, 'last_at' => $r['last_at']], $rows);
    }

    /** @return array{since: ?string, first: ?string, views: int, users: int} */
    public function totals(?string $from, ?string $profile = null): array
    {
        [$where, $params] = $this->where($from, $profile, '');
        $stmt = $this->db->prepare("SELECT MIN(day) AS since, COALESCE(SUM(views), 0) AS views, COUNT(DISTINCT id_user) AS users FROM n_page_view {$where}");
        $stmt->execute($params);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $first = $this->db->query('SELECT MIN(day) FROM n_page_view')->fetchColumn();
        return ['since' => $r['since'] ?? null, 'first' => $first ?: null, 'views' => (int) ($r['views'] ?? 0), 'users' => (int) ($r['users'] ?? 0)];
    }

    public function hasProfile(): bool
    {
        if ($this->hasProfile === null) {
            try {
                $this->hasProfile = (bool) $this->db->query("SHOW COLUMNS FROM n_page_view LIKE 'profile'")->fetch();
            } catch (\PDOException) {
                $this->hasProfile = false;
            }
        }
        return $this->hasProfile;
    }

    /** @return array{0:string, 1:array} */
    private function where(?string $from, ?string $profile, string $alias): array
    {
        $cond = [];
        $params = [];
        if ($from) { $cond[] = "{$alias}day >= ?"; $params[] = $from; }
        if ($profile !== null && $profile !== '' && $this->hasProfile()) { $cond[] = "{$alias}profile = ?"; $params[] = $profile; }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }
}
