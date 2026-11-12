<?php
declare(strict_types=1);
/*
 * [277] Lista płac + [276] Szablony umów :
 *   - calcul (droit polonais 2026) : les 4 lignes de la maquette 41 au grosz près (6 000 zł, płaca minimalna,
 *     nadgodziny 150 %, student zlecenie), zlecenie sans ulga, L4 (1/30 et 80 %), ulga dla młodych, seuil 32 %,
 *     plafond 30-krotności, jours ouvrés (fériés PL), date de paiement (art. 85 KP), wymiar, PESEL, okres wypowiedzenia ;
 *   - fichier pain.001 : IBAN (mod 97), caractères refusés par ING retirés, NbOfTxs / CtrlSum, catégorie SALA ;
 *   - base jetable : migration 171 rejouée, fiche HR (champs de paie chiffrés, visibles seulement avec l'accès
 *     « payroll »), mois brouillon → approbation (Czas pracy clôturé exigé) → fichier XML → CSV → réouverture ;
 *   - modèles de contrats : champs manquants signalés, DOCX, PDF rangé chiffré dans les documents.
 *
 *   php tests/payroll_test.php
 *
 * Base : TFB_TEST_DSN / TFB_TEST_USER / TFB_TEST_PASS, structures de TFB_TEST_SOURCE_DB (erp). erp_pay_test est recréée
 * puis supprimée. Aucun virement, aucun e-mail : le fichier XML n'est que du texte.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\PageAccess;
use App\Core\Web\PageTabs;
use App\Core\Web\Rail;
use App\Domain\Logistics\Holidays;
use App\Domain\Payroll\BankXml;
use App\Domain\Payroll\PayrollCalculator as Calc;
use App\Exceptions\CustomException;
use App\Services\Hr\ContractTemplateService;
use App\Services\Hr\HrService;
use App\Services\Payroll\PayrollService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectPay(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function payCode(callable $fn): string
{
    try { $fn(); } catch (CustomException $e) { return (string) $e->getCode(); }
    return 'ok';
}

// --- Calcul ----------------------------------------------------------------------------------------------
$r = ['min_wage' => 4806, 'min_hourly' => 31.40, 'zus_cap' => 282600, 'emp_pension' => 9.76, 'emp_disability' => 1.5, 'emp_sickness' => 2.45, 'health' => 9,
    'er_pension' => 9.76, 'er_disability' => 6.5, 'er_accident' => 1.67, 'er_fp' => 2.45, 'er_fgsp' => 0.1, 'kup' => 250, 'kup_raised' => 300, 'kup_civil' => 20,
    'pit_low' => 12, 'pit_high' => 32, 'pit_threshold' => 120000, 'pit_reduction' => 300, 'young_limit' => 85528, 'sick_pay' => 80, 'sick_days_employer' => 33];
$m = ['workdays' => 20, 'employed_workdays' => 20, 'hours' => 0, 'sick_days' => 0, 'unpaid_days' => 0];
$emp = ['kind' => 'praca', 'pay_type' => 'monthly', 'fraction' => 1, 'pit2' => true, 'kup_raised' => false, 'young' => false];
$line = fn(array $x) => [$x['gross'], $x['zus_total'], $x['health'], $x['pit'], $x['net'], $x['cost']];
expectPay($line(Calc::compute($emp + ['pay_amount' => 6000], $m, [], $r)) === [6000.0, 822.6, 465.97, 291.0, 4420.43, 7228.8], 'maquette 41 : 6 000 zł → netto 4 420,43, koszt 7 228,80');
expectPay($line(Calc::compute($emp + ['pay_amount' => 4806], $m, [], $r)) === [4806.0, 658.91, 373.24, 168.0, 3605.85, 5790.28], 'maquette 41 : płaca minimalna → netto 3 605,85');
expectPay($line(Calc::compute($emp + ['pay_amount' => 6000], $m, ['overtime50_h' => 6], $r)) === [6337.5, 868.87, 492.18, 326.0, 4650.45, 7635.43], 'maquette 41 : 6 h × 150 % = 337,50');
$st = Calc::compute(['kind' => 'zlecenie', 'pay_type' => 'hourly', 'pay_amount' => 31.40, 'student' => true, 'young' => true, 'pit2' => true], ['hours' => 25] + $m, [], $r);
expectPay($line($st) === [785.0, 0.0, 0.0, 0.0, 785.0, 785.0] && $st['student'], 'maquette 41 : student < 26, zlecenie 25 h × 31,40 = 785 bez ZUS i podatku');
$zl = Calc::compute(['kind' => 'zlecenie', 'pay_type' => 'hourly', 'pay_amount' => 31.40, 'pit2' => false], ['hours' => 100] + $m, [], $r);
expectPay([$zl['zus_total'], $zl['health'], $zl['kup'], $zl['tax_base'], $zl['pit'], $zl['net']] === [353.56, 250.78, 557.29, 2229.0, 267.0, 2268.66] && $zl['zus']['sickness'] === 0.0,
    'zlecenie : emerytalna + rentowa, bez chorobowej, KUP 20 %, PIT 12 % bez PIT-2');
$sick = Calc::compute($emp + ['pay_amount' => 6000], ['sick_days' => 5] + $m, [], $r);
expectPay($sick['earnings'][1]['amount'] === -1000.0 && $sick['sick_pay'] === 690.32 && $sick['zus_base'] === 5000.0 && count($sick['warnings']) === 1,
    'L4 5 dni : − 1/30 × 5, chorobowe 80 % (690,32), bez ZUS od chorobowego, uwaga o podstawie');
$young = Calc::compute(array_merge($emp, ['pay_amount' => 6000, 'young' => true]), $m, [], $r);
expectPay($young['pit'] === 0.0 && $young['zus_total'] === 822.6 && $young['health'] === 465.97, 'ulga dla młodych : bez PIT, ZUS i zdrowotna zostają');
$high = Calc::compute($emp + ['pay_amount' => 12000], ['ytd' => ['tax_base' => 118000]] + $m, [], $r);
expectPay($high['pit'] > 0 && in_array(true, array_map(fn($w) => str_contains($w, '32 %'), $high['warnings']), true), 'próg 120 000 zł : 32 % od nadwyżki');
$cap = Calc::compute($emp + ['pay_amount' => 30000], ['ytd' => ['pension_base' => 280000]] + $m, [], $r);
expectPay($cap['pension_base'] === 2600.0 && $cap['zus']['pension'] === 253.76 && $cap['zus']['sickness'] === 735.0, 'limit 30-krotności : emerytalna od 2 600, chorobowa od całości');
$older = Calc::compute($emp + ['pay_amount' => 6000, 'fp_exempt' => true], $m, [], $r);
expectPay($older['employer']['fp'] === 0.0 && $older['employer']['fgsp'] === 0.0, 'kobieta 55+ / mężczyzna 60+ : bez FP i FGŚP');
$hol = Holidays::forYear(2026, 'PL');
expectPay(Calc::workdays('2026-11-01', '2026-11-30', $hol) === 20 && Calc::workdays('2026-12-01', '2026-12-31', $hol) === 21, 'dni robocze : listopad 20 (11.11), grudzień 21 (24.12, 25–26.12)');
expectPay(PayrollService::payBy('2026-11') === '2026-12-10' && PayrollService::payBy('2026-04') === '2026-05-08', 'wypłata do 10. ; niedziela 10.05 → piątek 08.05');
expectPay(Calc::fraction('1/2 etatu') === 0.5 && Calc::fraction('pełny etat') === 1.0 && Calc::fraction('0,75') === 0.75, 'wymiar etatu');
expectPay(HrService::peselOk('44051401359') && !HrService::peselOk('44051401358'), 'PESEL : suma kontrolna');
expectPay(ContractTemplateService::notice('nieokreslony', '2026-01-01', null, '2026-03-01') === '2 tygodnie' && ContractTemplateService::notice('okreslony', '2022-01-01', null, '2026-03-01') === '3 miesiące'
    && ContractTemplateService::notice('probny', '2026-01-01', '2026-03-31', '2026-02-01') === '2 tygodnie', 'okres wypowiedzenia : art. 36 i art. 34');

// --- Fichier banque --------------------------------------------------------------------------------------
expectPay(BankXml::iban('61 1090 1014 0000 0712 1981 2874') === 'PL61109010140000071219812874' && BankXml::iban('PL61109010140000071219812875') === null, 'IBAN : mod 97');
$xml = BankXml::build('PLACE-2026-11', ['name' => 'SL Food <sp. z o.o.>', 'iban' => 'PL61109010140000071219812874', 'bic' => null], '2026-12-10', [
    ['id' => 'PL202611-1', 'name' => 'Jan Kow@lski #1', 'iban' => 'PL61109010140000071219812874', 'amount' => 4420.43, 'title' => 'Wynagrodzenie 11/2026'],
    ['id' => 'PL202611-2', 'name' => 'Żaneta Łąka', 'iban' => 'PL61109010140000071219812874', 'amount' => 785, 'title' => 'Wynagrodzenie 11/2026'],
], '2026-12-01T10:00:00');
$dom = new DOMDocument();
expectPay($dom->loadXML($xml) && $dom->documentElement->namespaceURI === 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03', 'pain.001.001.03 : XML poprawny');
$xp = new DOMXPath($dom);
$xp->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
expectPay($xp->evaluate('string(//p:GrpHdr/p:NbOfTxs)') === '2' && $xp->evaluate('string(//p:GrpHdr/p:CtrlSum)') === '5205.43' && $xp->evaluate('string(//p:CtgyPurp/p:Cd)') === 'SALA'
    && $xp->evaluate('string(//p:ReqdExctnDt)') === '2026-12-10', 'liczba przelewów, suma kontrolna, SALA, data');
expectPay(!preg_match('/[@#<>$*]/', $xp->evaluate('string(//p:CdtTrfTxInf[1]/p:Cdtr/p:Nm)') . $xp->evaluate('string(//p:Dbtr/p:Nm)')) && str_contains($xml, 'Żaneta Łąka'),
    'znaki odrzucane przez ING usunięte ; polskie litery zostają');

expectPay(PageAccess::keyFor('/payroll') === 'payroll' && !PageAccess::adminOnly('payroll') && Rail::itemFor('/payroll/settings') === 'payroll'
    && count(PageTabs::GROUPS['payroll']['tabs']) === 2 && PageAccess::keyFor('/hr/templates') === 'hr', 'klucz « payroll », pozycja railu i 2 zakładki ; szablony w HR');

// --- Base jetable ----------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_pay_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "payroll_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source)
        . " AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT LIKE 'n\\_hr\\_%' AND TABLE_NAME NOT LIKE 'n\\_pay\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login, password) VALUES (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', 'ada', 'x')");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (5, 'ADMINISTRACJA', 'Administracja i fakturowanie', 'Admin', 'erp', 2, 1), (7, 'PIEKARZ', 'Piekarz', 'Boulanger', 'prod', 3, 1)");
    $pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (5, 'invoicing')");
    foreach (['170_hr.sql', '171_payroll_contracts.sql', '171_payroll_contracts.sql', '172_hr_quick_note.sql'] as $f) {
        foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
    }
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectPay($count('SELECT COUNT(*) FROM n_pay_rate WHERE year = 2026') === 22 && $count('SELECT COUNT(*) FROM n_hr_template') === 9
        && $count("SELECT COUNT(*) FROM n_pay_rate WHERE source IS NULL OR source = ''") === 0, 'migration 171 (rejouée) : 22 stawki 2026, każda ze źródłem ; 9 szablonów');
    expectPay($count("SELECT COUNT(*) FROM n_page_access WHERE page_key = 'payroll' AND id_position = 5") === 1 && $count("SELECT COUNT(*) FROM n_position_menu WHERE item = 'payroll' AND id_position = 5") === 1,
        'dostęp i menu « Lista płac » : Administracja i fakturowanie');

    $pdo->exec("UPDATE erp_company_data SET wz_company_name = 'SL Food Test', wz_company_address_1 = 'ul. Testowa 1', wz_company_address_2 = '00-001 Warszawa', wz_company_nip = '1234563218', wz_company_bank_1 = 'PL61109010140000071219812874' WHERE id = 1");
    if ($count('SELECT COUNT(*) FROM erp_company_data') === 0) {
        $pdo->exec("INSERT INTO erp_company_data (id, wz_company_name, wz_company_address_1, wz_company_address_2, wz_company_nip, wz_company_bank_1) VALUES (1, 'SL Food Test', 'ul. Testowa 1', '00-001 Warszawa', '1234563218', 'PL61109010140000071219812874')");
    }
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost) VALUES (1, 'Jan', 'Testowy', 0), (2, 'Ola', 'Studentka', 0), (3, 'Bez', 'Danych', 0)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group, id_position) VALUES (11, 1, 1, 1, 7), (12, 2, 1, 1, NULL), (13, 3, 1, 1, NULL)");
    $clock = $pdo->prepare("INSERT INTO n_prod_clock (id_employee, clock_in, clock_out, source) VALUES (12, ?, ?, 'kiosk')");
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $day) $clock->execute([$day . ' 08:00:00', $day . ' 13:00:00']);

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('p', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
            {
                throw new RuntimeException('Aucun e-mail ne part pendant les tests.');
            }
        }]);
    $c = $b->build();
    $hr = $c->get(HrService::class);
    $pay = $c->get(PayrollService::class);
    $tpl = $c->get(ContractTemplateService::class);

    // Fiche : champs de paie validés, chiffrés, invisibles sans l'accès « payroll »
    $bad = null;
    try { $hr->save(11, ['pay_type' => 'rok', 'pay_amount' => '-5', 'bank' => '1234', 'pesel' => '44051401358'], 1, true); } catch (CustomException $e) { $bad = $e->getErrors()['fields'] ?? []; }
    expectPay($bad !== null && count(array_intersect(['pay_type', 'pay_amount', 'bank', 'pesel'], array_keys($bad))) === 4, 'walidacja : rodzaj, kwota, rachunek, PESEL');
    $hr->save(11, ['birth_date' => '1980-04-02', 'gender' => 'M', 'hire_date' => '2020-01-01', 'contract_type' => 'nieokreslony', 'work_time' => 'pełny etat',
        'pay_type' => 'monthly', 'pay_amount' => '6 000,00', 'bank' => '61 1090 1014 0000 0712 1981 2874', 'pesel' => '44051401359', 'address' => 'ul. Polna 2, 00-002 Warszawa', 'pit2' => true], 1, true);
    $hr->save(12, ['birth_date' => '2004-06-01', 'gender' => 'K', 'hire_date' => '2026-09-01', 'contract_type' => 'zlecenie', 'pay_type' => 'hourly', 'pay_amount' => '31,40',
        'bank' => 'PL61109010140000071219812874', 'student' => true], 1, true);
    $hr->save(11, ['pay_amount' => '9999'], 1, false);
    $raw = $pdo->query('SELECT * FROM n_hr_employee WHERE id_employee = 11')->fetch();
    expectPay((float) $raw['pay_amount'] === 6000.0 && str_starts_with((string) $raw['bank_enc'], 'h1:') && !str_contains(implode('|', array_map('strval', $raw)), '1090')
        && !str_contains((string) $raw['pesel_enc'], '4405'), 'rachunek i PESEL zaszyfrowane ; bez dostępu « payroll » kwota się nie zmienia');
    expectPay(!isset($hr->card(11, 1, false, null, false)['person']['pay']) && $hr->card(11, 1, true, null, true)['person']['pay']['bank'] === 'PL61 1090 1014 0000 0712 1981 2874',
        'płace w karcie tylko z dostępem « payroll »');

    // Mois : brouillon, Czas pracy non clôturé → pas d'approbation
    $mo = $pay->month('2026-10', 1, strtotime('2026-11-12 10:00'));
    $lines = array_column($mo['lines'], null, 'employee_id');
    expectPay($mo['status'] === 'draft' && count($mo['lines']) === 2 && count($mo['incomplete']) === 1 && $mo['incomplete'][0]['employee_id'] === 13, 'projekt : 2 osoby, 1 bez umowy i wynagrodzenia');
    expectPay($lines[11]['calc']['net'] === 4420.43 && $lines[12]['hours'] === 25.0 && $lines[12]['calc']['net'] === 785.0 && !isset($lines[11]['iban']) && $lines[11]['account'] === 'PL** **** … 2874',
        'Jan 6 000 → 4 420,43 ; Ola 25 h z Czasu pracy → 785 ; rachunek zamaskowany');
    expectPay($mo['totals']['net'] === 5205.43 && $mo['totals']['transfers'] === 2 && $mo['pay_by'] === '2026-11-10', 'razem do wypłaty, 2 przelewy, do 10.11');
    expectPay(payCode(fn() => $pay->approve('2026-10', 1, strtotime('2026-11-12 10:00'))) === '422', 'bez zamkniętego miesiąca w Czasie pracy : brak zatwierdzenia');
    expectPay(payCode(fn() => $pay->bankFile('2026-10', null, 1, strtotime('2026-11-12 10:00'))) === '422', 'plik XML dopiero po zatwierdzeniu');

    $pay->saveAdjust('2026-10', 11, ['overtime50_h' => '6', 'bonus' => '0'], 1);
    expectPay(array_column($pay->month('2026-10', 1, strtotime('2026-11-12 10:00'))['lines'], null, 'employee_id')[11]['calc']['gross'] === 6306.82, 'korekta : 6 h × 6 000 / 176 h (22 dni rob. października) × 150 % = 306,82');
    $pdo->exec("INSERT INTO n_time_month (month, closed_by) VALUES ('2026-10', 1)");
    $ap = $pay->approve('2026-10', 1, strtotime('2026-11-12 10:00'));
    expectPay($ap['status'] === 'approved' && $count("SELECT COUNT(*) FROM n_pay_line WHERE month = '2026-10'") === 2 && payCode(fn() => $pay->saveAdjust('2026-10', 11, ['bonus' => '100'], 1)) === '409',
        'zatwierdzona : 2 linie zapisane, korekty zablokowane');
    $file = $pay->bankFile('2026-10', '2026-11-13', 1, strtotime('2026-11-12 10:00'));
    expectPay($file['count'] === 2 && $file['sum'] === 5414.36 && str_contains($file['content'], '<IBAN>PL61109010140000071219812874</IBAN>') && $count("SELECT COUNT(*) FROM n_pay_run WHERE xml_at IS NOT NULL") === 1,
        'plik XML : 2 przelewy, suma, data pobrania zapisana');
    $csv = $pay->csv('2026-10', 1);
    expectPay(str_starts_with($csv['content'], "\u{FEFF}") && substr_count($csv['content'], "\r\n") === 3 && str_contains($csv['content'], '"4629,36"'), 'CSV dla biura rachunkowego');
    $slips = App\Services\Payroll\PayslipPdf::render($pay->printable('2026-10', [11], 1));
    expectPay(str_starts_with($slips['content'], '%PDF') && str_contains($slips['filename'], 'PASEK_2026-10_Jan_Testowy'), 'pasek PDF');
    // Mois suivant : cumuls de l'année ; réouverture d'octobre refusée tant que novembre est approuvé
    $pdo->exec("INSERT INTO n_time_month (month, closed_by) VALUES ('2026-11', 1)");
    $pay->approve('2026-11', 1, strtotime('2026-12-02 10:00'));
    expectPay(payCode(fn() => $pay->reopen('2026-10', 1)) === '409' && $pay->reopen('2026-11', 1)['status'] === 'draft', 'otwarcie : najpierw późniejszy miesiąc');
    expectPay($pay->copyRates(2027, 1)['own'] && $count('SELECT COUNT(*) FROM n_pay_rate WHERE year = 2027') === 22, 'kopiowanie stawek na nowy rok');

    // Modèles de contrats
    $pv = $tpl->render('praca_nieokreslony', 11, ['sign_date' => '2026-11-12'], 1);
    expectPay(in_array('miejscowość (Ustawienia płac)', $pv['missing'], true) && in_array('reprezentant firmy (Ustawienia płac)', $pv['missing'], true) && !in_array('adres pracownika (karta HR)', $pv['missing'], true)
        && str_contains(implode(' ', $pv['paragraphs']), '6 000,00 zł brutto') && str_contains(implode(' ', $pv['paragraphs']), 'Piekarz'), 'podgląd : pensja i stanowisko z karty, brakujące pola firmy');
    expectPay(payCode(fn() => $tpl->docx('praca_nieokreslony', 11, [], 1)) === '422', 'brakujące pola : bez DOCX');
    $pay->saveSettings(['city' => 'Warszawa', 'rep' => 'Ada Admin — prezes zarządu', 'workplace' => 'ul. Testowa 1, Warszawa'], 1);
    $docx = $tpl->docx('praca_nieokreslony', 11, ['sign_date' => '2026-11-12'], 1);
    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'dx');
    file_put_contents($tmp, $docx['content']);
    expectPay($zip->open($tmp) === true && str_contains((string) $zip->getFromName('word/document.xml'), 'Jan Testowy') && str_contains((string) $zip->getFromName('word/document.xml'), '44051401359'), 'DOCX : umowa z danymi');
    $zip->close();
    @unlink($tmp);
    $hr->save(12, ['address' => 'ul. Łąkowa 5, 00-003 Warszawa', 'pesel' => '04260112347'], 1, true);
    $saved = $tpl->savePdf('zlecenie', 12, ['sign_date' => '2026-11-12', 'date_to' => '2027-06-30', 'subject' => 'Pomoc przy pakowaniu'], 1);
    $doc = $pdo->query('SELECT kind, mime, content FROM n_hr_document WHERE id = ' . (int) $saved['document_id'])->fetch();
    expectPay($doc['kind'] === 'umowa' && $doc['mime'] === 'application/pdf' && str_starts_with((string) $doc['content'], 'H1'), 'PDF umowy zlecenia zapisany zaszyfrowany w dokumentach');
    expectPay(payCode(fn() => $tpl->save('aneks', ['title' => 'Aneks', 'body' => 'ANEKS {nieznane_pole} ………………………'], 1)) === '422', 'szablon : nieznane pole odrzucone');

    echo "payroll_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
