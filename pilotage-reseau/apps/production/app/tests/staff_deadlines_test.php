<?php
/**
 * [404] Asystent « Badania i szkolenia (przegląd terminów) » : salariés actifs seulement ; badania = n_hr_employee.medical_until
 * (absente, dépassée, dans hr.alert_days jours), dernier document « badania » pour information ; szkolenia = registre de
 * l'assistant (meilleur enregistrement par type : sans échéance, sinon « ważne do » la plus lointaine ; dépassé, bientôt ;
 * « brak BHP » selon staff_deadlines.bhp_required) ; décisions (umówione avec date d'aujourd'hui à un an, reprise jusqu'à cette
 * date ; aktualne seulement sans signal — enregistrée seule après un szkolenie saisi, avec les signaux vus après une
 * correction dans HR ; nie dotyczy avec note, reprise tant que les signaux sont les mêmes) ; registre (validation, retrait) ;
 * « Zakończ » → n_wizard_done, état de la tâche ; mois suivant ; aucune écriture dans les tables de l'ERP ni HR (checksums) ;
 * contrôleur construit par PHP-DI et appelé (droits « staff_deadlines » + « hr » 200 et lecture notée dans n_hr_log, sans
 * l'un des deux 403) ; aucune lecture chiffrée, aucun envoi, aucun journal ; enregistrement (catalogue, droits, routes,
 * réglages, équipe) ; migration 244 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_staff_deadlines_test recréée (tables minimales de l'ERP, migrations 170 et 244), supprimée à la fin
 * (SD_JSON_OUT=fichier : données de la page avant les décisions). Personnes fictives.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Hr\StaffDeadlinesController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Hr\StaffDeadlinesRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\StaffDeadlinesService as S;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_staff_deadlines_test'); $pdo->exec('CREATE DATABASE erp_staff_deadlines_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_staff_deadlines_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(60), surname VARCHAR(60))',
    'CREATE TABLE n_prod_group (id INT PRIMARY KEY, name_pl VARCHAR(60), color VARCHAR(10) NULL, sort_order INT DEFAULT 0)',
    'CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT, id_primary_group INT NULL, id_position INT NULL, active TINYINT DEFAULT 1)',
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
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2), (3, 'PIEKARZ', 'Piekarz', 7),
    (4, 'ADMINISTRACJA', 'Administracja', 3), (5, 'TESTER', 'Tester', 9)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (8, 'ADMIN')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '9.5', NULL, 'Badania i szkolenia (terminy)', 'monthly'),
    (2, '9.6', NULL, 'Badania i szkolenia (terminy)', 'monthly'), (2, '10.1', NULL, 'Serwisy i przeglądy maszyn', 'monthly'), (4, '9', NULL, 'Badania i szkolenia (terminy)', 'monthly'),
    (2, '9.3', 'time_day_validated', NULL, 'daily')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 7)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (5, 'staff_deadlines')");
$pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (2, 'time')");
foreach (['170_hr.sql', '244_staff_deadlines.sql'] as $f) {
    $stmts = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f));
    for ($k = 0; $k < ($f === '244_staff_deadlines.sql' ? 2 : 1); $k++) foreach ($stmts as $sql) $pdo->exec($sql);
}

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 244 (deux passages)
$duties = $pdo->query('SELECT wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0] === ['wizard_code' => 'staff_deadlines_reviewed', 'proposed_wizard' => null] && $duties[1] === $duties[0] && $duties[2]['proposed_wizard'] === 'Serwisy i przeglądy maszyn'
    && $duties[3]['proposed_wizard'] === 'Badania i szkolenia (terminy)' && $duties[4]['wizard_code'] === 'time_day_validated', 'migration 244 : KIEROWNIK_PRODUKCJI 9.5 and 9.6 → staff_deadlines_reviewed ; other rows and positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'staff_deadlines_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'monthly' && $tasks[0]['month_day'] === 'wd3' && $tasks[0]['link_path'] === '/hr/deadlines'
    && (int) $tasks[0]['id_category'] === 8 && (int) $tasks[0]['sort_order'] === 8, 'one monthly task for Kierownik produkcji (category « Administracja »)');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'hr,staff_deadlines,time', 4 => 'hr', 5 => 'staff_deadlines'], 'right « staff_deadlines » for Kierownik produkcji only (hr from 170) ; no other right');
$check($pdo->query("SELECT CONCAT(value, '|', value_type) FROM n_prod_param WHERE code = 'staff_deadlines.bhp_required'")->fetchColumn() === '1|int'
    && (int) $pdo->query("SELECT COUNT(*) FROM n_prod_param WHERE code LIKE 'staff_deadlines.%'")->fetchColumn() === 1 && ParamRules::RANGES['staff_deadlines.bhp_required'] === [0, 1, true],
    'setting seeded once ; ParamRules bounds ; alert delay = hr.alert_days of the HR table');
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_staff%'")->fetchColumn();
$check($fks === 0 && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_staff_training', 'n_staff_deadline_review')")->fetchColumn() === 2,
    '2 tables, no foreign key');

$lw = new LightWizardRepository($pdo);
$make = fn() => new S(new StaffDeadlinesRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';

// --- Sans salarié
$e = $svc->data($today);
$check($e['review'] === [] && $e['can_finish'] && $e['totals']['employees'] === 0 && $e['sources']['employees']['note'] === 'brak aktywnych pracowników'
    && $e['settings'] === ['alert_days' => 30, 'bhp_required' => true] && $e['summary'] === 'listopad 2026 : 0 pracowników · badania aktualne · szkolenia aktualne', 'no employee : nothing to decide');
$check(S::dateIn('2026-02-30') === null && S::dateIn('2026-12-03') === '2026-12-03' && S::dateIn('3.12.2026') === null && S::reviewMonth('2026-11-24') === '2026-11-01', 'date parsing, review month');

// --- Données fictives : contrôle le mar. 24.11.2026.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL), (5, 'tester', NULL)");
$pdo->exec("INSERT INTO n_prod_group VALUES (1, 'Piekarnia', '#c00', 1), (2, 'Cukiernia', '#0c0', 2)");
$pdo->exec("INSERT INTO production_employee VALUES (11, 'Anna', 'Testowa'), (12, 'Bartek', 'Próbny'), (13, 'Celina', 'Fikcyjna'), (14, 'Damian', 'Wzorowy'), (15, 'Ewa', 'Nieaktywna')");
$pdo->exec('INSERT INTO n_prod_employee VALUES (1, 11, 1, 3, 1), (2, 12, 1, NULL, 1), (3, 13, 2, NULL, 1), (4, 14, 2, NULL, 1), (5, 15, 1, NULL, 0)');
$pdo->exec("INSERT INTO n_hr_employee (id_employee, phone_enc, birth_enc, medical_until) VALUES (1, 'ENC-x', 'ENC-y', '2026-10-01'), (3, NULL, NULL, '2026-12-10'), (4, NULL, NULL, '2027-06-01'), (5, NULL, NULL, '2025-01-01')");
$pdo->exec("INSERT INTO n_hr_document (id_employee, kind, title, filename, mime, size, doc_date, content) VALUES (1, 'badania', 'Orzeczenie', 'a.pdf', 'application/pdf', 3, '2025-10-01', 'ENC'),
    (1, 'umowa', 'Umowa', 'u.pdf', 'application/pdf', 3, '2026-03-01', 'ENC')");
$tr = $pdo->prepare('INSERT INTO n_staff_training (id_employee, kind, title, done_on, valid_until) VALUES (?, ?, ?, ?, ?)');
foreach ([[1, 'bhp', 'BHP okresowe', '2023-05-01', '2024-05-01'], [1, 'bhp', 'BHP okresowe', '2024-05-01', '2027-05-01'], [3, 'bhp', null, '2023-11-30', '2026-11-30'],
    [3, 'sanitary', 'Higiena', '2024-11-01', '2026-11-01'], [4, 'bhp', 'BHP wstępne', '2026-01-10', null], [5, 'bhp', null, '2020-01-01', '2021-01-01']] as $r) $tr->execute($r);

$erpTables = ['n_hr_employee', 'n_hr_document', 'n_prod_employee', 'production_employee', 'n_prod_group', 'user'];
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
$check($keys === ['medical:1', 'medical:2', 'training:3:sanitary', 'training:2:bhp', 'medical:3', 'training:3:bhp'],
    'items : expired / missing badania, expired szkolenie, missing BHP, then « soon » (inactive employee and up-to-date ones not listed)');
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check($codes($rv['medical:1']) === ['medical_expired'] && $rv['medical:1']['signals'][0]['detail'] === 'ważne do 01.10.2026 (54 dni temu)'
    && $rv['medical:1']['info'] === 'ostatni dokument « Badania lekarskie » w HR : 01.10.2025' && $rv['medical:1']['sub'] === 'Piekarnia · Piekarz' && $rv['medical:1']['title'] === 'Anna Testowa'
    && $codes($rv['medical:2']) === ['medical_missing'] && $rv['medical:2']['info'] === 'brak dokumentu « Badania lekarskie » w HR'
    && $codes($rv['medical:3']) === ['medical_soon'] && $rv['medical:3']['signals'][0]['detail'] === 'ważne do 10.12.2026 (za 16 dni)', 'badania : expired, missing (no HR card), soon ; last « badania » document date only');
$check($codes($rv['training:2:bhp']) === ['training_missing'] && $codes($rv['training:3:bhp']) === ['training_soon'] && $codes($rv['training:3:sanitary']) === ['training_expired']
    && !isset($rv['training:1:bhp']) && !isset($rv['training:4:bhp']) && $rv['training:3:sanitary']['info'] === 'Higiena · szkolenie 01.11.2024',
    'szkolenia : best record per kind (newer BHP of Anna, open-ended BHP of Damian), missing BHP, soon, expired');
$check(!str_contains(json_encode($d), 'ENC') && !str_contains(json_encode($d), 'Orzeczenie') && !str_contains(json_encode($d), 'Umowa'), 'no encrypted field, no document title or content in the data');
$check($d['totals']['waiting'] === 6 && $d['totals']['employees'] === 4 && $d['totals']['medical_expired'] === 1 && $d['totals']['training_missing'] === 1 && !$d['can_finish']
    && count($d['register']) === 5 && array_column($d['employees'], 'name') === ['Celina Fikcyjna', 'Bartek Próbny', 'Anna Testowa', 'Damian Wzorowy'] && $erp0 === $sum(),
    'totals ; register of active employees ; reading writes nothing');
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'staff_deadlines.bhp_required'");
$pdo->exec("UPDATE n_prod_param SET value = '10' WHERE code = 'hr.alert_days'");
$x = $make()->data($today);
$check(!in_array('training:2:bhp', array_column($x['review'], 'key'), true) && !in_array('medical:3', array_column($x['review'], 'key'), true)
    && in_array('training:3:bhp', array_column($x['review'], 'key'), true), 'settings : no BHP signal when 0 ; « soon » follows hr.alert_days (10 days)');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'staff_deadlines.bhp_required'");
$pdo->exec("UPDATE n_prod_param SET value = '30' WHERE code = 'hr.alert_days'");
$svc = $make();

// --- Krok 2
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'medical:1', 'decision' => 'current'], 2, $today)), 'custom:422:Nadal : badania lekarskie po terminie. Wpisz nową datę badań w HR'),
    '« aktualne » refused while the signal remains');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'medical:1', 'decision' => 'booked'], 2, $today)), 'custom:422:Umówione : wpisz datę badań')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'medical:1', 'decision' => 'booked', 'booked_on' => '2026-11-20'], 2, $today)), 'custom:422:Umówione : data od dziś do roku naprzód')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'medical:1', 'decision' => 'booked', 'booked_on' => '2028-01-01'], 2, $today)), 'custom:422:Umówione : data od dziś'),
    '« umówione » needs a date from today to one year');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'training:2:bhp', 'decision' => 'not_applicable'], 2, $today)), 'custom:422:Nie dotyczy : wpisz dlaczego')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'medical:4', 'decision' => 'booked', 'booked_on' => '2026-12-01'], 2, $today)), 'custom:422:Tego terminu nie ma na liście'),
    '« nie dotyczy » needs a note ; item without signal refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 6 terminów') && (int) $pdo->query('SELECT COUNT(*) FROM n_staff_deadline_review')->fetchColumn() === 0,
    'finish blocked ; refused calls write nothing');
$d = $svc->saveDecision(['key' => 'medical:1', 'decision' => 'booked', 'booked_on' => '2026-12-03', 'note' => 'Medycyna pracy — gabinet testowy'], 2, $today);
$row = $pdo->query("SELECT * FROM n_staff_deadline_review WHERE item_key = 'medical:1'")->fetch();
$check($row['decision'] === 'booked' && $row['booked_on'] === '2026-12-03' && $row['flags'] === 'medical_expired' && (int) $row['id_employee'] === 1 && !array_column($d['review'], null, 'key')['medical:1']['waiting'],
    '« umówione » saved with the date and the signals');
// Szkolenia : validation, puis saisie → « aktualne » enregistrée seule.
$check(str_starts_with($err(fn() => $svc->saveTraining(['id_employee' => 5, 'kind' => 'bhp', 'done_on' => '2026-11-20'], 2, $today)), 'custom:422:Wybierz aktywnego pracownika')
    && str_starts_with($err(fn() => $svc->saveTraining(['id_employee' => 2, 'kind' => 'xxx', 'done_on' => '2026-11-20'], 2, $today)), 'custom:422:Wybierz rodzaj')
    && str_starts_with($err(fn() => $svc->saveTraining(['id_employee' => 2, 'kind' => 'bhp', 'done_on' => '2026-11-30'], 2, $today)), 'custom:422:Data szkolenia nie może być w przyszłości')
    && str_starts_with($err(fn() => $svc->saveTraining(['id_employee' => 2, 'kind' => 'bhp', 'done_on' => ''], 2, $today)), 'custom:422:Wpisz datę szkolenia')
    && str_starts_with($err(fn() => $svc->saveTraining(['id_employee' => 2, 'kind' => 'bhp', 'done_on' => '2026-11-20', 'valid_until' => '2026-11-01'], 2, $today)), 'custom:422:Ważne do : data po dacie')
    && str_starts_with($err(fn() => $svc->saveTraining(['id_employee' => 2, 'kind' => 'other', 'done_on' => '2026-11-20'], 2, $today)), 'custom:422:Szkolenie « Inne » : wpisz nazwę'),
    'training validated : active employee, kind, past date, validity after, name of « other »');
$d = $svc->saveTraining(['id_employee' => 2, 'kind' => 'bhp', 'title' => 'BHP wstępne', 'done_on' => '2026-11-20', 'valid_until' => '2027-11-20'], 2, $today);
$rv = array_column($d['review'], null, 'key');
$check($rv['training:2:bhp']['decision']['code'] === 'current' && $rv['training:2:bhp']['decision']['flags'] === ['training_missing'] && !$rv['training:2:bhp']['waiting'] && $rv['training:2:bhp']['signals'] === [],
    'training saved : « aktualne » recorded by itself with the former signal');
$d = $svc->saveTraining(['id_employee' => 3, 'kind' => 'sanitary', 'done_on' => '2026-11-18', 'valid_until' => '2028-11-18'], 2, $today);
// Date corrigée dans HR depuis l'affichage : « aktualne » avec les signaux vus.
$pdo->exec("INSERT INTO n_hr_employee (id_employee, medical_until) VALUES (2, '2027-11-15')");
$erp1 = $sum();
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'medical:2', 'decision' => 'current'], 2, $today)), 'custom:422:Tego terminu nie ma na liście'), 'fixed in HR, without « seen » : not in the list');
$d = $svc->saveDecision(['key' => 'medical:2', 'decision' => 'current', 'seen' => ['medical_missing']], 2, $today);
$check(array_column($d['review'], null, 'key')['medical:2']['decision']['flags'] === ['medical_missing'], 'fixed in HR : « aktualne » with the signals seen');
$d = $svc->saveDecision(['key' => 'medical:3', 'decision' => 'booked', 'booked_on' => '2026-12-01'], 2, $today);
$d = $svc->saveDecision(['key' => 'training:3:bhp', 'decision' => 'not_applicable', 'note' => 'Umowa kończy się 30.11 — bez przedłużenia'], 2, $today);
$st = $tasks->state(S::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '6 terminów z decyzją', 'task state : in progress');
$check($d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['booked'] === 2 && $d['totals']['current'] === 3 && $d['totals']['not_applicable'] === 1 && $erp1 === $sum(),
    'all decided → step 3 ; decisions and register write nothing in HR or the ERP');
$r = $svc->finish(2, $today);
$check($r['summary'] === 'listopad 2026 : 4 pracowników · badania 1 po terminie · 1 wkrótce · szkolenia 1 wkrótce · decyzje : 2 umówione · 3 aktualne · 1 nie dotyczy'
    && (int) $pdo->query("SELECT COUNT(*) FROM n_wizard_done WHERE code = 'staff_deadlines_reviewed' AND day = '2026-11-24'")->fetchColumn() === 1, 'finish : summary, n_wizard_done');
$check($tasks->state(S::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30')['done'] === true && $tasks->state(S::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');

// Mois suivant (mer. 2.12) : rendez-vous du 3.12 repris ; celui du 1.12 passé ; « nie dotyczy » d'un signal qui a changé.
$n = $make()->data('2026-12-02');
$nv = array_column($n['review'], null, 'key');
$check(array_column($n['review'], 'key') === ['training:3:bhp', 'medical:3', 'medical:1'] && !$nv['medical:1']['waiting'] && $nv['medical:1']['earlier']['booked_on'] === '2026-12-03'
    && $nv['medical:3']['waiting'] && $nv['medical:3']['reason'] === 'termin umówiony 01.12.2026 minął — wpisz nową datę badań w HR albo umów ponownie'
    && $nv['training:3:bhp']['waiting'] && $codes($nv['training:3:bhp']) === ['training_expired'] && $n['done'] === null,
    'next month : booking kept until its date, passed booking waits again, « nie dotyczy » not carried when the signal changed');
$check(array_column($make()->data('2026-12-05')['review'], null, 'key')['medical:1']['waiting'], 'booking of 03.12 passed on 05.12 → waits again');
// Registre : retrait d'un wpis błędny.
$bid = (int) $pdo->query("SELECT id FROM n_staff_training WHERE id_employee = 2 AND kind = 'bhp'")->fetchColumn();
$check(str_starts_with($err(fn() => $svc->removeTraining(['id' => 999], 2, $today)), 'custom:404:Nie ma takiego wpisu'), 'unknown register entry → 404');
$d = $svc->removeTraining(['id' => $bid], 2, $today);
$check((int) $pdo->query("SELECT active FROM n_staff_training WHERE id = {$bid}")->fetchColumn() === 0 && array_column($d['review'], null, 'key')['training:2:bhp']['waiting'],
    'register entry removed (deactivated) : the BHP signal comes back');

// --- Contrôleur : construit par PHP-DI, data(), decision(), training(), removeTraining() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_staff_deadlines_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(StaffDeadlinesController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 4), (4, 3), (5, 5)');
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), $res]; };
$erp3 = $sum();
$logs0 = (int) $pdo->query("SELECT COUNT(*) FROM n_hr_log WHERE action = 'deadlines'")->fetchColumn();
[$c2, $r2] = $call(2, 'data');
$b2 = json_decode((string) $r2->getContent(), true);
$check($c2 === 200 && $b2['data']['month_start'] === S::reviewMonth(date('Y-m-d')) && array_column($b2['data']['links'], 'href') === ['/hr', '/hr/documents']
    && (int) $pdo->query("SELECT COUNT(*) FROM n_hr_log WHERE action = 'deadlines' AND id_user = 2 AND id_employee IS NULL")->fetchColumn() === $logs0 + 1,
    'controller data() (Kierownik produkcji : staff_deadlines + hr) : 200, real month, HR links ; reading noted in the HR log (no employee detail)');
[$c1] = $call(1, 'data');
[$c4] = $call(4, 'data'); [$c4d] = $call(4, 'decision'); [$c4t] = $call(4, 'training'); [$c4r] = $call(4, 'removeTraining'); [$c4f] = $call(4, 'finish');
[$c3] = $call(3, 'data');
[$c5, $r5] = $call(5, 'data');
$check($c1 === 200 && [$c4, $c4d, $c4t, $c4r, $c4f, $c3] === [403, 403, 403, 403, 403, 403] && $c5 === 403 && str_starts_with((string) (json_decode((string) $r5->getContent(), true)['message'] ?? ''), 'Brak dostępu do HR'),
    'controller : administrator 200 ; without « staff_deadlines » (Piekarz, Administracja) 403 ; « staff_deadlines » without « hr » 403');
[$cF2] = $call(2, 'finish');
[$cD2] = $call(2, 'decision');
$check(in_array($cF2, [200, 422], true) && $cD2 === 422, 'controller finish() and decision() really called');
$check($erp3 === $sum(), 'controller calls write nothing in HR or the ERP');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune lecture chiffrée, aucun journal, aucune écriture hors des tables de l'assistant : code sans commentaires.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/StaffDeadlinesService.php', 'App/Repositories/Hr/StaffDeadlinesRepository.php',
    'App/Controllers/Web/Hr/StaffDeadlinesController.php', 'public/assets/js/hr/staff-deadlines.js']));
$check(!preg_match('/UPDATE\s+(n_hr_employee|n_hr_document|n_prod_employee|production_employee)|INSERT\s+INTO\s+(n_hr_employee|n_hr_document|n_prod_employee)|DELETE\s+FROM|_enc\b|HrCipher|decrypt|\bcontent\b|error_log|PHPMailer|Sms|OutgoingMessage|curl_|https?:\/\//i', $src),
    'no write to HR or ERP tables, no encrypted column, no document content, no log, no mail / SMS, no external call, no hard-coded URL');

// --- Enregistrement
$check(TaskWizards::info('staff_deadlines_reviewed')['link'] === '/hr/deadlines' && TaskWizards::byLink('/hr/deadlines') === 'staff_deadlines_reviewed'
    && isset(TaskWizards::AUTO['staff_deadlines_reviewed']) && PositionTaskService::LINK_AUTO['/hr/deadlines'] === 'staff_deadlines_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['staff_deadlines_reviewed'][0] === 'Badania i szkolenia (przegląd terminów)' && TaskWizards::CATALOG['staff_deadlines_reviewed'][0] !== 'Badania i szkolenia (terminy)',
    'catalog label distinct from the proposed name (237)');
$check(PageAccess::keyFor('/hr/deadlines') === 'staff_deadlines' && PageAccess::keyFor('/ajax/hr/deadlines/decision') === 'staff_deadlines' && in_array('staff_deadlines', PageAccess::grantable(), true)
    && PageAccess::keyFor('/hr') === 'hr' && PageAccess::keyFor('/hr/documents') === 'hr', 'own right « staff_deadlines » (page and ajax) ; HR pages unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'staff_deadlines_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/hr/deadlines'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Hr/StaffDeadlinesRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/hr/deadlines') === 'index' && $found('GET', '/ajax/hr/deadlines') === 'data' && $found('POST', '/ajax/hr/deadlines/decision') === 'decision'
    && $found('POST', '/ajax/hr/deadlines/training') === 'training' && $found('POST', '/ajax/hr/deadlines/training/remove') === 'removeTraining' && $found('POST', '/ajax/hr/deadlines/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/hr/deadlines')[1]['controller'] === StaffDeadlinesController::class && $allR->dispatch('GET', '/hr/documents')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing HR routes unchanged');

if (getenv('SD_JSON_OUT')) file_put_contents((string) getenv('SD_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_staff_deadlines_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
