<?php
declare(strict_types=1);
/*
 * [281] Profil CEO (makieta 47) + [283] Zadania w railu, sur une base jetable (toutes les migrations) :
 *   - migration 177 rejouable ; compte CEO : voit tout sauf les réglages, écrit seulement les décisions, jamais de verrou ;
 *   - administrateur : profil « CEO · kontrola » sous l'avatar (page d'accueil Kontrola, menu sans réglages), retour Administrator ;
 *   - Kontrola : personnes (entrée QR / tablette, absence, tâches, obligatoires bloquées → Odblokuj), équipes, semaine, décisions ;
 *   - demande de déblocage : chaque destinataire reçoit sa cloche (clé avec destinataire), visible dans Kontrola ;
 *   - rail : « Zadania » dans le menu de chaque profil, même avec un menu de poste.
 *
 *   php tests/control_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\PageAccess;
use App\Core\Web\Rail;
use App\Core\Web\ViewSession;
use App\Exceptions\CustomException;
use App\Services\Workshop\Migration\MigrationRunner;

function expectC(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// Katalog : profil CEO bez ustawień, zapis tylko decyzji.
expectC(PageAccess::ceoSees('dashboard') && PageAccess::ceoSees('control') && PageAccess::ceoSees('activity') && !PageAccess::ceoSees('settings')
    && !PageAccess::ceoSees('access') && !PageAccess::ceoSees('ai'), 'CEO : wszystko oprócz ustawień');
expectC(PageAccess::ceoMayWrite('/ajax/tasks/unblock/3/901') && PageAccess::ceoMayWrite('/ajax/organization/execution/remind/5') && PageAccess::ceoMayWrite('/logout')
    && !PageAccess::ceoMayWrite('/ajax/organization/week-plan/2') && !PageAccess::ceoMayWrite('/users/edit/3'), 'CEO : zapis tylko decyzji');
// [283] Zadania w railu każdego profilu (także menu stanowiska bez tej pozycji).
$keys = array_merge(...array_map(fn($s) => array_column($s['items'], 'key'), Rail::menu(['production' => null])));
expectC(in_array('my_tasks', $keys, true) && in_array('today', $keys, true) && Rail::ITEMS['my_tasks'][0] === 'Zadania', 'Zadania zawsze w menu');
expectC(Rail::itemFor('/my-tasks') === 'my_tasks' && Rail::itemFor('/tasks/required') === 'my_tasks' && Rail::itemFor('/control') === 'control', 'podświetlenie Zadania i Kontrola');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'control_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "control_test: SKIPPED (no database: {$e->getMessage()})\n";
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

    $lock = $c->get(App\Services\Organization\TaskLockService::class);
    $access = fn() => $c->make(App\Services\Organization\PageAccessService::class);
    $control = fn() => $c->make(App\Services\Organization\ControlService::class);
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/177_ceo_profile.sql')) as $sql) $pdo->exec($sql);
    expectC($count("SELECT COUNT(*) FROM n_user_ceo") === 0, 'migracja 177 (rejouée) : nikt nie ma profilu CEO');
    $_SESSION = [];

    // 1. Konto CEO (użytkownik 4, bez stanowiska) : nadaje tylko administrator.
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (4, 'Wiktor', 'Wspólnik', 'Wiktor W.', '', '', 0, 'wiktor', 'x')");
    try { $access()->setCeo(4, true, 2); expectC(false, 'profil CEO nadaje administrator'); } catch (CustomException $e) { expectC($e->getCode() === 403, 'nie-administrator : 403'); }
    $access()->setCeo(4, true, 1);
    $a = $access();
    expectC($a->isCeo(4) && !$a->isCeo(1) && !$a->isAdmin(4), 'konto CEO');
    expectC($a->canSee(4, '/dashboard') && $a->canSee(4, '/control') && $a->canSee(4, '/organization/execution') && !$a->canSee(4, '/settings') && !$a->canSee(4, '/organization/access'),
        'CEO widzi wszystko oprócz ustawień');
    expectC(!$a->canSee(2, '/control') && $a->canSee(1, '/control'), 'Kontrola : administrator i CEO');
    expectC($a->homePath(4) === '/control' && !array_key_exists('settings', $a->railMenu(4)) && !array_key_exists('access', $a->railMenu(4))
        && array_key_exists('control', $a->railMenu(4)) && array_key_exists('profitability', $a->railMenu(4)), 'CEO : start Kontrola, menu bez ustawień');
    $menu = $a->sessionMenu(4);
    expectC($menu['label'] === 'CEO · kontrola' && !$menu['can_switch'], 'CEO : etykieta pod imieniem, bez przełącznika');
    expectC($lock->blocking(4) === [], 'CEO : nigdy blokady');

    // 2. Administrator : profil CEO pod awatarem.
    $m = $a->sessionMenu(1);
    expectC(array_column($m['profiles'], 'key') === ['admin', 'ceo'] && $m['profiles'][0]['current'], 'profile pod awatarem : Administrator (bieżący) i CEO');
    expectC($a->switchSession(1, null, 'ceo') === '/control' && ViewSession::ceo(), 'profil CEO → Kontrola');
    $prefixes = $access()->railPrefixes(1);
    expectC(($prefixes['/settings'] ?? null) === false && ($prefixes['/dashboard'] ?? null) === true && $access()->sessionMenu(1)['label'] === 'CEO · kontrola', 'profil CEO administratora : menu bez ustawień');
    expectC($access()->canSee(1, '/settings'), 'administrator w profilu CEO : dostęp bez zmian');
    expectC($access()->switchSession(1, null, 'admin') === '/dashboard' && !ViewSession::ceo(), 'powrót : Administrator');
    try { $access()->switchSession(2, null, 'ceo'); expectC(false, 'profil : tylko administrator'); } catch (CustomException $e) {}
    try { $access()->switchSession(1, null, 'boss'); expectC(false, 'nieznany profil'); } catch (CustomException $e) {}

    // 3. Kontrola : osoba zablokowana (Ewa, obowiązkowe po terminie), wejście QR, nieobecność, prośba o odblokowanie.
    $now = new DateTimeImmutable('today 15:00');
    $today = $now->format('Y-m-d');
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (51, 'Ewa', 'Finanse', 0, 8), (52, 'Kasia', 'Kierownik', 0, 8), (53, 'Jan', 'Piekarz', 0, 8), (54, 'Ola', 'Pakowanie', 0, 8)");
    foreach ([51, 52, 53, 54] as $pe) $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active) VALUES ({$pe}, {$pe}, 1)");
    $pdo->exec("INSERT INTO n_user_employee (id_user, id_employee) VALUES (2, 51), (3, 52)");
    $qr = (int) $pdo->query("SELECT value FROM n_prod_param WHERE code = 'erp.qr_device_id'")->fetchColumn();
    $pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, source, id_device) VALUES (51, '{$today} 07:58:00', 'kiosk', " . ($qr ?: 'NULL') . "), (53, '{$today} 06:00:00', 'kiosk', NULL)");
    $pdo->exec("INSERT INTO n_time_absence (id_employee, date_from, date_to, type) VALUES (52, '{$today}', '{$today}', 'urlop')");
    expectC(count($lock->blocking(2, $now)) === 2, 'Ewa : 2 obowiązkowe po 11:00');
    $lock->requestUnblock(902, ['reason' => 'awaria banku, wyciąg po 13:00'], 2);
    expectC($count("SELECT COUNT(*) FROM app_notification WHERE type = 'task_unblock'") === 3, 'prośba : dzwonek przełożonego (Kasia), administratora i konta CEO — każdy osobno');
    $o = $control()->overview(1, $now);
    $ewa = array_values(array_filter($o['people'], fn($p) => $p['id'] === 2))[0] ?? null;
    $kasia = array_values(array_filter($o['people'], fn($p) => $p['id'] === 3))[0] ?? null;
    expectC($ewa && $ewa['in'] === '07:58' && $ewa['in_source'] === ($qr ? 'QR' : 'tablet') && $ewa['out'] === null && $ewa['mandatory']['state'] === 'blocked'
        && $ewa['action']['kind'] === 'unblock' && str_starts_with($ewa['action']['href'], '/tasks/unblock/2/'), 'Ewa : wejście, ERP zablokowany → Odblokuj ' . json_encode($ewa));
    expectC($kasia && $kasia['absence'] === 'urlop' && $kasia['in'] === null, 'Kasia : urlop');
    expectC(!array_filter($o['people'], fn($p) => in_array($p['id'], [1, 4], true)), 'bez administratora i konta CEO');
    expectC($o['kpi']['people'] === 4 && $o['kpi']['present'] === 2 && $o['kpi']['absent'] === 1 && $o['kpi']['missing'] === 1 && $o['kpi']['blocked'] === 1, 'kafelki obecności ' . json_encode($o['kpi']));
    expectC($o['team']['people'] === 2 && $o['team']['present'] === 1, 'zespoły pracowni : osoby bez konta ERP');
    expectC(count($o['decisions']['requests']) === 1 && $o['decisions']['requests'][0]['reason'] === 'awaria banku, wyciąg po 13:00' && $o['kpi']['requests'] === 1, 'prośba w « Do decyzji CEO »');
    expectC(count($o['week']['columns']) >= 5 && $o['week']['rows'] !== [], 'tydzień : stanowiska × dni');
    try { $control()->overview(2, $now); expectC(false, 'Kontrola : nie dla stanowiska'); } catch (CustomException $e) { expectC($e->getCode() === 403, '403'); }
    expectC(is_array($control()->overview(4, $now)['people']), 'konto CEO otwiera Kontrolę');

    // 4. CEO odblokowuje na dziś (decyzja), zapomniane wyjście wczoraj.
    $lock->unblock(2, 902, ['reason' => 'zgoda CEO'], 4, $now);
    expectC(count($lock->blocking(2, $now)) === 1, 'CEO odblokował jedno zadanie na dziś');
    $pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, source) VALUES (54, DATE_SUB('{$today} 07:00:00', INTERVAL 1 DAY), 'kiosk')");
    $o = $control()->overview(4, $now);
    expectC($o['decisions']['forgotten'] === 1 && $o['decisions']['requests'] === [], 'zapomniane wyjście ; prośba rozpatrzona znika');
    echo "control_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
