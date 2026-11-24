<?php
/**
 * [421] Jakość → Kierownik produkcji (migration 253) : tâches (sans doublon d'assistant), droits, menu, matrice, postes
 * rattachés, poste Jakość inactif (seulement si personne ne l'occupe) ; rejouable ; autres postes intacts.
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), base erp_quality_kp_test recréée puis supprimée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$sql = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/253_quality_to_production_manager.sql'));

$setup = function (bool $occupied) use ($pdo) {
    $pdo->exec('DROP DATABASE IF EXISTS erp_quality_kp_test'); $pdo->exec('CREATE DATABASE erp_quality_kp_test'); $pdo->exec('USE erp_quality_kp_test');
    $pdo->exec('CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), active TINYINT DEFAULT 1, id_reports_to INT NULL)');
    $pdo->exec('CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, title_pl VARCHAR(80), auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)');
    $pdo->exec('CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), PRIMARY KEY (id_position, page_key))');
    $pdo->exec('CREATE TABLE n_position_menu (id_position INT, item VARCHAR(40), open_path VARCHAR(160) NULL, PRIMARY KEY (id_position, item))');
    $pdo->exec('CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)');
    $pdo->exec("CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, number VARCHAR(12), area_no SMALLINT NULL, area VARCHAR(160) NULL, text_pl TEXT, text_fr TEXT NULL,
        wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL, rhythm VARCHAR(12) DEFAULT 'none', source VARCHAR(200) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
    $pdo->exec("INSERT INTO n_position (id, code, id_reports_to) VALUES (1, 'KIEROWNIK_PRODUKCJI', NULL), (2, 'JAKOSC', NULL), (3, 'LOGISTYKA', NULL), (4, 'LABORANT', 2)");
    $pdo->exec("INSERT INTO n_position_task (id_position, title_pl, auto_code, sort_order, active) VALUES
        (1, 'NC (KP, suspendue)', 'nc_reviewed', 10, 0), (1, 'Czas pracy', 'time_day_validated', 20, 1),
        (2, 'NC (Jakość)', 'nc_reviewed', 10, 1), (2, 'Przyjęcia', 'receiving_checked', 20, 1), (2, 'HACCP', 'haccp_month_reviewed', 30, 1),
        (2, 'Notatka', NULL, 40, 1), (2, 'Stara', 'old_code', 50, 0), (3, 'Trasa', 'route_done', 10, 1)");
    $pdo->exec("INSERT INTO n_page_access VALUES (1, 'time'), (2, 'haccp'), (2, 'receiving'), (2, 'time'), (3, 'logistics')");
    $pdo->exec("INSERT INTO n_position_menu VALUES (1, 'time', NULL), (2, 'haccp', NULL), (3, 'logistics', NULL)");
    $pdo->exec("INSERT INTO n_position_duty (id_position, number, text_pl, wizard_code, rhythm, sort_order, active) VALUES
        (2, '1', 'Plan HACCP', 'haccp_month_reviewed', 'monthly', 10, 1), (2, '2', 'Rejestr NC', 'nc_reviewed', 'monthly', 20, 1), (2, '3', 'Stary punkt', NULL, 'none', 30, 0)");
    if ($occupied) $pdo->exec('INSERT INTO n_user_position VALUES (7, 2)');
};

$setup(false);
for ($k = 0; $k < 2; $k++) foreach ($sql as $s) $pdo->exec($s);
$kp = $pdo->query('SELECT title_pl, auto_code, active FROM n_position_task WHERE id_position = 1 ORDER BY sort_order, id')->fetchAll();
$codes = array_count_values(array_filter(array_column(array_filter($kp, fn($t) => (int) $t['active'] === 1), 'auto_code')));
$check(($codes['nc_reviewed'] ?? 0) === 1 && ($codes['receiving_checked'] ?? 0) === 1 && ($codes['haccp_month_reviewed'] ?? 0) === 1 && ($codes['time_day_validated'] ?? 0) === 1,
    'KP : NC (reactivated, no duplicate), receptions, HACCP and its own tasks active — replayed twice');
$check(in_array('Notatka', array_column($kp, 'title_pl'), true) && !in_array('Stara', array_column($kp, 'title_pl'), true), 'manual task moved ; inactive old task left at Jakość');
$check((int) $pdo->query("SELECT COUNT(*) FROM n_position_task WHERE id_position = 2 AND active = 1")->fetchColumn() === 0, 'Jakość : no active task left (duplicate NC deactivated, not deleted)');
$check($pdo->query('SELECT page_key FROM n_page_access WHERE id_position = 1 ORDER BY page_key')->fetchAll(PDO::FETCH_COLUMN) === ['haccp', 'receiving', 'time'], 'rights : haccp, receiving added to KP (no duplicate)');
$check($pdo->query('SELECT item FROM n_position_menu WHERE id_position = 1 ORDER BY item')->fetchAll(PDO::FETCH_COLUMN) === ['haccp', 'time'], 'own menu of KP completed');
$d = $pdo->query('SELECT number, area_no, wizard_code FROM n_position_duty WHERE id_position = 1 ORDER BY number')->fetchAll();
$check(array_column($d, 'number') === ['J1', 'J2'] && (int) $d[0]['area_no'] === 13 && $d[1]['wizard_code'] === 'nc_reviewed', 'matrix : active duties copied to KP as J1, J2 (area 13), once');
$check((int) $pdo->query('SELECT COUNT(*) FROM n_position_duty WHERE id_position = 2 AND active = 1')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM n_position_duty WHERE id_position = 2')->fetchColumn() === 3,
    'Jakość duties deactivated, never deleted');
$check((int) $pdo->query('SELECT id_reports_to FROM n_position WHERE id = 4')->fetchColumn() === 1 && (int) $pdo->query('SELECT active FROM n_position WHERE id = 2')->fetchColumn() === 0,
    'subordinate of Jakość now under KP ; Jakość inactive (nobody holds it)');
$check($pdo->query("SELECT page_key FROM n_page_access WHERE id_position = 3")->fetchAll(PDO::FETCH_COLUMN) === ['logistics'] && (int) $pdo->query("SELECT active FROM n_position_task WHERE id_position = 3")->fetchColumn() === 1,
    'other positions untouched');

$setup(true);
foreach ($sql as $s) $pdo->exec($s);
$check((int) $pdo->query('SELECT active FROM n_position WHERE id = 2')->fetchColumn() === 1 && $pdo->query('SELECT page_key FROM n_page_access WHERE id_position = 2 ORDER BY page_key')->fetchAll(PDO::FETCH_COLUMN) === ['haccp', 'receiving', 'time'],
    'someone still holds Jakość : position stays active with its rights (moved by hand later)');

$pdo->exec('DROP DATABASE erp_quality_kp_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
