<?php
/**
 * [398] Asystent « Intrastat miesiąca » : mois examiné (le précédent) ; wywóz = lignes des WDT du mois (date de wysyłka sinon
 * d'établissement, WDT remplacées exclues, société = vendeur du document, VAT UE du destinataire sinon de la fiche client,
 * masse = poids des cartons du document sinon masse × PPC) ; przywóz = lignes des factures d'achat d'un vendeur au VAT UE
 * (préfixes VIES ; NIP polonais ignoré ; devise étrangère sans VAT UE signalée) ; regroupement code CN × pays × VAT ; kursy et
 * codes CN saisis dans l'assistant (8 chiffres), masse par unité pour un surowiec hors g / kg ; seuils des réglages (0 = non
 * défini) et somme de l'année ; décisions (uzupełnione seulement sans signal, enregistrée seule après un code ou un kurs,
 * nie dotyczy avec note, déclaration zgłoszona avec numéro — bloquée tant qu'une ligne attend — ou poniżej progu) ; CSV ;
 * « Zakończ » → n_wizard_done, état de la tâche ; mois suivant ; aucune écriture dans les tables de l'ERP (checksums) ;
 * contrôleur construit par PHP-DI et appelé (droit « intrastat » 200, sans droit 403, CSV) ; enregistrement (catalogue,
 * droits, routes, réglages, équipe) ; migration 239 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_intrastat_month_test recréée (tables minimales de l'ERP avec les colonnes que lit l'assistant, n_inv / n_inv_line
 * de docs/db/n_schema.sql, migration 239), supprimée à la fin (IM_JSON_OUT=fichier : données de la page avant les décisions).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Shipping\IntrastatMonthController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\{IntrastatMonthRepository, ParamRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\IntrastatMonthService as S;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_intrastat_month_test'); $pdo->exec('CREATE DATABASE erp_intrastat_month_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_intrastat_month_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL, tax_number VARCHAR(30) NULL, id_country INT NULL)',
    'CREATE TABLE erp_company_data (id INT PRIMARY KEY, wdt_company_name VARCHAR(120), wdt_company_nip VARCHAR(30))',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), weight INT NULL, ppc INT NULL, active TINYINT DEFAULT 1)',
    "CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40), id_customer INT, consignor_name VARCHAR(120), consignor_nip VARCHAR(30), consignee_name VARCHAR(120), consignee_nip VARCHAR(30),
        established_date DATE NULL, invoiced_date DATE NULL, shipping_date DATE NULL, total_net DECIMAL(12,2) NULL, currency VARCHAR(3) DEFAULT 'EUR')",
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT, name TEXT NULL, quantity DECIMAL(10,2), price_carton DECIMAL(10,2),
        total_net DECIMAL(12,2) NULL, weight_carton DECIMAL(12,3) NULL, weight_carton_total DECIMAL(12,3) NULL, ppc INT NULL)',
    'CREATE TABLE n_wdt_superseded (id_document_wdt INT PRIMARY KEY)',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80), email VARCHAR(120) NULL, nip VARCHAR(20) NULL)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), id_unit INT NULL)',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), kind VARCHAR(20) DEFAULT 'erp', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_task_category (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20))',
    "CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_category INT NULL, title_pl VARCHAR(255), title_fr VARCHAR(255), frequency VARCHAR(20),
        minutes INT NULL, month_day VARCHAR(8) NULL, link_path VARCHAR(255) NULL, auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
    'CREATE TABLE n_position_menu (id_position INT, item VARCHAR(40), open_path VARCHAR(160) NULL, updated_by INT NULL, PRIMARY KEY (id_position, item))',
] as $sql) $pdo->exec($sql);
// n_inv et n_inv_line exactement comme docs/db/n_schema.sql (schéma final des tables n_), sans les clés étrangères vers l'ERP.
$schema = (string) file_get_contents(__DIR__ . '/../docs/db/n_schema.sql');
foreach (['n_inv', 'n_inv_line'] as $t) {
    preg_match('/CREATE TABLE `' . $t . '` \(.*?\) ENGINE=[^;]*;/s', $schema, $m);
    $pdo->exec(preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY [^\n]*/', '', $m[0]));
}
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2), (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (8, 'ADMIN')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '6.5', NULL, 'Intrastat miesiąca', 'monthly'),
    (2, '2.1', NULL, 'PPWR i opakowania (kompletność)', 'monthly'), (4, '9', NULL, 'Intrastat miesiąca', 'monthly'), (2, '3.5', 'complaints_reviewed', NULL, 'weekly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 7)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (4, 'clients')");
$pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (2, 'time')");
$m239 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/239_intrastat_month.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m239 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 239 (deux passages)
$duties = $pdo->query('SELECT wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0] === ['wizard_code' => 'intrastat_month_reviewed', 'proposed_wizard' => null] && $duties[1]['proposed_wizard'] === 'PPWR i opakowania (kompletność)'
    && $duties[2]['proposed_wizard'] === 'Intrastat miesiąca' && $duties[3]['wizard_code'] === 'complaints_reviewed', 'migration 239 : KIEROWNIK_PRODUKCJI 6.5 → intrastat_month_reviewed ; other rows and positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'intrastat_month_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'monthly' && $tasks[0]['month_day'] === 'wd5' && $tasks[0]['link_path'] === '/intrastat/month'
    && (int) $tasks[0]['id_category'] === 8 && (int) $tasks[0]['sort_order'] === 8, 'one monthly task for Kierownik produkcji (category « Administracja »)');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'intrastat,time', 4 => 'clients'] && $pdo->query('SELECT GROUP_CONCAT(item) FROM n_position_menu')->fetchColumn() === 'time', 'right « intrastat » for Kierownik produkcji only ; no menu entry');
$params = $pdo->query("SELECT code, CONCAT(value, '|', value_type, '|', IFNULL(unit, '')) FROM n_prod_param WHERE code LIKE 'intrastat.%' ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($params === ['intrastat.delivery_terms' => '|string|', 'intrastat.threshold_arrival_kpln' => '0|decimal|tys. zł', 'intrastat.threshold_dispatch_kpln' => '0|decimal|tys. zł',
    'intrastat.transaction_nature' => '|string|', 'intrastat.transport_mode' => '|string|'], 'settings seeded once : thresholds 0 (not set), texts empty — no legal value written');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_intrastat_code', 'n_intrastat_rate', 'n_intrastat_review')")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_intrastat%'")->fetchColumn();
$check($tables === 3 && $fks === 0 && ParamRules::RANGES['intrastat.threshold_dispatch_kpln'] === [0, 1000000, false], '3 tables, no foreign key ; ParamRules bounds');

// --- Règles pures
$check(S::euVat('BE 0123.456.789') === ['BE', 'BE0123456789'] && S::euVat('EL123456789') === ['GR', 'EL123456789'] && S::euVat('PL5250001009') === null && S::euVat('5250001009') === null
    && S::euVat('') === null, 'partner VAT : KSeF WDT rule (prefix other than PL), EL → country GR');
$check(S::sellerOrigin('DE811111111') === 'eu' && S::sellerOrigin('5250001009') === 'pl' && S::sellerOrigin('PL 525-000-10-09') === 'pl' && S::sellerOrigin('CHE-123.456.789') === 'unknown'
    && S::sellerOrigin('GB123456789') === 'unknown' && S::sellerOrigin(null) === 'unknown', 'seller origin : VIES EU prefix, Polish NIP, other');
$check(S::normalizeCn('1905 90 60') === '19059060' && S::normalizeCn('1905.90.60') === '19059060' && S::normalizeCn('') === '' && S::normalizeCn('1905906') === null && S::normalizeCn('19059060A') === null,
    'CN code : exactly 8 digits');
$check(S::reviewedMonth('2026-11-24') === '2026-10-01' && S::reviewedMonth('2027-01-05') === '2026-12-01', 'reviewed month : the previous one');

$lw = new LightWizardRepository($pdo);
$make = fn() => new S(new IntrastatMonthRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';

// --- Sans données
$e = $svc->data($today);
$check($e['review'] === [] && $e['declarations'] === [] && $e['can_finish'] && $e['company'] === null && $e['sources']['company']['available'] === false
    && $e['summary'] === 'październik 2026 : brak wywozu i przywozu UE w ERP' && $e['settings'] === ['threshold_dispatch' => null, 'threshold_arrival' => null, 'transaction_nature' => null,
        'delivery_terms' => null, 'transport_mode' => null], 'no documents : nothing to declare ; company unknown ; settings not set');
$pdo->exec('RENAME TABLE document_wdt TO dw_x');
$check($make()->data($today)['sources']['wdt']['note'] === 'brak danych — brak tabeli WDT : wywóz nieznany', 'missing WDT table → « brak danych »');
$pdo->exec('RENAME TABLE dw_x TO document_wdt');

// --- Données fictives : contrôle le mar. 24.11.2026 → październik 2026.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO erp_company_data VALUES (1, 'Piekarnia Testowa Sp. z o.o.', '777-000-11-12')");
$pdo->exec("INSERT INTO customer (id, company, tax_number) VALUES (1, 'Boulangerie Dupont', 'BE0123456789'), (2, 'Bäckerei Schmidt', ''), (3, 'Café Marie', 'FR12345678901'), (4, 'Bistro Kowal', NULL)");
$pdo->exec("INSERT INTO final_product (id, name, weight, ppc) VALUES (20, 'Bagietka', 250, 18), (21, 'Croissant maślany', 65, 24), (22, 'Tarta cytrynowa', NULL, NULL)");
$w = $pdo->prepare("INSERT INTO document_wdt (id, doc_num, id_customer, consignor_name, consignor_nip, consignee_name, consignee_nip, established_date, shipping_date, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$sl = ['Piekarnia Testowa Sp. z o.o.', '7770001112'];
foreach ([[101, 'WDT/10/001', 1, ...$sl, 'Boulangerie Dupont', 'BE0123456789', '2026-10-05', '2026-10-06', 'EUR'], [102, 'WDT/10/002', 2, ...$sl, 'Bäckerei Schmidt', 'DE 123456789', '2026-10-14', null, 'EUR'],
    [103, 'WDT/10/003', 4, ...$sl, 'Bistro Kowal', '', '2026-10-20', '2026-10-20', 'eur'], [104, 'WDT/10/004', 3, ...$sl, 'Café Marie', '', '2026-10-22', '2026-10-22', 'PLN'],
    [105, 'WDT/10/005', 1, ...$sl, 'Boulangerie Dupont', 'BE0123456789', '2026-10-23', '2026-10-23', 'EUR'], [106, 'WDT/09/010', 1, ...$sl, 'Boulangerie Dupont', 'BE0123456789', '2026-09-15', '2026-09-15', 'EUR'],
    [107, 'WDT/11/001', 1, ...$sl, 'Boulangerie Dupont', 'BE0123456789', '2026-11-03', '2026-11-03', 'EUR'], [108, 'WDT/09/011', 1, ...$sl, 'Boulangerie Dupont', 'BE0123456789', '2026-09-30', '2026-10-02', 'EUR'],
    [109, 'WDT/A/001', 1, 'Atelier Testowe Sp. z o.o.', '999-000-22-23', 'Boulangerie Dupont', 'BE0123456789', '2026-10-09', '2026-10-09', '']] as $r) $w->execute($r);
$pdo->exec('INSERT INTO n_wdt_superseded VALUES (105)');
$lp = $pdo->prepare('INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton, weight_carton_total) VALUES (?, ?, ?, ?, ?, ?)');
foreach ([[101, 20, 'Bagietka', 10, 40, 45], [101, 21, 'Croissant', 5, 30, 0], [102, 20, 'Bagietka', 4, 40, 18], [102, 22, 'Tarta', 2, 25, null], [103, 21, 'Croissant', 3, 30, 4.68],
    [104, 20, 'Bagietka', 2, 170, 9], [105, 20, 'Bagietka', 50, 40, 225], [106, 20, 'Bagietka', 10, 40, 45], [107, 20, 'Bagietka', 6, 40, 27], [108, 20, 'Bagietka', 1, 40, 4.5],
    [109, 21, 'Croissant', 4, 30, 6.24]] as $r) $lp->execute($r);
$pdo->exec("INSERT INTO unit VALUES (1, 'g'), (2, 'szt'), (3, 'ml')");
$pdo->exec("INSERT INTO supplier VALUES (1, 'Młyn Krajowy', NULL, '5250001009'), (2, 'Beurre SA', NULL, 'FR40303265045')");
$pdo->exec("INSERT INTO raw_material VALUES (30, 'Mąka typ 550', 1), (31, 'Masło AOP 250 g', 2), (32, 'Drożdże świeże', 1)");
$inv = $pdo->prepare("INSERT INTO n_inv (id, source, number, issue_date, currency, seller_nip, seller_name, id_supplier, status) VALUES (?, 'pdf', ?, ?, ?, ?, ?, ?, 'accepted')");
foreach ([[201, 'RE-2026-0815', '2026-10-08', 'EUR', 'DE811111111', 'Mühle Test GmbH', null], [202, 'FV/10/77', '2026-10-09', 'PLN', '5250001009', 'Młyn Krajowy', 1],
    [203, 'CH-4411', '2026-10-12', 'CHF', '', 'Alpen Test AG', null], [204, 'F-889', '2026-10-15', 'EUR', null, null, 2], [205, 'IT-77', '2026-09-10', 'EUR', 'IT01234567890', 'Forno Test Srl', null]] as $r) $inv->execute($r);
$il = $pdo->prepare('INSERT INTO n_inv_line (id_inv, line_no, name, quantity, unit, unit_price, net_value, id_raw_material, factor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[201, 1, 'Mehl Type 550', 500, 'kg', 0.6, 300, 30, 1000], [201, 2, 'Hefe frisch', 20, 'kg', 2, 40, null, null], [202, 1, 'Mąka', 1000, 'kg', 2.1, 2100, 30, 1000],
    [203, 1, 'Schokolade', 10, 'kg', 12, 120, null, null], [204, 1, 'Beurre AOP', 100, 'szt', 2.5, 250, 31, 1], [205, 1, 'Farina', 100, 'kg', 1, 100, 30, 1000]] as $r) $il->execute($r);
$pdo->exec("INSERT INTO n_intrastat_rate (month_start, currency, rate) VALUES ('2026-09-01', 'EUR', 4.30)");
$pdo->exec("INSERT INTO n_intrastat_code (item_kind, item_id, cn_code) VALUES ('product', 20, '19059060')");
$pdo->exec("UPDATE n_prod_param SET value = '3000' WHERE code = 'intrastat.threshold_dispatch_kpln'");
$pdo->exec("UPDATE n_prod_param SET value = '-' WHERE code = 'intrastat.transaction_nature'");
$pdo->exec("UPDATE n_prod_param SET value = 'DAP' WHERE code = 'intrastat.delivery_terms'");

$erpTables = ['document_wdt', 'document_wdt_product', 'n_wdt_superseded', 'n_inv', 'n_inv_line', 'customer', 'final_product', 'raw_material', 'supplier', 'unit', 'erp_company_data', 'user'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$svc = $make(); // réglages relus (ParamRepository garde les valeurs lues)
$d = $svc->data($today);
$boot = $d;
$check($d['month_start'] === '2026-10-01' && $d['month_end'] === '2026-10-31' && $d['company'] === ['nip' => '777-000-11-12', 'name' => 'Piekarnia Testowa Sp. z o.o.']
    && $d['settings'] === ['threshold_dispatch' => 3000000.0, 'threshold_arrival' => null, 'transaction_nature' => null, 'delivery_terms' => 'DAP', 'transport_mode' => null]
    && $d['currencies'] === [['currency' => 'EUR', 'rate' => null]], 'month, ERP company, settings (« - » = not set, threshold in thousands of zł), currencies of the month');
$decls = array_column($d['declarations'], null, 'key');
$check(array_keys($decls) === ['decl:7770001112:dispatch', 'decl:9990002223:dispatch', 'decl:7770001112:arrival'], 'declarations : seller of each WDT (second seller NIP = own declaration), arrivals to the ERP company');
$sd = $decls['decl:7770001112:dispatch'];
$rows = array_map(fn($r) => [$r['cn'], $r['country'], $r['partner_vat'], $r['mass_kg'], $r['mass_complete'], $r['value_pln'], $r['value_complete'], $r['docs']], $sd['rows']);
$check($rows === [['19059060', 'BE', 'BE0123456789', 49.5, true, 0.0, false, 2], ['19059060', 'DE', 'DE123456789', 18.0, true, 0.0, false, 1], ['19059060', 'FR', 'FR12345678901', 9.0, true, 340.0, true, 1],
    [null, null, null, 4.68, true, 0.0, false, 1], [null, 'BE', 'BE0123456789', 7.8, true, 0.0, false, 1], [null, 'DE', 'DE123456789', 0.0, false, 0.0, false, 1]],
    'dispatch rows : CN × country × partner VAT (shipping date, superseded and other months excluded ; partner VAT from the client card ; mass from the WDT, else weight × PPC)');
$check($sd['docs'] === 5 && $sd['value_pln'] === 340.0 && !$sd['value_complete'] && $sd['year_pln'] === 2060.0 && !$sd['year_complete'] && $sd['threshold'] === 3000000.0,
    'dispatch totals : 5 WDT, PLN known only for the PLN WDT ; year = September at its rate (1 720) + October PLN (340)');
$ad = $decls['decl:7770001112:arrival'];
$check(array_map(fn($r) => [$r['cn'], $r['country'], $r['mass_kg'], $r['mass_complete'], $r['docs']], $ad['rows']) === [[null, 'DE', 500.0, false, 1], [null, 'FR', 0.0, false, 1]] && $ad['docs'] === 2,
    'arrival rows : EU sellers only (VAT on the invoice, else supplier NIP) ; flour 500 kg from g ; unmapped line and unit « szt » without mass ; Polish and CHF invoices not arrivals');
$keys = array_column($d['review'], 'key');
$check($keys === ['rate:EUR', 'product:21', 'product:22', 'material:31', 'material:30', 'partner:4', 'invoice:203', 'invoice:201',
    'decl:7770001112:arrival', 'decl:9990002223:dispatch', 'decl:7770001112:dispatch'], 'items : rate, products, materials, partner, invoices, then the declarations');
$rv = array_column($d['review'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check($codes($rv['product:21']) === ['cn_missing'] && $codes($rv['product:22']) === ['cn_missing', 'mass_missing'] && !isset($rv['product:20'])
    && $codes($rv['material:31']) === ['cn_missing', 'mass_missing'] && $rv['material:31']['needs_unit_mass'] && $rv['material:31']['signals'][1]['detail'] === 'jednostka surowca « szt » — wpisz masę netto (kg) jednej jednostki'
    && $codes($rv['material:30']) === ['cn_missing'] && !$rv['material:30']['needs_unit_mass'], 'product / material signals : CN code, net mass (unit other than g / kg)');
$check($codes($rv['partner:4']) === ['vat_missing'] && $rv['partner:4']['signals'][0]['detail'] === 'WDT/10/003' && !isset($rv['partner:3']) && $rv['partner:4']['links'][0]['href'] === '/clients/4/edit'
    && $codes($rv['invoice:201']) === ['line_unmapped'] && $rv['invoice:201']['signals'][0]['detail'] === '1 pozycja' && $codes($rv['invoice:203']) === ['partner_unknown'] && !isset($rv['invoice:202']),
    'partner without EU VAT (WDT and card) ; client fixed on the card not flagged ; unmapped invoice line ; CHF invoice without EU VAT');
$check($codes($rv['decl:7770001112:dispatch']) === ['threshold_below'] && str_contains($rv['decl:7770001112:dispatch']['signals'][0]['detail'], "< 3\u{00A0}000\u{00A0}000,00 zł — sprawdź po uzupełnieniu kursów")
    && $codes($rv['decl:7770001112:arrival']) === ['threshold_not_set'] && $rv['decl:7770001112:dispatch']['blocked'] === 4, 'declarations : threshold of the settings vs year sum, not set → no threshold signal ; blocked by 4 waiting items');
$check($d['totals']['waiting'] === 11 && $d['totals']['signals'] === 8 && !$d['can_finish'] && $erp0 === $sum(), 'totals ; reading writes nothing in the ERP');

// --- Krok 2
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'decl:7770001112:dispatch', 'decision' => 'declared', 'note' => 'PL123'], 2, $today)), 'custom:422:Najpierw uzupełnij 4 elementy'),
    'declaration « zgłoszone » blocked while its lines wait');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'decl:7770001112:dispatch', 'decision' => 'completed'], 2, $today)), 'custom:422:Dla « Deklaracja » wybierz : Zgłoszone w PUESC, Poniżej progu'),
    'declaration : only zgłoszone / poniżej progu');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'product:21', 'decision' => 'completed'], 2, $today)), 'custom:422:Nadal : brak kodu CN'), '« uzupełnione » refused while the signal remains');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'invoice:203', 'decision' => 'not_applicable'], 2, $today)), 'custom:422:Nie dotyczy : wpisz'), '« nie dotyczy » needs a note');
$check(str_starts_with($err(fn() => $svc->saveCode(['key' => 'product:21', 'cn_code' => '1905906'], 2, $today)), 'custom:422:Kod CN : dokładnie 8 cyfr')
    && str_starts_with($err(fn() => $svc->saveCode(['key' => 'partner:4', 'cn_code' => '19059060'], 2, $today)), 'custom:422:Kod CN wpisuje się dla produktu albo surowca')
    && str_starts_with($err(fn() => $svc->saveRate(['currency' => 'USD', 'rate' => '4'], 2, $today)), 'custom:422:Waluta « USD » nie występuje')
    && str_starts_with($err(fn() => $svc->saveRate(['currency' => 'EUR', 'rate' => '0'], 2, $today)), 'custom:422:Kurs : liczba PLN'), 'CN code, product only, currency of the month and rate validated');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 11 elementów') && (int) $pdo->query('SELECT COUNT(*) FROM n_intrastat_review')->fetchColumn() === 0,
    'finish blocked ; refused calls write nothing');
$d = $svc->saveRate(['currency' => 'EUR', 'rate' => '4,25'], 2, $today);
$row = $pdo->query("SELECT * FROM n_intrastat_review WHERE item_key = 'rate:EUR'")->fetch();
$check((float) $pdo->query("SELECT rate FROM n_intrastat_rate WHERE month_start = '2026-10-01' AND currency = 'EUR'")->fetchColumn() === 4.25 && $row['decision'] === 'completed'
    && $row['flags'] === 'rate_missing' && $row['month_start'] === '2026-10-01', 'rate saved for the month ; « uzupełnione » recorded by itself');
$sd = array_column($d['declarations'], null, 'key')['decl:7770001112:dispatch'];
$check($sd['value_pln'] === 4122.5 && $sd['value_complete'] && $sd['year_pln'] === 5842.5 && $sd['rows'][0]['value_pln'] === 1870.0, 'values in PLN at the rate of the month (lowercase « eur » WDT too) ; year = 1 720 + 4 122,50');
$d = $svc->saveCode(['key' => 'product:21', 'cn_code' => '1905 90 60'], 2, $today);
$d = $svc->saveCode(['key' => 'product:22', 'cn_code' => '19059045'], 2, $today);
$rv = array_column($d['review'], null, 'key');
$check($rv['product:21']['decision']['code'] === 'completed' && !$rv['product:21']['waiting'] && $rv['product:22']['waiting'] && $codes($rv['product:22']) === ['mass_missing']
    && $rv['product:22']['decision'] === null, 'CN saved : « uzupełnione » by itself when no signal is left ; mass still missing → waits');
$d = $svc->saveDecision(['key' => 'product:22', 'decision' => 'not_applicable', 'note' => 'Tarta : próbki dla klienta, bez masy w kartotece'], 2, $today);
$d = $svc->saveCode(['key' => 'material:30', 'cn_code' => '11010015'], 2, $today);
$check(str_starts_with($err(fn() => $svc->saveCode(['key' => 'material:31', 'cn_code' => '04051011', 'unit_mass_kg' => 'abc'], 2, $today)), 'custom:422:Masa jednostki'), 'unit mass validated');
$d = $svc->saveCode(['key' => 'material:31', 'cn_code' => '04051011', 'unit_mass_kg' => '0,25'], 2, $today);
$check($pdo->query("SELECT CONCAT(cn_code, '|', unit_mass_kg) FROM n_intrastat_code WHERE item_kind = 'material' AND item_id = 31")->fetchColumn() === '04051011|0.250000'
    && array_column($d['review'], null, 'key')['material:31']['decision']['code'] === 'completed', 'material : CN and mass of one unit saved in the assistant table');
// Corrections faites dans l'ERP (fiche client, ligne de facture rapprochée) : le signal disparaît, « uzupełnione » avec les signaux vus.
$pdo->exec("UPDATE customer SET tax_number = 'NL123456789B01' WHERE id = 4");
$pdo->exec("UPDATE n_inv_line SET id_raw_material = 32, factor = 1000 WHERE id_inv = 201 AND line_no = 2");
$erp1 = $sum();
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'partner:4', 'decision' => 'completed'], 2, $today)), 'custom:422:Tego elementu nie ma na liście'), 'fixed item without « seen » : not in the list');
$d = $svc->saveDecision(['key' => 'partner:4', 'decision' => 'completed', 'seen' => ['vat_missing']], 2, $today);
$d = $svc->saveDecision(['key' => 'invoice:201', 'decision' => 'completed', 'seen' => ['line_unmapped']], 2, $today);
$rv = array_column($d['review'], null, 'key');
$check(!$rv['partner:4']['waiting'] && $rv['partner:4']['decision']['flags'] === ['vat_missing'] && $codes($rv['material:32']) === ['cn_missing'] && $rv['material:32']['waiting'],
    'client card and invoice fixed in the ERP : « uzupełnione » with the signals seen ; the mapped line brings a new material to code');
$d = $svc->saveCode(['key' => 'material:32', 'cn_code' => '21021031'], 2, $today);
$d = $svc->saveDecision(['key' => 'invoice:203', 'decision' => 'not_applicable', 'note' => 'Szwajcaria — import spoza UE'], 2, $today);
$st = $tasks->state(S::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '9 elementów z decyzją', 'task state : in progress');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'decl:7770001112:dispatch', 'decision' => 'declared', 'note' => ''], 2, $today)), 'custom:422:Zgłoszone w PUESC : wpisz numer'), 'reference number required');
$d = $svc->saveDecision(['key' => 'decl:7770001112:dispatch', 'decision' => 'declared', 'note' => 'PUESC ref. TEST-2026-10-001'], 2, $today);
$d = $svc->saveDecision(['key' => 'decl:9990002223:dispatch', 'decision' => 'below_threshold', 'note' => 'drugi sprzedawca — test, suma roku poniżej progu'], 2, $today);
$d = $svc->saveDecision(['key' => 'decl:7770001112:arrival', 'decision' => 'below_threshold', 'note' => 'przywóz 2026 poniżej progu z przepisów'], 2, $today);
$check($d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['declared'] === 1 && $d['totals']['below_threshold'] === 2 && $d['totals']['not_applicable'] === 2, 'all decided → step 3');

// --- CSV
$csv = $svc->csv('decl:7770001112:dispatch', $today);
$lines = explode("\r\n", trim($csv['content']));
$check($csv['name'] === 'INTRASTAT_2026-10_WYWOZ_7770001112.csv' && str_starts_with($lines[0], "\u{FEFF}\"Miesiąc\";\"Kierunek\";\"NIP firmy\";\"Firma\";\"Kod CN\"") && count($lines) === 6
    && $lines[2] === '"2026-10";"Wywóz";"7770001112";"Piekarnia Testowa Sp. z o.o.";"19059060";"BE";"BE0123456789";"";"DAP";"";"57,300";"2507,50";"2";""'
    && str_contains($lines[5], '"19059060";"NL";"NL123456789B01"')
    && str_contains($csv['content'], '"19059045";"DE";"DE123456789";"";"DAP";"";"0,000";"212,50";"1";"masa niepełna"'), 'CSV : header, rows CN × country × VAT, settings, mass, PLN value, remarks');
$check(str_starts_with($err(fn() => $svc->csv('decl:x:dispatch', $today)), 'custom:404:Brak takiej deklaracji'), 'unknown declaration → 404');
$check($erp1 === $sum(), 'decisions, codes and rates write nothing in the ERP');
$r = $svc->finish(2, $today);
$check($r['summary'] === "październik 2026 : wywóz 2 deklaracje (4\u{00A0}632,50 zł, 6 dok.) · przywóz 1 deklaracja (2\u{00A0}507,50 zł, 2 dok.) · 2 sygnały · decyzje : 7 uzupełnione · 2 nie dotyczy · 1 zgłoszone w PUESC · 2 poniżej progu"
    && (int) $pdo->query("SELECT COUNT(*) FROM n_wizard_done WHERE code = 'intrastat_month_reviewed' AND day = '2026-11-24'")->fetchColumn() === 1, 'finish : summary, n_wizard_done');
$check($tasks->state(S::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30')['done'] === true && $tasks->state(S::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');

// Mois suivant (mer. 2.12) : novembre ; codes gardés, kurs EUR de novembre manquant, un seul WDT.
$n = $make()->data('2026-12-02');
$check($n['month_start'] === '2026-11-01' && array_column($n['review'], 'key') === ['rate:EUR', 'decl:7770001112:dispatch'] && $n['done'] === null, 'next month : codes kept, November rate missing, one declaration');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'intrastat.threshold_dispatch_kpln'");
$check(array_column($make()->data($today)['review'], null, 'key')['decl:7770001112:dispatch']['signals'][0]['code'] === 'threshold_exceeded', 'threshold 1 000 zł : year sum above → « próg » signal');
$pdo->exec("UPDATE n_prod_param SET value = '9999999' WHERE code = 'intrastat.threshold_dispatch_kpln'");
$check($make()->settings()['threshold_dispatch'] === null, 'out of bounds threshold → not set');

// --- Contrôleur : construit par PHP-DI, data(), decision(), code(), rate(), csv() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_intrastat_month_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(IntrastatMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 4), (4, 3)');
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), $res]; };
$erp3 = $sum();
[$c2, $r2] = $call(2, 'data');
$b2 = json_decode((string) $r2->getContent(), true);
$check($c2 === 200 && $b2['data']['month_start'] === S::reviewedMonth(date('Y-m-d')) && $b2['data']['settings_link'] === null, 'controller data() (Kierownik produkcji, « intrastat ») : 200, real month ; no settings link without « settings »');
[$c1, $r1] = $call(1, 'data');
$check($c1 === 200 && json_decode((string) $r1->getContent(), true)['data']['settings_link'] === '/other-settings#params', 'controller data() (administrator) : 200 with the settings link');
[$c4] = $call(4, 'data'); [$c4d] = $call(4, 'decision'); [$c4c] = $call(4, 'code'); [$c4r] = $call(4, 'rate'); [$c4f] = $call(4, 'finish'); [$c4v] = $call(4, 'csv');
[$c3] = $call(3, 'data');
$check([$c4, $c4d, $c4c, $c4r, $c4f, $c4v, $c3] === [403, 403, 403, 403, 403, 403, 403], 'controller : without « intrastat » (Piekarz, Administracja) → 403 everywhere');
$_GET['decl'] = 'decl:nope:dispatch';
[$cv, $rvv] = $call(2, 'csv');
$check($cv === 404, 'controller csv() really called : unknown declaration → 404');
unset($_GET['decl']);
[$cF2, $rF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true), 'controller finish() really called');
$check($erp3 === $sum(), 'controller calls write nothing in the ERP');
unset($_SESSION['USER']);

// --- Aucun envoi, aucun appel extérieur, aucune écriture dans l'ERP, aucun XML : code sans les commentaires.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/IntrastatMonthService.php', 'App/Repositories/Workshop/IntrastatMonthRepository.php',
    'App/Controllers/Web/Shipping/IntrastatMonthController.php', 'public/assets/js/shipping/intrastat-month.js']));
$check(!preg_match('/UPDATE\s+(document|n_inv|customer|final_product|raw_material|supplier)|INSERT\s+INTO\s+(document|n_inv\b|n_inv_line|customer|final_product|raw_material)|DELETE\s+FROM|XMLWriter|SimpleXML|PHPMailer|Sms|curl_|file_get_contents\(\s*[\'"]http|https?:\/\//i', $src),
    'no write to the ERP tables, no XML, no mail / SMS, no external call, no hard-coded URL');

// --- Enregistrement
$check(TaskWizards::info('intrastat_month_reviewed')['link'] === '/intrastat/month' && TaskWizards::byLink('/intrastat/month') === 'intrastat_month_reviewed'
    && isset(TaskWizards::AUTO['intrastat_month_reviewed']) && PositionTaskService::LINK_AUTO['/intrastat/month'] === 'intrastat_month_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['intrastat_month_reviewed'][0] === 'Intrastat miesiąca (deklaracje)' && TaskWizards::CATALOG['intrastat_month_reviewed'][0] !== 'Intrastat miesiąca', 'catalog label distinct from the proposed name (237)');
$check(PageAccess::keyFor('/intrastat/month') === 'intrastat' && PageAccess::keyFor('/ajax/intrastat/month/csv') === 'intrastat' && in_array('intrastat', PageAccess::grantable(), true)
    && PageAccess::keyFor('/shipments') === 'shipping', 'own right « intrastat » (page and ajax) ; shipments unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'intrastat_month_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/intrastat/month'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Shipping/IntrastatMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/intrastat/month') === 'index' && $found('GET', '/ajax/intrastat/month') === 'data' && $found('GET', '/ajax/intrastat/month/csv') === 'csv'
    && $found('POST', '/ajax/intrastat/month/decision') === 'decision' && $found('POST', '/ajax/intrastat/month/code') === 'code' && $found('POST', '/ajax/intrastat/month/rate') === 'rate'
    && $found('POST', '/ajax/intrastat/month/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/intrastat/month')[1]['controller'] === IntrastatMonthController::class && $allR->dispatch('GET', '/shipments')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing routes unchanged');

if (getenv('IM_JSON_OUT')) file_put_contents((string) getenv('IM_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_intrastat_month_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
