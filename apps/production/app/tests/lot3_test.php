<?php
declare(strict_types=1);
/*
 * Lot 3 audytu : jedna karta produktu — jedna walidacja logistyki (Karta, Załadunek, Wagi BDO, nowe produkty),
 * stan minimalny opcjonalny przy tworzeniu (bez minimum = MTO, z minimum = MTS na karcie), jeden wskaźnik
 * « pracownik aktywny » (Budżet personelu = moduł produkcji).
 *
 *   php tests/lot3_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide) ; structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migrations
 * tfb appliquées). La base erp_lot3_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Domain\Product\ProductLogistics;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Repositories\Product\FinalProductRepository;
use App\Repositories\Staff\StaffRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Audit\ProductWeightAuditService;
use App\Services\Logistics\LogisticsService;
use App\Services\Product\ProductService;

function expectLot3(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Reguła wspólna (bez bazy)
expectLot3(ProductLogistics::errors(['weight' => 500, 'ppc' => 12, 'cpl' => 10, 'lpp' => 8, 'cpp' => 80]) === [], 'dane poprawne');
expectLot3(ProductLogistics::errors(['cpp' => 81], ['cpl' => 10, 'lpp' => 8]) === ['cpp' => ['PALLET_OVER_LAYERS', ['max' => 80]]], 'paleta ponad warstwy × kartony');
expectLot3(ProductLogistics::errors(['lpp' => 7], ['cpl' => 10, 'cpp' => 80])['cpp'][0] === 'PALLET_OVER_LAYERS', 'zmiana warstw sprawdza paletę z wartością bieżącą');
expectLot3(ProductLogistics::errors(['cpl' => 0, 'lpp' => 0, 'cpp' => 0]) === [], 'edycja : 0 = jeszcze nie podano');
expectLot3(ProductLogistics::errors(['weight' => '12.5'])['weight'][0] === 'NOT_INTEGER' && ProductLogistics::errors(['lpp' => 180])['lpp'][0] === 'OUT_OF_RANGE', 'liczby całkowite w zakresie Karty');
$new = ProductLogistics::errors(['weight' => 500, 'ppc' => 12, 'ppb' => 0, 'bpc' => 12, 'cpl' => 10, 'lpp' => 8], [], array_keys(ProductLogistics::RANGES));
expectLot3($new['ppb'][0] === 'OUT_OF_RANGE' && $new['cpp'][0] === 'REQUIRED', 'nowy produkt : wszystkie pola > 0');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_lot3_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "lot3_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['final_product', 'n_prod_item', 'n_prod_product_settings', 'n_prod_group', 'n_prod_param', 'production_employee',
                 'production_sections', 'n_staff_pay', 'n_prod_employee'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, id_section, active) VALUES (3, 'PIEK', 'Piekarnia', 'Boulangerie', 1, 1)");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, id_section, weight, ppc, ppb, bpc, cpl, lpp, cpp, label_type, stock_minimum, shelf_life, active)
                VALUES (20, 'Bagietka', 1, 1, 250, 12, 1, 12, 10, 8, 80, 0, 0, 120, 1)");
    $refused = function (callable $fn, string $needle = ''): bool {
        try { $fn(); return false; } catch (CustomException $e) { return $needle === '' || str_contains($e->getMessage(), $needle); }
    };

    // --- Załadunek dnia : ta sama walidacja palety
    $logistics = new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo));
    expectLot3($refused(fn() => $logistics->savePalletData(20, ['cpp' => '90']), 'najwyżej 80'), 'Załadunek : 90 kartonów > 10 × 8 odrzucone');
    expectLot3($refused(fn() => $logistics->savePalletData(20, ['lpp' => '150']), 'od 1 do 100'), 'Załadunek : warstwy w zakresie Karty');
    $saved = $logistics->savePalletData(20, ['cpl' => '12', 'lpp' => '8', 'cpp' => '90']);
    expectLot3($saved === ['cpl' => 12, 'lpp' => 8, 'cpp' => 90, 'consistent' => false]
        && $pdo->query('SELECT CONCAT(cpl, "/", lpp, "/", cpp) FROM final_product WHERE id = 20')->fetchColumn() === '12/8/90', 'Załadunek : niepełna warstwa dozwolona i zapisana');

    // --- Wagi produktów BDO : pełne gramy jak w Karcie
    $products = new ProductService(new FinalProductRepository($pdo), new \App\Repositories\Utils\RecordHistoryRepository($pdo));
    $weights = new ProductWeightAuditService(new FinalProductRepository($pdo), $products);
    expectLot3($refused(fn() => $weights->updateWeight(20, '12,5'), 'liczba całkowita'), 'BDO : 12,5 g odrzucone (kolumna INT)');
    expectLot3($weights->updateWeight(20, '260')['unit_weight_g'] === 260.0, 'BDO : 260 g zapisane');

    // --- Nowy produkt : minimum opcjonalne, karta od razu
    $form = ['name' => 'Chleb', 'id_category' => '1', 'id_section' => '1', 'label_type' => '0', 'weight' => '500', 'ppc' => '10', 'ppb' => '1',
        'bpc' => '10', 'cpl' => '8', 'lpp' => '6', 'cpp' => '48', 'shelf_life' => '90', 'stock_minimum' => ''];
    expectLot3($products->validationErrors($form) === [], 'minimum puste = dane poprawne');
    $errors = $products->validationErrors(['cpp' => '60', 'stock_minimum' => '-1'] + $form);
    expectLot3(str_contains($errors['cpp'] ?? '', 'najwyżej 48') && isset($errors['stock_minimum']), 'nowy produkt : paleta i minimum sprawdzane');
    $mto = (int) $products->insert($form);
    $card = fn(int $id) => $pdo->query("SELECT s.mode, s.stock_min, s.id_primary_group, fp.stock_minimum FROM n_prod_item i JOIN n_prod_product_settings s ON s.id_item = i.id
                                        JOIN final_product fp ON fp.id = i.id_final_product WHERE i.id_final_product = {$id}")->fetch();
    expectLot3($card($mto) == ['mode' => 'MTO', 'stock_min' => null, 'id_primary_group' => 3, 'stock_minimum' => 0], 'bez minimum : karta MTO, minimum ERP 0');
    $mts = (int) $products->insert(['name' => 'Rogal', 'stock_minimum' => '5'] + $form);
    expectLot3($card($mts) == ['mode' => 'MTS', 'stock_min' => '50.000', 'id_primary_group' => 3, 'stock_minimum' => 5], 'minimum 5 kartonów : karta MTS, 50 szt.');

    // --- Jeden wskaźnik « aktywny »
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO production_sections (id, name) VALUES (1, 'Piekarnia')");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (1, 'Anna', 'K.', 6000, 1), (2, 'Jan', 'N.', 5500, 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id_production_employee, active) VALUES (1, 1)");
    $staff = new StaffRepository($pdo);
    $staff->setActive(1, false);
    $staff->setActive(2, false);
    $active = array_column($staff->employees(), 'active', 'id');
    expectLot3((int) $active[1] === 0 && (int) $active[2] === 0
        && $pdo->query('SELECT GROUP_CONCAT(active ORDER BY id_production_employee) FROM n_prod_employee')->fetchColumn() === '0,0', 'budżet → profil produkcji (tablet, zespoły), profil tworzony gdy go nie ma');
    $staff->setActive(2, true);
    expectLot3((int) array_column($staff->employees(), 'active', 'id')[2] === 1, 'powrót do aktywnych');
    expectLot3(!(bool) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$source}' AND TABLE_NAME = 'n_staff_pay' AND COLUMN_NAME = 'active'")->fetchColumn(),
        'kolumna n_staff_pay.active usunięta (migracja 058)');

    echo "lot3_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
