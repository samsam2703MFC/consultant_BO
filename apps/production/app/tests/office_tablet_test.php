<?php
declare(strict_types=1);
/*
 * Tablet biurowy : tablet w trybie « office » (bez grupy), działy → nazwiska (także osoby bez profilu produkcji),
 * osobisty kod QR (osoba + dzień), pierwszy skan → tylko PIN tej osoby, telefon innej osoby odrzucony
 * (QR_OTHER_PERSON), kod z innego dnia odrzucony, wejście / wyjście zatwierdzone na telefonie i potwierdzone
 * na tablecie (status), tablet kierowcy odrzucony (DEVICE_NOT_OFFICE ; tablet pracowni otwarty na /office działa), stary QR grupy działa jak wcześniej.
 *
 *   php tests/office_tablet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_office_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\Jwt;
use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Workshop\Migration\MigrationRunner;
use Symfony\Component\HttpFoundation\Request;

function expectOff(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_office_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "office_tablet_test: SKIPPED (no database: {$e->getMessage()})\n";
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
        (1, 'Piekarnia', 0, 0, 0, 0, 0), (7, 'Czekolada', 0, 0, 0, 0, 0), (8, 'Administracja', 0, 0, 0, 0, 0)");
    // Osoba bez profilu przed migracją : migracja 101 tworzy jej profil (PIN możliwy od razu).
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (19, 'Zofia', 'Kadry', 0, 8)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_manager')");
    expectOff((int) $pdo->query('SELECT COUNT(*) FROM n_prod_employee WHERE id_production_employee = 19 AND active = 1')->fetchColumn() === 1, 'migration: profile created for an employee without one');
    expectOff((string) $pdo->query("SELECT value FROM n_prod_param WHERE code = 'kiosk.office_qr_ttl_s'")->fetchColumn() === '120', 'migration: personal QR validity parameter');

    $pdo->exec("SET @mode := @@SESSION.sql_mode");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (20, 'Jan', 'Kowal', 0, 1), (30, 'Ola', 'Biuro', 0, 8), (31, 'Stary', 'Pracownik', 0, 8)");
    $pdo->exec("INSERT IGNORE INTO n_prod_group (id, code, name_pl, name_fr, active) VALUES (1, 'PIEK_T', 'Piekarnia', 'Boulangerie', 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, id_primary_group, active) VALUES (80, 20, 1, 1), (81, 31, NULL, 0)");
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
        $server = ['HTTP_ACCEPT_LANGUAGE' => $lang, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)'];
        foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        $request = Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
        $response = $kernel->handle($request, parse_url('/api/v1/production' . $path, PHP_URL_PATH));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    };
    $manager = ['Authorization' => 'Bearer ' . $call('POST', '/auth/login', ['login' => 'manager', 'password' => 'Secret-123'])[1]['data']['access_token']];
    $codes = fn(array $b) => array_map(fn($e) => $e['field'] . ':' . $e['code'], $b['errors'] ?? []);
    $tokenOf = fn(array $q) => substr($q['url'], strpos($q['url'], '#t=') + 3);
    $base = 'https://erp.example/production';

    // --- Tablet biurowy : tryb « office », bez grupy, parowanie
    [$s, $b] = $call('POST', '/kiosk/devices', ['name' => 'Tablet biuro', 'mode' => 'office', 'group_id' => 1], $manager);
    expectOff($s === 201, 'office tablet created');
    $officeId = $b['data']['id'];
    [, $pair] = $call('POST', '/kiosk/pair', ['code' => $b['data']['pairing_code']]);
    $office = ['X-Device-Token' => $pair['data']['device_token']];
    expectOff($pair['data']['device']['mode'] === 'office' && $pair['data']['device']['group_id'] === null, 'paired in office mode, group removed');
    [, $b] = $call('GET', '/kiosk/device', null, $office);
    expectOff($b['data']['mode'] === 'office', 'device knows its office mode');
    [$s, $b] = $call('POST', '/kiosk/devices', ['name' => 'X', 'mode' => 'biuro'], $manager);
    expectOff($s === 422 && $codes($b) === ['mode:INVALID_CHOICE'], 'unknown mode refused');

    [, $b] = $call('POST', '/kiosk/devices', ['name' => 'Tablet Piekarnia', 'group_id' => 1], $manager);
    $atelierId = $b['data']['id'];
    $atelier = ['X-Device-Token' => $call('POST', '/kiosk/pair', ['code' => $b['data']['pairing_code']])[1]['data']['device_token']];
    [$s, $b] = $call('GET', '/kiosk/office/sections', null, $atelier, 'fr');
    expectOff($s === 200 && count($b['data']) > 0, 'a workshop tablet opened on /office shows the office screen (all groups, all people)');
    [, $b] = $call('POST', '/kiosk/devices', ['name' => 'Tablet kierowcy', 'mode' => 'driver'], $manager);
    $driverDev = ['X-Device-Token' => $call('POST', '/kiosk/pair', ['code' => $b['data']['pairing_code']])[1]['data']['device_token']];
    [$s, $b] = $call('GET', '/kiosk/office/sections', null, $driverDev, 'fr');
    expectOff($s === 403 && $b['description'] === 'DEVICE_NOT_OFFICE' && str_contains($b['message'], 'chauffeur'), 'driver tablet cannot open the office screens');
    [$s] = $call('GET', '/kiosk/office/sections', null, []);
    expectOff($s === 401, 'office screens need a paired tablet');

    // --- Działy → nazwiska : wszystkie działy z osobami, osoby bez profilu też, nieaktywne nie
    [$s, $b] = $call('GET', '/kiosk/office/sections', null, $office);
    $sections = array_column($b['data'], null, 'id');
    expectOff($s === 200 && array_keys($sections) === [1, 8], 'sections with active people only (empty section hidden)');
    $admin = array_column($sections[8]['people'], null, 'id');
    expectOff($sections[8]['name'] === 'Administracja' && array_keys($admin) === [30, 19], 'section people sorted by surname, inactive person hidden');
    expectOff($admin[30]['employee_id'] === null && $admin[30]['has_pin'] === false && $admin[30]['phones'] === 0 && $admin[30]['clocked_in'] === false, 'person without production profile listed');
    expectOff($sections[1]['people'][0]['employee_id'] === 80 && $sections[1]['people'][0]['name'] === 'Jan Kowal', 'workshop person listed in its section');

    // --- Osobisty kod QR : osoba + dzień ; profil utworzony przy pierwszym kodzie
    [$s, $b] = $call('POST', '/kiosk/office/qr', ['person_id' => 30, 'base' => 'javascript:alert(1)'], $office);
    expectOff($s === 422 && $codes($b) === ['base:INVALID_URL'], 'QR address must be http(s)');
    [$s, $b] = $call('POST', '/kiosk/office/qr', ['person_id' => 31, 'base' => $base], $office);
    expectOff($s === 404, 'inactive person has no QR');
    [$s, $b] = $call('POST', '/kiosk/office/qr', ['base' => $base], $office);
    expectOff($s === 422 && $codes($b) === ['person_id:REQUIRED'], 'person required');
    [$s, $q] = $call('POST', '/kiosk/office/qr', ['person_id' => 30, 'base' => $base . '/'], $office);
    $ola = $q['data']['person']['employee_id'] ?? null;
    expectOff($s === 200 && is_int($ola) && $q['data']['person']['name'] === 'Ola Biuro' && $q['data']['day'] === date('Y-m-d'), 'personal QR: person and day');
    expectOff(str_starts_with($q['data']['url'], $base . '/kiosk/scan#t=') && $q['data']['expires_in'] === 120 && $q['data']['refresh_s'] === 105
        && count($q['data']['rows']) === $q['data']['size'], 'personal QR matrix, valid 120 s, renewed before');
    expectOff((int) $pdo->query("SELECT id_production_employee FROM n_prod_employee WHERE id = {$ola}")->fetchColumn() === 30, 'production profile created on the first QR');
    $claims = (new Jwt(str_repeat('s', 48)))->decode($tokenOf($q['data']), KioskAuthService::QR_AUDIENCE);
    expectOff($claims['emp'] === $ola && $claims['day'] === date('Y-m-d') && $claims['dev'] === $officeId, 'QR token carries the tablet, the person and the day');
    $olaQr = $tokenOf($q['data']);
    $since = $q['data']['since'];
    [, $q2] = $call('POST', '/kiosk/office/qr', ['person_id' => 30, 'base' => $base], $office);
    expectOff($q2['data']['person']['employee_id'] === $ola && (int) $pdo->query('SELECT COUNT(*) FROM n_prod_employee WHERE id_production_employee = 30')->fetchColumn() === 1, 'second QR reuses the profile');

    // --- Telefon, pierwszy skan : tylko osoba z kodu, PIN tej osoby
    [$s, $b] = $call('GET', '/kiosk/phone/people', null, ['X-Qr-Token' => $olaQr]);
    expectOff($s === 200 && count($b['data']) === 1 && $b['data'][0]['id'] === $ola && $b['data'][0]['has_pin'] === false, 'first scan of a personal QR: only that person');
    [$s, $b] = $call('POST', '/kiosk/phone/enroll', ['employee_id' => $ola, 'pin' => '1234'], ['X-Qr-Token' => $olaQr]);
    expectOff($s === 409 && $b['description'] === 'PIN_NOT_SET', 'no PIN yet: ask the manager');
    $call('PUT', "/kiosk/employees/{$ola}/credentials", ['pin' => '4321'], $manager);
    $call('PUT', '/kiosk/employees/80/credentials', ['pin' => '2468'], $manager);
    [$s, $b] = $call('POST', '/kiosk/phone/enroll', ['employee_id' => 80, 'pin' => '2468'], ['X-Qr-Token' => $olaQr]);
    expectOff($s === 403 && $b['description'] === 'QR_OTHER_PERSON' && str_contains($b['message'], 'Ola Biuro'), 'someone else cannot register on a personal QR');
    expectOff((int) $pdo->query('SELECT COUNT(*) FROM n_prod_phone')->fetchColumn() === 0, 'no phone registered for the other person');
    [$s, $en] = $call('POST', '/kiosk/phone/enroll', ['employee_id' => $ola, 'pin' => '4321'], ['X-Qr-Token' => $olaQr]);
    $olaPhone = $en['data']['phone_token'] ?? '';
    expectOff($s === 201 && strlen($olaPhone) === 64 && $en['data']['employee']['id'] === $ola, 'phone registered with the PIN of the QR person');

    // --- Tablet czeka : nic jeszcze ; wejście na telefonie ; tablet potwierdza
    [$s, $st] = $call('GET', "/kiosk/office/status?employee_id={$ola}&since=" . rawurlencode($since), null, $office);
    expectOff($s === 200 && $st['data']['clocked'] === false && $st['data']['clocked_in'] === false, 'tablet: nothing recorded yet');
    [$s, $b] = $call('GET', "/kiosk/office/status?employee_id={$ola}&since=today", null, $office);
    expectOff($s === 422 && $codes($b) === ['since:INVALID_DATETIME'], 'status: date-time checked');
    [$s] = $call('GET', "/kiosk/office/status?employee_id={$ola}&since=" . rawurlencode($since), null, $driverDev);
    expectOff($s === 403, 'status not for the driver tablet');
    $olaPhoneH = ['X-Qr-Token' => $olaQr, 'X-Phone-Token' => $olaPhone];
    [$s, $me] = $call('GET', '/kiosk/phone/me', null, $olaPhoneH);
    expectOff($s === 200 && $me['data']['employee']['id'] === $ola && $me['data']['clocked_in'] === false, 'phone: my state on my QR');
    $yes = fn(array $qs) => array_map(fn($x) => ['question_id' => $x['id'], 'yes' => true], $qs);
    $img = imagecreatetruecolor(300, 120);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imageline($img, 10, 100, 290, 20, imagecolorallocate($img, 0, 0, 0));
    ob_start();
    imagepng($img);
    $signature = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    expectOff($me['data']['signature'] === ['in' => true, 'out' => true], 'phone: signature required at entry and exit');
    [$s, $b] = $call('POST', '/kiosk/phone/clock', ['client_uuid' => '7e9f0c2e-1a2b-4c3d-8e9f-0a1b2c3d4e5f', 'answers' => $yes($me['data']['questions']['in'])], $olaPhoneH, 'fr');
    expectOff($s === 422 && $b['errors'][0]['field'] === 'signature' && $b['errors'][0]['code'] === 'SIGNATURE_REQUIRED' && str_contains($b['errors'][0]['message'], 'Signez'), 'no clock in without the finger signature');
    [, $st] = $call('GET', "/kiosk/office/status?employee_id={$ola}&since=" . rawurlencode($since), null, $office);
    expectOff($st['data']['clocked'] === false, 'tablet still waiting (nothing recorded without signature)');
    [$s, $in] = $call('POST', '/kiosk/phone/clock', ['client_uuid' => '7e9f0c2e-1a2b-4c3d-8e9f-0a1b2c3d4e5f', 'answers' => $yes($me['data']['questions']['in']), 'signature' => $signature], $olaPhoneH);
    expectOff($s === 200 && $in['data']['clock']['clock_out'] === null && $in['data']['signed'], 'clock in validated and signed on the phone');
    expectOff((int) $pdo->query("SELECT id_device FROM n_prod_clock WHERE id = {$in['data']['clock']['id']}")->fetchColumn() === $officeId, 'clock recorded at the office tablet');
    [, $st] = $call('GET', "/kiosk/office/status?employee_id={$ola}&since=" . rawurlencode($since), null, $office);
    expectOff($st['data']['clocked'] === true && $st['data']['direction'] === 'in' && $st['data']['at'] === substr($in['data']['clock']['clock_in'], 11, 5) && $st['data']['clocked_in'], 'tablet confirms the clock in');
    [, $b] = $call('GET', '/kiosk/office/sections', null, $office);
    $p30 = array_values(array_filter(array_column($b['data'], null, 'id')[8]['people'], fn($p) => $p['id'] === 30))[0];
    expectOff($p30['clocked_in'] && $p30['since'] === $st['data']['at'] && $p30['phones'] === 1 && $p30['has_pin'], 'sections show the person present with a phone');

    // --- Telefon innej osoby na kodzie Oli : odrzucony ; kod Jana na telefonie Jana działa
    [, $qj] = $call('POST', '/kiosk/office/qr', ['person_id' => 20, 'base' => $base], $office);
    $janQr = $tokenOf($qj['data']);
    expectOff($qj['data']['person']['employee_id'] === 80 && $qj['data']['person']['has_pin'] === true && $qj['data']['person']['phones'] === 0, 'QR of a workshop person');
    [, $ej] = $call('POST', '/kiosk/phone/enroll', ['employee_id' => 80, 'pin' => '2468'], ['X-Qr-Token' => $janQr]);
    $janPhone = $ej['data']['phone_token'];
    [$s, $b] = $call('GET', '/kiosk/phone/me', null, ['X-Qr-Token' => $olaQr, 'X-Phone-Token' => $janPhone], 'fr');
    expectOff($s === 403 && $b['description'] === 'QR_OTHER_PERSON' && str_contains($b['message'], 'Ola Biuro') && str_contains($b['message'], 'autre personne'), 'phone of someone else refused on a personal QR');
    [$s, $b] = $call('POST', '/kiosk/phone/clock', ['direction' => 'out'], ['X-Qr-Token' => $olaQr, 'X-Phone-Token' => $janPhone]);
    expectOff($s === 403 && $b['description'] === 'QR_OTHER_PERSON', 'clocking for someone else impossible');
    expectOff((int) $pdo->query("SELECT COUNT(*) FROM n_prod_clock WHERE clock_out IS NOT NULL")->fetchColumn() === 0, 'Ola still inside');

    // --- Kod z innego dnia (zdjęcie wczorajszego kodu) : wygasły
    $yesterday = (new Jwt(str_repeat('s', 48)))->encode(['iss' => KioskAuthService::AUDIENCE, 'aud' => KioskAuthService::QR_AUDIENCE, 'dev' => $officeId, 'emp' => $ola,
        'day' => date('Y-m-d', strtotime('-1 day')), 'iat' => time(), 'nbf' => time() - 5, 'exp' => time() + 60]);
    [$s, $b] = $call('GET', '/kiosk/phone/me', null, ['X-Qr-Token' => $yesterday, 'X-Phone-Token' => $olaPhone]);
    expectOff($s === 401 && $b['description'] === 'QR_EXPIRED', 'QR of another day refused');

    // --- Wyjście : nowy kod, telefon Oli, tablet potwierdza wyjście
    [, $q3] = $call('POST', '/kiosk/office/qr', ['person_id' => 30, 'base' => $base], $office);
    expectOff($q3['data']['person']['clocked_in'] === true, 'next QR knows the person is inside (exit expected)');
    $since3 = $q3['data']['since'];
    $h3 = ['X-Qr-Token' => $tokenOf($q3['data']), 'X-Phone-Token' => $olaPhone];
    [$s, $out] = $call('POST', '/kiosk/phone/clock', ['answers' => $yes($me['data']['questions']['out']), 'signature' => $signature], $h3);
    expectOff($s === 200 && $out['data']['clock']['clock_out'] !== null && $out['data']['signed'], 'clock out validated and signed on the phone');
    expectOff((int) $pdo->query("SELECT COUNT(*) FROM n_prod_signature WHERE id_employee = {$ola} AND purpose IN ('clock_in', 'clock_out') AND ref = '{$in['data']['clock']['id']}'")->fetchColumn() === 2, 'entry and exit signatures kept with the clock');
    [, $st] = $call('GET', "/kiosk/office/status?employee_id={$ola}&since=" . rawurlencode($since3), null, $office);
    expectOff($st['data']['clocked'] && $st['data']['direction'] === 'out' && $st['data']['clocked_in'] === false, 'tablet confirms the clock out');
    [, $b] = $call('GET', '/kiosk/office/sections', null, $office);
    $p30 = array_values(array_filter(array_column($b['data'], null, 'id')[8]['people'], fn($p) => $p['id'] === 30))[0];
    expectOff(!$p30['clocked_in'] && $p30['left_at'] === $st['data']['at'], 'sections show the exit time of the day');

    // --- QR grupy tabletu pracowni : jak wcześniej (lista grupy, każdy zarejestrowany telefon)
    [, $gq] = $call('GET', '/kiosk/qr?base=' . rawurlencode($base), null, $atelier);
    $groupQr = $tokenOf($gq['data']);
    expectOff(!isset((new Jwt(str_repeat('s', 48)))->decode($groupQr, KioskAuthService::QR_AUDIENCE)['emp']) && $gq['data']['expires_in'] === 90, 'group QR has no person');
    [$s, $b] = $call('GET', '/kiosk/phone/people', null, ['X-Qr-Token' => $groupQr]);
    expectOff($s === 200 && array_column($b['data'], 'id') === [80], 'group QR: people of the group');
    [$s, $b] = $call('GET', '/kiosk/phone/me', null, ['X-Qr-Token' => $groupQr, 'X-Phone-Token' => $olaPhone]);
    expectOff($s === 200 && $b['data']['employee']['id'] === $ola, 'group QR: any registered phone (unchanged)');

    // --- Bez telefonu : PIN, pytania i podpis na tablecie biurowym ; z telefonem — kod QR (OFFICE_USE_PHONE)
    $call('DELETE', '/kiosk/employees/80/phones', null, $manager);
    [, $b] = $call('GET', '/kiosk/office/sections', null, $office);
    $jan = array_values(array_filter(array_column($b['data'], null, 'id')[1]['people'], fn($p) => $p['id'] === 20))[0];
    $olaRow = array_values(array_filter(array_column($b['data'], null, 'id')[8]['people'], fn($p) => $p['id'] === 30))[0];
    expectOff($jan['phones'] === 0 && $jan['pin_allowed'] === true && $olaRow['phones'] === 1 && $olaRow['pin_allowed'] === false, 'PIN offered to the person without a phone only');
    [$s, $b] = $call('POST', '/kiosk/identify', ['employee_id' => $ola, 'pin' => '4321'], $office, 'fr');
    expectOff($s === 409 && $b['description'] === 'OFFICE_USE_PHONE' && str_contains($b['message'], 'téléphone'), 'person with a phone: QR on the phone, not the PIN');
    [$s, $b] = $call('POST', '/kiosk/identify', ['employee_id' => 80, 'pin' => '0000'], $office);
    expectOff($s === 401 && $b['description'] === 'PIN_INVALID', 'wrong PIN refused');
    [$s, $id80] = $call('POST', '/kiosk/identify', ['employee_id' => 80, 'pin' => '2468'], $office);
    $janH = $office + ['X-Employee-Token' => $id80['data']['employee_token'] ?? ''];
    [$s2, $meJ] = $call('GET', '/kiosk/me', null, $janH);
    expectOff($s === 200 && $s2 === 200 && $meJ['data']['clocked_in'] === false && $meJ['data']['signature']['in'] === true, 'PIN on the office tablet: session of the person, entry with signature expected');
    [$s, $b] = $call('POST', '/kiosk/clock', ['direction' => 'in', 'answers' => $yes($meJ['data']['questions']['in'])], $janH);
    expectOff($s === 422 && in_array('signature:SIGNATURE_REQUIRED', $codes($b), true), 'no signature, no clock in');
    [$s, $inJ] = $call('POST', '/kiosk/clock', ['direction' => 'in', 'client_uuid' => '1d2c3b4a-5e6f-4a7b-8c9d-0e1f2a3b4c5d', 'answers' => $yes($meJ['data']['questions']['in']), 'signature' => $signature], $janH);
    $clockJ = $pdo->query("SELECT id_device FROM n_prod_clock WHERE id = " . (int) ($inJ['data']['clock']['id'] ?? 0))->fetch();
    expectOff($s === 200 && $inJ['data']['signed'] && (int) $clockJ['id_device'] === $officeId
        && (int) $pdo->query("SELECT COUNT(*) FROM n_prod_signature WHERE id_employee = 80 AND purpose = 'clock_in' AND ref = '{$inJ['data']['clock']['id']}'")->fetchColumn() === 1,
        'clock in with PIN + signature on the office tablet (signature kept, office tablet as place)');
    [$s, $b] = $call('PATCH', '/referentiels/params/kiosk.office_pin_all', ['value' => true], $manager);
    if ($s === 200) {
        [, $b] = $call('GET', '/kiosk/office/sections', null, $office);
        $olaRow = array_values(array_filter(array_column($b['data'], null, 'id')[8]['people'], fn($p) => $p['id'] === 30))[0];
        [$s2] = $call('POST', '/kiosk/identify', ['employee_id' => $ola, 'pin' => '4321'], $office);
        expectOff($olaRow['pin_allowed'] === true && $s2 === 200, 'kiosk.office_pin_all = 1: PIN + signature for everyone');
    }

    // --- Tablet grupy bierze inną grupę (27/10/2026) : adres /tablet/production?team=N → nagłówek X-Kiosk-Group
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (21, 'Kinga', 'Tarta', 0, 1)");
    $pdo->exec("UPDATE n_prod_group SET active = 1 WHERE id = 2");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, active) VALUES (99, 'OLD_T', 'Stara', 'Ancienne', 0)");
    $pdo->exec("INSERT INTO n_prod_employee (id_production_employee, id_primary_group, active) VALUES (21, 2, 1)");
    $kinga = (int) $pdo->lastInsertId();
    $pdo->exec("SET SESSION sql_mode = @mode");
    $call('PUT', "/kiosk/employees/{$kinga}/credentials", ['pin' => '1357'], $manager);
    [, $b] = $call('GET', '/kiosk/device', null, $atelier);
    expectOff($b['data']['group_id'] === 1 && $b['data']['home_group_id'] === 1 && $b['data']['generic'] === false, 'group tablet: its own group by default');
    [$s, $b] = $call('GET', '/kiosk/groups', null, $atelier);
    $gr = array_column($b['data'], null, 'id');
    expectOff($s === 200 && isset($gr[1], $gr[2]) && !isset($gr[99]) && $gr[1]['own'] === true && $gr[2]['own'] === false, 'group tablet lists every active group, its own marked');
    $tarty = $atelier + ['X-Kiosk-Group' => '2'];
    [, $b] = $call('GET', '/kiosk/device', null, $tarty);
    expectOff($b['data']['group_id'] === 2 && $b['data']['home_group_id'] === 1, 'team in the address: the group tablet takes the other group');
    [, $b] = $call('GET', '/kiosk/people', null, $tarty);
    expectOff(array_column($b['data'], 'id') === [$kinga], 'people of the chosen group');
    [, $b] = $call('GET', '/kiosk/people', null, $atelier);
    expectOff(in_array(80, array_column($b['data'], 'id'), true) && !in_array($kinga, array_column($b['data'], 'id'), true), 'without a team: people of its own group');
    [, $b] = $call('GET', '/kiosk/device', null, $atelier + ['X-Kiosk-Group' => '99']);
    expectOff($b['data']['group_id'] === 1, 'inactive group ignored: own group');
    [, $b] = $call('GET', '/kiosk/device', null, $driverDev + ['X-Kiosk-Group' => '2']);
    expectOff($b['data']['group_id'] === null, 'driver tablet ignores the team');
    [$s, $idK] = $call('POST', '/kiosk/identify', ['employee_id' => $kinga, 'pin' => '1357'], $tarty);
    expectOff($s === 200 && $idK['data']['group_id'] === 2, 'PIN on the chosen group: session in that group');
    [$s, $rf] = $call('POST', '/kiosk/refresh', null, $atelier + ['X-Employee-Token' => $idK['data']['employee_token']]);
    expectOff($s === 200 && $rf['data']['group_id'] === 2, 'the session keeps the chosen group (token), even without the header');
    [, $tq] = $call('GET', '/kiosk/qr?base=' . rawurlencode($base), null, $tarty);
    [$s, $b] = $call('GET', '/kiosk/phone/people', null, ['X-Qr-Token' => $tokenOf($tq['data'])]);
    expectOff($s === 200 && array_column($b['data'], 'id') === [$kinga], 'phone QR shown for the chosen group: its people');

    // --- Zmiana trybu : tablet pracowni → biuro (grupa usunięta)
    [$s, $b] = $call('PATCH', "/kiosk/devices/{$atelierId}", ['mode' => 'office'], $manager);
    $row = $pdo->query("SELECT mode, id_group FROM n_prod_device WHERE id = {$atelierId}")->fetch();
    expectOff($s === 200 && $row['mode'] === 'office' && $row['id_group'] === null, 'device switched to office mode, group removed');

    echo "office_tablet_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
