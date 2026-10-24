<?php
declare(strict_types=1);
/*
 * Logistyka : palety klientów dnia (pełne + mieszane), waga, trasa i pojazd klienta (przydział dnia,
 * trasa dnia tygodnia z pojazdem dobieranym z pojazdów trasy, ostatni CMR), zapełnienie pojazdów,
 * przekroczenie pojemności, trasy (pojazdy, klienci, walidacja), walidacja pojazdów i danych
 * paletowych karty produktu. Liczby kontrolne na bazie jednorazowej.
 *
 *   php tests/logistics_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide) ; structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migrations
 * tfb appliquées). La base erp_logistics_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\LogisticsService;
use App\Services\Logistics\PalletCalculator;

function expectLogi(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Calcul pur
$p = PalletCalculator::compute([['cartons' => 70, 'cpp' => 60, 'ppc' => 64, 'weight_g' => 75], ['cartons' => 30, 'cpp' => 40, 'ppc' => 20, 'weight_g' => 250]], true, 25);
expectLogi($p['full_pallets'] === 1 && $p['mixed_pallets'] === 1 && $p['pallets'] === 2, '70/60 + 30/40 : 1 pełna + 1 mieszana (10/60 + 30/40 < 1)');
expectLogi(abs($p['weight_kg'] - (70 * 64 * 0.075 + 30 * 20 * 0.25 + 2 * 25)) < 0.01, 'waga towaru + tara palet');
$p = PalletCalculator::compute([['cartons' => 70, 'cpp' => 60], ['cartons' => 30, 'cpp' => 40]], false, 25);
expectLogi($p['pallets'] === 3, 'bez palet mieszanych : osobna paleta na każdą resztę');
$p = PalletCalculator::compute([['cartons' => 120, 'cpp' => 60], ['cartons' => 5, 'cpp' => null]], true, 25);
expectLogi($p['pallets'] === 2 && $p['mixed_pallets'] === 0 && $p['missing_cpp'] === 1, 'pełne palety bez reszty ; produkt bez cpp sygnalizowany');
expectLogi(PalletCalculator::compute([], true, 25)['pallets'] === 0, 'brak pozycji : 0 palet');
// Paleta mieszana tylko z kartonami jednego formatu : dwie reszty w różnych kartonach = dwie palety
$p = PalletCalculator::compute([['cartons' => 70, 'cpp' => 60, 'product' => 'Croissant', 'carton_type_id' => 1, 'carton_type' => 'Karton 590 × 390 × 210', 'category' => 'Viennoiserie'],
    ['cartons' => 30, 'cpp' => 40, 'product' => 'Bagietka', 'carton_type_id' => 2, 'carton_type' => 'Karton 390 × 195 × 120', 'category' => 'Boulangerie']], true, 25);
expectLogi($p['pallets'] === 3 && $p['mixed_pallets'] === 2 && count($p['carton_types']) === 2, 'reszty w różnych kartonach nie mieszają się');
$p = PalletCalculator::compute([['cartons' => 70, 'cpp' => 60, 'product' => 'A', 'carton_type_id' => 1, 'category' => 'X'], ['cartons' => 45, 'cpp' => 60, 'product' => 'B', 'carton_type_id' => 1, 'category' => 'Y'],
    ['cartons' => 25, 'cpp' => 60, 'product' => 'C', 'carton_type_id' => 1, 'category' => 'X']], true, 25);
$type = $p['carton_types'][0];
$mixedPallets = array_values(array_filter($type['pallets'], fn($x) => $x['kind'] === 'mixed'));
expectLogi(count($type['pallets']) === 3 && $mixedPallets[0]['items'][0] == ['product' => 'B', 'cartons' => 45.0] && $mixedPallets[0]['fill'] == 1.0
    && $mixedPallets[1]['items'][0]['product'] === 'C' && array_sum(array_map(fn($x) => array_sum(array_column($x['items'], 'cartons')), $mixedPallets)) == 80.0,
    'paleta mieszana wypełniana po kolei : 45 B + 10 C pełna, potem reszta C + 10 A');
expectLogi(array_column($type['categories'], 'name') === ['X', 'Y'] && $type['categories'][0]['cartons'] == 95.0, 'w rodzaju kartonu : kategorie od największej');
// Resztki różnych formatów poniżej 50 % : razem na jednej palecie
$leftover = [['cartons' => 6, 'cpp' => 60, 'product' => 'A', 'carton_type_id' => 1, 'carton_type' => 'K1'], ['cartons' => 10, 'cpp' => 40, 'product' => 'B', 'carton_type_id' => 2, 'carton_type' => 'K2'],
    ['cartons' => 30, 'cpp' => 40, 'product' => 'C', 'carton_type_id' => 3, 'carton_type' => 'K3']];
expectLogi(PalletCalculator::compute($leftover, true, 25)['pallets'] === 3, 'bez łączenia resztek : 3 palety');
$p = PalletCalculator::compute($leftover, true, 25, 0.5);
$formats = array_values(array_filter($p['carton_types'], fn($t) => $t['id'] === 'formats'))[0] ?? null;
expectLogi($p['pallets'] === 2 && $formats && count($formats['pallets']) === 1 && abs($formats['pallets'][0]['fill'] - 0.35) < 0.001
    && array_column($formats['pallets'][0]['items'], 'format') === ['K2', 'K1'], 'resztki 10 % + 25 % różnych formatów razem ; 75 % zostaje w swoim formacie');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_logistics_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "logistics_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['n_prod_param', 'carrier', 'carrier_vehicle', 'n_vehicle', 'n_tour', 'n_tour_vehicle', 'n_tour_customer', 'n_shipping_assignment', 'customer', 'customer_order',
                 'customer_order_product', 'final_product', 'palets_delivery', 'document_cmr', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'raw_material',
                 'category_product'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES
        ('logistics.mixed_pallets', '1', '1', 'bool'), ('logistics.pallet_tare_kg', '25', '25', 'decimal'), ('logistics.default_pallet_places', '33', '33', 'int'),
        ('planning.default_departure_time', '20:00', '20:00', 'time'), ('planning.loading_time_min', '60', '60', 'int')");
    $pdo->exec("INSERT INTO carrier (id, name, active) VALUES (1, 'Trans', 1), (2, 'Stary', 0)");
    $pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname) VALUES (1, 1, 'DX 1', 'Jan', 'K'), (2, 1, 'DX 2', '', ''), (3, 1, 'DX 3', '', '')");
    $pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, max_load_kg, departure_time, active) VALUES (1, 10, 5000, '18:00:00', 1), (2, 33, NULL, NULL, 1)");
    $pdo->exec("INSERT INTO customer (id, name, surname, company, fac_town, fac_country) VALUES
        (1, '', '', 'Sklep A', 'Wrocław', 'PL'), (2, '', '', 'Sklep B', 'Berlin', 'DE'), (3, 'Jan', 'Kowalski', '', 'Opole', 'PL'), (4, '', '', 'Sklep D', 'Łódź', 'PL')");
    $pdo->exec("INSERT INTO final_product (id, name, cpp, cpl, lpp, ppc, weight, active) VALUES
        (10, 'Croissant', 60, 10, 6, 64, 75, 1), (11, 'Bagietka', 40, 8, 5, 20, 250, 1), (12, 'Tarta', 0, 0, 0, 0, 0, 1), (13, 'Ciasto', 50, 10, 6, 10, 100, 1)");
    $pdo->exec("INSERT INTO carton_dimension (id, length, width, height, name) VALUES (4006, 590, 390, 210, 'Karton 590 x 390 x 210')");
    $pdo->exec("INSERT INTO final_product_ppwr_sales_packaging (id_final_product, id_outer_raw_material) VALUES (10, 4006), (11, 4006), (13, 4006)");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Viennoiserie'), (2, 'Boulangerie')");
    $pdo->exec("UPDATE final_product SET id_category = 1 WHERE id IN (10, 13)");
    $pdo->exec("UPDATE final_product SET id_category = 2 WHERE id = 11");
    $d = '2026-10-20';
    $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date) VALUES
        (1, 1, 1, '{$d}'), (2, 1, 2, '{$d}'), (3, 2, 1, '{$d}'), (4, 3, 3, '{$d}'), (5, 4, 5, '{$d}'), (6, 4, 1, '2026-10-21')");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES
        (1, 10, 70), (1, 11, 30), (2, 10, 50), (2, 13, 10), (3, 10, 600), (4, 12, 10), (4, 13, 25), (5, 10, 99), (6, 10, 5)");
    $pdo->exec("INSERT INTO palets_delivery (client, delivery_date, status, id_customer) VALUES ('B', '{$d}', 2, 2), ('B', '{$d}', 2, 2)");
    $pdo->exec("INSERT INTO n_shipping_assignment (ship_date, id_customer, id_carrier_vehicle, stop_order) VALUES ('{$d}', 1, 1, 2)");
    $pdo->exec("INSERT INTO document_cmr (id, id_customer, id_carrier, id_carrier_vehicle) VALUES (1, 3, 1, 3), (2, 3, 1, 2)");

    $new = fn() => new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo));
    $service = $new();
    $refused = function (callable $fn): bool {
        try { $fn(); return false; } catch (CustomException) { return true; }
    };
    // Trasa T1 : pojazd 1, klient B na stałe (wtorek 20.10 w dniach trasy)
    $t1 = $service->createTour(['name' => '  Wrocław   Berlin ', 'days' => [1, 2, 3, 4, 5], 'color' => '#AA3300'], 1);
    $service->setTourVehicles($t1, ['vehicle_ids' => [1]]);
    $service->setTourCustomers($t1, ['customers' => [['id' => 2, 'stop' => 1]]]);
    expectLogi($pdo->query("SELECT CONCAT(name, '|', weekdays, '|', color) FROM n_tour WHERE id = {$t1}")->fetchColumn() === 'Wrocław Berlin|31|#aa3300', 'trasa : nazwa, dni pon.–pt., kolor');
    $day = $service->day($d);
    $c = $day['by_id'];
    expectLogi(count($day['customers']) === 3 && !isset($c[4]), '3 klientów (zamówienie zarchiwizowane i inny dzień pominięte)');
    expectLogi($c[1]['orders'] === 2 && $c[1]['cartons'] == 160.0, 'klient A : 2 zamówienia, 160 kartonów');
    expectLogi($c[1]['full_pallets'] === 2 && $c[1]['mixed_pallets'] === 1 && $c[1]['pallets'] === 3,
        'klient A : Croissant 70 + 50 = 120 → 2 pełne ; reszty 30/40 + 10/50 → 1 mieszana');
    expectLogi(abs($c[1]['weight_kg'] - (120 * 64 * 0.075 + 30 * 20 * 0.25 + 10 * 10 * 0.1 + 3 * 25)) < 0.01, 'waga klienta A');
    $types = $c[1]['carton_types'];
    expectLogi(count($types) === 1 && $types[0]['name'] === 'Karton 590 × 390 × 210' && array_column($types[0]['categories'], 'name') === ['Viennoiserie', 'Boulangerie'],
        'klient A : jeden rodzaj kartonu, kategorie Viennoiserie (130 kart.) i Boulangerie (30)');
    expectLogi($c[1]['vehicle_id'] === 1 && $c[1]['vehicle_source'] === 'day' && $c[1]['stop'] === 2, 'A : przydział na ten dzień');
    expectLogi($c[2]['pallets'] === 10 && $c[2]['scanned'] === 2 && $c[2]['tour_id'] === $t1 && $c[2]['tour_source'] === 'tour' && $c[2]['tour_member']
        && $c[2]['vehicle_id'] === 1 && $c[2]['vehicle_source'] === 'tour' && $c[2]['stop'] === 1, 'B : 10 pełnych palet, 2 zeskanowane, trasa T1, pojazd z trasy');
    expectLogi($c[1]['tour_id'] === null && $day['no_tour'] === [1, 3] && count($day['tours']) === 1 && $day['tours'][0]['customers'] === [2]
        && $day['tours'][0]['pallets'] === 10 && $day['tours'][0]['places'] === 10, 'trasy dnia : T1 z B, reszta bez trasy');
    expectLogi($c[3]['name'] === 'Jan Kowalski' && $c[3]['vehicle_id'] === 2 && $c[3]['vehicle_source'] === 'last_cmr', 'klient bez firmy : imię i nazwisko ; pojazd z ostatniego CMR');
    expectLogi($c[3]['missing_cpp'] === 1 && $c[3]['pallets'] === 1 && $c[3]['palletised'], 'produkt bez cpp sygnalizowany ; zamówienie spaletyzowane');

    $fleet = array_column($day['fleet'], null, 'id');
    expectLogi($fleet[1]['pallets'] === 13 && $fleet[1]['over'] && abs($fleet[1]['fill_pct'] - 130) < 0.01, 'pojazd 1 : 13 palet na 10 miejsc → przekroczony');
    expectLogi($fleet[1]['departure'] === '18:00' && $fleet[1]['loading_from'] === '17:00', 'wyjazd pojazdu i początek załadunku');
    expectLogi($fleet[2]['departure'] === '20:00' && $fleet[2]['pallets'] === 1 && !$fleet[2]['over'], 'pojazd bez godziny : wyjazd domyślny');
    expectLogi(!isset($fleet[3]), 'pojazd bez pojemności i bez klientów pominięty');
    expectLogi($fleet[1]['customers'] === [2, 1], 'klienci pojazdu w kolejności rozładunku');
    expectLogi(count(array_filter($day['warnings'], fn($w) => str_contains($w, 'DX 1') && str_contains($w, '13 palet na 10'))) === 1, 'ostrzeżenie o przekroczeniu');
    $issues = array_column($day['products'], 'issue', 'name');
    ksort($issues);
    expectLogi($issues === ['Ciasto' => 'layers', 'Tarta' => 'missing'], 'produkty do uzupełnienia : brak cpp (Tarta) i niezgodność warstw (Ciasto 10 × 6 ≠ 50)');
    expectLogi($day['totals']['pallets'] === 14 && $day['totals']['scanned'] === 2, 'razem palet dnia');

    // Drugi pojazd trasy : B nie mieści się w pojeździe 1 (3 palety A + 10 > 10) → pojazd 2
    $service->setTourVehicles($t1, ['vehicle_ids' => ['1', 2, 1]]);
    $service->updateTour($t1, ['departure_time' => '16:30'], 1);
    $day = $new()->day($d);
    $fleet = array_column($day['fleet'], null, 'id');
    expectLogi($day['by_id'][2]['vehicle_id'] === 2 && $fleet[1]['pallets'] === 3 && !$fleet[1]['over'] && $fleet[2]['pallets'] === 11, 'pojazd z trasy : pierwszy, w którym palety klienta się mieszczą');
    expectLogi($fleet[2]['departure'] === '16:30' && $fleet[1]['departure'] === '18:00' && $fleet[2]['loading_from'] === '15:30', 'wyjazd pojazdu = godzina trasy jego klientów');
    expectLogi($day['tours'][0]['places'] === 43 && $day['tours'][0]['departure'] === '16:30', 'trasa dnia : miejsca obu pojazdów, wyjazd trasy');
    $service->updateTour($t1, ['days' => [1]], 1);
    $day = $new()->day($d);
    expectLogi($day['by_id'][2]['tour_id'] === null && $day['by_id'][2]['vehicle_id'] === null && $day['tours'] === [], 'trasa tylko w poniedziałki : we wtorek B bez trasy');
    $service->updateTour($t1, ['days' => [1, 2, 3, 4, 5], 'departure_time' => ''], 1);

    // Trasa na ten dzień
    $service->assign(['date' => $d, 'customer_id' => 2, 'tour_id' => null, 'vehicle_id' => 'auto'], 1);
    $b = $new()->day($d)['by_id'][2];
    expectLogi($b['tour_id'] === null && $b['tour_source'] === 'day' && $b['vehicle_id'] === null, 'B bez trasy tylko na ten dzień');
    $service->assign(['date' => $d, 'customer_id' => 2, 'tour_id' => (string) $t1, 'vehicle_id' => 'auto'], 1);
    expectLogi((int) $pdo->query("SELECT tour_set FROM n_shipping_assignment WHERE ship_date = '{$d}' AND id_customer = 2")->fetchColumn() === 0
        && $new()->day($d)['by_id'][2]['tour_source'] === 'tour', 'trasa dnia = trasa klienta : bez wyjątku');
    $t2 = $service->createTour(['name' => 'Opole', 'days' => [2, 6]], 1);
    $service->setTourCustomers($t2, ['customers' => [3, ['id' => 3, 'stop' => '4']]]);
    $tours = $service->tours();
    expectLogi(array_column($tours['tours'], 'name') === ['Opole', 'Wrocław Berlin'] && $tours['tours'][0]['customers'][0]['stop'] === 4 && $tours['tours'][0]['days'] === 'wt., sob.',
        'lista tras : klienci bez powtórzeń, dni');
    $service->assign(['date' => $d, 'customer_id' => 3, 'tour_id' => $t1, 'vehicle_id' => 'auto', 'stop' => '3', 'remember' => true], 1);
    expectLogi($pdo->query("SELECT GROUP_CONCAT(CONCAT(id_tour, ':', stop_order)) FROM n_tour_customer WHERE id_customer = 3")->fetchColumn() === "{$t1}:3",
        'na stałe w trasie T1 z kolejnością 3 ; usunięty z trasy Opole jeżdżącej też we wtorki');
    $c3 = $new()->day($d)['by_id'][3];
    expectLogi($c3['tour_source'] === 'tour' && $c3['vehicle_source'] === 'tour' && $c3['stop'] === 3, 'klient 3 : trasa i pojazd z trasy');
    $service->assign(['date' => $d, 'customer_id' => 3, 'tour_id' => $t1, 'vehicle_id' => 'auto', 'stop' => '3', 'remember' => false], 1);
    expectLogi((int) $pdo->query('SELECT COUNT(*) FROM n_tour_customer WHERE id_customer = 3')->fetchColumn() === 0
        && $new()->day($d)['by_id'][3]['tour_source'] === 'day', 'odznaczone : usunięty z trasy, trasa zostaje na ten dzień');
    $service->setTourCustomers($t2, ['customers' => [['id' => 3, 'stop' => 1]]]);
    $moved = $service->setTourCustomers($t1, ['customers' => [['id' => 2, 'stop' => 1], ['id' => 3, 'stop' => 2]]])['moved'];
    expectLogi($moved === [['customer' => 'Jan Kowalski', 'tour' => 'Opole']]
        && $pdo->query('SELECT GROUP_CONCAT(id_tour) FROM n_tour_customer WHERE id_customer = 3')->fetchColumn() === (string) $t1,
        'klient dodany do trasy : usunięty z trasy jeżdżącej w te same dni (wt.)');
    $service->updateTour($t2, ['days' => [6]], 1);
    expectLogi($service->setTourCustomers($t2, ['customers' => [['id' => 3, 'stop' => 1]]])['moved'] === []
        && (int) $pdo->query('SELECT COUNT(*) FROM n_tour_customer WHERE id_customer = 3')->fetchColumn() === 2, 'inne dni tygodnia : klient w obu trasach');
    $service->updateTour($t2, ['days' => [2, 6]], 1);
    expectLogi(count(array_filter($service->tours()['warnings'], fn($w) => str_contains($w, 'Jan Kowalski') && str_contains($w, 'wt.'))) === 1, 'dni trasy zmienione później : ostrzeżenie');
    expectLogi($refused(fn() => $service->createTour(['name' => 'Opole'], 1)), 'nazwa trasy zajęta');
    expectLogi($refused(fn() => $service->createTour(['name' => ' '], 1)), 'nazwa trasy wymagana');
    expectLogi($refused(fn() => $service->createTour(['name' => 'X', 'days' => []], 1)), 'co najmniej jeden dzień');
    expectLogi($refused(fn() => $service->createTour(['name' => 'X', 'days' => [8]], 1)), 'zły dzień tygodnia');
    expectLogi($refused(fn() => $service->createTour(['name' => 'X', 'color' => 'red'], 1)), 'zły kolor');
    expectLogi($refused(fn() => $service->updateTour($t2, ['departure_time' => '7:00'], 1)), 'zła godzina wyjazdu trasy');
    expectLogi($refused(fn() => $service->setTourVehicles($t2, ['vehicle_ids' => [99]])), 'nieznany pojazd trasy');
    expectLogi($refused(fn() => $service->setTourCustomers($t2, ['customers' => [['id' => 99]]])), 'nieznany klient trasy');
    expectLogi($refused(fn() => $service->setTourCustomers($t2, ['customers' => [['id' => 1, 'stop' => 100]]])), 'kolejność 1–99');
    expectLogi($refused(fn() => $service->assign(['date' => $d, 'customer_id' => 1, 'tour_id' => 999], 1)), 'nieznana trasa przydziału');
    $service->deleteTour($t2);
    $pdo->exec("DELETE FROM n_tour_customer WHERE id_tour = {$t2}");
    expectLogi($refused(fn() => $service->updateTour($t2, ['name' => 'Y'], 1)) && count($service->tours()['tours']) === 1, 'trasa usunięta');
    $service->setTourVehicles($t1, ['vehicle_ids' => [1]]);
    $service->setTourCustomers($t1, ['customers' => [['id' => 2, 'stop' => 1]]]);
    $pdo->exec("DELETE FROM n_shipping_assignment WHERE id_customer IN (2, 3)");

    // Lista pojazdów : tylko w użyciu ; podpowiedź z CMR tylko dla pojazdu w użyciu
    $pdo->exec('UPDATE n_vehicle SET active = 0 WHERE id_carrier_vehicle = 2');
    $off = $new()->day($d);
    expectLogi(array_column($off['vehicles'], 'id') === [1] && $off['by_id'][3]['vehicle_id'] === null, 'pojazd nieaktywny : nie na liście i nie podpowiadany');
    $pdo->exec('UPDATE n_vehicle SET active = 1 WHERE id_carrier_vehicle = 2');

    // Bez palet mieszanych
    (new ParamRepository($pdo))->setValue('logistics.mixed_pallets', '0', 1);
    expectLogi($new()->day($d)['by_id'][1]['pallets'] === 4, 'bez mieszanych : A = 2 pełne + 2 niepełne');
    (new ParamRepository($pdo))->setValue('logistics.mixed_pallets', '1', 1);

    // Przydział
    $service->assign(['date' => $d, 'customer_id' => 2, 'vehicle_id' => 2, 'remember' => false], 1);
    $day = $new()->day($d);
    $fleet = array_column($day['fleet'], null, 'id');
    expectLogi($fleet[1]['pallets'] === 3 && !$fleet[1]['over'] && $fleet[2]['pallets'] === 11, 'B przeniesiony na ten dzień do pojazdu 2');
    expectLogi((int) $pdo->query('SELECT COUNT(*) FROM n_tour_customer WHERE id_customer = 2')->fetchColumn() === 1 && $day['by_id'][2]['tour_id'] === $t1, 'trasa klienta bez zmian');
    $service->assign(['date' => $d, 'customer_id' => 1, 'vehicle_id' => null], 1);
    expectLogi($new()->day($d)['by_id'][1]['vehicle_id'] === null && count($new()->day($d)['unassigned']) === 1, 'klient bez pojazdu na ten dzień (przydział ma pierwszeństwo przed trasą)');
    expectLogi($refused(fn() => $service->assign(['date' => $d, 'customer_id' => 1, 'vehicle_id' => 99], 1)), 'nieznany pojazd odrzucony');
    expectLogi($refused(fn() => $service->assign(['date' => '20.10.2026', 'customer_id' => 1, 'vehicle_id' => 1], 1)), 'zły format daty odrzucony');
    expectLogi($refused(fn() => $service->assign(['date' => $d, 'customer_id' => 99, 'vehicle_id' => 1], 1)), 'nieznany klient odrzucony');

    // Pojazdy
    expectLogi($refused(fn() => $service->saveVehicle(3, ['pallet_places' => 0], 1)), 'miejsca paletowe > 0');
    expectLogi($refused(fn() => $service->saveVehicle(3, ['pallet_places' => 18, 'departure_time' => '25:00'], 1)), 'zła godzina wyjazdu');
    $service->saveVehicle(3, ['pallet_places' => '18', 'max_load_kg' => '12 000', 'departure_time' => '05:30', 'active' => true], 1);
    $row = $pdo->query('SELECT * FROM n_vehicle WHERE id_carrier_vehicle = 3')->fetch();
    expectLogi((int) $row['pallet_places'] === 18 && (int) $row['max_load_kg'] === 12000 && $row['departure_time'] === '05:30:00', 'pojemność pojazdu zapisana');
    expectLogi($refused(fn() => $service->addVehicle(['carrier_id' => 2, 'regis_num' => 'X', 'pallet_places' => 10], 1)), 'nieaktywny przewoźnik odrzucony');
    expectLogi($refused(fn() => $service->addVehicle(['carrier_id' => 1, 'regis_num' => ' ', 'pallet_places' => 10], 1)), 'numer rejestracyjny wymagany');
    $id = $service->addVehicle(['carrier_id' => 1, 'regis_num' => 'DX 99', 'pallet_places' => '33', 'driver_name' => 'Ola'], 1);
    expectLogi($pdo->query("SELECT regis_num FROM carrier_vehicle WHERE id = {$id}")->fetchColumn() === 'DX 99'
        && (int) $pdo->query("SELECT pallet_places FROM n_vehicle WHERE id_carrier_vehicle = {$id}")->fetchColumn() === 33, 'nowy pojazd w ERP z pojemnością');

    // Karta produktu
    expectLogi($refused(fn() => $service->savePalletData(12, ['cpl' => 10, 'lpp' => 5, 'cpp' => 0])), 'kartony na paletę > 0');
    expectLogi($refused(fn() => $service->savePalletData(999, ['cpp' => 10])), 'nieznany produkt');
    $saved = $service->savePalletData(12, ['cpl' => '10', 'lpp' => '5', 'cpp' => '50']);
    expectLogi($saved['consistent'] && (int) $pdo->query('SELECT cpp FROM final_product WHERE id = 12')->fetchColumn() === 50, 'dane paletowe zapisane w karcie produktu');
    expectLogi($service->savePalletData(13, ['cpp' => '55'])['consistent'] === false, 'niezgodność warstw zgłoszona');

    expectLogi($service->defaultDate('2026-10-19') === $d, 'domyślny dzień : pierwszy dzień wysyłki od dziś');
    echo "logistics_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
