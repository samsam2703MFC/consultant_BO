<?php
declare(strict_types=1);
/*
 * [275] Wejście do ERP przez QR (makieta 40) sur une base jetable (toutes les migrations) :
 *   - migration 174 : liaison compte ↔ personne, option des postes (Administracja, Kierownik produkcji activés), appareil
 *     interne de l'ERP (mode office, caché de la liste des tablettes), durée du QR ;
 *   - verrou : poste avec option + compte lié + entrée non pointée → ERP fermé ; jamais l'administrateur ; pas de liaison
 *     ou poste sans option → pas de verrou ;
 *   - matin : QR de l'ERP → premier scan (PIN) → entrée validée au téléphone (questions, signature) → ERP ouvert, pointage
 *     enregistré « à l'ERP » ; soir : même QR → sortie (marchandise « Nie ») → verrou de nouveau ;
 *   - téléphone d'une autre personne refusé (QR_OTHER_PERSON) ; état du jour (minutes, heures sup.) ; réglage des postes ;
 *     une personne liée à un seul compte ; adresse de retour sûre.
 *
 *   php tests/erp_entry_test.php
 *
 * Base : TFB_TEST_DSN / TFB_TEST_USER / TFB_TEST_PASS, structures de TFB_TEST_SOURCE_DB (erp). erp_entry_test est recréée
 * puis supprimée. Aucun e-mail ni SMS.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Controllers\Web\Organization\ErpEntryController;
use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Organization\ErpEntryService;
use App\Services\Workshop\Migration\MigrationRunner;
use Symfony\Component\HttpFoundation\Request;

function expectE(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_entry_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "erp_entry_test: SKIPPED (no database: {$e->getMessage()})\n";
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
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', '', 0, 'ada', '" . password_hash('Secret-123', PASSWORD_BCRYPT) . "'),
        (2, 'Ewa', 'Finanse', 'Ewa F.', 'ewa@example.invalid', '', 0, 'ewa', 'x'),
        (3, 'Leon', 'Logistyka', 'Leon L.', '', '', 0, 'leon', 'x'),
        (4, 'Iza', 'Finanse', 'Iza F.', '', '', 0, 'iza', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (8, 'Administracja', 0, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (30, 'Ewa', 'Biuro', 0, 8), (31, 'Jan', 'Kowal', 0, 8)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/174_erp_qr_entry.sql')) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $dev = (int) $pdo->query("SELECT value FROM n_prod_param WHERE code = 'erp.qr_device_id'")->fetchColumn();
    expectE($count("SELECT COUNT(*) FROM n_position WHERE erp_qr = 1") === 2 && $count("SELECT COUNT(*) FROM n_position WHERE erp_qr = 1 AND code IN ('ADMINISTRACJA', 'KIEROWNIK_PRODUKCJI')") === 2
        && $dev > 0 && $count("SELECT COUNT(*) FROM n_prod_device WHERE mode = 'office' AND token_hash IS NOT NULL AND id = {$dev}") === 1
        && $count("SELECT COUNT(*) FROM n_prod_device WHERE name = 'ERP — wejście przez QR'") === 1, 'migration 174 (rejouée) : 2 postes, un seul appareil interne, paramètres');

    $pos = fn(string $code) => (int) $pdo->query("SELECT id FROM n_position WHERE code = '{$code}'")->fetchColumn();
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL), (1, 'prod_manager', NULL)");
    $pdo->exec('INSERT INTO n_user_position (id_user, id_position) VALUES (1, ' . $pos('ADMINISTRACJA') . '), (2, ' . $pos('ADMINISTRACJA') . '), (3, ' . $pos('LOGISTYKA') . '), (4, ' . $pos('ADMINISTRACJA') . ')');
    $ewa = (int) $pdo->query('SELECT id FROM n_prod_employee WHERE id_production_employee = 30')->fetchColumn();
    $jan = (int) $pdo->query('SELECT id FROM n_prod_employee WHERE id_production_employee = 31')->fetchColumn();
    expectE($ewa > 0 && $jan > 0, 'profils de production des deux personnes');

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('e', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
        }]);
    $c = $builder->build();
    $kernel = $c->get(WorkshopApiKernel::class);
    $call = function (string $method, string $path, ?array $body, array $headers = []) use ($kernel): array {
        $server = ['HTTP_ACCEPT_LANGUAGE' => 'pl', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)'];
        foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        $response = $kernel->handle(Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body)), parse_url('/api/v1/production' . $path, PHP_URL_PATH));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    };
    $manager = ['Authorization' => 'Bearer ' . $call('POST', '/auth/login', ['login' => 'ada', 'password' => 'Secret-123'])[1]['data']['access_token']];
    $call('PUT', "/kiosk/employees/{$ewa}/credentials", ['pin' => '4321'], $manager);
    $call('PUT', "/kiosk/employees/{$jan}/credentials", ['pin' => '2468'], $manager);
    $s = $c->get(ErpEntryService::class);
    $tokenOf = fn(array $q) => substr($q['url'], strpos($q['url'], '#t=') + 3);
    $base = 'https://erp.example/production';

    // 1. Qui est concerné
    expectE(!$s->locked(2) && $s->state(2) === ['enabled' => false], 'Ewa sans liaison : pas de verrou');
    $s->link(2, $ewa, 1);
    expectE($s->locked(2) && !$s->locked(1) && !$s->locked(3) && !$s->locked(4), 'Ewa liée : ERP fermé ; administrateur, Logistyka (sans option) et compte non lié : ouverts');
    $s->link(1, $jan, 1);
    expectE(!$s->locked(1), 'administrateur lié : jamais de code');
    $s->link(1, null, 1);

    // 2. Matin : QR de l'ERP → premier scan (PIN) → entrée au téléphone
    $q = $s->qr(2, $base);
    expectE($q['direction'] === 'in' && str_starts_with($q['url'], $base . '/kiosk/scan#t=') && $q['expires_in'] === 180 && $q['size'] > 20 && count($q['rows']) === $q['size'],
        'QR du matin : adresse de scan, 3 min, matrice');
    $qr = $tokenOf($q);
    [$st, $b] = $call('GET', '/kiosk/phone/people', null, ['X-Qr-Token' => $qr]);
    expectE($st === 200 && array_column($b['data'], 'id') === [$ewa], 'premier scan : seulement la personne du QR');
    [$st, $b] = $call('POST', '/kiosk/phone/enroll', ['employee_id' => $jan, 'pin' => '2468'], ['X-Qr-Token' => $qr]);
    expectE($st === 403 && $b['description'] === 'QR_OTHER_PERSON', 'téléphone d\'une autre personne : refusé');
    [$st, $en] = $call('POST', '/kiosk/phone/enroll', ['employee_id' => $ewa, 'pin' => '4321'], ['X-Qr-Token' => $qr]);
    $phone = ['X-Qr-Token' => $qr, 'X-Phone-Token' => $en['data']['phone_token'] ?? ''];
    expectE($st === 201, 'téléphone appairé avec le PIN');
    expectE($s->status(2, $q['since'])['clocked'] === false, 'ERP : rien encore');
    [, $me] = $call('GET', '/kiosk/phone/me', null, $phone);
    $yes = fn(array $qs) => array_map(fn($x) => ['question_id' => $x['id'], 'yes' => ($x['kind'] ?? 'confirm') !== 'yes_no'], $qs);
    $img = imagecreatetruecolor(300, 120);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imageline($img, 10, 100, 290, 20, imagecolorallocate($img, 0, 0, 0));
    ob_start();
    imagepng($img);
    $signature = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    [$st, $in] = $call('POST', '/kiosk/phone/clock', ['client_uuid' => '5b1d2c3e-4f50-4a6b-8c7d-9e0f1a2b3c4d', 'answers' => $yes($me['data']['questions']['in']), 'signature' => $signature], $phone);
    expectE($st === 200 && $in['data']['clock']['clock_out'] === null, 'entrée validée au téléphone');
    expectE($count("SELECT id_device FROM n_prod_clock WHERE id = {$in['data']['clock']['id']}") === $dev, 'pointage enregistré « à l\'ERP » (appareil interne)');
    $stt = $s->status(2, $q['since']);
    expectE($stt['clocked'] && $stt['direction'] === 'in' && $stt['clocked_in'] && !$s->locked(2), 'ERP : entrée vue → ERP ouvert');
    $state = $s->state(2);
    expectE($state['enabled'] && $state['clocked_in'] && $state['since'] === substr($in['data']['clock']['clock_in'], 11, 5) && $state['first_name'] === 'Ewa' && $state['plan_minutes'] >= 60,
        'barre du haut : « W pracy od … »');
    $pdo->exec("UPDATE n_prod_clock SET clock_in = NOW() - INTERVAL 500 MINUTE WHERE id = {$in['data']['clock']['id']}");
    $state = $s->state(2);
    expectE($state['minutes'] >= 499 && $state['overtime_minutes'] === max(0, $state['minutes'] - $state['plan_minutes']), 'Zakończ dzień : minutes du jour et heures sup.');

    // 3. Soir : même QR → sortie (marchandise « Nie ») → verrou
    $q2 = $s->qr(2, $base);
    expectE($q2['direction'] === 'out', 'QR du soir : sortie');
    $phone2 = ['X-Qr-Token' => $tokenOf($q2), 'X-Phone-Token' => $en['data']['phone_token']];
    [, $me] = $call('GET', '/kiosk/phone/me', null, $phone2);
    [$st, $out] = $call('POST', '/kiosk/phone/clock', ['answers' => $yes($me['data']['questions']['out']), 'signature' => $signature], $phone2);
    expectE($st === 200 && $out['data']['clock']['clock_out'] !== null, 'sortie validée au téléphone');
    $stt = $s->status(2, $q2['since']);
    expectE($stt['direction'] === 'out' && !$stt['clocked_in'] && $s->locked(2), 'ERP : sortie vue → déconnexion, ERP de nouveau fermé');

    // 4. Appareil interne caché ; réglages ; liaison unique ; adresse de retour
    [, $devices] = $call('GET', '/kiosk/devices', null, $manager);
    expectE(!in_array($dev, array_column($devices['data'], 'id'), true), 'appareil interne caché de la liste des tablettes');
    $m = array_column($s->matrix(), null, 'code');
    $ewaRow = array_values(array_filter($m['ADMINISTRACJA']['users'], fn($u) => $u['id'] === 2))[0];
    expectE($m['ADMINISTRACJA']['enabled'] && !$m['LOGISTYKA']['enabled'] && $ewaRow['linked'] && $ewaRow['phones'] === 1, 'Uprawnienia i menu : postes, comptes liés, téléphone');
    $s->setPosition($pos('ADMINISTRACJA'), false);
    expectE(!$s->locked(2), 'option retirée au poste : ERP ouvert');
    $s->setPosition($pos('ADMINISTRACJA'), true);
    $code = 'ok';
    try { $s->setPosition(99999, true); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectE($code === '404', 'poste inconnu → 404');
    $s->link(4, $ewa, 1);
    expectE($s->linkData(2)['employee_id'] === null && $s->linkData(4)['employee_id'] === $ewa && !$s->locked(2), 'une personne ↔ un seul compte');
    $code = 'ok';
    try { $s->link(4, 777777, 1); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectE($code === '422', 'liaison : personne active seulement');
    expectE(ErpEntryController::safeNext('/finances?tab=2') === '/finances?tab=2' && ErpEntryController::safeNext('//evil.example/x') === ''
        && ErpEntryController::safeNext('https://evil.example') === '' && ErpEntryController::safeNext('/erp-entry') === '' && ErpEntryController::safeNext('/ajax/x') === '',
        'retour après l\'entrée : seulement une page interne');

    echo "erp_entry_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
