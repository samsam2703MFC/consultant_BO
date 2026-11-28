<?php
/**
 * [401] Asystent « Kartoteka surowców » : mois du contrôle (celui de la tâche) ; surowce des recettes actives — sans
 * kategoria, jednostka, cena, wielkość opakowania, dostawca (ou dostawca nieaktywny), wartości odżywcze (surowce
 * spożywcze seulement : opakowanie jamais signalé) ; surowiec inutilisé compté sans signal ; nowe surowce (première cena
 * depuis le mois écoulé avant le premier « Zakończ », puis id au-delà du repère) ; alergeny comptés, jamais signalés ;
 * « brak danych » sans table ; décisions (uzupełnione refusée tant qu'il reste un brak, zapytanie et nie dotyczy avec
 * note), étape 3 bloquée, « Zakończ » → n_wizard_done + repère, état de la tâche du mois ; mois suivant : zapytanie
 * revient, nie dotyczy reprise, seuls les surowce créés après le repère sont nouveaux ; aucune écriture dans les tables
 * d'origine (checksums) ; contrôleur construit par PHP-DI et appelé (droit « materials_month » 200, sans droit 403, liens
 * filtrés par les droits) ; enregistrement (catalogue, droits, routes, équipe, menu) ; migration 241 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_kp_materials_month_test recréée (tables minimales + migration 241), supprimée à la fin (MM_JSON_OUT=fichier :
 * données de la page avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Material\MaterialsMonthController;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\MaterialsMonthRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\MaterialsMonthService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$db = 'erp_kp_materials_month_test';
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
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2),
    (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (5, 'UPLOAD')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '1.3', NULL, 'Kartoteka surowców (kompletność)', 'monthly'),
    (2, '1.5', NULL, 'Kartoteka surowców (kompletność)', 'monthly'), (2, '1.2', NULL, 'Receptury i dane produktów', 'monthly'), (1, '9', NULL, 'Kartoteka surowców (kompletność)', 'monthly'),
    (2, '1.4', 'inv_checked', NULL, 'weekly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 4)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (2, 'purchasing'), (4, 'clients')");
$m241 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/241_materials_month.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m241 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 241 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_map(fn($d) => [$d['wizard_code'], $d['proposed_wizard']], array_slice($duties, 0, 2)) === array_fill(0, 2, ['materials_month_reviewed', null]),
    'migration 241: KIEROWNIK_PRODUKCJI 1.3, 1.5 → materials_month_reviewed');
$check($duties[2]['proposed_wizard'] === 'Receptury i dane produktów' && $duties[3]['wizard_code'] === null && $duties[3]['proposed_wizard'] === 'Kartoteka surowców (kompletność)'
    && $duties[4]['wizard_code'] === 'inv_checked', 'migration: other duties and other positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'materials_month_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'monthly' && $tasks[0]['month_day'] === 'wd5' && $tasks[0]['link_path'] === '/materials/month'
    && (int) $tasks[0]['id_category'] === 1 && (int) $tasks[0]['sort_order'] === 5 && (int) $tasks[0]['active'] === 1, 'one monthly task for Kierownik produkcji (5th working day), after its tasks');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'materials,materials_month,purchasing,supplier_prices,time', 4 => 'clients'], 'rights : materials_month + materials + supplier_prices for Kierownik produkcji only, other positions untouched');
$check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_materials_month_%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_materials_month_review', 'n_materials_month_mark')")->fetchColumn() === 2, 'tables created once, no foreign key');

$lw = new LightWizardRepository($pdo);
$make = fn() => new MaterialsMonthService(new MaterialsMonthRepository($pdo), $lw);
$today = '2026-11-24';

$e = $make()->data($today);
$check($e['review'] === [] && $e['can_finish'] && $e['source']['available'] === false && $e['source']['note'] === 'brak danych — brak tabeli surowców w bazie' && $e['new_rule'] === null
    && $e['month_start'] === '2026-11-01', 'no material table → « brak danych », no rule for new materials');

foreach ([
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE category_material (id INT PRIMARY KEY, name VARCHAR(80), id_group INT NULL)',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80), email VARCHAR(120) NULL, is_active TINYINT DEFAULT 1)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), id_unit INT NULL, id_category INT NULL, id_current_supplier INT NULL, price_per_unit DECIMAL(12,6) NULL,
        package_size_in_base_unit DECIMAL(12,3) NULL, is_part_of_package TINYINT NOT NULL DEFAULT 0, energy_100g DECIMAL(8,2) NULL, fat_100g DECIMAL(8,2) NULL, saturated_fat_100g DECIMAL(8,2) NULL,
        carbohydrates_100g DECIMAL(8,2) NULL, sugars_100g DECIMAL(8,2) NULL, salt_100g DECIMAL(8,2) NULL, fiber_100g DECIMAL(8,2) NULL, proteins_100g DECIMAL(8,2) NULL) ENGINE=InnoDB',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), id_recipe INT NULL, active TINYINT DEFAULT 1)',
    'CREATE TABLE flattened_recipe_ingredient (id INT AUTO_INCREMENT PRIMARY KEY, recipe_id INT, material_id INT, quantity DECIMAL(12,4))',
    'CREATE TABLE allergen (id INT PRIMARY KEY, code VARCHAR(20))',
    'CREATE TABLE material_allergen_connection (material_id INT, allergen_id INT)',
    'CREATE TABLE raw_material_price_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_raw_material INT, pack_price DECIMAL(12,4), package_size_in_base_unit DECIMAL(12,4), timestamp DATETIME, id_supplier INT NULL)',
] as $sql) $pdo->exec($sql);
$e = $make()->data($today);
$check($e['source']['note'] === 'brak danych — brak surowców' && $e['new_rule'] === 'first_price' && $e['new_since'] === '2026-10-01', 'no material → « brak danych — brak surowców » ; before the first review : first price since 1.10');

// --- Données fictives : contrôle le mar. 24.11.2026 (listopad 2026).
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO unit VALUES (3, 'g'), (4, 'ml'), (5, 'szt')");
$pdo->exec("INSERT INTO category_material VALUES (1, 'Opakowania', 1), (2, 'Mąki i suche', 2), (3, 'Nabiał', 2)");
$pdo->exec("INSERT INTO supplier VALUES (1, 'Młyn Sp. z o.o.', 'biuro@mlyn.example', 1), (2, 'Mleczarnia Stara', NULL, 0)");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit, id_category, id_current_supplier, price_per_unit, package_size_in_base_unit, is_part_of_package, energy_100g, fat_100g, saturated_fat_100g,
        carbohydrates_100g, sugars_100g, salt_100g, fiber_100g, proteins_100g) VALUES
    (10, 'Mąka pszenna T55', 3, 2, 1, 0.0032, 25000, 0, 364, 1, 0.2, 76, 0.3, 0, 2.7, 10),
    (11, 'Masło 82 %', 3, 3, 2, 0.031, 10000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
    (12, 'Ser gruyère', 3, NULL, NULL, NULL, NULL, 0, 413, 32, 19, 0.4, 0.4, 1.7, 0, 30),
    (13, 'Karton 30x20x10', 5, 1, 1, 1.2, 100, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
    (14, 'Woda', 4, 2, 1, 0.0001, 1000, 0, 0, 0, 0, 0, 0, 0, 0, 0),
    (15, 'Pistacje łuskane', 99, 2, 1, 0.09, 1000, 0, 560, 45, 6, 28, 8, 0, 10, 20),
    (16, 'Cukier stary', 3, 2, 1, NULL, 1000, 0, 400, 0, 0, 100, 100, 0, 0, 0),
    (17, 'Sól morska', 3, 2, 1, 0.002, 25000, 0, 0, 0, 0, 0, 0, 99, 0, 0)");
$pdo->exec("INSERT INTO final_product VALUES (20, 'Quiche', 100, 1), (21, 'Stary produkt', 200, 0)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 10, 1500), (100, 11, 300), (100, 12, 500), (100, 13, 1), (100, 14, 600), (100, 17, 20), (200, 16, 100)");
$pdo->exec("INSERT INTO allergen VALUES (1, 'GLUTEN'), (2, 'MLEKO')");
$pdo->exec("INSERT INTO material_allergen_connection VALUES (10, 1), (11, 2), (12, 2)");
$pdo->exec("INSERT INTO raw_material_price_changelog (id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES (10, 80, 25000, '2024-01-10 10:00:00'),
    (11, 310, 10000, '2024-02-01 10:00:00'), (13, 120, 100, '2025-03-01 10:00:00'), (14, 0.1, 1000, '2024-01-01 10:00:00'), (15, 90, 1000, '2026-10-15 09:00:00'),
    (16, 4, 1000, '2023-01-01 10:00:00'), (17, 50, 25000, '2024-01-01 10:00:00')");

$erpTables = ['raw_material', 'unit', 'category_material', 'supplier', 'final_product', 'flattened_recipe_ingredient', 'material_allergen_connection', 'raw_material_price_changelog', 'user'];
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
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check(array_column($d['review'], 'key') === ['material:12', 'material:11', 'material:15', 'material:14'],
    'flagged materials : most braki first (complete flour, salt and packaging not listed, unused sugar not listed)');
$check($codes($rv['material:12']) === ['NO_CATEGORY', 'NO_PRICE', 'NO_PACKAGE_SIZE', 'NO_SUPPLIER'] && array_column($rv['material:12']['links'], 'href') === ['/materials/12/edit', '/materials/prices'],
    'no category, price, package size, supplier ; links to the material card and prices');
$check($codes($rv['material:11']) === ['SUPPLIER_INACTIVE', 'NO_NUTRITION'] && array_column($rv['material:11']['links'], 'href') === ['/material-suppliers', '/materials/nutritional-values']
    && str_contains($rv['material:11']['sub'], '31,00 zł / kg'), 'inactive supplier, no nutrition values ; price shown per kg');
$check($codes($rv['material:15']) === ['NO_UNIT', 'NEW'] && $rv['material:15']['codes'] === ['NO_UNIT'] && $rv['material:15']['signals'][1]['detail'] === 'pierwsza cena czw. 15.10'
    && $rv['material:15']['used'] === false, 'new material (first price in October), unknown unit, not used yet');
$check($codes($rv['material:14']) === ['NO_NUTRITION'] && !isset($rv['material:13']) && !isset($rv['material:16']) && !isset($rv['material:17']), 'water : all nutrition values 0 ; packaging never asked for nutrition');
$t = $d['totals'];
$check($t['review'] === 4 && $t['waiting'] === 4 && $t['materials'] === ['total' => 8, 'used' => 6, 'incomplete' => 3, 'unused_incomplete' => 2, 'new' => 1, 'no_allergen' => 2]
    && $d['can_finish'] === false && $t['by_code']['NO_PRICE'] === 1, 'totals (allergens counted, not flagged)');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:12', 'decision' => 'x'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:12', 'decision' => 'checked'], 2, $today)), 'custom:422:Nadal brakuje : brak kategorii surowca'), '« uzupełnione » refused while braki remain');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:12', 'decision' => 'supplier_asked', 'note' => ''], 2, $today)), 'custom:422:Zapytanie do dostawcy : wpisz'), 'supplier request needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:14', 'decision' => 'not_applicable', 'note' => 'a'], 2, $today)), 'custom:422:Nie dotyczy : wpisz'), '« nie dotyczy » needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:10', 'decision' => 'not_applicable', 'note' => 'abc'], 2, $today)), 'custom:422:Tego surowca nie ma na liście')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:16', 'decision' => 'checked'], 2, $today)), 'custom:422:Tego surowca nie ma na liście'), 'complete or unused material refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 4 surowców'), 'finish blocked while materials wait');
$check($erp0 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_materials_month_review')->fetchColumn() === 0, 'refused decisions write nothing');
$d = $svc->saveDecision(['key' => 'material:12', 'decision' => 'supplier_asked', 'note' => 'Serownia : cena i opakowanie, e-mail 24.11'], 2, $today);
$row = $pdo->query("SELECT * FROM n_materials_month_review WHERE item_key = 'material:12'")->fetch();
$check($row['month_start'] === '2026-11-01' && (int) $row['source_id'] === 12 && $row['decision'] === 'supplier_asked' && $row['flags'] === 'NO_CATEGORY,NO_PRICE,NO_PACKAGE_SIZE,NO_SUPPLIER'
    && (int) $row['id_user'] === 2, 'supplier request saved with the braki codes');
$st = $tasks->state(MaterialsMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 surowiec z decyzją', 'task state : in progress');
$d = $svc->saveDecision(['key' => 'material:14', 'decision' => 'not_applicable', 'note' => 'Woda : bez wartości odżywczych'], 2, $today);
// Fiches complétées dans l'ERP (Karta surowca, Wartości odżywcze), puis « uzupełnione ».
$pdo->exec("UPDATE raw_material SET id_current_supplier = 1, energy_100g = 717, fat_100g = 82 WHERE id = 11");
$pdo->exec("UPDATE raw_material SET id_unit = 3 WHERE id = 15");
$erp1 = $sum();
$d = $svc->saveDecision(['key' => 'material:11', 'decision' => 'checked', 'seen' => ['SUPPLIER_INACTIVE', 'NO_NUTRITION', 'NIEZNANY']], 2, $today);
$check($pdo->query("SELECT flags FROM n_materials_month_review WHERE item_key = 'material:11'")->fetchColumn() === 'SUPPLIER_INACTIVE,NO_NUTRITION', 'completed in the ERP : « uzupełnione » saved with the braki seen on screen');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:15', 'decision' => 'not_applicable', 'note' => 'abc'], 2, $today)), 'custom:422:Ten surowiec nie ma braków'), 'new material without braki : only « sprawdzone »');
$d = $svc->saveDecision(['key' => 'material:15', 'decision' => 'checked', 'note' => 'Karta sprawdzona'], 2, $today);
$check($pdo->query("SELECT flags FROM n_materials_month_review WHERE item_key = 'material:15'")->fetchColumn() === 'NEW', 'new material checked (flag NEW)');
$check($d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['checked'] === 2 && $d['totals']['supplier_asked'] === 1 && $d['totals']['not_applicable'] === 1, 'all decided → step 3 open');
$check($erp1 === $sum(), 'decisions write nothing in the source tables');
$r = $svc->finish(2, $today);
$check($r['summary'] === 'listopad 2026 : surowce 2 z brakami / 6 używanych · 1 nowy · decyzje : 2 sprawdzone · 1 zapytanie do dostawcy · 1 nie dotyczy'
    && (int) $pdo->query("SELECT max_material_id FROM n_materials_month_mark WHERE month_start = '2026-11-01'")->fetchColumn() === 17 && $r['data']['done'] !== null, 'finish : summary, n_wizard_done, mark of known materials');
$st = $tasks->state(MaterialsMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state : done this month');
$check($erp1 === $sum(), 'finish writes nothing in the source tables');

// Mois suivant (mer. 2.12) : zapytanie revient, nie dotyczy reprise, nowe = après le repère.
$pdo->exec("INSERT INTO raw_material (id, name, id_unit, id_category, id_current_supplier, price_per_unit, package_size_in_base_unit, is_part_of_package, energy_100g)
    VALUES (18, 'Mąka orkiszowa', 3, 2, 1, 0.006, 25000, 0, 340)");
$n = $make()->data('2026-12-02');
$nx = array_column($n['review'], null, 'key');
$check($n['new_rule'] === 'mark' && array_keys($nx) === ['material:12', 'material:18', 'material:14'] && $nx['material:12']['waiting'] && $nx['material:12']['previous']['code'] === 'supplier_asked'
    && !$nx['material:14']['waiting'] && $nx['material:14']['earlier']['code'] === 'not_applicable' && $codes($nx['material:18']) === ['NEW'] && $nx['material:18']['waiting']
    && $n['totals']['waiting'] === 2 && !isset($nx['material:15']), 'next month : supplier request comes back, « nie dotyczy » carried, only materials created after the mark are new');

// --- Contrôleur : construit par PHP-DI, data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=' . $db, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(MaterialsMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'materials_month')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
$hrefs = fn(array $body) => array_values(array_unique(array_merge([], ...array_map(fn($i) => array_column($i['links'], 'href'), $body['data']['review']))));
[$c2, $b2] = $call(2, 'data');
$check($c2 === 200 && $b2['data']['month_start'] === MaterialsMonthService::reviewMonth(date('Y-m-d')) && in_array('/materials/12/edit', $hrefs($b2), true) && in_array('/materials/prices', $hrefs($b2), true)
    && count($b2['data']['links']) === 3, 'controller data() (Kierownik produkcji : materials_month + materials + supplier_prices) : 200, card and prices links');
[$c3, $b3] = $call(3, 'data');
$check($c3 === 200 && $hrefs($b3) === [] && $b3['data']['links'] === [], 'controller data() (position with the wizard right only) : 200, no link to screens it cannot open');
[$c1, $b1] = $call(1, 'data');
$check($c1 === 200 && in_array('/materials/prices', $hrefs($b1), true), 'controller data() (administrator) : 200 with every link');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do asystenta « Kartoteka surowców ».' && $cD === 403 && $cF === 403, 'controller : Piekarz without « materials_month » → 403 for data(), decision() and finish()');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() really called : done or blocked by waiting materials');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/MaterialsMonthService.php', 'App/Repositories/Workshop/MaterialsMonthRepository.php',
    'App/Controllers/Web/Material/MaterialsMonthController.php', 'public/assets/js/materials_month/materials-month.js']));
$check(!preg_match('/UPDATE\s+(raw_material|supplier|material_)|INSERT\s+INTO\s+(raw_material|supplier|material_)|DELETE\s+FROM|PHPMailer|Mail::|Sms|curl_|https?:\/\/|PATCH|mailto/i', $src),
    'no write to materials / suppliers, no mail/SMS sending, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('materials_month_reviewed')['link'] === '/materials/month' && TaskWizards::byLink('/materials/month') === 'materials_month_reviewed'
    && isset(TaskWizards::AUTO['materials_month_reviewed']) && PositionTaskService::LINK_AUTO['/materials/month'] === 'materials_month_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['materials_month_reviewed'][0] === 'Kartoteka surowców (przegląd miesiąca)' && TaskWizards::CATALOG['materials_month_reviewed'][0] !== 'Kartoteka surowców (kompletność)',
    'catalog label distinct from the proposed name in the matrix (237)');
$check(PageAccess::keyFor('/materials/month') === 'materials_month' && PageAccess::keyFor('/ajax/materials/month/finish') === 'materials_month' && PageAccess::keyFor('/materials/12/edit') === 'materials'
    && PageAccess::keyFor('/materials/prices') === 'supplier_prices' && in_array('materials_month', PageAccess::grantable(), true), 'own right « materials_month » (page and ajax) ; material screens unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'materials_month_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/materials/month'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Material/MaterialsMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/materials/month') === 'index' && $found('GET', '/ajax/materials/month') === 'data' && $found('POST', '/ajax/materials/month/decision') === 'decision'
    && $found('POST', '/ajax/materials/month/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/materials/month')[1]['controller'] === MaterialsMonthController::class && $allR->dispatch('GET', '/materials/12/edit')[0] === FastRoute\Dispatcher::FOUND
    && $allR->dispatch('GET', '/materials/prices')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob ; existing material routes unchanged');

if (getenv('MM_JSON_OUT')) file_put_contents((string) getenv('MM_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec("DROP DATABASE {$db}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
