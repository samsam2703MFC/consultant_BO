<?php
declare(strict_types=1);
/*
 * [285] Raport stanowisk (makieta 39) sur une base jetable : minutes jour / semaine / mois et remplissage (÷ 8 h × jours ouvrés
 * × personnes), fait / dû, en retard, tâches du mois ; administrateur et profil CEO seulement ; remplissage du poste
 * (« Zadania stanowiska ») ; minutes masquées dans « Kto co zrobił » pour un non-administrateur ; Dzisiaj sans minutes.
 *
 *   php tests/position_report_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Workshop\Migration\MigrationRunner;

function expectR(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'position_report_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "position_report_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency',
                 'customer', 'document_order_shipping_difference', 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings',
                 'supplier', 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month',
                 'cost_media_section_allocation', 'cost_media_type', 'communication_message', 'communication_message_attachment', 'communication_email_account',
                 'erp_company_data', 'flattened_recipe_recipe', 'product_preparation_type', 'product_recipe_preparation_connection', 'product_recipe_type',
                 'product_recipe_preparation_step', 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status', 'test_product_type',
                 'test_product_stage', 'test_product_changelog',
                 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery', 'document_cmr', 'document_wz', 'shipment_log', 'app_notification'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', '', 0, 'ada', 'x'), (2, 'Ewa', 'Finanse', 'Ewa F.', '', '', 0, 'ewa', 'x'),
        (3, 'Kasia', 'Kierownik', 'Kasia K.', '', '', 0, 'kasia', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (8, 'Administracja', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $pos = fn(string $code) => (int) $pdo->query("SELECT id FROM n_position WHERE code = '{$code}'")->fetchColumn();
    $adm = $pos('ADMINISTRACJA'); $kier = $pos('KIEROWNIK_PRODUKCJI');
    $pdo->exec("UPDATE n_position SET id_reports_to = {$kier} WHERE id = {$adm}");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) VALUES (1, {$adm}), (2, {$adm}), (3, {$kier})");
    $pdo->exec("UPDATE n_position_task SET active = 0");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, title_pl, title_fr, frequency, minutes, due_time, link_path, mandatory, block_mode, sort_order, active) VALUES
        (901, {$adm}, 'Rozliczenie przewoźników dnia', 'x', 'daily', 15, '11:00:00', '/shipments', 1, 'due', 1, 1),
        (902, {$adm}, 'Płatności dnia', 'x', 'daily', 25, '11:00:00', '/finances/payments-day', 1, 'due', 2, 1),
        (903, {$adm}, 'Koszty usług', 'x', 'monthly', 30, NULL, '/finances/costs', 0, 'due', 3, 1)");
    $pdo->exec("UPDATE n_position_task SET auto_code = 'payments_reviewed' WHERE id = 902");

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('t', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
        }]);
    $c = $b->build();


    $report = fn() => $c->make(App\Services\Organization\PositionReportService::class);
    $tasks = $c->get(App\Services\Organization\PositionTaskService::class);
    $now = new DateTimeImmutable('2026-11-12');   // czwartek, 11.11 święto
    $r = $report()->report(1, $now);
    expectR($r['period']['month_days'] === 20 && in_array('11.11', $r['period']['holidays'], true) && $r['period']['week_days'] === 4, 'listopad : 20 dni roboczych (11.11 święto), tydzień 46 : 4 dni ' . json_encode($r['period']));
    $adm = array_values(array_filter($r['rows'], fn($x) => $x['name'] === 'Administracja i fakturowanie' || str_contains($x['name'], 'Administracja')))[0] ?? null;
    expectR($adm !== null && $adm['people'] === 2, 'Administracja : 2 osoby ' . json_encode(array_column($r['rows'], 'name')));
    // 901 i 902 codziennie (15 + 25 min) ; 903 co miesiąc 30 min.
    expectR($adm['day']['min'] === 40 && $adm['day']['cap'] === 960 && $adm['day']['pct'] === 4, 'dzień : 40 min z 2 × 8 h ' . json_encode($adm['day']));
    expectR($adm['month']['cap'] === 480 * 20 * 2 && $adm['month']['min'] === 40 * 20 + 30, 'miesiąc : 20 × 40 + 30 min z 2 × 160 h ' . json_encode($adm['month']));
    $t901 = array_values(array_filter($adm['tasks'], fn($t) => str_starts_with($t['title'], 'Rozliczenie')))[0];
    expectR($t901['count'] === 20 && $t901['total_min'] === 300 && $t901['minutes'] === 15 && $t901['freq'] === 'codziennie · 11:00', 'zadanie : × 20 w miesiącu, 300 min ' . json_encode($t901));
    expectR($adm['due'] > 0 && $adm['late'] > 0 && $r['kpi']['late'] >= $adm['late'] && $r['kpi']['positions'] >= 1, 'spóźnione dni przed 12.11');
    expectR($r['kpi']['top'] !== null && $r['kpi']['target'] === 90, 'kafelki : najbardziej wypełnione, cel 90 %');
    try { $report()->report(2, $now); expectR(false, 'raport : nie dla stanowiska'); } catch (CustomException $e) { expectR($e->getCode() === 403, '403'); }
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (4, 'Wiktor', 'W', 'Wiktor W.', '', '', 0, 'wiktor', 'x')");
    $pdo->exec("INSERT INTO n_user_ceo (id_user) VALUES (4)");
    expectR(is_array($c->make(App\Services\Organization\PositionReportService::class)->report(4, $now)['rows']), 'profil CEO : raport');
    $f = $report()->positionFill($adm['id'], $now);
    expectR($f['day']['min'] === 40 && $f['month']['pct'] === $adm['month']['pct'] && $f['people'] === 2, 'wypełnienie stanowiska (Zadania stanowiska)');

    // Kto co zrobił : minuty tylko dla administratora (i CEO).
    $kier = $pos('KIEROWNIK_PRODUKCJI');
    $pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES ({$kier}, 'execution')");
    $exA = $c->make(App\Services\Organization\PositionTaskService::class)->execution('week', 1, $now);
    $exK = $c->make(App\Services\Organization\PositionTaskService::class)->execution('week', 3, $now);
    $mins = fn($ex) => array_filter(array_merge(...array_map(fn($row) => array_merge(...$row['cells']), $ex['rows'])), fn($it) => ($it['minutes'] ?? null) !== null);
    expectR($exA['tiles']['minutes'] > 0 && count($mins($exA)) > 0, 'administrator : minuty w Kto co zrobił');
    expectR($exK['tiles']['minutes'] === null && count($mins($exK)) === 0, 'kierownik : bez minut w Kto co zrobił');

    // Dzisiaj (Mój tydzień) : bez minut dla nie-administratora.
    $m = new ReflectionMethod(App\Controllers\Web\Dashboard\DashboardController::class, 'withoutMinutes');
    $w = $m->invoke(null, ['totals' => ['minutes' => 120, 'done_minutes' => 40, 'done' => 1], 'flow' => ['today' => [['title' => 'x', 'minutes' => 25]]]]);
    expectR($w['totals']['minutes'] === null && $w['totals']['done_minutes'] === null && $w['totals']['done'] === 1 && $w['flow']['today'][0]['minutes'] === null, 'Mój tydzień bez minut');
    echo "position_report_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
