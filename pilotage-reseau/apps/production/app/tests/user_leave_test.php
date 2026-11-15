<?php
declare(strict_types=1);
/*
 * [289] « Mój urlop » (makieta 50) sur une base jetable (toutes les migrations, 180 rejouée) :
 *   - urlop d'un compte ERP avec remplaçant ; refus (passé, sans jour ouvré, chevauchement, soi-même, remplaçant en congé, solde) ;
 *   - validation par le CEO (pas par un autre compte), cloche du CEO puis de la personne et du remplaçant ;
 *   - pendant le congé validé : tâches du poste chez le remplaçant (« zastępstwo »), bandeau, la personne n'est plus bloquée,
 *     le remplaçant l'est par les tâches obligatoires du poste ; changement de remplaçant sans validation ; annulation.
 *
 *   php tests/user_leave_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Organization\UserLeaveService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectL(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function codeL(callable $fn): string
{
    try { $fn(); } catch (CustomException $e) { return $e->getCode() . ' ' . $e->getMessage(); }
    return 'accepted';
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'user_leave_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "user_leave_test: SKIPPED (no database: {$e->getMessage()})\n";
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
        (3, 'Kasia', 'Kierownik', 'Kasia K.', '', '', 0, 'kasia', 'x'), (4, 'Wiktor', 'Wspolnik', 'Wiktor W.', '', '', 0, 'wiktor', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (8, 'Administracja', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/180_user_leave.sql')) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $pos = fn(string $code) => (int) $pdo->query("SELECT id FROM n_position WHERE code = '{$code}'")->fetchColumn();
    $adm = $pos('ADMINISTRACJA'); $kier = $pos('KIEROWNIK_PRODUKCJI');
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) VALUES (2, {$adm}), (3, {$kier})");
    $pdo->exec("INSERT INTO n_user_ceo (id_user, granted_by) VALUES (4, 1)");
    $pdo->exec("UPDATE n_position_task SET active = 0");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, title_pl, title_fr, frequency, minutes, due_time, link_path, mandatory, block_mode, sort_order, active) VALUES
        (902, {$adm}, 'Płatności dnia', 'x', 'daily', 25, '11:00:00', '/finances/payments-day', 1, 'due', 1, 1),
        (950, {$kier}, 'Plan na jutro', 'x', 'daily', 20, NULL, '/production/tomorrow', 0, 'due', 1, 1)");

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('t', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $b->build();
    $_SESSION = [];
    $leave = $c->get(UserLeaveService::class);
    $now = strtotime('2026-11-12 10:00:00');
    expectL($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_user_leave'") === 1, 'migration 180 (rejouée)');

    // --- Page : solde 26, remplaçants (comptes ERP) sans soi-même ; CEO et admin décident.
    $p = $leave->page(2, $now);
    $cand = array_column($p['candidates'], 'position', 'id');
    expectL($p['balance']['remaining'] === 26 && !isset($cand[2]) && isset($cand[1], $cand[3], $cand[4]) && $cand[4] === 'CEO' && !$p['can_decide'], 'page : solde, remplaçants (Administrator / IT, CEO, Produkcja)');
    expectL($leave->canDecide(4) && $leave->canDecide(1) && !$leave->canDecide(3), 'CEO et administrateur décident');

    // --- Refus.
    $ref = fn(array $in, int $u = 2) => codeL(fn() => $leave->create($u, $in, $now));
    expectL(str_contains($ref(['from' => '2026-11-10', 'to' => '2026-11-10']), 'od dziś'), 'passé refusé');
    expectL(str_contains($ref(['from' => '2026-11-14', 'to' => '2026-11-15']), 'dni roboczych'), 'week-end seul refusé');
    expectL(str_contains($ref(['from' => '2026-11-16', 'to' => '2026-11-20', 'substitute_id' => 2]), 'ta sama osoba'), 'remplaçant = soi-même refusé');
    expectL(str_contains($ref(['from' => '2026-11-16', 'to' => '2026-12-31']), 'Za mało urlopu'), 'solde dépassé refusé');

    // --- Ewa (Finanse) : urlop 16–20.11, remplaçante Kasia.
    $r = $leave->create(2, ['type' => 'urlop', 'from' => '2026-11-16', 'to' => '2026-11-20', 'substitute_id' => 3, 'note' => 'wyjazd'], $now);
    expectL($r['days'] === 5 && $count("SELECT COUNT(*) FROM n_user_leave WHERE id = {$r['id']} AND status = 'pending' AND id_substitute = 3") === 1, 'urlop 5 jours, en attente, remplaçante');
    expectL($count("SELECT COUNT(*) FROM app_notification WHERE type = 'user_leave' AND id_user = 4") === 1 && $count("SELECT COUNT(*) FROM app_notification WHERE type = 'user_leave' AND id_user = 1") === 0, 'cloche du CEO (pas de l admin quand un CEO existe)');
    expectL(str_contains($ref(['from' => '2026-11-18', 'to' => '2026-11-18']), 'Masz już urlop'), 'chevauchement refusé');
    expectL(str_contains($ref(['from' => '2026-11-17', 'to' => '2026-11-17', 'substitute_id' => 2], 3), 'Zastępca ma urlop'), 'remplaçant en congé refusé');
    expectL($leave->balance(2, 2026)['remaining'] === 21, 'demande comptée dans le solde');

    // --- Pas encore validé : aucun transfert.
    $tasks = $c->get(App\Services\Organization\PositionTaskService::class);
    $w = $tasks->week(3, new DateTimeImmutable('2026-11-17'));
    expectL($w['delegations'] === [] && !in_array(902, array_map(fn($s) => $s['task']['id'] ?? null, $w['strip']), true), 'demande non validée : pas de transfert');

    // --- Validation : pas Kasia, le CEO oui.
    expectL(str_starts_with(codeL(fn() => $leave->approve($r['id'], 3)), '403'), 'un autre compte ne valide pas');
    $ok = $leave->approve($r['id'], 4);
    expectL($ok['status'] === 'approved' && $count("SELECT COUNT(*) FROM app_notification WHERE type = 'user_leave' AND id_user IN (2, 3)") === 2, 'CEO valide ; cloche de la personne et de la remplaçante');
    expectL(str_starts_with(codeL(fn() => $leave->approve($r['id'], 4)), '409'), 'deuxième décision refusée');

    // --- Pendant le congé : tâches de Finanse chez Kasia, bandeau, blocage.
    $c2 = (new DI\ContainerBuilder())->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('t', 48), 'API_BASE_URL' => 'https://erp.test'])])->build();
    $tasks = $c2->get(App\Services\Organization\PositionTaskService::class);
    $w = $tasks->week(3, new DateTimeImmutable('2026-11-17'));
    $all = json_encode($w, JSON_UNESCAPED_UNICODE);
    expectL(count($w['delegations']) === 1 && $w['delegations'][0]['tasks'] === 1 && str_contains($all, 'Płatności dnia') && str_contains($all, 'Ewa F. (urlop do 20.11)'), 'remplaçante : tâche Finanse marquée « zastępstwo »');
    $we = $tasks->week(2, new DateTimeImmutable('2026-11-17'));
    expectL($we['on_leave'] !== null && $we['on_leave']['substitute'] === 'Kasia K.' && $we['delegations'] === [], 'personne en congé : bandeau avec la remplaçante');
    $after = $tasks->week(3, new DateTimeImmutable('2026-11-23'));
    expectL($after['delegations'] === [] && !str_contains(json_encode($after, JSON_UNESCAPED_UNICODE), 'Płatności dnia'), 'après le congé : les tâches reviennent');

    $lock = $c2->get(App\Services\Organization\TaskLockService::class);
    $pdo->exec("INSERT INTO n_task_occurrence (id_task, period_key, due_date, status) VALUES (902, '2026-11-16', '2026-11-16', 'open')");
    $blockedK = array_column($lock->blocking(3, new DateTimeImmutable('2026-11-17 12:00:00')), 'task_id');
    expectL($lock->blocking(2, new DateTimeImmutable('2026-11-17 12:00:00')) === [] && in_array(902, $blockedK, true), 'blocage : jamais la personne en congé, la remplaçante oui');

    // --- Changer de remplaçant : la personne, sans validation ; pas un autre compte.
    expectL(str_starts_with(codeL(fn() => $leave->changeSubstitute($r['id'], 1, 3)), '403'), 'un autre compte ne change pas le remplaçant');
    $ch = $leave->changeSubstitute($r['id'], 1, 2);
    expectL($ch['substitute_id'] === 1 && $ch['status'] === 'approved', 'remplaçant changé (Administrator), sans nouvelle validation');
    expectL(count($c2->make(App\Services\Organization\PositionTaskService::class)->week(1, new DateTimeImmutable('2026-11-17'))['delegations']) === 1, 'nouveau remplaçant : reçoit les tâches');

    // --- Refus et annulation.
    $r2 = $leave->create(3, ['type' => 'na_zadanie', 'from' => '2026-12-01', 'to' => '2026-12-01', 'substitute_id' => 2], $now);
    expectL(str_starts_with(codeL(fn() => $leave->reject($r2['id'], '', 4)), '422'), 'refus sans motif refusé');
    $leave->reject($r2['id'], 'inwentaryzacja', 4);
    expectL($pdo->query("SELECT CONCAT(status, '|', decision_note) FROM n_user_leave WHERE id = {$r2['id']}")->fetchColumn() === 'rejected|inwentaryzacja', 'refus avec motif');
    $leave->cancel($r['id'], 2, $now);
    expectL($count("SELECT COUNT(*) FROM n_user_leave WHERE id = {$r['id']} AND status = 'cancelled'") === 1, 'annulation avant le début');

    echo "user_leave_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
