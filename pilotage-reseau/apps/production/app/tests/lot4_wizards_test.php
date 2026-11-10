<?php
declare(strict_types=1);
/*
 * Lot 4 [230] : trois nouveaux assistants attachés aux tâches de poste — sur une base jetable (structure copiée de erp) :
 *   - migration 149 : tâches Jakość / Kierownik (« contrôle des réceptions »), Administracja (« paiements du jour »),
 *     Kierownik trimestriel (« évaluation des fournisseurs ») reliées à leur assistant, pages et accès ;
 *   - Kontrola przyjęć dnia (tablette d'emballage, mode « Przyjęcia ») : livraisons du jour, lignes OK / NC (réception
 *     dans la commande avec lot, DLUO, température ; NC avec types, décision, photo ; cloche pour retour / décision),
 *     livraison non arrivée, résumé et brouillon d'e-mail dans la langue du fournisseur, tâche cochée seule ;
 *   - Płatności dnia : proformas impayées, retard, rappels dus, « opłacone » avec date et montant, fin → tâche cochée ;
 *   - Ocena dostawców : trimestre précédent, note 40/30/30, A / B / C, décision par défaut, matières proposées
 *     (+5 %, fournisseur C, clé avec < 3 fournisseurs), brouillon d'appel d'offres (aucun envoi), fin → tâche cochée.
 *
 *   php tests/lot4_wizards_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp, migration 149 appliquée). La base erp_lot4_test est
 * recréée puis supprimée. Aucun e-mail : le transport SMTP du test refuse tout.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Finances\PaymentsDayService;
use App\Services\Organization\TaskWizards;
use App\Services\Purchasing\ReceivingWizardService;
use App\Services\Purchasing\SupplierReviewService;
use App\Services\Workshop\Migration\MigrationRunner;

function expect4(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- Sans base : trimestre, note, décision
$q = SupplierReviewService::quarter(null, '2026-10-31');
expect4($q['key'] === '2026-Q3' && $q['from'] === '2026-07-01' && $q['to'] === '2026-10-01' && $q['label'] === 'III kwartał 2026', 'quarter: the previous one (Q3 for 31.10)');
expect4(SupplierReviewService::quarter('2026-Q1', '2026-10-31')['key'] === '2026-Q1' && SupplierReviewService::quarter('2026-Q4', '2026-10-31')['key'] === '2026-Q3'
    && SupplierReviewService::quarter(null, '2026-02-10')['key'] === '2025-Q4', 'quarter: past choice only, January → Q4 of the previous year');
expect4(SupplierReviewService::score(96, 1 - 2 / 26, -1.2) === ['score' => 96.1, 'grade' => 'A'], 'score: 96 % on time, 1 NC / 26, −1,2 % → A');
expect4(SupplierReviewService::score(88, 1 - 4 / 24, 6.8)['grade'] === 'B' && SupplierReviewService::score(75, 1.0, 14.0)['grade'] === 'C'
    && SupplierReviewService::score(64, 0.27, 9.5)['grade'] === 'C', 'score: mockup grades B / C / C');
expect4(SupplierReviewService::score(null, 1.0, null) === ['score' => 100.0, 'grade' => 'A'] && SupplierReviewService::score(null, null, null)['grade'] === null, 'score: missing parts are skipped');
expect4(SupplierReviewService::defaultDecision('C') === 'change' && SupplierReviewService::defaultDecision('B') === 'watch' && SupplierReviewService::defaultDecision(null) === 'keep', 'default decision');
expect4(ReceivingWizardService::isCold('Nabiał', 'Masło 82 %') && ReceivingWizardService::isCold(null, 'Drożdże świeże') && !ReceivingWizardService::isCold('Mąka', 'Mąka pszenna typ 650'), 'cold room materials');
expect4(ReceivingWizardService::day('2099-01-01') === date('Y-m-d') && ReceivingWizardService::day(date('Y-m-d', strtotime('-1 day'))) === date('Y-m-d', strtotime('-1 day'))
    && ReceivingWizardService::day('2000-01-01') === date('Y-m-d'), 'receiving day: today or up to 30 days back');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_lot4_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "lot4_wizards_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    expect4(in_array('n_receiving_check', $tables, true) && in_array('n_supplier_review', $tables, true), 'source database has migration 149');
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $today = date('Y-m-d');

    // --- Migration 149 : relier les tâches (état avant 149), rejouable
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'anna', 'Anna K.')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'JAKOSC', 'Jakość', 'Qualité', 'bi-shield', 1, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (2, 'ADMINISTRACJA', 'Administracja', 'Administration', 'erp', 1, 1),
        (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 2, 1), (4, 'JAKOSC', 'Jakość', 'Qualité', 'erp', 3, 1)");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, link_path, extra_links, sort_order, active) VALUES
        (2, 2, 1, 'Kontrola wysłanych faktur i płatności', 'x', 'daily', '/communication/messages', NULL, 1, 1),
        (4, 4, 1, 'Analiza niezgodności', 'x', 'monthly', '/purchasing/nc', NULL, 1, 1),
        (5, 4, 1, 'Kontrola jakości przyjęć — rejestr NC', 'x', 'daily', '/purchasing/nc', NULL, 2, 1),
        (12, 3, 1, 'Ocena dostawców i zapytanie ofertowe', 'x', 'quarterly', '/purchasing/tenders', '[\"/purchasing/nc\"]', 3, 1),
        (16, 3, 1, 'Sprawdzić przyjęcia surowców', 'x', 'daily', '/purchasing/nc', NULL, 4, 1)");
    $pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (2, 'reminders'), (3, 'purchasing'), (3, 'supply'), (4, 'production')");
    $migration = (string) file_get_contents(__DIR__ . '/../migrations/tfb/149_lot4_wizards.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($migration) as $sql) $pdo->exec($sql);
    $tasks = $pdo->query('SELECT id, link_path, auto_code, due_time, extra_links FROM n_position_task ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE);
    expect4($tasks[5]['auto_code'] === 'receiving_checked' && $tasks[16]['auto_code'] === 'receiving_checked' && $tasks[5]['link_path'] === '/workshop/receiving'
        && $tasks[5]['due_time'] === '14:00:00' && $tasks[5]['extra_links'] === '["/purchasing/nc"]', 'migration: Jakość + Kierownik daily reception check → receiving wizard (14:00)');
    expect4($tasks[2]['auto_code'] === 'payments_reviewed' && $tasks[2]['link_path'] === '/finances/payments-day' && $tasks[2]['due_time'] === '11:00:00', 'migration: Administracja → payments wizard (11:00)');
    expect4($tasks[12]['auto_code'] === 'suppliers_reviewed' && $tasks[12]['link_path'] === '/purchasing/supplier-review' && str_contains((string) $tasks[12]['extra_links'], '/purchasing/tenders'), 'migration: quarterly review → supplier review wizard');
    expect4($tasks[4]['auto_code'] === null && $tasks[4]['link_path'] === '/purchasing/nc', 'migration: monthly NC analysis untouched');
    $access = $pdo->query("SELECT CONCAT(id_position, ':', page_key) FROM n_page_access WHERE page_key IN ('receiving', 'payments_day', 'supplier_review') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    expect4($access === ['2:payments_day', '3:receiving', '3:supplier_review', '4:receiving'], 'migration: page access from parent pages — ' . json_encode($access));
    foreach (['receiving_checked', 'payments_reviewed', 'suppliers_reviewed'] as $code) expect4(TaskWizards::info($code) !== null && isset(TaskWizards::AUTO[$code]), "catalogue: {$code}");
    expect4(TaskWizards::byLink('/workshop/receiving') === 'receiving_checked' && TaskWizards::byLink('/finances/payments-day') === 'payments_reviewed', 'catalogue: by link');
    expect4(\App\Core\Web\PageAccess::keyFor('/workshop/receiving') === 'receiving' && \App\Core\Web\PageAccess::keyFor('/finances/payments-day') === 'payments_day'
        && \App\Core\Web\PageAccess::keyFor('/purchasing/supplier-review') === 'supplier_review' && \App\Core\Web\PageAccess::keyFor('/purchasing/nc/photo/3') === 'production', 'page access keys');

    // --- Données : fournisseurs, matières, commandes du jour, opérateur
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g'), (2, 'szt')");
    $pdo->exec("INSERT INTO category_material (id, id_group, name) VALUES (105, 0, 'Mąka'), (109, 0, 'Nabiał'), (110, 0, 'Jaja')");
    $pdo->exec("INSERT INTO supplier (id, name, email, delivery_weekdays, order_lang, is_active) VALUES (7, 'Młyn Test', 'mlyn@example.invalid', NULL, 'fr', 1),
        (8, 'Mleczarnia Test', 'mleko@example.invalid', NULL, 'pl', 1), (9, 'Masło Plus', 'maslo@example.invalid', NULL, 'pl', 1), (10, 'Jaja Wiejskie', '', NULL, 'pl', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, id_category, price_per_unit, id_current_supplier) VALUES (41, 'Mąka pszenna typ 650', 1, 105, 0.002, 7),
        (42, 'Masło 82 %', 1, 109, 0.03, 8), (43, 'Jaja L', 2, 110, 0.6, 10)");
    $pdo->exec("INSERT INTO n_material_supplier (id_raw_material, id_supplier, sort, source) VALUES (41, 7, 1, 'manual'), (42, 8, 1, 'manual'), (42, 9, 2, 'manual'), (43, 10, 1, 'manual')");
    $pdo->exec("INSERT INTO production_employee (id, name, surname) VALUES (5, 'Jan', 'Kowalski')");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active) VALUES (9, 5, 1)");
    $pdo->exec("INSERT INTO n_po (id, code, id_supplier, status, week_start, delivery_date, language, created_at) VALUES
        (1, 'ZM-0001', 7, 'sent', '{$today}', '{$today}', 'fr', NOW()), (2, 'ZM-0002', 10, 'sent', '{$today}', '{$today}', 'pl', NOW()),
        (3, 'ZM-0003', 8, 'sent', '{$today}', DATE_SUB('{$today}', INTERVAL 3 DAY), 'pl', NOW())");
    $pdo->exec("INSERT INTO n_po_line (id, id_po, id_raw_material, quantity, unit_price) VALUES (11, 1, 41, 25000, 0.002), (12, 1, 42, 5000, 0.03), (21, 2, 43, 300, 0.6), (31, 3, 42, 2000, 0.03)");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
    }]);
    $container = $builder->build();
    $rcv = $container->get(ReceivingWizardService::class);
    $w = $container->get(TaskWizards::class);
    $dayRange = [$today . ' 00:00:00', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00', $today];

    // --- Kontrola przyjęć : livraisons du jour
    $ov = $rcv->overview($today);
    expect4($ov['stats']['due'] === 2 && $ov['stats']['overdue'] === 1 && $ov['stats']['lines'] === 3 && $ov['orders'][0]['due'] && !end($ov['orders'])['due'], 'receiving: 2 deliveries today + 1 overdue open order');
    $st = $w->state('receiving_checked', ...$dayRange);
    expect4(!$st['done'] && $st['step'] === null, 'task: nothing checked yet');
    $o1 = $rcv->order(1, $today);
    expect4($o1['lines'][0]['unit'] === 'kg' && $o1['lines'][0]['ordered'] == 25 && $o1['lines'][0]['remaining'] == 25 && !$o1['lines'][0]['cold'] && $o1['lines'][1]['cold'], 'order: kg display, masło = cold room');

    // Refus : NC sans type, photo qui n'est pas une image
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    $lines = [['id' => 11, 'status' => 'ok', 'qty' => 25, 'lot' => 'l-2410-88', 'best_before' => '2027-04-30', 'temperature' => null],
        ['id' => 12, 'status' => 'nc', 'qty' => 4.5, 'lot' => 'M-77', 'best_before' => '2026-12-01', 'temperature' => 3.5]];
    foreach ([[['line_id' => 12, 'kinds' => [], 'decision' => 'manager']], [['line_id' => 12, 'kinds' => ['qty'], 'decision' => 'manager', 'photos' => ['data:text/plain;base64,aGVsbG8=']]],
              [['line_id' => 12, 'kinds' => ['qty'], 'decision' => 'manager', 'photos' => ['data:image/png;base64,aGVsbG8=']]]] as $bad) {
        try { $rcv->save(1, ['day' => $today, 'lines' => $lines, 'problems' => $bad], 1); expect4(false, 'bad NC refused'); }
        catch (App\Exceptions\CustomException $e) { expect4($e->getCode() === 422, 'bad NC → 422 : ' . $e->getMessage()); }
    }
    expect4((int) $pdo->query('SELECT COUNT(*) FROM n_po_receipt')->fetchColumn() === 0, 'refused check writes nothing');

    // Contrôle valide depuis la tablette (opérateur Jan Kowalski)
    $r = $rcv->save(1, ['day' => $today, 'lines' => $lines, 'problems' => [['line_id' => 12, 'kinds' => ['qty', 'package'], 'description' => 'Brak 1 kartonu', 'decision' => 'manager', 'photos' => [$png]]]], null, 9);
    expect4($r['ok'] === 1 && $r['nc'] === 1 && count($r['nc_numbers']) === 1, 'check saved: 1 OK · 1 NC');
    $receipts = $pdo->query('SELECT id_po_line, quantity, lot_number, best_before, temperature_c, id_nc, id_employee FROM n_po_receipt ORDER BY id_po_line')->fetchAll();
    expect4(count($receipts) === 2 && (float) $receipts[0]['quantity'] === 25000.0 && $receipts[0]['lot_number'] === 'L-2410-88' && $receipts[0]['best_before'] === '2027-04-30',
        'receipt in the order: 25 kg → 25 000 g, lot upper-cased, best before');
    expect4((float) $receipts[1]['quantity'] === 4500.0 && (float) $receipts[1]['temperature_c'] === 3.5 && (int) $receipts[1]['id_nc'] > 0 && (int) $receipts[1]['id_employee'] === 9,
        'NC line: 4,5 kg accepted, 3,5 °C, linked NC, tablet operator');
    $nc = $pdo->query('SELECT * FROM n_nc')->fetch();
    expect4($nc['source'] === 'supplier_delivery' && (int) $nc['id_supplier'] === 7 && (int) $nc['id_raw_material'] === 42 && (float) $nc['quantity'] === 500.0 && $nc['status'] === 'open'
        && str_contains($nc['description'], '[Ilość, Opakowanie uszkodzone]') && str_contains($nc['description'], 'tablet : Jan Kowalski') && str_contains($nc['immediate_action'], 'Do decyzji kierownika'),
        'NC: supplier, material, 500 g missing, kinds, operator, decision');
    expect4((int) $pdo->query("SELECT COUNT(*) FROM n_nc_photo WHERE id_nc = {$nc['id']} AND mime = 'image/png'")->fetchColumn() === 1, 'NC photo stored (PNG)');
    $bell = $pdo->query("SELECT type, severity, title, link_url FROM app_notification")->fetchAll();
    expect4(count($bell) === 1 && $bell[0]['type'] === 'receiving_nc' && str_contains($bell[0]['title'], 'Do decyzji kierownika') && $bell[0]['link_url'] === '/purchasing/nc', 'bell: manager decision');
    expect4($pdo->query('SELECT status FROM n_po WHERE id = 1')->fetchColumn() === 'partial', 'order partially received (4,5 of 5 kg)');
    $chk = $pdo->query('SELECT lines_ok, lines_nc, arrived, id_employee, nc_ids FROM n_receiving_check WHERE id_po = 1')->fetch();
    expect4((int) $chk['lines_ok'] === 1 && (int) $chk['lines_nc'] === 1 && (int) $chk['arrived'] === 1 && (int) $chk['id_employee'] === 9 && json_decode($chk['nc_ids'], true) === [(int) $nc['id']], 'check row');
    $st = $w->state('receiving_checked', ...$dayRange);
    expect4(!$st['done'] && $st['step'] === 2 && $st['step_label'] === '1 z 2 dostaw sprawdzone', 'task: 1 of 2 checked');

    // Livraison non arrivée → tâche cochée
    $rcv->save(2, ['day' => $today, 'arrived' => false], 1);
    $st = $w->state('receiving_checked', ...$dayRange);
    expect4($st['done'] && $st['summary'] === "2 dostawy · 1 OK · 1 NC · 1 nie dotarła", 'task done: ' . ($st['summary'] ?? ''));
    expect4((int) $pdo->query('SELECT COUNT(*) FROM n_po_receipt WHERE id_po_line = 21')->fetchColumn() === 0 && $pdo->query('SELECT status FROM n_po WHERE id = 2')->fetchColumn() === 'sent', 'not arrived: no receipt, order still open');

    // Résumé ERP : NC, photo, e-mail en français pour le fournisseur francophone
    $sum = $rcv->summary($today);
    expect4(count($sum['ncs']) === 1 && $sum['ncs'][0]['photos'] !== [] && count($sum['mails']) === 1 && $sum['mails'][0]['email'] === 'mlyn@example.invalid', 'summary: NC with photo, one mail');
    expect4(str_starts_with($sum['mails'][0]['subject'], 'Non-conformité à la réception') && str_contains($sum['mails'][0]['body'], 'Masło 82 %') && str_contains($sum['mails'][0]['body'], 'décision : en attente de décision'),
        'mail draft in the supplier language (FR) — ' . $sum['mails'][0]['body']);
    expect4($rcv->photoFile($sum['ncs'][0]['photos'][0])['mime'] === 'image/png', 'photo served');
    $o1 = $rcv->order(1, $today);
    expect4($o1['check']['ok'] === 1 && $o1['lines'][1]['today']['qty'] == 4.5 && $o1['lines'][1]['today']['temperature'] == 3.5 && $o1['lines'][1]['remaining'] == 0.5, 'order view after check: today 4,5 kg, 0,5 kg left');

    // Commande déjà reçue (tablette du groupe) : contrôle sans nouvelle réception
    $pdo->exec("UPDATE n_po SET status = 'received' WHERE id = 3");
    $pdo->exec("INSERT INTO n_po_receipt (id_po_line, received_on, quantity, unit_price, created_at) VALUES (31, '{$today}', 2000, 0.03, NOW())");
    $ov = $rcv->overview($today);
    expect4($ov['stats']['due'] === 3, 'order received today counts as a delivery of the day');
    $rcv->save(3, ['day' => $today, 'lines' => [['id' => 31, 'status' => 'nc', 'qty' => null]], 'problems' => [['line_id' => 31, 'kinds' => ['temp'], 'decision' => 'return']]], 1);
    expect4((int) $pdo->query('SELECT COUNT(*) FROM n_po_receipt WHERE id_po_line = 31')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM n_nc')->fetchColumn() === 2
        && (int) $pdo->query('SELECT COUNT(*) FROM app_notification')->fetchColumn() === 2, 'received order: NC + bell (return), no extra receipt');
    expect4($w->state('receiving_checked', ...$dayRange)['done'], 'task still done with 3 of 3');

    // --- Płatności dnia
    $pdo->exec("INSERT INTO customer (id, company) VALUES (1, 'Sklep Test')");
    $d10 = date('Y-m-d', strtotime('-10 days'));
    $d2 = date('Y-m-d', strtotime('-2 days'));
    $pdo->exec("INSERT INTO document_proforma (id, doc_num, id_customer, established_date, status) VALUES (10, 'PF/T/10', 1, '{$d10}', 'issued'), (11, 'PF/T/11', 1, '{$d2}', 'issued'), (12, 'PF/T/12', 1, '{$d10}', 'paid')");
    $pdo->exec("INSERT INTO document_proforma_product (id_proforma, id_product, product_name, quantity, total_price_net) VALUES (10, 1, 'A', 10, 1200.50), (11, 1, 'A', 5, 600), (12, 1, 'A', 1, 99)");
    $pdo->exec("INSERT INTO communication_message (id_proforma, message_type, status, created_at) VALUES (10, 'proforma_reminder_1', 'sent', DATE_SUB(NOW(), INTERVAL 3 DAY))");
    $pay = $container->get(PaymentsDayService::class);
    $d = $pay->data($today);
    expect4($d['totals']['count'] === 2 && $d['totals']['unpaid'] == 1800.5 && $d['totals']['overdue'] == 1200.5 && $d['proformas'][0]['id'] === 10 && $d['proformas'][0]['level'] === 1, 'payments: 2 unpaid, 1 overdue (> 7 days), sorted by age');
    expect4(count($d['reminders']) === 1 && $d['reminders'][0]['next_level'] === 2 && !$d['reminders'][0]['auto'] && $d['done'] === null, 'payments: reminder level 2 due (manual, auto off)');
    $st = $w->state('payments_reviewed', ...$dayRange);
    expect4(!$st['done'] && $st['step'] === null, 'payments task: open');
    foreach ([[], [['id' => 10, 'amount' => -5]], [['id' => 10, 'paid_on' => '2099-01-01']]] as $bad) {
        try { $pay->markPaid($bad, $today, 1); expect4(false, 'bad payment refused'); } catch (App\Exceptions\CustomException $e) { expect4($e->getCode() === 422, 'bad payment → 422'); }
    }
    $m = $pay->markPaid([['id' => 10, 'paid_on' => $today, 'amount' => 1200.5]], $today, 2);
    expect4($m['paid'] === 1 && $m['sum'] == 1200.5 && $pdo->query('SELECT status FROM document_proforma WHERE id = 10')->fetchColumn() === 'paid', 'proforma marked paid');
    $pp = $pdo->query('SELECT paid_on, amount, recorded_by FROM n_proforma_payment WHERE id_proforma = 10')->fetch();
    expect4($pp['paid_on'] === $today && (float) $pp['amount'] === 1200.5 && (int) $pp['recorded_by'] === 2, 'payment date and amount kept');
    $st = $w->state('payments_reviewed', ...$dayRange);
    expect4(!$st['done'] && $st['step'] === 2 && $st['step_label'] === '1 wpłata zapisana', 'payments task in progress after step 1');
    $d = $pay->data($today);
    expect4($d['totals']['count'] === 1 && $d['totals']['paid_today'] == 1200.5 && $d['totals']['avg_days'] == 10.0 && count($d['paid_today']) === 1 && $d['reminders'] === [], 'after payment: 1 unpaid, paid today, 10 days to pay');
    $f = $pay->finish($today, 2);
    expect4($f['summary'] === "1 opłacona · 0 przypomnień · należności 600 €", 'finish summary — ' . $f['summary']);
    $st = $w->state('payments_reviewed', ...$dayRange);
    expect4($st['done'] && $st['summary'] === $f['summary'], 'payments task done');

    // --- Ocena dostawców (trimestre III 2026, tâche du IV)
    $pdo->exec("INSERT INTO n_po (id, code, id_supplier, status, week_start, delivery_date, language, created_at) VALUES
        (5, 'ZM-0005', 7, 'received', '2026-08-10', '2026-08-10', 'pl', NOW()), (6, 'ZM-0006', 8, 'received', '2026-08-12', '2026-08-12', 'pl', NOW())");
    $pdo->exec("INSERT INTO n_po_line (id, id_po, id_raw_material, quantity, unit_price) VALUES (51, 5, 41, 100000, 0.002), (61, 6, 42, 10000, 0.03)");
    $pdo->exec("INSERT INTO n_po_receipt (id_po_line, received_on, quantity, unit_price, created_at) VALUES (51, '2026-08-10', 100000, 0.002, NOW()), (61, '2026-08-15', 10000, 0.03, NOW())");
    $pdo->exec("INSERT INTO n_nc (number, reported_at, source, id_supplier, description, severity, status) VALUES ('NC-Q3-1', '2026-08-15', 'supplier_delivery', 8, 'x', 'minor', 'closed'),
        ('NC-Q3-2', '2026-09-02', 'supplier_delivery', 8, 'x', 'minor', 'closed')");
    $pdo->exec("INSERT INTO raw_material_price_changelog (id_supplier, id_raw_material, pack_price, package_size_in_base_unit, source, timestamp) VALUES
        (7, 41, 2.00, 1000, 'manual', '2026-06-01 10:00:00'), (7, 41, 1.96, 1000, 'manual', '2026-09-10 10:00:00'),
        (8, 42, 30.0, 1000, 'manual', '2026-06-01 10:00:00'), (8, 42, 34.0, 1000, 'manual', '2026-09-01 10:00:00')");
    $sr = $container->get(SupplierReviewService::class);
    $data = $sr->data(null, '2026-10-31');
    $by = array_column($data['suppliers'], null, 'id');
    expect4($data['quarter']['key'] === '2026-Q3' && count($data['suppliers']) === 2 && $data['suppliers'][0]['id'] === 8, 'review: 2 suppliers with Q3 data, sorted by purchases (300 zł first)');
    expect4($by[7]['grade'] === 'A' && $by[7]['on_time'] == 100.0 && $by[7]['price'] == -2.0 && $by[7]['decision'] === 'keep' && $by[7]['value'] == 200.0, 'Młyn: on time, −2 % → A, keep');
    expect4($by[8]['grade'] === 'C' && $by[8]['on_time'] == 0.0 && $by[8]['nc'] === 2 && $by[8]['price'] == 13.3 && $by[8]['decision'] === 'change', 'Mleczarnia: late, 2 NC, +13,3 % → C, change');
    expect4($data['grades'] === ['A' => 1, 'B' => 0, 'C' => 1], 'grades count');
    $mats = array_column($data['materials'], null, 'id');
    expect4(isset($mats[42]) && $mats[42]['reasons'] === ['price', 'supplier', 'few'] && isset($mats[41]) && $mats[41]['reasons'] === ['few'] && !isset($mats[43]), 'materials to quote: masło (3 reasons), mąka (key, 1 supplier)');
    expect4($data['materials'][0]['id'] === 42, 'materials sorted by number of reasons');
    $cands = array_column($data['candidates'], 'id');
    sort($cands);
    expect4($cands === [7, 8, 9], 'tender candidates: active suppliers with e-mail (Jaja without e-mail excluded)');
    try { $sr->saveGrades(null, [['supplier_id' => 10, 'decision' => 'keep']], 1, '2026-10-31'); expect4(false, 'unknown supplier refused'); } catch (App\Exceptions\CustomException $e) { expect4(true, ''); }
    expect4($sr->saveGrades(null, [['supplier_id' => 7, 'decision' => 'keep'], ['supplier_id' => 8, 'decision' => 'watch', 'note' => 'Rozmowa o cenie masła']], 2, '2026-10-31') === 2, 'grades saved');
    $rev = $pdo->query("SELECT id_supplier, grade, decision, note, score FROM n_supplier_review WHERE quarter = '2026-Q3' ORDER BY id_supplier")->fetchAll();
    expect4($rev[1]['grade'] === 'C' && $rev[1]['decision'] === 'watch' && $rev[1]['note'] === 'Rozmowa o cenie masła' && (float) $rev[0]['score'] === 100.0, 'review rows: grade server-side, decision corrected, note');
    $q4 = ['2026-10-01 00:00:00', '2027-01-01 00:00:00', '2026-12-31'];
    $st = $w->state('suppliers_reviewed', ...$q4);
    expect4(!$st['done'] && $st['step'] === 2, 'review task in progress after grades');
    $data = $sr->data(null, '2026-10-31');
    expect4(array_column($data['materials'], 'reasons', 'id')[42] === ['price', 'few'], 'corrected decision (watch) removes the « supplier C » reason');
    try { $sr->createTender(['material_ids' => [], 'supplier_ids' => [7]], 1); expect4(false, 'empty tender refused'); } catch (App\Exceptions\CustomException $e) { expect4(true, ''); }
    $t = $sr->createTender(['title' => 'Zapytanie Q4', 'reply_deadline' => '2026-11-16', 'material_ids' => [42, 41], 'supplier_ids' => [8, 9]], 2);
    $tender = $pdo->query("SELECT status, title, reply_deadline FROM n_tender WHERE id = {$t['id']}")->fetch();
    expect4($t['code'] !== null && $tender['status'] === 'draft' && $tender['reply_deadline'] === '2026-11-16'
        && (int) $pdo->query("SELECT COUNT(*) FROM n_tender_item WHERE id_tender = {$t['id']}")->fetchColumn() === 2
        && (int) $pdo->query("SELECT COUNT(*) FROM n_tender_supplier WHERE id_tender = {$t['id']}")->fetchColumn() === 2, 'tender draft: 2 materials, 2 suppliers, nothing sent');
    expect4((int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE message_type LIKE 'tender%'")->fetchColumn() === 0, 'no tender e-mail');
    $f = $sr->finish(null, [], $t['id'], 2, '2026-10-31');
    expect4($f['summary'] === 'III kwartał 2026 : 1 A · 0 B · 1 C · zapytanie ' . $t['code'], 'review summary — ' . $f['summary']);
    $st = $w->state('suppliers_reviewed', ...$q4);
    expect4($st['done'] && $st['summary'] === $f['summary'], 'review task done for Q4');
    expect4($sr->data(null, '2026-10-31')['done']['summary'] === $f['summary'], 'wizard shows the result');

    echo "lot4_wizards_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
