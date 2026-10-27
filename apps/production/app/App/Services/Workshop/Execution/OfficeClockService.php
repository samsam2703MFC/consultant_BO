<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Workshop\EmployeeRepository;
use App\Repositories\Workshop\ExecutionRepository;

/**
 * Tablette de bureau (mode « office ») : la personne touche sa section (dział) puis son nom ; la tablette affiche son QR
 * personnel (personne + jour, kiosk.office_qr_ttl_s). Elle le scanne avec son téléphone enregistré et valide l'entrée ou
 * la sortie sur le téléphone (questions, signature) — le téléphone d'une autre personne est refusé (QR_OTHER_PERSON).
 * La tablette interroge /kiosk/office/status et confirme dès que le pointage est enregistré. Sans téléphone : PIN puis
 * questions et signature sur la tablette (pin_allowed ; kiosk.office_pin_all = 1 : pour tout le monde).
 */
final class OfficeClockService
{
    public function __construct(
        private ExecutionRepository $repo,
        private EmployeeRepository $employees,
        private KioskAuthService $kiosk,
    ) {
    }

    /** Sections et leurs personnes actives, avec ce qu'il faut pour le premier scan (PIN) et l'état du jour. */
    public function sections(ApiContext $context): array
    {
        $this->officeDevice($context);
        $sections = [];
        foreach ($this->repo->officePeople(date('Y-m-d')) as $p) {
            $id = (int) $p['section_id'];
            $sections[$id] ??= ['id' => $id, 'name' => $p['section_name'], 'people' => []];
            $sections[$id]['people'][] = $this->person($p);
        }
        return array_values(array_filter($sections, fn($s) => $s['people']));
    }

    /** QR personnel d'une personne (person_id = production_employee.id) ; son profil de production est créé au besoin. */
    public function qr(array $body, ApiContext $context): array
    {
        $this->officeDevice($context);
        $v = (new Validator($body))->only(['person_id', 'base'])->integer('person_id', true, 1)->string('base', 220, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $row = $this->row((int) $c['person_id']) ?? throw ApiException::notFound('person', (int) $c['person_id']);
        $employeeId = $row['employee_id'] !== null ? (int) $row['employee_id'] : $this->employees->ensureProfile((int) $c['person_id']);
        return ['person' => $this->person(['employee_id' => $employeeId] + $row), 'day' => date('Y-m-d'), 'since' => date('Y-m-d H:i:s')]
            + $this->kiosk->personalQr($context, $employeeId, (string) $c['base']);
    }

    /** Pointage enregistré depuis l'affichage du QR (la tablette confirme et revient à l'accueil). */
    public function status(int $employeeId, string $since, ApiContext $context): array
    {
        $this->officeDevice($context);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) throw ApiException::validation([['field' => 'since', 'code' => 'INVALID_DATETIME', 'params' => []]]);
        $event = $this->repo->clockEventSince($employeeId, $since);
        $direction = $event === null ? null : ($event['clock_out'] !== null && $event['clock_out'] >= $since ? 'out' : 'in');
        return [
            'clocked' => $event !== null,
            'direction' => $direction,
            'at' => $event === null ? null : substr((string) ($direction === 'out' ? $event['clock_out'] : $event['clock_in']), 11, 5),
            'clocked_in' => $this->repo->openClock($employeeId) !== null,
        ];
    }

    private function person(array $p): array
    {
        return [
            'id' => (int) $p['person_id'], 'employee_id' => $p['employee_id'] !== null ? (int) $p['employee_id'] : null, 'name' => $p['name'],
            'has_pin' => (bool) $p['has_pin'], 'phones' => (int) $p['phones'],
            'pin_allowed' => (bool) $p['has_pin'] && ((int) $p['phones'] === 0 || $this->kiosk->officePinAll()),
            'clocked_in' => $p['clocked_in_at'] !== null, 'since' => $p['clocked_in_at'] !== null ? substr((string) $p['clocked_in_at'], 11, 5) : null,
            'left_at' => $p['clocked_in_at'] === null && $p['left_at'] !== null ? substr((string) $p['left_at'], 11, 5) : null,
        ];
    }

    private function row(int $personId): ?array
    {
        foreach ($this->repo->officePeople(date('Y-m-d')) as $p) {
            if ((int) $p['person_id'] === $personId) return $p;
        }
        return null;
    }

    private function officeDevice(ApiContext $context): void
    {
        if (($this->repo->find((int) $context->deviceId)['mode'] ?? 'atelier') !== 'office') throw new ApiException('DEVICE_NOT_OFFICE', 403);
    }
}
