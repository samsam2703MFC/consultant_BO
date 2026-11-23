<?php
/**
 * [403] Asystent « Stany magazynowe na koniec miesiąca » : mois examiné = le mois écoulé ; produits finis au début et à la
 * fin du mois (règle du rejestr ruchów : étiquette = entrée, palette expédiée = sortie ; carton de novembre ignoré),
 * valeur au coût de la karta produktu ; surowce = dernière inwentaryzacja + przyjęcia − zużycie des recettes bornés à la
 * fin du mois (sans inwentaryzacja : przyjęcia seules), prix de l'historique à la date sinon actuel ; signaux (sans coût,
 * périmés, inactif, négatif, sans inwentaryzacja, sans prix, écart au-delà des réglages) ; « brak danych » sans table ;
 * instantané : décisions refusées avant, données et fichiers figés après (les stocks changent, l'instantané non), PDF et
 * CSV tirés de l'instantané ; décisions avec note, « Przekazane do księgowości » seulement quand tout est décidé, puis
 * instantané verrouillé ; « Zakończ » → n_wizard_done, état de la tâche ; aucune écriture dans les tables d'origine
 * (checksums), aucun e-mail ; contrôleur construit par PHP-DI et appelé (droit « stock_month » 200, sans droit 403, liens
 * filtrés) ; enregistrement (catalogue, droits, réglages, routes, équipe, menu) ; migration 243 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_kp_stock_month_test recréée (tables minimales + migration 243), supprimée à la fin (SM_JSON_OUT=fichier :
 * données de la page avec l'instantané, avant les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Stock\StockMonthController;
use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Stock\FinalProduct\StockLedgerRepository;
use App\Repositories\Workshop\{ParamRepository, StockMonthRepository};
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\StockMonthService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$db = 'erp_kp_stock_month_test';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("DROP DATABASE IF EXISTS {$db}"); $pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); $pdo->exec("USE {$db}");
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
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
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'JAKOSC', 'Specjalista ds. jakości', 1), (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 2),
    (3, 'PIEKARZ', 'Piekarz', 7), (4, 'ADMINISTRACJA', 'Administracja', 3)");
$pdo->exec("INSERT INTO n_task_category (id, code) VALUES (1, 'PRODUKCJA'), (5, 'UPLOAD')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (2, '6.4', NULL, 'Stany magazynowe na koniec miesiąca', 'monthly'),
    (2, '6.5', 'intrastat_month_reviewed', NULL, 'monthly'), (1, '9', NULL, 'Stany magazynowe na koniec miesiąca', 'monthly'), (2, '4.1', NULL, 'Dokumenty wysyłek miesiąca', 'monthly')");
$pdo->exec("INSERT INTO n_position_task (id_position, title_pl, frequency, link_path, auto_code, sort_order) VALUES (2, 'Czas pracy dnia', 'daily', '/time', 'time_day_validated', 4)");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'time'), (2, 'stock_pallets'), (2, 'supply'), (4, 'clients')");
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('stock_month.variance_pct', '50', '50', 'int', 'x', 'x')");
$m243 = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/243_stock_month.sql'));
for ($k = 0; $k < 2; $k++) foreach ($m243 as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 243 (deux passages)
$duties = $pdo->query('SELECT id_position, number, wizard_code, proposed_wizard FROM n_position_duty ORDER BY id')->fetchAll();
$check([$duties[0]['wizard_code'], $duties[0]['proposed_wizard']] === ['stock_month_reported', null], 'migration 243: KIEROWNIK_PRODUKCJI 6.4 → stock_month_reported');
$check($duties[1]['wizard_code'] === 'intrastat_month_reviewed' && $duties[2]['wizard_code'] === null && $duties[2]['proposed_wizard'] === 'Stany magazynowe na koniec miesiąca'
    && $duties[3]['proposed_wizard'] === 'Dokumenty wysyłek miesiąca', 'migration: other duties and other positions untouched');
$tasks = $pdo->query("SELECT * FROM n_position_task WHERE auto_code = 'stock_month_reported'")->fetchAll();
$check(count($tasks) === 1 && (int) $tasks[0]['id_position'] === 2 && $tasks[0]['month_day'] === 'wd3' && $tasks[0]['link_path'] === '/stock/month-end' && (int) $tasks[0]['id_category'] === 5
    && (int) $tasks[0]['sort_order'] === 5, 'one monthly task for Kierownik produkcji (3rd working day), category « Dokumenty »');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) AS k FROM n_page_access GROUP BY id_position ORDER BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [2 => 'stock_month,stock_pallets,supply,time', 4 => 'clients'], 'rights : stock_month only, for Kierownik produkcji only (no other right added)');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'stock_month.%' ORDER BY code")->fetchAll(), 'value', 'code');
$check($params === ['stock_month.variance_min_pln' => '1000', 'stock_month.variance_pct' => '50'] && isset(ParamRules::RANGES['stock_month.variance_pct'], ParamRules::RANGES['stock_month.variance_min_pln']),
    'settings seeded once (existing value kept), bounds in ParamRules');
$check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_stock_month_%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_stock_month_%'")->fetchColumn() === 3, 'three tables, no foreign key');

$lw = new LightWizardRepository($pdo);
$make = fn() => new StockMonthService(new StockMonthRepository($pdo), new StockLedgerRepository($pdo), $lw, new ParamRepository($pdo));
$today = '2026-11-24';
$e = $make()->data($today);
$check($e['available'] === false && $e['items'] === [] && $e['snapshot'] === null && $e['month_start'] === '2026-10-01' && $e['month_end'] === '2026-10-31'
    && str_starts_with($err(fn() => $make()->snapshot(2, $today)), 'custom:422:Brak danych o stanach'), 'no stock table → « brak danych », no snapshot possible ; examined month = previous month');

foreach ([
    'CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(120), id_recipe INT NULL, id_category INT NULL, active TINYINT DEFAULT 1, pre_calculated_raw_price_per_carton DECIMAL(12,4) NULL)',
    'CREATE TABLE category_product (id INT PRIMARY KEY, name VARCHAR(80))',
    "CREATE TABLE carton_stock (id_carton INT AUTO_INCREMENT PRIMARY KEY, id_product INT, status TINYINT, date_add DATE, date_pallet DATETIME NULL, expiration_date DATE DEFAULT '2000-01-01', batch_number VARCHAR(20) NULL)",
    'CREATE TABLE unit (id INT PRIMARY KEY, name VARCHAR(20))',
    'CREATE TABLE raw_material (id INT PRIMARY KEY, name VARCHAR(120), id_unit INT NULL, price_per_unit DECIMAL(12,6) NULL, waste_perc DECIMAL(5,2) NULL) ENGINE=InnoDB',
    'CREATE TABLE n_raw_material_loss (id_raw_material INT PRIMARY KEY, loss_perc DECIMAL(5,2), review TINYINT DEFAULT 0)',
    'CREATE TABLE flattened_recipe_ingredient (id INT AUTO_INCREMENT PRIMARY KEY, recipe_id INT, material_id INT, quantity DECIMAL(12,4))',
    'CREATE TABLE n_mp_count (id INT AUTO_INCREMENT PRIMARY KEY, id_raw_material INT, count_date DATE, quantity DECIMAL(14,3))',
    'CREATE TABLE n_po_line (id INT AUTO_INCREMENT PRIMARY KEY, id_po INT, id_raw_material INT, quantity DECIMAL(14,3))',
    'CREATE TABLE n_po_receipt (id INT AUTO_INCREMENT PRIMARY KEY, id_po_line INT, received_on DATE, quantity DECIMAL(14,3))',
    'CREATE TABLE raw_material_price_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_raw_material INT, pack_price DECIMAL(12,4), package_size_in_base_unit DECIMAL(12,4), timestamp DATETIME, id_supplier INT NULL)',
] as $sql) $pdo->exec($sql);

// --- Données fictives : contrôle le mar. 24.11.2026, stany na 31.10.2026.
$pdo->exec("INSERT INTO user VALUES (1, 'admin', NULL), (2, 'kasia', 'Kasia P.'), (3, 'olek', NULL), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO final_product VALUES (20, 'Bagietka', 100, 1, 1, 9.5), (21, 'Croissant maślany', 101, 1, 1, NULL), (22, 'Tarta cytrynowa', 102, 2, 0, 12),
    (23, 'Quiche lotaryńska', 103, 2, 1, 14.2), (24, 'Brioche', 104, 2, 1, 5)");
$cs = $pdo->prepare('INSERT INTO carton_stock (id_product, status, date_add, date_pallet, expiration_date) VALUES (?, ?, ?, ?, ?)');
$add = function (int $n, int $p, int $st, string $in, ?string $out, string $exp = '2000-01-01') use ($cs) { for ($i = 0; $i < $n; $i++) $cs->execute([$p, $st, $in, $out, $exp]); };
$add(5, 20, 3, '2026-09-20', '2026-10-03 10:00:00');
$add(10, 20, 1, '2026-10-10', null, '2026-11-20');
$add(4, 21, 2, '2026-10-28', '2026-11-02 06:00:00');
$add(3, 22, 1, '2026-08-01', null, '2026-10-15');
$add(195, 23, 3, '2026-09-15', '2026-10-05 08:00:00');
$add(5, 23, 1, '2026-09-15', null, '2026-12-15');
$add(6, 24, 1, '2026-11-05', null);
$pdo->exec("INSERT INTO unit VALUES (3, 'g'), (5, 'szt')");
$pdo->exec("INSERT INTO raw_material VALUES (10, 'Mąka pszenna T55', 3, 0.004, 0), (11, 'Masło 82 %', 3, 0.031, 0), (12, 'Ser gruyère', 3, NULL, 0), (13, 'Karton 30x20x10', 5, 1.2, 0),
    (14, 'Pistacje łuskane', 3, 0.09, 0), (15, 'Cukier', 3, 0.003, 0)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (100, 10, 2000), (100, 11, 100)");
$pdo->exec("INSERT INTO n_mp_count (id_raw_material, count_date, quantity) VALUES (10, '2026-09-30', 50000), (11, '2026-10-01', 500), (13, '2026-09-30', 100), (14, '2026-08-31', 20000),
    (15, '2026-11-10', 9000)");
$pdo->exec("INSERT INTO n_po_line (id, id_po, id_raw_material, quantity) VALUES (1, 1, 10, 25000), (2, 2, 12, 2000), (3, 3, 14, 60000)");
$pdo->exec("INSERT INTO n_po_receipt (id_po_line, received_on, quantity) VALUES (1, '2026-10-15', 25000), (2, '2026-10-20', 2000), (3, '2026-10-10', 60000)");
$pdo->exec("INSERT INTO raw_material_price_changelog (id_raw_material, pack_price, package_size_in_base_unit, timestamp) VALUES (10, 70, 25000, '2026-01-01 10:00:00'),
    (10, 80, 25000, '2026-10-01 10:00:00'), (10, 100, 25000, '2026-11-15 10:00:00')");

$erpTables = ['final_product', 'carton_stock', 'raw_material', 'unit', 'flattened_recipe_ingredient', 'n_mp_count', 'n_po_line', 'n_po_receipt', 'raw_material_price_changelog', 'user'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $erpTables));
$erp0 = $sum();
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

$svc = $make();
$d = $svc->data($today);
$it = array_column($d['items'], null, 'key');
$check($d['snapshot'] === null && $d['available'] && array_keys($it) === ['product:20', 'product:21', 'product:23', 'product:22', 'material:13', 'material:11', 'material:10', 'material:14', 'material:12'],
    'live statement before the snapshot : products then materials by name (November-only product and material counted after the month not listed)');
$check($it['product:20']['quantity'] == 10 && $it['product:20']['value'] == 95.0 && $it['product:20']['opening_value'] == 47.5 && $it['product:20']['codes'] === []
    && $it['product:23']['quantity'] == 5 && $it['product:21']['quantity'] == 4, 'products : cartons at month end by the ledger rule (shipped in October out, palletised not yet shipped in), card cost');
$check($it['material:10']['quantity'] == 55000.0 && $it['material:10']['value'] == 176.0 && $it['material:10']['opening_value'] == 140.0 && $it['material:10']['qty_label'] === '55 kg'
    && str_contains($it['material:10']['sub'], '3,20 zł / kg (cena z końca miesiąca)'), 'flour : count 30.09 + receipt 15.10 − 10 cartons × 2 kg ; price from the price history at 31.10 (not the November price)');
$check($it['material:11']['quantity'] == -500.0 && $it['material:11']['codes'] === ['NEGATIVE'] && $it['material:13']['codes'] === [] && $it['material:13']['qty_label'] === '100 szt',
    'butter : count 1.10 − consumption → negative ; packaging counted, no signal');
$check($it['material:12']['codes'] === ['NO_COUNT', 'NO_PRICE'] && $it['material:12']['value'] === null && $it['material:12']['basis'] === 'receipts', 'cheese : receipts only (no inventory), no price → out of the total');
$check($it['product:21']['codes'] === ['NO_COST'] && $it['product:22']['codes'] === ['EXPIRED', 'INACTIVE'] && $it['product:22']['signals'][0]['detail'] === '3 kart. po dacie ważności'
    && $it['product:23']['codes'] === ['VARIANCE'] && $it['product:23']['signals'][0]['detail'] === 'z 2 840,00 zł na 71,00 zł (−98 %)'
    && $it['material:14']['codes'] === ['VARIANCE'] && $it['material:14']['signals'][0]['detail'] === 'z 1 800,00 zł na 7 200,00 zł (+300 %)', 'signals : no cost, expired and inactive, value variances beyond 50 % and 1 000 zł');
$t = $d['totals'];
$check($t['products']['value'] == 202.0 && $t['products']['rows'] === 4 && $t['products']['cartons'] === 22 && $t['materials']['value'] == 7480.5 && $t['materials']['rows'] === 5 && $t['value'] == 7682.5
    && $t['review'] === 6 && $t['waiting'] === 6 && $d['can_finish'] === false && $d['can_hand'] === false, 'totals (lines without price out of the sum)');
$check(array_column($d['review'], 'key') === ['product:22', 'product:21', 'product:23', 'material:12', 'material:11', 'material:14'], 'signalled lines : products then materials, most signals first');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'confirmed', 'note' => 'abc'], 2, $today)), 'custom:422:Najpierw zapisz zestawienie')
    && str_starts_with($err(fn() => $svc->csv($today)), 'custom:404:Brak zapisanego zestawienia') && str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Najpierw zapisz zestawienie'),
    'before the snapshot : no decision, no file, no finish');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Instantané
$pdo->exec("UPDATE n_prod_param SET value = 'abc' WHERE code = 'stock_month.variance_min_pln'");
$check($make()->param('variance_min') === 1000 && $make()->param('variance_pct') === 50, 'invalid setting → default');
$d = $svc->snapshot(2, $today);
$boot = $d;
$head = $pdo->query("SELECT * FROM n_stock_month_statement")->fetch();
$check($d['snapshot'] !== null && $d['snapshot']['by'] === 'Kasia P.' && $d['snapshot']['after_month'] === true && $head['month_start'] === '2026-10-01' && (float) $head['products_value'] == 202.0
    && (float) $head['materials_value'] == 7480.5 && (int) $head['products_rows'] === 4 && (int) $pdo->query('SELECT COUNT(*) FROM n_stock_month_item')->fetchColumn() === 9, 'snapshot saved (header + 9 lines)');
$st = $tasks->state(StockMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === 'zestawienie zapisane · 0 decyzji', 'task state : snapshot saved');
// Les stocks changent après l'instantané : l'écran et les fichiers restent ceux de l'instantané.
$add(50, 20, 1, '2026-10-30', null);
$pdo->exec("UPDATE final_product SET pre_calculated_raw_price_per_carton = 99 WHERE id = 23");
$erp1 = $sum();
$d2 = $make()->data($today);
$check(array_column($d2['items'], 'quantity', 'key') === array_column($d['items'], 'quantity', 'key') && $d2['totals']['value'] == 7682.5, 'after the snapshot the statement is frozen (later carton and cost changes ignored)');
$csv = $svc->csv($today);
$lines = explode("\r\n", trim($csv['content']));
$check($csv['name'] === 'STANY_MAGAZYNOWE_2026-10.csv' && str_starts_with($lines[0], "\u{FEFF}\"Miesiąc\";\"Stan na dzień\";\"Rodzaj\"") && count($lines) === 10
    && str_contains($csv['content'], '"Produkt gotowy";"20";"Bagietka";"10,000";"kart.";"9,500000";"card";"95,00";"5,000";"47,50";"ledger"')
    && str_contains($csv['content'], '"Surowiec";"10";"Mąka pszenna T55";"55000,000";"g";"0,003200";"history";"176,00"'), 'CSV from the snapshot (BOM, « ; », comma decimals, one line per item)');
$pdf = $svc->pdf($today);
$check(str_starts_with($pdf['content'], '%PDF') && $pdf['name'] === 'STANY_MAGAZYNOWE_2026-10.pdf', 'PDF from the snapshot');
$check($erp1 === $sum(), 'snapshot and files write nothing in the source tables');

// --- Krok 2 : decyzje i przekazanie
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'x', 'note' => 'abc'], 2, $today)), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'to_correct', 'note' => ''], 2, $today)), 'custom:422:Do korekty : wpisz')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:11', 'decision' => 'confirmed', 'note' => 'x'], 2, $today)), 'custom:422:Sprawdzone : wpisz'), 'both decisions need a note');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:13', 'decision' => 'confirmed', 'note' => 'abc'], 2, $today)), 'custom:422:Tej pozycji nie ma'), 'line without signal refused');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'statement', 'decision' => 'handed', 'note' => 'Asia, e-mail'], 2, $today)), 'custom:422:Najpierw zapisz decyzję dla 6 pozycji'), 'handover refused while lines wait');
$d = $svc->saveDecision(['key' => 'material:11', 'decision' => 'to_correct', 'note' => 'Brak przyjęcia faktury masła 12/10 — korekta w księgowości'], 2, $today);
$row = $pdo->query("SELECT * FROM n_stock_month_review WHERE item_key = 'material:11'")->fetch();
$check($row['month_start'] === '2026-10-01' && $row['decision'] === 'to_correct' && $row['flags'] === 'NEGATIVE' && (int) $row['id_user'] === 2, 'decision saved for the examined month with the signal codes');
foreach (['product:22' => 'Tarta wycofana — 3 kartony do utylizacji', 'product:21' => 'Koszt karty do uzupełnienia (receptura)', 'product:23' => 'Wysyłka sezonowa 5.10 — stan poprawny',
    'material:12' => 'Ser z dostawy 20.10, inwentaryzacja 30.11', 'material:14' => 'Zakup na sezon świąteczny'] as $k => $note) $d = $svc->saveDecision(['key' => $k, 'decision' => 'confirmed', 'note' => $note], 2, $today);
$check($d['totals']['waiting'] === 0 && $d['can_hand'] === true && $d['can_finish'] === false && str_starts_with($err(fn() => $svc->finish(2, $today)), 'custom:422:Zapisz « Przekazane do księgowości »'),
    'all lines decided : handover open, finish still blocked');
$check(str_starts_with($err(fn() => $svc->saveDecision(['key' => 'statement', 'decision' => 'handed', 'note' => ''], 2, $today)), 'custom:422:Przekazane do księgowości : wpisz')
    && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'statement', 'decision' => 'confirmed', 'note' => 'abc'], 2, $today)), 'custom:422:Wybierz « Przekazane'), 'handover needs its own decision and a note');
$d = $svc->saveDecision(['key' => 'statement', 'decision' => 'handed', 'note' => 'Asia — PDF i CSV e-mailem 24.11'], 2, $today);
$check($d['handed']['label'] === 'Przekazane do księgowości' && $d['can_finish'] === true, 'handed over');
$check(str_starts_with($err(fn() => $svc->snapshot(2, $today)), 'custom:422:Zestawienie jest już przekazane') && str_starts_with($err(fn() => $svc->saveDecision(['key' => 'material:14', 'decision' => 'to_correct', 'note' => 'abc'], 2, $today)), 'custom:422:Zestawienie jest już przekazane'),
    'after the handover : snapshot and decisions locked');
$csv2 = $svc->csv($today);
$check(str_contains($csv2['content'], '"Do korekty";"Brak przyjęcia faktury masła 12/10 — korekta w księgowości"'), 'CSV carries the decisions');
$r = $svc->finish(2, $today);
$check($r['summary'] === 'październik 2026 : produkty 202,00 zł (4 poz.) · surowce 7 480,50 zł (5 poz.) · 6 z sygnałem · decyzje : 5 sprawdzone · 1 do korekty · przekazane'
    && $r['data']['done'] !== null, 'finish : summary, n_wizard_done');
$st = $tasks->state(StockMonthService::CODE, '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-30');
$check($st['done'] === true && $st['summary'] === $r['summary'] && $tasks->state(StockMonthService::CODE, '2026-12-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31')['done'] === false,
    'task state : done this month, open again next month');
$n = $make()->data('2026-12-02');
$check($n['month_start'] === '2026-11-01' && $n['snapshot'] === null && $n['handed'] === null, 'next month : November, no snapshot yet');
$check($erp1 === $sum(), 'decisions and finish write nothing in the source tables');

// --- Contrôleur : construit par PHP-DI, appels réels.
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=' . $db, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])]);
$ctl = $b->build()->get(StockMonthController::class);
$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 2), (3, 1), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'stock_month')");
$call = function (int $user, string $method) use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true), $res]; };
$filter = (new ReflectionClass($ctl))->getMethod('withLinks');
$hrefs = fn(array $data) => array_values(array_unique(array_merge([], ...array_map(fn($i) => array_map(fn($l) => strtok($l['href'], '#?'), $i['links']), $data['items']))));
$asUser = function (int $u) use ($filter, $ctl, $make, $today) { $_SESSION['USER'] = ['id' => $u, 'login' => 'u' . $u]; return $filter->invoke($ctl, $make()->data($today)); };
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
$check($c2 === 200 && $b2['data']['month_start'] === StockMonthService::reviewedMonth(date('Y-m-d')), 'controller data() (Kierownik produkcji) : 200');
$h2 = $hrefs($asUser(2));
$check(in_array('/logistics/stock', $h2, true) && in_array('/workshop/supply', $h2, true) && !in_array('/workshop/stock-movements', $h2, true) && !in_array('/materials/prices', $h2, true),
    'Kierownik produkcji links : Stany i palety, Zaopatrzenie (its rights) — not Ruchy magazynowe nor Ceny surowców without those rights');
$check($hrefs($asUser(3)) === [] && in_array('/workshop/stock-movements', $hrefs($asUser(1)), true), 'wizard right only : no link ; administrator : every link');
[$cP, , $rP] = $call(2, 'pdf');
[$cC, , $rC] = $call(2, 'csv');
$check(in_array($cP, [200, 404], true) && in_array($cC, [200, 404], true) && ($cP !== 200 || str_starts_with((string) $rP->getContent(), '%PDF')), 'controller pdf() and csv() really called (file of the snapshot or 404 without one)');
foreach (['data', 'snapshot', 'decision', 'finish', 'pdf', 'csv'] as $m) { [$c] = $call(4, $m); $codes4[$m] = $c; }
$check($codes4 === array_fill_keys(['data', 'snapshot', 'decision', 'finish', 'pdf', 'csv'], 403), 'controller : Piekarz without « stock_month » → 403 everywhere');
$check($erp3 === $sum(), 'controller calls write nothing in the source tables');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune écriture dans les sources.
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/StockMonthService.php', 'App/Repositories/Workshop/StockMonthRepository.php',
    'App/Controllers/Web/Stock/StockMonthController.php', 'public/assets/js/stock_month/stock-month.js']));
$check(!preg_match('/UPDATE\s+(carton|raw_material|final_product|n_mp_|n_po)|INSERT\s+INTO\s+(carton|raw_material|final_product|n_mp_|n_po)|DELETE\s+FROM\s+(?!n_stock_month_item)|PHPMailer|Mail::|SmsGateway|curl_|https?:\/\/|mailto/i', $src),
    'no write to stocks / prices, no mail/SMS sending, no hard-coded URL');

// --- Enregistrement.
$check(TaskWizards::info('stock_month_reported')['link'] === '/stock/month-end' && TaskWizards::byLink('/stock/month-end') === 'stock_month_reported'
    && isset(TaskWizards::AUTO['stock_month_reported']) && PositionTaskService::LINK_AUTO['/stock/month-end'] === 'stock_month_reported', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['stock_month_reported'][0] === 'Stany magazynowe — zestawienie miesiąca' && TaskWizards::CATALOG['stock_month_reported'][0] !== 'Stany magazynowe na koniec miesiąca',
    'catalog label distinct from the proposed name in the matrix (237)');
$check(PageAccess::keyFor('/stock/month-end') === 'stock_month' && PageAccess::keyFor('/ajax/stock/month-end/csv') === 'stock_month' && PageAccess::keyFor('/stock') === 'production'
    && in_array('stock_month', PageAccess::grantable(), true), 'own right « stock_month » (page, ajax and files) ; stock screens unchanged');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'stock_month_reported' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/stock/month-end'), 'team tasks hint and search entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Stock/StockMonthRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/stock/month-end') === 'index' && $found('GET', '/ajax/stock/month-end') === 'data' && $found('POST', '/ajax/stock/month-end/snapshot') === 'snapshot'
    && $found('POST', '/ajax/stock/month-end/decision') === 'decision' && $found('POST', '/ajax/stock/month-end/finish') === 'finish' && $found('GET', '/ajax/stock/month-end/pdf') === 'pdf'
    && $found('GET', '/ajax/stock/month-end/csv') === 'csv', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/stock/month-end')[1]['controller'] === StockMonthController::class && $allR->dispatch('GET', '/ajax/stock/stock-state')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing stock routes unchanged');

if (getenv('SM_JSON_OUT')) file_put_contents((string) getenv('SM_JSON_OUT'), json_encode(['before' => $boot], JSON_UNESCAPED_UNICODE));
$pdo->exec("DROP DATABASE {$db}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
