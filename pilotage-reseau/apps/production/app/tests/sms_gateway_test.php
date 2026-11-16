<?php
declare(strict_types=1);
/*
 * [313] Bramka SMS (SMSAPI, jak w CRM) i historia wysyłek klienta, na bazie jednorazowej (struktura skopiowana z erp) :
 *   - migracja 198 (dziennik n_sms_log, przeniesienie dotychczasowych SMS-ów, parametry sms.*) — powtarzalna, bez duplikatów ;
 *   - token : .env → erp_cles (smsapi_token) → Ustawienia (zaszyfrowany) ; nigdy w dzienniku ;
 *   - wysyłka tylko w trybie « live » z tokenem ; kopia testowa / bez tokenu / wyłącznik = tylko zapis ;
 *   - zapytanie SMSAPI : POST /sms.do, Bearer, to (E.164 bez +), from, utf-8 ; błąd SMSAPI ze wskazówką ;
 *   - numery : 0476… → +32476…, +48…, 0048…, « +32 (0)476… » ;
 *   - Ustawienia › SMS : token sprawdzony przez /profile przed zapisem, nadawca, wyłącznik, tylko administrator ;
 *   - historia klienta : e-maile (klient, proforma), Poczta, SMS — kolejność, filtry, statusy (LOG_ONLY = tylko zapis).
 *
 *   php tests/sms_gateway_test.php
 *
 * Żaden SMS ani e-mail nie wychodzi : HTTP SMSAPI zastąpione (SmsGateway::$http), baza jednorazowa erp_sms_test usuwana na końcu.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Client\ClientMessageLogService;
use App\Services\Communication\SmsSettingsService;
use App\Services\Logistics\Sms\SmsGateway;
use App\Services\Workshop\Migration\MigrationRunner;

function expectS(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_sms_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "sms_gateway_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
putenv('SMSAPI_TOKEN');
putenv('SMSAPI_BASE');
unset($_ENV['SMSAPI_TOKEN'], $_ENV['SMSAPI_BASE']);
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source)
        . " AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT IN ('n_sms_log', 'erp_cles')")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, login, password) VALUES
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', NULL, 'ada', 'x'), (2, 'Ola', 'Biuro', 'Ola B.', 'ola@example.invalid', NULL, 'ola', 'x')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
    $pdo->exec("INSERT INTO customer (id, name, surname, company, phone_head, email_head, active) VALUES (7, 'Jan', 'Klient', 'Sklep Testowy', '0476 12 34 56', 'sklep@example.invalid', 1),
        (8, 'Inny', 'Klient', 'Inny Sklep', '', 'inny@example.invalid', 1)");
    $pdo->exec("INSERT INTO document_proforma (id, id_customer, doc_num, status) VALUES (50, 7, 'PF/1/2026', 'issued')");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
        ('sms.provider', 'log_only', 'log_only', 'string', NULL, 'x', 'x')");
    // SMS-y sprzed bramki : przeniesione raz do dziennika.
    $pdo->exec("INSERT INTO n_delivery_notice (ship_date, id_customer, channel, language, recipient, body, status, provider, created_at) VALUES
        ('2026-11-02', 7, 'sms', 'fr', '0476123456', 'Livraison demain', 'logged', 'log_only', '2026-11-01 15:00:00'),
        ('2026-11-02', 7, 'mail', 'fr', 'sklep@example.invalid', 'Mail', 'queued', NULL, '2026-11-01 15:00:00')");
    $pdo->exec("INSERT INTO n_proforma_sms (id_proforma, id_order, recipient, language, body, status, provider, created_by, created_at) VALUES
        (50, 9, '0476123456', 'fr', 'Rappel proforma', 'logged', 'log_only', 'order_list:2', '2026-11-03 10:00:00')");
    foreach (['198_sms_gateway_client_log.sql', '198_sms_gateway_client_log.sql'] as $f) {
        foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
    }
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectS($count('SELECT COUNT(*) FROM n_sms_log') === 2 && $count("SELECT COUNT(*) FROM n_sms_log WHERE id_customer = 7 AND context = 'payment_reminder' AND ref_id = 50") === 1,
        'migracja 198 (dwa razy) : 2 dotychczasowe SMS-y przeniesione raz (dostawa + proforma), bez e-maili');
    expectS($pdo->query("SELECT value FROM n_prod_param WHERE code = 'sms.provider'")->fetchColumn() === 'smsapi'
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'sms.base'")->fetchColumn() === 'https://api.smsapi.com', 'parametry : sms.provider = smsapi, sms.base');

    $calls = [];
    $reply = [200, ['count' => 1, 'list' => [['id' => 'SMSAPI-1', 'points' => 0.16]]]];
    SmsGateway::$http = function (string $url, ?array $fields, string $token) use (&$calls, &$reply) {
        $calls[] = [$url, $fields, $token];
        if ($fields === null) return $token === 'zly-token-0123456789abcd' ? [401, ['error' => 101, 'message' => 'Authorization failed']] : [200, ['username' => 'slfood', 'points' => 120.5]];
        return $reply;
    };
    SmsGateway::$modeOverride = 'live';
    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Logistics\Sms\SmsTransport::class => DI\get(SmsGateway::class)]);
    $c = $b->build();
    $sms = $c->get(SmsGateway::class);

    // Numery.
    expectS($sms->e164('0476 12 34 56') === '+32476123456' && $sms->e164('+48 600 100 200') === '+48600100200' && $sms->e164('0048600100200') === '+48600100200'
        && $sms->e164('+32 (0)476 12 34 56') === '+32476123456' && $sms->e164('476123456') === '+32476123456' && $sms->e164('12') === null, 'numery E.164 (Belgia domyślnie, +48, 00, (0))');

    // Bez tokenu : tylko zapis, bez HTTP.
    $r = $sms->send('0476123456', 'Test bez tokenu', ['customer' => 7, 'context' => 'delivery_notice']);
    expectS($r['status'] === 'logged' && !$sms->delivers() && $sms->name() === 'log_only' && $calls === [] && str_contains((string) $sms->blocker(), 'tokenu'), 'bez tokenu : tylko zapis, nic nie wychodzi');

    // Token z erp_cles (wspólna tabela kluczy ERP).
    $pdo->exec("CREATE TABLE erp_cles (cle VARCHAR(80) NOT NULL PRIMARY KEY, valeur MEDIUMTEXT NULL, secret TINYINT(1) NOT NULL DEFAULT 1, indice VARCHAR(12) NULL,
        portail VARCHAR(20) NOT NULL DEFAULT 'commun', integration VARCHAR(60) NULL, maj_le DATETIME NOT NULL, maj_par VARCHAR(160) NULL)");
    $pdo->exec("INSERT INTO erp_cles (cle, valeur, portail, maj_le) VALUES ('smsapi_token', 'wspolny-token-erp-0123456789', 'commun', NOW())");
    $sms->forget();
    expectS($sms->token()['source'] === 'erp_cles' && $sms->delivers(), 'token z erp_cles (portal commun) → wysyłka');
    $c->get(App\Repositories\Workshop\ParamRepository::class)->setValue('sms.from', 'SLFOOD', 1);
    $r = $sms->send('0476 12 34 56', 'Dostawa jutro 8:00', ['customer' => 7, 'context' => 'delivery_notice', 'author' => 'user:2']);
    [$url, $fields, $token] = $calls[0];
    expectS($r['status'] === 'sent' && $r['provider_id'] === 'SMSAPI-1' && $url === 'https://api.smsapi.com/sms.do' && $token === 'wspolny-token-erp-0123456789'
        && $fields['to'] === '32476123456' && $fields['from'] === 'SLFOOD' && $fields['encoding'] === 'utf-8' && $fields['format'] === 'json', 'SMSAPI : POST /sms.do, Bearer, to bez +, nadawca, utf-8');
    $logged = $pdo->query("SELECT * FROM n_sms_log ORDER BY id DESC LIMIT 1")->fetch();
    expectS($logged['status'] === 'sent' && $logged['provider'] === 'smsapi' && $logged['provider_id'] === 'SMSAPI-1' && (int) $logged['id_customer'] === 7 && $logged['recipient'] === '+32476123456'
        && $count("SELECT COUNT(*) FROM n_sms_log WHERE CONCAT_WS(' ', body, error, recipient, created_by) LIKE '%wspolny-token%'") === 0, 'dziennik : wysłany, klient, numer E.164, bez tokenu');

    // Błąd SMSAPI.
    $reply = [200, ['error' => 14, 'message' => 'Invalid sender']];
    $r = $sms->send('+48600100200', 'Test', ['customer' => 7, 'context' => 'payment_reminder', 'ref' => 50]);
    expectS($r['status'] === 'failed' && str_contains($r['error'], '(14)') && str_contains($r['error'], 'nadawca'), 'błąd SMSAPI ze wskazówką (14 : nadawca)');
    $reply = [200, ['count' => 1, 'list' => [['id' => 'SMSAPI-2']]]];

    // Kopia testowa i wyłącznik : tylko zapis.
    SmsGateway::$modeOverride = 'log_only';
    $n = count($calls);
    $r = $sms->send('0476123456', 'Kopia testowa', ['customer' => 7]);
    expectS($r['status'] === 'logged' && count($calls) === $n && str_contains((string) $sms->blocker(), 'testowa'), 'kopia testowa (MAIL_DELIVERY_MODE ≠ live) : nic nie wychodzi');
    SmsGateway::$modeOverride = 'live';

    // Ustawienia › SMS.
    $pdo->exec('DELETE FROM erp_cles');
    $settings = $c->make(SmsSettingsService::class, ['sms' => $sms]);
    $sms->forget();
    $forbidden = null;
    try { $settings->saveKey(2, 'dobry-token-0123456789abcdef'); } catch (CustomException $e) { $forbidden = $e->getCode(); }
    expectS($forbidden === 403, 'token : tylko administrator');
    $refused = null;
    try { $settings->saveKey(1, 'zly-token-0123456789abcd'); } catch (CustomException $e) { $refused = $e->getMessage(); }
    expectS($refused !== null && str_contains($refused, '101') && $count("SELECT COUNT(*) FROM n_app_secret WHERE code = 'smsapi_token'") === 0, 'token odrzucony przez SMSAPI (/profile) : nie zapisany');
    $state = $settings->saveKey(1, 'dobry-token-0123456789abcdef');
    $stored = (string) $pdo->query("SELECT secret FROM n_app_secret WHERE code = 'smsapi_token'")->fetchColumn();
    expectS($state['source'] === 'settings' && $state['hint'] === 'cdef' && $state['profile']['ok'] && $state['profile']['points'] === 120.5 && !str_contains($stored, 'dobry-token')
        && !str_contains(json_encode($state), 'dobry-token'), 'token zapisany zaszyfrowany, w stanie tylko 4 ostatnie znaki, konto i punkty');
    $bad = null;
    try { $settings->save(1, ['from' => 'Za długi nadawca!!']); } catch (CustomException $e) { $bad = $e->getCode(); }
    $state = $settings->save(1, ['enabled' => false, 'from' => 'SL Food', 'prefix' => '48']);
    expectS($bad === 422 && !$state['enabled'] && !$state['delivers'] && $state['from'] === 'SL Food' && $state['prefix'] === '48', 'nadawca sprawdzany, wyłącznik, kraj domyślny');
    $test = $settings->test(1, '600100200');
    expectS(str_contains($test['message'], 'zapisany') && $pdo->query("SELECT recipient FROM n_sms_log ORDER BY id DESC LIMIT 1")->fetchColumn() === '+48600100200', 'SMS próbny przy wyłączniku : tylko zapis (kraj 48)');
    $settings->save(1, ['enabled' => true]);

    // Historia wysyłek klienta : e-maile (klient i przez proformę), Poczta, SMS.
    $pdo->exec("INSERT INTO communication_message (message_type, id_customer, id_proforma, recipient_email, subject_text, body_text, variables_json, status, provider_message_id, created_by, created_at, sent_at) VALUES
        ('delivery_details', 7, NULL, 'sklep@example.invalid', 'Votre livraison', 'Bonjour', '{}', 'sent', '<abc@x>', 'user:2', '2026-11-10 08:00:00', '2026-11-10 08:01:00'),
        ('proforma_reminder_2', NULL, 50, 'sklep@example.invalid', 'Rappel 2', 'Rappel', '{}', 'sent', 'LOG_ONLY:1', 'order_list:2', '2026-11-11 09:00:00', '2026-11-11 09:00:00'),
        ('proforma', 7, NULL, 'sklep@example.invalid', 'Brouillon', 'x', '{}', 'draft', NULL, 'user:2', '2026-11-12 09:00:00', NULL),
        ('invoice', 8, NULL, 'inny@example.invalid', 'Autre client', 'x', '{}', 'sent', '<d@x>', 'user:2', '2026-11-12 09:00:00', '2026-11-12 09:00:00')");
    $pdo->exec("INSERT INTO n_user_mail_sent (id_user, to_list, subject, body_text, mode, status, id_customer, created_at) VALUES (2, 'sklep@example.invalid', 'Question', 'Bonjour, une question', 'live', 'sent', 7, '2026-11-09 12:00:00')");
    $log = $c->get(ClientMessageLogService::class)->log(7, ['period' => 'all']);
    $kinds = array_map(fn($r) => $r['channel'] . ':' . $r['kind'], $log['rows']);
    $byKind = array_column($log['rows'], null, 'kind');
    expectS(in_array('email:delivery_details', $kinds, true) && in_array('email:proforma_reminder_2', $kinds, true) && in_array('email:mailbox', $kinds, true)
        && !in_array('email:invoice', $kinds, true) && !in_array('email:proforma', $kinds, true) && in_array('sms:payment_reminder', $kinds, true), 'historia : e-maile klienta i przez proformę, Poczta, SMS ; bez szkiców i innych klientów');
    $ats = array_column($log['rows'], 'at');
    $sorted = $ats; rsort($sorted);
    expectS($ats === $sorted && $byKind['proforma_reminder_2']['status'] === 'logged' && $byKind['delivery_details']['status'] === 'sent'
        && $byKind['delivery_details']['author'] === 'Ola Biuro' && $byKind['mailbox']['author'] === 'Ola Biuro' && $byKind['proforma_reminder_2']['label'] === 'Proforma : przypomnienie 2/5',
        'historia : od najnowszych, LOG_ONLY = tylko zapis, autor po imieniu, etykiety rodzajów');
    $onlySms = $c->get(ClientMessageLogService::class)->log(7, ['channel' => 'sms', 'period' => 'all']);
    expectS($onlySms['rows'] && !array_filter($onlySms['rows'], fn($r) => $r['channel'] !== 'sms') && $onlySms['counts']['sms'] >= 1, 'filtr : tylko SMS ; liczniki 30 dni');

    echo "sms_gateway_test: OK\n";
} finally {
    SmsGateway::$http = null;
    SmsGateway::$modeOverride = null;
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
