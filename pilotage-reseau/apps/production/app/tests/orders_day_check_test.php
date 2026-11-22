<?php
/**
 * [369] Asystent « Zamówienia dnia » : fenêtre depuis la validation précédente (sinon jour ouvré précédent), nouvelles
 * et modifiées (historique hors système et hors champs techniques), signaux (date passée, absente, délai trop court,
 * quantité inhabituelle — pas de signal avec moins de 3 commandes —, produit inactif), étape 3 bloquée tant qu'une
 * commande signalée n'a pas de résultat, « Zakończ » → n_wizard_done, état de la tâche, migration 217 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_orders_day_test recréée (tables minimales + migration 217), supprimée à la fin (OD_JSON_OUT=fichier : données
 * de la page, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderDayCheckRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\OrdersDayCheckService;
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_orders_day_test'); $pdo->exec('CREATE DATABASE erp_orders_day_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_orders_day_test');
$pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80))');
$pdo->exec('CREATE TABLE final_product (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT DEFAULT 1)');
$pdo->exec("CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, order_description TEXT NULL, status INT DEFAULT 1,
    expected_shipping_date DATE NULL, create_order_date DATETIME, order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD', is_freebie TINYINT DEFAULT 0,
    id_wdt INT NULL, id_proforma INT NULL)");
$pdo->exec('CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2), expected_quantity_carton DECIMAL(10,2) NULL)');
$pdo->exec("CREATE TABLE customer_order_changelog (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT NOT NULL, id_user INT NULL, action_type ENUM('create','update','delete') NOT NULL,
    source ENUM('web','api','collector','system') NOT NULL DEFAULT 'web', changed_fields JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
    done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))");
$pdo->exec("CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
    unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)");
$pdo->exec("CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40))");
$pdo->exec("INSERT INTO n_position (id, code) VALUES (1, 'KIEROWNIK_OPERACYJNY'), (2, 'LOGISTYKA')");
$pdo->exec("CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
    rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard) VALUES (1, '1.1', NULL, 'Zamówienia dnia'), (1, '1.7', NULL, 'Domówienia dnia'), (1, '1.4', 'tomorrow_sent', NULL),
    (1, '1.2', NULL, NULL), (1, '1.8', NULL, NULL), (2, '9.9', NULL, NULL)");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/217_orders_day_check.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 217 (deux passages)
$duties = array_column($pdo->query('SELECT number, wizard_code, proposed_wizard FROM n_position_duty')->fetchAll(), null, 'number');
$check($duties['1.2']['wizard_code'] === 'orders_day_checked' && $duties['1.8']['wizard_code'] === 'orders_day_checked' && $duties['9.9']['wizard_code'] === null,
    'migration 217 : 1.2 et 1.8 du Kierownik operacyjny rattachées à l\'assistant, autre poste intact');
$check($duties['1.1']['wizard_code'] === 'orders_day_checked' && $duties['1.1']['proposed_wizard'] === null && $duties['1.7']['proposed_wizard'] === 'Domówienia dnia'
    && $duties['1.4']['wizard_code'] === 'tomorrow_sent', 'migration: 1.1 → orders_day_checked, other duties untouched');
$check((int) $pdo->query("SELECT COUNT(*) FROM n_prod_param WHERE code = 'orders.min_lead_days' AND value = '1'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_order_check'")->fetchColumn() === 1,
    'migration replayed: one lead-time param (1 day), n_order_check created once');

// --- Données : Sklep A (3 commandes de 10 kart. en octobre-novembre), Sklep B (2 commandes), Sklep C (aucune).
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer VALUES (1, 'Sklep A'), (2, 'Sklep B'), (3, 'Sklep C')");
$pdo->exec("INSERT INTO final_product VALUES (10, 'Croissant', 1), (11, 'Bagietka', 1), (12, 'Tarta jabłkowa', 0)");
$order = function (int $id, int $cust, string $created, ?string $ship, array $lines, string $type = 'STANDARD', int $status = 1, ?int $wdt = null, ?string $ref = null) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, reference, status, expected_shipping_date, create_order_date, order_type, id_wdt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $cust, $ref, $status, $ship, $created, $type, $wdt]);
    foreach ($lines as $p => $q) $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton) VALUES (?, ?, ?)')->execute([$id, $p, $q]);
};
$order(70, 1, '2026-10-10 09:00:00', '2026-10-13', [10 => 10]);
$order(71, 1, '2026-10-20 09:00:00', '2026-10-23', [10 => 10]);
$order(72, 1, '2026-11-05 09:00:00', '2026-11-07', [10 => 6, 11 => 4]);
$order(80, 2, '2026-11-01 09:00:00', '2026-11-04', [10 => 10]);
$order(81, 2, '2026-11-05 09:00:00', '2026-11-07', [10 => 10]);
// Dans la fenêtre (depuis lun. 23.11, 0:00) :
$order(101, 1, '2026-11-23 09:00:00', '2026-11-27', [10 => 20, 11 => 10], 'STANDARD', 1, null, 'PO-4411'); // 30 kart. > 2 × 10 → quantité haute
$order(102, 2, '2026-11-23 10:00:00', '2026-11-27', [10 => 30]);           // 30 kart. mais 2 commandes seulement → pas de signal
$order(103, 1, '2026-11-23 11:00:00', null, [10 => 10]);                   // pas de date d'expédition
$order(104, 3, '2026-11-23 16:00:00', '2026-11-23', [11 => 5]);            // date passée (aujourd'hui mar. 24.11)
$order(105, 3, '2026-11-24 08:00:00', '2026-11-24', [10 => 2], 'SUPPLEMENT'); // domówienie pour le jour même : délai 0 < 1
$order(106, 2, '2026-11-23 12:00:00', '2026-11-28', [10 => 5, 12 => 5]);   // produit inactif
$order(107, 1, '2026-11-23 13:00:00', '2026-11-28', [11 => 2]);            // 2 kart. < 0,3 × 14 → quantité basse
$order(94, 1, '2026-11-23 09:30:00', '2026-11-20', [10 => 3], 'STANDARD', 5);   // archivée : absente
$order(95, 3, '2026-11-23 09:40:00', '2026-11-20', [10 => 4], 'STANDARD', 1, 77); // déjà avec WDT : listée, sans signal
// Anciennes commandes (Sklep C) : 90 modifiée (date et quantité), 91 proforma seulement, 92 système, 93 modifiée avant la fenêtre.
$order(90, 3, '2026-11-10 09:00:00', '2026-11-26', [10 => 12]);
$order(91, 3, '2026-11-12 09:00:00', '2026-11-30', [10 => 10]);
$order(92, 3, '2026-11-12 09:00:00', '2026-11-30', [10 => 10]);
$order(93, 3, '2026-11-12 09:00:00', '2026-11-30', [10 => 10]);
$log = fn(int $id, string $source, array $fields, string $at, ?int $user = 1) => $pdo->prepare('INSERT INTO customer_order_changelog (id_order, id_user, action_type, source, changed_fields, created_at) VALUES (?, ?, \'update\', ?, ?, ?)')
    ->execute([$id, $user, $source, json_encode($fields), $at]);
$log(90, 'web', ['before' => ['expected_shipping_date' => '2026-11-25', 'order_description' => '', 'products' => [['id_product' => 10, 'quantity_carton' => 10]]],
    'after' => ['expected_shipping_date' => '2026-11-26', 'order_description' => '', 'products' => [['id_product' => 10, 'quantity_carton' => 12]]]], '2026-11-23 14:00:00');
$log(91, 'web', ['after' => ['id_proforma' => 5]], '2026-11-23 14:10:00');
$log(92, 'system', ['after' => ['supplement_push_status' => 'sent']], '2026-11-23 14:20:00', null);
$log(93, 'web', ['after' => ['expected_shipping_date' => '2026-11-30']], '2026-11-20 10:00:00');

$wizards = new LightWizardRepository($pdo);
$svc = new OrdersDayCheckService(new OrderDayCheckRepository($pdo), $wizards, new ParamRepository($pdo));
$today = '2026-11-24';
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id) { throw new RuntimeException('not needed: ' . $id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);

// --- Fenêtre : pas encore de validation → jour ouvré précédent
$check($svc->previousWorkday('2026-11-24') === '2026-11-23' && $svc->previousWorkday('2026-11-23') === '2026-11-20', 'previous working day: Tue → Mon, Mon → Fri');
$d = $svc->data($today);
$by = array_column($d['orders'], null, 'id');
$boot = $d; // aperçu (OD_JSON_OUT) : état avant les résultats
$flags = fn(int $id) => array_column($by[$id]['flags'] ?? [], 'code');
$check($d['since']['kind'] === 'workday' && $d['since']['at'] === '2026-11-23 00:00:00', 'no validation yet: window from Mon 23.11, 0:00');
$ks = array_keys($by); sort($ks);
$check($ks === [90, 95, 101, 102, 103, 104, 105, 106, 107] && array_key_first($by) === 103, 'listed: 7 new + WDT order + changed 90 (sorted: missing date first) — ' . implode(',', array_keys($by)));
$check(!isset($by[94]) && !isset($by[91]) && !isset($by[92]) && !isset($by[93]), 'not listed: archived, proforma-only change, system change, change before the window');
$check($d['totals']['new'] === 8 && $d['totals']['changed'] === 1 && $by[90]['kind'] === 'changed' && $by[101]['kind'] === 'new', 'new vs changed');
$check($by[90]['changes'] === ['data wysyłki śr. 25.11 → czw. 26.11', 'Croissant 10 → 12 kart.'] && $by[90]['changed_by'] === 'Anna K.', 'what changed: shipping date and quantity, by whom');
$check($by[101]['lines'] === 2 && $by[101]['cartons'] == 30 && $by[101]['reference'] === 'PO-4411' && $by[105]['type'] === 'SUPPLEMENT', 'lines, cartons, reference, supplement type');
$check($d['totals']['cartons'] == 30 + 30 + 10 + 5 + 2 + 10 + 2 + 4 + 12, 'tile: cartons of the listed orders');
$check($flags(103) === ['date_missing'], 'flag: missing shipping date');
$check($flags(104) === ['date_past'] && str_contains($by[104]['flags'][0]['reason'], 'pon. 23.11'), 'flag: shipping date in the past');
$check($flags(105) === ['date_short'] && str_contains($by[105]['flags'][0]['reason'], 'minimum 1'), 'flag: shipping sooner than the lead time (0 days < 1)');
$check($flags(101) === ['qty_high'] && $by[101]['average']['orders'] === 3, 'flag: quantity > 2× the customer average (3 orders)');
$check($flags(107) === ['qty_low'], 'flag: quantity < 0.3× the customer average');
$check($flags(102) === [] && $by[102]['average']['orders'] === 2, 'unusual quantity with < 3 previous orders: no flag');
$check($flags(106) === ['product_inactive'] && str_contains($by[106]['flags'][0]['reason'], 'Tarta jabłkowa'), 'flag: inactive product line');
$check($flags(95) === [] && $by[95]['open'] === false && $flags(90) === [], 'order already with WDT: no flag ; changed 90 (3 days lead): no flag');
$check($d['totals']['flagged'] === 6 && $d['totals']['waiting'] === 6 && $d['can_finish'] === false, '6 orders to clarify, step 3 closed');
// Délai à 0 : plus de signal « trop court » (la date passée reste).
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'orders.min_lead_days'");
$d0 = (new OrdersDayCheckService(new OrderDayCheckRepository($pdo), $wizards, new ParamRepository($pdo)))->data($today);
$check(array_column(array_column($d0['orders'], null, 'id')[105]['flags'], 'code') === [], 'lead time 0 in the settings: no short-lead flag');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'orders.min_lead_days'");

// --- Étape 2 : résultats
$check(str_contains($err(fn() => $svc->finish(1, $today)), 'custom:422'), 'finish refused while flagged orders have no result');
$check(str_contains($err(fn() => $svc->saveResult(['id_order' => 104, 'result' => 'contact', 'note' => ''], 1, $today)), 'notatk'), 'contact without a note: refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_order' => 104, 'result' => 'maybe'], 1, $today)), 'custom:422'), 'unknown result: refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_order' => 70, 'result' => 'ok'], 1, $today)), 'custom:422'), 'order outside the day list: refused');
$svc->saveResult(['id_order' => 104, 'result' => 'ok'], 1, $today);
$svc->saveResult(['id_order' => 104, 'result' => 'contact', 'note' => 'Tel. z p. Nowak : wysyłka jutro'], 1, $today); // upsert : le dernier compte
$check((int) $pdo->query("SELECT COUNT(*) FROM n_order_check WHERE id_order = 104")->fetchColumn() === 1
    && $pdo->query("SELECT result FROM n_order_check WHERE id_order = 104")->fetchColumn() === 'contact', 'one row per order per day (upsert)');
$st = $tasks->state(OrdersDayCheckService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 zamówienie wyjaśnione', 'task in progress: step 2, 1 order clarified');
foreach ([101 => 'ok', 103 => 'changed', 105 => 'ok', 106 => 'changed'] as $id => $res) $svc->saveResult(['id_order' => $id, 'result' => $res, 'note' => $res === 'changed' ? 'Poprawione w zamówieniu' : ''], 2, $today);
$d = $svc->data($today);
$check($d['totals']['waiting'] === 1 && $d['can_finish'] === false && str_contains($err(fn() => $svc->finish(1, $today)), 'Najpierw wyjaśnij 1 zamówienie'), 'one left: step 3 still blocked');
$svc->saveResult(['id_order' => 107, 'result' => 'ok'], 1, $today);
$d = $svc->data($today);
$check($d['can_finish'] === true && $d['summary'] === '9 zamówień · 6 wyjaśnionych · 1 kontakt · 2 zmienione', 'all clarified: step 3 open — ' . $d['summary']);

// --- Étape 3 : Zakończ
$r = $svc->finish(1, $today);
$done = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'orders_day_checked'")->fetch();
$check($done && $done['day'] === $today && $done['summary'] === $r['summary'] && (int) $done['done_by'] === 1 && $r['data']['done'] !== null, 'finish writes n_wizard_done (code, day, summary, user)');
$st = $tasks->state(OrdersDayCheckService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'position task done with the summary');

// --- Fenêtre depuis la validation précédente
$pdo->exec("INSERT INTO n_wizard_done (code, day, summary, done_by, done_at) VALUES ('orders_day_checked', '2026-11-23', 'x', 1, '2026-11-23 12:30:00')");
$d = $svc->data($today);
$ids = array_column($d['orders'], 'id');
sort($ids);
$check($d['since']['kind'] === 'done' && $d['since']['at'] === '2026-11-23 12:30:00' && $ids === [90, 104, 105, 107], 'window from the previous validation (23.11 12:30): only orders after it — ' . implode(',', $ids));
$check($d['done'] !== null && $d['since']['label'] === 'od zatwierdzenia pon. 23.11, 12:30', 'already done today: shown ; window label');

// --- Enregistrement : catalogue, lien, droits, routes
$check(TaskWizards::info('orders_day_checked')['link'] === '/client-orders/day-check' && TaskWizards::byLink('/client-orders/day-check') === 'orders_day_checked'
    && isset(TaskWizards::AUTO['orders_day_checked']) && PositionTaskService::LINK_AUTO['/client-orders/day-check'] === 'orders_day_checked', 'catalog, auto description and position-task link');
$check(PageAccess::keyFor('/client-orders/day-check') === 'orders' && PageAccess::keyFor('/ajax/client-orders/day-check/result') === 'orders', 'page and ajax under the « orders » right');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Client/OrderRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/client-orders/day-check') === 'index' && $found('GET', '/ajax/client-orders/day-check') === 'data'
    && $found('POST', '/ajax/client-orders/day-check/result') === 'result' && $found('POST', '/ajax/client-orders/day-check/finish') === 'finish', 'routes');

if (getenv('OD_JSON_OUT')) file_put_contents((string) getenv('OD_JSON_OUT'), json_encode($boot, JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_orders_day_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
