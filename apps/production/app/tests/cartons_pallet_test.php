<?php
declare(strict_types=1);
/*
 * Kartony i palety [205] : układ kartonów na palecie (warstwa jednolita / dwa bloki, wystawanie, warstwy do maks.
 * wysokości i wagi, piętrowanie, ocena układu z ERP) ; na bazie jednorazowej : katalog (karton = surowiec-opakowanie,
 * produkty z kartonem z karty / PPWR / receptury, za ciężkie, układ z ERP), zapis (wymiary → carton_dimension, waga
 * kartonu → surowiec), dodanie z listy surowców, krok « Paleta » (obliczenie, informacje produktu), karta produktu :
 * karton z katalogu i ograniczenia palety w API logistyki, kreator 9 kroków.
 *
 *   php tests/cartons_pallet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 129 appliquée). La base erp_cartons_test est
 * recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Domain\Logistics\PalletLayout;
use App\Exceptions\CustomException;
use App\Services\Product\CartonService;
use App\Services\Workshop\Product\ItemService;

function expectCt(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectCtFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectCt((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Układ palety (bez bazy) -------------------------------------------------------------------------------
$L = ['max_height_mm' => 1800, 'max_kg' => 1000, 'overhang_mm' => 0, 'truck_height_mm' => 2550];
$p = PalletLayout::plan(['length_mm' => 395, 'width_mm' => 295, 'height_mm' => 210, 'tare_g' => 380], 'eur', $L, 4.8);
expectCt($p['per_layer'] === 8 && $p['max']['layers'] === 7 && $p['max']['cartons'] === 56 && $p['max']['height_mm'] === 1614, 'karton 395 × 295 × 210 na EUR : 8 × 7 = 56, 1614 mm');
expectCt(abs($p['max']['gross_kg'] - (56 * 5.18 + 25)) < 0.1 && $p['floor_pct'] === 97.1, 'waga brutto 56 × 5,18 kg + paleta 25 kg ; podłoga 97,1 %');
expectCt($p['stack'] !== null && $p['stack']['layers'] === 5 && $p['stack']['height_mm'] === 1194 && $p['stack']['per_place'] === 80 && $p['stack_height_mm'] === 1225, 'piętrowanie : 2 × (8 × 5) = 80 na miejsce, ≤ 1225 mm');
foreach ($p['rects'] as $r) expectCt($r['x'] >= 0 && $r['y'] >= 0 && $r['x'] + $r['w'] <= 1200 && $r['y'] + $r['h'] <= 800, 'kartony na palecie (bez wystawania)');
expectCt(PalletLayout::check($p, 10, 6, 60) === [['code' => 'LAYER_TOO_MANY', 'params' => ['cpl' => 10, 'max' => 8]]], 'ERP 10 na warstwę : za dużo (maks. 8)');
expectCt(PalletLayout::check($p, 8, 9, 72)[0]['code'] === 'TOO_HIGH' && PalletLayout::check($p, 8, 7, 56) === [] && PalletLayout::check($p, 0, 0, 0)[0]['code'] === 'ERP_EMPTY', 'ERP 8 × 9 : za wysoko ; 8 × 7 OK ; brak układu');
$heavy = PalletLayout::plan(['length_mm' => 395, 'width_mm' => 295, 'height_mm' => 210, 'tare_g' => 500], 'eur', ['max_kg' => 400] + $L, 12.0);
expectCt($heavy['layers_by_weight'] === 3 && $heavy['max']['layers'] === 3 && PalletLayout::check($heavy, 8, 6, 48)[0]['code'] === 'TOO_HEAVY', 'maks. 400 kg : 12,5 kg / karton → 3 warstwy ; 6 warstw za ciężko');
$mixed = PalletLayout::layer(380, 190, 1200, 800);
expectCt(count($mixed) === 12 && count(PalletLayout::layer(590, 390, 1200, 800)) === 4, 'karton 380 × 190 : 12 na warstwę ; 590 × 390 : 4');
expectCt(count(PalletLayout::layer(400, 300, 1200, 800)) === 8 && count(PalletLayout::layer(400, 300, 1200, 800, 25)) === 8, 'wystawanie 25 mm : 400 × 300 nadal 8');
expectCt(count(PalletLayout::layer(410, 300, 1200, 800)) === 6 && count(PalletLayout::layer(410, 300, 1200, 800, 20)) === 8, 'karton 410 × 300 : 6 bez wystawania, 8 przy 20 mm z każdej strony');
$ind = PalletLayout::plan(['length_mm' => 395, 'width_mm' => 295, 'height_mm' => 210], 'industrial', $L, null);
expectCt($ind['per_layer'] === 10 && $ind['max']['gross_kg'] === null && $ind['layers_by_weight'] === null, 'paleta przemysłowa 1200 × 1000 : 10 na warstwę ; bez wagi — bez limitu wagi');
expectCt(PalletLayout::plan(['length_mm' => 0, 'width_mm' => 295, 'height_mm' => 210], 'eur', $L, null) === null, 'bez wymiarów : brak planu');

// --- Baza jednorazowa ------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_cartons_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "cartons_pallet_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['raw_material', 'category_material', 'carton_dimension', 'raw_material_ppwr_profile', 'raw_material_ppwr_dimension', 'n_carton', 'final_product',
                 'final_product_ppwr_sales_packaging', 'flattened_recipe_ingredient', 'n_prod_item', 'n_prod_item_logistics', 'n_prod_product_settings', 'n_prod_param',
                 'document_wdt', 'document_wdt_product', 'n_wdt_superseded', 'erp_company_other_settings', 'production_sections', 'category_product', 'product_recipe',
                 'n_prod_group', 'n_prod_routing_step', 'n_prod_routing_alternative', 'n_prod_workstation', 'n_prod_audit_log', 'n_recipe_producer', 'carton_stock', 'customer_order', 'customer_order_product', 'n_prod_order'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO category_material (id, name) VALUES (200, 'Kartony (Klapowy)'), (103, 'Opakowanie')");
    $pdo->exec("INSERT INTO raw_material (id, name, id_category, price_per_unit, packaging_unit_weight_g, is_part_of_package) VALUES
        (4006, 'Karton 590 x 390 x 210', 200, 3.71, 770, 1), (4093, 'karton 596 x 395 x 102', 200, 4.01, 630, 1), (4500, 'karton 395 x 295 x 210', 200, 2.50, 380, 1),
        (4600, 'Taśma klejąca', 103, 9.99, NULL, 1), (1, 'Mąka', 105, 2.10, NULL, 0)");
    $pdo->exec("INSERT INTO n_carton (id_raw_material, length_mm, width_mm, height_mm, kind, max_load_kg) VALUES
        (4006, 590, 390, 210, 'klapowy', 10), (4093, 596, 395, 102, 'klapowy', NULL), (4500, 395, 295, 210, 'klapowy', 4)");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('logistics.pallet_max_height_mm', '1800', '1800', 'int'),
        ('logistics.pallet_max_kg', '1000', '1000', 'int'), ('logistics.pallet_overhang_mm', '0', '0', 'int'), ('logistics.truck_inner_height_mm', '2550', '2550', 'int'),
        ('logistics.default_pallet_places', '33', '33', 'int')");
    // Produkty : Croissant (karton z karty 4500, ERP 10 × 6 = 60, zawartość 64 × 75 g = 4,8 kg > maks. 4 kg),
    // Bagietka (PPWR 4006, 4 × 7 = 28), Tarta (receptura z kartonem 4093), Babka (bez kartonu), nieaktywny (PPWR 4006).
    $pdo->exec("INSERT INTO final_product (id, name, active, weight, ppc, cpl, lpp, cpp, id_recipe) VALUES
        (10, 'Croissant 75 g', 1, 75, 64, 10, 6, 60, NULL), (11, 'Bagietka', 1, 250, 20, 4, 7, 28, NULL), (12, 'Tarta', 1, 500, 6, 0, 0, 0, 900),
        (13, 'Babka', 1, 400, 8, 0, 0, 0, NULL), (14, 'Stary', 0, 100, 10, 4, 7, 28, NULL)");
    $pdo->exec("INSERT INTO final_product_ppwr_sales_packaging (id_final_product, id_outer_raw_material) VALUES (11, 4006), (14, 4006)");
    $pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (900, 4093, 1), (900, 1, 3000)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, base_unit) VALUES (100, 'product', 10, 'szt'), (101, 'product', 11, 'szt')");
    $pdo->exec("INSERT INTO n_prod_item_logistics (id_item, id_carton, carton_length_mm, carton_width_mm, carton_height_mm, pallet_type) VALUES (100, 4500, 395, 295, 210, 'eur')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $builder->build();
    $svc = $c->get(CartonService::class);

    $cat = $svc->catalog();
    $by = array_column($cat['cartons'], null, 'id');
    expectCt(count($cat['cartons']) === 3 && $cat['products_total'] === 4 && $cat['without_carton'] === 1, 'katalog : 3 kartony, 4 aktywne produkty, 1 bez kartonu (Babka)');
    $cr = $by[4500]['products'][0];
    expectCt($by[4500]['product_count'] === 1 && $cr['name'] === 'Croissant 75 g' && $cr['source'] === 'card' && $cr['net_kg'] === 4.8 && $cr['over_load'], 'Croissant : karton z karty, zawartość 4,8 kg > maks. 4 kg');
    expectCt($cr['issues'][0]['code'] === 'LAYER_TOO_MANY' && [$cr['best']['per_layer'], $cr['best']['layers'], $cr['best']['cartons']] === [8, 7, 56] && !$cr['best']['by_weight'] && $by[4500]['issue_count'] === 1, 'Croissant : ERP 10 na warstwę, mieści się 8 × 7 = 56');
    expectCt($by[4006]['product_count'] === 1 && $by[4006]['products'][0]['source'] === 'ppwr' && $by[4006]['products'][0]['issues'] === [] && $by[4006]['plans']['eur']['per_layer'] === 4
        && $by[4006]['plans']['eur']['layers'] === 7, 'Bagietka : karton PPWR (nieaktywny produkt pominięty), ERP 4 × 7 zgodne ; 590 × 390 na EUR : 4 × 7');
    expectCt($by[4093]['products'][0]['source'] === 'recipe' && $by[4093]['products'][0]['issues'] === [] && $by[4093]['volume_l'] === 24.0, 'Tarta : karton z receptury, bez układu w ERP — bez błędów ; 24 l');

    // Zapis : wymiary → carton_dimension, waga kartonu → surowiec, maks. ładunek, archiwum.
    $d = $svc->save(4500, ['length_mm' => '400', 'width_mm' => '300', 'height_mm' => '210', 'tare_g' => '410', 'max_load_kg' => '6,5', 'kind' => 'fasonowy', 'note' => 'mrożonki', 'active' => true], 7);
    expectCt($d['length_mm'] === 400 && $d['tare_g'] === 410.0 && $d['max_load_kg'] === 6.5 && $d['kind'] === 'fasonowy' && !$d['products'][0]['over_load'], 'zapis : 400 × 300, 410 g, maks. 6,5 kg — Croissant już nie za ciężki');
    expectCt($pdo->query('SELECT CONCAT(length, "x", width, "x", height, "|", name) FROM carton_dimension WHERE id = 4500')->fetchColumn() === '400x300x210|karton 395 x 295 x 210'
        && (float) $pdo->query('SELECT packaging_unit_weight_g FROM raw_material WHERE id = 4500')->fetchColumn() === 410.0, 'wymiary w carton_dimension (ERP), waga w surowcu (BDO)');
    expectCtFail(fn() => $svc->save(4500, ['length_mm' => '5', 'max_load_kg' => 'abc', 'kind' => 'x'], 7), 422, 'walidacja : wymiar 20–2000, kg, rodzaj');
    expectCtFail(fn() => $svc->save(9999, ['length_mm' => 400], 7), 404, 'nieznany karton');
    $svc->save(4093, ['active' => false], 7);
    expectCt(!array_column($svc->catalog()['cartons'], null, 'id')[4093]['active'], 'karton w archiwum');

    // Dodanie z listy surowców.
    expectCt(array_column($svc->candidates(), 'id') === [4600], 'kandydaci : opakowania spoza katalogu (Taśma)');
    $a = $svc->add(['raw_material_id' => 4600], 7);
    expectCt($a['id'] === 4600 && $a['length_mm'] === null && $a['kind'] === null, 'dodany karton bez wymiarów (brak w ERP / PPWR)');
    expectCtFail(fn() => $svc->add(['raw_material_id' => 4600], 7), 409, 'już w katalogu');
    expectCtFail(fn() => $svc->add(['raw_material_id' => 'x'], 7), 422, 'bez opakowania');

    // Krok « Paleta ».
    $info = $svc->productInfo(10);
    expectCt($info['carton_id'] === 4500 && $info['carton_source'] === 'card' && !in_array(4093, array_column($info['cartons'], 'id'), true) && $info['limits']['max_height_mm'] === 1800
        && $info['places'] === 33 && $info['weekly_cartons'] === 0.0, 'krok Paleta : karton z karty, kartony aktywne, ograniczenia z parametrów');
    $plan = $svc->plan(['length_mm' => 395, 'width_mm' => 295, 'height_mm' => 210, 'tare_g' => 380, 'pallet' => 'eur', 'net_kg' => '4.8', 'cpl' => 10, 'lpp' => 6, 'cpp' => 60, 'max_height_mm' => 1500]);
    expectCt($plan['max']['layers'] === 6 && $plan['erp_check'][0]['code'] === 'LAYER_TOO_MANY', 'obliczenie : maks. 1500 mm → 6 warstw ; ERP 10 na warstwę za dużo');
    expectCtFail(fn() => $svc->plan(['length_mm' => 0]), 422, 'obliczenie bez wymiarów');
    expectCtFail(fn() => $svc->productInfo(14), 404, 'nieaktywny produkt');

    // Karta produktu : karton z katalogu i ograniczenia palety w API logistyki ; kreator 9 kroków.
    $items = $c->get(ItemService::class);
    $ctx = new ApiContext('t1', new Translator('pl'), 7, [], ['prod.products.write' => true, 'prod.products.read' => true]);
    $pdo->exec("INSERT INTO n_prod_product_settings (id_item, mode, never_frozen, status, wizard_step) VALUES (101, 'MTO', 0, 'draft', 2)");
    $it = $items->updateLogistics(101, ['carton_id' => 4006, 'carton_length_mm' => 590, 'carton_width_mm' => 390, 'carton_height_mm' => 210, 'carton_tare_g' => 770,
        'pallet_type' => 'eur', 'pallet_max_height_mm' => 1600, 'cpl' => 4, 'lpp' => 6, 'cpp' => 24], $ctx);
    expectCt($it['logistics']['carton_id'] === 4006 && $it['logistics']['pallet_max_height_mm'] === 1600 && $it['logistics']['pallet_max_kg'] === null && (int) $it['erp']['lpp'] === 6,
        'API logistyki : karton z katalogu, maks. wysokość produktu, ERP 4 × 6 = 24');
    expectCt(array_column($svc->catalog()['cartons'], null, 'id')[4006]['products'][0]['source'] === 'card', 'karton z karty ma pierwszeństwo przed PPWR');
    try {
        $items->updateLogistics(101, ['carton_id' => 4999], $ctx);
        expectCt(false, 'nieznany karton w karcie');
    } catch (ApiException $e) {
        expectCt($e->httpStatus() === 422 && $e->fieldErrors()[0]['field'] === 'carton_id', 'nieznany karton w karcie : 422 carton_id');
    }
    // [211] Maks. wysokość / waga palety rodzaju kartonu : produkt → karton → parametry ; warstwy z wagi produktu.
    $d = $svc->save(4006, ['pallet_max_height_mm' => '1200', 'pallet_max_kg' => '100'], 7);
    $bg = $d['products'][0];
    expectCt($d['pallet_max_height_mm'] === 1200 && $d['pallet_max_kg'] === 100 && $d['limits']['max_height_mm'] === 1200 && $d['defaults']['max_height_mm'] === 1800,
        'karton : maks. 1200 mm i 100 kg (parametry 1800 mm zostają domyślne)');
    expectCt($bg['limits']['max_height_mm'] === 1600 && $bg['limits']['max_kg'] === 100 && $bg['limits']['own'], 'Bagietka : własna wysokość 1600 mm (karta) ma pierwszeństwo, waga 100 kg z kartonu');
    // 20 × 250 g + 770 g = 5,77 kg / karton, 4 na warstwę = 23,08 kg ; (100 − tara palety) ÷ 23,08 → 3 warstwy (wysokość pozwala na więcej).
    expectCt($bg['best']['per_layer'] === 4 && $bg['best']['layers'] === 3 && $bg['best']['cartons'] === 12 && $bg['best']['by_weight'] && $bg['best']['gross_kg'] <= 100
        && in_array('TOO_HEAVY', array_column($bg['issues'], 'code'), true), 'Bagietka : warstwy wg wagi 4 × 3 = 12 ; ERP 4 × 6 za ciężkie');
    $info = $svc->productInfo(11);
    expectCt($info['defaults']['max_kg'] === 100 && $info['defaults']['max_height_mm'] === 1200 && $info['limits']['max_height_mm'] === 1600, 'krok Paleta : domyślne z kartonu, własne z karty');
    $it = $items->updateLogistics(101, ['cpl' => 4, 'lpp' => 3, 'cpp' => 12], $ctx);
    $bg = array_column($svc->catalog()['cartons'], null, 'id')[4006]['products'][0];
    expectCt($bg['issues'] === [] && (int) $it['erp']['cpp'] === 12, 'zastosowany układ 4 × 3 = 12 : bez błędów');
    expectCtFail(fn() => $svc->save(4006, ['pallet_max_height_mm' => '100', 'pallet_max_kg' => '5000'], 7), 422, 'walidacja : 300–3000 mm, 50–3000 kg');
    $d = $svc->save(4006, ['pallet_max_height_mm' => '', 'pallet_max_kg' => null], 7);
    expectCt($d['pallet_max_height_mm'] === null && $d['limits']['max_kg'] === 1000, 'puste = parametry logistyki');
    $s = $items->updateSettings(101, ['wizard_step' => 9], $ctx);
    expectCt($s['settings']['wizard_step'] === 9 && ItemService::WIZARD_STEPS === 9, 'kreator : 9 kroków (krok 3 = Paleta)');
    // [213] Dane palety do wagi i wysokości : kartony na warstwę z ERP, gdy spójne ; inaczej z wymiarów kartonu.
    $pd = $svc->palletData([10, 11, 13]);
    expectCt($pd[10]['cpl'] === 8 && $pd[10]['cpl_source'] === 'carton' && $pd[10]['height_mm'] === 210 && $pd[10]['tare_g'] === 410.0,
        'Croissant : ERP 10 / warstwę nie mieści się (395 × 295 → 8), karton 210 mm, 410 g');
    expectCt($pd[11]['cpl'] === 4 && $pd[11]['cpl_source'] === 'erp' && $pd[11]['max_height_mm'] === 1600 && $pd[11]['max_kg'] === null
        && $pd[13]['height_mm'] === null && $pd[13]['cpl'] === 0, 'Bagietka : ERP 4 × 3 = 12 spójne, własna wysokość 1600 mm ; Babka bez kartonu : brak wysokości');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "cartons_pallet_test: OK\n";
