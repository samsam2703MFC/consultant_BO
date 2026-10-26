<?php
declare(strict_types=1);
/*
 * Tablet kierowcy : tablet w trybie « driver » z pojazdem, parowanie, trasa dnia (klient, adres, telefon, palety),
 * start trasy, dostawa (podpis obowiązkowy, zdjęcie), zapis ponowiony bez duplikatu, problem → dzwonek ERP,
 * tablet pracowni odrzucony, ERP : dostawy dnia, PDF potwierdzenia z podpisem, dokumenty dnia.
 *
 *   php tests/driver_tablet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_driver_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Services\Logistics\DriverService;
use App\Services\Workshop\Migration\MigrationRunner;
use Symfony\Component\HttpFoundation\Request;

function expectDrv(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_driver_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "driver_tablet_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency',
                 'customer', 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings',
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
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_manager')");

    // Dzień wysyłki : jeden klient z zamówieniem, przypisany do pojazdu ; drugi klient bez dostawy.
    $today = date('Y-m-d');
    $pdo->exec("SET @mode := @@SESSION.sql_mode");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO carrier (id, name, active) VALUES (1, 'Trans Test', 1)");
    $pdo->exec("INSERT INTO carrier_vehicle (id, id_carrier, regis_num, driver_name, driver_surname, driver_phone) VALUES (5, 1, 'DW 12345', 'Jan', 'Kowal', '+48 600 000 000')");
    $pdo->exec("INSERT INTO n_vehicle (id_carrier_vehicle, pallet_places, active) VALUES (5, 33, 1)");
    $pdo->exec("INSERT INTO customer (id, company, fac_street, fac_street_num, fac_zip, fac_town, fac_country, phone_head, active) VALUES
        (3, 'Boulangerie Test', 'Rue Haute', '12', '1000', 'Bruxelles', 'BE', '+32 2 000 00 00', 1), (4, 'Bez dostawy', '', '', '', 'Gand', 'BE', '', 1)");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, ppb, bpc, cpl, lpp, cpp, id_section, active) VALUES (500, 'Croissant 70 g', 1, 70, 60, 1, 60, 8, 6, 48, 1, 1)");
    $pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date) VALUES (10, 3, 1, '{$today}', NOW())");
    $pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (10, 500, 50)");
    $pdo->exec("INSERT INTO n_shipping_assignment (ship_date, id_customer, id_carrier_vehicle, vehicle_set, tour_set) VALUES ('{$today}', 3, 5, 1, 0)");
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

    // --- Tablet kierowcy : tryb, pojazd, parowanie
    [$s, $b] = $call('GET', '/kiosk/devices/vehicles', null, $manager);
    expectDrv($s === 200 && $b['data'][0]['label'] === 'DW 12345' && $b['data'][0]['driver'] === 'Jan Kowal', 'vehicles to choose for a driver tablet');
    [$s, $b] = $call('POST', '/kiosk/devices', ['name' => 'Tablet DW 12345', 'mode' => 'driver', 'vehicle_id' => 5, 'group_id' => 1], $manager);
    expectDrv($s === 201, 'driver tablet created');
    [, $pair] = $call('POST', '/kiosk/pair', ['code' => $b['data']['pairing_code']]);
    $driver = ['X-Device-Token' => $pair['data']['device_token']];
    expectDrv($pair['data']['device']['mode'] === 'driver' && $pair['data']['device']['group_id'] === null, 'paired in driver mode, no group');
    [, $b] = $call('GET', '/kiosk/device', null, $driver);
    expectDrv($b['data']['mode'] === 'driver' && $b['data']['vehicle_id'] === 5, 'device knows its mode and vehicle');
    [, $b] = $call('GET', '/kiosk/devices', null, $manager);
    expectDrv($b['data'][0]['mode'] === 'driver' && $b['data'][0]['vehicle_label'] === 'DW 12345', 'device list shows the vehicle');
    [$s, $b] = $call('POST', '/kiosk/devices', ['name' => 'X', 'mode' => 'driver', 'vehicle_id' => 999], $manager);
    expectDrv($s === 422 && $codes($b) === ['vehicle_id:REFERENCE_NOT_FOUND'], 'unknown vehicle refused');

    [, $b] = $call('POST', '/kiosk/devices', ['name' => 'Tablet Piekarnia', 'group_id' => 1], $manager);
    $atelier = ['X-Device-Token' => $call('POST', '/kiosk/pair', ['code' => $b['data']['pairing_code']])[1]['data']['device_token']];
    [$s, $b] = $call('GET', '/driver/day', null, $atelier, 'fr');
    expectDrv($s === 403 && $b['description'] === 'DEVICE_NOT_DRIVER' && str_contains($b['message'], 'tablette chauffeur'), 'workshop tablet cannot open the driver day');

    // --- Trasa dnia
    [$s, $day] = $call('GET', '/driver/day', null, $driver);
    $day = $day['data'];
    expectDrv($s === 200 && $day['date'] === $today && $day['vehicle']['label'] === 'DW 12345' && count($day['stops']) === 1, 'day of the device vehicle');
    $stop = $day['stops'][0];
    expectDrv($stop['name'] === 'Boulangerie Test' && $stop['address'] === 'Rue Haute 12, 1000 Bruxelles, BE' && $stop['phone'] === '+32 2 000 00 00'
        && $stop['pallets'] === 2 && $stop['status'] === null, 'stop: address, phone, pallets (50 cartons / 48 per pallet)');

    [$s, $b] = $call('POST', '/driver/run', ['date' => $today, 'vehicle_id' => 5, 'action' => 'start', 'driver_name' => 'Jan Kowal'], $driver);
    expectDrv($s === 200 && $b['data']['started_at'] !== null && $b['data']['finished_at'] === null && $b['data']['driver_name'] === 'Jan Kowal', 'run started');

    // --- Dostawa : podpis obowiązkowy, potem zapis z podpisem i zdjęciem
    $png = function (int $w, int $h): string { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 10, 10, $w - 10, $h - 10, imagecolorallocate($im, 0, 0, 0)); ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode((string) ob_get_clean()); };
    $jpeg = function (): string { $im = imagecreatetruecolor(320, 240); imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 60)); ob_start(); imagejpeg($im, null, 70); return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean()); };
    [$s, $b] = $call('POST', '/driver/stops', ['date' => $today, 'customer_id' => 3, 'status' => 'delivered', 'receiver_name' => 'Marie'], $driver);
    expectDrv($s === 422 && $codes($b) === ['signature:REQUIRED'], 'signature required for a delivery');
    $delivery = ['date' => $today, 'customer_id' => 3, 'vehicle_id' => 5, 'status' => 'delivered', 'receiver_name' => 'Marie Dupont', 'pallets_delivered' => 2,
        'pallets_returned' => 1, 'signature' => $png(600, 200), 'photo' => $jpeg(), 'lat' => 50.8466, 'lng' => 4.3528, 'recorded_at' => date('Y-m-d H:i:s'), 'driver_name' => 'Jan Kowal',
        'client_uuid' => 'u-1'];
    [$s, $b] = $call('POST', '/driver/stops', $delivery, $driver);
    expectDrv($s === 201 && $b['data']['status'] === 'delivered', 'delivery recorded');
    [$s] = $call('POST', '/driver/stops', $delivery, $driver);
    expectDrv($s === 201 && (int) $pdo->query('SELECT COUNT(*) FROM n_delivery_stop')->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM n_delivery_media')->fetchColumn() === 2, 'replayed record (offline queue) updates the same stop');
    [, $day] = $call('GET', '/driver/day', null, $driver);
    $stop = $day['data']['stops'][0];
    expectDrv($stop['status'] === 'delivered' && $stop['receiver_name'] === 'Marie Dupont' && $stop['has_signature'] && $stop['has_photo']
        && $day['data']['counts']['delivered'] === 1, 'day shows the delivery');
    [$s, $b] = $call('POST', '/driver/stops', ['date' => $today, 'customer_id' => 4, 'status' => 'problem', 'reason' => 'closed'], $driver);
    expectDrv($s === 422 && $codes($b) === ['customer_id:DRIVER_CUSTOMER_NOT_PLANNED'], 'customer without delivery that day refused');
    [$s, $b] = $call('POST', '/driver/stops', ['date' => date('Y-m-d', strtotime('-30 days')), 'customer_id' => 3, 'status' => 'problem', 'reason' => 'closed'], $driver);
    expectDrv($s === 422 && $codes($b) === ['date:DRIVER_DATE_OUT_OF_RANGE'], 'old shipping day refused');
    [$s, $b] = $call('POST', '/driver/stops', ['date' => $today, 'customer_id' => 3, 'status' => 'delivered', 'receiver_name' => 'X', 'signature' => 'data:image/png;base64,AAAA'], $driver);
    expectDrv($s === 422 && $codes($b) === ['signature:INVALID_IMAGE'], 'broken image refused');

    // --- ERP : dostawy dnia, PDF potwierdzenia, status u klienta
    $service = $container->get(DriverService::class);
    $erp = $service->erpDay($today);
    $row = $erp['trucks'][0]['rows'][0];
    expectDrv($erp['trucks'][0]['label'] === 'DW 12345' && $erp['trucks'][0]['driver'] === 'Jan Kowal' && $row['stop']['status'] === 'delivered'
        && $row['stop']['pallets_returned'] === 1 && $row['stop']['signature_id'] !== null && count($row['stop']['photo_ids']) === 1 && $erp['totals']['delivered'] === 1, 'ERP delivery list');
    $pdf = $service->proofPdf($row['stop']['id'], 'fr');
    expectDrv(str_starts_with($pdf['content'], '%PDF') && str_starts_with($pdf['filename'], 'POTWIERDZENIE_DOSTAWY_Boulangerie_Test_') && strlen($pdf['content']) > 20000, 'proof of delivery PDF with signature and photo');
    expectDrv($service->statuses($today)[3]['status'] === 'delivered', 'status for the day loading page');
    expectDrv($service->media($row['stop']['signature_id'])['mime'] === 'image/png', 'signature image served');

    // --- Problem : powiadomienie w dzwonku, status zastąpiony ; koniec trasy ; dokumenty dnia
    [$s] = $call('POST', '/driver/stops', ['date' => $today, 'customer_id' => 3, 'status' => 'problem', 'reason' => 'damaged', 'note' => '2 kartony zgniecione'], $driver);
    $note = $pdo->query("SELECT * FROM app_notification WHERE type = 'delivery_problem'")->fetch();
    expectDrv($s === 201 && $note && str_contains($note['body'], 'Boulangerie Test : Uszkodzenie — 2 kartony zgniecione') && str_contains($note['link_url'], '/logistics/deliveries?date=' . $today), 'problem → notification in the ERP bell');
    expectDrv($service->statuses($today)[3]['reason_label'] === 'Uszkodzenie', 'stop now a problem');
    [$s, $b] = $call('POST', '/driver/run', ['date' => $today, 'vehicle_id' => 5, 'action' => 'finish'], $driver);
    expectDrv($s === 200 && $b['data']['finished_at'] !== null && $b['data']['driver_name'] === 'Jan Kowal', 'run finished, driver kept');
    [$s, $b] = $call('GET', '/driver/documents?date=' . $today, null, $driver);
    expectDrv($s === 200 && $b['data']['filename'] === 'DOKUMENTY_DLA_KIEROWCOW_' . $today . '.pdf' && str_starts_with(base64_decode($b['data']['content_base64']), '%PDF'), 'documents of the day for the driver');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "driver_tablet_test: OK\n";
