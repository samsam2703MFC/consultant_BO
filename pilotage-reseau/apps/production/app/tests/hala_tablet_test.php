<?php
/**
 * [409–411] Tablet hali « Mój dzień » (makieta des asystenci stanowisk, ekrany A–H) :
 *  - migrations 247, 248, 249 rejouables (strefy de départ une fois par groupe actif, groupe d'emballage et groupes à
 *    schładzanie réglés, paramètres, droit « hala » pour Jakość et Kierownik produkcji seulement, sorte HACCP) ;
 *  - réglages par groupe (défaut, enregistré, groupe d'emballage, validations de la page ERP), strefy (ajout, retrait) ;
 *  - Mój dzień : étapes dans l'ordre, une « teraz » ; Co robię (plan de l'équipe, heure du plan, compteur « zrobione » =
 *    une seule partie par produit et jour, plus / moins, pas sous les pertes) ; Skanuj worki (bon sac → lot dans la partie,
 *    autre surowiec → STOP sans écriture, date dépassée → STOP, code inconnu → surowiec choisi, registre « manual ») ;
 *    « Dalej » refusé tant que la condition manque ; Sprzątanie (fait, annulé) ; « Zatwierdź dzień » refusé avant la fin
 *    puis validation du jour (n_prod_day_close, note automatique pour la kontrola jakości), n_hala_day ;
 *  - Schładzanie (minutnik, mesure, trop chaud → wezwanie « chill » automatique, états running / ok / warm / late) ;
 *  - Pomoc (un seul wezwanie ouvert par groupe, motif plus précis), Zmiana (refusée hors chef, statuts rouge / vert,
 *    Idę → Załatwione, « Zatwierdź zmianę » refusée puis acceptée) ;
 *  - HACCP miesiąca : schładzanie hors limites et jour de production sans sprzątanie complet = éléments à expliquer ;
 *  - GRATIS : cartons gratuits par ligne de commande (tablette d'emballage) ;
 *  - service construit par PHP-DI, routes API documentées (OpenAPI), libellés PL / FR de la tablette.
 *
 *   php tests/hala_tablet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_hala_test recréée (tables n_ de docs/db/n_schema.sql + migrations 098, 114, 209, 228 + tables ERP minimales +
 * migrations 247–249), supprimée à la fin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\OpenApiSpec;
use App\Core\Api\Workshop\Translator;
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsBoardRepository;
use App\Services\Organization\Wizards\HaccpMonthService;
use App\Services\Workshop\Execution\HalaService;
use App\Services\Workshop\Migration\MigrationRunner;

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fail++;
};
$error = function (callable $fn): ?string {
    try { $fn(); } catch (ApiException $e) { return $e->errorCode(); } catch (CustomException $e) { return 'CUSTOM:' . $e->getMessage(); }
    return null;
};

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "hala_tablet_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
$db = 'erp_hala_test';
$pdo->exec("DROP DATABASE IF EXISTS {$db}");
$pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE {$db}");
$pdo->exec("SET SESSION sql_mode = ''");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$dir = __DIR__ . '/../migrations/tfb/';

// Tables n_ telles que le schéma de référence les décrit (après la migration 093), sans clés étrangères vers l'ERP.
foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../docs/db/n_schema.sql')) as $sql) {
    if (!str_starts_with($sql, 'CREATE TABLE')) continue;
    $sql = implode("\n", array_filter(explode("\n", $sql), fn($l) => !preg_match('/CONSTRAINT `[^`]+` FOREIGN KEY/', $l)));
    $pdo->exec(preg_replace('/,(\s*\n\))/', '$1', $sql));
}
// Tables ERP minimales lues par la tablette (et par la synchronisation du registre des lots : factures, réceptions).
$pdo->exec('DROP TABLE IF EXISTS n_inv, n_inv_line, n_po, n_po_line, n_po_receipt');
foreach ([
    'CREATE TABLE n_inv (id INT PRIMARY KEY, id_supplier INT NULL, number VARCHAR(40) NULL, issue_date DATE NULL, created_at DATETIME NULL, kind VARCHAR(20), status VARCHAR(20))',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(120))',
    'CREATE TABLE n_inv_line (id INT PRIMARY KEY, id_inv INT, id_raw_material INT NULL, quantity DECIMAL(14,3), factor DECIMAL(10,3) NULL)',
    'CREATE TABLE n_po (id INT PRIMARY KEY, id_supplier INT NULL)',
    'CREATE TABLE n_po_line (id INT PRIMARY KEY, id_po INT, id_raw_material INT)',
    'CREATE TABLE n_po_receipt (id INT PRIMARY KEY, id_po_line INT, lot_number VARCHAR(64) NULL, best_before DATE NULL, received_on DATE)',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), ppc INT NULL, id_recipe INT NULL, id_category INT NULL, id_section INT NULL, active TINYINT DEFAULT 1)',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE product_recipe (id INT PRIMARY KEY, name VARCHAR(120), yield_quantity DECIMAL(12,3) NULL, id_unit INT NULL, description TEXT NULL)',
    'CREATE TABLE product_recipe_connection (id INT AUTO_INCREMENT PRIMARY KEY, parent_recipe_id INT, child_recipe_id INT NULL, child_ingredient_id INT NULL, quantity DECIMAL(12,4))',
    'CREATE TABLE flattened_recipe_ingredient (recipe_id INT, material_id INT, quantity DECIMAL(14,6))',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), name_fr VARCHAR(120) NULL, id_unit INT NULL, id_category INT NULL)',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE category_material (id INT PRIMARY KEY, id_group INT NULL)',
    'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(60), surname VARCHAR(60), id_section INT NULL)',
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL)',
    'CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, status INT, id_wdt INT NULL, is_freebie TINYINT DEFAULT 0, expected_shipping_date DATETIME)',
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
] as $sql) $pdo->exec($sql);
// Tables ajoutées après le schéma de référence : jour validé ligne par ligne (098), kompozycje (114), lots (209), HACCP (228).
foreach (['098_kiosk_day_validation.sql', '114_group_orders.sql', '209_material_lots_samples.sql', '228_haccp_month.sql'] as $file) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents($dir . $file)) as $sql) {
        try { $pdo->exec($sql); } catch (PDOException) { /* données de départ d'autres modules : sans objet ici */ }
    }
}

// Données : groupes, postes, personnes, produits, recette, plan du jour, registre des lots.
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES
    (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#8D1D2C', 1, 1), (2, 'GARMAZERIA', 'Garmażeria', 'Traiteur', '#B0792A', 2, 1),
    (3, 'PAKOWANIE', 'Pakowanie', 'Emballage', '#3f7a52', 3, 1), (4, 'STARA', 'Stara', 'Ancienne', '#777777', 9, 0)");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (1, 'PIEKARZ', 'Piekarz', 'Boulanger', 'production', 1, 1),
    (2, 'BRYGADZISTA', 'Brygadzista zmiany', 'Chef d''équipe', 'production', 2, 1), (3, 'JAKOSC', 'Specjalista ds. jakości', 'Qualité', 'erp', 3, 1),
    (4, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Responsable production', 'erp', 4, 1), (5, 'LOGISTYKA', 'Logistyka', 'Logistique', 'erp', 5, 1)");
$pdo->exec("INSERT INTO production_employee (id, name, surname) VALUES (70, 'Marek', 'Testowy'), (80, 'Ewa', 'Brygadzistka'), (90, 'Ola', 'Garmaż')");
$pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, id_position, active) VALUES (7, 70, 1, 1, 1), (8, 80, NULL, 2, 1), (9, 90, 2, NULL, 1)");
$pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Pieczywo')");
$pdo->exec("INSERT INTO final_product (id, name, ppc, id_recipe, id_category, active) VALUES (10, 'Baguette Tradition 500 g', 18, 50, 1, 1), (11, 'Pain Demi-Gris 600 g', 11, NULL, 1, 1),
    (12, 'Lasagne', 6, NULL, 1, 1)");
$pdo->exec("INSERT INTO n_prod_item (id, item_type, base_unit, id_final_product) VALUES (100, 'product', 'szt', 10), (101, 'product', 'szt', 11), (102, 'product', 'szt', 12)");
$pdo->exec("INSERT INTO n_prod_product_settings (id_item, id_primary_group) VALUES (100, 1), (101, 1), (102, 2)");
$pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'kg')");
$pdo->exec("INSERT INTO raw_material (id, name, name_fr, id_unit) VALUES (501, 'Mąka T65', 'Farine T65', 1), (502, 'Woda', 'Eau', 1), (503, 'Drożdże', 'Levure', 1), (509, 'Mąka żytnia', 'Farine de seigle', 1)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (50, 501, 9), (50, 502, 6), (50, 503, 0.2)");
$pdo->exec("INSERT INTO n_material_trace (id_raw_material, tracked) VALUES (502, 0)");
$pdo->exec("INSERT INTO n_prod_week_slot (week_start, id_item, work_date, quantity, start_time) VALUES ('{$today}', 100, '{$today}', 1044, NULL), ('{$today}', 101, '{$today}', 143, '11:00:00'),
    ('{$today}', 102, '{$today}', 60, NULL)");
$future = date('Y-m-d', strtotime('+60 days'));
$past = date('Y-m-d', strtotime('-3 days'));
$pdo->exec("INSERT INTO n_material_lot (id, id_raw_material, lot_number, best_before, source, received_on, active) VALUES
    (1, 501, 'M-4471', '{$future}', 'receipt', '{$past}', 1), (2, 509, 'R-999', '{$future}', 'receipt', '{$past}', 1), (3, 503, 'D-OLD', '{$past}', 'receipt', '{$past}', 0)");

// --- Migrations 247, 248, 249 : deux fois (rejouables)
for ($k = 0; $k < 2; $k++) {
    foreach (['247_hala_tablet.sql', '248_hala_chill.sql', '249_hala_help.sql'] as $file) {
        foreach (MigrationRunner::splitStatements((string) file_get_contents($dir . $file)) as $sql) $pdo->exec($sql);
    }
}
$q = fn(string $sql) => $pdo->query($sql)->fetchAll();
$zones = $q('SELECT id_group, COUNT(*) AS n FROM n_hala_clean_zone GROUP BY id_group ORDER BY id_group');
$check(array_column($zones, 'n', 'id_group') === [1 => 4, 2 => 4, 3 => 4], 'migration 247 replayed: four starting zones per active group, once (inactive group without zones)');
$sets = $q('SELECT id_group, step_make, step_bags, step_chill, step_pack FROM n_hala_group ORDER BY id_group');
$check(array_map(fn($r) => implode('', [$r['id_group'], $r['step_make'], $r['step_bags'], $r['step_chill'], $r['step_pack']]), $sets) === ['21110', '30001'],
    'migrations 247–248: packing group (Sprzątanie → Pakuję) and chilling group set, others on defaults');
$params = array_column($q("SELECT code, value FROM n_prod_param WHERE code LIKE 'hala.%' ORDER BY code"), 'value', 'code');
$check($params === ['hala.chill_max_minutes' => '120', 'hala.chill_target_c' => '10', 'hala.delay_tolerance_pct' => '15', 'hala.help_poll_s' => '15',
    'hala.shift_end' => '14:00', 'hala.shift_lead_positions' => 'BRYGADZISTA,KIEROWNIK_PRODUKCJI', 'hala.shift_start' => '06:00'], 'migrations 248–249: parameters once');
foreach (['hala.chill_target_c', 'hala.chill_max_minutes', 'hala.delay_tolerance_pct', 'hala.help_poll_s'] as $code) {
    $check(isset(ParamRules::RANGES[$code]) && ParamRules::rangeError($code, $params[$code]) === null, "ParamRules bounds for {$code}");
}
$grants = array_column($q("SELECT p.code FROM n_page_access a JOIN n_position p ON p.id = a.id_position WHERE a.page_key = 'hala' ORDER BY p.code"), 'code');
$check($grants === ['JAKOSC', 'KIEROWNIK_PRODUKCJI'], 'migration 249: right « hala » for Jakość and Kierownik produkcji only');
$kind = $q("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_haccp_month_review' AND COLUMN_NAME = 'kind'")[0]['t'];
$check(str_contains($kind, "'hala_chill'") && str_contains($kind, "'hala_clean'") && substr_count($kind, 'hala_chill') === 1, 'migration 248: HACCP review kinds hala_chill / hala_clean');
$check(PageAccess::keyFor('/production/hala') === 'hala' && PageAccess::keyFor('/ajax/production/hala/zone') === 'hala' && PageAccess::keyFor('/production/haccp-month') === 'production',
    'PageAccess: /production/hala under its own right (longest prefix), HACCP page unchanged');

// --- Service construit par PHP-DI (comme l'application)
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => $pdo]);
$container = $b->build();
/** @var HalaService $hala */
$hala = $container->get(HalaService::class);
$ctx = fn(?int $employee, int $group = 1, string $lang = 'pl') => new ApiContext('t' . bin2hex(random_bytes(4)), new Translator($lang), null, [], [], [], 9, $employee, $group);
$marek = $ctx(7);

// --- Réglages
$check($hala->settingsOf(3) === ['guided' => true, 'make' => false, 'bags' => false, 'chill' => false, 'clean' => true, 'pack' => true]
    && $hala->settingsOf(1) === ['guided' => true, 'make' => true, 'bags' => true, 'chill' => false, 'clean' => true, 'pack' => false]
    && HalaService::stepsOf($hala->settingsOf(2)) === ['make', 'bags', 'chill', 'clean'], 'settings: saved packing group, default group, chilling group (fixed order)');
$check($error(fn() => $hala->saveGroup(['group_id' => 1, 'guided' => true, 'bags' => true], 1)) === 'CUSTOM:« Skanuj worki » wymaga kroku « Co robię dziś » (worki skanuje się do tego, co zrobione).'
    && $error(fn() => $hala->saveGroup(['group_id' => 1, 'guided' => true], 1)) === 'CUSTOM:Grupa potrzebuje co najmniej jednego kroku.'
    && $error(fn() => $hala->saveGroup(['group_id' => 99, 'make' => true], 1)) === 'CUSTOM:Nieznana grupa.', 'settings page: bags need make, at least one step, known group');
$sd = $hala->saveZone(['group_id' => 2, 'name_pl' => 'Piec', 'name_fr' => 'Four', 'icon' => 'oven'], 1);
$g2 = array_values(array_filter($sd['groups'], fn($g) => $g['id'] === 2))[0];
$oven = end($g2['zones']);
$check(count($sd['groups']) === 3 && count($g2['zones']) === 5 && $oven['name_pl'] === 'Piec' && $oven['icon'] === 'oven', 'settings page: zone added (active groups only)');
$sd = $hala->saveZone(['id' => $oven['id'], 'group_id' => 2, 'name_pl' => 'Piec', 'icon' => 'oven', 'active' => false], 1);
$check($error(fn() => $hala->saveZone(['group_id' => 2, 'name_pl' => '', 'icon' => 'oven'], 1)) !== null && $error(fn() => $hala->saveZone(['group_id' => 2, 'name_pl' => 'X', 'icon' => 'rocket'], 1)) !== null
    && count(array_filter(array_values(array_filter($sd['groups'], fn($g) => $g['id'] === 2))[0]['zones'], fn($z) => $z['active'])) === 4, 'settings page: zone removed (kept, inactive) ; name and icon checked');

// --- A / B : Mój dzień, Co robię dziś
$d = $hala->day($marek);
$check($d['guided'] && $d['person']['name'] === 'Marek Testowy' && $d['person']['first_name'] === 'Marek' && !$d['person']['lead']
    && array_column($d['steps'], 'key') === ['make', 'bags', 'clean', 'end'] && array_column($d['steps'], 'state') === ['now', 'later', 'later', 'later'] && $d['now'] === 'make',
    'day: steps of the group in order, one « teraz »');
$m = $d['make'];
$check(array_column($m['items'], 'item_id') === [100, 101] && $m['items'][0]['planned'] == 1044 && $m['items'][1]['start'] === '11:00' && $m['items'][0]['state'] === 'now'
    && $m['items'][1]['state'] === 'later' && $m['now_item'] === 100 && $m['total'] === 2 && $m['done'] === 0, 'make: team plan of the day, plan hour, first not done = teraz');
$hala->made(100, ['made' => 612], $marek);
$hala->made(100, ['made' => 630], $marek);
$d = $hala->made(100, ['made' => 600], $marek);
$batches = $q("SELECT id, quantity, lot_code, cancelled_at FROM n_prod_output WHERE id_item = 100 AND work_date = '{$today}'");
$check(count($batches) === 1 && (float) $batches[0]['quantity'] === 600.0 && $batches[0]['cancelled_at'] === null && $d['make']['items'][0]['made'] == 600,
    'make counter: one batch per product and day (grows, shrinks)');
$check($error(fn() => $hala->made(100, ['made' => -1], $marek)) === 'VALIDATION_FAILED' && $error(fn() => $hala->made(999, ['made' => 1], $marek)) === 'VALIDATION_FAILED',
    'make counter: negative or unknown product refused');
$pdo->exec("INSERT INTO n_prod_waste (work_date, id_group, id_item, quantity, reason, id_employee, id_device) VALUES ('{$today}', 1, 100, 12, 'burnt', 7, 9)");
$check($error(fn() => $hala->made(100, ['made' => 10], $marek)) === 'WASTE_EXCEEDS_PRODUCED', 'make counter: not below the waste of the day');
$d = $hala->step('make', $marek);
$check(array_column($d['steps'], 'state') === ['done', 'now', 'later', 'later'] && $d['make']['waste'] == 12, 'Dalej on make: seen, bags = teraz');

// --- C : Skanuj worki
$bags = $d['bags'];
$ing = array_column($bags['products'][0]['ingredients'], null, 'material_id');
$check(count($bags['products']) === 1 && $bags['focus'] === 100 && !$ing[501]['done'] && $ing[502]['done'] && !$ing[502]['tracked'] && !$ing[503]['done'] && !$bags['complete'],
    'bags: tracked materials to scan, untracked « bez worka »');
$check($error(fn() => $hala->step('bags', $marek)) === 'HALA_STEP_INCOMPLETE', 'Dalej on bags refused while a bag is missing');
$lots = fn() => (int) $q('SELECT COUNT(*) AS n FROM n_prod_output_lot')[0]['n'];
$r = $hala->scanBag(['item_id' => 100, 'code' => 'r-999'], $marek);
$check($r['result'] === 'wrong' && $r['material'] === 'Mąka żytnia' && $r['expected'] === ['Mąka T65', 'Drożdże'] && $lots() === 0, 'bag of another material: STOP, nothing recorded');
$r = $hala->scanBag(['item_id' => 100, 'code' => 'D-OLD'], $marek);
$check($r['result'] === 'expired' && $r['material'] === 'Drożdże' && $lots() === 0, 'expired bag: STOP, nothing recorded');
$r = $hala->scanBag(['item_id' => 100, 'code' => ' m-4471 '], $marek);
$lot = $q("SELECT * FROM n_prod_output_lot WHERE id_material = 501")[0] ?? [];
$check($r['result'] === 'ok' && $r['material'] === 'Mąka T65' && ($lot['lot_number'] ?? null) === 'M-4471' && (int) ($lot['id_material_lot'] ?? 0) === 1 && $lot['best_before'] === $future,
    'good bag: lot recorded in the batch (register lot, best before)');
$r = $hala->scanBag(['item_id' => 100, 'code' => 'XYZ-1'], $marek);
$check($r['result'] === 'unknown' && array_column($r['choices'], 'material_id') === [501, 503], 'unknown code: choose the material');
$check($error(fn() => $hala->scanBag(['item_id' => 100, 'code' => 'XYZ-1', 'material_id' => 509], $marek)) === 'VALIDATION_FAILED', 'unknown code: material outside the recipe refused');
$r = $hala->scanBag(['item_id' => 100, 'code' => 'XYZ-1', 'material_id' => 503], $marek);
$manual = $q("SELECT source, active FROM n_material_lot WHERE lot_number = 'XYZ-1'");
$check($r['result'] === 'ok' && $r['day']['bags']['complete'] && $manual === [['source' => 'manual', 'active' => 1]], 'unknown code + material: recorded, number added to the register (manual)');
$check($error(fn() => $hala->scanBag(['item_id' => 101, 'code' => 'M-4471'], $marek)) === 'HALA_NO_BATCH', 'bags for a product not made yet: 409 HALA_NO_BATCH');
$d = $hala->step('bags', $marek);
$check(array_column($d['steps'], 'state') === ['done', 'done', 'now', 'later'] && $d['end']['scans']['scans'] === 2, 'Dalej on bags: done, clean = teraz');

// --- E : Sprzątanie, F : Koniec dnia
$check($error(fn() => $hala->close($marek)) === 'HALA_STEP_INCOMPLETE', 'Zatwierdź dzień refused before the last step');
$zoneIds = array_column($d['clean']['zones'], 'id');
foreach ($zoneIds as $z) $d = $hala->clean($z, ['done' => true], $marek);
$check($d['clean']['done'] === 4 && $d['clean']['complete'] && $d['clean']['zones'][0]['by'] === 'Marek Testowy', 'clean: four zones done (who)');
$d = $hala->clean($zoneIds[2], ['done' => false], $marek);
$check($d['clean']['done'] === 3 && $error(fn() => $hala->step('clean', $marek)) === 'HALA_STEP_INCOMPLETE', 'clean: undo (wrong touch), Dalej refused');
$check($error(fn() => $hala->clean(9999, ['done' => true], $marek)) === 'NOT_FOUND' && $error(fn() => $hala->clean($oven['id'], ['done' => true], $ctx(9, 2))) === 'NOT_FOUND',
    'clean: zone of the group only, active only');
$hala->clean($zoneIds[2], ['done' => true], $marek);
$d = $hala->step('clean', $marek);
$check($d['now'] === 'end' && $d['end']['can_close'] && !$d['end']['finished'], 'all steps done: Koniec dnia = teraz');
$d = $hala->close($marek);
$close = $q("SELECT * FROM n_prod_day_close WHERE work_date = '{$today}' AND id_group = 1")[0] ?? null;
$hd = $q("SELECT * FROM n_hala_day WHERE work_date = '{$today}' AND id_group = 1")[0] ?? null;
$check($close !== null && (int) $close['id_employee'] === 7 && $close['note'] === 'Kontrola jakości : obchód Kierownika produkcji (tablet hali)' && $hd !== null
    && $hd['summary'] === 'zrobione 0/2 · sprzątanie 4/4' && $d['end']['finished'] && end($d['steps'])['state'] === 'done' && $d['now'] === null,
    'Zatwierdź dzień: production day validated (task « Dzień grupy »), quality check noted, hala day finished');
$check($error(fn() => $hala->made(100, ['made' => 700], $marek)) === 'DAY_CLOSED', 'day validated: counter locked');

// --- D : Schładzanie (Garmażeria), Pomoc
$ola = $ctx(9, 2);
$hala->made(102, ['made' => 60], $ola);
$d = $hala->chillStart(['item_id' => 102, 'client_uuid' => '11111111-2222-4333-8444-555555555555'], $ola);
$hala->chillStart(['item_id' => 102, 'client_uuid' => '11111111-2222-4333-8444-555555555555'], $ola);
$check(count($d['chill']['rows']) === 1 && $d['chill']['rows'][0]['state'] === 'running' && $d['chill']['rows'][0]['left'] === 120 && !$d['chill']['complete']
    && $d['chill']['limits'] === ['target_c' => 10.0, 'max_minutes' => 120] && count($q('SELECT id FROM n_hala_chill')) === 1, 'chill: timer started once (client_uuid), running, limits');
$check($error(fn() => $hala->step('chill', $ola)) === 'HALA_STEP_INCOMPLETE', 'Dalej on chill refused before measuring');
$chillId = $d['chill']['rows'][0]['id'];
$d = $hala->chillMeasure($chillId, ['temp_c' => 14], $ola);
$call = $q("SELECT * FROM n_hala_help WHERE id_group = 2")[0] ?? null;
$check($d['chill']['rows'][0]['state'] === 'warm' && $d['chill']['bad'] === 1 && $d['chill']['complete'] && $call !== null && $call['reason'] === 'chill'
    && $call['note'] === 'Lasagne 14 °C, za ciepło' && $call['status'] === 'open' && $d['help']['id'] === (int) $call['id'], 'chill: 14 °C too warm → automatic call to the shift lead');
$check($error(fn() => $hala->chillMeasure($chillId, ['temp_c' => 14], $marek)) === 'NOT_FOUND', 'chill: other group cannot measure');
$st = fn(array $r) => HalaService::chillState($r + ['target_c' => 10, 'max_minutes' => 120], strtotime('2026-11-23 12:00:00'))['state'];
$check($st(['out_at' => '2026-11-23 11:00:00', 'temp_c' => null, 'measured_at' => null]) === 'running' && $st(['out_at' => '2026-11-23 09:00:00', 'temp_c' => null, 'measured_at' => null]) === 'late'
    && $st(['out_at' => '2026-11-23 10:00:00', 'temp_c' => 8, 'measured_at' => '2026-11-23 11:05:00']) === 'ok'
    && $st(['out_at' => '2026-11-23 08:00:00', 'temp_c' => 8, 'measured_at' => '2026-11-23 11:00:00']) === 'late', 'chill states: running, late (no measure), ok, late (measured after the limit)');
$h1 = $hala->help(['step' => 'make'], $marek);
$h2 = $hala->help(['step' => 'bags', 'reason' => 'bag', 'note' => 'Mąka żytnia — R-999'], $marek);
$check($h1['help']['status'] === 'open' && $h2['help']['id'] === $h1['help']['id'] && $h2['help']['reason'] === 'bag' && $h2['help']['note'] === 'Mąka żytnia — R-999'
    && $hala->helpState($marek)['help']['id'] === $h1['help']['id'] && $h1['poll_s'] === 15, 'Pomoc: one open call per group, a precise reason completes it');

// --- H : Zmiana
$check($error(fn() => $hala->shift($marek)) === 'HALA_NOT_SHIFT_LEAD', 'Zmiana: workers refused');
$ewa = $ctx(8, 1);
$s = $hala->shift($ewa);
$groups = array_column($s['groups'], null, 'id');
$check($s['person']['lead'] && count($s['calls']) === 2 && isset($groups[1], $groups[2]) && !isset($groups[3]) && $groups[1]['status'] === 'red' && $groups[2]['status'] === 'red'
    && $groups[2]['chill_bad'] === 1 && $groups[1]['closed_at'] !== null && !$s['can_validate'], 'Zmiana: groups with work today, calls and too warm = red, not ready');
$c1 = $h1['help']['id'];
$s = $hala->helpAction($c1, ['action' => 'take'], $ewa);
$taken = array_values(array_filter($s['calls'], fn($c) => $c['id'] === $c1))[0];
$check($taken['status'] === 'taken' && $taken['taken_by'] === 'Ewa Brygadzistka' && $hala->helpState($marek)['help']['status'] === 'taken', 'Idę: call taken, the group tablet sees it');
$hala->helpAction($c1, ['action' => 'close'], $ewa);
$check($error(fn() => $hala->helpAction($c1, ['action' => 'take'], $ewa)) === 'HALA_HELP_CLOSED' && $hala->helpState($marek)['help'] === null, 'Załatwione: closed, not reopened');
$check($error(fn() => $hala->validateShift($ewa)) === 'HALA_SHIFT_NOT_READY', 'Zatwierdź zmianę refused while a group is red');
$pdo->exec("DELETE FROM n_hala_chill");
$pdo->exec("UPDATE n_hala_help SET status = 'closed'");
$pdo->exec("UPDATE n_prod_week_slot SET quantity = 600 WHERE id_item = 100");
$pdo->exec("UPDATE n_prod_output SET quantity = 143 WHERE id_item = 101");
$pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, id_employee, note, summary) VALUES ('{$today}', 2, 9, NULL, '[]')");
$s = $hala->shift($ewa);
$check(!array_filter($s['groups'], fn($g) => $g['status'] !== 'green') && $s['can_validate'], 'Zmiana: all groups green and validated → ready');
$s = $hala->validateShift($ewa);
$check($s['validated'] !== null && $s['validated']['by'] === 'Ewa Brygadzistka' && !$s['can_validate'] && count($q('SELECT * FROM n_hala_shift')) === 1, 'Zatwierdź zmianę: who and when');

// --- HACCP miesiąca : schładzanie hors limites, sprzątanie manquant
$pdo->exec("INSERT INTO n_hala_chill (work_date, id_group, id_item, out_at, temp_c, measured_at, target_c, max_minutes) VALUES
    ('{$today}', 2, 102, NOW() - INTERVAL 3 HOUR, 15.5, NOW() - INTERVAL 2 HOUR, 10, 120), ('{$today}', 2, 102, NOW() - INTERVAL 1 HOUR, 6, NOW() - INTERVAL 30 MINUTE, 10, 120)");
$pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, id_employee, note, summary) VALUES ('{$yesterday}', 2, 9, NULL, '[]')");
$pdo->exec("INSERT INTO n_hala_clean_record (work_date, id_group, id_zone, id_employee, done_at) SELECT '{$yesterday}', 2, id, 9, NOW() FROM n_hala_clean_zone WHERE id_group = 2 AND active = 1 ORDER BY sort_order LIMIT 3");
$pdo->exec("UPDATE n_hala_clean_zone SET created_at = NOW() - INTERVAL 10 DAY");
/** @var HaccpMonthService $hm */
$hm = $container->get(HaccpMonthService::class);
$hd = $hm->data($today);
$items = array_column($hd['review'], null, 'key');
$chillItems = array_values(array_filter($hd['review'], fn($i) => $i['kind'] === 'hala_chill'));
$cleanItems = array_values(array_filter($hd['review'], fn($i) => $i['kind'] === 'hala_clean'));
$check(count($chillItems) === 1 && $chillItems[0]['title'] === 'Lasagne' && $chillItems[0]['signals'][0]['code'] === 'hala_chill_warm' && str_starts_with($chillItems[0]['signals'][0]['detail'], '15,5 °C po ')
    && $hd['sources']['hala_chill']['available'] && $hd['totals']['by_kind']['hala_chill'] === 1, 'HACCP miesiąca: chilling above the limit = item to explain (ok chilling ignored)');
$check(count($cleanItems) === 1 && $cleanItems[0]['key'] === 'hala_clean:' . $yesterday . ':2' && $cleanItems[0]['sub'] === '3 z 4 stref'
    && $cleanItems[0]['signals'][0]['detail'] === 'niesprzątnięte : Kosz (1 strefa)' && $hd['totals']['by_kind']['hala_clean'] === 1,
    'HACCP miesiąca: production day without full cleaning = item to explain (today not judged yet, removed zone ignored)');
$hm->saveDecision(['key' => $chillItems[0]['key'], 'decision' => 'corrective', 'note' => 'Lasagne : mniejsze blachy, schładzarka od razu'], 1, $today);
$check($q("SELECT kind FROM n_haccp_month_review")[0]['kind'] === 'hala_chill', 'HACCP miesiąca: decision stored with the new kind');
$hd = $hm->data($today);
$check(str_contains($hd['summary'], '1 schładzanie poza limitem') && str_contains($hd['summary'], '1 dzień bez pełnego sprzątania'), 'HACCP miesiąca: summary names the tablet signals');

// --- GRATIS : cartons gratuits par ligne (tablette d'emballage, « pakuj, nie skanuj »)
$pdo->exec("INSERT INTO customer_order (id, id_customer, status, is_freebie, expected_shipping_date) VALUES (1, 4, 1, 0, '{$today} 08:00:00'), (2, 4, 1, 1, '{$today} 08:00:00')");
$pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (1, 10, 8), (1, 12, 1), (2, 12, 1)");
$lines = array_column((new LogisticsBoardRepository($pdo))->orderLines($today), null, 'id_product');
$check((float) $lines[10]['cartons'] === 8.0 && (float) $lines[10]['free_cartons'] === 0.0 && (float) $lines[12]['cartons'] === 2.0 && (float) $lines[12]['free_cartons'] === 1.0,
    'GRATIS: freebie cartons counted in the order line and flagged (packing tablet)');

// --- API : routes documentées, droits des tablettes ; libellés PL / FR
$routes = array_values(array_filter(WorkshopApiRoutes::routes(), fn($r) => str_starts_with($r[1], '/kiosk/hala/')));
$spec = OpenApiSpec::build('https://erp.test');
$check(count($routes) === 14 && !array_filter($routes, fn($r) => !in_array($r[4], ['kiosk', 'kiosk_employee'], true)) && isset($spec['paths']['/kiosk/hala/day']['get'], $spec['paths']['/kiosk/hala/bags']['post']),
    'API: 14 tablet routes (device / person tokens), documented in OpenAPI');
$pl = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/ui/pl/kiosk.json'), true);
$fr = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/ui/fr/kiosk.json'), true);
preg_match_all("/t\\('(hala_[a-z_]+)'/", (string) file_get_contents(__DIR__ . '/../public/assets/js/workshop/hala.js'), $used);
$missing = array_values(array_filter(array_unique($used[1]), fn($k) => !str_ends_with($k, '_') && !isset($pl[$k], $fr[$k])));
$plKeys = array_values(array_filter(array_keys($pl), fn($k) => str_starts_with($k, 'hala_')));
$frKeys = array_values(array_filter(array_keys($fr), fn($k) => str_starts_with($k, 'hala_')));
foreach (['make', 'bags', 'chill', 'clean', 'pack', 'end'] as $s2) foreach (['hala_step_', 'hala_short_'] as $p) if (!isset($pl[$p . $s2], $fr[$p . $s2])) $missing[] = $p . $s2;
foreach (['burnt', 'quality', 'damaged'] as $s2) if (!isset($pl['hala_w_' . $s2], $fr['hala_w_' . $s2])) $missing[] = 'hala_w_' . $s2;
foreach (HalaService::HELP_REASONS as $s2) if (!isset($pl['hala_reason_' . $s2], $fr['hala_reason_' . $s2])) $missing[] = 'hala_reason_' . $s2;
$check($missing === [] && $plKeys === $frKeys && isset($pl['tab_day'], $fr['tab_day_short']), 'labels: every hala key of the tablet in PL and FR' . ($missing ? ' — ' . implode(', ', $missing) : ''));
// Aucun envoi ni appel extérieur depuis le code du tablet hali.
$code = '';
foreach (['App/Services/Workshop/Execution/HalaService.php', 'App/Repositories/Workshop/HalaRepository.php', 'App/Controllers/Api/Workshop/HalaController.php',
             'App/Controllers/Web/Production/HalaSettingsController.php', 'public/assets/js/workshop/hala.js'] as $f) $code .= file_get_contents(__DIR__ . '/../' . $f);
$check(!preg_match('/curl_init|\bmail\(|new \w*Mailer|Sms(Sender|Service|Gateway)|file_get_contents\(\s*[\'"]https?:|fetch\(\s*[\'"`]https?:/', $code), 'no e-mail, SMS or external call');

$pdo->exec("DROP DATABASE {$db}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
