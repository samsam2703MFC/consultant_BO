<?php
declare(strict_types=1);
/*
 * [348] HR › Grafik tygodnia i przypomnienie o wejściu, sur une base jetable (structure copiée de erp) :
 *   - migration 207 rejouable : n_hr_schedule, n_hr_clock_reminder, colonnes clock_sms / clock_sms_text, paramètres
 *     hr.clock_sms_delay (15) et hr.clock_sms_text ;
 *   - fiche : grafik validé (GG:MM 24 h, début et fin), texte = texte commun → pas de texte propre, téléphone masqué ;
 *   - cron : SMS à début + délai, rien avant, rien après la fin du grafik, rien si pointé / absence / férié PL / sans
 *     téléphone / SMS coupé / inactif, un seul par jour, {godzina} remplacé, texte propre ou commun, grafik de nuit,
 *     numéro polonais sans indicatif → +48, numéro illisible = échec sans le numéro ;
 *   - Ustawienia HR : délai 1–240, texte commun (vide = texte d'origine) ; Czas pracy › Dzień : ✉ et l'heure du SMS.
 *
 *   php tests/hr_clock_reminder_test.php
 *
 * Aucun vrai SMS : SmsGateway::$modeOverride = 'log_only' puis 'live' avec SmsGateway::$http remplacé (faux SMSAPI).
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_hr_clock_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Hr\ClockReminderService;
use App\Services\Hr\HrService;
use App\Services\Logistics\Sms\SmsGateway;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Time\TimeService;

function expectCr(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function crErr(callable $fn): array
{
    try { $fn(); } catch (CustomException $e) { return [(string) $e->getCode(), $e->getErrors()['fields'] ?? []]; }
    return ['ok', []];
}

// Outils purs.
expectCr(ClockReminderService::time('7:05') === '07:05' && ClockReminderService::time('23:59') === '23:59' && ClockReminderService::time('24:00') === null
    && ClockReminderService::time('7') === null && ClockReminderService::time('07:60') === null, 'heure GG:MM 24 h');
expectCr(ClockReminderService::mask('+48 600 000 121') === '+•• ••• ••• •21' && ClockReminderService::mask('600000121') === '•••••••21', 'téléphone masqué sauf 2 chiffres');
expectCr(ClockReminderService::polish('600 000 121') === '+48600000121' && ClockReminderService::polish('0476 12 34 56') === '0476 12 34 56'
    && ClockReminderService::polish('+32 476 12 34 56') === '+32 476 12 34 56', 'numéro de 9 chiffres sans indicatif = Pologne');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_hr_clock_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "hr_clock_reminder_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
// Aucun appel réseau, quel que soit le mode : faux SMSAPI qui note les appels.
$calls = [];
SmsGateway::$http = function (string $url, ?array $fields, string $token) use (&$calls) {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return [200, ['count' => 1, 'list' => [['id' => 'T-' . count($calls), 'points' => 0.16]]]];
};
SmsGateway::$modeOverride = 'log_only';
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT LIKE 'n\\_hr\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    foreach (['170_hr.sql', '171_payroll_contracts.sql', '172_hr_quick_note.sql', '179_hr_clothing_size.sql', '207_hr_clock_reminder.sql', '207_hr_clock_reminder.sql'] as $f) {
        foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
    }
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectCr($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_hr_schedule', 'n_hr_clock_reminder')") === 2
        && $count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_employee' AND COLUMN_NAME IN ('clock_sms', 'clock_sms_text')") === 2
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'hr.clock_sms_delay'")->fetchColumn() === '15'
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'hr.clock_sms_text'")->fetchColumn() === ClockReminderService::DEFAULT_TEXT,
        'migration 207 (rejouée) : 2 tables, 2 colonnes, délai 15 min et texte par défaut');
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
        ('sms.provider', 'smsapi', 'smsapi', 'string', NULL, 'x', 'x'), ('sms.base', 'https://sms.test', '', 'string', NULL, 'x', 'x'),
        ('sms.default_prefix', '32', '32', 'string', NULL, 'x', 'x')");

    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login, password) VALUES (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', 'ada', 'x')");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1)");
    $names = [21 => 'Anna', 22 => 'Bartek', 23 => 'Celina', 24 => 'Darek', 25 => 'Ela', 26 => 'Franek', 27 => 'Gosia', 28 => 'Henryk', 29 => 'Iza', 30 => 'Jacek'];
    foreach ($names as $id => $n) {
        $pdo->exec("INSERT INTO production_employee (id, name, surname, cost) VALUES ({$id}, '{$n}', 'Testowy', 5000)");
        $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES ({$id}, {$id}, " . ($id === 28 ? 0 : 1) . ', 1)');
    }

    $b = new DI\ContainerBuilder();
    // Aucun e-mail : le transport SMTP du test refuse tout appel.
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
            {
                throw new RuntimeException('Aucun e-mail ne part pendant les tests.');
            }
        }]);
    $c = $b->build();
    $hr = $c->get(HrService::class);
    $svc = $c->get(ClockReminderService::class);

    // --- Fiche : téléphones (chiffrés par la fiche HR), grafik, texte
    $phones = [21 => '600 000 121', 22 => '+48 600 000 122', 23 => '600 000 123', 24 => '600 000 124', 26 => '600 000 126', 27 => '600 000 127', 28 => '600 000 128',
        29 => '600 000 129', 30 => '123 456'];
    foreach ($phones as $id => $p) $hr->save($id, ['phone' => $p], 1);
    $tue = ['start' => '07:00', 'end' => '15:00'];
    [$code, $fields] = crErr(fn() => $svc->save(21, ['days' => [2 => ['start' => '25:00', 'end' => '15:00'], 3 => ['start' => '07:00', 'end' => ''], 4 => ['start' => '08:00', 'end' => '08:00']]], 1));
    expectCr($code === '422' && isset($fields['start_2'], $fields['end_3'], $fields['end_4']), 'grafik refusé : heure hors 24 h, fin manquante, fin = début');
    expectCr(crErr(fn() => $svc->save(21, ['days' => [], 'text' => str_repeat('x', 481)], 1))[0] === '422' && crErr(fn() => $svc->save(999, ['days' => []], 1))[0] === '404',
        'texte de plus de 480 signes refusé ; personne inconnue');
    $week = array_fill_keys([1, 2, 3, 4, 5], ['start' => '7:00', 'end' => '15:00']);
    $card = $svc->save(21, ['days' => $week + [6 => ['start' => '', 'end' => '']], 'sms' => true, 'text' => ClockReminderService::DEFAULT_TEXT], 1);
    expectCr(count((array) $card['days']) === 5 && ((array) $card['days'])[1] === $tue && !isset(((array) $card['days'])[6]) && $card['text'] === '' && $card['sms'] === true
        && $card['delay'] === 15 && $card['phone'] === '••• ••• •21' && $card['last'] === null, 'grafik pn–pt 07:00–15:00, sobota wolna, texte commun (pas de texte propre), téléphone masqué');
    expectCr($pdo->query('SELECT clock_sms_text FROM n_hr_employee WHERE id_employee = 21')->fetchColumn() === null
        && $count('SELECT COUNT(*) FROM n_hr_schedule WHERE id_employee = 21') === 5, 'texte identique au commun → NULL ; 5 jours en base');
    $svc->save(22, ['days' => [2 => $tue], 'sms' => true, 'text' => 'Hej, grafik od {godzina}. Odbij wejście.'], 1);
    foreach ([23, 24, 25, 28, 30] as $id) $svc->save($id, ['days' => [2 => $tue]], 1);
    $svc->save(26, ['days' => [2 => $tue], 'sms' => false], 1);
    $svc->save(27, ['days' => [2 => ['start' => '06:00', 'end' => '07:10']]], 1);
    $svc->save(29, ['days' => [2 => ['start' => '22:00', 'end' => '06:00']]], 1);
    expectCr($hr->card(22, 1, true)['clock']['text'] === 'Hej, grafik od {godzina}. Odbij wejście.' && $hr->card(25, 1, true)['clock']['phone'] === null,
        'fiche relue : texte propre ; sans téléphone = null (avertissement à l\'écran)');

    // Pointée à 06:55 ; absente (urlop) toute la semaine.
    $pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, source) VALUES (23, '2026-11-17 06:55:00', 'kiosk')");
    $pdo->exec("INSERT INTO n_time_absence (id_employee, date_from, date_to, type) VALUES (24, '2026-11-16', '2026-11-20', 'urlop')");

    // --- Cron, mardi 17.11.2026 (jour ouvré)
    $at = fn(string $s) => new DateTimeImmutable($s);
    $r = $svc->run($at('2026-11-17 07:14:59'));
    expectCr($r['due'] === 0 && $count('SELECT COUNT(*) FROM n_hr_clock_reminder') === 0 && $count('SELECT COUNT(*) FROM n_sms_log') === 0, 'rien avant début + 15 min');
    $r = $svc->run($at('2026-11-17 07:15:00'));
    expectCr($r['logged'] === 2 && $r['failed'] === 1 && $r['clocked_in'] === 1 && $r['absent'] === 1 && $r['no_phone'] === 1 && $r['off'] === 1 && $r['sent'] === 0,
        'à 07:15 : Anna et Bartek (SMS), numéro illisible en échec ; pointée, absente, sans téléphone, SMS coupé : rien — ' . json_encode($r));
    expectCr(array_filter($r, fn($v) => !is_int($v)) === [], 'statistiques : des nombres seulement (journal du cron)');
    $rows = $pdo->query('SELECT id_employee, day, scheduled_start, sent_at, status, error FROM n_hr_clock_reminder ORDER BY id_employee')->fetchAll(PDO::FETCH_UNIQUE);
    expectCr(array_keys($rows) === [21, 22, 30] && $rows[21]['status'] === 'logged' && $rows[21]['sent_at'] === '2026-11-17 07:15:00' && $rows[21]['scheduled_start'] === '07:00:00'
        && $rows[30]['status'] === 'failed' && !str_contains((string) $rows[30]['error'], '123'), 'rappels : un par personne ; échec sans le numéro ; ni Gosia (fin 07:10) ni l\'inactif');
    $log = $pdo->query("SELECT ref_id, recipient, body, status, context FROM n_sms_log ORDER BY ref_id")->fetchAll(PDO::FETCH_UNIQUE);
    expectCr(array_keys($log) === [21, 22] && $log[21]['context'] === 'clock_reminder' && $log[21]['status'] === 'logged' && $calls === [], 'n_sms_log : 2 SMS « clock_reminder » zapisane, aucun appel HTTP (log_only)');
    expectCr($log[21]['body'] === str_replace('{godzina}', '07:00', ClockReminderService::DEFAULT_TEXT) && $log[22]['body'] === 'Hej, grafik od 07:00. Odbij wejście.',
        '{godzina} → 07:00 ; texte commun pour Anna, texte propre pour Bartek');
    expectCr($log[21]['recipient'] === '+48600000121' && $log[22]['recipient'] === '+48600000122', 'numéro sans indicatif envoyé en +48 (pas le préfixe belge par défaut)');
    $r = $svc->run($at('2026-11-17 07:40:00'));
    $r2 = $svc->run($at('2026-11-17 14:59:00'));
    expectCr($r['logged'] + $r['sent'] + $r2['logged'] + $r2['sent'] === 0 && $count('SELECT COUNT(*) FROM n_hr_clock_reminder') === 3 && $count('SELECT COUNT(*) FROM n_sms_log') === 2, 'un seul SMS par personne et par jour');
    $pdo->exec("DELETE FROM n_prod_clock WHERE id_employee = 23");
    expectCr($svc->run($at('2026-11-17 15:00:00'))['due'] === 0, 'rien après la fin du grafik (15:00)');
    // Grafik de nuit 22:00–06:00 : rappel à 22:15 avec « 22:00 ».
    $r = $svc->run($at('2026-11-17 22:15:00'));
    expectCr($r['logged'] === 1 && str_contains((string) $pdo->query('SELECT body FROM n_sms_log WHERE ref_id = 29')->fetchColumn(), 'od 22:00'), 'grafik de nuit : SMS à 22:15 (« od 22:00 »)');

    // --- Czas pracy › Dzień : ✉ + heure à côté du nom
    $day = $c->get(TimeService::class)->day('2026-11-17', strtotime('2026-11-18 08:00:00'));
    $people = array_column($day['people'], null, 'employee_id');
    expectCr(($people[21]['clock_sms'] ?? null) === ['at' => '07:15', 'status' => 'logged'] && isset($people[23]) && array_key_exists('clock_sms', $people[23]) && $people[23]['clock_sms'] === null,
        'Czas pracy › Dzień : SMS de 07:15 sur la ligne d\'Anna, rien pour Celina');

    // --- Férié PL (11.11.2026, mercredi) : rien
    $r = $svc->run($at('2026-11-11 07:20:00'));
    expectCr($r['holiday'] === 1 && $r['due'] === 0 && $count("SELECT COUNT(*) FROM n_hr_clock_reminder WHERE day = '2026-11-11'") === 0, 'jour férié PL : aucun SMS');

    // --- Mode live (faux SMSAPI) : mercredi 18.11, Anna seule a un grafik
    SmsGateway::$modeOverride = 'live';
    putenv('SMSAPI_TOKEN=test-token-0123456789');
    $_ENV['SMSAPI_TOKEN'] = 'test-token-0123456789';
    $c->get(SmsGateway::class)->forget();
    $r = $svc->run($at('2026-11-18 07:16:00'));
    expectCr($r['sent'] === 1 && count($calls) === 1 && $calls[0]['url'] === 'https://sms.test/sms.do' && $calls[0]['fields']['to'] === '48600000121'
        && str_contains($calls[0]['fields']['message'], 'od 07:00'), 'mode live : un SMS au +48600000121 (faux SMSAPI)');
    $last = $hr->card(21, 1, true)['clock']['last'];
    expectCr($last['day'] === '2026-11-18' && $last['at'] === '2026-11-18 07:16' && $last['status'] === 'sent' && $last['label'] === 'wysłany', 'fiche : dernier rappel 18.11 07:16 — wysłany');
    SmsGateway::$modeOverride = 'log_only';

    // --- Ustawienia HR : délai et texte communs
    expectCr(crErr(fn() => $hr->saveRules(['clock_sms_delay' => 0], 1))[0] === '422' && crErr(fn() => $hr->saveRules(['clock_sms_delay' => 241], 1))[0] === '422'
        && crErr(fn() => $hr->saveRules(['clock_sms_text' => str_repeat('x', 481)], 1))[0] === '422', 'réglages : délai 1–240, texte 480 signes');
    $rules = $hr->saveRules(['clock_sms_delay' => '30', 'clock_sms_text' => 'Grafik od {godzina} — brak wejścia.'], 1);
    expectCr($rules['clock_sms_delay'] === 30 && $rules['clock_sms_text'] === 'Grafik od {godzina} — brak wejścia.' && $hr->card(21, 1, true)['clock']['delay'] === 30, 'réglages enregistrés : 30 min, nouveau texte commun');
    expectCr($svc->run($at('2026-11-19 07:29:00'))['due'] === 0, 'jeudi : rien à 07:29 avec 30 min');
    $svc->run($at('2026-11-19 07:30:00'));
    expectCr($pdo->query("SELECT body FROM n_sms_log WHERE ref_id = 21 ORDER BY id DESC LIMIT 1")->fetchColumn() === 'Grafik od 07:00 — brak wejścia.', 'nouveau texte commun utilisé (fiche sans texte propre)');
    expectCr($hr->saveRules(['clock_sms_text' => '  '], 1)['clock_sms_text'] === ClockReminderService::DEFAULT_TEXT, 'texte commun vide → texte d\'origine');
    expectCr($count("SELECT COUNT(*) FROM n_hr_log WHERE action = 'schedule'") >= 10, 'enregistrements du grafik notés dans le journal HR');

    echo "hr_clock_reminder_test: OK\n";
} finally {
    SmsGateway::$http = null;
    SmsGateway::$modeOverride = null;
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
