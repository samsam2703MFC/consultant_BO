<?php
declare(strict_types=1);
/*
 * Do obsłużenia [160] : une ligne par cas à traiter — commande en retard d'expédition, commande sans proforma,
 * proforma périmée (commande modifiée), proforma non envoyée, rappel de paiement dû (même calcul que
 * Przypomnienia płatności), palettes prêtes pour les documents, écart d'expédition ouvert, e-mail client en erreur.
 * Commandes ouvertes = OpenOrders ; proforma payée, gratuite au prix 0 ou commande close : rien. Échéances, filtres
 * (retard, aujourd'hui, 7 jours, règle, « moje » selon le poste), actions directes et liens. Rien n'est modifié.
 *
 *   php tests/order_queue_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_order_queue_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Client\Order\OrderQueueService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectOq(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_order_queue_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "order_queue_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency',
                 'customer', 'document_order_shipping_difference', 'document_order_shipping_difference_products', 'carrier', 'carrier_vehicle', 'category_product',
                 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings', 'supplier', 'raw_material_price_changelog', 'cost_month',
                 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month', 'cost_media_section_allocation', 'cost_media_type',
                 'communication_message', 'communication_message_attachment', 'communication_email_account', 'erp_company_data', 'flattened_recipe_recipe',
                 'product_preparation_type', 'product_recipe_preparation_connection', 'product_recipe_type', 'product_recipe_preparation_step',
                 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status', 'test_product_type', 'test_product_stage',
                 'test_product_changelog', 'complaint', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery', 'document_cmr', 'document_wz',
                 'shipment_log', 'app_notification', 'outbox', 'webhook_outbox_shipments', 'cron_notification_outbox', 'cron_runs',
                 'document_proforma', 'document_proforma_product', 'document_invoice'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x'), (2, 'Piotr', 'L', 'Piotr L.', 'p@x.test', '1', 0, 'piotr', 'x'), (3, 'Ola', 'M', 'Ola M.', 'o@x.test', '1', 0, 'ola', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) SELECT 1, id FROM n_position WHERE code = 'ADMINISTRACJA'");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) SELECT 2, id FROM n_position WHERE code = 'LOGISTYKA'");

    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO customer (id, company) VALUES (51, 'Klient A'), (52, 'Klient B')");
    $pdo->exec("INSERT INTO final_product (id, name, active) VALUES (900, 'Croissant', 1), (901, 'Chleb', 1)");
    // Commandes : 101 en retard sans proforma ; 102 proforma non envoyée ; 103 proforma périmée (quantité changée, envoyée) ;
    // 104 proforma payée ; 105 close (statut 3) ; 106 déjà avec WDT ; 107 gratuite (proforma au prix 0, envoyée au franchisé).
    $pdo->exec("INSERT INTO customer_order (id, id_customer, reference, status, expected_shipping_date, create_order_date, id_proforma, id_wdt, is_freebie) VALUES
        (101, 51, 'R-101', 1, '2026-10-24', '2026-10-20 08:00:00', NULL, NULL, 0),
        (102, 52, 'R-102', 1, '2026-10-28', '2026-10-24 08:00:00', 201, NULL, 0),
        (103, 51, 'R-103', 1, '2026-10-27', '2026-10-19 08:00:00', 202, NULL, 0),
        (104, 52, 'R-104', 1, '2026-10-27', '2026-10-19 08:00:00', 203, NULL, 0),
        (105, 52, 'R-105', 3, '2026-10-10', '2026-10-05 08:00:00', NULL, NULL, 0),
        (106, 52, 'R-106', 1, '2026-10-15', '2026-10-10 08:00:00', NULL, 77, 0),
        (107, 51, 'R-107', 1, '2026-10-29', '2026-10-25 08:00:00', 204, NULL, 1)");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, purchase_price) VALUES
        (101, 900, 4, 20), (102, 900, 10, 20), (103, 900, 8, 20), (104, 901, 3, 15), (107, 900, 2, 20)");
    $pdo->exec("INSERT INTO document_proforma (id, doc_num, id_customer, established_date, status, is_sent_to_client, is_sent_to_franchise) VALUES
        (201, 'FP-201', 52, '2026-10-25', 'issued', 0, 0),
        (202, 'FP-202', 51, '2026-10-20', 'issued', 1, 0),
        (203, 'FP-203', 52, '2026-10-19', 'paid', 1, 0),
        (204, 'FP-204', 51, '2026-10-26', 'issued', 0, 1),
        (205, 'FP-205', 52, '2026-09-01', 'issued', 1, 0),
        (206, 'FP-206', 52, '2026-08-01', 'archived', 1, 0)");
    $pdo->exec("INSERT INTO document_proforma_product (id_proforma, id_product, product_name, quantity, price_per_carton, total_price_net) VALUES
        (201, 900, 'Croissant', 10, 20, 200), (202, 900, 'Croissant', 5, 20, 100), (203, 901, 'Chleb', 3, 15, 45), (204, 900, 'Croissant', 2, 0, 0),
        (205, 901, 'Chleb', 4, 15, 60), (206, 901, 'Chleb', 1, 15, 15)");
    // Proforma 205 : deux rappels déjà envoyés → le 3e est dû 14 jours après la proforma.
    $pdo->exec("INSERT INTO communication_message (id, message_type, id_customer, id_proforma, recipient_email, subject_text, body_text, status, attempts, created_at, next_try_at) VALUES
        (11, 'proforma_reminder_1', 52, 205, 'b@k.test', 'R1', 'x', 'sent', 1, '2026-09-05 10:00:00', '2026-09-05 10:00:00'),
        (12, 'proforma_reminder_2', 52, 205, 'b@k.test', 'R2', 'x', 'sent', 1, '2026-09-09 10:00:00', '2026-09-09 10:00:00'),
        (13, 'wdt', 51, NULL, 'a@k.test', 'WDT', 'x', 'failed', 5, '2026-10-25 09:00:00', '2026-10-25 16:00:00')");
    $pdo->exec("UPDATE communication_message SET error_message = 'SMTP 550' WHERE id = 13");
    $pdo->exec("INSERT INTO palets_delivery (id_palet, client, delivery_date, status, id_customer) VALUES
        (1, '', '2026-10-25', 2, 52), (2, '', '2026-10-25', 2, 52), (3, '', '2026-09-01', 2, 52), (4, '', '2026-10-25', 3, 51)");
    $pdo->exec("INSERT INTO document_order_shipping_difference (id, id_customer, established_date) VALUES (31, 51, '2026-10-24')");
    $pdo->exec("INSERT INTO n_shipping_issue (source, id_difference, id_customer, opened_on, due_on, id_owner, status) VALUES ('difference', 31, 51, '2026-10-24', '2026-10-28', 1, 'open')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        PDO::class => $pdo,
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string { throw new RuntimeException('no e-mail in tests'); }
        },
    ]);
    $queue = $builder->build()->get(OrderQueueService::class);
    $now = new DateTimeImmutable('2026-10-26 10:00:00');

    // --- Une ligne par cas
    $o = $queue->overview(null, 'all', false, 1, $now);
    expectOq($o['counts'] === ['late_shipping' => 1, 'no_proforma' => 1, 'proforma_stale' => 1, 'proforma_not_sent' => 1, 'reminder_due' => 2,
        'documents_ready' => 1, 'shipping_issue' => 1, 'send_error' => 1], 'one case per rule ; closed order, order with WDT, paid and free proformas left out');
    $by = [];
    foreach ($o['items'] as $i) $by[$i['key']] = $i;
    expectOq(array_keys($by) === ['reminder_due:proforma:205', 'reminder_due:proforma:202', 'late_shipping:order:101', 'no_proforma:order:101',
        'documents_ready:pallets:2026-10-25:52', 'send_error:email:13', 'proforma_stale:proforma:202', 'proforma_not_sent:proforma:201', 'shipping_issue:issue:1'],
        'sorted by deadline (oldest first)');
    $late = $by['late_shipping:order:101'];
    expectOq($late['due'] === '2026-10-24' && $late['days'] === -2 && $late['tone'] === 'danger' && $late['customer'] === 'Klient A'
        && $late['title'] === 'Zamówienie #101 · R-101' && $late['actions'][0]['url'] === '/logistics?date=2026-10-24', 'late order: deadline, customer, loading day');
    $np = $by['no_proforma:order:101'];
    expectOq($np['actions'][0]['post'] === '/ajax/client-orders/101/proforma' && str_contains($np['actions'][0]['confirm'], '#101') && $np['positions'] === ['ADMINISTRACJA'],
        'order without proforma: issue it (with confirmation)');
    $st = $by['proforma_stale:proforma:202'];
    expectOq($st['actions'][0]['post'] === '/ajax/client-orders/103/proforma/regenerate' && $st['note'] === 'zamówienie zmienione po wystawieniu proformy' && $st['days'] === 1,
        'stale proforma: order changed after the proforma, regenerate');
    $ns = $by['proforma_not_sent:proforma:201'];
    expectOq($ns['actions'][0]['url'] === '/communication/compose/proforma/201' && $ns['due'] === '2026-10-28' && $ns['tone'] === 'info', 'proforma not sent: prepare the e-mail');
    $r205 = $by['reminder_due:proforma:205'];
    expectOq($r205['due'] === '2026-09-15' && $r205['actions'][0]['label'] === 'Przypomnienie 3/5' && $r205['actions'][0]['url'] === '/communication/compose/reminder/proforma/205'
        && $r205['actions'][1]['post'] === '/ajax/proforma-documents/205/paid' && str_contains($r205['detail'], '2/5'), 'reminder: next level after those already sent, mark paid');
    expectOq($by['reminder_due:proforma:202']['due'] === '2026-10-23' && $by['reminder_due:proforma:202']['actions'][0]['label'] === 'Przypomnienie 1/5', 'reminder 1/5 due 3 days after the proforma');
    expectOq($by['documents_ready:pallets:2026-10-25:52']['title'] === 'Palety gotowe : 2 palet na 25.10.2026'
        && $by['documents_ready:pallets:2026-10-25:52']['actions'][0]['url'] === '/shipping/wizard?date=2026-10-25', 'ready pallets of the last 14 days: ship the day');
    expectOq($by['shipping_issue:issue:1']['note'] === 'odpowiedzialny : Anna K.' && $by['shipping_issue:issue:1']['actions'][0]['url'] === '/shipments/differences', 'open shipping difference');
    expectOq($by['send_error:email:13']['detail'] === 'SMTP 550' && $by['send_error:email:13']['customer'] === 'Klient A' && $by['send_error:email:13']['note'] === 'a@k.test', 'failed customer e-mail');
    expectOq($late['owners'] !== [] && $o['position'] === 'ADMINISTRACJA' && $o['overdue'] === 6, 'owners named ; user position ; overdue count');

    // --- Filtres : échéance, règle, « moje »
    expectOq(count($queue->overview(null, 'overdue', false, 1, $now)['items']) === 6 && count($queue->overview(null, 'today', false, 1, $now)['items']) === 6
        && count($queue->overview(null, 'week', false, 1, $now)['items']) === 9, 'deadline filters');
    $rem = $queue->overview('reminder_due', 'all', false, 1, $now);
    expectOq(count($rem['items']) === 2 && $rem['when_counts']['overdue'] === 2 && $rem['counts']['late_shipping'] === 1, 'rule filter keeps the counters of the other rules');
    $admin = $queue->overview(null, 'all', true, 1, $now);
    expectOq(count($admin['items']) === 7 && $admin['mine_count'] === 7 && !in_array('late_shipping', array_column($admin['items'], 'rule'), true), 'mine: administration');
    $logi = $queue->overview(null, 'all', true, 2, $now);
    expectOq(array_column($logi['items'], 'rule') === ['late_shipping', 'documents_ready', 'shipping_issue'], 'mine: logistics');
    expectOq($queue->overview(null, 'all', false, 3, $now)['position'] === null && count($queue->overview(null, 'all', true, 3, $now)['items']) === 0, 'user without position');
    expectOq($queue->overview('nonsense', 'nonsense', false, null, $now)['rule'] === null && $queue->overview('nonsense', 'nonsense', false, null, $now)['when'] === 'all', 'unknown filters ignored');
    expectOq($o['top'] === ['reminder_due', 'late_shipping', 'no_proforma'], 'tiles: busiest rules first');
    expectOq($queue->counts($now) === $o['counts'], 'counts for Dzisiaj = queue');

    // --- Rien n'est modifié par la lecture
    expectOq((int) $pdo->query("SELECT COUNT(*) FROM document_proforma WHERE status = 'issued'")->fetchColumn() === 4
        && $pdo->query("SELECT id_proforma FROM customer_order WHERE id = 101")->fetchColumn() === null, 'read only');

    echo "order_queue_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
