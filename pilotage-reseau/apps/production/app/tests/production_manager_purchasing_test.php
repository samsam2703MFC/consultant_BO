<?php
/**
 * [394] Les achats reviennent au Kierownik produkcji (migration 236) : tâches d'achat réactivées, les autres tâches
 * suspendues par la 162 restent suspendues, droits des pages d'achat et menu ; rejouable ; autres postes intacts.
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), base erp_kp_purchasing_test recréée puis supprimée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Core\Web\Rail;
use App\Services\Organization\TaskWizards;
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_kp_purchasing_test'); $pdo->exec('CREATE DATABASE erp_kp_purchasing_test'); $pdo->exec('USE erp_kp_purchasing_test');
$pdo->exec('CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), active TINYINT DEFAULT 1)');
$pdo->exec('CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, auto_code VARCHAR(40) NULL, active TINYINT DEFAULT 1)');
$pdo->exec('CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))');
$pdo->exec('CREATE TABLE n_position_menu (id_position INT, item VARCHAR(40), open_path VARCHAR(160) NULL, PRIMARY KEY (id_position, item))');
$pdo->exec("INSERT INTO n_position (id, code, active) VALUES (1, 'KIEROWNIK_PRODUKCJI', 1), (2, 'ZAKUPY', 0), (3, 'LOGISTYKA', 1)");
// État après la 162 : seul « Czas pracy dnia » actif chez le KP.
$pdo->exec("INSERT INTO n_position_task (id_position, auto_code, active) VALUES (1, 'time_day_validated', 1), (1, 'buy_sent', 0), (1, 'inv_checked', 0), (1, 'suppliers_reviewed', 0),
    (1, 'material_costs_reviewed', 0), (1, 'forecast_approved', 0), (1, 'production_day_closed', 0), (1, 'yield_reviewed', 0), (1, NULL, 0), (3, 'buy_sent', 0)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'time'), (3, 'logistics')");
$pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (1, 'time')");

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/236_production_manager_purchasing.sql')) as $sql) $pdo->exec($sql);

$tasks = $pdo->query("SELECT COALESCE(auto_code, '-') c, active FROM n_position_task WHERE id_position = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($tasks['buy_sent'] === 1 && $tasks['inv_checked'] === 1 && $tasks['suppliers_reviewed'] === 1 && $tasks['material_costs_reviewed'] === 1 && $tasks['time_day_validated'] === 1,
    'Kierownik produkcji : purchasing tasks active again (+ Czas pracy)');
$check($tasks['forecast_approved'] === 0 && $tasks['production_day_closed'] === 0 && $tasks['yield_reviewed'] === 0 && $tasks['-'] === 0,
    'forecast, production tracking, yield and others stay suspended');
$check((int) $pdo->query("SELECT active FROM n_position_task WHERE id_position = 3")->fetchColumn() === 0, 'other positions untouched');
$keys = $pdo->query('SELECT page_key FROM n_page_access WHERE id_position = 1 ORDER BY page_key')->fetchAll(PDO::FETCH_COLUMN);
$check($keys === ['purchasing', 'supplier_review', 'supply', 'time'], 'rights : supply, purchasing, supplier_review added to time (migration replayed twice, no duplicate)');
$check($pdo->query('SELECT page_key FROM n_page_access WHERE id_position = 3')->fetchAll(PDO::FETCH_COLUMN) === ['logistics'], 'other positions\' rights untouched');
$menu = $pdo->query('SELECT item FROM n_position_menu WHERE id_position = 1 ORDER BY item')->fetchAll(PDO::FETCH_COLUMN);
$check($menu === ['materials', 'purchasing', 'time'], 'own menu : Zakupy and Surowce i ceny added');

// Cohérence : les pages des 4 assistants tombent sous les droits donnés ; les entrées de menu existent.
$given = ['supply', 'purchasing', 'supplier_review', 'time'];
foreach (['buy_sent', 'inv_checked', 'suppliers_reviewed', 'material_costs_reviewed'] as $code) {
    $link = (string) parse_url(TaskWizards::CATALOG[$code][1], PHP_URL_PATH);
    $check(in_array(PageAccess::keyFor($link), $given, true) && in_array(PageAccess::keyFor($link), PageAccess::grantable(), true), "{$code} : page {$link} covered by a given right (" . PageAccess::keyFor($link) . ')');
}
$check(isset(Rail::ITEMS['purchasing']) && isset(Rail::ITEMS['materials']), 'menu items « purchasing » and « materials » exist in the rail');

$pdo->exec('DROP DATABASE erp_kp_purchasing_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
