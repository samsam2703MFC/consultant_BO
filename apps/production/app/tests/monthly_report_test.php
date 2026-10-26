<?php
declare(strict_types=1);
/*
 * Raport miesięczny zarządu : wskaźniki miesiąca wobec M-1 i N-1 (sprzedaż WDT, klienci, reklamacje, niezgodności,
 * dostawy z tabletu kierowcy), najważniejsze PL / FR, PDF PL / FR, wysyłka e-mailem (kolejka, bez SMTP), ustawienia
 * i wysyłka automatyczna raz w miesiącu, uprawnienia strony i wyszukiwarka.
 *
 *   php tests/monthly_report_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_monthly_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Services\Analysis\MonthlyReportService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectRep(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectRepError(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectRep($e->getCode() === 422, $message . ' (code ' . $e->getCode() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (no error)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_monthly_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "monthly_report_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency',
                 'customer', 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings',
                 'supplier', 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month',
                 'cost_media_section_allocation', 'cost_media_type', 'cost_month_section', 'communication_message', 'communication_message_attachment',
                 'communication_email_account', 'erp_company_data', 'flattened_recipe_recipe', 'product_preparation_type', 'product_recipe_preparation_connection',
                 'product_recipe_type', 'product_recipe_preparation_step', 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status',
                 'test_product_type', 'test_product_stage', 'test_product_changelog', 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery',
                 'document_cmr', 'document_wz', 'shipment_log', 'app_notification', 'complaint'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();

    // Wrzesień 2026 (raport), sierpień 2026 (M-1), wrzesień 2025 (N-1).
    $pdo->exec("SET @mode := @@SESSION.sql_mode");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO erp_company_other_settings (id, exchange_rate_euro) VALUES (1, 4.25)");
    $pdo->exec("INSERT INTO erp_company_data (id, wdt_company_name) VALUES (1, 'SL Food Test')");
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Viennoiserie')");
    $pdo->exec("INSERT INTO customer (id, company, active) VALUES (3, 'Boulangerie Test', 1), (4, 'Ancien Client', 1)");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, id_section, active) VALUES (500, 'Croissant 70 g', 1, 70, 60, 1, 1)");
    $pdo->exec("INSERT INTO document_wdt (id, id_customer, established_date, currency) VALUES (1, 3, '2026-09-10', 'EUR'), (2, 3, '2026-08-12', 'EUR'), (3, 4, '2025-09-15', 'EUR')");
    $pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES
        (1, 500, 'Croissant 70 g', 100, 20), (2, 500, 'Croissant 70 g', 50, 20), (3, 500, 'Croissant 70 g', 80, 20)");
    $pdo->exec("INSERT INTO complaint (complaint_key, complaint_type, complaint_reason, requested_action, id_customer, id_product, accepted_quantity, single_purchase_price,
            acceptance_status, reported_at, acceptance_timestamp) VALUES
        (UUID(), 'PRODUCT', 'product_quality', 'CREDIT', 3, 500, 5, 20, 'ACCEPTED', '2026-09-10 08:00:00', '2026-09-12 08:00:00'),
        (UUID(), 'PRODUCT', 'broken_packaging', 'REFUND', 3, 500, 0, 20, 'REJECTED', '2026-09-20 08:00:00', '2026-09-21 08:00:00'),
        (UUID(), 'PRODUCT', 'product_quality', 'REPLACEMENT', 3, 500, 0, 20, 'NEW', '2026-09-28 08:00:00', '2026-09-28 08:00:00'),
        (UUID(), 'PRODUCT', 'other', 'CREDIT', 3, 500, 2, 20, 'ACCEPTED', '2026-08-05 08:00:00', '2026-08-06 08:00:00')");
    $pdo->exec("INSERT INTO n_nc (number, reported_at, source, description, severity, cost_impact, status) VALUES ('NC-T-1', '2026-09-05', 'production', 'Test', 'major', 50, 'open')");
    $pdo->exec("INSERT INTO n_delivery_stop (ship_date, id_customer, status, reason, pallets_returned, recorded_at) VALUES
        ('2026-09-10', 3, 'delivered', NULL, 2, '2026-09-10 09:00:00'), ('2026-09-11', 3, 'problem', 'closed', 0, '2026-09-11 09:00:00')");
    $pdo->exec("SET SESSION sql_mode = @mode");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        PDO::class => $pdo,
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string { throw new RuntimeException('no e-mail in tests'); }
        },
    ]);
    $container = $builder->build();
    $reports = $container->get(MonthlyReportService::class);
    $today = new DateTimeImmutable('2026-10-15');

    // Miesiąc z adresu : domyślnie ostatni zamknięty, nigdy przyszły.
    expectRep($reports->normalize(null, $today) === '2026-09', 'default month = last closed month');
    expectRep($reports->normalize('2026-11', $today) === '2026-09', 'future month rejected');
    expectRep($reports->normalize('2026-10', $today) === '2026-10', 'current month allowed');
    expectRep($reports->normalize('09-2026', $today) === '2026-09', 'bad month format rejected');
    expectRep(count($reports->months($today)) === 13 && array_key_first($reports->months($today)) === '2026-10', 'months : current + 12');

    $d = $reports->build('2026-09', $today);
    $k = array_column($d['kpis'], null, 'code');
    expectRep(!$d['current'] && $d['from'] === '2026-09-01' && $d['to'] === '2026-09-30', 'closed month period');
    expectRep($d['months'] === ['cur' => '2026-09', 'prev' => '2026-08', 'ly' => '2025-09'], 'comparison months');
    expectRep(abs($k['revenue']['values']['cur'] - 2000) < 0.01 && abs($k['revenue']['values']['prev'] - 1000) < 0.01 && abs($k['revenue']['values']['ly'] - 1600) < 0.01, 'revenue M / M-1 / N-1');
    expectRep($k['revenue']['trend_prev']['good'] === true && abs($k['revenue']['trend_prev']['delta'] - 100) < 0.01, 'revenue +100 % vs M-1, good');
    expectRep(abs($k['revenue']['trend_ly']['delta'] - 25) < 0.01, 'revenue +25 % vs N-1');
    expectRep((int) $k['cartons']['values']['cur'] === 100 && $k['customers']['values']['cur'] === 1, 'cartons and customers');
    expectRep($k['complaints']['values']['cur'] === 3 && $k['complaints']['values']['prev'] === 1 && $k['complaints']['trend_prev']['good'] === false, 'complaints count, more = worse');
    expectRep(abs($k['complaint_value']['values']['cur'] - 100) < 0.01 && abs($k['complaint_value']['values']['prev'] - 40) < 0.01, 'accepted complaint value = qty × price');
    expectRep(abs($k['complaint_rate']['values']['cur'] - 5) < 0.01, 'complaints / revenue = 5 %');
    expectRep(abs($k['complaint_days']['values']['cur'] - 1.5) < 0.01, 'days to decision (accepted 2 d, rejected 1 d)');
    expectRep($k['nc']['values']['cur'] === 1 && $k['nc']['values']['prev'] === 0, 'non-conformities');
    $c = $d['complaints'];
    expectRep($c['totals']['cur']['accepted'] === 1 && $c['totals']['cur']['rejected'] === 1 && $c['totals']['cur']['open'] === 1, 'complaints by status');
    expectRep($c['reasons'][0]['label'] === ['Jakość produktu', 'Qualité du produit'] && $c['reasons'][0]['n'] === 2, 'reasons PL / FR, most frequent first');
    expectRep($c['products'][0]['name'] === 'Croissant 70 g' && $c['customers'][0]['name'] === 'Boulangerie Test', 'complaints by product and customer');
    expectRep(abs($c['per_1000_cartons'] - 30) < 0.01, 'complaints per 1000 cartons');
    expectRep($d['customers']['top'][0]['name'] === 'Boulangerie Test' && $d['customers']['new'] === 1 && $d['customers']['lost'] === 1, 'customers : top, new and lost vs N-1');
    expectRep($d['deliveries']['delivered'] === 1 && $d['deliveries']['problem'] === 1 && $d['deliveries']['pallets_returned'] === 2
        && $d['deliveries']['reasons'][0]['label'] === ['Zamknięte', 'Fermé'], 'driver tablet deliveries');
    expectRep($d['margin']['top'][0]['name'] === 'Croissant 70 g' && abs($d['margin']['top'][0]['revenue'] - 2000) < 0.01, 'product margin table');
    expectRep($d['groups'][0]['name'] === 'Piekarnia' && abs($d['groups'][0]['delta_ly'] - 25) < 0.01, 'group revenue vs N-1');
    expectRep(str_contains($d['highlights'][0][0], '2 000 €') && str_contains($d['highlights'][0][1], 'Chiffre d’affaires'), 'highlights PL / FR');
    expectRep((bool) array_filter($d['highlights'], fn($h) => str_contains($h[0], 'Reklamacje : 3') && str_contains($h[1], 'motif principal : Qualité du produit')), 'complaint highlight');
    expectRep((bool) array_filter($d['notes'], fn($n) => str_contains($n[1], 'calculateur de coûts')), 'missing monthly costs noted in PL and FR');

    // Bieżący miesiąc : w toku, ułamek okresu.
    $now = $reports->build('2026-10', $today);
    expectRep($now['current'] && abs($now['elapsed'] - 15 / 31) < 0.001 && $now['to'] === '2026-10-15', 'current month in progress');

    foreach (['pl' => 'RAPORT_MIESIECZNY_2026-09_PL.pdf', 'fr' => 'RAPORT_MIESIECZNY_2026-09_FR.pdf'] as $lang => $name) {
        $pdf = $reports->pdf('2026-09', $lang, $today);
        expectRep(str_starts_with($pdf['content'], '%PDF') && $pdf['filename'] === $name && strlen($pdf['content']) > 20000, "PDF {$lang}");
    }

    // Wysyłka : walidacja, kolejka z PDF, bez SMTP.
    expectRepError(fn() => $reports->send(['month' => '2026-09', 'lang' => 'pl', 'to' => 'zarzad@firma'], 'Tester', $today), 'invalid e-mail rejected');
    expectRepError(fn() => $reports->send(['month' => '2026-09', 'lang' => 'de', 'to' => 'a@x.test'], 'Tester', $today), 'language pl / fr only');
    expectRepError(fn() => $reports->send(['month' => '2027-01', 'lang' => 'pl', 'to' => 'a@x.test'], 'Tester', $today), 'future month rejected for e-mail');
    expectRepError(fn() => $reports->send(['month' => '2026-09', 'lang' => 'pl', 'to' => ' '], 'Tester', $today), 'recipient required');
    $sent = $reports->send(['month' => '2026-09', 'lang' => 'fr', 'to' => 'a@x.test; b@x.test, a@x.test'], 'Tester', $today);
    expectRep($sent['queued'] === 2 && $sent['recipients'] === ['a@x.test', 'b@x.test'], 'one message per distinct address');
    $messages = $pdo->query("SELECT m.*, (SELECT COUNT(*) FROM communication_message_attachment a WHERE a.id_message = m.id AND a.mime_type = 'application/pdf') AS pdfs
        FROM communication_message m WHERE message_type = 'management_report' ORDER BY id")->fetchAll();
    expectRep(count($messages) === 2 && $messages[0]['status'] === 'queued' && (int) $messages[0]['pdfs'] === 1, 'queued with PDF attached');
    expectRep(str_contains($messages[0]['subject_text'], 'septembre 2026') && str_contains($messages[0]['body_text'], 'L\'essentiel')
        && str_contains($messages[0]['body_text'], 'SL Food Test'), 'French template rendered');

    // Ustawienia i wysyłka automatyczna.
    expectRepError(fn() => $reports->saveSettings(['recipients' => 'a@x.test', 'send_day' => 0, 'lang' => 'pl'], null), 'send day 1–28');
    expectRepError(fn() => $reports->saveSettings(['recipients' => 'nie-adres', 'send_day' => 3, 'lang' => 'pl'], null), 'recipients validated');
    expectRep($reports->autoSend(new DateTimeImmutable('2026-10-05'))['skipped'] === 'no recipients', 'auto send off without recipients');
    $settings = $reports->saveSettings(['recipients' => 'dir@x.test,  ', 'send_day' => 4, 'lang' => 'pl'], null);
    expectRep($settings === ['recipients' => 'dir@x.test', 'send_day' => 4, 'lang' => 'pl'], 'settings saved');
    expectRep(str_starts_with($reports->autoSend(new DateTimeImmutable('2026-10-03'))['skipped'], 'before day'), 'auto send waits for the day');
    $auto = $reports->autoSend(new DateTimeImmutable('2026-10-05'));
    expectRep(($auto['month'] ?? null) === '2026-09' && $auto['queued'] === 1, 'auto send previous month');
    expectRep(str_starts_with($reports->autoSend(new DateTimeImmutable('2026-10-06'))['skipped'], 'already sent'), 'auto send once a month');
    expectRep((int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE created_by = 'monthly-report:2026-09'")->fetchColumn() === 1, 'one automatic message');

    // Uprawnienia, rail, wyszukiwarka.
    expectRep(PageAccess::keyFor('/dashboard/monthly') === 'dashboard' && PageAccess::keyFor('/dashboard/monthly/pdf') === 'dashboard', 'page access : Rentowność');
    $sidebar = file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig');
    $search = file_get_contents(__DIR__ . '/../public/assets/js/atelier-search.js');
    expectRep(str_contains($sidebar, '/dashboard/monthly"') && str_contains($search, "'mensuel'") && str_contains($search, "'rapport'"), 'rail entry and search aliases');

    echo "monthly_report_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
