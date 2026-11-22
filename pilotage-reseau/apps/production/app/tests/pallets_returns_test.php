<?php
/**
 * [381] Asystent « Palety zwrotne » : semaine examinée (lundi → dimanche précédents) ; solde des palettes EUR par client
 * (Σ pallets_delivered − Σ pallets_returned des arrêts confirmés « delivered » / « problem » sur pallets_returns.balance_weeks
 * semaines ; arrêt « arrived » et arrêts plus anciens ignorés ; saisie vide = 0) et même solde une semaine plus tôt
 * (évolution) ; arrêt de la semaine avec palette manquante (livrées > rendues sans pallets_note ; avec motif → pas de
 * signal) ; palette abîmée (n_delivery_incident.kind = 'pallet_damaged' ; autres incidents et semaines ignorés) ; « brak
 * danych — tablet kierowcy nie zapisuje palet » si les colonnes sont vides partout, « brak tabeli » si la table manque ;
 * décisions (przypomnienie et spisane avec note obligatoire, uzgodnione), étape 3 bloquée, « Zakończ » → n_wizard_done,
 * état de la tâche de la semaine ; semaine suivante : le client toujours au-dessus du seuil revient avec la décision
 * précédente ; aucune écriture dans les tables d'origine (checksums) ; contrôleur construit par PHP-DI et appelé (data,
 * decision, finish ; droit « logistics » 200, sans droit 403) ; enregistrement (catalogue, droits, routes, paramètres,
 * équipe, menu) ; migration 229 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_pallets_returns_test recréée (tables des migrations 082, 084 et 195 telles qu'elles sont écrites + tables
 * minimales + migration 229), supprimée à la fin (PR_JSON_OUT=fichier : données de la page avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Logistics\PalletsReturnsController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\PalletsReturnsRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\PalletsReturnsService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_pallets_returns_test'); $pdo->exec('CREATE DATABASE erp_pallets_returns_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_pallets_returns_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL, phone_head VARCHAR(30) NULL) ENGINE=InnoDB',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), kind VARCHAR(20) DEFAULT 'erp', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
] as $sql) $pdo->exec($sql);
// Tables de la tablette chauffeur exactement comme leurs migrations les créent : 082 (CREATE, l'ALTER de n_prod_device est
// hors sujet), 084 (statut « arrived ») et 195 (pallets_note, incidents) en entier.
$run = function (string $file, bool $createsOnly = false) use ($pdo) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $file)) as $sql) {
        if ($createsOnly && !preg_match('/^(CREATE TABLE|INSERT INTO)/', ltrim($sql))) continue;
        $pdo->exec($sql);
    }
};
$run('082_driver_tablet.sql', true);
$run('084_driver_photos_instructions.sql');
$run('195_driver_pallets_incidents.sql');
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'LOGISTYKA', 'Logistyka', 1), (2, 'KIEROWCA', 'Kierowca-dostawca', 2), (3, 'PIEKARZ', 'Piekarz', 3)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '6', NULL, 'Palety zwrotne', 'weekly'), (2, '6', NULL, 'Palety zwrotne', 'weekly'),
    (1, '7', NULL, NULL, 'none'), (1, '5', 'deliveries_delays_checked', NULL, 'daily'), (2, '7', NULL, 'HACCP miesiąca', 'monthly')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/229_pallets_returns.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 229 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_map(fn($d) => [$d['wizard_code'], $d['proposed_wizard']], array_slice($duties, 0, 2)) === array_fill(0, 2, ['pallets_returns_reviewed', null]),
    'migration 229: LOGISTYKA 6, KIEROWCA 6 → pallets_returns_reviewed');
$check($duties[2]['wizard_code'] === null && $duties[2]['proposed_wizard'] === null && $duties[3]['wizard_code'] === 'deliveries_delays_checked' && $duties[4]['proposed_wizard'] === 'HACCP miesiąca',
    'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'pallets_returns.%' ORDER BY code")->fetchAll(), 'value', 'code');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_pallet_return_review'")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_pallet_return_review'")->fetchColumn();
$check($params === ['pallets_returns.balance_weeks' => '8', 'pallets_returns.threshold' => '4'] && $tables === 1 && $fks === 0, 'migration replayed: 2 params once, 1 table, no foreign key');
$last = array_values(array_filter(MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/229_pallets_returns.sql')), fn($s) => trim($s) !== ''));
$check(trim(end($last), " \n;") === "UPDATE n_position_duty SET wizard_code = 'pallets_returns_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'Palety zwrotne'", 'migration: last statement is the duty UPDATE');
$check(ParamRules::RANGES['pallets_returns.balance_weeks'] === [1, 52, true] && ParamRules::RANGES['pallets_returns.threshold'] === [1, 200, true], 'ParamRules bounds for pallets_returns.*');

$lw = new LightWizardRepository($pdo);
$make = fn() => new PalletsReturnsService(new PalletsReturnsRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';

$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'ewa', 'Ewa L.'), (3, 'adam', 'Adam K.'), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, name, surname, phone_head) VALUES (1, 'Bistro Lumière', NULL, NULL, '+48 600 000 001'), (2, '', 'Jan', 'Kowal', NULL), (3, 'Hotel Vistule', NULL, NULL, NULL),
    (4, 'Café Mazur', NULL, NULL, NULL), (5, 'Piekarnia Nowa', NULL, NULL, NULL)");

// --- Sources vides : aucun arrêt, puis arrêts sans palettes saisies → « brak danych — tablet kierowcy nie zapisuje palet »
$e = $svc->data($today);
$check($e['available'] === false && $e['sources']['pallets']['note'] === 'brak danych — tablet kierowcy nie zapisuje palet' && $e['review'] === [] && $e['can_finish'] === true
    && $e['summary'] === 'tydzień 16.11 – 22.11 : brak danych — tablet kierowcy nie zapisuje palet · nic do wyjaśnienia', 'no stop → « brak danych — tablet kierowcy nie zapisuje palet »');
$pdo->exec("INSERT INTO n_delivery_stop (id, ship_date, id_customer, status, recorded_at, driver_name) VALUES (90, '2026-11-17', 3, 'delivered', '2026-11-17 09:00:00', 'Adam')");
$e = $svc->data($today);
$check($e['available'] === false && $e['sources']['pallets']['rows'] === 1 && $e['sources']['pallets']['with_pallets'] === 0 && $e['balances'] === [] && $e['totals']['week_stops'] === 1,
    'stops with empty pallet columns everywhere → still « brak danych »');
$pdo->exec('DELETE FROM n_delivery_stop WHERE id = 90');
$pdo->exec('RENAME TABLE n_delivery_incident TO n_delivery_incident_x');
$pdo->exec('RENAME TABLE n_delivery_stop TO n_delivery_stop_x');
$x = $make()->data($today);
$check($x['sources']['pallets']['note'] === 'brak danych — brak tabeli w bazie' && $x['sources']['damaged']['note'] === 'brak danych — brak tabeli w bazie' && $x['sources']['pallets']['rows'] === null,
    'missing tables → « brak danych — brak tabeli w bazie »');
$pdo->exec('RENAME TABLE n_delivery_stop_x TO n_delivery_stop');
$pdo->exec('RENAME TABLE n_delivery_incident_x TO n_delivery_incident');

// --- Données fictives : contrôle le mar. 24.11.2026 → semaine examinée 16.11 – 22.11, solde sur 8 semaines (28.09 → 22.11).
$st = $pdo->prepare('INSERT INTO n_delivery_stop (id, ship_date, id_customer, status, reason, pallets_delivered, pallets_returned, pallets_note, note, recorded_at, driver_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, '2026-10-05', 1, 'delivered', null, 3, 0, null, null, '2026-10-05 09:00:00', 'Tomasz'],
    [2, '2026-10-19', 1, 'delivered', null, 3, 1, null, null, '2026-10-19 09:00:00', 'Tomasz'],
    [3, '2026-11-02', 1, 'delivered', null, 2, 1, null, null, '2026-11-02 09:00:00', 'Tomasz'],
    [4, '2026-11-18', 1, 'delivered', null, 2, 0, null, 'Klient bez pustych palet', '2026-11-18 09:00:00', 'Tomasz'],   // brak palet, bez uzasadnienia
    [5, '2026-10-12', 2, 'delivered', null, 2, 2, null, null, '2026-10-12 10:00:00', 'Adam'],
    [6, '2026-11-20', 2, 'delivered', null, 2, 1, 'Klient odda w piątek', null, '2026-11-20 10:00:00', 'Adam'],       // uzasadnione
    [7, '2026-10-08', 3, 'delivered', null, 3, 0, null, null, '2026-10-08 11:00:00', 'Adam'],
    [8, '2026-11-17', 3, 'delivered', null, 1, 1, null, null, '2026-11-17 11:00:00', 'Adam'],
    [9, '2026-09-15', 4, 'delivered', null, 5, 0, null, null, '2026-09-15 08:00:00', 'Tomasz'],                       // poza oboma okresami
    [10, '2026-09-25', 4, 'delivered', null, 4, 0, null, null, '2026-09-25 08:00:00', 'Tomasz'],                      // tylko poprzednie saldo
    [11, '2026-11-03', 4, 'delivered', null, 0, 4, null, null, '2026-11-03 08:00:00', 'Tomasz'],
    [12, '2026-11-16', 5, 'problem', 'closed', null, null, null, 'Zamknięte', '2026-11-16 07:00:00', 'Adam'],
    [13, '2026-11-19', 5, 'arrived', null, 5, 0, null, null, '2026-11-19 07:00:00', 'Adam'],                         // niepotwierdzony
    [14, '2026-11-21', 5, 'delivered', null, 6, null, null, null, '2026-11-21 07:30:00', 'Adam'],                     // zwrot niewpisany
] as $row) $st->execute($row);
$inc = $pdo->prepare('INSERT INTO n_delivery_incident (id, id_stop, kind, note, recorded_at, driver_name) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([[41, 6, 'pallet_damaged', 'Paleta pęknięta przy rozładunku', '2026-11-20 10:05:00', 'Adam'], [42, 8, 'temperature', 'Chłodnia +9 °C', '2026-11-17 11:05:00', 'Adam'],
    [43, 2, 'pallet_damaged', 'Stara', '2026-10-19 09:05:00', 'Tomasz']] as $row) $inc->execute($row);
// Décision de la semaine précédente (9.11) pour Bistro Lumière : montrée comme « poprzedni tydzień ».
$pdo->exec("INSERT INTO n_pallet_return_review (week_start, id_customer, decision, note, balance, flags, id_user) VALUES ('2026-11-09', 1, 'reminder', 'Odbiór 4 palet w czwartek', 6, 'balance_high', 2)");

$erpTables = ['n_delivery_stop', 'n_delivery_incident', 'n_delivery_media', 'n_delivery_pallet_scan', 'n_delivery_run', 'customer', 'user', 'n_position'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();

$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$check(PalletsReturnsService::reviewedWeek('2026-11-24') === '2026-11-16' && PalletsReturnsService::reviewedWeek('2026-11-22') === '2026-11-09' && PalletsReturnsService::reviewedWeek('2027-01-04') === '2026-12-28',
    'reviewed week: the previous Monday → Sunday');
$d = $svc->data($today);
$boot = $d;
$check($d['week_start'] === '2026-11-16' && $d['week_end'] === '2026-11-22' && $d['week_label'] === '16.11 – 22.11.2026' && $d['balance_from'] === '2026-09-28' && $d['balance_weeks'] === 8 && $d['threshold'] === 4
    && $d['available'] === true && $d['sources']['pallets']['note'] === null, 'week, balance window (8 weeks), parameters, data available');
$by = array_column($d['balances'], null, 'id');
$check(array_column($d['balances'], 'id') === [1, 5, 3, 2, 4] && $by[1]['name'] === 'Bistro Lumière' && $by[2]['name'] === 'Jan Kowal', 'balances sorted (highest first) ; customer name as in DeliveryRepository');
$check([$by[1]['delivered'], $by[1]['returned'], $by[1]['balance'], $by[1]['prev_balance'], $by[1]['delta']] === [10, 2, 8, 6, 2]
    && [$by[4]['balance'], $by[4]['prev_balance'], $by[4]['delta']] === [-4, 0, -4] && [$by[5]['balance'], $by[5]['delta']] === [6, 6] && [$by[3]['balance'], $by[3]['delta']] === [3, 0]
    && [$by[2]['balance'], $by[2]['delta']] === [1, 1], 'balance = delivered − returned (8 weeks) ; previous week window ; stop before both windows ignored ; empty return = 0');
$check(count($by[5]['week_stops']) === 2 && !in_array(13, array_column($by[5]['week_stops'], 'id'), true) && $by[5]['week_stops'][0]['status_label'] === 'problem : Zamknięte'
    && $by[1]['week_stops'][0]['link']['href'] === '/logistics/deliveries?date=2026-11-18', '« arrived » stop ignored ; problem stop with reason ; link to Dostawy › potwierdzenia');
$check(array_column($d['review'], 'id') === [1, 5, 2], 'flagged customers by signal priority (missing pallet, damaged, balance) then balance');
$rv = array_column($d['review'], null, 'id');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check($codes($rv[1]) === ['missing', 'balance_high'] && $rv[1]['signals'][0]['detail'] === 'śr. 18.11 : 2 dostarczone, 0 zwróconych — brakuje 2 palet, bez uzasadnienia (Tomasz)'
    && $rv[1]['signals'][1]['detail'] === 'saldo 8 palet EUR (10 dostarczone − 2 zwrócone w 8 tygodniach) — próg 4', 'missing pallet without note + balance above threshold');
$check($codes($rv[5]) === ['missing', 'balance_high'] && $rv[5]['missing'][0]['short'] === 6 && $rv[5]['missing'][0]['returned'] === null, 'return not entered → counts 0, missing 6');
$check($codes($rv[2]) === ['damaged'] && $rv[2]['damaged'][0]['label'] === 'Uszkodzona paleta' && $rv[2]['damaged'][0]['note'] === 'Paleta pęknięta przy rozładunku' && $rv[2]['missing'] === []
    && !isset($rv[3]), 'damaged pallet of the week (label of the driver tablet) ; shortage with a note not flagged ; temperature and older damage ignored ; within threshold not flagged');
$check($rv[1]['previous']['code'] === 'reminder' && $rv[1]['previous']['balance'] === 6 && $rv[1]['decision'] === null && $rv[1]['waiting'], 'previous week decision shown');
$t = $d['totals'];
$check([$t['balance'], $t['prev_balance'], $t['delta'], $t['balance_positive'], $t['customers_positive'], $t['customers_over']] === [14, 9, 5, 18, 4, 2]
    && [$t['week_delivered'], $t['week_returned'], $t['week_stops'], $t['week_stops_pallets'], $t['missing_stops'], $t['missing_pallets'], $t['damaged']] === [11, 2, 5, 4, 2, 8, 1]
    && $t['review'] === 3 && $t['waiting'] === 3 && $t['by_code'] === ['missing' => 2, 'damaged' => 1, 'balance_high' => 2] && $d['can_finish'] === false, 'totals');
$sr = array_column($d['series'], null, 'week_start');
$check(count($d['series']) === 8 && $d['series'][0]['week_start'] === '2026-09-28' && $sr['2026-11-16']['reviewed'] && [$sr['2026-11-16']['delivered'], $sr['2026-11-16']['returned'], $sr['2026-11-16']['stops']] === [11, 2, 5]
    && [$sr['2026-11-02']['delivered'], $sr['2026-11-02']['returned']] === [2, 5], 'weekly series of the balance window');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_customer' => 1, 'decision' => 'sms'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_customer' => 1, 'decision' => 'reminder', 'note' => ' '], 2, $today)), 'custom:422:Przypomnienie / odbiór zaplanowany : wpisz'), 'reminder needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_customer' => 5, 'decision' => 'written_off', 'note' => 'x'], 2, $today)), 'custom:422:Spisane / do faktury : wpisz'), 'written off needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_customer' => 3, 'decision' => 'agreed'], 2, $today)), 'custom:422:Tego klienta nie ma na liście'), 'customer without signal refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 3 klientów'), 'finish blocked while customers wait');
$check($erp0 === $sum() && (int) $pdo->query("SELECT COUNT(*) FROM n_pallet_return_review WHERE week_start = '2026-11-16'")->fetchColumn() === 0, 'refused decisions write nothing');
$d = $svc->saveDecision(['id_customer' => 1, 'decision' => 'reminder', 'note' => 'Odbiór 8 palet w czwartek 26.11 (Tomasz)'], 2, $today);
$row = $pdo->query("SELECT * FROM n_pallet_return_review WHERE week_start = '2026-11-16' AND id_customer = 1")->fetch();
$check($row['decision'] === 'reminder' && (int) $row['balance'] === 8 && $row['flags'] === 'missing,balance_high' && (int) $row['id_user'] === 2, 'reminder saved for the reviewed week with balance and signals');
$check(array_column($d['review'], null, 'id')[1]['decision']['by'] === 'Ewa L.' && $d['totals']['waiting'] === 2, 'decision shown with its author');
$st = $tasks->state(PalletsReturnsService::CODE, '2026-11-23 00:00:00', '2026-11-30 00:00:00', '2026-11-24');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 klient wyjaśniony', 'task state: in progress (decisions for the reviewed week)');
$d = $svc->saveDecision(['id_customer' => 2, 'decision' => 'agreed'], 2, $today);
$d = $svc->saveDecision(['id_customer' => 5, 'decision' => 'written_off', 'note' => '6 palet do faktury — klient nie oddaje'], 2, $today);
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && [$d['totals']['reminder'], $d['totals']['agreed'], $d['totals']['written_off'], $d['totals']['reminder_balance']] === [1, 1, 1, 8]
    && $pdo->query("SELECT note FROM n_pallet_return_review WHERE id_customer = 2 AND week_start = '2026-11-16'")->fetchColumn() === null, 'all decided → step 3 open ; agreed without note');
$erp2 = $sum();
$check($erp0 === $erp2, 'decisions write nothing in the source tables');
$r = $svc->finish(2, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'pallets_returns_reviewed'")->fetchAll();
$check($r['summary'] === 'tydzień 16.11 – 22.11 : saldo 14 palet EUR u 4 klientów (+5 vs poprzedni tydzień) · 8 brakujących bez uzasadnienia · 1 uszkodzona · 3 do wyjaśnienia : 1 przypomnienie · 1 uzgodnione · 1 spisane'
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null, 'finish: summary, n_wizard_done');
$st = $tasks->state(PalletsReturnsService::CODE, '2026-11-23 00:00:00', '2026-11-30 00:00:00', '2026-11-24');
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done this week');
$check($tasks->state(PalletsReturnsService::CODE, '2026-11-30 00:00:00', '2026-12-07 00:00:00', '2026-12-01')['done'] === false, 'next week: task open again');
$check($erp2 === $sum(), 'finish writes nothing in the source tables');

// Semaine suivante (mar. 1.12) : semaine 23.11 – 29.11 ; Bistro Lumière toujours au-dessus du seuil → revient, avec la décision précédente.
$n = $make()->data('2026-12-01');
$nx = array_column($n['review'], null, 'id');
$check($n['week_start'] === '2026-11-23' && array_keys($nx) === [1, 5] && $nx[1]['waiting'] && array_column($nx[1]['signals'], 'code') === ['balance_high'] && $nx[1]['previous']['code'] === 'reminder'
    && $nx[5]['previous']['code'] === 'written_off' && $n['done'] === null, 'next week: customers still above threshold come back with last week decision');
$pdo->exec("UPDATE n_prod_param SET value = '10' WHERE code = 'pallets_returns.threshold'");
$check(array_column(array_column($make()->data($today)['review'], null, 'id')[1]['signals'], 'code') === ['missing'], 'threshold = 10 → balance 8 no longer a signal');
$pdo->exec("UPDATE n_prod_param SET value = '999' WHERE code = 'pallets_returns.threshold'");
$check($make()->param('threshold') === 4, 'out of bounds → default 4');
$pdo->exec("UPDATE n_prod_param SET value = '4' WHERE code = 'pallets_returns.threshold'");
$pdo->exec("UPDATE n_prod_param SET value = '4' WHERE code = 'pallets_returns.balance_weeks'");
$w4 = array_column($make()->data($today)['balances'], null, 'id');
$check($w4[1]['balance'] === 3 && count($make()->data($today)['series']) === 4, 'balance_weeks = 4 → balance from 26.10 only');
$pdo->exec("UPDATE n_prod_param SET value = '8' WHERE code = 'pallets_returns.balance_weeks'");

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_pallets_returns_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(PalletsReturnsController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 1), (3, 2), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'logistics'), (2, 'logistics'), (2, 'tablet'), (3, 'production'), (3, 'tablet')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
$real = PalletsReturnsService::reviewedWeek(date('Y-m-d'));
[$c2, $b2] = $call(2, 'data');
$check($c2 === 200 && $b2['data']['week_start'] === $real && isset($b2['data']['totals']['balance']) && $b2['data']['deliveries_link'] === '/logistics/deliveries',
    'controller data() (Logistyka, right « logistics ») : 200, real reviewed week');
[$c3] = $call(3, 'data');
[$c1] = $call(1, 'data');
$check($c3 === 200 && $c1 === 200, 'controller data() (Kierowca-dostawca with « logistics », administrator) : 200');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do logistyki (palety zwrotne).' && $cD === 403 && $cF === 403, 'controller: Piekarz without « logistics » → 403 for data(), decision() and finish()');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() (Logistyka) really called : done or blocked by waiting customers');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/PalletsReturnsService.php', 'App/Repositories/Logistics/PalletsReturnsRepository.php',
    'App/Controllers/Web/Logistics/PalletsReturnsController.php', 'public/assets/js/logistics/pallets-returns.js']));
$check(!preg_match('/UPDATE\s+(n_delivery|customer)|INSERT\s+INTO\s+(n_delivery|customer)|DELETE\s+FROM|Mail|Sms|curl_|https?:\/\/|PATCH|phone/i', $src), 'no write to deliveries / customers, no mail/SMS, no phone, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('pallets_returns_reviewed')['link'] === '/logistics/pallets-returns' && TaskWizards::byLink('/logistics/pallets-returns') === 'pallets_returns_reviewed'
    && isset(TaskWizards::AUTO['pallets_returns_reviewed']) && PositionTaskService::LINK_AUTO['/logistics/pallets-returns'] === 'pallets_returns_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['pallets_returns_reviewed'][0] === 'Palety zwrotne (saldo klientów)' && TaskWizards::CATALOG['pallets_returns_reviewed'][0] !== 'Palety zwrotne', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/logistics/pallets-returns') === 'logistics' && PageAccess::keyFor('/ajax/logistics/pallets-returns') === 'logistics' && PageAccess::keyFor('/ajax/logistics/pallets-returns/decision') === 'logistics'
    && PageAccess::keyFor('/logistics/deliveries') === 'logistics' && PageAccess::keyFor('/logistics/stock') === 'stock_pallets' && PageAccess::keyFor('/ajax/logistics/delays') === 'logistics',
    'page and ajax under the existing « logistics » right ; others unchanged');
$check(count(PageAccess::PAGES) === count(array_unique(array_keys(PageAccess::PAGES))) && !isset(PageAccess::PAGES['pallets_returns']), 'no new right');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'pallets_returns_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/logistics/pallets-returns'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Logistics/PalletsReturnsRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/logistics/pallets-returns') === 'index' && $found('GET', '/ajax/logistics/pallets-returns') === 'data'
    && $found('POST', '/ajax/logistics/pallets-returns/decision') === 'decision' && $found('POST', '/ajax/logistics/pallets-returns/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/logistics/pallets-returns')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/logistics/pallets-returns')[1]['controller'] === PalletsReturnsController::class
    && $allR->dispatch('GET', '/logistics/delays')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/logistics/deliveries')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing routes unchanged');

if (getenv('PR_JSON_OUT')) file_put_contents((string) getenv('PR_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_pallets_returns_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
