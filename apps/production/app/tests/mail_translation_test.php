<?php
declare(strict_types=1);
/*
 * Poczta : tłumacz DeepL i stopki e-maili w językach klientów (27/10/2026).
 *  - rozpoznanie języka (pl, fr, nl, en, de ; cytaty pominięte, za krótki tekst = brak) ;
 *  - treść wysyłana : tekst, podpis, stopka (mniejsza, szara), cytat ; HTML bez wstrzykniętych znaczników ;
 *  - wygląd e-maili automatycznych : stopka w języku wiadomości zamiast « SL Food · L'Atelier By » ;
 *  - DeepL (transport testowy, nic nie wychodzi) : adres Free / Pro, nagłówek klucza, EN → EN-GB, puste teksty pominięte,
 *    błędy 403 / 456, brak klucza, limit dzienny na osobę, licznik w n_translation_log ;
 *  - klucz wpisany w Ustawieniach : sprawdzony w DeepL, zaszyfrowany, 4 ostatnie znaki, usunięcie ;
 *  - stopki : język → angielski → polski, parametry Poczta / e-maile automatyczne, język szablonu albo klienta ;
 *  - kolejka Komunikacji : stopka w języku szablonu przekazana do transportu.
 * Wszystko, co test tworzy w ERP, jest usuwane (stopki, sekret, parametry i dziennik przywrócone).
 *   php tests/mail_translation_test.php
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Exceptions\CustomException;
use App\Services\Communication\CommunicationEmailDeliveryWorker;
use App\Services\Communication\MailDeliveryPolicy;
use App\Services\Communication\MailLayout;
use App\Services\Communication\SmtpTransport;
use App\Services\Mail\MailFooterService;
use App\Services\Mail\UserMailService;
use App\Services\Translation\DeepLTranslator;
use App\Services\Translation\LanguageGuess;

function expectTr(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectTrError(callable $fn, int $code, string $needle, string $message): void
{
    try { $fn(); } catch (CustomException $e) {
        expectTr($e->getCode() === $code && str_contains($e->getMessage(), $needle), $message . ' (got ' . $e->getCode() . ' ' . $e->getMessage() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (no exception)');
}

final class FooterRecordingTransport implements SmtpTransport
{
    public array $sent = [];
    public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
    {
        $this->sent[] = compact('to', 'subject', 'body', 'footer');
        return MailDeliveryPolicy::LOG_ONLY_PREFIX . $messageId;
    }
}

// --- bez bazy : język, treść, wygląd -----------------------------------------------------------------
expectTr(LanguageGuess::guess("Bonjour,\nmerci pour votre commande, la livraison est prévue demain. Cordialement") === 'fr', 'French');
expectTr(LanguageGuess::guess("Dzień dobry,\nprosimy o potwierdzenie zamówienia na jutro. Pozdrawiam") === 'pl', 'Polish');
expectTr(LanguageGuess::guess("Goedemorgen,\ngraag een bevestiging van de bestelling voor morgen. Met vriendelijke groeten") === 'nl', 'Dutch');
expectTr(LanguageGuess::guess("Hello,\nplease confirm the order for tomorrow, thank you. Kind regards") === 'en', 'English');
expectTr(LanguageGuess::guess("Guten Tag,\nbitte bestätigen Sie die Bestellung für morgen. Vielen Dank und freundliche Grüße") === 'de', 'German');
expectTr(LanguageGuess::guess('OK') === null && LanguageGuess::guess('') === null, 'too short → unknown');
expectTr(LanguageGuess::guess("Merci, c'est noté pour la commande.\n> Dzień dobry, prosimy o potwierdzenie zamówienia na jutro, pozdrawiam serdecznie") === 'fr', 'quoted lines ignored');

$c = UserMailService::compose("Bonjour,\nmerci <b>!</b>\n\n", 'Anna K.', "SL Food\nTVA : PL123", "27.10.2026, Jan napisał(a) :\n> Dzień dobry\n> <script>x</script>");
expectTr($c['text'] === "Bonjour,\nmerci <b>!</b>\n\n-- \nAnna K.\n\nSL Food\nTVA : PL123\n\n27.10.2026, Jan napisał(a) :\n> Dzień dobry\n> <script>x</script>", 'plain text : text, signature, footer, quote');
expectTr(str_contains($c['html'], 'merci &lt;b&gt;!&lt;/b&gt;') && !str_contains($c['html'], '<script>') && str_contains($c['html'], '&lt;script&gt;x'), 'HTML escaped');
expectTr(str_contains($c['html'], 'font-size:11px') && str_contains($c['html'], 'SL Food<br />') && str_contains($c['html'], 'border-left:3px') && !str_contains($c['html'], '&gt; Dzień'), 'footer small and grey, quote without « > »');
$bare = UserMailService::compose('Tekst', null, null, null);
expectTr($bare['text'] === 'Tekst' && !str_contains($bare['html'], 'border-top'), 'nothing appended without signature, footer, quote');
expectTr(str_contains(MailLayout::html('Treść', "Firma <x>\nBTW : PL1"), 'Firma &lt;x&gt;<br />') && !str_contains(MailLayout::html('Treść', "Firma\nBTW"), "SL Food · L'Atelier By"), 'automatic e-mail : footer instead of the default line');
expectTr(str_contains(MailLayout::html('Treść'), "SL Food · L'Atelier By") && str_contains(MailLayout::html('Treść', '  '), "SL Food · L'Atelier By"), 'default line without footer');

try {
    $container = require __DIR__ . '/../App/Core/bootstrap.php';
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    echo "mail_translation_test: OK (no database: {$e->getMessage()})\n";
    exit(0);
}
if (!$pdo->query("SHOW TABLES LIKE 'n_mail_footer'")->fetchColumn()) {
    echo "mail_translation_test: OK (without database part : run php Bin/tfb_migrate.php apply)\n";
    exit(0);
}

$userId = (int) $pdo->query('SELECT id FROM user ORDER BY id LIMIT 1')->fetchColumn();
$before = [
    'log' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM n_translation_log')->fetchColumn(),
    'message' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM communication_message')->fetchColumn(),
    'footers' => $pdo->query('SELECT lang, body, updated_by FROM n_mail_footer')->fetchAll(),
    'secret' => $pdo->query("SELECT * FROM n_app_secret WHERE code = 'deepl_api_key'")->fetch() ?: null,
    'params' => $pdo->query("SELECT code, value FROM n_prod_param WHERE code IN ('mail.footer_user_mail', 'mail.footer_system', 'translate.daily_chars_per_user')")->fetchAll(PDO::FETCH_KEY_PAIR),
    'env' => getenv('DEEPL_API_KEY'),
];
$calls = [];
$reply = [200, ''];
DeepLTranslator::$transport = function (string $method, string $url, array $headers, ?array $body) use (&$calls, &$reply) {
    $calls[] = compact('method', 'url', 'headers', 'body');
    if ($reply[0] !== 200) return $reply;
    if (str_ends_with($url, '/v2/usage')) return [200, json_encode(['character_count' => 1234, 'character_limit' => 500000])];
    return [200, json_encode(['translations' => array_map(fn($t) => ['detected_source_language' => 'PL', 'text' => '[' . $body['target_lang'] . '] ' . $t], $body['text'])])];
};
$setEnv = function (?string $key) {
    if ($key === null) { putenv('DEEPL_API_KEY'); unset($_ENV['DEEPL_API_KEY']); }
    else { putenv('DEEPL_API_KEY=' . $key); $_ENV['DEEPL_API_KEY'] = $key; }
};
// nowe instancje z nową pamięcią parametrów (ParamRepository trzyma wartości w pamięci)
$fresh = fn(string $class) => $container->make($class, ['params' => $container->make(App\Repositories\Workshop\ParamRepository::class)]);

try {
    $pdo->exec("DELETE FROM n_app_secret WHERE code = 'deepl_api_key'");
    $pdo->exec("UPDATE n_prod_param SET value = '60000' WHERE code = 'translate.daily_chars_per_user'");

    // --- DeepL : brak klucza
    $setEnv(null);
    $tr = $fresh(DeepLTranslator::class);
    expectTr(!$tr->configured() && $tr->plan() === null && $tr->usage() === null, 'no key : not configured');
    expectTrError(fn() => $tr->translate(['Dzień dobry'], 'fr', 'mail_out', $userId), 409, 'DEEPL_API_KEY', 'no key : clear message');

    // --- klucz Free z .env
    $setEnv('00000000-1111-2222-3333-444444444444:fx');
    $tr = $fresh(DeepLTranslator::class);
    expectTr($tr->configured() && $tr->plan() === 'free' && $tr->source() === 'env' && $tr->hint() === '4:fx', 'free key from .env');
    $out = $tr->translate(['Temat', '', 'Treść wiadomości'], 'en', 'mail_out', $userId);
    $last = end($calls);
    expectTr($last['url'] === 'https://api-free.deepl.com/v2/translate' && in_array('Authorization: DeepL-Auth-Key 00000000-1111-2222-3333-444444444444:fx', $last['headers'], true), 'free endpoint and auth header');
    expectTr($last['body']['target_lang'] === 'EN-GB' && $last['body']['text'] === ['Temat', 'Treść wiadomości'], 'EN → EN-GB, empty text not sent');
    expectTr($out[0]['text'] === '[EN-GB] Temat' && $out[1] === ['text' => '', 'source' => null] && $out[2]['text'] === '[EN-GB] Treść wiadomości' && $out[2]['source'] === 'pl', 'results in order, source detected');
    $log = $pdo->query('SELECT * FROM n_translation_log WHERE id > ' . $before['log'] . ' ORDER BY id DESC LIMIT 1')->fetch();
    expectTr($log && (int) $log['id_user'] === $userId && (int) $log['chars'] === mb_strlen('Temat') + mb_strlen('Treść wiadomości') && $log['source_lang'] === 'pl' && $log['target_lang'] === 'en' && $log['context'] === 'mail_out', 'usage logged');
    expectTr($tr->usage() === ['count' => 1234, 'limit' => 500000], 'account usage');
    $tr->translate(['Bonjour'], 'pl', 'mail_in', $userId, 'fr');
    expectTr(end($calls)['body']['source_lang'] === 'FR', 'source language passed');
    expectTrError(fn() => $tr->translate(['x'], 'xx', 'mail_out', $userId), 422, 'język', 'unknown target');
    expectTrError(fn() => $tr->translate([str_repeat('a', 30001)], 'fr', 'mail_out', $userId), 413, '30 000', 'too long');
    $reply = [456, ''];
    expectTrError(fn() => $tr->translate(['Dzień dobry'], 'fr', 'mail_out', $userId), 502, 'Limit znaków', 'DeepL quota');
    $reply = [403, ''];
    expectTrError(fn() => $tr->translate(['Dzień dobry'], 'fr', 'mail_out', $userId), 502, 'Klucz DeepL odrzucony', 'DeepL key refused');
    $reply = [200, ''];
    $pdo->exec("UPDATE n_prod_param SET value = '50' WHERE code = 'translate.daily_chars_per_user'");
    $capped = $fresh(DeepLTranslator::class);
    expectTrError(fn() => $capped->translate([str_repeat('b', 40)], 'fr', 'mail_out', $userId), 429, 'Dzienny limit', 'daily cap per person');
    $pdo->exec("UPDATE n_prod_param SET value = '60000' WHERE code = 'translate.daily_chars_per_user'");

    // --- klucz Pro wpisany w Ustawieniach
    $setEnv(null);
    $tr = $fresh(DeepLTranslator::class);
    expectTrError(fn() => $tr->saveKey('abc', $userId), 422, 'klucz DeepL', 'malformed key');
    $reply = [403, ''];
    expectTrError(fn() => $tr->saveKey('99999999-8888-7777-6666-555555555555', $userId), 422, 'odrzucony', 'refused key not saved');
    expectTr(!$pdo->query("SELECT 1 FROM n_app_secret WHERE code = 'deepl_api_key'")->fetchColumn(), 'nothing saved after refusal');
    $reply = [200, ''];
    $tr->saveKey('99999999-8888-7777-6666-555555555555', $userId);
    $row = $pdo->query("SELECT * FROM n_app_secret WHERE code = 'deepl_api_key'")->fetch();
    expectTr($row && str_starts_with($row['secret'], 'm1:') && !str_contains($row['secret'], '9999') && $row['hint'] === '5555', 'key encrypted, hint only');
    $tr = $fresh(DeepLTranslator::class);
    expectTr($tr->configured() && $tr->source() === 'settings' && $tr->plan() === 'pro', 'key from Settings');
    $tr->translate(['Dzień dobry'], 'nl', 'footer', $userId);
    expectTr(end($calls)['url'] === 'https://api.deepl.com/v2/translate' && in_array('Authorization: DeepL-Auth-Key 99999999-8888-7777-6666-555555555555', end($calls)['headers'], true), 'pro endpoint with decrypted key');
    $tr->saveKey('', $userId);
    expectTr(!$pdo->query("SELECT 1 FROM n_app_secret WHERE code = 'deepl_api_key'")->fetchColumn() && !$fresh(DeepLTranslator::class)->configured(), 'key removed');

    // --- stopki
    $pdo->exec('DELETE FROM n_mail_footer');
    $footers = $fresh(MailFooterService::class);
    expectTr($footers->footer('fr') === null, 'no footer');
    $repo = $container->get(App\Repositories\Mail\MailFooterRepository::class);
    $repo->save('pl', "SL Food\nNIP : 123", $userId);
    $repo->save('en', "SL Food\nVAT : PL123", $userId);
    $repo->save('fr', "SL Food\nTVA : PL123", $userId);
    $footers = $fresh(MailFooterService::class);
    expectTr($footers->footer('fr') === "SL Food\nTVA : PL123" && $footers->footer('nl') === "SL Food\nVAT : PL123" && $footers->footer(null) === "SL Food\nVAT : PL123", 'language → English → Polish');
    $pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'mail.footer_user_mail'");
    $footers = $fresh(MailFooterService::class);
    expectTr($footers->forUserMail('fr') === null, 'Poczta footer off');
    $pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'mail.footer_user_mail'");
    $nl = (int) $pdo->query("SELECT id FROM communication_template WHERE language_code = 'nl' ORDER BY id LIMIT 1")->fetchColumn();
    $frCustomer = (int) $pdo->query("SELECT id FROM customer WHERE communication_lang = 'fr' ORDER BY id LIMIT 1")->fetchColumn();
    $repo->save('nl', "SL Food\nbtw : PL123", $userId);
    $footers = $fresh(MailFooterService::class);
    if ($nl) expectTr($footers->forMessage(['template_id' => $nl, 'id_customer' => $frCustomer ?: null]) === "SL Food\nbtw : PL123", 'automatic e-mail : template language first');
    if ($frCustomer) expectTr($footers->forMessage(['template_id' => null, 'id_customer' => $frCustomer]) === "SL Food\nTVA : PL123", 'automatic e-mail : customer language');
    $state = $footers->state($userId);
    expectTr($state['footers']['nl'] === "SL Food\nbtw : PL123" && str_contains($state['suggestions']['fr'], 'confidentiels') && str_contains($state['suggestions']['pl'], 'NIP'), 'settings state with company suggestions');
    $saved = $footers->save($userId, ['footers' => ['pl' => "  A\n\n\n\nB  ", 'fr' => '', 'nl' => 'C', 'en' => 'D'], 'user_mail' => true, 'system' => false]);
    expectTr($saved['footers']['pl'] === "A\n\nB" && $saved['footers']['fr'] === '' && $saved['system'] === false && $saved['user_mail'] === true, 'save : trimmed, empty removed, switches');
    expectTr($fresh(MailFooterService::class)->forMessage(['template_id' => $nl ?: null]) === null, 'automatic e-mails footer off');
    expectTrError(fn() => $footers->save($userId, ['footers' => ['pl' => str_repeat('x', 1501)]]), 422, 'Popraw', 'footer too long');

    // --- kolejka Komunikacji : stopka do transportu
    $pdo->exec("UPDATE n_prod_param SET value = '1' WHERE code = 'mail.footer_system'");
    if ($nl) {
        $pdo->prepare("INSERT INTO communication_message (message_type, template_id, recipient_email, subject_text, body_text, variables_json, status, created_at, queued_at, next_try_at, message_id_header)
                       VALUES ('test', ?, 'klient@example.invalid', 'Test', 'Treść', '{}', 'queued', NOW(), NOW(), NOW(), ?)")
            ->execute([$nl, '<test-' . bin2hex(random_bytes(4)) . '@example.invalid>']);
        $mid = (int) $pdo->lastInsertId();
        $recorder = new FooterRecordingTransport();
        $logOnly = new MailDeliveryPolicy('log_only');
        $worker = $container->make(CommunicationEmailDeliveryWorker::class, ['transport' => $recorder, 'policy' => $logOnly, 'footers' => $fresh(MailFooterService::class)]);
        $r = $worker->processMessageNow($mid);
        expectTr($r['status'] === 'sent' && count($recorder->sent) === 1 && $recorder->sent[0]['footer'] === 'C' && $recorder->sent[0]['to'] === 'klient@example.invalid', 'queue passes the Dutch footer');
    }
} finally {
    DeepLTranslator::$transport = null;
    $setEnv($before['env'] === false ? null : $before['env']);
    $pdo->exec('DELETE FROM n_translation_log WHERE id > ' . $before['log']);
    $pdo->exec('DELETE FROM communication_message_attempt WHERE id_message > ' . $before['message']);
    $pdo->exec('DELETE FROM communication_message WHERE id > ' . $before['message']);
    $pdo->exec('DELETE FROM n_mail_footer');
    $ins = $pdo->prepare('INSERT INTO n_mail_footer (lang, body, updated_by) VALUES (?, ?, ?)');
    foreach ($before['footers'] as $f) $ins->execute([$f['lang'], $f['body'], $f['updated_by']]);
    $pdo->exec("DELETE FROM n_app_secret WHERE code = 'deepl_api_key'");
    if ($before['secret']) $pdo->prepare('INSERT INTO n_app_secret (code, secret, hint, updated_by) VALUES (?, ?, ?, ?)')
        ->execute([$before['secret']['code'], $before['secret']['secret'], $before['secret']['hint'], $before['secret']['updated_by']]);
    $up = $pdo->prepare('UPDATE n_prod_param SET value = ? WHERE code = ?');
    foreach ($before['params'] as $code => $value) $up->execute([$value, $code]);
}

echo "mail_translation_test: OK\n";
