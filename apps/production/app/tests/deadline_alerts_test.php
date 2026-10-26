<?php
declare(strict_types=1);
/*
 * Alertes et échéances [153] : réclamation sans décision (alerte puis alerte rouge, une seule fois chacune), essai d'un
 * nouveau produit planifié et pas fait, date visée dépassée, mois sans coûts saisis (à partir du jour réglé, mois vide
 * compris), « Do zrobienia » de la page Dzisiaj, mois vides ignorés par la rentabilité.
 *
 *   php tests/deadline_alerts_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_deadline_alerts_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Notification\DeadlineAlertService;
use App\Services\Workshop\Execution\DevProductService;
use App\Services\Workshop\Migration\MigrationRunner;
use Symfony\Component\HttpFoundation\Request;

function expectAl(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_deadline_alerts_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "deadline_alerts_test: SKIPPED (no database: {$e->getMessage()})\n";
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
                 'test_product_stage', 'test_product_changelog', 'complaint',
                 'final_product_ppwr_sales_packaging', 'carton_dimension', 'palets_delivery', 'document_cmr', 'document_wz', 'shipment_log', 'app_notification'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'manager', '" . password_hash('Secret-123', PASSWORD_BCRYPT) . "')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES
        (1, 'Piekarnia', 0, 0, 0, 0, 0), (2, 'Cukiernia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_manager')");

    $pdo->exec("SET @mode := @@SESSION.sql_mode");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, id_section, active) VALUES
        (61, 'T-PIEK', 'Piekarnia T', 'Boulangerie T', '#8D1D2C', 1, 1), (62, 'T-CUK', 'Cukiernia T', 'Pâtisserie T', '#AF52DE', 2, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (10, 'Jan', 'Piekarz', 0, 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, active) VALUES (70, 10, 61, 1)");
    $pdo->exec("INSERT INTO test_product_status (id, name) VALUES (1, 'Propozycja'), (2, 'W produkcji'), (3, 'Zakończone')");
    $pdo->exec("INSERT INTO test_product_type (id, name) VALUES (1, 'Produkt')");
    $pdo->exec("INSERT INTO product_recipe (id, name) VALUES (5, 'Bułka dyniowa — receptura')");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, id_section, active) VALUES (900, 'Bułka dyniowa 80 g', 1, 80, 40, 1, 1)");
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
    $kernel = $container->get(WorkshopApiKernel::class);
    $call = function (string $method, string $path, ?array $body, array $headers = [], string $lang = 'pl') use ($kernel): array {
        $server = ['HTTP_ACCEPT_LANGUAGE' => $lang, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1'];
        foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        $request = Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
        $response = $kernel->handle($request, parse_url('/api/v1/production' . $path, PHP_URL_PATH));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    };
    $manager = ['Authorization' => 'Bearer ' . $call('POST', '/auth/login', ['login' => 'manager', 'password' => 'Secret-123'])[1]['data']['access_token']];
    $codes = fn(array $b) => array_map(fn($e) => $e['field'] . ':' . $e['code'], $b['errors'] ?? []);
    $alerts = $container->get(DeadlineAlertService::class);
    $dev = $container->get(DevProductService::class);
    $notes = fn(string $type) => $pdo->query("SELECT severity, title, body, link_url, dedup_key FROM app_notification WHERE type = '{$type}' ORDER BY id")->fetchAll();

    // --- Réclamations : 5 jours (alerte), 10 jours en vérification (rouge), 1 jour et décidée (rien)
    $pdo->exec("INSERT INTO customer (id, company, active) VALUES (3, 'Boulangerie Test', 1)");
    $pdo->exec("INSERT INTO complaint (id, complaint_key, complaint_reason, requested_action, id_customer, id_product, acceptance_timestamp, acceptance_status) VALUES
        (11, UUID(), 'product_quality', 'REFUND', 3, 900, NOW() - INTERVAL 5 DAY, 'NEW'),
        (12, UUID(), 'broken_packaging', 'CREDIT', 3, NULL, NOW() - INTERVAL 10 DAY, 'IN_REVIEW'),
        (13, UUID(), 'other', 'REFUND', 3, NULL, NOW() - INTERVAL 1 DAY, 'NEW'),
        (14, UUID(), 'other', 'REFUND', 3, NULL, NOW() - INTERVAL 20 DAY, 'ACCEPTED')");

    // --- Nouveaux produits : essai planifié hier, date visée dépassée ; un autre lancé (rien)
    $id = $dev->create(['name' => 'Bułka dyniowa', 'id_group' => 61, 'target_date' => date('Y-m-d', strtotime('-3 days'))], 1);
    $dev->planTrial($id, ['planned_date' => date('Y-m-d', strtotime('-1 day')), 'id_group' => 61], 1);
    $done = $dev->create(['name' => 'Tarta', 'id_group' => 61, 'target_date' => date('Y-m-d', strtotime('-30 days'))], 1);
    $dev->launch($done, ['final_product_id' => 900], 1);
    $ready = $dev->create(['name' => 'Rogal', 'id_group' => 61], 1);
    $dev->setStage($ready, ['stage' => 'launch'], 1);

    // --- Coûts : M-3 saisi, M-2 ouvert mais vide, M-1 absent (mois relatifs au jour du test)
    $pl = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    $first = new DateTimeImmutable('first day of this month');
    [$m3, $m2, $m1] = [$first->modify('-3 months'), $first->modify('-2 months'), $first->modify('-1 month')];
    $label = fn(DateTimeImmutable $d) => $pl[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    $pdo->exec("INSERT INTO cost_month (id, year, month, status) VALUES (7, {$m3->format('Y')}, {$m3->format('n')}, 'LOCKED'), (8, {$m2->format('Y')}, {$m2->format('n')}, 'OPEN')");
    $pdo->exec("INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included) VALUES (7, 1, 'Jan', 'Piekarz', 6500, 1)");
    expectAl(array_keys($container->get(ProfitabilityRepository::class)->costMonths()) === [$m3->format('Y-m')], 'empty cost month ignored by profitability (estimated from the last entered)');

    // Avant le jour réglé : pas d'alerte de coûts.
    $params = $container->get(ParamRepository::class);
    $params->setValue('alerts.cost_due_day', (string) ((int) date('j') + 1), null);
    $r = $alerts->run();
    expectAl($r['costs'] === 0 && $notes('cost_month_missing') === [], 'no cost alert before the due day');
    expectAl($r['complaints'] === 2 && $r['trials'] === 1 && $r['targets'] === 1, 'late complaints, trial and target found');
    $c = array_column($notes('complaint_late'), null, 'dedup_key');
    expectAl(count($c) === 2 && $c['complaint_late:11']['severity'] === 'warning' && $c['complaint_late:11']['title'] === 'Reklamacja bez decyzji od 5 dni'
        && str_contains($c['complaint_late:11']['body'], 'Boulangerie Test · Bułka dyniowa 80 g · Jakość produktu — nowa.') && $c['complaint_late_urgent:12']['severity'] === 'danger'
        && $c['complaint_late_urgent:12']['title'] === 'Reklamacja bez decyzji od 10 dni' && str_contains($c['complaint_late_urgent:12']['body'], 'w weryfikacji')
        && $c['complaint_late:11']['link_url'] === '/franchise-complaints', 'complaint alerts: warning after 3 days, urgent after 7, customer, product, reason');
    $t = $notes('dev_trial_late');
    expectAl(count($t) === 1 && $t[0]['title'] === 'Nowy produkt : test 1 zaległy' && str_contains($t[0]['body'], 'Bułka dyniowa — zaplanowany na ' . date('d.m.Y', strtotime('-1 day')) . ' (Piekarnia T)')
        && $t[0]['link_url'] === "/test-products/{$id}/tablet", 'late trial alert with date, group and link');
    $g = $notes('dev_target_late');
    expectAl(count($g) === 1 && str_contains($g[0]['body'], 'etap « Test 1 : receptura i zdjęcia »'), 'target date alert for the product in development only (launched one ignored)');

    // À partir du jour réglé : M-2 (vide) et M-1 manquent → deux alertes rouges, chacune une fois.
    $params->setValue('alerts.cost_due_day', '1', null);
    $r = $alerts->run();
    $alerts->run();
    $k = $notes('cost_month_missing');
    expectAl($r['costs'] === 2 && count($k) === 2 && $k[0]['title'] === 'Brak kosztów za ' . $label($m2) && $k[1]['title'] === 'Brak kosztów za ' . $label($m1)
        && str_contains($k[1]['body'], 'na kosztach z ' . $label($m3)) && $k[0]['link_url'] === '/month-close/' . $m2->format('Y-m') && $k[0]['severity'] === 'danger', 'missing cost months since the last entered one');
    expectAl(count($notes('complaint_late')) === 2 && count($notes('dev_trial_late')) === 1, 'alerts published once (dedup)');

    // Après 7 jours, la réclamation 11 passe en rouge (nouvelle alerte), une seule fois.
    $pdo->exec('UPDATE complaint SET acceptance_timestamp = NOW() - INTERVAL 8 DAY WHERE id = 11');
    $alerts->run();
    $alerts->run();
    $c = $notes('complaint_late');
    expectAl(count($c) === 3 && $c[2]['dedup_key'] === 'complaint_late_urgent:11', 'escalation to urgent once');

    // Paramètre : délai 6 jours → la réclamation de 5 jours n'est plus en retard.
    $params->setValue('alerts.complaint_days', '6', null);
    $pdo->exec('UPDATE complaint SET acceptance_timestamp = NOW() - INTERVAL 5 DAY WHERE id = 11');
    expectAl($alerts->run()['complaints'] === 1, 'complaint delay from the parameter');

    // « Do zrobienia » : essai en retard, lancement à faire, mois sans coûts.
    $todo = array_column($alerts->todo(), null, 'icon');
    expectAl($todo['bi-kanban']['count'] === 1 && $todo['bi-rocket-takeoff']['count'] === 1 && $todo['bi-calculator']['count'] === 2
        && $todo['bi-calculator']['note'] === $label($m2) . ', ' . $label($m1) . ' — rentowność liczona na szacunku' && $todo['bi-calculator']['positions'] === ['ADMINISTRACJA'],
        'today: late trials, launches to do, missing cost months');
    // Coûts saisis pour M-2 et M-1 : plus rien.
    $pdo->exec("INSERT INTO cost_month (id, year, month, status) VALUES (9, {$m1->format('Y')}, {$m1->format('n')}, 'OPEN')");
    $pdo->exec("INSERT INTO cost_month_employee (month_id, section_id, name, surname, monthly_cost, is_included) VALUES (8, 1, 'Jan', 'Piekarz', 6500, 1), (9, 1, 'Jan', 'Piekarz', 6500, 1)");
    expectAl(!isset(array_column($alerts->todo(), null, 'icon')['bi-calculator']), 'costs entered: no more cost alert');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "deadline_alerts_test: OK\n";
