<?php
declare(strict_types=1);

namespace App\Services\User;

use App\Core\Web\PageAccess;
use App\Repositories\Organization\PageAccessRepository;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\PositionTaskService;

/**
 * Kreator użytkownika ERP (audyt 2, lot C) : po utworzeniu konta (osoba, login, hasło) zapisuje w tym samym
 * formularzu stanowisko (od dziś), rolę w module produkcji (tylko administrator produkcji) i stronę startową
 * stanowiska (tylko administrator — dotyczy wszystkich osób na stanowisku). Podgląd : które strony zobaczy osoba.
 * Każdy krok osobno : błąd jednego trafia na listę « do uzupełnienia » na karcie użytkownika.
 */
final class UserCreationService
{
    public function __construct(
        private PositionTaskService $positions,
        private PageAccessService $access,
        private PageAccessRepository $accessRepo,
    ) {
    }

    /**
     * Stanowiska do wyboru (aktywne) z podglądem : strona startowa i strony według sekcji (klucz 0 = bez stanowiska).
     * Rola i strona startowa — tylko gdy osoba wypełniająca może je ustawić.
     * @return array{positions:list<array{id:int,name:string}>, preview:array<int,array{home:string,home_path:string,sections:array<string,list<string>>}>, home_pages:array<string,string>, roles:list<array{code:string,name_pl:string}>, can_set_role:bool, can_set_home:bool}
     */
    public function choices(?int $actorId): array
    {
        $matrix = $this->access->matrix();
        $preview = [];
        $positions = [];
        foreach ($matrix['columns'] as $c) {
            // Sekcje w kolejności menu.
            $granted = array_flip($c['keys']);
            $sections = [];
            foreach ($matrix['sections'] as $sec) foreach ($sec['pages'] as $p) if (isset($granted[$p['key']])) $sections[$sec['name']][] = $p['label'];
            $preview[$c['id']] = ['home' => PageAccess::HOME_PAGES[$c['home']] ?? 'Dzisiaj', 'home_path' => $c['home'], 'sections' => $sections];
            if ($c['id'] !== 0 && $c['active']) $positions[] = ['id' => $c['id'], 'name' => $c['name']];
        }
        return ['positions' => $positions, 'preview' => $preview, 'home_pages' => PageAccess::HOME_PAGES, 'roles' => $this->positions->moduleRoles(0)['roles'],
            'can_set_role' => $this->positions->canSetModuleRoles($actorId), 'can_set_home' => $this->access->isAdmin($actorId)];
    }

    /** @return list<string> problemy (krok : komunikat) */
    public function complete(int $userId, array $input, ?int $actorId): array
    {
        $problems = [];
        $positionId = ctype_digit(trim((string) ($input['position_id'] ?? ''))) ? (int) $input['position_id'] : null;
        if ($positionId !== null) {
            try {
                $this->positions->assign($userId, ['position_id' => $positionId, 'since' => date('Y-m-d')]);
            } catch (\Throwable $e) {
                $problems[] = 'Stanowisko : ' . $e->getMessage();
                $positionId = null;
            }
        }
        $role = trim((string) ($input['role_code'] ?? ''));
        if ($role !== '') {
            try {
                $this->positions->setModuleRole($userId, ['role_code' => $role], $actorId);
            } catch (\Throwable $e) {
                $problems[] = 'Rola w module produkcji : ' . $e->getMessage();
            }
        }
        $home = trim((string) ($input['home_page'] ?? ''));
        $current = $this->accessRepo->homes()[$positionId ?? 0] ?? '/today';
        if ($home !== '' && $home !== $current) {
            try {
                $this->access->saveHome($positionId ?? 0, $home, $actorId);
            } catch (\Throwable $e) {
                $problems[] = 'Strona startowa : ' . $e->getMessage();
            }
        }
        return $problems;
    }
}
