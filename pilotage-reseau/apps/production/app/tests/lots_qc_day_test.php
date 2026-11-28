<?php
/**
 * [377] Asystent « Partie i kontrola dnia » : fenêtre (jour ouvré précédent → aujourd'hui, rattrapage depuis la dernière
 * validation borné par lots_qc.catchup_days) ; journées de groupe closes seulement (non closes : info) ; trous par partie
 * — surowiec suivi sans lot (même calcul que le kiosque : recette aplatie, CompositionCut, n_material_trace), brak partii
 * avec motif, sans contrôle qualité, lot périmé au jour de production, lot du registre sans numéro ; parties annulées
 * ignorées ; note de clôture ; résultat par journée (uzupełnione vérifié, NC / zaakceptowane avec note), « Uzupełnione »
 * rouvert si un trou revient ; étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche du jour ; aucune écriture
 * hors des tables de l'assistant ; contrôleur construit par PHP-DI et appelé (data, result, finish ; droit « production ») ;
 * enregistrement (catalogue, droits, routes, paramètre) ; migration 225 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_lots_qc_day_test recréée (tables minimales + migrations 209 et 225), supprimée à la fin (LQ_JSON_OUT=fichier :
 * données de la page avant et après les résultats, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Production\LotsQcDayController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\{LotsQcDayRepository, OutputRepository, ParamRepository, RecipeProducerRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\LotsQcDayService;
use App\Services\Workshop\Execution\CompositionCut;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_lots_qc_day_test'); $pdo->exec('CREATE DATABASE erp_lots_qc_day_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_lots_qc_day_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    "CREATE TABLE n_prod_group (id INT PRIMARY KEY, code VARCHAR(32), name_pl VARCHAR(100), name_fr VARCHAR(100), color CHAR(7) NOT NULL DEFAULT '#8D1D2C', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    'CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(50), surname VARCHAR(50))',
    'CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT NOT NULL)',
    'CREATE TABLE n_prod_day_close (id BIGINT AUTO_INCREMENT PRIMARY KEY, work_date DATE NOT NULL, id_group INT NOT NULL, id_employee INT NULL, id_device INT NULL, note VARCHAR(255) NULL,
        summary TEXT NULL, closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (work_date, id_group))',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE category_material (id INT PRIMARY KEY, id_group INT NULL)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(100), name_fr VARCHAR(100) NULL, id_unit INT NULL, id_category INT NULL)',
    'CREATE TABLE product_recipe (id INT PRIMARY KEY, name VARCHAR(100), yield_quantity DECIMAL(12,3) DEFAULT 1, id_unit INT NULL)',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(100), id_recipe INT NULL, id_category INT NULL, ppc INT NULL, active TINYINT DEFAULT 1)',
    'CREATE TABLE flattened_recipe_ingredient (recipe_id INT, material_id INT, quantity DECIMAL(14,3))',
    'CREATE TABLE product_recipe_connection (id INT AUTO_INCREMENT PRIMARY KEY, parent_recipe_id INT, child_recipe_id INT NULL, child_ingredient_id INT NULL, quantity DECIMAL(14,3))',
    'CREATE TABLE n_recipe_producer (id_recipe INT PRIMARY KEY, id_group INT NOT NULL, lead_days TINYINT NULL)',
    "CREATE TABLE n_prod_item (id INT PRIMARY KEY, item_type ENUM('product','semi') NOT NULL, id_final_product INT NULL, id_recipe INT NULL, base_unit VARCHAR(8) NOT NULL DEFAULT 'szt')",
    "CREATE TABLE n_prod_output (id BIGINT AUTO_INCREMENT PRIMARY KEY, work_date DATE NOT NULL, id_group INT NOT NULL, id_item INT NOT NULL, quantity DECIMAL(12,3) NOT NULL, lot_code VARCHAR(32) NOT NULL,
        id_employee INT NULL, id_device INT NULL, client_uuid CHAR(36) NULL, note VARCHAR(255) NULL, qc_status ENUM('ok','nc') NULL, qc_at DATETIME NULL, qc_employee INT NULL, qc_note VARCHAR(255) NULL,
        id_nc INT NULL, cancelled_at DATETIME NULL, cancelled_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (lot_code))",
    'CREATE TABLE n_prod_output_lot (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_output BIGINT NOT NULL, id_material INT NOT NULL, quantity DECIMAL(14,3) NULL, lot_number VARCHAR(64) NULL, best_before DATE NULL,
        id_photo BIGINT NULL, ocr_text TEXT NULL, id_employee INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (id_output, id_material))',
    'CREATE TABLE n_prod_output_component (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_output BIGINT NOT NULL, id_recipe INT NOT NULL, id_source_output BIGINT NULL, quantity DECIMAL(14,3) NULL, unit VARCHAR(8) NULL,
        id_employee INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (id_output, id_recipe))',
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    'CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)',
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
] as $sql) $pdo->exec($sql);
// Registre des lots matières et surowiec suivi (209) : leurs CREATE TABLE ; colonnes id_material_lot / missing_reason : ALTER de 209.
foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/209_material_lots_samples.sql')) as $sql) {
    if (str_starts_with(ltrim($sql), 'CREATE TABLE') || str_starts_with(ltrim($sql), 'SET @') || str_starts_with(ltrim($sql), 'PREPARE') || str_starts_with(ltrim($sql), 'EXECUTE') || str_starts_with(ltrim($sql), 'DEALLOCATE')) $pdo->exec($sql);
}
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order, active) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 3, 1), (2, 'LOGISTYKA', 'Logistyka', 6, 1), (3, 'PIEKARZ', 'Piekarz', 7, 1)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '5', NULL, 'Partie i kontrola dnia', 'daily'), (3, '4', NULL, 'Partie i kontrola dnia', 'daily'),
    (1, '1', NULL, 'HACCP miesiąca', 'monthly'), (1, 'Z1', 'receiving_checked', NULL, 'daily')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/225_lots_qc_day.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 225 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'lots_qc_day_checked' && $duties[0]['proposed_wizard'] === null && $duties[1]['wizard_code'] === 'lots_qc_day_checked' && $duties[1]['proposed_wizard'] === null,
    'migration 225: JAKOSC 5 and PIEKARZ 4 → lots_qc_day_checked');
$check($duties[2]['proposed_wizard'] === 'HACCP miesiąca' && $duties[2]['wizard_code'] === null && $duties[3]['wizard_code'] === 'receiving_checked', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'lots_qc.%'")->fetchAll(), 'value', 'code');
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_lots_qc_check'")->fetchColumn();
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_lots_qc_check'")->fetchColumn();
$check($params === ['lots_qc.catchup_days' => '7'] && $tables === 1 && $fks === 0, 'migration replayed: 1 param once, 1 table, no foreign key');
$check(ParamRules::RANGES['lots_qc.catchup_days'] === [1, 31, true], 'ParamRules bounds for lots_qc.catchup_days');

// --- Données : contrôle le mar. 24.11.2026 → fenêtre lun. 23.11 – mar. 24.11.
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL), (3, 'ewa', 'Ewa L.')");
$pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', 1), (2, 'CUKIERNIA', 'Cukiernia', 'Pâtisserie', 2),
    (3, 'GARMAZERIA', 'Garmażeria', 'Traiteur', 3), (4, 'TARTY', 'Tarty', 'Tartes', 4)");
$pdo->exec("INSERT INTO production_employee VALUES (1, 'Jan', 'Kowalski'), (2, 'Ola', 'Nowak')");
$pdo->exec('INSERT INTO n_prod_employee VALUES (1, 1), (2, 2)');
$pdo->exec("INSERT INTO unit VALUES (1, 'kg')");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit) VALUES (100, 'Mąka T55', 1), (101, 'Masło 82 %', 1), (102, 'Sól', 1)");
$pdo->exec('INSERT INTO n_material_trace (id_raw_material, tracked) VALUES (102, 0)'); // sól : nieśledzona
$pdo->exec("INSERT INTO product_recipe (id, name, yield_quantity, id_unit) VALUES (501, 'Croissant', 1, 1), (502, 'Tarta cytrynowa', 1, 1), (503, 'Krem cytrynowy', 1, 1)");
$pdo->exec("INSERT INTO final_product (id, name, id_recipe) VALUES (10, 'Croissant maślany', 501), (11, 'Tarta cytrynowa', 502)");
$pdo->exec('INSERT INTO flattened_recipe_ingredient VALUES (501, 100, 0.06), (501, 101, 0.03), (501, 102, 0.001), (502, 100, 0.5), (502, 101, 0.2), (503, 101, 0.2)');
$pdo->exec('INSERT INTO product_recipe_connection (parent_recipe_id, child_recipe_id, child_ingredient_id, quantity) VALUES (502, NULL, 100, 0.5), (502, 503, NULL, 1), (503, NULL, 101, 0.2)');
$pdo->exec('INSERT INTO n_recipe_producer (id_recipe, id_group) VALUES (503, 2)');             // krem robi Cukiernia : odcięty w Tartach
$pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, id_recipe, base_unit) VALUES (1, 'product', 10, NULL, 'kart'), (2, 'product', 11, NULL, 'szt'), (3, 'semi', NULL, 503, 'kg')");
$pdo->exec("INSERT INTO n_material_lot (id, id_raw_material, lot_number, best_before, source, received_on, active, off_reason) VALUES
    (1, 100, 'L-M1', '2026-12-31', 'receipt', '2026-11-10', 1, NULL), (2, 101, NULL, NULL, 'ksef', '2026-11-12', 0, 'no_number'), (3, 100, 'L-M0', '2026-11-20', 'receipt', '2026-10-01', 0, 'expired')");
$close = fn(string $day, int $g, ?string $note, string $at, ?int $emp = 1) => $pdo->prepare('INSERT INTO n_prod_day_close (work_date, id_group, id_employee, note, summary, closed_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$day, $g, $emp, $note, '[]', $at]);
$out = function (int $id, string $day, int $g, int $item, string $lot, float $q, ?string $qc, array $o = []) use ($pdo) {
    $pdo->prepare('INSERT INTO n_prod_output (id, work_date, id_group, id_item, quantity, lot_code, qc_status, qc_at, qc_note, id_nc, cancelled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $day, $g, $item, $q, $lot, $qc, $qc ? $day . ' 14:00:00' : null, $o['qc_note'] ?? null, $o['nc'] ?? null, $o['cancelled'] ?? null, $day . ' 08:00:00']);
};
$lotRow = fn(int $o, int $m, ?string $lot, ?string $bb = null, ?int $reg = null, ?string $reason = null) => $pdo->prepare('INSERT INTO n_prod_output_lot (id_output, id_material, lot_number, best_before, id_material_lot, missing_reason) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$o, $m, $lot, $bb, $reg, $reason]);

$close('2026-11-23', 1, 'Brak etykiety masła, uzupełnimy', '2026-11-23 15:10:00');
$out(1, '2026-11-23', 1, 1, '261123-PIE-01', 12, 'ok'); $lotRow(1, 100, 'L-M1', '2026-12-31', 1); $lotRow(1, 101, null, null, null, 'etykieta zniszczona');
$out(2, '2026-11-23', 1, 1, '261123-PIE-02', 8, null); $lotRow(2, 100, 'L-M0', null, 3);                       // mąka po DLC (rejestr 20.11), masło brak, bez kontroli
$out(3, '2026-11-23', 1, 1, '261123-PIE-03', 5, null, ['cancelled' => '2026-11-23 09:00:00']);                   // anulowana : pominięta
$close('2026-11-23', 2, null, '2026-11-23 16:00:00', 2);
$out(4, '2026-11-23', 2, 3, '261123-CUK-01', 6, 'nc', ['nc' => 55, 'qc_note' => 'Za rzadki']); $lotRow(4, 101, 'L-B7', '2026-12-20', 2); // partia z rejestru bez numeru
$close('2026-11-23', 4, null, '2026-11-23 15:30:00');
$out(5, '2026-11-23', 4, 2, '261123-TAR-01', 20, 'ok'); $lotRow(5, 100, 'L-M1', '2026-12-31', 1);
$pdo->exec('INSERT INTO n_prod_output_component (id_output, id_recipe, id_source_output) VALUES (5, 503, 4)');    // komplet
$close('2026-11-24', 4, 'Krem z Cukierni, partia do wpisania', '2026-11-24 13:00:00');
$out(6, '2026-11-24', 4, 2, '261124-TAR-01', 10, 'ok'); $lotRow(6, 100, 'L-M1', '2026-12-31', 1);
$pdo->exec('INSERT INTO n_prod_output_component (id_output, id_recipe, id_source_output) VALUES (6, 503, NULL)'); // krem bez partii Cukierni
$close('2026-11-24', 3, 'Masło uzupełnione później', '2026-11-24 12:00:00');
$out(7, '2026-11-24', 3, 1, '261124-GAR-01', 4, 'ok'); $lotRow(7, 100, 'L-M1', '2026-12-31', 1); $lotRow(7, 101, 'M-22', '2026-12-01');
$out(8, '2026-11-24', 1, 1, '261124-PIE-01', 10, null);                                                              // dzień niezatwierdzony
$close('2026-11-20', 1, 'piątek', '2026-11-20 15:00:00');
$out(9, '2026-11-20', 1, 1, '261120-PIE-01', 10, null);                                                              // poza zakresem (piątek)

$sum = fn() => $pdo->query("SELECT CONCAT((SELECT COUNT(*) FROM n_prod_output), '/', (SELECT COALESCE(SUM(COALESCE(qc_status = 'ok', 0)), 0) FROM n_prod_output), '/',
    (SELECT COUNT(*) FROM n_prod_output_lot), '/', (SELECT COUNT(lot_number) FROM n_prod_output_lot), '/', (SELECT COUNT(*) FROM n_material_lot), '/', (SELECT COALESCE(SUM(active), 0) FROM n_material_lot), '/',
    (SELECT COUNT(*) FROM n_prod_day_close), '/', (SELECT COUNT(note) FROM n_prod_day_close), '/', (SELECT COUNT(*) FROM n_prod_output_component), '/', (SELECT COUNT(id_source_output) FROM n_prod_output_component))")->fetchColumn();
$erpBefore = $sum();

$lw = new LightWizardRepository($pdo);
$outputs = new OutputRepository($pdo);
$make = fn() => new LotsQcDayService(new LotsQcDayRepository($pdo), $outputs, new CompositionCut($outputs, new RecipeProducerRepository($pdo)), $lw, new ParamRepository($pdo));
$svc = $make();
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);
$today = '2026-11-24';

$check($svc->previousWorkday('2026-11-24') === '2026-11-23' && $svc->previousWorkday('2026-11-23') === '2026-11-20', 'previous workday (Monday → Friday)');
$d = $svc->data($today);
$boot = $d;
$w = $d['window'];
$check($w['from'] === '2026-11-23' && $w['to'] === $today && $w['kind'] === 'workday' && $w['last_done'] === null, 'window: previous workday → today (never validated)');
$days = array_column($d['days'], null, 'key');
$check(array_keys($days) === ['2026-11-24|3', '2026-11-24|4', '2026-11-23|1', '2026-11-23|2', '2026-11-23|4'], 'closed group-days only, newest first, by group order (Friday out, unclosed out)');
$p = $days['2026-11-23|1'];
$check($p['batches'] === 2 && $p['counts'] === ['lot_missing' => 1, 'lot_reason' => 1, 'qc_missing' => 1, 'lot_expired' => 1, 'lot_no_number' => 0] && $p['gaps'] === 4
    && $p['note'] === 'Brak etykiety masła, uzupełnimy' && $p['closed_by'] === 'Jan Kowalski' && $p['closed_at'] === '15:10' && $p['waiting'], 'Piekarnia 23.11: 4 gaps, cancelled batch ignored, note and who closed');
$b = array_column($p['gap_batches'], null, 'lot_code');
$check(array_column($b['261123-PIE-01']['gaps'], 'code') === ['lot_reason'] && $b['261123-PIE-01']['gaps'][0]['detail'] === 'Masło 82 % : etykieta zniszczona'
    && $b['261123-PIE-01']['lots_done_kiosk'] === 2 && $b['261123-PIE-01']['lots_total'] === 2, 'batch 1: « brak partii » with a reason (complete for the kiosk, still a gap ; salt not tracked)');
$check(array_column($b['261123-PIE-02']['gaps'], 'code') === ['lot_missing', 'qc_missing', 'lot_expired'] && $b['261123-PIE-02']['gaps'][0]['detail'] === 'Masło 82 %'
    && str_contains($b['261123-PIE-02']['gaps'][2]['detail'], 'L-M0 · DLC 20.11.2026 (produkcja 23.11.2026)') && $b['261123-PIE-02']['lots_ok'] === 1 && $b['261123-PIE-02']['lots_done_kiosk'] === 1,
    'batch 2: butter missing, no QC, flour used after its best-before date (register date) ; same count as the kiosk');
$c = $days['2026-11-23|2'];
$check($c['gaps'] === 1 && $c['counts']['lot_no_number'] === 1 && $c['qc_nc'] === 1 && $c['gap_batches'][0]['nc_id'] === 55 && $c['gap_batches'][0]['qc_note'] === 'Za rzadki'
    && str_contains($c['gap_batches'][0]['gaps'][0]['detail'], 'L-B7 — w rejestrze partii surowców bez numeru'), 'Cukiernia 23.11: register lot without number ; NC from the tablet shown');
$check(!$days['2026-11-23|4']['review'] && $days['2026-11-23|4']['gaps'] === 0 && !$days['2026-11-23|4']['waiting'], 'Tarty 23.11: composition cut (cream by Cukiernia) with its batch → clean');
$t4 = $days['2026-11-24|4'];
$check($t4['gaps'] === 1 && $t4['gap_batches'][0]['gaps'][0]['detail'] === 'Krem cytrynowy (partia grupy Cukiernia)' && $t4['gap_batches'][0]['lots_total'] === 2 && $t4['today'],
    'Tarty 24.11 (closed today): cream batch of the producing group missing ; butter only in the cream (cut)');
$g3 = $days['2026-11-24|3'];
$check($g3['review'] && $g3['gaps'] === 0 && $g3['waiting'] && $g3['note'] === 'Masło uzupełnione później', 'Garmażeria 24.11: closed with a note, gaps filled since → to review');
$t = $d['totals'];
$check($t['days'] === 5 && $t['batches'] === 6 && $t['review'] === 4 && $t['clean'] === 1 && $t['waiting'] === 4 && $t['gaps'] === 6
    && $t['by_code'] === ['lot_missing' => 2, 'lot_reason' => 1, 'qc_missing' => 1, 'lot_expired' => 1, 'lot_no_number' => 1] && $t['notes'] === 3 && $t['qc_nc'] === 1 && $t['unclosed'] === 1
    && $d['can_finish'] === false, 'totals: 5 days, 6 batches, 6 gaps in 4 days, 1 unclosed');
$check($d['unclosed'] === [['work_date' => '2026-11-24', 'day_label' => 'wt. 24.11', 'group' => 'Piekarnia', 'batches' => 1]], 'unclosed group-day listed (info)');
$check($d['nc_link'] === '/purchasing/nc#new' && PageAccess::keyFor('/purchasing/nc') === 'production' && $p['check_link'] === '/workshop/traceability/check?date=2026-11-23', 'NC creation link and input-check link (same right)');

// --- Krok 2 : wyniki
$erp1 = $sum();
$check(str_starts_with($err(fn() => $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 1, 'result' => 'x'], 1, $today)), 'custom:422:Wybierz wynik'), 'unknown result refused');
$check(str_starts_with($err(fn() => $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 1, 'result' => 'nc', 'note' => ' '], 1, $today)), 'custom:422:Niezgodność : wpisz notatkę'), 'NC needs a note');
$check(str_starts_with($err(fn() => $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 1, 'result' => 'accepted', 'note' => 'a'], 1, $today)), 'custom:422:Zaakceptowane : wpisz notatkę'), 'accepted needs a note');
$e = $err(fn() => $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 1, 'result' => 'completed'], 1, $today));
$check(str_starts_with($e, 'custom:422:Nadal brakuje : 1 × brak partii surowca, 1 × brak partii — powód, 1 × brak kontroli jakości, 1 × partia po dacie ważności'), '« Uzupełnione » refused while gaps remain');
$check(str_starts_with($err(fn() => $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 4, 'result' => 'accepted', 'note' => 'abc'], 1, $today)), 'custom:422:Ten dzień grupy nie ma braków'), 'clean day: only « Uzupełnione » makes sense');
$check(str_starts_with($err(fn() => $svc->saveResult(['work_date' => '2026-11-20', 'id_group' => 1, 'result' => 'completed'], 1, $today)), 'custom:422:Tego dnia grupy nie ma'), 'day outside the window refused');
$check(str_starts_with($err(fn() => $svc->finish(1, $today)), 'custom:422:Najpierw wyjaśnij 4 dni grup'), 'finish blocked while days wait');
$d = $svc->saveResult(['work_date' => '2026-11-24', 'id_group' => 3, 'result' => 'completed', 'note' => ''], 3, $today);
$days = array_column($d['days'], null, 'key');
$check($days['2026-11-24|3']['check']['result'] === 'completed' && $days['2026-11-24|3']['check']['by'] === 'Ewa L.' && !$days['2026-11-24|3']['waiting'] && $d['totals']['completed'] === 1, '« Uzupełnione » saved when no gap is left');
$d = $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 1, 'result' => 'nc', 'note' => 'NC-2026-118 : mąka po DLC, partia zablokowana'], 3, $today);
$row = $pdo->query("SELECT * FROM n_lots_qc_check WHERE work_date = '2026-11-23' AND id_group = 1")->fetch();
$check($row['result'] === 'nc' && $row['flags'] === 'lot_missing:1,lot_reason:1,qc_missing:1,lot_expired:1' && (int) $row['gaps'] === 4 && $row['checked_on'] === $today && (int) $row['id_user'] === 3, 'NC saved with the gaps at that time');
$st = $tasks->state(LotsQcDayService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '2 dni grup wyjaśnione', 'task state: in progress (results saved today)');
$check($erp1 === $sum(), 'saving results writes nothing in production tables');
// Uzupełnione w ERP : krem dostaje partię Cukierni → Tarty 24.11 bez braków.
$pdo->exec('UPDATE n_prod_output_component SET id_source_output = 4 WHERE id_output = 6');
$d = $svc->saveResult(['work_date' => '2026-11-24', 'id_group' => 4, 'result' => 'completed'], 1, $today);
$d = $svc->saveResult(['work_date' => '2026-11-23', 'id_group' => 2, 'result' => 'accepted', 'note' => 'Partia z faktury KSeF, numer na fakturze — rejestr do uzupełnienia'], 1, $today);
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0, 'all days decided → step 3 open');
// Brak wraca po « Uzupełnione » : dzień znowu do wyjaśnienia.
$pdo->exec('UPDATE n_prod_output_component SET id_source_output = NULL WHERE id_output = 6');
$x = $svc->data($today);
$check(array_column($x['days'], null, 'key')['2026-11-24|4']['waiting'] === true && $x['can_finish'] === false, '« Uzupełnione » reopened when a gap comes back');
$pdo->exec('UPDATE n_prod_output_component SET id_source_output = 4 WHERE id_output = 6');
$erp2 = $sum();
$r = $svc->finish(1, $today);
$wd = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'lots_qc_day_checked'")->fetchAll();
$check($r['summary'] === '5 dni grup · 6 partii · 5 braków w 4 dniach · 2 uzupełnione · 1 NC · 1 zaakceptowane' && count($wd) === 1 && $wd[0]['day'] === $today && $r['data']['done'] !== null,
    'finish: summary, n_wizard_done');
$st = $tasks->state(LotsQcDayService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done today');
$check($tasks->state(LotsQcDayService::CODE, '2026-11-25 00:00:00', '2026-11-26 00:00:00', '2026-11-25')['done'] === false, 'next day: task open again');
$check($erp2 === $sum() && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_nc%'")->fetchColumn() === 0,
    'no write in production tables, no NC created');

// Fenêtre : rattrapage depuis la dernière validation (24.11) jeudi 26.11 ; borné par lots_qc.catchup_days ; lundi suivant.
$w = $make()->window('2026-11-26');
$check($w['from'] === '2026-11-24' && $w['kind'] === 'catchup' && $w['last_done'] === '2026-11-24', 'catch-up from the last validation day');
$check($make()->window('2026-11-25')['from'] === '2026-11-24' && $make()->window('2026-11-25')['kind'] === 'workday', 'validated yesterday: previous workday');
$check($make()->window('2026-12-15')['from'] === '2026-12-08', 'catch-up bounded (7 days by default)');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'lots_qc.catchup_days'");
$check($make()->window('2026-11-26')['from'] === '2026-11-25' && $make()->catchupDays() === 1, 'catchup_days = 1 → previous workday only');
$pdo->exec("UPDATE n_prod_param SET value = '90' WHERE code = 'lots_qc.catchup_days'");
$check($make()->catchupDays() === 7, 'out of bounds → default 7');
$check(count(array_column($make()->data('2026-11-24')['days'], 'key')) === 5 && array_column($make()->data('2026-11-24')['days'], 'check')[0]['result'] === 'completed', 'results kept by group-day (re-read)');

// --- Contrôleur : construit par PHP-DI (comme App/Core/bootstrap.php, PDO de test), data(), result() et finish() réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_lots_qc_day_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$di = $b->build();
$ctl = $di->get(LotsQcDayController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'production'), (2, 'logistics')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)]; };
[$code3, $body3] = $call(3, 'data');
$check($code3 === 200 && $body3['data']['window']['to'] === date('Y-m-d') && isset($body3['data']['totals']['gaps']) && $body3['data']['nc_link'] === '/purchasing/nc#new',
    'controller data() (Specjalista ds. jakości, right « production ») : 200, real day');
[$code1] = $call(1, 'data');
[$code2, $body2] = $call(2, 'data');
$check($code1 === 200 && $code2 === 403 && $body2['message'] === 'Brak dostępu do produkcji (partie i identyfikowalność).', 'controller: « production » right required (admin 200, Logistyka 403)');
[$codeR] = $call(2, 'result');
[$codeF] = $call(2, 'finish');
$check($codeR === 403 && $codeF === 403, 'controller result() and finish() guarded too');
unset($_SESSION['USER']);

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('lots_qc_day_checked')['link'] === '/workshop/traceability/day-check' && TaskWizards::byLink('/workshop/traceability/day-check') === 'lots_qc_day_checked'
    && isset(TaskWizards::AUTO['lots_qc_day_checked']) && PositionTaskService::LINK_AUTO['/workshop/traceability/day-check'] === 'lots_qc_day_checked', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['lots_qc_day_checked'][0] !== 'Partie i kontrola dnia', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/workshop/traceability/day-check') === 'production' && PageAccess::keyFor('/ajax/workshop/traceability/day-check') === 'production'
    && PageAccess::keyFor('/ajax/workshop/traceability/day-check/result') === 'production' && PageAccess::keyFor('/workshop/traceability/check') === 'production'
    && PageAccess::keyFor('/logistics/delays') === 'logistics', 'page and ajax under the existing « production » right ; others unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'lots_qc_day_checked' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/workshop/traceability/day-check'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Production/LotsQcDayRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/workshop/traceability/day-check') === 'index' && $found('GET', '/ajax/workshop/traceability/day-check') === 'data'
    && $found('POST', '/ajax/workshop/traceability/day-check/result') === 'result' && $found('POST', '/ajax/workshop/traceability/day-check/finish') === 'finish', 'routes');
$all = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($all->dispatch('GET', '/workshop/traceability/day-check')[0] === FastRoute\Dispatcher::FOUND && $all->dispatch('GET', '/workshop/traceability/day-check')[1]['controller'] === LotsQcDayController::class
    && $all->dispatch('GET', '/workshop/traceability/check')[1]['method'] === 'inputCheck', 'routes file loaded by the */*Routes.php glob ; existing traceability routes unchanged');

if (getenv('LQ_JSON_OUT')) file_put_contents((string) getenv('LQ_JSON_OUT'), json_encode(['before' => $boot, 'after' => $r['data']], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_lots_qc_day_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
