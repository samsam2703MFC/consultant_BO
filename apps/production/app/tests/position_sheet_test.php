<?php
declare(strict_types=1);
/*
 * Karty stanowisk (fiches de poste) : karty wypełnione migracją (biuro i pracownia), zapis = nowa wersja do
 * zatwierdzenia, zatwierdzenie, przełożony bez pętli, pracownicy produkcji na stanowisku, PDF PL / FR, wyszukiwarka.
 *
 *   php tests/position_sheet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_position_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiException;
use App\Repositories\Search\GlobalSearchRepository;
use App\Services\Organization\PositionSheetService;
use App\Services\Organization\PositionTaskService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectPos(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_position_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "position_sheet_test: SKIPPED (no database: {$e->getMessage()})\n";
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
                 'test_product_stage', 'test_product_changelog'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (10, 'Jan', 'Kowalski', 5000, 1), (11, 'Ewa', 'Nowak', 5200, 1), (12, 'Piotr', 'Zieliński', 4800, 1)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $sheets = $container->get(PositionSheetService::class);
    $tasks = $container->get(PositionTaskService::class);
    $id = fn(string $code) => (int) $pdo->query("SELECT id FROM n_position WHERE code = " . $pdo->quote($code))->fetchColumn();
    $error = function (callable $fn): ?string {
        try { $fn(); return null; } catch (ApiException $e) { return implode(',', array_map(fn($x) => $x['field'] . ':' . $x['code'], $e->fieldErrors())) ?: $e->errorCode(); }
    };

    // --- Karty z migracji : biuro i pracownia, przełożony, treść PL / FR
    $positions = $tasks->positions();
    expectPos(count($positions) === 12 && count(array_filter($positions, fn($p) => $p['kind'] === 'atelier')) === 7, '5 office + 7 workshop positions');
    expectPos(count(array_filter($positions, fn($p) => $p['sheet_state'] === 'draft')) === 12, 'every position has a sheet to validate');
    $baker = $sheets->sheet($id('PIEKARZ'));
    expectPos($baker['filled'] && $baker['version'] === 1 && $baker['validated_at'] === null, 'baker sheet filled, version 1, not validated');
    expectPos($baker['position']['reports_to']['name_pl'] === 'Brygadzista zmiany' && $baker['position']['kind_label'][1] === 'Atelier', 'baker reports to the shift leader');
    expectPos(count($baker['texts']['responsibilities']['pl']) === 6 && count($baker['texts']['responsibilities']['fr']) === 6
        && str_starts_with($baker['texts']['mission']['fr'][0], 'Fabrique le pain'), 'sheet texts in Polish and French');
    $leader = $sheets->sheet($id('BRYGADZISTA'));
    expectPos(count($leader['subordinates']) === 4, 'shift leader has 4 workshop positions under him');
    expectPos($sheets->sheet($id('KIEROWNIK_PRODUKCJI'))['manager_module'] === true, 'production manager sheet links to the manager module');

    // --- Zapis : punkty oczyszczone, nowa wersja do zatwierdzenia ; ten sam tekst = bez nowej wersji
    $sheets->save($id('PIEKARZ'), ['responsibilities_pl' => "- Zaczyn\n\n• Wypiek \n 3) Sprzątanie"], 1);
    $baker = $sheets->sheet($id('PIEKARZ'));
    expectPos($baker['texts']['responsibilities']['pl'] === ['Zaczyn', 'Wypiek', 'Sprzątanie'] && $baker['version'] === 2, 'saved lines cleaned, version 2');
    expectPos($baker['texts']['responsibilities']['fr_raw'] !== '', 'French text untouched');
    $sheets->validate($id('PIEKARZ'), 1);
    $baker = $sheets->sheet($id('PIEKARZ'));
    expectPos($baker['validated_at'] !== null && $baker['validated_by_name'] === 'Anna K.', 'validated by Anna');
    $sheets->save($id('PIEKARZ'), ['responsibilities_pl' => "Zaczyn\nWypiek\nSprzątanie"], 1);
    expectPos($sheets->sheet($id('PIEKARZ'))['version'] === 2 && $sheets->sheet($id('PIEKARZ'))['validated_at'] !== null, 'same text: no new version, still validated');
    $sheets->save($id('PIEKARZ'), ['skills_fr' => 'Pétrissage'], 1);
    expectPos($sheets->sheet($id('PIEKARZ'))['version'] === 3 && $sheets->sheet($id('PIEKARZ'))['validated_at'] === null, 'changed text: new version to validate again');
    expectPos($error(fn() => $sheets->save($id('PIEKARZ'), ['mission_pl' => str_repeat('a', 4001)], 1)) === 'mission_pl:TOO_LONG', 'text limited to 4000 characters');

    // --- Przełożony : bez pętli
    expectPos($error(fn() => $sheets->save($id('BRYGADZISTA'), ['reports_to_id' => $id('PIEKARZ')], 1)) === 'reports_to_id:POSITION_CYCLE', 'no cycle (boss under his own report)');
    expectPos($error(fn() => $sheets->save($id('PIEKARZ'), ['reports_to_id' => $id('PIEKARZ')], 1)) === 'reports_to_id:POSITION_CYCLE', 'a position does not report to itself');
    $sheets->save($id('KIEROWCA'), ['reports_to_id' => null, 'kind' => 'atelier', 'group_id' => null], 1);
    expectPos($sheets->sheet($id('KIEROWCA'))['position']['reports_to'] === null, 'boss cleared');

    // --- Pracownicy produkcji : profil tworzony w razie potrzeby, lista zastępuje poprzednią
    expectPos($sheets->setEmployees($id('PIEKARZ'), ['employee_ids' => ['10', 11]]) === 2, 'two bakers');
    expectPos(array_column($sheets->sheet($id('PIEKARZ'))['employees'], 'surname') === ['Kowalski', 'Nowak'], 'bakers listed on the sheet');
    $sheets->setEmployees($id('PIEKARZ'), ['employee_ids' => [12]]);
    expectPos(array_column($sheets->sheet($id('PIEKARZ'))['employees'], 'surname') === ['Zieliński']
        && (int) $pdo->query('SELECT COUNT(*) FROM n_prod_employee WHERE id_position IS NOT NULL')->fetchColumn() === 1, 'new list replaces the old one');
    expectPos($error(fn() => $sheets->setEmployees($id('PIEKARZ'), ['employee_ids' => [999]])) === 'employee_ids:REFERENCE_NOT_FOUND', 'unknown employee refused');
    expectPos((int) array_values(array_filter($tasks->positions(), fn($p) => $p['code'] === 'PIEKARZ'))[0]['employee_count'] === 1, 'employee count on the positions page');

    // --- PDF i wyszukiwarka
    $pdf = $sheets->pdf($id('KIEROWCA'), 'both');
    expectPos(str_starts_with($pdf['content'], '%PDF') && $pdf['filename'] === 'KARTA_STANOWISKA_KIEROWCA.pdf' && strlen($pdf['content']) > 5000, 'PDF PL + FR');
    expectPos($sheets->pdf($id('ZAKUPY'), 'fr')['filename'] === 'KARTA_STANOWISKA_ZAKUPY_FR.pdf', 'PDF in French only');
    $found = (new GlobalSearchRepository($pdo))->positions('%boulanger%', 5);
    expectPos($found && $found[0]['name_pl'] === 'Piekarz', 'search « boulanger » finds the baker sheet');
    $found = (new GlobalSearchRepository($pdo))->positions('%palettes rendues%', 5);
    expectPos(in_array('Kierowca-dostawca', array_column($found, 'name_pl'), true), 'search inside responsibilities (driver)');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "position_sheet_test: OK\n";
