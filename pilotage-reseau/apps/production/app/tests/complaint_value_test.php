<?php
/**
 * [362] Valeur d'une réclamation : la quantité est en pièces, le prix enregistré est celui du carton (ligne WDT) →
 * valeur = cartons entamés (pièces ÷ pièces par carton de la ligne de facture, sinon de la fiche ; arrondi au carton
 * supérieur) × prix du carton.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_complaint_value_test recréée (tables minimales), supprimée à la fin. Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Analysis\MonthlyReportRepository;
use App\Repositories\Complaint\ComplaintRepository;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_complaint_value_test'); $pdo->exec('CREATE DATABASE erp_complaint_value_test'); $pdo->exec('USE erp_complaint_value_test');
$pdo->exec('CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), ppc DECIMAL(10,2) NULL)');
$pdo->exec('CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40))');
$pdo->exec('CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, id_product INT, ppc DECIMAL(10,2) NULL, price_carton DECIMAL(10,2))');
$pdo->exec("CREATE TABLE complaint (id INT AUTO_INCREMENT PRIMARY KEY, complaint_type VARCHAR(16) DEFAULT 'PRODUCT', complaint_reason VARCHAR(64) NULL, id_customer INT NULL,
    invoice_num VARCHAR(40) NULL, id_product INT NULL, product_quantity DECIMAL(10,2), accepted_quantity DECIMAL(10,2) NULL, single_purchase_price DECIMAL(10,2),
    acceptance_status VARCHAR(16), reported_at DATETIME, acceptance_timestamp DATETIME NULL)");
$pdo->exec('CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80))');
$pdo->exec('CREATE TABLE n_prod_item (id INT PRIMARY KEY, id_final_product INT)');
$pdo->exec('CREATE TABLE n_prod_product_settings (id_item INT, id_primary_group INT)');
$pdo->exec('CREATE TABLE n_prod_group (id INT PRIMARY KEY, sort_order INT, name_pl VARCHAR(80) NULL)');
$pdo->exec(file_get_contents(__DIR__ . '/../migrations/tfb/215_complaint_seen.sql')); // [367] lue par la liste et le détail
$pdo->exec('CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(60), surname VARCHAR(60))');
$pdo->exec('CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT)');
// Baguette : 30 pièces par carton sur la facture (fiche : 25), carton 30 € ; Blanc : rien sur la facture, fiche 6 / carton ; Inconnu : sans ppc.
$pdo->exec("INSERT INTO final_product VALUES (1, 'Baguette', 25), (2, 'Blanc', 6), (3, 'Inconnu', NULL), (4, 'Tarte', 6)");
$pdo->exec("INSERT INTO document_wdt VALUES (10, 'WDT/1/2026')");
$pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, ppc, price_carton) VALUES (10, 1, 30, 30)");
$pdo->exec("INSERT INTO complaint (invoice_num, id_product, product_quantity, accepted_quantity, single_purchase_price, acceptance_status, reported_at) VALUES
    ('WDT/1/2026', 1, 120, 120, 30, 'ACCEPTED', '2026-11-10 10:00:00'),
    ('WDT/9/2026', 2, 12, 12, 14, 'ACCEPTED', '2026-11-11 10:00:00'),
    ('WDT/9/2026', 3, 5, 5, 8, 'ACCEPTED', '2026-11-12 10:00:00'),
    ('WDT/9/2026', 4, 7, 7, 12, 'ACCEPTED', '2026-11-13 10:00:00')");
$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$rows = array_column((new ComplaintRepository($pdo))->chartRows(), null, 'product');
$val = fn(string $p) => ComplaintRepository::value((float) $rows[$p]['accepted_quantity'], (float) $rows[$p]['ppc'], (float) $rows[$p]['single_purchase_price']);
$check($val('Baguette') === 120.0, '120 baguettes, 30 / carton on the invoice, 30 € carton → 120 € (was 3 600 €)');
$check($val('Blanc') === 28.0, '12 pieces, no invoice line → product card ppc 6 → 2 cartons × 14 € = 28 €');
$check($val('Inconnu') === 40.0, 'no ppc anywhere → treated as 1 piece per carton');
$check($val('Tarte') === 24.0, '7 pieces, 6 per carton → 2 started cartons × 12 € = 24 €');
$m = new MonthlyReportRepository($pdo);
$check(round((float) $m->complaintTotals('2026-11-01', '2026-11-30')['value'], 2) === 212.0, 'monthly report total uses the same rule (120 + 28 + 40 + 24)');
$byProduct = array_column($m->complaintsByProduct('2026-11-01', '2026-11-30'), 'value', 'name');
$check(round((float) ($byProduct['Baguette'] ?? 0), 2) === 120.0, 'monthly report by product uses the same rule');
// [363] Fenêtre de détail : pièces par carton de la facture et ce qui a été envoyé.
$pdo->exec('ALTER TABLE complaint ADD requested_action VARCHAR(16) NULL, ADD compensation_method VARCHAR(16) NULL, ADD client_comment TEXT NULL, ADD production_response TEXT NULL');
$pdo->exec('ALTER TABLE document_wdt ADD established_date DATE NULL, ADD total_net DECIMAL(10,2) NULL');
$pdo->exec('ALTER TABLE document_wdt_product ADD quantity DECIMAL(10,2) NULL, ADD total_net DECIMAL(10,2) NULL');
$pdo->exec('UPDATE document_wdt_product SET quantity = 10, total_net = 300');
$repo = new ComplaintRepository($pdo);
$baguetteId = (int) $pdo->query("SELECT id FROM complaint WHERE id_product = 1")->fetchColumn();
$d = $repo->detailRow($baguetteId);
$check($d !== null && (float) $d['ppc'] === 30.0 && $d['product'] === 'Baguette' && (int) $d['wdt_id'] === 10, 'detail: invoice ppc and WDT found');
$lines = $repo->shippedLines(10, 1);
$check(count($lines) === 1 && (float) $lines[0]['quantity'] === 10.0 && (float) $lines[0]['price_carton'] === 30.0, 'detail: what was sent on the invoice (10 cartons × 30 €)');

// [367] Grupa widziała reklamację : pierwsze « Widziane » zostaje, etykieta na liście i w oknie.
$pdo->exec("INSERT INTO n_prod_group (id, sort_order, name_pl) VALUES (7, 1, 'Piekarnia')");
$pdo->exec("INSERT INTO production_employee VALUES (30, 'Osoba', 'A'), (31, 'Osoba', 'B')");
$pdo->exec('INSERT INTO n_prod_employee VALUES (3, 30), (4, 31)');
$board = new \App\Repositories\Workshop\ComplaintBoardRepository($pdo);
$check(($repo->detailRow($baguetteId)['seen_label'] ?? null) === null, 'not seen yet: no label');
$board->markSeen($baguetteId, 7, 3);
$board->markSeen($baguetteId, 7, 4);
$seen = $board->seen([$baguetteId], 7);
$check(($seen[$baguetteId]['name'] ?? null) === 'Osoba A', 'first « seen » of the group stays (who)');
$label = (string) $repo->detailRow($baguetteId)['seen_label'];
$check(str_contains($label, 'Piekarnia') && str_contains($label, 'Osoba A') && preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/', $label) === 1, 'detail label: date · group · person');
$pdo->exec('ALTER TABLE complaint ADD complaint_uuid VARCHAR(40) NULL, ADD id_order INT NULL');
$all = array_column($repo->getAll(), 'seen_label', 'id');
$check(str_contains((string) ($all[$baguetteId] ?? ''), 'Piekarnia'), 'list label for the seen complaint');

$pdo->exec('DROP DATABASE erp_complaint_value_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
