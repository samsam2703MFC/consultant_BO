<?php
/**
 * [400] Asystent « Receptury i dane produktów » : mois du contrôle (celui de la tâche) ; produits actifs seulement —
 * sans recette, recette introuvable / sans składnik / sans coût, surowce sans prix, recette « na 1 sztukę » (règle de
 * RecipeUnitCheckRepository::candidates, décision « 1 karton » prise = plus signalé), masse (ProductWeightAuditEvaluator),
 * sztuk w kartonie et palette (ProductLogistics), przydatność, kategoria, dział ; produit complet et produit inactif non
 * listés ; « brak danych » sans table ; décisions (uzupełnione refusée tant qu'il reste un brak, przekazane et nie dotyczy
 * avec note), étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche du mois ; mois suivant : przekazane revient,
 * nie dotyczy reprise ; aucune écriture dans les tables d'origine (checksums) ; contrôleur construit par PHP-DI et appelé
 * (droit « recipes_month » 200, sans droit 403, liens et PDF filtrés par les droits) ; enregistrement (catalogue, droits,
 * routes, équipe, menu) ; migration 240 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_kp_recipes_month_test recréée (tables minimales + migration 240), supprimée à la fin (RM_JSON_OUT=fichier :
 * données de la page avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Product\RecipesMonthController;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\{RecipeUnitCheckRepository, RecipesMonthRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\RecipesMonthService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$db = 'erp_kp_recipes_month_test';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("DROP DATABASE IF EXISTS {$db}"); $pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); $pdo->exec("USE {$db}");
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), kind VARCHAR(20) DEFAULT 'erp', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_task_category (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20))',
    "CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_category INT NULL, title_pl VARCHAR(255), title_fr VARCHAR(255), frequency VARCHAR(20),
        minutes INT NULL, month_day VARCHAR(8) NULL, link_path VARCHAR(255) NULL, auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
    'CREATE TABLE n_position_menu (id_position INT, item VARCHAR(40), open_path VARCHAR(160) NULL, updated_by INT NULL, PRIMARY KEY (id_position, item))',
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2),
    (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (5, 'UPLOAD')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '1.2', NULL, 'Receptury i dane produktów', 'monthly'),
    (2, '1.6', NULL, 'Receptury i dane produktów', 'monthly'), (2, '1.3', NULL, 'Kartoteka surowców (kompletność)', 'monthly'), (2, '6.4', NULL, 'Stany magazynowe na koniec miesiąca', 'monthly'),
    (1, '9', NULL, 'Receptury i dane produktów', 'monthly'), (2, '1.1', 'launches_reviewed', NULL, 'monthly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 4)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (2, 'bdo'), (4, 'clients')");
$m240 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/240_recipes_month.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m240 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 240 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_map(fn($d) => [$d['wizard_code'], $d['proposed_wizard']], array_slice($duties, 0, 2)) === array_fill(0, 2, ['recipes_month_reviewed', null]),
    'migration 240: KIEROWNIK_PRODUKCJI 1.2, 1.6 → recipes_month_reviewed');
$check($duties[2]['proposed_wizard'] === 'Kartoteka surowców (kompletność)' && $duties[3]['proposed_wizard'] === 'Stany magazynowe na koniec miesiąca' && $duties[4]['wizard_code'] === null
    && $duties[4]['proposed_wizard'] === 'Receptury i dane produktów' && $duties[5]['wizard_code'] === 'launches_reviewed', 'migration: other duties and other positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'recipes_month_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'monthly' && $tasks[0]['month_day'] === 'wd5' && $tasks[0]['link_path'] === '/recipes/month'
    && (int) $tasks[0]['id_category'] === 1 && (int) $tasks[0]['sort_order'] === 5 && (int) $tasks[0]['active'] === 1, 'one monthly task for Kierownik produkcji (5th working day), category « Produkcja », after its tasks');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'bdo,products,product_cards,recipes_month,time', 4 => 'clients'], 'rights : recipes_month + products + product_cards for Kierownik produkcji only, other positions untouched');
$check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_recipes_month_review'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_recipes_month_review'")->fetchColumn() === 1, 'table created once, no foreign key');

$lw = new LightWizardRepository($pdo);
$make = fn() => new RecipesMonthService(new RecipesMonthRepository($pdo), new RecipeUnitCheckRepository($pdo), $lw);
$today = '2026-11-24';

// --- Sans table produit : « brak danych »
$e = $make()->data($today);
$check($e['review'] === [] && $e['can_finish'] && $e['source']['available'] === false && $e['source']['note'] === 'brak danych — brak tabeli produktów w bazie'
    && $e['month_start'] === '2026-11-01' && $e['month_label'] === 'listopad 2026', 'no product table → « brak danych » ; month of the task');

foreach ([
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), id_recipe INT NULL, id_category INT NULL, id_section INT NULL, ppc INT NULL, weight INT NULL, ppb INT NULL, bpc INT NULL,
        cpl INT NULL, lpp INT NULL, cpp INT NULL, shelf_life INT NULL, pre_calculated_raw_price_per_carton DECIMAL(12,4) NULL, active TINYINT DEFAULT 1, create_timestamp DATETIME NULL) ENGINE=InnoDB',
    'CREATE TABLE product_recipe (id INT PRIMARY KEY, name VARCHAR(100))',
    'CREATE TABLE product_recipe_connection (id INT AUTO_INCREMENT PRIMARY KEY, parent_recipe_id INT, child_recipe_id INT NULL, child_ingredient_id INT NULL, quantity DECIMAL(14,3))',
    'CREATE TABLE flattened_recipe_ingredient (id INT AUTO_INCREMENT PRIMARY KEY, recipe_id INT, material_id INT, quantity DECIMAL(12,4))',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), id_unit INT NULL, price_per_unit DECIMAL(12,6) NULL) ENGINE=InnoDB',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE production_sections (id INT PRIMARY KEY, name VARCHAR(80))',
    "CREATE TABLE n_recipe_unit_check (id INT AUTO_INCREMENT PRIMARY KEY, id_product INT, id_recipe INT, decision ENUM('scaled','kept'), factor INT NULL, cost_before DECIMAL(12,2), decided_by INT NULL)",
    'CREATE TABLE n_activity_view (id INT AUTO_INCREMENT PRIMARY KEY, path VARCHAR(160), started_at DATETIME, last_seen_at DATETIME)',
] as $sql) $pdo->exec($sql);
$e = $make()->data($today);
$check($e['source']['available'] === false && $e['source']['note'] === 'brak danych — brak aktywnych produktów' && $e['source']['unit_check'] === true, 'no active product → « brak danych — brak aktywnych produktów »');

// --- Données fictives : contrôle le mar. 24.11.2026 (listopad 2026).
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO category_product VALUES (1, 'Pieczywo'), (2, 'Ciasta')");
$pdo->exec("INSERT INTO production_sections VALUES (1, 'Piekarnia'), (2, 'Cukiernia')");
$pdo->exec("INSERT INTO raw_material VALUES (10, 'Mąka pszenna T55', 3, 0.0032), (11, 'Masło 82 %', 3, 0.031), (12, 'Ser gruyère', 3, NULL), (13, 'Jaja', 5, 0)");
$pdo->exec("INSERT INTO product_recipe VALUES (100, 'Bagietka'), (101, 'Tarta cytrynowa'), (102, 'Quiche'), (103, 'Chleb wiejski')");
$pdo->exec("INSERT INTO product_recipe_connection (parent_recipe_id, child_ingredient_id, quantity) VALUES (100, 10, 2000), (100, 11, 100), (102, 10, 1500), (102, 12, 500), (102, 13, 6), (103, 10, 400)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 10, 2000), (100, 11, 100), (102, 10, 1500), (102, 12, 500), (102, 13, 6), (103, 10, 400)");
$pdo->exec("INSERT INTO final_product (id, name, id_recipe, id_category, id_section, ppc, weight, ppb, bpc, cpl, lpp, cpp, shelf_life, pre_calculated_raw_price_per_carton, active, create_timestamp) VALUES
    (20, 'Bagietka', 100, 1, 1, 18, 250, 0, 0, 8, 5, 40, 3, 9.5, 1, '2025-01-10 08:00:00'),
    (21, 'Croissant maślany', NULL, 1, 1, 24, 1, 0, 0, 0, 5, 40, NULL, NULL, 1, '2026-11-10 08:00:00'),
    (22, 'Tarta cytrynowa', 101, 99, NULL, 8, 120, 0, 0, 10, 6, 60, 4, 0, 1, '2024-05-01 08:00:00'),
    (23, 'Quiche lotaryńska', 102, 2, 2, 12, 200, 0, 0, 8, 5, 50, 5, 14.2, 1, '2024-06-01 08:00:00'),
    (24, 'Chleb wiejski', 103, 1, 1, 10, 400, 0, 0, 6, 6, 36, 5, 1.28, 1, '2025-03-01 08:00:00'),
    (25, 'Brioche', 999, 2, 2, NULL, 0, 0, 0, 8, 5, 40, 6, 5.0, 1, '2025-04-01 08:00:00'),
    (26, 'Stary produkt', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, 0, NULL, NULL, 0, '2023-01-01 08:00:00')");

$erpTables = ['final_product', 'product_recipe', 'product_recipe_connection', 'flattened_recipe_ingredient', 'raw_material', 'category_product', 'production_sections', 'n_recipe_unit_check', 'user'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$svc = $make();
$d = $svc->data($today);
$boot = $d;
$keys = array_column($d['review'], 'key');
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => $i['codes'];
$check($keys === ['product:21', 'product:22', 'product:25', 'product:23', 'product:24'], 'flagged products : most braki first (complete product 20 and inactive product 26 not listed)');
$check($codes($rv['product:21']) === ['NO_RECIPE', 'WEIGHT_SUSPECT', 'PALLET_MISSING', 'SHELF_LIFE_MISSING'] && $rv['product:21']['signals'][2]['detail'] === 'kartony na warstwę'
    && array_column($rv['product:21']['links'], 'href') === ['/workshop/products#/product/21?step=4', '/audits/product-weights', '/workshop/products#/product/21?step=3', '/workshop/products#/product/21'],
    'no recipe, 1 g weight, pallet layer missing, no shelf life ; links to card (recipe, pallet), weights');
$check($codes($rv['product:22']) === ['RECIPE_EMPTY', 'RECIPE_NO_COST', 'NO_CATEGORY', 'NO_SECTION']
    && array_column($rv['product:22']['links'], 'href') === ['/recipes/101/edit', '/workshop/products#/product/22', '/workshop/products#/product/22?step=4'], 'empty recipe, cost 0, unknown category, no section');
$check($codes($rv['product:23']) === ['RECIPE_MATERIAL_NO_PRICE', 'PALLET_OVER'] && $rv['product:23']['signals'][0]['detail'] === 'Jaja, Ser gruyère' && $rv['product:23']['signals'][1]['detail'] === '50 > 40'
    && $rv['product:23']['links'][0]['href'] === '/materials/month', 'materials without price (names), more cartons than layers × cartons per layer');
$check($codes($rv['product:24']) === ['RECIPE_PER_PIECE'] && $rv['product:24']['signals'][0]['detail'] === 'masa składników 400 g wobec kartonu 4 000 g'
    && $rv['product:24']['links'][0]['href'] === '/products/new-with-recipe', 'recipe for one piece (rule « 1 karton »)');
$check($codes($rv['product:25']) === ['RECIPE_NOT_FOUND', 'WEIGHT_MISSING', 'PPC_MISSING'] && $rv['product:25']['signals'][1]['detail'] === 'Masa produktu wynosi 0 g'
    && !in_array('/recipes/999/edit', array_column($rv['product:25']['links'], 'href'), true), 'recipe not found, weight 0, no pieces per carton (no link to a missing recipe)');
$t = $d['totals'];
$check($t['review'] === 5 && $t['waiting'] === 5 && $t['products'] === ['total' => 6, 'no_recipe' => 1, 'recipe' => 4, 'data' => 4, 'incomplete' => 5] && $d['can_finish'] === false
    && $d['pdf']['href'] === '/ajax/recipes/products-without-recipe/pdf', 'totals ; PDF of products without recipe');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');
// Décision « 1 karton » déjà prise dans l'écran existant : plus signalé.
$pdo->exec("INSERT INTO n_recipe_unit_check (id_product, id_recipe, decision, cost_before) VALUES (24, 103, 'kept', 1.28)");
$check(!in_array('product:24', array_column($make()->data($today)['review'], 'key'), true), '« 1 karton » decision taken in the existing screen → no longer flagged');
$pdo->exec('DELETE FROM n_recipe_unit_check');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:21', 'decision' => 'x'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:21', 'decision' => 'checked'], 2, $today)), 'custom:422:Nadal brakuje : brak receptury'), '« uzupełnione » refused while braki remain');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:21', 'decision' => 'assigned', 'note' => ' '], 2, $today)), 'custom:422:Przekazane do uzupełnienia : wpisz'), '« przekazane » needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:22', 'decision' => 'not_applicable'], 2, $today)), 'custom:422:Nie dotyczy : wpisz'), '« nie dotyczy » needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:20', 'decision' => 'not_applicable', 'note' => 'abc'], 2, $today)), 'custom:422:Tego produktu nie ma na liście')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:20', 'decision' => 'checked'], 2, $today)), 'custom:422:Ten produkt nie miał braków')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:26', 'decision' => 'checked'], 2, $today)), 'custom:422:Tego produktu nie ma na liście'), 'complete or inactive product refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 5 produktów'), 'finish blocked while products wait');
$check($erp0 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_recipes_month_review')->fetchColumn() === 0, 'refused decisions write nothing');
$d = $svc->saveDecision(['key' => 'product:21', 'decision' => 'assigned', 'note' => 'Technolog : receptura i paleta do 5.12'], 2, $today);
$row = $pdo->query("SELECT * FROM n_recipes_month_review WHERE item_key = 'product:21'")->fetch();
$check($row['month_start'] === '2026-11-01' && (int) $row['source_id'] === 21 && $row['decision'] === 'assigned' && (int) $row['id_user'] === 2
    && $row['flags'] === 'NO_RECIPE,WEIGHT_SUSPECT,PALLET_MISSING,SHELF_LIFE_MISSING', '« przekazane » saved with the braki codes');
$x = array_column($d['review'], null, 'key')['product:21'];
$check(!$x['waiting'] && $x['decision']['by'] === 'Kasia P.' && $x['decision']['label'] === 'Przekazane do uzupełnienia', 'decision shown with its author');
$st = $tasks->state(RecipesMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 produkt z decyzją', 'task state : in progress');
$d = $svc->saveDecision(['key' => 'product:22', 'decision' => 'not_applicable', 'note' => 'Tarta tylko na zamówienie sklepu, bez receptury w ERP'], 2, $today);
// Données complétées dans l'ERP (comme dans la Karta produktu et la recette), puis « uzupełnione ».
$pdo->exec("UPDATE raw_material SET price_per_unit = 0.045 WHERE id = 12");
$pdo->exec("UPDATE raw_material SET price_per_unit = 0.9 WHERE id = 13");
$pdo->exec("UPDATE final_product SET cpp = 40 WHERE id = 23");
$erp1 = $sum();
$d = $svc->saveDecision(['key' => 'product:23', 'decision' => 'checked', 'seen' => ['RECIPE_MATERIAL_NO_PRICE', 'PALLET_OVER', 'NIEZNANY']], 2, $today);
$check($pdo->query("SELECT flags FROM n_recipes_month_review WHERE item_key = 'product:23'")->fetchColumn() === 'RECIPE_MATERIAL_NO_PRICE,PALLET_OVER', 'completed in the ERP : « uzupełnione » saved with the braki seen on screen (unknown codes dropped)');
$d = $svc->saveDecision(['key' => 'product:24', 'decision' => 'assigned', 'note' => 'Sprawdzić recepturę z piekarzem'], 2, $today);
$d = $svc->saveDecision(['key' => 'product:25', 'decision' => 'not_applicable', 'note' => 'Brioche wycofywana w grudniu'], 2, $today);
$rv = array_column($d['review'], null, 'key');
$check($rv['product:23']['codes'] === [] && $rv['product:23']['decision']['code'] === 'checked' && $d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['checked'] === 1
    && $d['totals']['assigned'] === 2 && $d['totals']['not_applicable'] === 2, 'all decided → step 3 open ; completed product stays listed with its decision');
$check($erp1 === $sum(), 'decisions write nothing in the source tables');
$r = $svc->finish(2, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'recipes_month_reviewed'")->fetchAll();
$check($r['summary'] === 'listopad 2026 : produkty 4 z brakami / 6 · bez receptury 1 · receptury do poprawy 3 · dane 3 · decyzje : 1 sprawdzone · 2 przekazane · 2 nie dotyczy'
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null, 'finish : summary, n_wizard_done');
$st = $tasks->state(RecipesMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === true && $st['summary'] === $r['summary'] && $tasks->state(RecipesMonthService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');
$check($erp1 === $sum(), 'finish writes nothing in the source tables');

// Mois suivant (mer. 2.12) : przekazane revient (braki toujours là), nie dotyczy reprise, produit complété absent.
$n = $make()->data('2026-12-02');
$nx = array_column($n['review'], null, 'key');
$check($n['month_start'] === '2026-12-01' && $nx['product:21']['waiting'] && $nx['product:21']['previous']['code'] === 'assigned' && !$nx['product:22']['waiting']
    && $nx['product:22']['earlier']['code'] === 'not_applicable' && !isset($nx['product:23']) && $n['totals']['waiting'] === 2 && $n['done'] === null,
    'next month : « przekazane » comes back, « nie dotyczy » carried, completed product gone');
$pdo->exec("UPDATE final_product SET id_section = 2 WHERE id = 22");
$check(array_column($make()->data('2026-12-02')['review'], null, 'key')['product:22']['waiting'] === false, 'fewer braki than at « nie dotyczy » : still carried');
$pdo->exec("UPDATE final_product SET shelf_life = 0 WHERE id = 22");
$check(array_column($make()->data('2026-12-02')['review'], null, 'key')['product:22']['waiting'] === true, 'new brak after « nie dotyczy » : waiting again');

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=' . $db, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(RecipesMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'recipes_month')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
$hrefs = fn(array $body) => array_values(array_unique(array_merge(...array_map(fn($i) => array_map(fn($l) => strtok($l['href'], '#?'), $i['links']), $body['data']['review'] ?: [['links' => []]]))));
$check($c2 === 200 && $b2['data']['month_start'] === RecipesMonthService::reviewMonth(date('Y-m-d')) && in_array('/workshop/products', $hrefs($b2), true) && in_array('/audits/product-weights', $hrefs($b2), true)
    && !in_array('/materials/month', $hrefs($b2), true) && $b2['data']['pdf'] !== null, 'controller data() (Kierownik produkcji : recipes_month + products + product_cards + bdo) : 200, card and weights links, no link to a wizard it has no right for');
[$c3, $b3] = $call(3, 'data');
$check($c3 === 200 && $hrefs($b3) === [] && $b3['data']['pdf'] !== null, 'controller data() (position with the wizard right only) : 200, no link to screens it cannot open (PDF : common route)');
[$c1, $b1] = $call(1, 'data');
$check($c1 === 200 && in_array('/workshop/products', $hrefs($b1), true), 'controller data() (administrator) : 200 with every link');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do asystenta « Receptury i dane produktów ».' && $cD === 403 && $cF === 403, 'controller : Piekarz without « recipes_month » → 403 for data(), decision() and finish()');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() really called : done or blocked by waiting products');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/RecipesMonthService.php', 'App/Repositories/Workshop/RecipesMonthRepository.php',
    'App/Controllers/Web/Product/RecipesMonthController.php', 'public/assets/js/recipes_month/recipes-month.js']));
$check(!preg_match('/UPDATE\s+(raw_material|final_product|product_recipe)|INSERT\s+INTO\s+(raw_material|final_product|product_recipe)|DELETE\s+FROM|PHPMailer|Mail::|Sms|curl_|https?:\/\/|PATCH/i', $src),
    'no write to recipes / products, no mail/SMS sending, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('recipes_month_reviewed')['link'] === '/recipes/month' && TaskWizards::byLink('/recipes/month') === 'recipes_month_reviewed'
    && isset(TaskWizards::AUTO['recipes_month_reviewed']) && PositionTaskService::LINK_AUTO['/recipes/month'] === 'recipes_month_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['recipes_month_reviewed'][0] === 'Receptury i dane produktów (przegląd miesiąca)' && TaskWizards::CATALOG['recipes_month_reviewed'][0] !== 'Receptury i dane produktów',
    'catalog label distinct from the proposed name in the matrix (237)');
$check(PageAccess::keyFor('/recipes/month') === 'recipes_month' && PageAccess::keyFor('/ajax/recipes/month/decision') === 'recipes_month' && PageAccess::keyFor('/recipes/12/edit') === 'products'
    && PageAccess::keyFor('/workshop/products') === 'product_cards' && in_array('recipes_month', PageAccess::grantable(), true), 'own right « recipes_month » (page and ajax) ; recipe and card screens unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'recipes_month_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/recipes/month'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Product/RecipesMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/recipes/month') === 'index' && $found('GET', '/ajax/recipes/month') === 'data' && $found('POST', '/ajax/recipes/month/decision') === 'decision'
    && $found('POST', '/ajax/recipes/month/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/recipes/month')[1]['controller'] === RecipesMonthController::class && $allR->dispatch('GET', '/recipes/12/edit')[0] === FastRoute\Dispatcher::FOUND
    && $allR->dispatch('GET', '/ajax/recipes/products-without-recipe/pdf')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob ; existing recipe routes unchanged');

if (getenv('RM_JSON_OUT')) file_put_contents((string) getenv('RM_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec("DROP DATABASE {$db}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
