<?php
/**
 * [379] Asystent « Reklamacje klientów » : semaine examinée (lundi → dimanche précédents) ; signaux — NEW / IN_REVIEW
 * depuis plus de complaints_review.open_days jours (reported_at, sinon acceptance_timestamp ; statut vide = NEW), ACCEPTED
 * sans production_response, ACCEPTED non vue par le groupe du produit (n_complaint_seen ; « Widziane » d'un autre groupe ne
 * compte pas), produit sans groupe, acceptées hors complaints_review.lookback_days ignorées ; semaine par type, motif
 * (ComplaintReasonTranslator), client, produit, groupe ; récurrences client + produit sur 8 semaines ; NC « klient »
 * rapprochées (même produit, 0–30 jours) ; montants seulement avec [312] (aucune colonne de valeur sinon) ; décisions
 * (odpowiedź vérifiée sur l'état réel, NC, do obserwacji avec note), étape 3 bloquée, « Zakończ » → n_wizard_done, état de
 * la tâche de la semaine ; aucune écriture hors des tables de l'assistant (statuts des réclamations inchangés) ; contrôleur
 * construit par PHP-DI et appelé (data, decision, finish ; droit « franchise » ; montants Finanse seulement) ;
 * enregistrement (catalogue, droits, routes, paramètres, équipe, menu) ; migration 227 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_complaints_review_test recréée (tables minimales + migrations 215 et 227), supprimée à la fin
 * (CR_JSON_OUT=fichier : données de la page avant les décisions, profil Jakość sans montants, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Client\Complaint\ComplaintsReviewController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\Complaint\ComplaintsReviewRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\ComplaintsReviewService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_complaints_review_test'); $pdo->exec('CREATE DATABASE erp_complaints_review_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_complaints_review_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80))',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), ppc DECIMAL(10,2) NULL, id_section INT NULL)',
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40))',
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT, ppc DECIMAL(10,2) NULL, price_carton DECIMAL(10,2))',
    // Colonnes de complaint : ComplaintRepository::$allowedColumns + acceptance_timestamp (getAll, detailRow, chartRows).
    "CREATE TABLE complaint (id INT AUTO_INCREMENT PRIMARY KEY, complaint_uuid CHAR(36) NULL, complaint_key VARCHAR(64) NULL, complaint_type ENUM('PRODUCT','NON_PRODUCT') NOT NULL DEFAULT 'PRODUCT',
        complaint_reason VARCHAR(64) NULL, requested_action VARCHAR(32) NULL, compensation_method VARCHAR(32) NULL, id_customer INT NULL, id_order INT NULL, invoice_num VARCHAR(255) NULL,
        id_product INT NULL, client_comment TEXT NULL, product_quantity DECIMAL(12,3) NULL, accepted_quantity DECIMAL(12,3) NULL, single_purchase_price DECIMAL(12,3) NULL,
        acceptance_status VARCHAR(16) NULL, acceptance_timestamp DATETIME NULL, production_response TEXT NULL, closed_on DATETIME NULL, notified_at DATETIME NULL, reported_at DATETIME NULL)",
    "CREATE TABLE n_prod_item (id INT PRIMARY KEY, id_final_product INT, item_type VARCHAR(20) DEFAULT 'product')",
    'CREATE TABLE n_prod_product_settings (id_item INT, id_primary_group INT NULL)',
    'CREATE TABLE n_prod_group (id INT PRIMARY KEY, name_pl VARCHAR(80), sort_order INT DEFAULT 0, active TINYINT DEFAULT 1, id_section INT NULL)',
    'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(60), surname VARCHAR(60))',
    'CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT)',
    // n_nc : colonnes de docs/db/n_schema.sql utiles ici.
    "CREATE TABLE n_nc (id INT AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20) NOT NULL, reported_at DATE NOT NULL, source ENUM('supplier_delivery','production','customer','internal') NOT NULL,
        id_final_product INT NULL, description TEXT NOT NULL, status ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open')",
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
$pdo->exec((string) file_get_contents(__DIR__ . '/../migrations/tfb/215_complaint_seen.sql'));
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'PIEKARZ', 'Piekarz', 7), (3, 'ADMINISTRACJA', 'Administracja i fakturowanie', 2)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '3', NULL, 'Reklamacje klientów', 'weekly'),
    (1, '5', NULL, 'Partie i kontrola dnia', 'daily'), (1, '2', 'nc_reviewed', NULL, 'monthly')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/227_complaints_review.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 227 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'complaints_reviewed' && $duties[0]['proposed_wizard'] === null, 'migration 227: JAKOSC 3 → complaints_reviewed');
$check($duties[1]['proposed_wizard'] === 'Partie i kontrola dnia' && $duties[1]['wizard_code'] === null && $duties[2]['wizard_code'] === 'nc_reviewed', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'complaints_review.%' ORDER BY code")->fetchAll(), 'value', 'code');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_complaint_review'")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_complaint_review'")->fetchColumn();
$check($params === ['complaints_review.lookback_days' => '56', 'complaints_review.open_days' => '3'] && $tables === 1 && $fks === 0, 'migration replayed: 2 params once, 1 table, no foreign key');
$check(ParamRules::RANGES['complaints_review.open_days'] === [1, 60, true] && ParamRules::RANGES['complaints_review.lookback_days'] === [7, 365, true], 'ParamRules bounds for complaints_review.*');

// --- Données fictives : contrôle le mar. 24.11.2026 → semaine examinée lun. 16.11 – niedz. 22.11.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'ewa', 'Ewa L.'), (3, 'anna', 'Anna K.'), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer VALUES (1, 'Bistro Lumière'), (2, 'Café Rivoli'), (3, 'Hotel Vistule'), (4, 'Sklep Pod Lipą')");
$pdo->exec("INSERT INTO final_product VALUES (1, 'Croissant maślany', 6, 1), (2, 'Tarta cytrynowa', 6, 2), (3, 'Bagietka wiejska', 10, 9)");
$pdo->exec("INSERT INTO n_prod_group (id, name_pl, sort_order, active, id_section) VALUES (10, 'PIEKARNIA', 1, 1, 1), (11, 'CUKIERNIA', 2, 1, 2), (12, 'ROGAL', 3, 1, NULL)");
// Croissant : groupe principal ROGAL (fiche produit) ; Tarta : pas de fiche → premier groupe actif de la section (CUKIERNIA) ; Bagietka : aucun groupe.
$pdo->exec("INSERT INTO n_prod_item VALUES (100, 1, 'product')"); $pdo->exec('INSERT INTO n_prod_product_settings VALUES (100, 12)');
$pdo->exec("INSERT INTO document_wdt VALUES (50, 'WDT/50/2026')"); $pdo->exec('INSERT INTO document_wdt_product (id_document_wdt, id_product, ppc, price_carton) VALUES (50, 1, 6, 30)');
$pdo->exec("INSERT INTO production_employee VALUES (7, 'Jan', 'Nowak')"); $pdo->exec('INSERT INTO n_prod_employee VALUES (70, 7)');
$cpl = $pdo->prepare('INSERT INTO complaint (id, complaint_type, complaint_reason, id_customer, invoice_num, id_product, client_comment, product_quantity, accepted_quantity, single_purchase_price,
    acceptance_status, acceptance_timestamp, production_response, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 'PRODUCT', 'product_quality', 1, 'WDT/50/2026', 1, 'Croissanty spalone od spodu', 7, null, 30, 'NEW', '2026-11-18 10:00:00', null, '2026-11-18 09:30:00'],
    [2, 'NON_PRODUCT', 'driver_issue', 2, null, null, 'Kierowca nie wniósł palety', null, null, null, 'IN_REVIEW', '2026-11-22 08:00:00', null, '2026-11-22 07:40:00'],
    [3, 'PRODUCT', 'broken_packaging', 1, 'WDT/50/2026', 1, 'Zgniecione kartony', 12, 12, 30, 'ACCEPTED', '2026-11-17 11:00:00', '', '2026-11-16 08:00:00'],
    [4, 'PRODUCT', 'size_or_weight_issue', 3, null, 2, null, 3, 3, 14, 'ACCEPTED', '2026-11-20 09:00:00', 'Wymiana w piątek', '2026-11-19 12:00:00'],
    [5, 'PRODUCT', 'product_quality', 4, null, 3, null, 20, 10, 9, 'ACCEPTED', '2026-11-12 09:00:00', 'Kredyt na konto', '2026-11-10 09:00:00'],
    [6, 'PRODUCT', 'product_quality', 1, null, 1, null, 6, null, 30, 'REJECTED', '2026-10-16 09:00:00', 'Brak zdjęć', '2026-10-15 09:00:00'],
    [7, 'PRODUCT', 'product_quality', 2, null, 2, null, 6, 6, 14, 'ACCEPTED', '2026-08-02 09:00:00', '', '2026-08-01 09:00:00'],   // poza 56 dniami
    [8, 'PRODUCT', 'other', 2, null, 2, null, 6, null, 14, 'NEW', '2026-11-23 09:00:00', null, '2026-11-23 08:00:00'],               // bieżący tydzień, 1 dzień
    [9, 'PRODUCT', null, 3, null, 2, null, 1, null, 14, null, '2026-11-01 09:00:00', null, null],                                   // statut vide, sans reported_at
    [10, 'PRODUCT', 'incorrect_information', 2, null, 1, null, 6, 6, 30, 'ACCEPTED', '2026-11-23 09:00:00', 'Etykiety poprawione', '2026-11-21 09:00:00'],
] as $row) $cpl->execute($row);
// « Widziane » : CUKIERNIA a vu la n° 4 ; PIEKARNIA (pas le groupe du croissant) a vu la n° 3 — ne compte pas.
$pdo->exec("INSERT INTO n_complaint_seen (id_complaint, id_group, id_employee, seen_at) VALUES (4, 11, 70, '2026-11-20 14:05:00'), (3, 10, 70, '2026-11-18 07:00:00')");
$pdo->exec("INSERT INTO n_nc (number, reported_at, source, id_final_product, description, status) VALUES ('NC/2026/014', '2026-11-19', 'customer', 1, 'Croissanty spalone', 'open'),
    ('NC/2026/015', '2026-11-19', 'production', 1, 'Piec', 'open'), ('NC/2026/016', '2026-12-05', 'customer', 2, 'Tarta', 'in_progress')");

$erpTables = ['complaint', 'n_complaint_seen', 'n_nc', 'customer', 'final_product', 'n_prod_group'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));

$lw = new LightWizardRepository($pdo);
$make = fn() => new ComplaintsReviewService(new ComplaintsReviewRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);
$today = '2026-11-24';

$erp0 = $sum();
$check(ComplaintsReviewService::reviewedWeek('2026-11-24') === '2026-11-16' && ComplaintsReviewService::reviewedWeek('2026-11-16') === '2026-11-09', 'reviewed week: previous Monday → Sunday');
$d = $svc->data($today);
$boot = $d;
$check($d['week_start'] === '2026-11-16' && $d['week_end'] === '2026-11-22' && $d['recurring_from'] === '2026-09-28' && $d['open_days'] === 3 && $d['lookback_days'] === 56 && $d['values'] === false, 'week, 8-week window, parameters, no values by default');
$rv = array_column($d['review'], null, 'id');
$codes = fn(array $c) => array_column($c['signals'], 'code');
$check(array_column($d['review'], 'id') === [9, 1, 3, 5], 'flagged complaints, by first signal then reported day');
$check($codes($rv[1]) === ['open_late'] && $rv[1]['signals'][0]['detail'] === 'Nowa · zgłoszona śr. 18.11 · 6 dni bez decyzji' && $rv[1]['waiting'], 'NEW for 6 days > 3 → open_late');
$check($codes($rv[9]) === ['open_late'] && $rv[9]['status'] === 'NEW' && $rv[9]['reported'] === '2026-11-01' && $rv[9]['reason'] === 'bez powodu', 'empty status = NEW, no reported_at → acceptance_timestamp ; no reason');
$check($codes($rv[3]) === ['no_response', 'not_seen'] && str_contains($rv[3]['signals'][1]['detail'], 'grupa ROGAL — przyjęta wt. 17.11, 7 dni bez « Widziane »') && $rv[3]['seen'] === null,
    'accepted without response, not seen by its group (« Widziane » of another group ignored)');
$check($codes($rv[5]) === ['no_group'] && $rv[5]['group'] === null && $rv[5]['product']['name'] === 'Bagietka wiejska', 'accepted product without production group');
$all = array_column(array_merge($d['review'], $d['week']), null, 'id');
$check(!isset($rv[2]) && !isset($rv[4]) && !isset($rv[8]) && !isset($rv[10]) && !isset($all[7]),
    'not flagged: in review for 2 days, accepted + answered + seen, new for 1 day, accepted yesterday ; accepted outside 56 days not read');
$check($all[4]['group']['name'] === 'CUKIERNIA' && $all[4]['seen']['at'] === '20.11 14:05' && $all[4]['seen']['by'] === 'Jan Nowak' && $all[3]['group']['name'] === 'ROGAL', 'group: product card first, else section group (as on the tablet) ; seen by whom');
$check(array_column($rv[1]['ncs'], 'number') === ['NC/2026/014'] && $rv[1]['ncs'][0]['status_label'] === 'otwarta' && array_column($rv[3]['ncs'], 'number') === ['NC/2026/014'] && $rv[9]['ncs'] === [],
    'customer NC matched (same product, 0–30 days after) ; production NC and other products ignored');
$check(array_column($d['week'], 'id') === [3, 1, 4, 10, 2], 'week: complaints reported 16.11 – 22.11');
$t = $d['totals'];
$check($t['week'] === 5 && $t['week_product'] === 4 && $t['week_other'] === 1 && $t['week_accepted'] === 3 && $t['week_rejected'] === 0 && $t['week_open'] === 2 && $t['week_customers'] === 3
    && $t['open'] === 4 && $t['open_new'] === 3 && $t['open_review'] === 1 && $t['review'] === 4 && $t['waiting'] === 4 && $t['recurring'] === 2
    && $t['by_code'] === ['open_late' => 2, 'no_response' => 1, 'not_seen' => 1, 'no_group' => 1] && $d['can_finish'] === false, 'totals');
$b = $d['breakdown'];
$check(array_map(fn($x) => [$x['label'], $x['count']], $b['types']) === [['Reklamacja produktowa', 4], ['Inna reklamacja', 1]]
    && $b['customers'][0]['label'] === 'Bistro Lumière' && $b['customers'][0]['count'] === 2 && $b['products'][0]['label'] === 'Croissant maślany' && $b['products'][0]['count'] === 3
    && array_map(fn($x) => [$x['label'], $x['count']], $b['groups']) === [['ROGAL', 3], ['CUKIERNIA', 1]]
    && in_array('Uszkodzone opakowanie', array_column($b['reasons'], 'label'), true) && in_array('Problem z kierowcą', array_column($b['reasons'], 'label'), true),
    'week by type, customer, product, group and translated reason');
$check(count($d['recurring']) === 2 && $d['recurring'][1]['customer']['name'] === 'Hotel Vistule' && $d['recurring'][1]['ids'] === [9, 4] && $d['recurring'][0]['customer']['name'] === 'Bistro Lumière' && $d['recurring'][0]['product']['name'] === 'Croissant maślany'
    && $d['recurring'][0]['count'] === 3 && $d['recurring'][0]['in_week'] === 2 && $d['recurring'][0]['ids'] === [6, 3, 1], 'recurring: same customer + product in 8 weeks (3 × croissant, 2 × tarta — one without reported_at)');
$json = json_encode($d, JSON_UNESCAPED_UNICODE);
$check(!preg_match('/"(value|accepted_value|week_value|week_accepted_value|price|single_purchase_price)"|€/', $json), 'no amount in the page data without [312]');
$v = $svc->data($today, true);
$va = array_column($v['week'], null, 'id');
$check($v['values'] === true && $va[1]['value'] === 60.0 && $va[3]['value'] === 60.0 && $va[3]['accepted_value'] === 60.0 && $va[4]['value'] === 14.0 && $va[10]['value'] === 30.0 && $va[2]['value'] === 0.0
    && $v['totals']['week_value'] === 164.0 && $v['totals']['week_accepted_value'] === 104.0 && $v['breakdown']['customers'][0]['value'] === 120.0,
    'with [312]: value = started cartons × carton price (ComplaintRepository::valueSql)');
$check($v['summary'] === $d['summary'] && !preg_match('/\d+[,.]\d{2}|€|zł/', $d['summary']), 'summary without amounts');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_complaint' => 1, 'decision' => 'x'], 2, false, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_complaint' => 9, 'decision' => 'watch', 'note' => ' '], 2, false, $today)), 'custom:422:Do obserwacji : wpisz notatkę'), '« Do obserwacji » needs a note');
$check(str_contains($err(fn() => $svc->saveDecision(['id_complaint' => 1, 'decision' => 'answered'], 2, false, $today)), 'reklamacja nadal « Nowa » — bez decyzji'), '« Odpowiedź » refused while the complaint is still NEW');
$check(str_contains($err(fn() => $svc->saveDecision(['id_complaint' => 3, 'decision' => 'answered'], 2, false, $today)), 'przyjęta, ale bez odpowiedzi do magazynu'), '« Odpowiedź » refused while accepted without response');
$check(str_starts_with($err(fn() => $svc->saveDecision(['id_complaint' => 4, 'decision' => 'watch', 'note' => 'abc'], 2, false, $today)), 'custom:422:Tej reklamacji nie ma na liście'), 'complaint without signal refused');
$check(str_starts_with($err(fn() => $svc->finish(2, false, $today)), 'custom:422:Najpierw zapisz decyzję dla 4 reklamacji'), 'finish blocked while complaints wait');
$check($erp0 === $sum(), 'refused decisions write nothing in ERP tables');
$d = $svc->saveDecision(['id_complaint' => 1, 'decision' => 'nc', 'note' => 'NC/2026/014 — temperatura pieca'], 2, false, $today);
$row = $pdo->query('SELECT * FROM n_complaint_review WHERE id_complaint = 1')->fetch();
$check($row['week_start'] === '2026-11-16' && $row['decision'] === 'nc' && $row['flags'] === 'open_late' && $row['status_at'] === 'NEW' && (int) $row['id_user'] === 2, 'NC saved for the reviewed week with the signals at that time');
$d = $svc->saveDecision(['id_complaint' => 9, 'decision' => 'watch', 'note' => 'Klient dośle zdjęcia do piątku'], 2, false, $today);
$d = $svc->saveDecision(['id_complaint' => 5, 'decision' => 'answered', 'note' => ''], 2, false, $today);
$check(array_column($d['review'], null, 'id')[5]['decision']['label'] === 'Odpowiedź / zamknięta' && $d['totals']['waiting'] === 1, '« Odpowiedź » saved for an accepted complaint with response');
$st = $tasks->state(ComplaintsReviewService::CODE, '2026-11-23 00:00:00', '2026-11-30 00:00:00', '2026-11-27');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '3 reklamacje wyjaśnione', 'task state: in progress (decisions for the reviewed week)');
// Le bureau répond au magasin dans « Reklamacje » (hors assistant) : la n° 3 a maintenant sa réponse.
$pdo->exec("UPDATE complaint SET production_response = 'Wymiana 2 kartonów w czwartek' WHERE id = 3");
$erp1 = $sum();
$d = $svc->saveDecision(['id_complaint' => 3, 'decision' => 'answered', 'note' => 'Odpowiedź wysłana ; ROGAL — przypomnienie na tablecie'], 2, false, $today);
$x = array_column($d['review'], null, 'id')[3];
$check($x['decision']['code'] === 'answered' && !$x['waiting'] && $codes($x) === ['not_seen'] && $d['can_finish'] === true && $erp1 === $sum(), '« Odpowiedź » once the office answered ; all decided → step 3 open ; nothing written in ERP');
$erp2 = $sum();
$r = $svc->finish(2, false, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'complaints_reviewed'")->fetchAll();
$check($r['summary'] === '5 reklamacji w tygodniu (4 produktowe, 1 inna) · 4 do wyjaśnienia : 2 odpowiedzi · 1 NC · 1 do obserwacji · 2 powtarzające się'
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null, 'finish: summary, n_wizard_done');
$st = $tasks->state(ComplaintsReviewService::CODE, '2026-11-23 00:00:00', '2026-11-30 00:00:00', '2026-11-27');
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done this week');
$check($tasks->state(ComplaintsReviewService::CODE, '2026-11-30 00:00:00', '2026-12-07 00:00:00', '2026-12-04')['done'] === false, 'next week: task open again');
$check($erp2 === $sum() && $pdo->query("SELECT GROUP_CONCAT(COALESCE(acceptance_status, '∅') ORDER BY id) FROM complaint")->fetchColumn() === 'NEW,IN_REVIEW,ACCEPTED,ACCEPTED,ACCEPTED,REJECTED,ACCEPTED,NEW,∅,ACCEPTED',
    'finish writes nothing in ERP tables ; complaint statuses unchanged');

// Semaine suivante (mar. 1.12) : les décisions valent pour la semaine examinée — la n° 1 encore NEW revient.
$n = $make()->data('2026-12-01');
$nx = array_column($n['review'], null, 'id');
$check($n['week_start'] === '2026-11-23' && $nx[1]['waiting'] && $nx[1]['decision'] === null && $nx[8]['waiting'] && $codes($nx[8]) === ['open_late'] && $n['done'] === null,
    'next week: still open complaints come back, new ones flagged');
$pdo->exec("UPDATE n_prod_param SET value = '10' WHERE code = 'complaints_review.open_days'");
$check(!isset(array_column($make()->data($today)['review'], null, 'id')[1]['signals'][0]) || $codes(array_column($make()->data($today)['review'], null, 'id')[1]) === [], 'open_days = 10 → NEW for 6 days not flagged');
$pdo->exec("UPDATE n_prod_param SET value = '99' WHERE code = 'complaints_review.open_days'");
$check($make()->param('open') === 3, 'out of bounds → default 3');
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'complaints_review.open_days'");

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_complaints_review_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(ComplaintsReviewController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 1), (3, 3), (4, 2)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'franchise'), (1, 'production'), (3, 'franchise'), (3, 'invoices'), (2, 'production')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
[$c2, $b2] = $call(2, 'data');
$check($c2 === 200 && $b2['data']['week_start'] === ComplaintsReviewService::reviewedWeek(date('Y-m-d')) && $b2['data']['values'] === false && isset($b2['data']['totals']['by_code'])
    && !preg_match('/"(value|accepted_value|week_value|week_accepted_value)"|€/', (string) json_encode($b2)), 'controller data() (Jakość, right « franchise ») : 200, real day, no amounts');
[$c3, $b3] = $call(3, 'data');
$check($c3 === 200 && $b3['data']['values'] === true && array_key_exists('week_value', $b3['data']['totals']), 'controller data() (Finanse [312]) : 200 with amounts');
[$c1, $b1] = $call(1, 'data');
[$c4, $b4] = $call(4, 'data');
$check($c1 === 200 && $b1['data']['values'] === false && $c4 === 403 && $b4['message'] === 'Brak dostępu do reklamacji klientów.', 'controller: admin full view 200 without amounts ; Piekarz without « franchise » 403');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($cD === 403 && $cF === 403, 'controller decision() and finish() guarded too');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les réclamations : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/ComplaintsReviewService.php', 'App/Repositories/Client/Complaint/ComplaintsReviewRepository.php',
    'App/Controllers/Web/Client/Complaint/ComplaintsReviewController.php', 'public/assets/js/client/complaints-review.js']));
$check(!preg_match('/UPDATE\s+complaint|INSERT\s+INTO\s+(complaint|n_nc|n_complaint_seen)\b|DELETE\s+FROM|Mail|Sms|curl_|https?:\/\/|PATCH/i', $src), 'no write to complaints / NC / seen, no mail/SMS, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('complaints_reviewed')['link'] === '/franchise-complaints/review' && TaskWizards::byLink('/franchise-complaints/review') === 'complaints_reviewed'
    && isset(TaskWizards::AUTO['complaints_reviewed']) && PositionTaskService::LINK_AUTO['/franchise-complaints/review'] === 'complaints_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['complaints_reviewed'][0] !== 'Reklamacje klientów', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/franchise-complaints/review') === 'franchise' && PageAccess::keyFor('/ajax/franchise-complaints/review') === 'franchise' && PageAccess::keyFor('/ajax/franchise-complaints/review/decision') === 'franchise'
    && PageAccess::keyFor('/franchise-complaints') === 'franchise' && PageAccess::keyFor('/ajax/franchise-complaints/5/detail') === null && PageAccess::keyFor('/client-complaints') === 'returns',
    'page and ajax under the existing « franchise » right ; others unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'complaints_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/franchise-complaints/review'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Client/ComplaintsReviewRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/franchise-complaints/review') === 'index' && $found('GET', '/ajax/franchise-complaints/review') === 'data'
    && $found('POST', '/ajax/franchise-complaints/review/decision') === 'decision' && $found('POST', '/ajax/franchise-complaints/review/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/franchise-complaints/review')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/franchise-complaints/review')[1]['controller'] === ComplaintsReviewController::class
    && $allR->dispatch('GET', '/franchise-complaints')[1]['method'] === 'overview' && $allR->dispatch('PATCH', '/ajax/franchise-complaints/5')[1]['method'] === 'ajaxUpdateFranchiseComplaint'
    && $allR->dispatch('GET', '/ajax/franchise-complaints/5/detail')[1]['method'] === 'ajaxDetail', 'routes file loaded by the */*Routes.php glob ; existing complaint routes unchanged');

if (getenv('CR_JSON_OUT')) file_put_contents((string) getenv('CR_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_complaints_review_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
