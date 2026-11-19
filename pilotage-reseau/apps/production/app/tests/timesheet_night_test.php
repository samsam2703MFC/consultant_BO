<?php
/**
 * [365] Feuille de temps : heures de nuit 21:00–05:00 (paramètres), totaux par semaine et par mois, remarque du jour
 * écrite à la sortie, reprise sur la feuille imprimée.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_timesheet_night_test recréée (tables minimales), supprimée à la fin (TS_HTML_OUT=fichier : feuille rendue). Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\TransactionManager;
use App\Repositories\Workshop\{AuditLogRepository, ClockQuestionRepository, ExecutionRepository, ParamRepository};
use App\Services\Workshop\Execution\{SignatureStorage, TimesheetService};
use App\Services\Workshop\Referentiel\AuditTrail;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_timesheet_night_test'); $pdo->exec('CREATE DATABASE erp_timesheet_night_test'); $pdo->exec('USE erp_timesheet_night_test');
$pdo->exec('CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(60), surname VARCHAR(60), id_section INT NULL)');
$pdo->exec('CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT, id_primary_group INT NULL, active TINYINT DEFAULT 1)');
$pdo->exec("CREATE TABLE n_prod_clock (id BIGINT AUTO_INCREMENT PRIMARY KEY, id_employee INT, clock_in DATETIME, clock_out DATETIME NULL, id_device INT NULL,
    source ENUM('kiosk','manual') NOT NULL DEFAULT 'kiosk', note VARCHAR(255) NULL, in_client_uuid CHAR(36) NULL, out_client_uuid CHAR(36) NULL,
    corrected_by INT NULL, corrected_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE n_prod_signature (id INT AUTO_INCREMENT PRIMARY KEY, id_employee INT, purpose VARCHAR(32), ref VARCHAR(32), sha256 CHAR(64), signed_at DATETIME)');
$pdo->exec('CREATE TABLE n_clock_question (id INT PRIMARY KEY, kind VARCHAR(16))');
$pdo->exec('CREATE TABLE n_clock_answer (id INT AUTO_INCREMENT PRIMARY KEY, id_clock BIGINT, id_question INT, answer TINYINT)');
$pdo->exec('CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value VARCHAR(255), default_value VARCHAR(255), value_type VARCHAR(16), unit VARCHAR(16) NULL, description_pl TEXT, description_fr TEXT)');
foreach (\App\Services\Workshop\Migration\MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/214_night_hours.sql')) as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO production_employee VALUES (30, 'Osoba', 'A', 8)");
$pdo->exec('INSERT INTO n_prod_employee (id, id_production_employee) VALUES (3, 30)');
// Semaine 46 (lun. 9 → dim. 15.11) : nuit 21:00 → 07:00 (8 h de nuit), soir 14:00 → 22:30 (1,5 h) ; semaine 47 : 20:00 → 06:00 (8 h), jour 06:00 → 14:00.
$pdo->exec("INSERT INTO n_prod_clock (id_employee, clock_in, clock_out) VALUES
    (3, '2026-11-10 21:00:00', '2026-11-11 07:00:00'), (3, '2026-11-12 14:00:00', '2026-11-12 22:30:00'),
    (3, '2026-11-16 20:00:00', '2026-11-17 06:00:00'), (3, '2026-11-18 06:00:00', '2026-11-18 14:00:00')");
$repo = new ExecutionRepository($pdo);
$repo->setClockDayNote((int) $pdo->query("SELECT id FROM n_prod_clock WHERE clock_in = '2026-11-18 06:00:00'")->fetchColumn(), 'Wyszedłem — wizyta u lekarza');
$svc = new TimesheetService($repo, new TransactionManager($pdo), new AuditTrail(new AuditLogRepository($pdo)),
    (new ReflectionClass(SignatureStorage::class))->newInstanceWithoutConstructor(), new ClockQuestionRepository($pdo), new ParamRepository($pdo));
$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };

$m = $svc->month(3, '2026-11', strtotime('2026-11-19 12:00'));
$check($m['night_window'] === '21:00–05:00', 'night window from the parameters (migration 214): 21:00–05:00');
$check($m['totals']['night_hours'] == 17.5 && $m['totals']['hours'] == 36.5, 'month: 36.5 h of which 17.5 h at night (8 + 1.5 + 8)');
$w = array_column($m['weeks'], null, 'week');
$check(count($m['weeks']) === 2 && $w[46]['night_hours'] == 9.5 && $w[46]['hours'] == 18.5 && $w[47]['night_hours'] == 8.0 && $w[47]['hours'] == 18.0, 'week totals: W46 18.5 h / 9.5 h night, W47 18 h / 8 h night');
$check($w[46]['from'] === '2026-11-09' && $w[46]['to'] === '2026-11-15', 'weeks run Monday → Sunday');
$check(end($m['days'])['day_note'] === 'Wyszedłem — wizyta u lekarza', 'day note written at clock-out is on the sheet');
$pdo->exec("UPDATE n_prod_param SET value = '22:00' WHERE code = 'time.night_start'");
$pdo->exec("UPDATE n_prod_param SET value = '06:00' WHERE code = 'time.night_end'");
$svc2 = new TimesheetService($repo, new TransactionManager($pdo), new AuditTrail(new AuditLogRepository($pdo)),
    (new ReflectionClass(SignatureStorage::class))->newInstanceWithoutConstructor(), new ClockQuestionRepository($pdo), new ParamRepository($pdo));
$check($svc2->month(3, '2026-11', strtotime('2026-11-19 12:00'))['totals']['night_hours'] == 16.5, 'window changed in the parameters (22:00–06:00): 8 + 0.5 + 8');

// Feuille imprimée : sous-totaux de semaine et remarque.
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../App/Views'));
$twig->addFilter(new \Twig\TwigFilter('day_pl', fn($d) => (string) $d));
$html = $twig->render('workshop/timesheet.twig', ['sheet' => $m, 'images' => []]);
if (getenv('TS_HTML_OUT')) file_put_contents((string) getenv('TS_HTML_OUT'), $html);
$check(substr_count($html, 'class="week"') === 2 && str_contains($html, 'Tydzień 46 (09.11–15.11)') && str_contains($html, 'Razem miesiąc'), 'print: one subtotal row per week and the month total');
$check(str_contains($html, 'Wyszedłem — wizyta u lekarza') && str_contains($html, '21:00–05:00'), 'print: day note and night window');

$pdo->exec('DROP DATABASE erp_timesheet_night_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
