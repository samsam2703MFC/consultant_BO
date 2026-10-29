<?php
declare(strict_types=1);
/*
 * Logistyka i pakowanie : ekran paletyzacji (na palecie = skan + tablet, gotowe = stan dzielony w kolejności trasy
 * + spakowane bez etykiety, brak), karta produktu 1/1/1 traktowana jak brak danych, pakowanie (normy, średnia, osoby,
 * licznik bez podwójnego liczenia po etykietach), załadunek naczepy (układ automatyczny LIFO, zapis, miejsca
 * nieprawidłowe, dopisanie i zdjęcie klienta), wysłanie planu (wersje, potwierdzenia zmienionych miejsc kasowane)
 * i tablet kierowcy (plan, załadowane, nieznane miejsce). Liczby kontrolne na bazie jednorazowej.
 *
 *   php tests/logistics_board_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide) ; structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migrations
 * tfb appliquées). La base erp_logistics_board_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsBoardRepository;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\LogisticsBoardService;
use App\Services\Logistics\LogisticsService;

function expectBoard(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_logistics_board_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "logistics_board_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['n_prod_param', 'carrier', 'carrier_vehicle', 'n_vehicle', 'n_tour', 'n_tour_vehicle', 'n_tour_customer', 'n_shipping_assignment', 'customer', 'customer_order',
                 'customer_order_product', 'final_product', 'palets_delivery', 'document_cmr', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'raw_material',
                 'category_product', 'carton_stock', 'carton_pallet_connection', 'n_pallet_entry', 'n_packing_count', 'n_load_plan', 'n_load_check', 'n_prod_item',
                 'n_prod_routing_step', 'n_prod_group', 'n_prod_employee', 'n_prod_team', 'n_prod_team_member', 'n_prod_clock', 'user', 'production_employee'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES
        ('logistics.mixed_pallets', '1', '1', 'bool'), ('logistics.pallet_tare_kg', '25', '25', 'decimal'), ('logistics.default_pallet_places', '33', '33', 'int'),
        ('planning.default_departure_time', '20:00', '20:00', 'time'), ('planning.loading_time_min', '60', '60', 'int'), ('logistics.packing_group', 'PAKOWANIE', 'PAKOWANIE', 'string')");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login) VALUES (1, 'Ewa', 'Nowak', 'Ewa', 'ewa@example.invalid', 'ewa')");
    $pdo->exec("INSERT INTO carrier (id, name, active) VALUES (1, 'Trans', 1)");
    $pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname) VALUES (1, 1, 'DX 1', 'Jan', 'K'), (2, 1, 'DX 2', '', '')");
    $pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, max_load_kg, departure_time, active) VALUES (1, 6, 5000, '18:00:00', 1), (2, 33, NULL, NULL, 1)");
    $pdo->exec("INSERT INTO customer (id, name, surname, company, fac_town, fac_country) VALUES
        (1, '', '', 'Sklep A', 'Wrocław', 'PL'), (2, '', '', 'Sklep B', 'Berlin', 'DE'), (3, '', '', 'Sklep C', 'Opole', 'PL')");
    // Tarta : karta 1 / 1 / 1 (wartość domyślna) = brak danych paletowych
    $pdo->exec("INSERT INTO final_product (id, name, cpp, cpl, lpp, ppc, weight, active) VALUES
        (10, 'Croissant', 60, 10, 6, 64, 75, 1), (11, 'Bagietka', 40, 8, 5, 20, 250, 1), (12, 'Tarta', 1, 1, 1, 10, 100, 1)");
    $d = '2026-10-20';
    $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date) VALUES (1, 1, 1, '{$d}'), (2, 2, 1, '{$d}'), (3, 3, 1, '{$d}')");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (1, 10, 70), (1, 11, 30), (2, 10, 120), (3, 11, 10), (3, 12, 5)");
    $pdo->exec("INSERT INTO n_shipping_assignment (ship_date, id_customer, id_carrier_vehicle, vehicle_set, stop_order) VALUES ('{$d}', 1, 1, 1, 1), ('{$d}', 2, 1, 1, 2)");
    // Stan : 50 kartonów Croissant wolnych ; paleta 501 klienta B z 3 kartonami ; tablet : A 10 Croissant + 5 Bagietka
    $values = [];
    for ($i = 1; $i <= 50; $i++) $values[] = "({$i}, 1, 10, 1)";
    for ($i = 51; $i <= 53; $i++) $values[] = "({$i}, 1, 10, 2)";
    $pdo->exec('INSERT INTO carton_stock (id_carton, batch_number, id_product, status) VALUES ' . implode(', ', $values));
    $pdo->exec("INSERT INTO palets_delivery (id_palet, client, delivery_date, status, id_customer, last_update_date) VALUES (501, 'B', '{$d}', 1, 2, '{$d} 09:00:00')");
    $pdo->exec('INSERT INTO carton_pallet_connection (id_palet, id_carton) VALUES (501, 51), (501, 52), (501, 53)');
    $pdo->exec("INSERT INTO n_pallet_entry (ship_date, id_customer, id_product, cartons, source, created_at) VALUES
        ('{$d}', 1, 10, 4, 'tablet', '{$d} 10:00:00'), ('{$d}', 1, 10, 6, 'tablet', '{$d} 10:05:00'), ('{$d}', 1, 11, 5, 'tablet', '{$d} 10:10:00')");
    // Norma pakowania tylko dla Croissant (300 s + 2 s / szt.) ; 2 osoby obecne w grupie PAKOWANIE, 1 wyszła
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product) VALUES (1, 'product', 10)");
    $pdo->exec("INSERT INTO n_prod_routing_step (id_item, step_no, name_pl, name_fr, id_workstation, fixed_time_s, variable_time_s) VALUES (1, 1, 'Pakowanie', 'Emballage', 1, 300, 2)");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr) VALUES (7, 'PAKOWANIE', 'Pakowanie', 'Emballage')");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, active) VALUES (1, 1, 7, 1), (2, 2, 7, 1), (3, 3, 7, 1)");
    $pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, clock_out) VALUES (1, NOW(), NULL), (2, NOW(), NULL), (3, NOW() - INTERVAL 5 HOUR, NOW())");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (1, 'Marek', 'Nowak', 0, 1), (2, 'Ola', 'Kowal', 0, 1), (3, 'Piotr', 'Lis', 0, 1)");

    $new = fn() => new LogisticsBoardService(new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo)), new LogisticsBoardRepository($pdo), new ParamRepository($pdo));
    $board = $new();
    $refused = function (callable $fn): bool {
        try { $fn(); return false; } catch (CustomException) { return true; }
    };
    $line = fn(array $live, int $cid, string $name) => array_values(array_filter(array_column($live['clients'], null, 'id')[$cid]['lines'], fn($l) => $l['name'] === $name))[0];

    // --- Karta 1 / 1 / 1 : brak danych (do poprawienia), nie 5 palet po 1 kartonie
    $day = (new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo)))->day($d);
    $tarta = array_values(array_filter($day['products'], fn($p) => $p['name'] === 'Tarta'))[0];
    expectBoard($tarta['placeholder'] && $tarta['issue'] === 'missing' && $day['by_id'][3]['missing_cpp'] === 1 && $day['by_id'][3]['pallets'] === 1,
        'Tarta 1/1/1 : brak danych paletowych, klient C 1 paleta (nie 1 + 5)');

    // --- Ekran paletyzacji
    $live = $board->live($d);
    expectBoard(array_column($live['clients'], 'id') === [1, 2, 3], 'klienci : pojazd w kolejności przystanków, potem bez pojazdu');
    $t = $live['totals'];
    expectBoard($t['ordered'] === 235 && $t['on_pallet'] === 18 && $t['stock'] === 50 && $t['packed'] === 0 && $t['missing'] === 167
        && $t['ordered'] === $t['on_pallet'] + $t['ready'] + $t['missing'], 'razem : 235 = 18 na paletach + 50 na stanie + 167 brak');
    $a = $line($live, 1, 'Croissant');
    expectBoard($a['on_pallet'] === 10 && $a['entered'] === 10 && $a['scanned'] === 0 && $a['stock'] === 50 && $a['missing'] === 10, 'A Croissant : 4 + 6 z tabletu, stan 50 dla pierwszego przystanku, brak 10');
    $b = $line($live, 2, 'Croissant');
    expectBoard($b['scanned'] === 3 && $b['stock'] === 0 && $b['missing'] === 117, 'B Croissant : 3 zeskanowane, stan już rozdany, brak 117');
    $clients = array_column($live['clients'], null, 'id');
    expectBoard($clients[2]['pallets'] === [['id' => 501, 'cartons' => 3, 'open' => true]] && $t['pallets'] === 1 && $t['pallets_open'] === 1, 'paleta 501 w budowie');
    expectBoard($clients[1]['vehicle']['id'] === 1 && $clients[3]['vehicle'] === null && $clients[1]['departure'] === '18:00' && $clients[3]['departure'] === '20:00', 'pojazd i wyjazd klienta');
    expectBoard($clients[3]['left'] === 15 && $live['alerts'][0] == ['type' => 'no_pallet', 'customer' => 'Sklep C'], 'C bez żadnej palety : alarm');
    $worst = array_values(array_filter($live['alerts'], fn($x) => $x['type'] === 'missing'))[0];
    expectBoard($worst['customer'] === 'Sklep B' && $worst['cartons'] === 117 && $worst['products'] === ['Croissant'], 'alarm : najwięcej brakuje u B');
    expectBoard($live['last_activity'] === "{$d} 10:10:00" && $live['departure'] === '18:00', 'ostatni ruch (tablet) i najwcześniejszy wyjazd');
    expectBoard($clients[1]['lines'][0]['name'] === 'Bagietka', 'linie klienta : najpierw największy brak');

    // --- Pakowanie
    $p = $board->packing($d);
    $items = array_column($p['items'], null, 'name');
    expectBoard(array_keys($items) === ['Bagietka', 'Croissant', 'Tarta'], '3 produkty do spakowania');
    expectBoard($items['Croissant']['need'] === 127 && $items['Croissant']['pieces'] === 127 * 64 && $items['Croissant']['norm']
        && $items['Croissant']['seconds'] === 300 + 2 * 127 * 64, 'Croissant : 127 kartonów, 8128 szt., norma « Pakowanie »');
    expectBoard($items['Bagietka']['need'] === 35 && !$items['Bagietka']['norm'] && $items['Bagietka']['seconds'] === 300 + 2 * 35 * 20, 'Bagietka bez normy : średnia norm');
    expectBoard(array_column($items['Croissant']['clients'], 'cartons', 'name') === ['Sklep A' => 10, 'Sklep B' => 117], 'Croissant : dla kogo');
    expectBoard($p['people'] === ['present' => 2, 'used' => 2, 'group' => 'PAKOWANIE'], '2 osoby obecne (wyszła nie liczy się)');
    expectBoard($p['totals']['seconds'] === array_sum(array_column($p['items'], 'seconds')) && $p['totals']['remaining'] === 167, 'razem czas i kartony');
    expectBoard($refused(fn() => $board->setPacked($d, 10, -1, 1)) && $refused(fn() => $board->setPacked($d, 10, 'abc', 1))
        && $refused(fn() => $board->setPacked($d, 10, 2.5, 1)) && $refused(fn() => $board->setPacked($d, 999, 1, 1)) && $refused(fn() => $board->setPacked('20.10.2026', 10, 1, 1)),
        'licznik : 0–9999 całych, znany produkt, data');
    $p = $board->setPacked($d, 10, 20, 1);
    $items = array_column($p['items'], null, 'name');
    expectBoard($items['Croissant']['packed'] === 20 && $items['Croissant']['remaining'] === 107 && !$items['Croissant']['done'], '20 spakowanych : zostaje 107');
    expectBoard((int) $pdo->query("SELECT base_count FROM n_packing_count WHERE ship_date = '{$d}' AND id_product = 10")->fetchColumn() === 53, 'stan zapamiętany przy pierwszym wpisie (50 + 3)');
    $live = $board->live($d);
    expectBoard($line($live, 1, 'Croissant')['packed'] === 10 && $line($live, 1, 'Croissant')['missing'] === 0 && $line($live, 2, 'Croissant')['packed'] === 10
        && $live['totals']['missing'] === 147, 'spakowane gotowe : A 10, B 10 → brak 147');
    // 5 kartonów z etykietą (na stanie) : już nie liczą się jako spakowane bez etykiety
    $pdo->exec('INSERT INTO carton_stock (id_carton, batch_number, id_product, status) VALUES (54, 2, 10, 1), (55, 2, 10, 1), (56, 2, 10, 1), (57, 2, 10, 1), (58, 2, 10, 1)');
    $live = $board->live($d);
    expectBoard($line($live, 1, 'Croissant')['stock'] === 55 && $line($live, 1, 'Croissant')['packed'] === 5 && $live['totals']['missing'] === 147
        && $live['totals']['ready'] === 55 + 15, 'etykiety po pakowaniu : bez podwójnego liczenia (brak dalej 147)');
    $board->setPacked($d, 10, 25, 1);
    expectBoard((int) $pdo->query("SELECT base_count FROM n_packing_count WHERE ship_date = '{$d}' AND id_product = 10")->fetchColumn() === 53, 'kolejny wpis : stan bazowy bez zmian');

    // --- Załadunek : układ automatyczny (ostatni przystanek przy kabinie, pierwszy przy drzwiach)
    $l = $board->loading($d);
    $v = $l['vehicle'];
    expectBoard($v['id'] === 1 && $v['places'] === 6 && $v['columns'] === 3 && $v['auto'] && $v['sent'] === null, 'pojazd 1 : 6 miejsc, układ automatyczny, nie wysłany');
    expectBoard(array_column($v['customers'], 'pallets', 'id') === [1 => 2, 2 => 2] && $v['customers'][1]['real'][0]['id'] === 501, 'A 2 palety planowane, B 2 (w tym 501)');
    expectBoard($v['layout'] === [['slot' => 1, 'customer_id' => 2, 'pallet_id' => 501], ['slot' => 2, 'customer_id' => 2, 'pallet_id' => null],
        ['slot' => 3, 'customer_id' => 1, 'pallet_id' => null], ['slot' => 4, 'customer_id' => 1, 'pallet_id' => null]] && $v['overflow'] === [] && $v['used'] === 4,
        'LIFO : B (2. przystanek) przy kabinie z paletą 501, A bliżej drzwi');
    expectBoard(array_column($l['vehicles'], 'id') === [1, 2] && array_column($l['unassigned'], 'id') === [3], 'pojazdy i klient bez pojazdu');

    // Zapis układu : miejsce poza naczepą, paleta innego klienta, za dużo palet klienta → poprawione
    $l = $board->saveLoading(['date' => $d, 'vehicle_id' => 1, 'customers' => [1, 2], 'layout' => [
        ['slot' => 6, 'customer_id' => 1], ['slot' => 5, 'customer_id' => 1, 'pallet_id' => 501], ['slot' => 4, 'customer_id' => 1],
        ['slot' => 9, 'customer_id' => 2, 'pallet_id' => 501], ['slot' => 1, 'customer_id' => 99]]], 1);
    $v = $l['vehicle'];
    expectBoard(!$v['auto'] && $v['layout'] === [['slot' => 1, 'customer_id' => 2, 'pallet_id' => 501], ['slot' => 2, 'customer_id' => 2, 'pallet_id' => null],
        ['slot' => 5, 'customer_id' => 1, 'pallet_id' => null], ['slot' => 6, 'customer_id' => 1, 'pallet_id' => null]],
        'zapis : A na 5 i 6 (3. paleta A odrzucona, numer cudzej palety usunięty), B dołożony od kabiny z 501');
    expectBoard($refused(fn() => $board->saveLoading(['date' => $d, 'vehicle_id' => 99], 1)) && $refused(fn() => $board->saveLoading(['date' => $d, 'vehicle_id' => 1, 'customers' => [42]], 1)),
        'nieznany pojazd albo klient bez zamówień');

    // Dopisanie klienta C na koniec pojazdu, potem zdjęcie z pojazdu
    $v = $board->saveLoading(['date' => $d, 'vehicle_id' => 1, 'append' => [3]], 1)['vehicle'];
    expectBoard(array_column($v['customers'], 'id') === [1, 2, 3] && $v['customers'][2]['stop'] === 3
        && array_values(array_filter($v['layout'], fn($s) => $s['customer_id'] === 3)) === [['slot' => 3, 'customer_id' => 3, 'pallet_id' => null]],
        'C dopisany jako 3. przystanek, paleta na pierwszym wolnym miejscu');
    $l = $board->saveLoading(['date' => $d, 'vehicle_id' => 1, 'customers' => [2, 1], 'unassign' => [3]], 1);
    expectBoard(array_column($l['vehicle']['customers'], 'id') === [2, 1] && array_column($l['unassigned'], 'id') === [3]
        && $pdo->query("SELECT CONCAT(COALESCE(id_carrier_vehicle, '-'), '|', vehicle_set) FROM n_shipping_assignment WHERE ship_date = '{$d}' AND id_customer = 3")->fetchColumn() === '-|1',
        'kolejność B, A ; C zdjęty z pojazdu (bez pojazdu na ten dzień)');
    $board->saveLoading(['date' => $d, 'vehicle_id' => 1, 'customers' => [1, 2], 'layout' => null], 1);
    expectBoard($board->loading($d, 1)['vehicle']['auto'], 'układ null : znów automatyczny');

    // --- Wysłanie na tablet kierowcy
    expectBoard($board->driverPlan($d, 1) === null && $board->driverSummary($d, 1) === null, 'przed wysłaniem : brak planu u kierowcy');
    expectBoard($refused(fn() => $board->sendLoading(['date' => $d, 'vehicle_id' => 2], 1)), 'pojazd bez klientów : nic do wysłania');
    $sent = $board->sendLoading(['date' => $d, 'vehicle_id' => 1], 1)['vehicle']['sent'];
    expectBoard($sent['version'] === 1 && $sent['by'] === 'Ewa N.' && !$sent['changed'] && $sent['total'] === 4 && $sent['loaded'] === 0, 'wersja 1 wysłana przez Ewa N.');
    $plan = $board->driverPlan($d, 1);
    expectBoard($plan['places'] === 6 && $plan['total'] === 4 && array_column($plan['stops'], 'name') === ['Sklep A', 'Sklep B'] && $plan['stops'][0]['stop'] === 1
        && array_column($plan['stops'][1]['slots'], 'slot') === [1, 2] && $plan['stops'][1]['slots'][0]['pallet_id'] === 501, 'plan kierowcy : przystanki w kolejności, miejsca B 1–2 z paletą 501');
    expectBoard($board->driverCheck($d, 1, 5, true, 3, 1) === null, 'miejsce spoza planu odrzucone');
    $board->driverCheck($d, 1, 1, true, 3, 1);
    $plan = $board->driverCheck($d, 1, 3, true, 3, 1);
    expectBoard($plan['loaded'] === 2 && $plan['slots'][0]['loaded'] && $board->driverSummary($d, 1) === ['version' => 1, 'sent_at' => $plan['sent_at'], 'total' => 4, 'loaded' => 2],
        'kierowca : 2 palety załadowane');
    expectBoard($board->driverCheck($d, 1, 3, false, 3, 1)['loaded'] === 1, 'cofnięcie załadunku');
    $board->driverCheck($d, 1, 3, true, 3, 1);
    // Biuro przestawia A z miejsca 3 na 6 : nowa wersja, potwierdzenie miejsca 3 skasowane, miejsce 1 zostaje
    $board->saveLoading(['date' => $d, 'vehicle_id' => 1, 'layout' => [['slot' => 1, 'customer_id' => 2, 'pallet_id' => 501], ['slot' => 2, 'customer_id' => 2],
        ['slot' => 6, 'customer_id' => 1], ['slot' => 4, 'customer_id' => 1]]], 1);
    expectBoard($board->loading($d, 1)['vehicle']['sent']['changed'], 'układ zmieniony po wysłaniu : « zmiany nie wysłane »');
    $sent = $board->sendLoading(['date' => $d, 'vehicle_id' => 1], 1)['vehicle']['sent'];
    expectBoard($sent['version'] === 2 && !$sent['changed'] && $sent['loaded'] === 1
        && array_keys((new LogisticsBoardRepository($pdo))->checks($d, 1)) === [1], 'wersja 2 : potwierdzenie miejsca 3 skasowane, miejsce 1 zostaje');
    expectBoard(array_column($board->driverPlan($d, 1)['slots'], 'slot') === [1, 2, 4, 6], 'kierowca widzi nowy układ');

    // --- Paletyzacja (tablet pakowania, tryb « Paletyzacja ») : pojazd → klienci w kolejności, wpisy, operator, cofnięcie
    $p = $board->pallets($d, 1);
    $a = $p['clients'][0];
    expectBoard(array_column($p['vehicles'], 'id') === [1] && $p['vehicle_id'] === 1 && array_column($p['clients'], 'id') === [1, 2] && $p['customer_id'] === 1,
        'pojazd 1 : klienci A, B w kolejności rozładunku ; pierwszy z resztą wybrany');
    expectBoard(array_column($a['lines'], 'name') === ['Bagietka', 'Croissant'] && $a['lines'][1]['on_pallet'] === 10 && $a['lines'][1]['left'] === 60
        && $a['ordered'] === 100 && $a['left'] === 85, 'A : Croissant 10 na palecie (4 + 6), zostało 60 ; razem zostało 85');
    expectBoard(array_column(((array) $p['entries'])[10], 'cartons') === [4, 6] && array_column($p['people'], 'name') === ['Marek Nowak', 'Ola Kowal'],
        'wpisy A (4 + 6) ; operatorzy = osoby obecne');
    $all = $board->pallets($d, null, 3);
    expectBoard($all['vehicle_id'] === null && array_column($all['clients'], 'id') === [1, 2, 3] && $all['customer_id'] === 3 && $all['clients'][2]['n'] === 3,
        'wszyscy klienci dnia : A, B, potem C bez pojazdu ; wybrany C');
    expectBoard($board->pallets($d, 99)['vehicle_id'] === null, 'nieznany pojazd : wszyscy klienci');
    $board->addPalletEntry($d, 1, 10, 12, 1, 7, null);
    $p = $board->pallets($d, 1, 1);
    $croissant = ((array) $p['entries'])[10];
    $last = $croissant[count($croissant) - 1];
    expectBoard($p['clients'][0]['lines'][1]['on_pallet'] === 22 && $p['clients'][0]['lines'][1]['left'] === 48 && $last['cartons'] === 12 && $last['who'] === 'Marek Nowak'
        && $last['source'] === 'tablet', 'operator Marek kładzie 12 kartonów : 4 + 6 + 12 = 22, zostało 48');
    expectBoard($board->live($d)['totals']['on_pallet'] === 18 + 12, 'ekran paletyzacji widzi wpis z tabletu');
    expectBoard(!$board->ordered($d, 2, 11) && $refused(fn() => $board->addPalletEntry($d, 2, 11, 1, null, 7, null)), 'produkt niezamówiony przez klienta odrzucony');
    expectBoard($refused(fn() => $board->addPalletEntry($d, 1, 10, 0, null, 7, null)) && $refused(fn() => $board->addPalletEntry($d, 1, 10, 1000, null, 7, null))
        && $refused(fn() => $board->addPalletEntry($d, 1, 10, 1, 99, 7, null)), 'kartony 1–999, operator istniejący');
    expectBoard($board->deletePalletEntry($last['id']) === ['date' => $d, 'customer_id' => 1] && $board->pallets($d, 1, 1)['clients'][0]['lines'][1]['on_pallet'] === 10
        && $board->deletePalletEntry($last['id']) === null, 'cofnięcie ostatniego wpisu : znów 10 ; drugi raz nic');
    $pdo->exec("INSERT INTO n_pallet_entry (ship_date, id_customer, id_product, cartons, source) VALUES ('{$d}', 1, 11, 2, 'erp')");
    expectBoard($board->deletePalletEntry((int) $pdo->lastInsertId()) === null, 'wpis z ERP nie cofa się z tabletu');

    echo "logistics_board_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
