<?php
declare(strict_types=1);
/*
 * [288] Urlopy (makiety 48 + 49) sur une base jetable (structure copiée de erp) :
 *   - migration 178 rejouable (wnioski, korekta salda, paramètres 01.06 et première année suivie) ;
 *   - zaległy z poprzedniego roku : reste de l'année précédente, consommé d'abord par le congé pris jusqu'au 01.06,
 *     reste perdu après la date ; aucun zaległy avant la première année suivie (2026) ;
 *   - HR : « zostało » saisi → korekta de l'année (reste exact, zaległy de l'année suivante en découle) ;
 *   - téléphone : solde, demande du … au … (jours ouvrés hors fériés PL), refus (passé, sans jour ouvré, deux années,
 *     chevauchement, na żądanie > 4, solde insuffisant), cloche du kierownik produkcji, annulation par la personne ;
 *   - kierownik : calendrier (demande hachurée, compteur), refus avec motif obligatoire, validation → congé au calendrier.
 *
 *   php tests/leave_request_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Exceptions\CustomException;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Time\LeaveRequestService;
use App\Services\Workshop\Time\TimeService;

function expectLv(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function refusedLv(callable $fn): string
{
    try { $fn(); } catch (CustomException $e) { return $e->getCode() . ' ' . $e->getMessage(); }
    return 'accepted';
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_leave_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "leave_request_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, ['n_time_leave_request', 'n_time_leave_adjust'], true)) continue; // recréées par la migration 178
        $pdo->exec("CREATE TABLE {$testDb}.`{$t}` LIKE {$source}.`{$t}`");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $params = (string) file_get_contents(__DIR__ . '/../migrations/tfb/161_time_tracking.sql');
    foreach (MigrationRunner::splitStatements($params) as $sql) if (str_contains($sql, 'n_prod_param')) $pdo->exec($sql);
    foreach ([1, 2] as $run) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/178_leave_requests.sql')) as $sql) $pdo->exec($sql);
    $count = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    expectLv($count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('n_time_leave_request', 'n_time_leave_adjust')") === 2
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'time.leave_carry_until'")->fetchColumn() === '01.06'
        && $pdo->query("SELECT value FROM n_prod_param WHERE code = 'time.leave_since_year'")->fetchColumn() === '2026', 'migration 178 (replayed twice): 2 tables, 01.06, since 2026');

    // Kierownik produkcji (dzwonek) ; trzy osoby w grupie.
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Admin', '', 'Admin', '', '', 0, 'admin', ''), (3, 'Anna', 'Kier', 'Anna K.', 'anna@example.invalid', '', 0, 'anna', '')");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 1, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (3, 3, '2026-01-01')");
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, color, sort_order, active) VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', '#C17A2A', 1, 1)");
    $pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (1, 'Adam', 'Testowy', 0, 1), (2, 'Basia', 'Testowa', 0, 1), (3, 'Cezary', 'Testowy', 0, 1)");
    $pdo->exec("INSERT INTO n_prod_employee (id, id_production_employee, active, id_primary_group) VALUES (11, 1, 1, 1), (12, 2, 1, 1), (13, 3, 1, 1)");

    $b = new DI\ContainerBuilder();
    $b->addDefinitions([PDO::class => $pdo, WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('t', 48)])]);
    $c = $b->build();
    $time = $c->get(TimeService::class);
    $leave = $c->get(LeaveRequestService::class);
    $now = strtotime('2026-11-12 10:00:00');

    expectLv(TimeService::workdays('2026-12-21', '2026-12-31') === 7 && TimeService::workdays('2027-01-04', '2027-01-08') === 4, 'workdays: PL holidays 24–26.12 and 6.01 excluded');
    expectLv($time->carryDeadline(2027) === '2027-06-01', 'carry-over deadline = 01.06 of the year');

    // --- Zaległy : 2026 = première année (pas de zaległy) ; A prend 5 jours en mars.
    $time->addAbsence(11, 'urlop', '2026-03-02', '2026-03-06', null, 3, $now);
    $b26 = $time->leaveBalances(2026, $now)[11];
    expectLv($b26['carry'] === 0 && $b26['used'] === 5 && $b26['remaining'] === 21 && $b26['carry_next'] === 21, '2026: no carry-over, 26 − 5 = 21 → 21 to carry');

    // --- HR : « zostało 18 » → korekta −3.
    $set = $time->setLeaveRemaining(11, 18, 1, 2026, $now);
    expectLv($set['remaining'] === 18 && $set['adjust'] === -3 && $count('SELECT days FROM n_time_leave_adjust WHERE id_employee = 11 AND year = 2026') === -3, 'HR remaining 18 → adjust −3');
    expectLv(str_starts_with(refusedLv(fn() => $time->setLeaveRemaining(11, 120, 1, 2026, $now)), '422'), 'remaining out of 0–80 refused');
    $time->setLeaveRemaining(12, 26, 1, 2026, $now);
    expectLv($count('SELECT COUNT(*) FROM n_time_leave_adjust WHERE id_employee = 12') === 0, 'remaining equal to the computed balance: no adjust row');

    // --- 2027 : zaległy 18, congé 4–8.01 (4 jours ouvrés) consomme d'abord le zaległy.
    $time->addAbsence(11, 'urlop', '2027-01-04', '2027-01-08', null, 3, $now);
    $b27 = $time->leaveBalances(2027, $now)[11];
    expectLv($b27['carry'] === 18 && $b27['carry_used'] === 4 && $b27['carry_left'] === 14 && $b27['carry_lapsed'] === 0 && $b27['remaining'] === 40 && $b27['entitled'] === 44,
        '2027 (seen in 11.2026): carry 18, 4 used first, 14 left until 01.06 → 26 + 14 = 40');
    $after = $time->leaveBalances(2027, strtotime('2027-07-01 10:00:00'))[11];
    expectLv($after['carry_lapsed'] === 14 && $after['carry_left'] === 0 && $after['remaining'] === 26, 'after 01.06.2027: 14 days lapse, 26 left');
    $time->addAbsence(11, 'urlop', '2027-06-14', '2027-06-18', null, 3, $now);
    $late = $time->leaveBalances(2027, strtotime('2027-07-01 10:00:00'))[11];
    expectLv($late['carry_used'] === 4 && $late['remaining'] === 21, 'leave after 01.06 does not use the carry-over: 26 − 5 = 21');

    // --- Téléphone : saldo, refus.
    $phone = $leave->phone(12, $now);
    expectLv(isset($phone['balances']['2026'], $phone['balances']['2027']) && $phone['balances']['2026']['available'] === 26 && in_array('2026-12-24', $phone['holidays'], true),
        'phone: balance of this and next year (from October), PL holidays for the date picker');
    $ref = fn(array $in, int $emp = 12) => refusedLv(fn() => $leave->create($emp, $in, null, $now));
    expectLv(str_contains($ref(['type' => 'urlop', 'from' => '2026-11-10', 'to' => '2026-11-10']), 'od dziś'), 'past date refused');
    expectLv(str_contains($ref(['type' => 'urlop', 'from' => '2026-11-14', 'to' => '2026-11-15']), 'dni roboczych'), 'weekend only refused');
    expectLv(str_contains($ref(['type' => 'urlop', 'from' => '2026-12-28', 'to' => '2027-01-08']), 'roku kalendarzowym'), 'request across two years refused');
    expectLv(str_contains($ref(['type' => 'na_zadanie', 'from' => '2026-11-16', 'to' => '2026-11-20']), 'na żądanie'), 'on-demand over 4 days refused');
    expectLv(str_contains($ref(['type' => 'chorobowe', 'from' => '2026-11-16', 'to' => '2026-11-16']), 'Rodzaj'), 'L4 cannot be requested by phone');
    $time->setLeaveRemaining(13, 2, 1, 2026, $now);
    expectLv(str_contains($ref(['type' => 'urlop', 'from' => '2026-11-16', 'to' => '2026-11-18'], 13), 'Za mało urlopu'), 'balance too small (2 left, 3 asked) refused');

    // --- Wniosek B 21–31.12 (7 jours ouvrés) : cloche du kierownik, solde « po wnioskach ».
    $r1 = $leave->create(12, ['type' => 'urlop', 'from' => '2026-12-21', 'to' => '2026-12-31', 'note' => 'wyjazd świąteczny'], null, $now);
    expectLv($r1['days'] === 7 && $count("SELECT COUNT(*) FROM n_time_leave_request WHERE id = {$r1['id']} AND status = 'pending'") === 1, 'request 21–31.12 = 7 working days, pending');
    expectLv($count("SELECT COUNT(*) FROM app_notification WHERE type = 'leave_request' AND id_user = 3") === 1, 'bell notification for the Kierownik produkcji');
    expectLv(str_contains($ref(['type' => 'urlop', 'from' => '2026-12-30', 'to' => '2026-12-30']), 'wniosek'), 'overlapping a pending request refused');
    $bal = $leave->balance(12, 2026, $now);
    expectLv($bal['pending'] === 7 && $bal['available'] === 19 && $bal['remaining'] === 26, 'pending days lower « available », not the balance');

    $cal = $time->calendar('2026-12-21', 14, $now);
    $pB = array_column($cal['people'], null, 'employee_id')[12];
    expectLv(count($cal['pending']) === 1 && count($pB['requests']) === 1 && $pB['pending_days'] === 7 && $pB['requests'][0]['note'] === 'wyjazd świąteczny', 'calendar: hatched request + counter');
    expectLv($cal['carry_deadline'] === '2026-06-01' && in_array('2026-12-24', $cal['holiday_dates'], true), 'calendar: deadline and holidays for the dialog');

    // --- Odrzuć (motif obligatoire) / Zatwierdź.
    expectLv(str_starts_with(refusedLv(fn() => $leave->reject($r1['id'], ' ', 3)), '422'), 'reject without a reason refused');
    $leave->approve($r1['id'], 3, $now);
    $row = $pdo->query("SELECT status, id_absence, decided_by FROM n_time_leave_request WHERE id = {$r1['id']}")->fetch();
    expectLv($row['status'] === 'approved' && (int) $row['decided_by'] === 3 && $count("SELECT COUNT(*) FROM n_time_absence WHERE id = {$row['id_absence']} AND type = 'urlop' AND note LIKE 'Wniosek z telefonu%'") === 1,
        'approve → leave in the calendar (note « Wniosek z telefonu »)');
    expectLv(str_starts_with(refusedLv(fn() => $leave->approve($r1['id'], 3, $now)), '409'), 'second decision refused');
    expectLv($time->leaveBalances(2026, $now)[12]['planned'] === 7 && $leave->balance(12, 2026, $now)['pending'] === 0, 'approved: 7 days planned, nothing pending');

    $r2 = $leave->create(12, ['type' => 'na_zadanie', 'from' => '2026-11-16', 'to' => '2026-11-16'], null, $now);
    $leave->reject($r2['id'], 'za dużo osób w grupie', 3);
    expectLv($pdo->query("SELECT CONCAT(status, '|', decision_note) FROM n_time_leave_request WHERE id = {$r2['id']}")->fetchColumn() === 'rejected|za dużo osób w grupie', 'reject with a reason');
    $r3 = $leave->create(12, ['type' => 'urlop', 'from' => '2026-11-23', 'to' => '2026-11-24'], null, $now);
    expectLv(str_starts_with(refusedLv(fn() => $leave->cancel($r3['id'], 11)), '404'), 'someone else cannot cancel the request');
    $leave->cancel($r3['id'], 12);
    expectLv(str_starts_with(refusedLv(fn() => $leave->cancel($r3['id'], 12)), '409'), 'cancel only while pending');
    $mine = $leave->phone(12, $now);
    $statuses = array_column($mine['requests'], 'status');
    sort($statuses);
    expectLv($statuses === ['approved', 'cancelled', 'rejected'] && count($mine['absences']) === 1 && $mine['absences'][0]['days'] === 7, 'phone « Moje urlopy »: statuses and the approved leave');

    echo "leave_request_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
