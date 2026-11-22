<?php
/**
 * [373] Asystent « Płynność i gotówka » : 4 semaines calendaires (semaine en cours depuis aujourd'hui + 3) ; entrées
 * (proformas non payées à échéance date + délai du client ou délai par défaut, commandes ouvertes sans proforma à
 * l'expédition + délai), créances échues à part ; sorties (factures d'achat hors rejetées à émission + délai,
 * commandes fournisseurs sans facture à livraison + délai, liste de paie : net, ZUS, PIT, estimation depuis le dernier
 * mois approuvé, autres dépenses saisies) ; conversion € au cours des réglages ; solde saisi et prévision ; signaux
 * (semaine sous le coussin, client en retard ≥ seuil ou qui continue de commander, gros paiement fournisseur sous
 * 14 jours) ; montants retirés hors profil Finanse / CEO ; décision par signal (note obligatoire pour « Działanie »),
 * « Zakończ » → n_wizard_done, état de la tâche hebdomadaire, enregistrement (catalogue, droits, routes, paramètres),
 * migration 220 rejouable. Données fictives.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_liquidity_test recréée (tables minimales + migration 220), supprimée à la fin (LQ_JSON_OUT=fichier :
 * données de la page avant et après le solde, pour un aperçu). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\PageAccess;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Finances\LiquidityCheckRepository;
use App\Repositories\Organization\{LightWizardRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Organization\{PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\LiquidityCheckService;
use App\Services\Workshop\Migration\MigrationRunner;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_liquidity_test'); $pdo->exec('CREATE DATABASE erp_liquidity_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_liquidity_test');
$pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), payment_termin INT NULL, default_currency VARCHAR(3) NULL)');
$pdo->exec('CREATE TABLE document_proforma (id INT PRIMARY KEY, doc_num VARCHAR(40), established_date DATE NULL, id_customer INT, status VARCHAR(20) NULL)');
$pdo->exec('CREATE TABLE document_proforma_product (id INT AUTO_INCREMENT PRIMARY KEY, id_proforma INT, total_price_net DECIMAL(12,2))');
$pdo->exec('CREATE TABLE communication_message (id INT AUTO_INCREMENT PRIMARY KEY, id_proforma INT NULL, message_type VARCHAR(60), status VARCHAR(20), created_at DATETIME)');
$pdo->exec("CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, status INT DEFAULT 1, expected_shipping_date DATE NULL, create_order_date DATETIME NULL,
    is_freebie TINYINT DEFAULT 0, id_wdt INT NULL, id_proforma INT NULL)");
$pdo->exec('CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, id_product INT, quantity_carton DECIMAL(10,2), purchase_price DECIMAL(10,2))');
$pdo->exec('CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80))');
$pdo->exec("CREATE TABLE n_inv (id INT PRIMARY KEY, source VARCHAR(10) DEFAULT 'ksef', kind ENUM('goods','service') NOT NULL DEFAULT 'goods', id_supplier INT NULL, seller_nip VARCHAR(20) NULL,
    seller_name VARCHAR(255) NULL, number VARCHAR(80) NULL, issue_date DATE NULL, currency CHAR(3) NOT NULL DEFAULT 'PLN', total_net DECIMAL(14,2) NULL, total_gross DECIMAL(14,2) NULL,
    id_po INT NULL, status ENUM('open','accepted','disputed','rejected') NOT NULL DEFAULT 'open')");
$pdo->exec("CREATE TABLE n_po (id INT PRIMARY KEY, code VARCHAR(20), id_supplier INT, status ENUM('draft','sent','partial','received','cancelled') NOT NULL DEFAULT 'draft', delivery_date DATE NULL)");
$pdo->exec('CREATE TABLE n_po_line (id INT AUTO_INCREMENT PRIMARY KEY, id_po INT, id_raw_material INT, quantity DECIMAL(14,3), unit_price DECIMAL(14,6) NULL)');
$pdo->exec("CREATE TABLE n_pay_run (month CHAR(7) PRIMARY KEY, status ENUM('draft','approved') NOT NULL DEFAULT 'draft', approved_by INT NULL, approved_at DATETIME NULL, totals TEXT NULL,
    xml_at DATETIME NULL, xml_by INT NULL, exec_date DATE NULL)");
$pdo->exec('CREATE TABLE erp_company_other_settings (id INT PRIMARY KEY, exchange_rate_euro DECIMAL(10,4))');
$pdo->exec("CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
    done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))");
$pdo->exec("CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
    unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)");
$pdo->exec("CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
    rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')");
$pdo->exec("INSERT INTO n_position_duty (number, wizard_code, proposed_wizard, rhythm) VALUES ('2.1', NULL, 'Płynność i gotówka', 'weekly'), ('2.2', NULL, 'Płynność i gotówka', 'weekly'),
    ('2.8', NULL, 'Płynność i gotówka', 'weekly'), ('2.3', 'payments_reviewed', NULL, 'daily'), ('1.5', NULL, 'Inny asystent', 'weekly')");
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/220_liquidity_check.sql')) as $sql) $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };
$near = fn($a, $b) => $a !== null && abs((float) $a - (float) $b) < 0.01;

// --- Migracja 220 (deux passages)
$duties = array_column($pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty')->fetchAll(), null, 'number');
$check(array_column(array_intersect_key($duties, array_flip(['2.1', '2.2', '2.8'])), 'wizard_code') === ['liquidity_checked', 'liquidity_checked', 'liquidity_checked']
    && $duties['2.1']['proposed_wizard'] === null && $duties['2.8']['rhythm'] === 'weekly', 'migration 220: duties 2.1, 2.2, 2.8 → liquidity_checked (weekly)');
$check($duties['2.3']['wizard_code'] === 'payments_reviewed' && $duties['1.5']['proposed_wizard'] === 'Inny asystent', 'migration: other duties untouched');
$params = array_column($pdo->query("SELECT code, value, value_type FROM n_prod_param WHERE code LIKE 'liquidity.%'")->fetchAll(), null, 'code');
$check(count($params) === 5 && $params['liquidity.overdue_min']['value'] === '5000' && $params['liquidity.supplier_terms_days']['value_type'] === 'int'
    && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_liquidity_balance', 'n_liquidity_decision')")->fetchColumn() === 2,
    'migration replayed: five liquidity.* params once, two tables');
$check(!array_diff(array_keys($params), array_keys(ParamRules::RANGES)), 'every liquidity.* param is bounded in ParamRules');

// --- Données fictives : aujourd'hui mar. 24.11.2026 ; semaines 24.11–29.11, 30.11–6.12, 7.12–13.12, 14.12–20.12 ; 1 € = 4,25 zł.
$pdo->exec("INSERT INTO user VALUES (1, 'anna', 'Anna K.'), (2, 'piotr', NULL)");
$pdo->exec('INSERT INTO erp_company_other_settings VALUES (1, 4.25)');
$pdo->exec("INSERT INTO customer VALUES (1, 'Sklep A', 14, 'EUR'), (2, 'Sklep B', NULL, 'EUR'), (3, 'Sklep C', 30, 'PLN')");
$pf = function (int $id, int $cust, string $date, float $amount, ?string $status = 'issued') use ($pdo) {
    $pdo->prepare('INSERT INTO document_proforma VALUES (?, ?, ?, ?, ?)')->execute([$id, 'PF/' . $id . '/2026', $date, $cust, $status]);
    $pdo->prepare('INSERT INTO document_proforma_product (id_proforma, total_price_net) VALUES (?, ?), (?, ?)')->execute([$id, $amount / 2, $id, $amount / 2]);
};
$pf(1, 1, '2026-11-01', 2000);            // échue 15.11 : 8 500 zł (≥ 5 000)
$pf(2, 1, '2026-11-20', 1000);            // 4.12 → semaine 2 : 4 250 zł
$pf(3, 2, '2026-11-12', 500);             // délai par défaut 14 → 26.11 → semaine 1 : 2 125 zł
$pf(4, 2, '2026-11-05', 300);             // échue 19.11 : 1 275 zł (< seuil)
$pf(5, 3, '2026-11-20', 10000);           // PLN, délai 30 → 20.12 → semaine 4 : 10 000 zł
$pf(6, 3, '2026-11-10', 9999, 'paid');    // payée : absente
$pf(7, 3, '2026-11-10', 9999, 'cancelled');
$pf(8, 3, '2026-11-23', 700);             // 23.12 : après l'horizon
$pdo->exec("INSERT INTO communication_message (id_proforma, message_type, status, created_at) VALUES (1, 'proforma_reminder_1', 'sent', '2026-11-04 09:00:00'),
    (1, 'proforma_reminder_2', 'sent', '2026-11-10 09:00:00'), (1, 'proforma_reminder_3', 'failed', '2026-11-20 09:00:00')");
$order = function (int $id, int $cust, ?string $ship, array $lines, array $o = []) use ($pdo) {
    $pdo->prepare('INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, is_freebie, id_wdt, id_proforma) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $cust, $o['status'] ?? 1, $ship, $o['freebie'] ?? 0, $o['wdt'] ?? null, $o['proforma'] ?? null]);
    foreach ($lines as [$q, $p]) $pdo->prepare('INSERT INTO customer_order_product (id_order, id_product, quantity_carton, purchase_price) VALUES (?, 1, ?, ?)')->execute([$id, $q, $p]);
};
$order(101, 2, '2026-11-27', [[10, 20]]);                 // sans proforma : 200 € → 11.12 → semaine 3 : 850 zł ; Sklep B continue de commander
$order(102, 1, '2026-11-25', [[5, 30]], ['proforma' => 2]); // proforma non payée : déjà comptée ; Sklep A continue de commander
$order(103, 3, '2026-11-26', [[4, 10]], ['freebie' => 1]); // gratis : absent
$order(104, 3, '2026-12-18', [[1, 100]]);                // 18.12 + 30 : après l'horizon
$order(105, 1, '2026-11-20', [[3, 10]], ['wdt' => 9]);     // expédiée : absente
$order(106, 3, '2026-11-30', [[2, 50]], ['proforma' => 7]); // proforma annulée = sans proforma : 100 zł → 30.12, après l'horizon
$pdo->exec("INSERT INTO supplier VALUES (1, 'Dostawca X'), (2, 'Dostawca Y')");
$pdo->exec("INSERT INTO n_inv (id, kind, id_supplier, seller_nip, seller_name, number, issue_date, currency, total_net, total_gross, id_po, status) VALUES
    (1, 'goods', 1, '1110001111', 'X sp.', 'FV/1', '2026-11-15', 'PLN', 7317.07, 9000, NULL, 'accepted'),
    (2, 'goods', 1, '1110001111', 'X sp.', 'FV/2', '2026-11-20', 'PLN', 3252.03, 4000, 202, 'open'),
    (3, 'goods', 2, '2220002222', 'Y sp.', 'FV/3', '2026-11-01', 'PLN', 800, 1000, NULL, 'accepted'),
    (4, 'goods', 2, '2220002222', 'Y sp.', 'FV/4', '2026-11-24', 'EUR', 1626.02, 2000, NULL, 'open'),
    (5, 'goods', 1, '1110001111', 'X sp.', 'FV/5', '2026-11-20', 'PLN', 40000, 50000, NULL, 'rejected'),
    (6, 'service', NULL, '3330003333', 'Energia Sp.', 'E/6', '2026-11-18', 'PLN', NULL, 1500, NULL, 'open')");
$pdo->exec("INSERT INTO n_po VALUES (201, 'PO-0201', 2, 'sent', '2026-11-26'), (202, 'PO-0202', 1, 'received', '2026-11-20'), (203, 'PO-0203', 2, 'draft', '2026-11-26')");
$pdo->exec('INSERT INTO n_po_line (id_po, id_raw_material, quantity, unit_price) VALUES (201, 1, 100, 30), (202, 1, 10, 300), (203, 1, 10, 999)');
$pdo->exec("INSERT INTO n_pay_run (month, status, totals, exec_date) VALUES ('2026-10', 'approved', '{\"net\":30000,\"zus_all\":15000,\"pit\":3000}', '2026-11-09')");

$wizards = new LightWizardRepository($pdo);
$make = fn() => new LiquidityCheckService(new LiquidityCheckRepository($pdo), $wizards, new ParamRepository($pdo));
$svc = $make();
$today = '2026-11-24';
$container = new class implements Psr\Container\ContainerInterface {
    public function get(string $id) { throw new RuntimeException('not needed: ' . $id); }
    public function has(string $id): bool { return false; }
};
$tasks = new TaskWizards(new TaskWizardRepository($pdo), $container);
$sum = fn() => json_encode($pdo->query('CHECKSUM TABLE customer, document_proforma, document_proforma_product, communication_message, customer_order, customer_order_product, n_inv, n_po, n_po_line, n_pay_run')->fetchAll());
$before = $sum();

// --- Fonctions pures
$w = LiquidityCheckService::weeks($today);
$check(array_column($w, 'from') === ['2026-11-24', '2026-11-30', '2026-12-07', '2026-12-14'] && $w[3]['to'] === '2026-12-20' && $w[0]['monday'] === '2026-11-23'
    && LiquidityCheckService::monday('2026-11-29') === '2026-11-23', 'weeks: current week from today, then 3 Mon–Sun weeks');
$check(LiquidityCheckService::weekIndex('2026-11-23', $w) === null && LiquidityCheckService::weekIndex('2026-12-07', $w) === 2 && LiquidityCheckService::weekIndex('2026-12-21', $w) === null, 'week index');
$check(LiquidityCheckService::summary(['risk_weeks' => 2, 'signals' => 5, 'action' => 2, 'monitor' => 1, 'no_risk' => 2], true) === '4 tyg. · 2 tygodnie z ryzykiem · 5 sygnałów : 2 działania · 1 monitorować · 2 bez ryzyka',
    'summary without amounts');

// --- Étape 1 : situation sans solde
$d = $svc->data($today);
$boot = $d;
$wk = $d['weeks'];
$check($near($wk[0]['in_receivables'], 2125) && $near($wk[1]['in_receivables'], 4250) && $near($wk[3]['in_receivables'], 10000) && $near($wk[2]['in_orders'], 850),
    'inflows: proforma due = date + client terms (default 14), EUR × 4,25 ; order without proforma = shipping + terms');
$check($near($wk[0]['out_suppliers'], 9000) && $near($wk[1]['out_suppliers'], 5500) && $near($wk[2]['out_suppliers'], 8500 + 3000) && $near($wk[3]['out_suppliers'], 0),
    'outflows: invoices (gross, EUR converted, service included, rejected and past-due excluded), PO without invoice (PO with invoice and draft excluded)');
$check($near($wk[2]['out_payroll'], 30000) && $near($wk[3]['out_payroll'], 18000) && $near($wk[0]['out_payroll'], 0) && $d['payroll']['estimate'] === ['listopad 2026'] && $d['payroll']['missing'] === [],
    'payroll: November estimated from October — net 10.12, ZUS 15.12 + PIT 20.12 ; October already paid');
$check($near($d['totals']['in_4w'], 17225) && $near($d['totals']['out_4w'], 74000) && $d['totals']['negative_weeks'] === 4 && $d['totals']['opening'] === null && $wk[0]['end'] === null,
    'totals: in 17 225, out 74 000, no balance yet');
$cl = array_column($d['clients'], null, 'id');
$check($near($cl[1]['overdue'], 8500) && $cl[1]['max_days_over'] === 9 && $cl[1]['reminder']['level'] === 2 && $near($cl[2]['overdue'], 1275) && $near($d['totals']['overdue_total'], 9775)
    && $d['totals']['overdue_clients'] === 2 && $near($cl[3]['later'], 700) && !isset($cl[4]), 'receivables per client: overdue (not counted as inflow), last reminder (failed ignored), later');
$check($cl[2]['open_orders'] === 1 && $cl[1]['open_orders'] === 1 && $cl[3]['open_orders'] === 2, 'open orders per client (freebie and shipped excluded)');
$sig = array_column($d['signals'], null, 'key');
$check(array_keys($sig) === ['client:1', 'client:2', 'supplier:s1'] && $sig['client:1']['codes'] === ['overdue', 'ordering'] && $sig['client:2']['codes'] === ['ordering'],
    'signals before balance: client A (overdue + ordering), client B (ordering), supplier X — no week signal yet');
$check($near($sig['supplier:s1']['amount'], 13000) && str_contains($sig['supplier:s1']['reasons'][1], 'FV/1') && !isset($sig['supplier:s2']), 'supplier X: 13 000 zł within 14 days ; Y below threshold or later');
$check(str_contains($sig['client:1']['reasons'][0], '9 dni po terminie') && str_contains($sig['client:1']['reasons'][0], 'poziom 2') && str_contains($sig['client:1']['reasons'][0], "8\u{00A0}500\u{00A0}zł"),
    'client reason: days overdue, reminder level, amount');
$check($d['totals']['signals'] === 3 && $d['can_finish'] === false && $d['balance']['saved'] === false, 'step 3 closed without balance');

// Montants masqués (profil sans Finanse / CEO) : rien qui ressemble à un montant.
$m = $svc->data($today, false);
$json = json_encode($m, JSON_UNESCAPED_UNICODE);
$check($m['totals']['in_4w'] === null && $m['weeks'][2]['out'] === null && $m['signals'][0]['amount'] === null && $m['clients'][0]['overdue'] === null && $m['outflows'][0]['amount'] === null
    && !str_contains($json, 'zł"') && !str_contains($json, "\u{00A0}zł") && count($m['signals']) === 3 && $m['values'] === false, 'amounts hidden without values: nulls, no « zł » in texts, same signals');

// Réglages : seuil relevé → plus de signal « overdue » pour A ; valeur hors bornes → défaut.
$pdo->exec("UPDATE n_prod_param SET value = '9000' WHERE code = 'liquidity.overdue_min'");
$check(array_column($make()->data($today)['signals'], 'codes', 'key')['client:1'] === ['ordering'], 'overdue threshold 9 000: A only « ordering »');
$pdo->exec("UPDATE n_prod_param SET value = '-5' WHERE code = 'liquidity.overdue_min'");
$check($make()->settings()['overdue_min'] == 5000, 'out-of-range threshold falls back to the default');
$pdo->exec("UPDATE n_prod_param SET value = '5000' WHERE code = 'liquidity.overdue_min'");

// --- Étape 2 : solde
$check(str_contains($err(fn() => $svc->saveBalance(['bank_pln' => ''], 1, true, $today)), 'saldo rachunku')
    && str_contains($err(fn() => $svc->saveBalance(['bank_pln' => '10', 'cash_pln' => '-1'], 1, true, $today)), 'ujemne')
    && str_contains($err(fn() => $svc->saveBalance(['bank_pln' => 'abc'], 1, true, $today)), 'liczbę')
    && str_contains($err(fn() => $svc->saveBalance(['bank_pln' => '10', 'date' => '2026-11-20'], 1, true, $today)), 'Data salda'), 'balance validation: required, negative cash, not a number, date outside the week');
$d = $svc->saveBalance(['bank_pln' => '40 000', 'bank_eur' => '1000', 'cash_pln' => '750,00', 'other' => ['', '2000', 0, null], 'note' => 'Rata leasingu w tyg. 2'], 1, true, $today);
$after = $d;
$wk = $d['weeks'];
$check($near($d['totals']['opening'], 45000) && $near($wk[1]['out_other'], 2000) && $near($wk[0]['end'], 38125) && $near($wk[1]['end'], 34875) && $near($wk[2]['end'], -5775) && $near($wk[3]['end'], -13775),
    'forecast with balance 45 000 (40 000 + 1 000 € + 750) and other spending: ends 38 125 / 34 875 / −5 775 / −13 775');
$check($d['totals']['risk_weeks'] === 2 && $wk[2]['risk'] && !$wk[1]['risk'] && $near($d['totals']['min_end'], -13775), 'weeks 3 and 4 at risk');
$keys = array_column($d['signals'], 'key');
$check($keys === ['week:2026-12-07', 'week:2026-12-14', 'client:1', 'client:2', 'supplier:s1'] && str_contains($d['signals'][0]['reasons'][0], 'saldo ujemne')
    && str_contains($d['signals'][0]['reasons'][1], 'wynagrodzenia'), 'week signals once the balance is saved (most serious first) — ' . implode(',', $keys));
$pdo->exec("UPDATE n_prod_param SET value = '-20000' WHERE code = 'liquidity.min_balance'");
$check($make()->data($today)['totals']['risk_weeks'] === 0, 'cushion −20 000 (credit line): no week at risk');
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'liquidity.min_balance'");
$hidden = $svc->data($today, false)['balance'];
$check(array_key_exists('bank_pln', $hidden) && $hidden['bank_pln'] === null && $hidden['other'] === [null, null, null, null] && $hidden['saved'] === true, 'saved balance hidden without values (still marked saved)');

// --- Décisions
$check(str_contains($err(fn() => $svc->saveDecision(['key' => 'client:1', 'decision' => 'action', 'note' => 'ab'], 1, true, $today)), 'notatk')
    && str_contains($err(fn() => $svc->saveDecision(['key' => 'client:1', 'decision' => 'maybe'], 1, true, $today)), 'custom:422')
    && str_contains($err(fn() => $svc->saveDecision(['key' => 'client:3', 'decision' => 'monitor'], 1, true, $today)), 'nie ma na liście'), 'decision validation: note for action, unknown decision, unknown signal');
$svc->saveDecision(['key' => 'client:1', 'decision' => 'monitor'], 1, true, $today);
$svc->saveDecision(['key' => 'client:1', 'decision' => 'action', 'note' => 'Telefon : wstrzymać wysyłkę do wpłaty'], 1, true, $today); // la dernière compte
$row = $pdo->query("SELECT * FROM n_liquidity_decision WHERE signal_key = 'client:1'")->fetchAll();
$check(count($row) === 1 && $row[0]['decision'] === 'action' && $row[0]['week_start'] === '2026-11-23' && $row[0]['codes'] === 'overdue,ordering' && $near($row[0]['amount_pln'], 8500) && $row[0]['label'] === 'Sklep A',
    'one decision per signal per week (upsert) with label, codes and amount');
$st = $tasks->state(LiquidityCheckService::CODE, '2026-11-23 00:00:00', '2026-11-30 00:00:00', '2026-11-29');
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === 'saldo wpisane · 1 decyzja', 'weekly task in progress: balance + 1 decision');
$check(str_contains($err(fn() => $svc->finish(1, true, $today)), 'Najpierw podejmij decyzję dla 4 sygnałów'), 'finish refused while signals wait');
foreach (['week:2026-12-07' => ['action', 'Przesunąć płatność dla X na 15.12'], 'week:2026-12-14' => ['action', 'Kredyt obrotowy : rozmowa z bankiem'],
    'client:2' => ['monitor', ''], 'supplier:s1' => ['no_risk', 'Termin u X : 30 dni']] as $k => [$dec, $note]) {
    $svc->saveDecision(['key' => $k, 'decision' => $dec, 'note' => $note], 2, true, $today);
}
$d = $svc->data($today);
$check($d['can_finish'] === true && $d['summary'] === '4 tyg. · 2 tygodnie z ryzykiem · 5 sygnałów : 3 działania · 1 monitorować · 1 bez ryzyka', 'all decided: step 3 open — ' . $d['summary']);
$check(array_column($d['signals'], null, 'key')['client:2']['decision']['by'] === 'piotr' && array_column($d['signals'], null, 'key')['client:1']['decision']['by'] === 'Anna K.', 'decision author shown');

// --- Étape 3 : Zakończ
$r = $svc->finish(1, true, $today);
$done = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'liquidity_checked'")->fetch();
$check($done && $done['day'] === $today && $done['summary'] === $r['summary'] && (int) $done['done_by'] === 1 && $r['data']['done'] !== null && !str_contains($done['summary'], 'zł'),
    'finish writes n_wizard_done (code, day, summary without amounts, user)');
$st = $tasks->state(LiquidityCheckService::CODE, '2026-11-23 00:00:00', '2026-11-30 00:00:00', '2026-11-29');
$check($st['done'] === true && $st['summary'] === $r['summary'], 'weekly task done with the summary');
$check($tasks->state(LiquidityCheckService::CODE, '2026-11-30 00:00:00', '2026-12-07 00:00:00', '2026-12-06')['done'] === false, 'next week: task open again');
$check($svc->data('2026-12-01')['balance']['saved'] === false && $svc->data('2026-12-01')['done'] === null, 'next week: balance and decisions start again');
$check($sum() === $before, 'read only: ERP tables unchanged');

// --- Enregistrement : catalogue, lien, droits, routes
$check(TaskWizards::info('liquidity_checked')['link'] === '/finances/liquidity' && TaskWizards::byLink('/finances/liquidity') === 'liquidity_checked'
    && isset(TaskWizards::AUTO['liquidity_checked']) && PositionTaskService::LINK_AUTO['/finances/liquidity'] === 'liquidity_checked', 'catalog, auto description and position-task link');
$check(PageAccess::keyFor('/finances/liquidity') === 'margins' && PageAccess::keyFor('/ajax/finances/liquidity') === 'margins' && PageAccess::keyFor('/ajax/finances/liquidity/decision') === 'margins'
    && PageAccess::keyFor('/finances/payments-day') === 'payments_day', 'page and ajax under the existing « margins » right (Finanse) ; other finance pages unchanged');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/finances/LiquidityRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/finances/liquidity') === 'index' && $found('GET', '/ajax/finances/liquidity') === 'data' && $found('POST', '/ajax/finances/liquidity/balance') === 'balance'
    && $found('POST', '/ajax/finances/liquidity/decision') === 'decision' && $found('POST', '/ajax/finances/liquidity/finish') === 'finish', 'routes');
$all = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($all->dispatch('GET', '/finances/liquidity')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob');

if (getenv('LQ_JSON_OUT')) file_put_contents((string) getenv('LQ_JSON_OUT'), json_encode(['before' => $boot, 'after' => $after, 'done' => $svc->data($today)], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP DATABASE erp_liquidity_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
