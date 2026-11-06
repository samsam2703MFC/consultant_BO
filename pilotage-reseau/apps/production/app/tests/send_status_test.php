<?php
declare(strict_types=1);
/*
 * Stan wysyłek [159] : e-maile clients, envois aux franchisés (outbox), webhooks d'expédition, notifications de
 * surveillance et e-mails automatiques lus ensemble — « Błąd » (toutes les tentatives faites) et « Czeka » (en file
 * au-delà du délai), fenêtre de jours, filtres ; « Ponów » remet une seule position pour une tentative de plus (jamais
 * une position partie ou en file) ; « Rozwiązane » avec motif obligatoire ; secrets des payloads et identifiants AWS
 * jamais montrés ; point « Do zrobienia » pour Dzisiaj. Rien n'est envoyé : aucun worker n'est lancé.
 *
 *   php tests/send_status_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_send_status_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Communication\MailAutomationService;
use App\Services\Communication\SendStatusService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectSs(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function refusedSs(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectSs((int) $e->getCode() === $code, $message . ' (code ' . $e->getCode() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (accepted)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_send_status_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "send_status_test: SKIPPED (no database: {$e->getMessage()})\n";
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
                 'document_proforma', 'document_invoice', 'customer_order_changelog'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();

    $pdo->exec("SET SESSION sql_mode = ''");
    $shop = '01K34M5612TYGPH3KZG7WJPPQS';
    $pdo->exec("INSERT INTO customer (id, company, franchisee_uid) VALUES (51, 'Klient A', '{$shop}'), (52, 'Klient B', NULL)");
    $pdo->exec("INSERT INTO document_proforma (id, doc_num, id_customer, uuid) VALUES (7, 'FP-7', 51, 'uuid-7')");
    // « Maintenant » = 26.10.2026 12:00 ; délai « czeka » 30 min, fenêtre 30 jours.
    $now = new DateTimeImmutable('2026-10-26 12:00:00');
    $pdo->exec("INSERT INTO communication_message (id, message_type, id_customer, id_proforma, recipient_email, subject_text, body_text, status, attempts, error_message, created_at, next_try_at) VALUES
        (1, 'proforma', 51, 7, 'a@klient.test', 'Proforma FP-7', 'x', 'failed', 5, 'SMTP 550 mailbox unavailable', '2026-10-25 10:00:00', '2026-10-25 16:00:00'),
        (2, 'wdt', 52, NULL, 'b@klient.test', 'WDT', 'x', 'queued', 0, NULL, '2026-10-26 09:00:00', '2026-10-26 10:00:00'),
        (3, 'invoice', 52, NULL, 'b@klient.test', 'Faktura', 'x', 'queued', 0, NULL, '2026-10-26 11:40:00', '2026-10-26 11:50:00'),
        (4, 'proforma', 51, 7, 'a@klient.test', 'Proforma', 'x', 'sent', 1, NULL, '2026-10-24 10:00:00', '2026-10-24 10:00:00'),
        (5, 'proforma', 51, 7, 'a@klient.test', 'Stara', 'x', 'failed', 5, 'timeout', '2026-08-01 10:00:00', '2026-08-01 12:00:00')");
    $pdo->exec("UPDATE communication_message SET sent_at = '2026-10-24 10:00:05' WHERE id = 4");
    $secretPayload = json_encode(['order_key' => 'ORD-1', 'franchisee_uid' => $shop, 'franchisee_code' => 'SHOPA', 'proforma_uuid' => 'uuid-7', 's3_key' => 'proformas/x.pdf',
        'secret' => 'deadbeefSECRET', 'auth_token' => 'TOKENVALUE', 'callback_url' => 'https://hook.test/cb?token=abc']);
    $complaintPayload = json_encode(['complaint_key' => 'C-9', 'acceptance_status' => 'ACCEPTED', 'franchisee_uid' => $shop, 'secret' => 'deadbeefSECRET', 'callback_url' => 'https://hook.test/cb']);
    $aws = 'Error executing "PutObject" on "https://bucket.s3.amazonaws.com/x.pdf"; AWS HTTP error: Client error: `PUT https://bucket.s3.amazonaws.com/x.pdf` resulted in a `403 Forbidden` response:'
        . "\n<?xml version=\"1.0\"?>\n<Error><Code>AccessDenied</Code><Message>User: arn:aws:iam::901170572175:user/production is not authorized</Message></Error>";
    $ins = $pdo->prepare("INSERT INTO outbox (id, type, payload_json, status, dedup_key, attempts, next_try_at, last_error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([11, 'proforma.upload', $secretPayload, 'dead', 'd11', 6, '2026-10-20 10:00:00', $aws, '2026-10-20 08:00:00', '2026-10-20 10:00:00']);
    $ins->execute([12, 'complaint.update', $complaintPayload, 'pending', 'd12', 0, '2026-10-25 08:00:00', null, '2026-10-25 08:00:00', '2026-10-25 08:00:00']);
    $ins->execute([13, 'proforma.notify', $secretPayload, 'done', 'd13', 0, '2026-10-21 08:00:00', null, '2026-10-21 08:00:00', '2026-10-21 08:00:05']);
    $pdo->exec("INSERT INTO webhook_outbox_shipments (id, id_order, shop_uid, url, event_id, payload_json, status, attempts, next_try, last_error, created_at, updated_at) VALUES
        (21, 99, '{$shop}', 'https://hook.test/ship?token=zzz', 'e21', '{}', 'failed', 6, '2026-10-24 08:00:00', 'HTTP 500 ', '2026-10-23 08:00:00', '2026-10-24 08:00:00'),
        (22, 98, '{$shop}', 'https://hook.test/ship', 'e22', '{}', 'sent', 1, '2026-10-22 08:00:00', NULL, '2026-10-22 08:00:00', '2026-10-22 08:00:01')");
    $pdo->exec("INSERT INTO cron_runs (id, job_name, status, started_at) VALUES (31, 'poller', 'failed', '2026-10-23 07:00:00')");
    $pdo->exec("INSERT INTO cron_notification_outbox (id, cron_run_id, payload_json, status, attempts, next_try_at, last_error, created_at) VALUES
        (41, 31, '{}', 'dead', 8, '2026-10-24 07:00:00', 'Discord 401', '2026-10-23 07:00:01')");
    $pdo->exec("INSERT INTO n_mail_rule (id, code, event, template_type, recipient_kind, enabled) VALUES (300, 'test_proforma', 'proforma', 'proforma', 'customer', 1)");
    $pdo->prepare("INSERT INTO n_mail_dispatch (id, id_rule, ref_key, id_message, status, reason, created_at) VALUES (?, 300, ?, NULL, ?, ?, ?)")->execute([51, 'proforma:7', 'failed', 'SMTP down', '2026-10-22 08:00:00']);
    $pdo->prepare("INSERT INTO n_mail_dispatch (id, id_rule, ref_key, id_message, status, reason, created_at) VALUES (?, 300, ?, NULL, ?, ?, ?)")->execute([52, 'proforma:8', 'skipped', MailAutomationService::NOT_APPLICABLE, '2026-10-22 08:00:00']);
    $pdo->prepare("INSERT INTO n_mail_dispatch (id, id_rule, ref_key, id_message, status, reason, created_at) VALUES (?, 300, ?, NULL, ?, ?, ?)")->execute([53, 'proforma:9', 'skipped', 'Brak adresu e-mail klienta.', '2026-10-22 09:00:00']);
    $pdo->prepare("INSERT INTO n_mail_dispatch (id, id_rule, ref_key, id_message, status, reason, created_at) VALUES (?, 300, ?, 4, ?, NULL, ?)")->execute([54, 'proforma:10', 'queued', '2026-10-24 10:00:00']);

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $status = $builder->build()->get(SendStatusService::class);

    // --- Vue d'ensemble : erreurs (toutes les tentatives faites) et files bloquées, 30 derniers jours
    $o = $status->overview('todo', null, null, $now);
    expectSs($o['counts']['error'] === 6 && $o['counts']['stuck'] === 2 && $o['counts']['todo'] === 8 && $o['counts']['resolved'] === 0,
        'errors: e-mail, franchise, shipment webhook, monitoring, failed + skipped-with-a-reason rule ; stuck: queued e-mail and franchise job past 30 min');
    $t = $o['tiles'];
    expectSs([$t['email']['errors'], $t['email']['stuck'], $t['franchise']['errors'], $t['franchise']['stuck'], $t['shipment']['errors'], $t['monitoring']['errors'], $t['automation']['errors']]
        === [1, 1, 1, 1, 1, 1, 2], 'tiles per queue');
    expectSs($t['email']['last_success'] === '2026-10-24 10:00:05' && $t['franchise']['last_success'] === '2026-10-21 08:00:05' && $t['shipment']['last_success'] === '2026-10-22 08:00:01',
        'last successful send per queue');
    $states = array_column($o['items'], 'state');
    expectSs($states === array_merge(array_fill(0, 6, 'error'), ['stuck', 'stuck']), 'errors first, then what waits');
    $by = [];
    foreach ($o['items'] as $i) $by[$i['source'] . ':' . $i['id']] = $i;
    expectSs(!isset($by['email:3']) && !isset($by['email:4']) && !isset($by['email:5']) && !isset($by['franchise:13']) && !isset($by['automation:52']) && !isset($by['automation:54']),
        'not shown: queued within 30 min, sent, older than the window, done, « not for this customer », queued rule');
    $e1 = $by['email:1'];
    expectSs($e1['what'] === 'Proforma FP-7 — Proforma FP-7' && $e1['customer'] === 'Klient A' && $e1['recipient'] === 'a@klient.test' && $e1['url'] === '/communication/messages/1'
        && $e1['kind_label'] === 'Proforma' && $e1['attempts'] === 5 && $e1['can_retry'] && $e1['can_resolve'], 'e-mail: document, customer, recipient, link, attempts');
    $f = $by['franchise:11'];
    expectSs($f['what'] === 'Proforma FP-7 · zamówienie ORD-1' && $f['customer'] === 'Klient A' && $f['customer_id'] === 51 && $f['kind_label'] === 'Proforma → sklep (plik PDF)',
        'franchise: proforma number from its uuid, order, shop customer');
    expectSs(str_starts_with($f['error'], 'AWS PutObject : HTTP 403 AccessDenied') && !str_contains($f['error'], 'arn:') && !str_contains($f['error'], '901170572175'),
        'AWS error summarised without the account or IAM user');
    $dump = json_encode($o['items']);
    foreach (['deadbeefSECRET', 'TOKENVALUE', 'auth_token', 'hook.test', 'callback', 's3_key', 'proformas/x.pdf'] as $secret) {
        expectSs(!str_contains($dump, $secret), 'never shown: ' . $secret);
    }
    $c = $by['franchise:12'];
    expectSs($c['state'] === 'stuck' && $c['what'] === 'Reklamacja C-9 · ACCEPTED' && $c['url'] === '/franchise-complaints' && $c['waiting_minutes'] === 28 * 60
        && !$c['can_retry'] && !$c['can_resolve'], 'waiting job: how long, no retry (already queued), no resolve');
    expectSs($by['shipment:21']['customer'] === 'Klient A' && $by['shipment:21']['url'] === '/client-orders/99/edit' && $by['shipment:21']['what'] === 'Zamówienie #99', 'shipment webhook: order and shop');
    expectSs($by['monitoring:41']['what'] === 'poller · failed' && $by['monitoring:41']['error'] === 'Discord 401', 'monitoring: job of the notification');
    expectSs($by['automation:53']['error'] === 'Brak adresu e-mail klienta.' && str_contains($by['automation:53']['what'], '(pominięty)') && $by['automation:51']['kind_label'] === 'Proforma',
        'automatic e-mail: failed and skipped for a reason to fix');

    // --- Filtres et fenêtre
    expectSs(count($status->overview('stuck', null, null, $now)['items']) === 2, 'filter: waiting');
    $mail = $status->overview('todo', 'email', null, $now);
    expectSs(count($mail['items']) === 2 && $mail['counts']['todo'] === 2 && $mail['source'] === 'email', 'filter: one queue');
    expectSs($status->overview('error', null, 0, $now)['counts']['error'] === 7, 'whole history: old failed e-mail included');
    expectSs($status->overview('nonsense', 'nonsense', null, $now)['filter'] === 'todo', 'unknown filter / queue fall back to defaults');

    // --- Ponów : une seule position, une tentative de plus
    $status->retry('email', 1, 1);
    $m = $pdo->query("SELECT status, attempts, next_try_at <= NOW() AS due, error_message FROM communication_message WHERE id = 1")->fetch();
    expectSs($m['status'] === 'queued' && (int) $m['attempts'] === 4 && (int) $m['due'] === 1 && $m['error_message'] === 'SMTP 550 mailbox unavailable',
        'e-mail back in the queue for one last attempt (worker allows 5), previous error kept until it is sent');
    refusedSs(fn() => $status->retry('email', 1, 1), 409, 'second retry refused: already queued');
    refusedSs(fn() => $status->retry('email', 4, 1), 409, 'sent e-mail cannot be retried');
    refusedSs(fn() => $status->retry('email', 999, 1), 404, 'unknown position');
    refusedSs(fn() => $status->retry('ftp', 1, 1), 422, 'unknown queue');
    $status->retry('franchise', 11, 1);
    $status->retry('shipment', 21, 1);
    $status->retry('monitoring', 41, 1);
    $q = fn(string $sql) => $pdo->query($sql)->fetch();
    expectSs($q("SELECT status, attempts FROM outbox WHERE id = 11") == ['status' => 'pending', 'attempts' => 5]
        && $q("SELECT status, attempts FROM webhook_outbox_shipments WHERE id = 21") == ['status' => 'pending', 'attempts' => 5]
        && $q("SELECT status, attempts FROM cron_notification_outbox WHERE id = 41") == ['status' => 'pending', 'attempts' => 7],
        'franchise job, webhook and notification: one more attempt each (6, 6 and 8 allowed)');
    expectSs($q("SELECT payload_json FROM outbox WHERE id = 11")['payload_json'] === $secretPayload && $q("SELECT dedup_key FROM outbox WHERE id = 11")['dedup_key'] === 'd11',
        'payload and idempotency key untouched');
    $status->retry('automation', 51, 1);
    expectSs($q("SELECT COUNT(*) AS n FROM n_mail_dispatch WHERE id = 51")['n'] == 0, 'automatic e-mail: the rule will handle the case again');
    expectSs((int) $q("SELECT COUNT(*) AS n FROM n_send_issue_action WHERE action = 'retry' AND id_user = 1")['n'] === 5, 'each retry logged with its author');
    $after = $status->overview('todo', null, null, $now);
    expectSs($after['counts']['error'] === 1 && $after['items'][0]['source'] === 'automation' && $after['items'][0]['id'] === 53, 'retried positions leave the error list');

    // --- Rozwiązane : motif obligatoire, seulement une erreur
    refusedSs(fn() => $status->resolve('automation', 53, ' ok ', 1), 422, 'reason too short');
    refusedSs(fn() => $status->resolve('franchise', 12, 'wysłane ręcznie', 1), 409, 'a waiting job is not an error');
    refusedSs(fn() => $status->resolve('email', 999, 'wysłane ręcznie', 1), 404, 'unknown position');
    $status->resolve('automation', 53, 'klient dostał PDF przez WhatsApp', 1);
    $res = $status->overview('resolved', null, null, $now);
    expectSs(count($res['items']) === 1 && $res['items'][0]['state'] === 'resolved' && $res['items'][0]['last_action']['reason'] === 'klient dostał PDF przez WhatsApp'
        && $res['items'][0]['last_action']['user'] === 'Anna K.' && !$res['items'][0]['can_resolve'] && $res['items'][0]['can_retry'], 'resolved: reason, author ; can still be retried');
    expectSs($status->overview('todo', null, null, $now)['counts']['error'] === 0, 'resolved error no longer to handle');
    $status->resolve('email', 5, 'stara wiadomość, nieaktualna', 1);
    $status->retry('email', 5, 1);
    expectSs($q("SELECT status FROM communication_message WHERE id = 5")['status'] === 'queued', 'a resolved error can still be sent again');

    // --- Boutiques : récupération, import, produit inconnu (n_order_collect_issue) ; domówienia → boutique
    $issues = new App\Repositories\Client\Order\OrderCollectIssueRepository($pdo);
    $issues->record('pull', 'SHOP-A', 51, null, 'Sklep nie odpowiada na pobieranie zamówień : HTTP 500');
    $issues->record('pull', 'SHOP-A', 51, null, 'Sklep nie odpowiada na pobieranie zamówień : HTTP 502');
    $issues->record('unknown_product', 'SHOP-A', 51, 'ORD-7', 'Zamówienie ORD-7 : produkt SKU 999 nie istnieje w ERP', 99, '999');
    $issues->record('import', 'SHOP-A', 51, 'ORD-8', 'Zamówienie ORD-8 nie zostało zaimportowane : CLIENT_NOT_FOUND');
    $pdo->exec("INSERT INTO customer_order (id, id_customer, reference, invoice_num, status, order_description, expected_shipping_date, create_order_date, order_type, supplement_push_status, supplement_push_at)
        VALUES (98, 51, 'D-1', '', 1, '', '2026-10-27', '2026-10-25 08:00:00', 'SUPPLEMENT', 'failed', '2026-10-25 08:00:05')");
    $pdo->exec("INSERT INTO customer_order_changelog (id_order, action_type, source, changed_fields) VALUES (98, 'update', 'system', '{\"after\":{\"supplement_push_status\":\"failed\"},\"reason\":\"missing_webhook_base_url\"}')");
    $shops = $status->overview('todo', 'shops', null, new DateTimeImmutable());
    $sb = [];
    foreach ($shops['items'] as $i) $sb[$i['kind']] = $i;
    expectSs(count($shops['items']) === 3 && $sb['pull']['attempts'] === 2 && $sb['pull']['error'] === 'Sklep nie odpowiada na pobieranie zamówień : HTTP 502'
        && $sb['pull']['customer'] === 'Klient A' && !$sb['pull']['can_retry'] && $sb['pull']['can_resolve'], 'shops: one line per case, repeats counted, last message ; no retry (polled every minute)');
    expectSs($sb['unknown_product']['url'] === '/client-orders/99/edit' && $sb['unknown_product']['kind_label'] === 'Sklep : nieznany produkt' && $sb['unknown_product']['what'] === 'Zamówienie ORD-7',
        'unknown product: opens the order to add the line by hand');
    refusedSs(fn() => $status->retry('shops', (int) $sb['pull']['id'], 1), 409, 'shops: retry refused');
    $issues->close(['config', 'pull'], 'SHOP-A');
    $issues->close(['import'], 'SHOP-A', 'ORD-8');
    expectSs(array_column($status->overview('todo', 'shops', null, new DateTimeImmutable())['items'], 'kind') === ['unknown_product'], 'shop answers again / order imported: cases closed');
    $issues->record('pull', 'SHOP-A', 51, null, 'HTTP 503');
    expectSs((int) $pdo->query("SELECT occurrences FROM n_order_collect_issue WHERE kind = 'pull'")->fetchColumn() === 1, 'closed case that comes back starts again');
    $status->resolve('shops', (int) $sb['unknown_product']['id'], 'pozycja dodana ręcznie', 1);
    expectSs(!in_array('unknown_product', array_column($status->overview('todo', 'shops', null, new DateTimeImmutable())['items'], 'kind'), true), 'resolved shop case leaves the list');
    $sup = $status->overview('todo', 'supplement', 0, $now);
    expectSs(count($sup['items']) === 1 && $sup['items'][0]['what'] === 'Domówienie #98', 'failed supplement listed');
    expectSs($sup['items'][0]['error'] === 'missing_webhook_base_url' && $sup['items'][0]['url'] === '/client-orders/98/edit' && $sup['items'][0]['can_retry'], 'supplement: reason from the changelog');
    $status->retry('supplement', 98, 1);
    $d = $pdo->query("SELECT supplement_push_status AS s, supplement_push_at >= NOW() - INTERVAL 1 MINUTE AS fresh FROM customer_order WHERE id = 98")->fetch();
    expectSs($d['s'] === 'pending' && (int) $d['fresh'] === 1, 'supplement requeued: pushed once by the 5-minute task');
    refusedSs(fn() => $status->retry('supplement', 98, 1), 409, 'supplement already requeued');

    // --- Dzisiaj et nettoyage des messages
    $todo = $status->todo($now);
    expectSs(count($todo) === 1 && $todo[0]['url'] === '/communication/outbox' && $todo[0]['positions'] === ['ADMINISTRACJA'] && $todo[0]['count'] >= 2, 'Dzisiaj item');
    expectSs(SendStatusService::sanitize('POST https://x.test/h?token=abc&signature=s1 → Bearer eyJ.a.b AKIAABCDEFGHIJKLMNOP') === 'POST https://x.test/h?token=***&signature=*** → Bearer *** AKIA***',
        'errors: tokens, signatures, bearer and AWS key ids masked');
    expectSs(SendStatusService::sanitize('callback_failed') === 'callback_failed — sklep nie przyjął powiadomienia (adres albo klucz sklepu)', 'known error explained');
    expectSs(mb_strlen(SendStatusService::sanitize(str_repeat('x', 900))) === 301, 'long errors cut');

    echo "send_status_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
