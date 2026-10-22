<?php
declare(strict_types=1);
/*
 * Zadania z formularzem i śledzenie produkcji : test na lokalnej bazie ERP (erp), z przywróceniem stanu w finally.
 * Kategorie zadań, zebranie produkcyjne (szkic, zakończenie = zadanie zrobione, działania przenoszone na
 * następne zebranie), śledzenie produkcji (plan, partie, straty, zatwierdzone dni).
 *
 *   php tests/task_forms_test.php
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Core\Api\Workshop\ApiException;
use App\Exceptions\CustomException;
use App\Services\Organization\PositionTaskService;
use App\Services\Organization\TaskFormService;
use App\Services\Workshop\Execution\ProductionFollowUpService;

function expectTf(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function failsWith(callable $fn): ?string
{
    try { $fn(); } catch (CustomException $e) { return $e->getMessage(); } catch (ApiException $e) { return $e->errorCode(); }
    return null;
}

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$pdo = $container->get(PDO::class);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$max = [];
foreach (['tfb_task_occurrence', 'tfb_task_form', 'tfb_task_action', 'tfb_task_category', 'tfb_prod_output', 'tfb_prod_waste', 'tfb_prod_day_close', 'tfb_position_task'] as $table) {
    $max[$table] = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
}
$userId = (int) $pdo->query('SELECT MIN(id) FROM user')->fetchColumn();
$otherUser = (int) $pdo->query("SELECT MAX(id) FROM user")->fetchColumn();
$userPosition = $pdo->query("SELECT * FROM tfb_user_position WHERE id_user = {$userId}")->fetch(PDO::FETCH_ASSOC) ?: null;
$meetingTask = $pdo->query("SELECT * FROM tfb_position_task WHERE form_code = 'production_meeting' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$slotBackup = [];
$slotKeys = null;

try {
    $tasks = $container->get(PositionTaskService::class);
    $forms = $container->get(TaskFormService::class);
    $followUp = $container->get(ProductionFollowUpService::class);

    // --- Kategorie i zadania z migracji 047
    $cats = array_column($tasks->categories(), null, 'code');
    expectTf(isset($cats['CENY'], $cats['MARZE'], $cats['UPLOAD'], $cats['PRODUKCJA'], $cats['ZEBRANIA']), 'task categories seeded');
    expectTf($meetingTask && (int) $meetingTask['due_weekday'] === 1 && $meetingTask['frequency'] === 'weekly' && (int) $meetingTask['id_category'] === (int) $cats['ZEBRANIA']['id'],
        'production meeting: weekly on Monday, category meetings, with a form');
    expectTf(failsWith(fn() => $tasks->saveCategory(['code' => 'CENY', 'name_pl' => 'Duplikat'], null)) === 'DUPLICATE_CODE', 'category code unique');
    $newCat = $tasks->saveCategory(['code' => 'test_higiena', 'name_pl' => 'Higiena', 'icon' => 'bi-droplet'], null);
    $cat = $pdo->query("SELECT * FROM tfb_task_category WHERE id = {$newCat}")->fetch(PDO::FETCH_ASSOC);
    expectTf($cat['code'] === 'TEST_HIGIENA' && $cat['name_fr'] === 'Higiena' && $cat['icon'] === 'bi-droplet', 'new category (code upper-cased, FR defaults to PL)');
    expectTf(failsWith(fn() => $tasks->saveCategory(['icon' => 'fa-x'], $newCat)) === 'VALIDATION_FAILED', 'icon must be a Bootstrap icon name');

    // Zadanie : kategoria, formularz, dzień terminu, link z #
    $positionId = (int) $meetingTask['id_position'];
    $taskId = $tasks->saveTask(['title_pl' => 'Test : przegląd higieny', 'frequency' => 'weekly', 'due_weekday' => '3', 'category_id' => (string) $newCat,
        'form_code' => 'meeting', 'link_path' => '/workshop/supply#tab=invoices'], null, $positionId);
    $t = $pdo->query("SELECT * FROM tfb_position_task WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    expectTf((int) $t['id_category'] === $newCat && $t['form_code'] === 'meeting' && (int) $t['due_weekday'] === 3 && $t['link_path'] === '/workshop/supply#tab=invoices', 'task saved with category, form, weekday');
    $tasks->saveTask(['category_id' => null, 'form_code' => null, 'due_weekday' => null], $taskId, null);
    $t = $pdo->query("SELECT * FROM tfb_position_task WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
    expectTf($t['id_category'] === null && $t['form_code'] === null && $t['due_weekday'] === null, 'category, form and weekday cleared');
    expectTf(failsWith(fn() => $tasks->saveTask(['form_code' => 'unknown'], $taskId, null)) === 'VALIDATION_FAILED', 'only known forms');
    $tasks->saveTask(['active' => false], $taskId, null);

    // --- Tablica : stanowisko kierownika produkcji, poniedziałek tygodnia z zebraniem
    $tasks->assign($userId, ['position_id' => $positionId]);
    $monday = new DateTimeImmutable('monday this week');
    $board = $tasks->myTasks($userId, $monday);
    $meetingItem = array_values(array_filter($board['groups']['ZEBRANIA']['items'] ?? [], fn($it) => (int) $it['task']['id'] === (int) $meetingTask['id']))[0] ?? null;
    expectTf($meetingItem && $meetingItem['occurrence']['due_date'] === $monday->format('Y-m-d') && $meetingItem['occurrence']['form_id'] === null, 'meeting occurrence due on Monday, no form yet');
    $occ = (int) $meetingItem['occurrence']['id'];
    expectTf(str_contains((string) failsWith(fn() => $tasks->setStatus($occ, ['status' => 'done'], $userId)), 'protokołem'), 'a form task is done only through its form');

    // --- Śledzenie produkcji : plan, partie, straty, zatwierdzony dzień (grupa 1, poprzedni tydzień)
    $item = (int) $pdo->query("SELECT i.id FROM tfb_prod_item i JOIN tfb_prod_product_settings s ON s.id_item = i.id WHERE i.item_type = 'product' AND s.id_primary_group = 1 ORDER BY i.id LIMIT 1")->fetchColumn();
    $d1 = $monday->modify('-7 days')->format('Y-m-d');
    $d2 = $monday->modify('-6 days')->format('Y-m-d');
    $slotKeys = [$item, $d1, $d2];
    $slotBackup = $pdo->query("SELECT * FROM tfb_prod_week_slot WHERE id_item = {$item} AND work_date IN ('{$d1}', '{$d2}')")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec("DELETE FROM tfb_prod_week_slot WHERE id_item = {$item} AND work_date IN ('{$d1}', '{$d2}')");
    $pdo->exec("INSERT INTO tfb_prod_week_slot (week_start, id_item, work_date, quantity) VALUES ('{$d1}', {$item}, '{$d1}', 200), ('{$d1}', {$item}, '{$d2}', 100)");
    $pdo->exec("INSERT INTO tfb_prod_output (work_date, id_group, id_item, quantity, lot_code) VALUES ('{$d1}', 1, {$item}, 200, 'TF-TEST-01'), ('{$d2}', 1, {$item}, 60, 'TF-TEST-02')");
    $pdo->exec("INSERT INTO tfb_prod_waste (work_date, id_group, id_item, quantity, reason) VALUES ('{$d1}', 1, {$item}, 10, 'burnt'), ('{$d2}', 1, {$item}, 3, 'quality')");
    $pdo->exec("INSERT INTO tfb_prod_day_close (work_date, id_group, summary) VALUES ('{$d1}', 1, '[]')");
    $f = $followUp->summary($d1, $d2);
    $g = array_values(array_filter($f['groups'], fn($r) => $r['id'] === 1))[0] ?? null;
    $day2 = array_values(array_filter($f['days'], fn($d) => $d['date'] === $d2))[0] ?? null;
    $below = array_values(array_filter($f['below_plan'], fn($b) => $b['item_id'] === $item))[0] ?? null;
    expectTf($g && $g['planned'] >= 300 && $g['produced'] >= 260 && $g['waste'] >= 13 && $g['closed'] >= 1 && $g['days'] >= 2, 'follow-up per group: plan, produced, waste, validated days');
    expectTf(($f['reasons']['burnt'] ?? 0) >= 10 && ($f['reasons']['quality'] ?? 0) >= 3, 'waste by reason');
    expectTf($day2 && $day2['groups'] >= 1 && $day2['closed'] < $day2['groups'] && in_array($g['name'], $day2['open_groups'], true), 'day without validation lists the group');
    expectTf($below && abs($below['missing'] - 40) < 0.001 && $g['plan_pct'] !== null && $g['plan_pct'] <= 100, 'products below plan (overproduction does not count)');
    expectTf(ProductionFollowUpService::period('2026-12-31', '2026-01-01') === ['2026-10-01', '2026-12-31'], 'period ordered and limited to 92 days');

    // --- Protokół : szkic, zakończenie, działania
    $open = $forms->open($occ, $d1, $d2);
    expectTf($open['definition']['code'] === 'production_meeting' && $open['follow_up']['from'] === $d1 && count($open['topics']) === 6 && !$open['final'], 'new meeting form with live follow-up');
    expectTf(str_contains((string) failsWith(fn() => $forms->save($occ, ['final' => true, 'meeting_date' => $monday->format('Y-m-d'), 'topics' => ['production' => 'x']], $userId)), 'uczestników'), 'final protocol needs participants');
    $draft = $forms->save($occ, ['meeting_date' => $monday->format('Y-m-d'), 'participants' => [$userId, 999999], 'topics' => ['safety' => 'Rękawice przy piecu'],
        'period_from' => $d1, 'period_to' => $d2, 'new_actions' => [['title' => 'Naprawić czujnik pieca 2', 'user_id' => (string) $otherUser, 'due_date' => $monday->modify('+3 days')->format('Y-m-d')], ['title' => '  ']]], $userId);
    $formRow = $pdo->query("SELECT * FROM tfb_task_form WHERE id_occurrence = {$occ}")->fetch(PDO::FETCH_ASSOC);
    expectTf($formRow && $formRow['status'] === 'draft' && $draft['participants'] === [$userId] && count($draft['actions']) === 1 && $draft['topics']['safety'] === 'Rękawice przy piecu'
        && $pdo->query("SELECT status FROM tfb_task_occurrence WHERE id = {$occ}")->fetchColumn() === 'open', 'draft saved (unknown participant dropped, empty action ignored), task still open');
    $final = $forms->save($occ, ['final' => true, 'meeting_date' => $monday->format('Y-m-d'), 'participants' => [$userId], 'topics' => ['safety' => 'Rękawice przy piecu', 'production' => 'Plan OK'],
        'period_from' => $d1, 'period_to' => $d2], $userId);
    $data = json_decode((string) $pdo->query("SELECT data FROM tfb_task_form WHERE id_occurrence = {$occ}")->fetchColumn(), true);
    $occRow = $pdo->query("SELECT * FROM tfb_task_occurrence WHERE id = {$occ}")->fetch(PDO::FETCH_ASSOC);
    expectTf($final['final'] && $occRow['status'] === 'done' && (int) $occRow['done_by'] === $userId && str_starts_with((string) $occRow['note'], 'Protokół zebrania')
        && isset($data['follow_up']['groups']) && $data['follow_up']['from'] === $d1, 'final protocol: task done, follow-up snapshot stored');
    $formId = (int) $formRow['id'];
    $view = $forms->view($formId);
    expectTf(count($view['topics']) === 2 && count($view['actions']) === 1 && $view['follow_up'] !== null && $view['participants'] !== [], 'protocol view');
    expectTf(in_array($formId, array_map('intval', array_column($forms->list(), 'id')), true), 'protocol listed');

    // Następne zebranie : działanie otwarte wraca, zamknięcie na nowym protokole
    $nextMonday = $monday->modify('+7 days');
    $next = $tasks->myTasks($userId, $nextMonday);
    $nextItem = array_values(array_filter($next['groups']['ZEBRANIA']['items'], fn($it) => (int) $it['task']['id'] === (int) $meetingTask['id']))[0];
    $occ2 = (int) $nextItem['occurrence']['id'];
    $open2 = $forms->open($occ2);
    $carried = $open2['carried'][0] ?? null;
    expectTf($carried && $carried['title'] === 'Naprawić czujnik pieca 2' && $carried['status'] === 'open' && $open2['participants'] === [$userId], 'open action carried to next meeting, participants suggested');
    $forms->save($occ2, ['meeting_date' => $nextMonday->format('Y-m-d'), 'participants' => [$userId], 'close_actions' => [(int) $carried['id']]], $userId);
    $closed = $pdo->query("SELECT * FROM tfb_task_action WHERE id = {$carried['id']}")->fetch(PDO::FETCH_ASSOC);
    $form2 = (int) $pdo->query("SELECT id FROM tfb_task_form WHERE id_occurrence = {$occ2}")->fetchColumn();
    expectTf($closed['status'] === 'done' && (int) $closed['done_in_form'] === $form2 && (int) $closed['done_by'] === $userId, 'action closed in the next meeting');
    $open2b = $forms->open($occ2);
    expectTf(count($open2b['carried']) === 1 && $open2b['carried'][0]['status'] === 'done', 'closed action still shown on the meeting that closed it');
    $forms->save($occ2, ['meeting_date' => $nextMonday->format('Y-m-d'), 'participants' => [$userId], 'reopen_actions' => [(int) $carried['id']]], $userId);
    expectTf($pdo->query("SELECT status FROM tfb_task_action WHERE id = {$carried['id']}")->fetchColumn() === 'open', 'action reopened');
    $myAction = (int) $pdo->query("SELECT id FROM tfb_task_action WHERE id_form = {$formId}")->fetchColumn();
    $forms->save($occ2, ['meeting_date' => $nextMonday->format('Y-m-d'), 'participants' => [$userId], 'delete_actions' => [$myAction]], $userId);
    expectTf((int) $pdo->query("SELECT COUNT(*) FROM tfb_task_action WHERE id = {$myAction}")->fetchColumn() === 1, 'actions of another protocol are not deleted from here');
    expectTf(str_contains((string) failsWith(fn() => $forms->open((int) $pdo->query("SELECT o.id FROM tfb_task_occurrence o JOIN tfb_position_task t ON t.id = o.id_task WHERE t.form_code IS NULL LIMIT 1")->fetchColumn())), 'formularza'),
        'tasks without a form have no form page');

    echo "task_forms_test: OK\n";
} finally {
    $pdo->exec("DELETE FROM tfb_task_action WHERE id > {$max['tfb_task_action']}");
    $pdo->exec("DELETE FROM tfb_task_form WHERE id > {$max['tfb_task_form']}");
    $pdo->exec("DELETE FROM tfb_task_occurrence WHERE id > {$max['tfb_task_occurrence']}");
    $pdo->exec("DELETE FROM tfb_position_task WHERE id > {$max['tfb_position_task']}");
    $pdo->exec("DELETE FROM tfb_task_category WHERE id > {$max['tfb_task_category']}");
    $pdo->exec("DELETE FROM tfb_prod_waste WHERE id > {$max['tfb_prod_waste']}");
    $pdo->exec("DELETE FROM tfb_prod_day_close WHERE id > {$max['tfb_prod_day_close']}");
    $pdo->exec("DELETE FROM tfb_prod_output WHERE id > {$max['tfb_prod_output']}");
    if ($slotKeys) {
        [$item, $d1, $d2] = $slotKeys;
        $pdo->exec("DELETE FROM tfb_prod_week_slot WHERE id_item = {$item} AND work_date IN ('{$d1}', '{$d2}')");
        foreach ($slotBackup as $r) {
            $pdo->prepare('INSERT INTO tfb_prod_week_slot (' . implode(', ', array_keys($r)) . ') VALUES (' . implode(', ', array_fill(0, count($r), '?')) . ')')->execute(array_values($r));
        }
    }
    $pdo->exec("DELETE FROM tfb_user_position WHERE id_user = {$userId}");
    if ($userPosition) {
        $pdo->prepare('INSERT INTO tfb_user_position (id_user, id_position, since) VALUES (?, ?, ?)')->execute([$userId, $userPosition['id_position'], $userPosition['since']]);
    }
}
