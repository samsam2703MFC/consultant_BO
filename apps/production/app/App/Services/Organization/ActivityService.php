<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Organization\ActivityRepository;

/**
 * Aktywność w ERP : sesje (przerwa > 30 min = nowa sesja), wyświetlenia stron z czasem aktywnym (karta widoczna
 * i ruch w ostatniej minucie — liczy przeglądarka) i kliknięciami, kliknięcia według elementu (etykieta przycisku
 * albo trasa linku — nigdy treść pól). Rentowność czasu : godziny aktywne × koszt godziny użytkownika, według
 * użytkownika, modułu (pozycja railu) i strony. Dane starsze niż 13 miesięcy są usuwane.
 */
final class ActivityService
{
    public const SESSION_GAP_S = 1800;
    private const RETENTION_DAYS = 400;
    private const MAX_TARGETS = 30;

    public function __construct(private ActivityRepository $repo, private PageAccessService $access)
    {
    }

    /** Nowe wyświetlenie strony (sesja bieżąca albo nowa). @return array{id:int, session:int} */
    public function view(int $userId, array $input, ?int $now = null): array
    {
        $now ??= time();
        $at = date('Y-m-d H:i:s', $now);
        $path = self::path((string) ($input['path'] ?? ''));
        if ($path === null) throw new CustomException('Nieprawidłowa ścieżka.', 'errors', 422);
        $session = $this->repo->currentSession($userId, date('Y-m-d H:i:s', $now - self::SESSION_GAP_S));
        if ($session === null) {
            if (random_int(1, 50) === 1) $this->repo->prune(date('Y-m-d H:i:s', $now - self::RETENTION_DAYS * 86400));
            $sessionId = $this->repo->startSession($userId, $at, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160) ?: null);
        } else {
            $sessionId = (int) $session['id'];
            $this->repo->touchSession($sessionId, $at);
        }
        $title = trim((string) ($input['title'] ?? ''));
        $id = $this->repo->addView(['id_session' => $sessionId, 'id_user' => $userId, 'route' => self::route($path), 'path' => mb_substr($path, 0, 255),
            'page_key' => PageAccess::keyFor($path), 'title' => $title !== '' ? mb_substr($title, 0, 160) : null, 'at' => $at]);
        return ['id' => $id, 'session' => $sessionId];
    }

    /** Puls strony : czas aktywny i kliknięcia od otwarcia (narastająco), elementy kliknięte od ostatniego pulsu. */
    public function beat(int $userId, array $input, ?int $now = null): void
    {
        $now ??= time();
        $view = $this->repo->view((int) ($input['id'] ?? 0));
        if ($view === null || (int) $view['id_user'] !== $userId) throw new CustomException('Nie znaleziono wyświetlenia.', 'errors', 404);
        // Czas aktywny nie dłuższy niż czas od otwarcia strony (+ minuta zapasu na zegar przeglądarki).
        $elapsed = max(0, $now - strtotime($view['started_at'])) + 60;
        $active = max(0, min((int) ($input['active_s'] ?? 0), $elapsed));
        $clicks = max(0, min((int) ($input['clicks'] ?? 0), 100000));
        $at = date('Y-m-d H:i:s', $now);
        $this->repo->updateView((int) $view['id'], $active, $clicks, $at);
        $this->repo->touchSession((int) $view['id_session'], $at);
        $targets = [];
        foreach (array_slice(is_array($input['targets'] ?? null) ? $input['targets'] : [], 0, self::MAX_TARGETS, true) as $label => $n) {
            $label = trim(preg_replace('~\s+~u', ' ', (string) $label));
            $n = (int) $n;
            if ($label === '' || $n <= 0) continue;
            $targets[mb_substr($label, 0, 120)] = min($n, 1000);
        }
        if ($targets) $this->repo->addClicks(date('Y-m-d', $now), $view['route'], $targets);
    }

    /** Raport okresu [from, to] (dni) : użytkownicy, moduły, strony, kliknięcia, sesje, dni. */
    public function report(string $fromDay, string $toDay, ?int $userId = null): array
    {
        [$fromDay, $toDay] = self::period($fromDay, $toDay);
        $from = $fromDay . ' 00:00:00';
        $to = date('Y-m-d', strtotime($toDay . ' +1 day')) . ' 00:00:00';
        $users = array_column($this->repo->users(), null, 'id');
        $cost = fn(int $uid, float $hours) => isset($users[$uid]['hourly_cost']) ? $hours * (float) $users[$uid]['hourly_cost'] : null;
        $views = $this->repo->views($from, $to, $userId);
        $byUser = $byModule = $byRoute = $byDay = [];
        $total = ['active_h' => 0.0, 'views' => 0, 'clicks' => 0, 'cost' => 0.0, 'uncosted_h' => 0.0];
        foreach ($views as $v) {
            $uid = (int) $v['id_user'];
            $h = (int) $v['active_s'] / 3600;
            $c = $cost($uid, $h);
            $key = $v['page_key'] ?? '';
            $u = &$byUser[$uid];
            $u ??= ['user_id' => $uid, 'name' => $users[$uid]['name'] ?? ('#' . $uid), 'position' => $users[$uid]['position'] ?? null,
                'hourly_cost' => isset($users[$uid]['hourly_cost']) ? (float) $users[$uid]['hourly_cost'] : null,
                'sessions' => [], 'days' => [], 'active_h' => 0.0, 'views' => 0, 'clicks' => 0, 'cost' => null, 'modules' => []];
            $u['sessions'][$v['id_session']] = true;
            $u['days'][substr($v['started_at'], 0, 10)] = true;
            $u['active_h'] += $h;
            $u['views']++;
            $u['clicks'] += (int) $v['clicks'];
            if ($c !== null) $u['cost'] = ($u['cost'] ?? 0) + $c;
            $u['modules'][$key] = ($u['modules'][$key] ?? 0) + $h;
            unset($u);
            $m = &$byModule[$key];
            $m ??= ['key' => $key, 'label' => $key !== '' ? PageAccess::PAGES[$key][0] ?? $key : 'Wspólne (Dzisiaj, Moje zadania, wyszukiwarka…)',
                'section' => $key !== '' ? PageAccess::PAGES[$key][1] ?? '' : '', 'users' => [], 'active_h' => 0.0, 'views' => 0, 'clicks' => 0, 'cost' => 0.0, 'uncosted_h' => 0.0];
            $m['users'][$uid] = true;
            $m['active_h'] += $h;
            $m['views']++;
            $m['clicks'] += (int) $v['clicks'];
            if ($c !== null) $m['cost'] += $c; else $m['uncosted_h'] += $h;
            unset($m);
            $r = &$byRoute[$v['route']];
            $r ??= ['route' => $v['route'], 'title' => $v['title'], 'module' => $key, 'active_h' => 0.0, 'views' => 0, 'clicks' => 0, 'users' => []];
            $r['active_h'] += $h;
            $r['views']++;
            $r['clicks'] += (int) $v['clicks'];
            $r['users'][$uid] = true;
            unset($r);
            $d = substr($v['started_at'], 0, 10);
            $byDay[$d] ??= ['date' => $d, 'active_h' => 0.0, 'views' => 0, 'users' => []];
            $byDay[$d]['active_h'] += $h;
            $byDay[$d]['views']++;
            $byDay[$d]['users'][$uid] = true;
            $total['active_h'] += $h;
            $total['views']++;
            $total['clicks'] += (int) $v['clicks'];
            if ($c !== null) $total['cost'] += $c; else $total['uncosted_h'] += $h;
        }
        $round = fn(float $x, int $p = 2) => round($x, $p);
        $users2 = array_values(array_map(function ($u) use ($round) {
            arsort($u['modules']);
            $top = array_slice($u['modules'], 0, 3, true);
            $u['top_modules'] = array_map(fn($k, $h) => ['label' => $k !== '' ? PageAccess::PAGES[$k][0] ?? $k : 'Wspólne', 'active_h' => $round($h)], array_keys($top), $top);
            $u['sessions'] = count($u['sessions']);
            $u['days'] = count($u['days']);
            $u['active_h'] = $round($u['active_h']);
            $u['cost'] = $u['cost'] !== null ? $round($u['cost']) : null;
            $u['clicks_per_h'] = $u['active_h'] > 0 ? (int) round($u['clicks'] / $u['active_h']) : null;
            unset($u['modules']);
            return $u;
        }, $byUser));
        usort($users2, fn($a, $b) => $b['active_h'] <=> $a['active_h']);
        $modules = array_values(array_map(fn($m) => ['users' => count($m['users']), 'active_h' => $round($m['active_h']), 'cost' => $round($m['cost']),
            'uncosted_h' => $round($m['uncosted_h']), 'share' => $total['active_h'] > 0 ? $round($m['active_h'] / $total['active_h'] * 100, 1) : 0.0] + $m, $byModule));
        usort($modules, fn($a, $b) => $b['active_h'] <=> $a['active_h']);
        $routes = array_values(array_map(fn($r) => ['users' => count($r['users']), 'active_h' => $round($r['active_h']),
            'avg_min' => $r['views'] ? $round($r['active_h'] * 60 / $r['views'], 1) : 0.0] + $r, $byRoute));
        usort($routes, fn($a, $b) => $b['active_h'] <=> $a['active_h']);
        ksort($byDay);
        $sessions = [];
        foreach (array_slice($this->repo->sessions($from, $to, $userId), 0, 60) as $s) {
            $sv = array_values(array_filter($views, fn($v) => (int) $v['id_session'] === (int) $s['id']));
            $sessions[] = ['id' => (int) $s['id'], 'user_id' => (int) $s['id_user'], 'name' => $users[(int) $s['id_user']]['name'] ?? ('#' . $s['id_user']),
                'started_at' => $s['started_at'], 'last_seen_at' => $s['last_seen_at'],
                'duration_min' => (int) round((strtotime($s['last_seen_at']) - strtotime($s['started_at'])) / 60),
                'active_min' => (int) round(array_sum(array_column($sv, 'active_s')) / 60), 'views' => count($sv), 'clicks' => array_sum(array_column($sv, 'clicks')),
                'pages' => array_map(fn($v) => ['at' => substr($v['started_at'], 11, 5), 'route' => $v['route'], 'title' => $v['title'],
                    'active_s' => (int) $v['active_s'], 'clicks' => (int) $v['clicks']], $sv)];
        }
        return [
            'from' => $fromDay, 'to' => $toDay, 'user_id' => $userId,
            'total' => ['active_h' => $round($total['active_h']), 'views' => $total['views'], 'clicks' => $total['clicks'], 'cost' => $round($total['cost']),
                'uncosted_h' => $round($total['uncosted_h']), 'users' => count($byUser), 'sessions' => count(array_unique(array_column($views, 'id_session')))],
            'users' => $users2, 'modules' => $modules, 'routes' => array_slice($routes, 0, 40),
            'days' => array_values(array_map(fn($d) => ['date' => $d['date'], 'active_h' => $round($d['active_h']), 'views' => $d['views'], 'users' => count($d['users'])], $byDay)),
            'clicks' => array_map(fn($c) => ['route' => $c['route'], 'target' => $c['target'], 'clicks' => (int) $c['clicks']], $this->repo->clicks($fromDay, $toDay)),
            'sessions' => $sessions,
            'people' => array_values(array_map(fn($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'login' => $u['login'], 'position' => $u['position'],
                'hourly_cost' => $u['hourly_cost'] !== null ? (float) $u['hourly_cost'] : null], $users)),
        ];
    }

    /** Koszt godziny użytkownika (pusty = bez kosztu) — tylko administrator. */
    public function setCost(int $userId, array $input, ?int $actorId): ?float
    {
        if (!$this->access->isAdmin($actorId)) throw new CustomException('Koszt godziny ustawia administrator.', 'errors', 403);
        if (!$this->repo->userExists($userId)) throw new CustomException('Nie znaleziono użytkownika.', 'errors', 404);
        $raw = trim(str_replace([' ', ','], ['', '.'], (string) ($input['hourly_cost'] ?? '')));
        if ($raw === '') { $this->repo->setCost($userId, null, $actorId); return null; }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 10000) throw new CustomException('Koszt godziny : liczba od 0 do 10 000 zł.', 'errors', 422);
        $this->repo->setCost($userId, round((float) $raw, 2), $actorId);
        return round((float) $raw, 2);
    }

    /** Ścieżka strony ERP bez prefiksu aplikacji, zapytania i kotwicy ; null = nie ścieżka. */
    public static function path(string $raw): ?string
    {
        $p = parse_url($raw, PHP_URL_PATH);
        if (!is_string($p) || $p === '' || $p[0] !== '/') return null;
        $root = defined('ROOT') ? (string) parse_url((string) ROOT, PHP_URL_PATH) : '';
        if ($root !== '' && $root !== '/' && str_starts_with($p, $root)) $p = substr($p, strlen(rtrim($root, '/')));
        $p = '/' . trim($p, '/');
        return strlen($p) <= 255 ? $p : substr($p, 0, 255);
    }

    /** Trasa : segmenty z cyframi (identyfikatory, daty) jako {id}. */
    public static function route(string $path): string
    {
        $segments = array_map(fn($s) => preg_match('~\d~', $s) ? '{id}' : $s, explode('/', $path));
        return mb_substr(implode('/', $segments) ?: '/', 0, 190);
    }

    /** @return array{0:string,1:string} okres uporządkowany, najwyżej 366 dni */
    public static function period(string $from, string $to): array
    {
        $f = strtotime($from) ?: strtotime('-29 days');
        $t = strtotime($to) ?: time();
        if ($f > $t) [$f, $t] = [$t, $f];
        if ($t - $f > 365 * 86400) $f = $t - 365 * 86400;
        return [date('Y-m-d', $f), date('Y-m-d', $t)];
    }
}
