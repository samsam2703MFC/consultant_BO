<?php
/**
 * [368] Struktura i obowiązki : migracja 216 (Zarząd, Kierownik operacyjny, Administracja pod nim, 34 obowiązki),
 * organigram (przełożony, cykle, włączenie z palety), matryca (stan, zadanie stanowiska, edycja), wczytanie z kart
 * stanowisk i z pliku Word ; [376] migracja 224 (obowiązki wszystkich stanowisk poza KO : dwa przebiegi bez duplikatów,
 * asystenci z TaskWizards::CATALOG, wiersze KO bez zmian).
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_structure_test recréée (tables minimales + migration 216), supprimée à la fin (ST_JSON_OUT=fichier : données
 * de la page à la fin, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiException;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{PositionDutyRepository, PositionRepository};
use App\Services\Organization\{PositionSheetService, PositionTaskService, StructureService, TaskWizards};
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_structure_test'); $pdo->exec('CREATE DATABASE erp_structure_test'); $pdo->exec('USE erp_structure_test');
$pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, displayed_name VARCHAR(80))');
$pdo->exec("CREATE TABLE n_position (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(120), name_fr VARCHAR(120), description TEXT NULL, kind VARCHAR(16) DEFAULT 'erp',
    id_group INT NULL, id_reports_to INT NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
$pdo->exec('CREATE TABLE n_position_sheet (id_position INT PRIMARY KEY, mission_pl TEXT, mission_fr TEXT, responsibilities_pl TEXT, responsibilities_fr TEXT,
    validated_at DATETIME NULL, validated_by INT NULL, updated_by INT NULL, version INT DEFAULT 1, updated_at DATETIME NULL)');
$pdo->exec('CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT, since DATE NULL)');
$pdo->exec('CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_position INT NULL)');
$pdo->exec("CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_category INT NULL, title_pl VARCHAR(200), title_fr VARCHAR(200),
    frequency VARCHAR(16), link_path VARCHAR(255) NULL, auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
$pdo->exec('CREATE TABLE n_task_category (id INT PRIMARY KEY, name_pl VARCHAR(80))');
$pdo->exec('CREATE TABLE n_wizard_done (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40), day DATE, done_at DATETIME)');
$pdo->exec("INSERT INTO n_position (code, name_pl, name_fr, sort_order, active) VALUES ('ADMINISTRACJA', 'Administracja', 'Administration', 10, 1),
    ('LOGISTYKA', 'Logistyka', 'Logistique', 20, 1), ('ZAKUPY', 'Zakupy', 'Achats', 30, 0)");
$pdo->exec("INSERT INTO n_position (code, name_pl, name_fr, sort_order, active, id_reports_to) VALUES ('KIEROWCA', 'Kierowca', 'Chauffeur', 40, 1, 2)");
$pdo->exec("INSERT INTO n_position_sheet (id_position, responsibilities_pl) VALUES (2, 'Plan transportów\nKontakt z kierowcą\n- Monitorowanie dostaw')");
$pdo->exec("INSERT INTO n_wizard_done (code, day, done_at) VALUES ('payments_reviewed', '2026-11-21', '2026-11-21 08:40:00')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/216_structure_duties.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (ApiException $e) { return implode(',', array_column($e->fieldErrors(), 'code')) ?: $e->errorCode(); } catch (CustomException $e) { return 'custom:' . $e->getCode(); } };
$positions = new PositionRepository($pdo);
$tx = new TransactionManager($pdo);
$tasks = (new ReflectionClass(PositionTaskService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(PositionTaskService::class, 'positions'))->setValue($tasks, $positions);
$svc = new StructureService(new PositionDutyRepository($pdo), $positions, new PositionSheetService($positions, $tx), $tasks, $tx);
$code = fn(string $c) => (int) $pdo->query("SELECT id FROM n_position WHERE code = '{$c}'")->fetchColumn();
[$zarzad, $ko, $admin, $log, $zak, $kier] = [$code('ZARZAD'), $code('KIEROWNIK_OPERACYJNY'), $code('ADMINISTRACJA'), $code('LOGISTYKA'), $code('ZAKUPY'), $code('KIEROWCA')];

// --- Migracja 216
$chart = array_column($svc->chart(), null, 'id');
$check($chart[$zarzad]['reports_to'] === null && $chart[$ko]['reports_to'] === $zarzad && $chart[$admin]['reports_to'] === $ko && $chart[$log]['reports_to'] === null,
    'migration: Zarząd on top, Kierownik operacyjny under Zarząd, Administracja under KO, Logistyka unchanged');
$check($chart[$ko]['duties'] === 34 && $chart[$ko]['covered'] === 16 && $chart[$ko]['proposed'] === 8, 'KO: 34 duties, 16 with a control assistant, 8 proposed (replayed migration: no duplicates)');

// --- Organigram
$check($err(fn() => $svc->move($zarzad, ['reports_to_id' => $admin], 1)) === 'POSITION_CYCLE', 'drag Zarząd under Administracja: refused (cycle)');
$svc->move($log, ['reports_to_id' => $ko], 1);
$check((int) $positions->find($log)['id_reports_to'] === $ko, 'drag Logistyka onto KO: new superior');
$svc->move($log, ['reports_to_id' => null], 1);
$check($positions->find($log)['id_reports_to'] === null, 'drop on the top zone: no superior');
$svc->move($zak, ['reports_to_id' => $ko, 'activate' => true], 1);
$check((int) $positions->find($zak)['active'] === 1 && (int) $positions->find($zak)['id_reports_to'] === $ko, 'from the palette: activated and placed');

// --- Matryca
$m = $svc->matrix();
$rows = array_values(array_filter($m['rows'], fn($r) => $r['position_id'] === $ko));
$r23 = array_values(array_filter($rows, fn($r) => $r['number'] === '2.3'))[0];
$check($m['tiles']['duties'] === 34 && $m['tiles']['ok'] === 16 && $m['tiles']['proposed'] === 8 && $m['tiles']['none'] === 10, 'tiles: 34 = 16 controlled + 8 to create + 10 without assistant');
$check($r23['wizard_code'] === 'payments_reviewed' && $r23['last_done'] === '2026-11-21' && $r23['has_task'] === false && $r23['state'] === 'ok', '2.3 → Płatności dnia, last done 21.11, no position task yet');
$taskId = $svc->addTask($r23['id']);
$t = $pdo->query("SELECT * FROM n_position_task WHERE id = {$taskId}")->fetch();
$check((int) $t['id_position'] === $ko && $t['auto_code'] === 'payments_reviewed' && $t['frequency'] === 'daily' && $t['link_path'] !== null, '« + zadanie » : position task closed by the assistant, daily');
$r23b = array_values(array_filter($svc->matrix()['rows'], fn($r) => $r['id'] === $r23['id']))[0];
$check($r23b['has_task'] === true, 'matrix then shows « zadanie ✓ »');
$r18 = array_values(array_filter($rows, fn($r) => $r['number'] === '1.8'))[0];
$check($err(fn() => $svc->addTask($r18['id'])) === 'custom:422', 'no assistant: no task');
$svc->saveDuty($r18['id'], ['wizard_code' => 'tomorrow_sent', 'rhythm' => 'daily'], 1);
$check(array_values(array_filter($svc->matrix()['rows'], fn($r) => $r['id'] === $r18['id']))[0]['state'] === 'ok', 'assistant chosen in the drawer: controlled');
$check($err(fn() => $svc->saveDuty($r18['id'], ['wizard_code' => 'nope'], 1)) === 'INVALID_CHOICE' || $err(fn() => $svc->saveDuty($r18['id'], ['wizard_code' => 'nope'], 1)) !== 'none', 'unknown assistant refused');
$new = $svc->saveDuty(null, ['position_id' => $log, 'text' => 'Kontrola tachografów', 'proposed' => 'Tachografy', 'rhythm' => 'monthly'], 1);
$check(array_values(array_filter($svc->matrix()['rows'], fn($r) => $r['id'] === $new))[0]['state'] === 'proposed', 'new duty with an assistant to create');
$svc->deleteDuty($new, 1);
$check(!in_array($new, array_column($svc->matrix()['rows'], 'id'), true), 'deleted duty leaves the matrix');

// --- Wczytanie
$n = $svc->importSheets(1);
$logDuties = array_values(array_filter($svc->matrix()['rows'], fn($r) => $r['position_id'] === $log));
$check($n === 3 && count($logDuties) === 3 && $logDuties[2]['text'] === 'Monitorowanie dostaw' && $svc->importSheets(1) === 0, 'from position sheets: 3 duties for Logistyka (bullets cleaned), only once');
// Mały plik Word jak dokument z 21.11 : tytuł, obszary « 1. NAZWA » (nagłówki), punkty listy, podsumowanie bez numeru.
$docx = tempnam(sys_get_temp_dir(), 'docx');
$w = fn(string $t, string $style = '', bool $list = false) => '<w:p><w:pPr>' . ($style ? '<w:pStyle w:val="' . $style . '"/>' : '') . ($list ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '')
    . '</w:pPr><w:r><w:t>' . htmlspecialchars($t, ENT_XML1) . '</w:t></w:r></w:p>';
$xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    . $w('ZAKRES WYKONYWANYCH PRAC I OBOWIĄZKÓW', 'Title') . $w('1. PRODUKCJA – zamówienia', 'Heading3') . $w('Przyjmowanie zamówień.', '', true) . $w('Kontakt z odbiorcami.', '', true)
    . $w('2. LOGISTYKA – transporty', 'Heading3') . $w('Planowanie transportów.', '', true) . $w('PODSUMOWANIE', 'Heading3') . $w('Funkcja koordynacyjna.', '', true) . '</w:body></w:document>';
$zip = new ZipArchive();
$zip->open($docx, ZipArchive::OVERWRITE);
$zip->addFromString('word/document.xml', $xml);
$zip->close();
$p = $svc->parseDocx($docx, 'zakres.docx');
$check($p['count'] === 3 && count($p['areas']) === 2 && $p['areas'][1]['items'][0]['number'] === '2.1' && $p['title'] === 'ZAKRES WYKONYWANYCH PRAC I OBOWIĄZKÓW',
    'Word file: 2 numbered areas, 3 duties, summary left out');
$items = array_map(fn($it) => $it + ['area_no' => 2, 'area' => 'LOGISTYKA'], $p['areas'][1]['items']);
$check($svc->importItems(['position_id' => $kier, 'items' => $items, 'source' => $p['source']], 1) === 1, 'chosen items saved for a position');
unlink($docx);
$bad = tempnam(sys_get_temp_dir(), 'x');
file_put_contents($bad, 'not a zip');
$check($err(fn() => $svc->parseDocx($bad, 'x.docx')) === 'custom:422', 'not a Word file: clear refusal');
unlink($bad);

// [369] Garde : un contrôleur qui appelle $this->userId() doit la définir (sinon chaque écriture → « Błąd serwera »).
$missing = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../App/Controllers', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = (string) file_get_contents($f->getPathname());
    if (!str_contains($src, '$this->userId()') || !preg_match('/namespace\s+([^;]+);.*?\bclass\s+(\w+)/s', $src, $m)) continue;
    $cls = $m[1] . '\\' . $m[2];
    if (!class_exists($cls) || !method_exists($cls, 'userId')) $missing[] = $m[2];
}
$check(!$missing, 'controllers calling $this->userId() define it' . ($missing ? ' — missing : ' . implode(', ', $missing) : ''));

// --- [376] Migracja 224 : obowiązki wszystkich stanowisk (poza Kierownikiem operacyjnym)
$m224 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/224_structure_duties_all.sql'));
$koHash = fn(PDO $db) => $db->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', d.id, d.number, d.text_pl, IFNULL(d.wizard_code, ''), IFNULL(d.proposed_wizard, ''), d.rhythm, d.active, IFNULL(d.source, ''))
    ORDER BY d.id)) FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE p.code = 'KIEROWNIK_OPERACYJNY'")->fetchColumn();
$dupes = fn(PDO $db) => (int) $db->query('SELECT COUNT(*) FROM (SELECT 1 FROM n_position_duty GROUP BY id_position, number HAVING COUNT(*) > 1
    UNION ALL SELECT 1 FROM n_position_duty GROUP BY id_position, text_pl HAVING COUNT(*) > 1) x')->fetchColumn();
// Ta sama baza : Logistyka ma już 3 punkty z karty (importSheets, numery 1–3) — 224 ich nie dubluje.
$before = $koHash($pdo);
for ($k = 0; $k < 2; $k++) foreach ($m224 as $sql) $pdo->exec($sql);
$per = array_column($pdo->query('SELECT p.code, COUNT(*) AS n FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE d.active = 1 GROUP BY p.code')->fetchAll(), 'n', 'code');
$check($koHash($pdo) === $before && (int) $per['KIEROWNIK_OPERACYJNY'] === 34, '224 on the working base: Kierownik operacyjny rows untouched');
$check((int) $per['LOGISTYKA'] === 8 && (int) $per['ADMINISTRACJA'] === 7 && (int) $per['ZAKUPY'] === 6 && (int) $per['KIEROWCA'] === 8 && $dupes($pdo) === 0,
    '224 after importSheets / Word import: missing points only, no duplicate number or text (replayed twice)');

// Baza ze wszystkimi stanowiskami : 216 potem 224 dwa razy.
$pdo->exec('DROP DATABASE IF EXISTS erp_structure_test_224'); $pdo->exec('CREATE DATABASE erp_structure_test_224'); $pdo->exec('USE erp_structure_test_224');
$pdo->exec("CREATE TABLE n_position (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(120), name_fr VARCHAR(120), description TEXT NULL, kind VARCHAR(16) DEFAULT 'erp',
    id_group INT NULL, id_reports_to INT NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
$pdo->exec('CREATE TABLE n_position_sheet (id_position INT PRIMARY KEY, mission_pl TEXT, mission_fr TEXT, responsibilities_pl TEXT, responsibilities_fr TEXT)');
$expected = ['ZAKUPY' => 6, 'JAKOSC' => 7, 'KIEROWNIK_PRODUKCJI' => 17, 'LOGISTYKA' => 8, 'ADMINISTRACJA' => 7, 'BRYGADZISTA' => 6, 'PIEKARZ' => 6, 'TARTY' => 5,
    'ROGAL' => 6, 'CUKIERNIK' => 5, 'GARMAZER' => 6, 'PAKOWACZ' => 6, 'KIEROWCA' => 7];
$ins = $pdo->prepare("INSERT INTO n_position (code, name_pl, name_fr, kind, sort_order, active) VALUES (?, ?, ?, ?, ?, ?)");
foreach (array_keys($expected) as $i => $c) $ins->execute([$c, $c, $c, in_array($c, ['ZAKUPY', 'JAKOSC', 'KIEROWNIK_PRODUKCJI', 'LOGISTYKA', 'ADMINISTRACJA'], true) ? 'erp' : 'atelier', 10 + $i, $c === 'ZAKUPY' ? 0 : 1]);
foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/216_structure_duties.sql')) as $sql) $pdo->exec($sql);
$before = $koHash($pdo);
for ($k = 0; $k < 2; $k++) foreach ($m224 as $sql) $pdo->exec($sql);
$per = array_column($pdo->query("SELECT p.code, COUNT(*) AS n FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE p.code <> 'KIEROWNIK_OPERACYJNY' GROUP BY p.code")->fetchAll(), 'n', 'code');
$per = array_map('intval', $per);
ksort($per); ksort($expected);
$check($per === $expected && !isset($per['ZARZAD']), '224 replayed twice: 92 duties for 13 positions (inactive Zakupy included, Zarząd without sheet: none), no duplicates');
$st = $pdo->query("SELECT SUM(wizard_code IS NOT NULL) AS ok, SUM(wizard_code IS NULL AND proposed_wizard IS NOT NULL) AS proposed, SUM(wizard_code IS NULL AND proposed_wizard IS NULL) AS none,
    SUM(source = 'task') AS task, SUM(source = 'sheet') AS sheet FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE p.code <> 'KIEROWNIK_OPERACYJNY'")->fetch();
$check(array_map('intval', $st) === ['ok' => 46, 'proposed' => 17, 'none' => 29, 'task' => 14, 'sheet' => 78], '224: 46 with an assistant, 17 proposed, 29 without ; 78 from sheets, 14 from tasks');
$codes = array_column($pdo->query('SELECT DISTINCT wizard_code FROM n_position_duty WHERE wizard_code IS NOT NULL')->fetchAll(), 'wizard_code');
$check($codes !== [] && array_diff($codes, array_keys(TaskWizards::CATALOG)) === [], 'every seeded wizard_code exists in TaskWizards::CATALOG');
$proposed = array_column($pdo->query("SELECT DISTINCT d.proposed_wizard FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE p.code <> 'KIEROWNIK_OPERACYJNY' AND d.proposed_wizard IS NOT NULL")->fetchAll(), 'proposed_wizard');
$check(count($proposed) === 9 && array_intersect($proposed, array_column(TaskWizards::CATALOG, 0)) === [], '9 proposed assistants, none named like an existing one (217 → 222 updates never take them)');
$check($koHash($pdo) === $before && (int) $pdo->query("SELECT COUNT(*) FROM n_position_duty d JOIN n_position p ON p.id = d.id_position WHERE p.code = 'KIEROWNIK_OPERACYJNY'")->fetchColumn() === 34
    && $dupes($pdo) === 0, '224 leaves the 34 Kierownik operacyjny rows as they are');
$pdo->exec('DROP DATABASE erp_structure_test_224'); $pdo->exec('USE erp_structure_test');

if (getenv('ST_JSON_OUT')) file_put_contents((string) getenv('ST_JSON_OUT'), json_encode(['chart' => $svc->chart(), 'matrix' => $svc->matrix()], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_structure_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
