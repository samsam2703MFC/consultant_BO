<?php
/**
 * [396] Zakres obowiązków « produkcja » → Kierownik produkcji (migration 237) : 68 obowiązków en 11 obszarów dans la
 * matrice, anciennes lignes de la karta désactivées (jamais supprimées), zadania hors zakres gardés dans l'obszar 12,
 * karta remplacée (version + 1, à revalider) ; rejouable sans doublon ; autres postes et Kierownik operacyjny intacts.
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), base erp_kp_scope_test recréée puis supprimée.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Organization\{PositionSheetService, TaskWizards};
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_kp_scope_test'); $pdo->exec('CREATE DATABASE erp_kp_scope_test'); $pdo->exec('USE erp_kp_scope_test');
$pdo->exec("CREATE TABLE n_position (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(120), name_fr VARCHAR(120), description TEXT NULL, kind VARCHAR(16) DEFAULT 'erp',
    id_group INT NULL, id_reports_to INT NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
$pdo->exec('CREATE TABLE n_position_sheet (id_position INT PRIMARY KEY, mission_pl TEXT, mission_fr TEXT, responsibilities_pl TEXT, responsibilities_fr TEXT,
    version INT NOT NULL DEFAULT 1, validated_at DATETIME NULL, validated_by INT NULL)');
foreach (['KIEROWNIK_PRODUKCJI', 'ADMINISTRACJA', 'LOGISTYKA', 'ZAKUPY'] as $i => $c) $pdo->exec("INSERT INTO n_position (code, name_pl, name_fr, sort_order) VALUES ('{$c}', '{$c}', '{$c}', {$i})");
$pdo->exec("INSERT INTO n_position_sheet (id_position, mission_pl, responsibilities_pl, version, validated_at, validated_by) VALUES
    (1, 'Kieruje produkcją.', 'Czas pracy zespołu\nPlan produkcji tygodnia', 3, '2026-11-01 10:00:00', 5), (2, 'Administracja.', 'Zamówienia', 1, '2026-11-01 10:00:00', 5)");
foreach (['216_structure_duties.sql', '224_structure_duties_all.sql'] as $f) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . "/../migrations/tfb/{$f}")) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$hash = fn(string $where) => $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', d.id, d.number, d.area_no, d.text_pl, IFNULL(d.wizard_code, ''), IFNULL(d.proposed_wizard, ''), d.rhythm, d.active, d.sort_order)
    ORDER BY d.id)) FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE {$where}")->fetchColumn();
$othersBefore = $hash("p.code <> 'KIEROWNIK_PRODUKCJI'");
$oldIds = $pdo->query("SELECT id FROM n_position_duty d WHERE id_position = 1")->fetchAll(PDO::FETCH_COLUMN);
$check(count($oldIds) === 17, 'before : Kierownik produkcji has the 17 rows of 224 (7 from the karta, 10 tasks)');

$m237 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/237_production_manager_scope.sql'));
foreach ($m237 as $sql) $pdo->exec($sql);
$kpAfter = $hash("p.code = 'KIEROWNIK_PRODUKCJI'");
$sheetAfter = $pdo->query('SELECT * FROM n_position_sheet WHERE id_position = 1')->fetch();
foreach ($m237 as $sql) $pdo->exec($sql);
$check($hash("p.code = 'KIEROWNIK_PRODUKCJI'") === $kpAfter && $pdo->query('SELECT * FROM n_position_sheet WHERE id_position = 1')->fetch() === $sheetAfter, 'replayed : nothing changes (rows, karta, version)');

$src = 'Zakres obowiązków — produkcja (23.11.2026)';
$new = $pdo->query("SELECT * FROM n_position_duty WHERE id_position = 1 AND source = " . $pdo->quote($src) . ' ORDER BY sort_order')->fetchAll();
$areas = array_count_values(array_map(fn($r) => (int) $r['area_no'], $new));
$check(count($new) === 68 && count($areas) === 11 && $areas[2] === 10 && $areas[11] === 4 && $new[0]['number'] === '1.1' && end($new)['number'] === '11.4', '68 duties, 11 areas (1.1 → 11.4), in the order of the document');
$st = ['ok' => 0, 'proposed' => 0, 'none' => 0];
foreach ($new as $r) $st[$r['wizard_code'] ? 'ok' : ($r['proposed_wizard'] ? 'proposed' : 'none')]++;
$check($st === ['ok' => 22, 'proposed' => 27, 'none' => 19], '22 controlled by an existing assistant, 27 « do utworzenia », 19 without assistant');
$codes = array_unique(array_filter(array_column($new, 'wizard_code')));
$check(array_diff($codes, array_keys(TaskWizards::CATALOG)) === [], 'every wizard_code exists in TaskWizards::CATALOG (' . count($codes) . ' assistants)');
$proposed = array_unique(array_filter(array_column($new, 'proposed_wizard')));
$check(count($proposed) === 9 && array_intersect($proposed, array_column(TaskWizards::CATALOG, 0)) === [], '9 proposed assistants, none named like an existing one');
$check(!array_filter($new, fn($r) => !$r['wizard_code'] && !$r['proposed_wizard'] && $r['rhythm'] !== 'none'), 'rows without any assistant : rhythm « none »');
$by = array_column($new, null, 'number');
$check($by['5.1']['wizard_code'] === 'buy_sent' && $by['3.5']['wizard_code'] === 'complaints_reviewed' && $by['9.3']['wizard_code'] === 'time_day_validated'
    && $by['6.5']['proposed_wizard'] === 'Intrastat miesiąca' && $by['2.1']['proposed_wizard'] === 'PPWR i opakowania (kompletność)' && str_contains($by['8.1']['text_pl'], 'zatwierdza Asia'),
    'spot checks : 5.1 Zakupy tygodnia, 3.5 reklamacje, 9.3 czas pracy, 6.5 Intrastat (proposed), 2.1 PPWR (proposed), 8.1 « zatwierdza Asia » kept');

$old = $pdo->query('SELECT number, source, wizard_code, active, area_no FROM n_position_duty WHERE id_position = 1 AND id IN (' . implode(',', $oldIds) . ')')->fetchAll();
$check(count($old) === 17, 'old rows : still there (deactivated, never deleted)');
$activeOld = array_column(array_filter($old, fn($r) => (int) $r['active'] === 1), 'wizard_code', 'number');
ksort($activeOld);
$check($activeOld === ['Z3' => 'samples_done', 'Z4' => 'sop_daily', 'Z5' => 'forecast_approved', 'Z6' => 'production_day_closed', 'Z7' => 'yield_reviewed'],
    'karta 1 → 7 and tasks now in the zakres (Z1, Z2, Z8 → Z10) deactivated ; samples, SOP, forecast, tracking, yield kept');
$check(!array_filter($old, fn($r) => $r['source'] === 'task' && (int) $r['area_no'] !== 12), 'kept tasks moved to area 12 « Zadania stanowiska (poza zakresem) »');
$dupes = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM n_position_duty GROUP BY id_position, number HAVING COUNT(*) > 1
    UNION ALL SELECT 1 FROM n_position_duty GROUP BY id_position, text_pl HAVING COUNT(*) > 1) x')->fetchColumn();
$check($dupes === 0, 'no duplicate number or text per position');

$s = $sheetAfter;
$lines = PositionSheetService::lines((string) $s['responsibilities_pl']);
$check(count($lines) === 68 && $lines[0] === 'Wprowadzanie nowych produktów do systemu.' && (int) $s['version'] === 4 && $s['validated_at'] === null && $s['validated_by'] === null
    && str_contains((string) $s['mission_pl'], 'dokumentacji wymaganej przepisami prawa') && $s['responsibilities_fr'] === null, 'karta : mission and 68 lines replaced, version 3 → 4, to validate again');
$check($hash("p.code <> 'KIEROWNIK_PRODUKCJI'") === $othersBefore && $pdo->query('SELECT responsibilities_pl FROM n_position_sheet WHERE id_position = 2')->fetchColumn() === 'Zamówienia',
    'other positions (Kierownik operacyjny, Administracja…) : rows and karta untouched');

// Base sans karta du Kierownik produkcji : la karta est créée.
$pdo->exec('DELETE FROM n_position_sheet WHERE id_position = 1'); $pdo->exec('DELETE FROM n_position_duty WHERE id_position = 1');
foreach ($m237 as $sql) $pdo->exec($sql);
$check(count(PositionSheetService::lines((string) $pdo->query('SELECT responsibilities_pl FROM n_position_sheet WHERE id_position = 1')->fetchColumn())) === 68
    && (int) $pdo->query("SELECT COUNT(*) FROM n_position_duty WHERE id_position = 1")->fetchColumn() === 68, 'no karta yet : created, 68 rows');

$pdo->exec('DROP DATABASE erp_kp_scope_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
