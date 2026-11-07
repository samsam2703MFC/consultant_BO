<?php
declare(strict_types=1);
/*
 * [247] Lot « lżej » (makiety 18–23) : fonctions pures (proposition de déplacement « Plan na jutro », mois évalué, prix à une
 * date), puis sur une base jetable : « Niezgodności miesiąca » (liste, refus tant qu'une NC est sans action, cause + action,
 * audit fournisseur, e-mail dans la langue du fournisseur avec photo, mois validé) et « Wydajność i straty » (pertes en zł,
 * au plus 3 actions, bannière tablette, revue validée). Aucun e-mail réel : file de messages seulement.
 *
 *   php tests/light_wizards_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_light_wizards_test est recréée puis supprimée.
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Organization\Wizards\CostReviewService;
use App\Services\Organization\Wizards\NcReviewService;
use App\Services\Organization\Wizards\TomorrowPlanService;
use App\Services\Organization\Wizards\YieldReviewService;

function expectLw(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function refusedLw(callable $fn, string $needle): bool
{
    try {
        $fn();
    } catch (CustomException $e) {
        return str_contains($e->getMessage(), $needle);
    }
    return false;
}

// --- Fonctions pures ----------------------------------------------------------------------------------------------------
$g = ['free_h' => -0.8, 'blocks' => [['item_id' => 1, 'name' => 'A', 'hours' => 0.5, 'cartons' => 5], ['item_id' => 2, 'name' => 'B', 'hours' => 2.9, 'cartons' => 60], ['item_id' => 3, 'name' => 'C', 'hours' => 4.0, 'cartons' => 80]],
    'later' => [['date' => '2026-11-06', 'label' => 'pt. 6.11', 'free_h' => 1.0], ['date' => '2026-11-09', 'label' => 'pon. 9.11', 'free_h' => 24.0]]];
$p = TomorrowPlanService::proposal($g);
expectLw($p['item_id'] === 2 && $p['to'] === '2026-11-09' && $p['excess_h'] === 0.8, 'propozycja : najmniejszy blok pokrywający nadmiar, pierwszy dzień z miejscem ' . json_encode($p));
expectLw(TomorrowPlanService::proposal(['free_h' => -0.8] + $g + ['later' => []]) !== null, 'propozycja bez dnia');
$noDay = TomorrowPlanService::proposal(['free_h' => -3.0, 'blocks' => $g['blocks'], 'later' => [['date' => '2026-11-06', 'label' => 'x', 'free_h' => 1.0]]]);
expectLw($noDay === ['item_id' => null, 'excess_h' => 3.0], 'brak dnia z miejscem → tylko nadmiar');
expectLw(TomorrowPlanService::proposal(['free_h' => 2.0] + $g) === null, 'grupa w normie : bez propozycji');
$m = NcReviewService::month(null, '2026-11-04');
expectLw($m['key'] === '2026-10' && $m['from'] === '2026-10-01' && $m['to'] === '2026-11-01' && $m['quarter'] === '2026-Q4' && !$m['quarter_end'], 'miesiąc : poprzedni');
expectLw(NcReviewService::month('2026-09', '2026-11-04')['quarter_end'] && NcReviewService::month('2026-09', '2026-11-04')['quarter'] === '2026-Q3', 'wrzesień : koniec kwartału');
expectLw(NcReviewService::month('2027-01', '2026-11-04')['key'] === '2026-10', 'miesiąc z przyszłości → poprzedni');
$h = [['timestamp' => '2026-05-01 10:00:00', 'price_per_unit' => 0.006], ['timestamp' => '2026-09-01 10:00:00', 'price_per_unit' => 0.0074]];
expectLw(CostReviewService::priceAt($h, strtotime('2026-08-01')) === 0.006 && CostReviewService::priceAt($h, strtotime('2026-10-01')) === 0.0074
    && CostReviewService::priceAt($h, strtotime('2026-01-01')) === 0.006, 'cena w dniu : ostatnia przed, inaczej pierwsza');

// --- Base jetable ---------------------------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_light_wizards_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "light_wizards_test: PARTIAL (fonctions pures OK, bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['n_nc', 'n_nc_photo', 'supplier', 'raw_material', 'final_product', 'n_prod_group', 'user', 'n_supplier_review', 'n_supplier_audit', 'n_wizard_done', 'n_wizard_action',
                 'n_task_reminder', 'communication_message', 'communication_message_attachment', 'communication_email_account', 'n_prod_waste', 'n_prod_output', 'n_prod_item',
                 'n_prod_clock', 'n_prod_employee'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', 1, 1), (3, 'ROGAL', 'Rogal', 'Croissant', 2, 1), (6, 'PAKOWANIE', 'Pakowanie', 'Emballage', 9, 1)");
    $pdo->exec("INSERT INTO user (id, login, displayed_name, password) VALUES (2, 'jakosc', 'Kontrola J.', 'x'), (3, 'kierownik', 'Kierownik P.', 'x')");
    $pdo->exec("INSERT INTO supplier (id, name, email, order_lang, is_active) VALUES (9, 'Dostawca B', 'b@example.invalid', 'fr', 1), (10, 'Dostawca C', '', 'pl', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, price_per_unit) VALUES (4010, 'Masło', 0.0074)");
    $pdo->exec("INSERT INTO n_nc (id, number, reported_at, source, id_supplier, id_raw_material, description, severity, quantity, unit, cost_impact, status) VALUES
        (1, 'NC-2026-001', '2026-10-28', 'supplier_delivery', 9, 4010, 'temperatura 9 °C przy przyjęciu', 'major', 60, 'kg', 420, 'open'),
        (2, 'NC-2026-002', '2026-10-21', 'supplier_delivery', 9, 4010, 'krótki termin', 'minor', 20, 'kg', NULL, 'open'),
        (3, 'NC-2026-003', '2026-10-09', 'supplier_delivery', 9, 4010, 'kartony uszkodzone', 'minor', 2, 'karton', 48, 'closed'),
        (4, 'NC-2026-004', '2026-09-15', 'production', NULL, NULL, 'spalona partia', 'major', 320, 'szt', 310, 'in_progress'),
        (5, 'NC-2026-005', '2026-08-02', 'customer', NULL, NULL, 'zamknięta wcześniej', 'minor', NULL, NULL, NULL, 'closed')");
    $pdo->exec("INSERT INTO n_nc_photo (id_nc, mime, content) VALUES (1, 'image/jpeg', 'JPEGDATA')");
    $pdo->exec("INSERT INTO n_supplier_review (quarter, id_supplier, score, grade, decision) VALUES ('2026-Q3', 9, 55, 'C', 'change')");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $builder = new DI\ContainerBuilder();
    // Transport SMTP factice : rien ne part, la file de messages suffit.
    $builder->addDefinitions([PDO::class => $pdo, App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $a, string $t, string $s, string $b, string $m, array $at = [], ?string $f = null): string { throw new RuntimeException('no mail in tests'); }
    }]);
    $c = $builder->build();

    // --- Niezgodności miesiąca ----------------------------------------------------------------------------------------
    $nc = $c->get(NcReviewService::class);
    $d = $nc->data(null, '2026-11-04');
    expectLw($d['month']['key'] === '2026-10' && $d['totals']['count'] === 3 && $d['totals']['cost'] == 468.0 && $d['totals']['by_source']['supplier_delivery'] === 3, 'lista : 3 NC października, koszt 468 zł ' . json_encode($d['totals']));
    expectLw(count($d['rows']) === 4 && $d['open'] === [1, 2, 4] && $d['totals']['older_open'] === 1, 'otwarte : 2 z października + 1 starsza (wrzesień) ; zamknięta z sierpnia pominięta');
    $sup = $d['suppliers'][0];
    expectLw($sup['id'] === 9 && $sup['nc3'] === 3 && $sup['grade'] === 'C' && $sup['propose_audit'] && $sup['email'] && $d['totals']['top']['nc3'] === 3, 'dostawca B : 3 NC, ocena C → audyt');
    expectLw(refusedLw(fn() => $nc->finish(null, 3, '2026-11-04'), 'nie ma przyczyny'), 'zatwierdzenie odmówione : NC bez działania');
    expectLw(refusedLw(fn() => $nc->saveAction(1, ['cause_cat' => 'pogoda', 'corrective_action' => 'coś tam'], 2), 'Wybierz przyczynę'), 'nieznana przyczyna');
    expectLw(refusedLw(fn() => $nc->saveAction(1, ['cause_cat' => 'transport', 'corrective_action' => 'x'], 2), 'co najmniej 5'), 'działanie za krótkie');
    $nc->saveAction(1, ['cause_cat' => 'transport', 'corrective_action' => 'rejestrator temperatury w aucie', 'owner_user' => 2, 'due_date' => '2026-11-15'], 2);
    $nc->saveAction(2, ['cause_cat' => 'supplier', 'corrective_action' => 'minimalny termin w zamówieniu', 'status' => 'closed'], 2);
    $nc->saveAction(4, ['cause_cat' => 'production', 'corrective_action' => 'karta temperatury pieca'], 2);
    $r1 = $pdo->query('SELECT cause_cat, corrective_action, owner_user, due_date, status FROM n_nc WHERE id = 1')->fetch();
    $r2 = $pdo->query('SELECT status, closed_at FROM n_nc WHERE id = 2')->fetch();
    expectLw($r1['cause_cat'] === 'transport' && (int) $r1['owner_user'] === 2 && $r1['due_date'] === '2026-11-15' && $r1['status'] === 'in_progress', 'NC 1 : przyczyna, kto, termin, w toku');
    expectLw($r2['status'] === 'closed' && $r2['closed_at'] !== null, 'NC 2 : zamknięta');
    $nc->saveAudit(null, ['supplier_id' => 9, 'reason' => '3 NC · ocena C'], 2, '2026-11-04');
    $nc->saveAudit(null, ['supplier_id' => 9, 'result' => 'remarks', 'done_on' => '2026-11-20', 'planned_on' => '2026-11-18'], 2, '2026-11-04');
    $a = $pdo->query('SELECT * FROM n_supplier_audit')->fetchAll();
    expectLw(count($a) === 1 && $a[0]['quarter'] === '2026-Q4' && $a[0]['result'] === 'remarks' && $a[0]['reason'] === '3 NC · ocena C', 'audyt : jeden na kwartał, powód zachowany ' . json_encode($a));
    expectLw(refusedLw(fn() => $nc->mailSupplier(null, 10, 2, '2026-11-04'), 'nie ma adresu'), 'e-mail : dostawca bez adresu');
    $mail = $nc->mailSupplier(null, 9, 2, '2026-11-04');
    $msg = $pdo->query('SELECT message_type, recipient_email, subject_text, body_text, status FROM communication_message')->fetch();
    $att = (int) $pdo->query('SELECT COUNT(*) FROM communication_message_attachment')->fetchColumn();
    expectLw($mail['ncs'] === 3 && $mail['photos'] === 1 && $att === 1 && $msg['status'] === 'queued' && $msg['recipient_email'] === 'b@example.invalid', 'e-mail w kolejce : 3 NC, 1 zdjęcie');
    expectLw(str_starts_with($msg['subject_text'], 'Non-conformités') && str_contains($msg['body_text'], 'NC-2026-001'), 'e-mail po francusku (język dostawcy) z numerami NC');
    $done = $nc->finish(null, 3, '2026-11-04');
    expectLw(str_contains($done['summary'], 'październik 2026 : 3 NC') && str_contains($done['summary'], '1 audyt'), 'miesiąc zatwierdzony ' . $done['summary']);
    expectLw($nc->data(null, '2026-11-04')['done'] !== null, 'zadanie zaliczone (n_wizard_done)');

    // --- Wydajność i straty -------------------------------------------------------------------------------------------
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec("INSERT INTO final_product (id, name, ppc, pre_calculated_raw_price_per_carton) VALUES (101, 'Croissant', 10, 25.00), (102, 'Bagietka', 20, 30.00)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product, base_unit) VALUES (1, 'product', 101, 'szt'), (2, 'product', 102, 'szt')");
    $pdo->exec("INSERT INTO n_prod_waste (work_date, id_group, id_item, quantity, reason) VALUES ('2026-08-10', 3, 1, 100, 'burnt'), ('2026-09-01', 3, 1, 40, 'damaged'),
        ('2026-08-11', 1, 2, 60, 'quality'), ('2026-05-05', 3, 1, 999, 'burnt')");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $y = $c->get(YieldReviewService::class);
    $yd = $y->data(null, '2026-11-04');
    $byG = array_column($yd['groups'], null, 'name');
    expectLw($yd['quarter']['key'] === '2026-Q3' && $yd['prev']['key'] === '2026-Q2', 'kwartał : III (poprzedni : II)');
    expectLw(abs($byG['Rogal']['value'] - 350.0) < 0.01 && abs($byG['Piekarnia']['value'] - 90.0) < 0.01 && abs($yd['totals']['value'] - 440.0) < 0.01, 'wartość strat : szt. ÷ ppc × koszt kartonu (Rogal 350 zł, Piekarnia 90 zł) ' . json_encode($yd['totals']));
    expectLw($yd['reasons'][0]['code'] === 'burnt' && $yd['reasons'][0]['q'] == 100.0 && $yd['top'][0]['name'] === 'Croissant', 'przyczyny i produkty kwartału (bez II kwartału)');
    $four = array_fill(0, 4, ['title' => 'działanie', 'group_id' => 3]);
    expectLw(refusedLw(fn() => $y->finish(null, $four, true, 3, '2026-11-04'), 'Najwyżej 3'), 'najwyżej 3 działania');
    expectLw(refusedLw(fn() => $y->finish(null, [['title' => ' ']], true, 3, '2026-11-04'), 'co najmniej jedno'), 'co najmniej jedno działanie');
    expectLw(refusedLw(fn() => $y->finish(null, [['title' => 'x', 'user_id' => 99]], true, 3, '2026-11-04'), 'nieznana osoba'), 'nieznana osoba');
    $fin = $y->finish(null, [['title' => 'Rogal : karta temperatury pieca', 'goal' => 'straty < 2 %', 'user_id' => 3, 'group_id' => 3, 'due_date' => '2026-11-30'],
        ['title' => 'Przekładki w kartonach', 'goal' => '−50 % uszkodzeń']], true, 3, '2026-11-04');
    $acts = $pdo->query("SELECT code, period, title, id_group, status FROM n_wizard_action ORDER BY id")->fetchAll();
    $tab = $pdo->query("SELECT audience, target_kind, message FROM n_task_reminder")->fetchAll();
    expectLw(count($acts) === 2 && $acts[0]['period'] === '2026-Q3' && (int) $acts[0]['id_group'] === 3 && $acts[0]['status'] === 'open', 'działania zapisane (wracają na zebraniu)');
    expectLw(count($tab) === 1 && $tab[0]['audience'] === 'group:3' && $tab[0]['target_kind'] === 'tablet' && str_contains($tab[0]['message'], 'straty < 2 %') && $fin['sent'] === 1, 'baner na tablecie Rogala');
    $y->finish(null, [['title' => 'Jedno działanie']], false, 3, '2026-11-04');
    expectLw((int) $pdo->query('SELECT COUNT(*) FROM n_wizard_action')->fetchColumn() === 1 && $y->data(null, '2026-11-04')['done'] !== null, 'ponowne zatwierdzenie zastępuje otwarte działania ; zadanie zaliczone');

    echo "light_wizards_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
