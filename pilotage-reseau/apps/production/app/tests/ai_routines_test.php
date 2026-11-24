<?php
/**
 * [387] Rutyny poranne AI (pilot) : migration 234 rejouable (désactivé par défaut), clé Anthropic (.env → erp_cles →
 * réglage chiffré), anonymisation (aucune donnée personnelle des tables de test dans la charge ni dans la requête : noms,
 * e-mails, téléphones, NIP, adresses, villes, chauffeurs, plaques, utilisateurs, références et notes), validation des
 * réponses (ref connue, décision de l'assistant, confiance, note ≤ 500, note obligatoire, « poprawione » KSeF), interrupteurs
 * et budget vérifiés avant tout appel (aucun appel HTTP), refus du modèle, réponse coupée / sans JSON / erreurs HTTP, forme
 * exacte de la requête SDK (beta messages, effort low, json_schema strict, fallbacks, sans thinking / temperature /
 * budget_tokens / cache_control, clé seulement dans l'en-tête), coût (tokens × prix × taux, itérations de repli),
 * désanonymisation à l'affichage seulement, statuts accepted / edited / rejected, aucune écriture dans les tables de
 * l'ERP ni dans les résultats des assistants (sommes de contrôle), aucun SMS, cron (heure, jours ouvrés, une fois par
 * jour, une seule reprise après erreur), contrôleurs RÉELS construits par PHP-DI et appelés (réglages : admin 200, autre
 * 403 ; assistants : data() 200 avec `ai`, et sans migration / sans proposition la page fonctionne comme avant).
 *
 * AUCUN APPEL RÉEL À L'API : AiSettings::$clientFactory donne un client SDK officiel branché sur un gestionnaire Guzzle
 * local (réponses fabriquées dans le test, requêtes enregistrées).
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_ai_routines_test recréée (tables minimales des trois assistants + migrations 053–055, 217, 221, 226, 234),
 * supprimée à la fin. AI_JSON_OUT=fichier : données des trois pages avec propositions et état des réglages (aperçus).
 *   php tests/ai_routines_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Controllers\Web\Client\Order\OrderDayCheckController;
use App\Controllers\Web\Logistics\DeliveryDelaysController;
use App\Controllers\Web\Settings\AiRoutinesController;
use App\Controllers\Web\Shipping\InvoicesKsefDayController;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Ai\Anonymizer;
use App\Services\Ai\MorningRoutineService;
use App\Services\Ai\RoutineAssistants;
use App\Services\DevCard\AiSettings;
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Organization\Wizards\DeliveryDelaysService;
use App\Services\Organization\Wizards\InvoicesKsefDayService;
use App\Services\Organization\Wizards\OrdersDayCheckService;
use App\Services\Workshop\Migration\MigrationRunner;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Request;

const OD = OrdersDayCheckService::CODE;
const DD = DeliveryDelaysService::CODE;
const IK = InvoicesKsefDayService::CODE;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$dbUser = getenv('TFB_TEST_USER') ?: 'root';
$dbPass = getenv('TFB_TEST_PASS') ?: '';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$pdo = new PDO($dsn, $dbUser, $dbPass, $opts);
$pdo->exec('DROP DATABASE IF EXISTS erp_ai_routines_test');
$pdo->exec('CREATE DATABASE erp_ai_routines_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE erp_ai_routines_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    "CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), name VARCHAR(40) NULL, surname VARCHAR(40) NULL, fac_town VARCHAR(60) NULL, fac_country VARCHAR(40) NULL,
        fac_street VARCHAR(80) NULL, email VARCHAR(80) NULL, nip VARCHAR(30) NULL,
        active TINYINT DEFAULT 1, phone_head VARCHAR(40) NULL, phone_alt VARCHAR(40) NULL, communication_lang VARCHAR(5) NULL)",
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1, cpp INT NULL, cpl INT NULL, lpp INT NULL, ppc INT NULL, weight DECIMAL(10,2) NULL, id_category INT NULL)',
    'CREATE TABLE final_product_ppwr_sales_packaging (id INT AUTO_INCREMENT PRIMARY KEY, id_final_product INT, id_outer_raw_material INT NULL)',
    'CREATE TABLE carton_dimension (id INT PRIMARY KEY, length INT, width INT, height INT)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    "CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, order_description TEXT NULL, status INT DEFAULT 1, expected_shipping_date DATETIME NULL,
        create_order_date DATETIME, update_status_date DATETIME NULL, is_freebie TINYINT DEFAULT 0, order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD', id_wdt INT NULL, id_proforma INT NULL)",
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2), expected_quantity_carton DECIMAL(10,2) NULL)',
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
    'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)',
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
    "CREATE TABLE n_app_secret (code VARCHAR(64) NOT NULL PRIMARY KEY, secret TEXT NOT NULL, hint VARCHAR(16) NULL, updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
    "CREATE TABLE erp_cles (cle VARCHAR(80) NOT NULL PRIMARY KEY, valeur MEDIUMTEXT NULL, secret TINYINT(1) NOT NULL DEFAULT 1, indice VARCHAR(12) NULL,
        portail VARCHAR(20) NOT NULL DEFAULT 'commun', integration VARCHAR(60) NULL, maj_le DATETIME NOT NULL, maj_par VARCHAR(160) NULL)",
] as $sql) $pdo->exec($sql);
foreach (['053_ksef_integration.sql', '054_ksef_event_log.sql', '055_ksef_submission_environment.sql'] as $f) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/' . $f)) as $sql) $pdo->exec($sql);
}
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5,6,7', '1,2,3,4,5', 'string', 'dni', 'jours'),
    ('planning.default_departure_time', '20:00', '20:00', 'time', 'wyjazd', 'départ'), ('planning.loading_time_min', '60', '60', 'int', 'załadunek', 'chargement')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl) VALUES (1, 'KIEROWNIK_OPERACYJNY', 'Kierownik operacyjny'), (2, 'PIEKARZ', 'Piekarz'), (3, 'ADMINISTRACJA', 'Administracja')");
foreach (['217_orders_day_check.sql', '221_delivery_delays.sql', '226_invoices_ksef_day.sql'] as $f) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
}

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Données fictives, datées par rapport au jour réel (les contrôleurs lisent la date du serveur).
$T = date('Y-m-d');
$day = fn(int $n) => date('Y-m-d', strtotime($T . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' days'));
$pdo->exec("INSERT INTO user VALUES (1, 'kwisniewska', 'Katarzyna Wiśniewska'), (2, 'jnowakowski', 'Jan Nowakowski'), (3, 'mlewandowska', 'Monika Lewandowska')");
$pdo->exec("INSERT INTO customer (id, company, name, surname, fac_town, fac_street, email, nip, phone_head, phone_alt, communication_lang) VALUES
    (1, 'Piekarnia Kowalczyk i Synowie', 'Tomasz', 'Kowalczyk', 'Wrocław', 'ul. Długa 15', 'biuro@kowalczyk-piekarnia.pl', '526-104-08-28', '+48 600 123 456', '71 345 67 89', 'pl'),
    (2, 'Café Lumière Ixelles', 'Sophie', 'Dubreuil', 'Bruxelles', 'Rue de la Loi 12', 'contact@lumiere-ixelles.be', 'BE0123456789', '0476 12 34 56', NULL, 'fr'),
    (3, 'Sklep Pod Jabłonią', NULL, NULL, 'Kraków', 'ul. Floriańska 3', 'sklep.jablon@gmail.com', '6762468012', '12 422 11 00', NULL, 'pl'),
    (4, 'Delikatesy Malinowscy', 'Ewa', 'Malinowska', 'Gdańsk', 'al. Grunwaldzka 82', 'ewa.malinowska@delimal.pl', '5842711234', '+48 501 777 888', NULL, 'pl'),
    (5, 'Bistro Vistule Namur', NULL, NULL, 'Namur', 'Avenue Reine Astrid 54', 'hello@vistule.be', 'BE0987654321', '081 22 33 44', NULL, 'fr'),
    (6, 'Hotel Wisłoujście', NULL, NULL, 'Sopot', 'ul. Morska 9', 'recepcja@wisloujscie.pl', '5851234567', '58 555 01 02', NULL, 'pl')");
$pdo->exec("INSERT INTO final_product (id, name, cpp, cpl, lpp, ppc, weight) VALUES (10, 'Croissant maślany', 40, 8, 5, 60, 70), (11, 'Bagietka', 50, 10, 5, 20, 250), (12, 'Tarta jabłkowa', 40, 8, 5, 60, 90)");
$pdo->exec('UPDATE final_product SET active = 0 WHERE id = 12');
$pdo->exec("INSERT INTO carrier VALUES (1, 'Trans-Pol Zieliński', 1)");
$pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname, driver_phone, driver_email) VALUES (1, 1, 'WX 4471K', 'Marek', 'Zieliński', '+48 602 444 555', 'marek.z@transpol.pl')");
$pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, departure_time, active, id_driver) VALUES (1, 33, '20:00', 1, NULL)");
$pdo->exec("INSERT INTO production_employee VALUES (70, 'Grzegorz', 'Pawłowski')");
$pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee) VALUES (7, 70)");
$pdo->exec("INSERT INTO n_tour (id, name, weekdays, departure_time, id_driver, active) VALUES (1, 'Trasa Oleśnica-Brzeg', 127, NULL, 7, 1)");
$pdo->exec('INSERT INTO n_tour_vehicle VALUES (1, 1, 1)');
$pdo->exec('INSERT INTO n_tour_customer VALUES (1, 1, 1)');
$pdo->exec("INSERT INTO erp_company_data VALUES (1, 'Atelier Test')");
$order = function (int $id, int $cust, ?string $ship, array $lines, array $o = []) use ($pdo, $T) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, reference, order_description, status, expected_shipping_date, create_order_date, update_status_date, order_type, id_wdt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $cust, $o['ref'] ?? null, $o['desc'] ?? null, $o['status'] ?? 1, $ship !== null ? $ship . ' 00:00:00' : null,
        $o['created'] ?? $T . ' 00:05:00', $o['since'] ?? null, $o['type'] ?? 'STANDARD', $o['wdt'] ?? null]);
    foreach ($lines as $p => $q) $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (?, ?, ?)')->execute([$id, $p, $q]);
};
// Zamówienia dnia : sans date (référence et description avec des données personnelles), date passée (modifiée par une
// personne), produit inactif, une commande sans signal.
$order(501, 1, null, [10 => 10], ['ref' => 'PO-ZK-7781', 'desc' => 'Dzwonić do pani Ewy Malinowskiej 600 123 456 przed dostawą']);
$order(502, 2, $day(-1), [11 => 8]);
$order(503, 3, $day(3), [10 => 4, 12 => 2]);
$order(504, 1, $day(5), [10 => 6]);
$pdo->prepare("INSERT INTO customer_order_changelog (id_order, id_user, action_type, source, changed_fields, created_at) VALUES (502, 1, 'update', 'web', ?, ?)")
    ->execute([json_encode(['before' => ['expected_shipping_date' => $day(2)], 'after' => ['expected_shipping_date' => $day(-1)]]), $T . ' 00:10:00']);
// Opóźnienia dostaw : après la date sans WDT, « W transporcie » depuis 4 jours avec un problème à la livraison (note du chauffeur
// personnelle), aujourd'hui avec véhicule (plaque, chauffeur, transporteur, tournée) et rien de prêt.
$order(601, 2, $day(-2), [10 => 12], ['created' => $day(-5) . ' 09:00:00']);
$order(602, 4, $day(-4), [10 => 8], ['created' => $day(-6) . ' 09:00:00', 'status' => 3, 'since' => $day(-4) . ' 20:00:00', 'wdt' => 91]);
$order(603, 1, $T, [10 => 30], ['created' => $day(-3) . ' 09:00:00']);
$pdo->exec("INSERT INTO document_wdt VALUES (91, 'WDT/0091/2026', '" . $day(-4) . "')");
$pdo->exec("INSERT INTO shipment_log (id, shipment_date, id_customer, id_wdt) VALUES (90, '" . $day(-4) . "', 4, 91)");
$pdo->prepare("INSERT INTO n_delivery_stop (ship_date, id_customer, status, reason, note, recorded_at) VALUES (?, 4, 'problem', 'closed', 'Pani Ewa Malinowska nie odebrała, tel. 600 123 456', ?)")
    ->execute([$day(-4), $day(-3) . ' 06:40:00']);
$pdo->prepare('INSERT INTO n_shipping_assignment (ship_date, id_customer, id_tour, tour_set, id_carrier_vehicle, vehicle_set) VALUES (?, 1, 1, 1, 1, 1)')->execute([$T]);
// Faktury i KSeF dnia : sans WDT, rejetée par KSeF (message avec NIP et nom du client), acceptée.
$pdo->exec("INSERT INTO shipment_log (id, shipment_date, id_customer, id_wdt) VALUES (701, '{$T}', 5, NULL), (702, '{$T}', 6, 202), (703, '{$T}', 1, 203)");
$pdo->exec("INSERT INTO document_wdt VALUES (202, 'WDT/0202/2026', '{$T}'), (203, 'WDT/0203/2026', '{$T}')");
$ks = $pdo->prepare('INSERT INTO ksef_submission (id_document_wdt, id_shipment_log, status, environment, ksef_number, error_code, error_message, submitted_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$ks->execute([202, 702, 'rejected', 'production', null, '450', 'Błąd : NIP nabywcy 5851234567 niezgodny z danymi (Hotel Wisłoujście, recepcja@wisloujscie.pl)', $T . ' 08:00:00', $T . ' 08:00:00']);
$ks->execute([203, 703, 'accepted', 'production', '5260001111-20261123-0A1B2C-01', null, null, $T . ' 08:00:00', $T . ' 08:00:00']);
// Droits : 1 administrateur, 2 Piekarz sans droit, 3 Kierownik operacyjny (zamówienia, logistyka, wysyłki).
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1)');
$keys = array_unique(array_filter([PageAccess::keyFor(OrderDayCheckController::PATH), PageAccess::keyFor(DeliveryDelaysController::PATH), PageAccess::keyFor(InvoicesKsefDayController::PATH)]));
foreach ($keys as $k) $pdo->prepare('INSERT INTO n_page_access (id_position, page_key) VALUES (1, ?)')->execute([$k]);
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'production')");

// Valeurs personnelles des tables de test (scan de tout ce qui part vers l'API).
$sensitive = [];
foreach ($pdo->query('SELECT company, name, surname, fac_town, fac_street, email, nip, phone_head, phone_alt FROM customer')->fetchAll() as $r) foreach ($r as $v) if ($v !== null) $sensitive[] = $v;
foreach ($pdo->query('SELECT login, displayed_name FROM user')->fetchAll() as $r) foreach ($r as $v) $sensitive[] = $v;
foreach ($pdo->query('SELECT regis_num, driver_name, driver_surname, driver_phone, driver_email FROM carrier_vehicle')->fetchAll() as $r) foreach ($r as $v) $sensitive[] = $v;
foreach ($pdo->query('SELECT name FROM carrier UNION SELECT name FROM n_tour UNION SELECT name FROM production_employee UNION SELECT surname FROM production_employee')->fetchAll(PDO::FETCH_COLUMN) as $v) $sensitive[] = $v;
foreach (['SELECT reference FROM customer_order WHERE reference IS NOT NULL', 'SELECT order_description FROM customer_order WHERE order_description IS NOT NULL',
    'SELECT note FROM n_delivery_stop', 'SELECT ksef_number FROM ksef_submission WHERE ksef_number IS NOT NULL'] as $q) foreach ($pdo->query($q)->fetchAll(PDO::FETCH_COLUMN) as $v) $sensitive[] = $v;
// Mots distinctifs (noms de famille, villes) et chiffres des téléphones / NIP sans séparateurs.
$sensitive = array_merge($sensitive, ['Kowalczyk', 'Lumière', 'Dubreuil', 'Jabłoni', 'Malinowsk', 'Vistule', 'Wisłoujście', 'Wiśniewska', 'Nowakowski', 'Lewandowska', 'Zieliński',
    'Pawłowski', 'Oleśnica', 'Ixelles', 'Floriańska', 'Grunwaldzka']);
$digits = array_values(array_filter(array_map(fn($v) => preg_replace('/\D/', '', (string) $v), $sensitive), fn($d) => strlen($d) >= 7));
$sensitive = array_values(array_unique(array_filter(array_map('trim', $sensitive), fn($v) => mb_strlen($v) >= 3)));
$leaks = function (string $text) use ($sensitive, $digits): array {
    $out = [];
    $low = mb_strtolower($text);
    foreach ($sensitive as $v) if (str_contains($low, mb_strtolower($v))) $out[] = $v;
    $flat = preg_replace('/(?<=\d)[ .\-\/]+(?=\d)/', '', $text);
    foreach ($digits as $d) if (str_contains($flat, $d)) $out[] = '#' . $d;
    if (preg_match('/[\w.+-]+@[\w-]+\.\w{2,}/u', $text)) $out[] = '@e-mail';
    return array_values(array_unique($out));
};

// --- Faux serveur Anthropic : client SDK officiel, gestionnaire Guzzle local (aucun réseau).
$history = [];   // [request, body array]
$mode = 'echo';  // echo | refusal | max_tokens | nojson | http500 | http429 | fallback
$handler = function (RequestInterface $req, array $options) use (&$history, &$mode) {
    $raw = (string) $req->getBody();
    $body = json_decode($raw, true) ?: [];
    $history[] = ['req' => $req, 'raw' => $raw, 'body' => $body];
    $json = fn(array $a, int $status = 200) => Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($a, JSON_UNESCAPED_UNICODE)));
    if ($mode === 'http500') return $json(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal error']], 500);
    if ($mode === 'http429') return $json(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429);
    $text = is_string($body['messages'][0]['content'] ?? null) ? $body['messages'][0]['content'] : '';
    $data = preg_match('~<dane>\n(.*)\n</dane>~s', $text, $m) ? json_decode($m[1], true) : null;
    $enum = $body['output_config']['format']['schema']['properties']['proposals']['items']['properties']['decision']['enum'] ?? [];
    $pick = in_array('in_progress', $enum, true) ? 'in_progress' : (in_array('contact', $enum, true) ? 'contact' : ($enum[0] ?? 'ok'));
    $props = [];
    foreach ((array) ($data['pozycje'] ?? []) as $i => $it) {
        $props[] = ['ref' => (string) $it['ref'], 'decision' => $pick, 'note' => 'Ustalić z ' . ($it['klient'] ?? 'K?') . ' sprawę pozycji ' . $it['ref'] . '.',
            'reason' => 'Sygnał : ' . ($it['sygnaly'][0]['kod'] ?? '?') . '.', 'confidence' => ['high', 'medium', 'low'][$i % 3]];
    }
    $out = ['summary' => count($props) . ' pozycji do wyjaśnienia ; najpierw ' . ($data['pozycje'][0]['klient'] ?? '—') . '.', 'proposals' => $props];
    $usage = ['input_tokens' => 3000, 'output_tokens' => 800, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];
    if ($mode === 'fallback') $usage['iterations'] = [
        ['type' => 'message', 'model' => 'claude-opus-5-5', 'input_tokens' => 1000, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_creation' => null],
        ['type' => 'fallback_message', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 3000, 'output_tokens' => 800, 'cache_read_input_tokens' => 200, 'cache_creation_input_tokens' => 0, 'cache_creation' => null]];
    $stop = ['refusal' => 'refusal', 'max_tokens' => 'max_tokens'][$mode] ?? 'end_turn';
    $content = $mode === 'nojson' ? [['type' => 'text', 'text' => 'To nie jest JSON.']] : ($mode === 'refusal' ? [] : [['type' => 'text', 'text' => json_encode($out, JSON_UNESCAPED_UNICODE)]]);
    $msg = ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $mode === 'fallback' ? 'claude-sonnet-5-5' : (string) ($body['model'] ?? 'x'),
        'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => $usage];
    if ($mode === 'refusal') $msg['stop_details'] = ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'policy'];
    return $json($msg);
};
AiSettings::$clientFactory = fn(string $key): Client => new Client(apiKey: $key, baseUrl: 'https://api.anthropic.test',
    requestOptions: RequestOptions::with(maxRetries: 0, transporter: new \GuzzleHttp\Client(['handler' => HandlerStack::create($handler)])));
$prevKey = getenv('ANTHROPIC_API_KEY');
putenv('ANTHROPIC_API_KEY');
unset($_ENV['ANTHROPIC_API_KEY']);

// --- PHP-DI comme App/Core/bootstrap.php (PDO de test, passerelle SMS espionne : aucun SMS ne doit partir).
$smsSent = [];
$fakeSms = new class($smsSent) implements SmsTransport {
    public function __construct(private array &$sent) {}
    public function name(): string { return 'spy'; }
    public function delivers(): bool { return false; }
    public function send(string $to, string $text, array $context = []): array { $this->sent[] = $to; return ['status' => 'logged', 'provider_id' => null, 'error' => null]; }
};
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_ai_routines_test;charset=utf8mb4', $dbUser, $dbPass, $opts),
    WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
    SmsTransport::class => $fakeSms,
    // Comme delivery_delays_test : plan logistique sans le catalogue des cartons (tables n_carton… hors de ce test).
    App\Services\Logistics\LogisticsService::class => DI\autowire()->constructorParameter('cartons', null)]);
$di = $b->build();
$params = $di->get(ParamRepository::class);
$body = '';
// Corps JSON des appels ajax (php://input est vide en CLI) : la fabrique de Symfony reçoit le corps courant.
Request::setFactory(function ($q = [], $r = [], $a = [], $c = [], $f = [], $s = [], $content = null) use (&$body) { return new Request($q, $r, $a, $c, $f, $s, $body); });
$call = function (object $ctl, int $user, string $method, array $args = [], ?array $json = null) use (&$body) {
    $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user];
    $body = $json !== null ? json_encode($json) : '';
    $res = $ctl->$method(...$args);
    unset($_SESSION['USER']);
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};
$od = $di->get(OrderDayCheckController::class);
$dd = $di->get(DeliveryDelaysController::class);
$ik = $di->get(InvoicesKsefDayController::class);
$rt = $di->get(AiRoutinesController::class);

// --- Sans la migration 234 : les trois pages fonctionnent comme avant (ai = null), aucun appel.
foreach (['Zamówienia dnia' => $od, 'Opóźnienia dostaw' => $dd, 'Faktury i KSeF dnia' => $ik] as $label => $ctl) {
    [$code, $res] = $call($ctl, 3, 'data');
    $check($code === 200 && array_key_exists('ai', $res['data']) && $res['data']['ai'] === null && ($res['data']['today'] ?? null) === $T, "before migration 234 : {$label} data() 200, ai = null");
}
[$code] = $call($rt, 1, 'state');
$check($code >= 400 && $history === [], 'before migration 234 : settings state refused cleanly, no API call');

// --- Migration 234 (deux passages)
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/234_ai_routines.sql')) as $sql) $pdo->exec($sql);
$p = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'ai.routines%' ORDER BY code")->fetchAll(), 'value', 'code');
$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_ai_routine%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_ai_routine%'")->fetchColumn();
$check(count($p) === 10 && $p['ai.routines_enabled'] === '0' && $p['ai.routines_model'] === 'claude-opus-5-5' && $p['ai.routines_budget_pln'] === '50'
    && $p['ai.routines_time.orders_day_checked'] === '06:30' && $p['ai.routines_on.invoices_ksef_checked'] === '1', 'migration 234 replayed : 10 params once, master switch OFF by default, Opus 5.5');
$check($tables === ['n_ai_routine_proposal', 'n_ai_routine_run'] && $fks === 0, 'migration 234 : 2 tables, no foreign key to ERP tables');
foreach (['ai.routines_enabled', 'ai.routines_budget_pln', 'ai.routines_usd_pln', 'ai.routines_on.orders_day_checked'] as $c) $check(isset(ParamRules::RANGES[$c]), "ParamRules bounds for {$c}");
$check(ParamRules::rangeError('ai.routines_budget_pln', '-5') !== null && ParamRules::rangeError('ai.routines_budget_pln', '50') === null, 'budget bounds : negative refused');
$svc = $di->get(MorningRoutineService::class);
$ai = $di->get(AiSettings::class);
$check(!$svc->enabled() && $svc->model() === 'claude-opus-5-5' && $svc->budget() === 50.0 && $svc->usdPln() === 4.0 && $svc->time(OD) === '06:30' && $svc->time(IK) === '07:00', 'defaults read through the service');

// --- Clé : .env → erp_cles → réglage chiffré ; jamais la clé dans l'état.
$check(!$ai->configured() && $ai->keyInfo()['source'] === null, 'no key anywhere : not configured');
$cipher = $di->get(App\Services\Mail\MailboxSecretCipher::class);
$pdo->prepare("INSERT INTO n_app_secret (code, secret, hint) VALUES ('anthropic_api_key', ?, 'SETT')")->execute([$cipher->encrypt('sk-ant-settings-0000000000000000000000SETT')]);
$ai->forget();
$check($ai->keyInfo() === ['configured' => true, 'source' => 'settings', 'hint' => 'SETT'], 'encrypted setting used when nothing else');
$pdo->exec("INSERT INTO erp_cles (cle, valeur, portail, maj_le) VALUES ('anthropic_api_key', 'sk-ant-erpcles-000000000000000000000000ERPC', 'commun', NOW())");
$ai->forget();
$check($ai->keyInfo() === ['configured' => true, 'source' => 'erp_cles', 'hint' => 'ERPC'], 'erp_cles (portal commun) BEFORE the encrypted setting');
putenv('ANTHROPIC_API_KEY=sk-ant-env-00000000000000000000000000000ENVK');
$ai->forget();
$check($ai->keyInfo()['source'] === 'env', '.env first');
putenv('ANTHROPIC_API_KEY');
$ai->forget();
$check(!str_contains(json_encode($ai->keyInfo()), 'sk-ant-') && $ai->keyInfo()['hint'] === 'ERPC', 'key info : source and last 4 characters only');

// --- Anonymiseur
$a = new Anonymizer();
$k1 = $a->code('customer', 12, 'Piekarnia Kowalczyk i Synowie');
$check($k1 === 'K1' && $a->code('customer', 12, 'inna nazwa') === 'K1' && $a->code('customer', 13, 'Sklep') === 'K2' && $a->code('person', null, 'Jan Nowakowski') === 'P1'
    && $a->code('person', null, 'jan nowakowski ') === 'P1' && $a->code('customer', null, '') === null, 'codes : stable per entity, by id or name hash, empty → null');
$check($a->codes()['K1'] === 'customer:12' && str_starts_with($a->codes()['P1'], 'person:#') && !str_contains(json_encode($a->codes()), 'Nowakowski'), 'stored keys carry no name');
$s = $a->scrub('Piekarnia Kowalczyk i Synowie (biuro@kowalczyk-piekarnia.pl, +48 600 123 456, NIP 526-104-08-28, ul. Długa 15) — Jan Nowakowski dzwonił 24.11.2026, www.kowalczyk.pl', 300);
$check($s === 'K1 ([e-mail], [nr], NIP [nr], [adres]) — P1 dzwonił 24.11.2026, [link]', 'scrub : names → codes, e-mail, phone, NIP, street, link removed, date kept — ' . $s);
$check(Anonymizer::scrubPatterns('IBAN PL61 1090 1014 0000 0712 1981 2874, VAT BE0123456789, 2026-11-24, 0476 12 34 56') === 'IBAN [nr], VAT [nr], 2026-11-24, [nr]', 'scrub : IBAN, VAT, phone ; ISO date kept');
$check(Anonymizer::reveal('Zadzwonić do K1 (P1) ; K9 nieznany', $a->names()) === 'Zadzwonić do Piekarnia Kowalczyk i Synowie (Jan Nowakowski) ; K9 nieznany', 'reveal : codes → names, unknown code kept');
$check(Anonymizer::namesFor(['K7' => 'customer:12', 'K8' => 'customer:99'], $a->namesByKey()) === ['K7' => 'Piekarnia Kowalczyk i Synowie'], 'names of a stored run rebuilt from stable keys');

// --- Charge de chaque assistant : seulement les lignes signalées sans résultat, aucune donnée personnelle.
$assistants = $di->get(RoutineAssistants::class);
$payloads = [];
foreach ([OD => [501, 502, 503], DD => [502, 601, 602, 603], IK => [701, 702]] as $c => $refs) {
    $data = $assistants->data($c, $T);
    $pl = RoutineAssistants::payload($c, $data, new Anonymizer());
    $payloads[$c] = $pl;
    $got = array_map('intval', $pl['refs']); sort($got);
    $check($got === $refs, "payload {$c} : flagged rows without a result — " . implode(',', $got));
    $text = json_encode($pl, JSON_UNESCAPED_UNICODE) . RoutineAssistants::system($c) . MorningRoutineService::userText($c, $pl);
    $check($leaks($text) === [], "payload {$c} : no personal value of the test tables (" . implode(', ', $leaks($text)) . ')');
}
// Le scan n'est pas vide de sens : les données brutes des assistants contiennent bien ces valeurs.
$raw = $leaks(json_encode([$assistants->data(OD, $T), $assistants->data(DD, $T), $assistants->data(IK, $T)], JSON_UNESCAPED_UNICODE));
$check(count(array_intersect(['Piekarnia Kowalczyk i Synowie', 'WX 4471K', 'Marek', 'Katarzyna Wiśniewska', 'Trasa Oleśnica-Brzeg', 'PO-ZK-7781', '#5851234567'], $raw)) === 7,
    'scan sanity : the raw assistant data does contain customers, plate, driver, user, tour, reference, NIP');
$o501 = array_column($payloads[OD]['items'], null, 'ref')['501'];
$o502 = array_column($payloads[OD]['items'], null, 'ref')['502'];
$check(preg_match('/^K\d+$/', $o501['klient']) === 1 && $o501['ma_referencje_klienta'] === true && $o501['ma_opis'] === true && !isset($o501['referencja']), 'orders : customer as code, reference / description only as booleans');
$check(preg_match('/^P\d+$/', (string) $o502['zmienil']) === 1 && $o502['sygnaly'][0]['kod'] === 'date_past', 'orders : the person who changed the order as code');
$d603 = array_column($payloads[DD]['items'], null, 'ref')['603'];
$d602 = array_column($payloads[DD]['items'], null, 'ref')['602'];
$check(preg_match('/^V\d+$/', (string) $d603['transport']['pojazd']) === 1 && preg_match('/^P\d+$/', (string) $d603['transport']['kierowca']) === 1
    && preg_match('/^T\d+$/', (string) $d603['transport']['trasa']) === 1, 'deliveries : vehicle, driver and tour as codes');
$check($d602['dostawa']['notatka_kierowcy'] === true && in_array('delivery_problem', array_column($d602['sygnaly'], 'kod'), true), 'deliveries : driver note only as a boolean');
$k702 = array_column($payloads[IK]['items'], null, 'ref')['702'];
$check(str_contains(json_encode($k702['sygnaly'], JSON_UNESCAPED_UNICODE), '[nr]') && str_contains(json_encode($k702['sygnaly'], JSON_UNESCAPED_UNICODE), 'kod 450'), 'KSeF : rejection message kept, NIP and customer scrubbed');
$check(RoutineAssistants::schema(IK)['properties']['proposals']['items']['properties']['decision']['enum'] === array_keys(InvoicesKsefDayService::RESULTS)
    && RoutineAssistants::schema(DD)['properties']['proposals']['items']['properties']['decision']['enum'] === array_keys(DeliveryDelaysService::DECISIONS)
    && RoutineAssistants::schema(OD)['additionalProperties'] === false && RoutineAssistants::schema(OD)['properties']['proposals']['items']['additionalProperties'] === false,
    'schema : decision enum = the assistant results, additionalProperties false at both levels');

// --- Validation des réponses
$rows = ['501' => ['id' => 501], '502' => ['id' => 502], '503' => ['id' => 503]];
$v = MorningRoutineService::validate(OD, ['summary' => str_repeat('x', 900), 'proposals' => [
    ['ref' => '501', 'decision' => 'contact', 'note' => 'Zapytać K1 o datę.', 'reason' => 'brak daty', 'confidence' => 'high'],
    ['ref' => '501', 'decision' => 'ok', 'note' => '', 'reason' => 'x', 'confidence' => 'low'],               // doublon
    ['ref' => '999', 'decision' => 'ok', 'note' => '', 'reason' => 'x', 'confidence' => 'low'],               // ref inconnue
    ['ref' => '502', 'decision' => 'zapisz', 'note' => '', 'reason' => 'x', 'confidence' => 'low'],           // décision hors liste
    ['ref' => '502', 'decision' => 'ok', 'note' => '', 'reason' => 'x', 'confidence' => 'sure'],              // confiance inconnue
    ['ref' => '502', 'decision' => 'ok', 'note' => str_repeat('a', 501), 'reason' => 'x', 'confidence' => 'low'], // note > 500
    ['ref' => '502', 'decision' => 'contact', 'note' => ' ', 'reason' => 'x', 'confidence' => 'low'],         // note obligatoire vide
    'pas un objet',
    ['ref' => 503, 'decision' => 'changed', 'note' => '', 'reason' => 'produkt nieaktywny', 'confidence' => 'medium'],
]], $rows);
$check(array_column($v['proposals'], 'ref') === ['501', '503'] && $v['rejected'] === ['format' => 1, 'unknown_ref' => 1, 'duplicate' => 1, 'decision' => 1, 'confidence' => 1, 'too_long' => 1, 'note_missing' => 1]
    && mb_strlen($v['summary']) === 500, 'validation : known ref once, decision of the assistant, confidence, note ≤ 500, required note ; summary cut to 500');
$check(MorningRoutineService::validate(OD, ['proposals' => 'x'], $rows)['rejected'] === ['format' => 1] && MorningRoutineService::validate(OD, null, $rows)['proposals'] === [], 'validation : malformed JSON → nothing');
$vk = MorningRoutineService::validate(IK, ['summary' => '', 'proposals' => [['ref' => '702', 'decision' => 'fixed', 'note' => '', 'reason' => 'x', 'confidence' => 'high']]],
    ['702' => ['ksef' => ['status' => 'rejected'], 'signals' => [['code' => 'ksef_rejected']]]]);
$check($vk['proposals'] === [] && $vk['rejected'] === ['not_applicable' => 1], 'validation : KSeF « poprawione » only when KSeF accepted the invoice');

// --- Sommes de contrôle : toutes les tables sauf celles des routines (ERP, résultats des assistants, journal des SMS) ;
// hors réglages modifiés par le test lui-même (n_prod_param, erp_cles, n_app_secret).
$sum = function () use ($pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT LIKE 'n_ai_routine%'
        AND TABLE_NAME NOT IN ('n_prod_param', 'erp_cles', 'n_app_secret') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $out[$t] = $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => "IFNULL(`{$c}`, '∅')", $cols)) . ") ORDER BY 1 SEPARATOR '#')), '')) FROM `{$t}`")->fetchColumn();
    }
    return $out;
};
$pdo->exec('SET SESSION group_concat_max_len = 1000000');
$runs = fn() => (int) $pdo->query('SELECT COUNT(*) FROM n_ai_routine_run')->fetchColumn();
$erp0 = $sum();
$resultTables = ['n_order_check', 'n_delivery_delay_check', 'n_invoice_ksef_check', 'n_wizard_done', 'n_sms_log'];
$check(count(array_intersect($resultTables, array_keys($erp0))) === 5, 'checksums cover the assistants result tables, the wizard log and the SMS log');

// --- Interrupteurs et budget : vérifiés AVANT tout appel (aucune requête HTTP).
$r = $svc->run(OD, 'manual', 1, $T);
$check($r['status'] === 'skipped_off' && $history === [] && $svc->due($T . ' 23:00:00') === [], 'master switch OFF (default) : run skipped, cron has nothing to do, no HTTP call');
$params->setValue('ai.routines_enabled', '1', 1);
$params->setValue('ai.routines_on.orders_day_checked', '0', 1);
$check($svc->run(OD, 'manual', 1, $T)['status'] === 'skipped_off' && $history === [] && !in_array(OD, $svc->due($T . ' 23:00:00'), true), 'assistant switch OFF : skipped, not due, no HTTP call');
$params->setValue('ai.routines_on.orders_day_checked', '1', 1);
$pdo->prepare("INSERT INTO n_ai_routine_run (code, day, trigger_kind, started_at, status, cost_pln) VALUES ('orders_day_checked', ?, 'cron', ?, 'ok', 50)")->execute([substr($T, 0, 8) . '01', substr($T, 0, 8) . '01 06:31:00']);
$r = $svc->run(OD, 'manual', 1, $T);
$check($r['status'] === 'skipped_budget' && $history === [], 'month budget spent (50 zł of 50) : skipped before reading data, no HTTP call');
$pdo->exec('UPDATE n_ai_routine_run SET cost_pln = 49.999 WHERE cost_pln = 50');
$r = $svc->run(OD, 'manual', 1, $T);
$check($r['status'] === 'skipped_budget' && str_contains((string) $r['error'], 'Szacowany') && $history === [], 'estimated cost of the run above the rest of the budget : skipped, no HTTP call');
$pdo->exec('UPDATE n_ai_routine_run SET cost_pln = 1 WHERE cost_pln > 49');
$pdo->exec("DELETE FROM erp_cles"); $pdo->exec('DELETE FROM n_app_secret');
$ai->forget();
$r = $svc->run(OD, 'manual', 1, $T);
$check($r['status'] === 'error' && str_contains((string) $r['error'], 'erp_cles') && $history === [], 'no Anthropic key : error run, no HTTP call');
$pdo->exec("INSERT INTO erp_cles (cle, valeur, portail, maj_le) VALUES ('anthropic_api_key', 'sk-ant-erpcles-000000000000000000000000ERPC', 'commun', NOW())");
$ai->forget();
$check($sum() === $erp0, 'skipped runs : no write outside n_ai_routine_*');

// --- Passage réel (faux serveur) : forme de la requête SDK, coût, propositions en codes.
$r = $svc->run(OD, 'manual', 1, $T);
$check($r['status'] === 'ok' && $r['signals'] === 3 && $r['proposals'] === 3 && count($history) === 1, 'run « Zamówienia dnia » : one request, 3 proposals for 3 signals');
$h = $history[0];
$req = $h['req'];
$bd = $h['body'];
$check((string) $req->getUri() === 'https://api.anthropic.test/v1/messages' && str_contains($req->getHeaderLine('anthropic-beta'), 'server-side-fallback-2026-07-01'), 'SDK : beta messages endpoint, server-side fallback beta header');
$check(($req->getHeaderLine('x-api-key') === 'sk-ant-erpcles-000000000000000000000000ERPC' || $req->getHeaderLine('Authorization') === 'Bearer sk-ant-erpcles-000000000000000000000000ERPC')
    && !str_contains((string) $req->getUri() . $h['raw'], 'sk-ant-'), 'key from erp_cles, only in the request header');
$check($bd['model'] === 'claude-opus-5-5' && $bd['max_tokens'] === 16000 && ($bd['stream'] ?? false) === false && $bd['fallbacks'] === 'default', 'model Opus 5.5, max_tokens 16000, not streamed, fallbacks default');
$check($bd['output_config']['effort'] === 'low' && $bd['output_config']['format']['type'] === 'json_schema' && $bd['output_config']['format']['schema'] === RoutineAssistants::schema(OD), 'output_config : effort low, json_schema of the assistant');
$check(!isset($bd['thinking']) && !isset($bd['temperature']) && !str_contains($h['raw'], 'budget_tokens') && !str_contains($h['raw'], 'cache_control') && !isset($bd['tools']), 'no thinking, no temperature, no budget_tokens, no cache_control, no tools');
$check($bd['system'] === RoutineAssistants::system(OD) && count($bd['messages']) === 1 && str_contains($bd['messages'][0]['content'], '<dane>'), 'system = fixed rules of the assistant, one user message with the data');
$check($leaks($h['raw']) === [], 'raw HTTP body : no personal value (' . implode(', ', $leaks($h['raw'])) . ')');
$run = $pdo->query("SELECT * FROM n_ai_routine_run WHERE id = {$r['id']}")->fetch();
$check((int) $run['input_tokens'] === 3000 && (int) $run['output_tokens'] === 800 && abs((float) $run['cost_usd'] - 0.028) < 1e-9 && abs((float) $run['cost_pln'] - 0.112) < 1e-9
    && $run['model_served'] === 'claude-opus-5-5' && $run['trigger_kind'] === 'manual' && (int) $run['id_user'] === 1, 'cost : (3000 × 4 $ + 800 × 20 $) / 1M = 0,028 $ × 4,0 = 0,112 zł');
$stored = $pdo->query("SELECT ref, decision, note, reason, confidence, status FROM n_ai_routine_proposal WHERE id_run = {$r['id']} ORDER BY ref")->fetchAll();
$check(array_column($stored, 'ref') === ['501', '502', '503'] && array_unique(array_column($stored, 'status')) === ['pending'] && array_unique(array_column($stored, 'decision')) === ['contact']
    && preg_match('/^Ustalić z K\d+ /', $stored[0]['note']) === 1, 'proposals stored with codes (no name), pending');
$check($leaks(json_encode($run, JSON_UNESCAPED_UNICODE) . json_encode($stored, JSON_UNESCAPED_UNICODE)) === [], 'stored run (codes map, summary) and proposals : no personal value');
$check(MorningRoutineService::costUsd('claude-sonnet-5-5', 1_000_000, 1_000_000, 1_000_000) === 2.0 + 10.0 + 0.10 && MorningRoutineService::costUsd('claude-opus-5-5', 0, 0, 1_000_000) === 0.20,
    'price table : Sonnet 5.5 2 $ / 10 $ / 0,10 $ ; Opus 5.5 cache read 0,20 $');
$check($sum() === $erp0 && $smsSent === [], 'run : no write in ERP tables nor in the assistants results, no SMS');

// --- Refus, réponse coupée, sans JSON, erreurs HTTP : rien n'est remplacé.
$pending = fn(string $c) => (int) $pdo->query("SELECT COUNT(*) FROM n_ai_routine_proposal WHERE code = '{$c}' AND status = 'pending'")->fetchColumn();
$mode = 'refusal';
$r = $svc->run(OD, 'manual', 1, $T);
$check($r['status'] === 'refused' && str_contains((string) $r['error'], 'cyber') && $pending(OD) === 3, 'stop_reason refusal : run « refused », earlier proposals kept');
$mode = 'max_tokens';
$check($svc->run(OD, 'manual', 1, $T)['status'] === 'error' && $pending(OD) === 3, 'max_tokens : error, proposals kept');
$mode = 'nojson';
$check(($x = $svc->run(OD, 'manual', 1, $T))['status'] === 'error' && $x['error'] === 'Odpowiedź bez JSON.', 'text without JSON : error');
$mode = 'http500';
$x = $svc->run(DD, 'manual', 1, $T);
$check($x['status'] === 'error' && str_contains((string) $x['error'], 'HTTP 500') && !str_contains((string) $x['error'], 'sk-ant'), 'HTTP 500 : error with the status only');
$mode = 'http429';
$check(str_contains((string) $svc->run(DD, 'manual', 1, $T)['error'], '429'), 'HTTP 429 : error');
$errors = $pdo->query("SELECT error FROM n_ai_routine_run WHERE error IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$check($leaks(implode(' ', $errors)) === [] && !str_contains(implode(' ', $errors), 'sk-ant'), 'stored error texts : no data, no key');
// Repli serveur : coût par itération, chacune au prix de son modèle.
$mode = 'fallback';
$x = $svc->run(DD, 'manual', 1, $T);
$run = $pdo->query("SELECT * FROM n_ai_routine_run WHERE id = {$x['id']}")->fetch();
$expect = (1000 * 4 + 0 * 20) / 1e6 + (3000 * 2 + 800 * 10 + 200 * 0.10) / 1e6;
$check($x['status'] === 'ok' && $x['proposals'] === 4 && $run['model_served'] === 'claude-sonnet-5-5' && abs((float) $run['cost_usd'] - round($expect, 5)) < 1e-9
    && (int) $run['input_tokens'] === 4000 && (int) $run['cache_read_tokens'] === 200, 'fallback iterations : tokens summed, each priced with its own model — ' . json_encode([$x['status'], $x['error'], $run['cost_usd'] ?? null, $run['input_tokens'] ?? null, $run['cache_read_tokens'] ?? null, round($expect, 5)]));
$mode = 'echo';
$x = $svc->run(IK, 'manual', 1, $T);
$check($x['status'] === 'ok' && $x['proposals'] === 2 && $leaks(end($history)['raw']) === [], 'run « Faktury i KSeF dnia » : 2 proposals, no personal value sent');
$check($sum() === $erp0 && $smsSent === [], 'all runs : ERP tables and assistants results unchanged');

// --- Pages des assistants (contrôleurs réels) : propositions avec les vrais noms, seulement à l'affichage.
$pages = [];
foreach ([OD => $od, DD => $dd, IK => $ik] as $c => $ctl) {
    [$code, $res] = $call($ctl, 3, 'data');
    $pages[$c] = $res['data'];
    $aiv = $res['data']['ai'] ?? null;
    $check($code === 200 && is_array($aiv) && count($aiv['proposals']) === count($payloads[$c]['refs']) && $aiv['today'] === true && $aiv['model_label'] !== '', "page {$c} : data() 200 with ai proposals");
}
$p501 = array_column($pages[OD]['ai']['proposals'], null, 'ref')['501'];
$check(str_contains($p501['note'], 'Piekarnia Kowalczyk i Synowie') && !preg_match('/\bK\d+\b/', $p501['note']) && $p501['label'] === 'Kontakt z klientem'
    && $p501['confidence_label'] !== '' && is_int($p501['id']), 'page : codes revealed to the real names (display only), labels of the assistant');
$check(str_contains($pages[DD]['ai']['summary'], ' ') && !preg_match('/\b[KDPVT]\d+\b/', $pages[DD]['ai']['summary']), 'page : summary revealed');
$previewShot = $call($rt, 1, 'preview', [DD])[1]['data'] ?? null; // aperçu (AI_JSON_OUT) : charge du jour avant les décisions
$check($sum() === $erp0, 'page display : no write');

// --- Statuts : Przyjmij (identique) → accepted, Popraw (modifiée) → edited, Odrzuć → rejected.
$id502 = (int) array_column($pages[OD]['ai']['proposals'], null, 'ref')['502']['id'];
$id503 = (int) array_column($pages[OD]['ai']['proposals'], null, 'ref')['503']['id'];
[$code, $res] = $call($od, 3, 'result', [], ['id_order' => 501, 'result' => $p501['decision'], 'note' => $p501['note']]);
$st = fn(int $id) => $pdo->query("SELECT status, final_decision, decided_by FROM n_ai_routine_proposal WHERE id = {$id}")->fetch();
$check($code === 200 && $st($p501['id'])['status'] === 'accepted' && $st($p501['id'])['final_decision'] === 'contact' && (int) $st($p501['id'])['decided_by'] === 3, 'Przyjmij + Zapisz (same decision and note) → accepted — ' . $code . ' ' . ($res['message'] ?? '') . ' ' . json_encode($st($p501['id'])));
$check(!in_array('501', array_column($res['data']['ai']['proposals'] ?? [], 'ref'), true), 'saved row : proposal gone from the page');
[$code] = $call($od, 3, 'result', [], ['id_order' => 502, 'result' => 'changed', 'note' => 'Data poprawiona w ERP.']);
$check($code === 200 && $st($id502)['status'] === 'edited' && $st($id502)['final_decision'] === 'changed', 'Popraw + Zapisz (other decision / note) → edited');
[$code, $res] = $call($rt, 2, 'mark', [$id503], ['status' => 'rejected']);
$check($code === 403 && $st($id503)['status'] === 'pending', 'Odrzuć without the right to the assistant page : 403');
[$code, $res] = $call($rt, 3, 'mark', [$id503], ['status' => 'rejected']);
$check($code === 200 && $st($id503)['status'] === 'rejected' && $st($id503)['final_decision'] === null, 'Odrzuć → rejected');
[$code] = $call($rt, 3, 'mark', [$id503], ['status' => 'accepted']);
$check($code === 409, 'a decided proposal cannot be decided again (409)');
[$code, $res] = $call($od, 3, 'data');
$check($code === 200 && $res['data']['ai'] === null, 'all proposals decided : no AI block, page as before');
// Délais : « Popraw » puis enregistrement de la décision (identique) → accepted.
$pd = array_column($pages[DD]['ai']['proposals'], null, 'ref')['601'];
[$code] = $call($dd, 3, 'decision', [], ['id_order' => 601, 'decision' => $pd['decision'], 'note' => $pd['note']]);
$check($code === 200 && $st($pd['id'])['status'] === 'accepted', 'Opóźnienia dostaw : decision saved as proposed → accepted');
$pk = array_column($pages[IK]['ai']['proposals'], null, 'ref')['701'];
[$code] = $call($ik, 3, 'result', [], ['id_shipment' => 701, 'result' => 'justified', 'note' => 'Próbki bez faktury.']);
$check($code === 200 && $st($pk['id'])['status'] === 'edited', 'Faktury i KSeF : other result → edited');
$check($smsSent === [], 'no SMS sent by any of this');
// L'IA n'a rien enregistré : les seuls résultats sont ceux des clics humains ci-dessus (utilisateur 3).
$check((int) $pdo->query('SELECT COUNT(*) FROM n_order_check')->fetchColumn() === 2 && (int) $pdo->query('SELECT COUNT(*) FROM n_delivery_delay_check')->fetchColumn() === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM n_invoice_ksef_check')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM n_wizard_done')->fetchColumn() === 0,
    'assistant results : only the 4 human saves');

// --- Réglages (contrôleur réel) : administrateur 200, autres 403.
[$code, $res] = $call($rt, 1, 'state');
$check($code === 200 && $res['data']['enabled'] === true && count($res['data']['assistants']) === 3 && $res['data']['key']['source'] === 'erp_cles' && $res['data']['key']['hint'] === 'ERPC'
    && !str_contains(json_encode($res), 'sk-ant-') && $res['data']['month']['runs'] >= 1 && count($res['data']['recent']) > 0, 'settings state : admin 200 (switches, key source without key, month, recent runs)');
[$code3] = $call($rt, 3, 'state');
[$code2] = $call($rt, 2, 'state');
$check($code3 === 403 && $code2 === 403, 'settings state : Kierownik operacyjny and Piekarz 403');
[$code, $res] = $call($rt, 1, 'save', [], ['enabled' => false, 'model' => 'claude-sonnet-5-5', 'budget_pln' => '75,5', 'usd_pln' => '3.9',
    'assistants' => [OD => ['time' => '6:45', 'on' => true], IK => ['on' => false]]]);
$check($code === 200 && $res['data']['enabled'] === false && $res['data']['model'] === 'claude-sonnet-5-5' && $res['data']['budget_pln'] === 75.5 && $res['data']['usd_pln'] === 3.9
    && $res['data']['assistants'][0]['time'] === '06:45' && $res['data']['assistants'][2]['on'] === false, 'save : switch, model, budget, rate, time 6:45 → 06:45, assistant off');
[$code, $res] = $call($rt, 1, 'save', [], ['budget_pln' => '-5', 'model' => 'gpt', 'assistants' => [OD => ['time' => '25:00']]]);
$check($code === 422 && $params->get('ai.routines_budget_pln', '') === '75.5', 'save : bad budget, model and time refused (422), nothing changed');
[$code] = $call($rt, 3, 'save', [], ['enabled' => true]);
$check($code === 403 && $params->get('ai.routines_enabled', '') === '0', 'save by a non-admin : 403');
$erp1 = $sum();
$n = count($history);
[$code, $res] = $call($rt, 1, 'preview', [DD]);
$check($code === 200 && $res['data']['items'] === 3 && count($history) === $n && $leaks($res['data']['payload']) === [] && $res['data']['est_cost_pln']['claude-sonnet-5-5'] > 0,
    'preview of the day payload : admin 200, anonymized, cost estimate, no API call');
[$code] = $call($rt, 3, 'preview', [DD]);
$check($code === 403, 'preview : non-admin 403');
[$code] = $call($rt, 3, 'runNow', [OD]);
$check($code === 403 && count($history) === $n, 'Uruchom teraz : non-admin 403, no call');
[$code, $res] = $call($rt, 1, 'runNow', [OD]);
$check($code === 200 && $res['data']['run']['status'] === 'skipped_off' && count($history) === $n, 'Uruchom teraz with the master switch off : skipped, no call');
[$code] = $call($rt, 1, 'runNow', ['inny_asystent']);
$check($code === 422, 'Uruchom teraz : assistant outside the pilot refused');
$check($sum() === $erp1, 'preview and settings reads : no write');

// --- Cron : heure passée, jours ouvrés, une fois par jour, une seule reprise après erreur.
$params->setValue('ai.routines_enabled', '1', 1);
$params->setValue('ai.routines_on.invoices_ksef_checked', '1', 1);
$params->setValue('ai.routines_time.orders_day_checked', '06:30', 1);
$pdo->prepare('DELETE FROM n_ai_routine_proposal WHERE day = ?')->execute([$T]);
$pdo->prepare('DELETE FROM n_ai_routine_run WHERE day = ?')->execute([$T]);
$erp2 = $sum();
$check($svc->due($T . ' 05:00:00') === [] && $svc->due($T . ' 06:40:00') === [OD, DD] && $svc->due($T . ' 07:05:00') === [OD, DD, IK], 'due : only assistants whose time has passed');
$wd = (int) date('N', strtotime($T));
$params->setValue('week.production_days', implode(',', array_diff([1, 2, 3, 4, 5, 6, 7], [$wd])), 1);
$check($svc->due($T . ' 07:05:00') === [], 'due : nothing on a non-working day (week.production_days)');
$params->setValue('week.production_days', '1,2,3,4,5,6,7', 1);
$n = count($history);
$out = $svc->cron($T . ' 06:40:00');
$check(array_column($out, 'status') === ['ok', 'ok'] && count($history) === $n + 2 && $svc->cron($T . ' 06:50:00') === [] && count($history) === $n + 2, 'cron 6:40 : two runs ; cron 6:50 : nothing more (once a day)');
$mode = 'http500';
$check(array_column($svc->cron($T . ' 07:10:00'), 'status') === ['error'] && array_column($svc->cron($T . ' 07:20:00'), 'status') === ['error'] && $svc->cron($T . ' 07:30:00') === [],
    'cron : after an error one retry, then nothing until tomorrow');
$mode = 'echo';
$check(in_array(OD, $svc->due($day(1) . ' 06:40:00'), true), 'next day : due again');
$check($sum() === $erp2 && $smsSent === [], 'cron : no write outside n_ai_routine_*, no SMS');

// --- Câblage : routes, cron du déploiement, aucun secret ni URL en dur, aucun envoi dans le code du pilote.
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['controller'] . '::' . $x[1]['method'] : null;
$check($found('GET', '/ajax/settings/ai/routines') === AiRoutinesController::class . '::state' && $found('PUT', '/ajax/settings/ai/routines') === AiRoutinesController::class . '::save'
    && $found('POST', '/ajax/settings/ai/routines/orders_day_checked/run') === AiRoutinesController::class . '::runNow'
    && $found('GET', '/ajax/settings/ai/routines/deliveries_delays_checked/preview') === AiRoutinesController::class . '::preview'
    && $found('POST', '/ajax/ai/proposals/12') === AiRoutinesController::class . '::mark' && str_ends_with((string) $found('GET', '/settings/ai'), '::settings'), 'routes (glob */*Routes.php)');
$deploy = (string) file_get_contents(__DIR__ . '/../../deploy/server.sh');
// Bloc écrit en entier à chaque déploiement (cat > … <<CRON … CRON) : la ligne y est une fois, jamais ajoutée en double.
$from = strpos($deploy, "cat > /etc/cron.d/production-erp-mail <<CRON\n");
$to = $from === false ? false : strpos($deploy, "\nCRON\n", $from);
$block = $from !== false && $to !== false ? substr($deploy, $from, $to - $from) : '';
$check(str_contains($block, "\n*/10 * * * * \${CRON_RUN} Bin/ai_routines.php >> /var/log/production-erp-mail.log 2>&1") && substr_count($deploy, 'Bin/ai_routines.php') === 1
    && str_contains($deploy, 'CRON_RUN="www-data cd '), 'deploy : one cron line every 10 minutes (www-data) inside the cron.d file rewritten at each deployment (idempotent)');
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$files = ['App/Services/Ai/MorningRoutineService.php', 'App/Services/Ai/RoutineAssistants.php', 'App/Services/Ai/Anonymizer.php', 'App/Repositories/Ai/AiRoutineRepository.php',
    'App/Controllers/Web/Settings/AiRoutinesController.php', 'Bin/ai_routines.php', 'public/assets/js/organization/ai-proposals.js', 'public/assets/js/organization/ai-routines.js'];
$src = implode("\n", array_map($strip, $files));
$check(!preg_match('~https?://|sk-ant-|api\.anthropic\.com~', $src), 'pilot code : no URL, no key');
$check(!preg_match('/SmsTransport|SmtpTransport|OutgoingMessage|Mail|Ksef(?!Day)|->send\(|curl_|saveResult|saveDecision|->finish\(|markDone|error_log\([^)]*(payload|body|\$key)/', $src), 'pilot code : no send (SMS, e-mail, KSeF), never saves an assistant result, never logs the payload or the key');
$check(!preg_match('/thinking|temperature|budgetTokens|budget_tokens|cacheControl/', $strip('App/Services/Ai/MorningRoutineService.php')), 'request code : no thinking, temperature, token budget or cache control');
$repoSrc = $strip('App/Repositories/Ai/AiRoutineRepository.php');
preg_match_all('/(?:INSERT INTO|UPDATE|DELETE FROM)\s+`?(\w+)/i', $repoSrc, $m);
$check(array_values(array_unique($m[1])) === ['n_ai_routine_proposal'] && str_contains($repoSrc, "'n_ai_routine_run'"), 'repository : writes only n_ai_routine_run / n_ai_routine_proposal');
foreach (['client/order/day_check.twig', 'logistics/delivery_delays.twig', 'shipping/invoices_ksef_day.twig'] as $v) {
    $t = (string) file_get_contents(__DIR__ . '/../App/Views/' . $v);
    $check(str_contains($t, 'ai-proposals.js') && str_contains($t, 'ai-proposals.css'), "view {$v} : proposals script and style");
}
$tw = (string) file_get_contents(__DIR__ . '/../App/Views/settings/ai.twig');
$check(str_contains($tw, 'aiRoutinesBoot') && str_contains($tw, 'ai-routines.js') && str_contains($tw, "'erp_cles'"), 'settings view : routines section (admin) and erp_cles key source');

if (getenv('AI_JSON_OUT')) {
    file_put_contents((string) getenv('AI_JSON_OUT'), json_encode(['pages' => $pages, 'settings' => $call($rt, 1, 'state')[1]['data'], 'preview' => $previewShot], JSON_UNESCAPED_UNICODE));
}
AiSettings::$clientFactory = null;
Request::setFactory(null);
$prevKey === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $prevKey);
$pdo->exec('DROP DATABASE erp_ai_routines_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
