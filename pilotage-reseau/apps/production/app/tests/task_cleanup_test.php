<?php
declare(strict_types=1);
/*
 * Lot 2 « ménage des tâches par poste » [228] : la migration 147 fusionne dans les tâches d'assistant les tâches du
 * Kierownik produkcji qui en sont des étapes (21 → 13), garde les pages secondaires (extra_links) et ne touche ni aux
 * autres postes ni aux tâches déjà fusionnées (rejouable) ; enregistrement et validation des « autres pages » par le plan
 * du poste, affichage sur la carte « Mój tydzień » — sur une base jetable.
 *
 *   php tests/task_cleanup_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 147 appliquée). La base erp_task_cleanup_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Organization\PositionTaskService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectTc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_task_cleanup_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "task_cleanup_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_position_task', 'n_task_category', 'n_task_occurrence', 'n_task_form', 'n_prod_param',
                 'n_prod_user_role', 'n_page_access', 'n_position_sheet', 'n_prod_employee', 'n_activity_view', 'n_inv', 'n_inv_line',
                 'n_margin_recalc_run', 'n_price_publication', 'n_buy_round', 'n_task_cron_run'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    // Avant la migration : la colonne extra_links n'existe pas encore (comme en ligne avant 147).
    $pdo->exec('ALTER TABLE n_position_task DROP COLUMN extra_links');
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'anna', 'Anna K.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin')");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1), (2, 'JAKOSC', 'Jakość', 'Qualité', 'erp', 2, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 3, '2026-01-01')");
    // Les 21 tâches du Kierownik produkcji (comme en ligne) + une tâche Jakość au même lien (ne doit pas bouger).
    $pdo->exec("INSERT INTO n_position_task (id, id_position, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, form_code, sort_order, active) VALUES
        (121, 3, 'Zatwierdź prognozę 13 tygodni', 'p', 'weekly', 1, 30, '/production/forecast', 'forecast_approved', NULL, 1, 1),
        (19, 3, 'Zebranie produkcyjne — protokół', 'z', 'weekly', 1, NULL, NULL, NULL, 'production_meeting', 2, 1),
        (100, 3, 'Zakupy tygodnia : zapytania ofertowe', 'a', 'weekly', 1, 40, '/workshop/buy', 'buy_sent', NULL, 3, 1),
        (8, 3, 'Plan produkcji na jutro', 'p', 'daily', NULL, NULL, '/workshop/team-sheet', NULL, NULL, 4, 1),
        (18, 3, 'Śledzenie produkcji', 's', 'daily', NULL, NULL, '/production/follow-up', 'production_day_closed', NULL, 5, 1),
        (16, 3, 'Sprawdzić przyjęcia surowców', 's', 'daily', NULL, 10, '/purchasing/nc', NULL, NULL, 6, 1),
        (21, 3, 'Wgrać faktury zakupowe', 'f', 'weekly', 2, 20, '/workshop/supply#tab=invoices', NULL, NULL, 7, 1),
        (14, 3, 'Zarejestrować otrzymane oferty cenowe', 'o', 'weekly', 2, 15, '/purchasing/offers', NULL, NULL, 8, 1),
        (101, 3, 'Znajdź dostawców', 'd', 'weekly', 3, 30, '/workshop/buy#tab=suppliers', NULL, NULL, 9, 1),
        (117, 3, 'Przelicz koszty i marże po fakturach', 'k', 'weekly', 3, 20, '/finances/price-wizard', 'costs_recalc', NULL, 10, 1),
        (20, 3, 'Zaimportować ostatnie ceny surowców z faktur', 'c', 'weekly', 3, 25, '/workshop/supply#tab=invoices&inv=wizard', 'inv_checked', NULL, 11, 1),
        (127, 3, 'Plan produkcji tygodnia', 'p', 'weekly', 4, 45, '/production/plan-wizard', 'week_plan_validated', NULL, 12, 1),
        (118, 3, 'Nowe ceny', 'n', 'weekly', 4, 30, '/finances/price-wizard#step=2', 'prices_planned', NULL, 13, 1),
        (119, 3, 'Sprawdź publikację cen', 'p', 'weekly', 5, 10, '/finances/price-wizard#step=3', NULL, NULL, 14, 1),
        (15, 3, 'Wprowadzić nowe ceny surowców z faktur i cenników', 'c', 'weekly', 5, 15, '/materials/prices', NULL, NULL, 15, 1),
        (7, 3, 'Przegląd kosztu receptur (wpływ cen surowców)', 'r', 'monthly', NULL, NULL, '/purchasing', NULL, NULL, 16, 1),
        (17, 3, 'Przeliczyć marże kategorii po zmianach cen', 'm', 'monthly', NULL, NULL, '/finances/category-margin', NULL, NULL, 17, 1),
        (13, 3, 'Przegląd kosztów surowców i możliwych oszczędności', 'k', 'monthly', NULL, NULL, '/purchasing', NULL, NULL, 18, 1),
        (6, 3, 'Przegląd wydajności i odpadów', 'w', 'quarterly', NULL, NULL, NULL, NULL, NULL, 19, 1),
        (12, 3, 'Zapytanie ofertowe na kluczowe surowce', 'z', 'quarterly', NULL, NULL, '/purchasing/tenders', NULL, NULL, 20, 1),
        (11, 3, 'Ocena dostawców (ceny, terminy, niezgodności)', 'o', 'quarterly', NULL, NULL, '/purchasing/nc', NULL, NULL, 21, 1),
        (4, 2, 'Analiza niezgodności', 'a', 'quarterly', NULL, NULL, '/purchasing/nc', NULL, NULL, 1, 1)");
    $pdo->exec("INSERT INTO n_task_occurrence (id_task, period_key, due_date, status, done_at) VALUES (14, '2026-W44', '2026-10-27', 'done', '2026-10-27 10:00:00')");

    $sql = file_get_contents(__DIR__ . '/../migrations/tfb/147_task_cleanup.sql');
    $run = function () use ($pdo, $sql) { foreach (MigrationRunner::splitStatements($sql) as $st) $pdo->exec($st); };
    $run();
    $active = fn() => array_map('intval', $pdo->query('SELECT id FROM n_position_task WHERE id_position = 3 AND active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN));
    expectTc($active() === [121, 19, 100, 8, 18, 16, 117, 20, 127, 118, 13, 6, 12], 'Kierownik : 21 → 13 zadań (' . implode(',', $active()) . ')');
    $t = array_column($pdo->query('SELECT id, title_pl, minutes, extra_links FROM n_position_task')->fetchAll(), null, 'id');
    expectTc((int) $t[100]['minutes'] === 85 && json_decode($t[100]['extra_links'], true) === ['/purchasing/offers', '/workshop/buy#tab=suppliers'] && str_starts_with($t[100]['title_pl'], 'Zakupy tygodnia : co kupić'), 'zakupy : + oferty + dostawcy (85 min)');
    expectTc((int) $t[20]['minutes'] === 60 && json_decode($t[20]['extra_links'], true) === ['/workshop/supply#tab=invoices', '/materials/prices'], 'faktury : + wgraj + ceny surowców (60 min)');
    expectTc(json_decode($t[117]['extra_links'], true) === ['/finances/category-margin', '/purchasing'] && (int) $t[117]['minutes'] === 20, 'koszty i marże : + marże kategorii + koszt receptur');
    expectTc((int) $t[118]['minutes'] === 40 && json_decode($t[118]['extra_links'], true) === ['/finances/price-wizard#step=3'], 'nowe ceny : + publikacja (40 min)');
    expectTc(json_decode($t[12]['extra_links'], true) === ['/purchasing/nc'] && str_starts_with($t[12]['title_pl'], 'Ocena dostawców i zapytanie'), 'kwartał : ocena dostawców + zapytanie ofertowe');
    expectTc($t[13]['extra_links'] === null && $t[4]['extra_links'] === null, 'przegląd kosztów surowców zostaje ; inne stanowisko bez zmian');
    expectTc((int) $pdo->query('SELECT active FROM n_position_task WHERE id = 4')->fetchColumn() === 1, 'Jakość : zadanie /purchasing/nc nietknięte');
    expectTc((int) $pdo->query("SELECT COUNT(*) FROM n_task_occurrence WHERE id_task = 14 AND status = 'done'")->fetchColumn() === 1, 'historia wystąpień zostaje');
    // Rejouable : un second passage ne change rien (titres, minutes, liens).
    $before = $pdo->query('SELECT id, active, title_pl, minutes, extra_links FROM n_position_task ORDER BY id')->fetchAll();
    $run();
    expectTc($pdo->query('SELECT id, active, title_pl, minutes, extra_links FROM n_position_task ORDER BY id')->fetchAll() === $before, 'drugie uruchomienie : bez zmian');

    // Plan du poste : « autres pages » relues, validées, enregistrées ; carte « Mój tydzień ».
    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $s = $builder->build()->get(PositionTaskService::class);
    $plan = $s->weekPlan(3, 1, new DateTimeImmutable('2026-11-04 12:00:00'));
    $rows = array_column($plan['tasks'], null, 'id');
    expectTc($rows[100]['extra_links'] === ['/purchasing/offers', '/workshop/buy#tab=suppliers'] && $rows[8]['extra_links'] === [], 'plan : inne strony');
    $with = fn(int $id, array $patch) => array_map(fn($r) => $r['id'] === $id ? array_merge($r, $patch) : $r, $plan['tasks']);
    $fail = function (array $tasks, string $what) use ($s) {
        try { $s->saveWeekPlan(3, ['tasks' => $tasks], 1); } catch (CustomException $e) { expectTc((int) $e->getCode() === 422, $what); return; }
        throw new RuntimeException('FAILED: ' . $what . ' (brak wyjątku)');
    };
    $fail($with(8, ['extra_links' => ['https://example.invalid']]), 'inna strona spoza ERP : 422');
    $fail($with(8, ['extra_links' => ['/a', '/b', '/c', '/d', '/e', '/f', '/g']]), 'więcej niż 6 stron : 422');
    $s->saveWeekPlan(3, ['tasks' => $with(8, ['extra_links' => ['/production/follow-up', '/production/follow-up', ' ']])], 1, new DateTimeImmutable('2026-11-04'));
    expectTc($pdo->query('SELECT extra_links FROM n_position_task WHERE id = 8')->fetchColumn() === '["/production/follow-up"]', 'zapis : bez duplikatów i pustych');
    $s->saveWeekPlan(3, ['tasks' => $with(8, ['extra_links' => []])], 1, new DateTimeImmutable('2026-11-04'));
    expectTc($pdo->query('SELECT extra_links FROM n_position_task WHERE id = 8')->fetchColumn() === null, 'zapis : pusta lista = NULL');
    $w = $s->week(2, new DateTimeImmutable('2026-11-02'));
    $buy = array_column($w['days'][1]['items'], null, 'task_id')[100];
    expectTc($buy['extra'] === [['link' => '/purchasing/offers', 'label' => 'Oferty'], ['link' => '/workshop/buy#tab=suppliers', 'label' => 'Dostawcy']], 'Mój tydzień : « też : Oferty · Dostawcy »');
    expectTc(count($w['days'][1]['items']) + count($w['days'][2]['items']) + count($w['days'][3]['items']) + count($w['days'][4]['items']) + count($w['days'][5]['items']) === 7, 'tydzień : 7 zadań tygodniowych (było 13)');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "task_cleanup_test: OK\n";
