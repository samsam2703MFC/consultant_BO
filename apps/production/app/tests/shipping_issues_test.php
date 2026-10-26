<?php
declare(strict_types=1);
/*
 * File des écarts d'expédition [155] : différences proforma payée ↔ WDT (remboursement / dopłata) et problèmes de
 * livraison du chauffeur entrent une seule fois dans la file (sans proforma payée, sous le seuil ou trop anciens :
 * non), responsable par défaut selon le poste, échéance, décisions possibles selon le cas, note obligatoire pour
 * « sans suite », clôture et réouverture, montants à rembourser / à encaisser, alerte dans la cloche et Dzisiaj.
 *
 *   php tests/shipping_issues_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_shipping_issues_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Notification\DeadlineAlertService;
use App\Services\Shipping\ShippingIssueService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectSi(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function refusedSi(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectSi((int) $e->getCode() === $code, $message . ' (code ' . $e->getCode() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (accepted)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_shipping_issues_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "shipping_issues_test: SKIPPED (no database: {$e->getMessage()})\n";
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
                 'shipment_log', 'app_notification'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'anna', 'x'), (2, 'Piotr', 'L', 'Piotr L.', 'p@x.test', '1', 0, 'piotr', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    // Anna : Administracja (responsable des différences) ; personne à la Logistyka.
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) SELECT 1, id FROM n_position WHERE code = 'ADMINISTRACJA'");

    $today = new DateTimeImmutable('today');
    $d = fn(int $days) => $today->modify("{$days} days")->format('Y-m-d');
    $pdo->exec("SET @mode := @@SESSION.sql_mode");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO customer (id, company, active) VALUES (3, 'Pâtisserie Test', 1), (4, 'Neutralle Test', 1)");
    $pdo->exec("INSERT INTO document_wdt (id, doc_num, established_date, currency) VALUES (1, 'W/10', '{$d(-2)}', 'eur'), (2, 'W/11', '{$d(-1)}', 'eur')");
    // 101 : produits payés non envoyés → remboursement 428,34 € ; 102 : sans proforma payée ; 103 : envoyé en plus → dopłata 50 € ;
    // 104 : écart de 0,50 € (sous le seuil) ; 105 : trop ancien.
    $pdo->exec("INSERT INTO document_order_shipping_difference (id, id_customer, established_date) VALUES
        (101, 3, '{$d(-2)}'), (102, 4, '{$d(-2)}'), (103, 4, '{$d(-1)}'), (104, 3, '{$d(-1)}'), (105, 3, '{$d(-60)}')");
    $pdo->exec("INSERT INTO document_order_shipping_difference_products (id_document_order_shipping_difference, id_product, product_name, quantity_ordered, price_during_ordering, invoiced_quantity, price_during_invoicing, difference_reason) VALUES
        (101, 1, 'Brownies 22', 4, 79.39, 0, 0, 'brak w magazynie'), (101, 2, 'Fromage 19', 10, 55.39, 8, 55.39, ''), (101, 3, 'Lorraine 19', 5, 52.99, 5, 52.99, ''),
        (102, 1, 'Brownies 22', 0, 0, 30, 79.39, ''),
        (103, 4, 'Tarte 24', 2, 50, 3, 50, ''),
        (104, 5, 'Quiche', 1, 10.00, 1, 10.50, ''),
        (105, 1, 'Brownies 22', 4, 79.39, 0, 0, '')");
    // 101 a deux lignes d'expédition (le montant ne doit pas doubler).
    $pdo->exec("INSERT INTO shipment_log (id_diff, id_wdt) VALUES (101, 1), (101, 1), (103, 2)");
    $pdo->exec("INSERT INTO n_delivery_stop (ship_date, id_customer, status, reason, note, recorded_at, driver_name) VALUES ('{$d(-1)}', 3, 'problem', 'missing', 'Brak 2 kartonów', NOW(), 'Jan Kowal')");
    $pdo->exec("SET SESSION sql_mode = @mode");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        PDO::class => $pdo,
        WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string { throw new RuntimeException('no e-mail in tests'); }
        },
    ]);
    $container = $builder->build();
    $issues = $container->get(ShippingIssueService::class);

    // --- Alimentation : une seule fois, filtres (proforma payée, seuil, ancienneté)
    expectSi($issues->sync() === ['differences' => 2, 'deliveries' => 1, 'closed' => 0], 'sync: 2 differences (paid proforma, above 1 €, recent) + 1 delivery problem');
    expectSi($issues->sync() === ['differences' => 0, 'deliveries' => 0, 'closed' => 0], 'sync is idempotent');
    $view = $issues->overview('open', false, 1);
    $byKind = array_column($view['items'], null, 'kind');
    expectSi(count($view['items']) === 3 && isset($byKind['refund'], $byKind['collect'], $byKind['delivery']), 'three open cases: refund, collect, delivery');
    $refund = $byKind['refund'];
    expectSi(abs($refund['amount'] + 428.34) < 0.001 && $refund['customer'] === 'Pâtisserie Test' && $refund['wdt_number'] === 'W/10' && $refund['lines_changed'] === 2
        && $refund['owner_id'] === 1 && $refund['due_on'] === $d(3) && $refund['opened_on'] === $d(-2) && $refund['currency'] === 'EUR', 'refund case: amount, WDT, changed lines, default owner (Administracja), due date from entering the queue');
    expectSi($byKind['delivery']['owner_id'] === null && $byKind['delivery']['reason'] === 'Brak towaru' && $byKind['delivery']['stop_note'] === 'Brak 2 kartonów'
        && $byKind['delivery']['driver_name'] === 'Jan Kowal', 'delivery problem: no logistics person → no owner, reason, note, driver');
    expectSi(abs($view['counters']['to_refund'] - 428.34) < 0.001 && abs($view['counters']['to_collect'] - 50) < 0.001 && $view['counters']['open'] === 3, 'tiles: to refund, to collect, open');
    expectSi(in_array('refund', $refund['decisions'], true) && !in_array('collect', $refund['decisions'], true)
        && in_array('collect', $byKind['collect']['decisions'], true) && in_array('redeliver', $byKind['delivery']['decisions'], true), 'decisions offered per case');
    $lines = $issues->lines($refund['id']);
    expectSi(count($lines) === 2 && $lines[0]['product_name'] === 'Brownies 22' && $lines[0]['difference_reason'] === 'brak w magazynie' && abs($lines[0]['amount'] + 317.56) < 0.001, 'only differing lines, with the reason');

    // --- Responsable, décision, clôture
    refusedSi(fn() => $issues->assign($refund['id'], 99, 1), 422, 'unknown owner refused');
    $issues->assign($byKind['delivery']['id'], 2, 1);
    refusedSi(fn() => $issues->decide($refund['id'], 'collect', '', 1), 422, 'decision not matching the case refused');
    refusedSi(fn() => $issues->decide($refund['id'], 'waive', '  ', 1), 422, '« bez rozliczenia » needs a note');
    refusedSi(fn() => $issues->close($byKind['collect']['id'], 1), 422, 'closing needs a decision');
    $issues->decide($refund['id'], 'refund', 'Zwrot przelewem 428,34 €', 1);
    $row = $pdo->query("SELECT * FROM n_shipping_issue WHERE id = {$refund['id']}")->fetch();
    expectSi($row['status'] === 'decided' && $row['decision'] === 'refund' && (int) $row['decided_by'] === 1 && $row['decided_at'] !== null, 'decision recorded, case in progress');
    $issues->close($refund['id'], 2);
    refusedSi(fn() => $issues->decide($refund['id'], 'credit_next', '', 1), 409, 'closed case cannot change decision');
    $issues->reopen($refund['id']);
    expectSi($pdo->query("SELECT status FROM n_shipping_issue WHERE id = {$refund['id']}")->fetchColumn() === 'decided', 'reopened case goes back to « in progress »');
    $issues->close($refund['id'], 2);
    $mine = $issues->overview('all', true, 2);
    expectSi(count($mine['items']) === 1 && $mine['items'][0]['kind'] === 'delivery', '« only mine » filter');
    expectSi($issues->overview('open', false, 1)['counters']['to_refund'] == 0, 'closed refund no longer counted');

    // --- Alertes : écarts sans décision après l'échéance, une seule fois ; Dzisiaj
    $alerts = $container->get(DeadlineAlertService::class);
    expectSi($alerts->run($today->setTime(9, 0))['shipping'] === 0, 'cases just taken into the queue are not late yet');
    $later = $today->modify('+5 days')->setTime(9, 0);
    expectSi($alerts->run($later)['shipping'] === 2, 'two open cases past their due date');
    $alerts->run($later);
    $notes = $pdo->query("SELECT title, body, link_url FROM app_notification WHERE type = 'shipping_issue_late' ORDER BY id")->fetchAll();
    expectSi(count($notes) === 2 && $notes[0]['link_url'] === '/shipments/differences' && str_contains($notes[1]['body'] . $notes[0]['body'], 'odpowiedzialny : Piotr L.'), 'bell alerts once each, with owner');
    $todo = array_values(array_filter($alerts->todo($later), fn($i) => $i['url'] === '/shipments/differences'));
    expectSi(count($todo) === 1 && $todo[0]['count'] === 2 && $todo[0]['tone'] === 'danger' && $todo[0]['note'] === '2 po terminie', 'Dzisiaj: cases waiting for a decision');

    echo "shipping_issues_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
