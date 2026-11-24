<?php
/**
 * [415–416] Tablet jakości Kierownika produkcji (makieta hali I, J, K) : migrations 250 et 251 rejouables ; personnes aux
 * postes KIEROWNIK_PRODUKCJI / JAKOSC seulement (403 QC_ROUND_POSITION sinon, aussi par le noyau de l'API) ; tour du jour
 * (Pilne : non-conformité au contrôle du groupe, signalement du groupe ; Teraz / Potem dans l'ordre de démarrage ; contrôlées),
 * obchód N sur M ; contrôle d'une partie : poids (cible de la fiche, tolérance sur la moyenne, pièce légère), sacs sans scan
 * (409 tant qu'ils ne sont ni scannés ni envoyés au groupe), « Wyślij grupie » = message sur la tablette du groupe
 * (n_task_reminder, une fois, fermé quand les sacs sont scannés), « Zeskanuj sam » = mécanisme de la tablette du groupe,
 * échantillon (create / store, étiquette d'une autre partie refusée), enregistrement (n_qc_control + contrôle de la partie
 * → trou « brak kontroli jakości » de « Partie i kontrola dnia » comblé, n_wizard_done jamais écrit), écart → décision NC
 * obligatoire (warn sans NC, nc avec NC au registre), NC du groupe non écrasée ; suivi : numéro de partie, carton (une ou
 * plusieurs parties), matières (fournisseur, réception, manque, non suivie), cartons (client + WDT, palette, magasin) ;
 * PDF ; blocage sans toucher à carton_stock, lu par la palettisation (409 PALLET_PRODUCT_HELD) et le chargement (palette),
 * levée avec note ; enregistrement (routes, OpenAPI, traductions, page de la tablette).
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_quality_tablet_test recréée puis supprimée (QT_KEEP=1 : gardée, pour les captures). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Api\Workshop\PackingController;
use App\Core\Api\Workshop\{ApiContext, ApiException, ApiRequest, Jwt, Translator, WorkshopApiKernel, WorkshopConfig};
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Repositories\Workshop\{LotsQcDayRepository, QualityRoundRepository};
use App\Services\Organization\Wizards\LotsQcDayService;
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Quality\{LotHoldService, LotTraceService, QualityRoundService};
use Symfony\Component\HttpFoundation\Request;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$dbName = 'erp_quality_tablet_test';
$pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("DROP DATABASE IF EXISTS {$dbName}");
$pdo->exec("CREATE DATABASE {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE {$dbName}");
$pdo->exec("SET time_zone = '" . date('P') . "'");

foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL, email VARCHAR(120) NULL)',
    "CREATE TABLE n_prod_group (id INT PRIMARY KEY, code VARCHAR(32), name_pl VARCHAR(100), name_fr VARCHAR(100), color CHAR(7) NOT NULL DEFAULT '#8D1D2C', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(50), surname VARCHAR(50), id_section INT NULL)',
    'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)',
    'CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT NOT NULL, id_primary_group INT NULL, active TINYINT DEFAULT 1, id_position INT NULL)',
    'CREATE TABLE n_prod_employee_credential (id_employee INT PRIMARY KEY, badge_hash CHAR(64) NULL, pin_hash VARCHAR(255) NULL, pin_lookup CHAR(64) NULL, pin_conflict TINYINT NOT NULL DEFAULT 0,
        failed_attempts INT DEFAULT 0, locked_until DATETIME NULL, biometric_consent TINYINT DEFAULT 0)',
    'CREATE TABLE n_prod_clock (id INT AUTO_INCREMENT PRIMARY KEY, id_employee INT, clock_in DATETIME, clock_out DATETIME NULL)',
    'CREATE TABLE n_prod_phone (id INT AUTO_INCREMENT PRIMARY KEY, id_employee INT, revoked_at DATETIME NULL)',
    'CREATE TABLE n_prod_team (id INT PRIMARY KEY, id_group INT, active TINYINT DEFAULT 1)',
    'CREATE TABLE n_prod_team_member (id INT AUTO_INCREMENT PRIMARY KEY, id_team INT, id_employee INT, valid_from DATE NULL, valid_to DATE NULL)',
    "CREATE TABLE n_prod_device (id INT PRIMARY KEY, name VARCHAR(100), id_group INT NULL, active TINYINT, token_hash VARCHAR(64) NULL, mode VARCHAR(16) NULL, id_carrier_vehicle INT NULL,
        last_seen_at DATETIME NULL, pin_failed SMALLINT NOT NULL DEFAULT 0, pin_locked_until DATETIME NULL)",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INT NULL)",
    'CREATE TABLE n_prod_audit_log (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT NULL, action VARCHAR(64), entity VARCHAR(64), entity_id VARCHAR(64) NULL,
        before_json TEXT NULL, after_json TEXT NULL, request_id VARCHAR(64) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE category_material (id INT PRIMARY KEY, id_group INT NULL)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(100), name_fr VARCHAR(100) NULL, id_unit INT NULL, id_category INT NULL)',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(100))',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(100))',
    'CREATE TABLE product_recipe (id INT PRIMARY KEY, name VARCHAR(100), yield_quantity DECIMAL(12,3) DEFAULT 1, id_unit INT NULL)',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(100), id_recipe INT NULL, id_category INT NULL, ppc INT NULL, weight DECIMAL(10,2) NULL, shelf_life INT NULL, active TINYINT DEFAULT 1)',
    'CREATE TABLE flattened_recipe_ingredient (recipe_id INT, material_id INT, quantity DECIMAL(14,3))',
    'CREATE TABLE product_recipe_connection (id INT AUTO_INCREMENT PRIMARY KEY, parent_recipe_id INT, child_recipe_id INT NULL, child_ingredient_id INT NULL, quantity DECIMAL(14,3))',
    'CREATE TABLE n_recipe_producer (id_recipe INT PRIMARY KEY, id_group INT NOT NULL, lead_days TINYINT NULL)',
    "CREATE TABLE n_prod_item (id INT PRIMARY KEY, item_type ENUM('product','semi') NOT NULL, id_final_product INT NULL, id_recipe INT NULL, base_unit VARCHAR(8) NOT NULL DEFAULT 'szt')",
    'CREATE TABLE n_prod_product_settings (id_item INT PRIMARY KEY, id_primary_group INT NULL, batch_multiple DECIMAL(12,3) NULL)',
    "CREATE TABLE n_prod_output (id BIGINT AUTO_INCREMENT PRIMARY KEY, work_date DATE NOT NULL, id_group INT NOT NULL, id_item INT NOT NULL, quantity DECIMAL(12,3) NOT NULL, lot_code VARCHAR(32) NOT NULL,
        id_employee INT NULL, id_device INT NULL, client_uuid CHAR(36) NULL, note VARCHAR(255) NULL, qc_status ENUM('ok','nc') NULL, qc_at DATETIME NULL, qc_employee INT NULL, qc_note VARCHAR(255) NULL,
        id_nc INT NULL, cancelled_at DATETIME NULL, cancelled_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (lot_code))",
    'CREATE TABLE n_prod_output_lot (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_output BIGINT NOT NULL, id_material INT NOT NULL, quantity DECIMAL(14,3) NULL, lot_number VARCHAR(64) NULL, best_before DATE NULL,
        id_photo BIGINT NULL, ocr_text TEXT NULL, id_employee INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (id_output, id_material))',
    'CREATE TABLE n_prod_output_component (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_output BIGINT NOT NULL, id_recipe INT NOT NULL, id_source_output BIGINT NULL, quantity DECIMAL(14,3) NULL, unit VARCHAR(8) NULL,
        id_employee INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (id_output, id_recipe))',
    'CREATE TABLE n_prod_lot_photo (id BIGINT AUTO_INCREMENT PRIMARY KEY, mime VARCHAR(32) NOT NULL, bytes INT NOT NULL, sha256 CHAR(64) NOT NULL, image MEDIUMBLOB NOT NULL, id_device INT NULL,
        id_employee INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    "CREATE TABLE n_inv (id INT AUTO_INCREMENT PRIMARY KEY, source ENUM('ksef','pdf') DEFAULT 'ksef', id_supplier INT NULL, number VARCHAR(80), issue_date DATE NULL,
        status VARCHAR(20) DEFAULT 'open', kind VARCHAR(20) DEFAULT 'goods', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    'CREATE TABLE n_inv_line (id INT AUTO_INCREMENT PRIMARY KEY, id_inv INT, line_no SMALLINT, name VARCHAR(200), quantity DECIMAL(14,3), unit VARCHAR(20), id_raw_material INT NULL, factor DECIMAL(14,6) NULL)',
    'CREATE TABLE n_po (id INT AUTO_INCREMENT PRIMARY KEY, id_supplier INT)',
    'CREATE TABLE n_po_line (id INT AUTO_INCREMENT PRIMARY KEY, id_po INT, id_raw_material INT)',
    'CREATE TABLE n_po_receipt (id INT AUTO_INCREMENT PRIMARY KEY, id_po_line INT, received_on DATE, quantity DECIMAL(14,3), lot_number VARCHAR(64) NULL, best_before DATE NULL)',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL, done_at DATETIME NOT NULL, UNIQUE KEY (code, day))",
    "CREATE TABLE n_nc (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20) NOT NULL, reported_at DATE NOT NULL, source ENUM('supplier_delivery','production','customer','internal') NOT NULL,
        id_supplier INT NULL, id_raw_material INT NULL, id_final_product INT NULL, id_prod_group INT NULL, description TEXT NOT NULL, severity ENUM('minor','major','critical') NOT NULL DEFAULT 'minor',
        quantity DECIMAL(12,3) NULL, unit VARCHAR(16) NULL, immediate_action TEXT NULL, root_cause TEXT NULL, corrective_action TEXT NULL, cost_impact DECIMAL(12,2) NULL,
        status ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open', owner_user INT NULL, due_date DATE NULL, closed_at DATETIME NULL, created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (number))",
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(120))',
    "CREATE TABLE carton_stock (id_carton INT AUTO_INCREMENT PRIMARY KEY, id_product INT, status TINYINT, date_add DATE, date_pallet DATETIME NULL, expiration_date DATE DEFAULT '2000-01-01', batch_number VARCHAR(20) NULL)",
    'CREATE TABLE carton_pallet_connection (id_key INT AUTO_INCREMENT PRIMARY KEY, id_carton INT, id_palet INT)',
    'CREATE TABLE palets_delivery (id_palet INT AUTO_INCREMENT PRIMARY KEY, id_customer INT, delivery_date DATE, status INT, last_update_date DATETIME NULL)',
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40), id_customer INT, established_date DATE)',
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT)',
    'CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, expected_shipping_date DATETIME, status INT, is_freebie TINYINT NULL)',
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))',
] as $sql) $pdo->exec($sql);
$migrate = function (string $file) use ($pdo) { foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $file)) as $sql) $pdo->exec($sql); };
// 209 : ses tables et colonnes seulement (registre des lots, échantillons) — pas la tâche du poste.
foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/209_material_lots_samples.sql')) as $sql) {
    if (preg_match('/^\s*(CREATE TABLE|SET @|PREPARE|EXECUTE|DEALLOCATE)/', $sql)) $pdo->exec($sql);
}
$migrate('150_team_tasks_reminders.sql');
for ($k = 0; $k < 2; $k++) { $migrate('250_quality_round.sql'); $migrate('251_lot_hold.sql'); }

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$code = function (callable $f): string { try { $f(); return 'none'; } catch (ApiException $e) { return $e->errorCode() . ':' . $e->httpStatus(); } };
$q = fn(string $sql) => $pdo->query($sql)->fetchColumn();

// --- Migrations 250 / 251 (deux passages)
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'qc_round.%' ORDER BY code")->fetchAll(), 'value', 'code');
$check($params === ['qc_round.pieces' => '3', 'qc_round.times' => '08:00,10:30,13:00', 'qc_round.weight_tolerance_pct' => '2'], 'migration 250 replayed: 3 params once');
$check((int) $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_qc_control', 'n_qc_hold')") === 2
    && (int) $q("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_qc_control', 'n_qc_hold')") === 0,
    'migrations 250 + 251: 2 tables, no foreign key');
$check(App\Domain\Params\ParamRules::RANGES['qc_round.pieces'] === [1, 10, true] && App\Domain\Params\ParamRules::RANGES['qc_round.weight_tolerance_pct'] === [0, 50, false], 'ParamRules bounds');

// --- Données : lundi, parties du jour de 5 groupes
$today = date('Y-m-d');
$at = fn(string $hm) => $today . ' ' . $hm . ':00';
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('trace.label_days', '1', '1', 'int', 'x', 'x')");
$pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order) VALUES (1, 'PIEK', 'Piekarnia', 'Boulangerie', 1), (2, 'GARM', 'Garmażeria', 'Traiteur', 2),
    (3, 'ROGA', 'Rogal', 'Viennoiserie', 3), (4, 'TART', 'Tarty', 'Tartes', 4), (5, 'CUKI', 'Cukiernia', 'Pâtisserie', 5)");
$pdo->exec("INSERT INTO n_position (id, code, name_pl) VALUES (1, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji'), (2, 'JAKOSC', 'Specjalista ds. jakości'), (3, 'PIEKARZ', 'Piekarz')");
$pdo->exec("INSERT INTO production_employee (id, name, surname) VALUES (10, 'Anna', 'Kierownik'), (11, 'Ewa', 'Jakość'), (12, 'Jan', 'Piekarz')");
$pdo->exec('INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, id_position) VALUES (10, 10, NULL, 1), (11, 11, NULL, 2), (12, 12, 1, 3)');
$pdo->exec("INSERT INTO n_prod_employee_credential (id_employee, pin_hash) VALUES (10, '" . password_hash('2580', PASSWORD_BCRYPT) . "'), (12, '" . password_hash('1111', PASSWORD_BCRYPT) . "')");
$pdo->exec("INSERT INTO n_prod_device (id, name, id_group, active, token_hash, mode) VALUES (1, 'Tablet jakości', NULL, 1, '" . hash('sha256', 'dev-quality-token') . "', 'atelier')");
$pdo->exec("INSERT INTO unit VALUES (1, 'kg'), (2, 'l')");
$pdo->exec("INSERT INTO supplier VALUES (7, 'Młyn Testowy'), (8, 'Solny Test')");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit) VALUES (100, 'Mąka T65', 1), (101, 'Sól', 1), (102, 'Drożdże', 1), (103, 'Woda', 2), (104, 'Masło', 1)");
$pdo->exec('INSERT INTO n_material_trace (id_raw_material, tracked) VALUES (103, 0)');
$pdo->exec("INSERT INTO category_product VALUES (1, 'Pieczywo'), (2, 'Dania')");
$pdo->exec("INSERT INTO n_sample_rule (id_category, frequency) VALUES (1, 'lot'), (2, 'none')");
$pdo->exec("INSERT INTO product_recipe (id, name) VALUES (501, 'Baguette'), (502, 'Lasagne'), (503, 'Croissant'), (504, 'Tarta'), (505, 'Brownie')");
$pdo->exec("INSERT INTO final_product (id, name, id_recipe, id_category, ppc, weight, shelf_life) VALUES (20, 'Baguette Tradition', 501, 1, 18, 500, 3), (21, 'Lasagne', 502, 2, 6, 400, 4),
    (22, 'Croissant beurre', 503, 1, 40, 60, 2), (23, 'Tarte citron', 504, 2, 8, 450, 4), (24, 'Brownies 28', 505, 2, 28, NULL, 5)");
$pdo->exec('INSERT INTO flattened_recipe_ingredient VALUES (501, 100, 9), (501, 101, 0.2), (501, 102, 0.1), (501, 103, 6), (502, 104, 1), (503, 100, 2), (503, 104, 1), (504, 100, 1), (505, 104, 1)');
$pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, id_recipe, base_unit) VALUES (1, 'product', 20, NULL, 'szt'), (2, 'product', 21, NULL, 'szt'), (3, 'product', 22, NULL, 'szt'),
    (4, 'product', 23, NULL, 'szt'), (5, 'product', 24, NULL, 'szt')");
$out = function (int $id, int $g, int $item, string $lot, float $qty, string $hm, ?string $qc = null, array $o = []) use ($pdo, $today, $at) {
    $pdo->prepare('INSERT INTO n_prod_output (id, work_date, id_group, id_item, quantity, lot_code, qc_status, qc_at, qc_note, id_nc, cancelled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $o['day'] ?? $today, $g, $item, $qty, $lot, $qc, $o['qc_at'] ?? null, $o['qc_note'] ?? null, $o['nc'] ?? null, $o['cancelled'] ?? null, $at($hm)]);
};
$d6 = date('ymd');
$out(1, 1, 1, "{$d6}-PIEK-02", 1044, '07:40');                                                     // Baguette : drożdże sans scan, échantillon (règle lot)
$out(2, 2, 2, "{$d6}-GARM-03", 120, '08:10', 'nc', ['qc_at' => $at('09:05'), 'qc_note' => 'Temperatura 14 °C po 2 h']); // NC du groupe → Pilne
$out(3, 3, 3, "{$d6}-ROGA-01", 800, '09:00');                                                     // Croissant : Potem
$out(4, 4, 4, "{$d6}-TART-01", 96, '06:30');                                                      // Tarte : contrôlée → Dobrze
$out(5, 5, 5, "{$d6}-CUKI-02", 280, '08:30');                                                     // Brownies : signalement du groupe → Pilne
$out(6, 1, 1, "{$d6}-PIEK-01", 10, '06:00', null, ['cancelled' => $at('06:10')]);                 // annulée : hors tour
// [420] Table des refroidissements de la tablette de hala (vide jusqu'au cas « Schładzanie » plus bas).
$pdo->exec('CREATE TABLE n_hala_chill (id BIGINT AUTO_INCREMENT PRIMARY KEY, work_date DATE, id_group INT, id_item INT, id_output BIGINT NULL, out_at DATETIME, temp_c DECIMAL(4,1) NULL,
    measured_at DATETIME NULL, target_c DECIMAL(4,1), max_minutes SMALLINT)');
$pdo->exec("INSERT INTO n_material_lot (id, id_raw_material, lot_number, best_before, source, id_supplier, received_on, active) VALUES
    (1, 100, 'M-4471', '2027-01-14', 'receipt', 7, '" . date('Y-m-d', strtotime('-5 days')) . "', 1), (2, 101, 'S-0912', '2028-03-31', 'receipt', 8, '" . date('Y-m-d', strtotime('-21 days')) . "', 1),
    (3, 102, 'D-2211', '" . date('Y-m-d', strtotime('+20 days')) . "', 'receipt', 7, '" . date('Y-m-d', strtotime('-2 days')) . "', 1)");
$pdo->exec("INSERT INTO n_prod_output_lot (id_output, id_material, id_material_lot, lot_number, best_before) VALUES (1, 100, 1, 'M-4471', '2027-01-14'), (1, 101, 2, 'S-0912', '2028-03-31'),
    (3, 100, 1, 'M-4471', '2027-01-14'), (3, 104, NULL, 'B-1', NULL), (4, 100, 1, 'M-4471', NULL), (2, 104, NULL, 'B-2', NULL), (5, 104, NULL, 'B-3', NULL)");
$pdo->exec("INSERT INTO n_qc_control (id_output, work_date, id_employee, weights, target_g, tolerance_pct, weight_result, appearance, lots_total, result, created_at)
    VALUES (4, '{$today}', 11, '452,449,455', 450, 2, 'ok', 'good', 1, 'ok', '{$at('08:55')}')");
$pdo->exec("INSERT INTO n_nc (number, reported_at, source, id_final_product, id_prod_group, description, created_at) VALUES ('NC-2026-001', '{$today}', 'production', 24, 5, 'Brownies przypalone na brzegach', '{$at('09:40')}')");

// --- Conteneur (PHP-DI) sur cette base
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('q', 48)])]);
$di = $b->build();
$round = $di->get(QualityRoundService::class);
$trace = $di->get(LotTraceService::class);
$holds = $di->get(LotHoldService::class);
$tr = new Translator('pl');
$ctx = fn(int $emp) => new ApiContext('test', $tr, null, [], ['prod.kiosk' => true], [], deviceId: 1, employeeId: $emp, deviceGroupId: null);
$kp = $ctx(10);

// --- Qui
$people = $round->people();
$check(array_column($people, 'position', 'id') === [10 => 'KIEROWNIK_PRODUKCJI', 11 => 'JAKOSC'] && $people[0]['has_pin'] === true && $people[1]['has_pin'] === false,
    'people: Kierownik produkcji and Jakość only, PIN state');
$check($code(fn() => $round->round($ctx(12))) === 'QC_ROUND_POSITION:403', 'a baker cannot open the quality round (403 QC_ROUND_POSITION)');

// --- I. Le tour (10:45 → obchód 2 z 3)
$r = $round->round($kp, $at('10:45'));
$order = array_map(fn($x) => $x['id'] . ':' . $x['status'], $r['rows']);
$check($order === ['2:urgent', '5:urgent', '1:now', '3:later', '4:ok'], 'round order: urgent (group NC, group report), now, later, checked (' . implode(' ', $order) . ')');
$check($r['round'] === ['no' => 2, 'of' => 3] && $r['to_check'] === 3 && $r['next_id'] === 1 && $r['counts']['batches'] === 5, 'round 2 of 3, 3 to check, next = first not checked');
$rows = array_column($r['rows'], null, 'id');
$check($rows[1]['todo']['missing_lots'] === 1 && $rows[1]['todo']['sample'] === true && $rows[3]['todo']['sample'] === true && $rows[2]['todo']['sample'] === false,
    'todo: 1 bag without scan (water untracked), sample by category rule');
$check($rows[2]['signal']['kind'] === 'group_nc' && str_contains((string) $rows[2]['signal']['text'], '14 °C') && $rows[5]['signal']['kind'] === 'group_report'
    && $rows[4]['checked']['at'] === '8:55' && in_array('weight', $rows[4]['checked']['items'], true), 'signals from group tablets; checked row: time and items');
// [420] Refroidissement hors limite sur la tablette de hala (n_hala_chill) : signal « pilne » de la partie (Croissant 3).
$pdo->prepare('INSERT INTO n_hala_chill (work_date, id_group, id_item, id_output, out_at, temp_c, measured_at, target_c, max_minutes) VALUES (?, 3, 3, NULL, ?, 14.0, ?, 10.0, 120)')
    ->execute([$today, $at('09:30'), $at('10:20')]);
$pdo->prepare('INSERT INTO n_hala_chill (work_date, id_group, id_item, id_output, out_at, temp_c, measured_at, target_c, max_minutes) VALUES (?, 4, 4, 4, ?, 8.0, ?, 10.0, 120)')
    ->execute([$today, $at('07:00'), $at('08:00')]);
$rc = array_column($round->round($kp, $at('10:45'))['rows'], null, 'id');
$check($rc[3]['status'] === 'urgent' && $rc[3]['signal']['kind'] === 'chill' && str_contains((string) $rc[3]['signal']['text'], '14 °C') && $rc[4]['status'] === 'ok',
    '[420] chilling too warm (14 °C > 10 °C) on the hall tablet : batch urgent ; chilling within limits : no signal');
$pdo->exec('DELETE FROM n_hala_chill');
$check($round->round($kp, $at('07:10'))['round'] === ['no' => 1, 'of' => 3] && $round->round($kp, $at('14:00'))['round'] === ['no' => 3, 'of' => 3], 'round number before the first time and after the last');

// --- J. Une partie
$bt = $round->batch(1, $kp);
// [431] Karta produktu depuis la tablette : l'article et le produit du lot pour ouvrir la fiche (mode tablette).
$check($bt['item_id'] === 1 && array_key_exists('product_id', $bt), 'batch : item and product of the lot for « Karta produktu »');
$qjs = (string) file_get_contents(__DIR__ . '/../public/assets/js/workshop/quality.js');
$ptw = (string) file_get_contents(__DIR__ . '/../App/Views/workshop/products.twig');
$check(str_contains($qjs, '/workshop/products?tablet=1#/') && str_contains($qjs, 'data-cards') && str_contains($qjs, 'data-card]')
    && str_contains($ptw, '{{ root }}/tablet/quality') && str_contains($ptw, '#sidebar, #main > header { display: none !important; }'),
    'tablet : « Karty produktów » (round) and « Karta produktu » (batch) open the ERP product cards in tablet mode, with the way back');
$check($bt['weight'] === ['pieces' => 3, 'target_g' => 500.0, 'tolerance_pct' => 2.0, 'min_g' => 490.0, 'max_g' => 510.0], 'weight spec: 3 pieces, product card 500 g ± 2 %');
$lots = array_column($bt['lots'], null, 'name');
$check($lots['Mąka T65']['state'] === 'ok' && $lots['Mąka T65']['supplier'] === 'Młyn Testowy' && $lots['Drożdże']['state'] === 'missing' && $lots['Woda']['state'] === 'untracked'
    && $bt['missing_lots'] === 1 && $lots['Drożdże']['choices'][0]['lot_number'] === 'D-2211' && $bt['sample']['state'] === 'suggested', 'bags: scanned (supplier), missing, untracked; registry choices; sample suggested');
$w = QualityRoundService::weigh($bt['weight'], [497, 503, 488]);
$check($w === ['result' => 'ok', 'average' => 496.0, 'pieces' => ['ok', 'ok', 'light']], 'weight: average 496 g in range, piece 3 light (mockup)');
$check(QualityRoundService::weigh($bt['weight'], [480, 482, 485])['result'] === 'light' && QualityRoundService::weigh($bt['weight'], [530, 520, 515])['result'] === 'heavy'
    && QualityRoundService::weigh(['target_g' => null] + $bt['weight'], [1, 2])['result'] === 'none', 'weight: light, heavy, no target');
$check($code(fn() => $round->saveControl(1, ['weights' => [497, 503, 488], 'appearance' => 'good'], $kp)) === 'QC_ROUND_LOTS_OPEN:409', 'bag without scan: save refused until scanned or sent');

// Wyślij grupie : message sur la tablette du groupe, une fois.
$bt = $round->requestLots(1, $kp);
$round->requestLots(1, $kp);
$rem = $pdo->query("SELECT * FROM n_task_reminder")->fetchAll();
$check(count($rem) === 1 && $rem[0]['audience'] === 'group:1' && $rem[0]['target_kind'] === 'tablet' && str_contains($rem[0]['message'], 'Drożdże') && $rem[0]['batch'] === QualityRoundRepository::requestBatch(1)
    && $bt['requested'] !== null, 'send to group: one tablet message for group 1 (bag named)');
$check(count($di->get(App\Repositories\Organization\TeamTasksRepository::class)->tabletMessages(['group:1'], date('Y-m-d 00:00:00', strtotime('-7 days')))) === 1, 'message listed for the group tablet (GET /kiosk/messages)');
$check($round->round($kp, $at('10:46'))['rows'][2]['todo']['requested'] === 1, 'round shows the request');

// Zeskanuj sam : mécanisme de la tablette du groupe, au nom du groupe de la partie.
$bt = $round->scanLot(1, 102, ['material_lot_id' => 3], $kp);
$lotRow = $pdo->query('SELECT * FROM n_prod_output_lot WHERE id_output = 1 AND id_material = 102')->fetch();
$check($lotRow['lot_number'] === 'D-2211' && (int) $lotRow['id_material_lot'] === 3 && (int) $lotRow['id_employee'] === 10 && $bt['missing_lots'] === 0 && $bt['requested'] === null,
    'scan myself: lot saved like on the group tablet (person = manager)');
$check($pdo->query("SELECT closed_at FROM n_task_reminder")->fetchColumn() !== null, 'request closed once the bags are scanned');

// Échantillon : création, étiquette d'une autre partie refusée, étiquette posée.
$bt = $round->sample(1, ['action' => 'create'], $kp);
$sample = $pdo->query('SELECT * FROM n_prod_sample WHERE id_output = 1')->fetch();
$check($bt['sample']['state'] === 'planned' && $sample['status'] === 'planned', 'sample created (Próbki produkcji)');
$round->sample(3, ['action' => 'create'], $kp);
$other = $pdo->query('SELECT code FROM n_prod_sample WHERE id_output = 3')->fetchColumn();
$check($code(fn() => $round->sample(1, ['action' => 'store', 'code' => 'https://erp.test/q/' . $other], $kp)) === 'VALIDATION_FAILED:422', 'label of another batch refused');
$bt = $round->sample(1, ['action' => 'store', 'code' => 'https://erp.test/q/' . $sample['code']], $kp);
$check($bt['sample']['state'] === 'stored' && $pdo->query('SELECT status FROM n_prod_sample WHERE id_output = 1')->fetchColumn() === 'stored', 'sample label scanned: stored');

// Photo (n_prod_lot_photo)
$png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 120));
$photo = $round->photo(['photo' => $png], $kp);
$check($photo['id'] > 0 && (int) $q('SELECT id_employee FROM n_prod_lot_photo WHERE id = ' . $photo['id']) === 10, 'appearance photo stored with lot photos');

// Zapisz kontrolę : dobrze.
$wizardBefore = (int) $q('SELECT COUNT(*) FROM n_wizard_done');
$saved = $round->saveControl(1, ['weights' => [497, 503, 488], 'appearance' => 'good', 'photo_id' => $photo['id']], $kp);
$c1 = $pdo->query('SELECT * FROM n_qc_control WHERE id_output = 1')->fetch();
$o1 = $pdo->query('SELECT * FROM n_prod_output WHERE id = 1')->fetch();
$check($saved['result'] === 'ok' && $c1['weights'] === '497,503,488' && $c1['weight_result'] === 'ok' && $c1['sample'] === 'stored' && (int) $c1['lots_missing'] === 0 && (int) $c1['id_photo'] === $photo['id'],
    'control saved (weights, verdict, sample, bags, photo)');
$check($o1['qc_status'] === 'ok' && (int) $o1['qc_employee'] === 10 && str_contains((string) $o1['qc_note'], '496'), 'batch QC set through the group tablet mechanism');
$lq = $di->get(LotsQcDayService::class);
$lr = $di->get(LotsQcDayRepository::class);
$gaps = $lq->batch($di->get(QualityRoundRepository::class)->outputRow(1), $lr->lots([1])[1] ?? [], $lr->components([1])[1] ?? [])['gaps'];
$check(!array_filter($gaps, fn($g) => in_array($g['code'], ['qc_missing', 'lot_missing'], true)), '« Partie i kontrola dnia »: no more qc_missing / lot_missing for this batch');
$check((int) $q('SELECT COUNT(*) FROM n_wizard_done') === $wizardBefore, 'n_wizard_done never written by the tablet');
$after = array_column($saved['round']['rows'], 'status', 'id');
$check($after[1] === 'ok' && $after[3] === 'now', 'round after the control: batch checked, next becomes « now »');

// Écart : décision NC obligatoire ; sans NC = warn ; avec NC = registre.
$check($code(fn() => $round->saveControl(3, ['weights' => [55, 56, 57], 'appearance' => 'good'], $kp)) === 'QC_ROUND_NC_DECISION:409', 'weight out of range: NC decision required');
$ncBefore = (int) $q('SELECT COUNT(*) FROM n_nc');
$round->sample(3, ['action' => 'store', 'code' => $other], $kp);
$s = $round->saveControl(3, ['weights' => [55, 56, 57], 'appearance' => 'good', 'nc' => ['create' => false], 'note' => 'Partia na sprzedaż w sklepie'], $kp);
$check($s['result'] === 'warn' && (int) $q('SELECT COUNT(*) FROM n_nc') === $ncBefore && $q('SELECT qc_status FROM n_prod_output WHERE id = 3') === 'ok', 'deviation accepted without NC: warn, no NC created');
$check($code(fn() => $round->saveControl(3, ['weights' => [60, 60, 60], 'appearance' => 'bad', 'nc' => ['create' => true, 'description' => '']], $kp)) === 'VALIDATION_FAILED:422', 'NC needs a description');
$s = $round->saveControl(3, ['weights' => [60, 60, 60], 'appearance' => 'bad', 'nc' => ['create' => true, 'description' => 'Croissanty spalone', 'severity' => 'major']], $kp);
$nc = $pdo->query('SELECT * FROM n_nc ORDER BY id DESC LIMIT 1')->fetch();
$o3 = $pdo->query('SELECT * FROM n_prod_output WHERE id = 3')->fetch();
$check($s['result'] === 'nc' && $nc['source'] === 'production' && (int) $nc['id_final_product'] === 22 && (int) $nc['id_prod_group'] === 3 && $nc['severity'] === 'major'
    && str_contains($nc['description'], 'Croissanty spalone') && $o3['qc_status'] === 'nc' && (int) $o3['id_nc'] === (int) $nc['id'], 'NC accepted: in the register (production, group, product), batch QC = nc');
$check(array_column($s['round']['rows'], 'status', 'id')[3] === 'nc' && array_column($s['round']['rows'], 'status', 'id')[5] === 'urgent', 'our NC is not a group signal; group report still urgent');

// NC du groupe : notre contrôle ne l'écrase pas, la partie n'est plus « pilne ».
$round->saveControl(2, ['weights' => [401, 399, 402], 'appearance' => 'good'], $kp);
$o2 = $pdo->query('SELECT qc_status, qc_note FROM n_prod_output WHERE id = 2')->fetch();
$check($o2['qc_status'] === 'nc' && str_contains((string) $o2['qc_note'], '14 °C') && array_column($round->round($kp)['rows'], 'status', 'id')[2] === 'ok',
    'group NC kept on the batch; checked after the signal → no longer urgent');

// --- K. Suivi
$pdo->exec("INSERT INTO customer VALUES (4, 'Sklep nr 4'), (7, 'Sklep nr 7')");
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$pdo->exec("INSERT INTO palets_delivery (id_palet, id_customer, delivery_date, status) VALUES (31, 4, '{$tomorrow}', 3), (32, 7, '{$tomorrow}', 2), (33, 7, '{$tomorrow}', 1)");
$ins = $pdo->prepare('INSERT INTO carton_stock (id_product, status, date_add) VALUES (?, ?, ?)');
$con = $pdo->prepare('INSERT INTO carton_pallet_connection (id_carton, id_palet) VALUES (?, ?)');
for ($i = 0; $i < 3; $i++) { $ins->execute([20, 3, $today]); $con->execute([(int) $pdo->lastInsertId(), 31]); }
for ($i = 0; $i < 2; $i++) { $ins->execute([20, 2, $today]); $con->execute([(int) $pdo->lastInsertId(), 32]); }
for ($i = 0; $i < 4; $i++) $ins->execute([20, 1, $today]);
$ins->execute([20, 1, date('Y-m-d', strtotime('-9 days'))]);                                    // autre partie : hors fenêtre
$cartonId = (int) $q("SELECT MIN(id_carton) FROM carton_stock WHERE id_product = 20 AND status = 1 AND date_add = '{$today}'");
$pdo->exec("INSERT INTO document_wdt VALUES (900, 'WDT/11/2026/0042', 4, '{$tomorrow}')");
$pdo->exec('INSERT INTO document_wdt_product (id_document_wdt, id_product) VALUES (900, 20)');
$sc = $trace->scan(strtolower("{$d6}-piek-02"), $kp);
$check($sc['kind'] === 'lot' && $sc['lot']['id'] === 1, 'scan: batch code (case-insensitive) → batch');
$sc = $trace->scan('CARTON ' . $cartonId, $kp);
$lot = $sc['lot'];
$mats = array_column($lot['materials'], null, 'name');
$dest = array_map(fn($d) => $d['kind'] . ':' . ($d['customer'] ?? '-') . ':' . $d['cartons'] . ':' . ($d['wdt_date'] ?? '-'), $lot['destinations']);
$check($sc['kind'] === 'carton' && $sc['carton']['label_day'] === $today && $lot['id'] === 1 && $lot['cartons'] === ['total' => 9, 'shipped' => 3, 'pallet' => 2, 'stock' => 4],
    'scan: carton label → its batch, cartons of the label window counted');
$check($mats['Drożdże']['lot_number'] === 'D-2211' && $mats['Drożdże']['supplier'] === 'Młyn Testowy' && $mats['Woda']['state'] === 'untracked' && $mats['Sól']['supplier'] === 'Solny Test',
    'trace: materials with lot, supplier, untracked water');
$check($dest === ["shipped:Sklep nr 4:3:{$tomorrow}", 'pallet:Sklep nr 7:2:-', 'stock:-:4:-'], 'trace: destinations customer + WDT, pallet, stock (' . implode(' | ', $dest) . ')');
$check($lot['control']['result'] === 'ok', 'trace: last control');
$out(7, 1, 1, "{$d6}-PIEK-03", 500, '11:00');
$sc = $trace->scan((string) $cartonId, $kp);
$check($sc['lot'] === null && array_column($sc['choices'], 'id') === [7, 1], 'scan: carton with two possible batches → choice');
$check($code(fn() => $trace->scan('999999', $kp)) === 'QC_TRACE_UNKNOWN_CODE:404', 'unknown code: 404');
$pdf = $trace->pdf(1, $kp);
$check(str_starts_with($pdf['content'], '%PDF') && $pdf['filename'] === "partia-{$d6}-PIEK-02.pdf", 'batch PDF');

// --- Blocage
$cartonsBefore = $q('SELECT MD5(GROUP_CONCAT(CONCAT_WS(",", id_carton, id_product, status, date_add, IFNULL(date_pallet, "")) ORDER BY id_carton)) FROM carton_stock');
$check($code(fn() => $trace->hold(1, ['reason' => ''], $kp)) === 'VALIDATION_FAILED:422', 'hold needs a reason');
$lot = $trace->hold(1, ['reason' => 'Reklamacja klienta : ciało obce'], $kp);
$h = $pdo->query('SELECT * FROM n_qc_hold')->fetch();
$check($lot['hold']['active'] === true && (int) $h['id_final_product'] === 20 && $h['label_from'] === $today && $h['label_to'] === $tomorrow && (int) $h['held_by_employee'] === 10,
    'hold recorded: product + label days (production day → + trace.label_days), person');
$trace->hold(1, ['reason' => 'drugi raz'], $kp);
$check((int) $q('SELECT COUNT(*) FROM n_qc_hold') === 1, 'hold twice: one hold');
$check($q('SELECT MD5(GROUP_CONCAT(CONCAT_WS(",", id_carton, id_product, status, date_add, IFNULL(date_pallet, "")) ORDER BY id_carton)) FROM carton_stock') === $cartonsBefore,
    'hold does not touch carton_stock');
$check(($holds->heldStock([20, 22])[20][0]['cartons'] ?? null) === 6 && !isset($holds->heldStock([20, 22])[22]), 'palletization: held product with 6 cartons in stock / on pallet');
$check(count($holds->onPallet(32, 7, $tomorrow)) === 1 && $holds->onPallet(33, 7, $tomorrow) === [] && count($holds->onPallet(null, 7, $tomorrow)) === 1, 'loading: pallet with held cartons found (named pallet or customer day)');
$rowsHeld = array_column($round->round($kp)['rows'], 'hold', 'id');
$check($rowsHeld[1]['active'] === true && $rowsHeld[3] === null, 'round shows the hold');
// Palettisation : refus sans confirmation (le contrôle du blocage passe avant l'écriture).
$pdo->exec("INSERT INTO customer_order (id, id_customer, expected_shipping_date, status) VALUES (1, 4, '{$today} 06:00:00', 1)");
$pdo->exec('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (1, 20, 10)');
$packing = $di->get(PackingController::class);
$req = fn(array $body) => new ApiRequest(Request::create('/x', 'POST', [], [], [], [], json_encode($body)), [], $ctx(10));
$check($code(fn() => $packing->addPallet($req(['date' => $today, 'customer_id' => 4, 'product_id' => 20, 'cartons' => 2]))) === 'PALLET_PRODUCT_HELD:409', 'palletization refused for a held product (409)');
$src = (string) file_get_contents(__DIR__ . '/../App/Services/Logistics/DriverService.php');
$check(str_contains($src, "new ApiException('LOT_HELD_ON_PALLET', 409") && str_contains($src, '$this->holds->onPallet('), 'driver loading check refuses a pallet with held cartons');
// Levée
$check($code(fn() => $trace->release((int) $h['id'], ['note' => ''], $kp)) === 'VALIDATION_FAILED:422', 'release needs a note');
$lot = $trace->release((int) $h['id'], ['note' => 'Decyzja Jakości : partia zgodna'], $ctx(11));
$check($lot['hold'] === null && (int) $q('SELECT released_by_employee FROM n_qc_hold') === 11 && $holds->heldStock([20]) === [], 'released by Jakość: no more blocking');
$check($code(fn() => $trace->release((int) $h['id'], ['note' => 'jeszcze raz'], $kp)) === 'QC_HOLD_RELEASED:409', 'release twice: 409');

// --- Noyau de l'API : appareil + jeton de la personne, poste vérifié
$kernel = $di->get(WorkshopApiKernel::class);
$jwt = new Jwt(str_repeat('q', 48));
$token = fn(int $emp) => $jwt->encode(['iss' => KioskAuthService::AUDIENCE, 'aud' => KioskAuthService::AUDIENCE, 'sub' => (string) $emp, 'dev' => 1, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 120, 'jti' => 'x']);
$call = function (string $method, string $path, ?int $emp) use ($kernel, $token) {
    $r = Request::create(WorkshopApiRoutes::PREFIX . $path, $method);
    $r->headers->set('X-Device-Token', 'dev-quality-token');
    if ($emp !== null) $r->headers->set('X-Employee-Token', $token($emp));
    $res = $kernel->handle($r, WorkshopApiRoutes::PREFIX . parse_url($path, PHP_URL_PATH));
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};
[$st, $body] = $call('GET', '/kiosk/quality-round/people', null);
$check($st === 200 && count($body['data']) === 2, 'API: people with the device token');
[$st, $body] = $call('GET', '/kiosk/quality-round', 10);
$check($st === 200 && $body['data']['employee']['position'] === 'KIEROWNIK_PRODUKCJI' && count($body['data']['rows']) === 6, 'API: round for the manager');
[$st, $body] = $call('GET', '/kiosk/quality-round', 12);
$check($st === 403 && $body['description'] === 'QC_ROUND_POSITION' && str_contains($body['message'], 'Kierownik'), 'API: baker refused with a Polish message');
[$st, $body] = $call('GET', '/kiosk/quality-round/trace?code=' . $d6 . '-PIEK-02', 11);
$check($st === 200 && $body['data']['lot']['lot_code'] === "{$d6}-PIEK-02", 'API: trace for Jakość');

// --- Enregistrement : routes, OpenAPI, traductions, page
$routes = array_map(fn($r) => $r[0] . ' ' . $r[1] . ' ' . $r[4], WorkshopApiRoutes::routes());
$check(in_array('GET /kiosk/quality-round/people kiosk', $routes, true) && in_array('POST /kiosk/quality-round/trace/{id:\d+}/hold kiosk_employee', $routes, true)
    && count(array_filter($routes, fn($r) => str_contains($r, '/kiosk/quality-round'))) === 13, '13 API routes (people with the device, others with the person)');
$spec = App\Core\Api\Workshop\OpenApiSpec::build('https://erp.test');
$check(isset($spec['paths']['/kiosk/quality-round/batches/{id}/controls']['post'], $spec['components']['schemas']['QualityRound']), 'OpenAPI documents the tablet');
$pl = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/ui/pl/quality.json'), true);
$fr = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/ui/fr/quality.json'), true);
$check(is_array($pl) && array_keys($pl) === array_keys($fr), 'UI labels: same keys PL / FR');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/js/workshop/quality.js');
preg_match_all("/\\bt\\('([a-z_]+)'\\s*[,)]/", $js, $mk);
$missingKeys = array_diff(array_unique($mk[1]), array_keys($pl));
$check(!$missingKeys, 'every label used by quality.js exists (' . implode(', ', $missingKeys) . ')');
$ui = (string) file_get_contents(__DIR__ . '/../App/Core/Routes/Workshop/WorkshopUiRoutes.php');
$app = (string) file_get_contents(__DIR__ . '/../App/Core/App.php');
$check(str_contains($ui, "'/tablet/quality'") && str_contains($app, "'/tablet/quality'") && is_file(__DIR__ . '/../App/Views/workshop/quality.twig'), 'tablet page /tablet/quality (shell without ERP session)');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/css/quality.css');
$check(str_contains($css, 'Gotham_Black.otf') && str_contains($css, '--accent: #8D1D2C') && !preg_match('/box-shadow/', $css) && !preg_match('/border-radius:\s*999/', $css), 'design system: Gotham 700 = Black, tokens, no shadow, no pill');

if (getenv('QT_KEEP') !== '1') $pdo->exec("DROP DATABASE IF EXISTS {$dbName}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
