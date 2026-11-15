<?php
declare(strict_types=1);
/*
 * [278] HR › Sukcesja (makieta 43) sur une base jetable (structure copiée de erp) :
 *   - migration 173 rejouable (plans, étapes, cases, agenda, paramètre hr.succession_months) ;
 *   - départ à la retraite ≤ 24 mois → plan montré à la volée sans écriture, enregistré par le cron (une seule fois) ;
 *   - 5 étapes datées à rebours sur les 12 mois avant le départ, agenda 80 / 60 / 40 / 0 % ; fenêtre comprimée s'il en reste moins ;
 *   - cases à cocher, étape terminée, successeur → case de l'étape 2, pas de successeur = la personne qui part ;
 *   - « Mój tydzień » du responsable (Kierownik produkcji par défaut), étape en retard → cloche une fois par semaine ;
 *   - départ manuel (wypowiedzenie), nouvelle date de départ (étapes recalculées), plan annulé jamais recréé.
 *
 *   php tests/hr_succession_test.php
 *
 * Base : TFB_TEST_DSN / TFB_TEST_USER / TFB_TEST_PASS, structures de TFB_TEST_SOURCE_DB (erp). erp_hrs_test est recréée
 * puis supprimée. Aucun e-mail ni SMS.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Hr\HrCipher;
use App\Services\Hr\HrService;
use App\Services\Hr\HrSuccessionService;
use App\Services\Workshop\Migration\MigrationRunner;

function expectS(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_hrs_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "hr_succession_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
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
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) SELECT code, value, default_value, value_type FROM {$source}.n_prod_param WHERE code LIKE 'hr.%' AND code <> 'hr.succession_months'");
    foreach (['170_hr.sql', '171_payroll_contracts.sql', '172_hr_quick_note.sql', '173_hr_succession.sql', '173_hr_succession.sql', '179_hr_clothing_size.sql'] as $f) {
        foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) $pdo->exec($sql);
    }
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectS($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n\\_hr\\_succession%'") === 4
        && $count("SELECT COUNT(*) FROM n_prod_param WHERE code = 'hr.succession_months' AND value = '24'") === 1, 'migration 173 (rejouée) : 4 tables + paramètre');

    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, login, password) VALUES
        (1, 'Ada', 'Admin', 'Ada Admin', 'ada@example.invalid', 'ada', 'x'), (2, 'Kasia', 'Kierownik', 'Kasia K.', 'kasia@example.invalid', 'kasia', 'x'),
        (3, 'Fela', 'Finanse', 'Fela F.', NULL, 'fela', 'x')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1),
        (5, 'ADMINISTRACJA', 'Administracja i fakturowanie', 'Admin', 'erp', 2, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position) VALUES (2, 3), (3, 5)");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PAKOWANIE', 'Pakowanie', 'Emballage', '#555', 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost) VALUES (1, 'Jan', 'Testowy', 0), (2, 'Ewa', 'Testowa', 0), (3, 'Olek', 'Testowy', 0)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES (11, 1, 1, 1), (12, 2, 1, 1), (13, 3, 1, 1)");

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test']),
        App\Services\Logistics\Sms\SmsTransport::class => new class implements App\Services\Logistics\Sms\SmsTransport {
            public function name(): string { return 'test'; }
            public function delivers(): bool { return false; }
            public function send(string $to, string $text): array { throw new RuntimeException('Aucun SMS pendant les tests.'); }
        },
        App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
            public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
            {
                throw new RuntimeException('Aucun e-mail ne part pendant les tests.');
            }
        }]);
    $c = $b->build();
    $cipher = $c->get(HrCipher::class);
    // Jan : homme né le 01.12.1962 → retraite (65 ans) le 01.12.2027, dans 12 mois ; Ewa : femme née en 1975 ; Olek : sans date.
    $ins = $pdo->prepare('INSERT INTO n_hr_employee (id_employee, birth_enc, gender) VALUES (?, ?, ?)');
    $ins->execute([11, $cipher->encrypt('1962-12-01'), 'M']);
    $ins->execute([12, $cipher->encrypt('1975-05-20'), 'K']);
    $s = $c->get(HrSuccessionService::class);
    $today = new DateTimeImmutable('2026-11-12');

    // 1. Échéancier : 12 mois avant le départ.
    [$steps, $agenda, $from] = HrSuccessionService::schedule($today, new DateTimeImmutable('2027-12-01'));
    expectS($from === '2026-12-01' && $steps[0]['start'] === '2026-12-01' && $steps[4]['due'] === '2027-11-30' && count($steps) === 5
        && array_column($agenda, 'pct') === [80, 60, 40, 0] && $agenda[3]['to'] === '2027-11-30' && $steps[3]['start'] === $agenda[0]['from'],
        'échéancier : fenêtre 01.12.2026 → 30.11.2027, 5 étapes, agenda 80/60/40/0 % sur l\'étape 4');
    expectS(count(array_filter($steps[1]['items'], fn($i) => $i['code'] === 'successor')) === 1, 'étape 2 : case « Następca wybrany »');
    [$short] = HrSuccessionService::schedule($today, new DateTimeImmutable('2027-02-12'));
    expectS($short[0]['start'] === '2026-11-12' && $short[4]['due'] === '2027-02-11', 'départ dans 3 mois : étapes comprimées depuis aujourd\'hui');

    // 2. Vue : plan montré à la volée, aucune écriture.
    $v = $s->view(1, $today);
    $jan = $v['plans'][0] ?? null;
    expectS(count($v['plans']) === 1 && $jan['virtual'] && $jan['employee_id'] === 11 && $jan['departure'] === '2027-12-01' && $jan['retire_age'] === 65 && $jan['months_left'] === 12
        && $jan['responsible']['id'] === 2 && $jan['progress'] === 0 && $v['kpi']['retire_soon'] === 1 && $v['kpi']['active'] === 1 && $v['kpi']['successors'] === 0,
        'vue : Jan (retraite 01.12.2027, 65 ans, dans 12 mois) → plan automatique, responsable Kierownik produkcji');
    expectS($count('SELECT COUNT(*) FROM n_hr_succession') === 0, 'vue : lecture sans écriture');

    // 3. Cron : enregistre une fois.
    expectS($s->sync($today) === 1 && $s->sync($today) === 0 && $count("SELECT COUNT(*) FROM n_hr_succession WHERE auto = 1 AND responsible_user = 2") === 1
        && $count('SELECT COUNT(*) FROM n_hr_succession_step') === 5 && $count('SELECT COUNT(*) FROM n_hr_succession_item') === 19 && $count('SELECT COUNT(*) FROM n_hr_succession_agenda') === 4,
        'cron : plan enregistré une seule fois (5 étapes, 19 cases, 4 lignes d\'agenda)');
    $plan = $s->view(1, $today)['plans'][0];
    $id = $plan['id'];
    expectS(!$plan['virtual'] && $plan['steps'][0]['start'] === '2026-12-01', 'plan enregistré = plan montré');

    // 4. « Mój tydzień » : étape 1 visible 7 jours avant son début, chez le responsable seulement.
    $repo = $c->get(App\Repositories\Hr\HrSuccessionRepository::class);
    expectS($repo->openSteps(2, '2026-11-19') === [] && count($repo->openSteps(2, '2026-12-01')) === 1 && $repo->openSteps(3, '2026-12-31') === [], '« Mój tydzień » : étape 1 dès le 24.11 chez Kasia');

    // 5. Cases : étape terminée quand tout est coché ; case du successeur non cochable à la main.
    foreach ($plan['steps'][0]['items'] as $it) $s->check($it['id'], true, 2);
    $plan = $s->view(1, $today)['plans'][0];
    expectS($plan['steps'][0]['done'] && $plan['steps'][0]['progress'] === 100 && $plan['progress'] === 20 && $plan['steps'][0]['items'][0]['done_name'] === 'Kasia K.',
        'étape 1 : 4 cases cochées → terminée, plan 20 %');
    $succItem = array_values(array_filter($plan['steps'][1]['items'], fn($i) => $i['code'] === 'successor'))[0];
    $code = 'ok';
    try { $s->check($succItem['id'], true, 2); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectS($code === '422', 'case « Następca wybrany » : se coche seulement en choisissant le successeur');
    $code = 'ok';
    try { $s->update($id, ['successor_employee' => 11], 2, $today); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectS($code === '422', 'successeur = la personne qui part → refus');
    $s->update($id, ['successor_employee' => 13], 2, $today);
    $plan = $s->view(1, $today)['plans'][0];
    expectS($plan['successor']['name'] === 'Olek Testowy' && $plan['steps'][1]['progress'] === 25 && $s->view(1, $today)['kpi']['successors'] === 1, 'successeur choisi → case de l\'étape 2 cochée, KPI 1 / 1');
    $s->update($id, ['successor_employee' => null], 2, $today);
    expectS($s->view(1, $today)['plans'][0]['steps'][1]['progress'] === 0, 'successeur retiré → case décochée');
    $s->update($id, ['successor_employee' => 13], 2, $today);

    // 6. Retard : cloche du responsable, une fois par semaine et par étape.
    $late = new DateTimeImmutable('2027-04-05 07:10');
    expectS($s->reminders($late) === 1 && $s->reminders($late) === 1 && $count("SELECT COUNT(*) FROM app_notification WHERE type = 'hr_succession'") === 1
        && $s->view(1, new DateTimeImmutable('2027-04-05'))['kpi']['late'] === 1, 'étape 2 en retard au 05.04.2027 : 1 rappel cette semaine, KPI « spóźnione » 1');

    // 7. Nouvelle date de départ : étapes recalculées, cases gardées.
    $s->update($id, ['departure_date' => '2028-03-01'], 2, $today);
    $plan = $s->view(1, $today)['plans'][0];
    expectS($plan['departure'] === '2028-03-01' && $plan['steps'][0]['start'] === '2027-03-01' && $plan['steps'][4]['due'] === '2028-02-29' && $plan['steps'][0]['done'],
        'date de départ changée : étapes recalculées (01.03.2027 → 29.02.2028), étape 1 reste faite');

    // 8. Départ manuel (wypowiedzenie) : date trop proche refusée, plan comprimé.
    $code = 'ok';
    try { $s->open(['employee_id' => 12, 'reason' => 'wypowiedzenie', 'departure_date' => '2026-11-15'], 1, $today); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectS($code === '422', 'départ dans moins d\'une semaine → refus');
    $ewa = $s->open(['employee_id' => 12, 'reason' => 'wypowiedzenie', 'departure_date' => '2027-02-12'], 1, $today);
    expectS($s->open(['employee_id' => 12, 'reason' => 'wypowiedzenie', 'departure_date' => '2027-02-12'], 1, $today) === $ewa, 'départ manuel : idempotent tant que le plan est actif');
    $v = $s->view(1, $today);
    $p = array_values(array_filter($v['plans'], fn($x) => $x['id'] === $ewa))[0];
    expectS(!$p['auto'] && $p['reason_label'] === 'Wypowiedzenie' && $p['steps'][0]['start'] === '2026-11-12' && $p['steps'][0]['current'] && $v['kpi']['active'] === 2,
        'départ manuel Ewa (wypowiedzenie 12.02.2027) : étape 1 en cours dès aujourd\'hui');

    // 9. Annulation : le plan automatique n'est pas recréé.
    $s->update($id, ['status' => 'cancelled'], 1, $today);
    expectS($s->sync($today) === 0 && count(array_filter($s->view(1, $today)['plans'], fn($x) => $x['employee_id'] === 11 && $x['status'] === 'active')) === 0,
        'plan annulé : pas de nouveau plan automatique');
    $code = 'ok';
    try { $s->check($plan['steps'][2]['items'][0]['id'], true, 2); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectS($code === '422', 'plan annulé : cases bloquées');

    // 10. Réglage : nombre de mois (6–60).
    $hr = $c->get(HrService::class);
    $code = 'ok';
    try { $hr->saveRules(['succession_months' => 3], 1); } catch (CustomException $e) { $code = (string) $e->getCode(); }
    expectS($code === '422' && $hr->saveRules(['succession_months' => 36], 1)['succession_months'] === 36 && $s->months() === 36, 'Ustawienia HR : plan sukcesji 6–60 mois');

    echo "hr_succession_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
