<?php
declare(strict_types=1);
/*
 * Audit 2, lot E : menu, dossiers, automatismes.
 *  - P5 rappels de paiement : niveaux 1 à reminders.auto_levels envoyés seuls (désactivé par défaut), une fois par
 *    niveau, écart entre niveaux, pas de proforma de plus de 90 jours ; « Opłacone » en lot.
 *  - P7 décisions d'écart exécutables : dosłanie / ponowna dostawa → domówienie pré-remplie (GRATIS, commande d'origine),
 *    reliée à l'écart et close à sa WDT ; przekazanie do reklamacji → non-conformité client (une seule).
 *  - P21 prix des matières : chaque changement garde sa source (manual, offer, invoice, new) ; historique par matière.
 *  - P22 traçabilité : un lot de la tablette → cartons du produit étiquetés le jour (+ trace.label_days) → palettes →
 *    clients et WZ ; rappel par numéro de lot de matière.
 *  - P8 retours : une liste (réclamations sans décision, remplacements à préparer / à expédier, cartons de retour),
 *    « Wysłane » posé au WZ du client pour les remplacements emballés, réclamations et remplacements dans Do obsłużenia.
 *
 *   php tests/audit2_lot_e_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_lot_e_test est recréée puis supprimée.
 * Aucun e-mail ne part : le transport SMTP du test refuse tout, les messages restent en file.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';
if (!defined('ROOT')) define('ROOT', 'http://test.local');

use App\Services\Communication\PaymentReminderAutoService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectLotE(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_lot_e_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "audit2_lot_e_test: SKIPPED (no database: {$e->getMessage()})\n";
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
                 'document_proforma', 'document_invoice', 'cost_month_section', 'cost_logistics_type', 'document_proforma_product', 'ksef_submission', 'complaint_product_to_send_log', 'complaint_product_pending', 'carton_stock_complaint',
                 'carton_pallet_connection', 'document_wz_product'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("INSERT INTO erp_company_data (id, proforma_company_name, proforma_company_address_1, proforma_company_nip) VALUES (1, 'SL Food', 'ul. Testowa 1', '123')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
    }]);
    $container = $builder->build();

    // --- P5 : rappels automatiques
    $day = fn(int $ago) => date('Y-m-d', strtotime("-{$ago} days"));
    $pdo->exec("INSERT INTO customer (id, company, name, surname, email_accountant, communication_lang) VALUES
        (10, 'Sklep A', 'A', 'A', 'compta-a@example.invalid', 'fr'), (11, 'Sklep B', 'B', 'B', '', 'pl')");
    $pdo->exec("INSERT INTO final_product (id, name, ppc, active) VALUES (100, 'Croissant', 10, 1)");
    foreach ([[1, 10, 5], [2, 10, 10], [3, 10, 2], [4, 10, 120], [5, 11, 8], [6, 10, 20]] as [$id, $cust, $ago]) {
        $pdo->exec("INSERT INTO document_proforma (id, doc_num, id_customer, consignee_name, established_date, status) VALUES ({$id}, 'PRO-{$id}', {$cust}, 'X', '{$day($ago)}', 'issued')");
        $pdo->exec("INSERT INTO document_proforma_product (id_proforma, id_product, product_name, quantity, price_per_carton, total_price_net, tva, price_brut, tva_perc, ppc)
            VALUES ({$id}, 100, 'Croissant', 2, 50, 100, 0, 100, 0, 10)");
    }
    // PRO-6 : niveau 1 déjà envoyé il y a 2 jours (à la main) → niveau 2 pas encore (écart de 4 jours).
    $pdo->exec("INSERT INTO communication_message (message_type, id_customer, id_proforma, recipient_email, subject_text, body_text, variables_json, status, created_by, created_at)
        VALUES ('proforma_reminder_1', 10, 6, 'compta-a@example.invalid', 's', 'b', '{}', 'sent', 'Anna K.', NOW() - INTERVAL 2 DAY)");
    $auto = $container->get(PaymentReminderAutoService::class);
    expectLotE($auto->levels() === 0 && $auto->run() === ['skipped' => 'off'], 'automatic reminders are off by default');
    $plan = $auto->plan(null, 2);
    $byDoc = array_column($plan, 'level', 'doc_num');
    ksort($byDoc);
    expectLotE($byDoc === ['PRO-1' => 1, 'PRO-2' => 1, 'PRO-5' => 1], 'plan : level 1 after 3 days, nothing before, nothing after 90 days, gap before level 2');
    $one = array_column($auto->plan(null, 1), 'doc_num');
    sort($one);
    expectLotE($one === array_keys($byDoc), 'same plan with level 1 only');
    $params = $container->get(App\Repositories\Workshop\ParamRepository::class);
    $params->setValue('reminders.auto_levels', '2', 1);
    // Sans compte e-mail actif : rien ne part, on s'arrête au premier.
    $r = $auto->run();
    expectLotE($r['queued'] === 0 && count($r['failed']) === 1 && (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE created_by = 'auto-reminder' AND status = 'queued'")->fetchColumn() === 0, 'no active mail account : stop');
    $pdo->exec("DELETE FROM communication_message WHERE created_by = 'auto-reminder'");
    $pdo->exec("INSERT INTO communication_email_account (id, name, from_name, from_email, smtp_host, smtp_port, smtp_encryption, smtp_username, is_active)
        VALUES (1, 'Test', 'SL Food', 'erp@example.invalid', 'smtp.example.invalid', 587, 'tls', 'erp', 1)");
    $r = $auto->run();
    $msgs = $pdo->query("SELECT id_proforma, message_type, status, recipient_email, created_by FROM communication_message WHERE created_by = 'auto-reminder' ORDER BY id_proforma")->fetchAll();
    expectLotE($r['queued'] === 2 && count($r['failed']) === 1 && str_contains($r['failed'][0]['error'], 'adresu') && $r['failed'][0]['proforma'] === 'PRO-5', 'two reminders queued, the client without e-mail reported');
    expectLotE(array_column($msgs, 'id_proforma') === [1, 2] && $msgs[0]['message_type'] === 'proforma_reminder_1' && $msgs[0]['status'] === 'queued'
        && $msgs[0]['recipient_email'] === 'compta-a@example.invalid', 'level 1 queued to the accountant of the card');
    $att = $pdo->query("SELECT filename, LEFT(content, 4) AS head FROM communication_message_attachment WHERE id_message = (SELECT id FROM communication_message WHERE id_proforma = 1 AND created_by = 'auto-reminder')")->fetch();
    expectLotE($att && $att['head'] === '%PDF', 'proforma PDF attached');
    $r = $auto->run();
    expectLotE($r['queued'] === 0, 'a level is never queued twice (next level waits 4 days)');
    // Après 4 jours (niveau 1 vieux de 4 jours, proforma de 10+4 jours) : niveau 2 pour PRO-2.
    $pdo->exec("UPDATE communication_message SET status = 'sent', created_at = NOW() - INTERVAL 4 DAY WHERE created_by = 'auto-reminder'");
    $levels2 = array_column($auto->plan(), 'level', 'doc_num');
    expectLotE(($levels2['PRO-2'] ?? null) === 2 && ($levels2['PRO-6'] ?? null) === null && !isset($levels2['PRO-1']), 'level 2 after the gap, only from 7 days');
    $params->setValue('reminders.auto_levels', '1', 1);
    expectLotE(!isset(array_column($auto->plan(), 'level', 'doc_num')['PRO-2']), 'level 2 stays manual when only level 1 is automatic');

    // « Opłacone » en lot : la proforma payée sort des rappels.
    $proformas = $container->get(App\Services\Document\ProformaService::class);
    expectLotE($proformas->markPaid(1) && $proformas->markPaid(2), 'mark paid');
    $unpaid = array_column($container->get(App\Services\Communication\CommunicationService::class)->selectableUnpaidProformas(), 'doc_num');
    expectLotE(!in_array('PRO-1', $unpaid, true) && in_array('PRO-3', $unpaid, true), 'paid proformas leave the reminders');

    // --- P7 : décisions d'écart exécutables
    $issues = $container->get(App\Services\Shipping\ShippingIssueService::class);
    $pdo->exec("INSERT INTO final_product (id, name, ppc, active) VALUES (101, 'Pain', 6, 1)");
    $pdo->exec("INSERT INTO document_wdt (id, id_customer, doc_num) VALUES (70, 10, 'WDT/70'), (71, 10, 'WDT/71')");
    $pdo->exec("INSERT INTO customer_order (id, id_customer, reference, invoice_num, status, order_description, expected_shipping_date, id_wdt, order_type) VALUES
        (500, 10, 'R-500', '', 'SHIPPED', '', CURDATE() - INTERVAL 3 DAY, 70, 'STANDARD'), (501, 10, 'R-501', '', 'SHIPPED', '', CURDATE() - INTERVAL 1 DAY, NULL, 'STANDARD')");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, expected_quantity_carton, purchase_price) VALUES (501, 100, 4, 4, 50), (501, 101, 2, 2, 30)");
    $pdo->exec("INSERT INTO document_order_shipping_difference (id, id_customer, consignor_name, consignor_address_1, consignor_address_2, consignor_nip, consignee_name, consignee_address_1, consignee_address_2, consignee_nip, established_date)
        VALUES (80, 10, '', '', '', '', '', '', '', '', CURDATE() - INTERVAL 3 DAY)");
    $pdo->exec("INSERT INTO document_order_shipping_difference_products (id_document_order_shipping_difference, id_product, product_name, quantity_ordered, price_during_ordering, invoiced_quantity, price_during_invoicing)
        VALUES (80, 100, 'Croissant', 3, 50, 1, 50), (80, 101, 'Pain', 2, 30, 2, 30)");
    $pdo->exec("INSERT INTO shipment_log (id, shipment_date, id_customer, id_wz, id_wdt, id_diff, id_employee) VALUES (90, CURDATE() - INTERVAL 3 DAY, 10, 0, 70, 80, 0)");
    $pdo->exec("INSERT INTO n_delivery_stop (id, ship_date, id_customer, status, reason, note, recorded_at, driver_name) VALUES (60, CURDATE() - INTERVAL 1 DAY, 10, 'problem', 'closed', 'Sklep zamknięty', NOW(), 'Jan')");
    $pdo->exec("INSERT INTO n_shipping_issue (id, source, id_difference, id_customer, opened_on, due_on, status) VALUES (1, 'difference', 80, 10, CURDATE(), CURDATE(), 'open')");
    $pdo->exec("INSERT INTO n_shipping_issue (id, source, id_delivery_stop, id_customer, opened_on, due_on, status) VALUES (2, 'delivery', 60, 10, CURDATE(), CURDATE(), 'open'), (3, 'delivery', NULL, 10, CURDATE(), CURDATE(), 'open')");
    try { $issues->supplementPrefill(1); expectLotE(false, 'no prefill before the decision'); } catch (App\Exceptions\CustomException $e) { expectLotE($e->getCode() === 422, 'prefill only for resend / redeliver'); }
    $issues->decide(1, 'resend', '', 1);
    $pre = $issues->supplementPrefill(1);
    expectLotE($pre['customer'] === 10 && $pre['order_type'] === 'SUPPLEMENT' && $pre['is_freebie'] && $pre['parent_order_id'] === 500
        && $pre['products'] === [['id_product' => 100, 'product_name' => 'Croissant', 'quantity_carton' => 2, 'price_net' => 50.0]], 'resend : missing cartons only, free, original order');
    $orderId = $container->get(App\Services\Client\Order\OrderService::class)->insertNewOrder(['id_customer' => 10, 'order_description' => $pre['note'], 'expected_shipping_date' => date('Y-m-d'),
        'order_type' => 'SUPPLEMENT', 'is_freebie' => 1, 'parent_order_id' => $pre['parent_order_id'],
        'products' => array_map(fn($p) => ['id_product' => $p['id_product'], 'quantity' => $p['quantity_carton'], 'price' => $p['price_net']], $pre['products'])]);
    $issues->linkOrder(1, (int) $orderId);
    $order = $pdo->query("SELECT order_type, is_freebie, parent_order_id FROM customer_order WHERE id = " . (int) $orderId)->fetch();
    expectLotE($order['order_type'] === 'SUPPLEMENT' && (int) $order['is_freebie'] === 1 && (int) $order['parent_order_id'] === 500, 'supplement saved as a free domówienie of the original order');
    try { $issues->supplementPrefill(1); expectLotE(false, 'second supplement refused'); } catch (App\Exceptions\CustomException $e) { expectLotE($e->getCode() === 409, 'one supplement per issue'); }
    $issues->sync();
    expectLotE($pdo->query('SELECT status FROM n_shipping_issue WHERE id = 1')->fetchColumn() === 'decided', 'issue stays in progress until the supplement is shipped');
    $pdo->exec("UPDATE customer_order SET id_wdt = 71 WHERE id = " . (int) $orderId);
    $issues->sync();
    expectLotE($pdo->query('SELECT status FROM n_shipping_issue WHERE id = 1')->fetchColumn() === 'done', 'supplement shipped (WDT) : issue closed');
    // Ponowna dostawa : produits du jour de livraison ; réclamation : non-conformité client.
    $issues->decide(2, 'redeliver', '', 1);
    $pre2 = $issues->supplementPrefill(2);
    expectLotE(array_column($pre2['products'], 'quantity_carton', 'id_product') === [100 => 4, 101 => 2] && $pre2['parent_order_id'] === 501, 'redeliver : the products of that delivery day');
    $issues->decide(2, 'complaint', '', 1);
    $nc = $issues->createNc(2, 1);
    $row = $pdo->query("SELECT source, description, severity, number FROM n_nc WHERE id = {$nc['id_nc']}")->fetch();
    expectLotE($nc['created'] && $row['source'] === 'customer' && str_contains($row['description'], 'Sklep A') && str_contains($row['description'], 'Sklep zamknięty') && str_starts_with($row['number'], 'NC-'),
        'complaint : customer non-conformity with the delivery problem');
    expectLotE($issues->createNc(2, 1) === ['id_nc' => $nc['id_nc'], 'created' => false] && (int) $pdo->query('SELECT COUNT(*) FROM n_nc')->fetchColumn() === 1, 'one non-conformity per issue');
    try { $issues->decide(3, 'redeliver', '', 1); $issues->supplementPrefill(3); expectLotE(false, 'no lines'); } catch (App\Exceptions\CustomException $e) { expectLotE($e->getCode() === 422, 'nothing to resend : refused'); }

    // --- P8 : retours
    $returns = $container->get(App\Repositories\Client\Complaint\ReturnsRepository::class);
    $pdo->exec("INSERT INTO complaint (id, id_customer, id_product, acceptance_status, acceptance_timestamp) VALUES (1, 10, 100, 'NEW', NOW() - INTERVAL 5 DAY), (2, 10, 100, 'ACCEPTED', NOW() - INTERVAL 5 DAY)");
    $pdo->exec("INSERT INTO complaint_product_pending (id, id_customer, id_final_product, pending_product_qty) VALUES (1, 10, 101, 3), (2, 11, 100, 0)");
    $pdo->exec("INSERT INTO complaint_product_to_send_log (id, id_customer, id_product, carton_qty, status, shipping_date) VALUES
        (1, 10, 100, 2, 1, CURDATE()), (2, 10, 101, 1, 0, CURDATE()), (3, 11, 100, 1, 1, CURDATE() + INTERVAL 3 DAY), (4, 10, 100, 5, 2, CURDATE())");
    $pdo->exec("INSERT INTO carton_stock_complaint (id_carton, shipping_date, id_customer, status) VALUES (900, CURDATE(), 10, 1), (901, CURDATE(), 10, 1), (902, CURDATE(), 10, 2)");
    expectLotE(array_column($returns->openComplaints(), 'id') === [1] && array_column($returns->pendingReplacements(), 'id') === [1]
        && array_column($returns->toShip(), 'id') === [1, 2, 3] && $returns->returnCartons()[0]['cartons'] == 2, 'returns page : open complaint, replacement to prepare, to ship, return cartons');
    $q = $container->get(App\Services\Client\Order\OrderQueueService::class)->overview(null, 'all', false, null);
    $rules = array_count_values(array_column($q['items'], 'rule'));
    $complaintItem = array_values(array_filter($q['items'], fn($i) => $i['rule'] === 'complaint_open'))[0] ?? null;
    expectLotE(($rules['complaint_open'] ?? 0) === 1 && ($rules['return_to_ship'] ?? 0) === 3 && $complaintItem && $complaintItem['days'] < 0
        && $complaintItem['positions'] === ['ADMINISTRACJA', 'JAKOSC'], 'complaints and replacements in Do obsłużenia (complaint overdue after 3 days)');
    expectLotE($returns->markShippedWithWz(10, date('Y-m-d')) === 1
        && $pdo->query('SELECT GROUP_CONCAT(status ORDER BY id) FROM complaint_product_to_send_log')->fetchColumn() === '2,0,1,2', 'WZ of the client : packed replacement shipped, unpacked and other clients unchanged');

    // --- P21 : source des prix des matières
    $pdo->exec("INSERT INTO supplier (id, name, phone, email) VALUES (5, 'Młyn', '', 'mlyn@example.invalid')");
    $pdo->exec("INSERT INTO raw_material (id, name, id_current_supplier, package_size_in_base_unit, id_unit, price_per_unit) VALUES (300, 'Mąka', 5, 25000, 1, 0.002)");
    $prices = $container->get(App\Services\Material\MaterialPriceService::class);
    $prices->logInitial(300, ['id_current_supplier' => 5, 'pack_price' => 50, 'package_size_in_base_unit' => 25000]);
    $prices->update(300, ['id_raw_material' => 300, 'id_current_supplier' => 5, 'package_size_in_base_unit' => 25000, 'id_unit' => 1, 'pack_price' => 55], 'offer');
    $prices->update(300, ['id_raw_material' => 300, 'id_current_supplier' => 5, 'package_size_in_base_unit' => 25000, 'id_unit' => 1, 'pack_price' => 54], 'bogus');
    $hist = $prices->history(300);
    expectLotE(count($hist) === 3 && array_count_values(array_column($hist, 'source')) == ['new' => 1, 'offer' => 1, 'manual' => 1]
        && $hist[0]['supplier'] === 'Młyn', 'price history : new, offer, unknown source stored as manual, supplier name');
    expectLotE(abs((float) $pdo->query('SELECT price_per_unit FROM raw_material WHERE id = 300')->fetchColumn() - 54 / 25000) < 1e-9, 'last price applied');
    expectLotE($params->get('prices.manual_edit', '') === '1', 'manual price edits stay allowed by default');

    // --- P22 : lot de production → clients
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, active) VALUES (50, 'TST', 'Test', 'Test', 1)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, base_unit) VALUES (60, 'product', 100, 'szt')");
    $pdo->exec("INSERT INTO n_prod_output (id, work_date, id_group, id_item, quantity, lot_code) VALUES (70, '2026-03-02', 50, 60, 200, '260302-TST-01')");
    $pdo->exec("INSERT INTO n_prod_output_lot (id_output, id_material, quantity, lot_number) VALUES (70, 300, 1, 'MK-4711')");
    $pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, expiration_date, status, date_add, date_pallet) VALUES
        (1, 1, 100, '2026-09-01', 3, '2026-03-02', '2026-03-04'), (2, 1, 100, '2026-09-01', 3, '2026-03-03', '2026-03-04'), (3, 1, 100, '2026-09-01', 2, '2026-03-02', '2026-03-05'),
        (4, 1, 100, '2026-09-01', 1, '2026-03-02', NULL), (5, 1, 100, '2026-09-01', 3, '2026-03-05', '2026-03-06'), (6, 1, 101, '2026-09-01', 3, '2026-03-02', '2026-03-04')");
    $pdo->exec("INSERT INTO palets_delivery (id_palet, client, delivery_date, status, id_customer) VALUES (1, 'Sklep A', '2026-03-04', 3, 10), (2, 'Sklep B', '2026-03-05', 2, 11)");
    $pdo->exec("INSERT INTO carton_pallet_connection (id_palet, id_carton) VALUES (1, 1), (1, 2), (2, 3), (1, 5), (1, 6)");
    $pdo->exec("INSERT INTO document_wz (id, doc_num, id_customer, established_date) VALUES (40, 'WZ-0040', 10, '2026-03-04')");
    $pdo->exec("INSERT INTO document_wz_product (id_document_wz, id_product, name, quantity) VALUES (40, 100, 'Croissant', 2)");
    $outputs = $container->get(App\Repositories\Workshop\OutputRepository::class);
    $trace = $outputs->trace('2026-03-01', '2026-03-03', null, 'MK-4711');
    expectLotE(count($trace) === 1 && (int) $trace[0]['id_final_product'] === 100, 'material lot finds the production lot');
    $rows = $outputs->cartonsShipped([100], '2026-03-02', '2026-03-03');
    $byCustomer = [];
    foreach ($rows as $r) $byCustomer[$r['customer'] ?? '-'] = ($byCustomer[$r['customer'] ?? '-'] ?? 0) + (int) $r['cartons'];
    ksort($byCustomer);
    expectLotE($byCustomer === ['-' => 1, 'Sklep A' => 2, 'Sklep B' => 1], 'cartons of the product labelled that day and the next : 2 shipped to A, 1 on a pallet for B, 1 in stock (not the 05.03 carton, not the other product)');
    $wz = array_values(array_unique(array_filter(array_column($rows, 'wz'))));
    expectLotE($wz === ['WZ-0040'], 'delivery note of the customer found');

    echo "audit2_lot_e_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
