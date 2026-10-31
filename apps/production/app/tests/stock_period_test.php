<?php
declare(strict_types=1);
/*
 * Prognoza zapasu wg planu logistyki i okresy stanów wyjątkowych [216] : dzień powrotu z dniami blokady (bez bazy) ; na
 * bazie jednorazowej : kartony na wyjazd (koszyk klienta, zamówienia w tygodniu planu), zapas potrzebny (+ bezpieczeństwo),
 * projekcja od zapasu dziś (produkcja, zapas przed wyjazdem, palety z innymi produktami, zamrażarka), stany okresu z
 * prognozy, okres : utwórz, zmień stany w kartonach, zatwierdź (nakładanie się), obowiązywanie (StockLevels), cofnij, usuń.
 *
 *   php tests/stock_period_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 136 appliquée). La base erp_stock_period_test est
 * recréée puis supprimée. Dates relatives à aujourd'hui (le plan commence lundi prochain).
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Stock\StockForecastService;
use App\Services\Stock\StockPeriodService;

function expectStock(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectStockFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectStock((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Bez bazy : powrót ciężarówki -------------------------------------------------------------------------
$ret = new ReflectionMethod(StockForecastService::class, 'returnDay');
$hol = ['2026-12-24' => ['PL' => 'Wigilia'], '2026-12-25' => ['PL' => 'Boże Narodzenie', 'DE' => 'Weihnachten'], '2026-12-26' => ['PL' => 'Drugi dzień', 'DE' => '2. Weihnachtstag']];
[$day, $blocked] = $ret->invoke(null, ['delivery_date' => '2026-12-23'], null, 2, $hol, 'PL', ['DE']);
// 25.12 normalnie ; 24.12 Wigilia (PL), 25.12 i 26.12 (DE), 27.12 niedziela (DE) : + 4 dni → 29.12.
expectStock($day === '2026-12-29' && array_column($blocked, 'date') === ['2026-12-24', '2026-12-25', '2026-12-26', '2026-12-27'], 'powrót : dostawa 23.12 + 2 dni + 4 dni blokady = 29.12 (' . $day . ')');
expectStock(str_contains($blocked[0]['why'], 'Wigilia (PL)') && str_contains($blocked[1]['why'], '(DE)') && str_contains($blocked[3]['why'], 'niedziela'), 'powrót : powody blokady (święto kraju wyjazdu, przejazdu, niedziela)');
[$day] = $ret->invoke(null, ['delivery_date' => '2026-11-04'], '2026-11-06', 2, [], 'PL', ['DE']);
expectStock($day === '2026-11-06', 'powrót : z trasy (Kalendarz tras), bez blokady');
[$day, $blocked] = $ret->invoke(null, ['delivery_date' => '2026-11-10'], null, 1, ['2026-11-11' => ['PL' => 'Święto Niepodległości', 'BE' => 'Armistice']], 'PL', ['DE']);
expectStock($day === '2026-11-12' && count($blocked) === 1, 'powrót : 11.11 święto PL w drodze powrotnej → 12.11');

// --- Baza jednorazowa ------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_stock_period_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "stock_period_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['customer', 'customer_order', 'customer_order_product', 'final_product', 'final_product_ppwr_sales_packaging', 'category_product', 'carrier', 'carrier_vehicle',
                 'n_vehicle', 'n_tour', 'n_tour_customer', 'n_tour_vehicle', 'document_cmr', 'n_shipping_assignment', 'n_prod_param', 'n_prod_employee', 'production_employee',
                 'n_position', 'n_logistics_plan_week', 'n_logistics_plan_load', 'n_logistics_plan_vehicle', 'n_logistics_event', 'n_logistics_customer_coef',
                 'n_logistics_absence', 'n_logistics_holiday', 'n_logistics_trip', 'n_prod_item', 'n_prod_product_settings', 'carton_stock', 'erp_company_other_settings',
                 'user', 'n_stock_period', 'n_stock_period_item'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('logistics.default_pallet_places', '33', '33', 'int'), ('logistics.mixed_pallets', '0', '0', 'bool'),
        ('logistics.leftover_fill_max', '0', '0', 'decimal'), ('logistics.plan_departure_country', 'PL', 'PL', 'string'), ('logistics.plan_delivery_country', 'BE', 'BE', 'string'),
        ('logistics.plan_transit_countries', 'DE', 'DE', 'string'), ('logistics.plan_delivery_days', '1', '1', 'int'), ('logistics.plan_avg_weeks', '13', '13', 'int'),
        ('stock.plan_safety_pct', '25', '25', 'decimal'), ('stock.freezer_pallet_places', '40', '0', 'int'), ('stock.suggest_min_weeks', '1', '1', 'decimal')");
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4)");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (5, 'kierownik', 'Anna Kierownik')");
    $pdo->exec("INSERT INTO customer (id, company, fac_town) VALUES (1, 'Alfa s.a.', 'Corbais')");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Pieczywo')");
    // Bagietka : 10 szt. w kartonie, 10 kartonów na paletę, 8 zł surowców / karton ; Rogal : 5 szt., 10 kart./pal. ; Chleb (MTO, poza prognozą).
    $pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, cpl, lpp, cpp, active, pre_calculated_raw_price_per_carton) VALUES
        (1, 'Bagietka', 1, 100, 10, 5, 2, 10, 1, 8), (2, 'Rogal', 1, 60, 5, 5, 2, 10, 1, 4), (3, 'Chleb', 1, 500, 4, 5, 2, 10, 1, 6)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product) VALUES (11, 'product', 1), (12, 'product', 2), (13, 'product', 3)");
    // Stany normalne (jednostka bazowa = sztuki) : Bagietka 20–40 kart., Rogal 10–20 kart.
    $pdo->exec("INSERT INTO n_prod_product_settings (id_item, mode, stock_min, stock_max) VALUES (11, 'MTS', 200, 400), (12, 'MTS', 50, 100), (13, 'MTO', NULL, NULL)");
    // Zapas dziś : 12 kartonów Bagietki (wolne i na palecie), 25 kartonów Chleba (inne produkty w zamrażarce : 2,5 palety), 3 wysłane (nie liczą się).
    $carton = 0;
    $stock = function (int $product, int $n, int $status) use ($pdo, &$carton) {
        for ($i = 0; $i < $n; $i++) { $carton++; $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status) VALUES ({$carton}, 1, {$product}, {$status})"); }
    };
    $stock(1, 10, 1);
    $stock(1, 2, 2);
    $stock(1, 3, 3);
    $stock(3, 25, 1);

    $today = date('Y-m-d');
    $monday = date('Y-m-d', strtotime($today . ' -' . ((int) date('N', strtotime($today)) - 1) . ' days'));
    $order = 0;
    $add = function (string $date, array $lines, int $status = 3) use ($pdo, &$order) {
        $order++;
        $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date) VALUES ({$order}, 1, {$status}, '{$date}', '{$date}')");
        foreach ($lines as $p => $n) $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton) VALUES ({$order}, {$p}, {$n}, {$n})");
    };
    // 13 tygodni historii : co tydzień 30 kart. Bagietki + 10 kart. Rogala = 4 palety ; koszyk 75 % / 25 %.
    for ($i = 1; $i <= 13; $i++) $add(date('Y-m-d', strtotime($monday . " -{$i} weeks +1 day")), [1 => 30, 2 => 10]);
    $planStart = date('Y-m-d', strtotime($monday . ' +1 week'));
    // Zamówienie już złożone w drugim tygodniu planu : tylko Bagietka, 50 kartonów (5 palet).
    $add(date('Y-m-d', strtotime($planStart . ' +1 week +1 day')), [1 => 50], 1);

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $builder->build();
    $fc = $c->get(StockForecastService::class);
    $periods = $c->get(StockPeriodService::class);

    // --- Prognoza -----------------------------------------------------------------------------------------
    $f = $fc->forecast($today);
    $W = $f['weeks'];
    $n = count($W);
    expectStock($n === 12 && $W[0]['week_start'] === $planStart, 'prognoza : 12 tygodni planu od następnego poniedziałku');
    foreach ($W as $w) {
        expectStock($w['working_days'] === 5 - count($w['off']) && $w['return_date'] > $w['delivery_date'] && $w['ship_date'] >= $w['week_start'] && $w['ship_date'] <= date('Y-m-d', strtotime($w['week_start'] . ' +6 days')),
            "tydzień {$w['iso_week']} : dni pracy, powrót po dostawie, wyjazd w tygodniu");
        foreach ($w['off'] as $o) expectStock((int) date('N', strtotime($o['date'])) <= 5, 'dzień wolny tylko pn–pt');
    }
    $rows = array_column($f['products'], null, 'item_id');
    expectStock(array_keys($rows) == [11, 12] || array_keys($rows) == [12, 11], 'prognoza : tylko produkty MTS z kartonami (bez Chleba MTO)');
    [$b, $r] = [$rows[11], $rows[12]];
    expectStock($b['cpp'] === 10 && $b['min'] === 20.0 && $b['max'] === 40.0 && $b['normal_max'] === 40.0 && $b['period'] === null && $b['cost_eur'] === 2.0 && $b['stock_now'] === 12,
        'Bagietka : stany w kartonach (sztuki ÷ 10), koszt 8 zł ÷ 4 = 2 €, zapas dziś 12 (bez wysłanych)');
    expectStock($b['demand'][1] === 50 && $r['demand'][1] === 0, 'tydzień 2 : zamówienie złożone (50 kart. Bagietki, Rogal 0) zamiast prognozy');
    $avgWeeks = array_values(array_filter(range(0, $n - 1), fn($i) => $i !== 1 && $W[$i]['pallets'] === 4));
    expectStock(count($avgWeeks) >= 8, 'tygodnie bez zamówień : 4 palety ze średniej (' . json_encode(array_column($W, 'pallets')) . ')');
    foreach ($avgWeeks as $i) expectStock($b['demand'][$i] === 30 && $r['demand'][$i] === 10, "tydzień {$W[$i]['iso_week']} : 4 palety × 10 kart. × koszyk 75 / 25 % = 30 + 10");
    $safety = 1.25;
    for ($i = 0; $i < $n; $i++) {
        if ($W[$i]['working_days'] === 0) { expectStock($b['need'][$i] === null, 'tydzień bez produkcji : brak zapasu do zrobienia'); continue; }
        $v = $b['demand'][$i] * $safety;
        for ($j = $i + 1; $j < $n && $W[$j]['working_days'] === 0; $j++) $v += $b['demand'][$j];
        expectStock($b['need'][$i] === (int) ceil(round($v, 6)), "tydzień {$W[$i]['iso_week']} : zapas potrzebny = kartony × 1,25 (+ tygodnie bez produkcji)");
    }
    expectStock($b['need'][1] === 63 && $b['peak'] === max(array_map(fn($v) => $v ?? 0, $b['need'])), 'Bagietka : 50 × 1,25 = 62,5 → 63 ; szczyt');
    // Projekcja : od 12 kartonów dziś.
    $s = 12;
    for ($i = 0; $i < $n; $i++) {
        $prod = $b['need'][$i] === null ? 0 : max(0, $b['need'][$i] - $s);
        expectStock($b['prod'][$i] === $prod && $b['stock'][$i] === $s + $prod, "tydzień {$W[$i]['iso_week']} : produkcja = potrzebny − zapas, zapas przed wyjazdem");
        $s = max(0, $s + $prod - $b['demand'][$i]);
    }
    $P = $f['pallets'];
    expectStock(abs($P['now'] - 3.7) < 0.01 && abs($P['other'] - 2.5) < 0.01 && $P['places'] === 40, 'palety : dziś 3,7 (1,2 Bagietki + 2,5 Chleba), inne 2,5, zamrażarka 40 miejsc');
    expectStock(abs($P['prod'][0] - round(($b['prod'][0] + $r['prod'][0]) / 10, 1)) < 0.01 && abs($P['stock'][0] - round(($b['stock'][0] + $r['stock'][0]) / 10 + 2.5, 1)) < 0.06,
        'palety tygodnia : produkcja i magazyn przed wyjazdem (z paletami innych produktów)');
    expectStock($P['over'] === [] && $P['stock_peak'] <= 40, 'zamrażarka : mieści się');
    expectStock($f['totals']['max_now'] === 60 && $f['totals']['peak'] === max($f['need']), 'sumy : stany maks. 40 + 20, szczyt');

    // --- Stany okresu z prognozy --------------------------------------------------------------------------
    $from = $W[2]['week_start'];
    $to = date('Y-m-d', strtotime($W[5]['week_start'] . ' +6 days'));
    $lv = $fc->periodLevels($from, $to, $f);
    expectStock($lv['indexes'] === [2, 3, 4, 5] && !$lv['beyond'], 'okres : tygodnie planu 3–6');
    $peak = max(array_map(fn($i) => $b['need'][$i] ?? 0, [2, 3, 4, 5]));
    $avg = array_sum(array_map(fn($i) => $b['demand'][$i], [2, 3, 4, 5])) / 4;
    expectStock($lv['levels'][11]['min'] === (int) ceil($avg) && $lv['levels'][11]['max'] === max((int) ceil($avg), (int) $peak), 'Bagietka : min. = średnio na wyjazd × 1 tydzień, maks. = szczyt zapasu potrzebnego');
    expectStockFail(fn() => $fc->periodLevels('2001-01-01', '2001-02-01', $f), 422, 'okres poza planem logistyki');

    // --- Okres : projekt, stany, zatwierdzenie, obowiązywanie, cofnięcie, usunięcie ---------------------
    expectStockFail(fn() => $periods->create(['name' => '', 'date_from' => $from, 'date_to' => $to], 5), 422, 'nazwa wymagana');
    expectStockFail(fn() => $periods->create(['name' => 'X', 'date_from' => $to, 'date_to' => $from], 5), 422, 'do przed od');
    $p = $periods->create(['name' => 'Boże Narodzenie', 'date_from' => $from, 'date_to' => $to], 5);
    $items = array_column($p['items'], null, 'item_id');
    expectStock($p['status'] === 'draft' && count($items) === 2 && $items[11]['max'] === (float) $lv['levels'][11]['max'] && $items[11]['normal_max'] === 40.0,
        'utworzony projekt : stany z prognozy (kartony), normalne z karty');
    $id = $p['id'];
    expectStock((int) $pdo->query("SELECT stock_max FROM n_stock_period_item WHERE id_period = {$id} AND id_item = 11")->fetchColumn() === $lv['levels'][11]['max'] * 10, 'zapis w sztukach (kartony × 10)');
    $p = $periods->update($id, ['items' => [['item_id' => 11, 'min' => '25', 'max' => '70,5']]]);
    $items = array_column($p['items'], null, 'item_id');
    expectStock($items[11]['min'] === 25.0 && $items[11]['max'] === 70.5 && $items[11]['delta'] === 30.5 && (float) $pdo->query("SELECT stock_max FROM n_stock_period_item WHERE id_period = {$id} AND id_item = 11")->fetchColumn() === 705.0,
        'stany w kartonach (przecinek) → sztuki ; różnica do normalnego maks.');
    expectStock($p['totals']['extra_value_eur'] > 0 && $p['freezer']['places'] === 40 && $p['freezer']['ok'] === true, 'okres : wartość zapasu ponad normalny, zamrażarka');
    expectStockFail(fn() => $periods->update($id, ['items' => [['item_id' => 11, 'min' => '50', 'max' => '20']]]), 422, 'maks. mniejszy niż min.');
    // Przed zatwierdzeniem : karta i Stan magazynu — stany normalne.
    $mts = fn() => array_column($c->get(App\Repositories\Stock\StockPeriodRepository::class)->mtsProducts(), null, 'item_id');
    expectStock((float) $mts()[11]['stock_max'] === 400.0 && $mts()[11]['stock_period'] === null, 'projekt : obowiązują stany normalne');
    $p = $periods->approve($id, 5);
    expectStock($p['status'] === 'planned' && $p['approved_by'] === 'Anna Kierownik', 'zatwierdzony (od pierwszego dnia okresu)');
    expectStock((float) $mts()[11]['stock_max'] === 400.0, 'zatwierdzony, ale okres jeszcze się nie zaczął : stany normalne');
    expectStockFail(fn() => $periods->update($id, ['name' => 'Y']), 409, 'zatwierdzony : zmiana tylko po cofnięciu');
    $p2 = $periods->create(['name' => 'Nakładający się', 'date_from' => $W[4]['week_start'], 'date_to' => date('Y-m-d', strtotime($W[7]['week_start'] . ' +6 days'))], 5);
    expectStockFail(fn() => $periods->approve($p2['id'], 5), 409, 'okresy zatwierdzone nie nakładają się');
    // Okres obejmujący dziś (data w bazie) : stany wyjątkowe zamiast normalnych, normalne obok.
    $pdo->exec("UPDATE n_stock_period SET date_from = CURDATE() - INTERVAL 1 DAY WHERE id = {$id}");
    $row = $mts()[11];
    expectStock((float) $row['stock_max'] === 705.0 && (float) $row['normal_stock_max'] === 400.0 && $row['stock_period'] === 'Boże Narodzenie' && $row['stock_period_to'] === $to, 'okres trwa : stan maks. wyjątkowy (705), normalny 400 obok');
    expectStock((float) $mts()[12]['stock_max'] === (float) $pdo->query("SELECT stock_max FROM n_stock_period_item WHERE id_period = {$id} AND id_item = 12")->fetchColumn(), 'Rogal : stan okresu');
    $list = array_column($periods->periods(), null, 'id');
    expectStock($list[$id]['status'] === 'active' && $list[$p2['id']]['status'] === 'draft' && $list[$id]['back_to_normal'] === date('Y-m-d', strtotime($to . ' +1 day')), 'lista : aktywny, projekt, powrót dzień po końcu');
    $f2 = $fc->forecast($today);
    expectStock(array_column($f2['products'], null, 'item_id')[11]['period'] === 'Boże Narodzenie' && array_column($f2['products'], null, 'item_id')[11]['max'] === 70.5, 'prognoza : stan maks. okresu i znaczek');
    // Po końcu okresu : samo z powrotem normalne.
    $pdo->exec("UPDATE n_stock_period SET date_from = CURDATE() - INTERVAL 20 DAY, date_to = CURDATE() - INTERVAL 1 DAY WHERE id = {$id}");
    expectStock((float) $mts()[11]['stock_max'] === 400.0 && array_column($periods->periods(), null, 'id')[$id]['status'] === 'ended', 'po okresie : stany normalne wracają same');
    $pdo->exec("UPDATE n_stock_period SET date_from = CURDATE() - INTERVAL 1 DAY, date_to = '{$to}' WHERE id = {$id}");
    $p = $periods->reopen($id);
    expectStock($p['status'] === 'draft' && (float) $mts()[11]['stock_max'] === 400.0, 'cofnięcie zatwierdzenia : od razu stany normalne');
    $periods->delete($id);
    $periods->delete($p2['id']);
    expectStock((int) $pdo->query('SELECT COUNT(*) FROM n_stock_period_item')->fetchColumn() === 0 && $periods->periods() === [], 'usunięcie : okres i jego stany');
    expectStockFail(fn() => $periods->delete($id), 404, 'usunięty : 404');
    // Strona : prognoza + okresy.
    $page = $periods->page(null, $today);
    expectStock(count($page['forecast']['weeks']) === 12 && $page['period'] === null && $page['freezer_places'] === 40, 'strona : prognoza, bez okresu, zamrażarka');

    echo "stock_period_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
