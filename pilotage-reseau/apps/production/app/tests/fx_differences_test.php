<?php
/**
 * [438–442] Różnice kursowe (Finanse) : kursy NBP (tabela A) avec un faux client HTTP — aucun appel réseau — (jour férié
 * 11.11.2026 → 10.11, week-end → vendredi, jour inconnu demandé une fois, 404 « Brak danych », panne NBP), réglage de
 * l'adresse ; wyciągi ING (PDF chiffré comme les potwierdzenia wpłat, format ING pas encore pris en charge, doublon, autre
 * fichier refusé, lecteur injecté : lignes lues, payeur → klient) ; wpłaty saisies à la main ; dopasowanie (numéro dans le
 * titre, numéros courts « FV 0150 + 0151 », klient + montant, « do potwierdzenia » avec candidates) ; les quatre cas de la
 * maquette (przedpłata avant facture sur compte EUR, paiement après facture sur compte EUR, compte PLN au kurs ING, un
 * virement pour deux factures) aux chiffres exacts, « czeka na fakturę », totaux +39,31 / −91,98 / saldo −52,67 ;
 * zestawienie PDF (pdftotext) et CSV ; confirmation d'une candidate ; facture émise après la przedpłata ; droit propre
 * (catalogue, routes, migration 257, contrôleur réel : 403 sans droit, montants seulement Finanse) ; migrations 256 et 257
 * rejouées deux fois. Données fictives.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_fx_differences_test recréée (tables minimales + migrations 256 et 257), supprimée à la fin (FX_JSON_OUT=fichier :
 * données de la page pour un aperçu ; FX_PDF_OUT=fichier : le PDF du mois). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Finances\{FxDifferenceRepository, FxRateRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Finances\Fx\{BankStatementParser, BankStatementService, FxDifferenceService, IngStatementParser, NbpHttpClient, NbpRateService, StatementFormatNotSupported};
use App\Services\Finances\PaymentProof\PaymentProofCipher;
use App\Services\Utils\TCPDFService;
use App\Services\Workshop\Migration\MigrationRunner;

$db = 'erp_fx_differences_test';
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("DROP DATABASE IF EXISTS {$db}"); $pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); $pdo->exec("USE {$db}");
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL)',
    'CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(120), name VARCHAR(80) NULL, surname VARCHAR(80) NULL, default_currency VARCHAR(3) NULL)',
    'CREATE TABLE document_wdt (id INT PRIMARY KEY, doc_num VARCHAR(40), id_customer INT, established_date DATE NULL, invoiced_date DATE NULL, currency VARCHAR(3) NULL)',
    'CREATE TABLE document_wdt_product (id INT AUTO_INCREMENT PRIMARY KEY, id_document_wdt INT, quantity DECIMAL(10,2), price_carton DECIMAL(12,2), total_net DECIMAL(12,2) NULL)',
    'CREATE TABLE n_wdt_superseded (id_document_wdt INT PRIMARY KEY, superseded_by INT NOT NULL, reason VARCHAR(255) NOT NULL)',
    'CREATE TABLE document_proforma (id INT PRIMARY KEY, doc_num VARCHAR(40), established_date DATE NULL, id_customer INT, status VARCHAR(20) NULL)',
    'CREATE TABLE document_proforma_product (id INT AUTO_INCREMENT PRIMARY KEY, id_proforma INT, total_price_net DECIMAL(12,2))',
    'CREATE TABLE n_proforma_payment (id INT AUTO_INCREMENT PRIMARY KEY, id_proforma INT NOT NULL, paid_on DATE NOT NULL, amount DECIMAL(12,2) NULL, recorded_by INT NULL, recorded_at DATETIME NOT NULL, UNIQUE KEY (id_proforma))',
    'CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, id_proforma INT NULL, id_wdt INT NULL)',
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(120), name_fr VARCHAR(120) NULL, description TEXT NULL, kind VARCHAR(16) DEFAULT 'erp', id_group INT NULL, id_reports_to INT NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO n_position (id, code, name_pl) VALUES (11, 'KIEROWNIK_OPERACYJNY', 'Kierownik operacyjny'), (12, 'LOGISTYKA', 'Logistyka'), (13, 'ADMINISTRACJA', 'Administracja')");
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (11, 'liquidity'), (12, 'payments_day'), (13, 'payments_day'), (13, 'liquidity')");
$migrate = function () use ($pdo) {
    foreach (['256_fx_differences.sql', '257_fx_differences_access.sql', '258_fx_differences_operations.sql'] as $f) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
};
$migrate(); $migrate();

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migrations 256 et 257 (deux passages)
$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_fx_rate', 'n_fx_rate_day', 'n_bank_statement', 'n_bank_statement_line', 'n_fx_allocation') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$params = $pdo->query("SELECT code, value, value_type FROM n_prod_param WHERE code LIKE 'fx.%'")->fetchAll();
$check(count($tables) === 5 && count($params) === 1 && $params[0]['code'] === 'fx.nbp_api_url' && $params[0]['value_type'] === 'string', 'migration 256 replayed: five tables, one setting fx.nbp_api_url (string)');
$acc = $pdo->query("SELECT id_position, GROUP_CONCAT(page_key ORDER BY page_key) k FROM n_page_access GROUP BY id_position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($acc === [11 => 'fx_differences,liquidity', 12 => 'payments_day', 13 => 'fx_differences,liquidity,payments_day'], 'migrations 257 + 258 replayed: « fx_differences » for Finanse (ADMINISTRACJA) and [443] Kierownik operacyjny, not Logistyka');

// --- Faux NBP : tables A des jours ouvrés (11.11.2026 férié), cours de la maquette, compteur d'appels ; aucune requête réseau.
$fake = new class implements NbpHttpClient {
    public array $mid = ['2026-11-02' => 4.2600, '2026-11-04' => 4.2450, '2026-11-09' => 4.2310, '2026-11-10' => 4.2380, '2026-11-12' => 4.2290, '2026-11-13' => 4.2260,
        '2026-11-17' => 4.2210, '2026-11-19' => 4.2350, '2026-11-20' => 4.2380, '2026-11-23' => 4.2410, '2026-11-24' => 4.2500];
    public array $holidays = ['2026-11-01', '2026-11-11', '2026-12-25', '2026-12-26'];
    public array $calls = [];
    public bool $down = false;
    public ?string $today = null;
    public function get(string $url): array
    {
        $this->calls[] = $url;
        if ($this->down) throw new RuntimeException('offline');
        if (!preg_match('#/exchangerates/tables/A/(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})/\?format=json$#', $url, $m)) return [400, 'Bad Request'];
        $out = [];
        for ($d = $m[1], $n = 200; $d <= $m[2]; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ((int) date('N', strtotime($d)) >= 6 || in_array($d, $this->holidays, true) || ($this->today !== null && $d >= $this->today)) continue;
            $no = (int) date('z', strtotime($d)) - 85;
            $out[] = ['table' => 'A', 'no' => $no . '/A/NBP/2026', 'effectiveDate' => $d, 'rates' => [
                ['currency' => 'euro', 'code' => 'EUR', 'mid' => $this->mid[$d] ?? 4.2500], ['currency' => 'funt szterling', 'code' => 'GBP', 'mid' => 4.9000], ['currency' => 'forint', 'code' => 'HUF', 'mid' => 0.010712]]];
        }
        return $out ? [200, json_encode($out)] : [404, '404 NotFound - Not Found - Brak danych'];
    }
};
$fake->today = '2026-11-25';
$today = '2026-11-25';
$paramsRepo = new ParamRepository($pdo);
$nbp = new NbpRateService(new FxRateRepository($pdo), $fake, $paramsRepo);
$check($nbp->baseUrl() === 'https://api.nbp.pl/api' && $nbp->host() === 'api.nbp.pl' && NbpRateService::tableLabel('227/A/NBP/2026') === 'A 227/2026', 'NBP: address from the setting, host, table label');

$r = $nbp->rateBefore('EUR', '2026-11-12', true, $today);
$check($r !== null && $r['date'] === '2026-11-10' && abs($r['mid'] - 4.2380) < 1e-9 && count($fake->calls) === 1, 'NBP: 12.11 → 10.11 (11.11 holiday skipped), one request for the unknown days');
$r = $nbp->rateBefore('EUR', '2026-11-23', true, $today);
$check($r !== null && $r['date'] === '2026-11-20' && abs($r['mid'] - 4.2380) < 1e-9, 'NBP: Monday 23.11 → Friday 20.11 (weekend)');
$r = $nbp->rateBefore('EUR', '2026-11-16', true, $today);
$check($r['date'] === '2026-11-13' && count($fake->calls) <= 2, 'NBP: Monday 16.11 → Friday 13.11, known days not asked again');
$days = $pdo->query("SELECT day, has_table FROM n_fx_rate_day WHERE day IN ('2026-11-11', '2026-11-14', '2026-11-10')")->fetchAll(PDO::FETCH_KEY_PAIR);
$check($days === ['2026-11-10' => 1, '2026-11-11' => 0, '2026-11-14' => 0], 'NBP: days stored with or without a table (holiday, weekend)');
$r = $nbp->rateBefore('HUF', '2026-11-12', false);
$check($r !== null && abs($r['mid'] - 0.010712) < 1e-9 && $nbp->rateBefore('XXX', '2026-11-12', false) === null, 'NBP: every currency of table A stored (small units kept), unknown currency → null');
$calls = count($fake->calls);
$res = $nbp->fetchRange('2026-11-14', '2026-11-15', $today);
$check($res['tables'] === 0 && $res['days'] === 2 && count($fake->calls) === $calls + 1, 'NBP: 404 « Brak danych » (weekend only) → no table, two days noted');
$res = $nbp->refresh($today, 3);
$check($res['tables'] === 2 && (int) $pdo->query("SELECT COUNT(*) FROM n_fx_rate_day WHERE day = '2026-11-25'")->fetchColumn() === 0, 'NBP cron: last days fetched, today without table not noted (published around noon)');
$fake->down = true;
$nbp2 = new NbpRateService(new FxRateRepository($pdo), $fake, $paramsRepo);
$check($nbp2->ensure('2026-09-01', '2026-09-10', $today) === false && $nbp2->lastError() === 'NBP niedostępny.' && $nbp2->rateBefore('EUR', '2026-09-08', false) === null, 'NBP down: ensure() false, message, no rate (no guess)');
$fake->down = false;
$paramsRepo->setValue('fx.nbp_api_url', '', null);
$check(str_contains($err(fn() => (new NbpRateService(new FxRateRepository($pdo), $fake, $paramsRepo))->fetchRange('2026-09-01', '2026-09-02', $today)), 'fx.nbp_api_url'), 'NBP: empty setting → clear message, no request');
$paramsRepo->setValue('fx.nbp_api_url', 'https://api.nbp.pl/api/', null);

// --- Fonctions pures
$check(FxDifferenceService::diff(980, 4.2600, 4.2310) === 28.42 && FxDifferenceService::diff(2400, 4.2210, 4.2450) === -57.6 && FxDifferenceService::diff(1250, 4.2105, 4.2380) === -34.38
    && FxDifferenceService::diff(900, 4.2350, 4.2290) === 5.4 && FxDifferenceService::diff(610, 4.2350, 4.2260) === 5.49 && FxDifferenceService::diff(0.5, 4.25, 4.24) === 0.01
    && FxDifferenceService::diff(0.5, 4.24, 4.25) === -0.01, 'diff: mockup amounts, half away from zero (−34,375 → −34,38), integer arithmetic');
$check(BankStatementService::norm('FV/2026/11/0088 — zapłata') === 'FV2026110088ZAPATA' && BankStatementService::inTitle('PF202611012PRZEDPATA', 'PF/2026/11/012')
    && !BankStatementService::inTitle('PF202611012PRZEDPATA', 'PF/2026/11/01') && !BankStatementService::inTitle('X1234567', '123456') && BankStatementService::inTitle('ZAPLATA1234567', '1234567')
    && !BankStatementService::inTitle('FV1', 'FV/1'), 'title: normalized numbers, no digit glued before/after, 6 characters at least');
$check(BankStatementService::shortRefs('FV 0150 + 0151 — jeden przelew 2026') === ['150', '151'] && BankStatementService::lastGroup('FV/2026/11/0150') === '150'
    && BankStatementService::lastGroup('ABC') === null, 'title: short invoice numbers (years skipped), last digit group of a number');
$check(BankStatementService::words('Sklep Testowy A SPRL') === ['sklep', 'testowy', 'a'] && BankStatementService::num('1 250,00') === 1250.0 && BankStatementService::num('4.2105') === 4.2105
    && BankStatementService::num('1.250,50') === 1250.5 && BankStatementService::num('') === null, 'words without legal forms ; numbers « 1 250,00 », « 1.250,50 »');
[$rows, $rest] = BankStatementService::spread(1510, [['kind' => 'wdt', 'id' => 1, 'open' => 900], ['kind' => 'wdt', 'id' => 2, 'open' => 610]], 'number');
[$rows2, $rest2] = BankStatementService::spread(500, [['kind' => 'wdt', 'id' => 1, 'open' => 900]], 'number');
[$rows3, $rest3] = BankStatementService::spread(1000, [['kind' => 'wdt', 'id' => 1, 'open' => 900]], 'number');
$check(array_column($rows, 'amount') === [900.0, 610.0] && $rest === 0.0 && array_column($rows2, 'amount') === [500.0] && $rest2 === 0.0 && array_column($rows3, 'amount') === [900.0] && $rest3 === 100.0,
    'spread: one transfer for two invoices, partial payment, surplus left');
$check(FxDifferenceService::month('2026-10', $today) === '2026-10' && FxDifferenceService::month(null, $today) === '2026-11' && FxDifferenceService::month('2027-01', $today) === '2026-11'
    && str_starts_with($err(fn() => FxDifferenceService::month('2026-13', $today)), 'custom:422'), 'month: default current, future capped, format checked');
$check(FxDifferenceService::signed(-91.98) === '−91,98' && FxDifferenceService::signed(39.31) === '+39,31' && FxDifferenceService::statementLabel(['account_currency' => 'EUR', 'period_from' => '2026-11-01', 'period_to' => '2026-11-15']) === 'Konto EUR · 01–15.11'
    && FxDifferenceService::statementLabel(['account_currency' => 'PLN', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30']) === 'Konto PLN · listopad', 'labels: signed amounts, statements');

// --- Données fictives (maquette) : 5 clients EUR, factures (WDT) et proformas de novembre 2026
$pdo->exec("INSERT INTO customer (id, company, default_currency) VALUES (1, 'Sklep Testowy A', 'EUR'), (2, 'Bistro Test B', 'EUR'), (3, 'Sklep Testowy C', 'EUR'),
    (4, 'Restauracja Test D', 'EUR'), (5, 'Kawiarnia Test E', 'EUR'), (6, 'Hurtownia PL', 'PLN')");
$wdt = function (int $id, string $num, int $cust, string $date, float $amount) use ($pdo) {
    $pdo->prepare('INSERT INTO document_wdt VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $num, $cust, $date, $date, 'EUR']);
    $pdo->prepare('INSERT INTO document_wdt_product (id_document_wdt, quantity, price_carton, total_net) VALUES (?, 10, ?, ?), (?, 1, ?, ?)')->execute([$id, $amount * 0.06, $amount * 0.6, $id, $amount * 0.4, $amount * 0.4]);
};
$pf = function (int $id, string $num, int $cust, string $date, float $amount, string $status = 'issued') use ($pdo) {
    $pdo->prepare('INSERT INTO document_proforma VALUES (?, ?, ?, ?, ?)')->execute([$id, $num, $date, $cust, $status]);
    $pdo->prepare('INSERT INTO document_proforma_product (id_proforma, total_price_net) VALUES (?, ?)')->execute([$id, $amount]);
};
$wdt(88, 'FV/2026/11/0088', 1, '2026-11-10', 980);
$wdt(142, 'FV/2026/11/0142', 2, '2026-11-05', 2400);
$wdt(120, 'FV/2026/11/0120', 3, '2026-11-12', 1250);
$wdt(150, 'FV/2026/11/0150', 1, '2026-11-13', 900);
$wdt(151, 'FV/2026/11/0151', 1, '2026-11-14', 610);
$wdt(160, 'FV/2026/11/0160', 4, '2026-11-20', 640);
$wdt(170, 'FV/2026/10/0012', 6, '2026-10-12', 640);       // klient PLN : jamais candidate d'une wpłata EUR
$pf(12, 'PF/2026/11/012', 1, '2026-10-30', 980, 'paid');
$pf(31, 'PF/2026/11/031', 4, '2026-11-19', 640);
$pf(35, 'PF/2026/11/035', 5, '2026-11-20', 315.40, 'paid');
$pf(36, 'PF/2026/11/036', 5, '2026-11-20', 99, 'cancelled');
$pdo->exec("INSERT INTO customer_order VALUES (501, 1, 12, 88), (502, 4, 31, NULL), (503, 5, 35, NULL)");
$pdo->exec("INSERT INTO n_proforma_payment (id_proforma, paid_on, amount, recorded_at) VALUES (12, '2026-11-03', 980, '2026-11-03 10:00:00'), (35, '2026-11-24', 315.40, '2026-11-24 10:00:00')");
$sum = fn() => json_encode($pdo->query('CHECKSUM TABLE customer, document_wdt, document_wdt_product, document_proforma, document_proforma_product, n_proforma_payment, customer_order')->fetchAll());
$before = $sum();

$config = new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48)]);
$cipher = new PaymentProofCipher($config);
$repo = new FxDifferenceRepository($pdo);
$parser = new class implements BankStatementParser {
    public array $lines = [];
    public function name(): string { return 'test'; }
    public function header(string $text): array { return []; }
    public function parse(string $text): array { if (!str_contains((string) preg_replace('/\s+/', ' ', $text), 'WYCIAG TESTOWY')) throw new StatementFormatNotSupported('nieznany format'); return $this->lines; }
};
$ing = new BankStatementService($repo, $cipher, new IngStatementParser());
$stParsed = new BankStatementService($repo, $cipher, $parser);
$fx = new FxDifferenceService($repo, $ing, $nbp);
$makePdf = function (string $text): string { $p = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false); $p->setPrintHeader(false); $p->setPrintFooter(false); $p->AddPage(); $p->SetFont('dejavusans', '', 10); $p->MultiCell(0, 0, $text); return $p->Output('', 'S'); };

// --- Wyciągi : ING (texte non reconnu), lecteur de test (lignes lues), doublon, autre fichier
$pdfIng = $makePdf("ING Bank Śląski\nWyciąg z rachunku EUR 16.11.2026 – 30.11.2026\nUznanie 2 400,00 EUR Bistro Test B FV/2026/11/0142");
$u2 = $ing->upload($pdfIng, 'ING_EUR_16-30.11.pdf', ['account' => 'EUR', 'period_from' => '2026-11-16', 'period_to' => '2026-11-30'], 7, $today);
$row = $pdo->query("SELECT * FROM n_bank_statement WHERE id = {$u2['id']}")->fetch();
$check($u2['status'] === 'unsupported' && $u2['message'] === IngStatementParser::MESSAGE && $u2['lines'] === 0 && $row['parser'] === 'ing' && $row['parse_status'] === 'unsupported',
    'ING statement: stored, format « not yet supported » with a clear message, lines to type by hand');
$check(str_starts_with((string) $row['content'], 'p1:') && !str_contains((string) $row['content'], 'Bistro') && str_starts_with((string) $row['text_enc'], 'p1:')
    && $ing->file($u2['id'])['content'] === $pdfIng && $row['content_sha256'] === hash('sha256', $pdfIng), 'statement file and read text encrypted (p1:, like payment proofs), decrypted on download');
$check(str_starts_with($err(fn() => $ing->upload($pdfIng, 'x.pdf', ['account' => 'EUR'], 7, $today)), 'custom:409') && str_starts_with($err(fn() => $ing->upload('GIF89a', 'x.gif', ['account' => 'EUR'], 7, $today)), 'custom:422')
    && str_starts_with($err(fn() => $ing->upload($makePdf('inny'), 'x.pdf', ['account' => 'USD'], 7, $today)), 'custom:422'), 'statement: same file twice → 409, not a PDF → 422, account EUR / PLN only');
$u3 = $ing->upload($makePdf("ING Bank Śląski\nWyciąg z rachunku PLN listopad 2026\nUznanie 5 263,13 PLN (1 250,00 EUR kurs 4,2105)"), 'ING_PLN_11.pdf', ['account' => 'PLN', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'], 7, $today);
$parser->lines = [
    ['booked_on' => '2026-11-03', 'amount' => 980.0, 'currency' => 'EUR', 'amount_pln' => null, 'bank_rate' => null, 'payer' => 'SKLEP TESTOWY A SPRL', 'title' => 'PF/2026/11/012 — przedpłata', 'incoming' => true],
    ['booked_on' => '2026-11-04', 'amount' => 1200.0, 'currency' => 'EUR', 'amount_pln' => null, 'bank_rate' => null, 'payer' => 'Dostawca', 'title' => 'Faktura zakupowa', 'incoming' => false],
    ['booked_on' => '2026-11-05', 'amount' => 12.0, 'currency' => 'PLN', 'amount_pln' => null, 'bank_rate' => null, 'payer' => 'ING', 'title' => 'Opłata', 'incoming' => true],
];
$u1 = $stParsed->upload($makePdf("WYCIAG TESTOWY EUR 01.11.2026 - 15.11.2026"), 'ING_EUR_01-15.11.pdf', ['account' => 'EUR', 'period_from' => '2026-11-01', 'period_to' => '2026-11-15'], 7, $today);
$l1 = $pdo->query("SELECT * FROM n_bank_statement_line WHERE id_statement = {$u1['id']}")->fetchAll();
$check($u1['status'] === 'parsed' && $u1['lines'] === 1 && $u1['other'] === 2 && count($l1) === 1 && $l1[0]['source'] === 'pdf' && (int) $l1[0]['id_customer'] === 1
    && $l1[0]['match_state'] === 'matched' && str_starts_with((string) $l1[0]['payer_enc'], 'p1:') && !str_contains((string) $l1[0]['title_enc'], 'PF/'),
    'parser injected: customer credits in currency kept (other lines counted), payer and title encrypted, proforma number in the title → customer and match');

// --- Wpłaty saisies à la main (relevés ING pas encore lus)
$save = fn(array $in) => $ing->saveLine(null, $in, 7, $today);
$id2 = $save(['account' => 'EUR', 'id_statement' => $u2['id'], 'booked_on' => '2026-11-18', 'amount' => '2 400,00', 'currency' => 'EUR', 'id_customer' => 2, 'payer' => 'Bistro Test B', 'title' => 'FV/2026/11/0142']);
$id3 = $save(['account' => 'PLN', 'id_statement' => $u3['id'], 'booked_on' => '2026-11-20', 'amount' => '1250', 'currency' => 'EUR', 'bank_rate' => '4,2105', 'id_customer' => 3, 'title' => 'FV/2026/11/0120']);
// Maquette : « 21.11 · NBP 20.11 = 4,2350 » contredit « 20.11 = 4,2380 » (ligne 23.11 et panneau des kursy) ; ici la wpłata est du
// 20.11 et le kurs NBP du 19.11 = 4,2350 : mêmes montants (+5,40 / +5,49), données cohérentes.
$id4 = $save(['account' => 'EUR', 'id_statement' => $u2['id'], 'booked_on' => '2026-11-20', 'amount' => '1510', 'currency' => 'EUR', 'payer' => 'Sklep Testowy A sprl', 'title' => 'FV 0150 + 0151 — jeden przelew']);
$id5 = $save(['account' => 'EUR', 'id_statement' => $u2['id'], 'booked_on' => '2026-11-23', 'amount' => '640', 'currency' => 'EUR', 'id_customer' => 4, 'title' => '« zaliczka »']);
$id6 = $save(['account' => 'EUR', 'id_statement' => $u2['id'], 'booked_on' => '2026-11-24', 'amount' => '315,40', 'currency' => 'EUR', 'id_customer' => 5, 'title' => 'PF/2026/11/035 — przedpłata']);
$line = fn(int $id) => $pdo->query("SELECT * FROM n_bank_statement_line WHERE id = {$id}")->fetch();
$check((float) $line($id3)['amount_pln'] === 5263.13 && (float) $line($id3)['bank_rate'] === 4.2105 && $line($id3)['account_currency'] === 'PLN', 'PLN account: bank rate typed → PLN amount 5 263,13 (1 250 × 4,2105)');
$check((int) $line($id4)['id_customer'] === 1 && $line($id4)['match_state'] === 'matched' && $line($id4)['ref_numbers'] === 'FV/2026/11/0150, FV/2026/11/0151',
    'payer « Sklep Testowy A sprl » → Sklep Testowy A (not Sklep Testowy C) ; « FV 0150 + 0151 » → two invoices of the customer');
$check($line($id5)['match_state'] === 'to_confirm' && $line($id5)['match_note'] === 'brak numeru — 2 dokumenty o tej kwocie', 'no number, two documents of the amount → « do potwierdzenia »');
$check(str_starts_with($err(fn() => $save(['account' => 'PLN', 'booked_on' => '2026-11-20', 'amount' => '10', 'currency' => 'EUR'])), 'custom:422')
    && str_starts_with($err(fn() => $save(['account' => 'EUR', 'booked_on' => '2026-11-26', 'amount' => '10', 'currency' => 'EUR'])), 'custom:422')
    && str_starts_with($err(fn() => $save(['account' => 'EUR', 'booked_on' => '2026-11-20', 'amount' => '10', 'currency' => 'PLN'])), 'custom:422')
    && str_starts_with($err(fn() => $save(['account' => 'EUR', 'id_statement' => $u3['id'], 'booked_on' => '2026-11-20', 'amount' => '10', 'currency' => 'EUR'])), 'custom:422')
    && str_starts_with($err(fn() => $save(['account' => 'EUR', 'booked_on' => '2026-11-20', 'amount' => 'abc', 'currency' => 'EUR'])), 'custom:422'),
    'manual form: PLN account needs the bank rate, no future date, foreign currency only, same account as the statement, numbers checked');
$check($ing->lineView($id4)['payer'] === 'Sklep Testowy A sprl' && $ing->lineView($id5)['title'] === '« zaliczka »', 'correction form: payer and title decrypted');

// --- Mois de novembre 2026 : les quatre cas de la maquette
$d = $fx->data('2026-11', true, $today, 7);
$rows = array_column($d['rows'], null, 'id');
$l1id = (int) $l1[0]['id'];
$p1 = $rows[$l1id]['pieces'][0] ?? null;
$check($rows[$l1id]['state'] === 'counted' && $p1['invoice']['number'] === 'FV/2026/11/0088' && $p1['proforma']['number'] === 'PF/2026/11/012' && $p1['inv_rate']['date'] === '2026-11-09'
    && $rows[$l1id]['pay_rate']['date'] === '2026-11-02' && abs($rows[$l1id]['pay_rate']['rate'] - 4.26) < 1e-9 && $p1['diff'] === 28.42 && $p1['realized'] === '2026-11-10' && $p1['proforma']['pd_paid_on'] === '2026-11-03',
    'case 1 — prepayment 03.11 on PF (EUR account) → invoice FV/0088 of 10.11 : 980 × (4,2600 − 4,2310) = +28,42 (counted on the invoice date, Płatności dnia shown)');
$p2 = $rows[$id2]['pieces'][0];
$check($rows[$id2]['state'] === 'counted' && $p2['inv_rate']['date'] === '2026-11-04' && $rows[$id2]['pay_rate']['date'] === '2026-11-17' && $p2['diff'] === -57.6,
    'case 2 — payment 18.11 after invoice of 05.11 (EUR account) : 2 400 × (4,2210 − 4,2450) = −57,60');
$p3 = $rows[$id3]['pieces'][0];
$check($rows[$id3]['state'] === 'counted' && $rows[$id3]['pay_rate']['source'] === 'bank' && abs($rows[$id3]['pay_rate']['rate'] - 4.2105) < 1e-9 && $p3['inv_rate']['date'] === '2026-11-10'
    && abs($p3['inv_rate']['rate'] - 4.2380) < 1e-9 && $p3['diff'] === -34.38 && $rows[$id3]['amount_pln'] === 5263.13,
    'case 3 — PLN account at the ING rate : 1 250 × (4,2105 − 4,2380) = −34,38 (invoice 12.11 → NBP 10.11, 11.11 holiday)');
$p4 = $rows[$id4]['pieces'];
$check($rows[$id4]['state'] === 'counted' && array_column(array_column($p4, 'invoice'), 'number') === ['FV/2026/11/0150', 'FV/2026/11/0151'] && array_column($p4, 'amount') === [900.0, 610.0]
    && array_column($p4, 'diff') === [5.4, 5.49] && $rows[$id4]['diff'] === 10.89 && $p4[1]['inv_rate']['date'] === '2026-11-13',
    'case 4 — one transfer for two invoices : 900 × (4,2350 − 4,2290) = +5,40 and 610 × (4,2350 − 4,2260) = +5,49 (row +10,89)');
$cand = array_column($rows[$id5]['candidates'], 'number');
$check($rows[$id5]['state'] === 'to_confirm' && $cand === ['FV/2026/11/0160', 'PF/2026/11/031'] && $rows[$id5]['pieces'] === [] && $rows[$id5]['diff'] === 0.0,
    '« do potwierdzenia » : candidates PF/2026/11/031 and FV/2026/11/0160 (640,00 EUR), no difference yet');
$check($rows[$id6]['state'] === 'waiting' && $rows[$id6]['waiting'][0]['proforma']['number'] === 'PF/2026/11/035' && $rows[$id6]['pieces'] === [], '« czeka na fakturę » : paid proforma without invoice yet');
$k = $d['kpi'];
$check($d['totals'] === ['positive' => 39.31, 'negative' => -91.98, 'balance' => -52.67] && $k['positive_rows'] === 2 && $k['negative_rows'] === 2,
    'totals : dodatnie +39,31 (2 rows), ujemne −91,98 (2 rows), saldo −52,67');
$check($k['paid'] === [['currency' => 'EUR', 'amount' => 7095.4]] && $k['payments'] === 6 && $k['statements'] === 3 && $k['counted'] === 4 && $k['invoices'] === 5
    && $k['open'] === 2 && $k['open_by_state'] === ['to_confirm' => 1, 'waiting' => 1], 'KPIs : 7 095,40 EUR, 6 payments from 3 statements, 4 counted (5 invoices), 2 to explain (1 to confirm, 1 waiting)');
$st = array_column($d['statements'], null, 'id');
$check(count($st) === 3 && $st[$u1['id']]['label'] === 'Konto EUR · 01–15.11' && $st[$u1['id']]['chip'][0] === 'wczytany' && $st[$u1['id']]['other'] === 2
    && $st[$u2['id']]['chip'][0] === 'do wpisania ręcznie' && $st[$u2['id']]['customers'] === 4 && $st[$u3['id']]['label'] === 'Konto PLN · listopad',
    'statements panel : period, account, customer payments and other lines, read state');
$check($d['rates']['currency'] === 'EUR' && $d['rates']['host'] === 'api.nbp.pl' && count($d['rates']['list']) === 4 && $d['rates']['list'][0]['date'] === '2026-11-24'
    && $d['nav'][0]['month'] === '2026-10' && $d['nav'][2]['disabled'] === true && count($d['customers']) === 6, 'rates panel (last tables, host from the setting), month navigation, customers for the form');
$check($sum() === $before, 'ERP documents untouched (WDT, proformas, Płatności dnia, orders)');
if (getenv('FX_JSON_OUT')) file_put_contents((string) getenv('FX_JSON_OUT'), json_encode(['data' => $d], JSON_UNESCAPED_UNICODE));
$noValues = $fx->data('2026-11', false, $today, 7);
$check(!isset($noValues['rows']) && !isset($noValues['kpi']) && $noValues['values'] === false, 'without the amounts right : frame only, no payment or amount');

// --- Zestawienie miesiąca : PDF (pdftotext) et CSV
$f = $fx->pdf('2026-11', '2026-12-01');
$tmp = tempnam(sys_get_temp_dir(), 'fxpdf');
file_put_contents($tmp, $f['content']);
$txt = (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
if (getenv('FX_PDF_OUT')) copy($tmp, (string) getenv('FX_PDF_OUT'));
@unlink($tmp);
$check($f['name'] === 'ROZNICE_KURSOWE_2026-11.pdf' && str_starts_with($f['content'], '%PDF') && str_contains($txt, '+39,31 zł') && str_contains($txt, '−91,98 zł') && str_contains($txt, '−52,67 zł')
    && str_contains($txt, 'FV/2026/11/0088') && str_contains($txt, 'FV/2026/11/0151') && str_contains($txt, '4,2105 (ING)') && str_contains($txt, 'Restauracja Test D')
    && str_contains($txt, 'Kawiarnia Test E') && str_contains($txt, 'Do wyjaśnienia') && str_contains($txt, '01.12.2026') && str_contains($txt, '4 (5 faktur)'),
    'month PDF : one line per invoice, totals +39,31 / −91,98 / saldo −52,67, items to explain apart, print date');
$csv = $fx->csv('2026-11', $today);
$lines = explode("\r\n", trim($csv['content']));
$check($csv['name'] === 'ROZNICE_KURSOWE_2026-11.csv' && str_starts_with($csv['content'], "\u{FEFF}") && count(array_filter($lines, fn($x) => str_ends_with($x, '"policzone"'))) === 5
    && str_contains($csv['content'], '"28,42"') && str_contains($csv['content'], '"-34,38"') && str_contains($csv['content'], '"Saldo";"";"";"";"";"";"";"";"";"";"-52,67"')
    && str_contains($csv['content'], 'do wyjaśnienia : przedpłata, faktura jeszcze nie wystawiona') && str_contains($csv['content'], '"ING (przewalutowanie)"'),
    'month CSV : 5 invoice lines, totals, items to explain, payment rate source');

// --- Confirmation d'une candidate ; facture émise après la przedpłata ; « nie dotyczy »
$check(str_starts_with($err(fn() => $ing->allocate($id5, ['wdt:142'], 7)), 'custom:422') && str_starts_with($err(fn() => $ing->allocate($id5, [], 7)), 'custom:422'), 'confirm : document of another customer or nothing chosen → 422');
$ing->allocate($id5, ['wdt:160'], 7);
$d2 = $fx->data('2026-11', true, $today, 7);
$r5 = array_column($d2['rows'], null, 'id')[$id5];
$check($r5['state'] === 'counted' && $r5['pieces'][0]['diff'] === 1.92 && $d2['totals']['positive'] === 41.23 && $d2['kpi']['open'] === 1,
    'confirmed FV/0160 (20.11 → NBP 19.11) : 640 × (4,2380 − 4,2350) = +1,92 ; one item left to explain');
$id7 = $save(['account' => 'EUR', 'booked_on' => '2026-11-24', 'amount' => '640', 'currency' => 'EUR', 'id_customer' => 4, 'title' => 'druga zaliczka']);
$check($line($id7)['match_state'] === 'matched' && $pdo->query("SELECT CONCAT(doc_kind, ':', id_doc, ':', source) FROM n_fx_allocation WHERE id_line = {$id7}")->fetchColumn() === 'proforma:31:amount',
    'the confirmed invoice is no longer open : a second 640 EUR payment of the customer → the only open document (PF/2026/11/031, by amount)');
$ing->deleteLine($id7);
$wdt(180, 'FV/2026/11/0180', 5, '2026-11-25', 315.40);
$pdo->exec('UPDATE customer_order SET id_wdt = 180 WHERE id = 503');
$r6 = array_column($fx->data('2026-11', true, $today, 7)['rows'], null, 'id')[$id6];
$check($r6['state'] === 'counted' && $r6['pieces'][0]['invoice']['number'] === 'FV/2026/11/0180' && $r6['pieces'][0]['diff'] === FxDifferenceService::diff(315.40, 4.2410, 4.2500) && $r6['pieces'][0]['diff'] === -2.84,
    'invoice issued after the prepayment (25.11 → NBP 24.11) : 315,40 × (4,2410 − 4,2500) = −2,84');
$ing->ignore($id6, true, 7);
$check(!isset(array_column($fx->data('2026-11', true, $today, 7)['rows'], null, 'id')[$id6]), '« nie dotyczy » : the payment leaves the list');
$ing->ignore($id6, false, 7);
$check($line($id6)['match_state'] === 'matched', 'back to automatic matching');
$dec = $fx->data('2026-12', true, '2026-12-03', 7);
$check($dec['rows'] === [] && $dec['totals']['balance'] === 0.0, 'next month : nothing counted twice');
$oct = $fx->data('2026-10', true, $today, 7);
$check($oct['rows'] === [], 'previous month : nothing (prepayment counted in the month of the invoice)');
$ing->deleteStatement($u3['id']);
$check(!$pdo->query("SELECT COUNT(*) FROM n_bank_statement_line WHERE id = {$id3}")->fetchColumn() && !$pdo->query("SELECT COUNT(*) FROM n_fx_allocation WHERE id_line = {$id3}")->fetchColumn(),
    'statement deleted with its payments and their allocations');

// --- Droit propre : catalogue, onglet, routes, contrôleur réel (PHP-DI)
$check(PageAccess::keyFor('/finances/fx-differences') === 'fx_differences' && PageAccess::keyFor('/ajax/finances/fx-differences/pdf') === 'fx_differences'
    && PageAccess::keyFor('/finances/payments-day') === 'payments_day' && PageAccess::keyFor('/finances/invoices') === 'margins' && in_array('fx_differences', PageAccess::grantable(), true)
    && PageAccess::ceoSees('fx_differences'), 'own right « fx_differences » (page and ajax), grantable, CEO profile reads ; other finance pages unchanged');
$tabs = array_column(App\Core\Web\PageTabs::GROUPS['invoicing']['tabs'], 1, 0);
$check(($tabs['/finances/fx-differences'] ?? null) === 'Różnice kursowe' && App\Core\Web\Rail::itemFor('/finances/fx-differences') === 'invoicing', 'tab « Różnice kursowe » in Faktury i płatności (Finanse)');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/finances/FxDifferenceRoutes.php');
$found = fn(string $m, string $u) => ($r = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $r[1]['method'] : null;
$check($found('GET', '/finances/fx-differences') === 'index' && $found('GET', '/ajax/finances/fx-differences/pdf') === 'pdf' && $found('GET', '/ajax/finances/fx-differences/csv') === 'csv'
    && $found('POST', '/ajax/finances/fx-differences/statements') === 'upload' && $found('POST', '/ajax/finances/fx-differences/lines/5/allocate') === 'allocate'
    && $found('POST', '/ajax/finances/fx-differences/lines') === 'saveLine' && $found('POST', '/ajax/finances/fx-differences/lines/5') === 'saveLine', 'routes');
$all = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($all->dispatch('GET', '/finances/fx-differences')[0] === FastRoute\Dispatcher::FOUND, 'routes file loaded by the */*Routes.php glob');
$pdo->exec("INSERT INTO user (id, login) VALUES (21, 'ko'), (22, 'log'), (23, 'fin'), (24, 'log2')");
$pdo->exec('INSERT INTO n_user_position VALUES (21, 11), (22, 12), (23, 13), (24, 12)');
$b = new DI\ContainerBuilder();
$b->addDefinitions([PDO::class => fn() => new PDO($dsn . ';dbname=' . $db, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]),
    WorkshopConfig::class => $config, NbpHttpClient::class => $fake, BankStatementParser::class => DI\get(IngStatementParser::class)]);
$ctl = $b->build()->get(App\Controllers\Web\Finances\FxDifferenceController::class);
$call = function (int $user, string $method = 'data') use ($ctl) { $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user]; $_GET['month'] = '2026-11'; $res = $ctl->$method(); return [$res->getStatusCode(), json_decode((string) $res->getContent(), true), $res]; };
[$cFin, $bFin] = $call(23);
[$cKo, $bKo] = $call(21);
[$cKoPdf] = $call(21, 'pdf');
[$cLog] = $call(22);
[$cPdf, , $rPdf] = $call(23, 'pdf');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (12, 'fx_differences')");
[$cLog2, $bLog2] = $call(24);
[$cLogPdf] = $call(24, 'pdf');
$check($cFin === 200 && ($bFin['data']['values'] ?? null) === true && count($bFin['data']['rows'] ?? []) === 5 && $cPdf === 200 && $rPdf->headers->get('Content-Type') === 'application/pdf',
    'controller : Finanse (ADMINISTRACJA) sees the page with amounts, downloads the PDF');
$check($cKo === 200 && ($bKo['data']['values'] ?? null) === true && count($bKo['data']['rows'] ?? []) === 5 && $cKoPdf === 200, '[443] controller : Kierownik operacyjny sees the page with amounts and downloads the PDF');
$check($cLog === 403, 'controller : Logistyka without the right → 403');
$check($cLog2 === 200 && ($bLog2['data']['values'] ?? null) === false && !isset($bLog2['data']['rows']) && $cLogPdf === 403, 'controller : right given to another position → frame only, amounts and files refused (403)');
unset($_SESSION['USER'], $_GET['month']);

// --- [444] Wyciąg ING réel (mise en page du relevé du 23.11.2026, anonymisée : tests/fixtures/ing_wyciag_eur.txt) + un
// crédit de client fictif, rendu en PDF puis lu par pdftotext comme à l'envoi.
$lay = explode("\n", (string) file_get_contents(__DIR__ . '/fixtures/ing_wyciag_eur.txt'));
$at = array_key_first(preg_grep('/^\s*23\.11\.2026 /u', $lay));
$end = mb_strlen(rtrim($lay[$at]));
$cell = function (array $cells): string { $s = ''; foreach ($cells as [$col, $txt]) $s = mb_str_pad($s, $col) . $txt; return $s; };
array_splice($lay, $at, 0, [$cell([[4, '23.11.2026'], [15, 'BE00 0000 0000 0009'], [61, 'wplata test 777'], [89, 'PRZELEW SEPA PRZYCHODZĄCY'], [$end - 5, '77,00']]),
    $cell([[2, '97000000777'], [15, 'SKLEP TESTOWY C SPRL'], [89, 'Data transakcji: 23.11.2026']]), $cell([[17, '1000 BRUXELLES']])]);
$txt = str_replace(['Suma uznań (+):                   0,00', 'Liczba uznań (+): 0'], ['Suma uznań (+):                   77,00', 'Liczba uznań (+): 1'], implode("\n", $lay));
$pdfReal = (function (string $t): string { $p = new \TCPDF('L', 'mm', 'A3', true, 'UTF-8', false); $p->setPrintHeader(false); $p->setPrintFooter(false); $p->SetAutoPageBreak(false); $p->AddPage();
    $p->SetFont('dejavusansmono', '', 7); $y = 5; foreach (explode("\n", $t) as $l) { $p->Text(5, $y, $l); $y += 3.2; } return $p->Output('', 'S'); })($txt);
$before = (int) $pdo->query('SELECT COUNT(*) FROM n_bank_statement')->fetchColumn();
$wrong = $err(fn() => $ing->upload($pdfReal, 'Statement_EUR_279.pdf', ['account' => 'PLN'], 7, $today));
$check(str_starts_with($wrong, 'custom:422:To wyciąg z rachunku EUR') && (int) $pdo->query('SELECT COUNT(*) FROM n_bank_statement')->fetchColumn() === $before,
    '[444] EUR statement sent as « Konto PLN » → 422 (« Waluta rachunku » of the header), nothing stored');
$uR = $ing->upload($pdfReal, 'Statement_EUR_279.pdf', ['account' => 'EUR'], 7, $today);
$sR = $pdo->query("SELECT * FROM n_bank_statement WHERE id = {$uR['id']}")->fetch();
$lR = $pdo->query("SELECT * FROM n_bank_statement_line WHERE id_statement = {$uR['id']}")->fetchAll();
$check($uR['status'] === 'parsed' && $uR['lines'] === 1 && $uR['other'] === 7 && $sR['parser'] === 'ing' && $sR['period_from'] === '2026-11-22' && $sR['period_to'] === '2026-11-23'
    && count($lR) === 1 && $lR[0]['booked_on'] === '2026-11-23' && (float) $lR[0]['amount'] === 77.0 && $lR[0]['currency'] === 'EUR' && $lR[0]['source'] === 'pdf'
    && str_starts_with((string) $lR[0]['payer_enc'], 'p1:') && !str_contains((string) $lR[0]['title_enc'], '777'),
    '[444] ING EUR statement read : 1 customer credit kept (7 kantor / transfer debits counted), period from the header, payer and title encrypted');

$pdo->exec("DROP DATABASE {$db}");
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
