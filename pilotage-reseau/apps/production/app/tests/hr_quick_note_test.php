<?php
declare(strict_types=1);
/*
 * [279] HR › Szybka notatka (makieta 44) sur une base jetable (structure copiée de erp) :
 *   - migration 172 rejouable (rodzaj, temat chiffré, dla kogo, alertes, follow-up) ;
 *   - validation (pracownik, rodzaj, temat, dla kogo, follow-up : date et responsable) ;
 *   - destinataires par poste : cloche, e-mail (file d'envoi) et SMS (transport de test) sans le texte de la note ;
 *   - visibilité : « dla kogo » limite la note aux postes cités ; « tylko ja » = privée ;
 *   - follow-up : cloche du responsable, liste, « Mój tydzień » (canal « zadanie »), rappel au terme, « Zrobione ».
 *
 *   php tests/hr_quick_note_test.php
 *
 * Base : TFB_TEST_DSN / TFB_TEST_USER / TFB_TEST_PASS, structures de TFB_TEST_SOURCE_DB (erp). erp_hrq_test est recréée
 * puis supprimée. Aucun e-mail ni SMS ne part : file de la base jetable et transport SMS de test.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Hr\HrQuickNoteService;
use App\Services\Hr\HrService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectQ(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

final class TestSms implements App\Services\Logistics\Sms\SmsTransport
{
    public array $sent = [];
    public function name(): string { return 'test'; }
    public function delivers(): bool { return false; }
    public function send(string $to, string $text): array { $this->sent[] = [$to, $text]; return ['status' => 'logged', 'provider_id' => null, 'error' => null]; }
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_hrq_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "hr_quick_note_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
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
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, login, password) VALUES
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', '+48600000001', 'ada', 'x'),
        (2, 'Kasia', 'Kierownik', 'Kasia K.', 'kasia@example.invalid', '+48600000002', 'kasia', 'x'),
        (3, 'Fela', 'Finanse', 'Fela F.', 'fela@example.invalid', NULL, 'fela', 'x'),
        (4, 'Leon', 'Logistyka', 'Leon L.', NULL, '+48600000004', 'leon', 'x')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1),
        (5, 'ADMINISTRACJA', 'Administracja i fakturowanie', 'Admin', 'erp', 2, 1), (4, 'LOGISTYKA', 'Logistyka', 'Logistique', 'erp', 3, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) VALUES (2, 3), (3, 5), (4, 4)");
    foreach (['170_hr.sql', '171_payroll_contracts.sql', '172_hr_quick_note.sql', '172_hr_quick_note.sql'] as $f) {
        foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
    }
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectQ($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_note' AND COLUMN_NAME IN ('kind', 'topic_enc', 'audience', 'channels', 'followup_due', 'followup_user', 'followup_done_at')") === 7,
        'migration 172 (rejouée) : colonnes de la note rapide');
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost) VALUES (1, 'Jan', 'Testowy', 0), (2, 'Ewa', 'Testowa', 0)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES (11, 1, 1, 1), (12, 2, 1, 1)");

    $sms = new TestSms();
    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('q', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Logistics\Sms\SmsTransport::class => $sms,
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
            {
                throw new RuntimeException('Aucun e-mail ne part pendant les tests.');
            }
        }]);
    $c = $b->build();
    $q = $c->get(HrQuickNoteService::class);
    $hr = $c->get(HrService::class);
    $today = new DateTimeImmutable('2026-11-12');

    $opt = $q->options(1);
    $aud = array_column($opt['audience'], 'count', 'code');
    expectQ(count($opt['kinds']) === 8 && $aud['KIEROWNIK_PRODUKCJI'] === 1 && $aud['ADMINISTRACJA'] === 1 && $aud['admin'] === 1 && !$opt['sms_delivers']
        && in_array(2, array_column($opt['users'], 'id'), true) && count($opt['people']) === 2, 'options : 8 rodzajów, odbiorcy według stanowisk, osoby do follow-upu, pracownicy');

    $bad = null;
    try { $q->add(['employee_id' => 999, 'kind' => 'zly', 'topic' => '', 'audience' => [], 'followup' => true, 'followup_due' => '2026-11-01', 'followup_user' => 77], 1, $today); }
    catch (CustomException $e) { $bad = $e->getErrors()['fields'] ?? []; }
    expectQ($bad !== null && count(array_intersect(['employee_id', 'kind', 'topic', 'audience', 'followup_due', 'followup_user'], array_keys($bad))) === 6, 'walidacja : 6 pól');

    $secret = 'Tajna treść : rozmowa o zdrowiu';
    $r = $q->add(['employee_id' => 11, 'kind' => 'ocena', 'topic' => 'Przegląd po okresie próbnym', 'body' => $secret, 'audience' => ['admin', 'KIEROWNIK_PRODUKCJI', 'LOGISTYKA'],
        'channels' => ['bell', 'email', 'sms', 'task'], 'followup' => true, 'followup_due' => '2026-11-20', 'followup_user' => 2], 3, $today);
    $raw = $pdo->query('SELECT * FROM n_hr_note WHERE id = ' . (int) $r['id'])->fetch();
    expectQ(str_starts_with((string) $raw['topic_enc'], 'h1:') && !str_contains(implode('|', array_map('strval', $raw)), 'Przegląd') && $raw['audience'] === 'admin,KIEROWNIK_PRODUKCJI,LOGISTYKA'
        && $raw['visibility'] === 'public' && (int) $raw['to_admin'] === 1 && $raw['channels'] === 'bell,email,sms,task', 'notatka : temat i treść zaszyfrowane, dla kogo, kanały');
    expectQ($r['notified'] === ['bell' => 3, 'email' => 2, 'sms' => 3], 'powiadomienia : dzwonek 3 (admin, kierownik, logistyka), e-mail 2 (z adresem), SMS 3 (z telefonem)');
    $mails = $pdo->query("SELECT body_text FROM communication_message WHERE message_type = 'hr_note'")->fetchAll(PDO::FETCH_COLUMN);
    expectQ(count($mails) === 2 && str_contains($mails[0], 'Przegląd po okresie próbnym') && !str_contains(implode('|', $mails), 'Tajna') && str_contains($sms->sent[0][1], 'Follow-up do 20.11.2026')
        && !str_contains(implode('|', array_column($sms->sent, 1)), 'Tajna'), 'e-mail i SMS : kto, rodzaj, temat — bez treści');
    expectQ($count("SELECT COUNT(*) FROM app_notification WHERE type = 'hr_followup' AND id_user = 2") === 1, 'follow-up : dzwonek osoby odpowiedzialnej');

    $q->add(['employee_id' => 12, 'kind' => 'uwaga', 'topic' => 'Spóźnienie', 'audience' => ['ADMINISTRACJA'], 'channels' => []], 1, $today);
    $q->add(['employee_id' => 12, 'kind' => 'rozmowa', 'topic' => 'Prywatnie', 'audience' => ['me'], 'channels' => ['bell']], 2, $today);
    $vis = fn(int $u) => array_column($hr->card(12, $u, false, $today)['notes'], 'topic');
    expectQ($vis(3) === ['Spóźnienie'] && $vis(2) === ['Prywatnie'] && count($hr->card(12, 1, true, $today)['notes']) === 2, 'widoczność : finanse widzą notatkę « dla Finanse », kierownik tylko swoją prywatną, administrator wszystkie');
    $card = $hr->card(11, 2, false, $today)['notes'][0];
    expectQ($card['kind_label'] === 'Ocena / przegląd' && $card['topic'] === 'Przegląd po okresie próbnym' && $card['audience'] === ['Administrator', 'Kierownik produkcji', 'Logistyka']
        && $card['followup']['due'] === '2026-11-20' && $card['followup']['can_close'], 'karta : rodzaj, temat, dla kogo, follow-up');

    $fu = $q->followups(2, false, $today);
    expectQ($fu['open'] === 1 && $fu['late'] === 0 && $fu['items'][0]['mine'], 'follow-upy : 1 otwarty (kierownik)');
    $repo = $c->get(App\Repositories\Hr\HrRepository::class);
    expectQ(count($repo->taskFollowups(2)) === 1 && count($repo->taskFollowups(3)) === 0, '« Mój tydzień » : zadanie follow-upu u osoby odpowiedzialnej');
    expectQ($q->reminders(new DateTimeImmutable('2026-11-19 07:10')) === 0 && $q->reminders(new DateTimeImmutable('2026-11-21 07:10')) === 1
        && $count("SELECT COUNT(*) FROM app_notification WHERE type = 'hr_followup' AND severity = 'danger'") === 1, 'przypomnienie : od terminu, po terminie czerwone');
    $code = 'ok';
    try { $q->done((int) $r['id'], [], 4, false); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectQ($code === '403', 'zamknięcie : nie osoba odpowiedzialna → odmowa');
    $after = $q->done((int) $r['id'], ['note' => 'Umowa na czas nieokreślony podpisana'], 2, false);
    expectQ($after['open'] === 0 && $repo->taskFollowups(2) === [] && $hr->card(11, 1, true, $today)['notes'][0]['followup']['done_note'] === 'Umowa na czas nieokreślony podpisana',
        '« Zrobione » : follow-up zamknięty z uwagą, znika z « Mój tydzień »');

    echo "hr_quick_note_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
