<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Web\PageAccess;
use App\Core\Web\Rail;
use App\Core\Web\ViewSession;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PageAccessRepository;

/**
 * Uprawnienia stanowisk : które strony ERP widzi każde stanowisko (administrator ustawia na stronie « Uprawnienia
 * stanowisk »). Administrator (rola prod_admin) widzi wszystko, także strony tylko dla niego (moduł kierownika
 * produkcji, uprawnienia). Użytkownik widzi strony swojego stanowiska ; bez stanowiska — strony « bez stanowiska ».
 * Strony spoza katalogu (Dzisiaj, Moje zadania…) są dla wszystkich.
 */
final class PageAccessService
{
    /** @var array<int, array{admin:bool, keys:array<string,bool>}> */
    private array $cache = [];
    /** @var array<int, list<string>> [255] prawa stanowiska sesji administratora (jedno zapytanie na żądanie) */
    private array $viewGrants = [];
    /** @var array<int, array<string, ?string>>|null menu stanowisk (n_position_menu) */
    private ?array $menus = null;
    /** @var array<int, array{id:int, code:string, name_pl:string}>|null stanowiska ERP do wyboru jako sesja */
    private ?array $sessionPositions = null;

    /** [239] Sesje pod awatarem : nazwa obszaru i ikona wg kodu stanowiska (inne stanowiska — nazwa stanowiska). */
    private const SESSION_AREAS = [
        'KIEROWNIK_PRODUKCJI' => ['Produkcja', 'bi-gear'],
        'ADMINISTRACJA' => ['Administracja i finanse', 'bi-receipt'],
        'LOGISTYKA' => ['Logistyka', 'bi-truck'],
        'JAKOSC' => ['Jakość', 'bi-patch-check'],
        'ZAKUPY' => ['Zakupy', 'bi-cart3'],
    ];

    public function __construct(private PageAccessRepository $repo)
    {
    }

    public function isAdmin(?int $userId): bool
    {
        if (!$userId) return false;
        return $this->profile($userId)['admin'];
    }

    public function canSee(?int $userId, string $path): bool
    {
        $key = PageAccess::keyFor($path);
        if ($key === null) return true;
        if (!$userId) return false;
        $p = $this->profile($userId);
        if ($p['admin']) return true;
        return !PageAccess::adminOnly($key) && isset($p['keys'][$key]);
    }

    /**
     * Prefiksy stron katalogu z widocznością dla użytkownika (rail i wyszukiwarka ukrywają linki według najdłuższego
     * pasującego prefiksu, jak serwer). Pusta lista = administrator, wszystko widoczne. @return array<string, bool>
     */
    public function railPrefixes(?int $userId): array
    {
        $keys = $userId ? $this->profile($userId)['keys'] : [];
        if ($userId && $this->profile($userId)['admin']) {
            // [239] Sesja administratora : rail jak u stanowiska.
            $view = $this->viewPosition($userId);
            if ($view === null) return [];
            $keys = array_fill_keys($this->repo->positionGrants($view), true);
        }
        $out = [];
        foreach (PageAccess::PAGES as $key => [, , $prefixes]) {
            $seen = $userId && !PageAccess::adminOnly($key) && isset($keys[$key]);
            foreach ($prefixes as $p) $out[$p] = $seen;
        }
        return $out;
    }

    /**
     * Widoczność w menu (rail, linki na stronach) : jak canSee, a w sesji administratora — jak u wybranego stanowiska.
     * Dostęp do stron się nie zmienia (bramka w App używa canSee).
     */
    public function menuSees(?int $userId, string $path): bool
    {
        $view = $this->viewPosition($userId);
        if ($view === null) return $this->canSee($userId, $path);
        $key = PageAccess::keyFor($path);
        if ($key === null) return true;
        $this->viewGrants[$view] ??= $this->repo->positionGrants($view);
        return !PageAccess::adminOnly($key) && in_array($key, $this->viewGrants[$view], true);
    }

    /**
     * [253] Menu (rail) użytkownika : pozycja => strona otwierana (null = strona pozycji) albo null = całe menu.
     * Administrator : całe menu ; w sesji « jako stanowisko » — menu tego stanowiska. Inni : menu stanowiska ;
     * stanowisko bez własnego menu — całe menu (rail i tak ukrywa strony, których użytkownik nie widzi).
     * @return array<string, ?string>|null
     */
    public function railMenu(?int $userId): ?array
    {
        if (!$userId) return null;
        if ($this->profile($userId)['admin']) {
            $view = $this->viewPosition($userId);
            return $view === null ? null : $this->positionMenu($view);
        }
        return $this->positionMenu($this->repo->userPosition($userId));
    }

    /** Menu stanowiska w kolejności railu (null = brak własnego menu). @return array<string, ?string>|null */
    public function positionMenu(int $positionId): ?array
    {
        $this->menus ??= $this->repo->menus();
        $rows = $this->menus[$positionId] ?? [];
        if ($rows === []) return null;
        $out = [];
        foreach (array_keys(Rail::ITEMS) as $key) if (array_key_exists($key, $rows)) $out[$key] = $rows[$key];
        return $out === [] ? null : $out;
    }

    /** [239] Stanowisko sesji administratora (null = pełny widok albo nie administrator). */
    public function viewPosition(?int $userId): ?int
    {
        $id = ViewSession::position();
        if ($id === null || !$userId || !$this->profile($userId)['admin']) return null;
        return isset($this->sessionPositions()[$id]) ? $id : null;
    }

    /**
     * Menu pod awatarem : administrator — « Pełny widok » i sesje stanowisk ERP (obszar, stanowisko, strona startowa,
     * liczba stron) ; inni — tylko nazwa stanowiska pod imieniem.
     * @return array{can_switch:bool, label:string, current:?int, sessions:list<array>}
     */
    public function sessionMenu(?int $userId): array
    {
        if (!$userId) return ['can_switch' => false, 'label' => '', 'current' => null, 'sessions' => []];
        if (!$this->profile($userId)['admin']) {
            $pos = $this->repo->userPosition($userId);
            $name = $pos ? (array_column($this->repo->positions(), 'name_pl', 'id')[$pos] ?? '') : '';
            return ['can_switch' => false, 'label' => $name !== '' ? $name : 'bez stanowiska', 'current' => null, 'sessions' => []];
        }
        $view = $this->viewPosition($userId);
        $homes = $this->repo->homes();
        $sessions = [['id' => null, 'name' => 'Pełny widok', 'sub' => 'administrator — wszystkie strony', 'icon' => 'bi-grid-3x3-gap', 'current' => $view === null]];
        $label = 'Administrator';
        foreach ($this->sessionPositions() as $p) {
            [$area, $icon] = self::SESSION_AREAS[$p['code']] ?? [$p['name_pl'], 'bi-briefcase'];
            $pages = count(array_filter($this->repo->positionGrants((int) $p['id']), fn($k) => isset(PageAccess::PAGES[$k]) && !PageAccess::adminOnly($k)));
            $home = PageAccess::HOME_PAGES[$homes[(int) $p['id']] ?? '/today'] ?? 'Dzisiaj';
            $sessions[] = ['id' => (int) $p['id'], 'name' => $area, 'sub' => $p['name_pl'] . ' · ' . $pages . ' ' . self::pagesWord($pages) . ' · start : ' . $home,
                'icon' => $icon, 'current' => $view === (int) $p['id']];
            if ($view === (int) $p['id']) $label = 'Sesja : ' . $area;
        }
        return ['can_switch' => true, 'label' => $label, 'current' => $view, 'sessions' => $sessions];
    }

    /** Zmiana sesji (tylko administrator) ; zwraca stronę startową nowej sesji. */
    public function switchSession(?int $userId, mixed $positionId): string
    {
        if (!$userId || !$this->profile($userId)['admin']) throw new CustomException('Sesje stanowisk : tylko administrator.', 'errors', 403);
        $id = $positionId === null || $positionId === '' ? null : (is_numeric($positionId) ? (int) $positionId : -1);
        if ($id !== null && !isset($this->sessionPositions()[$id])) throw new CustomException('Nieznana sesja.', 'errors', 422);
        ViewSession::set($id);
        return $this->homePath($userId);
    }

    /** @return array<int, array{id:int, code:string, name_pl:string}> */
    private function sessionPositions(): array
    {
        if ($this->sessionPositions === null) {
            $this->sessionPositions = [];
            foreach ($this->repo->positions() as $p) {
                if ((int) $p['active'] === 1 && ($p['kind'] ?? 'erp') === 'erp') $this->sessionPositions[(int) $p['id']] = $p;
            }
        }
        return $this->sessionPositions;
    }

    private static function pagesWord(int $n): string
    {
        if ($n === 1) return 'strona';
        return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'strony' : 'stron';
    }

    /**
     * Strona startowa użytkownika (logo, logowanie, nieznany adres) : administrator — Rentowność ; inni — strona startowa
     * stanowiska (domyślnie Dzisiaj), o ile ją widzi.
     */
    public function homePath(?int $userId): string
    {
        if (!$userId) return '/today';
        if ($this->profile($userId)['admin']) {
            // [239] Sesja administratora : strona startowa stanowiska.
            $view = $this->viewPosition($userId);
            if ($view === null) return '/dashboard';
            $home = $this->repo->homes()[$view] ?? '/today';
            return isset(PageAccess::HOME_PAGES[$home]) ? $home : '/today';
        }
        $home = $this->repo->homes()[$this->repo->userPosition($userId)] ?? '/today';
        return isset(PageAccess::HOME_PAGES[$home]) && $this->canSee($userId, $home) ? $home : '/today';
    }

    /** Strona startowa stanowiska — tylko administrator. */
    public function saveHome(int $positionId, mixed $page, ?int $actorId): string
    {
        if (!$this->isAdmin($actorId)) throw new CustomException('Strony startowe ustawia administrator.', 'errors', 403);
        if ($positionId !== 0 && !$this->repo->positionExists($positionId)) throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $page = (string) $page;
        if (!isset(PageAccess::HOME_PAGES[$page])) throw new CustomException('Nieznana strona startowa.', 'errors', 422);
        $this->repo->setHome($positionId, $page, $actorId);
        return $page;
    }

    /** Strona administratora : stanowiska (z « bez stanowiska »), sekcje ze stronami, przyznane strony, użytkownicy. */
    public function matrix(): array
    {
        $users = $this->repo->users();
        $byPosition = [];
        foreach ($users as $u) $byPosition[(int) $u['id_position']][] = ['id' => (int) $u['id'], 'name' => $u['name'], 'admin' => (bool) $u['is_admin']];
        $grants = $this->repo->grants();
        $homes = $this->repo->homes();
        $columns = [];
        foreach ($this->repo->positions() as $p) {
            $columns[] = ['id' => (int) $p['id'], 'name' => $p['name_pl'], 'active' => (int) $p['active'] === 1,
                'users' => $byPosition[(int) $p['id']] ?? [], 'keys' => $grants[(int) $p['id']] ?? [], 'home' => $homes[(int) $p['id']] ?? '/today'];
        }
        $columns[] = ['id' => 0, 'name' => 'Bez stanowiska', 'active' => true, 'users' => $byPosition[0] ?? [], 'keys' => $grants[0] ?? [], 'home' => $homes[0] ?? '/today'];
        $sections = [];
        foreach (PageAccess::SECTIONS as $s) $sections[$s] = ['name' => $s, 'pages' => []];
        foreach (PageAccess::PAGES as $key => [$label, $section, $prefixes, $admin]) {
            $sections[$section]['pages'][] = ['key' => $key, 'label' => $label, 'path' => $prefixes[0], 'admin' => $admin];
        }
        return ['columns' => $columns, 'home_pages' => PageAccess::HOME_PAGES, 'sections' => array_values(array_filter($sections, fn($s) => $s['pages'])),
            'admins' => array_values(array_filter($users, fn($u) => (bool) $u['is_admin']))];
    }

    /** Stany pozycji menu na stronie « Uprawnienia i menu » [253]. */
    public const MENU_STATES = ['menu' => 'w menu', 'access' => 'dostęp (bez menu)', 'none' => 'brak'];

    /**
     * [253] Macierz « Uprawnienia i menu » : stanowiska biurowe (i « bez stanowiska ») × 26 pozycji railu. Stan pozycji :
     * menu (w menu stanowiska), access (strony dostępne, poza menu — wyszukiwarka), none (bez dostępu), admin (tylko
     * administrator). Stanowisko bez własnego menu : wszystkie dostępne pozycje są « w menu » (całe menu).
     * @return array{columns: list<array>, sections: list<array>}
     */
    public function menuMatrix(): array
    {
        $users = $this->repo->users();
        $byPosition = [];
        foreach ($users as $u) $byPosition[(int) $u['id_position']][] = $u['name'];
        $columns = [];
        $positions = array_values(array_filter($this->repo->positions(), fn($p) => ($p['kind'] ?? 'erp') === 'erp' && (int) $p['active'] === 1));
        $positions[] = ['id' => 0, 'name_pl' => 'Bez stanowiska'];
        foreach ($positions as $p) {
            $id = (int) $p['id'];
            $menu = $this->positionMenu($id);
            $states = $this->menuStates($id);
            $columns[] = ['id' => $id, 'name' => $p['name_pl'], 'users' => $byPosition[$id] ?? [], 'own' => $menu !== null,
                'states' => $states, 'open' => array_map(fn($path) => $path === null ? null : self::pathLabel($path), $menu ?? []),
                'count' => count(array_filter($states, fn($s) => $s === 'menu'))];
        }
        $sections = [];
        foreach (Rail::all() as $sec) {
            $items = [];
            foreach ($sec['items'] as $it) {
                $items[] = ['key' => $it['key'], 'label' => $it['label'], 'icon' => $it['icon'], 'href' => $it['href'],
                    'always' => Rail::accessKeys($it['key']) === [] && !Rail::adminOnly($it['key']), 'admin' => Rail::adminOnly($it['key']),
                    'tabs' => self::tabLabels($it['key'])];
            }
            $sections[] = ['title' => $sec['title'] ?: 'Wspólne', 'items' => $items];
        }
        return ['columns' => $columns, 'sections' => $sections, 'states' => self::MENU_STATES];
    }

    /** Stan każdej pozycji railu dla stanowiska. @return array<string, string> pozycja => menu|access|none|admin */
    public function menuStates(int $positionId): array
    {
        $grants = array_fill_keys($this->repo->positionGrants($positionId), true);
        $menu = $this->positionMenu($positionId);
        $out = [];
        foreach (array_keys(Rail::ITEMS) as $item) {
            if (Rail::adminOnly($item)) { $out[$item] = 'admin'; continue; }
            $keys = Rail::accessKeys($item);
            $seen = $keys === [] || array_intersect_key(array_fill_keys($keys, true), $grants) !== [];
            $out[$item] = !$seen ? 'none' : ($menu === null || array_key_exists($item, $menu) ? 'menu' : 'access');
        }
        return $out;
    }

    /**
     * [253] Zmiana stanu pozycji dla stanowiska (tylko administrator) : « w menu » / « dostęp » przyznają strony pozycji,
     * « brak » odbiera wszystkie strony pozycji (pozycja ze wspólnymi stronami też je traci) ; menu stanowiska powstaje z całego
     * menu przy pierwszej zmianie. reset = całe menu (bez własnego menu), uprawnienia bez zmian.
     * @return array{states: array<string, string>, side: list<string>} nowe stany ; side = inne pozycje bez dostępu (wspólne strony)
     */
    public function saveMenuState(int $positionId, array $input, ?int $actorId): array
    {
        if (!$this->isAdmin($actorId)) throw new CustomException('Menu stanowisk ustawia administrator.', 'errors', 403);
        if ($positionId !== 0 && !$this->repo->positionExists($positionId)) throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        if (!empty($input['reset'])) {
            $this->repo->setMenu($positionId, [], $actorId);
            $this->menus = null;
            return ['states' => $this->menuStates($positionId), 'side' => []];
        }
        $item = (string) ($input['item'] ?? '');
        $state = (string) ($input['state'] ?? '');
        if (!isset(Rail::ITEMS[$item])) throw new CustomException('Nieznana pozycja menu.', 'errors', 422);
        if (!isset(self::MENU_STATES[$state])) throw new CustomException('Nieznany stan : w menu, dostęp albo brak.', 'errors', 422);
        if (Rail::adminOnly($item)) throw new CustomException('« ' . Rail::ITEMS[$item][0] . ' » widzi tylko administrator.', 'errors', 422);
        $keys = Rail::accessKeys($item);
        if ($state === 'none' && $keys === []) throw new CustomException('« ' . Rail::ITEMS[$item][0] . ' » widzą wszyscy — może być tylko w menu albo poza menu.', 'errors', 422);

        $before = $this->menuStates($positionId);
        $menu = $this->positionMenu($positionId) ?? array_fill_keys(array_keys(array_filter($before, fn($s) => $s === 'menu')), null);
        $grants = array_fill_keys($this->repo->positionGrants($positionId), true);
        if ($state === 'none') {
            // Wszystkie strony pozycji bez dostępu ; pozycja dzieląca z nią strony (np. /finances : Marże i ceny oraz Koszty)
            // może stracić dostęp razem z nią — strona pokazuje które (side).
            foreach ($keys as $k) unset($grants[$k]);
            unset($menu[$item]);
        } else {
            foreach ($keys as $k) $grants[$k] = true;
            if ($state === 'menu') $menu[$item] = $menu[$item] ?? null;
            else unset($menu[$item]);
        }
        $this->repo->setGrants($positionId, array_values(array_intersect(PageAccess::grantable(), array_keys($grants))), $actorId);
        $ordered = [];
        foreach (array_keys(Rail::ITEMS) as $k) if (array_key_exists($k, $menu)) $ordered[$k] = $menu[$k];
        // Menu nie może być puste (puste = całe menu) : zostaje przynajmniej Dzisiaj.
        $this->repo->setMenu($positionId, $ordered ?: ['today' => null], $actorId);
        $this->menus = null;
        $this->cache = [];
        $this->viewGrants = [];
        $after = $this->menuStates($positionId);
        $side = [];
        foreach ($after as $other => $s) {
            if ($other !== $item && $s === 'none' && ($before[$other] ?? '') !== 'none') $side[] = Rail::ITEMS[$other][0];
        }
        return ['states' => $after, 'side' => $side];
    }

    /** Nazwa strony otwieranej przez pozycję (etykieta zakładki) albo ścieżka. */
    private static function pathLabel(string $path): string
    {
        foreach (\App\Core\Web\PageTabs::GROUPS as $g) foreach ($g['tabs'] as $t) if ($t[0] === $path) return $t[1];
        return $path;
    }

    /** Etykiety zakładek pozycji (podpowiedź na stronie uprawnień). @return list<string> */
    private static function tabLabels(string $item): array
    {
        $out = [];
        foreach (Rail::groupsOf($item) as $group) foreach (\App\Core\Web\PageTabs::GROUPS[$group]['tabs'] as $t) if (empty($t[4])) $out[] = $t[1];
        return $out;
    }

    /** Strony stanowiska (zastąpione) — tylko administrator ; strony administratora nie są przyznawane. */
    public function save(int $positionId, array $input, ?int $actorId): array
    {
        if (!$this->isAdmin($actorId)) throw new CustomException('Uprawnienia stanowisk ustawia administrator.', 'errors', 403);
        if ($positionId !== 0 && !$this->repo->positionExists($positionId)) throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $keys = $input['pages'] ?? null;
        if (!is_array($keys)) throw new CustomException('Brak listy stron.', 'errors', 422);
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $grantable = PageAccess::grantable();
        $unknown = array_diff($keys, $grantable);
        if ($unknown) throw new CustomException('Nieznana strona albo strona tylko dla administratora : ' . implode(', ', $unknown) . '.', 'errors', 422);
        $this->repo->setGrants($positionId, array_values(array_intersect($grantable, $keys)), $actorId);
        $this->cache = [];
        $this->viewGrants = [];
        $this->menus = null;
        return $this->repo->positionGrants($positionId);
    }

    /** @return array{admin:bool, keys:array<string,bool>} */
    private function profile(int $userId): array
    {
        if (!isset($this->cache[$userId])) {
            $admin = $this->repo->isProdAdmin($userId) || (!$this->repo->anyProdAdmin() && $this->repo->login($userId) === 'admin');
            $keys = $admin ? [] : array_fill_keys($this->repo->positionGrants($this->repo->userPosition($userId)), true);
            $this->cache[$userId] = ['admin' => $admin, 'keys' => $keys];
        }
        return $this->cache[$userId];
    }
}
