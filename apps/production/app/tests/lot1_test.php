<?php
declare(strict_types=1);
/*
 * Lot 1 audytu : zmiana ceny standardowej (klienci z ceną standardową idą za nią, ceny wynegocjowane
 * zostają, historia), wartość magazynu bez wartości losowych, wolumen sprzedaży działu (tylko produkty
 * działu), karta surowca (0 w wartościach odżywczych, źródło z internetu, przełącznik opakowania).
 *
 *   php tests/lot1_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide) ; structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migrations
 * tfb appliquées). La base erp_lot1_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Analysis\ProductMarginRepository;
use App\Repositories\Material\MaterialRepository;
use App\Repositories\Material\NutritionRepository;
use App\Repositories\Product\Price\ProductPriceChangeRepository;
use App\Repositories\Stock\FinalProduct\FinalProductStockRepository;
use App\Services\Finances\StandardPriceService;
use App\Services\Material\MaterialService;
use App\Services\Recipe\RecipeService;
use App\Services\Stock\FinalProductStockStateService;

function expectLot(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_lot1_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "lot1_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['final_product', 'final_product_customer_price', 'tfb_product_price_change', 'cost_month', 'document_wdt', 'document_wdt_product',
                 'tfb_wdt_superseded', 'carton_stock', 'raw_material', 'unit', 'supplier', 'tfb_material_nutrition_source'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");

    // --- Cena standardowa : jedna reguła dla cennika ogólnego i marż kategorii
    $pdo->exec("INSERT INTO final_product (id, name, standard_price, id_section, id_recipe, pre_calculated_raw_price_per_carton, active) VALUES
        (10, 'Croissant', 20.00, 1, 501, 12.50, 1), (11, 'Tarta', 30.00, 2, NULL, 18.40, 1), (12, 'Ciasto', 15.00, 1, NULL, 0, 1)");
    $pdo->exec("INSERT INTO final_product_customer_price (id_customer, id_final_product, price, available) VALUES
        (1, 10, 20.00, 1), (2, 10, 18.50, 1), (3, 10, 20.00, 0), (4, 10, 0, 1), (1, 11, 30.00, 1)");
    $prices = new StandardPriceService(new ProductPriceChangeRepository($pdo));
    $r = $prices->change(10, '22,40', 'general_price_list', 7);
    $client = fn(int $c, int $p) => (float) $pdo->query("SELECT price FROM final_product_customer_price WHERE id_customer = {$c} AND id_final_product = {$p}")->fetchColumn();
    expectLot($r['price'] === 22.4 && $r['old_price'] === 20.0 && $r['followers'] === 2 && $r['kept'] === 2, 'cena 20 → 22,40 : 2 klientów z ceną standardową, 2 bez zmian');
    expectLot((float) $pdo->query('SELECT standard_price FROM final_product WHERE id = 10')->fetchColumn() === 22.4, 'cena standardowa zapisana');
    expectLot($client(1, 10) === 22.4 && $client(3, 10) === 22.4 && $client(2, 10) === 18.5 && $client(4, 10) === 0.0 && $client(1, 11) === 30.0,
        'klienci 1 i 3 (cena = standardowa) idą za nią ; 18,50 i 0 zostają ; inny produkt bez zmian');
    $log = $pdo->query('SELECT id_customer, old_price, new_price, source, changed_by FROM tfb_product_price_change ORDER BY id')->fetchAll();
    expectLot(count($log) === 3 && $log[0]['id_customer'] === null && (float) $log[0]['old_price'] === 20.0 && (float) $log[0]['new_price'] === 22.4
        && array_map('intval', array_column(array_slice($log, 1), 'id_customer')) === [1, 3] && $log[0]['source'] === 'general_price_list' && (int) $log[2]['changed_by'] === 7,
        'historia : cena standardowa + 2 klientów, źródło i użytkownik');
    expectLot(str_contains($r['message'], '20,00 → 22,40') && str_contains($r['message'], 'ceną standardową: 2') && str_contains($r['message'], 'wynegocjowane bez zmian: 2'), 'komunikat z liczbami');
    $same = $prices->change(10, 22.4, 'category_margin', null);
    expectLot($same['followers'] === 0 && (int) $pdo->query('SELECT COUNT(*) FROM tfb_product_price_change')->fetchColumn() === 3, 'ta sama cena : nic do zapisania');
    $refused = function (callable $fn): bool {
        try { $fn(); return false; } catch (CustomException) { return true; }
    };
    expectLot($refused(fn() => $prices->change(10, 0, 'category_margin', null)) && $refused(fn() => $prices->change(10, 'abc', 'category_margin', null))
        && $refused(fn() => $prices->change(10, -3, 'category_margin', null)), 'cena 0, ujemna albo nieliczbowa odrzucona');
    expectLot($refused(fn() => $prices->change(999, 10, 'category_margin', null)) && $refused(fn() => $prices->change(10, 10, 'other', null)), 'nieznany produkt / źródło');

    // --- Wolumen działu : tylko kartony produktów działu (nie cała sprzedaż miesiąca)
    $pdo->exec("INSERT INTO cost_month (id, year, month) VALUES (1, 2026, 9), (2, 2026, 10)");
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, id_customer, established_date) VALUES (1, 'W1', 1, '2026-09-10'), (2, 'W2', 1, '2026-09-20'), (3, 'W3', 1, '2026-10-02')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, quantity) VALUES (1, 10, 100), (1, 11, 40), (2, 12, 25), (2, 11, 5), (3, 11, 7)");
    $pdo->exec("INSERT INTO tfb_wdt_superseded (id_document_wdt) VALUES (2)");
    $margins = new ProductMarginRepository($pdo);
    expectLot($margins->getSectionSalesVolumePerMonth(1, [1, 2]) == [1 => 100.0, 2 => 0.0], 'dział 1 : 100 kartonów we wrześniu (WDT zastąpiony pominięty), 0 w październiku');
    expectLot($margins->getSectionSalesVolumePerMonth(2, [1, 2]) == [1 => 40.0, 2 => 7.0], 'dział 2 : tylko jego produkty');

    // --- Wartość magazynu : receptura, koszt z karty produktu albo brak kosztu (nigdy losowy)
    $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status) VALUES (1, 1, 10, 1), (2, 1, 10, 2), (3, 1, 11, 1), (4, 1, 11, 1), (5, 1, 11, 1), (6, 1, 12, 1), (7, 1, 12, 3)");
    $recipes = new class extends RecipeService {
        public function __construct() {}
        public function getRecipeSummary($id_recipe) { return ['total_ingredient_cost' => $id_recipe === 501 ? 11.25 : 0, 'yield_quantity' => 1, 'recipe_unit' => 'szt', 'ppc' => 1]; }
    };
    $stock = new FinalProductStockStateService(new FinalProductStockRepository($pdo), $recipes);
    $first = array_column($stock->getCurrentStockWithValue(), null, 'id_product');
    $second = array_column($stock->getCurrentStockWithValue(), null, 'id_product');
    expectLot($first[10]['cost_source'] === 'recipe' && (float) $first[10]['total_material_cost'] === 22.5, 'z recepturą : 2 kartony × 11,25');
    expectLot($first[11]['cost_source'] === 'card' && (float) $first[11]['total_material_cost'] === 55.2, 'bez receptury : 3 kartony × 18,40 z karty produktu');
    expectLot($first[12]['cost_source'] === 'none' && $first[12]['total_material_cost'] === null && (int) $first[12]['count_in_stock'] === 1, 'bez receptury i kosztu : brak kosztu (karton wysłany pominięty)');
    expectLot($first == $second, 'ta sama wartość przy każdym wydruku');

    // --- Karta surowca
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g')");
    $pdo->exec("INSERT INTO raw_material (id, name, waste_perc, id_unit, id_category, package_size_in_base_unit, price_per_unit, energy_100g, fat_100g, saturated_fat_100g,
        carbohydrates_100g, sugars_100g, salt_100g, fiber_100g, proteins_100g, is_part_of_package) VALUES (5, 'Cukier', 100, 1, 3, 1000, 0.004, 400, 0, 0, 100, 100, 0.01, 0, 0, 0)");
    $pdo->exec("INSERT INTO tfb_material_nutrition_source (id_raw_material, source, ref, label, sample, applied_at) VALUES (5, 'off', 'en:sugars', 'Cukry', 40, NOW())");
    $materials = new MaterialService(new MaterialRepository($pdo), new NutritionRepository($pdo));
    // Jak na serwerze (DB_SQL_MODE strict) : puste pola BDO formularza nie mogą blokować zapisu
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $form = ['name' => 'Cukier', 'waste_perc' => 100, 'id_unit' => 1, 'id_category' => 3, 'pack_price' => 4, 'package_size_in_base_unit' => 1000, 'id_current_supplier' => 1,
        'bdo_material_category' => '', 'packaging_usage_type' => '', 'packaging_unit_weight_g' => '',
        'energy_100g' => '400', 'fat_100g' => '0', 'saturated_fat_100g' => '0', 'carbohydrates_100g' => '100', 'sugars_100g' => '100', 'salt_100g' => '0,01', 'fiber_100g' => '0', 'proteins_100g' => '0'];
    expectLot($materials->validate($form) === true, 'nowy surowiec : 0 g tłuszczu, białka, błonnika jest poprawne');
    expectLot($materials->validate(['salt_100g' => '-1'] + $form) === false && $materials->validate(['fat_100g' => ''] + $form) === false, 'nowy surowiec : wartość ujemna albo pusta odrzucona');
    expectLot($materials->validateUpdate(['fat_100g' => ''] + $form) === true && $materials->validateUpdate(['fat_100g' => 'x'] + $form) === false, 'edycja : puste = 0, nieliczbowe odrzucone');
    $nutrition = fn() => $pdo->query('SELECT energy_100g, salt_100g, is_part_of_package FROM raw_material WHERE id = 5')->fetch();
    $hasSource = fn() => (bool) $pdo->query('SELECT COUNT(*) FROM tfb_material_nutrition_source WHERE id_raw_material = 5')->fetchColumn();
    $materials->setPackagePart(5, 1);
    $row = $nutrition();
    expectLot((int) $row['is_part_of_package'] === 1 && (float) $row['energy_100g'] === 400.0 && (float) $row['salt_100g'] === 0.01 && $hasSource(), 'przełącznik opakowania : wartości odżywcze i źródło bez zmian');
    expectLot($materials->update(5, $form + ['is_part_of_package' => 1]) === true && $hasSource(), 'zapis karty (puste pola BDO, tryb strict) bez zmiany wartości : źródło z internetu zostaje');
    expectLot($pdo->query('SELECT bdo_material_category IS NULL AND packaging_unit_weight_g IS NULL FROM raw_material WHERE id = 5')->fetchColumn() == 1, 'puste pola BDO zapisane jako NULL');
    $newId = (int) $materials->insert(['name' => 'Sól'] + $form);
    expectLot($newId > 0 && (float) $pdo->query("SELECT fat_100g FROM raw_material WHERE id = {$newId}")->fetchColumn() === 0.0, 'nowy surowiec z wartościami 0 zapisany (tryb strict)');
    $materials->update(5, ['salt_100g' => '0'] + $form + ['is_part_of_package' => 1]);
    expectLot((float) $nutrition()['salt_100g'] === 0.0 && !$hasSource(), 'zmieniona wartość (sól 0) zapisana ; źródło z internetu usunięte');

    echo "lot1_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
