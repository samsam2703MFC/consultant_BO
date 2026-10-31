<?php
declare(strict_types=1);
/*
 * Moce produkcyjne [234] (makiety 10 i 11) : migracja 151 (rejouable, stoły ręczne ukryte), obciążenie maszyn z planu
 * tygodnia (etapy kart : praca, wsady, komora rozrostu jako oczekiwanie), dostępność (zmiany zespołów, TRS, dni produkcji,
 * zmiana wyłączona na maszynie, « co jeśli » nocna), dzień szczytu, zespoły (ludzie), ilość na zmianę i wąskie gardło,
 * « co jeszcze zmieszczę » (maszyna albo ludzie), wartość, produkty bez etapów, mroźnia zapasu (palety, miejsca, MTS
 * tygodnia), symulacja + ilość, ustawienia maszyny i mroźni (walidacja), filtr grupy, dostęp do strony.
 *
 *   php tests/capacity_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_capacity_test est recréée puis supprimée.
 * Semaine fixe : lundi 2.11.2026 (pn.–pt., zmiana dzienna 6–14).
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Core\Web\PageTabs;
use App\Exceptions\CustomException;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Planning\CapacityService;

function expectCap(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectCapFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectCap((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Bez bazy : dostęp i zakładka -------------------------------------------------------------------------------
expectCap(PageAccess::keyFor('/production/capacity') === 'production', 'dostęp : strona w katalogu « production »');
expectCap(in_array('/production/capacity', array_column(PageTabs::GROUPS['production']['tabs'], 0), true), 'zakładka « Moce produkcyjne » na stronie Produkcja');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_capacity_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "capacity_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    // État avant 151 : stanowiska bez wydajności, cyklu i widoczności.
    foreach (['efficiency_pct', 'cycle_s', 'capacity_view'] as $col) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_workstation' AND COLUMN_NAME = '{$col}'")->fetchColumn()) {
            $pdo->exec("ALTER TABLE n_prod_workstation DROP COLUMN {$col}");
        }
    }

    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('week.start_day', '1', '1', 'int'), ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string'),
        ('stock.freezer_pallet_places', '0', '0', 'int')");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order) VALUES (1, 'PIEK', 'Piekarnia', 'Boulangerie', 1)");
    $pdo->exec("INSERT INTO n_prod_shift_template (id, code, name_pl, name_fr, start_time, end_time, active_days, active, sort_order) VALUES
        (1, 'JOUR', 'Dzienna', 'Jour', '06:00:00', '14:00:00', '1,2,3,4,5,6', 1, 1), (2, 'APRES_MIDI', 'Popołudniowa', 'Après-midi', '14:00:00', '22:00:00', '1,2,3,4,5,6', 1, 2),
        (3, 'NUIT', 'Nocna', 'Nuit', '22:00:00', '06:00:00', '1,2,3,4,5,6', 1, 3)");
    $pdo->exec("INSERT INTO n_prod_team (id, code, id_group, id_shift_template, name, planned_headcount, active) VALUES (1, 'PIEK-J', 1, 1, 'Piekarnia dzień', 2, 1)");
    // 10 formierka (TRS 80 %), 11 piec pokładowy (4 × 12 = 48 szt., 25 min), 12 komora rozrostu (4 wózki), 13 stół (ręczny),
    // 14 szokowe mrożenie (bez etapów).
    $pdo->exec("INSERT INTO n_prod_workstation (id, code, id_group, name_pl, name_fr, type, capacity_per_pass, capacity_unit, active) VALUES
        (10, 'FORM', 1, 'Formierka bagietek', 'Façonneuse', 'machine', 100, 'piece', 1), (11, 'SOLE', 1, 'Piec pokładowy', 'Four à sole', 'four', 48, 'piece', 1),
        (12, 'ROZ', 1, 'Komora rozrostu', 'Chambre de pousse', 'fermentation', 4, 'chariot', 1), (13, 'STOL', 1, 'Stół formowania', 'Table', 'manuel', 1, 'piece', 1),
        (14, 'SZOK', 1, 'Komora szokowego mrożenia', 'Surgélateur', 'surgelation', 20, 'chariot', 1)");

    // --- Migracja 151 : dwa razy (rejouable) ---------------------------------------------------------------------
    $migration = (string) file_get_contents(__DIR__ . '/../migrations/tfb/151_capacity.sql');
    foreach (MigrationRunner::splitStatements($migration) as $sql) $pdo->exec($sql);
    $ws = $pdo->query('SELECT id, efficiency_pct, cycle_s, capacity_view FROM n_prod_workstation ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE);
    expectCap((float) $ws[10]['efficiency_pct'] === 100.0 && $ws[10]['cycle_s'] === null && (int) $ws[10]['capacity_view'] === 1 && (int) $ws[13]['capacity_view'] === 0,
        'migracja 151 : TRS 100 %, bez cyklu, stół ręczny ukryty');
    $pdo->exec('UPDATE n_prod_workstation SET capacity_view = 1 WHERE id = 13');
    foreach (MigrationRunner::splitStatements($migration) as $sql) $pdo->exec($sql);
    expectCap((int) $pdo->query('SELECT capacity_view FROM n_prod_workstation WHERE id = 13')->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM n_prod_param WHERE code = 'stock.freezer_alert_pct'")->fetchColumn() === 1, 'migracja 151 rejouable : ustawienia zostają, parametr raz');
    $pdo->exec('UPDATE n_prod_workstation SET capacity_view = 0 WHERE id = 13');
    $pdo->exec('UPDATE n_prod_workstation SET efficiency_pct = 80 WHERE id = 10');

    // Produkty : 1 Bagietka (MTS, 10 szt./karton, 50 kart./paleta, 20 € karton) — formierka 6 s/szt. + rozrost 1 h, piec 25 min / 48 szt. ;
    // 2 Quiche (stół, 2 min/szt., 2 osoby) ; 3 Tarta (w planie, bez etapów).
    $pdo->exec("INSERT INTO final_product (id, name, id_category, ppc, cpp, standard_price, active) VALUES (1, 'Bagietka', 1, 10, 50, 20, 1), (2, 'Quiche', 1, 6, 40, 0, 1), (3, 'Tarta', 1, 8, 40, 30, 1)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, base_unit) VALUES (1, 'product', 1, 'szt'), (2, 'product', 2, 'szt'), (3, 'product', 3, 'szt')");
    $pdo->exec("INSERT INTO n_prod_product_settings (id_item, mode, id_primary_group, status) VALUES (1, 'MTS', 1, 'active'), (2, 'MTO', 1, 'active'), (3, 'MTO', 1, 'active')");
    $pdo->exec("INSERT INTO n_prod_routing_step (id_item, step_no, name_pl, name_fr, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, cleaning_time_s, operators_required, delay_after_s, delay_id_workstation) VALUES
        (1, 1, 'Formowanie', 'Façonnage', 10, 0, 6, 100, 0, 1, 3600, 12), (1, 2, 'Wypiek', 'Cuisson', 11, 1500, 0, 48, 0, 1, 0, NULL),
        (2, 1, 'Składanie', 'Montage', 13, 0, 120, 1, 0, 2, 0, NULL)");
    $pdo->exec("INSERT INTO n_prod_plan (id, period_start, period_end, status, version) VALUES (1, '2026-11-02', '2026-11-08', 'validated', 1)");
    $pdo->exec("INSERT INTO n_prod_plan_line (id_plan, id_item, due_date, qty_final) VALUES (1, 1, '2026-11-04', 2400), (1, 3, '2026-11-04', 80)");
    $pdo->exec("INSERT INTO n_prod_week_slot (week_start, id_item, work_date, quantity) VALUES ('2026-11-02', 1, '2026-11-03', 2400)");
    // Zapas : 100 kartonów bagietki wolnych → 2 palety ; 10 sprzedanych (poza stanem).
    $values = [];
    for ($i = 1; $i <= 110; $i++) $values[] = "({$i}, 1, 1, " . ($i <= 100 ? 1 : 4) . ')';
    $pdo->exec('INSERT INTO carton_stock (id_carton, batch_number, id_product, status) VALUES ' . implode(', ', $values));

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => DI\factory(function () {
        throw new RuntimeException('Brak e-maili w teście mocy.');
    })]);
    $svc = $builder->build()->get(CapacityService::class);
    $date = '2026-11-04';

    // --- Wykorzystanie -----------------------------------------------------------------------------------------
    $d = $svc->data($date);
    expectCap($d['week']['start'] === '2026-11-02' && count($d['week']['days']) === 5 && $d['week']['planned_items'] === 2, 'tydzień : pn.–pt., 2 produkty w planie');
    $m = [];
    foreach ($d['sections'] as $s) foreach ($s['rows'] as $r) $m[$r['id']] = $r + ['section' => $s['name']];
    expectCap(array_column($d['sections'], 'name') === ['Chłodzenie i mrożenie', 'Wypiek', 'Maszyny', 'Rozrost i oczekiwanie'], 'sekcje wg rodzaju : ' . implode(', ', array_column($d['sections'], 'name')));
    expectCap(!isset($m[13]) && array_column($d['hidden'], 'id') === [13], 'stół ręczny ukryty (lista ukrytych)');
    expectCap($m[10]['avail_s'] == 115200 && $m[10]['load_s'] == 14400 && $m[10]['pct'] === 12.5 && $m[10]['efficiency_pct'] === 80.0,
        'formierka : 5 × 8 h × TRS 80 % = 32 h, 2400 × 6 s = 4 h → 12,5 %');
    expectCap($m[10]['peak_day']['label'] === 'wt.' && $m[10]['peak_day']['pct'] == 63 && $m[10]['days'][0]['pct'] == 0, 'formierka : szczyt we wtorek 63 %, poniedziałek 0 %');
    expectCap($m[11]['passes'] === 50 && $m[11]['load_s'] == 75000 && $m[11]['pct'] === 52.1 && $m[11]['cycle_s'] === 1500 && $m[11]['passes_shift'] === 19
        && $m[11]['free_label'] === '46 wsadów' && $m[11]['peak_day']['pct'] == 260, 'piec : 50 wsadów × 25 min = 52,1 %, 19 wsadów na zmianę, wolne 46 wsadów, wtorek 260 %');
    expectCap($m[12]['mode'] === 'delay' && $m[12]['occupancy'] == 86400 && $m[12]['pct'] === 15.0 && $m[12]['load_h'] === 6.0 && $m[12]['free_h'] === 34.0 && $m[12]['free_label'] === '34 h × 4 wózków',
        'komora rozrostu : 24 wsady × 1 h w 4 wózkach = 15 %');
    expectCap($m[14]['no_steps'] && $m[14]['pct'] === 0.0, 'szokowe mrożenie bez etapów : 0 %, oznaczone');
    $team = $d['teams'][0];
    expectCap($team['avail_h'] === 80.0 && $team['load_h'] === 24.8 && $team['pct'] === 31.0, 'zespół Piekarnia : 2 os. × 8 h × 5 dni = 80 h, 24,8 h pracy');
    expectCap($d['unrouted'] === [['item_id' => 3, 'name' => 'Tarta', 'quantity' => 80.0]], 'produkt bez etapów w planie : ostrzeżenie');
    expectCap($d['tiles']['bottleneck']['name'] === 'Piec pokładowy' && $d['tiles']['bottleneck']['top'] === 'Bagietka' && $d['tiles']['average']['pct'] === 26.5
        && $d['tiles']['average']['below'] === 3 && $d['tiles']['blast']['name'] === 'Komora szokowego mrożenia', 'kafelki : wąskie gardło piec, średnio 26,5 %, 3 poniżej celu');

    // --- Ilość na zmianę, co jeszcze zmieszczę -------------------------------------------------------------------
    $p = array_column($d['products'], null, 'item_id');
    expectCap($p[1]['per_shift'] === 921 && $p[1]['per_shift_cartons'] === 92.1 && $p[1]['limit_name'] === 'Piec pokładowy' && $p[1]['limit_cycle'] === '25 min / 48',
        'bagietka : 921 szt. na zmianę (piec 25 min / 48)');
    expectCap($p[1]['more'] === 2208 && $p[1]['more_cartons'] === 220.8 && !$p[1]['people_limited'] && $p[1]['price_unit'] === 2.0 && $p[1]['value'] === 4416,
        'bagietka : jeszcze 2208 szt. (piec), 2 € / szt. → 4416 €');
    expectCap($p[2]['per_shift'] === 240 && $p[2]['more'] === 827 && $p[2]['people_limited'] && $p[2]['more_limit'] === 'Zespół — Piekarnia' && $p[2]['value'] === null,
        'quiche : stół 240 / zmianę, ale ludzie : jeszcze 827 szt., bez ceny');
    expectCap($d['tiles']['best']['name'] === 'Bagietka' && $d['tiles']['best']['value'] === 4416 && $d['products'][0]['item_id'] === 1, 'najlepsza wartość : bagietka, pierwsza na liście');
    expectCap(!isset($p[3]), 'produkt bez etapów : poza listą mocy');

    // --- Mroźnia zapasu ------------------------------------------------------------------------------------------
    expectCap($d['freezer']['now'] === 2.0 && $d['freezer']['places'] === 0 && $d['freezer']['pct'] === null && $d['freezer']['mts_week'] === 4.8,
        'mroźnia : 2 palety dziś (sprzedane poza), bez miejsc → bez %, MTS tygodnia 4,8 palety');
    expectCapFail(fn() => $svc->saveFreezer(['places' => '10,5'], 5), 422, 'mroźnia : miejsca całkowite');
    expectCapFail(fn() => $svc->saveFreezer(['places' => 6000], 5), 422, 'mroźnia : najwyżej 5000 miejsc');
    expectCapFail(fn() => $svc->saveFreezer(['places' => 10, 'alert_pct' => 30], 5), 422, 'mroźnia : ostrzeżenie 50–100 %');
    $svc->saveFreezer(['places' => '10', 'alert_pct' => '85'], 5);
    $f = $svc->data($date)['freezer'];
    expectCap($f['places'] === 10 && $f['pct'] === 20.0 && $f['pct_max'] === 68.0 && $f['free'] === 8.0 && $f['alert_pct'] === 85.0, 'mroźnia : 10 miejsc → 20 % dziś, 68 % z MTS tygodnia');

    // --- Symulacja + ilość ---------------------------------------------------------------------------------------
    $sim = $svc->simulate($date, 1, 240);
    $st = array_column($sim['steps'], null, 'workstation');
    expectCap($sim['fits'] && $st['Formierka bagietek']['after'] === 13.8 && $st['Komora rozrostu']['type'] === 'delay' && $st['Komora rozrostu']['after'] === 16.9
        && $st['Piec pokładowy']['before'] === 52.1 && $st['Piec pokładowy']['after'] === 57.3 && $sim['extra_shift'] === 4608, 'symulacja + 240 bagietek : mieści się, piec 52,1 → 57,3 %');
    $sim = $svc->simulate($date, 1, 3000);
    expectCap(!$sim['fits'] && !array_column($sim['steps'], null, 'workstation')['Piec pokładowy']['ok'] && $sim['more'] === 2208, 'symulacja + 3000 : piec ponad 100 %, nie mieści się');
    expectCapFail(fn() => $svc->simulate($date, 3, 10), 422, 'symulacja produktu bez etapów');
    expectCapFail(fn() => $svc->simulate($date, 1, 0), 422, 'symulacja : ilość większa od zera');

    // --- Co jeśli : zmiana nocna ; zmiana wyłączona na maszynie ----------------------------------------------------
    $pdo->exec('INSERT INTO n_prod_workstation_shift (id_workstation, id_shift_template, available) VALUES (10, 3, 0)'); // formierka : noc wyłączona w Stanowiskach
    $n = $svc->data($date, ['night' => true]);
    $mn = [];
    foreach ($n['sections'] as $s) foreach ($s['rows'] as $r) $mn[$r['id']] = $r;
    expectCap($n['what_if']['night'] && $mn[11]['avail_s'] == 288000 && $mn[11]['pct'] === 26.0 && $n['teams'][0]['avail_h'] === 160.0 && $mn[10]['avail_s'] == 230400,
        '+ nocna : piec 26 %, zespół 160 h, formierka też (co jeśli otwiera zmianę dla wszystkich maszyn)');
    $mx = [];
    foreach ($svc->data($date)['sections'] as $s) foreach ($s['rows'] as $r) $mx[$r['id']] = $r;
    expectCap($mx[10]['avail_s'] == 115200, 'bez « co jeśli » : noc wyłączona na formierce nie liczy się');
    $svc->saveMachine(11, ['shifts' => [1 => false, 99 => true]], 5);
    $mx = [];
    foreach ($svc->data($date)['sections'] as $s) foreach ($s['rows'] as $r) $mx[$r['id']] = $r;
    expectCap($mx[11]['avail_s'] == 0 && $mx[11]['pct'] === 999.0 && $mx[11]['shift_ids'] === [], 'piec wyłączony na zmianie dziennej : brak czasu, przeciążenie');
    $svc->saveMachine(11, ['shifts' => [1 => true, 3 => true]], 5);
    $mx = [];
    foreach ($svc->data($date)['sections'] as $s) foreach ($s['rows'] as $r) $mx[$r['id']] = $r;
    expectCap($mx[11]['shift_ids'] === [1, 3] && $mx[11]['avail_s'] == 288000 && (int) $pdo->query('SELECT COUNT(*) FROM n_prod_workstation_shift WHERE id_workstation = 11')->fetchColumn() === 2,
        'piec włączony też w nocy (bez zespołu, np. wypiek nocny) : 2 zmiany, zmiana nieznana pominięta');

    // --- Ustawienia maszyny ---------------------------------------------------------------------------------------
    expectCapFail(fn() => $svc->saveMachine(999, ['efficiency_pct' => 90], 5), 404, 'maszyna nieznana');
    expectCapFail(fn() => $svc->saveMachine(10, ['efficiency_pct' => 5], 5), 422, 'TRS poniżej 10 %');
    expectCapFail(fn() => $svc->saveMachine(10, ['cycle_min' => 0], 5), 422, 'cykl 0 min');
    expectCapFail(fn() => $svc->saveMachine(10, ['capacity_unit' => 'tona'], 5), 422, 'jednostka nieznana');
    expectCapFail(fn() => $svc->saveMachine(10, ['capacity_per_pass' => 'dużo'], 5), 422, 'wsad nie jest liczbą');
    $svc->saveMachine(14, ['capacity_per_pass' => '12,5', 'capacity_unit' => 'chariot', 'efficiency_pct' => '90', 'cycle_min' => '45', 'view' => false], 5);
    $row = $pdo->query('SELECT capacity_per_pass, capacity_unit, efficiency_pct, cycle_s, capacity_view FROM n_prod_workstation WHERE id = 14')->fetch();
    expectCap((float) $row['capacity_per_pass'] === 12.5 && (float) $row['efficiency_pct'] === 90.0 && (int) $row['cycle_s'] === 2700 && (int) $row['capacity_view'] === 0,
        'ustawienia : wsad 12,5 wózka, TRS 90 %, cykl 45 min, ukryta');
    $h = $svc->data($date);
    expectCap(in_array(14, array_column($h['hidden'], 'id'), true) && $h['tiles']['blast'] === null, 'maszyna ukryta : poza sekcjami i kafelkami');
    $svc->saveMachine(14, ['cycle_min' => '', 'view' => true], 5);
    expectCap($pdo->query('SELECT cycle_s FROM n_prod_workstation WHERE id = 14')->fetchColumn() === null, 'cykl pusty → z kart produkcyjnych');

    // --- Filtr grupy ---------------------------------------------------------------------------------------------
    $g = $svc->data($date, [], 99);
    expectCap($g['sections'] === [] && $g['products'] === [] && $g['teams'] === [] && $g['group'] === 99, 'filtr innej grupy : pusto');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "capacity_test: OK\n";
