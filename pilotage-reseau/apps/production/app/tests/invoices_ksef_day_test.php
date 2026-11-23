<?php
/**
 * [378] Asystent « Faktury i KSeF dnia » : fenêtre (jour ouvré précédent → aujourd'hui, rattrapage depuis la dernière
 * validation borné par ksef_day.catchup_days) ; signaux par expédition — sans WDT, WDT sans date de facture, WDT non
 * envoyée à KSeF depuis ksef_day.pending_days jours (dernière erreur du journal KSeF), rejetée, en échec, sans réponse
 * depuis ksef_day.pending_days jours ; aujourd'hui encore dans le délai ; zaległe (problèmes ouverts des expéditions
 * antérieures, au plus ksef_day.lookback_days jours, sans résultat définitif) ; hors KSeF avant la première expédition
 * envoyée ; résultat par document (poprawione vérifié sur le statut « accepted », w toku et bez faktury avec note,
 * « w toku » valable le jour où il est écrit, « poprawione » rouvert si le problème revient) ; étape 3 bloquée,
 * « Zakończ » → n_wizard_done, état de la tâche du jour ; aucune écriture hors des tables de l'assistant, aucun appel
 * KSeF ; contrôleur construit par PHP-DI et appelé (data, result, finish ; droit « shipping ») ; enregistrement
 * (catalogue, droits, routes, paramètres) ; migration 226 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_invoices_ksef_day_test recréée (tables minimales + migrations KSeF 053–055 et 226), supprimée à la fin
 * (IK_JSON_OUT=fichier : données de la page avant les résultats, pour un aperçu). Aucun envoi, aucun appel KSeF.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Shipping\InvoicesKsefDayController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\InvoicesKsefDayRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\InvoicesKsefDayService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_invoices_ksef_day_test'); $pdo->exec('CREATE DATABASE erp_invoices_ksef_day_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_invoices_ksef_day_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), name VARCHAR(40) NULL, surname VARCHAR(40) NULL)',
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40), invoiced_date DATE NULL)',
    'CREATE TABLE shipment_log (id INT AUTO_INCREMENT PRIMARY KEY, shipment_date DATE, id_customer INT, id_wz INT NULL, id_cmr INT NULL, id_wdt INT NULL, id_diff INT NULL, id_employee INT NULL, insert_timestamp DATETIME NULL)',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)',
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
] as $sql) $pdo->exec($sql);
// Tables KSeF de l'ERP (migrations 053 à 055, telles quelles).
foreach (['053_ksef_integration.sql', '054_ksef_event_log.sql', '055_ksef_submission_environment.sql'] as $f) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/' . $f)) as $sql) $pdo->exec($sql);
}
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order, active) VALUES (1, 'ADMINISTRACJA', 'Administracja i fakturowanie', 2, 1), (2, 'PIEKARZ', 'Piekarz', 7, 1)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '2', NULL, 'Faktury i KSeF dnia', 'daily'),
    (1, '4', NULL, 'Dane klientów', 'monthly'), (1, '1', 'orders_day_checked', NULL, 'daily')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/226_invoices_ksef_day.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 226 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'invoices_ksef_checked' && $duties[0]['proposed_wizard'] === null, 'migration 226: ADMINISTRACJA 2 → invoices_ksef_checked');
$check($duties[1]['proposed_wizard'] === 'Dane klientów' && $duties[1]['wizard_code'] === null && $duties[2]['wizard_code'] === 'orders_day_checked', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'ksef_day.%' ORDER BY code")->fetchAll(), 'value', 'code');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_invoice_ksef_check'")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_invoice_ksef_check'")->fetchColumn();
$check($params === ['ksef_day.catchup_days' => '7', 'ksef_day.lookback_days' => '30', 'ksef_day.pending_days' => '1'] && $tables === 1 && $fks === 0, 'migration replayed: 3 params once, 1 table, no foreign key');
$check(ParamRules::RANGES['ksef_day.catchup_days'] === [1, 31, true] && ParamRules::RANGES['ksef_day.pending_days'] === [1, 14, true] && ParamRules::RANGES['ksef_day.lookback_days'] === [7, 92, true], 'ParamRules bounds for ksef_day.*');

// --- Données fictives : contrôle le mar. 24.11.2026 → fenêtre lun. 23.11 – mar. 24.11.
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL), (3, 'ewa', 'Ewa L.')");
$pdo->exec("INSERT INTO customer VALUES (1, 'Bistro Lumière', NULL, NULL), (2, 'Café Rivoli', NULL, NULL), (3, '', 'Kasia', 'Nowak'), (4, 'Hotel Vistule', NULL, NULL),
    (5, 'Sklep Pod Lipą', NULL, NULL), (6, 'Restauracja Wisła', NULL, NULL)");
$ship = fn(int $id, string $day, int $c, ?int $wdt) => $pdo->prepare('INSERT INTO shipment_log (id, shipment_date, id_customer, id_wdt) VALUES (?, ?, ?, ?)')->execute([$id, $day, $c, $wdt]);
$wdt = fn(int $id, ?string $inv) => $pdo->prepare('INSERT INTO document_wdt (id, doc_num, invoiced_date) VALUES (?, ?, ?)')->execute([$id, sprintf('WDT/%04d/2026', $id), $inv]);
$ks = fn(int $ship, int $w, string $st, array $o = []) => $pdo->prepare('INSERT INTO ksef_submission (id_document_wdt, id_shipment_log, status, environment, ksef_number, error_code, error_message, submitted_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$w, $ship, $st, $o['env'] ?? 'production', $o['number'] ?? null, $o['code'] ?? null, $o['msg'] ?? null, $o['at'] ?? null, $o['at'] ?? '2026-11-23 10:00:00']);
$ship(1, '2026-11-23', 1, 101); $wdt(101, '2026-11-23'); $ks(1, 101, 'accepted', ['number' => '5260001111-20261123-0A1B2C-01', 'at' => '2026-11-23 15:00:00']);
$ship(2, '2026-11-23', 2, 102); $wdt(102, '2026-11-23'); $ks(2, 102, 'rejected', ['code' => '450', 'msg' => 'Błąd weryfikacji semantyki dokumentu faktury', 'at' => '2026-11-23 15:01:00']);
$ship(3, '2026-11-23', 3, 103); $wdt(103, '2026-11-23');                                            // nie wysłana : walidacja odrzucona
$pdo->exec("INSERT INTO ksef_event_log (id_ksef_submission, id_shipment_log, level, stage, message, created_at) VALUES
    (NULL, 3, 'error', 'walidacja faktury', 'WDT nie zawiera wymaganego pola: consignee_nip.', '2026-11-23 15:02:00'), (NULL, 3, 'info', 'x', 'starsze', '2026-11-23 09:00:00')");
$ship(4, '2026-11-23', 4, 104); $wdt(104, '2026-11-23'); $ks(4, 104, 'processing', ['at' => '2026-11-23 16:00:00']);
$ship(5, '2026-11-24', 5, 105); $wdt(105, '2026-11-24'); $ks(5, 105, 'failed', ['msg' => 'cURL error 28: Operation timed out', 'at' => null]);
$ship(6, '2026-11-24', 1, 106); $wdt(106, '2026-11-24');                                            // dziś : jeszcze w terminie
$ship(7, '2026-11-24', 6, null);                                                                     // bez WDT
$ship(8, '2026-11-24', 2, 107); $wdt(107, null);                                                     // WDT bez daty faktury
$ship(9, '2026-11-24', 3, 108); $wdt(108, '2026-11-24'); $ks(9, 108, 'submitted', ['at' => '2026-11-24 08:00:00', 'env' => 'test']);
$ship(10, '2026-11-18', 4, 109); $wdt(109, '2026-11-18'); $ks(10, 109, 'rejected', ['code' => '440', 'msg' => 'Duplikat faktury', 'at' => '2026-11-18 15:00:00']);
$ship(11, '2026-11-19', 5, 110); $wdt(110, '2026-11-19'); $ks(11, 110, 'accepted', ['number' => 'K-110', 'at' => '2026-11-19 15:00:00']);
$ship(12, '2026-11-17', 6, 111); $wdt(111, '2026-11-17'); $ks(12, 111, 'failed', ['msg' => 'timeout']);
$ship(13, '2026-11-16', 1, 112); $wdt(112, '2026-11-16'); $ks(13, 112, 'rejected', ['code' => '450', 'msg' => 'NIP nabywcy', 'at' => '2026-11-16 15:00:00']);
$ship(14, '2026-10-01', 2, 113); $wdt(113, '2026-10-01'); $ks(14, 113, 'rejected', ['msg' => 'stary']);   // poza 30 dniami
$pdo->exec("INSERT INTO n_invoice_ksef_check (id_shipment_log, shipment_date, id_wdt, result, note, flags, ksef_status, checked_on, id_user) VALUES
    (12, '2026-11-17', 111, 'justified', 'Faktura wystawiona ręcznie w aplikacji MF', 'ksef_failed', 'failed', '2026-11-20', 1),
    (13, '2026-11-16', 112, 'in_progress', 'Czekam na NIP od klienta', 'ksef_rejected', 'rejected', '2026-11-23', 1)");

$erpTables = ['shipment_log', 'document_wdt', 'ksef_submission', 'ksef_event_log', 'customer'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));

$lw = new LightWizardRepository($pdo);
$make = fn() => new InvoicesKsefDayService(new InvoicesKsefDayRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);
$today = '2026-11-24';

$erp0 = $sum();
$check($svc->previousWorkday('2026-11-24') === '2026-11-23' && $svc->previousWorkday('2026-11-23') === '2026-11-20', 'previous workday (Monday → Friday)');
$d = $svc->data($today);
$boot = $d;
$w = $d['window'];
$check($w['from'] === '2026-11-23' && $w['to'] === $today && $w['kind'] === 'workday' && $w['last_done'] === null, 'window: previous workday → today (never validated)');
$docs = array_column($d['docs'], null, 'id');
$codes = fn(array $x) => array_column($x['signals'], 'code');
$check(array_keys($docs) === [6, 8, 9, 7, 5, 1, 2, 4, 3], 'window shipments, newest day first, then by customer');
$check($codes($docs[1]) === [] && !$docs[1]['review'] && $docs[1]['ksef']['label'] === 'przyjęta' && $docs[1]['ksef']['number'] === '5260001111-20261123-0A1B2C-01' && $docs[1]['links'] === [], 'accepted invoice: OK, no link needed');
$check($codes($docs[2]) === ['ksef_rejected'] && $docs[2]['signals'][0]['detail'] === 'kod 450 : Błąd weryfikacji semantyki dokumentu faktury' && $docs[2]['waiting']
    && array_column($docs[2]['links'], 'url') === ['/shipments?type=ksef-pending', '/wdt-documents/102/products'], 'rejected: code and message, links to « Wysyłki i dokumenty » and the WDT screen');
$check($codes($docs[3]) === ['ksef_not_sent'] && str_contains($docs[3]['signals'][0]['detail'], 'WDT/0103/2026 z 23.11.2026 · 1 dzień · ostatni błąd (walidacja faktury, 23.11) : WDT nie zawiera wymaganego pola: consignee_nip.')
    && $docs[3]['customer']['name'] === 'Kasia Nowak' && $docs[3]['ksef']['label'] === 'nie wysłano', 'not sent since 1 day, with the last KSeF log error ; customer without company → name');
$check($codes($docs[4]) === ['ksef_pending'] && str_contains($docs[4]['signals'][0]['detail'], 'wysłana 23.11 16:00 · 1 dzień bez odpowiedzi'), 'processing since yesterday → no answer');
$check($codes($docs[5]) === ['ksef_failed'] && $docs[5]['signals'][0]['detail'] === 'cURL error 28: Operation timed out', 'failed today → signalled at once');
$check($codes($docs[6]) === [] && !$docs[6]['review'] && $docs[6]['ksef']['label'] === 'nie wysłano' && !$docs[6]['ksef']['out'], 'not sent today: still within ksef_day.pending_days');
$check($codes($docs[7]) === ['no_wdt'] && $docs[7]['wdt'] === null && $docs[7]['links'][0]['url'] === '/shipments', 'shipment without WDT');
$check($codes($docs[8]) === ['not_invoiced'] && $docs[8]['wdt']['invoiced'] === null, 'WDT without invoice date');
$check($codes($docs[9]) === [] && $docs[9]['ksef']['env'] === 'test' && $docs[9]['ksef']['label'] === 'w toku', 'submitted today (test environment): within the delay');
$check(array_column($d['carried'], 'id') === [10, 13] && $d['carried'][0]['carried'] && $codes($d['carried'][0]) === ['ksef_rejected']
    && $d['carried'][1]['waiting'] && $d['carried'][1]['check']['result'] === 'in_progress' && $d['carried'][1]['check']['on'] === '2026-11-23',
    'carried: older open problems (« W toku » of yesterday comes back) ; justified, accepted and > 30 days left out');
$t = $d['totals'];
$check($t['shipments'] === 9 && $t['wdt'] === 8 && $t['accepted'] === 1 && $t['carried'] === 2 && $t['review'] === 8 && $t['clean'] === 3 && $t['waiting'] === 8 && $t['signals'] === 8
    && $t['by_code'] === ['no_wdt' => 1, 'not_invoiced' => 1, 'ksef_not_sent' => 1, 'ksef_rejected' => 3, 'ksef_failed' => 1, 'ksef_pending' => 1] && $d['can_finish'] === false,
    'totals: 9 shipments, 1 accepted, 8 documents to explain (2 carried)');
$check($d['ksef_since'] === '2026-10-01' && $d['pending_days'] === 1 && $d['lookback_days'] === 30, 'KSeF used since the first submitted shipment ; parameters');
$json = json_encode($d, JSON_UNESCAPED_UNICODE);
$check(!preg_match('/price|amount|net_|brutto|netto|value/i', $json), 'no amount in the page data');

// --- Krok 2 : wyniki
$check(str_starts_with($err(fn() => $svc->saveResult(['id_shipment' => 2, 'result' => 'x'], 1, $today)), 'custom:422:Wybierz wynik'), 'unknown result refused');
$check(str_starts_with($err(fn() => $svc->saveResult(['id_shipment' => 2, 'result' => 'in_progress', 'note' => ' '], 1, $today)), 'custom:422:W toku : wpisz notatkę'), '« W toku » needs a note');
$check(str_starts_with($err(fn() => $svc->saveResult(['id_shipment' => 7, 'result' => 'justified', 'note' => 'a'], 1, $today)), 'custom:422:Bez faktury — uzasadnione : wpisz notatkę'), '« Bez faktury » needs a note');
$e = $err(fn() => $svc->saveResult(['id_shipment' => 2, 'result' => 'fixed'], 1, $today));
$check(str_starts_with($e, 'custom:422:Nie można zapisać « Wysłane ponownie / poprawione » : KSeF nie przyjął jeszcze faktury WDT/0102/2026 (status : « odrzucona »)'), '« Poprawione » refused while KSeF has not accepted');
$check(str_contains($err(fn() => $svc->saveResult(['id_shipment' => 7, 'result' => 'fixed'], 1, $today)), 'wysyłka nadal nie ma faktury WDT'), '« Poprawione » refused without WDT');
$check(str_contains($err(fn() => $svc->saveResult(['id_shipment' => 8, 'result' => 'fixed'], 1, $today)), 'WDT/0107/2026 nadal nie ma daty faktury'), '« Poprawione » refused without invoice date');
$check(str_starts_with($err(fn() => $svc->saveResult(['id_shipment' => 1, 'result' => 'justified', 'note' => 'abc'], 1, $today)), 'custom:422:Ta wysyłka nie ma problemu'), 'clean document: only « Poprawione » makes sense');
$check(str_starts_with($err(fn() => $svc->saveResult(['id_shipment' => 14, 'result' => 'justified', 'note' => 'abc'], 1, $today)), 'custom:422:Tej wysyłki nie ma na liście'), 'shipment outside the window and the look-back refused');
$check(str_starts_with($err(fn() => $svc->finish(1, $today)), 'custom:422:Najpierw wyjaśnij 8 dokumentów'), 'finish blocked while documents wait');
$check($erp0 === $sum(), 'refused results write nothing in ERP tables');
$d = $svc->saveResult(['id_shipment' => 7, 'result' => 'justified', 'note' => 'Wysyłka próbek — bez sprzedaży'], 3, $today);
$row = $pdo->query('SELECT * FROM n_invoice_ksef_check WHERE id_shipment_log = 7')->fetch();
$check($row['result'] === 'justified' && $row['flags'] === 'no_wdt' && $row['id_wdt'] === null && $row['ksef_status'] === null && $row['checked_on'] === $today && (int) $row['id_user'] === 3
    && $row['shipment_date'] === '2026-11-24', '« Bez faktury — uzasadnione » saved with the signals at that time');
$d = $svc->saveResult(['id_shipment' => 3, 'result' => 'in_progress', 'note' => 'Brak NIP klienta — poprosiłam o dane'], 3, $today);
$d = $svc->saveResult(['id_shipment' => 13, 'result' => 'in_progress', 'note' => 'Klient przysłał NIP, korekta jutro'], 3, $today);
$x = array_column($d['carried'], null, 'id');
$check(!$x[13]['waiting'] && $x[13]['check']['on'] === $today && $x[13]['check']['by'] === 'Ewa L.' && $d['totals']['waiting'] === 5, '« W toku » saved today: carried document explained for today');
$st = $tasks->state(InvoicesKsefDayService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '3 dokumenty wyjaśnione', 'task state: in progress (results saved today)');
// Le cron des statuts (hors assistant) a relu KSeF : la WDT 102 renvoyée est acceptée.
$pdo->exec("UPDATE ksef_submission SET status = 'accepted', ksef_number = '5260001111-20261124-AB12CD-07', error_code = NULL, error_message = NULL WHERE id_shipment_log = 2");
$erp1 = $sum();
$d = $svc->saveResult(['id_shipment' => 2, 'result' => 'fixed'], 1, $today);
$x = array_column($d['docs'], null, 'id');
$check($x[2]['check']['result'] === 'fixed' && !$x[2]['waiting'] && $x[2]['signals'] === [] && $d['totals']['fixed'] === 1
    && $pdo->query('SELECT ksef_status FROM n_invoice_ksef_check WHERE id_shipment_log = 2')->fetchColumn() === 'accepted', '« Poprawione » saved once KSeF accepted (status checked at save time)');
$d = $svc->saveResult(['id_shipment' => 4, 'result' => 'in_progress', 'note' => 'KSeF przeciążony, status sprawdzam po południu'], 1, $today);
$d = $svc->saveResult(['id_shipment' => 5, 'result' => 'justified', 'note' => 'Faktura wystawiona w aplikacji MF — numer w notatce księgowej'], 1, $today);
$d = $svc->saveResult(['id_shipment' => 8, 'result' => 'in_progress', 'note' => 'Data faktury do uzupełnienia przez księgowość'], 1, $today);
$d = $svc->saveResult(['id_shipment' => 10, 'result' => 'justified', 'note' => 'Duplikat : faktura już przyjęta pod innym numerem'], 1, $today);
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && $erp1 === $sum(), 'all documents explained → step 3 open ; nothing written in ERP tables');
// Le problème revient après « Poprawione » : document de nouveau à expliquer.
$pdo->exec("UPDATE ksef_submission SET status = 'rejected' WHERE id_shipment_log = 2");
$x = $svc->data($today);
$check(array_column($x['docs'], null, 'id')[2]['waiting'] === true && $x['can_finish'] === false, '« Poprawione » reopened when KSeF status is no longer accepted');
$pdo->exec("UPDATE ksef_submission SET status = 'accepted' WHERE id_shipment_log = 2");
$erp2 = $sum();
$r = $svc->finish(1, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'invoices_ksef_checked'")->fetchAll();
$check($r['summary'] === '9 wysyłek · 2 przyjęte w KSeF · 8 dokumentów do wyjaśnienia (2 zaległe) · 1 poprawiony · 4 w toku · 3 bez faktury' && count($wd) === 1 && $wd[0]['day'] === $today
    && $r['data']['done'] !== null, 'finish: summary, n_wizard_done');
$st = $tasks->state(InvoicesKsefDayService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done today');
$check($tasks->state(InvoicesKsefDayService::CODE, '2026-11-25 00:00:00', '2026-11-26 00:00:00', '2026-11-25')['done'] === false, 'next day: task open again');
$check($erp2 === $sum(), 'finish writes nothing in ERP tables');

// Lendemain (mer. 25.11) : fenêtre depuis le 24.11 ; « W toku » d'hier revient tant que le problème reste, « Bez faktury » non.
$n = $make()->data('2026-11-25');
$check($n['window']['from'] === '2026-11-24' && $n['window']['kind'] === 'workday', 'next day: previous workday (validated yesterday)');
$check(array_column($n['carried'], 'id') === [4, 3, 13] && count(array_filter($n['carried'], fn($c) => $c['waiting'])) === 3, 'next day: yesterday\'s « W toku » come back as carried, justified ones do not');
$check(array_column($n['docs'], null, 'id')[6]['signals'][0]['code'] === 'ksef_not_sent', 'next day: yesterday\'s unsent WDT now signalled');

// Fenêtre : rattrapage depuis la dernière validation (24.11) jeudi 26.11 ; borné par ksef_day.catchup_days.
$w = $make()->window('2026-11-26');
$check($w['from'] === '2026-11-24' && $w['kind'] === 'catchup' && $w['last_done'] === '2026-11-24', 'catch-up from the last validation day');
$check($make()->window('2026-12-15')['from'] === '2026-12-08', 'catch-up bounded (7 days by default)');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'ksef_day.catchup_days'");
$check($make()->window('2026-11-26')['from'] === '2026-11-25' && $make()->param('catchup') === 1, 'catchup_days = 1 → previous workday only');
$pdo->exec("UPDATE n_prod_param SET value = '90' WHERE code = 'ksef_day.catchup_days'");
$check($make()->param('catchup') === 7, 'out of bounds → default 7');
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'ksef_day.pending_days'");
$check(array_column($make()->data('2026-11-25')['docs'], null, 'id')[6]['signals'] === [], 'pending_days = 3 → unsent WDT of yesterday not yet signalled');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'ksef_day.pending_days'");

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), result() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_invoices_ksef_day_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$di = $b->build();
$ctl = $di->get(InvoicesKsefDayController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'shipping'), (1, 'invoices'), (2, 'production')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
[$code3, $body3] = $call(3, 'data');
$check($code3 === 200 && $body3['data']['window']['to'] === date('Y-m-d') && isset($body3['data']['totals']['by_code']) && $body3['data']['shipments_link'] === '/shipments?type=ksef-pending',
    'controller data() (Administracja, right « shipping ») : 200, real day');
[$code1] = $call(1, 'data');
[$code2, $body2] = $call(2, 'data');
$check($code1 === 200 && $code2 === 403 && $body2['message'] === 'Brak dostępu do wysyłek i dokumentów (faktury WDT i KSeF).', 'controller: « shipping » right required (admin 200, Piekarz 403)');
[$codeR] = $call(2, 'result');
[$codeF] = $call(2, 'finish');
$check($codeR === 403 && $codeF === 403, 'controller result() and finish() guarded too');
unset($_SESSION['USER']);

// Hors KSeF : tant qu'aucune expédition n'a été envoyée, les WDT non envoyées ne sont pas signalées (comme « Do obsłużenia »).
$pdo->exec('DELETE FROM ksef_event_log'); $pdo->exec('DELETE FROM ksef_submission');
$o = $make()->data('2026-11-25');
$x = array_column($o['docs'], null, 'id');
$check($o['ksef_since'] === null && $x[6]['ksef']['out'] && $x[6]['ksef']['label'] === 'poza KSeF' && $x[6]['signals'] === [] && $o['totals']['out_of_ksef'] >= 1, 'KSeF never used: WDT out of KSeF, no signal');

// --- Aucun appel KSeF, aucun envoi : le code de l'assistant ne touche ni au client KSeF ni aux e-mails / SMS.
// Code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/InvoicesKsefDayService.php',
    'App/Repositories/Logistics/InvoicesKsefDayRepository.php', 'App/Controllers/Web/Shipping/InvoicesKsefDayController.php', 'public/assets/js/shipping/invoices-ksef-day.js']));
$check(!preg_match('/use App\\\\Services\\\\Ksef|KsefSubmissionService|KsefApiClient|KsefInvoiceSender|curl_|Mail|Sms|ksef\/send|refresh-statuses|https?:\/\//', $src), 'no KSeF client, no send route, no mail/SMS, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('invoices_ksef_checked')['link'] === '/shipments/ksef-day' && TaskWizards::byLink('/shipments/ksef-day') === 'invoices_ksef_checked'
    && isset(TaskWizards::AUTO['invoices_ksef_checked']) && PositionTaskService::LINK_AUTO['/shipments/ksef-day'] === 'invoices_ksef_checked', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['invoices_ksef_checked'][0] !== 'Faktury i KSeF dnia', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/shipments/ksef-day') === 'shipping' && PageAccess::keyFor('/ajax/shipments/ksef-day') === 'shipping' && PageAccess::keyFor('/ajax/shipments/ksef-day/result') === 'shipping'
    && PageAccess::keyFor('/shipments') === 'shipping' && PageAccess::keyFor('/ajax/shipments/5/ksef/send') === null && PageAccess::keyFor('/invoice-documents') === 'invoices',
    'page and ajax under the existing « shipping » right ; others unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'invoices_ksef_checked' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/shipments/ksef-day'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Shipping/InvoicesKsefDayRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/shipments/ksef-day') === 'index' && $found('GET', '/ajax/shipments/ksef-day') === 'data'
    && $found('POST', '/ajax/shipments/ksef-day/result') === 'result' && $found('POST', '/ajax/shipments/ksef-day/finish') === 'finish', 'routes');
$all = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($all->dispatch('GET', '/shipments/ksef-day')[0] === FastRoute\Dispatcher::FOUND && $all->dispatch('GET', '/shipments/ksef-day')[1]['controller'] === InvoicesKsefDayController::class
    && $all->dispatch('GET', '/shipments')[1]['method'] === 'overview' && $all->dispatch('GET', '/shipments/differences')[1]['method'] === 'index'
    && $all->dispatch('POST', '/ajax/shipments/5/ksef/send')[1]['method'] === 'send', 'routes file loaded by the */*Routes.php glob ; existing shipment routes unchanged');

if (getenv('IK_JSON_OUT')) file_put_contents((string) getenv('IK_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_invoices_ksef_day_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
