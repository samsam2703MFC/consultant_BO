<?php
declare(strict_types=1);
/*
 * Nowe produkty (CRM) [150-152] : biuro tworzy produkt dla grupy i planuje test ; tablet TEJ grupy go widzi (inna grupa
 * nie), ekran główny bez logowania (summary), test potwierdzony raz na tablecie (zdjęcia, ilość, wynik, co zmienić) →
 * etap test 2 → receptura do zatwierdzenia ; receptura zatwierdzona na tablecie → wprowadzenie ; biuro łączy produkt ERP
 * → wprowadzony (data dla kierownika produkcji), produkt znika z tabletu ; dzwonek ERP ; błędy PL / FR.
 *
 *   php tests/dev_pipeline_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_dev_pipeline_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Workshop\Execution\DevProductService;
use App\Services\Workshop\Migration\MigrationRunner;
use Symfony\Component\HttpFoundation\Request;

function expectDev(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_dev_pipeline_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "dev_pipeline_test: SKIPPED (no database: {$e->getMessage()})\n";
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
    $dev = $container->get(DevProductService::class);

    // --- Biuro : nowy produkt dla grupy Piekarnia, test 1 zaplanowany z wskazówkami
    try { $dev->create(['name' => ' ', 'id_group' => 61], 1); throw new RuntimeException('FAILED: empty name accepted'); }
    catch (CustomException $e) { expectDev($e->getCode() === 422, 'name required'); }
    try { $dev->create(['name' => 'X', 'id_group' => 999], 1); throw new RuntimeException('FAILED: unknown group accepted'); }
    catch (CustomException $e) { expectDev($e->getCode() === 422, 'group must exist'); }
    $id = $dev->create(['name' => 'Bułka dyniowa', 'id_group' => 61, 'target_date' => '2026-12-15', 'notes' => '15 % dyni'], 1);
    $tp = $pdo->query("SELECT id_status, id_type, id_section FROM test_product WHERE id = {$id}")->fetch();
    expectDev((int) $tp['id_status'] === 1 && (int) $tp['id_type'] === 1 && (int) $tp['id_section'] === 1, 'test product created (proposal, type product, section of the group)');
    $sheet = $dev->planTrial($id, ['planned_date' => '2026-10-27', 'instructions' => 'Zrobić 20 szt., 220 °C', 'id_group' => 61], 1);
    expectDev($sheet['product']['dev']['stage'] === 'test1' && $sheet['trials'][0]['status'] === 'planned' && $sheet['trials'][0]['no'] === 1
        && $sheet['product']['dev']['next']['label'] === 'Zrobić test 1 · 27.10', 'trial 1 planned: stage test 1, next step with the date');
    $other = $dev->create(['name' => 'Tarta cytrynowa', 'id_group' => 62], 1);

    // --- Tablety : grupa Piekarnia widzi swój produkt, Cukiernia swój ; ekran główny bez osoby
    $pair = function (int $group) use ($call, $manager): array {
        [, $b] = $call('POST', '/kiosk/devices', ['name' => 'Tablet ' . $group, 'group_id' => $group], $manager);
        return ['X-Device-Token' => $call('POST', '/kiosk/pair', ['code' => $b['data']['pairing_code']])[1]['data']['device_token']];
    };
    $bakery = $pair(61);
    $pastry = $pair(62);
    [$s, $b] = $call('GET', '/kiosk/development/summary', null, $bakery);
    expectDev($s === 200 && array_column($b['data']['products'], 'name') === ['Bułka dyniowa'] && $b['data']['products'][0]['stage'] === 'test1'
        && $b['data']['products'][0]['step'] === 2 && $b['data']['products'][0]['steps'] === 6 && $b['data']['products'][0]['next']['kind'] === 'trial', 'home widget: own group product, step 2/6, next trial');
    [, $b] = $call('GET', '/kiosk/development/summary', null, $pastry, 'fr');
    expectDev(array_column($b['data']['products'], 'name') === ['Tarta cytrynowa'] && $b['data']['products'][0]['stage_label'] === 'Idée', 'other group sees only its product (FR labels)');

    $call('PUT', '/kiosk/employees/70/credentials', ['pin' => '2468'], $manager);
    [$s, $b] = $call('POST', '/kiosk/identify', ['employee_id' => 70, 'pin' => '2468'], $bakery);
    expectDev($s === 200, 'person identified on the bakery tablet');
    $jan = $bakery + ['X-Employee-Token' => $b['data']['employee_token']];
    [$s, $b] = $call('GET', '/kiosk/development', null, $jan);
    expectDev($s === 200 && count($b['data']['products']) === 1 && $b['data']['products'][0]['dev']['group']['name'] === 'Piekarnia T', 'development tab: product of the group');
    [$s, $b] = $call('GET', "/kiosk/development/{$other}", null, $jan);
    expectDev($s === 404, 'product of another group: 404');
    [$s, $card] = $call('GET', "/kiosk/development/{$id}", null, $jan);
    $card = $card['data'];
    expectDev($s === 200 && $card['trials'][0]['instructions'] === 'Zrobić 20 szt., 220 °C' && $card['flow'][1]['state'] === 'current' && $card['flow'][0]['state'] === 'done'
        && $card['max_trial_photos'] === 6 && $card['recipe_confirmed'] === null, 'card: office instructions, flow (idea done, test 1 current)');

    // --- Test 1 na tablecie : walidacja, potem « do poprawy » ze zdjęciami → test 2
    $jpeg = function (): string { $im = imagecreatetruecolor(320, 240); imagefill($im, 0, 0, imagecolorallocate($im, 210, 160, 90)); ob_start(); imagejpeg($im, null, 70); return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean()); };
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/trials", ['result' => 'fix', 'photos' => []], $jan);
    expectDev($s === 422 && $codes($b) === ['notes:REQUIRED', 'photos:REQUIRED'], 'photo and « what to change » required');
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/trials", ['result' => 'maybe', 'photos' => ['data:text/plain;base64,eA==']], $jan, 'fr');
    expectDev($s === 422 && in_array('result:INVALID_CHOICE', $codes($b), true) && in_array('photos.0:INVALID_PHOTO', $codes($b), true), 'bad result and non-image refused');
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/trials", ['result' => 'ok', 'photos' => array_fill(0, 7, $jpeg())], $jan);
    expectDev($s === 422 && $codes($b) === ['photos:TOO_MANY'], 'at most 6 photos');
    $taken = date('Y-m-d H:i:s', time() - 600);
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/trials", ['quantity' => 20, 'result' => 'fix', 'notes' => 'Skórka za jasna', 'photos' => [['image' => $jpeg(), 'taken_at' => $taken], $jpeg()]], $jan);
    $card = $b['data'];
    expectDev($s === 201 && $card['dev']['stage'] === 'test2' && $card['trials'][0]['status'] === 'done' && $card['trials'][0]['result'] === 'fix'
        && $card['trials'][0]['employee'] === 'Jan Piekarz' && $card['trials'][0]['quantity'] === 20.0 && count($card['trials'][0]['photos']) === 2
        && $card['trials'][0]['photos'][0]['taken_at'] === $taken && str_starts_with($card['trials'][0]['photos'][0]['src'], 'data:image/jpeg;base64,'), 'trial 1 confirmed once: planned trial completed, who, quantity, photos with time → test 2');
    expectDev((int) $pdo->query("SELECT COUNT(*) FROM n_dev_trial WHERE id_test_product = {$id}")->fetchColumn() === 1, 'no duplicate trial (planned one completed)');
    $bell = $pdo->query("SELECT severity, title, body, link_url AS link FROM app_notification WHERE type = 'dev_trial' ORDER BY id DESC LIMIT 1")->fetch();
    expectDev($bell && $bell['severity'] === 'warning' && $bell['title'] === 'Nowy produkt : test 1' && str_contains($bell['body'], 'Do poprawy : Skórka za jasna (Jan Piekarz)')
        && $bell['link'] === "/test-products/{$id}/tablet", 'ERP bell: trial result with what to change and who');

    // --- Recepturę zatwierdza się dopiero po udanym teście 2
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/recipe", [], $jan, 'fr');
    expectDev($s === 409 && $b['description'] === 'DEV_RECIPE_NOT_READY' && str_contains($b['message'], 'Second test'), 'recipe cannot be confirmed before a good second test (FR)');
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/trials", ['quantity' => 24, 'result' => 'ok', 'photos' => [$jpeg()]], $jan);
    expectDev($s === 201 && $b['data']['dev']['stage'] === 'recipe' && $b['data']['trials'][1]['no'] === 2 && $b['data']['dev']['next']['kind'] === 'recipe', 'trial 2 OK (not planned: added) → recipe to confirm');
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/recipe", [], $jan);
    expectDev($s === 409 && $b['description'] === 'DEV_RECIPE_MISSING', 'no ERP recipe yet: office must choose it');
    $pdo->exec("UPDATE test_product SET id_recipe = 5 WHERE id = {$id}");
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/recipe", [], $jan);
    expectDev($s === 200 && $b['data']['dev']['stage'] === 'launch' && $b['data']['recipe_confirmed']['by'] === 'Jan Piekarz' && $b['data']['dev']['next']['kind'] === 'office'
        && (int) $pdo->query("SELECT id_status FROM test_product WHERE id = {$id}")->fetchColumn() === 2, 'recipe confirmed on the tablet: launch stage, who, test product « in production »');
    [$s, $b] = $call('POST', "/kiosk/development/{$id}/trials", ['result' => 'ok', 'photos' => [$jpeg()]], $jan);
    expectDev($s === 409 && $b['description'] === 'DEV_TRIAL_NOT_ALLOWED' && str_contains($b['message'], 'Wprowadzenie'), 'no more trials once in launch');

    // --- Biuro : karta, tablica, wprowadzenie
    $sheet = $dev->sheet($id);
    expectDev(count($sheet['trials']) === 2 && $sheet['product']['recipe_confirmed_name'] === 'Jan Piekarz' && count($sheet['trials'][0]['photos']) === 2
        && !isset($sheet['trials'][0]['photos'][0]['src']), 'office card: trials with photo ids (served as files), recipe confirmed by');
    $photoId = $sheet['trials'][0]['photos'][0]['id'];
    expectDev(($dev->trialPhoto($photoId)['mime'] ?? '') === 'image/jpeg', 'trial photo served from the database');
    $board = $dev->pipeline(61);
    $byStage = array_column($board['columns'], null, 'code');
    expectDev($board['count'] === 1 && count($byStage['launch']['items']) === 1 && $byStage['launch']['items'][0]['dev']['trials_done'] === 2, 'pipeline filtered by group: product in « launch » with 2 trials');
    try { $dev->planTrial($id, ['id_group' => 61], 1); throw new RuntimeException('FAILED: trial planned after the recipe'); }
    catch (CustomException $e) { expectDev($e->getCode() === 422, 'no trial planned once launching'); }
    try { $dev->launch($id, ['final_product_id' => 12345], 1); throw new RuntimeException('FAILED: unknown ERP product accepted'); }
    catch (CustomException $e) { expectDev($e->getCode() === 422, 'launch needs an existing ERP product'); }
    $sheet = $dev->launch($id, ['final_product_id' => 900], 1);
    expectDev($sheet['product']['dev']['stage'] === 'launched' && $sheet['product']['final_product_name'] === 'Bułka dyniowa 80 g' && !$sheet['product']['on_tablet']
        && (int) $pdo->query("SELECT id_status FROM test_product WHERE id = {$id}")->fetchColumn() === 3
        && $pdo->query('SELECT launch_date FROM n_mgr_launch WHERE id_product = 900')->fetchColumn() === date('Y-m-d'), 'launched: ERP product linked, launch date for the production manager, off the tablet');
    [, $b] = $call('GET', '/kiosk/development/summary', null, $bakery);
    expectDev($b['data']['products'] === [], 'launched product no longer on the tablet');

    // --- Etap ręcznie (wstrzymanie, powrót), anulowanie zaplanowanego testu
    $sheet = $dev->planTrial($other, ['id_group' => 62], 1);
    $planned = $sheet['trials'][0]['id'];
    $sheet = $dev->cancelTrial($other, $planned);
    expectDev($sheet['trials'] === [], 'planned trial cancelled');
    try { $dev->setStage($other, ['stage' => 'launched'], 1); throw new RuntimeException('FAILED: launched set by hand'); }
    catch (CustomException $e) { expectDev($e->getCode() === 422, 'launched only through « Wprowadź »'); }
    $dev->setStage($other, ['stage' => 'stopped'], 1);
    [, $b] = $call('GET', '/kiosk/development/summary', null, $pastry);
    expectDev($b['data']['products'] === [], 'stopped product hidden from the tablet');
    $sheet = $dev->setStage($other, ['stage' => 'test1'], 1);
    expectDev($sheet['product']['dev']['stage'] === 'test1', 'stage restored by the office');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "dev_pipeline_test: OK\n";
