<?php
declare(strict_types=1);
/*
 * Debug testera [201] : receptura produktu = 1 karton (lista receptur « na 1 sztukę » : masa składników, kreator,
 * receptura wspólna, decyzje), receptura produktu testowego (wybór na karcie, wprowadzenie = receptura produktu ERP,
 * produkt wprowadzony bez zmiany), podpowiedź ceny standardowej (koszt / karton × 2,5 · 3 · 4, domyślnie × 3, kurs EUR,
 * zaokrąglenie do 0,10 €, cena z karty nowego produktu).
 *
 *   php tests/tester_debug_201_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 127 appliquée). La base erp_tester_201_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\RecipeUnitCheckRepository;
use App\Services\Product\ProductCommercialService;
use App\Services\Product\RecipeUnitCheckService;
use App\Services\Workshop\Execution\DevProductService;

function expect201(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectFail201(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expect201((int) $e->getCode() === $code, $message . ' : kod ' . $e->getCode() . ' (' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' : brak wyjątku');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_tester_201_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "tester_debug_201_test: SKIP (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['final_product', 'product_recipe', 'product_recipe_type', 'product_recipe_connection', 'product_recipe_preparation_connection', 'flattened_recipe_ingredient',
                 'raw_material', 'unit', 'n_activity_view', 'n_recipe_unit_check', 'test_product', 'test_product_status', 'test_product_type', 'production_sections',
                 'n_dev_product', 'n_dev_product_photo', 'n_dev_trial', 'n_dev_trial_photo', 'n_dev_card', 'n_prod_group', 'n_prod_employee', 'production_employee',
                 'n_mgr_launch', 'n_prod_param', 'erp_company_other_settings', 'n_store_price_config', 'customer', 'final_product_customer_price',
                 'final_product_ppwr_sales_packaging', 'category_product', 'user', 'n_dev_card_frame', 'test_product_changelog', 'test_product_stage', 'n_ai_prompt'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO unit (id, name) VALUES (3, 'g'), (4, 'ml'), (5, 'szt')");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, is_part_of_package) VALUES (1, 'Mąka', 3, 0.004, 0), (2, 'Mleko', 4, 0.003, 0), (3, 'Etykieta', 5, 0.05, 1)");
    $pdo->exec("INSERT INTO product_recipe_type (id, name) VALUES (1, 'Kompozycja'), (2, 'Półprodukt'), (3, 'Produkt gotowy')");
    $pdo->exec("INSERT INTO product_recipe (id, name, id_type, is_subrecipe, id_unit, yield_quantity, precalculated_material_cost) VALUES
        (10, NULL, 3, 1, 5, 1, 0.55), (11, NULL, 3, 1, 5, 1, 6.60), (12, NULL, 3, 1, 5, 1, 0.40), (13, NULL, 3, 1, 5, 1, 0.50), (14, 'Krem', 2, 1, 5, 1, 1.00)");
    // 1 : 60 g na sztukę 80 g (karton 10 szt.) → do sprawdzenia ; 2 : 960 g ≈ karton → OK ; 3 : 40 g, utworzony w kreatorze
    // (tylko reguła kreatora) ; 4 : 60 g, receptura wspólna dla 2 produktów (zablokowana) ; 6 : już sprawdzony.
    $pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (10, 1, 50), (10, 2, 10), (10, 3, 1), (11, 1, 900), (11, 2, 60), (12, 1, 40), (13, 1, 60)");
    $pdo->exec("INSERT INTO product_recipe_connection (parent_recipe_id, child_ingredient_id, child_recipe_id, quantity) VALUES (10, 1, NULL, 50), (10, 2, NULL, 10), (10, NULL, 14, 1), (11, 1, NULL, 900)");
    $pdo->exec("INSERT INTO final_product (id, name, active, ppc, weight, id_recipe, pre_calculated_raw_price_per_carton, standard_price, create_timestamp) VALUES
        (1, 'Bułka sztuka', 1, 10, 80, 10, 0.55, 0, '2026-10-01 10:00:00'), (2, 'Bułka karton', 1, 10, 80, 11, 6.60, 0, '2026-10-01 10:00:00'),
        (3, 'Z kreatora', 1, 12, 100, 12, 0.40, 0, '2026-10-20 13:00:30'), (4, 'Wspólna A', 1, 10, 80, 13, 0.50, 0, '2026-10-01 10:00:00'),
        (5, 'Wspólna B', 1, 10, 80, 13, 0.50, 0, '2026-10-01 10:00:00'), (6, 'Sprawdzony', 1, 10, 80, 10, 0.55, 0, '2026-10-01 10:00:00')");
    $pdo->exec("INSERT INTO n_activity_view (id_session, id_user, route, path, started_at, last_seen_at) VALUES (1, 1, '/products/new-with-recipe', '/products/new-with-recipe', '2026-10-20 12:58:00', '2026-10-20 13:00:00')");
    $pdo->exec("INSERT INTO n_recipe_unit_check (id_product, id_recipe, decision) VALUES (6, 10, 'kept')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $builder->build();

    // --- 1. Receptury « na 1 sztukę » do sprawdzenia ----------------------------------------------------
    $check = $c->get(RecipeUnitCheckService::class);
    $rows = [];
    foreach ($check->list() as $r) $rows[$r['id']] = $r;
    expect201(array_keys($rows) == [3, 1, 4, 5] || (isset($rows[1], $rows[3], $rows[4], $rows[5]) && count($rows) === 4), 'lista : 1 (masa ≈ sztuka), 3 (kreator), 4 i 5 (wspólna) — ' . implode(',', array_keys($rows)));
    expect201(!isset($rows[2]) && !isset($rows[6]), 'lista : bez receptury na karton (2) i bez sprawdzonego (6)');
    expect201($rows[1]['mass'] == 60 && $rows[1]['carton_weight'] == 800 && $rows[1]['cost_scaled'] == 5.5 && !$rows[1]['from_wizard'], 'produkt 1 : masa 60 g (bez szt.), karton 800 g, koszt × 10 = 5,50');
    expect201($rows[3]['from_wizard'] && str_starts_with($rows[3]['reason'], 'utworzony w kreatorze'), 'produkt 3 : utworzony w kreatorze (rejestr aktywności)');
    expect201($rows[4]['blocked'] !== null && str_contains($rows[4]['blocked'], 'wspólna dla 2'), 'produkt 4 : receptura wspólna — bez automatycznego × szt.');
    expectFail201(fn() => $check->decide(4, 'scale', 7), 422, 'receptura wspólna : × szt. odrzucone');
    expectFail201(fn() => $check->decide(1, 'delete', 7), 422, 'nieznana decyzja');
    expectFail201(fn() => $check->decide(2, 'keep', 7), 404, 'produkt spoza listy');
    $res = $check->decide(5, 'keep', 7);
    expect201($res['decision'] === 'kept' && $pdo->query('SELECT decided_by FROM n_recipe_unit_check WHERE id_product = 5')->fetchColumn() == 7, '« już na karton » zapisane (kto)');
    expect201(!in_array(5, array_column($check->list(), 'id'), true), 'po decyzji : produkt znika z listy');
    $repo = $c->get(RecipeUnitCheckRepository::class);
    expect201(count($repo->connections(10)) === 3 && count(array_filter($repo->connections(10), fn($x) => $x['child_recipe_id'] !== null)) === 1, 'składniki do × szt. : surowce i półprodukt');

    // --- 2. Receptura produktu testowego ------------------------------------------------------------------
    $pdo->exec("INSERT INTO test_product_type (id, name) VALUES (1, 'Produkt')");
    $pdo->exec("INSERT INTO test_product (id, name, id_status, id_type, id_recipe) VALUES (90, 'Plecionka', 1, 1, NULL), (91, 'Wprowadzony', 3, 1, NULL)");
    $pdo->exec("INSERT INTO n_dev_product (id_test_product, stage, id_final_product) VALUES (91, 'launched', 2)");
    $dev = $c->get(DevProductService::class);
    $sheet = $dev->sheet(90);
    expect201(in_array(14, array_column($sheet['recipes'], 'id')) && in_array('Bułka sztuka', array_column($sheet['recipes'], 'name'), true), 'karta testu : lista receptur (nazwa produktu dla receptury produktu)');
    $sheet = $dev->setRecipe(90, ['id_recipe' => 14]);
    expect201($sheet['product']['recipe_id'] === 14 && $sheet['recipe_info']['lines'] == 0, 'karta testu : receptura wybrana');
    expectFail201(fn() => $dev->setRecipe(90, ['id_recipe' => 999]), 422, 'receptura spoza listy');
    $dev->setRecipe(90, ['id_recipe' => null]);
    expect201($pdo->query('SELECT id_recipe FROM test_product WHERE id = 90')->fetchColumn() === null, 'karta testu : receptura odłączona');
    expectFail201(fn() => $dev->setRecipe(91, ['id_recipe' => 14]), 422, 'produkt wprowadzony : receptura = receptura produktu ERP');
    $sheet = $dev->launch(90, ['final_product_id' => 1], 7);
    expect201((int) $pdo->query('SELECT id_recipe FROM test_product WHERE id = 90')->fetchColumn() === 10, 'wprowadzenie : receptura testu = receptura produktu ERP');
    expect201($sheet['recipe_info']['ppc'] == 10 && $sheet['recipe_info']['lines'] == 3 && $sheet['recipes'] === [], 'karta po wprowadzeniu : 1 karton = 10 szt., 3 składniki, bez wyboru');

    // --- 3. Podpowiedź ceny standardowej -------------------------------------------------------------------
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.20)");
    $pdo->exec("INSERT INTO n_store_price_config (id, multiplier, limit_coef, reserve_coef) VALUES (1, 2.50, 2.20, 3.20)");
    $commercial = $c->get(ProductCommercialService::class);
    $s = $commercial->summary(2)['suggest'];
    expect201($s['cost_carton_pln'] == 6.6 && $s['rate'] == 4.2 && $s['coef'] == 3.0, 'podpowiedź : koszt 6,60 zł / karton, kurs 4,20, domyślnie × 3');
    expect201(array_map(fn($o) => [$o['coef'], $o['price'], $o['default']], $s['options']) == [[2.5, 3.9, false], [3.0, 4.7, true], [4.0, 6.3, false]], 'podpowiedź : × 2,5 / 3 / 4 → 3,90 / 4,70 / 6,30 € (0,10 €)');
    expect201($s['card'] === null && $s['has_recipe'], 'podpowiedź : bez karty nowego produktu');
    $pdo->exec("INSERT INTO n_dev_card (id, id_test_product, status, source_kind, name, cost_pc_pln, factory_coef, yield_pcs, cost_at, created_at, updated_at) VALUES (5, 91, 'saved', 'text', 'Plecionka', 0.70, 3.00, 12, NOW(), NOW(), NOW())");
    $s = $commercial->summary(2)['suggest'];
    expect201($s['card'] !== null && $s['card']['piece'] == 0.5 && $s['card']['price'] == 5.0, 'z karty nowego produktu : 0,70 zł × 3 ÷ 4,20 = 0,50 € / szt. × 10 = 5,00 €');
    $pdo->exec("UPDATE final_product SET pre_calculated_raw_price_per_carton = 0, id_recipe = NULL WHERE id = 3");
    $s = $commercial->summary(3)['suggest'];
    expect201($s['options'] === [] && !$s['has_recipe'], 'bez receptury : bez podpowiedzi z kosztu');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "tester_debug_201_test: OK\n";
