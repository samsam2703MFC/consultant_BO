<?php
declare(strict_types=1);
/*
 * Audit 2, lot D : achats et clôture du mois.
 *  - P12 réception : lot (majuscules) et DLC par ligne, problème → non-conformité fournisseur liée à la réception,
 *    tablette : salarié enregistré, prix toujours celui de la commande, lots reçus proposés en FEFO ;
 *  - P15 commandes en lot : jour de livraison = premier jour habituel du fournisseur (fiche, sinon historique),
 *    langue de la fiche fournisseur ;
 *  - P19 inventaire en masse : un enregistrement pour toute la feuille (une ligne fausse bloque tout) ; min/max 1–2 semaines
 *    seulement pour les matières consommées.
 *
 *   php tests/audit2_lot_d_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_lot_d_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Repositories\Workshop\OutputRepository;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Supply\DeliveryScheduleService;
use App\Services\Workshop\Supply\SupplyService;

function expectLotD(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Sans base : jours habituels de livraison
expectLotD(DeliveryScheduleService::usualDays(1 | 4, ['2026-10-01', '2026-10-08']) === [1, 3], 'mask of the supplier card wins over history (mon + wed)');
expectLotD(DeliveryScheduleService::usualDays(null, ['2026-10-01', '2026-10-08', '2026-10-15', '2026-10-06']) === [4], 'history: at least 2 deliveries and a quarter of them (thursdays)');
expectLotD(DeliveryScheduleService::usualDays(null, ['2026-10-06']) === [], 'one delivery is not a habit');
expectLotD(DeliveryScheduleService::nextUsualDay('2026-10-27', [4]) === '2026-10-29', 'next usual day: tuesday → thursday');
expectLotD(DeliveryScheduleService::nextUsualDay('2026-10-29', [4]) === '2026-10-29', 'the base day itself when it is usual');
expectLotD(DeliveryScheduleService::nextUsualDay('2026-10-27', []) === '2026-10-27', 'no usual day: base day kept');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_lot_d_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "audit2_lot_d_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'customer_order_status_type', 'customer_order_changelog', 'carton_stock', 'document_wdt', 'document_wdt_product',
                 'communication_template', 'recipe_competency', 'customer', 'document_order_shipping_difference', 'document_order_shipping_difference_products',
                 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings', 'supplier',
                 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month',
                 'cost_media_section_allocation', 'cost_media_type', 'communication_message', 'communication_message_attachment', 'communication_email_account',
                 'erp_company_data', 'flattened_recipe_recipe', 'product_preparation_type', 'product_recipe_preparation_connection', 'product_recipe_type',
                 'product_recipe_preparation_step', 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status', 'test_product_type',
                 'test_product_stage', 'test_product_changelog', 'complaint', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery',
                 'document_cmr', 'document_wz', 'shipment_log', 'app_notification', 'outbox', 'webhook_outbox_shipments', 'cron_notification_outbox', 'cron_runs',
                 'document_proforma', 'document_invoice'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();

    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    // Fournisseurs : 7 livre le jeudi (fiche) en français, 8 sans jours ni langue ; matières en grammes.
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g')");
    $pdo->exec("INSERT INTO supplier (id, name, email, delivery_weekdays, order_lang, is_active) VALUES (7, 'Młyn', 'mlyn@example.invalid', 8, 'fr', 1), (8, 'Makro', '', NULL, NULL, 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, id_current_supplier) VALUES (41, 'Mąka', 1, 0.002, 7), (42, 'Masło', 1, 0.03, 8)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname) VALUES (5, 'Jan', 'Kowalski')");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active) VALUES (9, 5, 1)");

    $builder = new DI\ContainerBuilder();
    // Aucun envoi possible : le transport du test refuse tout.
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string { throw new RuntimeException('no e-mail in tests'); }
    }]);
    $container = $builder->build();
    $supply = $container->get(SupplyService::class);
    $office = new ApiContext('t1', new Translator('pl'), 1, [], ['prod.supply.write' => true, 'prod.supply.read' => true]);
    $tablet = new ApiContext('t2', new Translator('pl'), null, [], [], [], 3, 9, 1);

    // --- P15 : création groupée, jour habituel et langue du fournisseur
    $created = $supply->createOrders(['week_start' => '2026-11-04', 'lines' => [
        ['raw_material_id' => 41, 'quantity' => 25000], ['raw_material_id' => 42, 'quantity' => 4000]]], $office)['orders'];
    $mlyn = array_values(array_filter($created, fn($o) => $o['supplier']['id'] === 7))[0];
    $makro = array_values(array_filter($created, fn($o) => $o['supplier']['id'] === 8))[0];
    expectLotD(count($created) === 2 && $mlyn['usual_days'] === [4] && $makro['usual_days'] === [], 'one draft per supplier with its usual days');
    $base = max(date('Y-m-d', strtotime('tomorrow')), '2026-11-03');
    expectLotD($mlyn['delivery_date'] === DeliveryScheduleService::nextUsualDay($base, [4]) && (int) date('N', strtotime($mlyn['delivery_date'])) === 4, 'delivery on the next thursday');
    expectLotD($makro['delivery_date'] === $base, 'unknown habits: day before the week (or tomorrow)');
    expectLotD($mlyn['language'] === 'fr' && $makro['language'] === 'pl' && $mlyn['email_to'] === 'mlyn@example.invalid' && $makro['email_to'] === null, 'language and e-mail from the supplier card');

    // --- P12 : réception au bureau (lot, DLC, problème → NC liée)
    $pdo->exec("UPDATE n_po SET status = 'sent' WHERE id IN ({$mlyn['id']}, {$makro['id']})");
    $line = $mlyn['lines'][0]['id'];
    $after = $supply->receive($mlyn['id'], ['received_on' => date('Y-m-d'), 'lines' => [['id' => $line, 'quantity' => 10000, 'lot_number' => ' l-26/10  a ', 'best_before' => '2099-12-31']],
        'problems' => [['line_id' => $line, 'description' => 'Worek rozdarty', 'severity' => 'major', 'quantity' => 1000]]], $office);
    $r = $after['receipts'][0];
    expectLotD($after['status'] === 'partial' && $r['lot_number'] === 'L-26/10 A' && $r['best_before'] === '2099-12-31' && $r['nc_id'] !== null && $r['employee'] === null, 'receipt: lot normalised, DLC, NC linked');
    $nc = $pdo->query("SELECT * FROM n_nc WHERE id = {$r['nc_id']}")->fetch();
    expectLotD($nc['source'] === 'supplier_delivery' && (int) $nc['id_supplier'] === 7 && (int) $nc['id_raw_material'] === 41 && $nc['severity'] === 'major'
        && (float) $nc['quantity'] === 1000.0 && str_contains($nc['description'], 'Worek rozdarty') && $nc['status'] === 'open', 'supplier NC pre-filled from the line');
    try { $supply->receive($mlyn['id'], ['lines' => [['id' => $line, 'quantity' => 1]], 'problems' => [['line_id' => $line, 'description' => '']]], $office); expectLotD(false, 'empty problem refused'); }
    catch (ApiException $e) { expectLotD($e->httpStatus() === 422 || $e->httpStatus() === 400, 'a problem needs a description'); }

    // --- P12 : réception sur la tablette (salarié, prix de la commande, pas de prix montrés)
    $view = $supply->kioskOrder($makro['id'], $tablet);
    expectLotD($view['receivable'] && !array_key_exists('unit_price', $view['lines'][0]) && !array_key_exists('value', $view['lines'][0]), 'tablet view without prices');
    $done = $supply->receiveAtKiosk($makro['id'], ['lines' => [['id' => $view['lines'][0]['id'], 'quantity' => 4000, 'unit_price' => 99, 'lot_number' => 'M-1', 'best_before' => '2099-06-30']]], $tablet);
    $row = $pdo->query("SELECT r.*, l.unit_price AS ordered FROM n_po_receipt r JOIN n_po_line l ON l.id = r.id_po_line WHERE l.id_po = {$makro['id']}")->fetch();
    expectLotD($done['status'] === 'received' && (int) $row['id_employee'] === 9 && $row['created_by'] === null && (float) $row['unit_price'] === (float) $row['ordered'], 'tablet receipt: employee kept, ordered price');
    expectLotD($done['receipts'][0]['employee'] === 'Jan Kowalski' && !array_key_exists('unit_price', $done['receipts'][0]), 'receipt shows who received it on the tablet');
    $anonymous = new ApiContext('t3', new Translator('pl'), null, [], [], [], 3, null, 1);
    try { $supply->receiveAtKiosk($mlyn['id'], ['lines' => [['id' => $line, 'quantity' => 1]]], $anonymous); expectLotD(false, 'anonymous tablet refused'); }
    catch (ApiException $e) { expectLotD($e->httpStatus() === 401, 'tablet receipt needs an identified employee'); }

    // --- P12 : lots reçus proposés à la tablette (FEFO, non périmés)
    $pdo->exec("INSERT INTO n_po_receipt (id_po_line, received_on, quantity, lot_number, best_before) VALUES ({$line}, CURDATE(), 1, 'OLD', '2000-01-01'), ({$line}, CURDATE(), 1, 'SOON', '2098-01-01')");
    $lots = $container->get(OutputRepository::class)->receivedLots([41, 42]);
    expectLotD(array_column($lots[41], 'lot_number') === ['SOON', 'L-26/10 A'] && array_column($lots[42], 'lot_number') === ['M-1'], 'FEFO: nearest DLC first, expired lot hidden');

    // --- P19 : inventaire de toute la feuille, min/max groupé
    try { $supply->addCounts(['counts' => [['id' => 41, 'quantity' => 5000], ['id' => 999, 'quantity' => 1]]], $office); expectLotD(false, 'unknown material refused'); }
    catch (ApiException $e) { expectLotD($e->httpStatus() === 422 && (int) $pdo->query('SELECT COUNT(*) FROM n_mp_count')->fetchColumn() === 0, 'one wrong line: nothing saved'); }
    try { $supply->addCounts(['counts' => [['id' => 41, 'quantity' => -1]]], $office); expectLotD(false, 'negative refused'); }
    catch (ApiException $e) { expectLotD($e->httpStatus() === 422, 'negative count refused'); }
    $inv = $supply->addCounts(['count_date' => date('Y-m-d'), 'note' => 'Inwentaryzacja', 'counts' => [['id' => 41, 'quantity' => 5000], ['id' => 42, 'quantity' => 0]]], $office);
    $counts = $pdo->query('SELECT id_raw_material, quantity, note FROM n_mp_count ORDER BY id_raw_material')->fetchAll();
    expectLotD($inv['saved'] === 2 && count($counts) === 2 && (float) $counts[0]['quantity'] === 5000.0 && (float) $counts[1]['quantity'] === 0.0 && $counts[0]['note'] === 'Inwentaryzacja', 'whole sheet saved at once (zero included)');
    expectLotD((int) $pdo->query("SELECT COUNT(*) FROM n_prod_audit_log WHERE entity = 'material_inventory'")->fetchColumn() === 1, 'one audit entry for the inventory');
    $limits = $supply->suggestLimits(['ids' => [41, 42]], $office);
    expectLotD($limits['updated'] === 0 && $limits['skipped'] === 2 && (int) $pdo->query('SELECT COUNT(*) FROM n_mp_supply')->fetchColumn() === 0, 'no consumption: limits unchanged');

    echo "audit2_lot_d_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
