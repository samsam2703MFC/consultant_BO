<?php
declare(strict_types=1);
/*
 * Lot 1 « tâche = assistant » [227] : règles de date du mois (wd3, d5, jours fériés PL), état des assistants rattachés
 * aux tâches (prévision, achats, expédition du jour, journée validée, clôture du mois, coûts de services, plans), carte
 * « Mój tydzień » (Na dziś, en cours, résultat chiffré, retards regroupés), enregistrement des champs (heure, règle du
 * mois, cron, rappels), cron (planifié, dû, exécuté, « zalicza się samo », échecs et reprises), rappels et escalade
 * (fenêtre), vue « Kto co zrobił » et « Przypomnij » — sur une base jetable.
 *
 *   php tests/task_wizards_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 146 appliquée). La base erp_task_wizards_test
 * est recréée puis supprimée. Les actions lourdes du cron (stany, zamknięcie miesiąca) sont remplacées par des doublures.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Organization\PositionTaskService;
use App\Services\Organization\TaskCronService;
use App\Services\Organization\TaskWizards;
use App\Services\Production\Cost\MonthCloseService;
use App\Services\Workshop\Product\ItemService;

function expectTz(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectTzFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectTz((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_task_wizards_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "task_wizards_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_position_task', 'n_task_category', 'n_task_occurrence', 'n_task_form', 'n_prod_param',
                 'n_prod_user_role', 'n_page_access', 'n_position_sheet', 'n_prod_employee', 'n_activity_view', 'n_inv', 'n_inv_line',
                 'n_margin_recalc_run', 'n_price_publication', 'n_buy_round', 'n_forecast_run', 'cost_month', 'n_service_month', 'n_prod_plan',
                 'n_logistics_plan_week', 'palets_delivery', 'n_prod_day_close', 'n_prod_group', 'n_task_cron_run', 'app_notification'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'anna', 'Anna K.'), (3, 'tomek', 'Tomasz W.'), (4, 'ola', 'Ola P.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'PRODUKCJA', 'Produkcja', 'Production', 'bi-gear', 10, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active, id_reports_to) VALUES
        (1, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef de production', 'erp', 1, 1, NULL),
        (2, 'LOGISTYKA', 'Logistyka i wysyłki', 'Logistique', 'erp', 2, 1, 1),
        (3, 'JAKOSC', 'Jakość', 'Qualité', 'erp', 3, 1, NULL)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 1, '2026-01-01'), (3, 2, '2026-01-01'), (4, 3, '2026-01-01')");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, due_weekday, weekdays, due_time, month_day, minutes, link_path, auto_code,
            cron_on, cron_freq, cron_days, cron_time, cron_action, cron_then, remind_time, escalate_days, sort_order, active) VALUES
        (1, 1, 1, 'Zatwierdź prognozę 13 tygodni', 'Prévision', 'weekly', 1, NULL, '12:00:00', NULL, 30, '/production/forecast', 'forecast_approved', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 10, 1),
        (2, 1, 1, 'Zakupy tygodnia', 'Achats', 'weekly', 1, NULL, NULL, NULL, 40, '/workshop/buy', 'buy_sent', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 20, 1),
        (3, 1, 1, 'Zamknięcie miesiąca', 'Clôture', 'monthly', NULL, NULL, NULL, 'wd3', 120, '/month-close', 'month_closed', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 30, 1),
        (4, 2, 1, 'Wysyłka dnia', 'Expédition', 'daily', NULL, NULL, NULL, NULL, 20, '/shipping/wizard', 'day_shipped', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 10, 1),
        (5, 1, 1, 'Śledzenie produkcji', 'Suivi', 'daily', NULL, NULL, '16:00:00', NULL, 15, '/production/follow-up', 'production_day_closed', 0, NULL, NULL, NULL, NULL, 'wait', NULL, 2, 40, 1),
        (6, 1, 1, 'Przelicz stany min. / maks.', 'Stocks', 'weekly', 1, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'weekly', '1', '05:30:00', 'stock_recalc', 'done', NULL, NULL, 50, 1),
        (7, 2, 1, 'Koszty usług miesiąca', 'Services', 'monthly', NULL, NULL, NULL, 'wd2', 30, '/finances/costs', 'services_closed', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 20, 1),
        (8, 2, 1, 'Plan logistyki 13 tygodni', 'Logistique', 'weekly', 4, NULL, NULL, NULL, 30, '/logistics/plan', 'logistics_plan_approved', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 30, 1),
        (9, 1, 1, 'Plan produkcji tygodnia', 'Plan', 'weekly', 4, NULL, NULL, NULL, 45, '/production/plan-wizard', 'week_plan_validated', 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 60, 1),
        (10, 1, 1, 'Raport → zamknięcie (automatycznie)', 'Rapport', 'monthly', NULL, NULL, NULL, 'd1', NULL, NULL, NULL, 1, 'monthly', 'd1', '06:00:00', 'month_prepare', 'wait', NULL, NULL, 70, 1),
        (11, 3, 1, 'Ocena dostawców', 'Fournisseurs', 'weekly', 1, NULL, NULL, NULL, 20, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'wait', '09:00:00', 2, 10, 1)");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order, active) VALUES (1, 'PIEKARZ', 'Piekarnia', 'Boulangerie', 1, 1), (2, 'ROGAL', 'Rogal', 'Viennoiserie', 2, 1), (3, 'STARA', 'Stara', 'Ancienne', 3, 0)");

    // Doublures : actions lourdes du cron (aucun recalcul réel, aucun mois réellement ouvert).
    $stock = new class {
        public int $calls = 0;
        public function applyStockSuggestions(array $ids, $ctx): array { $this->calls++; return ['applied' => 177, 'failed' => []]; }
    };
    $month = new class {
        public function start(string $ym): array { throw new RuntimeException('test : kalkulator niedostępny'); }
        public function status(string $ym): array { return ['done' => 4, 'next' => 'inventory']; }
    };
    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, ItemService::class => $stock, MonthCloseService::class => $month]);
    $container = $builder->build();
    $s = $container->get(PositionTaskService::class);
    $w = $container->get(TaskWizards::class);
    $cron = $container->get(TaskCronService::class);
    $warsaw = new DateTimeZone(TaskCronService::TZ);
    $at = fn(string $t) => new DateTimeImmutable($t, $warsaw);

    // --- Règles du mois : n-ième jour ouvré (fériés PL), n-ième jour, dernier jour ------------------------------------
    expectTz($s->monthDue('2026-11', 'wd3') === '2026-11-04', 'wd3 listopad 2026 = śr. 04.11 (1.11 niedziela)');
    expectTz($s->monthDue('2026-11', 'wd8') === '2026-11-12', 'wd8 listopad 2026 = 12.11 (11.11 święto)');
    expectTz($s->monthDue('2026-11', 'd5') === '2026-11-05' && $s->monthDue('2026-02', 'd31') === '2026-02-28', 'd5 ; d31 w lutym = ostatni');
    expectTz($s->monthDue('2026-11', 'zzz') === '2026-11-30', 'nieznana reguła = ostatni dzień');
    expectTz($s->period('monthly', new DateTimeImmutable('2026-11-20'), null, null, 'wd3') === ['key' => '2026-11', 'due' => '2026-11-04'], 'miesiąc : termin wd3');
    expectTz($s->period('quarterly', new DateTimeImmutable('2026-11-20'), null, null, 'wd1') === ['key' => '2026-Q4', 'due' => '2026-10-01'], 'kwartał : wd1 1. miesiąca');
    expectTz(PositionTaskService::monthRuleLabel('wd3') === '3. dzień roboczy' && PositionTaskService::monthRuleLabel('d5') === '5. dzień' && PositionTaskService::monthRuleLabel(null) === 'ostatni dzień', 'etykiety reguł');

    // --- État des assistants ----------------------------------------------------------------------------------------
    $wk = ['2026-11-02 00:00:00', '2026-11-09 00:00:00'];
    expectTz(TaskWizards::byLink('/shipping/wizard') === 'day_shipped' && TaskWizards::info('nope') === null, 'katalog : po linku');
    expectTz(!$w->state('forecast_approved', ...[...$wk, '2026-11-02'])['done'], 'prognoza : niezatwierdzona');
    $pdo->exec("INSERT INTO n_forecast_run (week_start, cartons, payload, approved_at, approved_by) VALUES ('2026-11-02', 10988, '{}', '2026-11-02 09:04:00', 2)");
    $st = $w->state('forecast_approved', ...[...$wk, '2026-11-02']);
    expectTz($st['done'] && str_contains($st['summary'], "10\u{00A0}988 kart.") && str_contains($st['summary'], '09:04') && str_contains($st['summary'], 'Anna K.'), 'prognoza : wynik liczbowy ' . $st['summary']);
    $pdo->exec("INSERT INTO n_buy_round (id, code, status, created_at) VALUES (1, 'ZT-45', 'draft', '2026-11-02 10:00:00')");
    $st = $w->state('buy_sent', ...[...$wk, '2026-11-02']);
    expectTz(!$st['done'] && $st['step'] === 2 && $st['steps'] === 3 && str_contains($st['step_label'], 'Komu'), 'zakupy : szkic = krok 2 z 3');
    $pdo->exec("INSERT INTO palets_delivery (client, delivery_date, status, id_customer) VALUES ('A', '2026-11-04', 3, 10), ('A', '2026-11-04', 3, 10), ('B', '2026-11-04', 1, 11)");
    $st = $w->state('day_shipped', ...[...$wk, '2026-11-04']);
    expectTz(!$st['done'] && $st['step'] === 1 && $st['step_label'] === '1 paleta czeka', 'wysyłka : 1 paleta czeka');
    $pdo->exec("UPDATE palets_delivery SET status = 3");
    $st = $w->state('day_shipped', ...[...$wk, '2026-11-04']);
    expectTz($st['done'] && $st['summary'] === '2 klientów · 3 palety z dokumentami', 'wysyłka : ' . $st['summary']);
    expectTz($w->state('day_shipped', ...[...$wk, '2026-11-05'])['summary'] === 'brak wysyłek tego dnia', 'wysyłka : dzień bez palet');
    $pdo->exec("INSERT INTO n_prod_day_close (work_date, id_group, closed_at) VALUES ('2026-11-03', 1, '2026-11-03 15:10:00'), ('2026-11-03', 2, '2026-11-03 15:40:00')");
    expectTz($w->state('production_day_closed', ...[...$wk, '2026-11-03'])['summary'] === '2 grupy zatwierdziły dzień', 'dzień zatwierdzony : 2 grupy');
    $nov = ['2026-11-01 00:00:00', '2026-12-01 00:00:00'];
    expectTz($w->state('month_closed', ...[...$nov, '2026-11-04'])['step'] === null, 'zamknięcie : miesiąc nierozpoczęty');
    $pdo->exec("INSERT INTO cost_month (id, year, month, status) VALUES (1, 2026, 10, 'OPEN')");
    $st = $w->state('month_closed', ...[...$nov, '2026-11-04']);
    expectTz(!$st['done'] && $st['step'] === 5 && $st['steps'] === 8 && $st['step_label'] === 'Inwentaryzacja — październik 2026', 'zamknięcie : krok 5 z 8');
    $pdo->exec("INSERT INTO n_service_month (ym, total, closed_at) VALUES ('2026-10', 12345.6, '2026-11-02 10:00:00')");
    expectTz($w->state('services_closed', ...[...$nov, '2026-11-03'])['summary'] === "październik 2026 · 12\u{00A0}346 zł", 'koszty usług : poprzedni miesiąc');
    $pdo->exec("INSERT INTO n_prod_plan (id, period_start, period_end, status, created_at) VALUES (1, '2026-11-09', '2026-11-13', 'proposed', '2026-11-03 08:00:00')");
    $st = $w->state('week_plan_validated', ...[...$wk, '2026-11-05']);
    expectTz(!$st['done'] && $st['step'] === 1 && $st['step_label'] === 'propozycja 09.11 – 13.11', 'plan tygodnia : propozycja = w toku');
    $pdo->exec("INSERT INTO n_logistics_plan_week (week_start, approved_at) VALUES ('2026-11-09', '2026-11-05 11:00:00')");
    expectTz($w->state('logistics_plan_approved', ...[...$wk, '2026-11-05'])['summary'] === '1 tydzień zatwierdzony', 'plan logistyki : 1 tydzień');

    // --- Mój tydzień : środa 04.11 (Anna, Kierownik produkcji) --------------------------------------------------------
    $wed = new DateTimeImmutable('2026-11-04');
    $pdo->exec("INSERT INTO n_task_occurrence (id_task, period_key, due_date, status) VALUES (5, '2026-10-30', '2026-10-30', 'open'), (5, '2026-11-02', '2026-11-02', 'open'), (5, '2026-11-03', '2026-11-03', 'open')");
    $view = $s->week(2, $wed);
    $mon = array_column($view['days'][1]['items'], null, 'title');
    $fc = $mon['Zatwierdź prognozę 13 tygodni'];
    expectTz($fc['done'] && $fc['auto_done'] && str_contains((string) $fc['summary'], "10\u{00A0}988") && $fc['wizard']['label'] === 'Prognoza 13 tygodni', 'prognoza : zaliczona sama z wynikiem');
    expectTz($fc['when'] === 'co tydzień · pon. 02.11 do 12:00' && $fc['due_time'] === '12:00', 'kiedy : ' . $fc['when']);
    $occ = $pdo->query("SELECT status, done_by, summary FROM n_task_occurrence WHERE id_task = 1 AND period_key = '2026-W45'")->fetch();
    expectTz($occ['status'] === 'done' && $occ['done_by'] === null && str_contains((string) $occ['summary'], "10\u{00A0}988"), 'wystąpienie : wynik zapisany (summary)');
    $buy = $mon['Zakupy tygodnia'];
    expectTz(!$buy['done'] && $buy['late'] && $buy['late_days'] === 2 && $buy['progress'] === ['step' => 2, 'steps' => 3, 'label' => 'Komu — szkic ZT-45'], 'zakupy : spóźnione 2 dni, krok 2 z 3');
    $cronItem = $mon['Przelicz stany min. / maks.'];
    expectTz($cronItem['cron'] !== null && $cronItem['cron']['time'] === '05:30' && $cronItem['cron']['then'] === 'done' && $cronItem['cron']['last'] === null, 'cron : karta automatyczna');
    $month3 = array_column($view['later'], null, 'title')['Zamknięcie miesiąca'];
    expectTz($month3['due'] === '2026-11-04' && $month3['progress']['step'] === 5 && $month3['when'] === 'co miesiąc · 3. dzień roboczy (04.11)', 'zamknięcie : termin wd3, krok 5');
    expectTz($view['totals']['running'] >= 2, 'w toku : zakupy i zamknięcie miesiąca');
    $today = array_column($view['today'], null, 'title');
    expectTz(isset($today['Zakupy tygodnia'], $today['Zamknięcie miesiąca']) && !isset($today['Zatwierdź prognozę 13 tygodni']), 'Na dziś : spóźnione i w toku ; zaliczone (nie dziś) poza listą');
    $follow = array_values(array_filter($view['today'], fn($i) => $i['title'] === 'Śledzenie produkcji' && $i['late']))[0] ?? null;
    expectTz($follow && $follow['late_days'] === 5 && $follow['late_count'] === 2, 'Na dziś : śledzenie spóźnione 2× (najstarsze 30.10, 5 dni) w jednym wierszu');
    expectTz($view['today'][0]['late'] && end($view['today'])['late'] === false, 'kolejność : spóźnione najpierw');
    $closed = $pdo->query("SELECT status FROM n_task_occurrence WHERE id_task = 5 AND due_date = '2026-11-03'")->fetchColumn();
    expectTz($closed === 'done', 'śledzenie 03.11 : zaliczone samo (2 grupy zatwierdziły dzień)');

    // --- Zadania stanowiska : zapis pól [227] -------------------------------------------------------------------------
    $plan = $s->weekPlan(1, 1, new DateTimeImmutable('2026-11-04 12:00:00'));
    expectTz($plan['superior'] === 'Administrator' && $s->weekPlan(2, 1)['superior'] === 'Kierownik produkcji', 'przełożony : Administrator / Kierownik produkcji');
    expectTz(isset($plan['cron_actions']['stock_recalc']) && count($plan['wizards']) === count(TaskWizards::CATALOG), 'katalog kreatorów i akcji');
    $fw = array_column($plan['wizards'], null, 'code')['forecast_approved'];
    expectTz($fw['used'][0]['mine'] === true, 'kreator przypięty tutaj');
    $rows = $plan['tasks'];
    $with = fn(int $id, array $patch) => array_map(fn($r) => $r['id'] === $id ? array_merge($r, $patch) : $r, $rows);
    expectTzFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['due_time' => '25:00'])], 1), 422, 'termin 25:00 : 422');
    expectTzFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(3, ['month_day' => 'x3'])], 1), 422, 'reguła x3 : 422');
    expectTzFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['escalate_days' => 0])], 1), 422, 'przełożony po 0 dniach : 422');
    expectTzFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['cron_on' => true, 'cron_freq' => 'weekly', 'cron_days' => '1', 'cron_time' => '06:10', 'cron_action' => ''])], 1), 422, 'cron bez akcji : 422');
    expectTzFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['cron_on' => true, 'cron_freq' => 'daily', 'cron_days' => '', 'cron_time' => '06:10', 'cron_action' => 'forecast_panel'])], 1), 422, 'cron codziennie bez dni : 422');
    expectTzFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['cron_on' => true, 'cron_freq' => 'monthly', 'cron_days' => '1', 'cron_time' => '06:10', 'cron_action' => 'forecast_panel'])], 1), 422, 'cron miesięcznie z dniem tygodnia : 422');
    $n = $s->saveWeekPlan(1, ['tasks' => $with(1, ['due_time' => '12:30', 'remind_time' => '08:00', 'escalate_days' => 3, 'cron_on' => true, 'cron_freq' => 'weekly',
        'cron_days' => '1', 'cron_time' => '06:10', 'cron_action' => 'forecast_panel', 'cron_then' => 'wait'])], 1, $wed);
    expectTz($n === count($rows), 'zapis planu');
    $t1 = $pdo->query('SELECT due_time, remind_time, escalate_days, cron_on, cron_freq, cron_days, cron_time, cron_action, cron_then FROM n_position_task WHERE id = 1')->fetch();
    expectTz($t1 === ['due_time' => '12:30:00', 'remind_time' => '08:00:00', 'escalate_days' => 3, 'cron_on' => 1, 'cron_freq' => 'weekly', 'cron_days' => '1', 'cron_time' => '06:10:00',
        'cron_action' => 'forecast_panel', 'cron_then' => 'wait'], 'pola zapisane : ' . json_encode($t1));
    $s->saveWeekPlan(1, ['tasks' => $with(1, ['cron_on' => false, 'remind_time' => '', 'escalate_days' => null])], 1, $wed);
    $t1 = $pdo->query('SELECT cron_on, remind_time, escalate_days FROM n_position_task WHERE id = 1')->fetch();
    expectTz($t1 === ['cron_on' => 0, 'remind_time' => null, 'escalate_days' => null], 'cron wyłączony, bez przypomnień');
    expectTz($pdo->query("SELECT month_day FROM n_position_task WHERE id = 3")->fetchColumn() === 'wd3', 'reguła miesiąca zostaje');

    // --- Cron : planifié, dû, exécuté, « zalicza się samo » -----------------------------------------------------------
    $task6 = $pdo->query('SELECT t.*, p.name_pl AS position_name FROM n_position_task t JOIN n_position p ON p.id = t.id_position WHERE t.id = 6')->fetch();
    expectTz(!$cron->scheduled($task6, $at('2026-11-02 05:29')) && $cron->scheduled($task6, $at('2026-11-02 05:31')) && $cron->scheduled($task6, $at('2026-11-04 01:00')), 'co tydzień pn. 05:30 : po godzinie (i nadrabia później w tygodniu)');
    expectTz(TaskCronService::periodKey('weekly', $at('2026-11-04 10:00')) === '2026-W45' && TaskCronService::periodKey('quarterly', $at('2026-11-04 10:00')) === '2026-Q4', 'okres crona');
    expectTz($cron->due($task6, $at('2026-11-02 05:31')), 'do uruchomienia');
    $r = $cron->run($task6, $at('2026-11-02 05:31'));
    expectTz($r['ok'] && $r['summary'] === '177 produktów przeliczonych' && $stock->calls === 1, 'uruchomione : ' . json_encode($r));
    $o6 = $pdo->query("SELECT status, done_by, summary, note FROM n_task_occurrence WHERE id_task = 6 AND period_key = '2026-W45'")->fetch();
    expectTz($o6['status'] === 'done' && $o6['done_by'] === null && $o6['summary'] === '177 produktów przeliczonych' && str_contains($o6['note'], 'Automatycznie (cron) 05:31'), 'zalicza się samo (then = done)');
    expectTz(!$cron->due($task6, $at('2026-11-03 07:00')), 'jedno udane uruchomienie na tydzień');
    $view = $s->week(2, $wed);
    $c6 = array_column($view['days'][1]['items'], null, 'title')['Przelicz stany min. / maks.'];
    expectTz($c6['done'] && $c6['auto_done'] && $c6['cron']['last']['ok'] === true && $c6['summary'] === '177 produktów przeliczonych', 'karta : wykonane przez system');

    // Échec, reprise après 30 min, 3 essais au plus, cloche « danger ».
    $task10 = $pdo->query('SELECT t.*, p.name_pl AS position_name FROM n_position_task t JOIN n_position p ON p.id = t.id_position WHERE t.id = 10')->fetch();
    expectTz($cron->due($task10, $at('2026-11-01 06:05')), 'miesięcznie d1 06:00 : do uruchomienia');
    $r = $cron->run($task10, $at('2026-11-01 06:05'));
    expectTz(!$r['ok'] && str_contains($r['error'], 'kalkulator niedostępny'), 'błąd zapisany');
    $fail = $pdo->query("SELECT severity, link_url FROM app_notification WHERE type = 'task_cron_failed'")->fetchAll();
    expectTz(count($fail) === 1 && $fail[0]['severity'] === 'danger' && $fail[0]['link_url'] === '/organization/execution', 'dzwonek : błąd automatyczny');
    $pdo->exec("UPDATE n_task_cron_run SET started_at = '2026-11-01 06:05:00' WHERE id_task = 10");
    expectTz(!$cron->due($task10, $at('2026-11-01 06:20')), 'ponowienie dopiero po 30 min');
    expectTz($cron->due($task10, $at('2026-11-01 06:40')), 'ponowienie po 30 min');
    $pdo->exec("INSERT INTO n_task_cron_run (id_task, period_key, action, started_at, finished_at, ok) VALUES (10, '2026-11', 'month_prepare', '2026-11-01 06:40:00', '2026-11-01 06:40:01', 0), (10, '2026-11', 'month_prepare', '2026-11-01 07:15:00', '2026-11-01 07:15:01', 0)");
    expectTz(!$cron->due($task10, $at('2026-11-02 10:00')), 'po 3 błędach : stop do następnego miesiąca');
    expectTz($cron->due($task10, $at('2026-12-01 06:05')), 'następny miesiąc : znowu');

    // --- Rappels et escalade (fenêtre) ------------------------------------------------------------------------------
    $pdo->exec("INSERT INTO n_task_occurrence (id_task, period_key, due_date, status) VALUES (11, '2026-W43', '2026-10-19', 'open')"); // 16 dni : poza oknem
    [$rem] = $cron->remind($at('2026-11-04 08:59'));
    expectTz($rem === 0, 'przed 09:00 : bez przypomnienia');
    $cron->remind($at('2026-11-04 09:05'));
    $notes = $pdo->query("SELECT type, title, body FROM app_notification WHERE type IN ('task_reminder', 'task_late') ORDER BY id")->fetchAll();
    $titles = array_column($notes, 'title');
    expectTz(in_array('Dziś termin : Zamknięcie miesiąca', $titles, true), 'przypomnienie w dniu terminu (09:00) : ' . json_encode($titles, JSON_UNESCAPED_UNICODE));
    expectTz(in_array('Spóźnione 2 dni : Zakupy tygodnia', $titles, true), 'przełożony po 2 dniach');
    $late = array_values(array_filter($notes, fn($n) => $n['title'] === 'Spóźnione 2 dni : Zakupy tygodnia'))[0];
    expectTz(str_contains($late['body'], 'Kierownik produkcji (Anna K.)') && str_contains($late['body'], 'przełożony : Administrator'), 'treść : stanowisko, osoba, przełożony');
    expectTz(!array_filter($titles, fn($t) => str_contains($t, 'Ocena dostawców')), '16 dni spóźnienia : poza oknem (bez dzwonka)');
    expectTz($pdo->query("SELECT escalated_at IS NOT NULL FROM n_task_occurrence WHERE id_task = 2 AND period_key = '2026-W45'")->fetchColumn() == 1, 'spóźnienie oznaczone');
    expectTz($cron->remind($at('2026-11-04 10:00')) === [0, 0], 'raz na wystąpienie');

    // --- Kto co zrobił --------------------------------------------------------------------------------------------
    expectTzFail(fn() => $s->execution('week', 2, $wed), 403, 'Kto co zrobił : bez uprawnienia 403');
    $ex = $s->execution('week', 1, $wed);
    expectTz(count($ex['columns']) === 5 && $ex['columns'][2]['today'] && $ex['columns'][0]['label'] === 'Pn 02.11', 'tydzień : 5 kolumn, dziś środa');
    $byName = array_column($ex['rows'], null, 'name');
    expectTz(isset($byName['Kierownik produkcji'], $byName['Logistyka i wysyłki'], $byName['Jakość']) && $byName['Kierownik produkcji']['people'] === ['Anna K.'], 'wiersze stanowisk z osobami');
    $monCell = array_column($byName['Kierownik produkcji']['cells'][0], null, 'title');
    expectTz($monCell['Zatwierdź prognozę 13 tygodni']['state'] === 'auto' && $monCell['Zakupy tygodnia']['state'] === 'late' && $monCell['Przelicz stany min. / maks.']['state'] === 'auto', 'pn. : zaliczone samo, spóźnione');
    expectTz($monCell['Zakupy tygodnia']['escalated'] === true && $monCell['Zakupy tygodnia']['late_days'] === 2, 'spóźnione : przełożony powiadomiony');
    expectTz($ex['tiles']['late'] >= 1 && $ex['tiles']['escalated'] >= 1 && $ex['tiles']['done'] >= 2, 'kafelki');
    expectTz($ex['tablets']['groups'] === 2 && $ex['tablets']['cells'][1][0]['state'] === 'auto' && $ex['tablets']['cells'][0][0]['state'] === 'todo' && $ex['tablets']['cells'][3][0]['state'] === 'plan', 'tablety : wt. zatwierdzone, pn. nie, czw. zaplanowane (grupa nieaktywna pominięta)');
    expectTz(count($ex['system'][0]) === 1 && $ex['system'][0][0]['state'] === 'auto', 'System : uruchomienie w poniedziałek');
    $lateTitles = array_column($ex['late'], 'title');
    expectTz(count($lateTitles) === count(array_unique($lateTitles)), 'spóźnione : jedno zadanie raz');
    $month = $s->execution('month', 1, $wed);
    expectTz($month['view'] === 'month' && str_starts_with($month['columns'][0]['label'], 'tydz. ') && end($month['columns'])['to'] === '2026-11-30', 'miesiąc : tygodnie listopada');
    $day = $s->execution('day', 1, $wed);
    expectTz(count($day['columns']) === 1 && $day['columns'][0]['key'] === '2026-11-04', 'dziś : jedna kolumna');
    $buyOcc = (int) $pdo->query("SELECT id FROM n_task_occurrence WHERE id_task = 2 AND period_key = '2026-W45'")->fetchColumn();
    expectTzFail(fn() => $s->remind($buyOcc, 2), 403, 'Przypomnij : bez uprawnienia 403');
    $s->remind($buyOcc, 1);
    $s->remind($buyOcc, 1);
    $manual = $pdo->query("SELECT COUNT(*) FROM app_notification WHERE type = 'task_reminder' AND title = 'Przypomnienie : Zakupy tygodnia'")->fetchColumn();
    expectTz((int) $manual === 1, 'Przypomnij : jeden dzwonek dziennie');
    $doneOcc = (int) $pdo->query("SELECT id FROM n_task_occurrence WHERE id_task = 1 AND period_key = '2026-W45'")->fetchColumn();
    expectTzFail(fn() => $s->remind($doneOcc, 1), 422, 'Przypomnij : zadanie zrobione 422');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "task_wizards_test: OK\n";
