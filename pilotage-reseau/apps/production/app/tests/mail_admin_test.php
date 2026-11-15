<?php
declare(strict_types=1);
/*
 * [270] Poczta ustawiana i wstrzymywana przez administratora, na bazie jednorazowej (struktura skopiowana z erp) :
 *   - migracja 168 dwa razy : « Poczta » w menu Kierownika produkcji, jego skrzynka ustawiana przez administratora ;
 *   - użytkownik « tylko administrator » nie zmienia ani nie odłącza skrzynki (403), administrator tak ;
 *   - wstrzymanie : bez odbioru, listy, wysyłki (423), licznik 0, pomijana w cronie ; wznowienie przywraca wszystko ;
 *   - administrator sprawdza skrzynkę także wstrzymaną ; lista użytkowników bez haseł ;
 *   - strona /mail/admin tylko dla administratora (klucz mail_admin), zakładka w Poczcie.
 * Bez serwera pocztowego : nic nie łączy się z siecią (zapis ustawień bez testu połączenia).
 *
 *   php tests/mail_admin_test.php
 *
 * Baza : TFB_TEST_DSN (domyślnie mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (puste) ;
 * struktura z TFB_TEST_SOURCE_DB (domyślnie erp). Baza erp_mail_admin_test jest tworzona i usuwana.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\PageAccess;
use App\Core\Web\PageTabs;
use App\Exceptions\CustomException;
use App\Repositories\Mail\UserMailRepository;
use App\Services\Mail\UserMailService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectMa(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

/** Kod błędu CustomException albo « ok ». */
function codeOf(callable $fn): string
{
    try { $fn(); } catch (CustomException $e) { return (string) $e->getCode(); }
    return 'ok';
}

expectMa(PageAccess::keyFor('/mail/admin') === 'mail_admin' && PageAccess::adminOnly('mail_admin') && PageAccess::keyFor('/mail') === null, 'strona administratora : własny klucz, tylko administrator ; Poczta dla wszystkich');
expectMa(in_array('/mail/admin', array_column(PageTabs::GROUPS['mail']['tabs'], 0), true), 'zakładka « Skrzynki użytkowników » w Poczcie');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_mail_admin_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "mail_admin_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
putenv('MAIL_ALLOW_PRIVATE_HOSTS=1');
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) if ($t !== 'n_user_mail_admin') $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");

    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login, password) VALUES
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', 'ada', 'x'), (2, 'Kasia', 'Kierownik', 'Kasia Kierownik', 'kasia@example.invalid', 'kasia', 'x'),
        (3, 'Olek', 'Biuro', 'Olek Biuro', 'olek@example.invalid', 'olek', 'x')");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1), (4, 'BIURO', 'Biuro', 'Bureau', 'erp', 2, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) VALUES (2, 3), (3, 4)");
    $pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (3, 'time')");

    $m168 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/168_mailbox_admin.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($m168) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectMa($count("SELECT COUNT(*) FROM n_position_menu WHERE id_position = 3") === 2 && $count("SELECT COUNT(*) FROM n_position_menu WHERE id_position = 3 AND item = 'mail'") === 1,
        'migracja 168 (dwa razy) : Poczta w menu Kierownika, Czas pracy zostaje');
    expectMa($count('SELECT COUNT(*) FROM n_user_mail_admin') === 1 && $count('SELECT managed FROM n_user_mail_admin WHERE id_user = 2') === 1 && $count('SELECT stopped FROM n_user_mail_admin WHERE id_user = 2') === 0,
        'skrzynka Kierownika ustawiana przez administratora, poczta aktywna');

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test'])]);
    $c = $b->build();
    $mail = $c->get(UserMailService::class);
    $repo = $c->get(UserMailRepository::class);
    $box = ['email' => 'kasia@example.invalid', 'display_name' => 'Kasia', 'provider' => 'other', 'imap_host' => 'imap.example.invalid', 'imap_port' => 993,
        'imap_security' => 'ssl', 'imap_username' => '', 'imap_password' => 'sekret-imap', 'smtp_host' => 'smtp.example.invalid', 'smtp_port' => 587,
        'smtp_security' => 'starttls', 'smtp_username' => '', 'smtp_password' => '', 'tls_verify' => true, 'signature' => ''];

    // --- « tylko administrator »
    $s = $mail->state(2);
    expectMa($s['admin_policy']['managed'] === true && $s['admin_policy']['stopped'] === false && !$s['configured'], 'stan dla Kierownika : ustawia administrator');
    expectMa(codeOf(fn() => $mail->saveMailbox(2, $box)) === '403', 'Kierownik nie zapisuje sam swojej skrzynki (403)');
    $saved = $mail->saveMailbox(2, $box, true);
    expectMa($saved['configured'] && $saved['mailbox']['email'] === 'kasia@example.invalid' && !isset($saved['mailbox']['imap_secret'])
        && str_starts_with((string) $repo->mailbox(2)['imap_secret'], 'm1:'), 'administrator zapisuje skrzynkę za Kierownika (hasło zaszyfrowane, nie wraca)');
    expectMa(codeOf(fn() => $mail->deleteMailbox(2)) === '403' && $repo->mailbox(2) !== null, 'Kierownik nie odłącza skrzynki ustawionej przez administratora');
    expectMa(codeOf(fn() => $mail->saveMailbox(3, ['email' => 'olek@example.invalid'] + $box)) === 'ok', 'inny użytkownik ustawia swoją skrzynkę sam');

    // --- wstrzymanie
    $pdo->exec("INSERT INTO n_user_mail (id_user, folder, uid_validity, uid, subject, seen, body_text) VALUES (2, 'INBOX', 1, 1, 'Dostawa mąki', 0, 'jutro rano')");
    expectMa($mail->unread(2) === 1 && count($mail->list(2, 'INBOX', '', 1)['rows']) === 1, 'przed wstrzymaniem : licznik 1, lista');
    $p = $mail->saveAdminPolicy(2, ['stopped' => true], 1);
    expectMa($p['stopped'] && $p['managed'] && $p['stopped_at'] !== null, 'wstrzymana (data), « tylko administrator » bez zmian');
    expectMa(codeOf(fn() => $mail->list(2, 'INBOX', '', 1)) === '423' && codeOf(fn() => $mail->sync(2)) === '423'
        && codeOf(fn() => $mail->send(2, ['to' => 'x@example.invalid', 'subject' => 'a', 'text' => 'b'])) === '423' && codeOf(fn() => $mail->open(2, 1)) === '423',
        'wstrzymana : lista, odbiór, wysyłka, odczyt — 423');
    expectMa($mail->unread(2) === 0 && $mail->search(2, 'mąki', 5) === [] && $mail->state(2)['admin_policy']['stopped'], 'wstrzymana : licznik 0, wyszukiwarka pusta, stan pokazuje wstrzymanie');
    expectMa($repo->syncableUsers() === [3], 'cron : skrzynka wstrzymana pominięta');
    expectMa((int) $pdo->query('SELECT COUNT(*) FROM n_user_mail WHERE id_user = 2')->fetchColumn() === 1, 'wiadomości zostają w ERP');
    $again = $mail->saveAdminPolicy(2, ['stopped' => true], 1);
    expectMa($again['stopped_at'] === $p['stopped_at'], 'ponowne wstrzymanie nie zmienia daty');

    $users = array_column($mail->adminUsers(), null, 'id');
    expectMa(count($users) === 3 && $users[2]['stopped'] && $users[2]['managed'] && $users[2]['email'] === 'kasia@example.invalid' && $users[2]['position'] === 'Kierownik produkcji'
        && $users[1]['email'] === null && !$users[3]['managed'] && !array_key_exists('imap_secret', $users[2]), 'lista administratora : stanowisko, skrzynka, ustawienia, bez haseł');

    // --- wznowienie, « tylko administrator » wyłączone
    $mail->saveAdminPolicy(2, ['stopped' => false, 'managed' => false], 1);
    expectMa($mail->unread(2) === 1 && count($mail->list(2, 'INBOX', '', 1)['rows']) === 1 && $repo->adminPolicy(2) === ['managed' => false, 'stopped' => false, 'stopped_at' => null],
        'wznowiona : licznik i lista wracają');
    expectMa(codeOf(fn() => $mail->saveMailbox(2, ['display_name' => 'Kasia K.', 'imap_password' => ''] + $box)) === 'ok', 'bez « tylko administrator » Kierownik zmienia swoją skrzynkę');
    expectMa(codeOf(fn() => $mail->saveAdminPolicy(99, ['stopped' => true], 1)) === '404' && codeOf(fn() => $mail->adminTarget(99)) === '404', 'nieznany użytkownik : 404');

    echo "mail_admin_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
