<?php
declare(strict_types=1);
/*
 * [262] Czas pracy (pointages) sur une base jetable (structure copiée de erp) :
 *   - migrations 161 (tables, signature « comp_day », paramètres) et 162 (Kierownik produkcji : menu, accès, page d'accueil,
 *     tâches en pause + tâche n° 1 « Czas pracy dnia ») rejouables ;
 *   - règles : 8 h par jour ouvré (tolérance 15 min), week-end et férié PL sans plan, au-delà de 40 h la semaine ;
 *   - jour : statuts (sortie manquante, motif d'heure sup., motif d'absence), validation refusée puis acceptée, motif groupé,
 *     urlop / L4 → calendrier, correction de pointage (journal, jour à revalider), pointage manuel ;
 *   - banque : heures sup. des jours validés seulement, FIFO, échéance 3 mois, en retard ; jour de récupération proposé
 *     (refus : passé, week-end, plus que le plan, plus que la banque, doublon), signé (tablette : autre personne refusée,
 *     image invalide refusée ; ERP), absence « wn » et plan réduit, annulation ;
 *   - congés : 26 jours, « na żądanie » 4 par an, chevauchement refusé, droit par personne, alerte 2 absents d'un groupe ;
 *   - mois : matrice, clôture refusée (mois en cours, jours non validés), mois clos = plus de modification ;
 *   - fiche A4 PDF (logo SL Food), tâche n° 1 cochée par la validation, compteur du menu, tablette (/kiosk/me → comp_days) ;
 *   - [263] migration 163 : question de sortie « marchandise » Tak / Nie avec (i), « Tak » → quoi (obligatoire), visible au jour ;
 *   - [264] migration 165 : choix rapide catégorie → produit → pièces (catalogue des produits actifs, lignes additionnées,
 *     « z braków », « Inne », produit inconnu ou quantité hors 1–999 refusés), lignes gardées (n_clock_goods), résumé au jour ;
 *   - [265] karta : colonnes « Dzień » et « Data » séparées ;
 *   - [266] migration 164 : adresse e-mail du salarié chiffrée (jamais en clair en base), adresse invalide refusée, karta du
 *     mois mise en file d'envoi avec le PDF en pièce jointe (aucun envoi réel), personnes sans adresse listées, envoi noté.
 *
 *   php tests/time_tracking_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_time_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Time\TimeService;
use App\Services\Workshop\Time\TimeSheetMailService;

function expectTm(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

/** Code HTTP (CustomException) ou code d'erreur (ApiException) d'un appel qui doit échouer. */
function refusal(callable $fn): string
{
    try { $fn(); } catch (CustomException $e) { return $e->getCode() . ' ' . $e->getMessage(); } catch (ApiException $e) { return $e->errorCode(); }
    return 'accepted';
}

expectTm(TimeService::hm(570) === '9:30' && TimeService::hm(-45) === '−0:45' && TimeService::hm(90, true) === '+1:30', 'hm format');

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_time_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "time_tracking_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (str_starts_with($t, 'n_time_') || $t === 'n_clock_goods') continue; // recréées par les migrations 161, 164 et 165
        $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    // État avant 161 : signature sans « comp_day ».
    $pdo->exec("ALTER TABLE n_prod_signature MODIFY COLUMN purpose ENUM('clock_in','clock_out','timesheet_month','operation') NOT NULL");

    // État avant 165 : pas de choix de produits (colonne picker), note courte.
    if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_question' AND COLUMN_NAME = 'picker'")->fetchColumn() === 1) {
        $pdo->exec('ALTER TABLE n_clock_question DROP COLUMN picker');
    }
    $pdo->exec('ALTER TABLE n_clock_answer MODIFY COLUMN note VARCHAR(255) NULL');

    // --- Postes, tâches, comptes (avant 162)
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Admin', '', 'Admin', '', '', 0, 'admin', ''), (3, 'Anna', 'Kier', 'Anna K.', 'anna@example.invalid', '', 0, 'anna', '')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'PRODUKCJA', 'Produkcja', 'Production', 'bi-gear', 1, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (3, 3, '2026-01-01')");
    $pdo->exec("INSERT INTO n_position_menu (id_position, item) VALUES (3, 'production'), (3, 'purchasing')");
    $pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (3, 'production'), (3, 'supply')");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, link_path, auto_code, sort_order, active) VALUES
        (50, 3, 1, 'Zakupy tygodnia', 'Achats', 'weekly', '/workshop/buy', 'buy_sent', 30, 1)");

    $migrations = (string) file_get_contents(__DIR__ . '/../migrations/tfb/161_time_tracking.sql') . "\n" . file_get_contents(__DIR__ . '/../migrations/tfb/162_production_manager_time_only.sql')
        . "\n" . file_get_contents(__DIR__ . '/../migrations/tfb/163_clock_goods_question.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($migrations) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectTm($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'n_time_%'") === 6, 'migration 161: 6 tables');
    expectTm(str_contains((string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_signature' AND COLUMN_NAME = 'purpose'")->fetchColumn(), "'comp_day'"), 'migration 161: signature purpose comp_day');
    expectTm($count("SELECT COUNT(*) FROM n_prod_param WHERE code LIKE 'time.%'") === 6 && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'time.leave_days'")->fetchColumn() === '26', 'migration 161: params (26 days of leave)');
    expectTm($pdo->query('SELECT GROUP_CONCAT(item) FROM n_position_menu WHERE id_position = 3')->fetchColumn() === 'time'
        && $pdo->query('SELECT GROUP_CONCAT(page_key) FROM n_page_access WHERE id_position = 3')->fetchColumn() === 'time'
        && $pdo->query('SELECT home_page FROM n_position_home WHERE id_position = 3')->fetchColumn() === '/time', 'migration 162: Kierownik menu, access and home = Czas pracy only');
    expectTm($count('SELECT active FROM n_position_task WHERE id = 50') === 0 && $count("SELECT COUNT(*) FROM n_position_task WHERE id_position = 3 AND auto_code = 'time_day_validated' AND active = 1 AND sort_order = 1") === 1,
        'migration 162 (replayed twice): other tasks paused, one task n°1 « Czas pracy dnia »');
    expectTm(\App\Core\Web\PageAccess::keyFor('/time/month') === 'time' && \App\Core\Web\Rail::itemFor('/time/overtime') === 'time' && isset(\App\Core\Web\PageAccess::HOME_PAGES['/time']), 'page access key, rail item and home page');

    // --- Personnes, pointages (semaine 2.11–8.11.2026 ; 11.11 férié PL)
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (1, 'Adam', 'Testowy', 0, 1), (2, 'Basia', 'Testowa', 0, 1), (3, 'Cezary', 'Testowy', 0, 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES (11, 1, 1, 1), (12, 2, 1, 1), (13, 3, 1, 1)");
    $clock = $pdo->prepare("INSERT INTO n_prod_clock (id_employee, clock_in, clock_out, source, created_at) VALUES (?, ?, ?, 'kiosk', NOW())");
    foreach ([['2026-11-02', '06:00', '14:10'], ['2026-11-03', '06:00', '15:30'], ['2026-11-04', '06:00', '14:00'], ['2026-11-05', '06:00', '14:00'], ['2026-11-06', '06:00', '14:00'], ['2026-11-07', '07:00', '11:00']] as [$d, $in, $out]) {
        $clock->execute([11, "{$d} {$in}:00", "{$d} {$out}:00"]);
    }
    foreach (['2026-11-02', '2026-11-04', '2026-11-05', '2026-11-06'] as $d) $clock->execute([12, "{$d} 06:00:00", "{$d} 14:00:00"]);
    $clock->execute([13, '2026-11-02 06:00:00', null]);
    $openClock = (int) $pdo->lastInsertId();

    $now = strtotime('2026-11-12 18:00:00');
    $build = function () use ($pdo) {
        $b = new DI\ContainerBuilder();
        // Aucun envoi réel : le transport SMTP du test refuse tout appel (les messages restent dans la file).
        $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('t', 48)]),
            App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
                public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
                {
                    throw new RuntimeException('Aucun e-mail ne part pendant les tests.');
                }
            }]);
        return $b->build();
    };
    $c = $build();
    $time = $c->get(TimeService::class);

    // --- Jour : statuts
    $d3 = $time->day('2026-11-03', $now);
    $by = array_column($d3['people'], null, 'employee_id');
    expectTm($by[11]['status'] === 'need_overtime' && $by[11]['overtime'] === 90 && $by[11]['worked'] === 570 && $by[11]['plan'] === 480, 'Tue: A 9:30 → 1:30 overtime, reason needed');
    expectTm($by[12]['status'] === 'need_absence' && $by[13]['status'] === 'need_absence' && $d3['counts']['present'] === 1 && $d3['blocking'] === 3 && !$d3['can_validate'], 'Tue: B and C without clock → absence reasons needed');
    $d2 = $time->day('2026-11-02', $now);
    $by2 = array_column($d2['people'], null, 'employee_id');
    expectTm($by2[11]['overtime'] === 0 && $by2[11]['diff'] === 10 && $by2[11]['status'] === 'ok' && $by2[13]['status'] === 'open' && $d2['counts']['open'] === 1, 'Mon: 8:10 within tolerance (no overtime), C forgot to clock out');
    $d11 = $time->day('2026-11-11', $now);
    expectTm(!$d11['workday'] && $d11['holiday'] !== null && $d11['people'] === [] && $d11['can_validate'], '11.11 PL holiday: no plan, nobody to justify');
    $d7 = $time->day('2026-11-07', $now);
    expectTm(count($d7['people']) === 1 && $d7['people'][0]['overtime'] === 240 && $d7['people'][0]['week_extra'] === 0, 'Saturday: 4 h = overtime (no plan), week ≤ 40 h counted');

    // --- Validation refusée puis acceptée
    $refused = refusal(fn() => $time->validateDay('2026-11-03', 1, $now));
    expectTm(str_starts_with($refused, '422') && str_contains($refused, '1 powód nadgodzin') && str_contains($refused, '2 powody nieobecności'), 'validation refused with what is missing — ' . $refused);
    expectTm(str_starts_with(refusal(fn() => $time->setOvertimeReason(11, '2026-11-03', 'inne', ' ', 3)), '422'), '« inny powód » needs a description');
    expectTm(str_starts_with(refusal(fn() => $time->setOvertimeReason(11, '2026-11-03', 'nope', null, 3)), '422'), 'unknown reason refused');
    $time->setOvertimeReason(11, '2026-11-03', 'pilne', null, 3);
    $time->setAbsenceReason(12, '2026-11-03', 'urlop', null, 3);
    $time->setAbsenceReason(13, '2026-11-03', 'nieusprawiedliwiona', null, 3);
    expectTm($count("SELECT COUNT(*) FROM n_time_absence WHERE id_employee = 12 AND type = 'urlop' AND date_from = '2026-11-03'") === 1 && $count('SELECT COUNT(*) FROM n_time_note WHERE id_employee = 13') === 1, 'urlop → calendar, unjustified → day note');
    expectTm(str_starts_with(refusal(fn() => $time->setAbsenceReason(12, '2026-11-03', 'l4', null, 3)), '409'), 'second calendar entry on the same day refused');
    $v = $time->validateDay('2026-11-03', 3, $now);
    expectTm($v['validated'] !== null && str_contains($v['validated']['summary'], '1 obecny') && str_contains($v['validated']['summary'], 'nadgodziny 1:30') && str_contains($v['validated']['summary'], 'nieobecni 2'), 'day validated with summary — ' . $v['validated']['summary']);
    $wizards = $c->get(\App\Services\Organization\TaskWizards::class);
    expectTm($wizards->state('time_day_validated', '2026-11-03 00:00:00', '2026-11-04 00:00:00', '2026-11-03')['done'] && !$wizards->state('time_day_validated', '2026-11-02 00:00:00', '2026-11-03 00:00:00', '2026-11-02')['done'],
        'task n°1 done by the validation of the day');
    expectTm(str_starts_with(refusal(fn() => $time->validateDay('2026-11-20', 3, $now)), '422'), 'a future day cannot be validated');

    // --- Correction : sortie manquante (journal), revalidation après correction
    expectTm(str_starts_with(refusal(fn() => $time->correctClock($openClock, null, '2026-11-02 14:00', '', 3)), '422'), 'correction needs a reason');
    expectTm(str_starts_with(refusal(fn() => $time->correctClock($openClock, null, '2026-11-02 05:00', 'x', 3)), '422'), 'exit before entry refused');
    $time->correctClock($openClock, null, '2026-11-02 14:00', 'Zapomniane wyjście', 3);
    $time->validateDay('2026-11-02', 3, $now);
    $aMon = (int) $pdo->query("SELECT id FROM n_prod_clock WHERE id_employee = 11 AND clock_in = '2026-11-02 06:00:00'")->fetchColumn();
    $time->correctClock($aMon, null, '2026-11-02 14:05', 'Korekta wyjścia', 3);
    expectTm($time->day('2026-11-02', $now)['validated'] === null && $count("SELECT COUNT(*) FROM n_prod_audit_log WHERE entity = 'clock' AND action = 'correct'") === 2, 'correction logged, day to validate again');
    $time->validateDay('2026-11-02', 3, $now);
    $manual = $time->addClock(13, '2026-11-04 06:00', '2026-11-04 14:00', 'Zapomniane odbicie', 3);
    expectTm($manual['source'] === 'manual' && str_starts_with(refusal(fn() => $time->addClock(13, '2026-11-20 06:00', '2026-11-20 14:00', 'x', 3)), '422'), 'manual clock (not in the future)');

    // --- Semaine : au-delà de 40 h (paramètre abaissé à 30 h pour le test)
    $pdo->exec("UPDATE n_prod_param SET value = '1800' WHERE code = 'time.week_plan_min'");
    $sat = $build()->get(TimeService::class)->day('2026-11-07', $now)['people'][0];
    expectTm($sat['week_extra'] === 600 && $sat['overtime'] === 840, 'week over the weekly plan: extra on the last worked day — ' . json_encode([$sat['week_extra'], $sat['overtime']]));
    $pdo->exec("UPDATE n_prod_param SET value = '2400' WHERE code = 'time.week_plan_min'");

    // --- Banque : seulement jours validés, FIFO, échéance 3 mois
    expectTm($time->bankFor(11, null, $now)['bank'] === 90, 'bank: only validated days (Tue 1:30, Sat not yet)');
    $time->setOvertimeReason(11, '2026-11-07', 'inwentaryzacja', null, 3);
    $time->validateDay('2026-11-07', 3, $now);
    $b = $time->bankFor(11, null, $now);
    expectTm($b['bank'] === 330 && $b['oldest'] === ['day' => '2026-11-03', 'minutes' => 90] && $b['deadline'] === '2027-02-03' && $b['overdue'] === 0, 'bank 5:30, oldest 03.11, to recover by 03.02.2027');
    $ot = $time->overtime($now);
    expectTm($ot['kpis']['bank'] === 330 && $ot['kpis']['people'] === 1 && $ot['people'][0]['reasons'][0]['code'] === 'pilne', 'overtime tab: team bank and reasons');

    // --- Jour de récupération : propositions refusées, proposition, signature
    expectTm(str_starts_with(refusal(fn() => $time->proposeComp(11, '2026-11-06', 120, 3, $now)), '422'), 'comp day in the past refused');
    expectTm(str_starts_with(refusal(fn() => $time->proposeComp(11, '2026-11-14', 120, 3, $now)), '422'), 'comp day on a Saturday refused');
    expectTm(str_starts_with(refusal(fn() => $time->proposeComp(11, '2026-11-16', 500, 3, $now)), '422'), 'more than a day plan refused');
    expectTm(str_contains(refusal(fn() => $time->proposeComp(11, '2026-11-16', 400, 3, $now)), 'Bank nadgodzin do odbioru : 5:30'), 'more than the bank refused');
    $comp = $time->proposeComp(11, '2026-11-16', 120, 3, $now);
    expectTm($comp['status'] === 'proposed' && $time->bankFor(11, null, $now)['available'] === 210 && $time->bankFor(11, null, $now)['reserved'] === 120, 'proposed: 2:00 reserved, bank unchanged until signed');
    expectTm(str_starts_with(refusal(fn() => $time->proposeComp(11, '2026-11-16', 60, 3, $now)), '409'), 'one comp day per date');
    $kioskCtx = new ApiContext('t', new Translator('pl'), null, [], [], [], null, 11, null);
    $state = $c->get(\App\Services\Workshop\Execution\ExecutionService::class)->clockState($kioskCtx);
    expectTm(count($state['comp_days']) === 1 && $state['comp_days'][0]['hours'] === '2:00' && $state['comp_days'][0]['weekday'] === 'poniedziałek', 'tablet / phone: comp day to sign after clocking');
    $im = imagecreatetruecolor(300, 90);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imageline($im, 20, 60, 280, 25, imagecolorallocate($im, 17, 17, 17));
    ob_start();
    imagepng($im);
    $png = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    expectTm(str_starts_with(refusal(fn() => $time->signComp($comp['id'], $png, 12, null, null)), '403'), 'another person cannot sign');
    expectTm(refusal(fn() => $time->signComp($comp['id'], 'not-an-image', 11, null, null)) === 'VALIDATION_FAILED', 'invalid signature image refused');
    $signed = $time->signComp($comp['id'], $png, 11, null, null);
    expectTm($signed['status'] === 'signed' && $count("SELECT COUNT(*) FROM n_prod_signature WHERE purpose = 'comp_day' AND ref = '{$comp['id']}' AND id_employee = 11 AND storage = 'db'") === 1,
        'signed at the tablet: signature stored (comp_day)');
    expectTm($count("SELECT minutes FROM n_time_absence WHERE id_employee = 11 AND type = 'wn' AND date_from = '2026-11-16' AND id_comp = {$comp['id']}") === 120, 'signed comp day → calendar « wolne za nadgodziny » 2:00');
    $b = $time->bankFor(11, null, $now);
    expectTm($b['bank'] === 210 && $b['oldest'] === ['day' => '2026-11-07', 'minutes' => 210] && $b['deadline'] === '2027-02-07', 'FIFO: 03.11 recovered first, 3:30 left from 07.11');
    expectTm(str_starts_with(refusal(fn() => $time->signComp($comp['id'], $png, 11, null, null)), '409'), 'signed only once');
    $mon16 = array_column($time->day('2026-11-16', strtotime('2026-11-16 18:00:00'))['people'], null, 'employee_id')[11];
    expectTm($mon16['plan'] === 360 && $mon16['absence']['type'] === 'wn', 'partial comp day: plan 8:00 − 2:00 = 6:00');
    $time->cancelComp($comp['id'], 3, $now);
    expectTm($time->bankFor(11, null, $now)['bank'] === 330 && $count('SELECT COUNT(*) FROM n_time_absence WHERE id_comp IS NOT NULL') === 0, 'cancelled future comp day: bank back, calendar entry removed');
    $comp2 = $time->proposeComp(11, '2026-11-17', 330, 3, $now);
    $time->signComp($comp2['id'], $png, null, null, 3);
    expectTm($time->bankFor(11, null, $now)['bank'] === 0 && $time->overtime($now)['kpis']['recovered_month'] === 330, 'signed in the ERP (in front of the manager): bank recovered');
    $time->cancelComp($comp2['id'], 3, $now);

    // --- Heures en retard (plus de 3 mois)
    $clock->execute([11, '2026-07-01 06:00:00', '2026-07-01 16:00:00']);
    $time->setOvertimeReason(11, '2026-07-01', 'awaria', null, 3);
    $time->setAbsenceReason(12, '2026-07-01', 'inna', 'test', 3);
    $time->setAbsenceReason(13, '2026-07-01', 'nieusprawiedliwiona', null, 3);
    $time->validateDay('2026-07-01', 3, $now);
    $b = $time->bankFor(11, null, $now);
    expectTm($b['bank'] === 450 && $b['overdue'] === 120 && $b['oldest']['day'] === '2026-07-01' && $b['deadline'] === '2026-10-01', 'overtime older than 3 months: overdue');

    // --- Congés
    $leave = $time->leaveBalances(2026, $now);
    expectTm($leave[12]['right'] === 26 && $leave[12]['used'] === 1 && $leave[12]['remaining'] === 25, 'leave: 26 days, 1 used (03.11)');
    expectTm($time->addAbsence(12, 'na_zadanie', '2026-11-17', '2026-11-21', null, 3, $now) > 0, 'leave on request 17–21.11 (4 working days)');
    expectTm(str_contains(refusal(fn() => $time->addAbsence(12, 'na_zadanie', '2026-11-24', '2026-11-24', null, 3, $now)), 'najwyżej 4'), 'leave on request: at most 4 days a year');
    expectTm(str_starts_with(refusal(fn() => $time->addAbsence(12, 'urlop', '2026-11-20', '2026-11-25', null, 3, $now)), '409'), 'overlapping leave refused');
    expectTm(str_starts_with(refusal(fn() => $time->addAbsence(12, 'wn', '2026-11-25', '2026-11-25', null, 3, $now)), '422'), 'comp day only through the overtime tab');
    $time->setLeaveRight(12, 20, 1);
    $leave = $time->leaveBalances(2026, $now);
    expectTm($leave[12]['right'] === 20 && $leave[12]['planned'] === 4 && $leave[12]['on_demand'] === 4 && $leave[12]['remaining'] === 15, 'leave right per person (20), 4 planned');
    $time->addAbsence(13, 'urlop', '2026-11-18', '2026-11-18', null, 3, $now);
    $cal = $time->calendar('2026-11-16', 14, $now);
    expectTm($cal['absent']['2026-11-18'] === 2 && $cal['warnings'] === [['date' => '2026-11-18', 'group' => 'Piekarnia', 'count' => 2]], 'calendar: 2 absent in Piekarnia on 18.11 → warning');
    $wnId = (int) $time->proposeComp(11, '2026-11-25', 60, 3, $now)['id'];
    $time->signComp($wnId, $png, 11, null, null);
    $wnAbsence = (int) $pdo->query("SELECT id FROM n_time_absence WHERE id_comp = {$wnId}")->fetchColumn();
    expectTm(str_starts_with(refusal(fn() => $time->deleteAbsence($wnAbsence, 3)), '409'), 'comp day not deleted from the calendar (cancel it in Nadgodziny)');

    // --- Mois, clôture
    $m = $time->month('2026-11', $now);
    $rows = array_column($m['people'], null, 'employee_id');
    expectTm($rows[11]['cells']['2026-11-03']['w'] === 570 && $rows[11]['cells']['2026-11-03']['ot'] === 90 && $rows[12]['cells']['2026-11-03']['a'] === 'U' && $rows[11]['cells']['2026-11-25']['a'] === 'WN',
        'month matrix: hours, overtime, U, WN');
    expectTm(in_array('2026-11-05', $m['pending'], true) && !in_array('2026-11-03', $m['pending'], true) && !$m['can_close'], 'month: days still to validate, cannot close');
    expectTm(str_contains(refusal(fn() => $time->closeMonth('2026-11', 3, $now)), 'Miesiąc jeszcze trwa'), 'month not finished: no closing');
    expectTm(str_contains(refusal(fn() => $time->closeMonth('2026-10', 3, $now)), 'Najpierw zatwierdź'), 'closing needs every day validated');
    $pdo->exec("INSERT INTO n_time_month (month, closed_by) VALUES ('2026-11', 1)");
    expectTm(str_starts_with(refusal(fn() => $time->setOvertimeReason(11, '2026-11-05', 'pilne', null, 3)), '409') && str_starts_with(refusal(fn() => $time->reopenDay('2026-11-03', 3)), '409'), 'closed month: no more changes');
    $time->reopenMonth('2026-11', 1);

    // --- Fiche A4, compteur du menu
    $sheet = $c->get(\App\Services\Workshop\Time\TimeSheetPdf::class)->render([11], '2026-11');
    expectTm(str_starts_with($sheet['content'], '%PDF') && $sheet['filename'] === 'KARTA_CZASU_PRACY_2026-11_Adam_Testowy.pdf', 'A4 sheet PDF — ' . $sheet['filename']);
    $s = $time->sheet(11, '2026-11', $now);
    expectTm($s['totals']['overtime'] === 330 && $s['totals']['wn'] === 1 && $s['rows'][2]['reason'] === 'Zamówienie pilne / domówienie', 'sheet: validated overtime, comp day, reason');
    expectTm($c->get(\App\Repositories\Workshop\TimeRepository::class)->daysToValidate('2026-11-01', '2026-11-12') === 3, 'menu counter: days with clocks not validated (04, 05, 06.11)');

    // --- [263] Sortie : « Czy zabierasz dziś towar ? » (Tak / Nie, (i), « Tak » → co)
    expectTm($count("SELECT COUNT(*) FROM n_clock_question WHERE kind = 'yes_no' AND direction = 'out'") === 1, 'migration 163 (replayed twice): one goods question at clock-out');
    $cq = $c->get(\App\Services\Workshop\Execution\ClockQuestionService::class);
    $goods = array_values(array_filter($cq->forKiosk(true)['out'], fn($q) => $q['kind'] === 'yes_no'))[0];
    expectTm($goods['required'] && str_starts_with($goods['text'], 'Avez-vous repris de la marchandise') && str_contains((string) $goods['info'], 'demi-tarte sous le manteau'), 'tablet (FR): goods question with (i) policy');
    $gid = $goods['id'];
    expectTm(refusal(fn() => $cq->check('out', [], false)) === 'CLOCK_QUESTIONS_REQUIRED', 'goods question must be answered (Tak or Nie)');
    expectTm(refusal(fn() => $cq->check('out', [['question_id' => $gid, 'yes' => true, 'note' => ' ']], false)) === 'CLOCK_QUESTIONS_REQUIRED', '« Tak » needs what is taken');
    $no = $cq->check('out', [['question_id' => $gid, 'yes' => false]], false);
    expectTm($no[0]['answer'] === false && $no[0]['note'] === null, '« Nie » is a valid answer');
    $friday = (int) $pdo->query("SELECT id FROM n_prod_clock WHERE id_employee = 11 AND clock_in = '2026-11-06 06:00:00'")->fetchColumn();
    $cq->save($friday, 'out', $cq->check('out', [['question_id' => $gid, 'yes' => true, 'note' => '2 tarty z braków']], false));
    expectTm($pdo->query("SELECT note FROM n_clock_answer WHERE id_clock = {$friday}")->fetchColumn() === '2 tarty z braków', 'declaration kept with the clock-out');
    $fri = array_column($time->day('2026-11-06', $now)['people'], null, 'employee_id');
    expectTm($fri[11]['took'] === '2 tarty z braków' && $fri[12]['took'] === null, 'Czas pracy › Dzień shows what was taken');
    $sheetRow = $c->get(\App\Services\Workshop\Execution\TimesheetService::class)->month(11, '2026-11', $now)['days'];
    $friRow = array_values(array_filter($sheetRow, fn($d) => $d['id'] === $friday))[0];
    expectTm(($friRow['answers']['out']['declared'] ?? []) === ['2 tarty z braków'] && $friRow['answers']['out']['no'] === [], 'timesheet: declaration, not an unconfirmed point');

    // --- [264] Choix rapide des produits repris : migration 165 (rejouée deux fois), catalogue, lignes, résumé
    $m165 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/165_clock_goods_picker.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($m165) as $sql) $pdo->exec($sql);
    expectTm($pdo->query("SELECT picker FROM n_clock_question WHERE id = {$gid}")->fetchColumn() === 'products'
        && $count("SELECT COUNT(*) FROM n_clock_question WHERE picker = 'products'") === 1
        && $count("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_answer' AND COLUMN_NAME = 'note'") === 600
        && $count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_goods'") === 1,
        'migration 165: goods question picks products, longer note, n_clock_goods');
    $pdo->exec("INSERT INTO category_product (id, name) VALUES (530, 'Tartes 28'), (820, 'Quiches')");
    $pdo->exec("INSERT INTO final_product (id, name, id_category, active) VALUES (1, 'Tarte pommes', 530, 1), (2, 'Tarte poires', 530, 1), (3, 'Quiche lorraine', 820, 1), (4, 'Ancienne quiche', 820, 0)");
    $kiosk = $cq->forKiosk(false);
    $goodsQ = array_values(array_filter($kiosk['out'], fn($q) => $q['kind'] === 'yes_no'))[0];
    expectTm($goodsQ['picker'] === 'products' && array_column($kiosk['catalog'], 'name') === ['Quiches', 'Tartes 28']
        && array_column($kiosk['catalog'][0]['products'], 'name') === ['Quiche lorraine'] && array_column($kiosk['catalog'][1]['products'], 'id') === [2, 1],
        'tablet: catalogue by category (alphabetical), active products only');
    expectTm(!array_key_exists('catalog', array_filter($cq->forKiosk(false), fn($v, $k) => $k === 'in', ARRAY_FILTER_USE_BOTH)) && $kiosk['in'] !== null, 'catalogue sent once, next to the questions');
    expectTm(refusal(fn() => $cq->check('out', [['question_id' => $gid, 'yes' => true]], false)) === 'CLOCK_QUESTIONS_REQUIRED', '« Tak » without a product: refused');
    expectTm(refusal(fn() => $cq->check('out', [['question_id' => $gid, 'yes' => true, 'items' => [['product_id' => 999, 'quantity' => 1]]]], false)) === 'VALIDATION_FAILED', 'unknown product refused');
    expectTm(refusal(fn() => $cq->check('out', [['question_id' => $gid, 'yes' => true, 'items' => [['product_id' => 1, 'quantity' => 0]]]], false)) === 'VALIDATION_FAILED'
        && refusal(fn() => $cq->check('out', [['question_id' => $gid, 'yes' => true, 'items' => [['product_id' => 1, 'quantity' => 1000]]]], false)) === 'VALIDATION_FAILED', 'quantity outside 1–999 refused');
    $picked = $cq->check('out', [['question_id' => $gid, 'yes' => true, 'note' => '1 kg mąki',
        'items' => [['product_id' => 1, 'quantity' => 2], ['product_id' => 3, 'quantity' => 1, 'waste' => true], ['product_id' => 1, 'quantity' => 1]]]], false);
    expectTm($picked[0]['note'] === '3 × Tarte pommes · 1 × Quiche lorraine (braki) · inne : 1 kg mąki' && count($picked[0]['goods']) === 3, 'lines summed per product, « braki », « inne » — ' . $picked[0]['note']);
    $thursday = (int) $pdo->query("SELECT id FROM n_prod_clock WHERE id_employee = 11 AND clock_in = '2026-11-05 06:00:00'")->fetchColumn();
    $cq->save($thursday, 'out', $picked);
    expectTm($count("SELECT COUNT(*) FROM n_clock_goods WHERE id_clock = {$thursday}") === 3
        && $count("SELECT quantity FROM n_clock_goods WHERE id_clock = {$thursday} AND id_final_product = 1") === 3
        && $count("SELECT from_waste FROM n_clock_goods WHERE id_clock = {$thursday} AND id_final_product = 3") === 1
        && $pdo->query("SELECT product FROM n_clock_goods WHERE id_clock = {$thursday} AND id_final_product IS NULL")->fetchColumn() === '1 kg mąki', 'goods lines kept with the clock-out');
    $thu = array_column($time->day('2026-11-05', $now)['people'], null, 'employee_id');
    expectTm($thu[11]['took'] === '3 × Tarte pommes · 1 × Quiche lorraine (braki) · inne : 1 kg mąki', 'Czas pracy › Dzień shows the picked products');
    $cq->save($thursday, 'out', $cq->check('out', [['question_id' => $gid, 'yes' => true, 'items' => [['product_id' => 2, 'quantity' => 4]]]], false));
    expectTm($count("SELECT COUNT(*) FROM n_clock_goods WHERE id_clock = {$thursday}") === 1 && $pdo->query("SELECT note FROM n_clock_answer WHERE id_clock = {$thursday}")->fetchColumn() === '4 × Tarte poires', 'same clock answered again: lines replaced');
    $only = $cq->check('out', [['question_id' => $gid, 'yes' => true, 'note' => 'karton po jajkach']], false);
    expectTm($only[0]['note'] === 'inne : karton po jajkach' && $only[0]['goods'][0]['id_final_product'] === null, '« Inne » alone is enough');
    $none = $cq->check('out', [['question_id' => $gid, 'yes' => false, 'items' => [['product_id' => 1, 'quantity' => 2]]]], false);
    expectTm($none[0]['answer'] === false && $none[0]['note'] === null && $none[0]['goods'] === [], '« Nie » keeps no line');
    $admin = new ApiContext('test', new Translator('pl'), 1, [], ['prod.employees.write' => true]);
    $plain = $cq->create(['text_pl' => 'Czy był wypadek ?', 'direction' => 'out', 'kind' => 'yes_no', 'required' => false], $admin);
    expectTm($plain['picker'] === 'text' && $cq->update($plain['id'], ['picker' => 'products'], $admin)['picker'] === 'products', 'admin: picker saved with the question');
    $cq->delete($plain['id']);

    // --- [265] Karta : jour et date dans deux colonnes
    $src = (string) file_get_contents(__DIR__ . '/../App/Services/Workshop/Time/TimeSheetPdf.php');
    expectTm(str_contains($src, "['label' => 'Dzień', 'w' => 0.7]") && str_contains($src, "['label' => 'Data'") && str_contains($src, "\$d['dow'], date('d.m.Y'"), 'A4 sheet: « Dzień » and « Data » columns');

    // --- [266] E-mail du salarié (chiffré) et karta du mois par e-mail (file d'envoi, aucun envoi réel)
    $m164 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/164_time_sheet_mail.sql');
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements($m164) as $sql) $pdo->exec($sql);
    expectTm($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_time_contact', 'n_time_sheet_mail')") === 2, 'migration 164 (replayed twice): contact and sheet mail tables');
    $mail = $c->get(TimeSheetMailService::class);
    expectTm(str_starts_with(refusal(fn() => $mail->setEmail(11, 'adam-at-example', 3)), '422') && str_starts_with(refusal(fn() => $mail->setEmail(999, 'x@example.invalid', 3)), '404'), 'invalid address and unknown person refused');
    expectTm(str_contains(refusal(fn() => $mail->send('2026-11', null, 3, $now)), 'brak adresu e-mail'), 'nobody has an address: nothing sent');
    $saved = $mail->setEmail(11, ' Adam.Testowy@Example.INVALID ', 3);
    $enc = (string) $pdo->query('SELECT email_enc FROM n_time_contact WHERE id_employee = 11')->fetchColumn();
    expectTm($saved['email'] === 'adam.testowy@example.invalid' && str_starts_with($enc, 's1:') && !str_contains($enc, '@') && !str_contains(strtolower($enc), 'adam'), 'address kept encrypted, never in clear');
    expectTm(!str_contains((string) $pdo->query("SELECT GROUP_CONCAT(COALESCE(after_json, '')) FROM n_prod_audit_log WHERE entity = 'n_time_contact'")->fetchColumn(), 'example.invalid'), 'journal: the fact, not the address');
    $contacts = array_column($mail->contacts('2026-11'), null, 'employee_id');
    expectTm($contacts[11]['email'] === 'adam.testowy@example.invalid' && $contacts[12]['email'] === null && $contacts[11]['sent'] === null && count($contacts) === 3, 'contacts: every active person, decrypted address');
    expectTm(str_starts_with(refusal(fn() => $mail->send('2027-01', null, 3, $now)), '422') && str_starts_with(refusal(fn() => $mail->send('2026-11', [999], 3, $now)), '422'), 'future month and person without activity refused');
    $sent = $mail->send('2026-11', null, 3, $now);
    expectTm($sent['queued'] === 1 && $sent['missing'] === ['Basia Testowa', 'Cezary Testowy'], 'one sheet queued, people without address listed');
    $msg = $pdo->query("SELECT * FROM communication_message WHERE message_type = 'time_sheet'")->fetchAll();
    expectTm(count($msg) === 1 && $msg[0]['recipient_email'] === 'adam.testowy@example.invalid' && $msg[0]['status'] === 'queued' && $msg[0]['subject_text'] === 'Karta czasu pracy — listopad 2026'
        && str_contains($msg[0]['body_text'], 'Dzień dobry Adam') && $msg[0]['created_by'] === 'time_sheet:3', 'message queued for the worker (delivery mode decides, nothing sent here)');
    $att = $pdo->query('SELECT filename, mime_type, LEFT(content, 4) AS head FROM communication_message_attachment WHERE id_message = ' . (int) $msg[0]['id'])->fetch();
    expectTm($att['filename'] === 'KARTA_CZASU_PRACY_2026-11_Adam_Testowy.pdf' && $att['mime_type'] === 'application/pdf' && $att['head'] === '%PDF', 'PDF sheet attached');
    $summary = $mail->summary('2026-11');
    expectTm($summary[11]['email'] === true && $summary[11]['sent']['status'] === 'queued' && !isset($summary[12]) && $mail->summary('2026-10')[11]['sent'] === null, 'month summary: sent state per person, no address in it');
    $mail->send('2026-11', [11], 3, $now);
    expectTm($count("SELECT COUNT(*) FROM n_time_sheet_mail WHERE id_employee = 11 AND month = '2026-11'") === 2, 'sending again is noted');
    $mail->setEmail(11, '', 3);
    expectTm($count('SELECT COUNT(*) FROM n_time_contact') === 0 && $mail->summary('2026-11')[11]['email'] === false, 'empty address = removed');

    echo "time_tracking_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
