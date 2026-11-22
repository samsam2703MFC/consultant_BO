<?php
/**
 * [371] Asystent « Domówienia dnia » : fenêtre (domówienia passées depuis la validation précédente, sinon depuis le jour
 * ouvré précédent ; domówienia ouvertes à expédier d'ici le jour ouvré suivant), sans archivées ni commandes ordinaires ;
 * état côté production (zlecenie relié, plan validé après / avant la domówienie, proposition sur le vif, MTS, gratis,
 * sans fiche de planification, expédiée) ; commande principale (parent_order_id ou même jour) ; envoi au panel du
 * magasin (information) ; signaux (pas en production à ≤ 1 jour ouvré, après l'heure limite, date absente ou passée,
 * produit inactif) ; résultat par domówienie signalée (note obligatoire pour contact et report), étape 3 bloquée,
 * « Zakończ » → n_wizard_done, état de la tâche, enregistrement (catalogue, droits, routes), migration 219 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_supplements_day_test recréée (tables minimales + migration 219), supprimée à la fin (SD_JSON_OUT=fichier :
 * données de la page, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\SupplementsDayCheckRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\SupplementsDayCheckService;
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_supplements_day_test'); $pdo->exec('CREATE DATABASE erp_supplements_day_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_supplements_day_test');
$pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80))');
$pdo->exec('CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1)');
$pdo->exec("CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, order_description TEXT NULL, status INT DEFAULT 1,
    expected_shipping_date DATE NULL, create_order_date DATETIME, is_freebie TINYINT DEFAULT 0, order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD',
    parent_order_id INT NULL, supplement_push_status ENUM('pending','sent','failed') NULL, supplement_push_at DATETIME NULL, supplement_order_key VARCHAR(128) NULL,
    id_wdt INT NULL, id_proforma INT NULL)");
$pdo->exec('CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2))');
$pdo->exec("CREATE TABLE n_prod_item (id INT PRIMARY KEY, item_type ENUM('product','semi') NOT NULL DEFAULT 'product', id_final_product INT NULL, id_recipe INT NULL, UNIQUE KEY (id_final_product))");
$pdo->exec("CREATE TABLE n_prod_product_settings (id_item INT PRIMARY KEY, mode ENUM('MTO','MTS') NOT NULL DEFAULT 'MTO')");
$pdo->exec("CREATE TABLE n_prod_plan (id INT PRIMARY KEY, period_start DATE, period_end DATE, status ENUM('proposed','validated','frozen','closed') NOT NULL, version INT DEFAULT 1, validated_at DATETIME NULL)");
$pdo->exec("CREATE TABLE n_prod_order (id INT PRIMARY KEY, code VARCHAR(32), id_plan INT NULL, id_item INT, quantity DECIMAL(12,3), origin ENUM('MTO','MTS','SEMI'), due_at DATETIME,
    status ENUM('planned','released','in_progress','done','cancelled') NOT NULL DEFAULT 'planned')");
$pdo->exec('CREATE TABLE n_prod_order_demand (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_customer_order INT, id_final_product INT, quantity DECIMAL(12,3))');
$pdo->exec("CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
    done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))");
$pdo->exec("CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
    unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)");
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours')");
$pdo->exec("CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40))");
$pdo->exec("INSERT INTO n_position (id, code) VALUES (1, 'KIEROWNIK_OPERACYJNY'), (2, 'LOGISTYKA')");
$pdo->exec("CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
    rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '1.7', NULL, 'Domówienia dnia', 'daily'), (1, '1.1', 'orders_day_checked', NULL, 'daily'),
    (1, '1.3', 'customers_ordered_checked', NULL, 'daily'), (1, '1.5', NULL, 'Inny asystent', 'weekly'), (2, '9.9', NULL, NULL, 'none')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/219_supplements_day_check.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 219 (deux passages)
$duties = array_column($pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty')->fetchAll(), null, 'number');
$check($duties['1.7']['wizard_code'] === 'supplements_day_checked' && $duties['1.7']['proposed_wizard'] === null && $duties['1.7']['rhythm'] === 'daily',
    'migration 219: duty 1.7 → supplements_day_checked (daily)');
$check($duties['1.1']['wizard_code'] === 'orders_day_checked' && $duties['1.3']['wizard_code'] === 'customers_ordered_checked' && $duties['1.5']['proposed_wizard'] === 'Inny asystent'
    && $duties['9.9']['wizard_code'] === null, 'migration: other duties untouched');
$param = $pdo->query("SELECT value, value_type FROM n_prod_param WHERE code = 'orders.supplement_cutoff'")->fetchAll();
$check(count($param) === 1 && $param[0]['value'] === '12:00' && $param[0]['value_type'] === 'time'
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_supplement_check'")->fetchColumn() === 1,
    'migration replayed: one cutoff param (12:00, time), n_supplement_check created once');

// --- Données : aujourd'hui mar. 24.11.2026, jour ouvré suivant mer. 25.11 ; plan validé lun. 23.11 15:00 (23.11 – 29.11).
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer VALUES (1, 'Sklep A'), (2, 'Sklep B'), (3, 'Sklep C')");
$pdo->exec("INSERT INTO final_product VALUES (10, 'Croissant', 1), (11, 'Bagietka', 1), (12, 'Tarta jabłkowa', 0), (13, 'Ciasto drożdżowe', 1), (14, 'Rogal', 1)");
$pdo->exec("INSERT INTO n_prod_item (id, id_final_product) VALUES (1, 10), (2, 11), (3, 12), (4, 14)"); // 13 : sans fiche ; 14 : fiche sans réglages
$pdo->exec("INSERT INTO n_prod_product_settings (id_item, mode) VALUES (1, 'MTO'), (2, 'MTS'), (3, 'MTO')");
$pdo->exec("INSERT INTO n_prod_plan VALUES (1, '2026-11-23', '2026-11-29', 'validated', 1, '2026-11-23 15:00:00'), (2, '2026-11-16', '2026-11-22', 'closed', 1, '2026-11-13 10:00:00')");
$pdo->exec("INSERT INTO n_prod_order VALUES (501, 'OF-20261125-001', 1, 1, 400, 'MTO', '2026-11-25 18:00:00', 'released'), (502, 'OF-20261125-002', 1, 1, 50, 'MTO', '2026-11-25 18:00:00', 'cancelled')");
$order = function (int $id, int $cust, string $created, ?string $ship, array $lines, array $o = []) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, reference, status, expected_shipping_date, create_order_date, is_freebie, order_type, parent_order_id, supplement_push_status, supplement_push_at, id_wdt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $cust, $o['ref'] ?? null, $o['status'] ?? 1, $ship, $created, $o['freebie'] ?? 0, $o['type'] ?? 'SUPPLEMENT', $o['parent'] ?? null,
            $o['push'] ?? 'sent', isset($o['push']) && $o['push'] === null ? null : $created, $o['wdt'] ?? null]);
    foreach ($lines as $p => $q) $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (?, ?, ?)')->execute([$id, $p, $q]);
};
$order(150, 1, '2026-11-20 09:00:00', '2026-11-25', [10 => 20], ['type' => 'STANDARD']);              // commande principale de Sklep A
$order(160, 2, '2026-11-20 09:00:00', '2026-11-25', [10 => 12], ['type' => 'STANDARD']);              // commande du même jour de Sklep B
$order(202, 1, '2026-11-23 10:00:00', '2026-11-25', [10 => 4], ['parent' => 150, 'ref' => 'DOM-1']); // reliée à OF-…-001 → w planie
$order(203, 2, '2026-11-23 16:30:00', '2026-11-25', [10 => 3]);                                       // après la validation du plan → poza planem
$order(204, 3, '2026-11-24 13:10:00', '2026-11-25', [11 => 2]);                                       // MTS ; passée après 12:00 la veille
$order(205, 1, '2026-11-23 11:00:00', '2026-11-25', [10 => 1], ['freebie' => 1]);                      // gratis → hors quantités
$order(206, 2, '2026-11-23 12:00:00', '2026-12-02', [10 => 6]);                                       // semaine sans plan → proposition sur le vif
$order(207, 3, '2026-11-23 13:00:00', null, [10 => 2], ['push' => 'pending']);                         // sans date
$order(208, 1, '2026-11-23 14:00:00', '2026-11-26', [12 => 2]);                                       // produit inactif ; plan validé après → w planie
$order(209, 2, '2026-11-23 14:30:00', '2026-11-25', [13 => 2, 14 => 1]);                              // sans fiche de planification
$order(210, 3, '2026-11-23 08:00:00', '2026-11-23', [10 => 1]);                                       // date passée, sans WDT
$order(211, 1, '2026-11-23 09:30:00', '2026-11-24', [10 => 2], ['wdt' => 77]);                         // expédiée : listée, sans signal
$order(212, 1, '2026-11-23 09:40:00', '2026-11-25', [10 => 2], ['status' => 5]);                       // archivée : absente
$order(213, 3, '2026-11-23 09:50:00', '2026-11-25', [10 => 9], ['type' => 'STANDARD']);               // commande ordinaire : absente
$order(214, 2, '2026-11-18 09:00:00', '2026-11-25', [11 => 1], ['push' => 'failed']);                  // ancienne, expédiée demain → listée
$order(215, 3, '2026-11-18 09:00:00', '2026-11-27', [10 => 1]);                                       // ancienne, expédiée vendredi : absente
$order(216, 1, '2026-11-18 09:00:00', '2026-11-20', [10 => 1]);                                       // ancienne, date passée avant la fenêtre : absente
$pdo->exec("INSERT INTO n_prod_order_demand (id_order, id_customer_order, id_final_product, quantity) VALUES (501, 202, 10, 40), (502, 203, 10, 30)"); // 502 annulé : ne compte pas

$wizards = new LightWizardRepository($pdo);
$make = fn() => new SupplementsDayCheckService(new SupplementsDayCheckRepository($pdo), $wizards, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id) { throw new RuntimeException('not needed: ' . $id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);

// --- Fonctions pures
$wd = [1, 2, 3, 4, 5];
$check(SupplementsDayCheckService::workday('2026-11-27', 1, $wd) === '2026-11-30' && SupplementsDayCheckService::workday('2026-11-23', -1, $wd) === '2026-11-20'
    && SupplementsDayCheckService::workday('2026-11-24', 1, [2, 4]) === '2026-11-26', 'working days: Fri → Mon, Mon → Fri, custom production days');
$base = ['open' => true, 'shipping_date' => '2026-11-25', 'created_at' => '2026-11-23 10:00:00', 'freebie' => false];
$plan = [['period_start' => '2026-11-23', 'period_end' => '2026-11-29', 'validated_at' => '2026-11-23 15:00:00']];
$mto = [['id_product' => 10, 'quantity_carton' => 4, 'name' => 'Croissant', 'active' => 1, 'id_item' => 1, 'mode' => 'MTO']];
$check(SupplementsDayCheckService::production($base, $mto, [], $plan)['state'] === 'in_plan'
    && SupplementsDayCheckService::production(['created_at' => '2026-11-23 16:00:00'] + $base, $mto, [], $plan)['state'] === 'missing'
    && SupplementsDayCheckService::production($base, $mto, [], [])['state'] === 'live'
    && SupplementsDayCheckService::production(['shipping_date' => null] + $base, $mto, [], [])['state'] === 'missing'
    && SupplementsDayCheckService::production(['open' => false, 'status_label' => 'W transporcie'] + $base, $mto, [], [])['state'] === 'shipped'
    && SupplementsDayCheckService::production($base, [], [], $plan)['state'] === 'missing',
    'production state: plan validated after → in plan ; before → missing ; no plan → live ; no date → missing ; closed → shipped ; no lines → missing');
$mixed = SupplementsDayCheckService::production($base, [...$mto, ['id_product' => 11, 'quantity_carton' => 1, 'name' => 'Bagietka', 'active' => 1, 'id_item' => 2, 'mode' => 'MTS']], [], []);
$check($mixed['state'] === 'live' && $mixed['ok'] === true && array_column($mixed['lines'], 'state') === ['live', 'stock'], 'order state = most serious line (live over stock)');

// --- Étape 1 : vue d'ensemble (pas encore de validation → depuis lun. 23.11, 0:00)
$d = $svc->data($today);
$boot = $d; // aperçu (SD_JSON_OUT) : état avant les résultats
$by = array_column($d['orders'], null, 'id');
$flags = fn(int $id) => array_column($by[$id]['flags'] ?? [], 'code');
$state = fn(int $id) => $by[$id]['production']['state'] ?? null;
$check($d['since']['kind'] === 'workday' && $d['since']['at'] === '2026-11-23 00:00:00' && $d['next_workday'] === '2026-11-25', 'window from Mon 23.11, 0:00 ; next working day Wed 25.11');
$check(array_keys($by) === [207, 210, 211, 202, 205, 203, 209, 214, 204, 208, 206], 'listed and sorted (no date first, then shipping date, customer) — ' . implode(',', array_keys($by)));
$check(!isset($by[212]) && !isset($by[213]) && !isset($by[215]) && !isset($by[216]) && !isset($by[150]),
    'not listed: archived, ordinary orders, older supplement shipped after the next working day or before the window');
$check($by[214]['kind'] === 'upcoming' && $by[202]['kind'] === 'new' && $by[214]['upcoming'] === true && $by[206]['upcoming'] === false, 'new vs older supplement shipped tomorrow');
$check($state(202) === 'in_plan' && str_contains($by[202]['production']['detail'], 'OF-20261125-001 (zwolnione)') && !str_contains($by[202]['production']['detail'], 'OF-20261125-002')
    && $by[202]['products'][0]['of'] === 'OF-20261125-001 (zwolnione)', 'linked production order (cancelled order ignored)');
$check($state(203) === 'missing' && str_contains($by[203]['production']['detail'], 'przed domówieniem') && str_contains($by[203]['production']['detail'], 'pon. 23.11, 15:00'),
    'plan validated before the supplement: not in production');
$check($state(204) === 'stock' && $state(205) === 'freebie' && $state(206) === 'live' && $state(207) === 'missing' && $state(209) === 'unplanned' && $state(211) === 'shipped'
    && $state(208) === 'in_plan' && str_contains($by[208]['production']['detail'], 'po domówieniu') && $state(210) === 'in_plan',
    'states: MTS stock, freebie, live proposal, no date, no planning card, shipped, plan validated after');
$check(array_column($by[209]['products'], 'state') === ['unplanned', 'unplanned'] && str_contains($by[209]['production']['detail'], 'Ciasto drożdżowe'), 'no card and card without settings: unplanned');
$check($by[202]['parent']['id'] === 150 && $by[202]['parent']['kind'] === 'linked' && $by[202]['parent']['same_day'] === true
    && $by[203]['parent']['id'] === 160 && $by[203]['parent']['kind'] === 'same_day' && $by[204]['parent']['id'] === 213 && $by[206]['parent'] === null, 'main order: linked (parent_order_id), same day for the customer, none');
$check($by[214]['push']['status'] === 'failed' && $by[207]['push']['status'] === 'pending' && $by[202]['push']['label'] === 'w systemie sklepu', 'shop panel push shown as information');
$check($flags(203) === ['not_in_production'] && str_contains($by[203]['flags'][0]['reason'], 'śr. 25.11'), 'flag: not in production, shipping on the next working day');
$check($flags(205) === ['not_in_production'] && $flags(209) === ['not_in_production'], 'flag: freebie and unplanned products count as not transmitted');
$check($flags(204) === ['late_cutoff'] && str_contains($by[204]['flags'][0]['reason'], 'po godzinie 12:00 dnia wt. 24.11'), 'flag: after the 12:00 cutoff of the day before shipping');
$check($flags(207) === ['date_missing'] && $flags(210) === ['date_past'] && $flags(208) === ['product_inactive'] && str_contains($by[208]['flags'][0]['reason'], 'Tarta jabłkowa'),
    'flags: missing date, past date, inactive product');
$check($flags(202) === [] && $flags(206) === [] && $flags(211) === [] && $flags(214) === [], 'no flag: in plan, live proposal far away, shipped, MTS');
$t = $d['totals'];
$check($t['supplements'] === 11 && $t['new'] === 10 && $t['upcoming'] === 6 && $t['shipped'] === 1 && $t['in_production'] === 6 && $t['not_in_production'] === 4
    && $t['push_failed'] === 1 && $t['flagged'] === 7 && $t['waiting'] === 7 && $d['can_finish'] === false, 'tiles: 11 supplements, 6 in production, 7 to clarify, step 3 closed');
$check($t['cartons'] == 4 + 3 + 2 + 1 + 6 + 2 + 2 + 3 + 1 + 2 + 1, 'tile: cartons');

// Réglages : heure limite 13:30 → 204 (13:10) n'est plus en retard ; valeur invalide → 12:00.
$pdo->exec("UPDATE n_prod_param SET value = '13:30' WHERE code = 'orders.supplement_cutoff'");
$c = array_column($make()->data($today)['orders'], null, 'id');
$check($c[204]['flags'] === [], 'cutoff 13:30: the 13:10 supplement is on time');
$pdo->exec("UPDATE n_prod_param SET value = 'xx' WHERE code = 'orders.supplement_cutoff'");
$check($make()->settings()['cutoff'] === '12:00', 'invalid cutoff falls back to 12:00');
$pdo->exec("UPDATE n_prod_param SET value = '12:00' WHERE code = 'orders.supplement_cutoff'");

// --- Étape 2 : résultats (lecture seule : commandes et plan inchangés jusqu'à la fin)
$sum = fn() => json_encode($pdo->query('CHECKSUM TABLE customer_order, customer_order_product, n_prod_plan, n_prod_order, n_prod_order_demand')->fetchAll());
$before = $sum();
$check(str_contains($err(fn() => $svc->finish(1, $today)), 'custom:422') && str_contains($err(fn() => $svc->finish(1, $today)), '7 domówień'), 'finish refused while flagged supplements have no result');
$check(str_contains($err(fn() => $svc->saveResult(['id_order' => 203, 'result' => 'contact', 'note' => ''], 1, $today)), 'notatk')
    && str_contains($err(fn() => $svc->saveResult(['id_order' => 203, 'result' => 'moved', 'note' => 'ab'], 1, $today)), 'notatk'), 'contact or moved without a note (≥ 3 chars): refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_order' => 203, 'result' => 'maybe'], 1, $today)), 'custom:422'), 'unknown result: refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_order' => 215, 'result' => 'sent'], 1, $today)), 'nie ma na liście')
    && str_contains($err(fn() => $svc->saveResult(['id_order' => 202, 'result' => 'sent'], 1, $today)), 'nic do wyjaśnienia'), 'supplement outside the list or without a flag: refused');
$svc->saveResult(['id_order' => 203, 'result' => 'contact', 'note' => 'x y z'], 1, $today);
$svc->saveResult(['id_order' => 203, 'result' => 'sent', 'note' => 'Lista dla rogalni uzupełniona'], 1, $today); // upsert : le dernier compte
$row = $pdo->query('SELECT * FROM n_supplement_check WHERE id_order = 203')->fetchAll();
$check(count($row) === 1 && $row[0]['result'] === 'sent' && $row[0]['day'] === $today && $row[0]['flags'] === 'not_in_production' && $row[0]['production_state'] === 'missing',
    'one row per supplement per day (upsert), with flags and production state');
$st = $tasks->state(SupplementsDayCheckService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 domówienie wyjaśnione', 'task in progress: step 2, 1 supplement clarified');
foreach ([204 => ['sent', ''], 205 => ['sent', 'Gratis dopisany do listy pakowania'], 207 => ['contact', 'Tel. z p. Nowak : wysyłka pt.'], 208 => ['moved', 'Tarta wycofana — odrzucone'],
    209 => ['sent', 'Ciasto z bieżącej produkcji']] as $id => [$res, $note]) {
    $svc->saveResult(['id_order' => $id, 'result' => $res, 'note' => $note], 2, $today);
}
$d = $svc->data($today);
$check($d['totals']['waiting'] === 1 && $d['can_finish'] === false && str_contains($err(fn() => $svc->finish(1, $today)), 'Najpierw wyjaśnij 1 domówienie'), 'one left: step 3 still blocked');
$svc->saveResult(['id_order' => 210, 'result' => 'moved', 'note' => 'Wysyłka przesunięta na śr. 25.11'], 1, $today);
$d = $svc->data($today);
$by = array_column($d['orders'], null, 'id');
$check($d['can_finish'] === true && $d['summary'] === '11 domówień · 7 do wyjaśnienia · 4 przekazane do produkcji · 1 kontakt · 2 przesunięte / odrzucone', 'all clarified: step 3 open — ' . $d['summary']);
$check($by[207]['check']['by'] === 'piotr' && $by[203]['check']['by'] === 'Anna K.' && $by[203]['check']['label'] === 'Przekazane do produkcji', 'result shown with label and author');
// --- Étape 3 : Zakończ
$r = $svc->finish(1, $today);
$done = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'supplements_day_checked'")->fetch();
$check($done && $done['day'] === $today && $done['summary'] === $r['summary'] && (int) $done['done_by'] === 1 && $r['data']['done'] !== null, 'finish writes n_wizard_done (code, day, summary, user)');
$check($sum() === $before, 'read only: orders and production plan unchanged by results and finish');
$st = $tasks->state(SupplementsDayCheckService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'position task done with the summary');

// --- Fenêtre depuis la validation précédente (lun. 23.11, 12:30)
$pdo->exec("INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES ('supplements_day_checked', '2026-11-23', 'x', 1, '2026-11-23 12:30:00')");
$d = $svc->data($today);
$ids = array_column($d['orders'], 'id');
sort($ids);
$check($d['since']['kind'] === 'done' && $d['since']['label'] === 'od zatwierdzenia pon. 23.11, 12:30' && $ids === [202, 203, 204, 205, 207, 208, 209, 210, 214],
    'window from the previous validation: supplements after it + open ones shipped by the next working day — ' . implode(',', $ids));
$check(array_column($d['orders'], null, 'id')[202]['kind'] === 'upcoming' && $d['done'] !== null, 'earlier supplement shipped tomorrow kept as « upcoming » ; already done today: shown');
$check($svc->data('2026-11-25')['orders'] !== [] && array_column($svc->data('2026-11-25')['orders'], null, 'id')[203]['check'] === null, 'next day: results start again (one per day)');

// --- Enregistrement : catalogue, lien, droits, routes
$check(TaskWizards::info('supplements_day_checked')['link'] === '/client-orders/supplements' && TaskWizards::byLink('/client-orders/supplements') === 'supplements_day_checked'
    && isset(TaskWizards::AUTO['supplements_day_checked']) && PositionTaskService::LINK_AUTO['/client-orders/supplements'] === 'supplements_day_checked', 'catalog, auto description and position-task link');
$check(PageAccess::keyFor('/client-orders/supplements') === 'orders' && PageAccess::keyFor('/ajax/client-orders/supplements') === 'orders'
    && PageAccess::keyFor('/ajax/client-orders/supplements/result') === 'orders', 'page and ajax under the « orders » right');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Client/OrderRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/client-orders/supplements') === 'index' && $found('GET', '/ajax/client-orders/supplements') === 'data'
    && $found('POST', '/ajax/client-orders/supplements/result') === 'result' && $found('POST', '/ajax/client-orders/supplements/finish') === 'finish', 'routes');

if (getenv('SD_JSON_OUT')) file_put_contents((string) getenv('SD_JSON_OUT'), json_encode($boot, JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_supplements_day_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
