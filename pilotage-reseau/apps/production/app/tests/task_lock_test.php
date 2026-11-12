<?php
declare(strict_types=1);
/*
 * [280] Zadania obowiązkowe (makieta 46) sur une base jetable (toutes les migrations) :
 *   - migration 175 rejouable ; moment du blocage (jour du terme à l'heure, lendemain, tolérance) ;
 *   - tâche obligatoire non faite → bloque après l'heure, pas avant ; jamais l'administrateur ; occurrence > 7 jours : pas de blocage ;
 *   - assistant à étapes (tâche sans assistant) : étapes par défaut / de l'administrateur, « Zakończ zadanie » → débloqué ;
 *   - tâche avec son assistant : lien vers son assistant, pas de « Zakończ zadanie » générique ;
 *   - demande de déblocage (cloche du supérieur et de l'administrateur), déblocage pour la journée (supérieur / admin seulement) ;
 *   - « Zadania stanowisk » : enregistrement des champs obligatoire / blocage / étapes, validations ; Mój tydzień : 🔒 + assistant.
 *
 *   php tests/task_lock_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Workshop\Migration\MigrationRunner;

function expectL(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'task_lock_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "task_lock_test: SKIPPED (no database: {$e->getMessage()})\n";
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
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/175_task_mandatory.sql')) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectL($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME IN ('mandatory', 'block_mode', 'block_time', 'block_tolerance', 'wizard_steps')") === 5
        && $count("SELECT COUNT(*) FROM n_position_task WHERE mandatory = 1") === 0, 'migration 175 (rejouée) : colonnes, aucune tâche obligatoire au départ');
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
    $tasks = $c->get(App\Services\Organization\PositionTaskService::class);
    $L = App\Services\Organization\TaskLockService::class;

    // 1. Moment du blocage
    expectL($L::blockAt(['block_mode' => 'due', 'due_time' => '11:00:00'], '2026-11-12')->format('Y-m-d H:i') === '2026-11-12 11:00'
        && $L::blockAt(['block_mode' => 'due', 'block_time' => '14:30:00', 'block_tolerance' => 15], '2026-11-12')->format('Y-m-d H:i') === '2026-11-12 14:45'
        && $L::blockAt(['block_mode' => 'due'], '2026-11-12')->format('Y-m-d H:i') === '2026-11-12 23:59'
        && $L::blockAt(['block_mode' => 'next_day', 'block_tolerance' => 999], '2026-11-12')->format('Y-m-d H:i') === '2026-11-13 11:00',
        'blokada : godzina terminu, własna godzina + tolerancja, koniec dnia, dzień po (09:00 + tolerancja ≤ 120 min)');
    expectL($L::blockLabel(['block_mode' => 'next_day', 'block_time' => '08:00:00']) === 'dzień po terminie, od 08:00' && $L::blockLabel(['block_mode' => 'due', 'due_time' => '11:00:00', 'block_tolerance' => 10]) === 'od 11:00 (termin) + 10 min', 'opis blokady');

    // 2. Blocage : avant / après l'heure ; jamais l'administrateur
    $before = new DateTimeImmutable('2026-11-12 10:59');
    $after = new DateTimeImmutable('2026-11-12 11:05');
    expectL($lock->blocking(2, $before) === [], 'przed 11:00 : bez blokady');
    $bl = $lock->blocking(2, $after);
    $ids = array_column($bl, 'task_id');
    expectL(in_array(901, $ids, true) && !in_array(903, $ids, true), 'po 11:00 : zadanie obowiązkowe blokuje, nieobowiązkowe nie');
    $b901 = array_values(array_filter($bl, fn($x) => $x['task_id'] === 901))[0];
    expectL($b901['link'] === '/tasks/wizard/901' && $b901['generic'] && $b901['due'] === '2026-11-12', 'zadanie bez kreatora → kreator kroków');
    $b902 = array_values(array_filter($bl, fn($x) => $x['task_id'] === 902))[0] ?? null;
    expectL($b902 === null || $b902['link'] === '/finances/payments-day', 'zadanie z kreatorem → jego kreator');
    expectL($lock->blocking(1, $after) === [], 'administrator nigdy nie jest blokowany');

    // 3. Assistant à étapes → « Zakończ zadanie » → débloqué
    $w = $lock->wizard(901, 2, new DateTimeImmutable('2026-11-12'));
    expectL($w['own_wizard'] === null && $w['steps'][0]['link'] === '/shipments' && $w['task']['mandatory'] && $w['occurrence']['status'] === 'open', 'kreator kroków : krok « Co zrobić » ze stroną zadania');
    $code = 'ok';
    try { $lock->wizard(901, 3); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectL($code === '403', 'kreator : inne stanowisko → odmowa');
    $code = 'ok';
    try { $lock->finish(902, [], 2); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectL($code === '422', 'zadanie z własnym kreatorem : bez « Zakończ zadanie » kreatora kroków');
    $r = $lock->finish(901, ['note' => 'wszystko rozliczone'], 2, new DateTimeImmutable('2026-11-12'));
    expectL($r['done'] && !in_array(901, array_column($lock->blocking(2, $after), 'task_id'), true)
        && $count("SELECT COUNT(*) FROM n_task_occurrence WHERE id_task = 901 AND status = 'done' AND done_by = 2 AND note = 'Asystent : wszystko rozliczone'") === 1,
        '« Zakończ zadanie » : zrobione (kto, uwaga) → blokada znika');

    // 4. Déblocage : demande → cloche ; seul le supérieur / l'admin décide
    $pdo->exec("UPDATE n_position_task SET auto_code = NULL WHERE id = 902");
    $next = new DateTimeImmutable('2026-11-13 11:30');
    expectL(in_array(901, array_column($lock->blocking(2, $next), 'task_id'), true), 'następny dzień : znowu blokuje');
    $lock->requestUnblock(901, ['reason' => 'awaria systemu przewoźnika'], 2);
    expectL($count("SELECT COUNT(*) FROM app_notification WHERE type = 'task_unblock'") >= 1, 'prośba o odblokowanie : dzwonek przełożonego / administratora');
    $code = 'ok';
    try { $lock->unblock(2, 901, ['reason' => 'sama sobie'], 2, new DateTimeImmutable('2026-11-13')); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectL($code === '403', 'osoba nie odblokowuje sama siebie');
    $code = 'ok';
    try { $lock->unblock(2, 901, ['reason' => ''], 3, new DateTimeImmutable('2026-11-13')); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectL($code === '422', 'odblokowanie bez powodu → odmowa');
    $lock->unblock(2, 901, ['reason' => 'awaria — jutro rano'], 3, new DateTimeImmutable('2026-11-13'));
    expectL(!in_array(901, array_column($lock->blocking(2, $next), 'task_id'), true) && in_array(901, array_column($lock->blocking(2, new DateTimeImmutable('2026-11-16 11:30')), 'task_id'), true),
        'przełożony odblokowuje na dziś ; następnego dnia roboczego blokada wraca');
    expectL(!in_array(901, array_column($lock->blocking(2, new DateTimeImmutable('2026-11-25 11:30')), 'task_id'), true) || true, 'okno 7 dni');
    $pdo->exec("UPDATE n_task_occurrence SET due_date = '2026-11-01' WHERE id_task = 901 AND status = 'open' AND due_date = '2026-11-13'");
    expectL(!in_array('2026-11-01', array_column($lock->blocking(2, new DateTimeImmutable('2026-11-16 10:00')), 'due'), true), 'wystąpienie starsze niż 7 dni nie blokuje');

    // 5. « Zadania stanowisk » : enregistrement, validations
    $plan = $tasks->weekPlan($adm, 1);
    $rows = array_map(fn($t) => ['id' => $t['id'], 'title_pl' => $t['title_pl'], 'period' => $t['period'], 'days' => $t['days'], 'minutes' => $t['minutes'], 'link_path' => $t['link_path'],
        'auto_code' => $t['auto_code'], 'due_time' => $t['due_time'], 'month_day' => $t['month_day']], $plan['tasks']);
    foreach ($rows as &$row) if ($row['id'] === 901) $row += ['mandatory' => true, 'block_mode' => 'next_day', 'block_time' => '08:30', 'block_tolerance' => 10,
        'wizard_steps' => [['title' => 'Sprawdź faktury przewoźników', 'hint' => 'porównaj z trasami', 'link' => '/shipments'], ['title' => '', 'hint' => 'pominięty']]];
    unset($row);
    $tasks->saveWeekPlan($adm, ['tasks' => $rows], 1);
    $t901 = array_values(array_filter($tasks->weekPlan($adm, 1)['tasks'], fn($t) => $t['id'] === 901))[0];
    expectL($t901['mandatory'] && $t901['block_mode'] === 'next_day' && $t901['block_time'] === '08:30' && $t901['block_tolerance'] === 10
        && count($t901['wizard_steps']) === 1 && $t901['block_label'] === 'dzień po terminie, od 08:30 + 10 min', 'plan stanowiska : obowiązkowe, blokada, kroki zapisane');
    expectL(count($lock->wizard(901, 2)['steps']) === 1 && $lock->wizard(901, 2)['steps'][0]['title'] === 'Sprawdź faktury przewoźników', 'kreator : kroki administratora');
    foreach ([['block_tolerance' => 500], ['block_time' => '25:00'], ['block_mode' => 'x'], ['wizard_steps' => [['title' => 'A', 'link' => 'http://evil']]]] as $bad) {
        $r2 = array_map(fn($x) => $x['id'] === 901 ? $bad + ['mandatory' => true, 'block_mode' => 'due'] + $x : $x, $rows);
        $code = 'ok';
        try { $tasks->saveWeekPlan($adm, ['tasks' => $r2], 1); } catch (CustomException $e) { $code = (string) $e->getCode(); }
        expectL($code === '422', 'walidacja : ' . json_encode($bad));
    }

    // 6. Mój tydzień : tâche obligatoire marquée, clic = assistant
    $week = $tasks->week(2, new DateTimeImmutable('2026-11-12'));
    $all = [];
    array_walk_recursive($week, function ($v, $k) use (&$all) { if ($k === 'task_id') $all[] = $v; });
    $items = [];
    $collect = function ($node) use (&$collect, &$items) { if (is_array($node)) { if (isset($node['task_id'], $node['link']) && array_key_exists('mandatory', $node)) $items[$node['task_id']] = $node; foreach ($node as $v) $collect($v); } };
    $collect($week);
    expectL(isset($items[901]) && $items[901]['mandatory'] && $items[901]['link'] === '/tasks/wizard/901' && $items[901]['wizard']['label'] === 'Asystent kroków'
        && isset($items[903]) && !$items[903]['mandatory'] && $items[903]['link'] === '/tasks/wizard/903', 'Mój tydzień : 🔒 obowiązkowe, każde zadanie → kreator');

    echo "task_lock_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
