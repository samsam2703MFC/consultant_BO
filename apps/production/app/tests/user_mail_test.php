<?php
declare(strict_types=1);
/*
 * Poczta użytkownika : rozbiór MIME (nagłówki zakodowane, multipart, załączniki, ISO-8859-2), nazwy folderów IMAP,
 * szyfrowanie haseł ; z lokalnym serwerem IMAP (MAIL_TEST_IMAP=host:port:login:hasło, np. Dovecot) : ustawienia,
 * odrzucenie serwera z sieci wewnętrznej, synchronizacja, klient po adresie, odczyt (oznaczenie jako przeczytana),
 * załącznik, wyszukiwanie pełnotekstowe, wysyłka w trybie log_only (nic nie wychodzi) i odpowiedź.
 * Wszystko, co test tworzy w ERP, jest usuwane.
 *   MAIL_TEST_IMAP=127.0.0.1:1993:jan@slfood.test:haslo php tests/user_mail_test.php
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Exceptions\CustomException;
use App\Repositories\Mail\UserMailRepository;
use App\Services\Mail\ImapClient;
use App\Services\Mail\MailboxSecretCipher;
use App\Services\Mail\MimeMessage;
use App\Services\Mail\UserMailService;

function expectMail(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// --- MIME, bez bazy -------------------------------------------------------------------------------
$pdf = "%PDF-1.4\n% test\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
$alt = "From: =?UTF-8?B?" . base64_encode('Zakład Żółw') . "?= <Biuro@Example.PL>\r\nTo: jan@slfood.test, \"Kowalski, Jan\" <jk@example.pl>\r\n"
    . "Subject: =?UTF-8?Q?Zam=C3=B3wienie_nr_12?= =?UTF-8?Q?_=E2=80=94_pilne?=\r\nDate: Fri, 23 Oct 2026 14:05:00 +0200\r\nMessage-ID: <abc@example.pl>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\nPreambuła\r\n--MIX\r\nContent-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
    . "--ALT\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nDzie=C5=84 dobry,\r\nprosz=C4=99 o potwierdzenie.=\r\n Pozdrawiam\r\n"
    . "--ALT\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Dzień dobry,<br>proszę o <b>potwierdzenie</b>.</p><img src=\"cid:logo1\">\r\n--ALT--\r\n"
    . "--MIX\r\nContent-Type: image/png\r\nContent-ID: <logo1>\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')) . "\r\n"
    . "--MIX\r\nContent-Type: application/pdf; name=\"x.pdf\"\r\nContent-Disposition: attachment; filename*=UTF-8''Faktura%20%C5%BC%C3%B3%C5%82ta.pdf\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($pdf)) . "--MIX--\r\n";
$m = MimeMessage::parse($alt);
expectMail($m->header('subject') === 'Zamówienie nr 12 — pilne', 'temat zakodowany (Q, dwa słowa) : ' . $m->header('subject'));
$from = $m->addresses('from')[0];
expectMail($from['email'] === 'biuro@example.pl' && $from['name'] === 'Zakład Żółw', 'nadawca : adres małymi literami, nazwa zdekodowana');
expectMail(array_column($m->addresses('to'), 'email') === ['jan@slfood.test', 'jk@example.pl'], 'adresaci, przecinek w cudzysłowie');
expectMail($m->date() === date('Y-m-d H:i:s', strtotime('2026-10-23 14:05:00 +0200')) && $m->messageId() === '<abc@example.pl>', 'data i Message-ID');
expectMail(str_contains((string) $m->text, 'proszę o potwierdzenie. Pozdrawiam'), 'tekst quoted-printable z miękkim łamaniem');
expectMail(str_contains((string) $m->html, '<b>potwierdzenie</b>'), 'HTML');
$files = array_values(array_filter($m->attachments, fn($a) => !$a['inline']));
expectMail(count($files) === 1 && $files[0]['filename'] === 'Faktura żółta.pdf' && $files[0]['content'] === $pdf, 'załącznik PDF : nazwa RFC 2231, treść');
expectMail(count(array_filter($m->attachments, fn($a) => $a['inline'] && $a['cid'] === 'logo1')) === 1, 'obraz w treści (cid) osobno');
$latin2 = "From: hurt@example.pl\r\nSubject: =?ISO-8859-2?Q?Cennik_m=B1ki?=\r\nContent-Type: text/plain; charset=ISO-8859-2\r\n"
    . "Content-Transfer-Encoding: quoted-printable\r\n\r\nM=B1ka pszenna T550 =BF=F3=B3ta\r\n";
$l = MimeMessage::parse($latin2);
expectMail($l->header('subject') === 'Cennik mąki' && trim((string) $l->text) === 'Mąka pszenna T550 żółta', 'ISO-8859-2 : temat i treść');
expectMail(MimeMessage::htmlToText('<style>p{}</style><p>A&amp;B</p><p>C<br>D</p>') === "A&B\nC\nD", 'HTML → tekst');
expectMail(ImapClient::utf7Encode('Elementy wysłane') === 'Elementy wys&AUI-ane' && ImapClient::utf7Decode('Elementy wys&AUI-ane') === 'Elementy wysłane'
    && ImapClient::utf7Decode('A&-B') === 'A&B', 'nazwy folderów IMAP (UTF-7)');

try {
    $container = require __DIR__ . '/../App/Core/bootstrap.php';
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    echo "user_mail_test: OK (MIME only, no database: {$e->getMessage()})\n";
    exit(0);
}
$cipher = $container->get(MailboxSecretCipher::class);
$enc = $cipher->encrypt('hasło-1');
expectMail(str_starts_with($enc, 'm1:') && !str_contains($enc, 'hasło') && $cipher->decrypt($enc) === 'hasło-1' && $cipher->decrypt('x') === null, 'hasło zaszyfrowane, odczyt');

$imap = getenv('MAIL_TEST_IMAP') ?: '';
if ($imap === '' || !$pdo->query("SHOW TABLES LIKE 'n_user_mailbox'")->fetchColumn()) {
    echo "user_mail_test: OK (MIME + cipher ; set MAIL_TEST_IMAP=host:port:login:password for the mailbox part)\n";
    exit(0);
}
[$host, $port, $login, $password] = explode(':', $imap, 4);
$service = $container->get(UserMailService::class);
$repo = $container->get(UserMailRepository::class);
$userId = (int) $pdo->query('SELECT id FROM user WHERE id NOT IN (SELECT id_user FROM n_user_mailbox) ORDER BY id LIMIT 1')->fetchColumn();
$customer = $pdo->query("SELECT id, email_head FROM customer WHERE email_head LIKE '%@%.%' AND email_head NOT LIKE '%,%' AND email_head NOT LIKE '%;%' ORDER BY id LIMIT 1")->fetch();
expectMail($userId > 0 && $customer, 'dane testowe : użytkownik bez skrzynki, klient z adresem');
$customerEmail = strtolower(trim($customer['email_head']));
$run = 'T' . bin2hex(random_bytes(3));
putenv('MAIL_DELIVERY_MODE=log_only');
putenv('MAIL_ALLOW_PRIVATE_HOSTS');
$base = ['email' => $login, 'display_name' => 'Jan Test', 'provider' => 'other', 'imap_host' => $host, 'imap_port' => (int) $port, 'imap_security' => 'ssl',
    'imap_username' => $login, 'imap_password' => $password, 'smtp_host' => $host, 'smtp_port' => 1025, 'smtp_security' => 'starttls', 'smtp_username' => $login,
    'tls_verify' => false, 'signature' => "Jan Test\nSL Food"];
try {
    try { $service->sync($userId); expectMail(false, 'synchronizacja bez skrzynki odrzucona'); } catch (CustomException $e) { expectMail($e->getCode() === 409, 'bez skrzynki : 409'); }
    try { $service->saveMailbox($userId, ['email' => 'zly-adres', 'imap_host' => 'imap..pl'] + $base); expectMail(false, 'zły adres'); }
    catch (CustomException $e) { expectMail(isset($e->getErrors()['fields']['email'], $e->getErrors()['fields']['imap_host']), 'walidacja : adres i serwer'); }
    try { $service->saveMailbox($userId, $base); expectMail(false, 'serwer w sieci wewnętrznej odrzucony'); }
    catch (CustomException $e) { expectMail(str_contains((string) ($e->getErrors()['fields']['imap_host'] ?? ''), 'wewnętrznej'), 'localhost odrzucony bez MAIL_ALLOW_PRIVATE_HOSTS'); }
    putenv('MAIL_ALLOW_PRIVATE_HOSTS=1');
    $state = $service->saveMailbox($userId, ['smtp_password' => ''] + $base);
    $saved = $repo->mailbox($userId);
    expectMail($state['configured'] && $state['mailbox']['has_imap_password'] && $state['mailbox']['has_smtp_password'] && !isset($state['mailbox']['imap_secret'])
        && str_starts_with((string) $saved['imap_secret'], 'm1:') && $saved['smtp_secret'] === $saved['imap_secret'], 'zapisano ; hasło SMTP = IMAP (ten sam login), zaszyfrowane, nie wraca');
    $test = $service->test($userId);
    expectMail($test['imap']['ok'] && str_contains($test['imap']['message'], 'Sent') && !$test['smtp']['ok'], 'test : IMAP OK z folderem wysłanych, SMTP bez serwera = błąd');

    // trzy wiadomości w skrzynce
    $client = new ImapClient($host, (int) $port, 'ssl', false);
    $client->connect();
    $client->login($login, $password);
    $client->append('INBOX', str_replace(['<abc@example.pl>', 'Zam=C3=B3wienie_nr_12?='], ["<{$run}a@example.pl>", "Zam=C3=B3wienie_nr_12_{$run}?="], $alt)
        . '', []);
    $client->append('INBOX', "From: Klient <{$customerEmail}>\r\nTo: {$login}\r\nSubject: Reklamacja {$run}\r\nMessage-ID: <{$run}b@example.pl>\r\n"
        . "Date: Sat, 24 Oct 2026 07:00:00 +0200\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nRogaliki przyszły zgniecione, partia {$run}.\r\n", []);
    $client->append('INBOX', $latin2, ['\\Seen']);
    $client->logout();

    $sync = $service->sync($userId);
    expectMail($sync['new'] >= 3 && isset($sync['folders']['SENT']), 'synchronizacja : nowe wiadomości, folder wysłanych');
    $again = $service->sync($userId);
    expectMail($again['new'] === 0, 'druga synchronizacja : nic nowego');
    $list = $service->list($userId, 'INBOX', $run, 1);
    $subjects = array_column($list['rows'], 'subject');
    expectMail(count($list['rows']) === 2 && in_array("Reklamacja {$run}", $subjects, true) && in_array("Zamówienie nr 12 {$run} — pilne", $subjects, true), 'wyszukiwanie pełnotekstowe po znaczniku : 2 wiadomości');
    $complaint = array_values(array_filter($list['rows'], fn($r) => $r['subject'] === "Reklamacja {$run}"))[0];
    expectMail($complaint['customer']['id'] === (int) $customer['id'] && !$complaint['seen'] && str_contains((string) $complaint['snippet'], 'zgniecione'), 'klient rozpoznany po adresie, nieprzeczytana, fragment treści');
    $order = array_values(array_filter($list['rows'], fn($r) => str_starts_with((string) $r['subject'], 'Zamówienie')))[0];
    expectMail($order['attachments_flag'] && $order['from'] === 'biuro@example.pl' && $order['from_name'] === 'Zakład Żółw', 'lista : załącznik, nadawca');
    $unread = $service->unread($userId);
    $opened = $service->open($userId, $order['id']);
    expectMail($opened['offline'] === null && str_contains((string) $opened['html'], 'data:image/png;base64') && count($opened['attachments']) === 1
        && $opened['attachments'][0]['filename'] === 'Faktura żółta.pdf' && str_contains($opened['text'], 'potwierdzenie'), 'odczyt : HTML z obrazem w treści, załącznik, tekst');
    expectMail($service->unread($userId) === $unread - 1, 'otwarta = przeczytana (licznik)');
    $client = new ImapClient($host, (int) $port, 'ssl', false);
    $client->connect();
    $client->login($login, $password);
    $client->select('INBOX', true);
    $uid = (int) $pdo->query('SELECT uid FROM n_user_mail WHERE id = ' . (int) $order['id'])->fetchColumn();
    expectMail(in_array('\\Seen', $client->fetchFlags([$uid])[$uid] ?? [], true), 'flaga \\Seen ustawiona w skrzynce');
    $client->logout();
    $file = $service->attachment($userId, $order['id'], $opened['attachments'][0]['index']);
    expectMail($file['content'] === $pdf && $file['mime'] === 'application/pdf', 'pobranie załącznika');
    expectMail(count($service->list($userId, 'INBOX', 'zgniecione', 1)['rows']) >= 1 && count($repo->search($userId, "Reklamacja {$run}", 5)) === 1, 'treść w wyszukiwarce (lista i górny pasek)');
    expectMail(count($service->list($userId, 'INBOX', 'mąka', 1)['rows']) >= 1, 'polskie znaki w wyszukiwaniu');

    try { $service->send($userId, ['to' => 'zly@', 'subject' => '', 'text' => '']); expectMail(false, 'zła wiadomość'); }
    catch (CustomException $e) { expectMail(count(array_intersect(['to', 'subject', 'text'], array_keys($e->getErrors()['fields'] ?? []))) === 3, 'walidacja wysyłki'); }
    $sent = $service->send($userId, ['to' => $customerEmail, 'subject' => "Re: Reklamacja {$run}", 'text' => "Dzień dobry,\nwysyłamy nową partię.\n\n-- \nJan Test",
        'reply_to_id' => $complaint['id'], 'attachments' => [['name' => 'protokol.pdf', 'data' => 'data:application/pdf;base64,' . base64_encode($pdf)]]]);
    $log = $pdo->query('SELECT * FROM n_user_mail_sent WHERE id = ' . (int) $sent['id'])->fetch();
    expectMail($sent['mode'] === 'log_only' && $log['status'] === 'sent' && $log['mode'] === 'log_only' && (int) $log['id_reply_to'] === $complaint['id']
        && (int) $log['id_customer'] === (int) $customer['id'] && str_contains((string) $log['attachments'], 'protokol.pdf') && str_starts_with((string) $log['message_id'], '<'),
        'odpowiedź w trybie log_only : dziennik, klient, załącznik, nic nie wychodzi');
    $erp = $service->list($userId, 'ERP', $run, 1);
    expectMail($erp['total'] === 1 && $erp['rows'][0]['mode'] === 'log_only', 'folder « Wysłane z ERP »');
    $global = $container->get(App\Services\Search\GlobalSearchService::class);
    expectMail(true, 'global search service loads');
} finally {
    $pdo->prepare('DELETE FROM n_user_mail_sent WHERE id_user = ?')->execute([$userId]);
    $service->deleteMailbox($userId);
    putenv('MAIL_ALLOW_PRIVATE_HOSTS');
}
expectMail(!$repo->mailbox($userId) && (int) $pdo->query("SELECT COUNT(*) FROM n_user_mail WHERE id_user = {$userId}")->fetchColumn() === 0, 'sprzątanie : skrzynka i wiadomości usunięte');
echo "user_mail_test: OK\n";
