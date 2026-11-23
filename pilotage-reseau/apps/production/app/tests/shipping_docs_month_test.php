<?php
/**
 * [402] Asystent « Dokumenty wysyłek miesiąca » : mois examiné = le mois écoulé ; wysyłki de ce mois seulement — WZ / CMR /
 * WDT absente, introuvable ou vide, CMR sans przewoźnik ou numer rejestracyjny, sans colis, quantités WZ ≠ WDT (pas pour une
 * WDT remplacée), różnica non réglée (sans affaire ou affaire en cours), problème de livraison non clos, potwierdzenie
 * odbioru manquant ou sans podpis (seulement les jours avec tablette chauffeur) ; wysyłka complète non listée ; dowód
 * opakowań compté, jamais signalé (assistant PPWR) ; « brak danych » sans table ; décisions (uzupełnione refusée tant qu'il
 * reste un brak, papier et nie dotyczy avec note), étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche du mois ;
 * aucune écriture dans les tables d'origine (checksums) ; contrôleur construit par PHP-DI et appelé (droit
 * « shipping_docs_month » 200, sans droit 403, liens filtrés par les droits) ; enregistrement ; migration 242 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_kp_shipping_docs_test recréée (tables minimales + migration 242), supprimée à la fin (SD_JSON_OUT=fichier :
 * données de la page avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Shipping\ShippingDocsMonthController;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ShippingDocsMonthRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\ShippingDocsMonthService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$db = 'erp_kp_shipping_docs_test';
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
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (4, 'LOGISTYKA'), (5, 'UPLOAD')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '4.1', NULL, 'Dokumenty wysyłek miesiąca', 'monthly'),
    (2, '4.2', NULL, 'Dokumenty wysyłek miesiąca', 'monthly'), (2, '4.4', NULL, 'Dokumenty wysyłek miesiąca', 'monthly'), (2, '4.3', 'day_shipped', NULL, 'none'),
    (1, '9', NULL, 'Dokumenty wysyłek miesiąca', 'monthly'), (2, '6.4', NULL, 'Stany magazynowe na koniec miesiąca', 'monthly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 4)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (4, 'shipping')");
$m242 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/242_shipping_docs_month.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m242 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 242 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_map(fn($d) => [$d['wizard_code'], $d['proposed_wizard']], array_slice($duties, 0, 3)) === array_fill(0, 3, ['shipping_docs_reviewed', null]),
    'migration 242: KIEROWNIK_PRODUKCJI 4.1, 4.2, 4.4 → shipping_docs_reviewed');
$check($duties[3]['wizard_code'] === 'day_shipped' && $duties[4]['wizard_code'] === null && $duties[4]['proposed_wizard'] === 'Dokumenty wysyłek miesiąca'
    && $duties[5]['proposed_wizard'] === 'Stany magazynowe na koniec miesiąca', 'migration: other duties and other positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'shipping_docs_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['month_day'] === 'wd5' && $tasks[0]['link_path'] === '/shipments/documents-month'
    && (int) $tasks[0]['id_category'] === 4 && (int) $tasks[0]['sort_order'] === 5, 'one monthly task for Kierownik produkcji (5th working day), category « Logistyka »');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'shipping,shipping_docs_month,time', 4 => 'shipping'], 'rights : shipping_docs_month + shipping for Kierownik produkcji only, other positions untouched');
$check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_shipping_docs_review'")->fetchColumn() === 0, 'no foreign key');

$lw = new LightWizardRepository($pdo);
$make = fn() => new ShippingDocsMonthService(new ShippingDocsMonthRepository($pdo), $lw);
$today = '2026-11-24';
$e = $make()->data($today);
$check($e['review'] === [] && $e['can_finish'] && $e['source']['note'] === 'brak danych — brak tabeli wysyłek w bazie' && $e['month_start'] === '2026-10-01' && $e['month_end'] === '2026-10-31',
    'no shipment table → « brak danych » ; examined month = previous month');

foreach ([
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL)',
    'CREATE TABLE shipment_log (id INT AUTO_INCREMENT PRIMARY KEY, shipment_date DATE, id_customer INT, id_wz INT NULL, id_cmr INT NULL, id_wdt INT NULL, id_diff INT NULL) ENGINE=InnoDB',
    'CREATE TABLE document_wz (id INT PRIMARY KEY, doc_num VARCHAR(40), established_date DATE NULL)',
    'CREATE TABLE document_wz_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wz INT, id_product INT, quantity DECIMAL(10,2))',
    "CREATE TABLE document_cmr (id INT PRIMARY KEY, doc_num VARCHAR(40), carrier_name VARCHAR(120) NULL, carrier_regis_num VARCHAR(30) NULL, id_carrier_vehicle INT NULL)",
    'CREATE TABLE document_cmr_packages (id INT AUTO_INCREMENT PRIMARY KEY, id_document_cmr INT, name VARCHAR(80))',
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40))',
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT, quantity DECIMAL(10,2))',
    'CREATE TABLE n_wdt_superseded (id_document_wdt INT PRIMARY KEY, id_document_wdt_new INT NULL)',
    'CREATE TABLE document_order_shipping_difference_products (id INT AUTO_INCREMENT PRIMARY KEY, id_document_order_shipping_difference INT, id_product INT, quantity_ordered DECIMAL(10,2),
        invoiced_quantity DECIMAL(10,2), price_during_ordering DECIMAL(10,2), price_during_invoicing DECIMAL(10,2))',
    "CREATE TABLE n_delivery_run (id INT AUTO_INCREMENT PRIMARY KEY, ship_date DATE NOT NULL, id_carrier_vehicle INT NOT NULL)",
    "CREATE TABLE n_delivery_stop (id INT AUTO_INCREMENT PRIMARY KEY, ship_date DATE NOT NULL, id_customer INT NOT NULL, status ENUM('arrived','delivered','problem') NOT NULL, receiver_name VARCHAR(100) NULL)",
    "CREATE TABLE n_delivery_media (id INT AUTO_INCREMENT PRIMARY KEY, id_stop INT NOT NULL, kind ENUM('signature','photo') NOT NULL, mime VARCHAR(40), content MEDIUMBLOB)",
    "CREATE TABLE n_shipping_issue (id INT AUTO_INCREMENT PRIMARY KEY, source ENUM('difference','delivery') NOT NULL, id_difference INT NULL, id_delivery_stop INT NULL,
        status ENUM('open','decided','done') NOT NULL DEFAULT 'open')",
    'CREATE TABLE shipment_packaging_record (id INT AUTO_INCREMENT PRIMARY KEY, id_shipment_log INT, status VARCHAR(20))',
] as $sql) $pdo->exec($sql);
$e = $make()->data($today);
$check($e['source']['note'] === 'brak danych — brak wysyłek w miesiącu październik 2026' && !in_array(false, $e['checks'], true), 'no shipment in the month → « brak danych » ; every check available');

// --- Données fictives : contrôle le mar. 24.11.2026, wysyłki de październik 2026.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, name, surname) VALUES (1, 'Bistro Lumière', NULL, NULL), (2, '', 'Jan', 'Kowal')");
$pdo->exec("INSERT INTO shipment_log (id, shipment_date, id_customer, id_wz, id_cmr, id_wdt, id_diff) VALUES (31, '2026-10-05', 1, 501, 601, 701, 801), (32, '2026-10-12', 2, NULL, 602, 702, 802),
    (33, '2026-10-20', 1, 503, 603, 703, 803), (34, '2026-10-26', 2, 504, 604, 704, 804), (35, '2026-10-28', 1, 505, 605, 705, 805), (36, '2026-11-03', 2, NULL, NULL, NULL, NULL),
    (37, '2026-09-30', 1, NULL, NULL, NULL, NULL)");
$pdo->exec("INSERT INTO document_wz VALUES (501, 'WZ/501/10/2026', '2026-10-05'), (503, 'WZ/503/10/2026', '2026-10-20'), (504, 'WZ/504/10/2026', '2026-10-26'), (505, 'WZ/505/10/2026', '2026-10-28')");
$pdo->exec("INSERT INTO document_wz_product (id_document_wz, id_product, quantity) VALUES (501, 20, 10), (501, 21, 5), (503, 20, 10), (504, 20, 4), (505, 20, 6)");
$pdo->exec("INSERT INTO document_cmr VALUES (601, 'CMR/601', 'Trans-Pol', 'WA 12345', 3), (602, 'CMR/602', 'Trans-Pol', '', 3), (603, 'CMR/603', 'Trans-Pol', 'WA 12345', 3),
    (604, 'CMR/604', 'Trans-Pol', 'WA 12345', 3), (605, 'CMR/605', 'Trans-Pol', 'WA 12345', 3)");
$pdo->exec("INSERT INTO document_cmr_packages (id_document_cmr, name) VALUES (601, 'Bagietka'), (603, 'Bagietka'), (604, 'Bagietka'), (605, 'Bagietka')");
$pdo->exec("INSERT INTO document_wdt VALUES (701, 'WDT/701'), (702, 'WDT/702'), (703, 'WDT/703'), (705, 'WDT/705')");
$pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, quantity) VALUES (701, 20, 10), (701, 21, 5), (702, 20, 3), (703, 20, 8), (705, 20, 5)");
$pdo->exec("INSERT INTO n_wdt_superseded VALUES (705, 706)");
$pdo->exec("INSERT INTO document_order_shipping_difference_products (id_document_order_shipping_difference, id_product, quantity_ordered, invoiced_quantity, price_during_ordering, price_during_invoicing) VALUES
    (801, 20, 10, 10, 2.5, 2.5), (802, 20, 4, 3, 2.5, 2.5), (803, 20, 10, 8, 2.5, 2.5), (805, 20, 6, 5, 2.5, 2.5)");
$pdo->exec("INSERT INTO n_delivery_run (ship_date, id_carrier_vehicle) VALUES ('2026-10-05', 3), ('2026-10-12', 3), ('2026-10-20', 3), ('2026-10-26', 3)");
$pdo->exec("INSERT INTO n_delivery_stop (id, ship_date, id_customer, status) VALUES (1, '2026-10-05', 1, 'delivered'), (2, '2026-10-20', 1, 'delivered'), (3, '2026-10-26', 2, 'problem')");
$pdo->exec("INSERT INTO n_delivery_media (id_stop, kind, mime, content) VALUES (1, 'signature', 'image/png', 'x'), (2, 'photo', 'image/jpeg', 'y'), (3, 'signature', 'image/png', 'z')");
$pdo->exec("INSERT INTO n_shipping_issue (source, id_difference, id_delivery_stop, status) VALUES ('difference', 803, NULL, 'decided'), ('delivery', NULL, 3, 'open'), ('difference', 805, NULL, 'done')");
$pdo->exec("INSERT INTO shipment_packaging_record (id_shipment_log, status) VALUES (31, 'GENERATED')");

$erpTables = ['shipment_log', 'document_wz', 'document_wz_product', 'document_cmr', 'document_cmr_packages', 'document_wdt', 'document_wdt_product', 'n_wdt_superseded',
    'document_order_shipping_difference_products', 'n_delivery_run', 'n_delivery_stop', 'n_delivery_media', 'n_shipping_issue', 'shipment_packaging_record', 'customer', 'user'];
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
$codes = fn(array $i) => $i['codes'];
$check(array_column($d['review'], 'key') === ['shipment:32', 'shipment:33', 'shipment:34'], 'flagged October shipments, most braki first (complete 31, superseded-WDT 35, November and September not listed)');
$check($codes($rv['shipment:32']) === ['WZ_MISSING', 'CMR_CARRIER', 'CMR_PACKAGES', 'DIFF_OPEN', 'RECEIPT_MISSING'] && $rv['shipment:32']['title'] === 'Jan Kowal'
    && array_column($rv['shipment:32']['signals'], 'detail') === [null, 'brak numeru rejestracyjnego', null, '1 pozycja z różnicą · sprawa nie założona', 'brak przystanku klienta tego dnia']
    && array_column($rv['shipment:32']['links'], 'href') === ['/shipments?type=wz', '/shipments?type=cmr', '/shipments/differences', '/logistics/deliveries', '/ajax/shipments/32/documents/grouped?all=1'],
    'no WZ, CMR without plate and packages, difference without case, no delivery stop on a tablet day ; links to the documents and their PDF');
$check($codes($rv['shipment:33']) === ['WZ_WDT_QTY', 'DIFF_OPEN', 'RECEIPT_NO_SIGNATURE'] && $rv['shipment:33']['signals'][0]['detail'] === '1 produkt z inną ilością'
    && $rv['shipment:33']['signals'][1]['detail'] === '1 pozycja z różnicą · w toku', 'WZ and WDT quantities differ, difference case in progress, delivered without receiver signature');
$check($codes($rv['shipment:34']) === ['WDT_MISSING', 'DELIVERY_ISSUE_OPEN'], 'WDT not found, delivery problem still open (no receipt signal for a problem stop)');
$t = $d['totals'];
$check($t['review'] === 3 && $t['waiting'] === 3 && $t['shipments'] === ['total' => 5, 'incomplete' => 3, 'transport' => 3, 'settlement' => 3, 'receipt' => 2, 'no_packaging' => 4, 'receipt_days' => 4]
    && $d['can_finish'] === false, 'totals ; packaging evidence counted only (PPWR assistant)');
$check(!in_array('SHIPMENT_NO_EVIDENCE', array_merge(...array_column($d['review'], 'codes')), true) && !isset(ShippingDocsMonthService::SIGNALS['SHIPMENT_NO_EVIDENCE']), 'no packaging-evidence signal (not duplicated with « PPWR i opakowania »)');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Krok 2 : decyzje
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:32', 'decision' => 'x'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:32', 'decision' => 'checked'], 2, $today)), 'custom:422:Nadal brakuje : brak WZ'), '« uzupełnione » refused while braki remain');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:32', 'decision' => 'paper_ok', 'note' => ''], 2, $today)), 'custom:422:Dokument papierowy kompletny : wpisz'), 'paper decision needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:34', 'decision' => 'not_applicable', 'note' => 'x'], 2, $today)), 'custom:422:Nie dotyczy : wpisz'), '« nie dotyczy » needs a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:31', 'decision' => 'checked'], 2, $today)), 'custom:422:Dokumenty tej wysyłki nie miały braków')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'shipment:36', 'decision' => 'paper_ok', 'note' => 'abc'], 2, $today)), 'custom:422:Tej wysyłki nie ma na liście'), 'complete shipment or other month refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 3 wysyłek'), 'finish blocked while shipments wait');
$check($erp0 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_shipping_docs_review')->fetchColumn() === 0, 'refused decisions write nothing');
$d = $svc->saveDecision(['key' => 'shipment:32', 'decision' => 'paper_ok', 'note' => 'WZ ręczna i CMR podpisane — segregator październik'], 2, $today);
$row = $pdo->query("SELECT * FROM n_shipping_docs_review WHERE item_key = 'shipment:32'")->fetch();
$check($row['month_start'] === '2026-10-01' && (int) $row['source_id'] === 32 && $row['decision'] === 'paper_ok' && $row['flags'] === 'WZ_MISSING,CMR_CARRIER,CMR_PACKAGES,DIFF_OPEN,RECEIPT_MISSING',
    'paper decision saved for the examined month with the braki codes');
$st = $tasks->state(ShippingDocsMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 wysyłka z decyzją', 'task state : in progress');
// Corrigé dans l'ERP (WDT, affaire close, signature), puis « uzupełnione ».
$pdo->exec("UPDATE document_wdt_product SET quantity = 10 WHERE id_document_wdt = 703");
$pdo->exec("UPDATE n_shipping_issue SET status = 'done' WHERE id_difference = 803");
$pdo->exec("INSERT INTO n_delivery_media (id_stop, kind, mime, content) VALUES (2, 'signature', 'image/png', 's')");
$erp1 = $sum();
$d = $svc->saveDecision(['key' => 'shipment:33', 'decision' => 'checked', 'seen' => ['WZ_WDT_QTY', 'DIFF_OPEN', 'RECEIPT_NO_SIGNATURE', 'X']], 2, $today);
$check($pdo->query("SELECT flags FROM n_shipping_docs_review WHERE item_key = 'shipment:33'")->fetchColumn() === 'WZ_WDT_QTY,DIFF_OPEN,RECEIPT_NO_SIGNATURE', 'corrected in the ERP : « uzupełnione » saved with the braki seen on screen');
$d = $svc->saveDecision(['key' => 'shipment:34', 'decision' => 'not_applicable', 'note' => 'WDT anulowana — towar wrócił, reklamacja 12/10'], 2, $today);
$check($d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['checked'] === 1 && $d['totals']['paper_ok'] === 1 && $d['totals']['not_applicable'] === 1
    && array_column($d['review'], null, 'key')['shipment:33']['codes'] === [], 'all decided → step 3 open ; corrected shipment stays listed');
$check($erp1 === $sum(), 'decisions write nothing in the source tables');
$r = $svc->finish(2, $today);
$check($r['summary'] === 'październik 2026 : wysyłki 2 z brakami / 5 · dokumenty 2 · rozliczenia 2 · potwierdzenia 1 · decyzje : 1 sprawdzone · 1 na papierze · 1 nie dotyczy'
    && $r['data']['done'] !== null, 'finish : summary, n_wizard_done');
$st = $tasks->state(ShippingDocsMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === true && $st['summary'] === $r['summary'] && $tasks->state(ShippingDocsMonthService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');
$n = $make()->data('2026-12-02');
$check($n['month_start'] === '2026-11-01' && array_column($n['review'], 'key') === ['shipment:36'] && $n['done'] === null, 'next month : November shipments only');
$check($erp1 === $sum(), 'finish writes nothing in the source tables');

// --- Contrôleur : construit par PHP-DI, data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=' . $db, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(ShippingDocsMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'shipping_docs_month')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
$hrefs = fn(array $body) => array_values(array_unique(array_merge([], ...array_map(fn($i) => array_map(fn($l) => strtok($l['href'], '?'), $i['links']), $body['data']['review']))));
[$c2, $b2] = $call(2, 'data');
$check($c2 === 200 && $b2['data']['month_start'] === ShippingDocsMonthService::reviewedMonth(date('Y-m-d')), 'controller data() (Kierownik produkcji) : 200, month examined = previous month');
// Données du contrôleur : mois courant réel ; on vérifie les liens sur les wysyłki d'octobre via le service + filtre du contrôleur (même règle).
$filter = (new ReflectionClass($ctl))->getMethod('withLinks');
$_SESSION['USER'] = ['id' => 2, 'login' => 'u2'];
$f2 = $filter->invoke($ctl, $make()->data($today));
$h2 = $hrefs(['data' => $f2]);
$_SESSION['USER'] = ['id' => 3, 'login' => 'u3'];
$f3 = $filter->invoke($ctl, $make()->data($today));
$_SESSION['USER'] = ['id' => 1, 'login' => 'u1'];
$f1 = $filter->invoke($ctl, $make()->data($today));
$check(in_array('/shipments', $h2, true) && in_array('/shipments/differences', $h2, true) && !in_array('/logistics/deliveries', $h2, true) && in_array('/ajax/shipments/32/documents/grouped', $h2, true)
    && $f2['ppwr_link'] === null, 'Kierownik produkcji (shipping) : documents and differences links, no deliveries (logistics) nor PPWR link without those rights ; PDF is a common route');
$check($hrefs(['data' => $f3]) === ['/ajax/shipments/32/documents/grouped', '/ajax/shipments/34/documents/grouped', '/ajax/shipments/33/documents/grouped'], 'position with the wizard right only : PDF links only');
$check(in_array('/logistics/deliveries', $hrefs(['data' => $f1]), true) && $f1['ppwr_link'] !== null, 'administrator : every link');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do asystenta « Dokumenty wysyłek miesiąca ».' && $cD === 403 && $cF === 403, 'controller : Piekarz without « shipping_docs_month » → 403 for data(), decision() and finish()');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true), 'controller finish() really called');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/ShippingDocsMonthService.php', 'App/Repositories/Workshop/ShippingDocsMonthRepository.php',
    'App/Controllers/Web/Shipping/ShippingDocsMonthController.php', 'public/assets/js/shipping_docs_month/shipping-docs-month.js']));
$check(!preg_match('/UPDATE\s+(shipment|document_|n_shipping_issue|n_delivery)|INSERT\s+INTO\s+(shipment|document_|n_shipping_issue|n_delivery)|DELETE\s+FROM|PHPMailer|Mail::|Sms|\/ksef|KsefSubmission|curl_|https?:\/\//i', $src),
    'no write to shipments / documents / issues, no mail/SMS/KSeF sending, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('shipping_docs_reviewed')['link'] === '/shipments/documents-month' && TaskWizards::byLink('/shipments/documents-month') === 'shipping_docs_reviewed'
    && isset(TaskWizards::AUTO['shipping_docs_reviewed']) && PositionTaskService::LINK_AUTO['/shipments/documents-month'] === 'shipping_docs_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['shipping_docs_reviewed'][0] === 'Dokumenty wysyłek (przegląd miesiąca)' && TaskWizards::CATALOG['shipping_docs_reviewed'][0] !== 'Dokumenty wysyłek miesiąca',
    'catalog label distinct from the proposed name in the matrix (237)');
$check(PageAccess::keyFor('/shipments/documents-month') === 'shipping_docs_month' && PageAccess::keyFor('/ajax/shipments/documents-month/decision') === 'shipping_docs_month'
    && PageAccess::keyFor('/shipments') === 'shipping' && PageAccess::keyFor('/shipments/differences') === 'shipping' && in_array('shipping_docs_month', PageAccess::grantable(), true),
    'own right « shipping_docs_month » (page and ajax) ; shipping screens unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'shipping_docs_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/shipments/documents-month'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Shipping/ShippingDocsMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/shipments/documents-month') === 'index' && $found('GET', '/ajax/shipments/documents-month') === 'data' && $found('POST', '/ajax/shipments/documents-month/decision') === 'decision'
    && $found('POST', '/ajax/shipments/documents-month/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/shipments/documents-month')[1]['controller'] === ShippingDocsMonthController::class && $allR->dispatch('GET', '/shipments')[0] === FastRoute\Dispatcher::FOUND
    && $allR->dispatch('GET', '/ajax/shipments/12/documents/grouped')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob ; existing shipping routes unchanged');

if (getenv('SD_JSON_OUT')) file_put_contents((string) getenv('SD_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec("DROP DATABASE {$db}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
