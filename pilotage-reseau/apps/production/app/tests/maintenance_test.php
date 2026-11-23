<?php
/**
 * [405] Asystent « Serwisy i przeglądy maszyn » : registres de l'assistant (urządzenia, przeglądy, zgłoszenia) ; signaux
 * przegląd échu / proche (maintenance.alert_days) / sans terme, zgłoszenie ouvert après son termin / sans termin, arrêt
 * « awaria » de la tablette (n_prod_ws_state, depuis le début du mois écoulé, au moins maintenance.breakdown_min_minutes) sans
 * zgłoszenie lié ni sur la même machine (veille → 7 jours), machine active hors registre (stanowisko ręczne et machine inactive
 * exclus) ; actions (wykonanie avec prochain terme calculé, stan zgłoszenia, zgłoszenie depuis un arrêt, rejestr d'une machine)
 * → « Załatwione » seule ; « Umówione — termin » (date ; nouveau termin d'un zgłoszenie) ; « Nie dotyczy » (note, reprise) ;
 * validations ; « Zakończ » → n_wizard_done, état de la tâche ; mois suivant (reprises, rendez-vous passé) ; aucune écriture
 * dans les tables de l'ERP (checksums) ; contrôleur construit par PHP-DI et appelé (droit « maintenance » 200, sans 403) ;
 * aucun envoi ; enregistrement (catalogue, droits, routes, réglages, équipe) ; migration 245 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_maintenance_test recréée (tables minimales de l'ERP, migration 245), supprimée à la fin (MT_JSON_OUT=fichier :
 * données de la page avant les décisions). Données fictives.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Production\MaintenanceController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\{MaintenanceRepository, ParamRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\MaintenanceService as S;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_maintenance_test'); $pdo->exec('CREATE DATABASE erp_maintenance_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_maintenance_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE n_prod_group (id INT PRIMARY KEY, name_pl VARCHAR(60), color VARCHAR(10) NULL, sort_order INT DEFAULT 0)',
    "CREATE TABLE n_prod_workstation (id INT PRIMARY KEY, code VARCHAR(32), id_group INT, name_pl VARCHAR(100), name_fr VARCHAR(100), type ENUM('machine','four','robot','manuel','fermentation','froid','surgelation'),
        active TINYINT DEFAULT 1)",
    'CREATE TABLE n_prod_ws_state (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_workstation INT, state VARCHAR(8), reason VARCHAR(16) NULL, note VARCHAR(255) NULL, started_at DATETIME, ended_at DATETIME NULL)',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
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
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2), (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (8, 'ADMIN')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '10.1', NULL, 'Serwisy i przeglądy maszyn', 'monthly'),
    (2, '10.2', NULL, 'Serwisy i przeglądy maszyn', 'monthly'), (2, '10.3', NULL, 'Serwisy i przeglądy maszyn', 'monthly'), (2, '10.6', NULL, 'Serwisy i przeglądy maszyn', 'monthly'),
    (2, '10.4', NULL, NULL, 'none'), (2, '9.5', NULL, 'Badania i szkolenia (terminy)', 'monthly'), (4, '7', NULL, 'Serwisy i przeglądy maszyn', 'monthly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 7)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (4, 'clients')");
$m245 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/245_maintenance.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m245 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 245 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_column(array_slice($duties, 0, 4), 'wizard_code') === array_fill(0, 4, 'maintenance_reviewed') && array_column(array_slice($duties, 0, 4), 'proposed_wizard') === array_fill(0, 4, null)
    && $duties[4]['wizard_code'] === null && $duties[5]['proposed_wizard'] === 'Badania i szkolenia (terminy)' && $duties[6]['proposed_wizard'] === 'Serwisy i przeglądy maszyn',
    'migration 245 : KIEROWNIK_PRODUKCJI 10.1, 10.2, 10.3, 10.6 → maintenance_reviewed ; 10.4, other rows and positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'maintenance_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'monthly' && $tasks[0]['month_day'] === 'wd5' && $tasks[0]['link_path'] === '/maintenance'
    && (int) $tasks[0]['id_category'] === 1 && (int) $tasks[0]['sort_order'] === 8, 'one monthly task for Kierownik produkcji (category « Produkcja »)');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'maintenance,time', 4 => 'clients'], 'right « maintenance » for Kierownik produkcji only');
$params = $pdo->query("SELECT code, CONCAT(value, '|', value_type, '|', IFNULL(unit, '')) FROM n_prod_param WHERE code LIKE 'maintenance.%' ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($params === ['maintenance.alert_days' => '30|int|dni', 'maintenance.breakdown_min_minutes' => '15|int|min'] && ParamRules::RANGES['maintenance.alert_days'] === [1, 180, true]
    && ParamRules::RANGES['maintenance.breakdown_min_minutes'] === [0, 600, true], 'settings seeded once ; ParamRules bounds');
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'n_equipment%' OR TABLE_NAME = 'n_maintenance_review')")->fetchColumn();
$check($fks === 0 && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_equipment', 'n_equipment_check', 'n_equipment_ticket', 'n_maintenance_review')")->fetchColumn() === 4,
    '4 tables, no foreign key');
$check(S::addMonths('2026-01-31', 1) === '2026-02-28' && S::addMonths('2025-10-15', 12) === '2026-10-15' && S::addMonths('2024-02-29', 12) === '2025-02-28' && S::reviewMonth('2026-11-24') === '2026-11-01',
    'months added (end of month kept), review month');

$lw = new LightWizardRepository($pdo);
$make = fn() => new S(new MaintenanceRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';

// --- Sans données
$e = $svc->data($today);
$check($e['review'] === [] && $e['can_finish'] && $e['sources']['machines']['note'] === 'brak maszyn w ERP' && $e['sources']['stops']['note'] === 'brak postojów « awaria » zapisanych na tabletach od 01.10.2026'
    && $e['settings'] === ['alert_days' => 30, 'breakdown_min_minutes' => 15], 'no data : nothing to decide, sources explained');
$pdo->exec('RENAME TABLE n_prod_ws_state TO ws_x');
$check($make()->data($today)['sources']['stops']['note'] === 'brak danych — brak tabeli postojów TRS (tablet grupy)', 'missing OEE table → « brak danych »');
$pdo->exec('RENAME TABLE ws_x TO n_prod_ws_state');

// --- Données fictives : contrôle le mar. 24.11.2026.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO n_prod_group VALUES (1, 'Piekarnia', '#c00', 1)");
$pdo->exec("INSERT INTO n_prod_workstation VALUES (1, 'PIEC1', 1, 'Piec Testowy 1', 'Four 1', 'four', 1), (2, 'DZIEL', 1, 'Dzielarka Test', 'Diviseuse', 'machine', 1),
    (3, 'STOL', 1, 'Stół ręczny', 'Table', 'manuel', 1), (4, 'ROB', 1, 'Robot Test', 'Robot', 'robot', 0), (5, 'KOM', 1, 'Komora Test', 'Chambre', 'fermentation', 1)");
$pdo->exec("INSERT INTO n_equipment (id, name, kind, id_workstation, location) VALUES (1, 'Piec Testowy 1', 'machine', 1, 'hala A'), (2, 'Chłodnia 2', 'cold', NULL, 'magazyn'), (3, 'Waga 15 kg', 'scale', NULL, NULL)");
$pdo->exec("INSERT INTO n_equipment_check (id, id_equipment, kind, title, interval_months, last_done, next_due, provider) VALUES
    (1, 1, 'service', NULL, 12, '2025-10-15', '2026-10-15', 'Serwis Piecowy Test'), (2, 2, 'inspection', 'Szczelność', 6, '2026-06-05', '2026-12-05', NULL),
    (3, 3, 'calibration', NULL, NULL, NULL, NULL, NULL), (4, 3, 'measurement', NULL, 12, '2026-03-01', '2027-03-01', NULL)");
$pdo->exec("INSERT INTO n_equipment_ticket (id, id_equipment, id_workstation, kind, title, provider, reported_on, due_date, status, closed_on) VALUES
    (1, 2, NULL, 'breakdown', 'Nie chłodzi', 'Chłód-Test', '2026-11-02', '2026-11-10', 'ordered', NULL), (2, NULL, 5, 'service', 'Serwis komory', NULL, '2026-11-20', NULL, 'open', NULL),
    (3, NULL, 2, 'breakdown', 'Zacięty nóż', NULL, '2026-10-20', '2026-10-21', 'done', '2026-10-21'), (4, 1, NULL, 'service', 'Stary serwis', NULL, '2026-08-01', '2026-08-05', 'done', '2026-08-05')");
$pdo->exec("INSERT INTO n_prod_ws_state (id, id_workstation, state, reason, note, started_at, ended_at) VALUES
    (1, 1, 'stop', 'breakdown', 'grzałka', '2026-11-12 08:00:00', '2026-11-12 09:30:00'), (2, 2, 'stop', 'breakdown', NULL, '2026-10-19 10:00:00', '2026-10-19 11:00:00'),
    (3, 1, 'stop', 'breakdown', NULL, '2026-11-15 10:00:00', '2026-11-15 10:05:00'), (4, 5, 'stop', 'setup', NULL, '2026-11-16 10:00:00', '2026-11-16 12:00:00'),
    (5, 1, 'stop', 'breakdown', NULL, '2026-09-20 10:00:00', '2026-09-20 12:00:00'), (6, 5, 'stop', 'breakdown', NULL, '2026-11-23 22:00:00', NULL),
    (7, 1, 'run', NULL, NULL, '2026-11-12 09:30:00', NULL)");

$erpTables = ['n_prod_workstation', 'n_prod_ws_state', 'n_prod_group', 'user'];
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
$check($keys === ['check:1', 'ticket:1', 'stop:6', 'stop:1', 'check:3', 'ticket:2', 'check:2', 'machine:2', 'machine:5'],
    'items by priority : overdue check, overdue ticket, breakdowns without ticket, check without date, ticket without due date, soon, machines outside the register');
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check($rv['check:1']['signals'][0]['detail'] === 'termin 15.10.2026 (40 dni temu)' && $rv['check:1']['title'] === 'Piec Testowy 1'
    && $rv['check:1']['sub'] === 'Serwis okresowy · co 12 mies. · ostatnio 15.10.2025 · Serwis Piecowy Test' && $codes($rv['check:2']) === ['check_soon']
    && $rv['check:2']['signals'][0]['detail'] === 'termin 05.12.2026 (za 11 dni)' && $codes($rv['check:3']) === ['check_no_date'] && !isset($rv['check:4']),
    'checks : overdue, soon (30 days), without date ; future one not listed');
$check($codes($rv['ticket:1']) === ['ticket_overdue'] && $rv['ticket:1']['sub'] === 'Awaria · Chłodnia 2 · zgłoszone 02.11.2026 · Chłód-Test · zlecone' && $codes($rv['ticket:2']) === ['ticket_no_due']
    && str_contains($rv['ticket:2']['sub'], 'Komora Test') && !isset($rv['ticket:3']), 'tickets : overdue, without due date ; closed ones not listed');
$check($codes($rv['stop:1']) === ['stop_no_ticket'] && $rv['stop:1']['sub'] === 'postój « awaria » 12.11.2026, 8:00 · 1 h 30 min · grzałka' && str_contains($rv['stop:6']['sub'], '(trwa)')
    && !isset($rv['stop:2']) && !isset($rv['stop:3']) && !isset($rv['stop:4']) && !isset($rv['stop:5']) && $d['totals']['stops'] === 3 && $d['totals']['stop_no_ticket'] === 2,
    'breakdowns : without ticket (ongoing too) ; covered by a ticket on the same machine (day before → 7 days), too short, other reason, before the period not listed');
$check($codes($rv['machine:2']) === ['machine_unregistered'] && $rv['machine:5']['sub'] === 'komora rozrostu · Piekarnia · KOM' && !isset($rv['machine:1']) && !isset($rv['machine:3']) && !isset($rv['machine:4'])
    && $d['totals']['machines'] === 3, 'machines outside the register (manual station and inactive machine excluded)');
$check($d['totals']['waiting'] === 9 && !$d['can_finish'] && count($d['register']['equipment']) === 3 && count($d['register']['checks']) === 4 && count($d['register']['tickets']) === 3
    && array_column($d['machines'], 'registered', 'name') === ['Dzielarka Test' => false, 'Komora Test' => false, 'Piec Testowy 1' => true] && $erp0 === $sum(), 'totals, registers ; reading writes nothing');
$pdo->exec("UPDATE n_prod_param SET value = '5' WHERE code = 'maintenance.alert_days'");
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'maintenance.breakdown_min_minutes'");
$x = array_column($make()->data($today)['review'], null, 'key');
$check(!isset($x['check:2']) && isset($x['stop:3']), 'settings : « soon » follows maintenance.alert_days, minimal breakdown duration follows its setting');
$pdo->exec("UPDATE n_prod_param SET value = '30' WHERE code = 'maintenance.alert_days'");
$pdo->exec("UPDATE n_prod_param SET value = '15' WHERE code = 'maintenance.breakdown_min_minutes'");
$svc = $make();

// --- Krok 2 : refus et validations
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'check:1', 'decision' => 'done'], 2, $today)), 'custom:422:Nadal : przegląd / serwis po terminie. Zapisz wykonanie')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'stop:1', 'decision' => 'booked', 'booked_on' => '2026-12-01'], 2, $today)), 'custom:422:Dla « Awaria z tabletu » wybierz : Załatwione, Nie dotyczy')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'check:3', 'decision' => 'not_applicable'], 2, $today)), 'custom:422:Nie dotyczy : wpisz dlaczego')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'check:2', 'decision' => 'booked', 'booked_on' => '2026-11-01'], 2, $today)), 'custom:422:Umówione : data od dziś')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'check:4', 'decision' => 'not_applicable', 'note' => 'xyz'], 2, $today)), 'custom:422:Tego elementu nie ma na liście'),
    '« załatwione » refused while the signal remains ; decisions by kind ; note ; booking date ; item without signal');
$check(str_starts_with($err(fn() => $svc->saveEquipment(['kind' => 'machine', 'id_workstation' => 1], 2, $today)), 'custom:422:Ta maszyna jest już w rejestrze')
    && str_starts_with($err(fn() => $svc->saveEquipment(['kind' => 'machine', 'id_workstation' => 3], 2, $today)), 'custom:422:Nie ma takiej aktywnej maszyny')
    && str_starts_with($err(fn() => $svc->saveEquipment(['kind' => 'xxx', 'name' => 'Coś'], 2, $today)), 'custom:422:Wybierz rodzaj urządzenia')
    && str_starts_with($err(fn() => $svc->saveEquipment(['kind' => 'other', 'name' => 'X'], 2, $today)), 'custom:422:Nazwa urządzenia'),
    'equipment validated : machine already registered, manual station, kind, name');
$check(str_starts_with($err(fn() => $svc->saveCheck(['id_equipment' => 9, 'kind' => 'inspection'], 2, $today)), 'custom:422:Wybierz urządzenie z rejestru')
    && str_starts_with($err(fn() => $svc->saveCheck(['id_equipment' => 2, 'kind' => 'inspection', 'interval_months' => '0'], 2, $today)), 'custom:422:Co ile miesięcy')
    && str_starts_with($err(fn() => $svc->saveCheck(['id_equipment' => 2, 'kind' => 'inspection', 'last_done' => '2026-12-01'], 2, $today)), 'custom:422:Ostatnio wykonane : data nie może być w przyszłości')
    && str_starts_with($err(fn() => $svc->saveCheck(['id_equipment' => 2, 'kind' => 'inspection', 'last_done' => '2026-11-01', 'next_due' => '2026-10-01'], 2, $today)), 'custom:422:Następny termin : data po ostatnim')
    && str_starts_with($err(fn() => $svc->saveCheck(['id_equipment' => 2, 'kind' => 'other'], 2, $today)), 'custom:422:Rodzaj « Inne » : wpisz nazwę'),
    'check validated : equipment, interval, past date, next after last, name of « other »');
$check(str_starts_with($err(fn() => $svc->saveTicket(['kind' => 'breakdown', 'title' => 'Awaria'], 2, $today)), 'custom:422:Wybierz urządzenie albo maszynę')
    && str_starts_with($err(fn() => $svc->saveTicket(['kind' => 'breakdown', 'title' => 'Awaria', 'id_equipment' => 1, 'reported_on' => '2026-11-30'], 2, $today)), 'custom:422:Data zgłoszenia nie może być w przyszłości')
    && str_starts_with($err(fn() => $svc->saveTicket(['kind' => 'breakdown', 'title' => 'Awaria', 'id_equipment' => 1, 'reported_on' => '2026-11-20', 'due_date' => '2026-11-19'], 2, $today)), 'custom:422:Termin realizacji : nie przed')
    && str_starts_with($err(fn() => $svc->saveTicket(['kind' => 'breakdown', 'title' => 'Awaria', 'id_ws_state' => 3], 2, $today)), 'custom:422:Nie ma takiej awarii')
    && str_starts_with($err(fn() => $svc->ticketStatus(['id' => 99, 'status' => 'done'], 2, $today)), 'custom:404:Nie ma takiego zgłoszenia'),
    'ticket validated : target, dates, breakdown of the period, unknown ticket');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 9 elementów') && (int) $pdo->query('SELECT COUNT(*) FROM n_maintenance_review')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM n_equipment')->fetchColumn() === 3, 'finish blocked ; refused calls write nothing');

// --- Actions → « Załatwione » seule ; décisions.
$d = $svc->checkDone(['id' => 1, 'done_on' => '2026-11-20'], 2, $today);
$row = $pdo->query('SELECT last_done, next_due FROM n_equipment_check WHERE id = 1')->fetch();
$rv = array_column($d['review'], null, 'key');
$check($row === ['last_done' => '2026-11-20', 'next_due' => '2027-11-20'] && $rv['check:1']['decision']['code'] === 'done' && $rv['check:1']['decision']['flags'] === ['check_overdue'] && !$rv['check:1']['waiting'],
    'check done : next due = done + interval ; « załatwione » recorded by itself');
$d = $svc->saveDecision(['key' => 'check:2', 'decision' => 'booked', 'booked_on' => '2026-12-03', 'note' => 'Chłód-Test, rano'], 2, $today);
$d = $svc->saveDecision(['key' => 'ticket:1', 'decision' => 'booked', 'booked_on' => '2026-12-15', 'note' => 'część zamówiona'], 2, $today);
$check($pdo->query('SELECT due_date FROM n_equipment_ticket WHERE id = 1')->fetchColumn() === '2026-12-15' && array_column($d['review'], null, 'key')['ticket:1']['decision']['code'] === 'booked'
    && !array_column($d['review'], null, 'key')['ticket:1']['waiting'], 'ticket « umówione » : the date becomes its new due date');
$d = $svc->ticketStatus(['id' => 2, 'status' => 'done'], 2, $today);
$t2 = $pdo->query('SELECT status, closed_on FROM n_equipment_ticket WHERE id = 2')->fetch();
$rv = array_column($d['review'], null, 'key');
$check($t2 === ['status' => 'done', 'closed_on' => '2026-11-24'] && $rv['ticket:2']['decision']['code'] === 'done' && $rv['ticket:2']['decision']['flags'] === ['ticket_no_due'] && $rv['ticket:2']['signals'] === [],
    'ticket closed (today) : « załatwione » by itself, still shown this month');
$d = $svc->saveTicket(['kind' => 'breakdown', 'id_ws_state' => 1, 'title' => 'Awaria — Piec Testowy 1', 'provider' => 'Serwis Piecowy Test', 'reported_on' => '2026-11-12', 'due_date' => '2026-11-28'], 2, $today);
$t5 = $pdo->query('SELECT id_workstation, id_ws_state, status FROM n_equipment_ticket WHERE id_ws_state = 1')->fetch();
$check($t5 === ['id_workstation' => 1, 'id_ws_state' => 1, 'status' => 'open'] && array_column($d['review'], null, 'key')['stop:1']['decision']['code'] === 'done',
    'ticket from a breakdown : linked to the stop and its machine ; « załatwione » by itself');
$d = $svc->saveDecision(['key' => 'stop:6', 'decision' => 'not_applicable', 'note' => 'koniec zmiany — postój zapisany omyłkowo'], 2, $today);
$d = $svc->saveDecision(['key' => 'check:3', 'decision' => 'not_applicable', 'note' => 'waga pomocnicza, bez legalizacji'], 2, $today);
$d = $svc->saveEquipment(['kind' => 'machine', 'id_workstation' => 2, 'location' => 'hala A'], 2, $today);
$rv = array_column($d['review'], null, 'key');
$check($pdo->query('SELECT name FROM n_equipment WHERE id_workstation = 2')->fetchColumn() === 'Dzielarka Test' && $rv['machine:2']['decision']['code'] === 'done' && !$rv['machine:2']['waiting'],
    'machine registered (name from the ERP) : « załatwione » by itself, still shown this month');
$st = $tasks->state(S::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '8 elementów z decyzją', 'task state : in progress');
$d = $svc->saveDecision(['key' => 'machine:5', 'decision' => 'not_applicable', 'note' => 'komora w leasingu — serwis po stronie leasingodawcy'], 2, $today);
$check($d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['booked'] === 2 && $d['totals']['done'] === 4 && $d['totals']['not_applicable'] === 3, 'all decided → step 3');
$check($erp0 === $sum(), 'actions and decisions write nothing in the ERP (machines, stops)');
$r = $svc->finish(2, $today);
$check($r['summary'] === 'listopad 2026 : przeglądy 1 wkrótce · 1 bez terminu / 4 · zgłoszenia 2 otwarte · awarie bez zgłoszenia 1 / 3 · 1 maszyna bez rejestru · decyzje : 2 umówione · 4 załatwione · 3 nie dotyczy'
    && (int) $pdo->query("SELECT COUNT(*) FROM n_wizard_done WHERE code = 'maintenance_reviewed' AND day = '2026-11-24'")->fetchColumn() === 1, 'finish : summary, n_wizard_done');
$check($tasks->state(S::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30')['done'] === true && $tasks->state(S::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');

// Mois suivant : reprises (rendez-vous à venir, nie dotyczy aux mêmes signaux, arrêt décidé) ; rendez-vous passé.
$n = $make()->data('2026-12-02');
$nv = array_column($n['review'], null, 'key');
$check(!$nv['check:2']['waiting'] && $nv['check:2']['earlier']['code'] === 'booked' && !$nv['check:3']['waiting'] && !$nv['machine:5']['waiting'] && !$nv['stop:6']['waiting']
    && !isset($nv['ticket:1']) && $nv['ticket:5']['waiting'] && array_column($nv['ticket:5']['signals'], 'code') === ['ticket_overdue'] && $n['totals']['waiting'] === 1 && $n['done'] === null,
    'next month : booking, « nie dotyczy » and decided breakdown carried ; ticket within its new due date ; ticket of the breakdown past its due date');
$n = array_column($make()->data('2026-12-20')['review'], null, 'key');
$check($n['ticket:1']['waiting'] && $n['ticket:1']['reason'] === 'termin umówiony 15.12.2026 minął — zapisz wykonanie albo nowy termin' && $n['check:2']['waiting'], 'passed bookings wait again');

// --- Contrôleur : construit par PHP-DI, data() et les écritures réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_maintenance_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(MaintenanceController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 4), (4, 3)');
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), $res]; };
$erp3 = $sum();
[$c2, $r2] = $call(2, 'data');
$b2 = json_decode((string) $r2->getContent(), true);
$check($c2 === 200 && $b2['data']['month_start'] === S::reviewMonth(date('Y-m-d')) && $b2['data']['links'] === [], 'controller data() (Kierownik produkcji, « maintenance ») : 200, real month ; no link to screens he cannot open');
[$c1, $r1] = $call(1, 'data');
$check($c1 === 200 && array_column(json_decode((string) $r1->getContent(), true)['data']['links'], 'href') === ['/production/capacity', '/finances/costs'], 'controller data() (administrator) : 200 with the links');
$codesBy = [];
foreach (['data', 'decision', 'equipment', 'check', 'checkDone', 'ticket', 'ticketStatus', 'finish'] as $m) { [$codesBy[$m]] = $call(4, $m); }
[$c3] = $call(3, 'data');
$check(array_values(array_unique($codesBy)) === [403] && $c3 === 403, 'controller : without « maintenance » (Piekarz, Administracja) → 403 everywhere');
[$cD2] = $call(2, 'decision'); [$cE2] = $call(2, 'equipment'); [$cF2] = $call(2, 'finish');
$check($cD2 === 422 && $cE2 === 422 && in_array($cF2, [200, 422], true), 'controller decision(), equipment() and finish() really called');
$check($erp3 === $sum(), 'controller calls write nothing in the ERP');
unset($_SESSION['USER']);

// --- Aucun envoi, aucun appel extérieur, aucune écriture dans l'ERP : code sans les commentaires.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/MaintenanceService.php', 'App/Repositories/Workshop/MaintenanceRepository.php',
    'App/Controllers/Web/Production/MaintenanceController.php', 'public/assets/js/maintenance/maintenance.js']));
$check(!preg_match('/UPDATE\s+n_prod_|INSERT\s+INTO\s+n_prod_|DELETE\s+FROM|error_log|PHPMailer|Sms|OutgoingMessage|curl_|https?:\/\//i', $src),
    'no write to the ERP tables, no delete, no log, no mail / SMS, no external call, no hard-coded URL');

// --- Enregistrement
$check(TaskWizards::info('maintenance_reviewed')['link'] === '/maintenance' && TaskWizards::byLink('/maintenance') === 'maintenance_reviewed'
    && isset(TaskWizards::AUTO['maintenance_reviewed']) && PositionTaskService::LINK_AUTO['/maintenance'] === 'maintenance_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['maintenance_reviewed'][0] === 'Serwisy i przeglądy maszyn (przegląd miesiąca)' && TaskWizards::CATALOG['maintenance_reviewed'][0] !== 'Serwisy i przeglądy maszyn',
    'catalog label distinct from the proposed name (237)');
$check(PageAccess::keyFor('/maintenance') === 'maintenance' && PageAccess::keyFor('/ajax/maintenance/ticket/status') === 'maintenance' && in_array('maintenance', PageAccess::grantable(), true),
    'own right « maintenance » (page and ajax)');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'maintenance_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/maintenance"'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Production/MaintenanceRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/maintenance') === 'index' && $found('GET', '/ajax/maintenance') === 'data' && $found('POST', '/ajax/maintenance/decision') === 'decision'
    && $found('POST', '/ajax/maintenance/equipment') === 'equipment' && $found('POST', '/ajax/maintenance/check') === 'check' && $found('POST', '/ajax/maintenance/check/done') === 'checkDone'
    && $found('POST', '/ajax/maintenance/ticket') === 'ticket' && $found('POST', '/ajax/maintenance/ticket/status') === 'ticketStatus' && $found('POST', '/ajax/maintenance/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/maintenance')[1]['controller'] === MaintenanceController::class && $allR->dispatch('GET', '/production/capacity')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing routes unchanged');

if (getenv('MT_JSON_OUT')) file_put_contents((string) getenv('MT_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_maintenance_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
