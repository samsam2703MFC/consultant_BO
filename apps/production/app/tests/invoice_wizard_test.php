<?php
declare(strict_types=1);
/*
 * Kreator faktur [217] (Zaopatrzenie › Faktury › Kreator) : wszystkie otwarte faktury w jednej tabeli, linie sprawdzane jedna
 * po drugiej — stany linii (todo : nowa nazwa / cena / inne, auto, checked, disputed), dostawca do wybrania, sprawdzenie z
 * przypisaniem (zapamiętane i zastosowane w innej fakturze tego NIP), podwyżka wymaga decyzji (bez niej nic nie zapisane),
 * nowe przypisanie kasuje sprawdzenie, « bez ryzyka » (tylko cena niższa), sporna / cofnięcie, zatwierdzenie gotowych
 * (ceny, przyjęcie bez zamówienia, akceptacja), faktura niegotowa i zamknięta odrzucone.
 *
 *   php tests/invoice_wizard_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_invoice_wizard_test est recréée puis supprimée.
 * KSeF jamais appelé : source simulée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Supply\InvoiceService;

function expectWiz(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectWizError(callable $fn, string $code, string $message): void
{
    try { $fn(); } catch (ApiException $e) { expectWiz($e->errorCode() === $code, $message . ' (got ' . $e->errorCode() . ')'); return; }
    expectWiz(false, $message . ' (no error)');
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_invoice_wizard_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "invoice_wizard_test: SKIPPED (no database: {$e->getMessage()})\n";
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
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES (1, 'Ola', 'F', 'Ola F.', 'o@example.invalid', '1', 0, 'ola', 'x')");
    $pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
    (new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb'))->apply();
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g')");
    $pdo->exec("INSERT INTO supplier (id, name, email, is_active) VALUES (7, 'Młyn test', 'mlyn@example.invalid', 1), (8, 'Hurtownia inna', 'h@example.invalid', 1)");
    // Mąka 4 zł/kg (worek 25 kg), słód 5,20 zł/kg, masło 22 zł/kg — ceny bazowe za gram.
    $pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit, package_size_in_base_unit, id_current_supplier) VALUES
        (900, 'Mąka pszenna T-65', 1, 0.004, 25000, 7), (901, 'Słód jęczmienny palony', 1, 0.0052, 1000, 7), (902, 'Masło 82 %', 1, 0.022, 1000, 7)");

    $docs = [
        // M/1 : mąka (cena bazowa), słód (+11,5 % — podwyżka), masło (−18 % — tylko cena niższa).
        'KSEF-MLYN-0001' => ['5260250274', 'Młyn test Sp. z o.o.', 'M/1', date('Y-m-d', strtotime('-3 days')),
            [['MĄKA PSZENNA T-65 WOREK 25KG', 'szt', 2, 100.0], ['SŁÓD JĘCZMIENNY PALONY 10KG', 'worek', 5, 58.0], ['MASŁO EXTRA 82% BLOK', 'kg', 10, 18.0]]],
        // M/2 : ta sama mąka — przypisanie z M/1 działa od razu.
        'KSEF-MLYN-0002' => ['5260250274', 'Młyn test Sp. z o.o.', 'M/2', date('Y-m-d', strtotime('-2 days')), [['MĄKA PSZENNA T-65 WOREK 25KG', 'szt', 1, 100.0]]],
        // H/1 : inny dostawca (inny NIP), ta sama nazwa mąki — podpowiedź « znana nazwa » z przelicznikiem.
        'KSEF-INNY-0001' => ['1112223334', 'Hurtownia inna', 'H/1', date('Y-m-d'), [['MĄKA PSZENNA T-65 WOREK 25KG', 'szt', 1, 99.0]]],
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
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('no e-mail in tests'); }
        },
    ]);
    $c = $builder->build();
    $svc = $c->get(InvoiceService::class);
    $ctx = new ApiContext('w1', new Translator('pl'), 1, [], ['prod.supply.write' => true, 'prod.supply.read' => true]);
    $imp = $svc->ksefImport(['ksef_numbers' => ['KSEF-MLYN-0001', 'KSEF-MLYN-0002']], $ctx);
    expectWiz(count($imp['imported']) === 2 && !$imp['auto'], 'two invoices imported, none auto-processed (unknown names)');
    $inv = fn(array $w, string $number) => array_values(array_filter($w['invoices'], fn($i) => $i['number'] === $number))[0];
    $line = fn(array $i, string $start) => array_values(array_filter($i['lines'], fn($l) => str_starts_with($l['name'], $start)))[0];

    // --- Krok 1 : jedna tabela, wszystko do sprawdzenia (nowe nazwy), brak dostawcy ERP (import rozpoznał go z nazwy — tu zdjęty)
    $pdo->exec('UPDATE n_inv SET id_supplier = NULL, id_po = NULL');
    $w = $svc->wizard($ctx);
    expectWiz($w['totals']['invoices'] === 2 && $w['totals']['lines'] === 4 && $w['totals']['todo'] === 4 && $w['totals']['reasons']['new'] === 4 && $w['totals']['ready'] === 0, 'totals: 2 invoices, 4 new names');
    $m1 = $inv($w, 'M/1');
    expectWiz($m1['supplier'] === null && $m1['supplier_choices'][0]['id'] === 7 && !$m1['wizard_ready'] && $w['last_ksef'] !== null, 'no ERP supplier: Młyn test suggested first');
    expectWiz($line($m1, 'MĄKA')['state'] === 'todo' && $line($m1, 'MĄKA')['reason'] === 'new' && $line($m1, 'MĄKA')['suggestions'][0]['raw_material_id'] === 900, 'flour: new name, suggestion Mąka T-65');
    $svc->update($m1['id'], ['supplier_id' => 7], $ctx);
    $svc->update($inv($w, 'M/2')['id'], ['supplier_id' => 7], $ctx);

    // --- Krok 2 : mąka sprawdzona z przypisaniem → M/2 rozpoznana od razu (ten sam NIP)
    $w = $svc->checkLine($m1['id'], $line($m1, 'MĄKA')['id'], ['action' => 'check', 'raw_material_id' => 900], $ctx);
    $m1 = $inv($w, 'M/1');
    $m2 = $inv($w, 'M/2');
    $flour = $line($m1, 'MĄKA');
    expectWiz($flour['state'] === 'checked' && $flour['check']['by'] === 'Ola F.' && abs($flour['factor'] - 25000) < 1e-6 && $flour['control']['catalog_gap_pct'] == 0.0, 'flour checked, factor 25 kg from the name, base price');
    expectWiz($m2['lines'][0]['state'] === 'auto' && $m2['wizard_ready'] && $w['totals']['ready'] === 1, 'M/2: remembered name applied at once → auto, ready');
    // Słód : podwyżka ujawniona przez przypisanie — przypisanie zostaje, linia czeka na decyzję o cenie (bez sprawdzenia).
    $malt = $line($m1, 'SŁÓD');
    expectWizError(fn() => $svc->checkLine($m1['id'], $malt['id'], ['action' => 'check', 'raw_material_id' => 901], $ctx), 'INVOICE_PRICE_UP_PENDING', 'price up needs a decision');
    $malt = $line($inv($svc->wizard($ctx), 'M/1'), 'SŁÓD');
    expectWiz($malt['material']['id'] === 901 && $malt['state'] === 'todo' && $malt['reason'] === 'price' && $malt['check'] === null && $malt['up']['decision'] === null, 'malt mapped, waiting for the price decision');
    $w = $svc->checkLine($m1['id'], $malt['id'], ['action' => 'check', 'price' => 'accept'], $ctx);
    $malt = $line($inv($w, 'M/1'), 'SŁÓD');
    expectWiz($malt['state'] === 'checked' && $malt['up']['decision'] === 'accept' && abs($malt['factor'] - 10000) < 1e-6 && $malt['control']['catalog_gap_pct'] > 11, 'malt: 10 kg sack, +11.5 %, new base price accepted');
    // Nowe przypisanie (inny przelicznik) kasuje sprawdzenie i decyzję ; ponowne sprawdzenie.
    $svc->mapLine($m1['id'], $malt['id'], ['raw_material_id' => 901, 'factor' => 10000, 'remember' => false], $ctx);
    $malt = $line($inv($svc->wizard($ctx), 'M/1'), 'SŁÓD');
    expectWiz($malt['state'] === 'todo' && $malt['reason'] === 'price' && $malt['check'] === null, 'new mapping resets the check and the decision');
    $w = $svc->checkLine($m1['id'], $malt['id'], ['action' => 'check', 'price' => 'accept'], $ctx);
    expectWiz($line($inv($w, 'M/1'), 'SŁÓD')['state'] === 'checked', 'malt checked again');
    // Masło : tylko cena niższa (−18 %) → « bez ryzyka ».
    $butter = $line($inv($w, 'M/1'), 'MASŁO');
    $svc->mapLine($m1['id'], $butter['id'], ['raw_material_id' => 902, 'remember' => false], $ctx);
    $w = $svc->wizard($ctx);
    $butter = $line($inv($w, 'M/1'), 'MASŁO');
    expectWiz($butter['state'] === 'todo' && $butter['reason'] === 'other' && $butter['safe'] && $w['totals']['safe'] === 1 && in_array('price_jump', $butter['control']['flags'], true), 'butter: cheaper only → safe');
    $w = $svc->checkSafe($ctx);
    $m1 = $inv($w, 'M/1');
    expectWiz($w['checked'] === 1 && $line($m1, 'MASŁO')['state'] === 'checked' && $m1['wizard_ready'] && $w['totals']['todo'] === 0 && $w['totals']['ready'] === 2, 'safe lines checked: both invoices ready');
    expectWiz($m1['plan']['receive'] === 'invoice' && count($m1['plan']['prices']) === 2 && $m1['warnings'] === ['price_jump'], 'plan: receive from the invoice, malt and butter prices ; butter price jump shown in step 3');

    // Sporna i cofnięcie.
    $w = $svc->checkLine($m2['id'], $m2['lines'][0]['id'], ['action' => 'dispute', 'note' => 'brak worka'], $ctx);
    $m2 = $inv($w, 'M/2');
    expectWiz($m2['lines'][0]['state'] === 'disputed' && $m2['lines'][0]['check']['note'] === 'brak worka' && !$m2['wizard_ready'] && $w['totals']['disputed'] === 1, 'disputed line: invoice not ready');
    $r = $svc->processChecked(['ids' => [$m2['id']]], $ctx);
    expectWiz(!$r['processed'] && $r['errors'][0]['code'] === 'INVOICE_NOT_CHECKED', 'disputed invoice not processed');
    $w = $svc->checkLine($m2['id'], $m2['lines'][0]['id'], ['action' => 'reset'], $ctx);
    expectWiz($inv($w, 'M/2')['lines'][0]['state'] === 'auto' && $inv($w, 'M/2')['wizard_ready'], 'reset: auto again, ready');
    expectWizError(fn() => $svc->checkLine($m2['id'], $m2['lines'][0]['id'], ['action' => 'nope'], $ctx), 'VALIDATION_FAILED', 'unknown action');

    // --- Krok 3 : zatwierdzenie (ceny, przyjęcie z faktury, akceptacja)
    $r = $svc->processChecked(['ids' => [$m1['id'], $m2['id']]], $ctx);
    expectWiz(count($r['processed']) === 2 && !$r['errors'] && $r['totals']['invoices'] === 0, 'both processed, wizard empty');
    $p1 = array_values(array_filter($r['processed'], fn($x) => $x['id'] === $m1['id']))[0];
    expectWiz($p1['accepted'] && $p1['stock'] && $p1['prices'] >= 1, 'M/1 accepted, received, prices imported');
    expectWiz(abs((float) $pdo->query('SELECT price_per_unit FROM raw_material WHERE id = 901')->fetchColumn() - 0.0058) < 0.00001, 'malt base price 5.80 zł/kg');
    expectWiz((float) $pdo->query('SELECT price_per_unit FROM raw_material WHERE id = 902')->fetchColumn() >= 0.022 - 1e-9, 'butter: lower price held (base price never drops)');
    expectWiz((int) $pdo->query("SELECT COUNT(*) FROM n_inv WHERE status = 'accepted' AND id_po IS NOT NULL")->fetchColumn() === 2, 'both accepted with a receipt order from the invoice');
    $r = $svc->processChecked(['ids' => [$m1['id']]], $ctx);
    expectWiz($r['errors'][0]['code'] === 'INVOICE_CLOSED', 'closed invoice refused');
    expectWizError(fn() => $svc->processChecked(['ids' => []], $ctx), 'VALIDATION_FAILED', 'ids required');
    expectWizError(fn() => $svc->checkLine($m1['id'], $flour['id'], ['action' => 'check'], $ctx), 'INVOICE_CLOSED', 'no check on an accepted invoice');

    // Nazwa zapamiętana u Młyna : u innego dostawcy pierwsza podpowiedź « znana nazwa » (z przelicznikiem 25 kg).
    $svc->ksefImport(['ksef_numbers' => ['KSEF-INNY-0001']], $ctx);
    $h1 = $inv($svc->wizard($ctx), 'H/1');
    $sug = $h1['lines'][0]['suggestions'][0];
    expectWiz($h1['lines'][0]['state'] === 'todo' && $sug['raw_material_id'] === 900 && $sug['known'] && abs($sug['factor'] - 25000) < 1e-6 && $sug['score'] <= 1.0, 'other supplier: known name suggested first');

    echo "invoice_wizard_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
