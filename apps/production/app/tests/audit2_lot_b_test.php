<?php
declare(strict_types=1);
/*
 * Audit 2, lot B : boucler l'expédition.
 *  - une seule définition de commande ouverte : liste « Oczekujące » = sans WDT ; une commande avec WDT est dans l'archive ;
 *  - Wyślij dzień : commandes de la WDT « W transporcie » (journal), avis boutique mis en file (pas d'envoi direct),
 *    même contenu = même avis ; client sans boutique ignoré ; boutique sans identifiants signalée ;
 *  - « Oznacz jako wysłane i powiadom » : même règle ; livraison confirmée par le chauffeur → « Zrealizowane » ;
 *  - file des avis : sans clé valable rien ne part, un échec est reporté (adresse locale fermée, aucun envoi externe) ;
 *  - avis de livraison : un seul par client, jour et canal (automate et page), « wyślij ponownie » seulement à la main ;
 *  - onglets : Dzień wysyłki (sans /logistics/week, onglet « Ciężarówki » avec le plan 12 tygodni [200]), Magazyn, Opakowania.
 *
 *   php tests/audit2_lot_b_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_lot_b_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Web\PageTabs;
use App\Domain\Client\Order\CustomerOrderStatus;
use App\Repositories\Client\Order\OrderRepository;
use App\Services\Client\Order\OrderFulfilmentService;
use App\Services\Client\Order\OrderService;
use App\Services\Client\Order\ShipmentOutboxDispatcher;
use App\Services\Logistics\DeliveryNoticeService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectLotB(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Sans base : onglets et règle des avis de livraison
$tab = fn(string $p) => ($g = PageTabs::forPath($p)) ? $g['key'] . ':' . (array_values(array_filter($g['tabs'], fn($t) => $t['active']))[0]['url'] ?? '') : null;
expectLotB($tab('/logistics') === 'shipping_day:/logistics' && $tab('/logistics/week') === 'trucks:/logistics/week' && $tab('/logistics/plan') === 'trucks:/logistics/plan'
    && $tab('/logistics/tours') === null
    && $tab('/shipping/wizard') === 'shipping_day:/shipping/wizard' && $tab('/logistics/deliveries') === 'shipping_day:/logistics/deliveries', 'Dzień wysyłki: 5 tabs, week / tours stay apart');
expectLotB($tab('/workshop/stock') === 'warehouse:/workshop/stock' && $tab('/workshop/stock/mts') === 'warehouse:/workshop/stock/mts'
    && $tab('/workshop/stock-movements') === 'warehouse:/workshop/stock-movements' && $tab('/workshop/products') === 'products:/workshop/products', 'Magazyn tabs');
expectLotB($tab('/audits/product-weights') === 'packaging:/audits/product-weights' && $tab('/ppwr/reports') === 'packaging:/ppwr/reports'
    && !isset(PageTabs::GROUPS['bdo']) && !isset(PageTabs::GROUPS['ppwr']), 'Opakowania: BDO + PPWR in one group');
$mail = ['status' => 'queued', 'mail_status' => 'sent', 'created_at' => '2026-10-26 08:00:00', 'created_by' => 'automat:DELIVERY_PLANNED'];
expectLotB(array_keys(DeliveryNoticeService::alreadySent(['mail' => $mail], ['mail' => true, 'sms' => true], false)) === ['mail'], 'manual send after the automatic one: e-mail blocked');
expectLotB(DeliveryNoticeService::alreadySent(['mail' => $mail], ['mail' => true, 'resend' => true], false) === [], 'manual « wyślij ponownie » allowed');
expectLotB(array_keys(DeliveryNoticeService::alreadySent(['mail' => $mail], ['mail' => true, 'resend' => true], true)) === ['mail'], 'the automatic rule never sends twice');
expectLotB(DeliveryNoticeService::alreadySent(['mail' => ['mail_status' => 'failed'] + $mail, 'sms' => ['status' => 'logged'] + $mail], ['mail' => true, 'sms' => true], true) === [],
    'a failed e-mail or an SMS only logged (no provider) does not block');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_lot_b_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "audit2_lot_b_test: SKIPPED (no database: {$e->getMessage()})\n";
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
    $pdo->exec("INSERT INTO {$testDb}.customer_order_status_type SELECT * FROM {$source}.customer_order_status_type");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();

    $pdo->exec("SET SESSION sql_mode = ''");
    // 61 : boutique intégrée (clé valable, adresse locale fermée : aucun envoi ne peut partir) ; 62 : sans boutique ; 63 : boutique sans identifiants.
    $credentials = json_encode(['secret' => str_repeat('ab', 32), 'webhooks_base_url' => 'http://127.0.0.1:9']);
    $ins = $pdo->prepare('INSERT INTO customer (id, company, franchisee_uid, credentials) VALUES (?, ?, ?, ?)');
    $ins->execute([61, 'Sklep A', 'SHOP61', $credentials]);
    $ins->execute([62, 'Hurt B', null, null]);
    $ins->execute([63, 'Sklep C', 'SHOP63', null]);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");   // documents réduits au minimum utile (pas de WZ / différences complets)
    foreach ([[501, 61], [502, 62], [503, 63], [506, 61]] as [$wdt, $cid]) $pdo->exec("INSERT INTO document_wdt (id, id_customer, doc_num) VALUES ({$wdt}, {$cid}, 'WDT-{$wdt}')");
    $o = $pdo->prepare('INSERT INTO customer_order (id, id_customer, reference, status, expected_shipping_date, create_order_date, id_wdt) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $o->execute([101, 61, 'ORD-101', 1, '2026-10-27', '2026-10-24 10:00:00', 501]);
    $o->execute([102, 62, 'ORD-102', 1, '2026-10-27', '2026-10-24 10:00:00', 502]);
    $o->execute([103, 63, 'ORD-103', 1, '2026-10-27', '2026-10-24 10:00:00', 503]);
    $o->execute([104, 61, 'ORD-104', 1, '2026-10-30', '2026-10-25 10:00:00', null]);
    $o->execute([105, 61, 'ORD-105', 5, '2026-10-20', '2026-10-18 10:00:00', null]);
    $o->execute([106, 61, 'ORD-106', 1, '2026-10-27', '2026-10-24 11:00:00', 506]);
    foreach ([[701, 61, 501], [702, 62, 502], [703, 63, 503], [706, 61, 506]] as [$id, $cid, $wdt]) {
        $pdo->exec("INSERT INTO shipment_log (id, shipment_date, id_customer, id_wz, id_cmr, id_wdt, id_diff, id_employee) VALUES ({$id}, '2026-10-27', {$cid}, 1, 801, {$wdt}, 1, 1)");
    }
    $pdo->exec("INSERT INTO document_cmr (id, id_document_wz, id_customer, id_carrier) VALUES (801, 1, 61, 0)");
    $pdo->exec("INSERT INTO document_wdt_product (id, id_document_wdt, id_product, name, quantity, price_carton, ppc) VALUES
        (1, 501, 7, 'Bagietka', 12, 30, 20), (2, 501, 8, 'Rogal', 5, 25, 24), (3, 506, 7, 'Bagietka', 3, 30, 20)");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $orders = $container->get(OrderRepository::class);
    $fulfilment = $container->get(OrderFulfilmentService::class);
    $status = fn(int $id) => (int) $pdo->query("SELECT status FROM customer_order WHERE id = {$id}")->fetchColumn();

    // --- Une seule définition de commande ouverte
    expectLotB(array_map(fn($r) => (int) $r['id'], $orders->getPending()) === [104], 'Oczekujące = open orders only (no WDT)');
    $archived = [];
    foreach ($orders->getArchived() as $r) $archived[(int) $r['id']] = $r['status_name'];
    expectLotB(isset($archived[101], $archived[105]) && !isset($archived[104]) && $archived[101] === 'W transporcie' && $archived[105] === 'Zarchiwizowane',
        'archive: an order with its WDT shows « W transporcie » (canonical labels, not the old table names)');

    // --- Wyślij dzień : statut et journal, dans la transaction de création
    expectLotB($fulfilment->markShippedByWdt(501, 'Wyślij dzień : test') === [101] && $status(101) === CustomerOrderStatus::IN_TRANSIT, 'orders of the WDT → in transit');
    $log = $pdo->query("SELECT source, changed_fields FROM customer_order_changelog WHERE id_order = 101")->fetch();
    expectLotB($log['source'] === 'system' && str_contains($log['changed_fields'], 'Wyślij dzień : test'), 'order changelog keeps the reason');
    expectLotB($fulfilment->markShippedByWdt(501, 'again') === [] && (int) $pdo->query('SELECT COUNT(*) FROM customer_order_changelog WHERE id_order = 101')->fetchColumn() === 1,
        'second pass changes nothing');
    expectLotB(!$fulfilment->markShipped(105, 'x') && $status(105) === 5, 'an archived order is never reopened as sent');

    // --- Avis aux boutiques : mis en file seulement
    $notices = $fulfilment->queueShopNotices([101, 102, 103]);
    expectLotB(array_column($notices, 'status') === ['queued', 'not_integrated', 'error'] && $notices[2]['code'] === 'MISSING_CLIENT_CREDENTIALS', 'shop / no shop / shop without credentials');
    $row = $pdo->query('SELECT * FROM webhook_outbox_shipments')->fetch();
    $payload = json_decode($row['payload_json'], true);
    expectLotB($row['status'] === 'pending' && (int) $row['id_order'] === 101 && $row['shop_uid'] === 'SHOP61' && $row['url'] === 'http://127.0.0.1:9/webhooks/material-orders/shipment/sent'
        && $payload['order_key'] === 'ORD-101' && array_column($payload['products'], 'sku') === [7, 8] && $payload['carrier']['name'] === 'Produkcja', 'queued notice: WDT goods, carrier, shop address');
    $fulfilment->queueShopNotices([101]);
    expectLotB((int) $pdo->query('SELECT COUNT(*) FROM webhook_outbox_shipments')->fetchColumn() === 1, 'same content = same notice (no duplicate)');

    // --- « Oznacz jako wysłane i powiadom »
    expectLotB($container->get(OrderService::class)->notifyIntegrated(106) === true && $status(106) === CustomerOrderStatus::IN_TRANSIT
        && (int) $pdo->query('SELECT COUNT(*) FROM webhook_outbox_shipments WHERE id_order = 106')->fetchColumn() === 1, 'manual mark-as-sent: same rule, notice queued');

    // --- File des avis : échec reporté (adresse locale fermée), clé manquante = rien ne part
    $pdo->exec("INSERT INTO webhook_outbox_shipments (id_order, shop_uid, url, event_id, payload_json, status, attempts, next_try) VALUES (103, 'SHOP63', 'http://127.0.0.1:9/x', 'e-nokey', '{}', 'pending', 0, NOW())");
    $result = $container->get(ShipmentOutboxDispatcher::class)->dispatchDue(10);
    expectLotB($result === ['sent' => 0, 'postponed' => 3, 'failed' => 0], 'nothing delivered: closed local port and missing key are postponed');
    $nokey = $pdo->query("SELECT status, attempts, last_error, next_try > NOW() AS later FROM webhook_outbox_shipments WHERE event_id = 'e-nokey'")->fetch();
    expectLotB($nokey['status'] === 'pending' && (int) $nokey['attempts'] === 1 && str_contains($nokey['last_error'], 'Missing or invalid secret') && (int) $nokey['later'] === 1, 'retry later with backoff');
    expectLotB($container->get(ShipmentOutboxDispatcher::class)->dispatchDue(10) === ['sent' => 0, 'postponed' => 0, 'failed' => 0], 'not due again before the backoff');

    // --- Livraison confirmée par le chauffeur
    expectLotB($fulfilment->markDelivered('2026-10-27', 61, 'test') === [101, 106] && $status(101) === CustomerOrderStatus::COMPLETED && $status(106) === CustomerOrderStatus::COMPLETED,
        'delivered: the customer\'s orders of that day → Zrealizowane');
    expectLotB($fulfilment->markDelivered('2026-10-27', 62, 'test') === [102] && $status(102) === CustomerOrderStatus::COMPLETED, 'an order still « Oczekujące » with its WDT is completed too');
    expectLotB($fulfilment->markDelivered('2026-10-28', 61, 'test') === [] && $status(104) === CustomerOrderStatus::PENDING, 'other days and open orders untouched');

    echo "audit2_lot_b_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
