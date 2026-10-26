<?php
declare(strict_types=1);
/*
 * Versions de recette « en vigueur depuis » [154] : version de départ (rattrapage), nouvelle version datée quand un
 * półprodukt contenu change (recette parente, note avec le nom), quand la recette elle-même change (différences
 * matière par matière), pas de version pour un simple renommage ; la rentabilité valorise un mois passé avec la
 * composition de l'époque et le mois courant avec la composition actuelle ; page d'historique (effet prix / composition).
 *
 *   php tests/recipe_versions_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_recipe_versions_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Dashboard\ProfitabilityService;
use App\Services\Recipe\FlattenedRecipeService;
use App\Services\Recipe\RecipeHistoryViewService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\RecipeVersionService;
use App\Services\Recipe\SemiProductService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectRv(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_recipe_versions_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "recipe_versions_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency',
                 'customer', 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings',
                 'supplier', 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month',
                 'cost_media_section_allocation', 'cost_media_type', 'communication_message', 'communication_message_attachment', 'communication_email_account',
                 'erp_company_data', 'flattened_recipe_recipe', 'product_preparation_type', 'product_recipe_preparation_connection', 'product_recipe_type',
                 'product_recipe_preparation_step', 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status', 'test_product_type',
                 'test_product_stage', 'test_product_changelog', 'complaint', 'predefined_recipe_label', 'allergen', 'material_allergen_connection', 'language', 'raw_material_alias', 'allergen_alias',
                 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery', 'document_cmr', 'document_wz', 'shipment_log', 'app_notification'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'manager', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();

    $pdo->exec("SET @mode := @@SESSION.sql_mode");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.00)");
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'kg'), (3, 'g'), (5, 'szt')");
    // Mąka 0,004 zł/g, masło 0,04 zł/g, karton 1,50 zł/szt.
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, package_size_in_base_unit) VALUES
        (4001, 'Mąka T65', 3, 0.004, 25000), (4002, 'Masło 82 %', 3, 0.04, 10000), (4003, 'Karton 600 x 400', 5, 1.5, 1)");
    $pdo->exec("INSERT INTO product_preparation_type (id, name, price) VALUES (7, 'Pakowanie', 0)");
    // Półprodukt 20 « Ciasto maślane » (mąka 500 g + masło 100 g) ; produkt 10 « Croissant » = 2 × półprodukt + karton.
    $pdo->exec("INSERT INTO product_recipe (id, name, description, id_type, is_subrecipe, id_unit, yield_quantity, precalculated_material_cost) VALUES
        (20, 'Ciasto maślane', '', 2, 1, 5, 1, 0), (10, NULL, '', 3, 1, 5, 1, 0)");
    $pdo->exec("INSERT INTO product_recipe_connection (parent_recipe_id, child_recipe_id, child_ingredient_id, quantity) VALUES
        (20, NULL, 4001, 500), (20, NULL, 4002, 100), (10, 20, NULL, 2), (10, NULL, 4003, 1)");
    $pdo->exec("INSERT INTO product_recipe_preparation_connection (id_recipe, id_preparation_type, duration_second) VALUES (10, 7, 60)");
    $pdo->exec("INSERT INTO final_product (id, name, id_recipe, id_section, pre_calculated_raw_price_per_carton, active) VALUES (900, 'Croissant 60 g', 10, 1, 0, 1)");
    // Ventes WDT : 10 cartons en août, 10 en octobre 2026.
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, established_date, currency) VALUES (1, 'W/8', '2026-08-12', 'eur'), (2, 'W/10', '2026-10-07', 'eur')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (1, 900, 'Croissant 60 g', 10, 30), (2, 900, 'Croissant 60 g', 10, 30)");
    $pdo->exec("SET SESSION sql_mode = @mode");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $versions = $container->get(RecipeVersionService::class);
    $count = fn(int $id) => (int) $pdo->query("SELECT COUNT(*) FROM n_recipe_version WHERE id_recipe = {$id}")->fetchColumn();
    $last = fn(int $id) => $pdo->query("SELECT * FROM n_recipe_version WHERE id_recipe = {$id} ORDER BY version_no DESC LIMIT 1")->fetch();
    $stored = fn(int $id) => (float) $pdo->query("SELECT precalculated_material_cost FROM product_recipe WHERE id = {$id}")->fetchColumn();

    // --- État de départ : recettes aplaties puis rattrapage → une version de départ par recette (valid_from NULL)
    $container->get(FlattenedRecipeService::class)->flatForAll();
    $container->get(App\Services\Recipe\RecipeCostRecalculationService::class)->recalcForAll();
    $pdo->exec("DELETE FROM n_recipe_version"); // données existantes avant le suivi des versions
    expectRv($versions->backfill() === 2 && $versions->backfill() === 0, 'backfill: one baseline per flattened recipe, only once');
    $base = $last(10);
    $costBefore = $stored(10);
    expectRv($base['valid_from'] === null && $base['source'] === 'baseline' && (int) $base['version_no'] === 1
        && abs((float) $base['material_cost'] - $costBefore) < 0.001 && $costBefore > 0, 'baseline: valid since always, cost = stored recipe cost');
    $composition = json_decode($base['composition'], true);
    expectRv(array_column($composition['components'], 'name') === ['Ciasto maślane'] && array_column($composition['materials'], 'name') === ['Karton 600 x 400']
        && $composition['preparations'][0]['name'] === 'Pakowanie', 'baseline keeps the direct composition with names');

    // --- Le półprodukt change (masło 100 → 150 g) : version « edit » du półprodukt, version « component » du produit
    $semi = $container->get(SemiProductService::class);
    $semi->update(20, ['name' => 'Ciasto maślane', 'material_data' => [['id' => 4001, 'quantity' => 500], ['id' => 4002, 'quantity' => 150]]]);
    $costAfter = $stored(10);
    $v2 = $last(10);
    expectRv($costAfter > $costBefore && $count(10) === 2 && $count(20) === 2, 'semi-product change: new version for the semi-product and its parent');
    expectRv($v2['source'] === 'component' && $v2['note'] === '« Ciasto maślane »' && $v2['valid_from'] !== null
        && abs((float) $v2['material_cost'] - $costAfter) < 0.001, 'parent version: component change, note with the semi-product name, new cost');
    expectRv($last(20)['source'] === 'edit', 'semi-product version: edit');
    $history = $versions->history(10);
    $flat = $history['versions'][0]['flat_changes'];
    expectRv($history['versions'][0]['changes'] === [] && count($flat) === 1 && $flat[0]['name'] === 'Masło 82 %' && $flat[0]['change'] === 'qty'
        && $flat[0]['after'] > $flat[0]['before'], 'history: no direct change, butter quantity up in the flattened list');
    expectRv(abs($history['versions'][0]['cost_delta'] - ($costAfter - $costBefore)) < 0.001 && $history['current']['untracked'] === false, 'history: cost delta, current state tracked');

    // --- Renommer sans changer la composition : pas de nouvelle version
    $semi->update(20, ['name' => 'Ciasto maślane (laminowane)', 'material_data' => [['id' => 4001, 'quantity' => 500], ['id' => 4002, 'quantity' => 150]]]);
    expectRv($count(10) === 2 && $count(20) === 2, 'rename only: no new version');

    // --- La recette elle-même change (2 → 3 półprodukty, karton retiré) : version « edit » avec les différences directes
    $container->get(RecipeService::class)->update(10, ['sfp_data' => [['id' => 20, 'quantity' => 3]], 'preparation_type' => [['id_preparation' => 7, 'duration_second' => 60]]]);
    $v3 = $last(10);
    $changes = $versions->history(10)['versions'][0]['changes'];
    $byName = array_column($changes, null, 'name');
    expectRv($v3['source'] === 'edit' && (int) $v3['version_no'] === 3 && $v3['note'] === null, 'recipe edit: version 3, edit');
    expectRv(($byName['Ciasto maślane (laminowane)']['change'] ?? null) === 'qty' && (float) $byName['Ciasto maślane (laminowane)']['before'] === 2.0
        && (float) $byName['Ciasto maślane (laminowane)']['after'] === 3.0 && ($byName['Karton 600 x 400']['change'] ?? null) === 'removed', 'recipe edit: semi-product 2 → 3, carton removed');

    // --- Rentabilité : août au coût de la version de départ, octobre (mois courant) à la composition actuelle
    $pdo->exec("UPDATE n_recipe_version SET valid_from = '2026-09-15 10:00:00' WHERE id_recipe = 10 AND version_no = 2");
    $pdo->exec("UPDATE n_recipe_version SET valid_from = '2026-10-02 10:00:00' WHERE id_recipe = 10 AND version_no = 3");
    $costNow = $stored(10);
    $profit = $container->get(ProfitabilityService::class);
    $aug = $profit->production('2026-08-01', '2026-08-31', 1.0)['groups'][0]['materials'];
    $oct = $profit->production('2026-10-01', '2026-10-31', 1.0)['groups'][0]['materials'];
    expectRv(abs($aug - 10 * $costBefore) < 0.01, 'August valued with the baseline composition (' . $aug . ' vs ' . 10 * $costBefore . ')');
    expectRv(abs($oct - 10 * $costNow) < 0.01 && $costNow > $costAfter, 'October valued with the current composition');
    $sep = $profit->recipeIngredientsAt(10, '2026-09-30 23:59:59');
    expectRv(abs($profit->ingredientsCostAt($sep, '2026-09-30 23:59:59') - $costAfter) < 0.01, 'September: version 2 (valid from 15 September)');

    // --- Page d'historique : prix du masło +50 % le 5 octobre → effet prix en octobre, effet composition en septembre
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO supplier (id, name) VALUES (1, 'Mleczarnia')");
    $pdo->exec("SET SESSION sql_mode = @mode");
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES
        (1, 4002, 400, 10000, '2026-01-01 08:00:00'), (1, 4002, 600, 10000, '2026-10-05 08:00:00')");
    $page = (new RecipeHistoryViewService($versions, $container->make(ProfitabilityService::class)))->build(10, new DateTimeImmutable('2026-10-20 12:00:00'));
    $months = array_column($page['months'], null, 'ym');
    expectRv(count($page['months']) === 12 && $page['months'][0]['ym'] === '2025-11' && $months['2026-10']['current'] === true, 'history page: 12 months up to the current one');
    expectRv($months['2026-08']['version'] === 1 && $months['2026-09']['version'] === 2 && $months['2026-10']['version'] === 3, 'version in force at each month end');
    expectRv(abs($months['2026-09']['price_effect']) < 0.001 && $months['2026-09']['composition_effect'] > 0, 'September: composition effect only');
    expectRv($months['2026-10']['price_effect'] > 0 && $months['2026-10']['composition_effect'] > 0
        && abs($months['2026-10']['delta'] - $months['2026-10']['price_effect'] - $months['2026-10']['composition_effect']) < 0.001, 'October: price + composition effects add up');
    expectRv(abs($page['cost_now'] - $months['2026-10']['cost']) < 0.001 && $page['kind'] === 'product' && $page['product']['id'] === 900
        && count($page['versions']) === 3 && $page['last_change'] === '2026-10-02 10:00:00', 'history page: tiles, product link, versions');

    // --- Nouvelle recette : première version « create » datée
    $pdo->exec("INSERT INTO product_recipe (id, name, description, id_type, is_subrecipe, id_unit, yield_quantity) VALUES (30, 'Krem', '', 1, 1, 3, 1000)");
    $pdo->exec("INSERT INTO product_recipe_connection (parent_recipe_id, child_recipe_id, child_ingredient_id, quantity) VALUES (30, NULL, 4002, 200)");
    $container->get(FlattenedRecipeService::class)->flatForSingle(30);
    expectRv($count(30) === 1 && $last(30)['source'] === 'create' && $last(30)['valid_from'] !== null, 'new recipe: first version dated, source create');

    echo "recipe_versions_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
