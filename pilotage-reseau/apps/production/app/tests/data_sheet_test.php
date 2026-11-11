<?php
declare(strict_types=1);
/*
 * Tabela do wypełnienia / tableau à remplir : export Excel pré-rempli, import avec aperçu (rien n'est enregistré),
 * application tout ou rien, rejeu sans changement, erreurs par ligne (PL / FR), droits.
 *
 *   php tests/data_sheet_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_datasheet_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Services\Workshop\Migration\MigrationRunner;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;

function expectSheet(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_datasheet_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "data_sheet_test: SKIPPED (no database: {$e->getMessage()})\n";
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
                 'test_product_stage', 'test_product_changelog'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'manager', '" . password_hash('Secret-123', PASSWORD_BCRYPT) . "'),
        (4, 'Dan', 'N', 'Dan N.', 'd@x.test', '4', 0, 'direction', '" . password_hash('pass-direction', PASSWORD_BCRYPT) . "')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_manager'), (4, 'direction')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        PDO::class => $pdo,
        WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
        },
    ]);
    $kernel = $builder->build()->get(WorkshopApiKernel::class);
    $call = function (string $method, string $path, ?array $body, ?string $token, string $lang = 'pl') use ($kernel): array {
        $server = ['HTTP_ACCEPT_LANGUAGE' => $lang, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.1'];
        if ($token !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $request = Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
        $response = $kernel->handle($request, '/api/v1/production' . $path);
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    };
    $token = fn(string $login, string $password) => $call('POST', '/auth/login', ['login' => $login, 'password' => $password], null)[1]['data']['access_token'];
    $manager = $token('manager', 'Secret-123');
    $direction = $token('direction', 'pass-direction');

    $load = function (string $base64): Spreadsheet {
        $file = tempnam(sys_get_temp_dir(), 'ds');
        file_put_contents($file, base64_decode($base64));
        $book = IOFactory::load($file);
        unlink($file);
        return $book;
    };
    $save = function (Spreadsheet $book): string {
        $file = tempnam(sys_get_temp_dir(), 'ds');
        (new Xlsx($book))->save($file);
        $content = base64_encode((string) file_get_contents($file));
        unlink($file);
        return $content;
    };
    $append = function (Spreadsheet $book, string $title, array $row): void {
        $sheet = $book->getSheetByName($title);
        $sheet->fromArray([$row], null, 'A' . ($sheet->getHighestDataRow() + 1), true);
    };
    $byKey = function (array $result): array {
        $out = [];
        foreach ($result['rows'] as $r) $out[$r['sheet'] . ':' . $r['key']] = $r;
        return $out;
    };

    // --- Export : feuilles, données actuelles, lecture seule suffisante
    [$s, $b] = $call('GET', '/referentiels/data-sheet', null, $direction);
    expectSheet($s === 200 && str_ends_with($b['data']['filename'], '.xlsx'), 'export allowed with read permission');
    $book = $load($b['data']['content_base64']);
    $titles = array_map(fn($w) => $w->getTitle(), $book->getAllSheets());
    expectSheet($titles === ['Instrukcja (mode d’emploi)', 'Stanowiska (postes)', 'Zmiany (horaires)', 'Zespoły (équipes)', 'Trasy (tournées)', 'Parametry (paramètres)', 'Grupy (groupes)'], 'sheets of the workbook');
    $shiftRows = $book->getSheetByName('Zmiany (horaires)')->toArray(null, false, false, false);
    expectSheet(count(array_filter(array_slice($shiftRows, 1), fn($r) => $r[0] !== null)) === (int) $pdo->query('SELECT COUNT(*) FROM n_prod_shift_template WHERE is_night = 0')->fetchColumn()
        && in_array('JOUR', array_column($shiftRows, 0), true) && !in_array('NUIT', array_column($shiftRows, 0), true), 'shift sheet pre-filled with the ERP day shifts (no night shift) [235]');
    $params = array_column($book->getSheetByName('Parametry (paramètres)')->toArray(null, false, false, false), 3, 0);
    expectSheet(array_key_exists('cold.freezer_capacity_cartons', $params) && (int) $params['cold.freezer_capacity_cartons'] === 0 && !array_key_exists('shift.night_staff_ratio', $params),
        'freezer capacity in the parameter sheet, no night staffing ratio');
    expectSheet(count($book->getSheetByName('Stanowiska (postes)')->getDataValidationCollection()) >= 4, 'drop-down lists on the workstation sheet');

    // --- Fichier rempli : nouveaux poste, cobot, shift, équipe sur ce shift, tournée (heure Excel), surgélateur
    $group = (string) $pdo->query("SELECT code FROM n_prod_group WHERE active = 1 ORDER BY sort_order, id LIMIT 1")->fetchColumn();
    $append($book, 'Stanowiska (postes)', ['four_test', $group, 'Piec testowy', 'Four test', 'four', '', '', 12, 'plaque', '55,5', 'tak', 'piec 12 blach']);
    $append($book, 'Stanowiska (postes)', ['COBOT_TEST', $group, 'Cobot', 'Cobot', 'robot', 'collaboratif', '0,25', 6, 'piece', 30, 'oui', '']);
    $append($book, 'Zmiany (horaires)', ['SOBOTA', 'Sobota', 'Samedi', '7:00', '13h00', '6', 0, 'nie', 9, 'tak']);
    $append($book, 'Zespoły (équipes)', ['SOBOTA_TEAM', $group, 'SOBOTA', 'Zespół sobotni', 3, 'tak']);
    $append($book, 'Trasy (tournées)', ['', 'Bruxelles', 20 / 24, '1-5', 'tak', 'camion 33 palettes']);
    $paramSheet = $book->getSheetByName('Parametry (paramètres)');
    foreach ($paramSheet->toArray(null, false, false, true) as $n => $row) {
        if ($row['A'] === 'cold.freezer_capacity_cartons') $paramSheet->setCellValue('D' . $n, 1200);
    }
    $filled = $save($book);

    // Aperçu : chaque ligne contrôlée, rien n'est enregistré
    [$s, $b] = $call('POST', '/referentiels/data-sheet/preview', ['file' => $filled], $manager);
    $rows = $byKey($b['data']);
    expectSheet($s === 200 && $b['data']['error_count'] === 0 && $b['data']['applied'] === false, 'preview without errors, nothing applied: ' . json_encode(array_filter($b['data']['rows'] ?? [], fn($r) => $r['errors'])));
    expectSheet($rows['workstations:FOUR_TEST']['action'] === 'create' && $rows['workstations:COBOT_TEST']['action'] === 'create'
        && $rows['shifts:SOBOTA']['action'] === 'create' && $rows['teams:SOBOTA_TEAM']['action'] === 'create'
        && $rows['tours:Bruxelles']['action'] === 'create' && $rows['params:cold.freezer_capacity_cartons']['action'] === 'update', 'preview actions');
    expectSheet($b['data']['change_count'] === 6 && $rows['shifts:JOUR']['action'] === 'unchanged', 'only the changed rows count');
    $change = $rows['params:cold.freezer_capacity_cartons']['changes'][0];
    expectSheet($change['before'] === '0' && $change['after'] === '1200', 'change shows before → after');
    expectSheet((int) $pdo->query("SELECT COUNT(*) FROM n_tour")->fetchColumn() === 0
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'cold.freezer_capacity_cartons'")->fetchColumn() === '0'
        && !$pdo->query("SELECT 1 FROM n_prod_workstation WHERE code = 'FOUR_TEST'")->fetchColumn(), 'preview writes nothing');

    // Droits : la direction lit mais n'importe pas
    [$s] = $call('POST', '/referentiels/data-sheet/preview', ['file' => $filled], $direction);
    expectSheet($s === 403, 'import needs prod.referentiels.write');

    // Application
    [$s, $b] = $call('POST', '/referentiels/data-sheet/apply', ['file' => $filled], $manager);
    expectSheet($s === 200 && $b['data']['applied'] === true && $b['data']['change_count'] === 6, 'apply');
    $four = $pdo->query("SELECT * FROM n_prod_workstation WHERE code = 'FOUR_TEST'")->fetch();
    expectSheet($four && $four['type'] === 'four' && (float) $four['capacity_per_pass'] === 12.0 && (float) $four['hourly_cost'] === 55.5 && $four['notes'] === 'piec 12 blach', 'workstation created (code upper-cased, decimal comma)');
    $cobot = $pdo->query("SELECT robot_mode, operator_share FROM n_prod_workstation WHERE code = 'COBOT_TEST'")->fetch();
    expectSheet($cobot['robot_mode'] === 'collaboratif' && (float) $cobot['operator_share'] === 0.25, 'cobot with operator share');
    $shift = $pdo->query("SELECT id, start_time, end_time, active_days FROM n_prod_shift_template WHERE code = 'SOBOTA'")->fetch();
    expectSheet($shift['start_time'] === '07:00:00' && $shift['end_time'] === '13:00:00' && $shift['active_days'] === '6', 'shift times « 7:00 » and « 13h00 »');
    expectSheet((int) $pdo->query("SELECT id_shift_template FROM n_prod_team WHERE code = 'SOBOTA_TEAM'")->fetchColumn() === (int) $shift['id'], 'team on the shift added in the same file');
    $tour = $pdo->query("SELECT name, departure_time, weekdays, notes, updated_by FROM n_tour")->fetch();
    expectSheet($tour['name'] === 'Bruxelles' && $tour['departure_time'] === '20:00:00' && (int) $tour['weekdays'] === 31 && (int) $tour['updated_by'] === 1, 'tour: Excel time 20:00, days 1-5');
    expectSheet($pdo->query("SELECT value FROM n_prod_param WHERE code = 'cold.freezer_capacity_cartons'")->fetchColumn() === '1200', 'freezer capacity saved');
    expectSheet((int) $pdo->query("SELECT COUNT(*) FROM n_prod_audit_log WHERE entity IN ('workstation', 'shift_template', 'team', 'tour', 'param') AND id_user = 1")->fetchColumn() >= 6, 'every change in the audit log');

    // Rejeu du même fichier : rien à changer
    [$s, $b] = $call('POST', '/referentiels/data-sheet/preview', ['file' => $filled], $manager);
    expectSheet($s === 200 && $b['data']['change_count'] === 0 && $b['data']['error_count'] === 0, 'same file again: no change (tour matched by name)');

    // Fichier avec erreurs : rien n'est enregistré, même les lignes justes ; messages par ligne
    [, $b] = $call('GET', '/referentiels/data-sheet', null, $manager);
    $book = $load($b['data']['content_base64']);
    $append($book, 'Stanowiska (postes)', ['FOUR_TEST', $group, 'Doublon', 'Doublon', 'four', '', '', 5, 'plaque', 1, 'tak', '']);
    $append($book, 'Stanowiska (postes)', ['NEW_ONE', 'NIEMA', 'Nowy', 'Nouveau', 'machine', '', '', 1, 'piece', 1, 'tak', '']);
    $append($book, 'Zmiany (horaires)', ['NOC2', 'Noc', 'Nuit', '25:00', '06:00', '1-5', 20, 'tak', 10, 'tak']);
    $paramSheet = $book->getSheetByName('Parametry (paramètres)');
    foreach ($paramSheet->toArray(null, false, false, true) as $n => $row) {
        if ($row['A'] === 'cold.freezer_capacity_cartons') $paramSheet->setCellValue('D' . $n, 1500);
    }
    $broken = $save($book);
    [$s, $b] = $call('POST', '/referentiels/data-sheet/apply', ['file' => $broken], $manager, 'fr');
    $rows = $byKey($b['data']);
    expectSheet($s === 200 && $b['data']['applied'] === false && $b['data']['error_count'] === 3, 'file with errors is refused');
    expectSheet(str_contains(implode(' ', $rows['workstations:FOUR_TEST']['errors']), 'figure déjà à la ligne'), 'duplicate row named (French)');
    expectSheet($rows['workstations:NEW_ONE']['errors'] === ['Code de groupe inconnu « NIEMA » (codes dans la feuille Grupy).'], 'unknown group code');
    expectSheet($rows['shifts:NOC2']['action'] === 'error' && $rows['params:cold.freezer_capacity_cartons']['action'] === 'update', 'invalid time is an error, valid rows still previewed');
    expectSheet($pdo->query("SELECT value FROM n_prod_param WHERE code = 'cold.freezer_capacity_cartons'")->fetchColumn() === '1200'
        && !$pdo->query("SELECT 1 FROM n_prod_shift_template WHERE code = 'NOC2'")->fetchColumn(), 'all or nothing: the valid change is not saved either');

    // Fichier illisible
    [$s, $b] = $call('POST', '/referentiels/data-sheet/preview', ['file' => base64_encode('hello')], $manager, 'fr');
    expectSheet($s === 422 && $b['description'] === 'DATA_SHEET_INVALID_FILE' && str_contains($b['message'], 'Fichier illisible'), 'unreadable file');
    [$s, $b] = $call('POST', '/referentiels/data-sheet/preview', [], $manager);
    expectSheet($s === 422 && $b['errors'][0]['field'] === 'file', 'file is required');

    // --- Mêmes règles que la page Ustawienia › Pozostałe (ParamRules) : alerte urgente ≥ alerte, bornes connues
    [$s, $b] = $call('GET', '/referentiels/data-sheet', null, $direction);
    $book = $load($b['data']['content_base64']);
    $paramSheet = $book->getSheetByName('Parametry (paramètres)');
    foreach ($paramSheet->toArray(null, false, false, true) as $n => $row) {
        if ($row['A'] === 'alerts.complaint_days') $paramSheet->setCellValue('D' . $n, 9);
        if ($row['A'] === 'alerts.complaint_escalate_days') $paramSheet->setCellValue('D' . $n, 5);
        if ($row['A'] === 'shipping_issue.lookback_days') $paramSheet->setCellValue('D' . $n, 2);
    }
    [$s, $b] = $call('POST', '/referentiels/data-sheet/preview', ['file' => $save($book)], $manager);
    $rows = $byKey($b['data']);
    expectSheet($b['data']['error_count'] === 2 && $rows['params:alerts.complaint_escalate_days']['errors'] && $rows['params:shipping_issue.lookback_days']['errors']
        && !$rows['params:alerts.complaint_days']['errors'], 'import: urgent alert before the normal alert and out-of-range value refused, like the settings page');
    foreach ($paramSheet->toArray(null, false, false, true) as $n => $row) {
        if ($row['A'] === 'alerts.complaint_escalate_days') $paramSheet->setCellValue('D' . $n, 12);
        if ($row['A'] === 'shipping_issue.lookback_days') $paramSheet->setCellValue('D' . $n, 45);
    }
    [$s, $b] = $call('POST', '/referentiels/data-sheet/preview', ['file' => $save($book)], $manager);
    expectSheet($b['data']['error_count'] === 0, 'import: both alert days changed together in the right order accepted (final state of the file)');
    [$s, $b] = $call('PATCH', '/referentiels/params/alerts.complaint_escalate_days', ['value' => 1], $manager);
    expectSheet($s === 422 && $b['errors'][0]['code'] === 'PARAM_ESCALATE_BEFORE_ALERT', 'API: same order rule');
    [$s, $b] = $call('PATCH', '/referentiels/params/alerts.cost_due_day', ['value' => 40], $manager);
    expectSheet($s === 422 && $b['errors'][0]['code'] === 'OUT_OF_RANGE', 'API: same ranges');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "data_sheet_test: OK\n";
