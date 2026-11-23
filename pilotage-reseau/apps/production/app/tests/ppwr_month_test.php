<?php
/**
 * [397] Asystent « PPWR i opakowania » : mois du contrôle (celui de la tâche) ; signaux — opakowanie utilisé dans un produit
 * actif avec braki du Rejestr PPWR (règle de RawMaterialPpwrService::issues) ou des données BDO, opakowanie inutilisé
 * compté sans signal, profil modifié depuis le dernier contrôle (« zmiana »), produit actif sans karton sprzedażowy / PPC /
 * receptura / masse correcte (règles de FinalProductPpwrSalesPackagingService::issueCodes et ProductWeightAuditEvaluator),
 * produit nouveau, wysyłka du mois écoulé sans dowód opakowań, « wymaga danych » ou lignes PPWR incomplètes ; « brak danych »
 * pour une source sans table ; décisions (uzupełnione refusée tant qu'il reste un brak, zapytanie do dostawcy réservé aux
 * opakowania avec note, nie dotyczy avec note), étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche du mois ;
 * mois suivant : zapytanie revient, nie dotyczy reprise, zmiany depuis le « Zakończ » ; aucune écriture dans les tables
 * d'origine (checksums) ; contrôleur construit par PHP-DI et appelé (droit « ppwr_month » 200, sans droit 403, liens
 * filtrés par les droits) ; enregistrement (catalogue, droits, routes, équipe, menu) ; migration 238 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_ppwr_month_test recréée (tables PPWR / BDO des migrations 040 → 052 telles qu'elles sont écrites + tables
 * minimales + migration 238), supprimée à la fin (PM_JSON_OUT=fichier : données de la page avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Audit\PpwrMonthController;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\PpwrMonthRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\PpwrMonthService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_ppwr_month_test'); $pdo->exec('CREATE DATABASE erp_ppwr_month_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_ppwr_month_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL)',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80), email VARCHAR(120) NULL, nip VARCHAR(20) NULL)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), id_unit INT NULL, id_current_supplier INT NULL, is_part_of_package TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), id_recipe INT NULL, ppc INT NULL, weight INT NULL, active TINYINT DEFAULT 1, create_timestamp DATETIME NULL) ENGINE=InnoDB',
    'CREATE TABLE flattened_recipe_ingredient (id INT AUTO_INCREMENT PRIMARY KEY, recipe_id INT, material_id INT, quantity DECIMAL(12,4))',
    'CREATE TABLE shipment_log (id INT AUTO_INCREMENT PRIMARY KEY, shipment_date DATE, id_customer INT, id_wz INT NULL, id_cmr INT NULL, id_wdt INT NULL) ENGINE=InnoDB',
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
// Tables PPWR / BDO exactement comme leurs migrations les créent ou les modifient (040 → 052, sans les données de départ 041 / 044 / 045).
foreach (['040_shipment_packaging_bdo', '042_shipment_packaging_evidence_basis', '043_raw_material_ppwr_profiles', '046_remove_ppwr_format', '047_final_product_ppwr_sales_packaging',
    '048_remove_ppwr_product_packaging_count', '049_add_ppwr_material_identification', '050_shipment_packaging_ppwr_snapshot', '051_shipment_ppwr_evidence_basis', '052_separate_ppwr_shipment_calculation'] as $f) {
    // query + closeCursor : les « EXECUTE » des migrations conditionnelles (SELECT 1 quand rien à faire) rendent un résultat.
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/' . $f . '.sql')) as $sql) $pdo->query($sql)->closeCursor();
}
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2),
    (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (5, 'UPLOAD')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '2.1', NULL, 'PPWR i opakowania (kompletność)', 'monthly'),
    (2, '2.7', NULL, 'PPWR i opakowania (kompletność)', 'monthly'), (2, '2.10', NULL, 'PPWR i opakowania (kompletność)', 'monthly'), (2, '2.8', NULL, 'Teczka do audytu i kontroli', 'quarterly'),
    (2, '6.5', NULL, 'Intrastat miesiąca', 'monthly'), (1, '9', NULL, 'PPWR i opakowania (kompletność)', 'monthly'), (2, '3.5', 'complaints_reviewed', NULL, 'weekly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 4)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (4, 'clients')");
$pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (2, 'time')");
$m238 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/238_ppwr_month.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m238 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 238 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_map(fn($d) => [$d['wizard_code'], $d['proposed_wizard']], array_slice($duties, 0, 3)) === array_fill(0, 3, ['ppwr_month_reviewed', null]),
    'migration 238: KIEROWNIK_PRODUKCJI 2.1, 2.7, 2.10 → ppwr_month_reviewed');
$check($duties[3]['proposed_wizard'] === 'Teczka do audytu i kontroli' && $duties[4]['proposed_wizard'] === 'Intrastat miesiąca' && $duties[5]['proposed_wizard'] === 'PPWR i opakowania (kompletność)'
    && $duties[5]['wizard_code'] === null && $duties[6]['wizard_code'] === 'complaints_reviewed', 'migration: other duties and other positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'ppwr_month_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'monthly' && $tasks[0]['month_day'] === 'wd5' && $tasks[0]['link_path'] === '/ppwr/month'
    && (int) $tasks[0]['id_category'] === 5 && (int) $tasks[0]['sort_order'] === 5 && (int) $tasks[0]['active'] === 1, 'one monthly task for Kierownik produkcji (5th working day), category « Dokumenty », after its tasks');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'bdo,ppwr,ppwr_month,time', 4 => 'clients'], 'rights : ppwr_month + ppwr + bdo for Kierownik produkcji only, other positions untouched');
$menu = $pdo->query('SELECT item, open_path FROM n_position_menu WHERE id_position = 2 ORDER BY item')->fetchAll(PDO::FETCH_KEY_PAIR);
$check($menu === ['packaging' => '/ppwr/register', 'time' => null], 'own menu : « Opakowania » added (opens Rejestr PPWR)');
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_ppwr_month_review'")->fetchColumn();
$check($fks === 0 && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_ppwr_month_review'")->fetchColumn() === 1, 'table created once, no foreign key');
// Poste sans menu propre : pas de menu ajouté.
$pdo->exec('DELETE FROM n_position_menu'); foreach ($m238 as $sql) $pdo->exec($sql);
$check((int) $pdo->query('SELECT COUNT(*) FROM n_position_menu')->fetchColumn() === 0, 'position without its own menu : no menu row added');

$lw = new LightWizardRepository($pdo);
$make = fn() => new PpwrMonthService(new PpwrMonthRepository($pdo), $lw);
$svc = $make();
$today = '2026-11-24';

// --- Sources vides : « brak danych »
$e = $svc->data($today);
$check($e['review'] === [] && $e['can_finish'] && array_map(fn($s) => $s['available'], $e['sources']) === ['materials' => false, 'products' => false, 'shipments' => false]
    && $e['sources']['materials']['note'] === 'brak danych — żaden surowiec nie jest oznaczony jako element opakowania' && $e['month_start'] === '2026-11-01' && $e['shipments_from'] === '2026-10-01'
    && $e['shipments_to'] === '2026-10-31' && $e['since'] === '2026-10-01 00:00:00', 'empty sources → « brak danych » ; month of the task, shipments of the previous month, changes since 1.10');
$pdo->exec('RENAME TABLE final_product_ppwr_sales_packaging TO fpsp_x');
$x = $make()->data($today);
$check($x['sources']['products']['rows'] === null && $x['sources']['products']['note'] === 'brak danych — brak tabeli w bazie', 'missing table → « brak danych — brak tabeli w bazie »');
$pdo->exec('RENAME TABLE fpsp_x TO final_product_ppwr_sales_packaging');

// --- Données fictives : contrôle le mar. 24.11.2026 (listopad 2026), wysyłki z października.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, name, surname) VALUES (1, 'Bistro Lumière', NULL, NULL), (2, '', 'Jan', 'Kowal')");
$pdo->exec("INSERT INTO unit VALUES (1, 'szt'), (2, 'g')");
$pdo->exec("INSERT INTO supplier VALUES (1, 'Kartonex Sp. z o.o.', 'biuro@kartonex.example', NULL), (2, 'FoliaPlast', NULL, NULL)");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit, id_current_supplier, is_part_of_package, bdo_material_category, packaging_unit_weight_g, packaging_usage_type) VALUES
    (10, 'Karton 30x20x10', 1, 1, 1, 'PAPER_CARDBOARD', 120, 'SINGLE_USE'), (11, 'Folia PE 40 µm', 1, 2, 1, NULL, NULL, NULL), (12, 'Etykieta 50x30', 1, 1, 1, 'PAPER_CARDBOARD', 2, 'SINGLE_USE'),
    (13, 'Tacka stara', 1, NULL, 1, NULL, NULL, NULL), (14, 'Mąka pszenna', 2, NULL, 0, NULL, NULL, NULL), (15, 'Stretch 23 µm', 1, 2, 1, 'PLASTIC', 30, 'SINGLE_USE')");
$prof = $pdo->prepare('INSERT INTO raw_material_ppwr_profile (id, id_raw_material, identification_number, identification_source, packaging_level, packaging_type, dominant_material,
    recycled_content_status, recyclability_status, minimisation_status, verification_status, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[1, 10, 'KRT-01', 'OWN', 'SALES', 'CARTON_BOX', 'PAPER_CARDBOARD', 'NOT_APPLICABLE', 'ASSESSED', 'ASSESSED', 'COMPLETE', '2026-09-15 10:00:00'],
    [2, 11, null, null, 'INNER', 'FILM', 'PLASTIC', 'NOT_ASSESSED', 'NOT_ASSESSED', 'NOT_ASSESSED', 'REQUIRES_DATA', '2026-08-01 10:00:00'],
    [3, 12, 'ETK-02', 'SUPPLIER', 'INNER', 'LABEL', 'PAPER_CARDBOARD', 'NOT_APPLICABLE', 'ASSESSED', 'ASSESSED', 'COMPLETE', '2026-11-05 09:30:00'],
    [4, 15, 'STR-23', 'SUPPLIER', 'TRANSPORT', 'STRETCH_FILM', 'PLASTIC', 'DECLARED', 'ASSESSED', 'ASSESSED', 'REQUIRES_DATA', '2026-07-01 10:00:00']] as $r) $prof->execute($r);
$pdo->exec("INSERT INTO final_product (id, name, id_recipe, ppc, weight, active, create_timestamp) VALUES (20, 'Bagietka', 100, 18, 250, 1, '2025-01-10 08:00:00'),
    (21, 'Croissant maślany', 101, 24, 1, 1, '2026-11-10 08:00:00'), (22, 'Tarta cytrynowa', NULL, NULL, NULL, 1, '2024-05-01 08:00:00'), (23, 'Stary produkt', 200, 10, 100, 0, '2023-01-01 08:00:00')");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 10, 1), (100, 11, 0.5), (100, 12, 18), (100, 15, 0.02), (100, 14, 2000), (101, 10, 1), (101, 12, 24), (200, 13, 1)");
$pdo->exec("INSERT INTO final_product_ppwr_sales_packaging (id_final_product, id_outer_raw_material, verification_status, updated_at) VALUES (20, 10, 'COMPLETE', '2026-06-01 10:00:00')");
$pdo->exec("INSERT INTO shipment_log (id, shipment_date, id_customer, id_wz) VALUES (31, '2026-10-05', 1, 501), (32, '2026-10-12', 2, 502), (33, '2026-10-20', 1, 503), (34, '2026-11-03', 2, 504), (35, '2026-09-28', 1, 505)");
$pdo->exec("INSERT INTO shipment_packaging_record (id, id_shipment_log, status) VALUES (1, 31, 'GENERATED'), (2, 33, 'REQUIRES_DATA'), (3, 35, 'REQUIRES_DATA')");
$item = $pdo->prepare("INSERT INTO shipment_packaging_item (id_shipment_packaging_record, id_document_wz, id_product, product_name_snapshot, shipped_cartons, calculation_status, ppwr_calculation_status) VALUES (?, ?, 20, 'Bagietka', 10, ?, ?)");
foreach ([[1, 501, 'COMPLETE', 'COMPLETE'], [1, 501, 'COMPLETE', 'COMPLETE'], [2, 503, 'COMPLETE', 'COMPLETE'], [2, 503, 'MISSING_WEIGHT', 'MISSING_IDENTIFIER'], [3, 505, 'MISSING_WEIGHT', 'MISSING_WEIGHT']] as $r) $item->execute($r);

$erpTables = ['raw_material', 'raw_material_ppwr_profile', 'raw_material_ppwr_dimension', 'raw_material_ppwr_document', 'final_product', 'final_product_ppwr_sales_packaging', 'flattened_recipe_ingredient',
    'shipment_log', 'shipment_packaging_record', 'shipment_packaging_item', 'supplier', 'customer', 'unit', 'user'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();

$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$d = $svc->data($today);
$boot = $d;
$keys = array_column($d['review'], 'key');
$check($keys === ['material:11', 'material:15', 'material:12', 'product:22', 'product:21', 'shipment:33', 'shipment:32'],
    'flagged items : waiting, opakowania → produkty → wysyłki, most braki first (complete material 10, product 20, shipment 31 not listed)');
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check($codes($rv['material:11']) === ['MISSING_IDENTIFICATION_NUMBER', 'MISSING_IDENTIFICATION_SOURCE', 'MISSING_UNIT_WEIGHT', 'MISSING_RECYCLED_CONTENT', 'MISSING_RECYCLABILITY',
    'MISSING_MINIMISATION', 'BDO_MISSING_CATEGORY', 'BDO_MISSING_USAGE_TYPE'] && $rv['material:11']['supplier'] === 'FoliaPlast'
    && array_column($rv['material:11']['links'], 'href') === ['/ppwr/register', '/audits/packaging-data'], 'material 11 : PPWR braki (Rejestr PPWR rule) + BDO braki, supplier, links to both screens');
$check($codes($rv['material:15']) === ['MISSING_DIMENSIONS'] && $codes($rv['material:12']) === ['CHANGED'] && $rv['material:12']['codes'] === []
    && str_starts_with($rv['material:12']['signals'][0]['detail'], 'profil PPWR zmieniony czw. 5.11'), 'transport stretch without dimensions ; label profile changed since 1.10 (no braki)');
$check(!isset($rv['material:13']) && $d['totals']['materials'] === ['total' => 5, 'used' => 4, 'incomplete' => 2, 'unused_incomplete' => 1], 'unused packaging (inactive product only) counted, not flagged ; flour (not packaging) ignored');
$check($rv['material:11']['ask']['email'] === null && $rv['material:11']['ask']['subject'] === 'Dane opakowania do dokumentacji PPWR : Folia PE 40 µm'
    && str_contains($rv['material:11']['ask']['text'], "- brak numeru identyfikacyjnego opakowania\n- brak źródła numeru") && $rv['material:12']['ask'] === null, 'supplier request text prepared (to copy), no e-mail address → no mailto');
$check($codes($rv['product:22']) === ['NO_RECIPE', 'NO_PACKAGING', 'NO_SALES_CARTON', 'NO_PPC', 'WEIGHT_MISSING'] && $rv['product:22']['signals'][4]['detail'] === 'Brak masy produktu'
    && array_column($rv['product:22']['links'], 'href') === ['/ppwr/sales-packaging', '/audits/product-weights'], 'product without recipe, packaging, carton, PPC and weight (ProductWeightAuditEvaluator reason)');
$check($codes($rv['product:21']) === ['NO_SALES_CARTON', 'WEIGHT_SUSPECT', 'CHANGED'] && $rv['product:21']['signals'][2]['detail'] === 'nowy produkt (utworzony wt. 10.11)' && !isset($rv['product:23']) && !isset($rv['product:20']),
    'new product : no sales carton, 1 g weight suspect ; inactive and complete products not listed');
$check($codes($rv['shipment:33']) === ['SHIPMENT_REQUIRES_DATA', 'SHIPMENT_PPWR_INCOMPLETE'] && $rv['shipment:33']['signals'][1]['detail'] === '1 z 2 pozycji bez kompletu danych PPWR'
    && $codes($rv['shipment:32']) === ['SHIPMENT_NO_EVIDENCE'] && $rv['shipment:32']['title'] === 'Jan Kowal' && !isset($rv['shipment:34']) && !isset($rv['shipment:35'])
    && array_column($rv['shipment:32']['links'], 'href') === ['/shipments', '/ppwr/reports'], 'October shipments : requires data / PPWR lines incomplete, without evidence ; November and September ignored');
$t = $d['totals'];
$check($t['review'] === 7 && $t['waiting'] === 7 && $t['by_kind'] === ['material' => 3, 'product' => 2, 'shipment' => 2] && $t['changed'] === 2 && $t['products'] === ['total' => 3, 'incomplete' => 2]
    && $t['shipments'] === ['total' => 3, 'missing' => 2] && $d['can_finish'] === false && array_filter(array_column($d['sources'], 'available')) === array_column($d['sources'], 'available'), 'totals ; all sources available');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'x'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'checked'], 2, $today)), 'custom:422:Nadal brakuje : brak numeru identyfikacyjnego opakowania'), '« uzupełnione » refused while braki remain');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'supplier_asked', 'note' => ' '], 2, $today)), 'custom:422:Zapytanie do dostawcy : wpisz'), 'supplier request needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:22', 'decision' => 'supplier_asked', 'note' => 'abc'], 2, $today)), 'custom:422:Zapytanie do dostawcy dotyczy tylko opakowań'), 'supplier request only for packaging');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:22', 'decision' => 'not_applicable'], 2, $today)), 'custom:422:Nie dotyczy : wpisz'), '« nie dotyczy » needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:10', 'decision' => 'not_applicable', 'note' => 'abc'], 2, $today)), 'custom:422:Tego elementu nie ma na liście')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:10', 'decision' => 'checked'], 2, $today)), 'custom:422:Ten element nie miał braku'), 'complete item not in the list refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:12', 'decision' => 'not_applicable', 'note' => 'abc'], 2, $today)), 'custom:422:Ten element nie ma braków'), 'change without braki : only « sprawdzone »');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 7 elementów'), 'finish blocked while items wait');
$check($erp0 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_ppwr_month_review')->fetchColumn() === 0, 'refused decisions write nothing');
$d = $svc->saveDecision(['key' => 'material:11', 'decision' => 'supplier_asked', 'note' => 'FoliaPlast : karta techniczna i deklaracja recyklatu, e-mail 24.11'], 2, $today);
$row = $pdo->query("SELECT * FROM n_ppwr_month_review WHERE item_key = 'material:11'")->fetch();
$check($row['month_start'] === '2026-11-01' && $row['kind'] === 'material' && (int) $row['source_id'] === 11 && $row['decision'] === 'supplier_asked' && (int) $row['id_user'] === 2
    && str_starts_with($row['flags'], 'MISSING_IDENTIFICATION_NUMBER,') && str_ends_with($row['flags'], 'BDO_MISSING_USAGE_TYPE'), 'supplier request saved with the braki codes');
$x = array_column($d['review'], null, 'key')['material:11'];
$check(!$x['waiting'] && $x['decision']['by'] === 'Kasia P.' && $x['decision']['label'] === 'Zapytanie do dostawcy wysłane', 'decision shown with its author');
$st = $tasks->state(PpwrMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 element z decyzją', 'task state : in progress');
$d = $svc->saveDecision(['key' => 'material:12', 'decision' => 'checked', 'note' => ''], 2, $today);
// Données complétées dans l'ERP (comme dans le Rejestr PPWR et les Kartony sprzedażowe), puis « uzupełnione ».
$pdo->exec("INSERT INTO raw_material_ppwr_dimension (id_raw_material_ppwr_profile, length_mm, width_mm, height_mm, outer_volume_cm3, is_active) VALUES (4, 500, 500, 23, 5750, 1)");
$pdo->exec("UPDATE final_product SET weight = 65 WHERE id = 21");
$pdo->exec("INSERT INTO final_product_ppwr_sales_packaging (id_final_product, id_outer_raw_material, verification_status, updated_at) VALUES (21, 10, 'COMPLETE', '2026-11-20 08:00:00')");
$erp1 = $sum();
// Complété depuis l'affichage : plus signalé, « uzupełnione » enregistrée avec les braki vus à l'écran (seen) ; produit nouveau : zmiana encore là.
$d = $svc->saveDecision(['key' => 'material:15', 'decision' => 'checked', 'seen' => ['MISSING_DIMENSIONS', 'NIEZNANY']], 2, $today);
$check($pdo->query("SELECT flags FROM n_ppwr_month_review WHERE item_key = 'material:15'")->fetchColumn() === 'MISSING_DIMENSIONS', 'completed in the ERP : « uzupełnione » saved with the braki seen on screen (unknown codes dropped)');
$d = $svc->saveDecision(['key' => 'product:21', 'decision' => 'checked'], 2, $today);
$d = $svc->saveDecision(['key' => 'product:22', 'decision' => 'not_applicable', 'note' => 'Tarta tylko na zamówienie sklepu, bez kartonu'], 2, $today);
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:33', 'decision' => 'checked'], 2, $today)), 'custom:422:Nadal brakuje : dowód opakowań « wymaga danych »'), 'shipment « uzupełnione » refused while evidence incomplete');
$d = $svc->saveDecision(['key' => 'shipment:33', 'decision' => 'not_applicable', 'note' => 'próbki — dowód uzupełniony na papierze'], 2, $today);
$d = $svc->saveDecision(['key' => 'shipment:32', 'decision' => 'not_applicable', 'note' => 'odbiór własny klienta'], 2, $today);
$rv = array_column($d['review'], null, 'key');
$check($rv['material:15']['signals'] === [] && $rv['material:15']['decision']['code'] === 'checked' && $rv['product:21']['codes'] === [] && $codes($rv['product:21']) === ['CHANGED']
    && $d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['checked'] === 3 && $d['totals']['supplier_asked'] === 1 && $d['totals']['not_applicable'] === 3, 'all decided → step 3 open ; completed items stay listed with their decision');
$check($erp1 === $sum(), 'decisions write nothing in the source tables');
$r = $svc->finish(2, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'ppwr_month_reviewed'")->fetchAll();
$check($r['summary'] === 'listopad 2026 : opakowania 1 z brakami / 4 · produkty 1 z brakami / 3 · 2 zmiany · wysyłki 2 bez kompletu dowodu / 3 · decyzje : 3 sprawdzone · 1 zapytanie do dostawcy · 3 nie dotyczy'
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null, 'finish : summary, n_wizard_done');
$st = $tasks->state(PpwrMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === true && $st['summary'] === $r['summary'] && $tasks->state(PpwrMonthService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');
$check($erp1 === $sum(), 'finish writes nothing in the source tables');

// Mois suivant (mer. 2.12) : zapytanie revient (braki toujours là), nie dotyczy reprise, zmiany depuis le « Zakończ », wysyłki de novembre.
$n = $make()->data('2026-12-02');
$nx = array_column($n['review'], null, 'key');
$check($n['month_start'] === '2026-12-01' && $n['since_review'] && $nx['material:11']['waiting'] && $nx['material:11']['previous']['code'] === 'supplier_asked'
    && !$nx['product:22']['waiting'] && $nx['product:22']['earlier']['code'] === 'not_applicable' && !isset($nx['material:12']) && !isset($nx['product:21'])
    && array_keys(array_filter($nx, fn($i) => $i['kind'] === 'shipment')) === ['shipment:34'] && $n['totals']['waiting'] === 2 && $n['done'] === null,
    'next month : supplier request comes back, « nie dotyczy » carried, no change since « Zakończ », November shipment');
$pdo->exec("UPDATE final_product SET ppc = 12 WHERE id = 22");
$check($make()->data('2026-12-02')['totals']['waiting'] === 2 && array_column($make()->data('2026-12-02')['review'], null, 'key')['product:22']['waiting'] === false,
    'fewer braki than at « nie dotyczy » : still carried');
$pdo->exec("UPDATE final_product SET ppc = NULL WHERE id = 22");

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_ppwr_month_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(PpwrMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'ppwr_month')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
$hrefs = fn(array $body) => array_values(array_unique(array_merge(...array_map(fn($i) => array_column($i['links'], 'href'), $body['data']['review']))));
$check($c2 === 200 && $b2['data']['month_start'] === PpwrMonthService::reviewMonth(date('Y-m-d')) && in_array('/ppwr/register', $hrefs($b2), true) && in_array('/audits/product-weights', $hrefs($b2), true)
    && !in_array('/shipments', $hrefs($b2), true), 'controller data() (Kierownik produkcji : ppwr_month + ppwr + bdo) : 200, PPWR / BDO links, no shipments link without « shipping »');
[$c3, $b3] = $call(3, 'data');
$check($c3 === 200 && $hrefs($b3) === [] && $b3['data']['links'] === [], 'controller data() (position with the wizard right only) : 200, no link to screens it cannot open');
[$c1, $b1] = $call(1, 'data');
$check($c1 === 200 && in_array('/shipments', $hrefs($b1), true), 'controller data() (administrator) : 200 with every link');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do asystenta « PPWR i opakowania ».' && $cD === 403 && $cF === 403, 'controller : Piekarz without « ppwr_month » → 403 for data(), decision() and finish()');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() really called : done or blocked by waiting items');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/PpwrMonthService.php', 'App/Repositories/Workshop/PpwrMonthRepository.php',
    'App/Controllers/Web/Audit/PpwrMonthController.php', 'public/assets/js/ppwr_month/ppwr-month.js']));
$check(!preg_match('/UPDATE\s+(raw_material|final_product|shipment)|INSERT\s+INTO\s+(raw_material|final_product|shipment)|DELETE\s+FROM|PHPMailer|Mail::|Sms|curl_|https?:\/\/|PATCH/i', $src),
    'no write to PPWR / BDO / shipments, no mail/SMS sending, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('ppwr_month_reviewed')['link'] === '/ppwr/month' && TaskWizards::byLink('/ppwr/month') === 'ppwr_month_reviewed'
    && isset(TaskWizards::AUTO['ppwr_month_reviewed']) && PositionTaskService::LINK_AUTO['/ppwr/month'] === 'ppwr_month_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['ppwr_month_reviewed'][0] === 'PPWR i opakowania (przegląd miesiąca)' && TaskWizards::CATALOG['ppwr_month_reviewed'][0] !== 'PPWR i opakowania (kompletność)',
    'catalog label distinct from the proposed name in the matrix (237)');
$check(PageAccess::keyFor('/ppwr/month') === 'ppwr_month' && PageAccess::keyFor('/ajax/ppwr/month/decision') === 'ppwr_month' && PageAccess::keyFor('/ppwr/register') === 'ppwr'
    && PageAccess::keyFor('/audits/product-weights') === 'bdo' && in_array('ppwr_month', PageAccess::grantable(), true), 'own right « ppwr_month » (page and ajax) ; PPWR and BDO screens unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'ppwr_month_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/ppwr/month'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Audit/PpwrMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/ppwr/month') === 'index' && $found('GET', '/ajax/ppwr/month') === 'data' && $found('POST', '/ajax/ppwr/month/decision') === 'decision'
    && $found('POST', '/ajax/ppwr/month/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/ppwr/month')[1]['controller'] === PpwrMonthController::class && $allR->dispatch('GET', '/ppwr/register')[0] === FastRoute\Dispatcher::FOUND
    && $allR->dispatch('GET', '/ppwr/sales-packaging')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob ; existing PPWR routes unchanged');

if (getenv('PM_JSON_OUT')) file_put_contents((string) getenv('PM_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_ppwr_month_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
