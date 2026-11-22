<?php
/**
 * [375] Asystent « Terminowość tygodnia (OTIF) » : semaine examinée (lundi → dimanche précédant le jour du contrôle),
 * commandes sans gratis ni archivées sans WDT ; à l'heure (heure des documents d'expédition, passage « W transporcie »,
 * date du document WDT, livraison confirmée et retard / refus sur la tablette chauffeur) ; complètes (lignes WDT des
 * palettes contre les commandes de la même WDT, manque signalé à la livraison) ; « brak danych » hors du calcul ; date
 * déplacée hors de la semaine ; tendance des 4 semaines ; par jour, client et cause ; décisions des assistants n°1 et
 * n°5 et réclamations (lecture seule) ; action par cause principale (note obligatoire, responsable / poste), étape 3
 * bloquée, « Zakończ » → n_otif_week et n_wizard_done, état de la tâche de la semaine ; aucune écriture hors des
 * tables de l'assistant ; contrôleur construit par PHP-DI et appelé (data, finish, droit « orders ») ; enregistrement
 * (catalogue, droits, routes, paramètres) ; migration 222 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_otif_week_test recréée (tables minimales + migration 222), supprimée à la fin (OT_JSON_OUT=fichier : données
 * de la page avant et après les actions, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Client\Order\OtifWeekController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OtifWeekRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\OtifWeekService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_otif_week_test'); $pdo->exec('CREATE DATABASE erp_otif_week_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_otif_week_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), name VARCHAR(40) NULL, surname VARCHAR(40) NULL)',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80))',
    "CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, status INT DEFAULT 1, expected_shipping_date DATETIME NULL,
        create_order_date DATETIME, update_status_date DATETIME NULL, is_freebie TINYINT DEFAULT 0, order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD', id_wdt INT NULL)",
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2), expected_quantity_carton DECIMAL(10,2) NULL)',
    "CREATE TABLE customer_order_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT NOT NULL, id_user INT NULL, action_type ENUM('create','update','delete') NOT NULL,
        source ENUM('web','api','collector','system') NOT NULL DEFAULT 'web', changed_fields JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40), invoiced_date DATE NULL)',
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_palette INT NULL, id_product INT, name VARCHAR(80), quantity DECIMAL(10,2))',
    'CREATE TABLE shipment_log (id INT AUTO_INCREMENT PRIMARY KEY, shipment_date DATE, id_customer INT, id_wz INT NULL, id_cmr INT NULL, id_wdt INT NULL, id_diff INT NULL, id_employee INT NULL, insert_timestamp DATETIME NULL)',
    "CREATE TABLE n_delivery_stop (id INT AUTO_INCREMENT PRIMARY KEY, ship_date DATE NOT NULL, id_customer INT NOT NULL, id_carrier_vehicle INT NULL, status ENUM('arrived','delivered','problem') NOT NULL,
        reason ENUM('closed','refused','damaged','missing','late','other') NULL, note VARCHAR(500) NULL, recorded_at DATETIME NOT NULL, UNIQUE KEY (ship_date, id_customer))",
    "CREATE TABLE complaint (id INT AUTO_INCREMENT PRIMARY KEY, complaint_type VARCHAR(20), complaint_reason VARCHAR(60) NULL, id_customer INT, id_order INT NULL, id_product INT NULL,
        acceptance_status VARCHAR(20) NULL, reported_at DATETIME NULL)",
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
// Tables des assistants n°1 et n°5 (décisions lues) : leurs CREATE TABLE seulement.
foreach (['217_orders_day_check.sql', '219_supplements_day_check.sql', '221_delivery_delays.sql'] as $m) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $m)) as $sql) if (str_starts_with(ltrim($sql), 'CREATE TABLE')) $pdo->exec($sql);
}
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order, active) VALUES (1, 'KIEROWNIK_OPERACYJNY', 'Kierownik operacyjny', 5, 1), (2, 'LOGISTYKA', 'Logistyka', 6, 1), (3, 'STARE', 'Stanowisko nieaktywne', 7, 0)");
$pdo->exec("INSERT INTO n_position_duty (number, wizard_code, proposed_wizard, rhythm) VALUES ('4.3', NULL, 'Terminowość tygodnia (OTIF)', 'weekly'), ('3.7', 'deliveries_delays_checked', NULL, 'daily'),
    ('4.4', NULL, 'Inny asystent', 'weekly')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/222_otif_week.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 222 (deux passages)
$duties = array_column($pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty')->fetchAll(), null, 'number');
$check($duties['4.3']['wizard_code'] === 'otif_week_reviewed' && $duties['4.3']['proposed_wizard'] === null && $duties['4.3']['rhythm'] === 'weekly', 'migration 222: duty 4.3 → otif_week_reviewed (weekly)');
$check($duties['3.7']['wizard_code'] === 'deliveries_delays_checked' && $duties['4.4']['proposed_wizard'] === 'Inny asystent', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'otif.%' ORDER BY code")->fetchAll(), 'value', 'code');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_otif_week_action', 'n_otif_week')")->fetchColumn();
$check($params === ['otif.delivery_days' => '1', 'otif.target_pct' => '95'] && $tables === 2, 'migration replayed: 2 params once, 2 tables');
foreach (array_keys($params) as $code) $check(isset(ParamRules::RANGES[$code]), "ParamRules bounds for {$code}");

// --- Données : contrôle le lun. 30.11.2026 → semaine examinée lun. 23.11 – dim. 29.11 ; tendance depuis le 26.10.
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL), (3, 'ewa', 'Ewa L.')");
$pdo->exec("INSERT INTO customer (id, company) VALUES (1, 'Sklep A'), (2, 'Sklep B'), (3, 'Sklep C'), (4, 'Sklep D')");
$pdo->exec("INSERT INTO final_product VALUES (10, 'Croissant'), (11, 'Bagietka')");
$order = function (int $id, int $cust, string $ship, array $lines, array $o = []) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, reference, status, expected_shipping_date, create_order_date, is_freebie, order_type, id_wdt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $cust, $o['ref'] ?? null, $o['status'] ?? 1, $ship . ' 00:00:00', '2026-11-16 09:00:00', $o['freebie'] ?? 0, $o['type'] ?? 'STANDARD', $o['wdt'] ?? null]);
    // expected_quantity_carton = quantity_carton, comme l'écrit l'ERP : il ne doit rien changer à la mesure.
    foreach ($lines as $p => $q) $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton) VALUES (?, ?, ?, ?)')->execute([$id, $p, $q, $q]);
};
$wdt = function (int $id, int $cust, string $day, array $lines, string|false|null $logged) use ($pdo) {
    $pdo->prepare('INSERT INTO document_wdt (id, doc_num, invoiced_date) VALUES (?, ?, ?)')->execute([$id, 'WDT-' . $id . '/2026', $day]);
    foreach ($lines as $p => $q) $pdo->prepare('INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity) VALUES (?, ?, ?, ?)')->execute([$id, $p, 'p' . $p, $q]);
    if ($logged !== false) $pdo->prepare('INSERT INTO shipment_log (shipment_date, id_customer, id_wdt, insert_timestamp) VALUES (?, ?, ?, ?)')->execute([$day, $cust, $id, $logged]);
};
$stop = fn(string $day, int $cust, string $status, ?string $reason, string $at, ?string $note = null) => $pdo->prepare('INSERT INTO n_delivery_stop (ship_date, id_customer, status, reason, note, recorded_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$day, $cust, $status, $reason, $note, $at]);
$log = fn(int $id, array $fields, string $at, string $source = 'web') => $pdo->prepare("INSERT INTO customer_order_changelog (id_order, action_type, source, changed_fields, created_at) VALUES (?, 'update', ?, ?, ?)")
    ->execute([$id, $source, json_encode($fields), $at]);

$order(401, 1, '2026-11-23', [10 => 40], ['status' => 2, 'wdt' => 91, 'ref' => 'PO-401']); $wdt(91, 1, '2026-11-23', [10 => 40], '2026-11-23 18:05:00');
$stop('2026-11-23', 1, 'delivered', null, '2026-11-24 06:30:00');                                     // OTIF, livrée le lendemain (1 jour admis)
$order(402, 2, '2026-11-23', [10 => 30, 11 => 10], ['status' => 3, 'wdt' => 92]); $wdt(92, 2, '2026-11-23', [10 => 30, 11 => 6], '2026-11-23 18:10:00'); // Bagietka 6 sur 10
$order(403, 3, '2026-11-24', [11 => 20], ['status' => 3, 'wdt' => 93]); $wdt(93, 3, '2026-11-24', [11 => 20], '2026-11-25 19:00:00'); // documents le lendemain : en retard
$order(404, 4, '2026-11-25', [10 => 10]);                                                              // pas expédiée
$order(405, 1, '2026-11-25', [10 => 8], ['status' => 2, 'wdt' => 94, 'type' => 'SUPPLEMENT']);        // domówienie + commande même WDT : 8 + 12 = 20
$order(406, 1, '2026-11-25', [10 => 12], ['status' => 2, 'wdt' => 94]); $wdt(94, 1, '2026-11-25', [10 => 20], '2026-11-25 17:00:00');
$stop('2026-11-25', 1, 'delivered', null, '2026-11-26 07:00:00');
$order(407, 2, '2026-11-26', [10 => 15], ['status' => 2, 'wdt' => 95]); $wdt(95, 2, '2026-11-26', [10 => 15], false); // sans journal : statut « W transporcie » le 26
$log(407, ['after' => ['status' => 3], 'reason' => 'Wyślij dzień'], '2026-11-26 18:00:00', 'system');
$stop('2026-11-26', 2, 'delivered', null, '2026-11-28 10:00:00');                                     // livrée 2 jours après : en retard
$order(408, 3, '2026-11-27', [10 => 5], ['status' => 3, 'wdt' => 96]); $wdt(96, 3, '2026-11-27', [10 => 5], '2026-11-27 16:00:00');
$stop('2026-11-27', 3, 'problem', 'missing', '2026-11-28 08:00:00', '2 kartony brak');                // manque à la livraison
$order(409, 4, '2026-11-27', [10 => 5], ['status' => 3]);                                              // « Oznacz jako wysłane » sans WDT : pas de quantités
$log(409, ['after' => ['status' => 3], 'reason' => 'Oznacz jako wysłane'], '2026-11-27 15:00:00');
$order(410, 1, '2026-11-26', [10 => 2], ['freebie' => 1]);                                             // gratis : absente
$order(411, 2, '2026-11-26', [10 => 2], ['status' => 5]);                                              // archivée sans WDT : hors mesure, comptée à part
$order(412, 3, '2026-12-02', [10 => 9]);                                                               // due le 25.11, déplacée au 2.12 le 24.11
$log(412, ['before' => ['expected_shipping_date' => '2026-11-25 00:00:00'], 'after' => ['expected_shipping_date' => '2026-12-02']], '2026-11-24 11:00:00');
$order(413, 4, '2026-11-24', [11 => 4], ['status' => 2, 'wdt' => 97]); $wdt(97, 4, '2026-11-24', [11 => 4], null); // journal sans heure : date du document
$order(414, 2, '2026-11-24', [10 => 6], ['status' => 3, 'wdt' => 98]); $wdt(98, 2, '2026-11-24', [10 => 6], '2026-11-24 17:00:00');
$stop('2026-11-24', 2, 'problem', 'refused', '2026-11-25 07:00:00');                                  // refusée
$order(415, 3, '2026-11-26', [11 => 10], ['status' => 3, 'wdt' => 99]); $wdt(99, 3, '2026-11-26', [11 => 8], '2026-11-26 17:30:00'); // 8 sur 10
$order(416, 1, '2026-11-28', [10 => 3], ['status' => 2, 'wdt' => 100]); $wdt(100, 1, '2026-11-28', [10 => 3], '2026-11-28 09:00:00'); // samedi (jour non ouvré)
$order(417, 4, '2026-11-26', [10 => 4], ['status' => 3, 'wdt' => 101]); $wdt(101, 4, '2026-11-26', [10 => 4], '2026-11-26 16:00:00'); // date changée dans la semaine (23 → 26) : pas « przesunięta »
$log(417, ['before' => ['expected_shipping_date' => '2026-11-23'], 'after' => ['expected_shipping_date' => '2026-11-26']], '2026-11-20 10:00:00');
// Semaines précédentes (tendance) : 16.11 deux OTIF, 9.11 une non expédiée, 2.11 rien, 26.10 une OTIF.
$order(301, 1, '2026-11-17', [10 => 5], ['status' => 2, 'wdt' => 81]); $wdt(81, 1, '2026-11-17', [10 => 5], '2026-11-17 18:00:00');
$order(302, 2, '2026-11-18', [10 => 5], ['status' => 2, 'wdt' => 82]); $wdt(82, 2, '2026-11-18', [10 => 5], '2026-11-18 18:00:00');
$order(303, 3, '2026-11-10', [10 => 5]);
$order(304, 4, '2026-10-27', [10 => 5], ['status' => 2, 'wdt' => 83]); $wdt(83, 4, '2026-10-27', [10 => 5], '2026-10-27 18:00:00');
$order(305, 4, '2026-10-20', [10 => 5]);                                                               // hors fenêtre
// Décisions des autres assistants et réclamation.
$pdo->exec("INSERT INTO n_delivery_delay_check (id_order, day, decision, new_date, note, id_user) VALUES (404, '2026-11-26', 'new_date', '2026-12-01', 'Brak bagietek, klient zgodził się', 1),
    (404, '2026-11-25', 'contact', NULL, 'Telefon', 1)");
$pdo->exec("INSERT INTO n_order_check (id_order, day, result, note, id_user) VALUES (412, '2026-11-24', 'changed', 'Klient przesunął na środę', 3)");
$pdo->exec("INSERT INTO complaint (complaint_type, complaint_reason, id_customer, id_order, id_product, acceptance_status, reported_at) VALUES ('PRODUCT', 'lack_of_conformity', 2, 402, 11, 'NEW', '2026-11-24 09:00:00')");

$sum = fn() => $pdo->query("SELECT CONCAT((SELECT COUNT(*) FROM customer_order), '/', (SELECT COALESCE(SUM(status), 0) FROM customer_order), '/', (SELECT COUNT(*) FROM customer_order_product), '/',
    (SELECT COUNT(*) FROM customer_order_changelog), '/', (SELECT COUNT(*) FROM document_wdt_product), '/', (SELECT COUNT(*) FROM n_delivery_delay_check), '/', (SELECT COUNT(*) FROM n_order_check), '/', (SELECT COUNT(*) FROM complaint))")->fetchColumn();
$erpBefore = $sum();

$lw = new LightWizardRepository($pdo);
$svc = new OtifWeekService(new OtifWeekRepository($pdo), $lw, new ParamRepository($pdo));
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);
$today = '2026-11-30';

$d = $svc->data($today);
$boot = $d;
$o = array_column($d['orders'], null, 'id');
$t = $d['totals'];
$check($d['week_start'] === '2026-11-23' && $d['week_end'] === '2026-11-29' && OtifWeekService::reviewedWeek('2026-12-06') === '2026-11-23' && OtifWeekService::reviewedWeek('2026-11-23') === '2026-11-16',
    'reviewed week = previous Monday → Sunday');
$check(count($o) === 15 && array_column($d['orders'], 'shipping_date') === array_values(array_map(fn($x) => $x, (function ($v) { sort($v); return $v; })(array_column($d['orders'], 'shipping_date')))), 'orders in week: 15 (sorted by date)');
$check(!isset($o[410]) && !isset($o[411]) && !isset($o[301]) && $t['cancelled'] === 1, 'freebie excluded, archived without WDT counted apart, other weeks out');
$check($o[401]['on_time'] === true && $o[401]['in_full'] === true && $o[401]['otif'] === true && $o[401]['ship_source'] === 'log' && $o[401]['delivered_label'] === 'wt. 24.11', '401: OTIF (documents 23.11, delivered next day)');
$check($o[402]['on_time'] === true && $o[402]['in_full'] === false && $o[402]['causes'][0]['code'] === 'short_shipped'
    && $o[402]['short'] === [['product' => 'Bagietka', 'ordered' => 10.0, 'shipped' => 6.0]] && str_contains($o[402]['causes'][0]['reason'], 'Bagietka 6 z 10 kart.'), '402: incomplete (WDT 6 of 10)');
$check($o[403]['on_time'] === false && $o[403]['in_full'] === true && $o[403]['causes'][0]['code'] === 'shipped_late' && str_contains($o[403]['causes'][0]['reason'], '1 dzień po terminie'), '403: shipped one day late (documents time)');
$check($o[404]['shipped'] === false && $o[404]['on_time'] === false && $o[404]['in_full'] === null && $o[404]['otif'] === false && $o[404]['causes'][0]['code'] === 'not_shipped'
    && str_contains($o[404]['causes'][0]['reason'], '5 dni temu'), '404: not shipped');
$check($o[405]['otif'] === true && $o[406]['otif'] === true && $o[405]['supplement'], '405 + 406: same WDT, 8 + 12 = 20 cartons → both complete');
$check($o[407]['ship_source'] === 'status' && $o[407]['causes'][0]['code'] === 'delivered_late' && $o[407]['on_time'] === false && $o[407]['in_full'] === true, '407: shipped on time (status), delivered 2 days later');
$check($o[408]['on_time'] === true && $o[408]['in_full'] === false && $o[408]['causes'][0]['code'] === 'delivery_shortage' && str_contains($o[408]['causes'][0]['reason'], '2 kartony brak'), '408: shortage at delivery (driver tablet)');
$check($o[409]['on_time'] === true && $o[409]['in_full'] === null && $o[409]['otif'] === null && $o[409]['no_data'] === ['wysłane bez WDT — brak ilości wysłanych'], '409: shipped without WDT → brak danych, outside OTIF');
$check($o[412]['otif'] === false && $o[412]['causes'][0]['code'] === 'moved' && $o[412]['shipping_date'] === '2026-11-25' && $o[412]['moved']['after_label'] === 'śr. 2.12', '412: date moved out of the week → not on time in the original week');
$check($o[413]['ship_source'] === 'document' && $o[413]['otif'] === true, '413: no document time → WDT date, OTIF');
$check($o[414]['causes'][0]['code'] === 'delivery_failed' && $o[414]['on_time'] === false && $o[414]['in_full'] === true, '414: refused at delivery');
$check($o[415]['causes'][0]['code'] === 'short_shipped', '415: incomplete');
$check($o[417]['otif'] === true && $o[417]['date_changes'] === 1, '417: date changed inside the week (before the week) → measured on its current date');
$check($t['orders'] === 15 && $t['measured_ot'] === 15 && $t['on_time'] === 10 && $t['measured_full'] === 12 && $t['in_full'] === 9 && $t['measured_otif'] === 14 && $t['otif'] === 6
    && $t['non_otif'] === 8 && $t['no_data'] === 1 && $t['otif_pct'] === 42.9 && $t['on_time_pct'] === 66.7 && $t['in_full_pct'] === 75.0 && $t['below_target'] === true,
    'totals: on time 10/15, in full 9/12, OTIF 6/14 = 42.9 % (below 95 %)');
$tr = array_column($d['trend'], null, 'week_start');
$check(count($d['trend']) === 5 && $tr['2026-10-26']['otif_pct'] === 100.0 && $tr['2026-11-02']['otif_pct'] === null && $tr['2026-11-09']['otif_pct'] === 0.0 && $tr['2026-11-16']['otif_pct'] === 100.0
    && $tr['2026-11-23']['current'] && $t['prev_avg_pct'] === 66.7 && $t['delta_pts'] === -23.8, 'trend: 4 previous weeks, average 66.7 %, −23.8 pts');
$days = array_column($d['days'], null, 'date');
$check(array_keys($days) === ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28'] && $days['2026-11-28']['workday'] === false && $days['2026-11-25']['orders'] === 4
    && $days['2026-11-25']['otif'] === 2, 'by day: 5 workdays + Saturday with an order ; moved order on its original day');
$check(array_column($d['customers'], 'name') === ['Sklep C', 'Sklep B', 'Sklep D', 'Sklep A'] && $d['customers'][0]['non_otif'] === 4 && $d['customers'][2]['no_data'] === 1, 'by customer: worst first');
$causes = array_column($d['causes'], null, 'cause');
$check(array_keys($causes) === ['moved', 'not_shipped', 'shipped_late', 'delivery_failed', 'delivered_late', 'short_shipped', 'delivery_shortage'] && $causes['short_shipped']['orders'] === 2,
    'by cause: 7 causes in priority order');
$late = array_column($d['late'], null, 'id');
$check(array_keys($late) === [412, 404, 403, 414, 407, 402, 415, 408], 'step 2: non-OTIF orders by cause priority');
$check(count($late[404]['prior']) === 1 && $late[404]['prior'][0]['source'] === 'delivery_delays' && $late[404]['prior'][0]['label'] === 'Nowy termin' && $late[404]['prior'][0]['new_label'] === 'wt. 1.12'
    && $late[412]['prior'][0]['label'] === 'Zmienione' && $late[412]['prior'][0]['by'] === 'Ewa L.', 'prior decisions (assistants 1 and 5, last one) read-only');
$check($o[402]['complaints'][0]['reason'] === 'Brak zgodności z zamówieniem' && $o[402]['complaints'][0]['product'] === 'Bagietka', 'complaint shown');
$check(count($d['groups']) === 7 && $d['groups'][5]['cause'] === 'short_shipped' && $d['groups'][5]['orders'] === 2 && $d['groups'][5]['customers'] === ['Sklep B', 'Sklep C']
    && $t['waiting'] === 7 && $d['can_finish'] === false, '7 cause groups waiting');
$check(!array_filter(json_decode(json_encode($d), true)['orders'], fn($x) => isset($x['value']) || isset($x['amount'])) && !str_contains(json_encode($d), 'price'), 'no amounts in data');
$check(array_column($d['positions'], 'id') === [1, 2] && count($d['users']) === 3, 'responsible: users and active positions');

// --- Krok 2 : działania
$check(str_starts_with($err(fn() => $svc->saveAction(['cause' => 'moved', 'decision' => 'x'], 1, $today)), 'custom:422:Wybierz działanie'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveAction(['cause' => 'moved', 'decision' => 'corrective', 'note' => ' '], 1, $today)), 'custom:422:Działanie korygujące'), 'corrective needs a note');
$check(str_starts_with($err(fn() => $svc->saveAction(['cause' => 'nope', 'decision' => 'watch'], 1, $today)), 'custom:422:Tej przyczyny'), 'unknown cause refused');
$check(str_starts_with($err(fn() => $svc->saveAction(['cause' => 'moved', 'decision' => 'corrective', 'note' => 'abc', 'id_responsible' => 99], 1, $today)), 'custom:422:Nieznana osoba'), 'unknown responsible refused');
$check(str_starts_with($err(fn() => $svc->saveAction(['cause' => 'moved', 'decision' => 'corrective', 'note' => 'abc', 'id_position' => 3], 1, $today)), 'custom:422:Nieznane stanowisko'), 'inactive position refused');
$check(str_starts_with($err(fn() => $svc->finish(1, $today)), 'custom:422:Najpierw wybierz działanie dla 7 przyczyn'), 'finish blocked while causes wait');
$d = $svc->saveAction(['cause' => 'short_shipped', 'decision' => 'corrective', 'note' => 'Pakowanie : kontrola ilości przed zamknięciem palety', 'id_responsible' => 3, 'id_position' => 2], 1, $today);
$g = array_column($d['groups'], null, 'cause');
$check($g['short_shipped']['action']['decision'] === 'corrective' && $g['short_shipped']['action']['responsible'] === 'Ewa L.' && $g['short_shipped']['action']['position'] === 'Logistyka'
    && $d['totals']['corrective'] === 1 && $d['totals']['waiting'] === 6, 'corrective action with responsible and position saved');
$d = $svc->saveAction(['cause' => 'moved', 'decision' => 'accepted', 'note' => 'Na prośbę klienta', 'id_responsible' => 3], 1, $today);
$g = array_column($d['groups'], null, 'cause');
$check($g['moved']['action']['id_responsible'] === null && $g['moved']['action']['note'] === 'Na prośbę klienta', 'accepted: responsible not kept');
$st = $tasks->state(OtifWeekService::CODE, '2026-11-30 00:00:00', '2026-12-07 00:00:00', '2026-12-04');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '2 przyczyny z działaniem', 'task state: in progress (actions of the reviewed week)');
$after = null;
foreach (['not_shipped' => 'corrective', 'shipped_late' => 'watch', 'delivery_failed' => 'accepted', 'delivered_late' => 'watch', 'delivery_shortage' => 'watch'] as $c => $dec) {
    $after = $svc->saveAction(['cause' => $c, 'decision' => $dec, 'note' => $dec === 'corrective' ? 'Plan produkcji : bagietki z dnia wcześniej' : ''], 2, $today);
}
$d = $svc->saveAction(['cause' => 'shipped_late', 'decision' => 'watch', 'note' => 'Druga zmiana dokumentów'], 1, $today); // la dernière compte
$check((int) $pdo->query("SELECT COUNT(*) FROM n_otif_week_action WHERE week_start = '2026-11-23'")->fetchColumn() === 7 && $d['can_finish'] === true
    && $d['summary'] === 'OTIF 42,9 % (cel 95 %) · −23,8 pkt · 15 zamówień · 7 przyczyn : 2 działania korygujące · 3 do obserwacji · 2 akceptowane', 'all causes decided ; summary');
$r = $svc->finish(1, $today);
$wk = $pdo->query("SELECT * FROM n_otif_week WHERE week_start = '2026-11-23'")->fetch();
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'otif_week_reviewed'")->fetchAll();
$check($r['summary'] === $d['summary'] && (float) $wk['otif_pct'] === 42.9 && (int) $wk['orders'] === 15 && (int) $wk['otif'] === 6 && (float) $wk['target_pct'] === 95.0
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null && $r['data']['saved']['otif_pct'] === 42.9, 'finish: week result frozen, n_wizard_done');
$check($r['data']['trend'][4]['saved_pct'] === 42.9, 'trend shows the saved result');
$st = $tasks->state(OtifWeekService::CODE, '2026-11-30 00:00:00', '2026-12-07 00:00:00', '2026-12-04');
$check($st['done'] === true && $st['summary'] === $d['summary'], 'task state: done this week');
$check($tasks->state(OtifWeekService::CODE, '2026-12-07 00:00:00', '2026-12-14 00:00:00', '2026-12-07')['done'] === false, 'next week: task open again');
$check($erpBefore === $sum(), 'no write in ERP tables (orders, lines, history, WDT, other wizards, complaints)');

// Réglages : objectif 80 %, 2 jours de livraison → 407 à l'heure ; valeur hors bornes → défaut.
$pdo->exec("UPDATE n_prod_param SET value = '80' WHERE code = 'otif.target_pct'"); $pdo->exec("UPDATE n_prod_param SET value = '2' WHERE code = 'otif.delivery_days'");
$fresh = fn() => new OtifWeekService(new OtifWeekRepository($pdo), $lw, new ParamRepository($pdo)); // réglages relus (cache par requête)
$x = $fresh()->data($today);
$check($x['settings']['target_pct'] === 80.0 && $x['settings']['delivery_days'] === 2 && array_column($x['orders'], 'otif', 'id')[407] === true, 'settings: target 80 %, 2 delivery days → 407 OTIF');
$pdo->exec("UPDATE n_prod_param SET value = '150' WHERE code = 'otif.target_pct'"); $pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'otif.delivery_days'");
$check($fresh()->data($today)['settings']['target_pct'] === 95.0, 'out of bounds target → default 95');

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_otif_week_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$di = $b->build();
$ctl = $di->get(OtifWeekController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'orders'), (2, 'logistics')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
[$code, $body] = $call(1, 'data');
$check($code === 200 && $body['data']['week_start'] === OtifWeekService::reviewedWeek(date('Y-m-d')) && isset($body['data']['totals']['otif_pct']), 'controller data() (admin) : 200, real week');
[$code3, $body3] = $call(3, 'data');
[$code2, $body2] = $call(2, 'data');
$check($code3 === 200 && $code2 === 403 && $body2['message'] === 'Brak dostępu do zamówień klientów.', 'controller: « orders » right required (Kierownik operacyjny 200, Logistyka 403)');
[$codeF, $bodyF] = $call(2, 'finish');
$check($codeF === 403, 'controller finish() guarded too');
unset($_SESSION['USER']);

// --- Enregistrement : catalogue, droits, routes, équipe.
$check(TaskWizards::info('otif_week_reviewed')['link'] === '/client-orders/otif' && TaskWizards::byLink('/client-orders/otif') === 'otif_week_reviewed'
    && isset(TaskWizards::AUTO['otif_week_reviewed']) && PositionTaskService::LINK_AUTO['/client-orders/otif'] === 'otif_week_reviewed', 'catalog, auto description and position-task link');
$check(PageAccess::keyFor('/client-orders/otif') === 'orders' && PageAccess::keyFor('/ajax/client-orders/otif') === 'orders' && PageAccess::keyFor('/ajax/client-orders/otif/action') === 'orders'
    && PageAccess::keyFor('/ajax/client-orders/missing') === 'orders' && PageAccess::keyFor('/logistics/delays') === 'logistics', 'page and ajax under the existing « orders » right ; others unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'otif_week_reviewed' =>"), 'team tasks hint');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Client/OtifRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/client-orders/otif') === 'index' && $found('GET', '/ajax/client-orders/otif') === 'data'
    && $found('POST', '/ajax/client-orders/otif/action') === 'action' && $found('POST', '/ajax/client-orders/otif/finish') === 'finish', 'routes');
$all = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($all->dispatch('GET', '/client-orders/otif')[0] === FastRoute\Dispatcher::FOUND && $all->dispatch('GET', '/client-orders/otif')[1]['controller'] === OtifWeekController::class,
    'routes file loaded by the */*Routes.php glob');

if (getenv('OT_JSON_OUT')) file_put_contents((string) getenv('OT_JSON_OUT'), json_encode(['before' => $boot, 'after' => $r['data']], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_otif_week_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
