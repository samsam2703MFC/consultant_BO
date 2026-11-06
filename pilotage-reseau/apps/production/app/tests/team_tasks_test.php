<?php
declare(strict_types=1);
/*
 * Zadania zespołu [232] (makiety 7 et 8) sur une base jetable (structure copiée de erp) :
 *   - migration 150 rejouable (n_task_reminder, app_notification.id_user) ;
 *   - tableau : semaine (mercredi 04.11.2026), sections groupe (tablette Piekarnia, groupe sans poste Tarty), pakowanie,
 *     poste ERP (Administracja) ; états en retard / à faire / prévu / fait / validé seul ; filtres groupe, personne
 *     (compte ERP, salarié), fréquence, catégorie, recherche, état ; accès (admin, Kierownik oui ; Administracja non) ;
 *   - « Przypomnij » : personne → cloche à elle seule + e-mail en file (aucun envoi), groupe → message tablette ;
 *     tâche faite ignorée, rien à envoyer → 422 ;
 *   - cloche par personne : visible et acquittable seulement par elle ; lu → « przeczytane (dzwonek) » au tableau ;
 *   - tablette : messages du groupe (+ chef d'équipe), pakowanie ; « Przeczytane » PIN faux / juste, sans PIN présent,
 *     sans PIN absent refusé ; jour validé → message fermé (disparaît).
 *
 *   php tests/team_tasks_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_team_test est recréée puis supprimée.
 * Aucun e-mail : le transport SMTP du test refuse tout (les e-mails restent « queued »).
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Services\Notification\AppNotificationService;
use App\Services\Organization\TeamTasksService;
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectT(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

expectT(TeamTasksService::initials('Piekarnia') === 'PI' && TeamTasksService::initials('Maria Testowa') === 'MT'
    && TeamTasksService::initials('Brygadzista zmiany (tablety grup)') === 'BZ', 'initials');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_team_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "team_tasks_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if ($t === 'n_task_reminder') continue; // recréée par la migration 150 ci-dessous
        $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    // État avant 150 : app_notification sans id_user.
    if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_notification' AND COLUMN_NAME = 'id_user'")->fetchColumn()) {
        $pdo->exec('ALTER TABLE app_notification DROP KEY idx_app_notification_user, DROP COLUMN id_user');
    }
    $migration = (string) file_get_contents(__DIR__ . '/../migrations/tfb/150_team_tasks_reminders.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($migration) as $sql) $pdo->exec($sql);
    expectT((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_notification' AND COLUMN_NAME = 'id_user'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_task_reminder'")->fetchColumn() === 1, 'migration 150 (replayed twice)');
    expectT(\App\Core\Web\PageAccess::keyFor('/organization/team-tasks') === 'execution', 'page access: team board = « Kto co zrobił » key');

    // --- Données : postes, tâches, comptes, groupes, salariés (mercredi 04.11.2026)
    $today = new DateTimeImmutable('2026-11-04');
    $mon = '2026-11-02'; $tue = '2026-11-03'; $wed = '2026-11-04'; $fri = '2026-11-06';
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Admin', '', 'Admin', '', '', 0, 'admin', ''), (2, 'Maria', 'Testowa', 'Maria Testowa', 'maria@example.invalid', '', 0, 'maria', ''),
        (3, 'Anna', 'Kier', 'Anna K.', 'anna@example.invalid', '', 0, 'anna', '')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'ADMIN', 'Administracja', 'Administration', 'bi-x', 1, 1), (2, 'PRODUKCJA', 'Produkcja', 'Production', 'bi-y', 2, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES
        (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1), (5, 'ADMINISTRACJA', 'Administracja i fakturowanie', 'Admin', 'erp', 2, 1),
        (7, 'PIEKARZ', 'Piekarz', 'Boulanger', 'atelier', 3, 1), (11, 'PAKOWACZ', 'Pakowanie', 'Emballage', 'atelier', 4, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 5, '2026-01-01'), (3, 3, '2026-01-01')");
    $pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (3, 'execution')");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, due_weekday, due_time, auto_code, sort_order, active) VALUES
        (10, 5, 1, 'Kontrola poczty i płatności', 'x', 'daily', NULL, '11:00:00', NULL, 1, 1),
        (11, 5, 1, 'Raport tygodnia', 'x', 'weekly', 5, NULL, NULL, 2, 1),
        (20, 7, 2, 'Dzień zatwierdzony na tablecie — Piekarnia (plan → zrobione → straty → zatwierdź)', 'x', 'daily', NULL, '18:00:00', 'group_day_closed', 1, 1),
        (30, 11, 2, 'Porządek na stanowisku pakowania', 'x', 'daily', NULL, NULL, NULL, 1, 1)");
    $pdo->exec("INSERT INTO n_task_occurrence (id_task, period_key, due_date, status, done_by, done_at, note) VALUES (10, '{$mon}', '{$mon}', 'done', 2, '{$mon} 10:12:00', NULL)");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1),
        (6, 'PAKOWANIE', 'Pakowanie/wysyłka', 'Emballage', '#555555', 5, 1), (7, 'TARTY', 'Tarty', 'Tartes', '#2b5f9e', 6, 1)");
    $pin = password_hash('1234', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (1, 'Jan', 'Testowy', 0, 1), (2, 'Ewa', 'Testowa', 0, 1), (3, 'Olek', 'Nieobecny', 0, 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group, id_position) VALUES (11, 1, 1, 1, 7), (12, 2, 1, 1, 7), (13, 3, 1, 1, 7)");
    $pdo->prepare('INSERT INTO n_prod_employee_credential (id_employee, pin_hash) VALUES (11, ?)')->execute([$pin]);
    $pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, source, created_at) VALUES (11, NOW(), 'kiosk', NOW()), (12, NOW(), 'kiosk', NOW())");
    // Tarty : lundi des lignes sans validation (en retard), mardi validé ; Piekarnia : mardi validé.
    $pdo->exec("INSERT INTO n_prod_day_line (work_date, id_group, id_item, produced) VALUES ('{$mon}', 7, 1, 40)");
    $pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, closed_at) VALUES ('{$tue}', 7, '{$tue} 14:05:00'), ('{$tue}', 1, '{$tue} 15:30:00')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
    }]);
    $container = $builder->build();
    $team = $container->get(TeamTasksService::class);

    // --- Accès
    try { $team->board([], 2, $today); expectT(false, 'Administracja has no access'); }
    catch (App\Exceptions\CustomException $e) { expectT($e->getCode() === 403, 'access: Administracja → 403'); }
    expectT($team->canUse(1) && $team->canUse(3) && !$team->canUse(2) && !$team->canUse(null), 'access: admin and Kierownik (« Kto co zrobił » page)');

    // --- Tableau semaine, tous les états
    $b = $team->board(['state' => 'all'], 3, $today);
    expectT($b['period']['from'] === $mon && $b['period']['to'] === '2026-11-08' && $b['period']['label'] === 'tydzień 45 (02.11–06.11.2026)' && $b['period']['short'] === 'tydz. 45', 'period: ISO week 45');
    $sec = [];
    foreach ($b['sections'] as $s) $sec[$s['key']] = $s;
    expectT(array_keys($sec) === ['g1', 'g7', 'p11', 'p5'], 'sections: Piekarnia (tablet), Tarty (group without position), Pakowanie, Administracja — ' . implode(',', array_keys($sec)));
    expectT($sec['g1']['kind'] === 'tablet' && $sec['g1']['remind'] === 'tablet' && str_contains($sec['g1']['sub'], '3 osoby · 2 obecne'), 'Piekarnia: tablet, 3 people, 2 present — ' . $sec['g1']['sub']);
    expectT($sec['p5']['kind'] === 'erp' && $sec['p5']['sub'] === 'Maria Testowa' && str_contains($sec['p5']['channel'], 'e-mail'), 'Administracja: Maria, bell + e-mail');
    $state = fn(string $key, string $due, ?int $task = null) => array_values(array_filter($sec[$key]['rows'], fn($r) => $r['due'] === $due && ($task === null || $r['task'] === $task)))[0] ?? null;
    expectT($state('g1', $mon)['state'] === 'late' && $state('g1', $tue)['state'] === 'auto' && $state('g1', $wed)['state'] === 'todo' && $state('g1', $fri)['state'] === 'plan', 'Piekarnia days: late, validated, today, planned');
    expectT($state('g1', $mon)['hint'] === null && $state('g1', $wed)['due_time'] === '18:00', 'Piekarnia: hint already in the title, due 18:00');
    expectT($state('g7', $mon)['state'] === 'late' && str_contains((string) $state('g7', $mon)['summary'], '1 pozycja wpisana') && $state('g7', $tue)['state'] === 'auto'
        && $state('g7', $wed)['state'] === 'todo' && $state('g7', $fri)['state'] === 'plan' && $state('g7', $mon)['group_id'] === 7, 'Tarty: entered not validated (late), validated, today, planned');
    expectT($state('p5', $mon, 10)['state'] === 'ok' && $state('p5', $mon, 10)['done_by'] === 'Maria Testowa' && !$state('p5', $mon, 10)['can_remind']
        && $state('p5', $tue, 10)['state'] === 'late' && $state('p5', $tue, 10)['late_days'] === 1 && $state('p5', $fri, 11)['state'] === 'plan', 'Administracja: done Monday by Maria, late Tuesday, weekly report Friday');
    expectT($b['counts']['all'] === $b['tiles']['total'] && $b['counts']['all'] === 21 && $b['counts']['done'] === 3 && $b['counts']['late'] === 5, 'counts — ' . json_encode($b['counts']));
    expectT($b['tiles']['open'] === $b['counts']['todo'] && $b['tiles']['open_due'] + $b['tiles']['open_later'] === $b['tiles']['open'] && $b['tiles']['late_2'] === 0, 'tiles — ' . json_encode($b['tiles']));
    expectT(count($b['options']['groups']) === 4 && in_array('u2', array_column($b['options']['people'], 'key'), true) && in_array('e11', array_column($b['options']['people'], 'key'), true)
        && array_column($b['options']['categories'], 'name') === ['Administracja', 'Produkcja'], 'filter options: groups, people (account + employee), categories');

    // --- Filtres
    $only = fn(array $q) => array_column($team->board($q + ['state' => 'all'], 3, $today)['sections'], 'key');
    expectT($only(['group' => 'g7']) === ['g7'] && $only(['person' => 'u2']) === ['p5'] && $only(['person' => 'e12']) === ['g1'], 'filters: group, ERP person, employee');
    expectT($only(['freq' => 'weekly']) === ['p5'] && $only(['category' => '2']) === ['g1', 'p11'] && $only(['q' => 'raport']) === ['p5'], 'filters: frequency, category, search');
    $late = $team->board(['state' => 'late'], 3, $today);
    expectT(array_sum(array_map(fn($s) => count($s['rows']), $late['sections'])) === 5 && $late['counts']['late'] === 5, 'filter: late only');
    $todo = $team->board(['state' => 'todo'], 3, $today);
    expectT(!array_filter(array_merge(...array_map(fn($s) => $s['rows'], $todo['sections'])), fn($r) => in_array($r['state'], ['ok', 'auto'], true)), 'filter: not done (default)');
    $day = $team->board(['view' => 'day', 'state' => 'all'], 3, $today);
    expectT($day['period']['from'] === $wed && $day['period']['short'] === 'dziś' && $team->board(['view' => 'month', 'offset' => '-1'], 3, $today)['period']['label'] === 'październik 2026', 'periods: today, previous month');

    // --- Przypomnij : Piekarnia lundi (tablette), Tarty lundi (tablette), Administracja mardi (cloche + e-mail), lundi déjà fait
    $r = $team->remind(['items' => [['task' => 20, 'due' => $mon], ['group_id' => 7, 'due' => $mon], ['task' => 10, 'due' => $tue], ['task' => 10, 'due' => $mon]],
        'message' => '', 'urgent' => true, 'channels' => ['bell' => true, 'email' => true, 'tablet' => true]], 3, $today);
    expectT($r['reminders'] === 3 && $r['users'] === 1 && $r['tablets'] === 2 && $r['emails'] === 1 && count($r['skipped']) === 1 && str_contains($r['skipped'][0], 'już zrobione'), 'remind: 2 tablets + Maria (bell + e-mail), done task skipped — ' . json_encode($r));
    $rows = $pdo->query('SELECT * FROM n_task_reminder ORDER BY id')->fetchAll();
    expectT(count($rows) === 3 && $rows[0]['audience'] === 'group:1' && $rows[1]['audience'] === 'group:7' && $rows[1]['id_task'] === null && $rows[2]['id_user'] == 2
        && $rows[2]['channels'] === 'bell,email' && (int) $rows[2]['urgent'] === 1 && str_contains($rows[2]['message'], '« Kontrola poczty i płatności » — wtorek 03.11 nie jest zrobione'), 'reminders stored with auto message');
    $mail = $pdo->query("SELECT * FROM communication_message WHERE message_type = 'task_reminder'")->fetchAll();
    expectT(count($mail) === 1 && $mail[0]['recipient_email'] === 'maria@example.invalid' && $mail[0]['status'] === 'queued' && str_starts_with($mail[0]['subject_text'], '[PILNE] Przypomnienie')
        && (int) $rows[2]['id_message'] === (int) $mail[0]['id'] && $rows[2]['id_notification'] !== null, 'e-mail queued for Maria (not sent), linked to the reminder');
    try { $team->remind(['items' => [['task' => 10, 'due' => $mon]]], 3, $today); expectT(false, 'nothing to send'); }
    catch (App\Exceptions\CustomException $e) { expectT($e->getCode() === 422 && str_contains($e->getMessage(), 'już zrobione'), 'remind: only done tasks → 422'); }
    try { $team->remind(['items' => [['task' => 20, 'due' => $mon]], 'channels' => ['bell' => false, 'email' => false, 'tablet' => false]], 3, $today); expectT(false, 'no channel'); }
    catch (App\Exceptions\CustomException $e) { expectT($e->getCode() === 422, 'remind: no channel → 422'); }
    try { $team->remind(['items' => [['task' => 20, 'due' => $mon]]], 2, $today); expectT(false, 'no access'); }
    catch (App\Exceptions\CustomException $e) { expectT($e->getCode() === 403, 'remind: no access → 403'); }

    // --- Cloche de Maria seulement
    $bell = $container->get(AppNotificationService::class);
    $mine = array_values(array_filter($bell->active(20, 2), fn($n) => $n['type'] === 'task_reminder'));
    expectT(count($mine) === 1 && $mine[0]['severity'] === 'danger' && str_contains($mine[0]['title'], 'Kontrola poczty'), 'bell: Maria sees her urgent reminder');
    expectT(!array_filter($bell->active(20, 3), fn($n) => $n['type'] === 'task_reminder') && !array_filter($bell->active(20, null), fn($n) => $n['type'] === 'task_reminder')
        && $bell->countActive(3) < $bell->countActive(2), 'bell: nobody else sees it');
    try { $bell->acknowledge((int) $mine[0]['id'], 'read', 3); expectT(false, 'other user acknowledge'); }
    catch (App\Exceptions\CustomException $e) { expectT($e->getCode() === 404, 'bell: another user cannot acknowledge it'); }
    $bell->acknowledge((int) $mine[0]['id'], 'read', 2);
    $b = $team->board(['state' => 'all'], 3, $today);
    $adm = array_values(array_filter($b['sections'], fn($s) => $s['key'] === 'p5'))[0];
    $tueRow = array_values(array_filter($adm['rows'], fn($r) => $r['due'] === $tue && $r['task'] === 10))[0];
    expectT($tueRow['reminder_count'] === 1 && $tueRow['reminders'][0]['read_via'] === 'bell' && $tueRow['reminders'][0]['read_at'] !== null && $tueRow['reminders'][0]['to'] === 'Maria Testowa', 'board: Maria read it (bell)');
    expectT($container->get(App\Repositories\Organization\TeamTasksRepository::class)->sentOn(date('Y-m-d')) === ['sent' => 3, 'read' => 1], 'sent today: 3, read 1');

    // --- Tablettes
    $ctx = fn(?int $group) => new ApiContext('team-test', new Translator('pl'), null, [], [], [], 50, null, $group);
    $pk = $team->tabletMessages($ctx(1), 'group', $today);
    expectT(count($pk) === 1 && $pk[0]['due'] === $mon && $pk[0]['urgent'] && $pk[0]['from'] === 'Od kierownika produkcji', 'tablet Piekarnia: its message');
    expectT(count($team->tabletMessages($ctx(7), 'group', $today)) === 1 && $team->tabletMessages($ctx(null), 'group', $today) === [] && $team->tabletMessages($ctx(1), 'packing', $today) === [], 'tablet Tarty; no group → nothing; packing → nothing');
    $auth = $container->get(KioskAuthService::class);
    $verify = fn(array $p) => $auth->verifyPin($p, $ctx(1));
    $id = $pk[0]['id'];
    try { $team->readTabletMessage($id, ['employee_id' => 11, 'pin' => '9999'], $ctx(1), 'group', $verify); expectT(false, 'wrong PIN'); }
    catch (ApiException $e) { expectT($e->errorCode() === 'PIN_INVALID', 'read: wrong PIN refused'); }
    try { $team->readTabletMessage($id, ['employee_id' => 11], $ctx(1), 'group', $verify); expectT(false, 'PIN required'); }
    catch (ApiException $e) { expectT($e->errorCode() === 'VALIDATION_FAILED', 'read: person with a PIN must type it'); }
    try { $team->readTabletMessage($id, ['employee_id' => 13], $ctx(1), 'group', $verify); expectT(false, 'absent without PIN'); }
    catch (ApiException $e) { expectT($e->errorCode() === 'VALIDATION_FAILED', 'read: absent person without PIN refused'); }
    try { $team->readTabletMessage(99999, ['employee_id' => 11, 'pin' => '1234'], $ctx(1), 'group', $verify); expectT(false, 'unknown'); }
    catch (ApiException $e) { expectT($e->errorCode() === 'NOT_FOUND', 'read: unknown message'); }
    $read = $team->readTabletMessage($id, ['employee_id' => 11, 'pin' => '1234'], $ctx(1), 'group', $verify);
    expectT($read['read'] && $read['via'] === 'pin' && $read['by'] === 'Jan Testowy' && $team->tabletMessages($ctx(1), 'group', $today) === [], 'read with PIN → gone from the tablet');
    // Tarty : Ewa (sans PIN, présente) touche son nom ; nouveau rappel puis jour validé → fermé
    $tarty = $team->tabletMessages($ctx(7), 'group', $today)[0]['id'];
    $tap = $team->readTabletMessage($tarty, ['employee_id' => 12], $ctx(7), 'group', $verify);
    expectT($tap['via'] === 'tap' && $tap['by'] === 'Ewa Testowa', 'read: present person without PIN by tap');
    $team->remind(['items' => [['task' => 20, 'due' => $wed]]], 3, $today);
    expectT(count($team->tabletMessages($ctx(1), 'group', $today)) === 1, 'new reminder for today');
    $pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, closed_at) VALUES ('{$wed}', 1, NOW())");
    expectT($team->tabletMessages($ctx(1), 'group', $today) === [] && (int) $pdo->query("SELECT COUNT(*) FROM n_task_reminder WHERE due_date = '{$wed}' AND closed_at IS NOT NULL")->fetchColumn() === 1, 'day validated → message closed (disappears)');
    $b = $team->board(['state' => 'all'], 3, $today);
    $pk = array_values(array_filter($b['sections'], fn($s) => $s['key'] === 'g1'))[0];
    $monRow = array_values(array_filter($pk['rows'], fn($r) => $r['due'] === $mon))[0];
    $wedRow = array_values(array_filter($pk['rows'], fn($r) => $r['due'] === $wed))[0];
    expectT($monRow['reminders'][0]['read_by'] === 'Jan Testowy' && $monRow['reminders'][0]['read_via'] === 'pin' && $wedRow['state'] === 'auto' && $wedRow['reminders'][0]['closed'], 'board: read by Jan (PIN), today validated and closed');

    // Pakowanie : message en-tête de la tablette d'emballage
    $team->remind(['items' => [['task' => 30, 'due' => $wed]], 'message' => 'Proszę posprzątać stanowisko przed 14:00.'], 3, $today);
    $pack = $team->tabletMessages($ctx(null), 'packing', $today);
    expectT(count($pack) === 1 && $pack[0]['message'] === 'Proszę posprzątać stanowisko przed 14:00.' && !$pack[0]['urgent'], 'packing tablet: custom message');

    echo "team_tasks_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
