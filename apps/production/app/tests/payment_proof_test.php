<?php
declare(strict_types=1);
/*
 * Wpłata z e-maila [233] (makieta 9) : odczyt potwierdzenia przelewu (PL, FR, NL, OCR z błędami : numer proformy, kwota,
 * data operacji, rachunek, płacący), nasz rachunek, płacący = klient ; na bazie jednorazowej : migracja 153 (rejouable),
 * lista « Z poczty » (słowa kluczowe, klient z proformą niezapłaconą, skrzynka użytkownika), odczyt PDF z tekstem
 * (pdftotext) i zdjęcia (OCR z przeglądarki), plik i tekst zaszyfrowane, podpowiedź proformy (numer, kwota, suma kilku),
 * zatwierdzenie (proforma opłacona + data i kwota + dowód), przelew za dwie proformy, proforma innego klienta odrzucona,
 * « To nie jest wpłata », cudze potwierdzenie niewidoczne, wpis na karcie klienta, Poczta : « Zweryfikuj wpłatę ».
 *
 *   php tests/payment_proof_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_payproof_test est recréée puis supprimée.
 * Aucun e-mail, aucune skrzynka IMAP : le fichier est passé directement (readContent).
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientSpaceRepository;
use App\Services\Finances\PaymentProof\PaymentProofParser as P;
use App\Services\Finances\PaymentProof\PaymentProofService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectPay(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectPayFail(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (CustomException $e) {
        expectPay((int) $e->getCode() === $code, $message . ' (HTTP ' . $e->getCode() . ' : ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

// --- Odczyt bez bazy ----------------------------------------------------------------------------------------------
$today = '2026-10-31';
$pl = "Data wydruku: 31.10.2026\nPotwierdzenie wykonania przelewu\nData operacji : 30.10.2026\nZleceniodawca : KLIENT A SPRL\nRachunek odbiorcy : PL61 1090 1014 0000 0712 1981 2874\n"
    . "Odbiorca : SL FOOD\nTytuł : FP-00001/28/07/2026\nKwota : 196,95 EUR\nStatus : zrealizowany";
$r = P::parse($pl, $today);
expectPay($r['numbers'] === ['FP-00001/28/07/2026'] && $r['amount'] === 196.95 && $r['currency'] === 'EUR' && $r['date'] === '2026-10-30'
    && $r['payer'] === 'KLIENT A SPRL' && $r['executed'] && $r['accounts'] === ['PL61109010140000071219812874'], 'PL : numer, kwota, data operacji (nie wydruku), płacący, rachunek');
$fr = "Avis de virement\nDonneur d'ordre\nBOULANGERIE DUPONT SRL\nBE68 5390 0754 7034\nIBAN bénéficiaire : 61109010140000071219812874\nMontant : 1.234,56 EUR\n"
    . "Date d'exécution : 29/10/2026\nCommunication : FP 00002 / 02 / 08 / 2026 et FP-00004/19/08/2026";
$r = P::parse($fr, $today);
expectPay($r['numbers'] === ['FP-00002/02/08/2026', 'FP-00004/19/08/2026'] && $r['amount'] === 1234.56 && $r['date'] === '2026-10-29' && $r['payer'] === 'BOULANGERIE DUPONT SRL',
    'FR : dwa numery, kwota z tysiącami, data wykonania, donneur d\'ordre w następnej linii');
expectPay(P::ownAccount($r['accounts'], ['PL 61 1090 1014 0000 0712 1981 2874']) === true, 'nasz rachunek : NRB bez PL = nasz IBAN');
$nl = "Overschrijving uitgevoerd\nOpdrachtgever: Bakkerij Peeters BV\nBedrag € 348,64\nUitvoeringsdatum 2026-10-28\nMededeling FP-OOO1O/O3/O8/2O26\nKosten 0,00";
$r = P::parse($nl, $today);
expectPay($r['numbers'] === ['FP-00010/03/08/2026'] && $r['amount'] === 348.64 && $r['date'] === '2026-10-28' && $r['accounts'] === [], 'NL po OCR : O → 0, kwota z € przed liczbą');
expectPay(P::ownAccount([], ['PL61109010140000071219812874']) === null && P::ownAccount(['BE68539007547034'], ['PL61109010140000071219812874']) === false, 'rachunek : brak → null, obcy → false');
expectPay(P::sameParty('KLIENT A SPRL', 'Klient A') && P::sameParty('Bakkerij Peeters BV', 'Peeters bakkerij') && !P::sameParty('SL FOOD', 'Beta sp. z o.o.'),
    'płacący ≈ klient : wspólne słowo (bez form prawnych, krótkie słowa pominięte)');
$r = P::parse("Kwota przelewu:\n2 500,00 PLN\nData: 03.11.2026\nData zlecenia 25.10.2026", $today);
expectPay($r['amount'] === 2500.0 && $r['currency'] === 'PLN' && $r['date'] === '2026-10-25', 'kwota w linii pod etykietą, PLN ; data z przyszłości pominięta');

// --- Baza jednorazowa ---------------------------------------------------------------------------------------------
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_payproof_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "payment_proof_test: PARTIAL (bez bazy : {$e->getMessage()}) — odczyt bez bazy OK\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (in_array($t, ['n_payment_proof', 'n_payment_proof_proforma'], true)) continue; // tworzone przez migrację 153 poniżej
        $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $migration = (string) file_get_contents(__DIR__ . '/../migrations/tfb/153_payment_proofs.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($migration) as $sql) $pdo->exec($sql);
    expectPay((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_payment_proof', 'n_payment_proof_proforma')")->fetchColumn() === 2,
        'migracja 153 (dwa razy) : tabele potwierdzeń');

    $day = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $dmY = date('d.m.Y', strtotime('-1 day'));
    $pdo->exec("INSERT INTO erp_company_data (id, proforma_company_name, proforma_company_bank) VALUES (1, 'SL FOOD', 'PL 61 1090 1014 0000 0712 1981 2874')");
    $pdo->exec("INSERT INTO customer (id, company, email_head, active) VALUES (1, 'Klient A SPRL', 'ksiegowosc@klient-a.example.invalid', 1), (2, 'Beta sp. z o.o.', 'beta@example.invalid', 1),
        (3, 'Gamma', 'gamma@example.invalid', 1)");
    $old = fn(int $d) => date('Y-m-d', strtotime("-{$d} days"));
    $pdo->exec("INSERT INTO document_proforma (id, doc_num, id_customer, established_date, status) VALUES
        (11, 'FP-00001/28/07/2026', 1, '{$old(95)}', 'issued'), (12, 'FP-00002/02/08/2026', 1, '{$old(90)}', 'issued'),
        (13, 'FP-00004/19/08/2026', 1, '{$old(73)}', 'issued'), (14, 'FP-00005/01/09/2026', 2, '{$old(60)}', 'issued'), (15, 'FP-00009/01/06/2026', 3, '{$old(150)}', 'paid')");
    $pdo->exec("INSERT INTO document_proforma_product (id_proforma, product_name, quantity, total_price_net) VALUES
        (11, 'Bagietka', 10, 150.00), (11, 'Croissant', 2, 46.95), (12, 'Cookie', 4, 62.68), (13, 'Tarta', 6, 212.81), (14, 'Chleb', 5, 100.00), (15, 'Chleb', 1, 10.00)");
    $pdo->exec("INSERT INTO communication_message (id_customer, id_proforma, message_type, status, created_at) VALUES (1, 11, 'proforma_reminder_1', 'sent', NOW()), (1, 11, 'proforma_reminder_2', 'sent', NOW())");
    $pdo->exec("INSERT INTO n_user_mail (id, id_user, folder, uid_validity, uid, from_email, from_name, subject, sent_at, has_attachments, id_customer, body_text, size) VALUES
        (501, 7, 'INBOX', 1, 1, 'ksiegowosc@klient-a.example.invalid', 'Księgowość Klient A', 'RE: Proforma FP-00001/28/07/2026 — potwierdzenie płatności', '{$day} 09:14:00', 1, 1, 'W załączeniu potwierdzenie przelewu.', 50000),
        (502, 7, 'INBOX', 1, 2, 'ksiegowosc@klient-a.example.invalid', 'Księgowość Klient A', 'Paiement', '{$yesterday} 17:40:00', 1, 1, 'Ci-joint la preuve de virement.', 900000),
        (503, 7, 'INBOX', 1, 3, 'ksiegowosc@klient-a.example.invalid', 'Księgowość Klient A', 'Zamówienie na przyszły tydzień', '{$yesterday} 10:00:00', 1, 1, 'Prosimy o 20 kartonów.', 4000),
        (504, 8, 'INBOX', 1, 1, 'ksiegowosc@klient-a.example.invalid', 'Księgowość Klient A', 'Przelew', '{$day} 08:00:00', 1, 1, 'przelew', 4000),
        (505, 7, 'INBOX', 1, 4, 'beta@example.invalid', 'Beta', 'Potwierdzenie przelewu', '{$day} 07:00:00', 1, 2, 'w załączniku', 4000),
        (506, 7, 'INBOX', 1, 5, 'gamma@example.invalid', 'Gamma', 'Przelew', '{$day} 07:00:00', 1, 3, 'przelew', 4000)");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo,
        WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { throw new RuntimeException('Brak e-maili w teście wpłat.'); }
        }]);
    $c = $builder->build();
    $svc = $c->get(PaymentProofService::class);
    $mailRow = fn(int $id) => $pdo->query("SELECT m.*, c.company FROM n_user_mail m LEFT JOIN customer c ON c.id = m.id_customer WHERE m.id = {$id}")->fetch();

    // --- Lista « Z poczty » ---------------------------------------------------------------------------------------
    $list = $svc->candidates(7);
    expectPay(array_column($list['rows'], 'mail_id') === [501, 505, 502], 'lista : słowa kluczowe, klient z proformą do zapłaty, tylko moja skrzynka (bez zamówienia, cudzej i klienta bez długu) : ' . json_encode(array_column($list['rows'], 'mail_id')));
    expectPay($list['rows'][0]['state'] === 'unread' && $list['rows'][0]['unpaid'] === ['count' => 3, 'sum' => 472.44], 'nieodczytane : 3 proformy klienta, 472,44 €');
    expectPay($svc->mailHint(7, 501)['unpaid'] === 3 && $svc->mailHint(7, 506) === null && $svc->mailHint(8, 501) === null, 'Poczta : « Zweryfikuj wpłatę » tylko dla klienta z długiem i we własnej skrzynce');

    // --- Odczyt PDF z tekstem (pdftotext) ---------------------------------------------------------------------------
    $tc = new TCPDF();
    $tc->setPrintHeader(false);
    $tc->setPrintFooter(false);
    $tc->AddPage();
    $tc->SetFont('dejavusans', '', 10);
    $tc->MultiCell(0, 0, "Potwierdzenie wykonania przelewu\nData operacji : {$dmY}\nZleceniodawca : KLIENT A SPRL\nRachunek odbiorcy : PL61 1090 1014 0000 0712 1981 2874\nOdbiorca : SL FOOD\nTytuł : FP-00001/28/07/2026\nKwota : 196,95 EUR\nStatus : zrealizowany", 0, 'L');
    $pdf = $tc->Output('', 'S');
    $v = $svc->readContent(7, $mailRow(501), 2, 'potwierdzenie_przelewu.pdf', 'application/pdf', $pdf, null);
    expectPay(!isset($v['need_ocr']) && $v['proof']['method'] === 'pdf_text' && $v['read']['amount'] === 196.95 && $v['read']['date'] === $yesterday
        && $v['read']['numbers'] === ['FP-00001/28/07/2026'] && $v['read']['account'] === 'PL61 … 2874' && $v['read']['payer'] === 'KLIENT A SPRL',
        'PDF tekstowy : kwota, data, numer, rachunek (zamaskowany), płacący');
    expectPay($v['suggested'] === [11] && $v['checks']['amount']['ok'] && $v['checks']['number'] === 'found' && $v['checks']['account'] === true && $v['checks']['payer'] === true,
        'podpowiedź : FP-00001, kwota = proforma, nasz rachunek, płacący = klient wiadomości');
    $p11 = array_column($v['proformas'], null, 'id')[11];
    expectPay(count($v['proformas']) === 3 && $p11['reminders'] === 2 && $p11['in_document'] && $p11['days'] === 95, 'proformy klienta : 3, przypomnienia 2, w dokumencie');
    $row = $pdo->query('SELECT * FROM n_payment_proof WHERE id_mail = 501')->fetch();
    expectPay(str_starts_with((string) $row['content'], 'p1:') && str_starts_with((string) $row['text_enc'], 'p1:') && str_starts_with((string) $row['payer_enc'], 'p1:')
        && !str_contains((string) $row['text_enc'], 'KLIENT') && $row['content_sha256'] === hash('sha256', $pdf), 'plik, tekst i płacący zaszyfrowane (dane klienta)');
    expectPay($svc->file(7, (int) $row['id'])['content'] === $pdf, 'plik dowodu : odszyfrowany = oryginał');
    expectPayFail(fn() => $svc->view(8, (int) $row['id']), 404, 'cudze potwierdzenie : niewidoczne');
    $list = $svc->candidates(7);
    expectPay($list['rows'][0]['state'] === 'ok' && $list['rows'][0]['match'] === ['FP-00001/28/07/2026'] && $list['rows'][0]['expected'] === 196.95, 'lista po odczycie : kwota zgodna');

    // --- Zatwierdzenie ----------------------------------------------------------------------------------------------
    expectPayFail(fn() => $svc->confirm(7, (int) $row['id'], ['proformas' => [14], 'amount' => 196.95]), 422, 'proforma innego klienta : odrzucona');
    expectPayFail(fn() => $svc->confirm(7, (int) $row['id'], ['proformas' => [], 'amount' => 196.95]), 422, 'bez proformy');
    expectPayFail(fn() => $svc->confirm(7, (int) $row['id'], ['proformas' => [11], 'amount' => 'dużo']), 422, 'kwota nie jest liczbą');
    $res = $svc->confirm(7, (int) $row['id'], ['proformas' => [11], 'paid_on' => $yesterday, 'amount' => '196,95']);
    expectPay($res['paid'] === 1 && $res['numbers'] === ['FP-00001/28/07/2026'], 'zatwierdzone : FP-00001');
    $pay = $pdo->query('SELECT * FROM n_proforma_payment WHERE id_proforma = 11')->fetch();
    expectPay($pdo->query('SELECT status FROM document_proforma WHERE id = 11')->fetchColumn() === 'paid' && $pay['paid_on'] === $yesterday && (float) $pay['amount'] === 196.95 && (int) $pay['recorded_by'] === 7,
        'proforma opłacona : status, data i kwota wpłaty (jak Płatności dnia)');
    expectPay((int) $pdo->query('SELECT COUNT(*) FROM n_payment_proof_proforma WHERE id_proforma = 11')->fetchColumn() === 1
        && $pdo->query("SELECT status FROM n_payment_proof WHERE id = {$row['id']}")->fetchColumn() === 'confirmed', 'dowód przy proformie, potwierdzenie zatwierdzone');
    expectPayFail(fn() => $svc->confirm(7, (int) $row['id'], ['proformas' => [12], 'amount' => 62.68]), 409, 'drugi raz : już zatwierdzone');
    expectPay(!in_array(501, array_column($svc->candidates(7)['rows'], 'mail_id'), true) && $svc->mailHint(7, 501)['proofs'][0]['status'] === 'confirmed', 'wiadomość znika z listy, Poczta : « Wpłata zatwierdzona »');
    $done = $svc->view(7, (int) $row['id']);
    expectPay($done['decided']['status'] === 'confirmed' && $done['decided']['proformas'] === ['FP-00001/28/07/2026'], 'okno po zatwierdzeniu : podsumowanie');
    $docs = $c->get(ClientSpaceRepository::class)->documents(1, 'proforma', null, '', 10);
    $note = array_column($docs, 'note', 'number')['FP-00001/28/07/2026'] ?? '';
    expectPay(str_contains((string) $note, 'opłacona ' . date('d.m.Y', strtotime('-1 day'))) && str_contains((string) $note, 'potwierdzenie przelewu z e-maila'), 'karta klienta : « opłacona … · potwierdzenie przelewu z e-maila » : ' . $note);

    // --- Zdjęcie (OCR w przeglądarce) : przelew za dwie proformy ------------------------------------------------------
    $jpg = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64);
    $need = $svc->readContent(7, $mailRow(502), 1, 'photo.jpg', 'image/jpeg', $jpg, null);
    expectPay(($need['need_ocr'] ?? false) && $need['kind'] === 'image', 'zdjęcie bez tekstu : OCR w przeglądarce');
    $ocr = "Avis de virement\nDonneur d'ordre : KLIENT A SPRL\nMontant : 275,49 EUR\nDate d'exécution : " . date('d/m/Y', strtotime('-1 day')) . "\nCommunication : FP-00002/02/08/2026 + FP-00004/19/08/2026";
    $v2 = $svc->readContent(7, $mailRow(502), 1, 'photo.jpg', 'image/jpeg', $jpg, $ocr);
    expectPay($v2['proof']['method'] === 'ocr' && $v2['suggested'] === [12, 13] && $v2['checks']['amount']['ok'] && $v2['checks']['account'] === null, 'OCR : dwie proformy, suma = kwota przelewu, brak rachunku');
    $res = $svc->confirm(7, $v2['proof']['id'], ['proformas' => [12, 13], 'paid_on' => $yesterday, 'amount' => 275.49]);
    $amounts = $pdo->query('SELECT id_proforma, amount FROM n_proforma_payment WHERE id_proforma IN (12, 13) ORDER BY id_proforma')->fetchAll(PDO::FETCH_KEY_PAIR);
    expectPay($res['paid'] === 2 && $res['diff'] === 0.0 && (float) $amounts[12] === 62.68 && (float) $amounts[13] === 212.81, 'dwie proformy opłacone, każda swoją kwotą');

    // --- « To nie jest wpłata » --------------------------------------------------------------------------------------
    $svc->reject(7, null, 505);
    expectPay(!in_array(505, array_column($svc->candidates(7)['rows'], 'mail_id'), true), 'wiadomość odrzucona bez odczytu : znika z listy');
    expectPayFail(fn() => $svc->reject(7, null, 504), 404, 'cudza wiadomość : nie do odrzucenia');
    $png = "\x89PNG\r\n\x1A\n" . str_repeat("\0", 32);
    expectPayFail(fn() => $svc->readContent(7, $mailRow(505), 0, 'x.txt', 'text/plain', 'zwykły tekst', null), 422, 'plik inny niż PDF / zdjęcie');
    $v3 = $svc->readContent(7, $mailRow(505), 1, 'skan.png', 'image/png', $png, "Kwota: 100,00 EUR\nTytuł FP-00005/01/09/2026");
    expectPay($v3['suggested'] === [14] && $v3['checks']['number'] === 'found', 'Beta : FP-00005 podpowiedziana');
    $svc->reject(7, $v3['proof']['id'], null);
    expectPay($pdo->query("SELECT status FROM n_payment_proof WHERE id = {$v3['proof']['id']}")->fetchColumn() === 'rejected'
        && $pdo->query('SELECT status FROM document_proforma WHERE id = 14')->fetchColumn() === 'issued', 'odrzucone : proforma dalej do zapłaty');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}

echo "payment_proof_test: OK\n";
