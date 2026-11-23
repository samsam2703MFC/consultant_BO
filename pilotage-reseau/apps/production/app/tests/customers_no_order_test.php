<?php
/**
 * [370] Asystent « Klienci bez zamówienia » : clients réguliers (au moins orders.regular_min_orders jours de commande sur
 * 8 semaines, commandes ordinaires de clients actifs : sans domówienia, gratuits ni archivées), rythme habituel (jours de
 * commande, sinon intervalle moyen), en retard après orders.missing_grace_days jours ouvrés, attendus aujourd'hui (commandé
 * ou encore dans le délai), résultat par client et date attendue (notatka obligatoire pour le contact, pause avec date de
 * fin reprise les jours suivants), étape 3 bloquée tant qu'un client en retard n'a pas de résultat, « Zakończ » →
 * n_wizard_done, état de la tâche, enregistrement (catalogue, droits, routes), migration 218 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_customers_no_order_test recréée (tables minimales + migration 218), supprimée à la fin (CN_JSON_OUT=fichier :
 * données de la page, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\CustomersNoOrderRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\CustomersNoOrderService;
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_customers_no_order_test'); $pdo->exec('CREATE DATABASE erp_customers_no_order_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_customers_no_order_test');
$pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), name VARCHAR(60) NULL, surname VARCHAR(60) NULL, phone_head VARCHAR(30) NULL, phone_alt VARCHAR(30) NULL,
    email_head VARCHAR(80) NULL, active TINYINT NOT NULL DEFAULT 1)');
$pdo->exec("CREATE TABLE customer_order (id INT AUTO_INCREMENT PRIMARY KEY, id_customer INT, status INT DEFAULT 1, expected_shipping_date DATE NULL, create_order_date DATETIME,
    order_type ENUM('STANDARD','SUPPLEMENT') NOT NULL DEFAULT 'STANDARD', is_freebie TINYINT DEFAULT 0)");
$pdo->exec("CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
    done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))");
$pdo->exec("CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
    unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)");
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours')");
$pdo->exec("CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40))");
$pdo->exec("INSERT INTO n_position (id, code) VALUES (1, 'KIEROWNIK_OPERACYJNY'), (2, 'LOGISTYKA')");
$pdo->exec("CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
    rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '1.3', NULL, 'Klienci bez zamówienia', 'daily'), (1, '1.7', NULL, 'Domówienia dnia', 'daily'),
    (1, '1.1', 'orders_day_checked', NULL, 'daily'), (2, '9.9', NULL, NULL, 'none')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/218_customers_no_order.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 218 (deux passages)
$duties = array_column($pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty')->fetchAll(), null, 'number');
$check($duties['1.3']['wizard_code'] === 'customers_ordered_checked' && $duties['1.3']['proposed_wizard'] === null && $duties['1.3']['rhythm'] === 'daily',
    'migration 218: duty 1.3 → customers_ordered_checked (daily)');
$check($duties['1.7']['proposed_wizard'] === 'Domówienia dnia' && $duties['1.1']['wizard_code'] === 'orders_day_checked' && $duties['9.9']['wizard_code'] === null, 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'orders.%'")->fetchAll(), 'value', 'code');
$check($params === ['orders.missing_grace_days' => '1', 'orders.regular_min_orders' => '4', 'orders.weekday_share_pct' => '50']
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customer_no_order'")->fetchColumn() === 1,
    'migration replayed: three params once (4 orders, 50 %, 1 day), n_customer_no_order created once');
$check(isset(ParamRules::RANGES['orders.regular_min_orders'], ParamRules::RANGES['orders.weekday_share_pct'], ParamRules::RANGES['orders.missing_grace_days']), 'params have bounds (ParamRules)');

// --- Données (aujourd'hui mar. 24.11.2026 ; fenêtre 8 semaines : 29.09 – 23.11)
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, name, surname, phone_head, phone_alt, email_head, active) VALUES
    (1, 'Sklep A', 'Jan', 'Nowak', '+48 600 100 200', NULL, 'a@example.test', 1), (2, 'Sklep B', NULL, NULL, NULL, '+48 600 300 400', NULL, 1),
    (3, 'Sklep C', NULL, NULL, NULL, NULL, NULL, 1), (4, 'Sklep D', NULL, NULL, NULL, NULL, NULL, 1), (5, 'Sklep E', NULL, NULL, NULL, NULL, NULL, 0),
    (6, 'Sklep F', NULL, NULL, NULL, NULL, NULL, 1), (7, 'Sklep G', NULL, NULL, NULL, NULL, NULL, 1), (8, 'Sklep H', NULL, NULL, NULL, NULL, NULL, 1)");
$order = function (int $cust, string $at, string $type = 'STANDARD', int $status = 1, int $freebie = 0) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id_customer, status, expected_shipping_date, create_order_date, order_type, is_freebie) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$cust, $status, date('Y-m-d', strtotime($at . ' +2 days')), strlen($at) === 10 ? $at . ' 09:00:00' : $at, $type, $freebie]);
};
$series = fn(string $first, string $last, int $step) => array_map(fn($i) => date('Y-m-d', strtotime($first . ' +' . ($i * $step) . ' days')), range(0, intdiv((int) round((strtotime($last) - strtotime($first)) / 86400), $step)));
$mondays = $series('2026-10-05', '2026-11-16', 7);                       // 7 lundis, sans le 23.11
foreach ([1, 5, 7, 8] as $c) foreach ($mondays as $d) $order($c, $d);
foreach ([2, 3] as $c) { foreach ($series('2026-09-29', '2026-11-17', 7) as $d) $order($c, $d); foreach ($series('2026-10-02', '2026-11-20', 7) as $d) $order($c, $d); } // mardi + vendredi
$order(2, '2026-11-24 08:00:00');                                       // Sklep B : commande de ce matin
foreach ($series('2026-10-01', '2026-11-15', 5) as $d) $order(4, $d);    // Sklep D : tous les 5 jours, jours variés, dernière le 15.11
foreach (['2026-11-02', '2026-11-09', '2026-11-16'] as $d) $order(6, $d); // Sklep F : 3 commandes ordinaires…
$order(6, '2026-11-03', 'SUPPLEMENT'); $order(6, '2026-11-10', 'SUPPLEMENT'); $order(6, '2026-11-11', 'STANDARD', 1, 1); $order(6, '2026-11-12', 'STANDARD', 5); // … le reste ne compte pas
$order(7, '2026-11-23', 'SUPPLEMENT'); $order(7, '2026-11-23 10:00:00', 'STANDARD', 5); $order(7, '2026-11-23 11:00:00', 'STANDARD', 1, 1); // Sklep G : ni domówienie, ni archivée, ni gratis ne couvrent
$order(8, '2026-11-22 20:00:00');                                       // Sklep H : client du lundi, commande dimanche soir

$wizards = new LightWizardRepository($pdo);
$make = fn() => new CustomersNoOrderService(new CustomersNoOrderRepository($pdo), $wizards, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id) { throw new RuntimeException('not needed: ' . $id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);

// --- Fonctions pures
$check(CustomersNoOrderService::occurrence([1], '2026-11-24') === ['on' => '2026-11-23', 'from' => '2026-11-20'], 'occurrence: Monday client, window from the Friday before');
$check(CustomersNoOrderService::occurrence([1, 4], '2026-11-23') === ['on' => '2026-11-23', 'from' => '2026-11-21'], 'occurrence: Mon + Thu client, Monday window from Saturday');
$check(CustomersNoOrderService::workdaysBetween('2026-11-20', '2026-11-24', [1, 2, 3, 4, 5]) === 2 && CustomersNoOrderService::workdaysBetween('2026-11-24', '2026-11-24', [1, 2, 3, 4, 5]) === 0,
    'working days after Fri up to Tue: 2 (weekend not counted)');

// --- Étape 1 : vue d'ensemble
$d = $svc->data($today);
$boot = $d; // aperçu (CN_JSON_OUT) : état avant les résultats
$by = array_column($d['clients'], null, 'customer');
$check(array_column($d['clients'], 'customer') === ['Sklep D', 'Sklep A', 'Sklep G', 'Sklep C', 'Sklep B'], 'listed and sorted: late (most days first), waiting, ordered — ' . implode(', ', array_column($d['clients'], 'customer')));
$check($d['totals']['regular'] === 6 && !isset($by['Sklep E']) && !isset($by['Sklep F']) && !isset($by['Sklep H']),
    'regular: 6 (inactive E, F with 3 ordinary order days and H who ordered on Sunday are not listed)');
$check($by['Sklep A']['status'] === 'late' && $by['Sklep A']['expected_on'] === '2026-11-23' && $by['Sklep A']['days_late'] === 1 && $by['Sklep A']['rhythm']['label'] === 'pon.'
    && $by['Sklep A']['rhythm']['kind'] === 'weekday' && $by['Sklep A']['order_days'] === 7, 'A: Monday client without Monday order → late by 1 working day');
$check($by['Sklep A']['phone'] === '+48 600 100 200' && $by['Sklep A']['contact'] === 'Jan Nowak' && $by['Sklep B']['phone'] === '+48 600 300 400' && $by['Sklep A']['last_order']['day'] === '2026-11-16',
    'contact details (phone, alternative phone, person) and last order');
$check($by['Sklep G']['status'] === 'late', 'G: supplement, archived and free orders do not count as the expected order');
$check($by['Sklep D']['rhythm']['kind'] === 'interval' && $by['Sklep D']['rhythm']['gap'] === 5 && $by['Sklep D']['expected_on'] === '2026-11-20' && $by['Sklep D']['days_late'] === 2,
    'D: no habitual weekday → average interval 5 days, expected Fri 20.11, 2 working days late');
$check($by['Sklep B']['status'] === 'ordered' && $by['Sklep B']['rhythm']['label'] === 'wt., pt.' && $by['Sklep C']['status'] === 'waiting' && $by['Sklep C']['expected_on'] === $today,
    'Tue + Fri clients: B ordered this morning, C still within the grace day');
$check($d['totals']['expected_today'] === 2 && $d['totals']['ordered_today'] === 1 && $d['totals']['waiting'] === 1 && $d['totals']['late'] === 3 && $d['totals']['open'] === 3 && $d['can_finish'] === false,
    'tiles: 2 expected today (1 ordered), 1 waiting, 3 late — step 3 closed');

// Réglages : délai 0 → C en retard le jour même ; part 100 % → A passe à l'intervalle (7 lundis sur 8 semaines) ; minimum 11 → seuls B et C (16 jours).
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'orders.missing_grace_days'");
$c0 = array_column($make()->data($today)['clients'], null, 'customer');
$check($c0['Sklep C']['status'] === 'late' && $c0['Sklep C']['days_late'] === 0, 'grace 0 days: C is late the same day');
$pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'orders.missing_grace_days'");
$pdo->exec("UPDATE n_prod_param SET value = '100' WHERE code = 'orders.weekday_share_pct'");
$c1 = array_column($make()->data($today)['clients'], null, 'customer');
$check($c1['Sklep A']['rhythm']['kind'] === 'interval' && $c1['Sklep A']['rhythm']['gap'] === 7 && $c1['Sklep A']['status'] === 'late', 'share 100 %: A by interval (7 days), still late');
$pdo->exec("UPDATE n_prod_param SET value = '50' WHERE code = 'orders.weekday_share_pct'");
$pdo->exec("UPDATE n_prod_param SET value = '11' WHERE code = 'orders.regular_min_orders'");
$c2 = $make()->data($today);
$check($c2['totals']['regular'] === 2 && array_column($c2['clients'], 'customer') === ['Sklep C', 'Sklep B'], 'min 11 order days: only B and C (16 days) are regular (D has 10)');
$pdo->exec("UPDATE n_prod_param SET value = '4' WHERE code = 'orders.regular_min_orders'");

// --- Étape 2 : résultats
$check(str_contains($err(fn() => $svc->finish(1, $today)), 'custom:422') && str_contains($err(fn() => $svc->finish(1, $today)), '3 klientów'), 'finish refused while late clients have no result');
$check(str_contains($err(fn() => $svc->saveResult(['id_customer' => 1, 'result' => 'contacted', 'note' => ''], 1, $today)), 'notatk'), 'contact without a note: refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_customer' => 1, 'result' => 'maybe'], 1, $today)), 'custom:422'), 'unknown result: refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_customer' => 2, 'result' => 'arrived'], 1, $today)), 'custom:422')
    && str_contains($err(fn() => $svc->saveResult(['id_customer' => 3, 'result' => 'arrived'], 1, $today)), 'custom:422'), 'client not late (ordered, waiting): refused');
$check(str_contains($err(fn() => $svc->saveResult(['id_customer' => 4, 'result' => 'contacted', 'note' => 'Tel.', 'paused_until' => '2026-12-01'], 1, $today)), 'przerwy')
    && str_contains($err(fn() => $svc->saveResult(['id_customer' => 4, 'result' => 'paused', 'paused_until' => '2026-11-20'], 1, $today)), 'Przerwa')
    && str_contains($err(fn() => $svc->saveResult(['id_customer' => 4, 'result' => 'paused', 'paused_until' => '2027-06-01'], 1, $today)), 'Przerwa'), 'pause end date: only for a pause, from today, within 120 days');
$svc->saveResult(['id_customer' => 1, 'result' => 'arrived'], 1, $today);
$svc->saveResult(['id_customer' => 1, 'result' => 'paused', 'note' => 'Urlop właściciela', 'paused_until' => '2026-12-06'], 1, $today); // upsert : le dernier compte
$row = $pdo->query("SELECT * FROM n_customer_no_order WHERE id_customer = 1")->fetchAll();
$check(count($row) === 1 && $row[0]['result'] === 'paused' && $row[0]['expected_on'] === '2026-11-23' && $row[0]['day'] === $today && $row[0]['paused_until'] === '2026-12-06' && $row[0]['rhythm'] === 'pon.',
    'one row per client and expected day (upsert), with the pause end and the rhythm');
$st = $tasks->state(CustomersNoOrderService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '1 klient wyjaśniony', 'task in progress: step 2, 1 client clarified');
$svc->saveResult(['id_customer' => 4, 'result' => 'contacted', 'note' => 'Tel. z p. Kowalską : zamówi jutro'], 2, $today);
$d = $svc->data($today);
$check($d['totals']['open'] === 1 && $d['can_finish'] === false && str_contains($err(fn() => $svc->finish(1, $today)), 'dla 1 klienta'), 'one left: step 3 still blocked');
$svc->saveResult(['id_customer' => 7, 'result' => 'arrived', 'note' => 'Zamówienie telefonem, wprowadzane'], 1, $today);
$d = $svc->data($today);
$by = array_column($d['clients'], null, 'customer');
$check($d['can_finish'] === true && $d['summary'] === '6 stałych klientów · 3 bez zamówienia · 1 kontakt · 1 zamówienie przyszło · 1 przerwa', 'all clarified: step 3 open — ' . $d['summary']);
$check($by['Sklep D']['check']['by'] === 'piotr' && $by['Sklep A']['check']['paused_label'] === 'niedz. 6.12' && $by['Sklep A']['check']['inherited'] === false, 'result shown with author and pause end');

// --- Étape 3 : Zakończ
$r = $svc->finish(1, $today);
$done = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'customers_ordered_checked'")->fetch();
$check($done && $done['day'] === $today && $done['summary'] === $r['summary'] && (int) $done['done_by'] === 1 && $r['data']['done'] !== null, 'finish writes n_wizard_done (code, day, summary, user)');
$st = $tasks->state(CustomersNoOrderService::CODE, $today . ' 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'position task done with the summary');

// --- Jours suivants : le résultat suit la date attendue, la pause couvre les dates suivantes jusqu'à sa fin
$w = array_column($svc->data('2026-11-25')['clients'], null, 'customer');
$check($w['Sklep A']['expected_on'] === '2026-11-23' && $w['Sklep A']['check']['result'] === 'paused' && $w['Sklep D']['check']['result'] === 'contacted' && $w['Sklep C']['status'] === 'late'
    && $w['Sklep C']['check'] === null, 'Wed 25.11: A and D keep their result for the same expected day ; C (Tue) now late without result');
$n = array_column($svc->data('2026-12-01')['clients'], null, 'customer');
$check($n['Sklep A']['expected_on'] === '2026-11-30' && $n['Sklep A']['check']['inherited'] === true && $n['Sklep A']['check']['result'] === 'paused'
    && $n['Sklep G']['expected_on'] === '2026-11-30' && $n['Sklep G']['check'] === null, 'Tue 1.12: A covered by the pause until 6.12 ; G has a new expected day without result');
$n = array_column($svc->data('2026-12-08')['clients'], null, 'customer');
$check(($n['Sklep A']['check'] ?? null) === null, 'Tue 8.12: the pause is over, A must be clarified again');

// --- Enregistrement : catalogue, lien, droits, routes
$check(TaskWizards::info('customers_ordered_checked')['link'] === '/client-orders/missing' && TaskWizards::byLink('/client-orders/missing') === 'customers_ordered_checked'
    && isset(TaskWizards::AUTO['customers_ordered_checked']) && PositionTaskService::LINK_AUTO['/client-orders/missing'] === 'customers_ordered_checked', 'catalog, auto description and position-task link');
$check(PageAccess::keyFor('/client-orders/missing') === 'orders' && PageAccess::keyFor('/ajax/client-orders/missing/result') === 'orders' && PageAccess::keyFor('/ajax/client-orders/missing') === 'orders',
    'page and ajax under the « orders » right');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Client/OrderRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/client-orders/missing') === 'index' && $found('GET', '/ajax/client-orders/missing') === 'data'
    && $found('POST', '/ajax/client-orders/missing/result') === 'result' && $found('POST', '/ajax/client-orders/missing/finish') === 'finish', 'routes');

if (getenv('CN_JSON_OUT')) file_put_contents((string) getenv('CN_JSON_OUT'), json_encode($boot, JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_customers_no_order_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
