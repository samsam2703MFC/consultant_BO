<?php
/**
 * [384] Asystent « Nowe produkty » : lancements des launches_review.months derniers mois (date n_mgr_launch.launch_date,
 * sinon date de création du produit ; lancement plus ancien et produit ancien exclus) ; objectifs du module Kierownik
 * produkcji comparés au réel calculé par les services existants (ProfitabilityService::production : coût matières, prix
 * pratiqué, marge après matières ; WDT remplacé exclu ; ManagerRepository::productShops : cartons / magasin / semaine ;
 * sans vente : coût de la fiche produit, prix standard) ; signaux (coût, prix, marge, ventes, aucune vente, trop tôt, sans
 * objectifs, sans décision du module) et seuils launches_review.* bornés ; « brak danych » sans n_mgr_launch (table
 * absente ou vide) avec la liste des produits créés récemment ; décisions (korekta et wycofać avec note obligatoire,
 * produit hors liste refusé), étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche du mois ; mois suivant :
 * lancement loin de ses objectifs revient, décidé sans écart non ; montants retirés sans le droit « margins » ; aucune
 * écriture dans les tables d'origine — n_mgr_launch.decision jamais écrite (checksums) ; contrôleur construit par PHP-DI et
 * réellement appelé (droit « products » 200, sans droit 403 ; montants absents sans « margins » ; liens filtrés) ;
 * enregistrement (catalogue, droits, routes, paramètres, équipe, menu) ; migration 232 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_launches_review_test recréée (n_mgr_launch et n_mgr_plan de la migration 041 telles qu'elles sont écrites,
 * tables ERP minimales, migration 232), supprimée à la fin (LR_JSON_OUT=fichier : données de la page, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Product\LaunchesReviewController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Analysis\{ProductMarginRepository, WdtSalesRepository};
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Organization\{LightWizardRepository, PageAccessRepository, TaskWizardRepository};
use App\Repositories\Product\LaunchesReviewRepository;
use App\Repositories\Production\Cost\SectionUnitCostRepository;
use App\Repositories\Workshop\{ManagerRepository, ParamRepository};
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Organization\{PageAccessService, PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\LaunchesReviewService;
use App\Services\Production\Cost\SectionUnitCostService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_launches_review_test'); $pdo->exec('CREATE DATABASE erp_launches_review_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE erp_launches_review_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL, name VARCHAR(40) NULL, surname VARCHAR(40) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80))',
    // final_product : colonnes lues par ManagerRepository::launches, ProfitabilityRepository et ProductMarginRepository::getProductData.
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), ppc INT NULL, weight INT NULL, active TINYINT DEFAULT 1, id_section INT NULL, id_category INT NULL, id_recipe INT NULL,
        standard_price DECIMAL(10,2) NULL, pre_calculated_raw_price_per_carton DECIMAL(10,4) NULL, workload_points DECIMAL(10,2) NULL, create_timestamp DATETIME NULL)',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80), target_margin_percentage DECIMAL(5,2) NULL)',
    'CREATE TABLE production_sections (id INT PRIMARY KEY, name VARCHAR(80), depreciation DECIMAL(12,2) DEFAULT 0, water_cost DECIMAL(12,2) DEFAULT 0, power_supply_cost DECIMAL(12,2) DEFAULT 0,
        fuel_cost DECIMAL(12,2) DEFAULT 0, other_cost DECIMAL(12,2) DEFAULT 0)',
    'CREATE TABLE n_section_target (id_section INT PRIMARY KEY, staff_pct_target DECIMAL(5,2) NULL, margin_pct_target DECIMAL(5,2) NULL, bonus_pct DECIMAL(5,2) NULL, is_shared TINYINT DEFAULT 0)',
    "CREATE TABLE document_wdt (id INT PRIMARY KEY, id_customer INT, established_date DATE, currency VARCHAR(3) DEFAULT 'EUR')",
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT, name TEXT NULL, quantity DECIMAL(10,2), price_carton DECIMAL(10,2))',
    'CREATE TABLE n_wdt_superseded (id_document_wdt INT PRIMARY KEY)',
    'CREATE TABLE erp_company_other_settings (id INT PRIMARY KEY, exchange_rate_euro DECIMAL(10,4))',
    'CREATE TABLE flattened_recipe_ingredient (id INT AUTO_INCREMENT PRIMARY KEY, recipe_id INT, material_id INT, quantity DECIMAL(12,4))',
    'CREATE TABLE n_recipe_version (id INT AUTO_INCREMENT PRIMARY KEY, id_recipe INT, version_no INT, valid_from DATETIME NULL, ingredients TEXT)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(80), id_unit INT NULL, id_category INT NULL, id_current_supplier INT NULL, price_per_unit DECIMAL(12,4), waste_perc DECIMAL(5,2) NULL)',
    'CREATE TABLE n_raw_material_loss (id_raw_material INT PRIMARY KEY, loss_perc DECIMAL(5,2))',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE category_material (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE raw_material_price_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_raw_material INT, pack_price DECIMAL(12,4), package_size_in_base_unit DECIMAL(12,4), timestamp DATETIME, id_supplier INT NULL)',
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
// n_mgr_launch et n_mgr_plan exactement comme la migration 041 les crée (renommées en n_ par 069).
foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/041_manager_module.sql')) as $sql) {
    if (preg_match('/^CREATE TABLE IF NOT EXISTS (tfb_mgr_launch|tfb_mgr_plan)\b/', trim($sql))) $pdo->exec(str_replace('tfb_mgr_', 'n_mgr_', $sql));
}
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'ADMINISTRACJA', 'Administracja i fakturowanie', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2), (3, 'PIEKARZ', 'Piekarz', 7)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '6', NULL, 'Nowe produkty', 'monthly'),
    (2, '5', 'haccp_month_reviewed', NULL, 'monthly'), (2, '7', NULL, NULL, 'none'), (1, '4', NULL, 'Dane klientów', 'monthly')");
$m232 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/232_launches_review.sql');
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements($m232) as $sql) if (trim($sql) !== '') $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };
$near = fn(?float $a, float $b) => $a !== null && abs($a - $b) < 0.01;

// --- Migracja 232 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'launches_reviewed' && $duties[0]['proposed_wizard'] === null, 'migration 232: KIEROWNIK_PRODUKCJI 6 → launches_reviewed');
$check($duties[1]['wizard_code'] === 'haccp_month_reviewed' && $duties[2]['wizard_code'] === null && $duties[3]['proposed_wizard'] === 'Dane klientów', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'launches_review.%' ORDER BY code")->fetchAll(), 'value', 'code');
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_launch_review'")->fetchColumn();
$check($params === ['launches_review.cost_over_pct' => '10', 'launches_review.margin_gap_pts' => '0', 'launches_review.min_weeks' => '4', 'launches_review.months' => '3',
    'launches_review.price_under_pct' => '5', 'launches_review.volume_under_pct' => '30'] && $fks === 0, 'migration replayed: 6 params once, no foreign key');
$check(count(array_filter(array_keys($params), fn($c) => isset(ParamRules::RANGES[$c]))) === 6, 'every launches_review.* param bounded in ParamRules');
$last = array_values(array_filter(MigrationRunner::splitStatements($m232), fn($s) => trim($s) !== ''));
$check(trim(end($last), " \n;") === "UPDATE n_position_duty SET wizard_code = 'launches_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'Nowe produkty'", 'migration: last statement is the duty UPDATE');

// --- Données fictives : contrôle le mar. 24.11.2026 ; fenêtre de 3 mois → depuis le 24.08.2026 ; cours 4 PLN / €.
$pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', NULL), (2, 'marek', 'Marek K.'), (3, 'ewa', 'Ewa L.'), (4, 'piotr', NULL)");
$pdo->exec('INSERT INTO erp_company_other_settings VALUES (1, 4.0000)');
$pdo->exec("INSERT INTO customer VALUES (1, 'Sklep A'), (2, 'Sklep B'), (3, 'Sklep C')");
$pdo->exec("INSERT INTO production_sections (id, name) VALUES (1, 'Piekarnia')");
$pdo->exec("INSERT INTO category_product VALUES (1, 'Wypieki', 55)");
$pdo->exec("INSERT INTO raw_material (id, name, price_per_unit) VALUES (1, 'Mąka testowa', 2.0000)");
// Recettes : 8 kg (16 zł = 4 € / karton), 4 kg (2 €), 6 kg (3 €).
$pdo->exec('INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 1, 8), (101, 1, 4), (102, 1, 6)');
$pdo->exec("INSERT INTO final_product (id, name, ppc, weight, active, id_section, id_category, id_recipe, standard_price, pre_calculated_raw_price_per_carton, create_timestamp) VALUES
    (10, 'Croissant pistacjowy', 12, 80, 1, 1, 1, 100, 10.00, 16, '2026-01-10 08:00:00'),
    (11, 'Bagietka orkiszowa', 10, 250, 1, 1, 1, 101, 6.00, 8, '2026-01-10 08:00:00'),
    (12, 'Tarta cytrynowa', 6, 120, 1, 1, 1, NULL, 7.00, 10, '2026-01-10 08:00:00'),
    (13, 'Muffin dyniowy', 12, 90, 1, 1, 1, NULL, 9.00, 12, '2026-01-10 08:00:00'),
    (14, 'Stary rogal', 12, 80, 1, 1, 1, NULL, 5.00, 8, '2026-01-10 08:00:00'),
    (15, 'Chleb żytni', 8, 500, 1, 1, 1, NULL, 5.00, 6, '2026-10-15 09:00:00'),
    (16, 'Bułka zwykła', 20, 60, 1, 1, 1, NULL, 3.00, 2, '2025-05-01 08:00:00'),
    (17, 'Ciastko owsiane', 24, 40, 1, 1, 1, 102, 8.00, 12, '2026-01-10 08:00:00')");
// Objectifs (module Kierownik produkcji, saisis par l'administrateur).
$pdo->exec("INSERT INTO n_mgr_launch (id_product, launch_date, target_cost, target_price, min_margin_pct, expected_week_shop, decision, decision_note, updated_by) VALUES
    (10, '2026-09-01', 3.00, 10.00, 60, 2.0, NULL, NULL, 1),
    (11, '2026-10-01', 2.00, 6.00, 50, 1.0, 'keep', 'Dobra sprzedaż', 1),
    (12, '2026-08-30', NULL, NULL, 40, 3.0, NULL, NULL, 1),
    (13, '2026-11-10', 3.00, 9.00, 50, 2.0, NULL, NULL, 1),
    (14, '2026-05-01', 2.00, 5.00, 40, 1.0, NULL, NULL, 1),
    (17, '2026-09-15', 3.00, 8.00, 50, 5.0, 'fix', 'Nowy karton', 1)");
// Ventes WDT (EUR) : 10 → 20 cartons à 9 € chez 2 magasins (+ un WDT remplacé, exclu) ; 11 → 30 à 6 € chez 3 ; 15 → 10 à 5 € ; 17 → 20 à 8 € chez 2.
$pdo->exec("INSERT INTO document_wdt (id, id_customer, established_date, currency) VALUES (1, 1, '2026-09-10', 'EUR'), (2, 2, '2026-10-20', 'EUR'), (3, 1, '2026-10-05', 'EUR'),
    (4, 2, '2026-10-12', 'EUR'), (5, 3, '2026-11-02', 'EUR'), (6, 1, '2026-10-20', 'EUR'), (7, 1, '2026-09-20', 'EUR'), (8, 2, '2026-11-03', 'EUR'), (9, 3, '2026-09-11', 'EUR')");
$pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES
    (1, 10, 'Croissant pistacjowy', 10, 9.00), (2, 10, 'Croissant pistacjowy', 10, 9.00), (9, 10, 'Croissant pistacjowy', 100, 2.00),
    (3, 11, 'Bagietka', 10, 6.00), (4, 11, 'Bagietka', 10, 6.00), (5, 11, 'Bagietka', 10, 6.00),
    (6, 15, 'Chleb żytni', 10, 5.00), (7, 17, 'Ciastko', 10, 8.00), (8, 17, 'Ciastko', 10, 8.00)");
$pdo->exec('INSERT INTO n_wdt_superseded VALUES (9)');
// Décision d'octobre de l'assistant sur le pain (sans objectifs) : ne revient pas, sans écart.
$pdo->exec("INSERT INTO n_launch_review (month_start, id_product, launch_date, decision, note, flags, id_user) VALUES ('2026-10-01', 15, '2026-10-15', 'keep', 'Produkt sezonowy, zostaje', 'no_targets,no_decision', 2)");
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'products'), (2, 'time'), (1, 'products'), (1, 'margins'), (3, 'production')");

$sources = ['final_product', 'n_mgr_launch', 'n_mgr_plan', 'document_wdt', 'document_wdt_product', 'n_wdt_superseded', 'raw_material', 'flattened_recipe_ingredient', 'category_product',
    'production_sections', 'customer', 'user', 'n_prod_param', 'n_page_access', 'n_user_position', 'n_position', 'erp_company_other_settings'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $sources));

$make = function () use ($pdo) {
    $pr = new ProfitabilityRepository($pdo);
    return new LaunchesReviewService(new LaunchesReviewRepository($pdo), new ManagerRepository($pdo),
        new ProfitabilityService($pr, new ParamRepository($pdo), new SectionUnitCostService(new SectionUnitCostRepository($pdo, new WdtSalesRepository($pdo)))), $pr,
        new ProductMarginRepository($pdo), new LightWizardRepository($pdo), new ParamRepository($pdo), new PageAccessService(new PageAccessRepository($pdo)));
};
$svc = $make();
$today = '2026-11-24';
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

// --- Étape 1 (avec montants)
$erp0 = $sum();
$d = $svc->data($today, true);
$boot = $d;
$by = array_column($d['items'], null, 'id_product');
$codes = fn(int $id) => array_column($by[$id]['signals'], 'code');
$ids = array_keys($by);
sort($ids);
$check($d['since'] === '2026-08-24' && $d['month_start'] === '2026-11-01' && $ids === [10, 11, 12, 13, 15, 17], 'window 3 months: launches 10, 11, 12, 13, 17 and product 15 created in the ERP ; May launch 14 and old product 16 excluded — ' . implode(',', $ids));
$p10 = $by[10];
$check($near($p10['actual']['cost'], 4.0) && $p10['actual']['cost_source'] === 'sales' && $near($p10['actual']['price'], 9.0) && $near($p10['actual']['margin'], 55.56)
    && $p10['actual']['cartons'] == 20 && $p10['actual']['shops'] === 2, 'P10 : cost 4 € (recipe 8 kg × 2 zł ÷ 4), price 9 €, margin 55,6 % ; superseded WDT excluded (20 cartons, 2 shops)');
$check($near($p10['actual']['per_shop_week'], 0.82) && $p10['weeks'] === 12 && $near($p10['gap']['cost_pct'], 33.33) && $near($p10['gap']['price_pct'], -10.0) && $near($p10['gap']['margin_pts'], -4.44),
    'P10 : 0,82 cartons / shop / week (20 ÷ 2 ÷ 85 days × 7), 12 weeks ; gaps +33 % cost, −10 % price, −4,4 pts margin');
$check($codes(10) === ['margin_below', 'cost_over', 'price_under', 'volume_below', 'no_decision'] && $p10['off_target'] && $p10['waiting'], 'P10 : margin, cost, price, volume, no decision → waiting');
$check($codes(11) === [] && !$by[11]['in_review'] && $by[11]['manager_decision']['code'] === 'keep' && $near($by[11]['actual']['per_shop_week'], 1.27), 'P11 on target and decided in the module → not in step 2');
$check($codes(12) === ['no_sales', 'no_decision'] && $by[12]['actual']['cost_source'] === 'card' && $near($by[12]['actual']['cost'], 2.5) && $by[12]['actual']['price_source'] === 'list'
    && $by[12]['actual']['margin'] === null, 'P12 : no sale after 12 weeks ; cost from the product card (10 zł ÷ 4), standard price, no margin');
$check($codes(13) === ['no_decision'] && $by[13]['too_early'] && $by[13]['weeks'] === 2, 'P13 launched 2 weeks ago : too early for sales, only « no decision »');
$check($codes(15) === ['no_targets', 'no_decision'] && $by[15]['launch_source'] === 'created' && $by[15]['launch_date'] === '2026-10-15' && !$by[15]['waiting'] && $by[15]['earlier']['code'] === 'keep',
    'P15 created in the ERP (create_timestamp), no targets ; decided in October without gap → not waiting');
$check($codes(17) === ['volume_below'] && $by[17]['manager_decision']['code'] === 'fix' && $by[17]['waiting'], 'P17 decided « fix » in the module but sales far below → waiting');
$check(array_column($d['review'], 'id_product') === [10, 12, 17, 15, 13], 'review order: by first signal, then launch date — ' . implode(',', array_column($d['review'], 'id_product')));
$t = $d['totals'];
$check($t['launches'] === 6 && $t['with_targets'] === 5 && $t['from_launch'] === 5 && $t['created'] === 1 && $t['manager_decided'] === 2 && $t['off_target'] === 3 && $t['too_early'] === 1
    && $t['with_sales'] === 4 && $t['review'] === 5 && $t['waiting'] === 4 && $t['earlier'] === 1 && $d['can_finish'] === false, 'totals');
$check($d['sources']['launch']['available'] && $d['sources']['launch']['admin_only'] && $d['sources']['sales']['available'], 'sources: launch targets present, entered by the administrator only ; WDT sales present');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Montants : sans le droit, retirés
$v = $svc->data($today, false);
$v10 = array_column($v['items'], null, 'id_product')[10];
$json = json_encode($v, JSON_UNESCAPED_UNICODE);
$check($v['values'] === false && $v['eur_rate'] === null && $v10['targets'] === ['cost' => null, 'price' => null, 'min_margin' => null, 'expected' => 2.0]
    && $v10['actual']['cost'] === null && $v10['actual']['price'] === null && $v10['actual']['margin'] === null && $v10['gap']['cost_pct'] === null && $v10['gap']['margin_pts'] === null
    && $v10['link'] === null && $v10['actual']['cartons'] == 20, 'no amount right: costs, prices, margins and their gaps removed ; cartons kept');
$check(!str_contains($json, '55,6') && !str_contains($json, '4 €') && !str_contains($json, '9 €') && !str_contains($json, '"cost_card":2.5') && array_column($v10['signals'], 'code') === $codes(10)
    && $v10['signals'][0]['detail'] === 'kwoty widoczne z prawem « Marże i koszty »', 'no amount in the page data ; signals kept without figures');

// --- Réglages
$pdo->exec("UPDATE n_prod_param SET value = '40' WHERE code = 'launches_review.cost_over_pct'");
$check(!in_array('cost_over', array_column(array_column($make()->data($today, true)['items'], null, 'id_product')[10]['signals'], 'code'), true), 'cost_over_pct = 40 → +33 % no longer flagged');
$pdo->exec("UPDATE n_prod_param SET value = '10' WHERE code = 'launches_review.cost_over_pct'");
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'launches_review.months'");
$check(array_column($make()->data($today, true)['items'], 'id_product') === [13], 'months = 1 → only the November launch');
$pdo->exec("UPDATE n_prod_param SET value = '99' WHERE code = 'launches_review.months'");
$check($make()->param('months') === 3, 'out of ParamRules bounds → default 3');
$pdo->exec("UPDATE n_prod_param SET value = '3' WHERE code = 'launches_review.months'");

// --- « brak danych » : sans ligne n_mgr_launch, puis sans table — liste des produits créés récemment, sans objectifs.
$pdo->exec('CREATE TABLE lr_bak AS SELECT * FROM n_mgr_launch'); $pdo->exec('DELETE FROM n_mgr_launch');
$e = $make()->data($today, true);
$check(!$e['sources']['launch']['available'] && str_starts_with((string) $e['sources']['launch']['note'], 'brak danych — nikt nie wpisuje celów') && array_column($e['items'], 'id_product') === [15]
    && $e['items'][0]['launch_source'] === 'created' && str_contains($e['summary'], 'cele : brak danych'), 'empty n_mgr_launch → « brak danych », recently created products still listed without targets');
$pdo->exec('RENAME TABLE n_mgr_launch TO lr_hidden');
$e = $make()->data($today, true);
$check($e['sources']['launch']['note'] === 'brak danych — brak tabeli n_mgr_launch w bazie' && array_column($e['items'], 'id_product') === [15], 'no n_mgr_launch table → « brak danych », created products listed');
$pdo->exec('RENAME TABLE lr_hidden TO n_mgr_launch'); $pdo->exec('INSERT INTO n_mgr_launch SELECT * FROM lr_bak'); $pdo->exec('DROP TABLE lr_bak');
$check($erp0 === $sum(), 'fixture restored');

// --- Krok 2 : decyzje
$dec = fn(int $id, string $decision, string $note = '', array $extra = [], string $at = '2026-11-24') => $svc->saveDecision(['id_product' => $id, 'decision' => $decision, 'note' => $note] + $extra, 2, $at, true);
$check(str_starts_with($err(fn() => $dec(10, 'drop')), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $dec(10, 'fix', ' ')), 'custom:422:Korekta : wpisz'), 'fix needs a note');
$check(str_starts_with($err(fn() => $dec(12, 'stop', 'x')), 'custom:422:Wycofać : wpisz'), 'stop needs a note');
$check(str_starts_with($err(fn() => $dec(10, 'fix', 'Cena', ['fix_area' => 'logo'])), 'custom:422:Nieznany zakres'), 'unknown fix area refused');
$check(str_starts_with($err(fn() => $dec(11, 'keep', 'OK')), 'custom:422:Tego produktu nie ma') && str_starts_with($err(fn() => $dec(14, 'keep', 'OK')), 'custom:422:Tego produktu nie ma'),
    'product on target / launch out of the window refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 4 produktów'), 'finish blocked while launches wait');
$check((int) $pdo->query('SELECT COUNT(*) FROM n_launch_review')->fetchColumn() === 1, 'refused decisions write nothing');
$erp1 = $sum();
$d = $dec(10, 'fix', 'Cena 10 € od grudnia, lżejsze opakowanie', ['fix_area' => 'price']);
$row = $pdo->query('SELECT * FROM n_launch_review WHERE id_product = 10')->fetch();
$check($row['decision'] === 'fix' && $row['fix_area'] === 'price' && $row['month_start'] === '2026-11-01' && $row['launch_date'] === '2026-09-01'
    && $row['flags'] === 'margin_below,cost_over,price_under,volume_below,no_decision' && (int) $row['id_user'] === 2, 'fix saved: month, launch date, area, signals, author');
$check($pdo->query('SELECT decision FROM n_mgr_launch WHERE id_product = 10')->fetchColumn() === null, 'n_mgr_launch.decision not written by the assistant');
$st = $tasks->state(LaunchesReviewService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 produkt wyjaśniony', 'task state: in progress (1 decision this month)');
$dec(12, 'stop', 'Brak sprzedaży — wycofać od 1.12');
$dec(13, 'keep', 'Za wcześnie, obserwacja');
$d = $dec(17, 'keep', 'Karton zmieniony w listopadzie, czekamy');
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && [$d['totals']['keep'], $d['totals']['fix'], $d['totals']['stop']] === [3, 1, 1], 'all decided → step 3 open (P15 keep from October counted)');
$after = $d;
$r = $svc->finish(2, $today, true);
$wdn = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'launches_reviewed'")->fetchAll();
$check($r['summary'] === 'listopad 2026 : 6 nowych produktów (3 mies.) · 5 z celami · 3 poza celem · 5 do wyjaśnienia : 3 zostaje · 1 korekta · 1 wycofać'
    && count($wdn) === 1 && $wdn[0]['day'] === $today && $r['data']['done'] !== null, 'finish: summary, n_wizard_done — ' . $r['summary']);
$check($tasks->state(LaunchesReviewService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', $today)['done'] === true
    && $tasks->state(LaunchesReviewService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-02')['done'] === false, 'task state: done this month, open next month');
$check($erp1 === $sum(), 'decisions and finish write nothing in the source tables (n_mgr_launch, products, prices untouched)');
$n = $make()->data('2026-12-01', true);
$nx = array_column($n['review'], null, 'id_product');
$check($nx[10]['waiting'] && $nx[10]['earlier']['code'] === 'fix' && !$nx[13]['waiting'] && $nx[13]['earlier']['code'] === 'keep' && !$nx[15]['waiting'] && $nx[17]['waiting'],
    'next month (1.12, window from 1.09): off-target launches come back (10, 17), decided without gap do not (13, 15)');

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), decision() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_launches_review_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(LaunchesReviewController::class);
$call = function (?int $user, string $method) use ($ctl) {
    if ($user === null) unset($_SESSION['USER']); else $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user];
    $res = $ctl->$method();
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
$j2 = json_encode($b2, JSON_UNESCAPED_UNICODE);
$i2 = $b2['data']['items'] ?? [];
$check($c2 === 200 && $b2['data']['today'] === date('Y-m-d') && $b2['data']['values'] === false && $b2['data']['manager_link'] === null
    && array_filter(array_column($i2, 'link')) === [] && array_filter(array_map(fn($i) => $i['actual']['cost'] ?? null, $i2)) === [] && array_filter(array_map(fn($i) => $i['actual']['margin'], $i2)) === []
    && !preg_match('/"(cost|cost_card|price|price_list|margin|min_margin|cost_pct|price_pct|margin_pts)":-?[0-9]/', $j2) && $b2['data']['eur_rate'] === null, 'controller data() (Kierownik produkcji, right « products », no « margins ») : 200, real day, no amount, no admin-only module link');
[$c3, $b3] = $call(3, 'data');
$i3 = array_column($b3['data']['items'], null, 'id_product');
$check($c3 === 200 && $b3['data']['values'] === true && $b3['data']['manager_link'] === null && ($i3[15]['link']['href'] ?? null) === '/finances/product-margin?product_id=15'
    && $near($i3[15]['actual']['price'], 5.0), 'controller data() (Finanse, « products » + « margins ») : amounts and the product-margin link');
[$c1, $b1] = $call(1, 'data');
$check($c1 === 200 && $b1['data']['values'] === true && $b1['data']['manager_link']['href'] === '/workshop/manager#tab=report', 'controller data() (administrator) : amounts and the link to the module where the decision is reported');
[$c4, $b4] = $call(4, 'data');
[$cD] = $call(4, 'decision');
[$cF] = $call(4, 'finish');
[$cN] = $call(null, 'data');
$check($c4 === 403 && $b4['message'] === 'Brak dostępu do produktów (asystent « Nowe produkty »).' && $cD === 403 && $cF === 403 && $cN === 403, 'controller: Piekarz without « products » (and not logged in) → 403 for data(), decision() and finish()');
[$cDe, $bDe] = $call(2, 'decision');
$check($cDe === 422 && str_starts_with((string) $bDe['message'], 'Wybierz decyzję'), 'controller decision() really called : empty body → 422');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() really called : done or blocked by waiting launches');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);
$pa = new PageAccessService(new PageAccessRepository($pdo));
$check(array_map(fn($u) => $pa->canSee($u, LaunchesReviewService::PATH), [1, 2, 3, 4]) === [true, true, true, false]
    && array_map(fn($u) => $pa->canSee($u, '/finances/product-margin'), [1, 2, 3, 4]) === [true, false, true, false], 'page guard = « products » right ; amounts = the « margins » rule of the margin screens');

// --- Aucun envoi, aucune écriture dans les sources : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/LaunchesReviewService.php', 'App/Repositories/Product/LaunchesReviewRepository.php',
    'App/Controllers/Web/Product/LaunchesReviewController.php', 'public/assets/js/product/launches-review.js']));
$check(!preg_match('/UPDATE\s+(n_mgr|final_product|n_recipe|raw_material)|INSERT\s+INTO\s+(n_mgr|final_product|n_wizard)|DELETE\s+FROM|saveLaunch|Mail|Sms|curl_|https?:\/\/|SELECT \*/i', $src),
    'no write to n_mgr_launch / products / recipes, no mail / SMS, no hard-coded URL, no SELECT *');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('launches_reviewed')['link'] === '/products/launches-review' && TaskWizards::byLink('/products/launches-review') === 'launches_reviewed'
    && isset(TaskWizards::AUTO['launches_reviewed']) && PositionTaskService::LINK_AUTO['/products/launches-review'] === 'launches_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['launches_reviewed'][0] === 'Nowe produkty (przegląd miesiąca)' && TaskWizards::CATALOG['launches_reviewed'][0] !== 'Nowe produkty', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/products/launches-review') === 'products' && PageAccess::keyFor('/ajax/products/launches-review') === 'products' && PageAccess::keyFor('/ajax/products/launches-review/finish') === 'products'
    && PageAccess::keyFor('/finances/product-margin') === 'margins' && PageAccess::keyFor('/workshop/manager') === 'manager' && PageAccess::keyFor('/products/new') === 'products',
    'page and ajax under the existing « products » right ; others unchanged');
$check(!isset(PageAccess::PAGES['launches']), 'no new right for this assistant (existing « products »)');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'launches_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/products/launches-review'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Product/LaunchesReviewRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/products/launches-review') === 'index' && $found('GET', '/ajax/products/launches-review') === 'data'
    && $found('POST', '/ajax/products/launches-review/decision') === 'decision' && $found('POST', '/ajax/products/launches-review/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/products/launches-review')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/products/launches-review')[1]['controller'] === LaunchesReviewController::class
    && $allR->dispatch('GET', '/products/new')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/products/12/edit')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing product routes unchanged');

if (getenv('LR_JSON_OUT')) file_put_contents((string) getenv('LR_JSON_OUT'), json_encode(['before' => $boot, 'after' => $after], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_launches_review_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
