<?php
/**
 * [406] Asystent « Teczka do audytu i kontroli » : trimestre (IV kwartał 2026) ; assistants de contrôle en usage (tâche active,
 * rythme le plus court) — jamais / pas dans leur rythme + audit_folder.grace_days, sans tâche « nieużywany » ; éléments ouverts
 * (chaîne du froid non traitée, NC ouvertes, réclamations sans décision, opakowania PPWR incomplets, SOP après revue ou sans
 * date ; table absente → « brak danych ») ; documents (liste de départ si vide ; jamais contrôlé, contrôle dans
 * audit_folder.alert_days jours, sans responsable, sans emplacement) ; décisions (gotowe : document avec responsable et
 * emplacement → contrôle du jour ; ERP encore signalé → note ; do uzupełnienia : date et note ; nie dotyczy : note) ; liste
 * modifiable (ajout, modification, retrait) ; « Zakończ » → n_wizard_done, état de la tâche du trimestre ; PDF ; trimestre
 * suivant ; aucune écriture dans les tables de l'ERP (checksums) ; contrôleur construit par PHP-DI et appelé (droit
 * « audit_folder » 200 et PDF, sans 403) ; aucun envoi ; enregistrement ; migration 246 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_audit_folder_test recréée (tables minimales de l'ERP, tables PPWR de leurs migrations, migration 246), supprimée à
 * la fin (AF_JSON_OUT=fichier : données de la page avant les décisions ; AF_PDF_OUT=fichier : la teczka PDF). Données fictives.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Audit\AuditFolderController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\{AuditFolderRepository, ParamRepository, PpwrMonthRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\AuditFolderService as S;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_audit_folder_test'); $pdo->exec('CREATE DATABASE erp_audit_folder_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_audit_folder_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80) NULL, name VARCHAR(60) NULL, surname VARCHAR(60) NULL)',
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80), email VARCHAR(120) NULL, nip VARCHAR(20) NULL)',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), id_unit INT NULL, id_current_supplier INT NULL, is_part_of_package TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB',
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), id_recipe INT NULL, ppc INT NULL, weight INT NULL, active TINYINT DEFAULT 1, create_timestamp DATETIME NULL) ENGINE=InnoDB',
    'CREATE TABLE flattened_recipe_ingredient (id INT AUTO_INCREMENT PRIMARY KEY, recipe_id INT, material_id INT, quantity DECIMAL(12,4))',
    'CREATE TABLE shipment_log (id INT AUTO_INCREMENT PRIMARY KEY, shipment_date DATE, id_customer INT, id_wz INT NULL, id_cmr INT NULL, id_wdt INT NULL) ENGINE=InnoDB',
    "CREATE TABLE n_mgr_haccp_event (id INT AUTO_INCREMENT PRIMARY KEY, event_date DATE, kind ENUM('official_control','cold_chain_break','independent_audit','self_check'), result VARCHAR(20) NULL, treated TINYINT DEFAULT 0)",
    "CREATE TABLE n_nc (id INT AUTO_INCREMENT PRIMARY KEY, number VARCHAR(20), status ENUM('open','in_progress','closed') DEFAULT 'open')",
    'CREATE TABLE complaint (id INT AUTO_INCREMENT PRIMARY KEY, acceptance_status VARCHAR(20) NULL)',
    "CREATE TABLE n_sop (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(24), title VARCHAR(200), status ENUM('draft','active','archived') DEFAULT 'draft', review_at DATE NULL)",
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), kind VARCHAR(20) DEFAULT 'erp', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_task_category (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20))',
    "CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_category INT NULL, title_pl VARCHAR(255), title_fr VARCHAR(255), frequency VARCHAR(20),
        minutes INT NULL, month_day VARCHAR(8) NULL, link_path VARCHAR(255) NULL, auto_code VARCHAR(40) NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
    'CREATE TABLE n_position_menu (id_position INT, item VARCHAR(40), open_path VARCHAR(160) NULL, updated_by INT NULL, PRIMARY KEY (id_position, item))',
] as $sql) $pdo->exec($sql);
// Tables PPWR exactement comme leurs migrations les créent ou les modifient (comme ppwr_month_test).
foreach (['040_shipment_packaging_bdo', '042_shipment_packaging_evidence_basis', '043_raw_material_ppwr_profiles', '046_remove_ppwr_format', '047_final_product_ppwr_sales_packaging',
    '048_remove_ppwr_product_packaging_count', '049_add_ppwr_material_identification', '050_shipment_packaging_ppwr_snapshot', '051_shipment_ppwr_evidence_basis', '052_separate_ppwr_shipment_calculation'] as $f) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/' . $f . '.sql')) as $sql) $pdo->query($sql)->closeCursor();
}
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2), (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (4, 'JAKOSC'), (8, 'ADMIN')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '2.8', NULL, 'Teczka do audytu i kontroli', 'quarterly'),
    (2, '2.9', NULL, 'Teczka do audytu i kontroli', 'quarterly'), (2, '7.4', NULL, 'Teczka do audytu i kontroli', 'quarterly'), (2, '8.4', NULL, 'Teczka do audytu i kontroli', 'quarterly'),
    (2, '2.1', NULL, 'PPWR i opakowania (kompletność)', 'monthly'), (1, '5', NULL, 'Teczka do audytu i kontroli', 'quarterly'), (2, '7.1', 'haccp_month_reviewed', NULL, 'monthly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order, active) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 7, 1),
    (2, 'HACCP', 'monthly', '/production/haccp-month', 'haccp_month_reviewed', 1, 1), (1, 'HACCP', 'monthly', '/production/haccp-month', 'haccp_month_reviewed', 1, 1),
    (2, 'PPWR', 'monthly', '/ppwr/month', 'ppwr_month_reviewed', 2, 1), (1, 'NC', 'monthly', '/purchasing/nc-review', 'nc_reviewed', 3, 1),
    (1, 'Reklamacje', 'weekly', '/franchise-complaints/review', 'complaints_reviewed', 4, 1), (1, 'Partie', 'daily', '/workshop/traceability/day-check', 'lots_qc_day_checked', 5, 1),
    (2, 'Badania', 'monthly', '/hr/deadlines', 'staff_deadlines_reviewed', 6, 1), (2, 'Próbki (stare)', 'daily', '/workshop/traceability/samples', 'samples_done', 9, 0)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (4, 'clients')");
$m246 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/246_audit_folder.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m246 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 246 (deux passages)
$duties = $pdo->query('SELECT wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check(array_column(array_slice($duties, 0, 4), 'wizard_code') === array_fill(0, 4, 'audit_folder_reviewed') && $duties[4]['proposed_wizard'] === 'PPWR i opakowania (kompletność)'
    && $duties[5]['proposed_wizard'] === 'Teczka do audytu i kontroli' && $duties[6]['wizard_code'] === 'haccp_month_reviewed', 'migration 246 : KIEROWNIK_PRODUKCJI 2.8, 2.9, 7.4, 8.4 → audit_folder_reviewed ; other rows and positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'audit_folder_reviewed'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['frequency'] === 'quarterly' && $tasks[0]['month_day'] === 'wd10' && $tasks[0]['link_path'] === '/audit-folder'
    && (int) $tasks[0]['id_category'] === 4 && (int) $tasks[0]['sort_order'] === 10, 'one quarterly task for Kierownik produkcji (category « Jakość »)');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'audit_folder,time', 4 => 'clients'], 'right « audit_folder » for Kierownik produkcji only');
$params = $pdo->query("SELECT code, CONCAT(value, '|', value_type) FROM n_prod_param WHERE code LIKE 'audit_folder.%' ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($params === ['audit_folder.alert_days' => '14|int', 'audit_folder.grace_days' => '7|int'] && ParamRules::RANGES['audit_folder.grace_days'] === [0, 60, true], 'settings seeded once ; ParamRules bounds');
$check((int) $pdo->query('SELECT COUNT(*) FROM n_audit_folder_item')->fetchColumn() === 9 && (int) $pdo->query("SELECT COUNT(*) FROM n_audit_folder_item WHERE owner IS NULL AND location IS NULL AND last_checked IS NULL")->fetchColumn() === 9
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_audit_folder%'")->fetchColumn() === 0,
    'starting list seeded once (9 documents, owner and place to fill) ; no foreign key');
$check(S::quarterStart('2026-11-24') === '2026-10-01' && S::quarterStart('2027-01-05') === '2027-01-01' && S::quarterStart('2026-06-30') === '2026-04-01' && S::quarterLabel('2026-10-01') === 'IV kwartał 2026',
    'quarter start and label');

$lw = new LightWizardRepository($pdo);
$make = fn() => new S(new AuditFolderRepository($pdo), new PpwrMonthRepository($pdo), $lw, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';

// --- Données fictives : contrôle le mar. 24.11.2026 (IV kwartał 2026).
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$wd = $pdo->prepare('INSERT INTO n_wizard_done (code, day, summary, done_at) VALUES (?, ?, ?, ?)');
foreach ([['haccp_month_reviewed', '2026-11-05', 'październik 2026 : 2 zdarzenia', '2026-11-05 10:00:00'], ['nc_reviewed', '2026-09-03', 'sierpień 2026', '2026-09-03 10:00:00'],
    ['complaints_reviewed', '2026-11-20', 'tydzień 46', '2026-11-20 10:00:00'], ['lots_qc_day_checked', '2026-11-10', 'dzień', '2026-11-10 10:00:00'],
    ['staff_deadlines_reviewed', '2026-11-03', 'listopad 2026', '2026-11-03 10:00:00'], ['haccp_month_reviewed', '2026-12-05', 'przyszłość', '2026-12-05 10:00:00']] as $r) $wd->execute($r);
$pdo->exec("INSERT INTO n_mgr_haccp_event (event_date, kind, treated) VALUES ('2026-11-02', 'cold_chain_break', 0), ('2026-10-02', 'cold_chain_break', 1), ('2026-10-12', 'official_control', 0)");
$pdo->exec("INSERT INTO n_nc (number, status) VALUES ('NC/1', 'open'), ('NC/2', 'in_progress'), ('NC/3', 'closed')");
$pdo->exec("INSERT INTO complaint (acceptance_status) VALUES ('ACCEPTED'), ('REJECTED')");
$pdo->exec("INSERT INTO n_sop (code, title, status, review_at) VALUES ('SOP-01', 'Mycie', 'active', '2026-10-01'), ('SOP-02', 'Próbki', 'active', NULL), ('SOP-03', 'Szkic', 'draft', '2020-01-01'),
    ('SOP-04', 'Waga', 'active', '2027-05-01')");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit, is_part_of_package, bdo_material_category, packaging_unit_weight_g, packaging_usage_type) VALUES
    (10, 'Karton 30x20x10', 1, 1, 'PAPER_CARDBOARD', 120, 'SINGLE_USE'), (11, 'Folia PE 40 µm', 1, 1, NULL, NULL, NULL), (13, 'Tacka stara', 1, 1, NULL, NULL, NULL)");
$prof = $pdo->prepare('INSERT INTO raw_material_ppwr_profile (id, id_raw_material, identification_number, identification_source, packaging_level, packaging_type, dominant_material,
    recycled_content_status, recyclability_status, minimisation_status, verification_status, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[1, 10, 'KRT-01', 'OWN', 'SALES', 'CARTON_BOX', 'PAPER_CARDBOARD', 'NOT_APPLICABLE', 'ASSESSED', 'ASSESSED', 'COMPLETE', '2026-09-15 10:00:00'],
    [2, 11, null, null, 'INNER', 'FILM', 'PLASTIC', 'NOT_ASSESSED', 'NOT_ASSESSED', 'NOT_ASSESSED', 'REQUIRES_DATA', '2026-08-01 10:00:00']] as $r) $prof->execute($r);
$pdo->exec("INSERT INTO final_product (id, name, id_recipe, ppc, weight, active) VALUES (20, 'Bagietka', 100, 18, 250, 1), (21, 'Stary', 200, 10, 100, 0)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 10, 1), (100, 11, 0.5), (200, 13, 1)");
$pdo->exec("UPDATE n_audit_folder_item SET owner = 'Kierownik produkcji', location = 'Segregator HACCP, biuro', last_checked = '2026-08-01' WHERE sort_order = 10");
$pdo->exec("UPDATE n_audit_folder_item SET owner = 'Kierownik produkcji', location = 'Szafa A, półka 2', last_checked = '2026-10-15' WHERE sort_order = 20");
$doc = array_column($pdo->query('SELECT sort_order, id FROM n_audit_folder_item')->fetchAll(), 'id', 'sort_order');

$erpTables = ['n_mgr_haccp_event', 'n_nc', 'complaint', 'n_sop', 'raw_material', 'raw_material_ppwr_profile', 'final_product', 'user'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$d = $svc->data($today);
$boot = $d;
$ck = array_column($d['checks'], null, 'key');
$codes = fn(array $i) => array_column($i['signals'], 'code');
$check(array_keys($ck) === ['wizard:haccp_month_reviewed', 'wizard:ppwr_month_reviewed', 'wizard:nc_reviewed', 'wizard:complaints_reviewed', 'wizard:lots_qc_day_checked', 'wizard:staff_deadlines_reviewed',
    'erp:haccp', 'erp:nc', 'erp:complaints', 'erp:ppwr', 'erp:sop'] && $d['unused'] === ['Próbki produkcji', 'Serwisy i przeglądy maszyn (przegląd miesiąca)'],
    'checks : assistants with an active position task (inactive task = unused), then the ERP open items');
$check($codes($ck['wizard:haccp_month_reviewed']) === [] && $ck['wizard:haccp_month_reviewed']['state'] === 'ostatnio 05.11.2026 — październik 2026 : 2 zdarzenia'
    && $codes($ck['wizard:ppwr_month_reviewed']) === ['wizard_never'] && $codes($ck['wizard:nc_reviewed']) === ['wizard_stale']
    && $ck['wizard:nc_reviewed']['signals'][0]['detail'] === 'ostatnio 03.09.2026, rytm co miesiąc + 7 dni' && $codes($ck['wizard:complaints_reviewed']) === []
    && $codes($ck['wizard:lots_qc_day_checked']) === ['wizard_stale'] && $ck['wizard:lots_qc_day_checked']['sub'] === 'codziennie' && $codes($ck['wizard:staff_deadlines_reviewed']) === [],
    'assistants : last « Zakończ » up to today vs shortest rhythm + grace days ; never finished');
$check($codes($ck['erp:haccp']) === ['open_items'] && $ck['erp:haccp']['state'] === '1 nieobsłużonych przerwań łańcucha chłodniczego' && $ck['erp:nc']['count'] === 2
    && $codes($ck['erp:complaints']) === [] && $ck['erp:complaints']['state'] === 'brak otwartych spraw' && $ck['erp:ppwr']['count'] === 1
    && $codes($ck['erp:sop']) === ['sop_review'] && $ck['erp:sop']['state'] === '1 po terminie przeglądu · 1 bez daty przeglądu : SOP-01, SOP-02',
    'ERP : untreated cold chain break, open NC, no open complaint, incomplete PPWR packaging of an active product, SOP late or without date (draft ignored)');
$dc = array_column($d['documents'], null, 'key');
$check($codes($dc['doc:' . $doc[10]]) === ['doc_due'] && $dc['doc:' . $doc[10]]['signals'][0]['detail'] === 'następne sprawdzenie 01.11.2026' && $codes($dc['doc:' . $doc[20]]) === []
    && $codes($dc['doc:' . $doc[30]]) === ['doc_unchecked', 'doc_no_owner', 'doc_no_location'] && count($d['documents']) === 9,
    'documents : check due (last + N months within alert days), checked one ok, new ones unchecked without owner and place');
$check(count($d['review']) === 15 && $d['totals']['waiting'] === 15 && $d['review'][0]['key'] === 'wizard:ppwr_month_reviewed' && $d['totals']['wizards_ok'] === 3 && $d['totals']['erp_open'] === 4
    && $d['totals']['docs_ok'] === 1 && !$d['can_finish'] && $erp0 === $sum(), 'review : 3 assistants, 4 ERP items, 8 documents ; reading writes nothing');
$pdo->exec('RENAME TABLE n_nc TO nc_x');
$check(array_column($make()->data($today)['checks'], null, 'key')['erp:nc']['state'] === 'brak danych w ERP', 'missing NC table → « brak danych » without signal');
$pdo->exec('RENAME TABLE nc_x TO n_nc');
$pdo->exec("UPDATE n_prod_param SET value = '60' WHERE code = 'audit_folder.grace_days'");
$check(array_column($make()->data($today)['checks'], null, 'key')['wizard:lots_qc_day_checked']['codes'] === [], 'grace days follow the setting');
$pdo->exec("UPDATE n_prod_param SET value = '7' WHERE code = 'audit_folder.grace_days'");
$svc = $make();

// --- Krok 2
$k3 = 'doc:' . $doc[30];
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => $k3, 'decision' => 'ready'], 2, $today)), 'custom:422:Najpierw uzupełnij : brak osoby odpowiedzialnej, brak miejsca')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'wizard:ppwr_month_reviewed', 'decision' => 'ready'], 2, $today)), 'custom:422:Gotowe mimo sygnału')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'erp:nc', 'decision' => 'action', 'note' => 'zamknąć NC'], 2, $today)), 'custom:422:Do uzupełnienia : termin')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'erp:nc', 'decision' => 'action', 'due_on' => '2026-12-15'], 2, $today)), 'custom:422:Do uzupełnienia : wpisz')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'erp:haccp', 'decision' => 'not_applicable'], 2, $today)), 'custom:422:Nie dotyczy : wpisz')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'erp:complaints', 'decision' => 'action', 'due_on' => '2026-12-15', 'note' => 'xyz'], 2, $today)), 'custom:422:Ten element nie ma sygnału')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'doc:999', 'decision' => 'ready'], 2, $today)), 'custom:422:Nie ma takiego elementu'),
    'refusals : document without owner / place, ERP signal without note, action without date or note, « nie dotyczy » without note, no signal, unknown item');
$check(str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz decyzję dla 15 elementów') && (int) $pdo->query('SELECT COUNT(*) FROM n_audit_folder_review')->fetchColumn() === 0,
    'finish blocked ; refused calls write nothing');
$d = $svc->saveDecision(['key' => 'doc:' . $doc[10], 'decision' => 'ready'], 2, $today);
$row = $pdo->query('SELECT last_checked, checked_by FROM n_audit_folder_item WHERE id = ' . $doc[10])->fetch();
$it = array_column($d['review'], null, 'key')['doc:' . $doc[10]];
$check($row === ['last_checked' => '2026-11-24', 'checked_by' => 2] && $it['codes'] === [] && !$it['waiting'] && $it['decision']['flags'] === ['doc_due'] && $it['state'] === 'sprawdzony 24.11.2026 (Kasia P.), następny 24.02.2027',
    'document « gotowe » : checked today by the user, signal gone');
// Liste : responsable et emplacement complétés, ajout, modification, retrait.
$check(str_starts_with($err(fn() => $svc->saveItem(['title' => 'X', 'area' => 'haccp'], 2, $today)), 'custom:422:Dokument : nazwa')
    && str_starts_with($err(fn() => $svc->saveItem(['title' => 'Dokument', 'area' => 'zzz'], 2, $today)), 'custom:422:Wybierz obszar')
    && str_starts_with($err(fn() => $svc->saveItem(['title' => 'Dokument', 'area' => 'haccp', 'check_months' => '40'], 2, $today)), 'custom:422:Sprawdzać co')
    && str_starts_with($err(fn() => $svc->saveItem(['id' => 999, 'title' => 'Dokument'], 2, $today)), 'custom:404:Nie ma takiego dokumentu'), 'document form validated');
$d = $svc->saveItem(['id' => $doc[30], 'title' => 'Wyniki badań wody (aktualne)', 'area' => 'sanepid', 'format' => 'both', 'owner' => 'Kierownik produkcji', 'location' => 'Segregator Sanepid', 'check_months' => '6'], 2, $today);
$check(array_column($d['review'], null, 'key')[$k3]['codes'] === ['doc_unchecked'], 'owner and place saved : only « unchecked » left');
$d = $svc->saveDecision(['key' => $k3, 'decision' => 'ready', 'note' => 'wynik z 10.2026'], 2, $today);
$d = $svc->saveItem(['title' => 'Instrukcja mycia i dezynfekcji (podpisana)', 'area' => 'haccp', 'format' => 'paper', 'owner' => 'Brygadzista', 'location' => 'Hala, tablica', 'check_months' => '12'], 2, $today);
$new = array_values(array_filter($d['documents'], fn($i) => $i['title'] === 'Instrukcja mycia i dezynfekcji (podpisana)'))[0];
$check($new['codes'] === ['doc_unchecked'] && $new['doc']['check_months'] === 12 && count($d['documents']) === 10, 'document added to the list');
$d = $svc->removeItem(['id' => $new['doc']['id']], 2, $today);
$d = $svc->removeItem(['id' => $doc[90]], 2, $today);
$check(count($d['documents']) === 8 && (int) $pdo->query('SELECT COUNT(*) FROM n_audit_folder_item WHERE active = 0')->fetchColumn() === 2, 'documents removed from the list (deactivated)');
// ERP et assistants.
$d = $svc->saveDecision(['key' => 'wizard:ppwr_month_reviewed', 'decision' => 'action', 'due_on' => '2026-12-10', 'note' => 'pierwszy przegląd PPWR w grudniu — Kierownik produkcji'], 2, $today);
$d = $svc->saveDecision(['key' => 'wizard:nc_reviewed', 'decision' => 'ready', 'note' => 'niezgodności przejrzane na zebraniu jakości'], 2, $today);
$d = $svc->saveDecision(['key' => 'wizard:lots_qc_day_checked', 'decision' => 'not_applicable', 'note' => 'zadanie Jakości — teczka partii osobno'], 2, $today);
$d = $svc->saveDecision(['key' => 'erp:nc', 'decision' => 'action', 'due_on' => '2026-12-15', 'note' => 'zamknąć NC/1 i NC/2 — Jakość'], 2, $today);
$d = $svc->saveDecision(['key' => 'erp:haccp', 'decision' => 'ready', 'note' => 'przerwanie z 02.11 opisane w karcie HACCP'], 2, $today);
$d = $svc->saveDecision(['key' => 'erp:ppwr', 'decision' => 'not_applicable', 'note' => 'folia w wycofaniu'], 2, $today);
$d = $svc->saveDecision(['key' => 'erp:sop', 'decision' => 'action', 'due_on' => '2026-12-20', 'note' => 'przegląd SOP-01 i data dla SOP-02'], 2, $today);
$st = $tasks->state(S::CODE, '2026-10-01 00:00:00', '2027-01-01 00:00:00', '2026-10-14');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '9 elementów z decyzją', 'quarterly task state : in progress');
foreach ($d['review'] as $i) {
    if (!$i['waiting'] || $i['kind'] !== 'doc') continue;
    $svc->saveItem(['id' => $i['doc']['id'], 'title' => $i['doc']['title'], 'area' => $i['doc']['area'], 'format' => $i['doc']['format'], 'owner' => 'Biuro', 'location' => 'Folder « Audyt » na dysku',
        'check_months' => '3'], 2, $today);
    $d = $svc->saveDecision(['key' => $i['key'], 'decision' => 'ready'], 2, $today);
}
$check($d['can_finish'] && $d['totals']['waiting'] === 0 && $d['totals']['ready'] === 9 && $d['totals']['action'] === 3 && $d['totals']['not_applicable'] === 2 && $erp0 === $sum(),
    'all decided → step 3 ; decisions and list write nothing in the ERP');
$pdf = $svc->pdf($today, 'Kasia P.');
$check($pdf['filename'] === 'TECZKA_AUDYT_2026-Q4.pdf' && str_starts_with($pdf['content'], '%PDF') && strlen($pdf['content']) > 5000, 'PDF « teczka » (TCPDFService)');
if (getenv('AF_PDF_OUT')) file_put_contents((string) getenv('AF_PDF_OUT'), $pdf['content']);
$r = $svc->finish(2, $today);
$check($r['summary'] === 'IV kwartał 2026 : asystenci 3 w rytmie / 6 · otwarte sprawy w 4 z 5 · dokumenty 8 gotowe / 8 · decyzje : 9 gotowe · 3 do uzupełnienia · 2 nie dotyczy'
    && (int) $pdo->query("SELECT COUNT(*) FROM n_wizard_done WHERE code = 'audit_folder_reviewed' AND day = '2026-11-24'")->fetchColumn() === 1, 'finish : summary, n_wizard_done');
$check($tasks->state(S::CODE, '2026-10-01 00:00:00', '2027-01-01 00:00:00', '2026-10-14')['done'] === true && $tasks->state(S::CODE, '2027-01-01 00:00:00', '2027-04-01 00:00:00', '2027-01-15')['done'] === false,
    'task state : done this quarter, open again next quarter');
$x = array_column($make()->data('2026-12-18')['review'], null, 'key');
$check($x['erp:nc']['waiting'] && $x['erp:nc']['reason'] === 'termin uzupełnienia 15.12.2026 minął' && !$x['erp:sop']['waiting'], 'action past its date within the quarter waits again');
$n = $make()->data('2027-01-05');
$nv = array_column($n['review'], null, 'key');
$check($n['quarter_label'] === 'I kwartał 2027' && $nv['erp:nc']['waiting'] && $nv['erp:nc']['decision'] === null && !isset($nv['doc:' . $doc[10]]) && $n['done'] === null,
    'next quarter : no decision carried (each folder reviewed again) ; document checked in November not due');

// --- Contrôleur : construit par PHP-DI, data(), pdf() et les écritures réellement appelés.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=erp_audit_folder_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(AuditFolderController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 4), (4, 3)');
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), $res]; };
$erp3 = $sum();
[$c2, $r2] = $call(2, 'data');
$b2 = json_decode((string) $r2->getContent(), true);
$check($c2 === 200 && $b2['data']['quarter_start'] === S::quarterStart(date('Y-m-d')) && array_column($b2['data']['checks'], null, 'key')['erp:nc']['links'] === [],
    'controller data() (Kierownik produkcji, « audit_folder ») : 200, real quarter ; no link to screens he cannot open');
[$c1, $r1] = $call(1, 'data');
$check($c1 === 200 && array_column(json_decode((string) $r1->getContent(), true)['data']['checks'], null, 'key')['erp:nc']['links'][0]['href'] === '/purchasing/nc', 'controller data() (administrator) : links');
[$cp, $rp] = $call(2, 'pdf');
$check($cp === 200 && $rp->headers->get('Content-Type') === 'application/pdf' && str_starts_with((string) $rp->getContent(), '%PDF'), 'controller pdf() : PDF');
$by = [];
foreach (['data', 'decision', 'item', 'removeItem', 'finish', 'pdf'] as $m) { [$by[$m]] = $call(4, $m); }
[$c3] = $call(3, 'data');
$check(array_values(array_unique($by)) === [403] && $c3 === 403, 'controller : without « audit_folder » (Piekarz, Administracja) → 403 everywhere');
[$cD2] = $call(2, 'decision'); [$cI2] = $call(2, 'item'); [$cF2] = $call(2, 'finish');
$check($cD2 === 422 && $cI2 === 422 && in_array($cF2, [200, 422], true), 'controller decision(), item() and finish() really called');
$check($erp3 === $sum(), 'controller calls write nothing in the ERP');
unset($_SESSION['USER']);

// --- Aucun envoi, aucun appel extérieur, aucune écriture dans l'ERP : code sans les commentaires.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/AuditFolderService.php', 'App/Repositories/Workshop/AuditFolderRepository.php',
    'App/Controllers/Web/Audit/AuditFolderController.php', 'public/assets/js/audit_folder/audit-folder.js']));
$check(!preg_match('/UPDATE\s+(n_mgr|n_nc|complaint|n_sop|raw_material|final_product|n_wizard_done)|INSERT\s+INTO\s+(n_mgr|n_nc|complaint|n_sop|raw_material)|DELETE\s+FROM|error_log|PHPMailer|Sms|OutgoingMessage|curl_|https?:\/\//i', $src),
    'no write to the ERP tables, no delete, no log, no mail / SMS, no external call, no hard-coded URL');

// --- Enregistrement
$check(TaskWizards::info('audit_folder_reviewed')['link'] === '/audit-folder' && TaskWizards::byLink('/audit-folder') === 'audit_folder_reviewed'
    && isset(TaskWizards::AUTO['audit_folder_reviewed']) && PositionTaskService::LINK_AUTO['/audit-folder'] === 'audit_folder_reviewed', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['audit_folder_reviewed'][0] === 'Teczka do audytu i kontroli (przegląd kwartału)' && TaskWizards::CATALOG['audit_folder_reviewed'][0] !== 'Teczka do audytu i kontroli',
    'catalog label distinct from the proposed name (237)');
$check(array_diff(S::WIZARDS, array_keys(TaskWizards::CATALOG)) === [], 'every assistant of the folder exists in the catalog');
$check(PageAccess::keyFor('/audit-folder') === 'audit_folder' && PageAccess::keyFor('/ajax/audit-folder/pdf') === 'audit_folder' && in_array('audit_folder', PageAccess::grantable(), true),
    'own right « audit_folder » (page, PDF and ajax)');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'audit_folder_reviewed' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/audit-folder'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Audit/AuditFolderRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/audit-folder') === 'index' && $found('GET', '/ajax/audit-folder') === 'data' && $found('GET', '/ajax/audit-folder/pdf') === 'pdf'
    && $found('POST', '/ajax/audit-folder/decision') === 'decision' && $found('POST', '/ajax/audit-folder/item') === 'item' && $found('POST', '/ajax/audit-folder/item/remove') === 'removeItem'
    && $found('POST', '/ajax/audit-folder/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/audit-folder')[1]['controller'] === AuditFolderController::class && $allR->dispatch('GET', '/ppwr/month')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing routes unchanged');

if (getenv('AF_JSON_OUT')) file_put_contents((string) getenv('AF_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_audit_folder_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
