<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Workshop\EmployeeRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Services\Workshop\Execution\KioskAuthService;

/**
 * Kreator pojazdu i kierowcy (audyt 2, lot C) : jedna karta — przewoźnik i numer, pojemność (palety, kg, wyjazd),
 * kierowca wybrany spośród pracowników (lista i PIN tabletu kierowcy ; jego nazwisko trafia też do CMR) albo kierowca
 * przewoźnika wpisany ręcznie, trasy pojazdu. Pojazd powstaje jak dotąd (LogisticsService::addVehicle, ta sama
 * walidacja) ; reszta krok po kroku, błąd kroku trafia na listę « do uzupełnienia ».
 */
final class VehicleCreationService
{
    public function __construct(
        private LogisticsService $logistics,
        private LogisticsRepository $repo,
        private EmployeeRepository $employees,
        private ExecutionRepository $execution,
        private PositionRepository $positions,
    ) {
    }

    /** Kierowcy (stanowisko Kierowca-dostawca) i pozostali aktywni pracownicy, trasy. */
    public function choices(): array
    {
        $drivers = $this->execution->people(null, KioskAuthService::DRIVER_POSITION);
        $driverIds = array_flip(array_map(fn($p) => (int) $p['id'], $drivers));
        $others = array_values(array_filter($this->execution->people(null), fn($p) => !isset($driverIds[(int) $p['id']])));
        $slim = fn(array $p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'has_pin' => (bool) (int) $p['has_pin']];
        return ['drivers' => array_map($slim, $drivers), 'other_employees' => array_map($slim, $others),
            'tour_choices' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'active' => (bool) (int) $t['active']], $this->repo->tours())];
    }

    /** @return array{id:int, problems:list<string>} */
    public function create(array $input, ?int $userId): array
    {
        $driverId = filter_var($input['driver_employee_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $driver = null;
        if ($driverId !== null) {
            $driver = $this->employees->byProfile($driverId);
            if (!$driver || !(int) $driver['active']) throw new CustomException('Nie znaleziono pracownika — wybierz kierowcę z listy.', 'errors', 422);
            // Nazwisko kierowcy na CMR = pracownik z tabletu (jedna osoba, nie dwie).
            $input['driver_name'] = $driver['name'];
            $input['driver_surname'] = $driver['surname'];
        }
        $id = $this->logistics->addVehicle($input, $userId);
        $problems = [];
        if ($driver !== null) {
            try {
                $this->repo->setVehicleDriver($id, $driverId, $userId);
                if (!empty($input['driver_set_position']) && $driver['id_position'] === null) {
                    $positionId = $this->positions->idByCode(KioskAuthService::DRIVER_POSITION);
                    if ($positionId !== null) $this->employees->setPosition($driverId, $positionId);
                    else $problems[] = 'Kierowca : brak stanowiska « Kierowca-dostawca » (Stanowiska i zadania).';
                }
            } catch (\Throwable $e) {
                $problems[] = 'Kierowca : ' . $e->getMessage();
            }
            $pin = trim((string) ($input['driver_pin'] ?? ''));
            if ($pin !== '') {
                // PIN już ustawiony zostaje (zmiana : Tablet produkcji › Identyfikatory i PIN).
                if (!empty($this->execution->credential($driverId)['pin_hash'])) $problems[] = 'PIN kierowcy : już ustawiony — nie zmieniono.';
                else if (!preg_match('/^\d{4,6}$/', $pin)) $problems[] = 'PIN kierowcy : 4 do 6 cyfr — nie zapisano.';
                else {
                    try {
                        $this->execution->saveCredential($driverId, ['pin_hash' => password_hash($pin, PASSWORD_BCRYPT), 'updated_by' => $userId]);
                    } catch (\Throwable $e) {
                        $problems[] = 'PIN kierowcy : ' . $e->getMessage();
                    }
                }
            }
        }
        $tourIds = array_values(array_unique(array_filter(array_map(fn($t) => filter_var($t, FILTER_VALIDATE_INT), (array) ($input['tour_ids'] ?? [])))));
        if ($tourIds) {
            $current = [];
            foreach ($this->repo->tourVehicles() as $tv) $current[(int) $tv['id_tour']][] = (int) $tv['id_carrier_vehicle'];
            foreach ($tourIds as $tourId) {
                try {
                    $this->logistics->setTourVehicles($tourId, ['vehicle_ids' => array_merge($current[$tourId] ?? [], [$id])]);
                } catch (\Throwable $e) {
                    $problems[] = 'Trasa : ' . $e->getMessage();
                }
            }
        }
        return ['id' => $id, 'problems' => $problems];
    }
}
