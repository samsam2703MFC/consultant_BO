<?php
/**
 * [432] Obchód chłodni przed zakupami (makieta « Obchód chłodni ») : migration 255 rejouée deux fois (tables, réglage
 * cold_visit.valid_hours une fois, sorte HACCP cold_clean une fois, aucune clé étrangère) ; miejsca = chłodnie / mroźnie actives
 * du rejestr (n_equipment) + miejsce ajoutée dans l'ERP (Magazyn suchy), retrait seulement pour une miejsce ajoutée ; poste
 * Kierownik produkcji / Jakość seulement (403 QC_ROUND_POSITION, aussi par le noyau de l'API) ; surowce rattachés (ajout,
 * doublon, retrait, recherche) ; « W ERP » = stan de Zaopatrzenie (inwentaryzacja + przyjęcia − zużycie z receptur),
 * partia bieżąca (FEFO), « też w » ; « Zapisz chłodnię » : tout compté (422), porządek « Nie » sans zdjęcie (422), strata
 * > policzone ou sans powód (422) ; inventaire n_mp_count = policzone − strata, additionné sur les miejsca du surowiec et
 * écrit quand la dernière est zapisana ; stan ERP après = la quantité réelle ; obchód complet quand toutes les miejsca sont
 * zapisane ; nouveau zapis = nouveau obchód (stan ERP d'avant gardé dans un même obchód) ; fenêtre de validité (24 h,
 * réglage 48 h) : « Co kupić » fermé / ouvert, BuyService::create 409 COLD_VISIT_REQUIRED ; résumé ERP (różnice, krótka
 * data, uszkodzone, porządek) ; HACCP miesiąca : porządek « Nie » = élément cold_clean avec photo, décision enregistrée ;
 * enregistrement (routes, OpenAPI, libellés PL / FR, TaskHowTo buy_sent).
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_cold_visit_test recréée puis supprimée (CV_KEEP=1 : gardée, pour les captures). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\{ApiContext, ApiException, Jwt, Translator, WorkshopApiKernel, WorkshopConfig};
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Repositories\Workshop\HaccpMonthRepository;
use App\Services\Organization\TaskHowTo;
use App\Services\Organization\Wizards\HaccpMonthService;
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Quality\ColdVisitService;
use App\Services\Workshop\Supply\{BuyService, SupplyService};
use Symfony\Component\HttpFoundation\Request;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$dbName = 'erp_cold_visit_test';
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
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), kind VARCHAR(20) DEFAULT 'erp', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
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
    'CREATE TABLE n_prod_lot_photo (id BIGINT AUTO_INCREMENT PRIMARY KEY, mime VARCHAR(32) NOT NULL, bytes INT NOT NULL, sha256 CHAR(64) NOT NULL, image MEDIUMBLOB NOT NULL, id_device INT NULL,
        id_employee INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE category_material (id INT PRIMARY KEY, id_group INT NULL, name VARCHAR(60) NULL)',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(120) NULL)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(100), name_fr VARCHAR(100) NULL, id_unit INT NULL, id_category INT NULL, price_per_unit DECIMAL(12,6) NULL,
        package_size_in_base_unit DECIMAL(14,3) NULL, id_current_supplier INT NULL, waste_perc DECIMAL(5,2) NULL)',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(100), id_recipe INT NULL, ppc INT NULL, active TINYINT DEFAULT 1)',
    'CREATE TABLE flattened_recipe_ingredient (recipe_id INT, material_id INT, quantity DECIMAL(14,3))',
    "CREATE TABLE carton_stock (id_carton INT AUTO_INCREMENT PRIMARY KEY, id_product INT, status TINYINT, date_add DATE)",
    'CREATE TABLE n_mp_count (id INT AUTO_INCREMENT PRIMARY KEY, id_raw_material INT NOT NULL, count_date DATE NOT NULL, quantity DECIMAL(14,3) NOT NULL, note VARCHAR(255) NULL,
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    "CREATE TABLE n_po (id INT AUTO_INCREMENT PRIMARY KEY, id_supplier INT, status VARCHAR(16) DEFAULT 'received')",
    'CREATE TABLE n_po_line (id INT AUTO_INCREMENT PRIMARY KEY, id_po INT, id_raw_material INT, quantity DECIMAL(14,3) NULL)',
    'CREATE TABLE n_po_receipt (id INT AUTO_INCREMENT PRIMARY KEY, id_po_line INT, received_on DATE, quantity DECIMAL(14,3), lot_number VARCHAR(64) NULL, best_before DATE NULL)',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL, done_at DATETIME NOT NULL, UNIQUE KEY (code, day))",
] as $sql) $pdo->exec($sql);
// Tables d'origine exactement comme leurs migrations les créent : rejestr urządzeń (245), partie surowców (209), odpad (183), revue HACCP (228).
$creates = function (string $file, array $only) use ($pdo) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $file)) as $sql) {
        if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m) && in_array($m[1], $only, true)) $pdo->exec($sql);
    }
};
$creates('245_maintenance.sql', ['n_equipment']);
$creates('209_material_lots_samples.sql', ['n_material_lot']);
$creates('183_raw_material_loss_n.sql', ['n_raw_material_loss']);
$creates('228_haccp_month.sql', ['n_haccp_month_review']);
$pdo->exec("ALTER TABLE n_haccp_month_review MODIFY kind ENUM('haccp_event','sop_review','sop_run','delivery_temp','hala_chill','hala_clean') NOT NULL");   // état après 248

// Rejestr urządzeń : deux chłodnie actives, une mroźnia retirée, une machine.
$pdo->exec("INSERT INTO n_equipment (id, name, kind, location, active) VALUES (1, 'Chłodnia 1 — nabiał', 'cold', 'Hala A', 1), (2, 'Chłodnia 2 — owoce i nadzienia', 'cold', NULL, 1),
    (3, 'Mroźnia stara', 'cold', NULL, 0), (4, 'Piec obrotowy', 'machine', NULL, 1)");

$migrate = function () use ($pdo) { foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/255_cold_visit.sql')) as $sql) $pdo->exec($sql); };
$migrate();
$migrate();

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$code = function (callable $f): string { try { $f(); return 'none'; } catch (ApiException $e) { return $e->errorCode() . ':' . $e->httpStatus(); } };
$q = fn(string $sql) => $pdo->query($sql)->fetchColumn();

// --- Migration 255 (deux passages)
$tables = ['n_cold_place', 'n_cold_place_material', 'n_cold_visit', 'n_cold_visit_place', 'n_cold_visit_line'];
$check((int) $q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("','", $tables) . "')") === 5
    && (int) $q("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n\\_cold\\_%'") === 0, 'migration 255 replayed: 5 tables, no foreign key');
$check($q("SELECT GROUP_CONCAT(value) FROM n_prod_param WHERE code = 'cold_visit.valid_hours'") === '24', 'migration 255 replayed: cold_visit.valid_hours = 24, once');
$kind = (string) $q("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_haccp_month_review' AND COLUMN_NAME = 'kind'");
$check(substr_count($kind, "'cold_clean'") === 1 && str_contains($kind, "'hala_clean'"), 'migration 255 replayed: HACCP kind cold_clean added once (' . $kind . ')');
$check((int) $q('SELECT COUNT(*) FROM n_cold_place') === 3 && (int) $q('SELECT COUNT(*) FROM n_cold_place WHERE id_equipment = 4') === 0, 'migration 255: one place per cold equipment (machine excluded), once');
$check(App\Domain\Params\ParamRules::RANGES['cold_visit.valid_hours'] === [1, 168, true], 'ParamRules bounds for cold_visit.valid_hours');

// --- Personnes, appareil, surowce, stan de Zaopatrzenie
$now = time();
$today = date('Y-m-d', $now);
$t0 = date('Y-m-d H:i:s', $now - 3600);                 // début du obchód : il y a une heure
$at = fn(int $min) => date('Y-m-d H:i:s', strtotime($t0) + $min * 60);
$pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'kp', 'Anna Kierownik')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 1), (2, 'JAKOSC', 'Specjalista ds. jakości', 2), (3, 'PIEKARZ', 'Piekarz', 3)");
$pdo->exec("INSERT INTO production_employee (id, name, surname) VALUES (10, 'Anna', 'Kierownik'), (11, 'Ewa', 'Jakość'), (12, 'Jan', 'Piekarz')");
$pdo->exec('INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, id_position) VALUES (10, 10, NULL, 1), (11, 11, NULL, 2), (12, 12, 1, 3)');
$pdo->exec("INSERT INTO n_prod_employee_credential (id_employee, pin_hash) VALUES (10, '" . password_hash('2580', PASSWORD_BCRYPT) . "'), (12, '" . password_hash('1111', PASSWORD_BCRYPT) . "')");
$pdo->exec("INSERT INTO n_prod_device (id, name, id_group, active, token_hash, mode) VALUES (1, 'Tablet jakości', NULL, 1, '" . hash('sha256', 'dev-cold-token') . "', 'atelier')");
$pdo->exec("INSERT INTO unit VALUES (1, 'g'), (2, 'ml'), (3, 'szt')");
$pdo->exec("INSERT INTO supplier VALUES (7, 'Mleczarnia', NULL)");
$pdo->exec("INSERT INTO raw_material (id, name, name_fr, id_unit) VALUES (100, 'Masło 82 % (blok 10 kg)', 'Beurre 82 %', 1), (101, 'Śmietanka 36 %', NULL, 2),
    (102, 'Maliny mrożone', 'Framboises surgelées', 1), (103, 'Jogurt naturalny', NULL, 1), (104, 'Mąka T65', NULL, 1), (105, 'Masa makowa', NULL, 1)");
$d = fn(int $days) => date('Y-m-d', strtotime($today . ' ' . $days . ' days'));
// Masło : inwentaryzacja 30 kg il y a 5 jours, + 10 kg reçus, − 6 kartonów × 1 kg (recette) → 34 kg.
$pdo->exec("INSERT INTO n_mp_count (id_raw_material, count_date, quantity) VALUES (100, '{$d(-5)}', 30000), (102, '{$d(-1)}', 18000), (103, '{$d(-2)}', 10000)");
$pdo->exec("INSERT INTO n_po (id, id_supplier) VALUES (1, 7)");
$pdo->exec("INSERT INTO n_po_line (id, id_po, id_raw_material) VALUES (1, 1, 100), (2, 1, 101)");
$pdo->exec("INSERT INTO n_po_receipt (id_po_line, received_on, quantity) VALUES (1, '{$d(-3)}', 10000), (2, '{$d(-2)}', 24000)");
$pdo->exec("INSERT INTO final_product (id, name, id_recipe, ppc) VALUES (20, 'Croissant', 501, 40)");
$pdo->exec('INSERT INTO flattened_recipe_ingredient VALUES (501, 100, 1000)');
for ($i = 0; $i < 6; $i++) $pdo->exec("INSERT INTO carton_stock (id_product, status, date_add) VALUES (20, 1, '{$d(-1)}')");
$pdo->exec("INSERT INTO n_material_lot (id_raw_material, lot_number, best_before, source, received_on, active) VALUES
    (100, 'M-2211', '" . $d(18) . "', 'receipt', '{$d(-3)}', 1), (100, 'M-1999', '" . $d(40) . "', 'receipt', '{$d(-10)}', 1), (101, 'S-0871', '" . $d(5) . "', 'receipt', '{$d(-2)}', 1),
    (102, 'R-0450', NULL, 'manual', '{$d(-20)}', 1)");

// --- Conteneur (PHP-DI) sur cette base
$b = new DI\ContainerBuilder();
// Aucun e-mail : le transport du test refuse tout (Zaopatrzenie en dépend, rien n'est envoyé ici).
$b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('c', 48)]),
    App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
    }]);
$di = $b->build();
/** @var ColdVisitService $cold */
$cold = $di->get(ColdVisitService::class);
$tr = new Translator('pl');
$ctx = fn(int $emp) => new ApiContext('test', $tr, null, [], ['prod.kiosk' => true], [], deviceId: 1, employeeId: $emp, deviceGroupId: null);
$kp = $ctx(10);
$erp = new ApiContext('erp', $tr, 1, [], ['prod.supply.write' => true, 'prod.supply.read' => true]);
$levels = $di->get(SupplyService::class)->stockLevels([100, 101, 102, 103, 104]);
$check($levels[100]['stock'] === 34000.0 && $levels[101]['stock'] === 24000.0 && $levels[101]['basis'] === 'receipts' && $levels[102]['stock'] === 18000.0 && $levels[104] === null,
    'stock of Zaopatrzenie: count + receipts − recipe consumption (34 kg), receipts only, unknown');

// --- Poste : Kierownik produkcji / Jakość seulement
$check($code(fn() => $cold->home($ctx(12))) === 'QC_ROUND_POSITION:403' && $code(fn() => $cold->place(1, $ctx(12))) === 'QC_ROUND_POSITION:403'
    && $code(fn() => $cold->save(1, [], $ctx(12))) === 'QC_ROUND_POSITION:403', 'a baker cannot open the cold-room round (403 QC_ROUND_POSITION)');

// --- Miejsca : rejestr + miejsce ajoutée dans l'ERP
$s = $cold->addPlace(['name' => 'Magazyn suchy'], $erp);
$check($code(fn() => $cold->addPlace(['name' => 'magazyn SUCHY'], $erp)) === 'VALIDATION_FAILED:422' && $code(fn() => $cold->addPlace(['name' => 'x'], $erp)) === 'VALIDATION_FAILED:422',
    'extra place: duplicate name and too short refused');
$names = array_column($s['places'], 'name');
$check($names === ['Chłodnia 1 — nabiał', 'Chłodnia 2 — owoce i nadzienia', 'Magazyn suchy'] && array_column($s['places'], 'source') === ['equipment', 'equipment', 'extra']
    && $s['places'][2]['kind'] === 'dry', 'places = active cold equipment (inactive excluded) + extra dry store');
$pdo->exec("INSERT INTO n_equipment (id, name, kind, active) VALUES (5, 'Lodówka — cukiernia', 'cold', 1)");
$h = $cold->home($kp);
$check(array_column($h['places'], 'name') === ['Chłodnia 1 — nabiał', 'Chłodnia 2 — owoce i nadzienia', 'Lodówka — cukiernia', 'Magazyn suchy']
    && array_column($h['places'], 'status') === ['now', 'later', 'later', 'later'] && $h['to_do'] === 4 && $h['visit'] === null, 'new cold equipment of the register appears; first place « now »');
$pdo->exec('UPDATE n_equipment SET active = 0 WHERE id = 5');
$ids = array_column($cold->home($kp)['places'], 'id', 'name');
[$c1, $c2, $dry] = [$ids['Chłodnia 1 — nabiał'], $ids['Chłodnia 2 — owoce i nadzienia'], $ids['Magazyn suchy']];
$check(count($ids) === 3, 'equipment withdrawn from the register: place no longer listed');

// --- Surowce rattachés (appris au premier obchód)
$cold->assign($c1, 103, $kp);
$cold->assign($c1, 100, $kp);
$cold->assign($c1, 100, $kp);
foreach ([101, 102, 100, 105] as $m) $cold->assign($c2, $m, $kp);
$cold->assign($dry, 104, $kp);
$p2 = $cold->unassign($c2, 105, $kp);
$check((int) $q('SELECT COUNT(*) FROM n_cold_place_material') === 6 && count($p2['rows']) === 3, 'assignment: once per place, removable (masa makowa removed)');
$check($code(fn() => $cold->assign($c1, 999, $kp)) === 'NOT_FOUND:404' && $code(fn() => $cold->assign(9999, 100, $kp)) === 'NOT_FOUND:404', 'unknown material / place: 404');
$found = $cold->search('masł', $kp);
$check(count($found) === 1 && $found[0]['id'] === 100 && $found[0]['unit'] === 'kg' && $cold->search('m', $kp) === [], 'search a material (2 letters at least), display unit');
$rows = array_column($p2['rows'], null, 'material_id');
$check($rows[100]['erp'] === 34000.0 && $rows[100]['unit'] === 'kg' && $rows[100]['factor'] === 1000 && $rows[100]['lot']['lot_number'] === 'M-2211' && $rows[100]['also_in'] === ['Chłodnia 1 — nabiał']
    && $rows[101]['unit'] === 'l' && $rows[102]['lot'] === ['lot_number' => 'R-0450', 'best_before' => null], 'place: ERP stock, current lot (shortest date first), also in, display units');
$frCtx = new ApiContext('t', new Translator('fr'), null, [], ['prod.kiosk' => true], [], deviceId: 1, employeeId: 10, deviceGroupId: null);
$frRows = array_column($cold->place($c2, $frCtx)['rows'], 'name', 'material_id');
$check($frRows[102] === 'Framboises surgelées' && $frRows[101] === 'Śmietanka 36 %', 'French names when present');

// --- « Zapisz chłodnię » : validation
$line = fn(int $m, float $qty, ?string $date = null, ?array $loss = null) => ['material_id' => $m, 'quantity' => $qty, 'date_state' => $date, 'loss' => $loss];
$ok2 = [$line(100, 20000, 'ok'), $line(101, 24000, 'short'), $line(102, 12000, null, ['kind' => 'damaged', 'quantity' => 2000, 'reason' => 'Uszkodzone opakowanie'])];
$check($code(fn() => $cold->save($c2, ['lines' => array_slice($ok2, 0, 2), 'clean' => 'yes'], $kp, $at(0))) === 'COLD_VISIT_NOT_COUNTED:422', 'every material must be counted');
$check($code(fn() => $cold->save($c2, ['lines' => $ok2, 'clean' => 'no'], $kp, $at(0))) === 'COLD_VISIT_PHOTO_REQUIRED:422', 'not clean without photo: refused');
$bad = $ok2; $bad[2]['loss']['quantity'] = 13000;
$check($code(fn() => $cold->save($c2, ['lines' => $bad, 'clean' => 'yes'], $kp, $at(0))) === 'VALIDATION_FAILED:422', 'loss larger than counted: refused');
$bad = $ok2; $bad[2]['loss']['reason'] = '';
$check($code(fn() => $cold->save($c2, ['lines' => $bad, 'clean' => 'yes'], $kp, $at(0))) === 'VALIDATION_FAILED:422', 'loss without reason: refused');
$check($code(fn() => $cold->save($c2, ['lines' => [...$ok2, $line(104, 1)], 'clean' => 'yes'], $kp, $at(0))) === 'VALIDATION_FAILED:422', 'material of another place: refused');
$check((int) $q('SELECT COUNT(*) FROM n_cold_visit') === 0 && (int) $q('SELECT COUNT(*) FROM n_mp_count') === 3, 'refused saves write nothing');

// --- Chłodnia 2 : masło aussi en Chłodnia 1 → son inventaire attend la deuxième miejsce
$buy = $di->get(BuyService::class);
$check($cold->blocked($at(0)) === true && $code(fn() => $buy->create(['week_start' => $d(7), 'lines' => [['material_id' => 100, 'quantity' => 1000]]], $erp)) === 'COLD_VISIT_REQUIRED:409',
    '« Co kupić » blocked without a cold-room round (create : 409 COLD_VISIT_REQUIRED)');
$h = $cold->save($c2, ['lines' => $ok2, 'clean' => 'yes'], $kp, $at(0));
$counts = array_column($pdo->query("SELECT id_raw_material, quantity, note FROM n_mp_count WHERE count_date = '{$today}'")->fetchAll(), null, 'id_raw_material');
$check(isset($counts[101], $counts[102]) && !isset($counts[100]) && (float) $counts[101]['quantity'] === 24000.0 && (float) $counts[102]['quantity'] === 10000.0
    && str_contains($counts[102]['note'], 'Obchód chłodni') && str_contains($counts[102]['note'], 'strata 2 kg'), 'counts written as n_mp_count (counted − damaged = 10 kg) ; butter waits for its second place');
$st = array_column($h['places'], 'status', 'id');
$check($st[$c2] === 'done' && $st[$c1] === 'now' && $st[$dry] === 'later' && $h['visit']['state'] === 'open' && $h['places'][1]['saved']['differences'] === 2,
    'home after the first place: done / now / later, 2 differences (butter 20 vs 34 kg, raspberries 12 vs 18 kg)');
$p = $cold->place($c2, $kp, $at(1));
$r2 = array_column($p['rows'], null, 'material_id');
$check($r2[102]['erp'] === 18000.0 && $r2[102]['saved']['loss']['kind'] === 'damaged' && $r2[101]['saved']['date_state'] === 'short' && $p['saved']['clean'] === 'yes',
    'place re-opened: ERP stock from before the round, saved lines');
$sum = $cold->summary('pl', $at(1));
$check($sum['blocked'] === true && $sum['open']['saved'] === 1 && $sum['open']['places'] === 3 && $sum['last'] === null, 'ERP: round in progress (1 of 3), still blocked');

// --- Chłodnia 1 : porządek « Nie » + photo
$png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 120));
$photo = $di->get(App\Services\Workshop\Quality\QualityRoundService::class)->photo(['photo' => $png], $kp);
$cold->save($c1, ['lines' => [$line(100, 10000, 'ok'), $line(103, 14000)], 'clean' => 'no', 'photo_id' => $photo['id'], 'note' => 'Rozlany jogurt na półce'], $kp, $at(10));
$c100 = $pdo->query("SELECT quantity, note FROM n_mp_count WHERE id_raw_material = 100 ORDER BY id DESC LIMIT 1")->fetch();
$check((float) $c100['quantity'] === 30000.0 && str_contains($c100['note'], 'Chłodnia 1') && str_contains($c100['note'], 'Chłodnia 2'), 'butter: one count = sum of its two places (20 + 10 kg)');
$lines100 = $pdo->query('SELECT count_qty, id_mp_count FROM n_cold_visit_line WHERE id_raw_material = 100')->fetchAll();
$check(count($lines100) === 2 && count(array_unique(array_column($lines100, 'id_mp_count'))) === 1 && (float) $lines100[0]['count_qty'] === 30000.0, 'both butter lines point to the same inventory count');
$check($cold->blocked($at(11)) === true, 'two places of three: still blocked');

// --- Magazyn suchy : obchód complet
$h = $cold->save($dry, ['lines' => [$line(104, 5000)], 'clean' => 'yes'], $kp, $at(20));
$v = $pdo->query('SELECT * FROM n_cold_visit')->fetch();
$check($v['completed_at'] === $at(20) && (int) $v['places'] === 3 && (int) $v['materials'] === 5 && (int) $v['differences'] === 3 && (int) $v['id_employee'] === 10,
    'round complete when every place is saved (3 places, 5 materials, 3 differences)');
$check($h['visit']['state'] === 'complete' && $h['to_do'] === 0 && $h['next_id'] === null, 'tablet: round done');
$levels = $di->get(SupplyService::class)->stockLevels([100, 102, 103, 104]);
$check($levels[100]['stock'] === 30000.0 && $levels[102]['stock'] === 10000.0 && $levels[103]['stock'] === 14000.0 && $levels[104]['stock'] === 5000.0 && $levels[104]['basis'] === 'count',
    'ERP stock (and « Co kupić ») now start from the real quantities');

// --- Fenêtre de validité
$sum = $cold->summary('pl', $at(30));
$lines = array_column($sum['lines'], null, 'material_id');
$check($sum['valid'] && !$sum['blocked'] && $sum['last']['by'] === 'Anna Kierownik' && $sum['last']['places'] === 3 && $sum['last']['materials'] === 5 && $sum['unchanged'] === 1
    && array_keys($lines) === [103, 100, 102, 101], 'ERP summary: valid, who, places, materials ; lines with difference, short date or loss');
$check($lines[100]['erp'] === 34000.0 && $lines[100]['counted'] === 30000.0 && $lines[100]['difference'] === -4000.0 && $lines[100]['places'] === ['Chłodnia 1 — nabiał', 'Chłodnia 2 — owoce i nadzienia']
    && $lines[102]['difference'] === -6000.0 && $lines[102]['losses'][0]['kind'] === 'damaged' && $lines[102]['stock_after'] === 10000.0
    && $lines[101]['difference'] === 0.0 && $lines[101]['short']['best_before'] === $d(5) && $lines[103]['difference'] === 4000.0,
    'differences: butter −4 kg over two places, raspberries −6 kg (2 kg damaged, 10 kg left), cream short date, yoghurt +4 kg');
$check(count($sum['last']['dirty']) === 1 && $sum['last']['dirty'][0]['place'] === 'Chłodnia 1 — nabiał' && $sum['last']['dirty'][0]['photo_id'] === $photo['id'], 'ERP summary: place not clean, with its photo');
$done = $at(20);
$check($cold->blocked(date('Y-m-d H:i:s', strtotime($done) + 23 * 3600)) === false && $cold->blocked(date('Y-m-d H:i:s', strtotime($done) + 25 * 3600)) === true,
    'validity window 24 h: open after 23 h, blocked after 25 h');
$di->get(App\Repositories\Workshop\ParamRepository::class)->setValue('cold_visit.valid_hours', '48', 1);
$late = $cold->summary('pl', date('Y-m-d H:i:s', strtotime($done) + 25 * 3600));
$check($late['valid'] === true && $late['valid_hours'] === 48, 'setting 48 h: still open after 25 h');
$di->get(App\Repositories\Workshop\ParamRepository::class)->setValue('cold_visit.valid_hours', '24', 1);
$old = $cold->summary('pl', date('Y-m-d H:i:s', strtotime($done) + 7 * 86400));
$check($old['blocked'] && $old['last']['days_ago'] === 7 && $old['lines'] === $sum['lines'], 'week-old round: blocked, « 7 days ago »');
$e = $code(function () use ($buy, $d, $erp) {
    try { $buy->create(['week_start' => $d(7), 'lines' => [['material_id' => 100, 'quantity' => 1000]]], $erp); } catch (ApiException $e) { throw $e; } catch (Throwable) { /* au-delà de la porte : besoins de la semaine absents de cette base */ }
});
$check($e !== 'COLD_VISIT_REQUIRED:409', 'complete round within 24 h: « Co kupić » open (create passes the gate)');

// --- Nouveau zapis après un obchód complet = nouveau obchód ; stan ERP d'avant gardé dans ce obchód
$cold->save($c2, ['lines' => [$line(100, 19000), $line(101, 24000), $line(102, 10000)], 'clean' => 'yes'], $kp, $at(40));
$cold->save($c2, ['lines' => [$line(100, 18000), $line(101, 23000), $line(102, 10000)], 'clean' => 'yes'], $kp, $at(45));
$v2 = $pdo->query('SELECT * FROM n_cold_visit ORDER BY id DESC LIMIT 1')->fetch();
$l2 = array_column($pdo->query("SELECT l.* FROM n_cold_visit_line l JOIN n_cold_visit_place vp ON vp.id = l.id_visit_place WHERE vp.id_visit = {$v2['id']}")->fetchAll(), null, 'id_raw_material');
$check((int) $v2['id'] === 2 && $v2['completed_at'] === null && (int) $q("SELECT COUNT(*) FROM n_cold_visit_place WHERE id_visit = 2") === 1
    && (float) $l2[101]['erp_qty'] === 24000.0 && (float) $l2[101]['counted_qty'] === 23000.0 && $l2[100]['id_mp_count'] === null,
    'saved again after a complete round: new round ; same place saved twice = one record, ERP stock from before kept');
$check($cold->summary('pl', $at(50))['valid'] === true && $cold->home($kp, $at(50))['visit']['state'] === 'open', 'last complete round still opens « Co kupić » ; tablet shows the new round');

// --- Miejsca : retrait
$check($code(fn() => $cold->removePlace($c1, $erp)) === 'COLD_PLACE_EQUIPMENT:409', 'equipment place: removed in the register only (409)');
$s = $cold->removePlace($dry, $erp);
$check(array_column($s['places'], 'name') === ['Chłodnia 1 — nabiał', 'Chłodnia 2 — owoce i nadzienia'] && $code(fn() => $cold->removePlace($dry, $erp)) === 'NOT_FOUND:404', 'extra place removed (once)');

// --- HACCP miesiąca : porządek « Nie » + photo
$dirty = $di->get(HaccpMonthRepository::class)->coldDirty($today, $today);
$check(count($dirty) === 1 && $dirty[0]['note'] === 'Rozlany jogurt na półce' && (int) $dirty[0]['id_photo'] === $photo['id'], 'HACCP repository: one place not clean today, with photo and note');
/** @var HaccpMonthService $hm */
$hm = $di->get(HaccpMonthService::class);
$data = $hm->data($today);
$it = array_values(array_filter($data['review'], fn($i) => $i['kind'] === 'cold_clean'))[0] ?? null;
$check($it !== null && $it['signals'][0]['code'] === 'cold_dirty' && $it['title'] === 'Chłodnia 1 — nabiał' && $it['photo'] === '/workshop/traceability/photo/' . $photo['id']
    && $it['link']['href'] === '/workshop/buy' && $data['sources']['cold_clean']['available'] === true && $data['totals']['by_kind']['cold_clean'] === 1,
    'HACCP miesiąca: cold-room disorder is a signal (kind cold_clean, photo, link)');
$data = $hm->saveDecision(['key' => $it['key'], 'decision' => 'corrective', 'note' => 'Półka umyta, szkolenie zespołu'], 1, $today);
$check($q("SELECT kind FROM n_haccp_month_review") === 'cold_clean', 'HACCP decision stored with the new kind');

// --- Noyau de l'API : appareil + jeton de la personne, poste vérifié
$kernel = $di->get(WorkshopApiKernel::class);
$jwt = new Jwt(str_repeat('c', 48));
$token = fn(int $emp) => $jwt->encode(['iss' => KioskAuthService::AUDIENCE, 'aud' => KioskAuthService::AUDIENCE, 'sub' => (string) $emp, 'dev' => 1, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 120, 'jti' => 'x']);
$call = function (string $method, string $path, ?int $emp, ?array $body = null) use ($kernel, $token) {
    $r = Request::create(WorkshopApiRoutes::PREFIX . $path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body !== null ? json_encode($body) : null);
    $r->headers->set('X-Device-Token', 'dev-cold-token');
    if ($emp !== null) $r->headers->set('X-Employee-Token', $token($emp));
    $res = $kernel->handle($r, WorkshopApiRoutes::PREFIX . parse_url($path, PHP_URL_PATH));
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};
[$st, $body] = $call('GET', '/kiosk/cold-visit', 10);
$check($st === 200 && count($body['data']['places']) === 2 && $body['data']['employee']['position'] === 'KIEROWNIK_PRODUKCJI', 'API: cold-room round for the manager');
[$st, $body] = $call('GET', '/kiosk/cold-visit', 12);
$check($st === 403 && $body['description'] === 'QC_ROUND_POSITION', 'API: baker refused (403)');
[$st, $body] = $call('GET', '/kiosk/cold-visit/materials?q=jogu', 11);
$check($st === 200 && $body['data'][0]['id'] === 103, 'API: material search for Jakość');
[$st, $body] = $call('POST', '/kiosk/cold-visit/places/' . $c1, 10, ['lines' => [], 'clean' => 'yes']);
$check($st === 422 && $body['description'] === 'COLD_VISIT_NOT_COUNTED' && str_contains($body['message'], 'Policz'), 'API: Polish message when materials are not counted');

// --- Enregistrement : routes, OpenAPI, libellés, fiche de fonction
$routes = array_map(fn($r) => $r[0] . ' ' . $r[1] . ' ' . $r[4], WorkshopApiRoutes::routes());
$check(count(array_filter($routes, fn($r) => str_contains($r, '/kiosk/cold-visit') && str_ends_with($r, ' kiosk_employee'))) === 6
    && in_array('POST /supply/buy/cold-places prod.supply.write', $routes, true) && in_array('DELETE /supply/buy/cold-places/{id:\d+} prod.supply.write', $routes, true),
    '6 tablet routes (person on the tablet), 2 ERP routes (existing purchasing right)');
$spec = App\Core\Api\Workshop\OpenApiSpec::build('https://erp.test');
$check(isset($spec['paths']['/kiosk/cold-visit/places/{id}']['post'], $spec['components']['schemas']['ColdVisitSummary'], $spec['components']['schemas']['BuyWeek']['properties']['cold_visit']),
    'OpenAPI documents the round and the purchasing step');
foreach (['pl', 'fr'] as $lang) {
    $api = json_decode((string) file_get_contents(__DIR__ . "/../public/resources/lang/api/{$lang}/production.json"), true);
    $check(isset($api['errors']['COLD_VISIT_REQUIRED'], $api['errors']['COLD_VISIT_NOT_COUNTED'], $api['errors']['COLD_VISIT_PHOTO_REQUIRED'], $api['errors']['COLD_PLACE_EQUIPMENT'], $api['entities']['cold_place']),
        "API messages ({$lang})");
}
$sp = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/ui/pl/supply.json'), true);
$sf = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/ui/fr/supply.json'), true);
$bj = (string) file_get_contents(__DIR__ . '/../public/assets/js/workshop/buy.js');
preg_match_all("/\\bt\\('(buy\\.cold\\.[a-z0-9_.]+)'/", $bj, $mk);
$check(array_keys($sp) === array_keys($sf) && $sp['buy.step0'] === 'Obchód chłodni' && !array_diff(array_unique($mk[1]), array_keys($sp)) && str_contains($bj, 'data-cold-next'),
    'ERP step labels PL / FR (every buy.cold.* used by buy.js exists)');
$qj = (string) file_get_contents(__DIR__ . '/../public/assets/js/workshop/quality.js');
$check(str_contains($qj, "'/kiosk/cold-visit'") && str_contains($qj, 'data-cold') && str_contains($qj, "'/kiosk/quality-round/photos'"), 'tablet: entry button and photo upload of the quality tablet');
$how = TaskHowTo::HOWTO['buy_sent'];
$check(str_contains($how['pl'][0], 'obchód chłodni') && str_contains($how['pl'][1], '« Obchód chłodni »') && count($how['pl']) === 5 && count($how['fr']) === 5, 'job sheet: buy_sent starts with the cold-room round');

if (getenv('CV_KEEP') !== '1') $pdo->exec("DROP DATABASE IF EXISTS {$dbName}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
