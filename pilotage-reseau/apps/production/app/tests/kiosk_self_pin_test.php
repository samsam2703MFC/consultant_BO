<?php
/**
 * [360] Tablette de bureau : la personne crée son premier PIN puis le change (ancien PIN demandé).
 * [364] Recherche de son nom (RODO : nom seul, sans service ni présence).
 * [361] Tous les PIN différents ; le PIN seul ouvre le profil (empreinte, PIN anciens en bcrypt, doublons, blocage tablette).
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_self_pin_test recréée (tables minimales), supprimée à la fin. Aucun envoi.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\{ApiContext, ApiException, Translator, WorkshopConfig};
use App\Core\TransactionManager;
use App\Repositories\Workshop\{AuditLogRepository, ExecutionRepository, ParamRepository};
use App\Services\Workshop\Execution\KioskAuthService;
use App\Services\Workshop\Referentiel\AuditTrail;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_self_pin_test'); $pdo->exec('CREATE DATABASE erp_self_pin_test'); $pdo->exec('USE erp_self_pin_test');
$pdo->exec('CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(60), surname VARCHAR(60), id_section INT NULL)');
$pdo->exec('CREATE TABLE n_prod_employee (id INT PRIMARY KEY, id_production_employee INT, id_primary_group INT NULL, active TINYINT DEFAULT 1)');
$pdo->exec('CREATE TABLE n_prod_employee_credential (id_employee INT PRIMARY KEY, badge_hash CHAR(64) NULL, pin_hash VARCHAR(255) NULL, pin_lookup CHAR(64) NULL UNIQUE, pin_conflict TINYINT NOT NULL DEFAULT 0, failed_attempts INT DEFAULT 0,
    locked_until DATETIME NULL, biometric_consent TINYINT DEFAULT 0, biometric_consent_at DATETIME NULL, biometric_consent_by INT NULL, updated_by INT NULL)');
$pdo->exec('CREATE TABLE n_prod_device (id INT PRIMARY KEY, id_group INT NULL, active TINYINT, token_hash VARCHAR(64) NULL, mode VARCHAR(16) NULL, pin_failed SMALLINT NOT NULL DEFAULT 0, pin_locked_until DATETIME NULL)');
$pdo->exec('CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value VARCHAR(255))');
$pdo->exec('CREATE TABLE n_prod_audit_log (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT NULL, action VARCHAR(64), entity VARCHAR(64), entity_id VARCHAR(64) NULL,
    before_json TEXT NULL, after_json TEXT NULL, request_id VARCHAR(64) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO production_employee VALUES (30, 'Osoba', 'A', 8), (31, 'Osoba', 'B', 8), (32, 'Osoba', 'C', 8), (33, 'Osoba', 'D', 8)");
$pdo->exec('INSERT INTO n_prod_employee (id, id_production_employee) VALUES (3, 30), (4, 31), (5, 32), (6, 33)');
// PIN posés avant l'unicité (bcrypt seul) : 4 → 2468 ; 5 et 6 → le même 1357.
$pdo->exec("INSERT INTO n_prod_employee_credential (id_employee, pin_hash) VALUES (4, '" . password_hash('2468', PASSWORD_BCRYPT) . "'),
    (5, '" . password_hash('1357', PASSWORD_BCRYPT) . "'), (6, '" . password_hash('1357', PASSWORD_BCRYPT) . "')");
$pdo->exec("INSERT INTO n_prod_param VALUES ('kiosk.office_pin_all', '1'), ('kiosk.office_pin_max_attempts', '3')");
$pdo->exec("INSERT INTO n_prod_device (id, id_group, active, token_hash, mode) VALUES (5, NULL, 1, 'h', 'office')");

$svc = new KioskAuthService(new ExecutionRepository($pdo), new ParamRepository($pdo), new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48)]),
    new TransactionManager($pdo), new AuditTrail(new AuditLogRepository($pdo)));
$tr = (new ReflectionClass(Translator::class))->newInstanceWithoutConstructor();
$device = new ApiContext('r', $tr, null, [], ['prod.kiosk' => true], [], deviceId: 5);
$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$code = function (callable $f): string { try { $f(); return 'none'; } catch (ApiException $e) { $d = $e->errorCode(); return $d === 'VALIDATION_FAILED' ? $d . ':' . implode(',', array_column($e->fieldErrors(), 'code')) : $d; } };
$hash = fn(int $id) => $pdo->query("SELECT pin_hash FROM n_prod_employee_credential WHERE id_employee = {$id}")->fetchColumn();

foreach (['1111', '1234', '9876', '012345'] as $weak) $check($code(fn() => $svc->firstPin(3, $weak, $device)) === 'VALIDATION_FAILED:PIN_TOO_SIMPLE', "too simple PIN refused ({$weak})");
$check($code(fn() => $svc->firstPin(3, '12a4', $device)) === 'VALIDATION_FAILED:INVALID_PIN', 'PIN must be digits');
$r = $svc->firstPin(3, '4071', $device);
$check(strlen($r['employee_token']) > 40 && $r['employee']['id'] === 3 && password_verify('4071', (string) $hash(3)), 'first PIN created, session opened');
$check($code(fn() => $svc->firstPin(3, '5839', $device)) === 'PIN_ALREADY_SET', 'a PIN that exists cannot be replaced without the old one');
$check($code(fn() => $svc->firstPin(4, '5839', $device)) === 'PIN_ALREADY_SET' && password_verify('2468', (string) $hash(4)), 'nobody can set the PIN of a person who has one');
$check((int) $pdo->query("SELECT COUNT(*) FROM n_prod_audit_log WHERE action = 'pin_self_created' AND entity_id = '3'")->fetchColumn() === 1, 'creation audited');

$me = new ApiContext('r', $tr, null, [], ['prod.kiosk' => true], [], deviceId: 5, employeeId: 3);
$check($code(fn() => $svc->changePin(['current_pin' => '9999', 'pin' => '5839'], $me)) === 'PIN_INVALID' && password_verify('4071', (string) $hash(3)), 'change refused with a wrong current PIN');
$check($code(fn() => $svc->changePin(['current_pin' => '4071', 'pin' => '4071'], $me)) === 'VALIDATION_FAILED:PIN_SAME', 'new PIN must differ');
$check($code(fn() => $svc->changePin(['current_pin' => '4071', 'pin' => '0000'], $me)) === 'VALIDATION_FAILED:PIN_TOO_SIMPLE', 'new PIN not too simple');
$out = $svc->changePin(['current_pin' => '4071', 'pin' => '5839'], $me);
$check($out['changed'] && password_verify('5839', (string) $hash(3)) && (int) $pdo->query("SELECT COUNT(*) FROM n_prod_audit_log WHERE action = 'pin_self_changed'")->fetchColumn() === 1, 'PIN changed and audited');
$check((int) $pdo->query('SELECT failed_attempts FROM n_prod_employee_credential WHERE id_employee = 3')->fetchColumn() === 0, 'failed attempts reset');


// --- [361] Connexion par le PIN seul, unicité
$col = fn(int $id, string $c) => $pdo->query("SELECT {$c} FROM n_prod_employee_credential WHERE id_employee = {$id}")->fetchColumn();
$l = $svc->loginByPin('5839', $device);
$check($l['employee']['id'] === 3 && $l['pin_change_required'] === false, 'PIN alone opens the profile (lookup)');
$l = $svc->loginByPin('2468', $device);
$check($l['employee']['id'] === 4 && $col(4, 'pin_lookup') === $svc->pinLookup('2468'), 'old PIN (bcrypt only) found, lookup filled in');
$check($code(fn() => $svc->changePin(['current_pin' => '5839', 'pin' => '2468'], $me)) === 'VALIDATION_FAILED:PIN_TAKEN', 'a PIN used by another person is refused (tablet)');
$pdo->exec('UPDATE n_prod_device SET pin_failed = 0');
$check($code(fn() => $svc->setCredentials(3, ['pin' => '2468'], new ApiContext('r', $tr, 1, [], [], []))) === 'VALIDATION_FAILED:PIN_TAKEN', 'a PIN used by another person is refused (back office)');
$check($code(fn() => $svc->pinCredential(4, '1357')) === 'VALIDATION_FAILED:PIN_TAKEN', 'old duplicate PINs also count as taken');
$check($code(fn() => $svc->loginByPin('1357', $device)) === 'PIN_CHOOSE_NAME' && (int) $col(5, 'pin_conflict') === 1 && (int) $col(6, 'pin_conflict') === 1,
    'two people with the same old PIN: choose the name, both flagged');
$id5 = $svc->identify(['employee_id' => 5, 'pin' => '1357'], $device);
$check($id5['employee']['id'] === 5 && $id5['pin_change_required'] === true, 'name + PIN still works, new PIN required');
$me5 = new ApiContext('r', $tr, null, [], ['prod.kiosk' => true], [], deviceId: 5, employeeId: 5);
$svc->changePin(['current_pin' => '1357', 'pin' => '3907'], $me5);
$check((int) $col(5, 'pin_conflict') === 0 && $svc->loginByPin('3907', $device)['employee']['id'] === 5, 'after the new PIN, PIN alone works again');
$pdo->exec('UPDATE n_prod_device SET pin_failed = 0, pin_locked_until = NULL');
$c1 = $code(fn() => $svc->loginByPin('7777', $device)); $c2 = $code(fn() => $svc->loginByPin('7778', $device)); $c3 = $code(fn() => $svc->loginByPin('7779', $device));
$check($c1 === 'PIN_UNKNOWN' && $c2 === 'PIN_UNKNOWN' && $c3 === 'DEVICE_PIN_LOCKED', 'unknown PINs counted, tablet locked after kiosk.office_pin_max_attempts');
$check($code(fn() => $svc->loginByPin('5839', $device)) === 'DEVICE_PIN_LOCKED', 'locked tablet refuses even a right PIN');

// --- [364] Recherche de son nom
$pdo->exec('CREATE TABLE n_prod_phone (id INT AUTO_INCREMENT PRIMARY KEY, id_employee INT, revoked_at DATETIME NULL)');
$repo = new ExecutionRepository($pdo);
$found = $repo->officeSearch('soba b');
$check(count($found) === 1 && $found[0]['name'] === 'Osoba B' && (int) $found[0]['has_pin'] === 1 && !array_key_exists('clocked_in_at', $found[0]) && !array_key_exists('section_name', $found[0]),
    'name search: only matching people, name + PIN set, no presence nor section');
$check(count($repo->officeSearch('B Osoba')) === 1 && count($repo->officeSearch('Osoba', 2)) === 2, 'surname first works, limit applied');
$check($repo->officeSearch('50%') === [], 'LIKE wildcards are escaped');

$pdo->exec('DROP DATABASE erp_self_pin_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
