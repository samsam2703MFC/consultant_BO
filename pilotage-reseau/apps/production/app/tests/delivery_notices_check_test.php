<?php
/**
 * [382] Asystent « Powiadomienia o dostawie » : jours contrôlés (aujourd'hui ; jour ouvré suivant seulement quand la règle
 * automatique « delivery_planned » est active, system.auto_send ≠ 0 et le jour ouvré suivant ≤ aujourd'hui + days_before ;
 * vendredi → lundi hors fenêtre avec days_before = 1 ; règle absente ou coupée → aujourd'hui seul) ; clients de
 * LogisticsService::day (jour hors fenêtre ignoré) avec tournée, véhicule, départ ; « powiadomiony » selon la barre Dzień
 * wysyłki (ShippingDayRepository::notifiedCustomers) ; signaux : e-mail en échec (status failed, ou communication_message
 * failed), SMS en échec, aucun avis réussi (aucun avis, SMS seulement « logged »), client sans e-mail ni téléphone ; avis
 * réussi après l'échec ; décisions (wysłane ponownie refusé sans avis réussi postérieur au dernier échec, téléphone et
 * uzasadnione avec note obligatoire), décision caduque après un nouvel échec, étape 3 bloquée, « Zakończ » → n_wizard_done,
 * état de la tâche du jour ; aucune écriture dans les tables d'origine (checksums) ; AUCUN ENVOI : contrôleur construit par
 * PHP-DI avec passerelle SMS factice et file e-mail / SMTP espionnées (jamais construites ni appelées), n_delivery_notice,
 * communication_message et n_sms_log inchangés ; data(), decision(), finish() réellement appelés (Logistyka « logistics »
 * 200, Piekarz sans droit 403) ; enregistrement (catalogue, droits, routes, équipe, menu) ; migration 230 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_delivery_notices_check_test recréée (n_delivery_notice comme docs/db/n_schema.sql, tables minimales de la
 * logistique comme tests/delivery_delays_test.php, migration 230), supprimée à la fin (DN_JSON_OUT=fichier : données de la
 * page avant et après les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Logistics\DeliveryNoticesCheckController;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\{DeliveryNoticesCheckRepository, LogisticsRepository, ShippingDayRepository};
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\{OutgoingMessageService, SmtpTransport};
use App\Services\Logistics\LogisticsService;
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\DeliveryNoticesCheckService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_delivery_notices_check_test'); $pdo->exec('CREATE DATABASE erp_delivery_notices_check_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE erp_delivery_notices_check_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    "CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), name VARCHAR(40) NULL, surname VARCHAR(40) NULL, fac_town VARCHAR(60) NULL, fac_country VARCHAR(40) NULL,
        active TINYINT DEFAULT 1, email_head VARCHAR(120) NULL, email_salesman VARCHAR(120) NULL, phone_head VARCHAR(40) NULL, phone_alt VARCHAR(40) NULL, communication_lang VARCHAR(5) NULL)",
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1, cpp INT NULL, cpl INT NULL, lpp INT NULL, ppc INT NULL, weight DECIMAL(10,2) NULL, id_category INT NULL)',
    'CREATE TABLE final_product_ppwr_sales_packaging (id INT AUTO_INCREMENT PRIMARY KEY, id_final_product INT, id_outer_raw_material INT NULL)',
    'CREATE TABLE carton_dimension (id INT PRIMARY KEY, length INT, width INT, height INT)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    "CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, order_description TEXT NULL, status INT DEFAULT 1, expected_shipping_date DATETIME NULL,
        create_order_date DATETIME, update_status_date DATETIME NULL, is_freebie TINYINT DEFAULT 0, order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD', id_wdt INT NULL, id_proforma INT NULL)",
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))',
    'CREATE TABLE carrier (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1)',
    "CREATE TABLE carrier_vehicle (id INT PRIMARY KEY, id_carrier INT, regis_num VARCHAR(20), driver_name VARCHAR(40) DEFAULT '', driver_surname VARCHAR(40) DEFAULT '', driver_phone VARCHAR(30) DEFAULT '', driver_email VARCHAR(80) DEFAULT '')",
    'CREATE TABLE n_vehicle (id_carrier_vehicle INT PRIMARY KEY, pallet_places INT NULL, max_load_kg INT NULL, departure_time TIME NULL, trips_per_week INT NULL, active TINYINT DEFAULT 1, notes VARCHAR(255) NULL, id_driver INT NULL, updated_by INT NULL)',
    'CREATE TABLE n_tour (id INT PRIMARY KEY, name VARCHAR(80), weekdays INT, departure_time TIME NULL, id_driver INT NULL, color VARCHAR(10) NULL, active TINYINT DEFAULT 1, notes VARCHAR(255) NULL)',
    'CREATE TABLE n_tour_vehicle (id_tour INT, id_carrier_vehicle INT, position INT)',
    'CREATE TABLE n_tour_customer (id_tour INT, id_customer INT, stop_order INT NULL, PRIMARY KEY (id_tour, id_customer))',
    'CREATE TABLE n_shipping_assignment (ship_date DATE, id_customer INT, id_tour INT NULL, tour_set TINYINT DEFAULT 0, id_carrier_vehicle INT NULL, vehicle_set TINYINT DEFAULT 0,
        stop_order INT NULL, plan_set TINYINT DEFAULT 0, updated_by INT NULL, PRIMARY KEY (ship_date, id_customer))',
    'CREATE TABLE document_cmr (id INT PRIMARY KEY, id_customer INT, id_carrier_vehicle INT NULL)',
    'CREATE TABLE palets_delivery (id_palet INT AUTO_INCREMENT PRIMARY KEY, id_customer INT, delivery_date DATE, status INT, last_update_date DATETIME NULL)',
    'CREATE TABLE n_mixed_pallet (id INT PRIMARY KEY, ship_date DATE, id_customer INT, pallet VARCHAR(20), height_mm INT NULL, kg DECIMAL(8,2) NULL, confirmed_at DATETIME, confirmed_by INT NULL)',
    'CREATE TABLE n_mixed_pallet_item (id INT AUTO_INCREMENT PRIMARY KEY, id_mixed_pallet INT, position INT, id_final_product INT, cartons INT, layers INT, per_layer INT)',
    // n_delivery_notice exactement comme docs/db/n_schema.sql (migration 062, renommée et réindexée en 070).
    "CREATE TABLE n_delivery_notice (id int(11) NOT NULL AUTO_INCREMENT, ship_date date NOT NULL, id_customer int(11) NOT NULL, channel varchar(8) NOT NULL COMMENT 'mail, sms',
        language char(2) NOT NULL, recipient varchar(190) NOT NULL, subject varchar(255) DEFAULT NULL, body text NOT NULL,
        status varchar(16) NOT NULL COMMENT 'queued (e-mail w kolejce), logged (SMS zapisany, bez dostawcy), sent, failed', id_message int(11) DEFAULT NULL COMMENT 'communication_message (e-mail)',
        provider varchar(40) DEFAULT NULL, error varchar(255) DEFAULT NULL, created_by varchar(160) DEFAULT NULL, created_at datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id), KEY idx_delivery_notice_day (ship_date, id_customer), KEY idx_n_delivery_notice_customer (id_customer), KEY idx_n_delivery_notice_message (id_message))",
    "CREATE TABLE communication_message (id INT AUTO_INCREMENT PRIMARY KEY, message_type VARCHAR(64), recipient VARCHAR(190), status VARCHAR(16) NOT NULL, error_message TEXT NULL)",
    "CREATE TABLE n_sms_log (id INT AUTO_INCREMENT PRIMARY KEY, id_customer INT NULL, context VARCHAR(30) NOT NULL, ref_id INT NULL, recipient VARCHAR(40) NOT NULL, body VARCHAR(1000) NOT NULL,
        status VARCHAR(16) NOT NULL, provider VARCHAR(40) NULL, provider_id VARCHAR(64) NULL, error VARCHAR(255) NULL, created_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE n_mail_rule (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL, event VARCHAR(40) NOT NULL, days_before TINYINT NOT NULL DEFAULT 1, enabled TINYINT(1) NOT NULL DEFAULT 0)",
    "CREATE TABLE n_mail_dispatch (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_rule INT NOT NULL, ref_key VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL)",
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
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours'),
    ('planning.default_departure_time', '20:00', '20:00', 'time', 'wyjazd', 'départ'), ('planning.loading_time_min', '60', '60', 'int', 'załadunek', 'chargement'),
    ('system.auto_send', '1', '1', 'bool', 'wysyłki automatyczne', 'envois automatiques')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'LOGISTYKA', 'Logistyka', 1), (2, 'KIEROWCA', 'Kierowca-dostawca', 2), (3, 'PIEKARZ', 'Piekarz', 3)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '4', NULL, 'Powiadomienia o dostawie', 'daily'),
    (1, '5', 'deliveries_delays_checked', NULL, 'daily'), (1, '7', NULL, 'Inny asystent', 'daily'), (2, '7', NULL, NULL, 'none')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/230_delivery_notices_check.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 230 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'delivery_notices_checked' && $duties[0]['proposed_wizard'] === null && $duties[0]['rhythm'] === 'daily', 'migration 230: LOGISTYKA 4 → delivery_notices_checked (daily)');
$check($duties[1]['wizard_code'] === 'deliveries_delays_checked' && $duties[2]['proposed_wizard'] === 'Inny asystent' && $duties[3]['wizard_code'] === null, 'migration: other duties untouched');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_notice_check'")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_notice_check'")->fetchColumn();
$check($tables === 1 && $fks === 0, 'migration replayed: 1 table, no foreign key');
$last = array_values(array_filter(MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/230_delivery_notices_check.sql')), fn($s) => trim($s) !== ''));
$check(trim(end($last), " \n;") === "UPDATE n_position_duty SET wizard_code = 'delivery_notices_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Powiadomienia o dostawie'", 'migration: last statement is the duty UPDATE');

// --- Données fictives : contrôle le mar. 24.11.2026 ; jour ouvré suivant mer. 25.11.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'ewa', 'Ewa L.'), (3, 'adam', 'Adam K.'), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, fac_town, email_head, email_salesman, phone_head, phone_alt) VALUES
    (1, 'Bistro Lumière', 'Bruxelles', 'bistro@example.test', NULL, NULL, NULL),
    (2, 'Hotel Vistule', 'Liège', NULL, NULL, '0476 12 34 56', NULL),
    (3, 'Café Mazur', 'Gent', '', 'pas-une-adresse', '12', NULL),
    (4, 'Sklep Nowy', 'Namur', NULL, 'handel@example.test', NULL, NULL),
    (5, 'Piekarnia Róża', 'Mons', 'roza@example.test', NULL, NULL, NULL),
    (6, 'Delikatesy Wars', 'Wavre', NULL, NULL, NULL, '0048 600 300 400'),
    (7, 'Brasserie Nord', 'Leuven', 'nord@example.test', NULL, '+32 470 11 22 33', NULL),
    (8, 'Sklep Jutro', 'Arlon', NULL, NULL, '+32 470 99 88 77', NULL),
    (9, 'Poza oknem', 'Spa', 'spa@example.test', NULL, NULL, NULL)");
$pdo->exec("INSERT INTO final_product (id, name, cpp, cpl, lpp, ppc, weight) VALUES (10, 'Croissant', 40, 8, 5, 60, 70)");
$pdo->exec("INSERT INTO carrier VALUES (1, 'Transport własny', 1)");
$pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname) VALUES (1, 1, 'WX 1234A', 'Jan', 'Kowalski')");
$pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, departure_time, active) VALUES (1, 33, '20:00', 1)");
$pdo->exec("INSERT INTO n_tour (id, name, weekdays, departure_time, active) VALUES (1, 'Bruksela', 127, '18:30', 1)");
$pdo->exec("INSERT INTO n_tour_vehicle VALUES (1, 1, 1)");
$pdo->exec("INSERT INTO n_tour_customer VALUES (1, 1, 1), (1, 2, 2)");
$order = function (int $id, int $cust, string $ship) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date) VALUES (?, ?, 1, ?, ?)')->execute([$id, $cust, $ship . ' 00:00:00', '2026-11-20 09:00:00']);
    $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (?, 10, 40)')->execute([$id]);
};
foreach ([1, 2, 3, 4, 5, 6, 7] as $c) $order(100 + $c, $c, '2026-11-24');
$order(201, 1, '2026-11-25');
$order(208, 8, '2026-11-25');
$order(309, 9, '2026-11-26');                       // hors fenêtre
$pdo->exec("INSERT INTO n_mail_rule (code, event, days_before, enabled) VALUES ('DELIVERY_PLANNED', 'delivery_planned', 1, 1), ('SHIPMENT_SENT', 'shipment_sent', 5, 1)");
$pdo->exec("INSERT INTO communication_message (id, message_type, recipient, status, error_message) VALUES (501, 'delivery_details', 'bistro@example.test', 'queued', NULL),
    (502, 'delivery_details', 'roza@example.test', 'failed', 'Mailbox unavailable'), (503, 'delivery_details', 'nord@example.test', 'sent', NULL), (504, 'delivery_details', 'bistro@example.test', 'queued', NULL)");
$ins = $pdo->prepare("INSERT INTO n_delivery_notice (id, ship_date, id_customer, channel, language, recipient, body, status, id_message, provider, error, created_by, created_at) VALUES (?, ?, ?, ?, 'fr', ?, 'x', ?, ?, ?, ?, ?, ?)");
foreach ([
    [1, '2026-11-24', 1, 'mail', 'bistro@example.test', 'queued', 501, null, null, 'automat:DELIVERY_PLANNED', '2026-11-23 06:00:00'],
    [2, '2026-11-24', 2, 'sms', '0476123456', 'failed', null, 'smsapi', 'Nieprawidłowy numer', 'automat:DELIVERY_PLANNED', '2026-11-23 06:00:00'],
    [3, '2026-11-24', 5, 'mail', 'roza@example.test', 'queued', 502, null, null, 'automat:DELIVERY_PLANNED', '2026-11-23 06:00:00'],     // odrzucony przez kolejkę
    [4, '2026-11-24', 6, 'sms', '+48600300400', 'logged', null, 'log_only', null, 'Ewa L.', '2026-11-23 09:00:00'],                     // tylko zapisany
    [5, '2026-11-24', 7, 'mail', 'nord@example.test', 'queued', 503, null, null, 'automat:DELIVERY_PLANNED', '2026-11-23 06:00:00'],
    [6, '2026-11-24', 7, 'sms', '+32470112233', 'failed', null, 'smsapi', 'Timeout', 'automat:DELIVERY_PLANNED', '2026-11-23 06:01:00'],
    [7, '2026-11-24', 7, 'sms', '+32470112233', 'sent', null, 'smsapi', null, 'Ewa L.', '2026-11-23 10:15:00'],                         // wysłane ponownie
    [8, '2026-11-25', 1, 'mail', 'bistro@example.test', 'queued', 504, null, null, 'automat:DELIVERY_PLANNED', '2026-11-24 06:00:00'],
    [9, '2026-11-23', 2, 'sms', '0476123456', 'failed', null, 'smsapi', 'Stary błąd', 'automat:DELIVERY_PLANNED', '2026-11-22 06:00:00'], // inny dzień
] as $row) $ins->execute($row);
$pdo->exec("INSERT INTO n_sms_log (context, ref_id, recipient, body, status) VALUES ('delivery_notice', 2, '0476123456', 'x', 'failed')");

$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(),
    ['n_delivery_notice', 'communication_message', 'n_sms_log', 'n_mail_rule', 'n_mail_dispatch', 'customer', 'customer_order', 'customer_order_product', 'n_shipping_assignment', 'n_prod_param', 'user', 'n_position']));

$logistics = new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo), null);
$make = fn() => new DeliveryNoticesCheckService(new DeliveryNoticesCheckRepository($pdo), new ShippingDayRepository($pdo), $logistics, new LightWizardRepository($pdo), new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

// --- Fenêtre : la veille seulement quand l'automate envoie la veille
$w = $svc->window($today);
$check($w['days'] === ['2026-11-24', '2026-11-25'] && $w['eve'] === true && $w['rule'] === ['enabled' => true, 'days_before' => 1], 'window: rule active, 1 day before → today + next working day');
$check($svc->window('2026-11-27')['days'] === ['2026-11-27'] && $svc->window('2026-11-27')['next'] === '2026-11-30', 'Friday, days_before 1: Monday notices not yet due → Friday only');
$pdo->exec("UPDATE n_mail_rule SET days_before = 3 WHERE code = 'DELIVERY_PLANNED'");
$check($make()->window('2026-11-27')['days'] === ['2026-11-27', '2026-11-30'], 'Friday, days_before 3 → Friday + Monday');
$pdo->exec("UPDATE n_mail_rule SET days_before = 1, enabled = 0 WHERE code = 'DELIVERY_PLANNED'");
$wd = $make()->window($today);
$check($wd['days'] === [$today] && str_contains($wd['reason'], 'automat powiadomień wyłączony'), 'rule disabled → today only (shipment rule with 5 days ignored)');
$pdo->exec("UPDATE n_mail_rule SET enabled = 1 WHERE code = 'DELIVERY_PLANNED'");
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'system.auto_send'");
$check($make()->window($today)['days'] === [$today] && str_contains($make()->window($today)['reason'], 'system.auto_send = 0'), 'auto_send = 0 → today only');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'system.auto_send'");
$pdo->exec('RENAME TABLE n_mail_rule TO n_mail_rule_x');
$check($make()->window($today)['days'] === [$today] && $make()->window($today)['rule'] === null, 'no rule table → today only');
$pdo->exec('RENAME TABLE n_mail_rule_x TO n_mail_rule');
$pdo->exec('RENAME TABLE n_delivery_notice TO n_delivery_notice_x');
$x = $make()->data($today);
$check($x['available'] === false && $x['sources']['notices']['note'] === 'brak danych — brak tabeli powiadomień w bazie' && $x['totals']['notified'] === 0, 'no notice table → « brak danych », no crash');
$pdo->exec('RENAME TABLE n_delivery_notice_x TO n_delivery_notice');

// --- Étape 1 (checksums pris après les réglages de fenêtre modifiés par le test lui-même)
$erp0 = $sum();
$d = $svc->data($today);
$boot = $d;
$days = array_column($d['days'], null, 'date');
$check(array_keys($days) === ['2026-11-24', '2026-11-25'] && $days['2026-11-24']['customers'] === 7 && $days['2026-11-25']['customers'] === 2, 'customers of LogisticsService::day for both days ; 26.11 ignored');
$notifiedBar = (new ShippingDayRepository($pdo))->notifiedCustomers('2026-11-24');
sort($notifiedBar);
$check($notifiedBar === [1, 5, 7] && $days['2026-11-24']['notified'] === 3 && $days['2026-11-25']['notified'] === 1, 'notified as the Dzień wysyłki bar (3 / 7 and 1 / 2)');
$by = array_column($d['customers'], null, 'key');
$codes = fn(string $k) => array_column($by[$k]['signals'], 'code');
$check($codes('2026-11-24|1') === [] && $by['2026-11-24|1']['ok'] && $by['2026-11-24|1']['tour'] === 'Bruksela' && $by['2026-11-24|1']['vehicle'] === 'WX 1234A'
    && $by['2026-11-24|1']['departure'] === '18:30' && $by['2026-11-24|1']['stop'] === 1, 'notified customer: no signal ; tour, vehicle, departure of the tour');
$check($codes('2026-11-24|2') === ['sms_failed'] && $by['2026-11-24|2']['signals'][0]['detail'] === 'SMS do 0476123456 (6:00, automat) — Nieprawidłowy numer' && $by['2026-11-24|2']['resend_notice'] === null,
    'SMS failed (error shown) ; failure of another day ignored');
$check($codes('2026-11-24|3') === ['no_contact'] && $by['2026-11-24|3']['email'] === '' && $by['2026-11-24|3']['phone'] === '', 'no valid e-mail nor phone → no_contact');
$check($codes('2026-11-24|4') === ['not_sent'] && $by['2026-11-24|4']['email'] === 'handel@example.test', 'no notice, contact (salesman e-mail) → not_sent');
$check($codes('2026-11-24|5') === ['mail_failed'] && $by['2026-11-24|5']['notified_bar'] === true && $by['2026-11-24|5']['notices'][0]['status_label'] === 'odrzucony przez kolejkę e-mail',
    'e-mail rejected by the queue (communication_message failed) → mail_failed, although the bar counts it');
$check($codes('2026-11-24|6') === ['not_sent'] && str_contains($by['2026-11-24|6']['signals'][0]['detail'], 'tylko SMS zapisany') && $by['2026-11-24|6']['phone'] === '+48600300400', 'SMS only logged → not_sent ; phone_alt 0048 → +48');
$check($codes('2026-11-24|7') === ['sms_failed'] && $by['2026-11-24|7']['resend_notice']['id'] === 7, 'SMS failed then sent again → still flagged, successful notice after the failure found');
$check($codes('2026-11-25|1') === [] && $codes('2026-11-25|8') === ['not_sent'], 'next working day: notified (eve) and not yet notified');
$check(array_column($d['review'], 'key') === ['2026-11-24|5', '2026-11-24|7', '2026-11-24|2', '2026-11-24|3', '2026-11-24|6', '2026-11-24|4', '2026-11-25|8'], 'review sorted by signal priority, day, name — ' . implode(',', array_column($d['review'], 'key')));
$t = $d['totals'];
$check($t['customers'] === 9 && $t['review'] === 7 && $t['waiting'] === 7 && $t['by_code'] === ['mail_failed' => 1, 'sms_failed' => 2, 'no_contact' => 1, 'not_sent' => 3] && $d['can_finish'] === false
    && $by['2026-11-24|4']['link']['href'] === '/logistics/notices?date=2026-11-24', 'totals ; link to Powiadomienia klientów of the day');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Krok 2 : decyzje
$dec = fn(string $day, int $c, string $decision, string $note = '') => $svc->saveDecision(['ship_date' => $day, 'id_customer' => $c, 'decision' => $decision, 'note' => $note], 2, $today);
$check(str_starts_with($err(fn() => $dec('2026-11-24', 2, 'sms')), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $dec('2026-11-24', 2, 'resent')), 'custom:422:Wysłane ponownie : nie ma jeszcze udanego powiadomienia po ostatnim błędzie'), 'resent refused without a successful notice after the failure');
$check(str_starts_with($err(fn() => $dec('2026-11-24', 4, 'resent')), 'custom:422:Wysłane ponownie : nie ma jeszcze udanego powiadomienia dla tego klienta'), 'resent refused without any notice');
$check(str_starts_with($err(fn() => $dec('2026-11-24', 3, 'phone', ' ')), 'custom:422:Kontakt telefoniczny : wpisz'), 'phone needs a note');
$check(str_starts_with($err(fn() => $dec('2026-11-24', 6, 'justified', 'x')), 'custom:422:Bez powiadomienia — uzasadnione : wpisz'), 'justified needs a note');
$check(str_starts_with($err(fn() => $dec('2026-11-24', 1, 'phone', 'Telefon')), 'custom:422:Tego klienta nie ma na liście'), 'notified customer without signal refused');
$check(str_starts_with($err(fn() => $dec('2026-11-26', 9, 'phone', 'Telefon')), 'custom:422:Tego klienta nie ma na liście'), 'day outside the window refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 7 klientów'), 'finish blocked while customers wait');
$check($erp0 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_delivery_notice_check')->fetchColumn() === 0, 'refused decisions write nothing');
$d = $dec('2026-11-24', 7, 'resent');
$row = $pdo->query("SELECT * FROM n_delivery_notice_check WHERE ship_date = '2026-11-24' AND id_customer = 7")->fetch();
$check($row['decision'] === 'resent' && (int) $row['id_notice'] === 7 && $row['flags'] === 'sms_failed' && $row['checked_on'] === $today && (int) $row['id_user'] === 2, 'resent accepted: successful notice id, signals, day of the control');
$check(array_column($d['review'], null, 'key')['2026-11-24|7']['decision']['by'] === 'Ewa L.' && $d['totals']['waiting'] === 6, 'decision shown with its author');
$st = $tasks->state(DeliveryNoticesCheckService::CODE, '2026-11-24 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 klient wyjaśniony', 'task state: in progress (decisions saved today)');
// Le renvoi se fait sur l'écran existant (ici simulé par une ligne d'historique) : l'assistant ne fait que le constater.
$pdo->exec("INSERT INTO n_delivery_notice (id, ship_date, id_customer, channel, language, recipient, body, status, provider, created_by, created_at) VALUES
    (20, '2026-11-24', 2, 'sms', 'fr', '+32476123456', 'x', 'sent', 'smsapi', 'Ewa L.', '2026-11-24 08:40:00')");
$d = $dec('2026-11-24', 2, 'resent');
$check(array_column($d['review'], null, 'key')['2026-11-24|2']['decision']['id_notice'] === 20, 'after a successful resend (existing screen) → resent accepted');
$dec('2026-11-24', 3, 'phone', 'Telefon do właściciela 8:30 — dostawa jutro 7:00');
$dec('2026-11-24', 4, 'justified', 'Klient odbiera sam, nie chce powiadomień');
$dec('2026-11-24', 5, 'phone', 'Zadzwoniono, adres e-mail do poprawy w karcie');
$dec('2026-11-24', 6, 'phone', 'SMS nie wyszedł (brak dostawcy) — telefon 9:10');
$d = $dec('2026-11-25', 8, 'justified', 'Dostawa przesunięta, klient wie');
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && [$d['totals']['resent'], $d['totals']['phone'], $d['totals']['justified']] === [2, 3, 2], 'all decided → step 3 open');
$after = $d;
// Nouvel échec après « Wysłane ponownie » : la décision devient caduque, le client attend de nouveau.
$pdo->exec("INSERT INTO n_delivery_notice (id, ship_date, id_customer, channel, language, recipient, body, status, provider, error, created_by, created_at) VALUES
    (21, '2026-11-24', 7, 'sms', 'fr', '+32470112233', 'x', 'failed', 'smsapi', 'Timeout', 'Ewa L.', '2026-11-24 09:00:00')");
$s7 = array_column($svc->data($today)['review'], null, 'key')['2026-11-24|7'];
$check($s7['waiting'] === true && $s7['decision']['stale'] === true && $s7['resend_notice'] === null && str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 1 klienta'),
    'new failure after resent → decision stale, finish blocked again');
$dec('2026-11-24', 7, 'phone', 'Telefon do kierownika sali');
$erp2 = $sum();
$r = $svc->finish(2, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'delivery_notices_checked'")->fetchAll();
$check($r['summary'] === '24.11 : 7 klientów, 4 powiadomionych · 25.11 : 2 klientów, 1 powiadomiony · 1 błąd e-mail · 2 błędy SMS · 1 bez kontaktu · 3 bez powiadomienia · 7 do wyjaśnienia : 1 wysłane ponownie · 4 telefony · 2 uzasadnione'
    && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null, 'finish: summary, n_wizard_done — ' . $r['summary']);
$st = $tasks->state(DeliveryNoticesCheckService::CODE, '2026-11-24 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done today');
$check($tasks->state(DeliveryNoticesCheckService::CODE, '2026-11-25 00:00:00', '2026-11-26 00:00:00', '2026-11-25')['done'] === false, 'next day: task open again');
$check($erp2 === $sum(), 'finish writes nothing in the source tables');
$n = $make()->data('2026-11-25');
$check(array_column($n['days'], 'date') === ['2026-11-25', '2026-11-26'] && array_column($n['review'], null, 'key')['2026-11-25|8']['waiting'] === false
    && array_column($n['review'], null, 'key')['2026-11-26|9']['signals'][0]['code'] === 'not_sent', 'next day: decision taken on the eve kept ; new next working day checked');

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test) ; passerelle SMS factice, file e-mail et SMTP espionnées.
$spy = new class { public array $calls = []; };
$fakeSms = new class($spy) implements SmsTransport {
    public function __construct(private object $spy) {}
    public function name(): string { return 'fake'; }
    public function delivers(): bool { return false; }
    public function send(string $to, string $text, array $context = []): array { $this->spy->calls[] = 'sms:' . $to; return ['status' => 'failed', 'provider_id' => null, 'error' => 'test']; }
};
$b = new DI\ContainerBuilder();
$b->addDefinitions([
    PDO::class => fn() => new PDO($dsn . ';dbname=erp_delivery_notices_check_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]),
    SmsTransport::class => $fakeSms,
    OutgoingMessageService::class => function () use ($spy) { $spy->calls[] = 'mail-queue built'; throw new RuntimeException('no mail in this test'); },
    SmtpTransport::class => function () use ($spy) { $spy->calls[] = 'smtp built'; throw new RuntimeException('no smtp in this test'); },
]);
$ctl = $b->build()->get(DeliveryNoticesCheckController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 1), (3, 2), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'logistics'), (2, 'tablet'), (3, 'production'), (3, 'tablet')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
$erp3 = $sum();
$notices3 = (int) $pdo->query('SELECT COUNT(*) FROM n_delivery_notice')->fetchColumn();
[$c2, $b2] = $call(2, 'data');
$check($c2 === 200 && $b2['data']['today'] === date('Y-m-d') && isset($b2['data']['totals']['customers']) && $b2['data']['notices_link'] === '/logistics/notices',
    'controller data() (Logistyka, right « logistics ») : 200, real day');
[$c1] = $call(1, 'data');
[$c3] = $call(3, 'data');
$check($c1 === 200 && $c3 === 403, 'controller data(): administrator 200 ; Kierowca without « logistics » 403');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do logistyki (powiadomienia o dostawie).' && $cD === 403 && $cF === 403, 'controller: Piekarz without « logistics » → 403 for data(), decision() and finish()');
[$cDe, $bDe] = $call(2, 'decision');
$check($cDe === 422 && str_starts_with((string) $bDe['message'], 'Wybierz decyzję'), 'controller decision() (Logistyka) really called : empty body → 422');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() (Logistyka) really called : done or blocked by waiting customers');
$check($spy->calls === [], 'no send: SMS gateway never called, mail queue and SMTP never built');
$check($erp3 === $sum() && $notices3 === (int) $pdo->query('SELECT COUNT(*) FROM n_delivery_notice')->fetchColumn(), 'controller calls write nothing in the source tables (no notice, message nor SMS log)');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/DeliveryNoticesCheckService.php', 'App/Repositories/Logistics/DeliveryNoticesCheckRepository.php',
    'App/Controllers/Web/Logistics/DeliveryNoticesCheckController.php', 'public/assets/js/logistics/delivery-notices-check.js']));
$check(!preg_match('/->send\(|->queue\(|SmsTransport|OutgoingMessageService|SmtpTransport|MailAutomation|notices\/send|INSERT\s+INTO\s+(n_delivery_notice\b(?!_)|customer|communication|n_sms_log|n_mail)|UPDATE\s+(n_delivery_notice\b(?!_)|customer|communication|n_mail)|DELETE\s+FROM|curl_|https?:\/\//i', $src),
    'no send call, no mail/SMS service, no write to notices / customers / messages, no hard-coded URL');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('delivery_notices_checked')['link'] === '/logistics/notices-check' && TaskWizards::byLink('/logistics/notices-check') === 'delivery_notices_checked'
    && isset(TaskWizards::AUTO['delivery_notices_checked']) && PositionTaskService::LINK_AUTO['/logistics/notices-check'] === 'delivery_notices_checked', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['delivery_notices_checked'][0] === 'Powiadomienia o dostawie (dzień)' && TaskWizards::CATALOG['delivery_notices_checked'][0] !== 'Powiadomienia o dostawie', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/logistics/notices-check') === 'logistics' && PageAccess::keyFor('/ajax/logistics/notices-check') === 'logistics' && PageAccess::keyFor('/ajax/logistics/notices-check/finish') === 'logistics'
    && PageAccess::keyFor('/logistics/notices') === 'logistics' && PageAccess::keyFor('/logistics/stock') === 'stock_pallets' && PageAccess::keyFor('/ajax/logistics/pallets-returns') === 'logistics',
    'page and ajax under the existing « logistics » right (same as /logistics/notices) ; others unchanged');
$check(count(PageAccess::PAGES) === count(array_unique(array_keys(PageAccess::PAGES))) && !isset(PageAccess::PAGES['delivery_notices']) && !isset(PageAccess::PAGES['notices_check']), 'no new right');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'delivery_notices_checked' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/logistics/notices-check'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Logistics/DeliveryNoticesCheckRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/logistics/notices-check') === 'index' && $found('GET', '/ajax/logistics/notices-check') === 'data'
    && $found('POST', '/ajax/logistics/notices-check/decision') === 'decision' && $found('POST', '/ajax/logistics/notices-check/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/logistics/notices-check')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/logistics/notices-check')[1]['controller'] === DeliveryNoticesCheckController::class
    && $allR->dispatch('GET', '/logistics/notices')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('POST', '/ajax/logistics/notices/send')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing routes unchanged');

if (getenv('DN_JSON_OUT')) file_put_contents((string) getenv('DN_JSON_OUT'), json_encode(['before' => $boot, 'after' => $after], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_delivery_notices_check_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
