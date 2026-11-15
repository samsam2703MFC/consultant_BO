<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Exceptions\CustomException;
use App\Repositories\Organization\ErpEntryRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Execution\KioskAuthService;
use DateTimeImmutable;

/**
 * [275] Wejście do ERP przez kod QR (makieta 40). Pour un compte dont un poste a l'option (n_position.erp_qr) et qui est
 * lié à une personne de Czas pracy (n_user_employee) : tant que la personne n'a pas pointé son entrée, l'ERP n'affiche que
 * le QR personnel (personne + jour, erp.qr_ttl_s, renouvelé tout seul). Elle le scanne avec son téléphone appairé et valide
 * l'entrée (mêmes questions que la tablette de bureau) → l'ERP s'ouvre. Entrée déjà pointée (tablette de bureau, PIN) :
 * ERP ouvert directement. Le soir « Zakończ dzień » → même QR → sortie (question marchandise) → déconnexion.
 * Jamais pour l'administrateur ni pour un compte CEO [281] ; sans liaison compte ↔ personne : pas de verrou (l'administrateur la règle dans la fiche
 * de l'utilisateur, la page « Uprawnienia i menu » montre les comptes sans liaison).
 */
final class ErpEntryService
{
    public function __construct(private ErpEntryRepository $repo, private PageAccessService $access, private KioskAuthService $kiosk,
        private ParamRepository $params)
    {
    }

    /** Personne liée au compte si l'entrée par QR s'applique à lui (sinon null). */
    public function subject(?int $userId): ?array
    {
        if (!$userId || $this->access->isAdmin($userId) || $this->access->isCeo($userId) || !$this->repo->positionRequiresQr($userId)) return null;
        $e = $this->repo->employeeOf($userId);
        return $e && (int) $e['active'] ? ['employee_id' => (int) $e['id'], 'name' => $e['name']] : null;
    }

    /** Verrou : le compte doit pointer son entrée avant d'ouvrir l'ERP. */
    public function locked(?int $userId): bool
    {
        $s = $this->subject($userId);
        return $s !== null && $this->repo->openClock($s['employee_id']) === null;
    }

    /** État de la journée (écran QR, barre du haut « W pracy od … · Zakończ dzień »). */
    public function state(?int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $s = $this->subject($userId);
        if ($s === null) return ['enabled' => false];
        $open = $this->repo->openClock($s['employee_id']);
        $minutes = 0;
        foreach ($this->repo->dayClocks($s['employee_id'], $now->format('Y-m-d')) as $c) {
            $end = $c['clock_out'] !== null ? strtotime((string) $c['clock_out']) : $now->getTimestamp();
            $minutes += max(0, intdiv($end - strtotime((string) $c['clock_in']), 60));
        }
        $plan = max(60, (int) $this->params->get('time.day_plan_min', '480'));
        return [
            'enabled' => true, 'name' => $s['name'], 'first_name' => explode(' ', trim($s['name']))[0], 'clocked_in' => $open !== null,
            'since' => $open !== null ? substr((string) $open['clock_in'], 11, 5) : null, 'now' => $now->format('H:i'), 'day' => $now->format('Y-m-d'),
            'minutes' => $minutes, 'plan_minutes' => $plan, 'overtime_minutes' => max(0, $minutes - $plan),
        ];
    }

    /** QR personnel du jour (entrée le matin, sortie le soir) ; « since » sert à attendre le pointage. */
    public function qr(?int $userId, string $base, ?DateTimeImmutable $now = null): array
    {
        $s = $this->subject($userId) ?? throw new CustomException('Wejście przez QR nie dotyczy tego konta.', 'errors', 403);
        $device = (int) $this->params->get('erp.qr_device_id', '0');
        if ($device <= 0) throw new CustomException('Brak urządzenia kodów QR (migracja 174).', 'errors', 503);
        $ttl = (int) $this->params->get('erp.qr_ttl_s', '180');
        return ['since' => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'), 'direction' => $this->repo->openClock($s['employee_id']) ? 'out' : 'in']
            + $this->kiosk->erpQr($device, $s['employee_id'], $base, $ttl);
    }

    /** Pointage enregistré depuis l'affichage du QR ? */
    public function status(?int $userId, string $since): array
    {
        $s = $this->subject($userId) ?? throw new CustomException('Wejście przez QR nie dotyczy tego konta.', 'errors', 403);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) throw new CustomException('Zły format czasu.', 'errors', 422);
        $e = $this->repo->clockEventSince($s['employee_id'], $since);
        $dir = $e === null ? null : ($e['clock_out'] !== null && $e['clock_out'] >= $since ? 'out' : 'in');
        return ['clocked' => $e !== null, 'direction' => $dir, 'at' => $e === null ? null : substr((string) ($dir === 'out' ? $e['clock_out'] : $e['clock_in']), 11, 5),
            'clocked_in' => $this->repo->openClock($s['employee_id']) !== null];
    }

    // --- Administrateur ------------------------------------------------------------------------------------

    /** Tableau « Uprawnienia i menu » : postes ERP, option, comptes (liés ou non, téléphone appairé). */
    public function matrix(): array
    {
        $users = [];
        foreach ($this->repo->positionUsers() as $u) $users[(int) $u['id_position']][] = ['id' => (int) $u['id'], 'name' => $u['name'],
            'linked' => $u['id_employee'] !== null, 'phones' => (int) $u['phones'], 'admin' => $this->access->isAdmin((int) $u['id'])];
        return array_map(fn($p) => ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name_pl'], 'enabled' => (bool) $p['erp_qr'],
            'users' => $users[(int) $p['id']] ?? []], $this->repo->positions());
    }

    public function setPosition(int $positionId, bool $on): array
    {
        if (!$this->repo->setPositionQr($positionId, $on)) throw new CustomException('Nie ma takiego stanowiska ERP.', 'errors', 404);
        return $this->matrix();
    }

    /** Fiche utilisateur : personne de Czas pracy liée (une seule ; une personne ne peut être liée qu'à un compte). */
    public function linkData(int $userId): array
    {
        $e = $this->repo->employeeOf($userId);
        return ['employee_id' => $e ? (int) $e['id'] : null, 'requires_qr' => $this->repo->positionRequiresQr($userId) && !$this->access->isAdmin($userId),
            'employees' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'taken' => $r['linked_user'] !== null && (int) $r['linked_user'] !== $userId], $this->repo->employees())];
    }

    public function link(int $userId, mixed $employeeId, ?int $by): array
    {
        $id = $employeeId === null || $employeeId === '' ? null : (int) $employeeId;
        if ($id !== null && !in_array($id, array_map(fn($r) => (int) $r['id'], $this->repo->employees()), true)) {
            throw new CustomException('Wybierz aktywnego pracownika.', 'errors', 422);
        }
        $this->repo->link($userId, $id, $by);
        return $this->linkData($userId);
    }
}
