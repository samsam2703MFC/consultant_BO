<?php
declare(strict_types=1);

namespace App\Services\Employee;

use App\Repositories\Staff\StaffRepository;
use App\Repositories\Workshop\EmployeeRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Services\Workshop\Execution\KioskAuthService;

/**
 * Kreator pracownika produkcji (audyt 2, lot C) : po utworzeniu pracownika (imię, nazwisko, dział, koszt pracodawcy na miesiąc)
 * zapisuje profil zespołu (grupa, stanowisko, poziom), płacę z budżetu (podstawa, premia) i dostęp do tabletu
 * (PIN 4–6 cyfr, badge — oba tylko jako skrót, nigdy w jawnej postaci). Każdy krok osobno : błąd jednego trafia
 * na listę « do uzupełnienia » na karcie pracownika.
 */
final class EmployeeCreationService
{
    public function __construct(
        private EmployeeRepository $employees,
        private StaffRepository $staff,
        private ExecutionRepository $execution,
        private KioskAuthService $kiosk,
    ) {
    }

    /** @return list<string> problemy (krok : komunikat) */
    public function complete(int $productionEmployeeId, array $input, ?int $userId): array
    {
        $problems = [];
        $int = fn($k) => ($v = trim((string) ($input[$k] ?? ''))) !== '' && ctype_digit($v) ? (int) $v : null;
        try {
            $profileId = $this->employees->ensureProfile($productionEmployeeId, $int('primary_group_id'));
            $profile = array_filter(['id_primary_group' => $int('primary_group_id'), 'level_name' => trim((string) ($input['level_name'] ?? '')) ?: null,
                'level_order' => $int('level_order'), 'active' => 1], fn($v) => $v !== null);
            if (isset($profile['level_name']) || isset($profile['level_order'])) $profile['level_synced_at'] = date('Y-m-d H:i:s');
            $this->employees->update($profileId, $profile);
            if ($int('position_id') !== null) $this->employees->setPosition($profileId, $int('position_id'));
        } catch (\Throwable $e) {
            return ['Zespół : ' . $e->getMessage()];
        }
        $base = str_replace(',', '.', trim((string) ($input['base_monthly'] ?? '')));
        if ($base !== '' || !empty($input['bonus_eligible'])) {
            try {
                $this->staff->savePay($productionEmployeeId, ['base_monthly' => is_numeric($base) && (float) $base >= 0 ? $base : null,
                    'bonus_eligible' => !empty($input['bonus_eligible']) ? 1 : 0, 'updated_by' => $userId]);
            } catch (\Throwable $e) {
                $problems[] = 'Płaca : ' . $e->getMessage();
            }
        }
        $pin = trim((string) ($input['pin'] ?? ''));
        $badge = trim((string) ($input['badge'] ?? ''));
        $credential = ['updated_by' => $userId];
        if ($pin !== '') {
            if (preg_match('/^\d{4,6}$/', $pin)) $credential['pin_hash'] = password_hash($pin, PASSWORD_BCRYPT);
            else $problems[] = 'PIN : 4 do 6 cyfr — nie zapisano.';
        }
        if ($badge !== '') {
            $hash = $this->kiosk->badgeHash($badge);
            $other = $this->execution->credentialByBadge($hash);
            if ($other && (int) $other['id_employee'] !== $profileId) $problems[] = 'Badge : ten numer ma już inny pracownik — nie zapisano.';
            else $credential['badge_hash'] = $hash;
        }
        if (count($credential) > 1) {
            try {
                $this->execution->saveCredential($profileId, $credential);
            } catch (\Throwable $e) {
                $problems[] = 'Tablet : ' . $e->getMessage();
            }
        }
        return $problems;
    }
}
