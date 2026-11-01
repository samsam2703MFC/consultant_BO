<?php
declare(strict_types=1);
/*
 * [240] Dane maszyn (makiety 12–13) sur une base jetable : vue d'ensemble (machines avec produits, produits avec données,
 * état par machine, groupe sans machine, machine suivante : four d'abord), fiche machine (produits, données existantes en
 * pièces / fournées par heure / personnes), enregistrement (étape créée en fin de fiche, étape existante modifiée, lignes
 * vides et inchangées ignorées, personnes par fournée, journal), refus (valeurs hors limites, produit inconnu, machine
 * inactive).
 *
 *   php tests/machine_data_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_machine_data_test est recréée puis supprimée.
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Workshop\Planning\MachineDataService;

function expectMd(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_machine_data_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "machine_data_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['n_prod_group', 'n_prod_workstation', 'n_prod_item', 'n_prod_product_settings', 'final_product', 'product_recipe', 'n_prod_routing_step',
                 'n_prod_routing_alternative', 'n_prod_audit_log', 'n_prod_shift_template', 'n_prod_param'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', 1, 1), (7, 'TARTY', 'Tarty', 'Tartes', 2, 1)");
    $pdo->exec("INSERT INTO n_prod_workstation (id, code, id_group, name_pl, name_fr, type, capacity_per_pass, capacity_unit, efficiency_pct, active) VALUES
        (1, 'MIX1', 1, 'Miesiarka spiralna 1', 'Pétrin 1', 'machine', 120, 'kg', 100, 1),
        (5, 'FORM', 1, 'Formierka bagietek', 'Façonneuse', 'machine', 220, 'piece', 100, 1),
        (9, 'PIEC', 1, 'Piec rotacyjny', 'Four rotatif', 'four', 18, 'plaque', 85, 1),
        (4, 'DZIEL', 1, 'Dzielarka', 'Diviseuse', 'machine', 30, 'piece', 100, 1),
        (30, 'OLD', 1, 'Stary piec', 'Vieux four', 'four', 10, 'plaque', 100, 0)");
    $pdo->exec("INSERT INTO n_prod_shift_template (id, code, name_pl, name_fr, start_time, end_time, active, sort_order) VALUES (1, 'JOUR', 'Dzienna', 'Jour', '06:00:00', '14:00:00', 1, 1)");
    $pdo->exec("INSERT INTO final_product (id, name, active, ppc) VALUES (101, 'Baguette tradition', 1, 12), (102, 'Pain doré', 1, 6), (103, 'Ciabatta', 1, NULL), (104, 'Stary chleb', 0, NULL), (105, 'Tarte citron', 1, 8)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, base_unit) VALUES (1, 'product', 101, 'piece'), (2, 'product', 102, 'piece'), (3, 'product', 103, 'piece'), (4, 'product', 104, 'piece'), (5, 'product', 105, 'piece')");
    $pdo->exec("INSERT INTO n_prod_product_settings (id_item, mode, id_primary_group) VALUES (1, 'MTS', 1), (2, 'MTS', 1), (3, 'MTO', 1), (4, 'MTO', 1), (5, 'MTO', 7)");
    // Baguette : miesiarka + formierka (220 szt., 600 s + 4 s/szt., 2 osoby).
    $pdo->exec("INSERT INTO n_prod_routing_step (id, id_item, step_no, name_pl, name_fr, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, operators_required) VALUES
        (1, 1, 1, 'Miesienie', 'Pétrissage', 1, 900, 0, 120, 1), (2, 1, 2, 'Formowanie', 'Façonnage', 5, 600, 4, 220, 2)");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $s = $builder->build()->get(MachineDataService::class);

    // --- Vue d'ensemble -------------------------------------------------------------------------------------------------------
    $o = $s->overview();
    expectMd($o['tiles']['machines'] === 4 && $o['tiles']['machines_with'] === 2, 'maszyny : 2 z 4 aktywnych mają produkty ' . json_encode($o['tiles']));
    expectMd($o['tiles']['products'] === 4 && $o['tiles']['products_with'] === 1, 'produkty : 1 z 4 (bez wyłączonego w ERP)');
    $byName = [];
    foreach ($o['groups'] as $g) foreach ($g['machines'] as $m) $byName[$m['name']] = $m;
    expectMd($byName['Formierka bagietek']['state'] === 'ok' && $byName['Formierka bagietek']['products'] === 1 && $byName['Piec rotacyjny']['state'] === 'todo', 'stan maszyn');
    expectMd(!isset($byName['Stary piec']), 'maszyna nieaktywna : poza listą');
    $tarty = array_values(array_filter($o['groups'], fn($g) => $g['name'] === 'Tarty'))[0];
    expectMd($tarty['machines'] === [] && $tarty['products'] === 1, 'Tarty : grupa bez maszyn');
    expectMd($o['next'] === 9 && $o['todo'] === 2, 'następna maszyna : najpierw piec (' . $o['next'] . ')');

    // --- Fiche machine --------------------------------------------------------------------------------------------------------
    $f = $s->machine(5);
    $bag = array_values(array_filter($f['products'], fn($p) => $p['id'] === 1))[0];
    expectMd($bag['on'] && $bag['per_batch'] == 220 && $bag['per_hour'] == 2.43 && $bag['people'] == 2 && $bag['others'] === ['Miesiarka spiralna 1'], 'formierka : dane bagietki ' . json_encode($bag));
    expectMd(count($f['products']) === 4 && $f['machine']['shift_h'] == 8 && $f['machine']['unit'] === 'szt.', 'formierka : 4 produkty, zmiana 8 h');
    $piec = $s->machine(9);
    expectMd($piec['machine']['type_name'] === 'piec' && $piec['machine']['efficiency_pct'] == 85 && !array_filter($piec['products'], fn($p) => $p['on']), 'piec : bez produktów');

    // --- Enregistrement -------------------------------------------------------------------------------------------------------
    $r = $s->save(9, [
        ['item_id' => 1, 'per_batch' => '216', 'per_hour' => '2', 'people' => '1'],
        ['item_id' => 2, 'per_batch' => '144', 'per_hour' => '1,5', 'people' => ''],
        ['item_id' => 3, 'per_batch' => '', 'per_hour' => '3'],
    ], 1);
    expectMd($r['created'] === 2 && $r['updated'] === 0 && $r['empty'] === 1 && $r['next'] === 4, 'zapis pieca : 2 nowe, 1 pusty, następna dzielarka ' . json_encode($r));
    $st = $pdo->query('SELECT id_item, step_no, name_pl, name_fr, capacity_per_pass, fixed_time_s, variable_time_s, operators_required FROM n_prod_routing_step WHERE id_workstation = 9 ORDER BY id_item')->fetchAll();
    expectMd(count($st) === 2 && (int) $st[0]['step_no'] === 3 && $st[0]['name_pl'] === 'Piec rotacyjny' && $st[0]['name_fr'] === 'Four rotatif'
        && (float) $st[0]['capacity_per_pass'] == 216 && (int) $st[0]['fixed_time_s'] === 1800 && (float) $st[0]['variable_time_s'] == 0 && (float) $st[0]['operators_required'] == 1, 'bagietka : etap 3 na końcu karty');
    expectMd((int) $st[1]['step_no'] === 1 && (int) $st[1]['fixed_time_s'] === 2400 && (float) $st[1]['operators_required'] == 1, 'pain doré : etap 1, 40 min, osób domyślnie 1');
    expectMd((int) $pdo->query("SELECT COUNT(*) FROM n_prod_audit_log WHERE entity = 'item_routing_step' AND action = 'create'")->fetchColumn() === 2, 'dziennik : 2 nowe etapy');
    // Zmiana istniejącego etapu (formierka) i wiersz bez zmian.
    $r = $s->save(5, [['item_id' => 1, 'per_batch' => '220', 'per_hour' => '2,43', 'people' => '2'], ['item_id' => 3, 'per_batch' => '200', 'per_hour' => '3', 'people' => '3']], 1);
    expectMd($r['unchanged'] === 1 && $r['created'] === 1 && $r['updated'] === 0, 'formierka : bagietka bez zmian, ciabatta nowa ' . json_encode($r));
    $r = $s->save(5, [['item_id' => 1, 'per_batch' => '200', 'per_hour' => '3', 'people' => '1,5']], 1);
    $b = $pdo->query('SELECT capacity_per_pass, fixed_time_s, variable_time_s, operators_required FROM n_prod_routing_step WHERE id = 2')->fetch();
    expectMd($r['updated'] === 1 && (float) $b['capacity_per_pass'] == 200 && (int) $b['fixed_time_s'] === 1200 && (float) $b['variable_time_s'] == 0 && (float) $b['operators_required'] == 1.5, 'formierka : bagietka zmieniona, 1,5 osoby');
    $log = $pdo->query("SELECT before_json, after_json FROM n_prod_audit_log WHERE entity_id = 2 AND action = 'update'")->fetch();
    expectMd($log && str_contains((string) $log['before_json'], '600') && str_contains((string) $log['after_json'], 'kreator maszyny'), 'dziennik : przed / po');
    $o = $s->overview();
    expectMd($o['tiles']['products_with'] === 3, 'po zapisie : 3 produkty z danymi');
    $byId = [];
    foreach ($o['groups'] as $g) foreach ($g['machines'] as $m) $byId[$m['id']] = $m;
    expectMd($byId[5]['batch_min'] == 200 && $byId[5]['batch_max'] == 200, 'wsad wg produktu (zakres z etapów) ' . json_encode($byId[5]));
    foreach ($byId as $m) if ($m['products'] === 0) expectMd($m['batch_min'] === null, 'maszyna bez produktów : brak wsadu');

    // --- Refus ------------------------------------------------------------------------------------------------------------------
    foreach ([
        [9, [['item_id' => 1, 'per_batch' => '0', 'per_hour' => '2']], 422, 'sztuk'],
        [9, [['item_id' => 1, 'per_batch' => '10', 'per_hour' => '900']], 422, 'wsadów'],
        [9, [['item_id' => 1, 'per_batch' => '10', 'per_hour' => '2', 'people' => '80']], 422, 'osób'],
        [9, [['item_id' => 4, 'per_batch' => '10', 'per_hour' => '2']], 422, 'produktu'],
        [9, [['item_id' => 1, 'per_batch' => 'abc', 'per_hour' => '2']], 422, 'liczbę'],
        [30, [['item_id' => 1, 'per_batch' => '10', 'per_hour' => '2']], 404, 'maszyny'],
    ] as [$mid, $rows, $code, $word]) {
        try {
            $s->save($mid, $rows, 1);
            expectMd(false, "odmowa : {$word}");
        } catch (CustomException $e) {
            expectMd($e->getCode() === $code && str_contains($e->getMessage(), $word), "odmowa {$word} : {$e->getCode()} {$e->getMessage()}");
        }
    }
    expectMd((int) $pdo->query('SELECT COUNT(*) FROM n_prod_routing_step')->fetchColumn() === 5, 'odmowy : nic nie zapisane');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "machine_data_test: OK\n";
