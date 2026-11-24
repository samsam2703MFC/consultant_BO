<?php
/**
 * [428] Stan ogólny (/logistics/stock?tab=general) : « Zamówione » = commandes ouvertes de la semaine de production
 * (même fenêtre que la feuille d'équipe) + les commandes en retard encore ouvertes ; les semaines suivantes et les
 * commandes sans date sont à part (« później ») et ne réduisent pas « Zostaje po wysyłce ». GRATIS compris.
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), base erp_stock_general_test recréée puis supprimée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Logistics\StockPalletRepository;
use App\Services\Logistics\StockPalletService;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_stock_general_test'); $pdo->exec('CREATE DATABASE erp_stock_general_test'); $pdo->exec('USE erp_stock_general_test');
foreach ([
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), id_category INT NULL, ppc INT, active TINYINT DEFAULT 1)',
    "CREATE TABLE n_prod_item (id INT PRIMARY KEY, id_final_product INT NULL, item_type VARCHAR(16) DEFAULT 'product')",
    'CREATE TABLE n_prod_product_settings (id_item INT PRIMARY KEY, mode VARCHAR(3), stock_min DECIMAL(12,3) NULL, stock_max DECIMAL(12,3) NULL)',
    'CREATE TABLE n_stock_period (id INT PRIMARY KEY, name VARCHAR(80), status VARCHAR(16), date_from DATE, date_to DATE)',
    'CREATE TABLE n_stock_period_item (id_period INT, id_item INT, stock_min DECIMAL(12,3) NULL, stock_max DECIMAL(12,3) NULL)',
    'CREATE TABLE carton_stock (id_carton INT AUTO_INCREMENT PRIMARY KEY, id_product INT, status INT)',
    'CREATE TABLE customer_order (id INT PRIMARY KEY, status INT, id_wdt INT NULL, is_freebie TINYINT NULL, expected_shipping_date DATETIME NULL)',
    'CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))',
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, id_customer INT, established_date DATE, currency VARCHAR(3))',
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT, name VARCHAR(120), quantity DECIMAL(10,2), price_carton DECIMAL(10,2))',
    'CREATE TABLE erp_company_other_settings (id INT PRIMARY KEY, exchange_rate_euro DECIMAL(10,4))',
    'CREATE TABLE n_wdt_superseded (id_document_wdt INT PRIMARY KEY)',
] as $sql) $pdo->exec($sql);

// Semaine de production du lundi 23.11.2026 au dimanche 29.11.2026 (aujourd'hui : mardi 24.11).
$pdo->exec("INSERT INTO category_product VALUES (1, 'Pieczywo')");
$pdo->exec("INSERT INTO final_product VALUES (1, 'Baguette Tradition 500 g.', 1, 18, 1)");
$pdo->exec("INSERT INTO n_prod_item VALUES (10, 1, 'product')");
$pdo->exec("INSERT INTO n_prod_product_settings VALUES (10, 'MTS', 1854, 5544)");
for ($i = 0; $i < 125; $i++) $pdo->exec('INSERT INTO carton_stock (id_product, status) VALUES (1, ' . ($i < 120 ? 1 : 2) . ')');
$pdo->exec('INSERT INTO carton_stock (id_product, status) VALUES (1, 3)'); // déjà parti : hors stock
$pdo->exec("INSERT INTO customer_order VALUES
    (1, 1, NULL, 0, '2026-11-24 00:00:00'), (2, 1, NULL, 1, '2026-11-26 00:00:00'), (3, 1, NULL, 0, '2026-11-20 00:00:00'),
    (4, 1, NULL, 0, '2026-12-01 00:00:00'), (5, 1, NULL, 0, NULL), (6, 1, 55, 0, '2026-11-24 00:00:00'), (7, 2, NULL, 0, '2026-11-24 00:00:00')");
$pdo->exec('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (1, 1, 80), (2, 1, 2), (3, 1, 5), (4, 1, 40), (5, 1, 3), (6, 1, 99), (7, 1, 99)');

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$g = (new StockPalletService(new StockPalletRepository($pdo)))->generalStock('2026-11-24');
$r = $g['categories'][0]['rows'][0];
$check($g['week_start'] === '2026-11-23' && $g['week_end'] === '2026-11-29' && $g['week_label'] === '23.11 – 29.11', 'production week 23.11 – 29.11 (Monday start without settings)');
$check($r['stock'] === 125 && $r['pallet'] === 5, 'stock : free 120 + on pallets 5 = 125 (shipped carton excluded)');
$check($r['ordered'] === 87 && $r['ordered_late'] === 5, 'ordered this week : 80 + 2 GRATIS + 5 overdue (still open) = 87 ; orders with WDT / other status excluded');
$check($r['ordered_later'] === 43, 'later : next week 40 + without date 3 = 43, shown apart');
$check($r['left'] === 38 && $r['state'] === 'low' && $r['min'] === 103, 'left after shipping = 125 − 87 = 38 (not 125 − 130) ; under min 1 854 szt. = 103 kart. : low');
$check($g['totals']['ordered'] === 87 && $g['totals']['ordered_later'] === 43 && $g['totals']['ordered_late'] === 5, 'totals');
$all = (new StockPalletRepository($pdo))->generalStock(42);
$check((int) round((float) $all[0]['ordered_week']) === 127 && (int) round((float) $all[0]['ordered_later']) === 3 && (int) round((float) $all[0]['ordered']) === 130, 'repository without week : every dated order counts, undated apart');
$tw = (string) file_get_contents(__DIR__ . '/../App/Views/logistics/stock_pallets.twig');
$check(str_contains($tw, 'tydzień {{ general.week_label }}') && str_contains($tw, 'w tym zaległe {{ r.ordered_late }}') && str_contains($tw, '+ {{ r.ordered_later }} później'), 'screen : week in the header, overdue and later under the number');

$pdo->exec('DROP DATABASE erp_stock_general_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
