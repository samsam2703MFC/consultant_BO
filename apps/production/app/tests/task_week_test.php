<?php
declare(strict_types=1);
/*
 * Feuille de route de la semaine [219] : Dzisiaj « Mój tydzień » (jours, minutes, liens, bandeau des tâches quotidiennes,
 * retards, mois et trimestre, validation automatique par les assistants) et plan de la semaine du poste (administrateur :
 * jours → fréquence, minutes, liens, temps réel du rejestrator, enregistrement, copie), sur une base jetable.
 *
 *   php tests/task_week_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 139 appliquée). La base erp_task_week_test
 * est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Organization\PositionTaskService;

function expectTw(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectTwFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectTw((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_task_week_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "task_week_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_position_task', 'n_task_category', 'n_task_occurrence', 'n_task_form', 'n_prod_param',
                 'n_prod_user_role', 'n_page_access', 'n_position_sheet', 'n_prod_employee', 'n_activity_view', 'n_inv', 'n_inv_line',
                 'n_margin_recalc_run', 'n_price_publication', 'n_buy_round'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'anna', 'Anna K.'), (3, 'tomek', 'Tomasz W.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES
        (1, 'CENY', 'Ceny i oferty', 'Prix', 'bi-tag', 10, 1), (2, 'UPLOAD', 'Dokumenty i upload', 'Upload', 'bi-cloud-upload', 20, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES
        (1, 'ZAKUPY', 'Specjalista ds. zakupów', 'Achats', 'erp', 1, 1), (2, 'LOGISTYKA', 'Logistyka', 'Logistique', 'erp', 2, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 1, '2026-01-01'), (3, 2, '2026-01-01')");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, weekdays, link_path, auto_code, sort_order, active) VALUES
        (1, 1, 1, 'Zatwierdź faktury tygodnia', 'Valider les factures', 'weekly', 3, 25, NULL, '/workshop/supply#tab=invoices&inv=wizard', 'inv_checked', 10, 1),
        (2, 1, 2, 'Sprawdź przyjęcia', 'Réceptions', 'daily', NULL, 10, NULL, '/purchasing/nc', NULL, 20, 1),
        (3, 1, 1, 'Oferty cenowe', 'Offres', 'daily', NULL, 15, '1,4', '/purchasing/offers', NULL, 30, 1),
        (4, 1, 1, 'Zakupy tygodnia', 'Achats de la semaine', 'weekly', 1, 40, NULL, '/workshop/buy', 'buy_sent', 40, 1),
        (5, 1, 1, 'Przegląd kosztów', 'Revue des coûts', 'monthly', NULL, NULL, NULL, '/purchasing', NULL, 50, 1),
        (6, 1, 1, 'Nowe ceny', 'Nouveaux prix', 'weekly', 4, 30, NULL, '/finances/price-wizard#step=2', 'prices_planned', 60, 1),
        (7, 1, 1, 'Przelicz koszty', 'Recalculer', 'weekly', 3, 20, NULL, '/finances/price-wizard', 'costs_recalc', 70, 1),
        (8, 1, 1, 'Stare zadanie', 'Ancienne', 'weekly', 2, 5, NULL, NULL, NULL, 80, 0),
        (20, 2, NULL, 'Wysyłki dnia', 'Expéditions', 'daily', NULL, 30, NULL, '/shipments', NULL, 10, 1),
        (21, 2, NULL, 'Zakupy tygodnia', 'Achats', 'weekly', 2, 10, NULL, NULL, NULL, 20, 1)");
    // Zaległe z poprzedniego tygodnia (tydzień 44) : zakupy tygodnia niezrobione.
    $pdo->exec("INSERT INTO n_task_occurrence (id_task, period_key, due_date, status) VALUES (4, '2026-W44', '2026-10-26', 'open')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $s = $builder->build()->get(PositionTaskService::class);

    // --- Okres zadania codziennego z dniami (1,4 = pon. i czw.) ----------------------------------------------
    expectTw($s->period('daily', new DateTimeImmutable('2026-11-02'), null, '1,4') === ['key' => '2026-11-02', 'due' => '2026-11-02'], 'codziennie 1,4 : poniedziałek');
    expectTw($s->period('daily', new DateTimeImmutable('2026-11-03'), null, '1,4') === null, 'codziennie 1,4 : wtorek — brak');
    expectTw($s->period('daily', new DateTimeImmutable('2026-11-07'), null, null) === null, 'codziennie : sobota — brak');
    expectTw($s->period('weekly', new DateTimeImmutable('2026-11-04'), 3) === ['key' => '2026-W45', 'due' => '2026-11-04'], 'tydzień 45 : środa');

    // --- Mój tydzień : środa 04.11 ------------------------------------------------------------------------------
    $wed = new DateTimeImmutable('2026-11-04');
    $w = $s->week(2, $wed);
    expectTw($w['week'] === ['number' => 45, 'from' => '2026-11-02', 'to' => '2026-11-06'], 'tydzień 45 : 02.11–06.11 (dni robocze)');
    expectTw(array_keys($w['days']) === [1, 2, 3, 4, 5] && $w['days'][3]['today'] && $w['days'][1]['past'] && !$w['days'][4]['past'], 'pięć dni, dziś środa');
    $titles = fn(int $n) => array_map(fn($i) => $i['title'], $w['days'][$n]['items']);
    expectTw($titles(1) === ['Oferty cenowe', 'Zakupy tygodnia'] && $titles(2) === [] && $titles(3) === ['Zatwierdź faktury tygodnia', 'Przelicz koszty']
        && $titles(4) === ['Oferty cenowe', 'Nowe ceny'] && $titles(5) === [], 'zadania dzień po dniu (codzienne 1,4 w kolumnach, nieaktywne pominięte)');
    expectTw(count($w['strip']) === 1 && $w['strip'][0]['task']['title'] === 'Sprawdź przyjęcia' && array_keys($w['strip'][0]['slots']) === [1, 2, 3, 4, 5], 'codzienne na wszystkie dni : pasek z 5 dniami');
    $strip = array_map(fn($x) => $x['status'], $w['strip'][0]['slots']);
    expectTw($strip === [1 => 'none', 2 => 'none', 3 => 'open', 4 => 'planned', 5 => 'planned'] && $w['strip'][0]['item']['id'] > 0, 'pasek : przeszłe bez wystąpienia, dziś otwarte, dalej zaplanowane');
    $mon = array_column($w['days'][1]['items'], null, 'title');
    expectTw($mon['Zakupy tygodnia']['status'] === 'open' && $mon['Zakupy tygodnia']['late'] && $mon['Zakupy tygodnia']['button'] === 'Zakupy tygodnia'
        && $mon['Oferty cenowe']['status'] === 'none' && !$mon['Oferty cenowe']['late'], 'poniedziałek : zakupy tygodnia zaległe, oferty bez wystąpienia');
    $wedItems = array_column($w['days'][3]['items'], null, 'title');
    expectTw($wedItems['Zatwierdź faktury tygodnia']['link'] === '/workshop/supply#tab=invoices&inv=wizard' && $wedItems['Zatwierdź faktury tygodnia']['button'] === 'Kreator faktur'
        && $wedItems['Zatwierdź faktury tygodnia']['color'] === '#8e1f2f' && $wedItems['Zatwierdź faktury tygodnia']['auto'] !== null, 'karta : link do kreatora faktur, przycisk, kolor kategorii, zalicza się samo');
    expectTw(count($w['overdue']) === 1 && $w['overdue'][0]['due'] === '2026-10-26' && $w['overdue'][0]['title'] === 'Zakupy tygodnia', 'zaległe z tygodnia 44');
    expectTw(count($w['later']) === 1 && $w['later'][0]['due'] === '2026-11-30' && $w['later'][0]['button'] === 'Koszty surowców', 'miesiąc : przegląd kosztów do 30.11');
    $t = $w['totals'];
    expectTw($t['total'] === 11 && $t['minutes'] === 195 && $t['done'] === 0 && $t['share'] === 8, 'razem : 11 zadań, 195 min = 8 % z 40 h');
    expectTw($t['today']['count'] === 3 && $t['today']['minutes'] === 55 && $t['today_name'] === 'środa' && $t['late'] === 1 && $t['overdue'] === 1, 'dziś : 3 zadania, 55 min ; zaległe 1 + 1');
    expectTw($w['days'][1]['minutes'] === 65 && $w['days'][3]['minutes'] === 55 && $w['days'][4]['minutes'] === 55 && $w['days'][5]['minutes'] === 10, 'minuty dni (z paskiem codziennym)');

    // Zrobione ręcznie.
    $s->setStatus((int) $wedItems['Zatwierdź faktury tygodnia']['id'], ['status' => 'done'], 2);
    $w = $s->week(2, $wed);
    $f = array_column($w['days'][3]['items'], null, 'title')['Zatwierdź faktury tygodnia'];
    expectTw($f['done'] && $f['done_by'] === 'Anna K.' && !$f['auto_done'] && $w['totals']['done'] === 1 && $w['totals']['done_minutes'] === 25 && $w['days'][3]['done_minutes'] === 25, 'zrobione : Anna K., 25 min');
    $s->setStatus((int) $f['id'], ['status' => 'open'], 2);

    // --- Zaliczenie automatyczne -------------------------------------------------------------------------------
    // Koszty przeliczone we wtorek (tydzień 45) → « Przelicz koszty » zrobione samo ; przeliczenie z tygodnia 44 nie liczy się.
    $pdo->exec("INSERT INTO n_margin_recalc_run (run_at, run_by, recipes, products, changed) VALUES ('2026-10-29 10:00:00', 1, 1, 1, 0)");
    $w = $s->week(2, $wed);
    expectTw(array_column($w['days'][3]['items'], null, 'title')['Przelicz koszty']['status'] === 'open', 'przeliczenie z poprzedniego tygodnia : nadal otwarte');
    $pdo->exec("INSERT INTO n_margin_recalc_run (run_at, run_by, recipes, products, changed) VALUES ('2026-11-03 09:30:00', 1, 10, 20, 3)");
    $w = $s->week(2, $wed);
    $k = array_column($w['days'][3]['items'], null, 'title')['Przelicz koszty'];
    expectTw($k['done'] && $k['auto_done'] && $k['done_by'] === null, 'koszty przeliczone we wtorek : zaliczone automatycznie');
    $note = $pdo->query("SELECT note, done_by FROM n_task_occurrence WHERE id = " . (int) $k['id'])->fetch();
    expectTw(str_starts_with((string) $note['note'], 'Automatycznie') && $note['done_by'] === null, 'notatka « Automatycznie », bez osoby');
    // Faktury : sprawdzona pozycja w tygodniu, ale inna czeka → otwarte ; po sprawdzeniu wszystkiego → zrobione.
    $pdo->exec("INSERT INTO n_inv (id, source, kind, status, number, issue_date) VALUES (1, 'ksef', 'goods', 'open', 'FV/1', '2026-11-02')");
    $pdo->exec("INSERT INTO n_inv_line (id, id_inv, check_status, checked_at) VALUES (1, 1, 'checked', '2026-11-03 08:00:00'), (2, 1, NULL, NULL)");
    $w = $s->week(2, $wed);
    expectTw(array_column($w['days'][3]['items'], null, 'title')['Zatwierdź faktury tygodnia']['status'] === 'open', 'pozycja faktury czeka : nie zalicza się');
    $pdo->exec("UPDATE n_inv_line SET check_status = 'checked', checked_at = '2026-11-04 09:00:00' WHERE id = 2");
    $w = $s->week(2, $wed);
    expectTw(array_column($w['days'][3]['items'], null, 'title')['Zatwierdź faktury tygodnia']['auto_done'], 'wszystkie pozycje sprawdzone : zaliczone');
    // Ceny : publikacja anulowana nie liczy się ; zadanie czwartkowe w przyszłości (okres = tydzień) zalicza się po zaplanowaniu.
    $pdo->exec("INSERT INTO n_price_publication (effective_date, status, created_at) VALUES ('2026-11-16', 'cancelled', '2026-11-04 10:00:00')");
    $w = $s->week(2, $wed);
    expectTw(array_column($w['days'][4]['items'], null, 'title')['Nowe ceny']['status'] === 'open', 'publikacja anulowana : otwarte');
    $pdo->exec("INSERT INTO n_price_publication (effective_date, status, created_at) VALUES ('2026-11-16', 'planned', '2026-11-04 11:00:00')");
    $w = $s->week(2, $wed);
    expectTw(array_column($w['days'][4]['items'], null, 'title')['Nowe ceny']['auto_done'], 'ceny zaplanowane w środę : czwartkowe zadanie zrobione');
    // Zakupy : runda wysłana w tygodniu 44 zalicza zaległe z tygodnia 44, nie tydzień 45.
    $pdo->exec("INSERT INTO n_buy_round (id_user, sent_at) VALUES (2, '2026-10-27 12:00:00')");
    $w = $s->week(2, $wed);
    expectTw($w['overdue'] === [] && array_column($w['days'][1]['items'], null, 'title')['Zakupy tygodnia']['status'] === 'open', 'runda z tygodnia 44 : zaległe zamknięte, tydzień 45 otwarty');
    expectTw($w['totals']['done'] === 3 && $w['totals']['overdue'] === 0, 'razem 3 zrobione (faktury, koszty, ceny)');

    // « Moje zadania » : zadanie codzienne 1,4 we wtorek bez wystąpienia.
    $board = $s->myTasks(2, new DateTimeImmutable('2026-11-03'));
    $items = [];
    foreach ($board['groups'] as $g) foreach ($g['items'] as $it) $items[$it['task']['title_pl']] = $it;
    expectTw($items['Oferty cenowe']['occurrence'] === null && $items['Sprawdź przyjęcia']['occurrence'] !== null, 'Moje zadania wtorek : oferty (pon., czw.) bez wystąpienia');

    // --- Plan tygodnia (administrator) ---------------------------------------------------------------------------
    expectTwFail(fn() => $s->weekPlan(1, 2), 403, 'plan : tylko administrator');
    expectTwFail(fn() => $s->weekPlan(99, 1), 404, 'plan : nieznane stanowisko');
    $now = new DateTimeImmutable('2026-11-04 12:00:00');
    $pdo->exec("INSERT INTO n_activity_view (id_session, id_user, route, path, started_at, last_seen_at, active_s) VALUES
        (1, 2, '/purchasing/offers', '/purchasing/offers', '2026-10-20 10:00:00', '2026-10-20 11:00:00', 3600),
        (1, 2, '/workshop/buy', '/workshop/buy', '2026-10-21 10:00:00', '2026-10-21 14:00:00', 14400),
        (1, 2, '/finances/price-wizard', '/finances/price-wizard', '2026-10-22 10:00:00', '2026-10-22 10:20:00', 1200),
        (1, 2, '/purchasing/nc/{id}', '/purchasing/nc/12', '2026-10-23 10:00:00', '2026-10-23 10:40:00', 2400),
        (1, 2, '/purchasing/offers', '/purchasing/offers', '2026-09-01 10:00:00', '2026-09-01 18:00:00', 28800),
        (2, 3, '/purchasing/offers', '/purchasing/offers', '2026-10-24 10:00:00', '2026-10-24 18:00:00', 28800)");
    $plan = $s->weekPlan(1, 1, $now);
    $rows = array_column($plan['tasks'], null, 'id');
    expectTw(count($plan['tasks']) === 7 && !isset($rows[8]) && $plan['tasks'][0]['id'] === 4, 'plan : 7 zadań aktywnych, poniedziałek na początku');
    expectTw($rows[3]['days'] === [1, 4] && $rows[2]['days'] === [1, 2, 3, 4, 5] && $rows[1]['days'] === [3] && $rows[5]['period'] === 'monthly' && $rows[5]['days'] === [], 'dni : 1,4 / codziennie / środa / co miesiąc');
    expectTw($plan['load']['days'] === [1 => 65, 2 => 10, 3 => 55, 4 => 55, 5 => 10] && $plan['load']['week'] === 195 && $plan['load']['share'] === 8, 'obciążenie dni i tygodnia');
    expectTw($rows[3]['real'] === 8 && $rows[3]['real_state'] === 'ok', 'oferty : 3600 s ÷ 8 wykonań = 7,5 → 8 min (plan 15) ; poza 28 dniami i inne stanowisko pominięte');
    expectTw($rows[4]['real'] === 60 && $rows[4]['real_state'] === 'over', 'zakupy tygodnia : 60 min na 40 planu → ponad 125 %');
    expectTw($rows[6]['real'] === 3 && $rows[7]['real'] === 2 && $rows[6]['shared'] && $rows[7]['shared'], 'kreator cen : 1200 s dzielone 30 : 20 (ta sama strona), ÷ 4');
    expectTw($rows[2]['real'] === 2 && $rows[5]['real'] === null, '/purchasing/nc/12 → zadanie NC (najdłuższy prefiks), nie koszty surowców');
    expectTw($rows[1]['real'] === null && $plan['users'][0]['login'] === 'anna' && count($plan['users']) === 1, 'brak czasu : — ; osoby stanowiska');
    expectTw(count($plan['others']) === 1 && $plan['others'][0]['name'] === 'Logistyka' && isset($plan['links']['/finances/price-wizard#step=2']) && $plan['link_auto']['/workshop/buy'] === 'buy_sent', 'inne stanowiska, katalog linków, zaliczenie wg linku');

    // Zapis planu : walidacja.
    $base = array_map(fn($r) => ['id' => $r['id'], 'title_pl' => $r['title_pl'], 'category_id' => $r['category_id'], 'period' => $r['period'], 'days' => $r['days'],
        'minutes' => $r['minutes'], 'link_path' => $r['link_path'], 'auto_code' => $r['auto_code']], $plan['tasks']);
    $with = function (int $id, array $changes) use ($base) {
        return array_map(fn($r) => $r['id'] === $id ? array_merge($r, $changes) : $r, $base);
    };
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $base], 2), 403, 'zapis : tylko administrator');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['days' => []])], 1), 422, 'tydzień bez dnia : 422');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['minutes' => 999])], 1), 422, 'minuty 999 : 422');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['link_path' => 'https://example.invalid'])], 1), 422, 'link spoza ERP : 422');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['auto_code' => 'magic'])], 1), 422, 'nieznane zaliczenie : 422');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $with(1, ['title_pl' => '  '])], 1), 422, 'bez nazwy : 422');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => array_merge($base, [['id' => 20, 'title_pl' => 'X', 'period' => 'week', 'days' => [1]]])], 1), 404, 'zadanie innego stanowiska : 404');
    expectTwFail(fn() => $s->saveWeekPlan(1, ['tasks' => $base, 'removed' => [21]], 1), 404, 'usunięcie zadania innego stanowiska : 404');
    expectTw((int) $pdo->query('SELECT minutes FROM n_position_task WHERE id = 1')->fetchColumn() === 25, 'po błędach plan bez zmian');

    // Zapis : przyjęcia raz w tygodniu (środa), oferty codziennie, zakupy pon. + śr., przegląd co kwartał, nowe zadanie, « Nowe ceny » usunięte.
    $todayOcc = (int) $pdo->query("SELECT id FROM n_task_occurrence WHERE id_task = 2 AND period_key = '2026-11-04'")->fetchColumn();
    expectTw($todayOcc > 0, 'wystąpienie przyjęć z dziś');
    $tasks = array_values(array_filter($base, fn($r) => $r['id'] !== 6));
    $tasks = array_map(fn($r) => match ($r['id']) {
        2 => array_merge($r, ['days' => [3], 'minutes' => 30]),
        3 => array_merge($r, ['days' => [5, 1, 2, 3, 4, 4]]),
        4 => array_merge($r, ['days' => [3, 1], 'title_pl' => 'Zakupy tygodnia (2×)']),
        5 => array_merge($r, ['period' => 'quarterly', 'minutes' => '60']),
        default => $r,
    }, $tasks);
    $tasks[] = ['id' => null, 'title_pl' => 'Ceny surowców z cenników', 'category_id' => 2, 'period' => 'week', 'days' => [5], 'minutes' => 12, 'link_path' => '/materials/prices', 'auto_code' => null];
    expectTw($s->saveWeekPlan(1, ['tasks' => $tasks, 'removed' => [6]], 1, $wed) === 7, 'zapis : 7 zadań');
    $db = array_column($pdo->query('SELECT * FROM n_position_task WHERE id_position = 1')->fetchAll(), null, 'title_pl');
    expectTw($db['Sprawdź przyjęcia']['frequency'] === 'weekly' && (int) $db['Sprawdź przyjęcia']['due_weekday'] === 3 && (int) $db['Sprawdź przyjęcia']['minutes'] === 30, '1 dzień → co tydzień w środę, 30 min');
    expectTw($db['Oferty cenowe']['frequency'] === 'daily' && $db['Oferty cenowe']['weekdays'] === null, 'wszystkie dni robocze → codziennie (bez listy dni)');
    expectTw($db['Zakupy tygodnia (2×)']['frequency'] === 'daily' && $db['Zakupy tygodnia (2×)']['weekdays'] === '1,3' && $db['Zakupy tygodnia (2×)']['title_fr'] === 'Achats de la semaine', '2 dni → codziennie 1,3 ; nazwa FR własna zostaje');
    expectTw($db['Przegląd kosztów']['frequency'] === 'quarterly' && (int) $db['Przegląd kosztów']['minutes'] === 60 && $db['Przegląd kosztów']['due_weekday'] === null, 'co kwartał, 60 min');
    expectTw((int) $db['Nowe ceny']['active'] === 0 && (int) $db['Stare zadanie']['active'] === 0, '« Nowe ceny » usunięte z planu (nieaktywne)');
    $new = $db['Ceny surowców z cenników'];
    expectTw($new['frequency'] === 'weekly' && (int) $new['due_weekday'] === 5 && $new['title_fr'] === 'Ceny surowców z cenników' && (int) $new['id_category'] === 2 && (int) $new['active'] === 1, 'nowe zadanie : piątek, FR = PL');
    expectTw((int) $db['Zakupy tygodnia (2×)']['sort_order'] === 10 && (int) $new['sort_order'] === 70, 'kolejność ekranu');
    expectTw(!$pdo->query("SELECT 1 FROM n_task_occurrence WHERE id = {$todayOcc}")->fetch(), 'zmiana dni : otwarte wystąpienie od poniedziałku usunięte');
    expectTw((bool) $pdo->query("SELECT 1 FROM n_task_occurrence WHERE id_task = 4 AND period_key = '2026-W44'")->fetch(), 'wystąpienia sprzed tygodnia zostają');
    $w = $s->week(2, $wed);
    $titles = fn(int $n) => array_map(fn($i) => $i['title'], $w['days'][$n]['items']);
    expectTw(in_array('Sprawdź przyjęcia', $titles(3), true) && in_array('Zakupy tygodnia (2×)', $titles(1), true) && in_array('Zakupy tygodnia (2×)', $titles(3), true)
        && !in_array('Nowe ceny', $titles(4), true) && in_array('Ceny surowców z cenników', $titles(5), true), 'Mój tydzień według nowego planu');
    expectTw(count($w['strip']) === 1 && $w['strip'][0]['task']['title'] === 'Oferty cenowe' && $w['later'][0]['frequency'] === 'Co kwartał', 'pasek : oferty codziennie ; przegląd co kwartał');
    $przyj = array_column($w['days'][3]['items'], null, 'title')['Sprawdź przyjęcia'];
    expectTw($przyj['id'] > 0 && $przyj['due'] === '2026-11-04' && $przyj['minutes'] === 30, 'przyjęcia : nowe wystąpienie tygodniowe na środę');

    // Kopiuj z innego stanowiska.
    expectTwFail(fn() => $s->copyPlan(2, 1, 2), 403, 'kopia : tylko administrator');
    expectTwFail(fn() => $s->copyPlan(1, 1, 1), 422, 'kopia z tego samego stanowiska : 422');
    expectTw($s->copyPlan(1, 2, 1) === 2, 'kopia z Logistyki : 2 zadania (nazwa « Zakupy tygodnia » już nie istnieje na Zakupach)');
    expectTw($s->copyPlan(1, 2, 1) === 0, 'druga kopia : nic (te same nazwy)');
    $copied = $pdo->query("SELECT frequency, minutes, link_path FROM n_position_task WHERE id_position = 1 AND title_pl = 'Wysyłki dnia'")->fetch();
    expectTw($copied === ['frequency' => 'daily', 'minutes' => 30, 'link_path' => '/shipments'], 'kopia z minutami i linkiem');

    // Edycja pojedynczego zadania (stara tabela stanowisk) : minuty i dni walidowane.
    $err = null;
    try { $s->saveTask(['minutes' => 600], 1, null); } catch (Throwable $e) { $err = $e; }
    expectTw($err !== null, 'saveTask : minuty 600 odrzucone');
    $err = null;
    try { $s->saveTask(['weekdays' => '1;4'], 1, null); } catch (Throwable $e) { $err = $e; }
    expectTw($err !== null, 'saveTask : dni « 1;4 » odrzucone');
    $s->saveTask(['minutes' => 35, 'weekdays' => '', 'auto_code' => null], 1, null);
    expectTw($pdo->query('SELECT minutes, weekdays, auto_code FROM n_position_task WHERE id = 1')->fetch() === ['minutes' => 35, 'weekdays' => null, 'auto_code' => null], 'saveTask : minuty 35, dni puste i bez zaliczenia = brak');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "task_week_test: OK\n";
