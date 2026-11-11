<?php
declare(strict_types=1);
/*
 * Lot 3 « tâches quotidiennes des tablettes » [229] : migration 148 (tâche du jour des postes d'atelier), occurrences
 * sans compte ERP, validation par la tablette (journée du groupe, toute l'équipe, emballage, tournée), « Kto co zrobił »
 * en deux lignes (groupes de production + Brygadzista · Pakowanie · Kierowca, groupe sans poste ajouté), état des jours
 * sans occurrence (lecture seule), aucune cloche pour ces postes — sur une base jetable.
 *
 *   php tests/task_atelier_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 148 appliquée). La base erp_task_atelier_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Organization\PositionTaskService;
use App\Services\Organization\TaskCronService;
use App\Services\Organization\TaskWizards;
use App\Services\Workshop\Migration\MigrationRunner;

function expectTa(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_task_atelier_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "task_atelier_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_position_task', 'n_task_category', 'n_task_occurrence', 'n_task_form', 'n_prod_param',
                 'n_prod_user_role', 'n_page_access', 'n_position_sheet', 'n_prod_employee', 'n_activity_view', 'n_task_cron_run', 'app_notification',
                 'n_prod_group', 'n_prod_day_close', 'n_prod_day_line', 'n_packing_count', 'n_delivery_run', 'n_delivery_stop', 'palets_delivery'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'anna', 'Anna K.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'PRODUKCJA', 'Produkcja', 'Production', 'bi-gear', 10, 1), (2, 'LOGISTYKA', 'Logistyka', 'Logistique', 'bi-truck', 20, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active, id_reports_to) VALUES
        (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1, NULL), (6, 'BRYGADZISTA', 'Brygadzista zmiany', 'Chef d''équipe', 'atelier', 2, 1, 3),
        (7, 'PIEKARZ', 'Piekarz', 'Boulanger', 'atelier', 3, 1, 6), (8, 'ROGAL', 'Piekarz wyrobów laminowanych', 'Viennoiserie', 'atelier', 4, 1, 6),
        (11, 'PAKOWACZ', 'Pracownik pakowania', 'Emballage', 'atelier', 5, 1, 3), (12, 'KIEROWCA', 'Kierowca-dostawca', 'Chauffeur', 'atelier', 6, 1, 3)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 3, '2026-01-01')");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', 1, 1),
        (3, 'ROGAL', 'Rogal', 'Viennoiserie', 2, 1), (7, 'TARTY', 'Tarty', 'Tartes', 3, 1)");
    $pdo->exec("INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, sort_order, active) VALUES
        (3, 1, 'Zebranie produkcyjne', 'Réunion', 'weekly', 1, 30, NULL, 1, 1)");

    $sql = file_get_contents(__DIR__ . '/../migrations/tfb/148_atelier_daily_tasks.sql');
    $run = function () use ($pdo, $sql) { foreach (MigrationRunner::splitStatements($sql) as $st) $pdo->exec($st); };
    $run();
    $run();
    $tasks = $pdo->query("SELECT p.code, t.auto_code, t.frequency, t.remind_time, t.escalate_days, t.title_pl FROM n_position_task t JOIN n_position p ON p.id = t.id_position WHERE t.auto_code IS NOT NULL ORDER BY p.id")->fetchAll();
    expectTa(array_column($tasks, 'auto_code', 'code') === ['BRYGADZISTA' => 'shift_day_validated', 'PIEKARZ' => 'group_day_closed', 'ROGAL' => 'group_day_closed', 'PAKOWACZ' => 'packing_done', 'KIEROWCA' => 'route_done'],
        'migracja : 5 zadań dnia (raz, rejouable) — ' . json_encode(array_column($tasks, 'auto_code', 'code')));
    expectTa(!array_filter($tasks, fn($t) => $t['frequency'] !== 'daily' || $t['remind_time'] !== null || $t['escalate_days'] !== null), 'codziennie, bez dzwonka');
    expectTa(str_contains($tasks[1]['title_pl'], 'Piekarnia'), 'tytuł z nazwą grupy');

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $s = $container->get(PositionTaskService::class);
    $w = $container->get(TaskWizards::class);
    $wk = ['2026-11-02 00:00:00', '2026-11-03 00:00:00'];

    // --- Sygnały tabletów ------------------------------------------------------------------------------------------
    expectTa(!$w->state('group_day_closed', ...[...$wk, '2026-11-02', 'PIEKARZ'])['done'], 'grupa : nic');
    expectTa($w->state('group_day_closed', ...[...$wk, '2026-11-02', 'KIEROWNIK_PRODUKCJI'])['step'] === null, 'stanowisko bez grupy : nigdy');
    $pdo->exec("INSERT INTO n_prod_day_line (work_date, id_group, id_item, produced) VALUES ('2026-11-02', 1, 10, 120), ('2026-11-02', 1, 11, 80), ('2026-11-02', 3, 12, 50)");
    $st = $w->state('group_day_closed', ...[...$wk, '2026-11-02', 'PIEKARZ']);
    expectTa(!$st['done'] && $st['step'] === 1 && $st['step_label'] === '2 pozycje wpisane, dzień niezatwierdzony', 'grupa : wpisane, niezatwierdzone');
    $sh = $w->state('shift_day_validated', ...[...$wk, '2026-11-02']);
    expectTa(!$sh['done'] && $sh['step'] === 1 && $sh['steps'] === 2 && $sh['step_label'] === '0 z 2 grup zatwierdziło', 'zmiana : 0 z 2');
    $pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, closed_at) VALUES ('2026-11-02', 1, '2026-11-02 14:35:00')");
    $st = $w->state('group_day_closed', ...[...$wk, '2026-11-02', 'PIEKARZ']);
    expectTa($st['done'] && $st['summary'] === 'dzień zatwierdzony 14:35 · 2 pozycje', 'grupa : zatwierdzone 14:35');
    expectTa($w->state('shift_day_validated', ...[...$wk, '2026-11-02'])['step_label'] === '1 z 2 grup zatwierdziło', 'zmiana : 1 z 2');
    $pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, closed_at) VALUES ('2026-11-02', 3, '2026-11-02 15:10:00')");
    expectTa($w->state('shift_day_validated', ...[...$wk, '2026-11-02'])['summary'] === '2 grupy zatwierdziły dzień', 'zmiana : wszystkie grupy');

    expectTa($w->state('packing_done', ...[...$wk, '2026-11-02'])['summary'] === 'brak wysyłek tego dnia', 'pakowanie : dzień bez wysyłek');
    $pdo->exec("INSERT INTO palets_delivery (client, delivery_date, status, id_customer) VALUES ('A', '2026-11-03', 1, 10), ('B', '2026-11-03', 1, 11)");
    $pdo->exec("INSERT INTO n_packing_count (ship_date, id_product, packed, base_count) VALUES ('2026-11-03', 1, 40, 100), ('2026-11-03', 2, 0, 60)");
    $tue = ['2026-11-03 00:00:00', '2026-11-04 00:00:00'];
    $pk = $w->state('packing_done', ...[...$tue, '2026-11-03']);
    expectTa(!$pk['done'] && $pk['step_label'] === '40 z 160 szt. spakowane', 'pakowanie : w toku ' . $pk['step_label']);
    $pdo->exec("UPDATE n_packing_count SET packed = base_count + 5");
    expectTa($w->state('packing_done', ...[...$tue, '2026-11-03'])['summary'] === '2 produkty · 160 szt. spakowane', 'pakowanie : wszystko');

    expectTa($w->state('route_done', ...[...$wk, '2026-11-02'])['summary'] === 'brak trasy tego dnia', 'trasa : dzień bez trasy');
    expectTa(!$w->state('route_done', ...[...$tue, '2026-11-03'])['done'], 'trasa : palety bez przejazdu = do zrobienia');
    $pdo->exec("INSERT INTO n_delivery_run (ship_date, id_carrier_vehicle, started_at) VALUES ('2026-11-03', 1, '2026-11-03 06:00:00')");
    $pdo->exec("INSERT INTO n_delivery_stop (ship_date, id_customer, status, recorded_at) VALUES ('2026-11-03', 10, 'delivered', '2026-11-03 08:00:00'), ('2026-11-03', 11, 'problem', '2026-11-03 09:00:00')");
    expectTa($w->state('route_done', ...[...$tue, '2026-11-03'])['step_label'] === '1 dostawa potwierdzona, trasa w toku', 'trasa : w toku');
    $pdo->exec("UPDATE n_delivery_run SET finished_at = '2026-11-03 12:40:00'");
    expectTa($w->state('route_done', ...[...$tue, '2026-11-03'])['summary'] === '1 dostawa · trasa zakończona 12:40 · 1 problem', 'trasa : zakończona');

    // --- Wystąpienia bez konta ERP (cron) i brak dzwonka --------------------------------------------------------------
    $s->ensureCurrent(new DateTimeImmutable('2026-11-04 07:00:00'));
    $occ = $pdo->query("SELECT p.code, o.status FROM n_task_occurrence o JOIN n_position_task t ON t.id = o.id_task JOIN n_position p ON p.id = t.id_position WHERE o.due_date = '2026-11-04' AND t.auto_code IS NOT NULL ORDER BY p.id")->fetchAll();
    expectTa(count($occ) === 5, 'wystąpienia dnia dla stanowisk pracowni (bez konta ERP) : ' . count($occ));
    $cron = $container->get(TaskCronService::class);
    $cron->remind(new DateTimeImmutable('2026-11-06 10:00:00', new DateTimeZone(TaskCronService::TZ)));
    $bodies = $pdo->query('SELECT CONCAT(title, \' \', body) FROM app_notification')->fetchAll(PDO::FETCH_COLUMN);
    expectTa(!array_filter($bodies, fn($b) => preg_match('/Piekarz|Brygadzista|Pakowania|Kierowca|tablecie/u', $b)), 'tablety : bez przypomnień i bez przełożonego w dzwonku ' . json_encode($bodies, JSON_UNESCAPED_UNICODE));
    expectTa(count($bodies) >= 1, 'zadanie ERP (zebranie) : przełożony powiadomiony jak dotąd');

    // --- Kto co zrobił : dwa wiersze pracowni, dni bez wystąpienia (odczyt), grupa bez stanowiska --------------------
    $ex = $s->execution('week', 1, new DateTimeImmutable('2026-11-04'));
    $names = array_column($ex['rows'], 'name');
    expectTa($names === ['Kierownik produkcji', 'Produkcja — grupy (tablety)', 'Brygadzista · Pakowanie · Kierowca (tablety)'], 'wiersze : ' . json_encode($names, JSON_UNESCAPED_UNICODE));
    expectTa($ex['tablets'] === null, 'osobny wiersz grup zastąpiony');
    $groups = $ex['rows'][1];
    $mon = $groups['cells'][0];
    $byWho = [];
    foreach ($mon as $it) $byWho[$it['who']][] = $it['state'];
    expectTa($byWho['Piekarz'] === ['auto'] && $byWho['Piekarz wyrobów laminowanych'] === ['auto'], 'pn. : obie grupy zatwierdzone (bez wystąpień — odczyt) ' . json_encode($byWho, JSON_UNESCAPED_UNICODE));
    expectTa(isset($byWho['Grupa bez stanowiska']) && $mon[2]['title'] === 'Tarty' && $mon[2]['state'] === 'todo', 'grupa bez stanowiska (Tarty) w wierszu');
    expectTa(str_contains($groups['sub'], '+ Tarty'), 'opis : + Tarty');
    $other = $ex['rows'][2];
    $tueOther = [];
    foreach ($other['cells'][1] as $it) $tueOther[$it['who']] = $it['state'];
    expectTa($tueOther['Pracownik pakowania'] === 'auto' && $tueOther['Kierowca-dostawca'] === 'auto' && $tueOther['Brygadzista zmiany'] === 'late', 'wt. : pakowanie i trasa zrobione, zmiana spóźniona ' . json_encode($tueOther, JSON_UNESCAPED_UNICODE));
    $monOther = [];
    foreach ($other['cells'][0] as $it) $monOther[$it['who']] = $it['state'];
    expectTa($monOther['Brygadzista zmiany'] === 'auto', 'pn. : zmiana — wszystkie grupy zatwierdziły');
    $wedPiekarz = array_values(array_filter($groups['cells'][2], fn($it) => $it['who'] === 'Piekarz'))[0];
    expectTa($wedPiekarz['state'] === 'todo' && $wedPiekarz['occurrence'] > 0, 'śr. (dziś) : wystąpienie z crona, do zrobienia');
    expectTa($ex['tiles']['total'] > 0 && $ex['tiles']['done'] >= 6, 'kafelki liczą zadania tabletów');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "task_atelier_test: OK\n";
