<?php
declare(strict_types=1);
/*
 * [353] Procedury SOP (makiety 69 i 69 B) : migracja 210 (powtarzalna : tabele, prawo « sop », menu Kierownika produkcji,
 * 3 pierwsze procedury przypięte do asystentów, zadanie « SOP dnia ») ; asystent (walidacja etapów, kod z prefiksu, zdjęcie /
 * zrzut ekranu w bazie, przypięcia) ; zatwierdzenie (wersja, przegląd, zadanie dnia) ; przycisk « Procedura » na stronie ;
 * procedura asystowana (potwierdzenia wg typu, wartość poza zakresem → niezgodność NC) ; asystenci bez procedury ; PDF A4.
 *
 *   php tests/sop_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_sop_test recréée (tables minimales + migration 210), supprimée à la fin (SOP_KEEP_DB=1 : conservée). Aucun envoi.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Purchasing\NcRepository;
use App\Repositories\Workshop\SopRepository;
use App\Services\Purchasing\NcService;
use App\Services\Workshop\Production\SopService;

function expectSop(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    echo "  ok  {$message}\n";
}

function expectSopFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectSop((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// Bez bazy : ścieżka strony.
expectSop(SopService::path('/workshop/supply#tab=invoices&inv=wizard') === '/workshop/supply' && SopService::path('/sop/?x=1') === '/sop'
    && SopService::path('http://x/a') === null && SopService::path('/a b') === null, 'ścieżka strony bez # i ?, tylko ścieżki ERP');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "sop_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$db = 'erp_sop_test';
$pdo->exec("DROP DATABASE IF EXISTS {$db}");
$pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE {$db}");
try {
    foreach ([
        'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(50), displayed_name VARCHAR(100))',
        'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40))',
        'CREATE TABLE n_task_category (id INT PRIMARY KEY, code VARCHAR(20))',
        'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), PRIMARY KEY (id_position, page_key))',
        'CREATE TABLE n_position_menu (id_position INT, item VARCHAR(32), open_path VARCHAR(120) NULL, PRIMARY KEY (id_position, item))',
        "CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_category INT NULL, title_pl VARCHAR(200), title_fr VARCHAR(200),
            frequency VARCHAR(10), due_weekday TINYINT NULL, minutes INT NULL, link_path VARCHAR(255), auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
        "CREATE TABLE n_nc (id INT AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20), reported_at DATE, source VARCHAR(30), id_supplier INT NULL, id_raw_material INT NULL,
            id_final_product INT NULL, description TEXT, severity VARCHAR(10) NULL, quantity DECIMAL(12,3) NULL, unit VARCHAR(16) NULL, immediate_action TEXT NULL,
            root_cause TEXT NULL, corrective_action TEXT NULL, cost_impact DECIMAL(12,2) NULL, status VARCHAR(12) DEFAULT 'open', owner_user INT NULL, due_date DATE NULL,
            closed_at DATETIME NULL, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    ] as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO user VALUES (5, 'anna', 'Anna Kierownik')");
    $pdo->exec("INSERT INTO n_position VALUES (3, 'KIEROWNIK_PRODUKCJI'), (4, 'JAKOSC'), (6, 'LOGISTYKA')");
    $pdo->exec("INSERT INTO n_task_category VALUES (5, 'PRODUKCJA')");
    $pdo->exec("INSERT INTO n_page_access VALUES (6, 'production')");
    $migration = file_get_contents(__DIR__ . '/../migrations/tfb/210_sop.sql');
    for ($i = 0; $i < 2; $i++) $pdo->exec($migration);
    expectSop((int) $pdo->query("SELECT COUNT(*) FROM n_sop")->fetchColumn() === 3 && (int) $pdo->query("SELECT COUNT(*) FROM n_sop_step")->fetchColumn() === 16
        && (int) $pdo->query("SELECT COUNT(*) FROM n_position_task WHERE auto_code = 'sop_daily' AND id_position = 3")->fetchColumn() === 1,
        'migracja 210 powtarzalna : 3 procedury (6 + 5 + 5 etapów), zadanie « SOP dnia » raz');
    expectSop((int) $pdo->query("SELECT COUNT(*) FROM n_page_access WHERE page_key = 'sop'")->fetchColumn() === 3
        && (int) $pdo->query("SELECT COUNT(*) FROM n_position_menu WHERE item = 'sop'")->fetchColumn() === 2, 'prawo « sop » : Kierownik, Jakość i kto ma Produkcję ; menu : Kierownik i Jakość');

    $repo = new SopRepository($pdo);
    $tx = new TransactionManager($pdo);
    $sop = new SopService($repo, $tx, new NcService(new NcRepository($pdo)));
    $list = array_column($sop->list(), null, 'code');
    expectSop($list['SOP-JAK-001']['status'] === 'draft' && $list['SOP-JAK-001']['links'] === ['/workshop/traceability/samples'] && $list['SOP-ZAK-002']['links'] === ['/settings/ksef/purchase-invoices', '/workshop/supply'],
        'pierwsze procedury : szkice przypięte do Próbek, asystenta cen i faktur KSeF');
    $here = $sop->forPath('/workshop/supply#tab=invoices&inv=wizard');
    expectSop(count($here) === 2 && $here[0]['code'] === 'SOP-ZAK-001', 'przycisk « Procedura » na Zaopatrzeniu : 2 procedury');
    $missing = array_column($sop->suggestions(), 'path');
    expectSop(in_array('/production/tomorrow', $missing, true) && !in_array('/workshop/traceability/samples', $missing, true) && !in_array('/workshop/supply', $missing, true),
        'asystenci bez procedury : np. Plan na jutro ; Próbki i faktury mają');

    // Asystent : walidacja, zdjęcie (zrzut ekranu PNG w bazie), kod z prefiksu.
    $png = imagecreatetruecolor(320, 180);
    imagefill($png, 0, 0, imagecolorallocate($png, 230, 220, 205));
    imagestring($png, 5, 20, 80, 'Mikser spiralny', imagecolorallocate($png, 40, 30, 20));
    ob_start(); imagepng($png); $bytes = ob_get_clean();
    $photo = $sop->uploadPhoto('data:image/png;base64,' . base64_encode($bytes), 5);
    expectSop($photo > 0 && $sop->photo($photo)['mime'] === 'image/png' && strlen($sop->photo($photo)['bytes']) === strlen($bytes), 'zrzut ekranu zapisany w bazie');
    expectSopFail(fn() => $sop->uploadPhoto('data:image/png;base64,' . base64_encode('nie obraz' . str_repeat('x', 200)), 5), 422, 'plik, który nie jest obrazem');
    expectSopFail(fn() => $sop->save(null, ['title' => ''], 5), 422, 'tytuł wymagany');
    expectSopFail(fn() => $sop->save(null, ['title' => 'X', 'steps' => [['title' => 'A', 'confirm' => 'value']]], 5), 422, 'wartość bez zakresu');
    expectSopFail(fn() => $sop->save(null, ['title' => 'X', 'steps' => [['title' => 'A', 'confirm' => 'value', 'value_min' => 70, 'value_max' => 60]]], 5), 422, 'min. > maks.');
    expectSopFail(fn() => $sop->save(null, ['title' => 'X', 'steps' => [['title' => 'A', 'photo_id' => 99999]]], 5), 422, 'nieznane zdjęcie');
    $id = $sop->save(null, ['prefix' => 'PROD', 'title' => 'Mycie i dezynfekcja miksera spiralnego', 'area' => 'Piekarnia · Mikser spiralny', 'ppe' => 'rękawice',
        'review_months' => 6, 'links' => ['/workshop/production-days?x=1', 'zly link'],
        'steps' => [
            ['title' => 'Wyłącz mikser i zablokuj wyłącznik', 'tag' => 'bhp', 'confirm' => 'check', 'photo_id' => $photo],
            ['title' => 'Umyj dzieżę roztworem 2 %', 'tag' => 'haccp', 'minutes' => 10, 'confirm' => 'value', 'value_min' => '55', 'value_max' => '65', 'value_unit' => '°C'],
            ['title' => 'Zdjęcie czystej dzieży', 'confirm' => 'photo'],
        ]], 5);
    $s = $sop->get($id);
    expectSop($s['code'] === 'SOP-PROD-001' && $s['status'] === 'draft' && $s['version'] === 0 && count($s['steps']) === 3 && $s['steps'][1]['value_min'] === 55.0
        && $s['links'] === ['/workshop/production-days'], 'nowa procedura : kod SOP-PROD-001, szkic, 3 etapy, przypięcie (zły link pominięty)');
    expectSop(!$sop->daily(date('Y-m-d'))['done'], 'zadanie « SOP dnia » : utworzenie bez zatwierdzenia nie wystarcza');

    // Zatwierdzenie (Kierownik produkcji).
    $a = $sop->approve($id, 5, '2026-11-19');
    expectSop($a['status'] === 'active' && $a['version'] === 1 && !$a['changed'] && $a['review_at'] === '2027-05-19', 'zatwierdzenie : v1 obowiązuje, przegląd za 6 miesięcy');
    expectSopFail(fn() => $sop->approve($id, 5), 409, 'bez zmian : druga wersja nie zatwierdza się');
    $d = $sop->daily(date('Y-m-d'));
    expectSop($d['done'] && str_contains($d['summary'], 'SOP-PROD-001 v1'), 'zadanie « SOP dnia » zaliczone (wersja zatwierdzona dziś)');
    $sop->save($id, ['title' => 'Mycie i dezynfekcja miksera spiralnego', 'review_months' => 6, 'steps' => $s['steps'], 'links' => $s['links']], 5);
    expectSop($sop->get($id)['changed'] && $sop->approve($id, 5)['version'] === 2, 'poprawka : zmiany → v2');

    // Procedura asystowana.
    $steps = $sop->get($id)['steps'];
    $jpg = imagecreatetruecolor(200, 120); ob_start(); imagejpeg($jpg); $shot = 'data:image/jpeg;base64,' . base64_encode(ob_get_clean());
    expectSopFail(fn() => $sop->run($id, ['steps' => [['step_id' => $steps[0]['id'], 'done' => true]]], 5), 422, 'wykonanie : etap 2 niepotwierdzony');
    expectSopFail(fn() => $sop->run($id, ['steps' => [['step_id' => $steps[0]['id'], 'done' => true], ['step_id' => $steps[1]['id'], 'value' => 'abc'], ['step_id' => $steps[2]['id'], 'photo' => $shot]]], 5), 422, 'wykonanie : wartość nie jest liczbą');
    expectSopFail(fn() => $sop->run($id, ['steps' => [['step_id' => $steps[0]['id'], 'done' => true], ['step_id' => $steps[1]['id'], 'value' => '60'], ['step_id' => $steps[2]['id']]]], 5), 422, 'wykonanie : brak zdjęcia');
    $ok = $sop->run($id, ['source_path' => '/workshop/production-days', 'started_at' => date('Y-m-d H:i:s', time() - 600),
        'steps' => [['step_id' => $steps[0]['id'], 'done' => true], ['step_id' => $steps[1]['id'], 'value' => '61,5'], ['step_id' => $steps[2]['id'], 'photo' => $shot]]], 5);
    expectSop($ok['result'] === 'ok' && $ok['nc_id'] === null, 'wykonanie w zakresie : OK, bez niezgodności');
    $bad = $sop->run($id, ['steps' => [['step_id' => $steps[0]['id'], 'done' => true], ['step_id' => $steps[1]['id'], 'value' => '48'], ['step_id' => $steps[2]['id'], 'photo' => $shot]]], 5);
    $nc = $pdo->query('SELECT * FROM n_nc WHERE id = ' . (int) $bad['nc_id'])->fetch();
    expectSop($bad['result'] === 'deviation' && $nc && $nc['source'] === 'internal' && str_contains($nc['description'], '48') && str_contains($nc['description'], 'SOP-PROD-001'),
        'wartość poza zakresem : odchylenie + niezgodność w rejestrze (internal)');
    $runs = $sop->runs($id);
    expectSop(count($runs) === 2 && $runs[0]['result'] === 'deviation' && $runs[0]['nc'] === $nc['number'] && $runs[1]['minutes'] >= 9 && $runs[1]['user'] === 'Anna Kierownik'
        && $runs[1]['source'] === '/workshop/production-days' && isset($runs[1]['detail'][2]['photo_id']), 'zakładka « Wykonania » : wynik, NC, czas, osoba, skąd, zdjęcie wykonania');

    // PDF A4 ze zdjęciami ; archiwum.
    $pdf = $sop->pdf($id, 'http://erp.test');
    expectSop(str_starts_with($pdf['file_string'], '%PDF') && $pdf['filename'] === 'sop-prod-001-v2.pdf', 'SOP w PDF A4 (zdjęcia etapów, QR)');
    if (($out = getenv('SOP_PDF_OUT')) !== false && $out !== '') file_put_contents($out, $pdf['file_string']);
    $sop->archive($id, 5);
    expectSop($sop->forPath('/workshop/production-days') === [] && in_array('/workshop/production-days', array_column($sop->suggestions(), 'path'), true) === false,
        'archiwum : przycisk znika ze strony');
    expectSopFail(fn() => $sop->run($id, ['steps' => []], 5), 409, 'archiwum : bez wykonań');
} finally {
    if (!getenv('SOP_KEEP_DB')) $pdo->exec("DROP DATABASE IF EXISTS {$db}");
}
echo "sop_test: OK\n";
