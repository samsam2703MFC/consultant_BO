<?php
declare(strict_types=1);
/*
 * [352] Partie surowców → kontrola wejść → próbki (makiety 68 i 68 B) :
 *  - rejestr : pozycje faktur (towar, nie odrzucone) = partie bez numeru (nieaktywne) ; przyjęcie z numerem uzupełnia partię
 *    z faktury tego samego dostawcy (±14 dni), inaczej nowa partia ; po DLC wyłączona ; numer wpisany raz → aktywna ;
 *    przełączniki partii i surowca ; lista tabletu (aktywne, ważne, FEFO) ; numer z tabletu spoza listy → partia « manual » ;
 *  - tablet : licznik « uzupełnione » (surowiec nieśledzony poza licznikiem, powód braku liczy się) ;
 *  - próbki : częstotliwość wg kategorii (partia, tydzień, bez próbek, domyślna), próbka wcześniej w tygodniu, przechowywać
 *    do = dzień + trwałość karty, kod QR z adresu, arkusz A4 (PDF), odłożenie, zadanie zaliczone dopiero po odłożeniu wszystkich.
 *
 *   php tests/material_lots_samples_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_lots_test recréée (tables minimales + migration 209), supprimée à la fin. Aucun envoi.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Workshop\MaterialLotRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SampleRepository;
use App\Services\Workshop\Production\MaterialLotService;
use App\Services\Workshop\Production\SampleService;

function expectLs(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    echo "  ok  {$message}\n";
}

function expectLsFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectLs((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// Bez bazy : kod próbki z treści QR, data przechowywania.
expectLs(SampleService::codeFrom('http://erp.test/workshop/traceability/samples/s/ABCDEFGH2345') === 'ABCDEFGH2345', 'kod z adresu QR');
expectLs(SampleService::codeFrom('http://192.168.1.150/production/q/ABCDEFGH2345') === 'ABCDEFGH2345', 'kod z krótkiego adresu QR (/q/KOD)');
expectLs(SampleService::codeFrom('abcdefgh2345') === 'ABCDEFGH2345' && SampleService::codeFrom('ABC-123') === null, 'kod wpisany ręcznie / nieprawidłowy');
expectLs(SampleService::keepUntil('2026-11-18', 180) === '2027-05-17' && SampleService::keepUntil('2026-11-18', null) === null, 'przechowywać do = dzień + trwałość karty');
expectLs(MaterialLotService::normalize('  l 2611  -0458 ') === 'L 2611 -0458' && MaterialLotService::normalize('  ') === null, 'numer partii : wielkie litery, spacje');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "material_lots_samples_test: PARTIAL (bez bazy : {$e->getMessage()}) — część bez bazy OK\n";
    exit(0);
}
$db = 'erp_lots_test';
$pdo->exec("DROP DATABASE IF EXISTS {$db}");
$pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE {$db}");
try {
    foreach ([
        'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
        'CREATE TABLE category_material (id INT PRIMARY KEY, id_group INT NULL)',
        'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(100), name_fr VARCHAR(100) NULL, id_unit INT NULL, id_category INT NULL)',
        'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(100))',
        'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(100))',
        'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(100), id_category INT NULL, shelf_life INT NULL, ppc INT NULL, active TINYINT DEFAULT 1)',
        'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(50), surname VARCHAR(50))',
        'CREATE TABLE flattened_recipe_ingredient (recipe_id INT, material_id INT, quantity DECIMAL(14,3))',
        "CREATE TABLE n_inv (id INT AUTO_INCREMENT PRIMARY KEY, source ENUM('ksef','pdf') DEFAULT 'ksef', id_supplier INT NULL, number VARCHAR(80), issue_date DATE NULL,
            status VARCHAR(20) DEFAULT 'open', kind VARCHAR(20) DEFAULT 'goods', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        'CREATE TABLE n_inv_line (id INT AUTO_INCREMENT PRIMARY KEY, id_inv INT, line_no SMALLINT, name VARCHAR(200), quantity DECIMAL(14,3), unit VARCHAR(20), id_raw_material INT NULL, factor DECIMAL(14,6) NULL)',
        'CREATE TABLE n_po (id INT AUTO_INCREMENT PRIMARY KEY, id_supplier INT)',
        'CREATE TABLE n_po_line (id INT AUTO_INCREMENT PRIMARY KEY, id_po INT, id_raw_material INT)',
        'CREATE TABLE n_po_receipt (id INT AUTO_INCREMENT PRIMARY KEY, id_po_line INT, received_on DATE, quantity DECIMAL(14,3), lot_number VARCHAR(64) NULL, best_before DATE NULL)',
        'CREATE TABLE n_prod_group (id INT PRIMARY KEY, name_pl VARCHAR(50), name_fr VARCHAR(50) NULL, code VARCHAR(10) NULL, active TINYINT DEFAULT 1, sort_order INT DEFAULT 0)',
        'CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT)',
        "CREATE TABLE n_prod_item (id INT PRIMARY KEY, item_type VARCHAR(10) DEFAULT 'product', id_final_product INT NULL, id_recipe INT NULL)",
        'CREATE TABLE n_prod_output (id BIGINT AUTO_INCREMENT PRIMARY KEY, work_date DATE, id_group INT, id_item INT, quantity DECIMAL(12,3), lot_code VARCHAR(32), id_employee INT NULL,
            id_device INT NULL, note VARCHAR(255) NULL, qc_status VARCHAR(10) NULL, cancelled_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE n_prod_output_lot (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_output BIGINT, id_material INT, quantity DECIMAL(14,3) NULL, lot_number VARCHAR(64) NULL,
            best_before DATE NULL, id_photo BIGINT NULL, ocr_text TEXT NULL, id_employee INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (id_output, id_material))',
        'CREATE TABLE n_prod_output_component (id INT AUTO_INCREMENT PRIMARY KEY, id_output BIGINT, id_source_output BIGINT NULL)',
        'CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value VARCHAR(255), default_value VARCHAR(255), value_type VARCHAR(20), unit VARCHAR(20) NULL,
            description_pl VARCHAR(500) NULL, description_fr VARCHAR(500) NULL, updated_by INT NULL, updated_at DATETIME NULL)',
        'CREATE TABLE n_wizard_done (code VARCHAR(40), day DATE, summary VARCHAR(255), done_by INT NULL, done_at DATETIME, PRIMARY KEY (code, day))',
        'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40))',
        'CREATE TABLE n_task_category (id INT PRIMARY KEY, code VARCHAR(20))',
        "CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_category INT NULL, title_pl VARCHAR(200), title_fr VARCHAR(200),
            frequency VARCHAR(10), due_weekday TINYINT NULL, minutes INT NULL, link_path VARCHAR(255), auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    ] as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO n_position VALUES (3, 'KIEROWNIK_PRODUKCJI')");
    $pdo->exec("INSERT INTO n_task_category VALUES (5, 'PRODUKCJA')");
    // Migracja 209 dwa razy : powtarzalna (tabele, kolumny, parametry, zadanie raz).
    $migration = file_get_contents(__DIR__ . '/../migrations/tfb/209_material_lots_samples.sql');
    for ($i = 0; $i < 2; $i++) $pdo->exec($migration);
    expectLs((int) $pdo->query("SELECT COUNT(*) FROM n_position_task WHERE auto_code = 'samples_done' AND link_path = '/workshop/traceability/samples' AND id_position = 3")->fetchColumn() === 1,
        'migracja 209 powtarzalna : zadanie dnia Kierownika produkcji raz');

    $today = date('Y-m-d');
    $ago = fn(int $d) => date('Y-m-d', strtotime("-{$d} days"));
    $pdo->exec("INSERT INTO unit VALUES (1, 'kg'), (2, 'szt')");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit) VALUES (1, 'Mąka pszenna typ 650', 1), (2, 'Masło 82 %', 1), (3, 'Jaja', 2), (4, 'Sól', 1)");
    $pdo->exec("INSERT INTO supplier VALUES (7, 'Dostawca B'), (8, 'Dostawca D')");
    // Faktury : mąka (Dostawca B, 10 dni temu, 40 worków × 25 kg), masło (Dostawca D) ; faktura odrzucona i usługa pominięte.
    $pdo->exec("INSERT INTO n_inv (id, id_supplier, number, issue_date, status, kind) VALUES (1, 7, 'FV 2026/11/0345', '{$ago(10)}', 'checked', 'goods'),
        (2, 8, 'FA/2026/1123', '{$ago(4)}', 'open', 'goods'), (3, 7, 'FV odrzucona', '{$ago(3)}', 'rejected', 'goods'), (4, 7, 'FV usługa', '{$ago(3)}', 'open', 'services')");
    $pdo->exec("INSERT INTO n_inv_line (id, id_inv, line_no, name, quantity, unit, id_raw_material, factor) VALUES (11, 1, 1, 'MAKA T650 25KG', 40, 'worek', 1, 25),
        (12, 2, 1, 'MASLO 82%', 120, 'kg', 2, NULL), (13, 3, 1, 'MASLO', 10, 'kg', 2, 1), (14, 4, 1, 'Transport', 1, 'usł', 2, 1), (15, 1, 2, 'Nieprzypisana', 5, 'kg', NULL, 1)");
    // Przyjęcia : mąka 9 dni temu z partią (→ faktura 11), jaja bez faktury, masło po dacie ważności.
    $pdo->exec("INSERT INTO n_po (id, id_supplier) VALUES (1, 7), (2, 8)");
    $pdo->exec("INSERT INTO n_po_line (id, id_po, id_raw_material) VALUES (1, 1, 1), (2, 2, 3), (3, 2, 2)");
    $pdo->exec("INSERT INTO n_po_receipt (id, id_po_line, received_on, quantity, lot_number, best_before) VALUES
        (1, 1, '{$ago(9)}', 40, 'l 2611-0458', '" . date('Y-m-d', strtotime('+180 days')) . "'),
        (2, 2, '{$ago(2)}', 360, 'J-1811', '" . date('Y-m-d', strtotime('+20 days')) . "'),
        (3, 3, '{$ago(40)}', 10, 'B-77812', '{$ago(1)}')");

    $repo = new MaterialLotRepository($pdo);
    $lots = new MaterialLotService($repo);
    $s = $lots->sync($today);
    expectLs($s['invoices'] === 2 && $s['receipts'] === 3, 'synchronizacja : 2 pozycje faktur (bez odrzuconej, usługi, nieprzypisanej) i 3 przyjęcia ; ' . json_encode($s));
    $flour = $pdo->query('SELECT * FROM n_material_lot WHERE id_inv_line = 11')->fetch();
    expectLs($flour['lot_number'] === 'L 2611-0458' && (int) $flour['id_po_receipt'] === 1 && (int) $flour['active'] === 1 && (float) $flour['quantity'] === 1000.0,
        'przyjęcie uzupełnia partię z faktury tego dostawcy (numer, DLC, aktywna) ; ilość 40 × 25 = 1 000 kg');
    $butter = $pdo->query('SELECT * FROM n_material_lot WHERE id_inv_line = 12')->fetch();
    expectLs($butter['lot_number'] === null && (int) $butter['active'] === 0 && $butter['off_reason'] === 'no_number', 'faktura bez przyjęcia : partia bez numeru, nieaktywna');
    $old = $pdo->query('SELECT * FROM n_material_lot WHERE id_po_receipt = 3')->fetch();
    expectLs($old['source'] === 'receipt' && (int) $old['active'] === 0 && $old['off_reason'] === 'expired', 'przyjęcie po DLC (inny dostawca niż faktura) : osobna partia, wyłączona');
    $again = $lots->sync($today);
    expectLs($again['invoices'] === 0 && $again['receipts'] === 0, 'druga synchronizacja nic nie dubluje');

    $tab = $lots->forTablet([1, 2, 3]);
    expectLs(count($tab[1] ?? []) === 1 && $tab[1][0]['lot_number'] === 'L 2611-0458' && $tab[1][0]['supplier'] === 'Dostawca B' && $tab[1][0]['invoice'] === 'FV 2026/11/0345'
        && !isset($tab[2]) && ($tab[3][0]['lot_number'] ?? null) === 'J-1811', 'lista tabletu : tylko aktywne z numerem, z dostawcą i fakturą');
    expectLsFail(fn() => $lots->setActive((int) $butter['id'], true, 1), 422, 'partia bez numeru nie włącza się');
    $lots->setNumber((int) $butter['id'], 'm-2611-221', date('Y-m-d', strtotime('+12 days')), 1);
    expectLs(($lots->forTablet([2])[2][0]['lot_number'] ?? null) === 'M-2611-221', 'numer wpisany w rejestrze : partia aktywna na liście');
    expectLsFail(fn() => $lots->setActive((int) $old['id'], true, 1), 422, 'partia po DLC nie włącza się');
    $lots->setActive((int) $butter['id'], false, 1);
    expectLs(!isset($lots->forTablet([2])[2]), 'partia wyłączona ręcznie : poza listą tabletu');
    $lots->setActive((int) $butter['id'], true, 1);
    $lots->setTracked(4, false, 1);
    expectLs($lots->tracked([1, 4]) === [1 => true, 4 => false], 'surowiec nieśledzony (sól), reszta domyślnie śledzona');
    expectLsFail(fn() => $lots->setTracked(999, false, 1), 404, 'nieznany surowiec');
    $reg = $lots->register();
    $byName = array_column($reg['materials'], null, 'name');
    expectLs(count($reg['materials']) === 3 && $byName['Mąka pszenna typ 650']['tracked'] && count($byName['Masło 82 %']['lots']) === 2 && $reg['no_number'] === 0,
        'rejestr pogrupowany po surowcu ; bez numeru : 0');
    $manual = $lots->rememberManual(3, 'j-9999', null, $today);
    expectLs($manual > 0 && $lots->rememberManual(3, 'J-9999', null, $today) === $manual && $pdo->query("SELECT source FROM n_material_lot WHERE id = {$manual}")->fetchColumn() === 'manual',
        'numer z tabletu spoza listy : partia « manual » w rejestrze, bez dubli');
    expectLs($lots->pick((int) $old['id'], 2) === null && $lots->pick((int) $flour['id'], 2) === null && $lots->pick((int) $flour['id'], 1) !== null,
        'wybór z listy : tylko aktywna partia tego surowca');

    // --- Tablet : licznik « uzupełnione » ------------------------------------------------------------------------------
    $pdo->exec("INSERT INTO category_product VALUES (1, 'Pieczywo'), (2, 'Ciasta'), (3, 'Napoje')");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, shelf_life, ppc) VALUES (101, 'Brioche – 400 g', 1, 180, 8), (102, 'Galette frangipane', 2, 30, 6), (103, 'Sok', 3, 10, 12)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, id_recipe) VALUES (11, 'product', 101, 500), (12, 'product', 102, 501), (13, 'product', 103, NULL)");
    $pdo->exec("INSERT INTO flattened_recipe_ingredient VALUES (500, 1, 0.5), (500, 2, 0.2), (500, 3, 4), (500, 4, 0.01)");
    $pdo->exec("INSERT INTO n_prod_group VALUES (1, 'Piekarnia', NULL, 'PIEK', 1, 1)");
    $pdo->exec("INSERT INTO production_employee VALUES (1, 'Jan', 'Test')");
    $pdo->exec("INSERT INTO n_prod_employee VALUES (1, 1)");
    $pdo->exec("INSERT INTO n_prod_output (id, work_date, id_group, id_item, quantity, lot_code, id_employee, created_at) VALUES
        (1, '{$today}', 1, 11, 80, 'A-01', 1, '{$today} 06:00'), (2, '{$today}', 1, 11, 40, 'A-02', 1, '{$today} 07:00'),
        (3, '{$today}', 1, 12, 30, 'A-03', 1, '{$today} 08:00'), (4, '{$today}', 1, 12, 30, 'A-04', 1, '{$today} 09:00'), (5, '{$today}', 1, 13, 24, 'A-05', 1, '{$today} 10:00')");
    $out = new OutputRepository($pdo);
    $out->saveLot(1, 1, 5.0, 'L 2611-0458', null, null, null, 1, (int) $flour['id'], null);
    $out->saveLot(1, 2, 2.0, null, null, 9, null, 1, null, 'Etykieta nieczytelna');
    $out->saveLot(1, 4, 0.1, 'SOL-1', null, null, null, 1, null, null);
    $rec = array_column($out->recipe(500), 'tracked', 'material_id');
    expectLs((int) $rec[4] === 0 && (int) $rec[1] === 1, 'receptura : sól nieśledzona');
    $day = array_column($out->day($today, 1), 'lots_done', 'id');
    expectLs((int) $day[1] === 2 && (int) $day[2] === 0, 'licznik : mąka z listy + masło z powodem = 2 ; sól nieśledzona poza licznikiem ; ' . json_encode($day));
    expectLs((int) $pdo->query('SELECT id_material_lot FROM n_prod_output_lot WHERE id_output = 1 AND id_material = 1')->fetchColumn() === (int) $flour['id'],
        'partia z listy zapisana z identyfikatorem rejestru');
    $reg = $lots->register('mąka');
    expectLs(count($reg['materials']) === 1 && $reg['materials'][0]['used'] === 1, 'rejestr : użycie w produkcji (1 partia wyrobu) ; szukanie po nazwie');

    // --- Próbki ------------------------------------------------------------------------------------------------------
    $params = new ParamRepository($pdo);
    $sr = new SampleRepository($pdo);
    $samples = new SampleService($sr, $params);
    $samples->setRule(1, 'lot', 1);
    $samples->setRule(3, 'none', 1);
    expectLsFail(fn() => $samples->setRule(1, 'hourly', 1), 422, 'nieznana częstotliwość');
    expectLsFail(fn() => $samples->setRule(99, 'week', 1), 404, 'nieznana kategoria');
    $p = $samples->page($today);
    $rows = array_column($p['rows'], null, 'lot_code');
    expectLs($p['rules']['default'] === 'week' && $rows['A-01']['suggested'] && $rows['A-02']['suggested'], 'Pieczywo « każda partia » : obie partie');
    expectLs($rows['A-03']['suggested'] && !$rows['A-04']['suggested'] && $rows['A-04']['reason'] === 'already_week', 'Ciasta (domyślnie tydzień) : pierwsza partia, druga już w tygodniu');
    expectLs(!$rows['A-05']['suggested'] && $rows['A-05']['frequency'] === 'none', 'Napoje « bez próbek »');
    expectLs($rows['A-01']['keep_until'] === date('Y-m-d', strtotime($today . ' +180 days')), 'przechowywać do = DLC z karty (180 dni)');
    expectLsFail(fn() => $samples->finish($today, 1), 409, 'zadanie : są partie do próbek — nie zaliczone bez próbek');
    expectLsFail(fn() => $samples->pdf($today, 'http://erp.test'), 404, 'arkusz bez próbek : 404');
    $n = $samples->create($today, [1, 2, 3], 1);
    $p = $samples->page($today);
    expectLs($n === 3 && $p['counts']['samples'] === 3 && $p['step'] === 2 && strlen($p['samples'][0]['code']) === 12, '3 próbki utworzone, krok 2 (druk)');
    expectLs($samples->create($today, [1], 1) === 0, 'partia z próbką : bez drugiej próbki');
    expectLsFail(fn() => $samples->create($today, [999], 1), 422, 'partia spoza dnia');
    $file = $samples->pdf($today, 'http://erp.test');
    expectLs(str_starts_with($file['file_string'], '%PDF') && $file['filename'] === 'probki-' . $today . '.pdf', 'arkusz PDF z kodami QR');
    expectLs(preg_match_all('#/Type\s*/Page[^s]#', $file['file_string']) === 3, '1 strona A4 na próbkę (3 próbki = 3 strony)');
    if (($out = getenv('LS_PDF_OUT')) !== false && $out !== '') file_put_contents($out, $file['file_string']); // podgląd arkusza
    $p = $samples->page($today);
    expectLs($p['counts']['printed'] === 3 && $p['step'] === 3, 'po wydruku : krok 3 (odłożenie)');
    $codes = array_column($p['samples'], 'code', 'lot_code');
    $st = $samples->store('http://erp.test/workshop/traceability/samples/s/' . $codes['A-01'], 1);
    expectLs($st['status'] === 'stored' && $st['location'] === 'Zamrażarka próbek' && !$st['already'], 'skan adresu QR : próbka odłożona (miejsce z parametru)');
    expectLs($samples->store($codes['A-01'], 1)['already'], 'drugi skan : już odłożona');
    $card = $samples->card('http://erp.test/q/' . $codes['A-02']);
    expectLs($card !== null && $card['lot_code'] === 'A-02' && $card['status'] === 'printed' && is_array($card['lot_rows']) && $samples->card('ZZZZZZZZZZZZ') === null,
        'karta próbki z kodu QR (/q/KOD) : partia, stan, partie surowców ; nieznany kod : brak');
    expectLsFail(fn() => $samples->store('ZZZZZZZZZZZZ', 1), 404, 'nieznany kod');
    expectLsFail(fn() => $samples->finish($today, 1), 409, 'zadanie : nie wszystkie próbki odłożone');
    $removable = $p['samples'][array_search('A-03', array_column($p['samples'], 'lot_code'), true)]['id'];
    $samples->remove($removable);
    expectLsFail(fn() => $samples->remove((int) $st['id']), 409, 'próbka odłożona nie usuwa się');
    expectLs($samples->store($codes['A-02'], null, 'półka 2')['stored_by'] === null, 'odłożona z telefonu bez logowania (bez osoby), miejsce z formularza');
    $done = $samples->finish($today, 1);
    expectLs($done['done'] !== null && $done['done']['summary'] === '2 próbki odłożone', 'wszystkie odłożone : zadanie zaliczone (n_wizard_done)');
    // Następny dzień tygodnia : Ciasta mają już próbkę w tym tygodniu ? (A-03 usunięta → nie) ; Pieczywo każda partia.
    $p2 = $samples->page($today);
    expectLs(array_column($p2['rows'], 'suggested', 'lot_code')['A-03'] === true, 'próbka usunięta : partia znów proponowana');
} finally {
    if (!getenv('LS_KEEP_DB')) $pdo->exec("DROP DATABASE IF EXISTS {$db}"); // LS_KEEP_DB=1 : baza zostaje (podgląd stron)
}
echo "material_lots_samples_test: OK\n";
