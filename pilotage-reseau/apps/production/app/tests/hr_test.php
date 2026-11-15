<?php
declare(strict_types=1);
/*
 * [271] Rail « HR » sur une base jetable (structure copiée de erp) :
 *   - migration 170 rejouable : tables, paramètres (retraite 60 / 65 ans), droit et menu « hr » du Kierownik produkcji et
 *     des finances ; clé d'accès, entrée du rail et onglets ;
 *   - fiche : validation, données personnelles chiffrées en base (téléphone, date de naissance, urgence), e-mail
 *     partagé avec Czas pracy, ancienneté, départ à la retraite (femme 60, homme 65), contrat qui se termine ;
 *   - documents : PDF accepté et chiffré, relu à l'identique ; type de fichier et taille refusés ; suppression ;
 *   - notes : privée (auteur + administrateur), publique (Kierownik, finances), « do administratora » → cloche ;
 *   - bilan du mois : e-mail aux administrateurs sans le texte des notes, une seule fois le dernier jour après 18 h ;
 *   - journal des lectures (n_hr_log).
 *
 *   php tests/hr_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_hr_test est recréée puis supprimée.
 * Aucun e-mail ne part : le bilan reste dans la file (communication_message) de la base jetable.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\PageAccess;
use App\Core\Web\PageTabs;
use App\Core\Web\Rail;
use App\Exceptions\CustomException;
use App\Services\Hr\HrService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectHr(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function hrCode(callable $fn): string
{
    try { $fn(); } catch (CustomException $e) { return (string) $e->getCode(); }
    return 'ok';
}

expectHr(PageAccess::keyFor('/hr') === 'hr' && PageAccess::keyFor('/hr/notes') === 'hr' && !PageAccess::adminOnly('hr'), 'clé d\'accès « hr », attribuable à un poste');
expectHr(Rail::itemFor('/hr/documents') === 'hr' && count(PageTabs::GROUPS['hr']['tabs']) === 6 && PageAccess::keyFor('/hr/succession') === 'hr', 'entrée du rail et 6 onglets (dont Szablony umów, Sukcesja)');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_hr_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "hr_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT LIKE 'n\\_hr\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");

    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login, password) VALUES
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', 'ada', 'x'), (2, 'Kasia', 'Kierownik', 'Kasia K.', 'kasia@example.invalid', 'kasia', 'x'),
        (3, 'Fela', 'Finanse', 'Fela F.', 'fela@example.invalid', 'fela', 'x')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1),
        (5, 'ADMINISTRACJA', 'Administracja i fakturowanie', 'Admin', 'erp', 2, 1), (4, 'LOGISTYKA', 'Logistyka', 'Logistique', 'erp', 3, 1)");
    $pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (3, 'time'), (3, 'mail'), (4, 'shipping_day')");
    $m170 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/170_hr.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($m170) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectHr($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n\\_hr\\_%'") === 5
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'hr.retire_age_k'")->fetchColumn() === '60' && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'hr.retire_age_m'")->fetchColumn() === '65',
        'migration 170 (rejouée) : 5 tables, retraite 60 / 65 ans');
    expectHr($count("SELECT COUNT(*) FROM n_page_access WHERE page_key = 'hr'") === 2 && $count("SELECT COUNT(*) FROM n_position_menu WHERE item = 'hr' AND id_position = 3") === 1
        && $count("SELECT COUNT(*) FROM n_position_menu WHERE item = 'hr' AND id_position = 5") === 0 && $count("SELECT COUNT(*) FROM n_position_menu WHERE item = 'hr' AND id_position = 4") === 0,
        'droit « hr » : Kierownik et finances ; menu ajouté seulement aux postes qui ont un menu propre');
    // [277] Champs de paie de la fiche (lus par HrRepository::people) : migration 171.
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/171_payroll_contracts.sql')) as $sql) $pdo->exec($sql);
    // [279] Szybka notatka (rodzaj, temat, dla kogo, follow-up) : migration 172.
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/172_hr_quick_note.sql')) as $sql) $pdo->exec($sql);
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/179_hr_clothing_size.sql')) as $sql) $pdo->exec($sql);

    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost) VALUES (1, 'Jan', 'Testowy', 8000), (2, 'Ewa', 'Testowa', 7000), (3, 'Olek', 'Dawny', 6000)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES (11, 1, 1, 1), (12, 2, 1, 1), (13, 3, 0, 1)");

    $b = new DI\ContainerBuilder();
    // Aucun envoi réel : le transport SMTP du test refuse tout appel (le bilan reste dans la file).
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
            {
                throw new RuntimeException('Aucun e-mail ne part pendant les tests.');
            }
        }]);
    $c = $b->build();
    $hr = $c->get(HrService::class);
    $today = new DateTimeImmutable('2026-11-11');

    // --- Fiche : validation, chiffrement, e-mail partagé, ancienneté, retraite
    $bad = null;
    try { $hr->save(11, ['email' => 'zly', 'phone' => 'abc', 'birth_date' => '2020-01-01', 'gender' => 'X', 'contract_type' => 'zle'], 1); } catch (CustomException $e) { $bad = $e->getErrors()['fields'] ?? []; }
    expectHr($bad !== null && count(array_intersect(['email', 'phone', 'birth_date', 'gender', 'contract_type'], array_keys($bad))) === 5, 'validation : e-mail, téléphone, naissance, sexe, contrat');
    $hr->save(11, ['email' => 'Jan.T@Example.invalid', 'phone' => '+48 600 000 101', 'birth_date' => '1966-05-12', 'gender' => 'M', 'hire_date' => '2019-03-03',
        'contract_type' => 'nieokreslony', 'medical_until' => '2026-11-30', 'emergency' => 'Żona, +48 600 000 999'], 1);
    $hr->save(12, ['birth_date' => '1981-02-01', 'gender' => 'K', 'hire_date' => '2025-06-10', 'contract_type' => 'probny', 'contract_end' => '2026-12-09'], 2);
    $raw = $pdo->query('SELECT * FROM n_hr_employee WHERE id_employee = 11')->fetch();
    expectHr(str_starts_with((string) $raw['phone_enc'], 'h1:') && !str_contains(implode('|', array_map('strval', $raw)), '600 000') && !str_contains((string) $raw['birth_enc'], '1966'),
        'téléphone, naissance et urgence chiffrés en base');
    expectHr($count('SELECT COUNT(*) FROM n_time_contact WHERE id_employee = 11') === 1, 'e-mail rangé avec ceux de Czas pracy (un seul endroit)');
    $v = $hr->view(1, true, $today);
    $p = array_column($v['people'], null, 'id');
    expectHr($p[11]['email'] === 'jan.t@example.invalid' && $p[11]['phone'] === '+48 600 000 101' && $p[11]['seniority'] === '7 l. 8 m.' && !$p[11]['missing'], 'fiche relue : e-mail, téléphone, ancienneté 7 l. 8 m.');
    expectHr($p[11]['retire']['date'] === '2031-05-12' && $p[11]['retire']['age'] === 65 && !$p[11]['retire_soon'] && $p[11]['medical_alert'], 'homme : retraite à 65 ans (2031), visite médicale bientôt');
    expectHr($p[12]['retire']['year'] === 2041 && $p[12]['retire']['age'] === 60 && $p[12]['contract_soon'] && $p[12]['missing'], 'femme : retraite à 60 ans (2041), fin de période d\'essai, données manquantes');
    expectHr($v['kpi']['active'] === 2 && $v['kpi']['contracts_ending'] === 1 && !$p[13]['active'], 'indicateurs : 2 actifs, 1 contrat qui se termine ; l\'inactif reste listé');

    // [288] Rozmiar odzieży (XXS–XXXL) et « zostało » urlopu saisi dans le tableau (korekta de l'année).
    expectHr(hrCode(fn() => $hr->save(11, ['clothing_size' => 'XXXXL'], 1)) === '422', 'taille hors liste refusée');
    $hr->save(11, ['clothing_size' => 'xl'], 1);
    expectHr($pdo->query('SELECT clothing_size FROM n_hr_employee WHERE id_employee = 11')->fetchColumn() === 'XL' && in_array('XXS', $v['sizes'], true) && end($v['sizes']) === 'XXXL', 'taille XL enregistrée (liste XXS–XXXL)');
    expectHr($p[11]['leave'] !== null && $p[11]['leave']['remaining'] === $p[11]['leave']['right'] && $p[13]['leave'] === null, 'urlop : reste affiché pour les actifs seulement');
    $lv = $hr->saveLeaveRemaining(11, '14', 1);
    expectHr($lv['remaining'] === 14 && $lv['adjust'] === 14 - $p[11]['leave']['right'] && str_contains($lv['message'], '14'), 'urlop : « zostało 14 » enregistré (korekta)');
    expectHr(hrCode(fn() => $hr->saveLeaveRemaining(11, '1.5', 1)) === '422' && hrCode(fn() => $hr->saveLeaveRemaining(13, 5, 1)) === '422', 'urlop : nombre entier, personne active seulement');
    expectHr(array_column($hr->view(1, true, $today)['people'], null, 'id')[11]['leave']['remaining'] === 14, 'tableau relu : zostało 14');

    // --- Documents
    $pdf = "%PDF-1.4\n% test HR\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
    $up = $hr->upload(11, ['kind' => 'umowa', 'filename' => 'Umowa 2019.pdf', 'doc_date' => '2019-03-03', 'content_base64' => base64_encode($pdf)], 2);
    $blob = (string) $pdo->query('SELECT content FROM n_hr_document WHERE id = ' . (int) $up['id'])->fetchColumn();
    expectHr(str_starts_with($blob, 'H1') && !str_contains($blob, 'test HR'), 'document chiffré en base');
    $dl = $hr->download($up['id'], 1);
    expectHr($dl['content'] === $pdf && $dl['mime'] === 'application/pdf' && $dl['filename'] === 'Umowa 2019.pdf', 'document relu à l\'identique');
    expectHr(hrCode(fn() => $hr->upload(11, ['kind' => 'umowa', 'filename' => 'x.exe', 'content_base64' => base64_encode('MZ...')], 2)) === '422'
        && hrCode(fn() => $hr->upload(11, ['kind' => 'zly', 'filename' => 'x.pdf', 'content_base64' => base64_encode($pdf)], 2)) === '422', 'type de fichier et type de document refusés');
    expectHr(hrCode(fn() => $hr->deleteDocument($up['id'], 3, false)) === '403', 'les finances ne suppriment pas le document ajouté par le Kierownik');

    // --- Notes : visibilité, cloche de l'administrateur
    $hr->addNote(11, ['body' => 'Bardzo dobra praca w święta.', 'visibility' => 'public', 'to_admin' => true], 2);
    $hr->addNote(11, ['body' => 'Rozmowa o podwyżce — prywatne.', 'visibility' => 'private'], 1);
    $hr->addNote(12, ['body' => 'Prywatna notatka kierownika.', 'visibility' => 'private'], 2);
    expectHr(!str_contains((string) $pdo->query('SELECT GROUP_CONCAT(body_enc) FROM n_hr_note')->fetchColumn(), 'podwyżce'), 'texte des notes chiffré');
    $cardFin = $hr->card(11, 3, false, $today);
    $cardAdm = $hr->card(11, 1, true, $today);
    expectHr(count($cardFin['notes']) === 1 && $cardFin['notes'][0]['visibility'] === 'public' && count($cardAdm['notes']) === 2, 'finances : la note publique ; administrateur : toutes');
    expectHr(count($hr->card(12, 2, false, $today)['notes']) === 1 && count($hr->card(12, 3, false, $today)['notes']) === 0, 'note privée : son auteur la voit, les finances non');
    expectHr($count("SELECT COUNT(*) FROM app_notification WHERE type = 'hr_note'") === 1, '« do administratora » : une cloche pour l\'administrateur');
    expectHr(hrCode(fn() => $hr->deleteNote((int) $cardAdm['notes'][0]['id'] === 0 ? 0 : (int) array_values(array_filter($cardAdm['notes'], fn($n) => $n['visibility'] === 'private'))[0]['id'], 3, false)) === '403', 'note d\'autrui : suppression refusée');

    // --- Notes du mois et bilan
    $month = date('Y-m');
    $m = $hr->monthNotes($month, 1, true);
    expectHr($m['counts']['all'] === 3 && $m['counts']['people'] === 2 && $m['counts']['to_admin'] === 1, 'notes du mois regroupées : 3 notes, 2 personnes');
    $s = $hr->sendSummary($month, 1);
    $mail = $pdo->query("SELECT recipient_email, body_text FROM communication_message WHERE message_type = 'hr_summary'")->fetch();
    expectHr($s === ['notes' => 3, 'recipients' => 1] && $mail['recipient_email'] === 'ada@example.invalid' && str_contains($mail['body_text'], 'Jan Testowy')
        && !str_contains($mail['body_text'], 'podwyżce') && !str_contains($mail['body_text'], 'święta'), 'bilan : un e-mail à l\'administrateur, sans le texte des notes');
    $pdo->exec('DELETE FROM n_hr_summary');
    expectHr($hr->autoSummary(new DateTimeImmutable('2026-11-29 19:00')) === null && $hr->autoSummary(new DateTimeImmutable('2026-11-30 10:00')) === null,
        'automatique : pas avant le dernier jour du mois à 18 h');
    $hr->autoSummary(new DateTimeImmutable('2026-11-30 18:05'));
    expectHr($hr->autoSummary(new DateTimeImmutable('2026-11-30 21:00')) === null && $count("SELECT COUNT(*) FROM n_hr_summary WHERE month = '2026-11' AND sent_by IS NULL") === 1, 'automatique : une seule fois');

    // --- Réglages et journal
    expectHr(hrCode(fn() => $hr->saveRules(['retire_k' => 30], 1)) === '422' && $hr->saveRules(['retire_k' => 62], 1)['retire_k'] === 62, 'réglages : 40 à 80 ans');
    expectHr($count("SELECT COUNT(*) FROM n_hr_log WHERE action IN ('list', 'card', 'document', 'save', 'upload', 'note')") >= 8, 'lectures et écritures notées dans le journal');

    echo "hr_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
