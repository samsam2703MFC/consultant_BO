<?php
declare(strict_types=1);
/*
 * Faktury zakupowe : surowce ↔ usługi (Finanse) ↔ odrzucone, reguły dostawców, koszty usług miesiąca → kalkulator kosztów,
 * miejsce pracy « Faktury i koszty » (27/10/2026, makiety 1 à 4).
 *  - podpowiedź usługi : sprzedawca (Orlen → paliwo) albo ≥ 60 % wartości linii (energia → prąd), linie surowców = zakup ;
 *  - klasyfikacja : usługa (kategoria kalkulatora, miesiąc), odrzucona, z powrotem do zaopatrzenia (przypisania wracają) ;
 *    reguła « zawsze dla dostawcy » (NIP) : inne otwarte faktury od razu, kolejne już przy imporcie z KSeF (bez automatu) ;
 *    faktura przetworzona zostaje w zaopatrzeniu ;
 *  - miesiąc usług : kategorie, porównanie z poprzednim miesiącem (brakuje / nie w tym miesiącu), zatwierdzenie, koszt
 *    wpisany ręcznie (dostawca zagraniczny), zamknięcie → koszty stałe / media / logistyka (ręczne wiersze zastąpione),
 *    otwarcie przywraca ręczne wiersze, zamknięty miesiąc kalkulatora blokuje ;
 *  - Faktury i koszty : przypisania z podpowiedzią, podejrzane usługi, gotowe, podwyżki ze skutkiem miesięcznym.
 *
 *   php tests/finance_costs_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_finance_test est recréée puis supprimée.
 * KSeF jamais appelé : source simulée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Services\Workshop\Finance\CostCategories;
use App\Services\Workshop\Finance\FinanceWorkspaceService;
use App\Services\Workshop\Finance\ServiceCostService;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Supply\InvoiceService;

function expectFin(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectFinError(callable $fn, string $code, string $message): void
{
    try { $fn(); } catch (ApiException $e) { expectFin($e->errorCode() === $code, $message . ' (got ' . $e->errorCode() . ')'); return; }
    expectFin(false, $message . ' (no error)');
}

// --- Sans base : podpowiedź usługi
$cats = ['log:FUEL' => ['label' => 'Paliwo'], 'media:POWER' => ['label' => 'Prąd'], 'log:FREIGHT_OUT' => ['label' => 'Transport'], 'fixed:TELECOM' => ['label' => 'Telefon']];
expectFin(CostCategories::guess(['seller_name' => 'ORLEN S.A.'], [['name' => 'ON EKODIESEL', 'net_value' => 300, 'id_raw_material' => null]], $cats)['code'] === 'log:FUEL', 'Orlen → fuel (seller)');
expectFin(CostCategories::guess(['seller_name' => 'X Sp. z o.o.'], [['name' => 'Energia elektryczna czynna', 'net_value' => 900], ['name' => 'Opłata handlowa', 'net_value' => 10]], $cats)['code'] === 'media:POWER', 'energy lines ≥ 60 % → power');
expectFin(CostCategories::guess(['seller_name' => 'Młyn'], [['name' => 'Mąka T-65', 'net_value' => 900], ['name' => 'Transport', 'net_value' => 40]], $cats) === null, 'goods with a transport line: not a service');
expectFin(CostCategories::guess(['seller_name' => 'ORLEN S.A.'], [['name' => 'Olej', 'net_value' => 30, 'id_raw_material' => 5]], $cats) === null, 'a mapped line means a purchase of materials');
expectFin(ServiceCostService::sellerKey('774-00-01-454', 'x') === 'nip:7740001454' && str_starts_with(ServiceCostService::sellerKey(null, 'Ąbc'), 'name:'), 'seller key: NIP, else name');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_finance_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "finance_costs_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit',
                 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency', 'customer',
                 'document_order_shipping_difference', 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings',
                 'supplier', 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month',
                 'cost_media_section_allocation', 'cost_media_type', 'cost_logistics_type', 'communication_message', 'communication_message_attachment', 'communication_email_account',
                 'erp_company_data', 'flattened_recipe_recipe', 'product_preparation_type', 'product_recipe_preparation_connection', 'product_recipe_type', 'product_recipe_preparation_step',
                 'product_recipe_prepare_instruction_connection', 'test_product', 'test_product_status', 'test_product_type', 'test_product_stage', 'test_product_changelog'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Ola', 'F', 'Ola F.', 'o@x.test', '1', 0, 'ola', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO cost_media_type (id, code, name) VALUES (1, 'POWER', 'Prąd'), (2, 'WATER', 'Woda')");
    $pdo->exec("INSERT INTO cost_logistics_type (id, code, name, sort_order) VALUES (1, 'FUEL', 'Paliwo', 1), (14, 'OTHER_LOG', 'Pozostałe', 14)");
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g')");
    $pdo->exec("INSERT INTO supplier (id, name, email, is_active) VALUES (7, 'Młyn test', 'mlyn@example.invalid', 1)");
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, package_size_in_base_unit, id_current_supplier) VALUES (900, 'Mąka pszenna T-65', 1, 0.004, 25000, 7)");

    $m1 = date('Y-m', strtotime('first day of -2 month'));
    $m2 = date('Y-m', strtotime('first day of last month'));
    $m3 = date('Y-m');
    // Faktury KSeF : Orlen (paliwo, 2 miesiące), Orange (telefon, tylko M2), Tauron (prąd), Młyn (mąka), sklep (nie dla firmy).
    $docs = [
        'KSEF-ORLEN-0001' => ['7740001454', 'ORLEN S.A.', 'FO/1', $m2 . '-05', [['ON EKODIESEL', 'l', 100, 6.0]]],
        'KSEF-ORLEN-0002' => ['7740001454', 'ORLEN S.A.', 'FO/2', $m2 . '-20', [['PB95', 'l', 50, 6.2]]],
        'KSEF-ORANGE-0001' => ['5260215088', 'Orange Polska S.A.', 'OR/9', $m2 . '-10', [['Abonament komórkowy', 'szt', 1, 250.0]]],
        'KSEF-TAURON-0001' => ['5272543000', 'TAURON Sprzedaż sp. z o.o.', 'T/1', $m2 . '-08', [['Energia elektryczna czynna', 'kWh', 4000, 0.75]]],
        'KSEF-MLYN-0001' => ['5260250274', 'Młyn test Sp. z o.o.', 'M/1', $m2 . '-12', [['MĄKA PSZENNA T-65 WOREK 25KG', 'szt', 2, 110.0]]],
        'KSEF-SHOP-0001' => ['1111111111', 'Sklep prywatny', 'P/1', $m2 . '-15', [['Telewizor', 'szt', 1, 2000.0]]],
        'KSEF-ORLEN-0003' => ['7740001454', 'ORLEN S.A.', 'FO/3', $m3 . '-02', [['ON EKODIESEL', 'l', 80, 6.1]]],
    ];
    $xml = function (array $d): string {
        [$nip, $name, $number, $date, $lines] = $d;
        $rows = '';
        $net = 0.0;
        foreach ($lines as $k => [$n, $u, $q, $p]) {
            $v = round($q * $p, 2);
            $net += $v;
            $rows .= '<FaWiersz><NrWierszaFa>' . ($k + 1) . "</NrWierszaFa><P_7>{$n}</P_7><P_8A>{$u}</P_8A><P_8B>{$q}</P_8B><P_9A>{$p}</P_9A><P_11>{$v}</P_11><P_12>23</P_12></FaWiersz>";
        }
        return '<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">'
            . "<Podmiot1><DaneIdentyfikacyjne><NIP>{$nip}</NIP><Nazwa>{$name}</Nazwa></DaneIdentyfikacyjne></Podmiot1>"
            . '<Podmiot2><DaneIdentyfikacyjne><NIP>8971902620</NIP><Nazwa>ATELIER</Nazwa></DaneIdentyfikacyjne></Podmiot2>'
            . "<Fa><KodWaluty>PLN</KodWaluty><P_1>{$date}</P_1><P_2>{$number}</P_2><P_13_1>{$net}</P_13_1><P_15>" . round($net * 1.23, 2) . "</P_15>{$rows}</Fa></Faktura>";
    };

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        PDO::class => $pdo,
        App\Services\Workshop\Supply\KsefInvoiceSource::class => new class ($docs, $xml) implements App\Services\Workshop\Supply\KsefInvoiceSource {
            public function __construct(private array $docs, private $xml) {}
            public function search(string $from, string $to): array { return []; }
            public function xml(string $ksefNumber): string { return ($this->xml)($this->docs[$ksefNumber]); }
        },
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string { throw new RuntimeException('no e-mail in tests'); }
        },
    ]);
    $container = $builder->build();
    $invoices = $container->get(InvoiceService::class);
    $services = $container->get(ServiceCostService::class);
    $workspace = $container->get(FinanceWorkspaceService::class);
    $ctx = new ApiContext('f1', new Translator('pl'), 1, [], ['prod.supply.write' => true, 'prod.supply.read' => true, 'prod.finance.read' => true, 'prod.finance.write' => true]);
    $id = fn(string $number) => (int) $pdo->query("SELECT id FROM n_inv WHERE number = " . $pdo->quote($number))->fetchColumn();
    $row = fn(string $number) => $pdo->query("SELECT * FROM n_inv WHERE number = " . $pdo->quote($number))->fetch();

    // --- Import i lista surowców : podejrzane usługi, liczby przełącznika
    $imp = $invoices->ksefImport(['ksef_numbers' => ['KSEF-ORLEN-0001', 'KSEF-ORLEN-0002', 'KSEF-ORANGE-0001', 'KSEF-TAURON-0001', 'KSEF-MLYN-0001', 'KSEF-SHOP-0001']], $ctx);
    expectFin(count($imp['imported']) === 6 && !$imp['errors'], 'six invoices imported');
    $list = $invoices->list('open', $ctx);
    $guess = array_column(array_map(fn($i) => ['n' => $i['number'], 'g' => $i['service_guess']['code'] ?? null], $list['invoices']), 'g', 'n');
    expectFin($guess['FO/1'] === 'log:FUEL' && $guess['OR/9'] === 'fixed:TELECOM' && $guess['T/1'] === 'media:POWER' && $guess['M/1'] === null && $guess['P/1'] === null, 'service guesses on the list');
    expectFin($list['kind'] === 'goods' && $list['counts']['goods']['open'] === 6 && $list['kpi']['services_suspected'] === 4 && $list['kpi']['open'] === 6, 'counts and tiles');
    expectFin(count(array_filter($list['categories'], fn($c) => $c['code'] === 'fixed:RENT')) === 1 && !array_filter($list['categories'], fn($c) => $c['code'] === 'fixed:UTILITIES'), 'cost categories without utilities');

    // --- Usługa z regułą : druga otwarta faktura Orlenu od razu, kolejna już przy imporcie
    $d = $invoices->detail($id('FO/1'), $ctx);
    expectFin($d['classify']['movable'] && $d['classify']['same_nip_open'] === 1 && $d['classify']['guess']['code'] === 'log:FUEL' && $d['classify']['default_month'] === $m2, 'dialog info: guess, same NIP, month');
    $res = $invoices->classify($id('FO/1'), ['action' => 'service', 'cost_category' => 'log:FUEL', 'rule' => true], $ctx);
    $o1 = $row('FO/1');
    $o2 = $row('FO/2');
    expectFin($o1['kind'] === 'service' && $o1['status'] === 'open' && $o1['cost_category'] === 'log:FUEL' && $o1['service_month'] === $m2 && $o1['id_rule'] !== null, 'Orlen → Finanse (fuel, month of issue)');
    expectFin($res['applied'] === [(int) $o2['id']] && $o2['kind'] === 'service' && $o2['id_rule'] === $o1['id_rule'], 'rule applied to the other open Orlen invoice');
    expectFin((int) $pdo->query("SELECT COUNT(*) FROM n_inv_supplier_rule WHERE seller_nip = '7740001454' AND action = 'service' AND cost_category = 'log:FUEL'")->fetchColumn() === 1, 'rule saved for the NIP');
    $imp3 = $invoices->ksefImport(['ksef_numbers' => ['KSEF-ORLEN-0003']], $ctx);
    $o3 = $row('FO/3');
    expectFin($o3['kind'] === 'service' && $o3['service_month'] === $m3 && $o3['id_rule'] === $o1['id_rule'] && !$imp3['auto'] && $o3['prices_imported_at'] === null, 'next Orlen invoice classified at import, never auto-processed');
    expectFinError(fn() => $invoices->mapLines($id('FO/3'), ['lines' => []], $ctx), 'INVOICE_NOT_GOODS', 'no mapping on a service invoice');
    $goods = $invoices->list('open', $ctx);
    expectFin(!array_filter($goods['invoices'], fn($i) => str_starts_with((string) $i['number'], 'FO/')) && $goods['counts']['service']['open'] === 3, 'Orlen gone from Zaopatrzenie, three services open');
    $svc = $invoices->list('all', $ctx, 'service');
    expectFin(count($svc['invoices']) === 3 && $svc['invoices'][0]['category']['label'] === 'Paliwo' && $svc['invoices'][0]['by_rule'], 'service list with category and rule');

    // Telefon i prąd : bez reguły ; odrzucona faktura prywatna i powrót do zaopatrzenia ; przetworzona zostaje.
    $invoices->classify($id('OR/9'), ['action' => 'service', 'cost_category' => 'fixed:TELECOM'], $ctx);
    $invoices->classify($id('T/1'), ['action' => 'service', 'cost_category' => 'media:POWER', 'service_month' => $m2], $ctx);
    $invoices->classify($id('P/1'), ['action' => 'reject', 'note' => 'Zakup prywatny', 'rule' => true], $ctx);
    expectFin($row('P/1')['status'] === 'rejected' && $row('P/1')['note'] === 'Zakup prywatny' && count($invoices->list('open', $ctx, 'rejected')['invoices']) === 1, 'private invoice rejected');
    $invoices->classify($id('P/1'), ['action' => 'goods', 'rule' => true], $ctx);
    expectFin($row('P/1')['kind'] === 'goods' && $row('P/1')['status'] === 'open' && (int) $pdo->query("SELECT COUNT(*) FROM n_inv_supplier_rule WHERE seller_nip = '1111111111'")->fetchColumn() === 0, 'back to supply, rule removed');
    $invoices->classify($id('P/1'), ['action' => 'reject'], $ctx);
    expectFinError(fn() => $invoices->classify($id('OR/9'), ['action' => 'service'], $ctx), 'VALIDATION_FAILED', 'service needs a category');
    $pdo->exec("UPDATE n_inv SET prices_imported_at = NOW() WHERE number = 'M/1'");
    expectFinError(fn() => $invoices->classify($id('M/1'), ['action' => 'service', 'cost_category' => 'fixed:OTHER'], $ctx), 'INVOICE_PROCESSED', 'processed invoice stays in supply');
    $pdo->exec("UPDATE n_inv SET prices_imported_at = NULL WHERE number = 'M/1'");
    // Mapping zapamiętany wraca po « z powrotem do zaopatrzenia ».
    $ml = $pdo->query("SELECT id FROM n_inv_line WHERE id_inv = {$id('M/1')}")->fetchColumn();
    $invoices->mapLine($id('M/1'), (int) $ml, ['raw_material_id' => 900, 'factor' => 25000, 'remember' => true], $ctx);
    $invoices->classify($id('M/1'), ['action' => 'service', 'cost_category' => 'fixed:OTHER'], $ctx);
    expectFin($pdo->query("SELECT id_raw_material FROM n_inv_line WHERE id = {$ml}")->fetchColumn() === null, 'service: lines unmapped (no prices, no stock)');
    $invoices->classify($id('M/1'), ['action' => 'goods'], $ctx);
    expectFin((int) $pdo->query("SELECT id_raw_material FROM n_inv_line WHERE id = {$ml}")->fetchColumn() === 900, 'back to supply: remembered mapping restored');

    // --- Miesiąc usług M2 : kalkulator z ręcznymi wierszami, zatwierdzenie, zamknięcie
    [$y2, $mm2] = [(int) substr($m2, 0, 4), (int) substr($m2, 5, 2)];
    $pdo->exec("INSERT INTO cost_month (id, year, month, status) VALUES (50, {$y2}, {$mm2}, 'OPEN')");
    $pdo->exec("INSERT INTO cost_month_fixed_cost (month_id, name, amount, category, service_month, posting_month) VALUES (50, 'Internet', 890, 'TELECOM', '{$m2}', '{$m2}'), (50, 'Czynsz', 20000, 'RENT', '{$m2}', '{$m2}')");
    $pdo->exec("INSERT INTO cost_media_month (cost_month_id, media_type_id, amount, note) VALUES (50, 1, 1000, 'ręcznie')");
    $pdo->exec("INSERT INTO cost_month_logistics (month_id, logistics_type_id, amount, note) VALUES (50, 1, 500, 'ręcznie')");
    $view = $services->month($m2, $ctx);
    $byCode = array_column($view['categories'], null, 'code');
    expectFin($byCode['log:FUEL']['count'] === 2 && abs($byCode['log:FUEL']['total'] - 910.0) < 0.01 && $byCode['log:FUEL']['state'] === 'open' && $byCode['log:FUEL']['calc']['manual'] === 500.0, 'fuel: 2 invoices, 910 zł, calculator 500 zł');
    expectFin($byCode['media:POWER']['total'] === 3000.0 && $byCode['fixed:TELECOM']['calc']['manual'] === 890.0 && $view['blockers'] === ['open'] && !$view['can_close'], 'open invoices block the close');
    expectFinError(fn() => $services->close($m2, $ctx), 'SERVICE_MONTH_INCOMPLETE', 'close refused with open invoices');
    $acc = $services->acceptAll($m2, $ctx);
    expectFin($acc['accepted'] === 4 && $acc['can_close'] && $acc['progress']['done'] === 3, 'accept all: month ready');
    $closed = $services->close($m2, $ctx);
    expectFin($closed['closed'] !== null && abs($closed['closed']['total'] - 4160.0) < 0.01 && count($closed['pushed']) === 3, 'month closed, 3 categories pushed (4160 zł)');
    $tel = $pdo->query("SELECT name, amount FROM cost_month_fixed_cost WHERE month_id = 50 AND category = 'TELECOM'")->fetchAll();
    expectFin(count($tel) === 1 && $tel[0]['name'] === 'Faktury : Orange Polska S.A.' && (float) $tel[0]['amount'] === 250.0, 'telecom: invoice row replaces the manual one');
    expectFin((float) $pdo->query("SELECT amount FROM cost_month_fixed_cost WHERE month_id = 50 AND category = 'RENT'")->fetchColumn() === 20000.0, 'rent without invoices untouched');
    expectFin((float) $pdo->query("SELECT amount FROM cost_media_month WHERE cost_month_id = 50 AND media_type_id = 1")->fetchColumn() === 3000.0, 'power: invoice amount');
    $fuel = $pdo->query("SELECT amount, note FROM cost_month_logistics WHERE month_id = 50 AND logistics_type_id = 1")->fetchAll();
    expectFin(count($fuel) === 1 && abs((float) $fuel[0]['amount'] - 910.0) < 0.01 && str_contains($fuel[0]['note'], 'ORLEN'), 'fuel: one row with the invoices');
    expectFinError(fn() => $services->update($id('FO/1'), ['cost_amount' => 1], $ctx), 'SERVICE_MONTH_CLOSED', 'closed month: no change');
    expectFinError(fn() => $invoices->classify($id('OR/9'), ['action' => 'goods'], $ctx), 'SERVICE_MONTH_CLOSED', 'closed month: no reclassification');
    $chips = array_column($services->month($m3, $ctx)['months'], null, 'month');
    expectFin($chips[$m2]['closed'] && !$chips[$m3]['closed'] && $chips[$m3]['current'] && !$chips[$m3]['over'] && count($chips) === 6, 'chips: closed month, running month');

    // Otwarcie : ręczne wiersze wracają ; zamknięty kalkulator blokuje.
    $pdo->exec("UPDATE cost_month SET status = 'LOCKED' WHERE id = 50");
    expectFinError(fn() => $services->reopen($m2, $ctx), 'COST_MONTH_LOCKED', 'locked cost month blocks reopening');
    $pdo->exec("UPDATE cost_month SET status = 'OPEN' WHERE id = 50");
    $services->reopen($m2, $ctx);
    $tel = $pdo->query("SELECT name, amount FROM cost_month_fixed_cost WHERE month_id = 50 AND category = 'TELECOM'")->fetchAll();
    expectFin(count($tel) === 1 && $tel[0]['name'] === 'Internet' && (float) $tel[0]['amount'] === 890.0, 'reopen: manual telecom row restored');
    expectFin((float) $pdo->query("SELECT amount FROM cost_media_month WHERE cost_month_id = 50 AND media_type_id = 1")->fetchColumn() === 1000.0
        && (float) $pdo->query("SELECT amount FROM cost_month_logistics WHERE month_id = 50 AND logistics_type_id = 1")->fetchColumn() === 500.0
        && (int) $pdo->query("SELECT COUNT(*) FROM n_service_month_push")->fetchColumn() === 0, 'reopen: power and fuel restored, nothing left');
    // Zmiana kwoty zatwierdzonej : z powrotem do sprawdzenia.
    $u = $services->update($id('T/1'), ['cost_amount' => 2800], $ctx);
    expectFin($u['status'] === 'open' && $u['cost_amount'] === 2800.0 && $u['cost_amount_set'], 'changed amount → to check again');
    $services->decide($id('T/1'), ['status' => 'accepted'], $ctx);

    // --- Miesiąc M3 : brakuje Orange (był w M2) ; « nie w tym miesiącu » ; miesiąc trwa
    $v3 = $services->month($m3, $ctx);
    $tel3 = array_values(array_filter($v3['categories'], fn($c) => $c['code'] === 'fixed:TELECOM'))[0];
    expectFin($tel3['state'] === 'missing' && $tel3['missing'][0]['seller_name'] === 'Orange Polska S.A.' && $tel3['missing'][0]['prev_amount'] === 250.0, 'Orange expected in M3 (was in M2)');
    expectFin(in_array('missing', $v3['blockers'], true) && in_array('not_over', $v3['blockers'], true), 'missing cost and running month block the close');
    $m0 = date('Y-m', strtotime('first day of -5 month'));
    expectFin(in_array('empty', $services->month($m0, $ctx)['blockers'], true), 'month without service invoices: nothing to close');
    expectFinError(fn() => $services->close($m0, $ctx), 'SERVICE_MONTH_EMPTY', 'empty month refused');
    $v3 = $services->mark(['month' => $m3, 'cost_category' => 'fixed:TELECOM', 'seller_key' => $tel3['missing'][0]['seller_key'], 'none' => true], $ctx);
    $tel3 = array_values(array_filter($v3['categories'], fn($c) => $c['code'] === 'fixed:TELECOM'))[0];
    expectFin($tel3['state'] === 'none' && !$tel3['missing'] && count($tel3['marked']) === 1, '« nie w tym miesiącu » clears the missing cost');
    // Koszt bez KSeF (dostawca zagraniczny) : od razu zatwierdzony ; usunięcie tylko ręcznych.
    $man = $services->create(['seller_name' => 'Hosting BE sprl', 'seller_nip' => 'BE0123456789', 'number' => 'INV-77', 'issue_date' => $m3 . '-03', 'cost_category' => 'fixed:IT',
        'total_net' => 120.5, 'filename' => 'inv.pdf', 'content_base64' => base64_encode("%PDF-1.4\n%test")], $ctx);
    expectFin($man['status'] === 'accepted' && $man['source'] === 'manual' && $man['cost_amount'] === 120.5 && $man['service_month'] === $m3 && $man['file']['mime'] === 'application/pdf', 'manual cost saved with its file');
    expectFinError(fn() => $services->create(['seller_name' => 'Hosting BE sprl', 'seller_nip' => 'BE0123456789', 'number' => 'INV-77', 'issue_date' => $m3 . '-03', 'cost_category' => 'fixed:IT', 'total_net' => 1], $ctx), 'INVOICE_EXISTS', 'duplicate manual cost');
    expectFinError(fn() => $services->delete($id('FO/3'), $ctx), 'SERVICE_NOT_MANUAL', 'KSeF invoice cannot be deleted');
    $services->delete($man['id'], $ctx);
    expectFin($id('INV-77') === 0, 'manual cost deleted');

    // --- Faktury i koszty : przypisania, gotowe, podwyżki
    $invoices->mapLine($id('M/1'), (int) $ml, ['raw_material_id' => null, 'remember' => false], $ctx);
    $w = $workspace->overview($ctx);
    expectFin($w['map']['invoices_total'] === 1 && $w['map']['invoices'][0]['number'] === 'M/1' && $w['map']['invoices'][0]['lines'][0]['suggestion']['raw_material_id'] === 900, 'map: flour line with a suggestion');
    expectFin($w['map']['suspects'] === [] && $w['validate']['services_open'] === 1 && $w['service_month']['month'] === $m2, 'no suspects left, Orlen M3 open in Finanse');
    // Cena mąki 4,40 zł / kg zamiast 4,00 : podwyżka 10 % → skutek na miesiąc z zakupów.
    $invoices->mapLine($id('M/1'), (int) $ml, ['raw_material_id' => 900, 'factor' => 25000, 'remember' => false], $ctx);
    $w = $workspace->overview($ctx);
    $up = $w['price_ups']['items'][0] ?? null;
    expectFin($up !== null && $up['raw_material_id'] === 900 && $up['change_pct'] > 9 && !$up['small'] && $up['monthly_extra'] > 0 && $w['map']['invoices_total'] === 0, 'price up with monthly impact, nothing left to map');
    expectFin($invoices->pendingPriceUps() !== [] && !array_filter($invoices->pendingPriceUps(), fn($p) => $p['number'] === 'FO/3'), 'pending price ups only on supply invoices');

    echo "finance_costs_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
