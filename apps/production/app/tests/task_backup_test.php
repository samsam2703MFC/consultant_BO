<?php
declare(strict_types=1);
/*
 * [237] [238] Migration 155 sur une base jetable :
 *   - « Kontrola przyjęć dnia » : une seule tâche (Jakość), la copie du Kierownik désactivée, remplaçant = Kierownik ;
 *   - poste vide → la tâche est chez le Kierownik (Moje zadania, Mój tydzień, Kto co zrobił, occurrences du cron,
 *     rappels), avec « zastępstwo : Specjalista ds. jakości » ; quelqu'un prend Jakość → la tâche revient à Jakość ;
 *   - plan du poste : choix du remplaçant (enregistré, refusé si même poste) ;
 *   - Tarty : poste propre « Pracownik tart » (groupe Tarty, chef d'équipe), fiche et tâche du jour ; plus de
 *     « Grupa bez stanowiska » pour Tarty dans « Kto co zrobił ».
 *
 *   php tests/task_backup_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 155 appliquée). La base erp_task_backup_test
 * est recréée puis supprimée.
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Organization\TaskWizardRepository;
use App\Services\Organization\PositionTaskService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectTb(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_task_backup_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "task_backup_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_task_category', 'n_task_occurrence', 'n_task_form', 'n_prod_param',
                 'n_prod_user_role', 'n_page_access', 'n_position_sheet', 'n_prod_employee', 'n_activity_view', 'n_task_cron_run', 'app_notification',
                 'n_prod_group', 'n_prod_day_close', 'n_prod_day_line'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    // n_position_task sans la colonne de la migration 155 : la migration doit l'ajouter.
    $pdo->exec("CREATE TABLE n_position_task LIKE {$source}.n_position_task");
    $pdo->exec('ALTER TABLE n_position_task DROP COLUMN id_backup_position');
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'anna', 'Anna K.'), (3, 'jola', 'Jola Q.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'PRODUKCJA', 'Produkcja', 'Production', 'bi-gear', 10, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active, id_reports_to, id_group) VALUES
        (2, 'JAKOSC', 'Specjalista ds. jakości', 'Qualité', 'erp', 1, 1, NULL, NULL), (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 2, 1, NULL, NULL),
        (6, 'BRYGADZISTA', 'Brygadzista zmiany', 'Chef d''équipe', 'atelier', 3, 1, 3, NULL), (7, 'PIEKARZ', 'Piekarz', 'Boulanger', 'atelier', 4, 1, 6, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 3, '2026-01-01')");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', 1, 1), (7, 'TARTY', 'Tarty', 'Tartes', 2, 1)");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active) VALUES
        (5, 2, 1, 'Kontrola przyjęć dnia', 'Réceptions', 'daily', 20, '/workshop/receiving', 'receiving_checked', '08:00:00', 1, 1, 1),
        (16, 3, 1, 'Kontrola przyjęć dnia', 'Réceptions', 'daily', 20, '/workshop/receiving', 'receiving_checked', NULL, NULL, 1, 1),
        (20, 3, 1, 'Zebranie produkcyjne', 'Réunion', 'weekly', 30, NULL, NULL, NULL, NULL, 2, 1),
        (30, 7, 1, 'Dzień zatwierdzony na tablecie — Piekarnia', 'Journée', 'daily', 10, '/production/follow-up', 'group_day_closed', NULL, NULL, 5, 1)");

    // --- Migration 155 (deux fois : rejouable) ---------------------------------------------------------------------------
    $sql = file_get_contents(__DIR__ . '/../migrations/tfb/155_task_backup_tarty.sql');
    $run = function () use ($pdo, $sql) { foreach (MigrationRunner::splitStatements($sql) as $st) $pdo->exec($st); };
    $run();
    $run();
    $t = $pdo->query('SELECT id, id_position, id_backup_position, active FROM n_position_task WHERE id IN (5, 16) ORDER BY id')->fetchAll();
    expectTb((int) $t[0]['id_backup_position'] === 3 && (int) $t[0]['active'] === 1, 'Jakość : zadanie aktywne, zastępstwo Kierownik');
    expectTb((int) $t[1]['active'] === 0, 'Kierownik : kopia wyłączona (nie usunięta)');
    $tarty = $pdo->query("SELECT * FROM n_position WHERE code = 'TARTY'")->fetchAll();
    expectTb(count($tarty) === 1 && $tarty[0]['name_pl'] === 'Pracownik tart' && (int) $tarty[0]['id_group'] === 7 && (int) $tarty[0]['id_reports_to'] === 6
        && $tarty[0]['kind'] === 'atelier', 'Tarty : stanowisko pracowni, grupa Tarty, przełożony Brygadzista');
    $tid = (int) $tarty[0]['id'];
    expectTb((int) $pdo->query("SELECT COUNT(*) FROM n_position_task WHERE id_position = {$tid} AND auto_code = 'group_day_closed' AND title_pl LIKE '%Tarty%'")->fetchColumn() === 1, 'Tarty : zadanie dnia (raz)');
    expectTb((int) $pdo->query("SELECT COUNT(*) FROM n_position_sheet WHERE id_position = {$tid} AND mission_pl LIKE 'Wytwarza tarty%' AND validated_at IS NULL")->fetchColumn() === 1, 'Tarty : karta do przejrzenia');

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $s = $container->get(PositionTaskService::class);
    $today = new DateTimeImmutable('2026-11-04');

    // --- Poste vide → chez le Kierownik ------------------------------------------------------------------------------------
    $my = $s->myTasks(2, $today);
    $titles = [];
    foreach ($my['groups'] as $g) foreach ($g['items'] as $it) $titles[$it['task']['title_pl']] = $it['task']['backup_for'] ?? null;
    expectTb(array_key_exists('Kontrola przyjęć dnia', $titles) && $titles['Kontrola przyjęć dnia'] === 'Specjalista ds. jakości', 'Moje zadania (Kierownik) : przyjęcia w zastępstwie ' . json_encode($titles, JSON_UNESCAPED_UNICODE));
    expectTb(count(array_filter(array_keys($titles), fn($x) => $x === 'Kontrola przyjęć dnia')) === 1, 'jedno zadanie przyjęć, nie dwa');
    $week = $s->week(2, $today);
    $wk = [];
    foreach ($week['days'] as $d) foreach ($d['items'] as $it) $wk[] = $it;
    foreach ($week['strip'] as $row) $wk[] = $row['task'];
    $rec = array_values(array_filter($wk, fn($it) => $it['title'] === 'Kontrola przyjęć dnia'));
    expectTb($rec && $rec[0]['backup_for'] === 'Specjalista ds. jakości', 'Mój tydzień : przyjęcia z opisem zastępstwa');
    $ex = $s->execution('week', 1, $today);
    $kier = array_values(array_filter($ex['rows'], fn($r) => $r['name'] === 'Kierownik produkcji'))[0] ?? null;
    expectTb($kier !== null && $kier['tasks'] === 2, 'Kto co zrobił : Kierownik — zebranie + przyjęcia (' . ($kier['tasks'] ?? '—') . ')');
    expectTb(!array_filter($ex['rows'], fn($r) => $r['name'] === 'Specjalista ds. jakości'), 'Kto co zrobił : bez wiersza pustego stanowiska');
    $items = $s->itemsBetween('2026-11-02', '2026-11-06', $today);
    $recItems = array_values(array_filter($items, fn($i) => $i['task'] === 5));
    expectTb(count($recItems) === 5 && $recItems[0]['position_code'] === 'KIEROWNIK_PRODUKCJI', 'Zadania zespołu : przyjęcia w sekcji Kierownika');
    expectTb(!array_filter($items, fn($i) => $i['task'] === 16), 'kopia Kierownika : nigdzie');

    // Cron : occurrences du jour + rappel / escalade vers le poste qui fait la tâche.
    $s->ensureCurrent(new DateTimeImmutable('2026-11-04 07:00:00'));
    expectTb((int) $pdo->query("SELECT COUNT(*) FROM n_task_occurrence WHERE id_task = 5 AND due_date = '2026-11-04'")->fetchColumn() === 1, 'cron : wystąpienie zadania Jakości (stanowisko puste, zastępstwo)');
    $open = $container->get(TaskWizardRepository::class)->openUntil('2026-11-04');
    $o5 = array_values(array_filter($open, fn($o) => (int) $o['id_task'] === 5))[0] ?? null;
    expectTb($o5 !== null && (int) $o5['id_position'] === 3 && $o5['position_name'] === 'Kierownik produkcji', 'przypomnienia : do stanowiska zastępczego');

    // --- Quelqu'un prend Jakość → la tâche revient ---------------------------------------------------------------------------
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (3, 2, '2026-11-04')");
    $my = $s->myTasks(2, $today);
    $titles = [];
    foreach ($my['groups'] as $g) foreach ($g['items'] as $it) $titles[] = $it['task']['title_pl'];
    expectTb(!in_array('Kontrola przyjęć dnia', $titles, true), 'Kierownik : bez przyjęć, gdy Jakość obsadzona');
    $myJ = $s->myTasks(3, $today);
    $j = [];
    foreach ($myJ['groups'] as $g) foreach ($g['items'] as $it) $j[$it['task']['title_pl']] = $it['task']['backup_for'] ?? null;
    expectTb(array_key_exists('Kontrola przyjęć dnia', $j) && $j['Kontrola przyjęć dnia'] === null, 'Jakość : zadanie wróciło, bez opisu zastępstwa');
    $o5 = array_values(array_filter($container->get(TaskWizardRepository::class)->openUntil('2026-11-04'), fn($o) => (int) $o['id_task'] === 5))[0] ?? null;
    expectTb($o5 !== null && (int) $o5['id_position'] === 2, 'przypomnienia : znów do Jakości');
    $pdo->exec('DELETE FROM n_user_position WHERE id_user = 3');

    // --- Plan du poste : choix du remplaçant ----------------------------------------------------------------------------------
    $plan = $s->weekPlan(2, 1, new DateTimeImmutable('2026-11-04 10:00:00'));
    $row = array_values(array_filter($plan['tasks'], fn($r) => $r['id'] === 5))[0];
    expectTb($row['backup_position_id'] === 3 && $plan['vacant'] === true, 'plan : zastępstwo widoczne, stanowisko puste');
    expectTb(in_array(3, array_column($plan['backup_positions'], 'id'), true) && !in_array(2, array_column($plan['backup_positions'], 'id'), true)
        && !in_array(7, array_column($plan['backup_positions'], 'id'), true), 'plan : lista stanowisk ERP bez własnego');
    $payload = fn(?int $backup) => ['tasks' => [['id' => 5, 'title_pl' => 'Kontrola przyjęć dnia', 'category_id' => 1, 'period' => 'week', 'days' => [1, 2, 3, 4, 5],
        'minutes' => 20, 'link_path' => '/workshop/receiving', 'extra_links' => [], 'auto_code' => 'receiving_checked', 'due_time' => null, 'month_day' => null,
        'cron_on' => false, 'cron_then' => 'wait', 'remind_time' => '08:00', 'escalate_days' => 1, 'backup_position_id' => $backup]], 'removed' => []];
    $s->saveWeekPlan(2, $payload(null), 1, $today);
    expectTb($pdo->query('SELECT id_backup_position FROM n_position_task WHERE id = 5')->fetchColumn() === null, 'plan : zastępstwo usunięte');
    $s->saveWeekPlan(2, $payload(3), 1, $today);
    expectTb((int) $pdo->query('SELECT id_backup_position FROM n_position_task WHERE id = 5')->fetchColumn() === 3, 'plan : zastępstwo z powrotem');
    try {
        $s->saveWeekPlan(2, $payload(2), 1, $today);
        expectTb(false, 'plan : zastępstwo = to samo stanowisko odrzucone');
    } catch (App\Exceptions\CustomException $e) {
        expectTb($e->getCode() === 422 && str_contains($e->getMessage(), 'zastępstwo'), 'plan : 422 ' . $e->getMessage());
    }

    // --- Tarty : son poste dans « Kto co zrobił » -------------------------------------------------------------------------------
    $groups = array_values(array_filter($ex['rows'], fn($r) => $r['name'] === 'Produkcja — grupy (tablety)'))[0] ?? null;
    $ex = $s->execution('week', 1, $today);
    $groups = array_values(array_filter($ex['rows'], fn($r) => $r['name'] === 'Produkcja — grupy (tablety)'))[0] ?? null;
    $who = [];
    foreach ($groups['cells'][0] ?? [] as $it) $who[] = $it['who'];
    expectTb(in_array('Pracownik tart', $who, true) && !in_array('Grupa bez stanowiska', $who, true), 'Kto co zrobił : Tarty ze swoim stanowiskiem ' . json_encode($who, JSON_UNESCAPED_UNICODE));
    expectTb(!str_contains((string) $groups['sub'], '+ Tarty'), 'opis : bez « + Tarty »');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "task_backup_test: OK\n";
