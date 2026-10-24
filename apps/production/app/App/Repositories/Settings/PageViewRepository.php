<?php
namespace App\Repositories\Settings;

use App\Core\Helpers\BaseRepository;

/** Licznik stron : wyświetlenia stron ERP na dzień, trasę i użytkownika (n_page_view). */
class PageViewRepository extends BaseRepository
{
    protected string $table = 'n_page_view';

    public function hit(string $route, string $path, int $userId): void
    {
        $this->db->prepare('INSERT INTO n_page_view (day, route, id_user, views, last_path, last_at) VALUES (CURDATE(), ?, ?, 1, ?, NOW())
                            ON DUPLICATE KEY UPDATE views = views + 1, last_path = VALUES(last_path), last_at = VALUES(last_at)')
            ->execute([mb_substr($route, 0, 190), $userId, mb_substr($path, 0, 255)]);
    }

    /** @return array<int, array> trasa => wyświetlenia, użytkownicy, dni z użyciem, ostatnia wizyta (od $from) */
    public function report(?string $from): array
    {
        $where = $from ? 'WHERE v.day >= ?' : '';
        $stmt = $this->db->prepare("SELECT v.route, SUM(v.views) AS views, COUNT(DISTINCT v.id_user) AS users, COUNT(DISTINCT v.day) AS days,
                                           MAX(v.last_at) AS last_at,
                                           SUBSTRING_INDEX(GROUP_CONCAT(v.last_path ORDER BY v.last_at DESC SEPARATOR '\n'), '\n', 1) AS last_path
                                    FROM n_page_view v {$where} GROUP BY v.route ORDER BY views DESC, v.route");
        $stmt->execute($from ? [$from] : []);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("SELECT v.route, COALESCE(NULLIF(u.displayed_name, ''), u.login, CONCAT('#', v.id_user)) AS name, SUM(v.views) AS views
                                    FROM n_page_view v LEFT JOIN user u ON u.id = v.id_user {$where}
                                    GROUP BY v.route, v.id_user, u.displayed_name, u.login ORDER BY views DESC");
        $stmt->execute($from ? [$from] : []);
        $who = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) $who[$r['route']][] = $r['name'] . ' (' . (int) $r['views'] . ')';

        foreach ($rows as &$r) {
            $r['views'] = (int) $r['views'];
            $r['users'] = (int) $r['users'];
            $r['days'] = (int) $r['days'];
            $r['who'] = $who[$r['route']] ?? [];
        }
        return $rows;
    }

    /** @return array{since: ?string, views: int, users: int} */
    public function totals(?string $from): array
    {
        $where = $from ? 'WHERE day >= ?' : '';
        $stmt = $this->db->prepare("SELECT MIN(day) AS since, COALESCE(SUM(views), 0) AS views, COUNT(DISTINCT id_user) AS users FROM n_page_view {$where}");
        $stmt->execute($from ? [$from] : []);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $first = $this->db->query('SELECT MIN(day) FROM n_page_view')->fetchColumn();
        return ['since' => $r['since'] ?? null, 'first' => $first ?: null, 'views' => (int) ($r['views'] ?? 0), 'users' => (int) ($r['users'] ?? 0)];
    }
}
