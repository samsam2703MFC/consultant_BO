<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Core\TransactionManager;
use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsPlanRepository;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Plan logistyki na 12 tygodni [200] (Logistyka i pakowanie › Plan 12 tygodni).
 *
 * Palety klienta na tydzień (palety liczone jak w dniu wysyłki : PalletCalculator na każdą wysyłkę, potem suma tygodnia) :
 * zamówienia już złożone na ten tydzień ; inaczej palety wpisane w Prognozie ; inaczej palety tego samego tygodnia ISO
 * rok temu (N-1) × współczynnik klienta (wpisany albo trend : średnio na tydzień z wysyłką w ostatnich 3 miesiącach ÷
 * w tych samych tygodniach rok temu ; bez trendu : logistics.plan_coef_default) ; bez N-1 w tym tygodniu : średnia
 * z ostatnich 13 tygodni (logistics.plan_avg_weeks). Prognoza × dodatkowe palety tygodnia (% wydarzenia, także co
 * roku), zaokrąglenie w górę z tolerancją 0,2 palety. Klienci planu : z wysyłkami w ostatnich 3 miesiącach albo
 * z zamówieniem / wpisem w tygodniach planu.
 *
 * Wyjazd : zwykły dzień (logistics.plan_ship_weekday), przesunięty, gdy to święto w kraju wyjazdu albo dzień dostawy
 * (wyjazd + logistics.plan_delivery_days) to święto w kraju dostawy lub niedziela — najpierw wcześniej w tygodniu,
 * potem później ; albo dzień wybrany ręcznie. Ciężarówki : aktywne pojazdy (miejsca paletowe ; bez danych —
 * logistics.default_pallet_places), kierowca pojazdu albo na zastępstwo, urlop kierowcy w dniu wyjazdu = ciężarówka
 * bez kierowcy. Zatwierdzenie tygodnia : pojazd klienta na dzień wyjazdu (Dzień wysyłki, Załadunek), gdy nie wybrano
 * go tam ręcznie. Zmiana w zatwierdzonym tygodniu : zatwierdzenie do ponowienia.
 */
final class LogisticsPlanService
{
    public const COLORS = ['#8D1D2C', '#2F6FB0', '#2E7D4F', '#9A6212', '#6B5B95', '#B5476B', '#1F7A7A', '#7A5C2E', '#4E6E2E', '#5B5F97', '#A8552B', '#3D6B8C'];
    private const TOLERANCE = 0.2;
    private const COEF_MIN = 0.3;
    private const COEF_MAX = 5.0;

    /** Dzień dzisiejszy (testy) ; null = data serwera. */
    public ?string $today = null;

    public function __construct(
        private LogisticsPlanRepository $repo,
        private LogisticsRepository $logi,
        private ParamRepository $params,
        private TransactionManager $tx,
        private LogisticsTripService $trips,
    ) {
    }

    /** Plan od tygodnia daty $from (domyślnie : następny tydzień). */
    public function plan(?string $from = null, ?string $today = null): array
    {
        $today ??= $this->today ?? date('Y-m-d');
        $start = $from !== null && $from !== '' ? self::monday(self::date($from)) : self::monday(date('Y-m-d', strtotime($today . ' +7 days')));
        return $this->compute($start, $today);
    }

    // --- Zapis -------------------------------------------------------------------------------------------

    /**
     * Klienci w tygodniach : [{week_start, customer_id, vehicle_id?, pallets?}] — tylko podane pola (vehicle_id null =
     * do rozplanowania, pallets null / '' = prognoza).
     */
    public function saveLoads(array $items, ?int $userId, ?string $from = null): array
    {
        if (!$items || count($items) > 500) throw new CustomException('Brak zmian do zapisania.', 'errors', 422);
        $vehicles = array_column($this->logi->vehicles(), null, 'id');
        $rows = [];
        foreach (array_values($items) as $i => $it) {
            if (!is_array($it)) throw new CustomException('Nieprawidłowe dane.', 'errors', 422);
            $week = self::weekStart((string) ($it['week_start'] ?? ''));
            $customer = filter_var($it['customer_id'] ?? null, FILTER_VALIDATE_INT);
            if ($customer === false || !$this->logi->customerExists((int) $customer)) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
            $data = [];
            if (array_key_exists('vehicle_id', $it)) {
                $v = $it['vehicle_id'];
                if ($v === null || $v === '') $data['id_carrier_vehicle'] = null;
                else {
                    $v = filter_var($v, FILTER_VALIDATE_INT);
                    if ($v === false || !isset($vehicles[$v])) throw new CustomException('Nie znaleziono ciężarówki.', 'errors', 404);
                    $data['id_carrier_vehicle'] = $v;
                }
            }
            if (array_key_exists('pallets', $it)) {
                $p = $it['pallets'];
                if ($p === null || $p === '') $data['pallets'] = null;
                else {
                    $p = filter_var($p, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
                    if ($p === false) throw new CustomException('Palety : liczba od 0 do 999.', 'errors', 422, ['fields' => ["items.{$i}.pallets" => 'Liczba od 0 do 999.']]);
                    $data['pallets'] = $p;
                }
            }
            if (!$data) continue;
            $rows[] = [$week, (int) $customer, $data];
        }
        $this->tx->execute(function () use ($rows, $userId) {
            foreach ($rows as [$week, $customer, $data]) {
                $this->repo->saveLoad($week, $customer, $data, $userId);
                $this->repo->unapprove($week);
            }
        });
        return $this->plan($from);
    }

    /** Dzień wyjazdu tygodnia (null = domyślny, przesunięty przy święcie). */
    public function saveWeek(string $week, array $input, ?int $userId, ?string $from = null): array
    {
        $week = self::weekStart($week);
        $ship = $input['ship_date'] ?? null;
        if ($ship !== null && $ship !== '') {
            $ship = self::date((string) $ship);
            if ($ship < $week || $ship > date('Y-m-d', strtotime($week . ' +6 days'))) throw new CustomException('Dzień wyjazdu : w tym samym tygodniu.', 'errors', 422);
        } else $ship = null;
        $this->tx->execute(function () use ($week, $ship, $userId) {
            $this->repo->saveWeek($week, ['ship_date' => $ship], $userId);
            $this->repo->unapprove($week);
        });
        return $this->plan($from);
    }

    /** Ciężarówka w tygodniu : kierowca na zastępstwo (null = kierowca pojazdu), niedostępna. */
    public function saveVehicleWeek(string $week, int $vehicleId, array $input, ?int $userId, ?string $from = null): array
    {
        $week = self::weekStart($week);
        if (!$this->logi->vehicleExists($vehicleId)) throw new CustomException('Nie znaleziono ciężarówki.', 'errors', 404);
        $driver = $input['driver_id'] ?? null;
        if ($driver !== null && $driver !== '') {
            $driver = filter_var($driver, FILTER_VALIDATE_INT);
            if ($driver === false || !$this->repo->employeeExists($driver)) throw new CustomException('Nie znaleziono kierowcy.', 'errors', 404);
        } else $driver = null;
        $off = filter_var($input['unavailable'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $trips = $input['trips'] ?? null;
        if ($trips !== null && $trips !== '') {
            $trips = filter_var($trips, FILTER_VALIDATE_INT);
            if ($trips === false || $trips < 1 || $trips > 4) throw new CustomException('Tras w tygodniu : od 1 do 4.', 'errors', 422);
        } else $trips = null;
        $this->tx->execute(function () use ($week, $vehicleId, $driver, $off, $trips, $userId) {
            $this->repo->saveVehicleWeek($week, $vehicleId, $driver, $off, $userId, $trips);
            $this->repo->unapprove($week);
        });
        return $this->plan($from);
    }

    /** Tras (zmian) ciężarówki z kierowcą w tygodniu — domyślnie dla wszystkich tygodni [208] (standard 1). */
    public function setVehicleTrips(int $vehicleId, mixed $trips, ?int $userId, ?string $from = null): array
    {
        $n = filter_var($trips, FILTER_VALIDATE_INT);
        if ($n === false || $n < 1 || $n > 4) throw new CustomException('Tras w tygodniu : od 1 do 4.', 'errors', 422);
        if (!$this->repo->setVehicleTrips($vehicleId, $n, $userId)) throw new CustomException('Najpierw ustaw ciężarówkę w Pojazdach i pojemności.', 'errors', 404);
        return $this->plan($from);
    }

    /** Współczynnik klienta (null = trend 3 miesięcy) ; $customerId null = dla wszystkich klientów planu. */
    public function saveCoef(?int $customerId, mixed $coef, ?int $userId, ?string $from = null): array
    {
        $value = null;
        if ($coef !== null && $coef !== '') {
            $value = self::number($coef);
            if ($value === null || $value < self::COEF_MIN || $value > self::COEF_MAX) {
                throw new CustomException('Współczynnik : od 0,3 do 5.', 'errors', 422, ['fields' => ['coef' => 'Od 0,3 do 5.']]);
            }
            $value = round($value, 2);
        }
        if ($customerId !== null && !$this->logi->customerExists($customerId)) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
        $ids = $customerId !== null ? [$customerId] : array_column($this->plan($from)['customers'], 'id');
        $this->tx->execute(function () use ($ids, $value, $userId) {
            foreach ($ids as $id) $this->repo->saveCoef((int) $id, $value, $userId);
        });
        return $this->plan($from);
    }

    /** Dodatkowe palety tygodnia : {week_start, name, pct, yearly} ; pct 0 = brak (np. zamiast wydarzenia z zeszłego roku). */
    public function saveEvent(array $input, ?int $userId, ?string $from = null): array
    {
        $week = self::weekStart((string) ($input['week_start'] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
        $f = [];
        if ($name === '' || mb_strlen($name) > 80) $f['name'] = 'Nazwa : od 1 do 80 znaków.';
        $pct = self::number($input['pct'] ?? null);
        if ($pct === null || $pct < -90 || $pct > 300) $f['pct'] = 'Od −90 % do +300 %.';
        if ($f) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $f]);
        $yearly = filter_var($input['yearly'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->tx->execute(function () use ($week, $name, $pct, $yearly, $userId) {
            $this->repo->saveEvent($week, $name, round($pct, 1), $yearly, $userId);
            $this->repo->unapprove($week);
        });
        return $this->plan($from);
    }

    public function deleteEvent(int $id, ?string $from = null): array
    {
        $e = $this->repo->event($id) ?? throw new CustomException('Nie znaleziono wydarzenia.', 'errors', 404);
        $this->tx->execute(function () use ($e, $id) {
            $this->repo->deleteEvent($id);
            $this->repo->unapprove($e['week_start']);
        });
        return $this->plan($from);
    }

    /** Urlop kierowcy : {employee_id, date_from, date_to, reason}. */
    public function addAbsence(array $input, ?int $userId, ?string $from = null): array
    {
        $f = [];
        $emp = filter_var($input['employee_id'] ?? null, FILTER_VALIDATE_INT);
        if ($emp === false || !$this->repo->employeeExists((int) $emp)) $f['employee_id'] = 'Wybierz kierowcę.';
        $a = self::dateOrNull($input['date_from'] ?? null);
        $b = self::dateOrNull($input['date_to'] ?? null) ?? $a;
        if ($a === null) $f['date_from'] = 'Podaj datę.';
        elseif ($b < $a) $f['date_to'] = 'Koniec po początku.';
        elseif ((strtotime($b) - strtotime($a)) / 86400 > 366) $f['date_to'] = 'Najwyżej rok.';
        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) > 120) $f['reason'] = 'Do 120 znaków.';
        if ($f) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $f]);
        $this->repo->addAbsence((int) $emp, $a, $b, $reason !== '' ? $reason : null, $userId);
        return $this->plan($from);
    }

    public function deleteAbsence(int $id, ?string $from = null): array
    {
        if (!$this->repo->deleteAbsence($id)) throw new CustomException('Nie znaleziono urlopu.', 'errors', 404);
        return $this->plan($from);
    }

    /** Dzień wolny : {day, country, name, off} — off false = święto ustawowe pominięte ; remove = przywróć automat. */
    public function saveHoliday(array $input, ?int $userId, ?string $from = null): array
    {
        $day = self::date((string) ($input['day'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        if (!in_array($country, Holidays::COUNTRIES, true)) throw new CustomException('Kraj : ' . implode(', ', Holidays::COUNTRIES) . '.', 'errors', 422, ['fields' => ['country' => implode(', ', Holidays::COUNTRIES) . '.']]);
        if (filter_var($input['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->repo->deleteHoliday($day, $country);
            return $this->plan($from);
        }
        $name = trim((string) ($input['name'] ?? ''));
        $auto = Holidays::forYear((int) substr($day, 0, 4), $country)[$day] ?? null;
        if ($name === '') $name = $auto ?? '';
        if ($name === '' || mb_strlen($name) > 80) throw new CustomException('Podaj nazwę dnia wolnego (do 80 znaków).', 'errors', 422, ['fields' => ['name' => 'Do 80 znaków.']]);
        $off = filter_var($input['off'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->repo->saveHoliday($day, $country, $name, $off, $userId);
        return $this->plan($from);
    }

    /**
     * Rozdziel automatycznie klientów bez ciężarówki (tygodnie niezatwierdzone) : ciężarówka z poprzedniego tygodnia,
     * z trasy klienta albo z ostatniego CMR, gdy jest dostępna i palety się mieszczą ; inaczej najpierw ciężarówki już
     * jadące, potem z największą liczbą wolnych miejsc. Klient się nie mieści : zostaje do rozplanowania.
     */
    public function autoDispatch(?int $userId, ?string $from = null): array
    {
        $plan = $this->plan($from);
        $tours = $this->tourVehicles();
        $last = $this->logi->lastVehicles(array_column($plan['customers'], 'id'));
        $prev = [];
        $writes = [];
        $placed = 0;
        $left = 0;
        foreach ($plan['weeks'] as $w) {
            $ws = $w['week_start'];
            $free = [];
            $places = [];
            foreach ($plan['vehicles'] as $v) {
                $cell = $v['cells'][$ws];
                if (!$cell['usable']) continue;
                $free[$v['id']] = $cell['places'] - $cell['pallets'];
                $places[$v['id']] = $cell['places'];
            }
            $todo = [];
            foreach ($plan['customers'] as $c) {
                $cell = $c['cells'][$ws];
                if ($cell['vehicle_id'] !== null) { $prev[$c['id']] = $cell['vehicle_id']; continue; }
                if ($cell['pallets'] > 0) $todo[] = [$c['id'], $cell['pallets']];
            }
            if ($w['approved_at'] !== null) continue;
            usort($todo, fn($a, $b) => $b[1] <=> $a[1]);
            $weekday = (int) date('N', strtotime($w['ship_date']));
            foreach ($todo as [$cid, $pallets]) {
                $pref = array_values(array_unique(array_filter([$prev[$cid] ?? null, $tours[$cid][$weekday] ?? null, $last[$cid] ?? null], fn($x) => $x !== null)));
                // Najpierw ciężarówki już jadące w tym tygodniu, potem najwięcej wolnych miejsc.
                $order = array_keys($free);
                usort($order, fn($a, $b) => [(int) ($free[$b] < $places[$b]), $free[$b], -$a] <=> [(int) ($free[$a] < $places[$a]), $free[$a], -$b]);
                $chosen = null;
                foreach ([...$pref, ...$order] as $vid) {
                    if (isset($free[$vid]) && $free[$vid] >= $pallets) { $chosen = $vid; break; }
                }
                if ($chosen === null) { $left++; continue; }
                $free[$chosen] -= $pallets;
                $prev[$cid] = $chosen;
                $writes[] = [$ws, $cid, $chosen];
                $placed++;
            }
        }
        $this->tx->execute(function () use ($writes, $userId) {
            foreach ($writes as [$ws, $cid, $vid]) $this->repo->saveLoad($ws, $cid, ['id_carrier_vehicle' => $vid], $userId);
        });
        $out = $this->plan($from);
        $out['result'] = ['placed' => $placed, 'left' => $left];
        return $out;
    }

    /**
     * « Wszystko do rozplanowania » [202] : wszyscy klienci zdjęci z ciężarówek w tygodniach planu od dziś (wyjazd ≥ dziś) ;
     * palety wpisane ręcznie zostają. Tygodnie zatwierdzone : do ponownego zatwierdzenia, pojazdy dnia zapisane z planu
     * zdjęte (wybory ręczne w Dniu wysyłki bez zmian).
     */
    public function resetLoads(?int $userId, ?string $from = null): array
    {
        $today = $this->today ?? date('Y-m-d');
        $plan = $this->plan($from);
        $weeks = array_values(array_filter($plan['weeks'], fn($w) => $w['ship_date'] >= $today));
        $cleared = 0;
        $days = 0;
        $unapproved = 0;
        if ($weeks) {
            $this->tx->execute(function () use ($weeks, $today, &$cleared, &$days, &$unapproved) {
                $cleared = $this->repo->clearVehicles(array_column($weeks, 'week_start'));
                foreach ($weeks as $w) {
                    if ($w['approved_at'] === null) continue;
                    $this->repo->unapprove($w['week_start']);
                    $unapproved++;
                }
                $days = $this->repo->clearPlanAssignments(max($today, $weeks[0]['week_start']), date('Y-m-d', strtotime(end($weeks)['week_start'] . ' +6 days')));
            });
        }
        $out = $this->plan($from);
        $out['result'] = ['cleared' => $cleared, 'weeks' => count($weeks), 'unapproved' => $unapproved, 'days' => $days];
        return $out;
    }

    /** Układ tygodnia (klient → ciężarówka) na następne tygodnie planu — tylko klienci jeszcze bez ciężarówki. */
    public function copyWeek(string $week, ?int $userId, ?string $from = null): array
    {
        $week = self::weekStart($week);
        $plan = $this->plan($from);
        $source = [];
        foreach ($plan['customers'] as $c) if (($c['cells'][$week]['vehicle_id'] ?? null) !== null) $source[$c['id']] = $c['cells'][$week]['vehicle_id'];
        if (!$source) throw new CustomException('W tym tygodniu nikt nie jest jeszcze na ciężarówce.', 'errors', 422);
        $writes = [];
        foreach ($plan['weeks'] as $w) {
            $ws = $w['week_start'];
            if ($ws <= $week || $w['approved_at'] !== null) continue;
            $usable = [];
            foreach ($plan['vehicles'] as $v) if ($v['cells'][$ws]['usable']) $usable[$v['id']] = true;
            foreach ($plan['customers'] as $c) {
                $cell = $c['cells'][$ws];
                if ($cell['vehicle_id'] === null && $cell['pallets'] > 0 && isset($source[$c['id']], $usable[$source[$c['id']]])) $writes[] = [$ws, $c['id'], $source[$c['id']]];
            }
        }
        $this->tx->execute(function () use ($writes, $userId) {
            foreach ($writes as [$ws, $cid, $vid]) $this->repo->saveLoad($ws, $cid, ['id_carrier_vehicle' => $vid], $userId);
        });
        $out = $this->plan($from);
        $out['result'] = ['placed' => count($writes)];
        return $out;
    }

    /**
     * Zatwierdź tydzień ($week) albo wszystkie tygodnie planu ($week null) : pojazdy klientów na dzień wyjazdu
     * (Dzień wysyłki, Załadunek), gdy nie wybrano ich tam ręcznie.
     */
    public function approve(?string $week, ?int $userId, ?string $from = null): array
    {
        $week = $week !== null ? self::weekStart($week) : null;
        $plan = $this->plan($from ?? $week);
        $saved = 0;
        $kept = 0;
        $weeks = 0;
        // Dzień wyjazdu klienta : wyjazd trasy, która go rozładowuje (2. trasa tygodnia [208] wyjeżdża później).
        $tripDay = [];
        foreach ($plan['trips'] ?? [] as $t) {
            foreach ($t['phases'] as $p) if ($p['k'] === 'unload' && isset($p['client_id'])) $tripDay[$t['week_start']][$t['vehicle_id']][(int) $p['client_id']] ??= substr($t['start_at'], 0, 10);
        }
        $this->tx->execute(function () use ($plan, $week, $userId, $tripDay, &$saved, &$kept, &$weeks) {
            foreach ($plan['weeks'] as $w) {
                if ($week !== null && $w['week_start'] !== $week) continue;
                foreach ($plan['customers'] as $c) {
                    $cell = $c['cells'][$w['week_start']];
                    if ($cell['vehicle_id'] === null || $cell['pallets'] <= 0) continue;
                    $day = $tripDay[$w['week_start']][$cell['vehicle_id']][$c['id']] ?? $w['ship_date'];
                    $this->repo->planAssignment($day, $c['id'], $cell['vehicle_id'], $userId) ? $saved++ : $kept++;
                }
                $this->repo->saveWeek($w['week_start'], ['approved_at' => date('Y-m-d H:i:s'), 'approved_by' => $userId], $userId);
                $weeks++;
            }
        });
        if (!$weeks) throw new CustomException('Tego tygodnia nie ma w planie.', 'errors', 404);
        $out = $this->plan($from ?? $week);
        $out['result'] = ['weeks' => $weeks, 'saved' => $saved, 'kept' => $kept];
        return $out;
    }

    // --- Obliczenia --------------------------------------------------------------------------------------

    private function compute(string $start, string $today): array
    {
        $n = (int) $this->param('logistics.plan_weeks', '12', 4, 26);
        $shipWeekday = (int) $this->param('logistics.plan_ship_weekday', '2', 1, 5);
        $deliveryDays = (int) $this->param('logistics.plan_delivery_days', '1', 0, 3);
        $avgWeeks = (int) $this->param('logistics.plan_avg_weeks', '13', 4, 26);
        $coefDefault = $this->param('logistics.plan_coef_default', '1', self::COEF_MIN, self::COEF_MAX);
        $depCountry = strtoupper($this->params->get('logistics.plan_departure_country', 'PL'));
        $delCountry = strtoupper($this->params->get('logistics.plan_delivery_country', 'BE'));
        $transit = $this->transitCountries([$depCountry, $delCountry]);
        $defaultPlaces = max(1, (int) $this->params->get('logistics.default_pallet_places', '33'));

        $weeks = [];
        for ($i = 0; $i < $n; $i++) $weeks[] = date('Y-m-d', strtotime($start . " +{$i} weeks"));
        $end = date('Y-m-d', strtotime(end($weeks) . ' +6 days'));
        $current = self::monday($today);
        $avgFrom = date('Y-m-d', strtotime($current . " -{$avgWeeks} weeks"));
        $avgMondays = [];
        for ($i = 0; $i < $avgWeeks; $i++) $avgMondays[] = date('Y-m-d', strtotime($avgFrom . " +{$i} weeks"));
        $n1 = [];
        foreach ([...$weeks, ...$avgMondays] as $m) $n1[$m] = self::lastYear($m);
        $histFrom = min([...array_values($n1), $avgFrom]);

        // Palety : każda wysyłka (klient, dzień) przez PalletCalculator, potem suma tygodnia.
        $mixed = $this->params->get('logistics.mixed_pallets', '1') === '1';
        $tare = (float) $this->params->get('logistics.pallet_tare_kg', '25');
        $leftover = max(0.0, min(1.0, (float) $this->params->get('logistics.leftover_fill_max', '0.5')));
        $ships = [];
        foreach ($this->repo->orderLines($histFrom, $end) as $l) {
            if ((int) $l['cpp'] === 1 && (int) $l['cpl'] <= 1 && (int) $l['lpp'] <= 1) $l['cpp'] = 0; // karta « 1 / 1 / 1 » = brak danych
            $ships[(int) $l['id_customer'] . '|' . $l['ship_date']][] = $l;
        }
        $weekly = [];
        $cartons = [];
        foreach ($ships as $key => $lines) {
            [$cid, $day] = explode('|', $key);
            $r = PalletCalculator::compute($lines, $mixed, $tare, $leftover);
            $m = self::monday($day);
            $weekly[(int) $cid][$m] = ($weekly[(int) $cid][$m] ?? 0) + $r['pallets'];
            $cartons[(int) $cid][$m] = ($cartons[(int) $cid][$m] ?? 0) + $r['cartons'];
        }

        $loads = [];
        foreach ($this->repo->loads($weeks[0], end($weeks)) as $l) $loads[$l['id_customer']][$l['week_start']] = $l;

        // Klienci planu : wysyłki w ostatnich 3 miesiącach, zamówienie albo wpis w tygodniach planu.
        $ids = [];
        foreach ($weekly as $cid => $byWeek) {
            foreach ($byWeek as $m => $p) if ($p > 0 && (($m >= $avgFrom && $m < $current) || ($m >= $weeks[0] && $m <= end($weeks)))) { $ids[$cid] = true; break; }
        }
        foreach (array_keys($loads) as $cid) $ids[$cid] = true;
        $info = $this->repo->customers(array_keys($ids));
        $manualCoef = $this->repo->coefs();

        // Tygodnie : święta, wyjazd, wydarzenie.
        $manualHolidays = $this->repo->holidays($weeks[0], date('Y-m-d', strtotime($end . ' +7 days')));
        $holidays = Holidays::between($weeks[0], date('Y-m-d', strtotime($end . ' +7 days')), array_values(array_unique([$depCountry, $delCountry, ...$transit])), $manualHolidays);
        $saved = $this->repo->weeks($weeks[0], end($weeks));
        $events = $this->repo->events();
        $weekRows = [];
        foreach ($weeks as $ws) {
            $row = $saved[$ws] ?? null;
            [$ship, $reason, $stuck] = $this->shipDate($ws, $shipWeekday, $deliveryDays, $holidays, $depCountry, $delCountry, $transit);
            $manual = $row !== null && $row['ship_date'] !== null;
            $shipDate = $manual ? $row['ship_date'] : $ship;
            $warnings = [];
            if ($manual && ($why = $this->blocked($shipDate, $deliveryDays, $holidays, $depCountry, $delCountry, $transit)) !== null) $warnings[] = 'Wyjazd ' . self::dm($shipDate) . ' : ' . $why . '.';
            if (!$manual && $stuck) $warnings[] = $reason . '.';
            $hol = [];
            for ($d = 0; $d < 7; $d++) {
                $day = date('Y-m-d', strtotime($ws . " +{$d} days"));
                foreach ($holidays[$day] ?? [] as $c => $name) $hol[] = ['date' => $day, 'country' => $c, 'name' => $name];
            }
            $weekRows[$ws] = [
                'week_start' => $ws, 'iso_week' => (int) date('W', strtotime($ws)), 'iso_year' => (int) date('o', strtotime($ws)),
                'ship_date' => $shipDate, 'ship_default' => $ship, 'ship_manual' => $manual, 'shifted' => !$manual && $reason !== null ? $reason : null,
                'delivery_date' => date('Y-m-d', strtotime($shipDate . " +{$deliveryDays} days")), 'holidays' => $hol, 'event' => $this->eventFor($ws, $events),
                'approved_at' => $row['approved_at'] ?? null, 'warnings' => $warnings, 'n1_week' => $n1[$ws],
            ];
        }

        // Klienci : prognoza na tydzień.
        $customers = [];
        foreach ($info as $cid => $c) {
            $byWeek = $weekly[$cid] ?? [];
            $avgSum = 0;
            $thisActive = [];
            $lastActive = [];
            foreach ($avgMondays as $m) {
                $p = $byWeek[$m] ?? 0;
                $avgSum += $p;
                if ($p > 0) $thisActive[] = $p;
                $q = $byWeek[$n1[$m]] ?? 0;
                if ($q > 0) $lastActive[] = $q;
            }
            $avg = $avgSum / $avgWeeks;
            $trend = $thisActive && $lastActive ? max(self::COEF_MIN, min(self::COEF_MAX, round((array_sum($thisActive) / count($thisActive)) / (array_sum($lastActive) / count($lastActive)), 2))) : null;
            $coef = $manualCoef[$cid] ?? $trend ?? $coefDefault;
            $cells = [];
            $n1Any = false;
            foreach ($weeks as $ws) {
                $ordered = $byWeek[$ws] ?? 0;
                $last = $byWeek[$n1[$ws]] ?? 0;
                if ($last > 0) $n1Any = true;
                $load = $loads[$cid][$ws] ?? null;
                $pct = $weekRows[$ws]['event']['pct'] ?? 0.0;
                $value = null;
                if ($ordered > 0) { $source = 'orders'; $pallets = $ordered; }
                elseif ($load !== null && $load['pallets'] !== null) { $source = 'manual'; $pallets = $load['pallets']; }
                elseif ($last > 0) { $source = 'n1'; $value = $last * $coef * (1 + $pct / 100); $pallets = self::roundUp($value); }
                elseif ($avg > 0) { $source = 'avg'; $value = $avg * (1 + $pct / 100); $pallets = self::roundUp($value); }
                else { $source = 'none'; $pallets = 0; }
                $cells[$ws] = ['pallets' => $pallets, 'value' => $value !== null ? round($value, 2) : null, 'source' => $source, 'n1' => $last, 'ordered' => $ordered,
                    'cartons' => round((float) ($cartons[$cid][$ws] ?? 0), 1), 'manual' => $load['pallets'] ?? null, 'vehicle_id' => $load['id_carrier_vehicle'] ?? null];
            }
            $customers[] = ['id' => $cid, 'name' => $c['name'], 'short' => self::short($c['name']), 'town' => $c['town'], 'country' => $c['country'],
                'avg' => round($avg, 2), 'trend' => $trend, 'coef' => round($coef, 2), 'coef_manual' => $manualCoef[$cid] ?? null, 'n1_any' => $n1Any, 'cells' => $cells];
        }
        usort($customers, fn($a, $b) => [-$a['avg'], $a['name']] <=> [-$b['avg'], $b['name']]);
        foreach ($customers as $i => &$c) $c['color'] = self::COLORS[$i % count(self::COLORS)];
        unset($c);

        // Ciężarówki : aktywne pojazdy i pojazdy z klientami w planie.
        $drivers = $this->repo->drivers();
        $absences = $this->repo->absences($weeks[0], $end);
        $vehicleWeeks = $this->repo->vehicleWeeks($weeks[0], end($weeks));
        $used = [];
        foreach ($customers as $c) foreach ($c['cells'] as $cell) if ($cell['vehicle_id'] !== null) $used[$cell['vehicle_id']] = true;
        $vehicles = [];
        foreach ($this->logi->vehicles() as $v) {
            $vid = (int) $v['id'];
            if (!(int) $v['active'] && !isset($used[$vid])) continue;
            $places = $v['pallet_places'] !== null ? (int) $v['pallet_places'] : $defaultPlaces;
            $defaultDriver = $v['id_driver'] !== null && isset($drivers[(int) $v['id_driver']])
                ? ['id' => (int) $v['id_driver'], 'name' => $drivers[(int) $v['id_driver']]['name'], 'external' => false]
                : ['id' => null, 'name' => trim((string) $v['driver']) ?: null, 'external' => true];
            $cells = [];
            foreach ($weeks as $ws) {
                $vw = $vehicleWeeks[$ws][$vid] ?? ['id_driver' => null, 'unavailable' => false, 'trips' => null];
                // Tras (zmian) w tygodniu [208] : wyjątek tygodnia albo ciężarówka (standard 1) ; miejsca tygodnia = miejsca × trasy.
                $trips = max(1, min(4, (int) ($vw['trips'] ?? 0) ?: (int) ($v['trips_per_week'] ?? 1)));
                $driver = $vw['id_driver'] !== null && isset($drivers[$vw['id_driver']])
                    ? ['id' => $vw['id_driver'], 'name' => $drivers[$vw['id_driver']]['name'], 'external' => false] : $defaultDriver;
                $absent = null;
                if ($driver['id'] !== null) {
                    foreach ($absences as $a) {
                        if ($a['id_employee'] === $driver['id'] && $a['date_from'] <= $weekRows[$ws]['ship_date'] && $a['date_to'] >= $weekRows[$ws]['ship_date']) { $absent = ['reason' => $a['reason'], 'to' => $a['date_to']]; break; }
                    }
                }
                $pallets = 0;
                $list = [];
                foreach ($customers as $c) {
                    $cell = $c['cells'][$ws];
                    if ($cell['vehicle_id'] === $vid && $cell['pallets'] > 0) { $pallets += $cell['pallets']; $list[] = $c['id']; }
                }
                $cells[$ws] = ['driver' => $driver, 'driver_override' => $vw['id_driver'] !== null, 'absent' => $absent, 'unavailable' => $vw['unavailable'],
                    'usable' => (bool) (int) $v['active'] && !$vw['unavailable'] && $absent === null, 'places' => $places * $trips, 'places_per_trip' => $places,
                    'trips' => $trips, 'trips_override' => ($vw['trips'] ?? null) !== null, 'pallets' => $pallets, 'customers' => $list,
                    'fill_pct' => $places > 0 ? round($pallets / ($places * $trips) * 100) : null];
            }
            $vehicles[] = ['id' => $vid, 'label' => trim((string) $v['regis_num']) ?: ('#' . $vid), 'carrier' => $v['carrier'], 'places' => $places,
                'places_known' => $v['pallet_places'] !== null, 'active' => (bool) (int) $v['active'], 'driver' => $defaultDriver, 'departure' => $v['departure_time'],
                'trips_per_week' => max(1, (int) ($v['trips_per_week'] ?? 1)), 'cells' => $cells];
        }

        // Sumy tygodni.
        foreach ($weekRows as $ws => &$w) {
            $total = 0;
            $assigned = 0;
            $unCustomers = 0;
            foreach ($customers as $c) {
                $cell = $c['cells'][$ws];
                $total += $cell['pallets'];
                if ($cell['pallets'] <= 0) continue;
                if ($cell['vehicle_id'] !== null) $assigned += $cell['pallets'];
                else $unCustomers++;
            }
            $places = 0;
            foreach ($vehicles as $v) if ($v['cells'][$ws]['usable']) $places += $v['cells'][$ws]['places'];
            $w += ['pallets' => $total, 'assigned' => $assigned, 'unassigned' => $total - $assigned, 'unassigned_customers' => $unCustomers, 'places' => $places,
                'over' => $total > $places];
            foreach ($vehicles as $v) {
                $cell = $v['cells'][$ws];
                if ($cell['pallets'] > $cell['places']) $w['warnings'][] = $v['label'] . ' : ' . $cell['pallets'] . ' palet na ' . $cell['places'] . ' miejscach.';
                if ($cell['pallets'] > 0 && !$cell['usable']) $w['warnings'][] = $v['label'] . ($cell['absent'] ? ' : kierowca na urlopie (' . $cell['driver']['name'] . ').' : ' : niedostępna w tym tygodniu.');
            }
        }
        unset($w);

        $totals = array_column($weekRows, 'pallets');
        $peak = $totals ? array_keys($totals, max($totals))[0] : 0;
        $holidayDays = [];
        foreach ($weekRows as $w) foreach ($w['holidays'] as $h) if ((int) date('N', strtotime($h['date'])) <= 5) $holidayDays[$h['date']] = true;
        $absentDrivers = [];
        foreach ($vehicles as $v) foreach ($v['cells'] as $cell) if ($cell['absent']) $absentDrivers[$cell['driver']['id']] = true;
        $rows = array_values($weekRows);

        return [
            'range' => ['from' => $weeks[0], 'to' => $end, 'prev' => date('Y-m-d', strtotime($weeks[0] . ' -4 weeks')), 'next' => date('Y-m-d', strtotime($weeks[0] . ' +4 weeks')),
                'current' => $current, 'default_start' => self::monday(date('Y-m-d', strtotime($today . ' +7 days')))],
            'params' => ['weeks' => $n, 'ship_weekday' => $shipWeekday, 'delivery_days' => $deliveryDays, 'avg_weeks' => $avgWeeks, 'coef_default' => $coefDefault,
                'departure_country' => $depCountry, 'delivery_country' => $delCountry, 'transit_countries' => $transit, 'default_places' => $defaultPlaces],
            'weeks' => $rows,
            'customers' => $customers,
            'vehicles' => $vehicles,
            'drivers' => array_values(array_filter($drivers, fn($d) => $d['active'])),
            'absences' => array_map(fn($a) => $a + ['employee' => $drivers[$a['id_employee']]['name'] ?? ('#' . $a['id_employee'])], $this->repo->absences($today, date('Y-m-d', strtotime($end . ' +1 year')))),
            'holidays' => $this->holidayList($weeks[0], $end, [$depCountry, $delCountry, ...$transit], $holidays, $manualHolidays),
            // Kalendarz tras [207] : trasa każdej ciężarówki z klientami w tygodniu (szablon albo zmieniona ręcznie).
            'trips' => $this->trips->build($weekRows, $vehicles, $customers),
            'trip_template' => $this->trips->template(),
            'events' => array_values(array_filter($events, fn($e) => $e['yearly'] || ($e['week_start'] >= $weeks[0] && $e['week_start'] <= end($weeks)))),
            'kpis' => [
                'avg' => $rows ? round(array_sum($totals) / count($totals), 1) : 0.0, 'peak' => $rows ? $rows[$peak]['pallets'] : 0, 'peak_week' => $rows ? $rows[$peak]['iso_week'] : null,
                'total' => array_sum($totals), 'holiday_days' => count($holidayDays), 'absent_drivers' => count($absentDrivers),
                'unassigned' => array_sum(array_column($rows, 'unassigned')), 'approved' => count(array_filter($rows, fn($w) => $w['approved_at'] !== null)),
            ],
        ];
    }

    /** Lista świąt okresu dla zakładki : automatyczne, dodane i pominięte. */
    private function holidayList(string $from, string $to, array $countries, array $holidays, array $manual): array
    {
        $out = [];
        foreach ($holidays as $d => $byCountry) {
            if ($d < $from || $d > $to) continue;
            foreach ($byCountry as $c => $name) $out[] = ['date' => $d, 'country' => $c, 'name' => $name, 'source' => isset($manual[$d][$c]) ? 'manual' : 'auto', 'skipped' => false];
        }
        foreach ($manual as $d => $byCountry) {
            if ($d < $from || $d > $to) continue;
            foreach ($byCountry as $c => $m) if (!$m['off']) $out[] = ['date' => $d, 'country' => $c, 'name' => $m['name'], 'source' => 'auto', 'skipped' => true];
        }
        usort($out, fn($a, $b) => [$a['date'], $a['country']] <=> [$b['date'], $b['country']]);
        return array_values(array_filter($out, fn($h) => in_array($h['country'], $countries, true)));
    }

    /**
     * Wyjazd domyślny tygodnia : zwykły dzień, a gdy zablokowany (święto wyjazdu, święto / niedziela w dniu dostawy) —
     * najbliższy wcześniejszy dzień roboczy w tygodniu, potem późniejszy. @return array{0:string, 1:?string, 2:bool} data, powód, brak wolnego dnia
     */
    private function shipDate(string $monday, int $weekday, int $deliveryDays, array $holidays, string $dep, string $del, array $transit = []): array
    {
        $default = date('Y-m-d', strtotime($monday . ' +' . ($weekday - 1) . ' days'));
        $why = $this->blocked($default, $deliveryDays, $holidays, $dep, $del, $transit);
        if ($why === null) return [$default, null, false];
        $order = [];
        for ($d = $weekday - 1; $d >= 1; $d--) $order[] = $d;
        for ($d = $weekday + 1; $d <= 5; $d++) $order[] = $d;
        foreach ($order as $d) {
            $day = date('Y-m-d', strtotime($monday . ' +' . ($d - 1) . ' days'));
            if ($this->blocked($day, $deliveryDays, $holidays, $dep, $del, $transit) === null) return [$day, self::dm($default) . ' : ' . $why . ' → wyjazd ' . self::weekday($day) . ' ' . self::dm($day), false];
        }
        return [$default, self::dm($default) . ' : ' . $why . ' — brak wolnego dnia w tym tygodniu', true];
    }

    /**
     * Powód, dla którego dzień nie nadaje się na wyjazd, albo null. Kraje przejazdu (DE) : święto od dnia wyjazdu
     * do dnia dostawy = zakaz ruchu ciężarówek po drodze [202].
     */
    private function blocked(string $day, int $deliveryDays, array $holidays, string $dep, string $del, array $transit = []): ?string
    {
        if ((int) date('N', strtotime($day)) >= 6) return 'weekend';
        if (isset($holidays[$day][$dep])) return $holidays[$day][$dep] . ' (' . $dep . ')';
        $delivery = date('Y-m-d', strtotime($day . " +{$deliveryDays} days"));
        if (isset($holidays[$delivery][$del])) return 'dostawa ' . self::dm($delivery) . ' — ' . $holidays[$delivery][$del] . ' (' . $del . ')';
        for ($i = 0; $i <= $deliveryDays; $i++) {
            $d = date('Y-m-d', strtotime($day . " +{$i} days"));
            foreach ($transit as $c) if (isset($holidays[$d][$c])) return 'przejazd ' . self::dm($d) . ' — ' . $holidays[$d][$c] . ' (' . $c . ', zakaz ruchu ciężarówek)';
        }
        if ((int) date('N', strtotime($delivery)) === 7) return 'dostawa w niedzielę';
        return null;
    }

    /** Kraje przejazdu z parametru (np. « DE »), bez kraju wyjazdu i dostawy. @return list<string> */
    private function transitCountries(array $exclude): array
    {
        $raw = strtoupper((string) $this->params->get('logistics.plan_transit_countries', 'DE'));
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $c) {
            if (in_array($c, Holidays::COUNTRIES, true) && !in_array($c, $exclude, true) && !in_array($c, $out, true)) $out[] = $c;
        }
        return $out;
    }

    /** Wydarzenie tygodnia : wpisane na ten tydzień albo powtarzane co roku (ten sam tydzień ISO, najnowsze). */
    private function eventFor(string $week, array $events): ?array
    {
        $iso = (int) date('W', strtotime($week));
        $inherited = null;
        foreach ($events as $e) {
            if ($e['week_start'] === $week) return $e + ['inherited' => false];
            if ($e['yearly'] && $e['week_start'] < $week && (int) date('W', strtotime($e['week_start'])) === $iso) $inherited = $e;
        }
        return $inherited !== null ? $inherited + ['inherited' => true] : null;
    }

    /** Pojazd trasy klienta na dzień tygodnia : klient => [1..7 => pojazd]. */
    private function tourVehicles(): array
    {
        $tours = [];
        foreach ($this->logi->tours() as $t) if ((int) $t['active']) $tours[(int) $t['id']] = ['days' => (int) $t['weekdays'], 'vehicle' => null];
        foreach ($this->logi->tourVehicles() as $tv) {
            $tid = (int) $tv['id_tour'];
            if (isset($tours[$tid]) && $tours[$tid]['vehicle'] === null) $tours[$tid]['vehicle'] = (int) $tv['id_carrier_vehicle'];
        }
        $out = [];
        foreach ($this->logi->tourCustomers() as $tc) {
            $t = $tours[(int) $tc['id_tour']] ?? null;
            if ($t === null || $t['vehicle'] === null) continue;
            for ($d = 1; $d <= 7; $d++) if ($t['days'] & (1 << ($d - 1))) $out[(int) $tc['id_customer']][$d] ??= $t['vehicle'];
        }
        return $out;
    }

    private function param(string $code, string $default, float $min, float $max): float
    {
        $v = self::number($this->params->get($code, $default));
        return $v !== null && $v >= $min && $v <= $max ? $v : (float) $default;
    }

    // --- Pomocnicze ----------------------------------------------------------------------------------------

    /** Zaokrąglenie prognozy : w górę, z tolerancją 0,2 palety (10,2 → 10 ; 10,3 → 11 ; 0,15 → 0). */
    public static function roundUp(float $v): int
    {
        return max(0, (int) ceil($v - self::TOLERANCE - 1e-9));
    }

    /** Ten sam tydzień ISO rok wcześniej (tydzień 53 → 52, gdy rok wcześniej ma 52 tygodnie). */
    public static function lastYear(string $monday): string
    {
        $t = strtotime($monday);
        $year = (int) date('o', $t) - 1;
        $week = (int) date('W', $t);
        $weeks = (int) date('W', strtotime("{$year}-12-28"));
        $d = new \DateTimeImmutable();
        return $d->setISODate($year, min($week, $weeks))->format('Y-m-d');
    }

    public static function monday(string $date): string
    {
        $t = strtotime($date);
        return date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
    }

    /** Nazwa klienta bez formy prawnej (« Berdiff s.a. » → « Berdiff »). */
    public static function short(string $name): string
    {
        $s = trim((string) preg_replace('/[\s,]+(s\.?\s?a\.?|s\.?r\.?l\.?|s\.?p\.?r\.?l\.?|b\.?v\.?(b\.?a\.?)?|n\.?v\.?|sp\.\s*z\s*o\.?\s*o\.?|sp\.\s*k\.?|gmbh|sas|sarl|ltd)\.?$/iu', '', trim($name)));
        return $s !== '' ? $s : trim($name);
    }

    private static function weekStart(string $v): string
    {
        $d = self::date($v);
        if ((int) date('N', strtotime($d)) !== 1) throw new CustomException('Tydzień : data poniedziałku.', 'errors', 422);
        return $d;
    }

    private static function date(string $v): string
    {
        $v = trim($v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v) || date('Y-m-d', strtotime($v)) !== $v) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        return $v;
    }

    private static function dateOrNull(mixed $v): ?string
    {
        if ($v === null || trim((string) $v) === '') return null;
        return self::date((string) $v);
    }

    private static function dm(string $d): string
    {
        return date('j.m', strtotime($d));
    }

    private static function weekday(string $d): string
    {
        return LogisticsService::WEEKDAYS[(int) date('N', strtotime($d))];
    }

    private static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (!is_string($v)) return null;
        $v = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim($v));
        $v = str_replace('−', '-', $v);
        return is_numeric($v) ? (float) $v : null;
    }
}
