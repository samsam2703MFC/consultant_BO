<?php
declare(strict_types=1);
/*
 * Paleta mieszana [215] (makieta 214) : kolejność warstw (cięższa na dole, mniejsze kartony i niepełna warstwa wyżej),
 * kontrole (wysokość, ciężarówka, waga, ładunek na karton, podłoga ≥ 90 %, niepełna warstwa), propozycje ; serwis na bazie
 * jednorazowej : propozycja dnia (palety klienta teraz / po), podgląd, zatwierdzenie (odmowy), dzień wysyłki liczy paletę
 * jako jedną, tablet pakowania (kolejność), etykieta PDF, usunięcie.
 *
 *   php tests/mixed_pallet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_mixed_pallet_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Domain\Logistics\MixedPallet;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsBoardRepository;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Logistics\MixedPalletRepository;
use App\Repositories\Product\CartonRepository;
use App\Repositories\Workshop\ItemRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\LogisticsBoardService;
use App\Services\Logistics\LogisticsService;
use App\Services\Logistics\MixedPalletService;
use App\Services\Product\CartonService;

function expectMx(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Domena : makieta 214 (Abricot 590 × 390 × 210, Baguette 596 × 395 × 102, po 4 na warstwę) -------------------
$L = ['max_height_mm' => 1800, 'max_kg' => 1000, 'truck_height_mm' => 2550];
$abricot = ['product_id' => 1, 'name' => 'Abricot', 'length_mm' => 590, 'width_mm' => 390, 'height_mm' => 210, 'per_layer' => 4, 'carton_kg' => 10.37, 'lpp' => 8];
$baguette = ['product_id' => 2, 'name' => 'Baguette', 'length_mm' => 395, 'width_mm' => 596, 'height_mm' => 102, 'per_layer' => 4, 'carton_kg' => 5.13, 'lpp' => 16];
$codes = fn(array $s) => array_column($s['checks'], 'status', 'code');

$s = MixedPallet::stack([['cartons' => 36] + $baguette, ['cartons' => 12] + $abricot], $L);
expectMx(array_column($s['groups'], 'name') === ['Abricot', 'Baguette'] && $s['height_mm'] === 144 + 630 + 918 && $s['kg'] === 334.1 && $s['cartons'] === 48 && $s['layers'] === 12,
    'makieta : Abricot (41,5 kg / warstwę) na dole, 1 692 mm, 334,1 kg');
expectMx($s['groups'][0]['load_kg'] === 66.9 && $s['groups'][0]['floor_pct'] === 95.9 && $s['groups'][1]['z_mm'] === 774, 'ładunek na najniższy karton 66,9 kg, podłoga 95,9 %, Baguette od 774 mm');
expectMx($s['ok'] && $codes($s) === ['height' => 'ok', 'weight' => 'ok', 'order' => 'ok', 'floor_same' => 'ok', 'floor_rule' => 'info']
    && $s['checks'][3]['params']['base'] === '600 × 400', 'kontrole makiety : wszystko ok, ta sama podłoga 600 × 400 (wymiary obrócone też)');

// Inna podłoga : mniejszy karton (390 × 195, 8 na warstwę = 63 %) zawsze wyżej, nawet cięższy ; pod nim pełne warstwy ≥ 90 %.
$cookie = ['product_id' => 3, 'name' => 'Cookie', 'length_mm' => 390, 'width_mm' => 195, 'height_mm' => 120, 'per_layer' => 8, 'carton_kg' => 7.0];
$s = MixedPallet::stack([['cartons' => 16] + $cookie, ['cartons' => 36] + $baguette], $L);
expectMx(array_column($s['groups'], 'name') === ['Baguette', 'Cookie'] && $codes($s)['order'] === 'warn' && $codes($s)['floor_diff'] === 'ok' && $s['ok'],
    'mniejsze kartony wyżej (Cookie 56 kg / warstwę na Baguette 20,5 kg) : ostrzeżenie kolejności, podłoga pod spodem 98 %');
$narrow = ['per_layer' => 3, 'name' => 'Abricot 3'] + $abricot;
$s = MixedPallet::stack([['cartons' => 9] + $narrow, ['cartons' => 16] + $cookie], $L);
expectMx($codes($s)['floor_small'] === 'error' && !$s['ok'], 'warstwa 3 kartonów (72 %) pod inną podłogą : błąd');

// Niepełna warstwa tylko na górze ; dwie niepełne = błąd.
$s = MixedPallet::stack([['cartons' => 10] + $abricot, ['cartons' => 36] + $baguette], $L);
expectMx(array_column($s['groups'], 'name') === ['Baguette', 'Abricot'] && $codes($s)['partial_top'] === 'ok' && $codes($s)['order'] === 'warn' && $s['groups'][1]['partial'] === 2,
    'Abricot 10 kart. (ostatnia warstwa 2 z 4) na górze, choć cięższy');
expectMx(!isset($codes($s)['load']), 'Baguette pod Abricot : 67 kg na karton, mniej niż na palecie samego produktu (16 warstw : 77 kg)');
$s = MixedPallet::stack([['cartons' => 10] + $abricot, ['cartons' => 36, 'lpp' => 8] + $baguette], $L);
expectMx($codes($s)['load'] === 'warn' && $s['groups'][0]['load_kg'] === 67.0 && $s['groups'][0]['own_load_kg'] === 35.9,
    'ładunek na karton Baguette (67 kg) większy niż na palecie samego produktu z 8 warstwami (35,9 kg) : ostrzeżenie');
$s = MixedPallet::stack([['cartons' => 10] + $abricot, ['cartons' => 34] + $baguette], $L);
expectMx($codes($s)['partial'] === 'error' && !$s['ok'], 'dwie niepełne warstwy : błąd');

// Wysokość, ciężarówka, limity produktu.
$s = MixedPallet::stack([['cartons' => 12] + $abricot, ['cartons' => 60] + $baguette], $L);
expectMx($s['height_mm'] === 2304 && $codes($s)['height'] === 'error' && !isset($codes($s)['truck']), '2 304 mm > 1 800 mm, w ciężarówce 2 550 mm');
$s = MixedPallet::stack([['cartons' => 12] + $abricot, ['cartons' => 96] + $baguette], ['max_height_mm' => 3000] + $L);
expectMx($codes($s)['truck'] === 'error', '3 222 mm > wnętrze ciężarówki');
$s = MixedPallet::stack([['cartons' => 12, 'max_height_mm' => 1850] + $abricot, ['cartons' => 36, 'max_height_mm' => 1900] + $baguette], $L);
expectMx($s['max_height_mm'] === 1850, 'limity produktów zastępują parametr : najniższy 1 850 mm');
$s = MixedPallet::stack([['cartons' => 12, 'max_height_mm' => 1850] + $abricot, ['cartons' => 36] + $baguette], $L);
expectMx($s['max_height_mm'] === 1800, 'produkt bez własnego limitu : parametr 1 800 mm');
expectMx(MixedPallet::stack([], $L)['groups'] === [] && MixedPallet::stack([], $L)['checks'] === [], 'pusta paleta');

// Propozycje : Abricot + Baguette razem ; za wysoki dodatek na osobnej palecie.
$bins = MixedPallet::propose([['cartons' => 12] + $abricot, ['cartons' => 36] + $baguette, ['cartons' => 40, 'product_id' => 4, 'name' => 'Pain'] + $abricot], $L);
expectMx(count($bins) === 2 && array_column($bins[0], 'name') === ['Pain'] && array_column($bins[1], 'name') === ['Baguette', 'Abricot'],
    'propozycja : Pain (10 warstw) sam, Baguette + Abricot razem');

// --- Serwis na bazie ------------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_mixed_pallet_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "mixed_pallet_test: PARTIAL (domain OK, no database: {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['n_prod_param', 'carrier', 'carrier_vehicle', 'n_vehicle', 'n_tour', 'n_tour_vehicle', 'n_tour_customer', 'n_shipping_assignment', 'customer', 'customer_order',
                 'customer_order_product', 'final_product', 'palets_delivery', 'document_cmr', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'raw_material',
                 'category_product', 'carton_stock', 'carton_pallet_connection', 'n_pallet_entry', 'n_packing_count', 'n_prod_item', 'n_prod_routing_step', 'n_prod_group',
                 'n_prod_employee', 'n_prod_clock', 'user', 'production_employee', 'n_carton', 'n_prod_item_logistics', 'flattened_recipe_ingredient', 'category_material',
                 'n_mixed_pallet', 'n_mixed_pallet_item'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES
        ('logistics.mixed_pallets', '1', '1', 'bool'), ('logistics.pallet_tare_kg', '25', '25', 'decimal'), ('planning.default_departure_time', '20:00', '20:00', 'time')");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login) VALUES (1, 'Ewa', 'Nowak', 'Ewa', 'ewa@example.invalid', 'ewa')");
    $pdo->exec("INSERT INTO customer (id, name, surname, company, fac_town, fac_country) VALUES (1, '', '', 'Berdiff', 'Bruxelles', 'BE'), (2, '', '', 'Sklep B', 'Liège', 'BE')");
    // Abricot : 10 × 1 000 g + karton 370 g = 10,37 kg, 4 × 8 = 32 / paleta ; Baguette : 20 × 250 g + 130 g = 5,13 kg, 4 × 16 = 64 ; Tarta bez kartonu i bez cpp.
    $pdo->exec("INSERT INTO final_product (id, name, cpp, cpl, lpp, ppc, weight, active) VALUES
        (20, 'Abricot & cerise', 32, 4, 8, 10, 1000, 1), (21, 'Baguette poivre & sel', 64, 4, 16, 20, 250, 1), (22, 'Tarta', 0, 0, 0, 10, 100, 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, id_category, price_per_unit, packaging_unit_weight_g, is_part_of_package) VALUES
        (4006, 'Karton 590 x 390 x 210', 200, 2.5, 370, 1), (4007, 'Karton 596 x 395 x 102', 200, 1.5, 130, 1)");
    $pdo->exec("INSERT INTO n_carton (id_raw_material, length_mm, width_mm, height_mm, kind) VALUES (4006, 590, 390, 210, 'klapowy'), (4007, 596, 395, 102, 'klapowy')");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product) VALUES (1, 'product', 20), (2, 'product', 21)");
    $pdo->exec("INSERT INTO n_prod_item_logistics (id_item, id_carton) VALUES (1, 4006), (2, 4007)");
    $pdo->exec("INSERT INTO final_product_ppwr_sales_packaging (id_final_product, id_outer_raw_material) VALUES (20, 4006), (21, 4007)");
    $d = '2026-11-03';
    $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date) VALUES (1, 1, 1, '{$d}'), (2, 2, 1, '{$d}')");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (1, 20, 12), (1, 21, 100), (1, 22, 5), (2, 20, 5)");

    $cartons = new CartonService(new CartonRepository($pdo), new ParamRepository($pdo), new ItemRepository($pdo));
    $logistics = fn() => new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo), $cartons);
    $mx = new MixedPalletService($logistics(), new MixedPalletRepository($pdo), $cartons);
    $refused = function (callable $fn, ?string $part = null): bool {
        try { $fn(); return false; } catch (CustomException $e) { return $part === null || str_contains($e->getMessage(), $part); }
    };

    $day = $logistics()->day($d);
    expectMx($day['by_id'][1]['pallets'] === 3 && $day['by_id'][1]['stack_pallets'] === 0, 'przed : Berdiff 3 palety (Abricot reszta, Baguette pełna + reszta), Tarta bez kartonu');

    $p = $mx->page(['date' => $d]);
    $prop = $p['proposals'][0] ?? null;
    expectMx(count($p['proposals']) === 1 && $prop['customer_id'] === 1 && !$prop['confirmed'] && $prop['now'] === 3 && $prop['after'] === 2
        && array_map(fn($x) => [$x['name'], $x['layers']], $prop['products']) === [['Abricot & cerise', 3], ['Baguette poivre & sel', 9]] && $prop['height_mm'] === 1692,
        'propozycja dnia : Berdiff Abricot 3 w. + Baguette 9 w. (reszta po pełnej palecie), 3 → 2 palety, 1 692 mm');
    $e = $p['editor'];
    expectMx($e && $e['customer']['id'] === 1 && $e['ok'] && $e['places'] === ['now' => 3, 'after' => 2] && $e['kg'] === 334.1 && count($e['groups'][0]['rects']) === 4
        && $e['groups'][1]['full_pallets'] === 1 && $e['checks'][1]['text'] === 'Waga 334 kg ≤ maks. 1000 kg ; ładunek na najniższy karton : 66,9 kg',
        'edytor : pierwsza propozycja, miejsca 3 → 2, widok z góry (4 kartony), teksty kontroli');
    $tarta = array_values(array_filter($e['options'], fn($o) => $o['name'] === 'Tarta'))[0];
    expectMx($tarta['reason'] === 'brak kartonu z katalogu', 'produkt bez kartonu : nie do dodania (powód)');

    // Podgląd : warstwy + (najwyżej zamówione), za wysoko.
    $v = $mx->preview(['date' => $d, 'customer' => 1, 'items' => '20:12,21:500']);
    expectMx($v['groups'][1]['cartons'] === 100 && !$v['ok'] && $v['checks'][0]['status'] === 'error', 'Baguette najwyżej 100 kart. (zamówione) ; 25 warstw za wysoko');
    $v = $mx->preview(['date' => $d, 'customer' => 1, 'items' => '20:12,21:40']);
    expectMx($v['ok'] && $v['height_mm'] === 1794, '10 warstw Baguette : 1 794 mm mieści się');

    // Zatwierdzenie : odmowy, potem zapis.
    $items = fn(array $m) => array_map(fn($pid, $n) => ['product_id' => $pid, 'cartons' => $n], array_keys($m), $m);
    expectMx($refused(fn() => $mx->confirm(['date' => $d, 'customer_id' => 1, 'items' => $items([20 => 12, 21 => 120])], 1), 'najwyżej 100'), 'więcej niż zamówione : odmowa');
    expectMx($refused(fn() => $mx->confirm(['date' => $d, 'customer_id' => 1, 'items' => $items([20 => 12, 21 => 44])], 1), 'nie przechodzi kontroli'), 'za wysoka : odmowa');
    expectMx($refused(fn() => $mx->confirm(['date' => $d, 'customer_id' => 1, 'items' => $items([20 => 12])], 1), 'co najmniej dwa'), 'jeden produkt : odmowa');
    expectMx($refused(fn() => $mx->confirm(['date' => $d, 'customer_id' => 1, 'items' => $items([20 => 12, 22 => 5])], 1), 'bez danych'), 'produkt bez kartonu : odmowa');
    expectMx($refused(fn() => $mx->page(['date' => $d, 'customer' => 99])), 'klient bez zamówień tego dnia : 404');
    $after = $mx->confirm(['date' => $d, 'customer_id' => 1, 'items' => $items([20 => 12, 21 => 36])], 1);
    $id = $after['editor']['pallet_id'];
    $row = $pdo->query('SELECT * FROM n_mixed_pallet')->fetch();
    expectMx($id > 0 && (int) $row['height_mm'] === 1692 && (float) $row['kg'] === 334.1 && (int) $row['confirmed_by'] === 1 && $after['editor']['confirmed']['who'] === 'Ewa'
        && $pdo->query('SELECT GROUP_CONCAT(id_final_product, ":", cartons, ":", layers ORDER BY position) FROM n_mixed_pallet_item')->fetchColumn() === '20:12:3,21:36:9',
        'zatwierdzona : wysokość, waga, kto ; produkty od dołu (Abricot 12 / 3 w., Baguette 36 / 9 w.)');
    expectMx(count($after['proposals']) === 1 && $after['proposals'][0]['confirmed'] && $after['proposals'][0]['now'] === 3 && $after['proposals'][0]['after'] === 2,
        'lista : paleta zatwierdzona (3 → 2), bez nowej propozycji');

    // Dzień wysyłki : paleta mieszana = jedno miejsce, kartony zdjęte z palet zwykłych.
    $day = $logistics()->day($d);
    $c = $day['by_id'][1];
    expectMx($c['pallets'] === 2 && $c['stack_pallets'] === 1 && $c['carton_types'][0]['id'] === 'stack' && $c['cartons'] == 117.0
        && $c['carton_types'][0]['pallets'][0]['load']['height_mm'] === 1692 && $c['weight_kg'] === round(12 * 10.0 + 100 * 5.0 + 5 * 1.0 + 2 * 25, 1),
        'dzień : Berdiff 2 palety (mieszana + pełna Baguette), 117 kartonów (z Tartą bez palety), wysokość palety mieszanej 1 692 mm');
    $board = new LogisticsBoardService($logistics(), new LogisticsBoardRepository($pdo), new ParamRepository($pdo), $cartons);
    $pal = $board->pallets($d, null, 1);
    $cl = array_column($pal['clients'], null, 'id')[1];
    expectMx(array_column($cl['mixed'][0]['items'], 'product') === ['Abricot & cerise', 'Baguette poivre & sel'] && count($cl['load_final']['pallets']) === 2
        && $cl['load_final']['pallets'][0]['stack'], 'tablet pakowania : kolejność układania od dołu, « po zamówieniu » z paletą mieszaną');

    // Zamówienie zmniejszone po zatwierdzeniu : liczone najwyżej zamówione.
    $pdo->exec('UPDATE customer_order_product SET quantity_carton = 8 WHERE id_order = 1 AND id_product = 20');
    $c = $logistics()->day($d)['by_id'][1];
    expectMx($c['carton_types'][0]['cartons'] == 44.0 && $c['pallets'] === 2, 'Abricot 8 zamiast 12 : na palecie mieszanej 8 + 36');
    $pdo->exec('UPDATE customer_order_product SET quantity_carton = 12 WHERE id_order = 1 AND id_product = 20');

    // Etykieta PDF : zatwierdzona i projekt.
    $pdf = $mx->label(['pallet' => $id]);
    expectMx(str_starts_with($pdf['file_string'], '%PDF') && str_contains($pdf['filename'], '2026-11-03_Berdiff'), 'etykieta PDF zatwierdzonej palety');
    expectMx(str_starts_with($mx->label(['date' => $d, 'customer' => 1, 'items' => '20:12,21:36'])['file_string'], '%PDF'), 'etykieta projektu');

    // Poprawka (te same produkty, mniej warstw) i usunięcie.
    $mx->confirm(['date' => $d, 'customer_id' => 1, 'pallet_id' => $id, 'items' => $items([20 => 8, 21 => 36])], 1);
    expectMx((int) $pdo->query('SELECT COUNT(*) FROM n_mixed_pallet')->fetchColumn() === 1
        && $pdo->query('SELECT GROUP_CONCAT(cartons ORDER BY position) FROM n_mixed_pallet_item')->fetchColumn() === '8,36', 'poprawka : ta sama paleta, nowe kartony');
    $mx->delete($id);
    expectMx((int) $pdo->query('SELECT COUNT(*) FROM n_mixed_pallet_item')->fetchColumn() === 0 && $logistics()->day($d)['by_id'][1]['pallets'] === 3, 'usunięta : znowu 3 palety');
    expectMx($refused(fn() => $mx->delete($id)), 'usunięta drugi raz : 404');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "mixed_pallet_test: OK\n";
