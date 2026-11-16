<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Workshop\EmployeeRepository;
use App\Services\Employee\EmployeeCreationService;
use App\Services\Employee\ProductionEmployeeService;
use App\Services\Employee\Section\ProductionSectionService;
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Hr\HrService;
use App\Domain\Payroll\BankXml;

/**
 * [303] Asystent « Transport dla zamówień » (makieta 56). Zamówienia z wysyłką dziś i jutro (expected_shipping_date — data wysyłki, nie data zamówienia), których klient nie ma pojazdu
 * (LogisticsService::day()['unassigned'] — ta sama reguła co w Dniu wysyłki : przydział dnia, pojazd trasy, ostatni CMR).
 * Logistyka zaznacza klientów i przypisuje ich do pojazdu z floty (przydział dnia, jak w Dniu wysyłki). Dopóki zostaje
 * klient bez transportu, zadanie obowiązkowe Logistyki (orders_transport) blokuje ERP.
 * [306] Brak pojazdu → mini-asystent « Dodaj pojazd » (włącz istniejący albo nowy) ; po przypisaniu krok « Kierowca i
 * trasa » : kierowca pojazdu (pracownik — lista i PIN tabletu kierowcy, nazwisko na CMR) i trasa pojazdu ; brak kierowcy
 * → mini-asystent « Dodaj kierowcę » (pracownik ze stanowiskiem Kierowca-dostawca).
 */
final class TransportWizardService
{
    public const CODE = 'orders_transport';
    private const DAYS = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];

    public function __construct(
        private LogisticsService $logistics,
        private LogisticsRepository $repo,
        private VehicleCreationService $vehicles,
        private ProductionEmployeeService $staff,
        private EmployeeCreationService $staffCreation,
        private ProductionSectionService $sections,
        private EmployeeRepository $employees,
        private PositionRepository $positions,
        private HrService $hr,
        private DriverContractService $contracts,
    ) {
    }

    /** Dni wysyłki sprawdzane : dziś i jutro. @return list<string> */
    public function dates(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('today');
        return [$now->format('Y-m-d'), $now->modify('+1 day')->format('Y-m-d')];
    }

    /** Klienci bez transportu w sprawdzanych dniach (liczba). */
    public function uncovered(?\DateTimeImmutable $now = null): int
    {
        $n = 0;
        foreach ($this->dates($now) as $d) $n += count($this->logistics->day($d)['unassigned']);
        return $n;
    }

    /** Dane asystenta : dni z klientami bez transportu i flotą (zapełnienie). */
    public function data(?\DateTimeImmutable $now = null): array
    {
        $days = [];
        $total = 0;
        foreach ($this->dates($now) as $d) {
            $day = $this->logistics->day($d);
            $un = array_map(fn($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'town' => $c['town'] ?? null, 'orders' => (int) $c['orders'],
                'pallets' => (int) $c['pallets'], 'cartons' => round((float) ($c['cartons'] ?? 0), 1)], $day['unassigned']);
            $fleet = array_map(fn($v) => ['id' => (int) $v['id'], 'label' => (string) $v['label'], 'carrier' => $v['carrier'] ?? null, 'places' => $v['places'],
                'pallets' => (int) $v['pallets'], 'customers' => count($v['customers']), 'departure' => $v['departure'] ?? null,
                'names' => array_values(array_map(fn($cid) => (string) ($day['by_id'][$cid]['name'] ?? ''), $v['customers']))],
                array_values(array_filter($day['fleet'], fn($v) => $v['active'] || $v['customers'])));
            $total += count($un);
            $days[] = ['date' => $d, 'label' => self::DAYS[(int) date('w', strtotime($d))] . ' ' . date('d.m', strtotime($d)), 'unassigned' => $un, 'fleet' => $fleet,
                'customers' => count($day['customers'])];
        }
        return ['days' => $days, 'uncovered' => $total] + $this->setup();
    }

    /**
     * Pojazdy (aktywne i do włączenia), kierowcy, trasy i działy — dla mini-asystentów « Dodaj pojazd », « Dodaj kierowcę »
     * i kroku « Kierowca i trasa ».
     */
    private function setup(): array
    {
        $v = $this->logistics->vehicles();
        $choices = $this->vehicles->choices();
        $tourOf = [];
        foreach ($this->repo->tourVehicles() as $tv) $tourOf[(int) $tv['id_carrier_vehicle']][] = (int) $tv['id_tour'];
        // Kierowca trasy pojazdu (pierwsza trasa z kierowcą) : podpowiedź, gdy pojazd nie ma kierowcy.
        $tourDriver = [];
        foreach ($this->repo->tours() as $t) if ($t['id_driver'] !== null) $tourDriver[(int) $t['id']] = (int) $t['id_driver'];
        $suggest = function (int $vid) use ($tourOf, $tourDriver): ?int {
            foreach ($tourOf[$vid] ?? [] as $tid) if (isset($tourDriver[$tid])) return $tourDriver[$tid];
            return null;
        };
        $label = fn($x) => trim((string) $x['regis_num']) !== '' ? (string) $x['regis_num'] : (string) $x['carrier'];
        $vehicles = array_map(fn($x) => ['id' => (int) $x['id'], 'label' => $label($x), 'carrier' => $x['carrier'], 'active' => (bool) (int) $x['active'],
            'places' => $x['pallet_places'] !== null ? (int) $x['pallet_places'] : null, 'departure' => $x['departure_time'],
            'driver_id' => $x['id_driver'] !== null ? (int) $x['id_driver'] : null, 'driver' => trim((string) $x['driver']) ?: null,
            'tour_ids' => $tourOf[(int) $x['id']] ?? [], 'tour_driver_id' => $suggest((int) $x['id'])], $v['vehicles']);
        $sections = array_map(fn($x) => ['id' => (int) $x['id'], 'name' => (string) $x['name']], $this->sections->getAll() ?: []);
        // Dział domyślny nowego kierowcy : pakowanie / wysyłka (po nazwie), inaczej pierwszy.
        $default = null;
        foreach ($sections as $x) if ($default === null && preg_match('/pakow|wysy|logist/i', $x['name'])) $default = $x['id'];
        return ['vehicles' => $vehicles, 'carriers' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => trim((string) $c['name'])], $v['carriers']),
            'default_places' => $v['default_places'], 'default_departure' => $v['default_departure'],
            'drivers' => $choices['drivers'], 'other_employees' => $choices['other_employees'], 'tours' => $choices['tour_choices'],
            'sections' => $sections, 'default_section' => $default ?? ($sections[0]['id'] ?? null),
            // [309] Kierowcy : współpraca (etat / zlecenie), umowa ; listy formularza « Dodaj kierowcę ».
            'driver_info' => array_column($this->contracts->drivers(), null, 'id'), 'pay_bases' => DriverContractService::PAY,
            'contract_types' => ['nieokreslony' => 'na czas nieokreślony', 'okreslony' => 'na czas określony', 'probny' => 'okres próbny'],
            'sizes' => HrService::SIZES];
    }

    /**
     * [306] Mini-asystent « Dodaj pojazd » : vehicle_id = włącz pojazd istniejący ; inaczej nowy pojazd (przewoźnik, numer —
     * ta sama walidacja co Logistyka › Pojazdy). [308] Pojemność (miejsca paletowe) i wyjazd zmienia się tylko w parametrach
     * pojazdu (Logistyka › Trasy › Pojazdy i kierowcy) : asystent ich nie przyjmuje. Pojazd bez pojemności dostaje domyślną
     * (logistics.default_pallet_places), jak nowy pojazd. @return int id pojazdu
     */
    public function addVehicle(array $in, ?int $userId): int
    {
        $default = (int) $this->logistics->vehicles()['default_places'];
        $existing = (int) ($in['vehicle_id'] ?? 0);
        if ($existing > 0) {
            $known = null;
            foreach ($this->logistics->vehicles()['vehicles'] as $v) if ((int) $v['id'] === $existing) $known = $v;
            if ($known === null) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
            $places = $known['pallet_places'] !== null ? (int) $known['pallet_places'] : $default;
            $this->logistics->saveVehicle($existing, ['pallet_places' => $places, 'active' => true], $userId);
            return $existing;
        }
        return $this->vehicles->create(['carrier_id' => $in['carrier_id'] ?? null, 'regis_num' => $in['regis_num'] ?? '', 'pallet_places' => $default, 'active' => true], $userId)['id'];
    }

    /**
     * [306] Krok « Kierowca i trasa » : kierowca pojazdu (pracownik ; nazwisko i telefon trafiają też na CMR) i trasy,
     * do których pojazd należy (dopisany, nie zastępuje innych pojazdów trasy).
     */
    public function linkDriver(array $in, ?int $userId): void
    {
        $vehicle = (int) ($in['vehicle_id'] ?? 0);
        if ($vehicle <= 0 || !$this->repo->vehicleExists($vehicle)) throw new CustomException('Nie znaleziono pojazdu.', 'errors', 404);
        $driverId = (int) ($in['driver_id'] ?? 0);
        if ($driverId > 0) {
            $driver = $this->employees->byProfile($driverId);
            if (!$driver || !(int) $driver['active']) throw new CustomException('Nie znaleziono pracownika — wybierz kierowcę z listy.', 'errors', 422);
            $this->repo->setVehicleDriver($vehicle, $driverId, $userId);
            $phone = trim((string) ($in['driver_phone'] ?? ''));
            if (mb_strlen($phone) > 30) throw new CustomException('Telefon kierowcy : najwyżej 30 znaków.', 'errors', 422);
            $this->repo->setVehicleDriverName($vehicle, (string) $driver['name'], (string) $driver['surname'], $phone !== '' ? $phone : null);
            // Kierowca bez stanowiska → Kierowca-dostawca (lista i PIN tabletu kierowcy).
            if ($driver['id_position'] === null && ($pos = $this->positions->idByCode(KioskAuthService::DRIVER_POSITION)) !== null) $this->employees->setPosition($driverId, $pos);
        }
        $tour = (int) ($in['tour_id'] ?? 0);
        if ($tour > 0) {
            $current = [];
            foreach ($this->repo->tourVehicles() as $tv) if ((int) $tv['id_tour'] === $tour) $current[] = (int) $tv['id_carrier_vehicle'];
            if (!in_array($vehicle, $current, true)) $this->logistics->setTourVehicles($tour, ['vehicle_ids' => array_merge($current, [$vehicle])]);
        }
        // Trasa = kierowca zwykle ten sam : trasy pojazdu bez kierowcy dostają kierowcę pojazdu.
        if ($driverId > 0) {
            foreach ($this->repo->tourVehicles() as $tv) {
                if ((int) $tv['id_carrier_vehicle'] !== $vehicle) continue;
                $t = $this->repo->tour((int) $tv['id_tour']);
                if ($t && $t['id_driver'] === null) $this->logistics->updateTour((int) $t['id'], ['driver_id' => $driverId], $userId);
            }
        }
    }

    /**
     * [306/309] Mini-asystent « Dodaj kierowcę » (makieta 57) : najpierw rodzaj współpracy.
     *  - etat : umowa o pracę, pełna karta HR (osoba, umowa, płaca — HrService::save, dane wrażliwe zaszyfrowane) ;
     *  - zlecenie : tylko dane do umowy zlecenia na przewóz (PESEL, adres, rachunek, prawo jazdy, wynagrodzenie za kurs /
     *    godzinę / km / ryczałt, okres) ; umowę przygotowuje potem Logistyka (DriverContractService).
     * Zawsze : pracownik (dział ; koszt pracodawcy uzupełnia administrator), stanowisko Kierowca-dostawca, PIN tabletu
     * kierowcy (opcjonalnie) ; vehicle_id → od razu kierowca tego pojazdu.
     * @return array{id:int, cooperation:string, problems:list<string>} id = profil pracownika (n_prod_employee)
     */
    public function addDriver(array $in, ?int $userId): array
    {
        $coop = (string) ($in['cooperation'] ?? 'etat');
        if (!in_array($coop, ['etat', 'zlecenie'], true)) throw new CustomException('Wybierz : kierowca etatowy albo na zlecenie.', 'errors', 422);
        $name = trim((string) ($in['name'] ?? ''));
        $surname = trim((string) ($in['surname'] ?? ''));
        if ($name === '' || $surname === '' || mb_strlen($name) > 60 || mb_strlen($surname) > 60) throw new CustomException('Podaj imię i nazwisko kierowcy.', 'errors', 422);
        $section = (int) ($in['section_id'] ?? 0);
        if (!in_array($section, array_column($this->setup()['sections'], 'id'), true)) throw new CustomException('Wybierz dział.', 'errors', 422);
        $pin = trim((string) ($in['pin'] ?? ''));
        if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) throw new CustomException('PIN : 4 do 6 cyfr.', 'errors', 422);
        [$hr, $driver] = $this->driverInput($coop, $in);

        $position = $this->positions->idByCode(KioskAuthService::DRIVER_POSITION);
        $peId = (int) $this->staff->insert(['name' => $name, 'surname' => $surname, 'cost' => '0', 'id_section' => $section]);
        $problems = $this->staffCreation->complete($peId, ['position_id' => $position !== null ? (string) $position : '', 'pin' => $pin], $userId);
        if ($position === null) $problems[] = 'Stanowisko : brak « Kierowca-dostawca » (Stanowiska i zadania).';
        $problems[] = 'Płaca : koszt pracodawcy uzupełnia administrator (Płace i premie › Budżet personelu).';
        $profile = $this->employees->ensureProfile($peId);
        try {
            $this->hr->save($profile, $hr, $userId, true);
        } catch (CustomException $e) {
            $fields = $e->getErrors()['fields'] ?? [];
            $problems[] = 'Karta HR : ' . ($fields ? implode(' ', array_values($fields)) : $e->getMessage()) . ' — uzupełnij w HR.';
        }
        $this->contracts->saveDriverData($profile, $driver['licence'], $driver['basis'], $driver['amount'], $userId);
        if ((int) ($in['vehicle_id'] ?? 0) > 0) $this->linkDriver(['vehicle_id' => $in['vehicle_id'], 'driver_id' => $profile, 'driver_phone' => $in['phone'] ?? ($in['driver_phone'] ?? '')], $userId);
        return ['id' => $profile, 'cooperation' => $coop, 'problems' => $problems];
    }

    /**
     * Pola karty HR i dane transportowe z formularza, sprawdzone przed utworzeniem pracownika (bez połowicznego kierowcy).
     * @return array{0: array, 1: array{licence: ?string, basis: ?string, amount: ?float}}
     */
    private function driverInput(string $coop, array $in): array
    {
        $t = fn(string $k) => trim((string) ($in[$k] ?? ''));
        $errors = [];
        $date = function (string $k) use ($t, &$errors): string {
            $v = $t($k);
            if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))) $errors[] = 'Data : RRRR-MM-DD.';
            return $v;
        };
        $pesel = preg_replace('/\s+/', '', $t('pesel'));
        if ($pesel !== '' && !HrService::peselOk($pesel)) $errors[] = 'PESEL : 11 cyfr, zła suma kontrolna.';
        if ($t('bank') !== '' && BankXml::iban($t('bank')) === null) $errors[] = 'Numer rachunku : 26 cyfr (suma kontrolna).';
        $amount = null;
        if ($t('pay_amount') !== '') {
            $v = str_replace([' ', ','], ['', '.'], $t('pay_amount'));
            if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 1_000_000) $errors[] = 'Wynagrodzenie : kwota brutto większa od 0.';
            else $amount = round((float) $v, 2);
        }
        $common = ['phone' => $t('phone'), 'pesel' => $pesel, 'address' => $t('address'), 'bank' => $t('bank')];
        if ($coop === 'zlecenie') {
            $basis = $t('pay_basis');
            $from = $date('date_from');
            $to = $date('date_to');
            if ($pesel === '' || $t('address') === '' || $from === '' || !isset(DriverContractService::PAY[$basis]) || $amount === null || $t('licence') === '') {
                $errors[] = 'Kierowca na zlecenie : PESEL, adres, prawo jazdy, wynagrodzenie (podstawa i kwota) i początek umowy są potrzebne do umowy.';
            }
            if ($from !== '' && $to !== '' && $to < $from) $errors[] = 'Koniec umowy przed początkiem.';
            if ($errors) throw new CustomException(implode(' ', array_unique($errors)), 'errors', 422);
            $hr = $common + ['contract_type' => 'zlecenie', 'hire_date' => $from, 'contract_end' => $to, 'student' => !empty($in['student'])];
            // Godzina → stawka godzinowa w karcie (lista płac) ; ryczałt → wynagrodzenie miesięczne ; kurs / km : tylko w umowie.
            if ($basis === 'godzina') $hr += ['pay_type' => 'hourly', 'pay_amount' => (string) $amount];
            if ($basis === 'ryczalt') $hr += ['pay_type' => 'monthly', 'pay_amount' => (string) $amount];
            return [$hr, ['licence' => $t('licence'), 'basis' => $basis, 'amount' => $amount]];
        }
        $contract = $t('contract_type');
        if (!in_array($contract, ['nieokreslony', 'okreslony', 'probny'], true)) $errors[] = 'Rodzaj umowy o pracę : na czas nieokreślony, określony albo okres próbny.';
        if ($date('hire_date') === '') $errors[] = 'Podaj datę zatrudnienia.';
        $date('birth_date'); $date('contract_end'); $date('medical_until');
        $payType = $t('pay_type');
        if ($payType !== '' && !in_array($payType, ['monthly', 'hourly'], true)) $errors[] = 'Wynagrodzenie : miesięcznie albo za godzinę.';
        if ($errors) throw new CustomException(implode(' ', array_unique($errors)), 'errors', 422);
        $hr = $common + ['contract_type' => $contract, 'hire_date' => $t('hire_date'), 'contract_end' => $t('contract_end'), 'birth_date' => $t('birth_date'),
            'gender' => $t('gender'), 'emergency' => $t('emergency'), 'clothing_size' => $t('clothing_size'), 'work_time' => $t('work_time'),
            'medical_until' => $t('medical_until'), 'pay_type' => $payType, 'pay_amount' => $amount !== null ? (string) $amount : '',
            'pit2' => !empty($in['pit2']), 'kup_raised' => !empty($in['kup_raised']), 'young_relief' => !empty($in['young_relief'])];
        return [$hr, ['licence' => $t('licence') ?: null, 'basis' => null, 'amount' => null]];
    }

    /** Klienci dnia → pojazd z floty (przydział dnia). @return int liczba przypisanych */
    public function assign(array $in, ?int $userId, ?\DateTimeImmutable $now = null): int
    {
        $date = (string) ($in['date'] ?? '');
        if (!in_array($date, $this->dates($now), true)) throw new CustomException('Ten dzień wysyłki nie jest w asystencie (dziś i jutro).', 'errors', 422);
        $vehicle = (int) ($in['vehicle_id'] ?? 0);
        if ($vehicle <= 0) throw new CustomException('Wybierz transport.', 'errors', 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['customers'] ?? [])), fn($i) => $i > 0)));
        if (!$ids) throw new CustomException('Zaznacz klientów.', 'errors', 422);
        $day = $this->logistics->day($date);
        $fleetIds = array_map(fn($v) => (int) $v['id'], $day['fleet']);
        if (!in_array($vehicle, $fleetIds, true)) throw new CustomException('Ten pojazd nie jest we flocie.', 'errors', 422);
        $known = array_map(fn($c) => (int) $c['id'], $day['customers']);
        foreach ($ids as $cid) {
            if (!in_array($cid, $known, true)) throw new CustomException('Ten klient nie ma zamówień w tym dniu.', 'errors', 422);
            $this->logistics->assign(['date' => $date, 'customer_id' => $cid, 'vehicle_id' => $vehicle], $userId);
        }
        return count($ids);
    }
}
