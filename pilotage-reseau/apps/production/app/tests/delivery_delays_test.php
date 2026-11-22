<?php
/**
 * [374] Asystent « Opóźnienia dostaw » : liste (wysyłki dziś et jusqu'au jour ouvré suivant, après la date sans WDT,
 * « W transporcie » sans livraison confirmée ; sans gratis, archivées, livrées ni commandes hors fenêtre), préparation
 * (palettes, liste de colisage), transport (LogisticsService::day : véhicule, chauffeur du véhicule / de la fiche / de la
 * tournée, départ, surcharge), tablette chauffeur (problème, livré) ; signaux (date passée, départ passé, rien de prêt,
 * sans véhicule, surcharge, sans chauffeur, transport trop long, problème à la livraison, date changée plusieurs fois) et
 * leurs réglages ; décision par livraison signalée (note et date obligatoires selon le cas, la commande garde sa date),
 * étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche, décision précédente, brouillon SMS (passerelle factice,
 * rien n'est envoyé), enregistrement (catalogue, droits, routes, paramètres), migration 221 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_delivery_delays_test recréée (tables minimales + migration 221), supprimée à la fin (DD_JSON_OUT=fichier :
 * données de la page avant et après les décisions, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderSmsRepository;
use App\Repositories\Logistics\{DeliveryDelaysRepository, LogisticsRepository};
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Client\Order\OrderSmsService;
use App\Services\Logistics\LogisticsService;
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Organization\{PositionTaskService, TaskWizards, TeamTasksService};
use App\Services\Organization\Wizards\DeliveryDelaysService;
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_delivery_delays_test'); $pdo->exec('CREATE DATABASE erp_delivery_delays_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_delivery_delays_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    "CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), name VARCHAR(40) NULL, surname VARCHAR(40) NULL, fac_town VARCHAR(60) NULL, fac_country VARCHAR(40) NULL,
        active TINYINT DEFAULT 1, phone_head VARCHAR(40) NULL, phone_alt VARCHAR(40) NULL, communication_lang VARCHAR(5) NULL)",
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1, cpp INT NULL, cpl INT NULL, lpp INT NULL, ppc INT NULL, weight DECIMAL(10,2) NULL, id_category INT NULL)',
    'CREATE TABLE final_product_ppwr_sales_packaging (id INT AUTO_INCREMENT PRIMARY KEY, id_final_product INT, id_outer_raw_material INT NULL)',
    'CREATE TABLE carton_dimension (id INT PRIMARY KEY, length INT, width INT, height INT)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    "CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, order_description TEXT NULL, status INT DEFAULT 1, expected_shipping_date DATETIME NULL,
        create_order_date DATETIME, update_status_date DATETIME NULL, is_freebie TINYINT DEFAULT 0, order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD', id_wdt INT NULL, id_proforma INT NULL)",
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))',
    "CREATE TABLE customer_order_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT NOT NULL, id_user INT NULL, action_type ENUM('create','update','delete') NOT NULL,
        source ENUM('web','api','collector','system') NOT NULL DEFAULT 'web', changed_fields JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40), invoiced_date DATE NULL)',
    'CREATE TABLE shipment_log (id INT AUTO_INCREMENT PRIMARY KEY, shipment_date DATE, id_customer INT, id_wz INT NULL, id_cmr INT NULL, id_wdt INT NULL, id_diff INT NULL, id_employee INT NULL, insert_timestamp DATETIME NULL)',
    'CREATE TABLE palets_delivery (id_palet INT AUTO_INCREMENT PRIMARY KEY, id_customer INT, delivery_date DATE, status INT, last_update_date DATETIME NULL)',
    "CREATE TABLE picking_list_generation (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source ENUM('api','web') NOT NULL DEFAULT 'web', selected_order_ids_json JSON NOT NULL, generated_at DATETIME NOT NULL)",
    "CREATE TABLE n_delivery_stop (id INT AUTO_INCREMENT PRIMARY KEY, ship_date DATE NOT NULL, id_customer INT NOT NULL, id_carrier_vehicle INT NULL, status ENUM('arrived','delivered','problem') NOT NULL,
        reason ENUM('closed','refused','damaged','missing','late','other') NULL, note VARCHAR(500) NULL, recorded_at DATETIME NOT NULL, UNIQUE KEY (ship_date, id_customer))",
    'CREATE TABLE n_delivery_run (id INT AUTO_INCREMENT PRIMARY KEY, ship_date DATE NOT NULL, id_carrier_vehicle INT NOT NULL, driver_name VARCHAR(100) NULL, started_at DATETIME NULL, finished_at DATETIME NULL)',
    'CREATE TABLE carrier (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1)',
    "CREATE TABLE carrier_vehicle (id INT PRIMARY KEY, id_carrier INT, regis_num VARCHAR(20), driver_name VARCHAR(40) DEFAULT '', driver_surname VARCHAR(40) DEFAULT '', driver_phone VARCHAR(30) DEFAULT '', driver_email VARCHAR(80) DEFAULT '')",
    'CREATE TABLE n_vehicle (id_carrier_vehicle INT PRIMARY KEY, pallet_places INT NULL, max_load_kg INT NULL, departure_time TIME NULL, trips_per_week INT NULL, active TINYINT DEFAULT 1, notes VARCHAR(255) NULL, id_driver INT NULL, updated_by INT NULL)',
    'CREATE TABLE n_tour (id INT PRIMARY KEY, name VARCHAR(80), weekdays INT, departure_time TIME NULL, id_driver INT NULL, color VARCHAR(10) NULL, active TINYINT DEFAULT 1, notes VARCHAR(255) NULL)',
    'CREATE TABLE n_tour_vehicle (id_tour INT, id_carrier_vehicle INT, position INT)',
    'CREATE TABLE n_tour_customer (id_tour INT, id_customer INT, stop_order INT NULL, PRIMARY KEY (id_tour, id_customer))',
    'CREATE TABLE n_shipping_assignment (ship_date DATE, id_customer INT, id_tour INT NULL, tour_set TINYINT DEFAULT 0, id_carrier_vehicle INT NULL, vehicle_set TINYINT DEFAULT 0,
        stop_order INT NULL, plan_set TINYINT DEFAULT 0, updated_by INT NULL, PRIMARY KEY (ship_date, id_customer))',
    'CREATE TABLE document_cmr (id INT PRIMARY KEY, id_customer INT, id_carrier_vehicle INT NULL)',
    'CREATE TABLE n_mixed_pallet (id INT PRIMARY KEY, ship_date DATE, id_customer INT, pallet VARCHAR(20), height_mm INT NULL, kg DECIMAL(8,2) NULL, confirmed_at DATETIME, confirmed_by INT NULL)',
    'CREATE TABLE n_mixed_pallet_item (id INT AUTO_INCREMENT PRIMARY KEY, id_mixed_pallet INT, position INT, id_final_product INT, cartons INT, layers INT, per_layer INT)',
    'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(40), surname VARCHAR(40))',
    'CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT, active TINYINT DEFAULT 1, id_position INT NULL)',
    'CREATE TABLE erp_company_data (id INT PRIMARY KEY, proforma_company_name VARCHAR(120))',
    "CREATE TABLE n_sms_log (id INT AUTO_INCREMENT PRIMARY KEY, id_customer INT NULL, context VARCHAR(30) NOT NULL, ref_id INT NULL, recipient VARCHAR(40) NOT NULL, body VARCHAR(1000) NOT NULL,
        status VARCHAR(16) NOT NULL, provider VARCHAR(40) NULL, provider_id VARCHAR(64) NULL, error VARCHAR(255) NULL, created_by VARCHAR(160) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40))',
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours'),
    ('planning.default_departure_time', '20:00', '20:00', 'time', 'wyjazd', 'départ'), ('planning.loading_time_min', '60', '60', 'int', 'załadunek', 'chargement')");
$pdo->exec("INSERT INTO n_position (id, code) VALUES (1, 'KIEROWNIK_OPERACYJNY')");
$pdo->exec("INSERT INTO n_position_duty (number, wizard_code, proposed_wizard, rhythm) VALUES ('3.7', NULL, 'Opóźnienia dostaw', 'daily'), ('1.7', 'supplements_day_checked', NULL, 'daily'),
    ('3.6', NULL, 'Inny asystent', 'daily')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/221_delivery_delays.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 221 (deux passages)
$duties = array_column($pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty')->fetchAll(), null, 'number');
$check($duties['3.7']['wizard_code'] === 'deliveries_delays_checked' && $duties['3.7']['proposed_wizard'] === null && $duties['3.7']['rhythm'] === 'daily',
    'migration 221: duty 3.7 → deliveries_delays_checked (daily)');
$check($duties['1.7']['wizard_code'] === 'supplements_day_checked' && $duties['3.6']['proposed_wizard'] === 'Inny asystent', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value, value_type FROM n_prod_param WHERE code LIKE 'deliveries.%' ORDER BY code")->fetchAll(), null, 'code');
$check(count($params) === 4 && $params['deliveries.delay_lookback_days']['value'] === '14' && $params['deliveries.transit_max_days']['value'] === '2'
    && $params['deliveries.date_changes_max']['value'] === '2' && $params['deliveries.departure_grace_min']['value'] === '30'
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_delivery_delay_check'")->fetchColumn() === 1,
    'migration replayed: 4 params once, n_delivery_delay_check created once');
foreach (array_keys($params) as $code) $check(isset(ParamRules::RANGES[$code]), "ParamRules bounds for {$code}");

// --- Données : aujourd'hui mar. 24.11.2026 (10:00), jour ouvré suivant mer. 25.11 ; fenêtre depuis le 10.11 (14 jours).
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, fac_town, phone_head, communication_lang) VALUES (1, 'Sklep A', 'Bruxelles', '0476 12 34 56', 'fr'), (2, 'Sklep B', 'Liège', '+48 600 300 400', 'pl'),
    (3, 'Sklep C', 'Gent', NULL, 'nl'), (4, 'Sklep D', 'Namur', NULL, 'fr'), (5, 'Sklep E', 'Mons', NULL, 'fr')");
$pdo->exec("INSERT INTO final_product (id, name, cpp, cpl, lpp, ppc, weight) VALUES (10, 'Croissant', 40, 8, 5, 60, 70), (11, 'Bagietka', 50, 10, 5, 20, 250)");
$pdo->exec("INSERT INTO carrier VALUES (1, 'Transport własny', 1)");
$pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname) VALUES (1, 1, 'WX 1234A', 'Jan', 'Kowalski'), (2, 1, 'WX 5678B', '', ''), (3, 1, 'BUS 01', '', '')");
$pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, departure_time, active, id_driver) VALUES (1, 33, '20:00', 1, NULL), (2, 33, '18:00', 1, NULL), (3, 4, '21:00', 1, 7)");
$pdo->exec("INSERT INTO production_employee VALUES (70, 'Piotr', 'Nowak'), (80, 'Ewa', 'Lis')");
$pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee) VALUES (7, 70), (8, 80)");
$pdo->exec("INSERT INTO n_tour (id, name, weekdays, departure_time, id_driver, active) VALUES (1, 'Bruksela', 127, NULL, NULL, 1), (2, 'Liège', 127, NULL, NULL, 1)");
$pdo->exec("INSERT INTO n_tour_vehicle VALUES (1, 1, 1), (2, 2, 1)");
$pdo->exec("INSERT INTO n_tour_customer VALUES (1, 1, 1), (1, 2, 2), (2, 3, 1)");
$pdo->exec("INSERT INTO n_shipping_assignment (ship_date, id_customer, id_carrier_vehicle, vehicle_set) VALUES ('2026-11-24', 4, 3, 1)");
$pdo->exec("INSERT INTO erp_company_data VALUES (1, 'Atelier Test')");
$order = function (int $id, int $cust, string $ship, array $lines, array $o = []) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, reference, status, expected_shipping_date, create_order_date, update_status_date, is_freebie, order_type, id_wdt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $cust, $o['ref'] ?? null, $o['status'] ?? 1, $ship . ' 00:00:00', $o['created'] ?? '2026-11-16 09:00:00', $o['since'] ?? null, $o['freebie'] ?? 0, $o['type'] ?? 'STANDARD', $o['wdt'] ?? null]);
    foreach ($lines as $p => $q) $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (?, ?, ?)')->execute([$id, $p, $q]);
};
$order(301, 1, '2026-11-24', [10 => 40], ['ref' => 'PO-301']);        // prête (1 palette), liste de colisage, véhicule avec chauffeur : rien
$order(302, 2, '2026-11-24', [10 => 30]);                             // ni liste de colisage ni palette → rien de prêt
$order(303, 3, '2026-11-24', [11 => 20]);                             // véhicule WX 5678B sans chauffeur ; liste de colisage, chargement 17:00
$order(304, 4, '2026-11-24', [10 => 250], ['type' => 'SUPPLEMENT']);  // BUS 01 (4 places) : 7 palettes → surcharge ; chauffeur de la fiche véhicule
$order(305, 5, '2026-11-25', [10 => 20]);                             // demain, client sans tournée ni CMR → sans véhicule
$order(306, 1, '2026-11-25', [10 => 10], ['ref' => 'PO-306']);        // date d'expédition changée deux fois
$order(307, 2, '2026-11-20', [10 => 12]);                             // date passée (ven. 20.11), sans WDT
$order(308, 3, '2026-11-19', [11 => 8], ['status' => 3, 'since' => '2026-11-19 20:15:00', 'wdt' => 81]); // en transport depuis 5 jours
$order(309, 1, '2026-11-23', [10 => 8], ['status' => 3, 'since' => '2026-11-23 20:00:00', 'wdt' => 83]); // en transport depuis hier : listée, sans signal
$order(310, 4, '2026-11-20', [10 => 8], ['status' => 3, 'since' => '2026-11-20 21:00:00', 'wdt' => 84]); // problème à la livraison (tablette)
$order(311, 5, '2026-11-21', [10 => 5], ['status' => 2, 'wdt' => 85]);  // livrée : absente
$order(312, 2, '2026-11-18', [10 => 5], ['wdt' => 82]);                 // « Oczekujące » avec WDT, livraison confirmée : absente
$order(313, 1, '2026-11-24', [10 => 2], ['freebie' => 1]);              // gratis : absente
$order(314, 1, '2026-11-24', [10 => 2], ['status' => 5]);               // archivée : absente
$order(315, 3, '2026-11-01', [10 => 5]);                                // hors fenêtre (14 jours) : absente
$order(316, 1, '2026-11-24', [10 => 40], ['status' => 3, 'since' => '2026-11-24 07:30:00', 'wdt' => 86]); // partie ce matin : listée, sans signal
$pdo->exec("INSERT INTO document_wdt VALUES (81, 'WDT/81/2026', '2026-11-19'), (82, 'WDT/82/2026', '2026-11-18'), (83, 'WDT/83/2026', '2026-11-23'), (84, 'WDT/84/2026', '2026-11-20'),
    (85, 'WDT/85/2026', '2026-11-21'), (86, 'WDT/86/2026', '2026-11-24')");
$pdo->exec("INSERT INTO shipment_log (shipment_date, id_customer, id_wdt) VALUES ('2026-11-19', 3, 81), ('2026-11-18', 2, 82), ('2026-11-23', 1, 83), ('2026-11-20', 4, 84), ('2026-11-21', 5, 85), ('2026-11-24', 1, 86)");
$pdo->exec("INSERT INTO n_delivery_stop (ship_date, id_customer, status, reason, note, recorded_at) VALUES ('2026-11-20', 4, 'problem', 'closed', 'Sklep zamknięty, nikt nie odebrał', '2026-11-21 06:40:00'),
    ('2026-11-18', 2, 'delivered', NULL, NULL, '2026-11-19 07:10:00'), ('2026-11-21', 5, 'delivered', NULL, NULL, '2026-11-22 08:00:00')");
$pdo->exec("INSERT INTO palets_delivery (id_customer, delivery_date, status) VALUES (1, '2026-11-24', 2), (4, '2026-11-24', 2), (4, '2026-11-24', 2), (4, '2026-11-24', 1)");
$pdo->exec("INSERT INTO picking_list_generation (selected_order_ids_json, generated_at) VALUES ('[301, 303]', '2026-11-23 14:00:00'), ('[\"304\"]', '2026-11-23 15:00:00'), ('[999]', '2026-11-23 16:00:00')");
$pdo->exec("INSERT INTO n_delivery_run (ship_date, id_carrier_vehicle, driver_name, started_at) VALUES ('2026-11-24', 1, 'Jan Kowalski', '2026-11-24 07:30:00')");
$ch = fn(int $id, string $json, string $at, string $src = 'web') => $pdo->prepare('INSERT INTO customer_order_changelog (id_order, id_user, action_type, source, changed_fields, created_at) VALUES (?, 1, ?, ?, ?, ?)')
    ->execute([$id, 'update', $src, $json, $at]);
$ch(306, '{"before":{"expected_shipping_date":"2026-11-23 00:00:00"},"after":{"expected_shipping_date":"2026-11-24"}}', '2026-11-19 10:00:00');
$ch(306, '{"before":{"expected_shipping_date":"2026-11-24"},"after":{"expected_shipping_date":"2026-11-24","order_description":"x"}}', '2026-11-20 10:00:00'); // même date : pas un changement
$ch(306, '{"before":{"expected_shipping_date":"2026-11-24"},"after":{"expected_shipping_date":"2026-11-25"}}', '2026-11-23 11:00:00');
$ch(306, '{"after":{"status":3},"reason":"x"}', '2026-11-23 12:00:00', 'system');                                                          // statut : pas un changement de date
$ch(307, '{"before":{"expected_shipping_date":"2026-11-19"},"after":{"expected_shipping_date":"2026-11-20"}}', '2026-11-18 10:00:00');   // une seule fois : pas de signal

$logistics = new LogisticsService(new LogisticsRepository($pdo), new ParamRepository($pdo), null);
$make = fn() => new DeliveryDelaysService(new DeliveryDelaysRepository($pdo), $logistics, new LightWizardRepository($pdo), new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';
$now = '2026-11-24 10:00:00';
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id) { throw new RuntimeException('not needed: ' . $id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);

// --- Fonctions pures
$check(DeliveryDelaysService::workday('2026-11-27', 1, [1, 2, 3, 4, 5]) === '2026-11-30', 'next working day: Fri → Mon');
$dc = DeliveryDelaysService::dateChanges([['changed_fields' => '{"before":{"expected_shipping_date":null},"after":{"expected_shipping_date":"2026-11-26"}}', 'created_at' => '2026-11-20 08:00:00'],
    ['changed_fields' => 'not json', 'created_at' => '2026-11-20 09:00:00']]);
$check($dc['count'] === 1 && $dc['last'] === '— → czw. 26.11 (pt. 20.11)', 'date changes: empty → date counts, invalid JSON ignored');
$ctx = ['today' => $today, 'now' => '2026-11-24 19:00:00', 'next' => '2026-11-25', 'transit_max_days' => 2, 'date_changes_max' => 2, 'departure_grace_min' => 30,
    'default_departure' => '20:00', 'loading_min' => 60];
$base = ['open' => true, 'shipping_date' => $today, 'transport' => ['vehicle' => ['label' => 'V', 'driver' => 'X', 'over' => false, 'pallets' => 1, 'places' => 10, 'max_kg' => null,
    'weight_kg' => 0, 'run' => null], 'tour' => null, 'departure' => '20:00', 'loading_from' => '19:00'], 'prep' => ['ready' => 0, 'building' => 0, 'picking_at' => '2026-11-23 10:00:00'],
    'date_changes' => ['count' => 0, 'last' => null], 'in_transit' => false, 'transit_days' => null, 'stop' => null, 'status' => 1];
$codes = fn(array $o, array $c = []) => array_column(DeliveryDelaysService::flags($o, $c + $ctx), 'code');
$check($codes($base) === ['not_ready'] && str_contains(DeliveryDelaysService::flags($base, $ctx)[0]['reason'], 'załadunek od 19:00'), 'loading started, nothing ready → not_ready');
$check($codes($base, ['now' => '2026-11-24 18:59:00']) === [] && $codes(['prep' => ['ready' => 1, 'building' => 0, 'picking_at' => null]] + $base) === [],
    'before loading with a picking list, or one pallet ready: no signal');
$check($codes($base, ['now' => '2026-11-24 20:29:00']) === ['not_ready'] && $codes($base, ['now' => '2026-11-24 20:30:00']) === ['departure_passed'],
    'departure + 30 min grace: departure_passed replaces not_ready');
$check($codes(['open' => false] + $base, ['now' => '2026-11-24 23:00:00']) === [], 'shipped (WDT): no departure signal');

// --- Étape 1 : vue d'ensemble
$d = $svc->data($today, $now);
$boot = $d; // aperçu (DD_JSON_OUT) : état avant les décisions
$by = array_column($d['orders'], null, 'id');
$flags = fn(int $id) => array_column($by[$id]['flags'] ?? [], 'code');
$check($d['next_workday'] === '2026-11-25' && $d['from'] === '2026-11-10' && $d['now'] === '10:00', 'window: next working day Wed 25.11, from 10.11');
$check(array_column($d['orders'], 'id') === [307, 308, 310, 309, 301, 316, 302, 303, 304, 306, 305], 'listed and sorted by section (overdue, transit, today, next), date, customer — ' . implode(',', array_column($d['orders'], 'id')));
$check(!isset($by[311]) && !isset($by[312]) && !isset($by[313]) && !isset($by[314]) && !isset($by[315]), 'not listed: delivered (status or tablet), freebie, archived, outside the window');
$check($by[307]['section'] === 'overdue' && $by[307]['late_days'] === 4 && $by[308]['section'] === 'transit' && $by[308]['transit_days'] === 5 && $by[308]['transit_label'] === 'czw. 19.11, 20:15'
    && $by[316]['section'] === 'today' && $by[316]['open'] === false && $by[305]['section'] === 'next', 'sections, days late and in transit');
$check($by[301]['prep']['ready'] === 1 && $by[301]['prep']['picking_label'] === 'pon. 23.11, 14:00' && $by[304]['prep']['ready'] === 2 && $by[304]['prep']['building'] === 1
    && $by[304]['prep']['picking_at'] === '2026-11-23 15:00:00' && $by[302]['prep']['picking_at'] === null, 'preparation: pallets ready / in build, picking list (ids as numbers or strings)');
$check($by[301]['transport']['vehicle']['label'] === 'WX 1234A' && $by[301]['transport']['vehicle']['driver'] === 'Jan Kowalski' && $by[301]['transport']['tour'] === 'Bruksela'
    && $by[301]['transport']['departure'] === '20:00' && $by[301]['transport']['vehicle']['run']['started'] === '7:30', 'transport: tour vehicle, driver on the vehicle, departure, run started on the tablet');
$check($by[304]['transport']['vehicle']['label'] === 'BUS 01' && $by[304]['transport']['vehicle']['driver'] === 'Piotr Nowak' && $by[304]['transport']['vehicle']['pallets'] === 7,
    'transport: day assignment, driver from the vehicle card (employee), 7 pallets');
$check($by[305]['transport'] !== null && $by[305]['transport']['vehicle'] === null && $by[307]['transport'] === null, 'tomorrow: customer without vehicle ; overdue: no transport lookup');
$check($by[310]['stop']['status'] === 'problem' && $by[310]['stop']['reason'] === 'klient zamknięty' && $by[310]['wdt']['num'] === 'WDT/84/2026' && $by[310]['shipped_label'] === 'pt. 20.11',
    'driver tablet problem and WDT / departure');
$check($by[306]['date_changes']['count'] === 2 && $by[307]['date_changes']['count'] === 1, 'date changes counted from the changelog (same date and status entries ignored)');
$check($flags(302) === ['not_ready'] && str_contains($by[302]['flags'][0]['reason'], 'brak listy kompletacyjnej'), 'flag: no picking list and no pallet today');
$check($flags(303) === ['no_driver'] && str_contains($by[303]['flags'][0]['reason'], 'WX 5678B'), 'flag: vehicle without driver (picking list, loading at 17:00 not yet)');
$check($flags(304) === ['vehicle_over'] && str_contains($by[304]['flags'][0]['reason'], '7 palet na 4 miejsc'), 'flag: overloaded vehicle');
$check($flags(305) === ['no_vehicle'] && $flags(306) === ['date_changed'] && str_contains($by[306]['flags'][0]['reason'], 'zmieniana 2 razy — ostatnio wt. 24.11 → śr. 25.11 (pon. 23.11)'),
    'flags: no vehicle tomorrow ; shipping date changed twice');
$check($flags(307) === ['ship_overdue'] && str_contains($by[307]['flags'][0]['reason'], 'pt. 20.11 minęła (4 dni temu)'), 'flag: shipping date passed without WDT');
$check($flags(308) === ['in_transit_long'] && $flags(310) === ['in_transit_long', 'delivery_problem'] && str_contains($by[310]['flags'][1]['reason'], 'Sklep zamknięty'),
    'flags: too long in transit ; problem reported on the driver tablet');
$check($flags(301) === [] && $flags(309) === [] && $flags(316) === [], 'no flag: ready with driver, in transit since yesterday, shipped this morning');
$fleet = array_column(array_filter($d['fleet'], fn($v) => $v['date'] === $today), null, 'label');
$check(count($fleet) === 3 && $fleet['WX 1234A']['pallets'] === 3 && $fleet['WX 1234A']['customers'] === ['Sklep A', 'Sklep B'] && $fleet['BUS 01']['over'] === true
    && $fleet['WX 5678B']['driver'] === null && $fleet['WX 1234A']['run']['started'] === '7:30', 'fleet of the day: pallets, customers, overload, driver, run');
$t = $d['totals'];
$check($t['orders'] === 11 && $t['today'] === 5 && $t['today_customers'] === 4 && $t['today_shipped'] === 1 && $t['next'] === 2 && $t['overdue'] === 1 && $t['transit'] === 3
    && $t['vehicles'] === 3 && $t['flagged'] === 8 && $t['waiting'] === 8 && $d['can_finish'] === false, 'tiles: 11 deliveries, 5 today, 1 overdue, 3 in transit, 8 to clarify');

// Signaux dépendant de l'heure et des réglages
$e = array_column($svc->data($today, '2026-11-24 17:30:00')['orders'], null, 'id');
$check(array_column($e[303]['flags'], 'code') === ['not_ready', 'no_driver'] && str_contains($e[303]['flags'][0]['reason'], 'załadunek od 17:00'), '17:30: loading of WX 5678B started, nothing ready');
$e = array_column($svc->data($today, '2026-11-24 20:45:00')['orders'], null, 'id');
$check(array_column($e[301]['flags'], 'code') === ['departure_passed'] && str_contains($e[301]['flags'][0]['reason'], 'kierowca zgłosił wyjazd 7:30')
    && array_column($e[302]['flags'], 'code') === ['departure_passed'] && array_column($e[304]['flags'], 'code') === ['vehicle_over'] && $e[316]['flags'] === [],
    '20:45: departure 20:00 passed without WDT (BUS 01 leaves at 21:00 ; shipped order not flagged)');
$pdo->exec("UPDATE n_prod_param SET value = '6' WHERE code = 'deliveries.transit_max_days'");
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'deliveries.date_changes_max'");
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'deliveries.delay_lookback_days'");
$e = array_column($make()->data($today, $now)['orders'], null, 'id');
$check(!isset($e[307]) && !isset($e[308]) && !isset($e[310]) && $e[309]['flags'] === [] && $e[306]['flags'] === [], 'settings: 3 days back, 6 days in transit, 3 date changes');
$pdo->exec("UPDATE n_prod_param SET value = 'abc' WHERE code = 'deliveries.transit_max_days'");
$pdo->exec("UPDATE n_prod_param SET value = '500' WHERE code = 'deliveries.delay_lookback_days'");
$s = $make()->settings();
$check($s['transit_max_days'] === 2 && $s['lookback_days'] === 14, 'invalid or out-of-range setting falls back to the default');
$pdo->exec("UPDATE n_prod_param SET value = default_value WHERE code LIKE 'deliveries.%'");

// --- Étape 2 : décisions (lecture seule : commandes, transport et tablette inchangés)
$sum = fn() => json_encode($pdo->query('CHECKSUM TABLE customer_order, customer_order_product, n_shipping_assignment, n_vehicle, carrier_vehicle, n_tour, n_tour_customer, palets_delivery, n_delivery_stop')->fetchAll());
$before = $sum();
$check(str_contains($err(fn() => $svc->finish(1, $today, $now)), 'custom:422') && str_contains($err(fn() => $svc->finish(1, $today, $now)), '8 dostaw'), 'finish refused while flagged deliveries have no decision');
$check(str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'maybe'], 1, $today, $now)), 'custom:422'), 'unknown decision: refused');
$check(str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'contact', 'note' => 'ab'], 1, $today, $now)), 'notatk'), 'contact without a note (≥ 3 chars): refused');
$check(str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'new_date', 'note' => 'Klient prosi'], 1, $today, $now)), 'podaj datę')
    && str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'new_date', 'new_date' => '2026-02-30', 'note' => 'Klient prosi'], 1, $today, $now)), 'podaj datę')
    && str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'new_date', 'new_date' => '2026-11-23', 'note' => 'Klient prosi'], 1, $today, $now)), 'przeszłości')
    && str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'new_date', 'new_date' => '2027-02-01', 'note' => 'Klient prosi'], 1, $today, $now)), '60 dni')
    && str_contains($err(fn() => $svc->saveDecision(['id_order' => 307, 'decision' => 'new_date', 'new_date' => '2026-11-26', 'note' => ''], 1, $today, $now)), 'notatk'),
    'new date: date required, valid, not in the past, ≤ 60 days, with a note');
$check(str_contains($err(fn() => $svc->saveDecision(['id_order' => 311, 'decision' => 'ok'], 1, $today, $now)), 'nie ma na liście')
    && str_contains($err(fn() => $svc->saveDecision(['id_order' => 301, 'decision' => 'ok'], 1, $today, $now)), 'nic do wyjaśnienia'), 'delivery outside the list or without a flag: refused');
$svc->saveDecision(['id_order' => 307, 'decision' => 'contact', 'note' => 'Telefon : klient nie odbiera'], 1, $today, $now);
$svc->saveDecision(['id_order' => 307, 'decision' => 'new_date', 'new_date' => '2026-11-26', 'note' => 'Klient prosi o czwartek'], 1, $today, $now); // upsert : la dernière compte
$row = $pdo->query('SELECT * FROM n_delivery_delay_check WHERE id_order = 307')->fetchAll();
$check(count($row) === 1 && $row[0]['decision'] === 'new_date' && $row[0]['new_date'] === '2026-11-26' && $row[0]['ship_date'] === '2026-11-20' && $row[0]['flags'] === 'ship_overdue' && $row[0]['day'] === $today,
    'one row per delivery per day (upsert), new date, shipping date and flags at the time');
$check($pdo->query('SELECT DATE(expected_shipping_date) FROM customer_order WHERE id = 307')->fetchColumn() === '2026-11-20', 'new date only noted: the order keeps its shipping date');
$st = $tasks->state(DeliveryDelaysService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 dostawa wyjaśniona', 'task in progress: step 2, 1 delivery clarified');
$svc->saveDecision(['id_order' => 305, 'decision' => 'ok', 'new_date' => '2026-11-30', 'note' => ''], 2, $today, $now); // date ignorée hors « nowy termin »
$check($pdo->query('SELECT new_date FROM n_delivery_delay_check WHERE id_order = 305')->fetchColumn() === null, 'new date ignored for other decisions');
foreach ([302 => ['ok', 'Lista kompletacyjna wysłana na rogalnię'], 303 => ['ok', 'Kierowca : Ewa Lis'], 304 => ['new_date', 'Reszta palet w czwartek', '2026-11-26'],
    306 => ['contact', 'Klient potwierdził środę'], 308 => ['ok', 'Kierowca : dostarczone, brak potwierdzenia na tablecie']] as $id => $v) {
    $svc->saveDecision(['id_order' => $id, 'decision' => $v[0], 'note' => $v[1], 'new_date' => $v[2] ?? null], 2, $today, $now);
}
$d = $svc->data($today, $now);
$check($d['totals']['waiting'] === 1 && $d['can_finish'] === false && str_contains($err(fn() => $svc->finish(1, $today, $now)), 'Najpierw wyjaśnij 1 dostawę'), 'one left: step 3 still blocked');
$svc->saveDecision(['id_order' => 310, 'decision' => 'contact', 'note' => 'Sklep D : nowa dostawa w piątek'], 1, $today, $now);
$d = $svc->data($today, $now);
$by = array_column($d['orders'], null, 'id');
$check($d['can_finish'] === true && $d['summary'] === '11 dostaw · 8 do wyjaśnienia · 4 w drodze / OK · 2 nowe terminy · 2 kontakty', 'all decided: step 3 open — ' . $d['summary']);
$after = $d; // aperçu : toutes les décisions prises, avant « Zakończ »
$check($by[307]['check']['label'] === 'Nowy termin' && $by[307]['check']['new_label'] === 'czw. 26.11' && $by[307]['check']['by'] === 'Anna K.' && $by[305]['check']['by'] === 'piotr',
    'decision shown with label, new date and author');

// --- SMS : brouillon « zmiana » avec le nouveau terme (passerelle factice, rien n'est envoyé par l'assistant)
$fake = new class implements SmsTransport {
    public array $sent = [];
    public function name(): string { return 'fake'; }
    public function delivers(): bool { return false; }
    public function send(string $to, string $text, array $context = []): array { $this->sent[] = $text; return ['status' => 'logged', 'provider_id' => null, 'error' => null]; }
};
$sms = new OrderSmsService(new OrderSmsRepository($pdo), $fake);
$draft = $sms->draft('order_changed', 307, ['detail' => 'nowy termin wysyłki : 26.11']);
$check($draft['language'] === 'pl' && str_contains($draft['text'], 'nowy termin wysyłki : 26.11') && $draft['phone'] === '+48 600 300 400' && $fake->sent === []
    && (int) $pdo->query('SELECT COUNT(*) FROM n_sms_log')->fetchColumn() === 0, 'SMS draft (pl) with the new date ; nothing sent without a click');

// --- Étape 3 : Zakończ
$r = $svc->finish(1, $today, $now);
$done = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'deliveries_delays_checked'")->fetch();
$check($done && $done['day'] === $today && $done['summary'] === $r['summary'] && (int) $done['done_by'] === 1 && $r['data']['done'] !== null, 'finish writes n_wizard_done (code, day, summary, user)');
$check($sum() === $before, 'read only: orders, transport, pallets and driver tablet unchanged by decisions and finish');
$st = $tasks->state(DeliveryDelaysService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'position task done with the summary');

// --- Lendemain : décisions à refaire, la précédente rappelée
$n = array_column($svc->data('2026-11-25', '2026-11-25 09:00:00')['orders'], null, 'id');
$check($n[307]['check'] === null && $n[307]['previous']['label'] === 'Nowy termin' && $n[307]['previous']['new_label'] === 'czw. 26.11' && $n[307]['previous']['day_label'] === 'wt. 24.11'
    && $n[307]['late_days'] === 5 && $tasks->state(DeliveryDelaysService::CODE, '2026-11-25 00:00:00', '2026-11-26 00:00:00', '2026-11-25')['done'] === false,
    'next day: decisions start again, the previous one is recalled, task open');

// --- Enregistrement : catalogue, lien, droits, routes
$check(TaskWizards::info('deliveries_delays_checked')['link'] === '/logistics/delays' && TaskWizards::byLink('/logistics/delays') === 'deliveries_delays_checked'
    && isset(TaskWizards::AUTO['deliveries_delays_checked']) && PositionTaskService::LINK_AUTO['/logistics/delays'] === 'deliveries_delays_checked', 'catalog, auto description and position-task link');
$check(PageAccess::keyFor('/logistics/delays') === 'logistics' && PageAccess::keyFor('/ajax/logistics/delays') === 'logistics' && PageAccess::keyFor('/ajax/logistics/delays/decision') === 'logistics'
    && PageAccess::keyFor('/ajax/logistics/stock/pallets/1') === 'stock_pallets', 'page and ajax under the existing « logistics » right (stock pallets unchanged)');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'deliveries_delays_checked' =>"), 'team tasks hint');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Logistics/DeliveryDelaysRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/logistics/delays') === 'index' && $found('GET', '/ajax/logistics/delays') === 'data'
    && $found('POST', '/ajax/logistics/delays/decision') === 'decision' && $found('POST', '/ajax/logistics/delays/finish') === 'finish', 'routes');
$all = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($all->dispatch('GET', '/logistics/delays')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob');

if (getenv('DD_JSON_OUT')) file_put_contents((string) getenv('DD_JSON_OUT'), json_encode(['before' => $boot, 'after' => $after], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_delivery_delays_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
