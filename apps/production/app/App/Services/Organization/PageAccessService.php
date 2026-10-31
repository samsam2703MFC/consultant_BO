<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Web\PageAccess;
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
        if ($userId && $this->profile($userId)['admin']) return [];
        $out = [];
        foreach (PageAccess::PAGES as $key => [, , $prefixes]) {
            $seen = $userId && !PageAccess::adminOnly($key) && isset($this->profile($userId)['keys'][$key]);
            foreach ($prefixes as $p) $out[$p] = $seen;
        }
        return $out;
    }

    /**
     * Strona startowa użytkownika (logo, logowanie, nieznany adres) : administrator — Rentowność ; inni — strona startowa
     * stanowiska (domyślnie Dzisiaj), o ile ją widzi.
     */
    public function homePath(?int $userId): string
    {
        if (!$userId) return '/today';
        if ($this->profile($userId)['admin']) return '/dashboard';
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
