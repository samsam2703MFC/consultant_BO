<?php
/**
 * [385] Asystent « Dane klientów » : clients actifs = commande non archivée dans les customers_data.active_months derniers
 * mois (client sans commande récente et client à commande archivée exclus, client désactivé qui commande inclus) ;
 * problèmes de la fiche et ce qu'ils cassent : e-mail (aucun, ni principal ni commercial, invalide — validation de la fiche —,
 * dernier envoi à une adresse de la fiche en erreur sans envoi réussi plus récent ; échec vers une ancienne adresse ignoré),
 * téléphone (vide, illisible pour SmsGateway::e164), langue, délai de paiement, NIP vide, VAT UE sans préfixe pour un client
 * livré en WDT (règle identique à KsefWdtInvoiceXmlMapper::splitEuVat, vérifiée par réflexion), adresse incomplète, cennik
 * absent, produit commandé sans prix client, client intégré non contrôlé ; « brak danych » quand une table ou une colonne
 * manque ; décisions (Poprawione refusé tant que la fiche a un problème puis accepté après correction, notes obligatoires,
 * client inactif refusé, décision caduque si un nouveau problème apparaît ou si le problème revient), « Nie dotyczy » d'un
 * mois précédent reprise, étape 3 bloquée, « Zakończ » → n_wizard_done, évolution vs le mois précédent, état de la tâche ;
 * mois suivant ; aucune écriture de l'assistant dans customer ni dans les autres tables d'origine (checksums), aucune donnée
 * personnelle dans n_customers_data_check, aucun SMS (passerelle espionnée) ; contrôleur construit par PHP-DI et réellement
 * appelé (Administracja avec le droit « clients » 200, Piekarz sans droit et non connecté 403, liens vers la fiche) ;
 * enregistrement (catalogue, droits, routes, paramètre, équipe, menu) ; migration 233 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_customers_data_check_test recréée, supprimée à la fin (DK_JSON_OUT=fichier : données de la page, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Client\CustomersDataCheckController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\CustomersDataCheckRepository;
use App\Repositories\Organization\{LightWizardRepository, PageAccessRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Ksef\KsefWdtInvoiceXmlMapper;
use App\Services\Logistics\Sms\SmsGateway;
use App\Services\Organization\{PageAccessService, PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\CustomersDataCheckService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_customers_data_check_test'); $pdo->exec('CREATE DATABASE erp_customers_data_check_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE erp_customers_data_check_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL, name VARCHAR(40) NULL, surname VARCHAR(40) NULL)',
    // customer : colonnes de ClientRepository::$allowedColumns (+ id, type), communication_lang comme la migration 060.
    "CREATE TABLE customer (id INT PRIMARY KEY, name VARCHAR(40) NULL, surname VARCHAR(40) NULL, company VARCHAR(60) NULL, fac_street VARCHAR(80) NULL, fac_street_num VARCHAR(10) NULL,
        fac_zip VARCHAR(10) NULL, fac_town VARCHAR(60) NULL, fac_country VARCHAR(40) NULL, id_country INT NULL, tax_number VARCHAR(30) NULL, active TINYINT DEFAULT 1, type INT DEFAULT 0,
        email_head VARCHAR(255) NULL, email_salesman VARCHAR(255) NULL, email_accountant VARCHAR(255) NULL, phone_head VARCHAR(40) NULL, phone_alt VARCHAR(40) NULL, payment_termin INT NULL,
        franchisee_code VARCHAR(20) NULL, franchisee_uid VARCHAR(40) NULL, franchise_network_id INT NULL, credentials TEXT NULL, default_currency VARCHAR(3) NULL,
        communication_lang CHAR(2) NOT NULL DEFAULT 'fr')",
    'CREATE TABLE country (id INT PRIMARY KEY, name VARCHAR(60))',
    "CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, create_order_date DATETIME, expected_shipping_date DATETIME NULL, status INT DEFAULT 1, order_type VARCHAR(20) DEFAULT 'STANDARD', is_freebie TINYINT DEFAULT 0)",
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton INT, expected_quantity_carton INT NULL, purchase_price DECIMAL(10,2) NULL)',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), standard_price DECIMAL(10,2) NULL, active TINYINT DEFAULT 1)',
    'CREATE TABLE final_product_customer_price (id INT AUTO_INCREMENT PRIMARY KEY, id_customer INT, id_final_product INT, price DECIMAL(10,2) NULL, available TINYINT DEFAULT 1)',
    "CREATE TABLE document_wdt (id INT PRIMARY KEY, id_customer INT, established_date DATE, consignee_nip VARCHAR(30) NULL, currency VARCHAR(3) DEFAULT 'EUR')",
    // communication_message : colonnes de la migration 020.
    "CREATE TABLE communication_message (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_type VARCHAR(64) NOT NULL, id_customer INT UNSIGNED NULL, recipient_email VARCHAR(255) NOT NULL,
        subject_text VARCHAR(255) NOT NULL, body_text MEDIUMTEXT NOT NULL, status ENUM('draft','queued','sent','failed') NOT NULL DEFAULT 'draft', error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL)",
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
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'ADMINISTRACJA', 'Administracja i fakturowanie', 1), (3, 'PIEKARZ', 'Piekarz', 7)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '4', NULL, 'Dane klientów', 'monthly'),
    (1, '3', 'payments_reviewed', NULL, 'daily'), (1, '6', NULL, 'Poczta od klientów', 'daily')");
$m233 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/233_customers_data_check.sql');
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements($m233) as $sql) if (trim($sql) !== '') $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 233 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'customers_data_checked' && $duties[0]['proposed_wizard'] === null, 'migration 233: ADMINISTRACJA 4 → customers_data_checked');
$check($duties[1]['wizard_code'] === 'payments_reviewed' && $duties[2]['wizard_code'] === null && $duties[2]['proposed_wizard'] === 'Poczta od klientów', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'customers_data.%'")->fetchAll(), 'value', 'code');
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customers_data_check'")->fetchColumn();
$check($params === ['customers_data.active_months' => '3'] && $fks === 0 && isset(ParamRules::RANGES['customers_data.active_months']), 'migration replayed: 1 param once, bounded in ParamRules, no foreign key');
$cols = array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customers_data_check' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME');
$check($cols === ['id', 'month_start', 'id_customer', 'decision', 'note', 'flags', 'id_user', 'created_at', 'updated_at'], 'assistant table: only the customer id, problem codes, decision and note (no personal data column)');
$last = array_values(array_filter(MigrationRunner::splitStatements($m233), fn($s) => trim($s) !== ''));
$check(trim(end($last), " \n;") === "UPDATE n_position_duty SET wizard_code = 'customers_data_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Dane klientów'", 'migration: last statement is the duty UPDATE');

// --- Données fictives : contrôle le mar. 24.11.2026 ; fenêtre de 3 mois → depuis le 24.08.2026.
$pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', NULL), (2, 'anna', 'Anna K.'), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO country VALUES (1, 'Polska'), (2, 'Belgia')");
$pdo->exec("INSERT INTO customer (id, company, fac_street, fac_street_num, fac_zip, fac_town, id_country, tax_number, active, email_head, email_salesman, email_accountant, phone_head, phone_alt, payment_termin, franchisee_uid, credentials, communication_lang) VALUES
    (1, 'Sklep A', 'Rue de la Gare', '1', '1000', 'Bruxelles', 2, 'BE0123456789', 1, 'a@example.test', NULL, 'compta-a@example.test', '+32 476 12 34 56', NULL, 14, NULL, NULL, 'fr'),
    (2, 'Sklep B', 'Rue Haute', '5', '4000', 'Liège', 2, 'BE0999999999', 1, '', NULL, 'compta-b@example.test', '', NULL, NULL, NULL, NULL, 'fr'),
    (3, 'Sklep C', 'Grote Markt', '7', '2000', 'Antwerpen', 2, '0123456789', 1, 'sklep-c.example.test', NULL, 'c@example.test', '0476 22 33 44', NULL, 30, NULL, NULL, ''),
    (4, 'Sklep D', '', '', '', '', NULL, '', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'fr'),
    (5, 'Sklep E', 'ul. Długa', '3', '', 'Kraków', 1, '', 1, 'zamowienia@e.example.test', NULL, NULL, '+48 600 100 200', NULL, 7, NULL, NULL, 'pl'),
    (6, 'Sklep F', 'Place Verte', '9', '5000', 'Namur', 2, 'BE0555555555', 0, 'f@example.test', NULL, NULL, 'abc', NULL, 14, 'shop-f-uid', '{\"secret\":\"x\"}', 'fr'),
    (7, 'Sklep G', '', '', '', '', NULL, '', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'fr')");
$pdo->exec("INSERT INTO final_product (id, name, standard_price) VALUES (10, 'Croissant', 10.00), (11, 'Bagietka', 6.00), (12, 'Tarta', 7.00)");
$pdo->exec("INSERT INTO customer_order (id, id_customer, create_order_date, status) VALUES (100, 1, '2026-11-20 08:00:00', 1), (101, 2, '2026-10-02 09:00:00', 2), (102, 3, '2026-11-18 10:00:00', 1),
    (103, 4, '2026-06-01 10:00:00', 2), (104, 5, '2026-09-15 10:00:00', 2), (105, 6, '2026-11-02 10:00:00', 2), (106, 7, '2026-11-03 10:00:00', 5), (107, 3, '2026-09-01 10:00:00', 2)");
$pdo->exec('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (100, 10, 5), (100, 11, 2), (101, 10, 3), (102, 10, 4), (102, 11, 6), (107, 12, 0), (104, 10, 2), (105, 12, 1)');
// Cennik : A complet ; B complet ; C sans Bagietka (prix 0 sur une ligne existante = pas de prix) ; E aucun ; F intégré, aucun.
$pdo->exec('INSERT INTO final_product_customer_price (id_customer, id_final_product, price) VALUES (1, 10, 9.50), (1, 11, 5.50), (2, 10, 9.00), (3, 10, 9.00), (3, 11, 0.00)');
$pdo->exec("INSERT INTO document_wdt (id, id_customer, established_date, consignee_nip) VALUES (1, 1, '2026-11-21', 'BE0123456789'), (2, 2, '2026-10-03', 'BE0999999999'), (3, 3, '2026-11-19', '0123456789'), (4, 4, '2026-06-02', '')");
// E-mails : E — envoi réussi le 1.10 puis échec le 10.11 vers l'adresse de la fiche ; échec vers une ancienne adresse (ignoré) ;
// A — échec le 1.11 puis réussite le 5.11 (réglé).
$pdo->exec("INSERT INTO communication_message (message_type, id_customer, recipient_email, subject_text, body_text, status, error_message, created_at) VALUES
    ('proforma', 5, 'zamowienia@e.example.test', 'Proforma 1', 'treść', 'sent', NULL, '2026-10-01 08:00:00'),
    ('payment_reminder', 5, 'Zamowienia@e.example.test', 'Przypomnienie TAJNE', 'treść', 'failed', 'Mailbox unavailable SECRET', '2026-11-10 08:00:00'),
    ('proforma', 5, 'stary@e.example.test', 'Proforma 0', 'treść', 'failed', '550', '2026-11-11 08:00:00'),
    ('proforma', 1, 'compta-a@example.test', 'Proforma 2', 'treść', 'failed', '550', '2026-11-01 08:00:00'),
    ('proforma', 1, 'compta-a@example.test', 'Proforma 3', 'treść', 'sent', NULL, '2026-11-05 08:00:00')");
// Mois précédent : validé avec 3 clients à manques ; « Nie dotyczy » d'octobre pour le téléphone de F.
$pdo->exec("INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES ('customers_data_checked', '2026-10-28', 'październik 2026 : 4 aktywni klienci (3 mies.) · 1 z kompletem danych · 3 z brakami', 2, '2026-10-28 10:00:00')");
$pdo->exec("INSERT INTO n_customers_data_check (month_start, id_customer, decision, note, flags, id_user) VALUES ('2026-10-01', 6, 'not_applicable', 'Sklep zamawia przez integrację, SMS nie używamy', 'phone_invalid', 2)");
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'clients'), (1, 'price_lists'), (3, 'production')");

$sources = ['customer', 'country', 'customer_order', 'customer_order_product', 'final_product', 'final_product_customer_price', 'document_wdt', 'communication_message',
    'user', 'n_prod_param', 'n_page_access', 'n_user_position', 'n_position', 'n_position_duty'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $sources));

// Passerelle SMS espionnée : aucun appel réseau ne doit partir (e164 seulement).
$smsCalls = 0;
SmsGateway::$http = function () use (&$smsCalls) { $smsCalls++; return [500, null]; };
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_customers_data_check_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$container = $b->build();
$sms = $container->get(SmsGateway::class);
$make = fn() => new CustomersDataCheckService(new CustomersDataCheckRepository($pdo), new LightWizardRepository($pdo), new ParamRepository($pdo), $sms);
$svc = $make();
$today = '2026-11-24';
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

// --- Règle VAT UE identique au mapper KSeF (méthode privée lue par réflexion)
$mapper = new KsefWdtInvoiceXmlMapper();
$split = new ReflectionMethod($mapper, 'splitEuVat');
$split->setAccessible(true);
$same = true;
foreach (['BE0123456789', 'be 0123.456.789', 'PL1234567890', '0123456789', 'DE12', 'B', '', 'FR12345678901', 'NL-123456789B01', 'XX123456789012345'] as $vat) {
    try { $split->invoke($mapper, $vat); $ksef = true; } catch (InvalidArgumentException) { $ksef = false; }
    if ($ksef !== CustomersDataCheckService::euVat($vat)) { $same = false; echo "  diff: {$vat}\n"; }
}
$check($same, 'VAT UE rule = KsefWdtInvoiceXmlMapper::splitEuVat on 10 numbers');

// --- Étape 1
$erp0 = $sum();
$d = $svc->data($today);
$boot = $d;
$by = array_column($d['items'], null, 'id_customer');
$codes = fn(int $id) => $by[$id]['codes'];
$ids = array_keys($by);
sort($ids);
$check($d['since'] === '2026-08-24' && $d['month_start'] === '2026-11-01' && $ids === [1, 2, 3, 5, 6], 'active = order in 3 months: 1, 2, 3, 5, 6 ; D (June) and G (archived order only) excluded — ' . implode(',', $ids));
$check($codes(1) === [], 'A complete (failed e-mail followed by a successful one ; EU VAT ; price list complete)');
$check($codes(2) === ['email_notice_missing', 'phone_missing', 'payment_missing'], 'B: only accountant e-mail, no phone, no payment term — ' . implode(',', $codes(2)));
$check($codes(3) === ['email_invalid', 'lang_missing', 'vat_eu_invalid', 'price_missing'], 'C: invalid e-mail, no language, VAT without country prefix (WDT client), ordered product without price — ' . implode(',', $codes(3)));
$check($codes(5) === ['email_failed', 'tax_missing', 'address_missing', 'price_list_missing'], 'E: last e-mail to the card address failed, no NIP, no zip code, no price list — ' . implode(',', $codes(5)));
$check($codes(6) === ['phone_invalid'] && $by[6]['integrated'] && !$by[6]['card_active'], 'F: unreadable phone ; integrated shop → price list not checked ; inactive in the ERP but ordering → checked');
$p = fn(int $id, string $c) => array_column($by[$id]['problems'], null, 'code')[$c];
$check(str_contains($p(5, 'email_failed')['detail'], 'zamowienia@e.example.test') && str_contains($p(5, 'email_failed')['detail'], 'przypomnienie płatności') && !str_contains(json_encode($d), 'stary@'),
    'E: failure on the card address shown (type, day) ; failure to an old address ignored');
$check($p(3, 'email_invalid')['detail'] === 'E-mail właściciela : sklep-c.example.test' && $p(3, 'price_missing')['detail'] === '1 produkt zamówiony bez ceny : Bagietka'
    && $p(5, 'address_missing')['detail'] === 'brak : kod pocztowy' && str_contains($p(3, 'vat_eu_invalid')['detail'], 'klient z WDT'), 'details: invalid address, product without price, missing address part, WDT reason');
$check($p(3, 'vat_eu_invalid')['breaks'] === CustomersDataCheckService::PROBLEMS['vat_eu_invalid'][1] && str_contains($p(5, 'tax_missing')['breaks'], 'consignee_nip')
    && $p(3, 'price_missing')['link']['href'] === '/clients/3/price-list' && $p(5, 'tax_missing')['link']['href'] === '/clients/5/edit#tax_number', 'each problem says what it breaks and links to the card / price list');
$check($by[6]['earlier']['code'] === 'not_applicable' && !$by[6]['waiting'] && $by[2]['waiting'] && $by[3]['waiting'] && $by[5]['waiting'], 'F covered by October « Nie dotyczy » (same problem) ; B, C, E waiting');
$t = $d['totals'];
$check($t['active'] === 5 && $t['complete'] === 1 && $t['incomplete'] === 4 && $t['review'] === 4 && $t['waiting'] === 3 && $t['earlier'] === 1 && $t['integrated'] === 1 && $t['inactive_flag'] === 1
    && $d['can_finish'] === false && $t['by_code']['phone_missing'] === 1, 'totals');
$check($d['previous']['incomplete'] === 3 && $d['previous']['complete'] === 1 && $d['previous']['delta'] === 1, 'evolution: October 3 incomplete → now 4 (+1)');
$json = json_encode($d, JSON_UNESCAPED_UNICODE);
$check(!str_contains($json, 'shop-f-uid') && !str_contains($json, 'secret') && !str_contains($json, 'TAJNE') && !str_contains($json, 'SECRET') && !str_contains($json, 'Sklep D') && !str_contains($json, 'Sklep G'),
    'page data: no integration id, credentials, e-mail subject or error text ; inactive clients absent');
$check($erp0 === $sum() && $smsCalls === 0, 'reading writes nothing in the source tables ; no SMS call');

// --- Sources absentes : table e-mails, colonne payment_termin
$pdo->exec('RENAME TABLE communication_message TO dk_hidden');
$e = $make()->data($today);
$check(!$e['sources']['mails']['available'] && str_starts_with((string) $e['sources']['mails']['note'], 'brak danych') && !in_array('email_failed', array_column($e['items'], null, 'id_customer')[5]['codes'], true),
    'no communication_message table → « brak danych », no e-mail failure signal');
$pdo->exec('RENAME TABLE dk_hidden TO communication_message');
$pdo->exec('ALTER TABLE customer CHANGE payment_termin dk_hidden_term INT NULL');
$e = $make()->data($today);
$check(!$e['sources']['card']['payment']['available'] && str_contains((string) $e['sources']['card']['payment']['note'], 'customer.payment_termin')
    && !in_array('payment_missing', array_column($e['items'], null, 'id_customer')[2]['codes'], true), 'missing column customer.payment_termin → check skipped, « brak danych »');
$pdo->exec('ALTER TABLE customer CHANGE dk_hidden_term payment_termin INT NULL');
$check($erp0 === $sum(), 'fixture restored');

// --- Réglage
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'customers_data.active_months'");
$ids1 = array_column($make()->data($today)['items'], 'id_customer'); sort($ids1);
$check($ids1 === [1, 3, 6], 'active_months = 1 → only clients who ordered since 24.10 — ' . implode(',', $ids1));
$pdo->exec("UPDATE n_prod_param SET value = '99' WHERE code = 'customers_data.active_months'");
$check($make()->param('months') === 3, 'out of ParamRules bounds → default 3');
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'customers_data.active_months'");

// --- Krok 2 : decyzje
$dec = fn(int $id, string $decision, string $note = '', array $extra = [], string $at = '2026-11-24') => $svc->saveDecision(['id_customer' => $id, 'decision' => $decision, 'note' => $note] + $extra, 2, $at);
$check(str_starts_with($err(fn() => $dec(2, 'drop')), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $dec(2, 'fixed')), 'custom:422:Karta klienta nadal ma : brak e-maila głównego i handlowca, brak telefonu, brak terminu płatności'), '« Poprawione » refused while the card still has problems');
$check(str_starts_with($err(fn() => $dec(2, 'no_data', ' ')), 'custom:422:Brak danych od klienta : wpisz'), 'no_data needs a note');
$check(str_starts_with($err(fn() => $dec(3, 'not_applicable', 'x')), 'custom:422:Nie dotyczy : wpisz'), 'not_applicable needs a note');
$check(str_starts_with($err(fn() => $dec(4, 'no_data', 'Prosiliśmy')), 'custom:422:Tego klienta nie ma') && str_starts_with($err(fn() => $dec(1, 'no_data', 'Prosiliśmy')), 'custom:422:Karta klienta jest już kompletna'),
    'inactive client refused ; complete client cannot get « brak danych »');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 3 klientów'), 'finish blocked while clients wait');
$check((int) $pdo->query('SELECT COUNT(*) FROM n_customers_data_check')->fetchColumn() === 1, 'refused decisions write nothing');
$erp1 = $sum();
$dec(2, 'no_data', 'Poproszono o e-mail i telefon 20.11');
$row = $pdo->query('SELECT * FROM n_customers_data_check WHERE id_customer = 2')->fetch();
$check($row['decision'] === 'no_data' && $row['month_start'] === '2026-11-01' && $row['flags'] === 'email_notice_missing,phone_missing,payment_missing' && (int) $row['id_user'] === 2,
    'no_data saved: month, problem codes only, author');
$st = $tasks->state(CustomersDataCheckService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 klient wyjaśniony', 'task state: in progress (1 decision this month)');
$d = $dec(3, 'not_applicable', 'Klient polski, WDT historyczne — od grudnia faktura krajowa');
$check($erp1 === $sum(), 'decisions write nothing in customer or the other source tables');
// Nouveau problème après la décision : la décision ne couvre plus → C attend de nouveau.
$pdo->exec('UPDATE customer SET payment_termin = NULL WHERE id = 3');
$x = array_column($make()->data($today)['items'], null, 'id_customer')[3];
$check($x['waiting'] && $x['reason'] === 'nowy problem po decyzji' && $x['decision']['code'] === 'not_applicable', 'new problem after « Nie dotyczy » → waiting again');
$pdo->exec('UPDATE customer SET payment_termin = 30 WHERE id = 3');
// L'utilisateur corrige la fiche de B dans Klienci (écriture du test, pas de l'assistant) : « Poprawione » accepté.
$pdo->exec("UPDATE customer SET email_head = 'b@example.test', phone_head = '+32 470 00 00 00', payment_termin = 14 WHERE id = 2");
$erp2 = $sum();
$d = $dec(2, 'fixed', '', ['seen' => ['email_notice_missing', 'phone_missing', 'payment_missing', 'bogus']]);
$row = $pdo->query('SELECT * FROM n_customers_data_check WHERE id_customer = 2')->fetch();
$b2 = array_column($d['items'], null, 'id_customer')[2];
$check($row['decision'] === 'fixed' && $row['flags'] === 'email_notice_missing,phone_missing,payment_missing' && $b2['in_review'] && !$b2['waiting'] && $b2['problems'] === [],
    '« Poprawione » accepted once the card has no problem ; seen codes filtered ; B stays on the list as fixed');
// Le problème revient : « Poprawione » ne couvre plus.
$pdo->exec("UPDATE customer SET phone_head = '' WHERE id = 2");
$x = array_column($make()->data($today)['items'], null, 'id_customer')[2];
$check($x['waiting'] && $x['reason'] === 'problem wrócił po « Poprawione »', 'problem back after « Poprawione » → waiting');
$pdo->exec("UPDATE customer SET phone_head = '+32 470 00 00 00' WHERE id = 2");
$d = $dec(5, 'no_data', 'Czekamy na NIP i kod pocztowy od klienta');
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && [$d['totals']['fixed'], $d['totals']['no_data'], $d['totals']['not_applicable']] === [1, 1, 2]
    && $d['totals']['incomplete'] === 3 && $d['previous']['delta'] === 0, 'all decided → step 3 open (F « Nie dotyczy » from October counted) ; 3 incomplete = October');
$after = $d;
$r = $svc->finish(2, $today);
$wdn = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'customers_data_checked' AND day = '2026-11-24'")->fetchAll();
$check($r['summary'] === 'listopad 2026 : 5 aktywnych klientów (3 mies.) · 2 z kompletem danych · 3 z brakami · decyzje : 1 poprawione · 1 brak danych od klienta · 2 nie dotyczy'
    && count($wdn) === 1 && $r['data']['done'] !== null, 'finish: summary, n_wizard_done — ' . $r['summary']);
$check(CustomersDataCheckService::parseSummary($r['summary']) === ['active' => 5, 'complete' => 2, 'incomplete' => 3], 'summary readable next month (evolution)');
$check($tasks->state(CustomersDataCheckService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', $today)['done'] === true
    && $tasks->state(CustomersDataCheckService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-02')['done'] === false, 'task state: done this month, open next month');
$check($erp2 === $sum() && $smsCalls === 0, 'decisions and finish write nothing in the source tables ; no SMS');
$n = $make()->data('2026-12-01');
$nx = array_column($n['items'], null, 'id_customer');
$check($nx[5]['waiting'] && !$nx[3]['waiting'] && $nx[3]['earlier']['code'] === 'not_applicable' && !$nx[6]['waiting'] && !$nx[2]['in_review'] && $n['previous']['incomplete'] === 3,
    'next month: « brak danych » comes back (E), « Nie dotyczy » with the same problems does not (C, F), fixed B gone ; evolution from November');
$n1 = $nx[1];
$check($n1['codes'] === [] && (int) $pdo->query("SELECT COUNT(*) FROM n_customers_data_check WHERE flags REGEXP '[@ ]'")->fetchColumn() === 0, 'assistant table: no address, phone or name stored');

// --- Contrôleur : construit par PHP-DI, data(), decision() et finish() réellement appelés.
$ctl = $container->get(CustomersDataCheckController::class);
$call = function (?int $user, string $method) use ($ctl) {
    if ($user === null) unset($_SESSION['USER']); else $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user];
    $res = $ctl->$method();
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
$i2 = array_column($b2['data']['items'] ?? [], null, 'id_customer');
$check($c2 === 200 && $b2['data']['today'] === date('Y-m-d') && ($i2[3]['link']['href'] ?? null) === '/clients/3/edit'
    && (array_column($i2[3]['problems'] ?? [], null, 'code')['price_missing']['link']['href'] ?? null) === '/clients/3/price-list', 'controller data() (Administracja, right « clients ») : 200, real day, links to the client card and price list');
[$c1] = $call(1, 'data');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
[$cN] = $call(null, 'data');
$check($c1 === 200 && $c4 === 403 && $b4['message'] === 'Brak dostępu do klientów (asystent « Dane klientów »).' && $cD === 403 && $cF === 403 && $cN === 403,
    'controller: administrator 200 ; Piekarz without « clients » (and not logged in) → 403 for data(), decision() and finish()');
[$cDe, $bDe] = $call(2, 'decision');
$check($cDe === 422 && str_starts_with((string) $bDe['message'], 'Wybierz decyzję'), 'controller decision() really called : empty body → 422');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() really called : done or blocked by waiting clients');
$check($erp3 === $sum() && $smsCalls === 0, 'controller calls write nothing in the source tables ; no SMS');
unset($_SESSION['USER']);
$pa = new PageAccessService(new PageAccessRepository($pdo));
$check(array_map(fn($u) => $pa->canSee($u, CustomersDataCheckService::PATH), [1, 2, 4]) === [true, true, false]
    && array_map(fn($u) => $pa->canSee($u, '/clients/3/edit'), [1, 2, 4]) === [true, true, false], 'page guard = the « clients » right of the client card');

// --- Aucune écriture dans la fiche, aucun envoi : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/CustomersDataCheckService.php', 'App/Repositories/Client/CustomersDataCheckRepository.php',
    'App/Controllers/Web/Client/CustomersDataCheckController.php', 'public/assets/js/client/customers-data-check.js']));
$check(!preg_match('/UPDATE\s+(customer|final_product|communication)|INSERT\s+INTO\s+(customer|final_product|communication|n_wizard)|DELETE\s+FROM|->send\(|ClientService\s*\$|curl_|fsockopen|checkdnsrr|getmxrr|https?:\/\/|SELECT \*|credentials/i', $src),
    'no write to customer / price lists / messages, no send, no DNS or network check, no hard-coded URL, no SELECT *, credentials never read');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('customers_data_checked')['link'] === '/clients/data-check' && TaskWizards::byLink('/clients/data-check') === 'customers_data_checked'
    && isset(TaskWizards::AUTO['customers_data_checked']) && PositionTaskService::LINK_AUTO['/clients/data-check'] === 'customers_data_checked', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['customers_data_checked'][0] === 'Dane klientów (kompletność)' && TaskWizards::CATALOG['customers_data_checked'][0] !== 'Dane klientów', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/clients/data-check') === 'clients' && PageAccess::keyFor('/ajax/clients/data-check') === 'clients' && PageAccess::keyFor('/ajax/clients/data-check/finish') === 'clients'
    && PageAccess::keyFor('/clients/12/edit') === 'clients' && PageAccess::keyFor('/ajax/clients/vies-lookup') === null && PageAccess::keyFor('/price-lists/general') === 'price_lists',
    'page and ajax under the existing « clients » right ; other paths unchanged');
$check(!isset(PageAccess::PAGES['customers_data']), 'no new right for this assistant (existing « clients »)');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'customers_data_checked' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/clients/data-check'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Client/CustomersDataCheckRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/clients/data-check') === 'index' && $found('GET', '/ajax/clients/data-check') === 'data'
    && $found('POST', '/ajax/clients/data-check/decision') === 'decision' && $found('POST', '/ajax/clients/data-check/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/clients/data-check')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/clients/data-check')[1]['controller'] === CustomersDataCheckController::class
    && $allR->dispatch('GET', '/clients/12/edit')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/clients/new')[0] === FastRoute\Dispatcher::FOUND
    && $allR->dispatch('GET', '/clients/12/price-list')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob ; existing client routes unchanged');

SmsGateway::$http = null;
if (getenv('DK_JSON_OUT')) file_put_contents((string) getenv('DK_JSON_OUT'), json_encode(['before' => $boot, 'after' => $after], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_customers_data_check_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
