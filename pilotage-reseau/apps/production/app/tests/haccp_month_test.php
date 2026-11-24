<?php
/**
 * [380] Asystent « HACCP miesiąca » : mois examiné (le précédent) + mois en cours jusqu'à aujourd'hui ; signaux —
 * rupture de la chaîne du froid non traitée (aussi plus ancienne, haccp_month.backlog_months), résultat défavorable,
 * audit sous le seuil du plan de l'année, SOP en vigueur en retard de revue ou sans date (brouillon et archive ignorés),
 * exécution SOP avec écart (étape HACCP retrouvée par position et titre, étape renommée sans étiquette), incident
 * « Temperatura » de la tablette chauffeur (autres incidents ignorés) ; « brak danych » pour une source sans table ou vide ;
 * tendance par mois ; décisions (corrective avec note obligatoire et responsable / poste, NC, wyjaśnione), étape 3 bloquée,
 * « Zakończ » → n_wizard_done, état de la tâche du mois ; mois suivant : les éléments ponctuels décidés ne reviennent pas,
 * la SOP en retard revient ; aucune écriture dans les tables d'origine (checksums) ; contrôleur construit par PHP-DI et
 * appelé (data, decision, finish ; droit « production » 200, sans droit 403 ; liens filtrés par les droits) ;
 * enregistrement (catalogue, droits, routes, paramètres, équipe, menu) ; migration 228 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_haccp_month_test recréée (tables des migrations 041, 082, 195 et 210 telles qu'elles sont écrites + tables
 * minimales + migration 228), supprimée à la fin (HM_JSON_OUT=fichier : données de la page avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Production\HaccpMonthController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\{HaccpMonthRepository, ParamRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\HaccpMonthService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_haccp_month_test'); $pdo->exec('CREATE DATABASE erp_haccp_month_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_haccp_month_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL)',
    // n_nc : colonnes de docs/db/n_schema.sql utiles ici.
    "CREATE TABLE n_nc (id INT AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20) NOT NULL, reported_at DATE NOT NULL, source ENUM('supplier_delivery','production','customer','internal') NOT NULL,
        description TEXT NOT NULL, status ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open')",
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
// Tables d'origine exactement comme leurs migrations les créent (041 renommée en n_ par 069 ; 082 et 195 tablette chauffeur ; 210 SOP).
$creates = function (string $file, array $only = []) use ($pdo) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $file)) as $sql) {
        if (!preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m) || ($only && !in_array($m[1], $only, true))) continue;
        $pdo->exec(str_replace('tfb_mgr_', 'n_mgr_', $sql));
    }
};
$creates('041_manager_module.sql', ['tfb_mgr_plan', 'tfb_mgr_haccp_event']);
$creates('082_driver_tablet.sql');
$creates('195_driver_pallets_incidents.sql');
$creates('210_sop.sql');
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2),
    (3, 'PIEKARZ', 'Piekarz', 7), (4, 'BRYGADZISTA', 'Brygadzista zmiany', 6), (5, 'KIEROWCA', 'Kierowca-dostawca', 9), (6, 'ZAKUPY', 'Zakupy', 10)");
$pdo->exec("UPDATE n_position SET active = 0 WHERE id = 6");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '1', NULL, 'HACCP miesiąca', 'monthly'), (2, '5', NULL, 'HACCP miesiąca', 'monthly'),
    (4, '4', NULL, 'HACCP miesiąca', 'monthly'), (5, '7', NULL, 'HACCP miesiąca', 'monthly'), (1, '4', NULL, NULL, 'none'), (1, '2', 'nc_reviewed', NULL, 'monthly'), (1, '9', NULL, 'Inny asystent', 'weekly')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/228_haccp_month.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 228 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_map(fn($d) => [$d['wizard_code'], $d['proposed_wizard']], array_slice($duties, 0, 4)) === array_fill(0, 4, ['haccp_month_reviewed', null]),
    'migration 228: JAKOSC 1, KIEROWNIK_PRODUKCJI 5, BRYGADZISTA 4, KIEROWCA 7 → haccp_month_reviewed');
$check($duties[4]['wizard_code'] === null && $duties[4]['proposed_wizard'] === null && $duties[5]['wizard_code'] === 'nc_reviewed' && $duties[6]['proposed_wizard'] === 'Inny asystent', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'haccp_month.%' ORDER BY code")->fetchAll(), 'value', 'code');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_haccp_month_review'")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_haccp_month_review'")->fetchColumn();
$check($params === ['haccp_month.backlog_months' => '12', 'haccp_month.trend_months' => '3'] && $tables === 1 && $fks === 0, 'migration replayed: 2 params once, 1 table, no foreign key');
$check(ParamRules::RANGES['haccp_month.backlog_months'] === [1, 36, true] && ParamRules::RANGES['haccp_month.trend_months'] === [1, 12, true], 'ParamRules bounds for haccp_month.*');

$lw = new LightWizardRepository($pdo);
$make = fn() => new HaccpMonthService(new HaccpMonthRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';

// --- Sources vides : « brak danych » (tables présentes mais personne n'y a rien enregistré)
$e = $svc->data($today);
$check($e['review'] === [] && $e['can_finish'] === true && array_map(fn($s) => $s['available'], $e['sources']) === ['haccp' => false, 'sop' => false, 'sop_haccp' => false, 'sop_run' => false, 'temp' => false, 'hala_chill' => false, 'hala_clean' => false]
    && $e['sources']['haccp']['note'] === 'brak danych — nikt nie wpisuje zdarzeń HACCP (moduł Kierownik produkcji)' && $e['trend']['rows'][0]['values'] === [null, null, null, null, null]
    && $e['summary'] === 'październik 2026 : brak sygnałów HACCP', 'empty sources → « brak danych », nothing to review, trend without data');
$pdo->exec('RENAME TABLE n_delivery_incident TO n_delivery_incident_x');
$x = $make()->data($today);
$check($x['sources']['temp']['rows'] === null && $x['sources']['temp']['note'] === 'brak danych — brak tabeli w bazie' && $x['trend']['rows'][2]['available'] === false, 'missing table → « brak danych — brak tabeli w bazie »');
$pdo->exec('RENAME TABLE n_delivery_incident_x TO n_delivery_incident');

// --- Données fictives : contrôle le mar. 24.11.2026 → mois examiné październik 2026 (+ listopad jusqu'au 24.11).
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'ewa', 'Ewa L.'), (3, 'marek', 'Marek K.'), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, name, surname) VALUES (1, 'Bistro Lumière', NULL, NULL), (2, '', 'Jan', 'Kowal'), (3, 'Hotel Vistule', NULL, NULL)");
$pdo->exec("INSERT INTO n_nc (id, number, reported_at, source, description) VALUES (20, 'NC/2026/020', '2026-10-14', 'internal', 'SOP-JAK-001 — wartość poza zakresem')");
$pdo->exec("INSERT INTO n_mgr_plan (year, audit_threshold) VALUES (2026, 85)");
$ev = $pdo->prepare('INSERT INTO n_mgr_haccp_event (id, event_date, kind, authority, result, score, treated, treated_at, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, '2026-10-05', 'cold_chain_break', null, null, null, 0, null, 'Chłodnia 2 : +8 °C przez 3 h w nocy', 3],
    [2, '2026-10-12', 'official_control', 'Sanepid', 'unfavorable', null, 0, null, 'Brak zapisów temperatur w weekend', 2],
    [3, '2026-10-20', 'independent_audit', 'Bureau Qualité', 'favorable', 78, 0, null, null, 2],
    [4, '2026-10-25', 'self_check', null, 'favorable', null, 0, null, 'Samokontrola higieny', 2],
    [5, '2026-10-28', 'cold_chain_break', null, null, null, 1, '2026-10-29', 'Mroźnia : drzwi otwarte 20 min', 3],
    [6, '2026-06-10', 'cold_chain_break', null, null, null, 0, null, 'Zamrażarka próbek', 3],          // zaległe (12 mies.)
    [7, '2025-08-01', 'cold_chain_break', null, null, null, 0, null, 'Stare', 3],                      // poza haccp_month.backlog_months
    [8, '2026-11-10', 'self_check', null, 'unfavorable', null, 0, null, 'Etykiety alergenów', 2],    // bieżący miesiąc
    [9, '2026-09-15', 'official_control', 'Sanepid', 'unfavorable', null, 1, '2026-09-20', null, 2], // przed miesiącem : tylko trend
] as $row) $ev->execute($row);
$sop = $pdo->prepare('INSERT INTO n_sop (id, code, title, area, status, version, changed, review_months, review_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
foreach ([[1, 'SOP-JAK-001', 'Próbki produkcji : wybór, wydruk A4, odłożenie', 'Wszystkie grupy', 'active', 2, 12, '2026-11-01'],
    [2, 'SOP-ZAK-001', 'Ceny surowców z faktur zakupu', 'Zaopatrzenie', 'active', 1, 12, '2027-03-01'],
    [3, 'SOP-PROD-002', 'Mycie pieca', null, 'draft', 0, 12, '2026-01-01'],
    [4, 'SOP-BHP-001', 'Krajalnica', 'Garmażerka', 'active', 1, 6, null],
    [5, 'SOP-JAK-003', 'Stara procedura', null, 'archived', 3, 12, '2025-05-01']] as $row) $sop->execute($row);
$st = $pdo->prepare('INSERT INTO n_sop_step (id_sop, sort, title, tag, confirm, value_min, value_max, value_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[1, 1, 'Otwórz Partie i identyfikowalność › Próbki', null, 'check', null, null, null], [1, 2, 'Sprawdź partie', null, 'check', null, null, null],
    [1, 3, 'Utwórz próbki', null, 'check', null, null, null], [1, 4, 'Pobierz próbkę z partii i zapakuj z kartą A4', 'haccp', 'photo', null, null, null],
    [1, 5, 'Odłóż do zamrażarki próbek', 'haccp', 'value', null, -18, '°C'], [1, 6, 'Zeskanuj QR karty i zakończ', null, 'check', null, null, null],
    [2, 1, 'Otwórz asystenta', null, 'check', null, null, null], [2, 2, 'Porównaj cenę pozycji', 'quality', 'value', 0, 10, '%'],
    [3, 1, 'Wyłącz piec', 'bhp', 'check', null, null, null]] as $row) $st->execute($row);
$run = $pdo->prepare('INSERT INTO n_sop_run (id, id_sop, version, source_path, id_user, started_at, finished_at, result, steps, id_nc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$j = fn(array $s) => json_encode($s, JSON_UNESCAPED_UNICODE);
foreach ([
    [11, 1, 2, '/workshop/traceability/samples', 3, '2026-10-14 07:50:00', '2026-10-14 08:05:00', 'deviation',
        $j([['step' => 4, 'title' => 'Pobierz próbkę z partii i zapakuj z kartą A4', 'confirm' => 'photo', 'ok' => true, 'photo_id' => 1], ['step' => 5, 'title' => 'Odłóż do zamrażarki próbek', 'confirm' => 'value', 'ok' => false, 'value' => -12.5, 'unit' => '°C']]), 20],
    [12, 2, 1, '/workshop/supply', 2, '2026-10-22 10:00:00', '2026-10-22 10:12:00', 'deviation', $j([['step' => 2, 'title' => 'Porównaj cenę pozycji', 'confirm' => 'value', 'ok' => false, 'value' => 14, 'unit' => '%']]), null],
    [13, 1, 2, null, 3, '2026-10-15 08:00:00', '2026-10-15 08:10:00', 'ok', $j([['step' => 5, 'title' => 'Odłóż do zamrażarki próbek', 'confirm' => 'value', 'ok' => true, 'value' => -20, 'unit' => '°C']]), null],
    [14, 1, 1, null, 3, '2026-11-05 08:00:00', '2026-11-05 08:10:00', 'deviation', $j([['step' => 5, 'title' => 'Odłóż do zamrażarki (stara wersja)', 'confirm' => 'value', 'ok' => false, 'value' => -10, 'unit' => '°C']]), null],
    [15, 1, 1, null, 3, '2026-09-30 08:00:00', '2026-09-30 08:10:00', 'deviation', $j([['step' => 5, 'title' => 'Odłóż do zamrażarki próbek', 'confirm' => 'value', 'ok' => false, 'value' => -15, 'unit' => '°C']]), null],
] as $row) $run->execute($row);
$stop = $pdo->prepare("INSERT INTO n_delivery_stop (id, ship_date, id_customer, status, recorded_at, driver_name) VALUES (?, ?, ?, 'delivered', ?, ?)");
foreach ([[31, '2026-10-08', 1, '2026-10-08 10:30:00', 'Tomasz'], [32, '2026-11-03', 2, '2026-11-03 09:00:00', 'Tomasz'], [33, '2026-10-09', 3, '2026-10-09 11:00:00', 'Adam'],
    [34, '2026-08-14', 1, '2026-08-14 11:00:00', 'Adam']] as $row) $stop->execute($row);
$inc = $pdo->prepare('INSERT INTO n_delivery_incident (id, id_stop, kind, note, recorded_at, driver_name) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([[41, 31, 'temperature', 'Chłodnia +9 °C przy otwarciu drzwi', '2026-10-08 10:20:00', 'Tomasz'], [42, 33, 'pallet_damaged', 'Paleta pęknięta', '2026-10-09 10:50:00', 'Adam'],
    [43, 32, 'temperature', null, '2026-11-03 08:55:00', 'Tomasz'], [44, 34, 'temperature', 'Agregat', '2026-08-14 10:55:00', 'Adam']] as $row) $inc->execute($row);

$erpTables = ['n_mgr_haccp_event', 'n_mgr_plan', 'n_sop', 'n_sop_step', 'n_sop_run', 'n_sop_event', 'n_delivery_stop', 'n_delivery_incident', 'n_nc', 'customer', 'user', 'n_position'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();

$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$check(HaccpMonthService::reviewedMonth('2026-11-24') === '2026-10-01' && HaccpMonthService::reviewedMonth('2026-01-03') === '2025-12-01', 'reviewed month: the previous one');
$d = $svc->data($today);
$boot = $d;
$check($d['month_start'] === '2026-10-01' && $d['month_end'] === '2026-10-31' && $d['month_label'] === 'październik 2026' && $d['backlog_months'] === 12 && $d['trend_months'] === 3,
    'month, label, parameters');
$keys = array_column($d['review'], 'key');
$check($keys === ['haccp:6', 'haccp:1', 'haccp:2', 'haccp:8', 'haccp:3', 'sop_run:11', 'temp:41', 'temp:43', 'sop_run:12', 'sop_run:14', 'sop_review:1:2026-11-01', 'sop_review:4:-'],
    'flagged items by signal priority, then backlog / state / month / current, then day');
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check($codes($rv['haccp:1']) === ['haccp_untreated'] && $rv['haccp:1']['period'] === 'month' && $rv['haccp:6']['period'] === 'backlog' && $rv['haccp:6']['date_label'] === 'śr. 10.06.2026'
    && !isset($rv['haccp:7']) && !isset($rv['haccp:5']), 'cold chain break untreated (also 5 months old) ; treated or older than 12 months not flagged');
$check($codes($rv['haccp:2']) === ['haccp_unfavorable'] && $rv['haccp:2']['signals'][0]['detail'] === 'Kontrola urzędowa (Sanepid) pon. 12.10 — wynik negatywny' && $rv['haccp:8']['period'] === 'current'
    && !isset($rv['haccp:9']), 'unfavorable control / self-check (current month caught up) ; September control only in the trend');
$check($codes($rv['haccp:3']) === ['haccp_below'] && $rv['haccp:3']['signals'][0]['detail'] === 'wynik 78 % — poniżej progu 85 % (plan 2026)', 'independent audit below the threshold of the year plan');
$check(array_column($d['info'], 'key') === ['haccp:4', 'haccp:5'] && $d['info'][1]['sub'] === 'obsłużone czw. 29.10', 'unflagged events of the month listed for information');
$check($codes($rv['sop_review:1:2026-11-01']) === ['sop_review_late'] && $rv['sop_review:1:2026-11-01']['late_days'] === 23 && $rv['sop_review:1:2026-11-01']['haccp'] === true
    && $codes($rv['sop_review:4:-']) === ['sop_review_missing'] && !isset($rv['sop_review:3:2026-01-01']) && !isset($rv['sop_review:5:2025-05-01']),
    'active SOP late for review (23 days) or without date ; draft and archived ignored ; SOP in time not flagged');
$check($codes($rv['sop_run:11']) === ['sop_deviation_haccp'] && $rv['sop_run:11']['signals'][0]['detail'] === 'etap 5 « Odłóż do zamrażarki próbek » : -12,5 °C (HACCP)' && $rv['sop_run:11']['nc'] === 'NC/2026/020'
    && $codes($rv['sop_run:12']) === ['sop_deviation'] && $rv['sop_run:12']['deviations'][0]['tag'] === 'quality'
    && $codes($rv['sop_run:14']) === ['sop_deviation'] && str_contains($rv['sop_run:14']['signals'][0]['detail'], '(etap zmieniony od wykonania)') && !isset($rv['sop_run:13']) && !isset($rv['sop_run:15']),
    'SOP deviations : HACCP step found by position and title, quality step, renamed step without tag ; ok run and September run ignored');
$check($codes($rv['temp:41']) === ['delivery_temp'] && $rv['temp:41']['title'] === 'Bistro Lumière' && $rv['temp:43']['title'] === 'Jan Kowal' && $rv['temp:43']['link']['href'] === '/logistics/deliveries?date=2026-11-03'
    && !isset($rv['temp:42']), 'temperature incidents (customer name as in DeliveryRepository) ; other incident kinds ignored');
$t = $d['totals'];
$check($t['review'] === 12 && $t['waiting'] === 12 && $t['by_kind'] === ['haccp_event' => 5, 'sop_review' => 2, 'sop_run' => 3, 'delivery_temp' => 2, 'hala_chill' => 0, 'hala_clean' => 0]
    && $t['month_by_kind'] === ['haccp_event' => 3, 'sop_review' => 2, 'sop_run' => 2, 'delivery_temp' => 1, 'hala_chill' => 0, 'hala_clean' => 0] && $t['current'] === 3 && $t['backlog'] === 1 && $t['month_events'] === 5
    && $t['month_runs_haccp'] === 1 && $t['sops_active'] === 3 && $d['can_finish'] === false && array_slice(array_column($d['sources'], 'available'), 0, 5) === [true, true, true, true, true]
    && $d['sources']['hala_chill']['note'] === 'brak danych — brak tabeli w bazie' && $d['sources']['hala_clean']['available'] === false, 'totals ; all sources available (tablet hali [409] : no table here, tests/hala_tablet_test.php)');
$tr = $d['trend'];
$check(array_column($tr['months'], 'key') === ['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] && $tr['months'][3]['reviewed'] && $tr['months'][4]['current']
    && array_column($tr['rows'], 'values', 'key') === ['haccp' => [0, 0, 1, 3, 1], 'sop_run' => [0, 0, 1, 2, 1], 'temp' => [0, 1, 0, 1, 1], 'hala_chill' => [null, null, null, null, null]]
    && $tr['rows'][0]['avg_prev'] === 0.3 && $tr['rows'][0]['dir'] === 'up' && $tr['rows'][2]['dir'] === 'up', 'trend: 3 previous months, reviewed month, current month');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'haccp:1', 'decision' => 'x'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'haccp:1', 'decision' => 'corrective', 'note' => ' '], 2, $today)), 'custom:422:Działanie korygujące : wpisz'), 'corrective action needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'haccp:4', 'decision' => 'explained'], 2, $today)), 'custom:422:Tego elementu nie ma na liście'), 'item without signal refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'haccp:1', 'decision' => 'corrective', 'note' => 'abc', 'id_responsible' => 99], 2, $today)), 'custom:422:Nieznana osoba'), 'unknown responsible refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'haccp:1', 'decision' => 'corrective', 'note' => 'abc', 'id_position' => 6], 2, $today)), 'custom:422:Nieznane stanowisko'), 'inactive position refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 12 elementów'), 'finish blocked while items wait');
$check($erp0 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_haccp_month_review')->fetchColumn() === 0, 'refused decisions write nothing');
$d = $svc->saveDecision(['key' => 'haccp:1', 'decision' => 'corrective', 'note' => 'Alarm temperatury na SMS kierownika do 15.12', 'id_responsible' => 3, 'id_position' => 2], 2, $today);
$row = $pdo->query("SELECT * FROM n_haccp_month_review WHERE item_key = 'haccp:1'")->fetch();
$check($row['month_start'] === '2026-10-01' && $row['kind'] === 'haccp_event' && (int) $row['source_id'] === 1 && $row['decision'] === 'corrective' && (int) $row['id_responsible'] === 3
    && (int) $row['id_position'] === 2 && $row['flags'] === 'haccp_untreated' && (int) $row['id_user'] === 2, 'corrective action saved for the reviewed month with responsible, position and signals');
$x = array_column($d['review'], null, 'key')['haccp:1']['decision'];
$check($x['responsible'] === 'Marek K.' && $x['position'] === 'Kierownik produkcji' && $x['by'] === 'Ewa L.', 'decision shown with responsible and position names');
$d = $svc->saveDecision(['key' => 'sop_run:11', 'decision' => 'nc', 'note' => 'NC/2026/020 — zamrażarka próbek', 'id_responsible' => 3, 'id_position' => 2], 2, $today);
$row = $pdo->query("SELECT * FROM n_haccp_month_review WHERE item_key = 'sop_run:11'")->fetch();
$check($row['decision'] === 'nc' && $row['id_responsible'] === null && $row['id_position'] === null, 'NC saved ; responsible / position only kept for a corrective action');
$st = $tasks->state(HaccpMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '2 elementy wyjaśnione', 'task state: in progress (decisions for the reviewed month)');
foreach (['haccp:6', 'haccp:2', 'haccp:8', 'haccp:3', 'temp:41', 'temp:43', 'sop_run:12', 'sop_run:14', 'sop_review:1:2026-11-01', 'sop_review:4:-'] as $k => $key) {
    $d = $svc->saveDecision(['key' => $key, 'decision' => $k % 3 === 0 ? 'corrective' : 'explained', 'note' => 'Notatka ' . $k], 2, $today);
}
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && $d['totals']['explained'] === 6 && $d['totals']['corrective'] === 5 && $d['totals']['nc'] === 1, 'all decided → step 3 open');
$erp2 = $sum();
$check($erp0 === $erp2, 'decisions write nothing in the source tables (HACCP events not marked « obsłużone »)');
$r = $svc->finish(2, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'haccp_month_reviewed'")->fetchAll();
$check($r['summary'] === 'październik 2026 : 5 zdarzeń HACCP · 2 SOP po terminie przeglądu · 3 odchylenia SOP · 2 temperatury w dostawie · 12 do wyjaśnienia : 6 wyjaśnionych · 5 działań korygujących · 1 NC'
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null, 'finish: summary, n_wizard_done');
$st = $tasks->state(HaccpMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done this month');
$check($tasks->state(HaccpMonthService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false, 'next month: task open again');
$check($erp2 === $sum(), 'finish writes nothing in the source tables');

// Mois suivant (mer. 2.12) : novembre examiné ; décidés une fois (événements, exécution, incident) → pas d'attente ; SOP en retard → revient.
$n = $make()->data('2026-12-02');
$nx = array_column($n['review'], null, 'key');
$check($n['month_start'] === '2026-11-01' && !$nx['haccp:8']['waiting'] && $nx['haccp:8']['decision'] === null && $nx['haccp:8']['earlier']['month'] === '2026-10'
    && $nx['haccp:1']['period'] === 'backlog' && !$nx['haccp:1']['waiting'] && !$nx['temp:43']['waiting'] && !$nx['sop_run:14']['waiting']
    && $nx['sop_review:1:2026-11-01']['waiting'] && $nx['sop_review:4:-']['waiting'] && $n['totals']['waiting'] === 2 && $n['totals']['earlier'] === 5 && $n['done'] === null,
    'next month: items decided once do not wait again ; SOP still late comes back');
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'haccp_month.backlog_months'");
$check(!isset(array_column($make()->data($today)['review'], null, 'key')['haccp:6']), 'backlog_months = 3 → June cold chain break no longer back');
$pdo->exec("UPDATE n_prod_param SET value = '99' WHERE code = 'haccp_month.backlog_months'");
$check($make()->param('backlog') === 12, 'out of bounds → default 12');
$pdo->exec("UPDATE n_prod_param SET value = '12' WHERE code = 'haccp_month.backlog_months'");

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_haccp_month_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(HaccpMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 1), (3, 2), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'production'), (2, 'production'), (2, 'sop'), (3, 'tablet'), (3, 'sop')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
$real = HaccpMonthService::reviewedMonth(date('Y-m-d'));
$links = fn(array $body) => array_values(array_unique(array_map(fn($i) => $i['link'] === null ? null : strtok($i['link']['href'], '?'), $body['data']['review'])));
$check($c2 === 200 && $b2['data']['month_start'] === $real && isset($b2['data']['totals']['by_kind']) && $b2['data']['nc_link'] === '/purchasing/nc#new'
    && !in_array('/sop?tab=runs', array_column(array_filter(array_column($b2['data']['review'], 'link')), 'href'), true), 'controller data() (Jakość, right « production ») : 200, real month ; NC link ; no SOP link without « sop »');
[$c3, $b3] = $call(3, 'data');
$sopLinks = array_filter(array_filter(array_column($b3["data"]["review"], "link")), fn($l) => str_starts_with($l['href'], '/sop'));
$hLinks = array_filter(array_filter(array_column($b3["data"]["review"], "link")), fn($l) => $l['href'] === '/workshop/manager');
$check($c3 === 200 && count($sopLinks) > 0 && count($hLinks) === 0, 'controller data() (Kierownik produkcji, « production » + « sop ») : 200, SOP links, no admin-only manager link');
[$c1, $b1] = $call(1, 'data');
$check($c1 === 200 && count(array_filter(array_filter(array_column($b1["data"]["review"], "link")), fn($l) => $l['href'] === '/workshop/manager')) > 0, 'controller data() (administrator) : 200 with every link');
[$c4, $b4] = $call(4, 'data');
[$cD, $bD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do produkcji (HACCP miesiąca).' && $cD === 403 && $cF === 403, 'controller: Piekarz without « production » → 403 for data(), decision() and finish()');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() (Jakość) really called : done or blocked by waiting items');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/HaccpMonthService.php', 'App/Repositories/Workshop/HaccpMonthRepository.php',
    'App/Controllers/Web/Production/HaccpMonthController.php', 'public/assets/js/production/haccp-month.js']));
$check(!preg_match('/UPDATE\s+n_(mgr|sop|delivery|nc)|INSERT\s+INTO\s+(n_mgr|n_sop|n_delivery|n_nc)|DELETE\s+FROM|treated\s*=\s*1|Mail|Sms|curl_|https?:\/\/|PATCH/i', $src), 'no write to HACCP / SOP / deliveries / NC, no mail/SMS, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('haccp_month_reviewed')['link'] === '/production/haccp-month' && TaskWizards::byLink('/production/haccp-month') === 'haccp_month_reviewed'
    && isset(TaskWizards::AUTO['haccp_month_reviewed']) && PositionTaskService::LINK_AUTO['/production/haccp-month'] === 'haccp_month_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['haccp_month_reviewed'][0] === 'HACCP miesiąca (przegląd)' && TaskWizards::CATALOG['haccp_month_reviewed'][0] !== 'HACCP miesiąca', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/production/haccp-month') === 'production' && PageAccess::keyFor('/ajax/production/haccp-month') === 'production' && PageAccess::keyFor('/ajax/production/haccp-month/decision') === 'production'
    && PageAccess::keyFor('/production/capacity/full') === 'capacity_full' && PageAccess::keyFor('/sop') === 'sop' && PageAccess::keyFor('/workshop/manager') === 'manager' && PageAccess::keyFor('/purchasing/nc') === 'production',
    'page and ajax under the existing « production » right ; others unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'haccp_month_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/production/haccp-month'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Production/HaccpMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/production/haccp-month') === 'index' && $found('GET', '/ajax/production/haccp-month') === 'data'
    && $found('POST', '/ajax/production/haccp-month/decision') === 'decision' && $found('POST', '/ajax/production/haccp-month/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/production/haccp-month')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/production/haccp-month')[1]['controller'] === HaccpMonthController::class
    && $allR->dispatch('GET', '/production/yield-review')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/sop')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing routes unchanged');

if (getenv('HM_JSON_OUT')) file_put_contents((string) getenv('HM_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_haccp_month_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
