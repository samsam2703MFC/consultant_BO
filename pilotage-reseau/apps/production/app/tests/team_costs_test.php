<?php
declare(strict_types=1);
/*
 * [267] « Koszty i premie » (session Kierownik produkcji) sur une base jetable (structure copiée de erp) :
 *   - migration 166 rejouable : paramètres (0,3 %, primes montrées, pas de prime trimestrielle), droit « team_costs »
 *     du Kierownik produkcji ; clé d'accès et onglet de Czas pracy ;
 *   - prochain palier d'un groupe (ce qui manque d'ici la fin du mois) ;
 *   - vue : coût employeur par groupe et par personne, personne de deux groupes comptée une fois, objectif de coût du
 *     personnel, prime du Kierownik = % du chiffre d'affaires général ; primes retirées côté serveur quand
 *     l'administrateur les masque (sauf pour l'administrateur) ;
 *   - réglages : taux de 0 à 5 %, refus hors limites.
 *
 *   php tests/team_costs_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_team_costs_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Core\Web\PageTabs;
use App\Exceptions\CustomException;
use App\Services\Staff\TeamBonusService;
use App\Services\Staff\TeamCostsService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectTc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Sans base : palier suivant, clé d'accès, onglet
$settings = ['min' => 30000.0, 'tiers' => [['from' => 30000.0, 'amount' => 300.0], ['from' => 35000.0, 'amount' => 600.0]], 'prorata' => true];
$now = ['available' => true, 'people' => 4.0, 'revenue_pln' => 60000.0, 'per_person' => 15000.0, 'projection' => 31000.0];
$next = TeamBonusService::nextStep($now, $settings, 10);
expectTc($next['from'] === 35000.0 && $next['missing_group'] === 80000.0 && $next['missing_per_person'] === 20000.0 && $next['per_day'] === 8000.0, 'next tier: 4 × 35 000 − 60 000 = 80 000 zł still to sell');
expectTc(TeamBonusService::nextStep(['available' => false] + $now, $settings, 10) === null && TeamBonusService::nextStep(['projection' => 40000.0] + $now, $settings, 10) === null, 'no next tier without figures or above the last tier');
expectTc(PageAccess::keyFor('/time/costs') === 'team_costs' && PageAccess::keyFor('/time/month') === 'time' && !PageAccess::adminOnly('team_costs'), 'own access key for the costs tab, grantable to a position');
expectTc(in_array('/time/costs', array_column(PageTabs::GROUPS['time']['tabs'], 0), true), 'tab « Koszty i premie » in Czas pracy');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_team_costs_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "team_costs_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1)");
    $m166 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/166_manager_team_costs.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($m166) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectTc($count("SELECT COUNT(*) FROM n_prod_param WHERE code LIKE 'manager.%'") === 3
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'manager.sales_bonus_pct'")->fetchColumn() === '0.3'
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'manager.quarterly_bonus'")->fetchColumn() === '0'
        && $count("SELECT COUNT(*) FROM n_page_access WHERE id_position = 3 AND page_key = 'team_costs'") === 1, 'migration 166 (replayed twice): 0,3 %, no quarterly bonus, access for the Kierownik');

    // --- Deux groupes de production, quatre personnes (une dans les deux groupes par une équipe), paliers
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0), (2, 'Tarty', 0, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active, id_section, bonus_visible) VALUES
        (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1, 1, 1), (2, 'TARTY', 'Tarty', 'Tartes', '#8D1D2C', 2, 1, 2, 0)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (1, 'Adam', 'Testowy', 8000, 1), (2, 'Basia', 'Testowa', 7000, 1), (3, 'Cezary', 'Testowy', 6500, 2), (4, 'Dawna', 'Osoba', 9000, 2)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES (11, 1, 1, 1), (12, 2, 1, 1), (13, 3, 1, 2), (14, 4, 0, 2)");
    $pdo->exec("INSERT INTO n_prod_team (id, id_group, name, active) VALUES (1, 2, 'Tarty rano', 1)");
    $pdo->exec("INSERT INTO n_prod_team_member (id_team, id_employee) VALUES (1, 12)");
    $pdo->exec("INSERT INTO n_bonus_tier (from_per_person_pln, amount_pln) VALUES (30000, 300), (35000, 600)");

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo]);
    $c = $b->build();
    $costs = $c->get(TeamCostsService::class);
    $today = new DateTimeImmutable('2026-11-11');

    $v = $costs->view(false, $today);
    $g = array_column($v['groups'], null, 'id');
    expectTc($v['month'] === '2026-11' && $v['day'] === 11 && $v['days'] === 30 && $v['show_bonuses'] === true, 'month view: 11th day of 30, bonuses shown by default');
    expectTc($g[1]['people'] === 2 && $g[1]['cost'] === 15000.0 && $g[2]['people'] === 2 && $g[2]['cost'] === 13500.0, 'cost per group: active people only (primary group or team)');
    expectTc(array_column($g[1]['persons'], 'cost', 'name') === ['Basia Testowa' => 7000.0, 'Adam Testowy' => 8000.0], 'cost per person');
    expectTc($v['totals']['people'] === 3 && $v['totals']['cost'] === 21500.0, 'a person of two groups counted once in the total');
    // Mois sans ventes ni personnes payées (coûts du mois non saisis) : pas de palier, pas de prime, la ligne reste lisible.
    expectTc($g[1]['next'] === null && $g[1]['below_min'] === false && $g[1]['tier'] === null && $g[1]['bonus_person'] === 0.0 && $g[1]['persons'][0]['bonus'] === 0.0, 'month without figures: no tier, no bonus');
    expectTc($v['manager']['pct'] === 0.3 && $v['manager']['now'] === 0.0 && $v['company']['staff_target'] !== null && array_key_exists('missing', $v['company']), 'Kierownik bonus = 0,3 % of the company sales ; staff cost target of the company');
    expectTc(array_key_exists('missing_target', $g[1]) && array_key_exists('staff_target', $g[1]), 'missing sales for the staff cost target per group');

    // --- L'administrateur masque les primes : rien ne sort du serveur pour le Kierownik
    expectTc(str_starts_with((string) (function () use ($costs) { try { $costs->saveSettings(['manager_bonus_pct' => '7'], 1); } catch (CustomException $e) { return $e->getCode() . ''; } return 'accepted'; })(), '422'), 'rate above 5 % refused');
    $costs->saveSettings(['manager_bonus_pct' => '0,45', 'manager_show_bonuses' => false], 1);
    expectTc($costs->settings() === ['show_bonuses' => false, 'bonus_pct' => 0.45], 'settings saved (comma accepted)');
    $hidden = $costs->view(false, $today);
    $hg = $hidden['groups'][0];
    expectTc($hidden['show_bonuses'] === false && !isset($hidden['manager']) && !isset($hidden['totals']['bonus']) && !isset($hg['bonus_person'], $hg['tier'], $hg['next'])
        && !array_key_exists('bonus', $hg['persons'][0]) && $hg['cost'] === 15000.0 && $hg['persons'][0]['cost'] !== null, 'bonuses hidden for the Kierownik: costs stay, no bonus field leaves the server');
    $admin = $costs->view(true, $today);
    expectTc($admin['show_bonuses'] === true && $admin['manager']['pct'] === 0.45 && isset($admin['groups'][0]['bonus_person']), 'the administrator always sees the bonuses');

    echo "team_costs_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
