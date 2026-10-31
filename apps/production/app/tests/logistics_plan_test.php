<?php
declare(strict_types=1);
/*
 * Plan logistyki na 12 tygodni [200] : święta PL / BE (Wielkanoc, Wigilia, dni dodane / pominięte), zaokrąglenie
 * prognozy, ten sam tydzień rok temu (tydzień 53), skrót nazwy klienta ; na bazie jednorazowej : prognoza palet
 * (N-1 × trend albo współczynnik, bez N-1 średnia 3 miesięcy, zamówienia, wpisy ręczne, wydarzenia co roku),
 * wyjazd przesunięty przy święcie, urlop kierowcy, rozdział automatyczny, przeciąganie, kopiowanie układu,
 * zatwierdzenie (pojazdy na dzień wyjazdu, bez nadpisywania wyborów ręcznych), walidacja.
 *
 *   php tests/logistics_plan_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 126 appliquée). La base erp_logistics_plan_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Services\Logistics\LogisticsPlanService as Plan;

function expectPlan(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectPlanFail(callable $fn, int $code, string $message): CustomException
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectPlan((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return $e;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Bez bazy -------------------------------------------------------------------------------------------
expectPlan(Holidays::easter(2026) === '2026-04-05' && Holidays::easter(2027) === '2027-03-28' && Holidays::easter(2025) === '2025-04-20', 'Wielkanoc 2025–2027');
$pl = Holidays::forYear(2026, 'PL');
$be = Holidays::forYear(2026, 'BE');
expectPlan(isset($pl['2026-12-24'], $pl['2026-06-04'], $pl['2026-05-24'], $pl['2026-01-06']) && !isset(Holidays::forYear(2024, 'PL')['2024-12-24']), 'PL : Wigilia (od 2025), Boże Ciało, Zielone Świątki, Trzech Króli');
expectPlan(isset($be['2026-05-14'], $be['2026-05-25'], $be['2026-07-21'], $be['2026-11-11']) && !isset($be['2026-12-26']) && !isset($be['2026-01-06']), 'BE : Wniebowstąpienie, Zielone Świątki, 21.07, 11.11 ; bez 26.12 i 6.01');
$de = Holidays::forYear(2026, 'DE');
expectPlan(isset($de['2026-04-03'], $de['2026-04-06'], $de['2026-05-14'], $de['2026-05-25'], $de['2026-10-03'], $de['2026-12-26']) && !isset($de['2026-06-04']) && !isset($de['2026-11-01']) && count($de) === 9,
    'DE : Wielki Piątek, Wielkanoc pn, Wniebowstąpienie, Zielone Świątki pn, 3.10, 26.12 ; bez regionalnych (Boże Ciało, 1.11)');
$b = Holidays::between('2026-11-01', '2026-12-31', ['PL', 'BE'], ['2026-11-11' => ['BE' => ['name' => 'x', 'off' => false]], '2026-12-07' => ['PL' => ['name' => 'Zamknięcie', 'off' => true]]]);
expectPlan(isset($b['2026-11-11']['PL']) && !isset($b['2026-11-11']['BE']) && $b['2026-12-07']['PL'] === 'Zamknięcie' && count($b['2026-12-25']) === 2, 'święta : pominięte BE 11.11, dodany dzień wolny, 25.12 w obu krajach');
expectPlan(Plan::roundUp(10.2) === 10 && Plan::roundUp(10.3) === 11 && Plan::roundUp(0.15) === 0 && Plan::roundUp(0.46) === 1 && Plan::roundUp(3.0) === 3, 'zaokrąglenie : w górę z tolerancją 0,2');
expectPlan(Plan::lastYear('2026-11-02') === '2025-11-03' && Plan::lastYear('2026-12-28') === '2025-12-22' && Plan::lastYear('2027-01-04') === '2025-12-29', 'N-1 : ten sam tydzień ISO, tydzień 53 → 52');
expectPlan(Plan::short('Berdiff s.a.') === 'Berdiff' && Plan::short('Max & Sandra SRL') === 'Max & Sandra' && Plan::short('SLFood Sp. z o.o.') === 'SLFood' && Plan::short('Pâtisserie Lefèvre') === 'Pâtisserie Lefèvre', 'skrót nazwy klienta');

// --- Baza jednorazowa ------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_logistics_plan_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "logistics_plan_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['customer', 'customer_order', 'customer_order_product', 'final_product', 'final_product_ppwr_sales_packaging', 'category_product', 'carrier', 'carrier_vehicle',
                 'n_vehicle', 'n_tour', 'n_tour_customer', 'n_tour_vehicle', 'document_cmr', 'n_shipping_assignment', 'n_prod_param', 'n_prod_employee', 'production_employee',
                 'n_position', 'n_logistics_plan_week', 'n_logistics_plan_load', 'n_logistics_plan_vehicle', 'n_logistics_event', 'n_logistics_customer_coef',
                 'n_logistics_absence', 'n_logistics_holiday', 'n_logistics_trip'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('logistics.default_pallet_places', '33', '33', 'int'), ('logistics.mixed_pallets', '1', '1', 'bool'),
        ('logistics.leftover_fill_max', '0', '0', 'decimal')");
    $pdo->exec("INSERT INTO customer (id, company, fac_town) VALUES (1, 'Alfa s.a.', 'Corbais'), (2, 'Beta SRL', 'Gosselies'), (3, 'Gamma', 'Namur')");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Pieczywo')");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, cpl, lpp, cpp) VALUES (1, 'Bagietka', 1, 100, 10, 5, 2, 10)");
    $pdo->exec("INSERT INTO carrier (id, name, active) VALUES (1, 'Trans', 1)");
    $pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname) VALUES (10, 1, 'DX 1', NULL, NULL), (11, 1, 'WX 2', 'Jan', 'Obcy'), (12, 1, 'OLD 3', NULL, NULL)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind) VALUES (12, 'KIEROWCA', 'Kierowca-dostawca', 'Chauffeur', 'atelier')");
    $pdo->exec("INSERT INTO production_employee (id, name, surname) VALUES (1, 'Mariusz', 'Kierowca'), (2, 'Janusz', 'Zastępca')");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, id_position, active) VALUES (1, 1, 12, 1), (2, 2, 12, 1)");
    $pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, id_driver, active) VALUES (10, 5, 1, 1), (11, 33, NULL, 1), (12, 20, NULL, 0)");

    $order = 0;
    $add = function (int $customer, string $date, int $cartons, int $status = 3) use ($pdo, &$order) {
        $order++;
        $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date) VALUES ({$order}, {$customer}, {$status}, '{$date}', '{$date}')");
        $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton) VALUES ({$order}, 1, {$cartons}, {$cartons})");
    };
    // Alfa : 3 palety co tydzień w ostatnich 13 tygodniach (wt 28.07 → 20.10) ; rok temu w tych tygodniach 2 palety (15 kartonów = 1 pełna + 1 mieszana) → trend 1,5.
    for ($i = 0; $i < 13; $i++) {
        $add(1, date('Y-m-d', strtotime("2026-07-28 +{$i} weeks")), 30);
        $add(1, date('Y-m-d', strtotime(Plan::lastYear(date('Y-m-d', strtotime("2026-07-27 +{$i} weeks"))) . ' +1 day')), 15);
    }
    $add(1, '2025-11-04', 20);           // N-1 tygodnia 45 : 2 palety
    $add(1, '2026-11-10', 50, 1);        // zamówienie już złożone na tydzień 46 : 5 palet
    // Beta : 6 tygodni po 1 palecie w ostatnich 3 miesiącach, bez N-1 → średnia 6/13 = 0,46 → 1 paleta.
    for ($i = 0; $i < 6; $i++) $add(2, date('Y-m-d', strtotime("2026-08-04 +" . (2 * $i) . " weeks")), 10);
    // Gamma : tylko rok temu (klient nieaktywny) → poza planem.
    $add(3, '2025-11-04', 40);
    $add(3, '2025-08-05', 40);
    // Wydarzenie co roku z zeszłego roku w tygodniu 45 : +100 %.
    $pdo->exec("INSERT INTO n_logistics_event (week_start, name, pct, yearly) VALUES ('2025-11-03', 'Akcja jesień', 100, 1)");
    // Urlop kierowcy pojazdu DX 1 w tygodniu 47 (wyjazd wt 17.11).
    $pdo->exec("INSERT INTO n_logistics_absence (id_employee, date_from, date_to, reason) VALUES (1, '2026-11-16', '2026-11-20', 'urlop')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $builder->build();
    $svc = $c->get(Plan::class);
    $today = '2026-10-29';
    $svc->today = $today;
    $p = $svc->plan(null, $today);
    $cust = array_column($p['customers'], null, 'id');
    $weeks = array_column($p['weeks'], null, 'week_start');
    expectPlan(count($p['weeks']) === 12 && $p['weeks'][0]['week_start'] === '2026-11-02' && $p['weeks'][11]['week_start'] === '2027-01-18', 'plan : 12 tygodni od następnego poniedziałku');
    expectPlan(array_keys($cust) === [1, 2], 'klienci : aktywni w 3 miesiącach (Gamma tylko rok temu — poza planem)');
    $a = $cust[1];
    expectPlan($a['trend'] === 1.5 && $a['coef'] === 1.5 && abs($a['avg'] - 3.0) < 0.001, 'Alfa : średnia 3 palety, trend 3 ÷ 2 = 1,5');
    $w45 = $a['cells']['2026-11-02'];
    expectPlan($w45['source'] === 'n1' && $w45['n1'] === 2 && $w45['value'] === 6.0 && $w45['pallets'] === 6, 'tydz. 45 : N-1 2 × 1,5 × (1 + 100 % co roku) = 6');
    expectPlan($weeks['2026-11-02']['event']['inherited'] && $weeks['2026-11-02']['event']['pct'] === 100.0, 'wydarzenie co roku z 2025 w tym samym tygodniu ISO');
    expectPlan($a['cells']['2026-11-09']['source'] === 'orders' && $a['cells']['2026-11-09']['pallets'] === 5, 'tydz. 46 : zamówienie złożone (5 palet) zamiast prognozy');
    expectPlan($a['cells']['2026-11-16']['source'] === 'avg' && $a['cells']['2026-11-16']['pallets'] === 3, 'tydz. 47 : bez N-1 → średnia 3 mies. (3)');
    $bc = $cust[2];
    expectPlan($bc['trend'] === null && $bc['coef'] === 1.0 && $bc['cells']['2026-11-16']['source'] === 'avg' && $bc['cells']['2026-11-16']['pallets'] === 1 && !$bc['n1_any'], 'Beta : bez N-1, średnia 0,46 → 1 paleta');
    expectPlan($weeks['2026-11-09']['ship_date'] === '2026-11-09' && str_contains((string) $weeks['2026-11-09']['shifted'], 'dostawa 11.11'), 'tydz. 46 : dostawa 11.11 święto BE → wyjazd pn 9.11');
    expectPlan($weeks['2026-12-21']['ship_date'] === '2026-12-22' && $weeks['2027-01-04']['ship_date'] === '2027-01-05', 'tydz. 52 i 1 : wyjazd wt bez zmian (święta w innych dniach)');
    $veh = array_column($p['vehicles'], null, 'id');
    expectPlan(array_keys($veh) === [10, 11] && $veh[10]['driver']['name'] === 'Mariusz Kierowca' && $veh[11]['driver']['external'] && $veh[11]['driver']['name'] === 'Jan Obcy', 'ciężarówki : aktywne, kierowca pracownik / przewoźnika');
    expectPlan($veh[10]['cells']['2026-11-16']['absent'] !== null && !$veh[10]['cells']['2026-11-16']['usable'] && $veh[10]['cells']['2026-11-09']['usable'], 'urlop kierowcy w dniu wyjazdu tygodnia 47 : DX 1 nie jedzie');
    expectPlan($p['kpis']['unassigned'] === $p['kpis']['total'] && $p['kpis']['holiday_days'] >= 5, 'wskaźniki : wszystko do rozplanowania, dni świąt');

    // Rozdział automatyczny.
    $p = $svc->autoDispatch(7, null);
    $cust = array_column($p['customers'], null, 'id');
    expectPlan($p['result']['placed'] > 0 && $cust[1]['cells']['2026-11-02']['vehicle_id'] === 11 && $cust[2]['cells']['2026-11-02']['vehicle_id'] === 11, 'auto : tydz. 45 Alfa (6) i Beta (1) na WX 2 (33 m. ; DX 1 ma 5)');
    expectPlan($cust[1]['cells']['2026-11-16']['vehicle_id'] === 11 && $p['kpis']['unassigned'] === 0, 'auto : tydz. 47 bez DX 1 (urlop), wszyscy rozplanowani');
    // Przeciągnięcie : Beta tydz. 45 → DX 1 ; potem do rozplanowania.
    $p = $svc->saveLoads([['week_start' => '2026-11-02', 'customer_id' => 2, 'vehicle_id' => 10]], 7);
    expectPlan(array_column($p['customers'], null, 'id')[2]['cells']['2026-11-02']['vehicle_id'] === 10 && array_column($p['vehicles'], null, 'id')[10]['cells']['2026-11-02']['pallets'] === 1, 'przeciągnij : Beta → DX 1');
    $p = $svc->saveLoads([['week_start' => '2026-11-02', 'customer_id' => 2, 'vehicle_id' => null]], 7);
    expectPlan(array_column($p['customers'], null, 'id')[2]['cells']['2026-11-02']['vehicle_id'] === null
        && (int) $pdo->query("SELECT COUNT(*) FROM n_logistics_plan_load WHERE week_start = '2026-11-02' AND id_customer = 2")->fetchColumn() === 0, 'do rozplanowania : pusty wiersz usunięty');
    expectPlanFail(fn() => $svc->saveLoads([['week_start' => '2026-11-03', 'customer_id' => 2, 'vehicle_id' => 10]], 7), 422, 'tydzień : tylko poniedziałek');
    expectPlanFail(fn() => $svc->saveLoads([['week_start' => '2026-11-02', 'customer_id' => 2, 'vehicle_id' => 999]], 7), 404, 'nieznana ciężarówka');
    expectPlanFail(fn() => $svc->saveLoads([['week_start' => '2026-11-02', 'customer_id' => 2, 'pallets' => 1000]], 7), 422, 'palety : do 999');
    expectPlanFail(fn() => $svc->saveLoads([], 7), 422, 'pusta lista');
    // Palety wpisane ręcznie, współczynnik.
    $p = $svc->saveLoads([['week_start' => '2026-11-23', 'customer_id' => 2, 'pallets' => '4']], 7);
    expectPlan(array_column($p['customers'], null, 'id')[2]['cells']['2026-11-23']['source'] === 'manual' && array_column($p['customers'], null, 'id')[2]['cells']['2026-11-23']['pallets'] === 4, 'Prognoza : 4 palety wpisane ręcznie');
    $p = $svc->saveCoef(1, '2,00', 7);
    $a = array_column($p['customers'], null, 'id')[1];
    expectPlan($a['coef_manual'] === 2.0 && $a['cells']['2026-11-02']['pallets'] === 8, 'współczynnik 2,00 : tydz. 45 = 2 × 2 × 2 = 8');
    expectPlanFail(fn() => $svc->saveCoef(1, '6', 7), 422, 'współczynnik : od 0,3 do 5');
    $p = $svc->saveCoef(null, null, 7);
    expectPlan(array_column($p['customers'], null, 'id')[1]['coef'] === 1.5 && (int) $pdo->query('SELECT COUNT(*) FROM n_logistics_customer_coef')->fetchColumn() === 0, 'trend dla wszystkich : wpisy usunięte');
    // Wydarzenia.
    $p = $svc->saveEvent(['week_start' => '2026-11-30', 'name' => 'Mikołaj', 'pct' => '15', 'yearly' => true], 7);
    $w49 = array_column($p['weeks'], null, 'week_start')['2026-11-30'];
    expectPlan($w49['event']['name'] === 'Mikołaj' && !$w49['event']['inherited'] && array_column($p['customers'], null, 'id')[1]['cells']['2026-11-30']['pallets'] === 4, 'Mikołaj +15 % : średnia 3 × 1,15 = 3,45 → 4');
    expectPlanFail(fn() => $svc->saveEvent(['week_start' => '2026-11-30', 'name' => 'x', 'pct' => 400], 7), 422, 'wydarzenie : do +300 %');
    $p = $svc->saveEvent(['week_start' => '2026-11-02', 'name' => 'Bez akcji', 'pct' => 0], 7);
    expectPlan(array_column($p['customers'], null, 'id')[1]['cells']['2026-11-02']['pallets'] === 3, 'tydz. 45 : 0 % zamiast wydarzenia z zeszłego roku → 2 × 1,5 = 3');
    $id = (int) $pdo->query("SELECT id FROM n_logistics_event WHERE week_start = '2026-11-02'")->fetchColumn();
    $p = $svc->deleteEvent($id);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-02']['event']['inherited'], 'usunięcie : znowu wydarzenie co roku');
    // Wyjazd ręcznie, ciężarówka na zastępstwo.
    $p = $svc->saveWeek('2026-11-02', ['ship_date' => '2026-11-04'], 7);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-02']['ship_date'] === '2026-11-04' && array_column($p['weeks'], null, 'week_start')['2026-11-02']['ship_manual'], 'wyjazd tydz. 45 ręcznie : śr 4.11');
    expectPlanFail(fn() => $svc->saveWeek('2026-11-02', ['ship_date' => '2026-11-10'], 7), 422, 'wyjazd poza tygodniem');
    $p = $svc->saveVehicleWeek('2026-11-16', 10, ['driver_id' => 2], 7);
    $cell = array_column($p['vehicles'], null, 'id')[10]['cells']['2026-11-16'];
    expectPlan($cell['usable'] && $cell['driver']['name'] === 'Janusz Zastępca' && $cell['driver_override'], 'tydz. 47 : kierowca na zastępstwo → DX 1 jedzie');
    $p = $svc->saveVehicleWeek('2026-11-23', 11, ['unavailable' => true], 7);
    expectPlan(!array_column($p['vehicles'], null, 'id')[11]['cells']['2026-11-23']['usable'] && array_column($p['weeks'], null, 'week_start')['2026-11-23']['warnings'], 'WX 2 niedostępna w tyg. 48 : ostrzeżenie (klienci na niej)');
    expectPlanFail(fn() => $svc->saveVehicleWeek('2026-11-23', 10, ['driver_id' => 99], 7), 404, 'nieznany kierowca');
    // Dni wolne : pominięte BE 11.11 → wyjazd tydz. 46 znowu wt ; dzień wolny PL wt 24.11 → wyjazd pn 23.11.
    $p = $svc->saveHoliday(['day' => '2026-11-11', 'country' => 'BE', 'off' => false], 7);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-09']['ship_date'] === '2026-11-10', 'BE 11.11 pominięte : wyjazd wt 10.11');
    $p = $svc->saveHoliday(['day' => '2026-11-11', 'country' => 'BE', 'remove' => true], 7);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-09']['ship_date'] === '2026-11-09', 'przywrócone : wyjazd pn 9.11');
    $p = $svc->saveHoliday(['day' => '2026-11-24', 'country' => 'PL', 'name' => 'Zamknięcie zakładu', 'off' => true], 7);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-23']['ship_date'] === '2026-11-23' && in_array('Zamknięcie zakładu', array_column($p['holidays'], 'name'), true), 'dzień wolny PL wt 24.11 : wyjazd pn 23.11');
    expectPlanFail(fn() => $svc->saveHoliday(['day' => '2026-11-24', 'country' => 'NL', 'name' => 'x'], 7), 422, 'kraj : PL, BE albo DE');
    // DE = kraj przejazdu (parametr domyślnie « DE ») : święto regionalne dodane ręcznie śr 18.11 (dostawa tyg. 47) → zakaz ruchu, wyjazd pn 16.11.
    expectPlan($p['params']['transit_countries'] === ['DE'] && array_column($p['weeks'], null, 'week_start')['2026-11-16']['ship_date'] === '2026-11-17', 'DE przejazd : tydz. 47 wyjazd wt 17.11 (bez święta DE)');
    $p = $svc->saveHoliday(['day' => '2026-11-18', 'country' => 'DE', 'name' => 'Dzień Pokuty (Saksonia)', 'off' => true], 7);
    $w47 = array_column($p['weeks'], null, 'week_start')['2026-11-16'];
    expectPlan($w47['ship_date'] === '2026-11-16' && str_contains((string) $w47['shifted'], 'przejazd 18.11') && str_contains((string) $w47['shifted'], 'zakaz ruchu ciężarówek'), 'DE 18.11 : ' . $w47['shifted']);
    expectPlan(in_array(['date' => '2026-11-18', 'country' => 'DE', 'name' => 'Dzień Pokuty (Saksonia)', 'source' => 'manual', 'skipped' => false], $p['holidays'], true)
        && in_array('DE', array_column($w47['holidays'], 'country'), true), 'DE na liście świąt i w tygodniu');
    $p = $svc->saveHoliday(['day' => '2026-11-18', 'country' => 'DE', 'off' => false, 'name' => 'Jedziemy'], 7);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-16']['ship_date'] === '2026-11-17', 'DE « Jedziemy » : wyjazd znowu wt 17.11');
    $p = $svc->saveHoliday(['day' => '2026-11-18', 'country' => 'DE', 'name' => 'Dzień Pokuty (Saksonia)', 'off' => true], 7);
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('logistics.plan_transit_countries', 'DE', 'DE', 'string')");
    $c->get(\App\Repositories\Workshop\ParamRepository::class)->setValue('logistics.plan_transit_countries', '', 7);
    $p = $svc->plan(null, $today);
    expectPlan($p['params']['transit_countries'] === [] && array_column($p['weeks'], null, 'week_start')['2026-11-16']['ship_date'] === '2026-11-17' && !in_array('DE', array_column($p['holidays'], 'country'), true),
        'parametr pusty : bez kraju przejazdu, DE nie liczone');
    $c->get(\App\Repositories\Workshop\ParamRepository::class)->setValue('logistics.plan_transit_countries', 'de, BE, xx', 7);
    expectPlan($svc->plan(null, $today)['params']['transit_countries'] === ['DE'], 'parametr « de, BE, xx » → [DE] (bez kraju dostawy i nieznanych)');
    // Urlopy.
    $p = $svc->addAbsence(['employee_id' => 2, 'date_from' => '2026-11-16', 'date_to' => '2026-11-18', 'reason' => 'choroba'], 7);
    expectPlan(array_column($p['vehicles'], null, 'id')[10]['cells']['2026-11-16']['absent'] !== null && count($p['absences']) === 2, 'zastępca też na urlopie : DX 1 znowu nie jedzie');
    expectPlanFail(fn() => $svc->addAbsence(['employee_id' => 2, 'date_from' => '2026-11-20', 'date_to' => '2026-11-18'], 7), 422, 'urlop : koniec przed początkiem');
    $p = $svc->deleteAbsence((int) $pdo->query("SELECT id FROM n_logistics_absence WHERE id_employee = 2")->fetchColumn());
    expectPlan(count($p['absences']) === 1, 'urlop usunięty');
    expectPlanFail(fn() => $svc->deleteAbsence(999), 404, 'nieznany urlop');
    // Kopiowanie układu : Beta tydz. 45 na DX 1, w następnych tygodniach Beta bez ciężarówki → DX 1 (gdy jedzie).
    $pdo->exec("UPDATE n_logistics_plan_load SET id_carrier_vehicle = NULL WHERE id_customer = 2 AND week_start > '2026-11-02'");
    $pdo->exec("DELETE FROM n_logistics_plan_load WHERE id_customer = 2 AND week_start > '2026-11-02' AND pallets IS NULL");
    $svc->saveLoads([['week_start' => '2026-11-02', 'customer_id' => 2, 'vehicle_id' => 10]], 7);
    $p = $svc->copyWeek('2026-11-02', 7);
    $bc = array_column($p['customers'], null, 'id')[2];
    expectPlan($bc['cells']['2026-11-30']['vehicle_id'] === 10 && $bc['cells']['2026-11-09']['vehicle_id'] === 10, 'kopiuj układ : Beta na DX 1 w następnych tygodniach');
    // Zatwierdzenie : pojazdy na dzień wyjazdu, wybór ręczny dnia bez zmian ; zmiana tygodnia → do ponowienia.
    $pdo->exec("INSERT INTO n_shipping_assignment (ship_date, id_customer, id_tour, tour_set, id_carrier_vehicle, vehicle_set) VALUES ('2026-11-04', 1, NULL, 0, 12, 1)");
    $p = $svc->approve('2026-11-02', 7);
    $as = $pdo->query("SELECT id_customer, id_carrier_vehicle FROM n_shipping_assignment WHERE ship_date = '2026-11-04' ORDER BY id_customer")->fetchAll(PDO::FETCH_KEY_PAIR);
    expectPlan($p['result'] === ['weeks' => 1, 'saved' => 1, 'kept' => 1] && $as === [1 => 12, 2 => 10], 'zatwierdź tydz. 45 : Beta → DX 1 na śr 4.11, Alfa wybrana ręcznie w Dniu wysyłki bez zmian');
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-02']['approved_at'] !== null && $p['kpis']['approved'] === 1, 'tydzień zatwierdzony');
    $p = $svc->saveLoads([['week_start' => '2026-11-02', 'customer_id' => 2, 'vehicle_id' => 11]], 7);
    expectPlan(array_column($p['weeks'], null, 'week_start')['2026-11-02']['approved_at'] === null, 'zmiana w zatwierdzonym tygodniu : zatwierdzenie do ponowienia');
    $p = $svc->approve(null, 7);
    expectPlan($p['result']['weeks'] === 12 && $p['kpis']['approved'] === 12, 'zatwierdź 12 tygodni');
    $as = $pdo->query("SELECT CONCAT(id_customer, ':', id_carrier_vehicle, ':', plan_set) FROM n_shipping_assignment WHERE ship_date = '2026-11-04' ORDER BY id_customer")->fetchAll(PDO::FETCH_COLUMN);
    expectPlan($as === ['1:12:0', '2:11:1'], 'ponowne zatwierdzenie : Beta (z planu) → WX 2, Alfa (ręcznie) bez zmian (' . implode(', ', $as) . ')');
    // « Wszystko do rozplanowania » : ciężarówki zdjęte od dziś, palety ręczne zostają, tygodnie do ponownego zatwierdzenia, pojazdy dnia z planu zdjęte.
    $pdo->exec("INSERT INTO n_logistics_plan_load (week_start, id_customer, pallets, id_carrier_vehicle) VALUES ('2026-10-26', 1, NULL, 11)");
    $p = $svc->resetLoads(7, null);
    $r = $p['result'];
    expectPlan($r['cleared'] > 0 && $r['weeks'] === 12 && $r['unapproved'] === 12 && $p['kpis']['approved'] === 0 && $p['kpis']['unassigned'] === $p['kpis']['total'], "reset : {$r['cleared']} × klient zdjęty, 12 tyg. do zatwierdzenia");
    expectPlan(!array_filter(array_merge(...array_map(fn($c) => array_column($c['cells'], 'vehicle_id'), $p['customers']))), 'reset : nikt na ciężarówce');
    expectPlan(array_column($p['customers'], null, 'id')[2]['cells']['2026-11-23']['source'] === 'manual' && array_column($p['customers'], null, 'id')[2]['cells']['2026-11-23']['pallets'] === 4, 'reset : palety wpisane ręcznie zostają');
    expectPlan((int) $pdo->query("SELECT id_carrier_vehicle FROM n_logistics_plan_load WHERE week_start = '2026-10-26' AND id_customer = 1")->fetchColumn() === 11, 'reset : tydzień przeszły bez zmian');
    $as = $pdo->query("SELECT CONCAT(id_customer, ':', COALESCE(id_carrier_vehicle, '-')) FROM n_shipping_assignment WHERE ship_date >= '2026-11-02' ORDER BY ship_date, id_customer")->fetchAll(PDO::FETCH_COLUMN);
    expectPlan($as === ['1:12'], 'reset : pojazdy dnia z planu zdjęte, wybór ręczny w Dniu wysyłki zostaje (' . implode(', ', $as) . ')');
    $p = $svc->resetLoads(7, null);
    expectPlan($p['result']['cleared'] === 0 && $p['result']['unapproved'] === 0, 'reset drugi raz : nic do zdjęcia');
    // Kalendarz tras [207] : trasa z szablonu (klienci na ciężarówce, przerwy i odpoczynki UE), zmiana ręczna, reset, szablon.
    $trips = $c->get(\App\Services\Logistics\LogisticsTripService::class);
    $svc->autoDispatch(7, null);
    $p = $svc->plan(null, $today);
    $t = array_values(array_filter($p['trips'], fn($t) => $t['week_start'] === '2026-11-30' && $t['vehicle_id'] === 11))[0] ?? null;
    expectPlan($t !== null && !$t['manual'] && $t['start_at'] === '2026-12-01 05:00' && $p['trip_template']['drive_out_min'] === 765, 'trasa tydz. 49 WX 2 : z szablonu, wyjazd wt 1.12 05:00');
    $kinds = array_column($t['phases'], 'k');
    expectPlan($kinds[0] === 'load' && in_array('brk', $kinds, true) && in_array('rest', $kinds, true) && end($kinds) === 'weekly' && in_array('unload', $kinds, true), 'fazy : załadunek, przerwa, odpoczynek, rozładunek, odpoczynek tygodniowy');
    expectPlan(array_column($t['checks'], 'level') === ['ok', 'ok', 'ok'] && $t['arrive_at'] !== null && $t['home_at'] > $t['arrive_at'], 'kontrola czasu pracy : OK ; u klienta i powrót');
    $unload = array_values(array_filter($t['phases'], fn($x) => $x['k'] === 'unload'));
    expectPlan(count($unload) === $t['clients'] && $unload[0]['client'] !== '' && $unload[0]['m'] === 30 + 2 * $unload[0]['pallets'], 'rozładunek : 30 min + 2 min / paletę, nazwa klienta');
    // Zmiana ręczna : rozładunek 2 h, jazda bez przerwy 6 h → kontrola ; brak klienta → ostrzeżenie.
    $planned = array_column($t['stops'], 'client');
    $trips->save('2026-11-30', 11, ['start_at' => '2026-12-01T06:00', 'phases' => [['k' => 'load', 'm' => 60], ['k' => 'drive', 'm' => 360], ['k' => 'unload', 'm' => 120, 'client_id' => 3], ['k' => 'back', 'm' => 300]]], 7);
    $t = array_values(array_filter($svc->plan(null, $today)['trips'], fn($t) => $t['week_start'] === '2026-11-30' && $t['vehicle_id'] === 11))[0];
    expectPlan($t['manual'] && $t['start_at'] === '2026-12-01 06:00' && $t['phases'][2]['s'] === '2026-12-01 13:00' && $t['phases'][2]['e'] === '2026-12-01 15:00', 'trasa ręczna : wyjazd 6:00, rozładunek 13:00–15:00');
    expectPlan($t['checks'][0]['code'] === 'CONT_DRIVE' && $t['checks'][0]['level'] === 'bad' && $t['checks'][0]['value'] === 660 && $t['checks'][1]['level'] === 'bad', 'kontrola : 11 h jazdy bez przerwy (rozładunek to nie przerwa), 11 h dziennie');
    $w = array_column($t['warnings'], null, 'code');
    expectPlan($w['MISSING']['names'] === $planned && $w['EXTRA']['names'] === ['#3'] && !isset($w['SHIP_DATE']), 'ostrzeżenia : klienci planu bez rozładunku, rozładunek klienta spoza ciężarówki');
    $n = $trips->insertRest('2026-11-30', 7);
    $t = array_values(array_filter($svc->plan(null, $today)['trips'], fn($t) => $t['week_start'] === '2026-11-30' && $t['vehicle_id'] === 11))[0];
    expectPlan($n === 1 && array_column($t['checks'], 'level') === ['ok', 'ok', 'ok'] && end($t['phases'])['k'] === 'weekly', '« Wstaw przerwy i odpoczynki » : trasa zgodna z UE');
    expectPlanFail(fn() => $trips->save('2026-11-30', 11, ['start_at' => '2026-12-01 06:00', 'phases' => [['k' => 'fly', 'm' => 10]]], 7), 422, 'nieznany rodzaj fazy');
    expectPlanFail(fn() => $trips->save('2026-11-30', 11, ['start_at' => '2026-12-01 06:00', 'phases' => [['k' => 'unload', 'm' => 10]]], 7), 422, 'rozładunek bez klienta');
    expectPlanFail(fn() => $trips->save('2026-11-30', 999, ['start_at' => '2026-12-01 06:00', 'phases' => [['k' => 'load', 'm' => 10]]], 7), 404, 'nieznana ciężarówka');
    expectPlanFail(fn() => $trips->save('2026-12-01', 11, ['start_at' => '2026-12-01 06:00', 'phases' => [['k' => 'load', 'm' => 10]]], 7), 422, 'tydzień : poniedziałek');
    expectPlanFail(fn() => $trips->save('2026-11-30', 11, ['start_at' => '2027-03-01 06:00', 'phases' => [['k' => 'load', 'm' => 10]]], 7), 422, 'początek poza tygodniem');
    $trips->reset('2026-11-30', 11);
    expectPlan(!array_values(array_filter($svc->plan(null, $today)['trips'], fn($t) => $t['week_start'] === '2026-11-30' && $t['vehicle_id'] === 11))[0]['manual'], 'przywróć szablon');
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('logistics.trip_departure_time', '05:00', '05:00', 'string'), ('logistics.trip_load_min', '60', '60', 'int'), ('logistics.trip_drive_out_min', '765', '765', 'int')");
    $trips->saveTemplate(['departure_time' => '4:30', 'load_min' => '45', 'drive_out_min' => '600'], 7);
    $t = array_values(array_filter($svc->plan(null, $today)['trips'], fn($t) => $t['week_start'] === '2026-11-30' && $t['vehicle_id'] === 11))[0];
    expectPlan($t['start_at'] === '2026-12-01 04:30' && $t['phases'][0]['m'] === 45, 'szablon : załadunek 4:30, 45 min');
    expectPlanFail(fn() => $trips->saveTemplate(['departure_time' => '25:00', 'load_min' => -1], 7), 422, 'szablon : walidacja');
    // Tras w tygodniu [208] : ciężarówka 3 miejsca × 2 trasy → 6 miejsc ; Alfa (3) w trasie 1, Beta (2) w trasie 2 po odpoczynku dobowym.
    $pdo->exec("UPDATE n_vehicle SET pallet_places = 3 WHERE id_carrier_vehicle = 11");
    $svc->saveLoads([['week_start' => '2026-12-07', 'customer_id' => 1, 'vehicle_id' => 11, 'pallets' => 3], ['week_start' => '2026-12-07', 'customer_id' => 2, 'vehicle_id' => 11, 'pallets' => 2]], 7);
    $p = $svc->setVehicleTrips(11, '2', 7, null);
    $cell = array_column($p['vehicles'], null, 'id')[11]['cells']['2026-12-07'];
    expectPlan(array_column($p['vehicles'], null, 'id')[11]['trips_per_week'] === 2 && $cell['trips'] === 2 && $cell['places_per_trip'] === 3 && $cell['places'] === 6 && !$cell['trips_override'], 'tras / tydz. 2 : miejsca tygodnia 3 × 2 = 6');
    $wk = array_values(array_filter($p['trips'], fn($t) => $t['week_start'] === '2026-12-07' && $t['vehicle_id'] === 11));
    expectPlan(count($wk) === 2 && $wk[0]['trip_no'] === 1 && $wk[1]['trip_no'] === 2 && $wk[0]['trips'] === 2 && $wk[0]['places'] === 3, 'dwie trasy w tygodniu, po 3 miejsca');
    expectPlan(array_column($wk[0]['stops'], 'client_id') === [1, 2] && $wk[0]['pallets'] === 3 && $wk[1]['pallets'] === 2 && $wk[0]['clients'] === 1 && $wk[1]['clients'] === 1, 'podział : Alfa (3) w trasie 1, Beta (2) w trasie 2');
    expectPlan($wk[0]['start_at'] === '2026-12-08 04:30' && end($wk[0]['phases'])['k'] === 'rest' && end($wk[0]['phases'])['m'] === 660 && end($wk[1]['phases'])['k'] === 'weekly', 'trasa 1 : wyjazd wt 4:30 (szablon), koniec odpoczynkiem dobowym ; trasa 2 : odpoczynek tygodniowy');
    expectPlan($wk[1]['start_at'] >= $wk[0]['end_at'] && (int) substr($wk[1]['start_at'], 14, 2) % 15 === 0 && !$wk[1]['warnings'], 'trasa 2 : po końcu trasy 1 (pełny kwadrans), bez ostrzeżeń');
    $week = array_column($wk[1]['checks'], null, 'code');
    expectPlan(isset($week['WEEK_DRIVE']) && $week['WEEK_DRIVE']['level'] === 'ok' && $week['WEEK_DRIVE']['value'] === 2 * ($p['trip_template']['drive_out_min'] + $p['trip_template']['drive_back_min']) && !isset(array_column($wk[0]['checks'], null, 'code')['WEEK_DRIVE']), 'jazda w tygodniu : 2 × (tam + powrót) ≤ 56 h (na ostatniej trasie)');
    // Trasa 2 zmieniona ręcznie przed końcem trasy 1 → ostrzeżenie ; za dużo jazdy w tygodniu → kontrola.
    $trips->save('2026-12-07', 11, ['start_at' => '2026-12-08 08:00', 'phases' => [['k' => 'drive', 'm' => 270], ['k' => 'brk', 'm' => 45], ['k' => 'drive', 'm' => 270], ['k' => 'unload', 'm' => 30, 'client_id' => 2]]], 7, 2);
    $wk = array_values(array_filter($svc->plan(null, $today)['trips'], fn($t) => $t['week_start'] === '2026-12-07' && $t['vehicle_id'] === 11));
    expectPlan($wk[1]['manual'] && !$wk[0]['manual'] && array_column($wk[1]['warnings'], 'code') === ['OVERLAP'] && $wk[1]['warnings'][0]['no'] === 2, 'trasa 2 ręczna przed końcem trasy 1 : ostrzeżenie OVERLAP');
    $trips->reset('2026-12-07', 11, 2);
    $wk = array_values(array_filter($svc->plan(null, $today)['trips'], fn($t) => $t['week_start'] === '2026-12-07' && $t['vehicle_id'] === 11));
    expectPlan(!$wk[1]['manual'] && !$wk[1]['warnings'], 'trasa 2 : przywróć szablon');
    // Zatwierdzenie : dzień wysyłki Bety = wyjazd trasy 2.
    $svc->approve('2026-12-07', 7, null);
    $days = $pdo->query("SELECT id_customer, ship_date FROM n_shipping_assignment WHERE ship_date BETWEEN '2026-12-07' AND '2026-12-13' AND id_carrier_vehicle = 11 ORDER BY id_customer")->fetchAll(PDO::FETCH_KEY_PAIR);
    expectPlan(($days[1] ?? null) === '2026-12-08' && ($days[2] ?? null) === substr($wk[1]['start_at'], 0, 10) && $days[2] > '2026-12-08', 'zatwierdzenie : Alfa wt 8.12, Beta w dniu wyjazdu trasy 2 (' . ($days[2] ?? '-') . ')');
    // Wyjątek tygodnia (⚙ Tablica) : 1 trasa → wszyscy w jednej trasie (ostatnia bierze resztę).
    $p = $svc->saveVehicleWeek('2026-12-07', 11, ['driver_id' => null, 'unavailable' => false, 'trips' => '1'], 7);
    $cell = array_column($p['vehicles'], null, 'id')[11]['cells']['2026-12-07'];
    $wk = array_values(array_filter($p['trips'], fn($t) => $t['week_start'] === '2026-12-07' && $t['vehicle_id'] === 11));
    expectPlan($cell['trips'] === 1 && $cell['trips_override'] && $cell['places'] === 3 && count($wk) === 1 && $wk[0]['clients'] === 2 && end($wk[0]['phases'])['k'] === 'weekly', 'wyjątek tygodnia : 1 trasa, 3 miejsca, obaj klienci');
    $p = $svc->saveVehicleWeek('2026-12-07', 11, ['driver_id' => null, 'unavailable' => false, 'trips' => ''], 7);
    expectPlan(array_column($p['vehicles'], null, 'id')[11]['cells']['2026-12-07']['trips'] === 2 && !$pdo->query("SELECT COUNT(*) FROM n_logistics_plan_vehicle WHERE week_start = '2026-12-07' AND id_carrier_vehicle = 11")->fetchColumn(), 'wyjątek usunięty : znów 2 trasy (bez wiersza tygodnia)');
    $pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num) VALUES (13, 1, 'NEW 4')");
    expectPlanFail(fn() => $svc->setVehicleTrips(11, 5, 7), 422, 'tras / tydz. : od 1 do 4');
    expectPlanFail(fn() => $svc->setVehicleTrips(13, 2, 7), 404, 'tras / tydz. : ciężarówka bez pojemności');
    expectPlanFail(fn() => $svc->saveVehicleWeek('2026-12-07', 11, ['trips' => 9], 7), 422, 'wyjątek tygodnia : od 1 do 4');
    expectPlanFail(fn() => $trips->save('2026-12-07', 11, ['start_at' => '2026-12-08 06:00', 'phases' => [['k' => 'load', 'm' => 10]]], 7, 5), 422, 'trasa : numer od 1 do 4');
    expectPlanFail(fn() => $c->get(\App\Services\Logistics\LogisticsService::class)->saveVehicle(11, ['pallet_places' => 3, 'trips_per_week' => 0], 7), 422, 'Pojazdy : tras w tygodniu od 1 do 4');
    $c->get(\App\Services\Logistics\LogisticsService::class)->saveVehicle(11, ['pallet_places' => 33, 'trips_per_week' => '1'], 7);
    expectPlan((int) $pdo->query('SELECT trips_per_week FROM n_vehicle WHERE id_carrier_vehicle = 11')->fetchColumn() === 1, 'Pojazdy : zapis tras w tygodniu');
    // Inny zakres (?from).
    $p = $svc->plan('2026-12-02', $today);
    expectPlan($p['weeks'][0]['week_start'] === '2026-11-30' && $p['range']['prev'] === '2026-11-02', 'zakres od tygodnia daty from');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "logistics_plan_test: OK\n";
