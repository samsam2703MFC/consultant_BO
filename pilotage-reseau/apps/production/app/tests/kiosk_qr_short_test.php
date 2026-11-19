<?php
/**
 * [355] QR pointage à code court : l'adresse porte {base}/kiosk/scan#t=CODE (11 caractères) au lieu du jeton signé
 * (≈ 340 caractères, QR de 73×73 modules lu lentement sur l'écran d'une tablette) ; le jeton reste dans n_kiosk_qr.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_kiosk_qr_test recréée (tables minimales + migration 211), supprimée à la fin. Aucun envoi.
 */
declare(strict_types=1);
$app = __DIR__ . '/..';
require_once $app . '/public/vendor/autoload.php';
require_once $app . '/App/models/libs/TCPDF/tcpdf_barcodes_2d.php';
use App\Core\Api\Workshop\{ApiContext, ApiException, Jwt, Translator, WorkshopConfig};
use App\Core\TransactionManager;
use App\Repositories\Workshop\{ExecutionRepository, ParamRepository, AuditLogRepository};
use App\Services\Workshop\Referentiel\AuditTrail;
use App\Services\Workshop\Execution\KioskAuthService;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_kiosk_qr_test'); $pdo->exec('CREATE DATABASE erp_kiosk_qr_test'); $pdo->exec('USE erp_kiosk_qr_test');
$pdo->exec(file_get_contents($app . '/migrations/tfb/211_kiosk_qr_short.sql'));
$pdo->exec('CREATE TABLE n_prod_device (id INT PRIMARY KEY, id_group INT NULL, active TINYINT, token_hash VARCHAR(64) NULL, mode VARCHAR(16) NULL)');
$pdo->exec('CREATE TABLE n_prod_group (id INT PRIMARY KEY, active TINYINT)');
$pdo->exec('CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value VARCHAR(255))');
$pdo->exec("INSERT INTO n_prod_device VALUES (5, NULL, 1, 'h', 'office')");
$repo = new ExecutionRepository($pdo);
$svc = new KioskAuthService($repo, new ParamRepository($pdo), new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48)]), new TransactionManager($pdo), new AuditTrail(new AuditLogRepository($pdo)));
$fail = 0; $check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$base = 'http://192.168.1.150/production';
$tr = (new ReflectionClass(Translator::class))->newInstanceWithoutConstructor();
$code = fn(array $q) => substr($q['url'], strpos($q['url'], '#t=') + 3);

$q = $svc->erpQr(5, 30, $base, 120);
$c = $code($q);
$check((bool) preg_match('/^P[A-Z2-9]{10}$/', $c) && strlen($q['url']) < 70, "personal QR: short code {$c}, url " . strlen($q['url']) . ' chars');
$check($q['size'] <= 37, "QR size {$q['size']}×{$q['size']} modules (was 73)");
$ctx = $svc->qrContext($c, 'r1', $tr);
$check($ctx->deviceId === 5 && $ctx->employeeId === 30, 'code resolves to tablet + person');
$ctx = $svc->qrContext(strtolower($c), 'r2', $tr);
$check($ctx->employeeId === 30, 'lowercase code still accepted');
$l = $code($svc->erpLoginQr(5, 77, $base, 120));
$check($l[0] === 'L' && $svc->loginRequestOf($l) === 77, 'login QR: L code carries the request');
$m = $code($svc->erpLoginQr(5, 78, $base, 120, 30));
$check($m[0] === 'M' && $svc->loginRequestOf($m) === 78, 'login QR with person: M code');
$p = $code($svc->erpQr(5, 30, $base, 120));
$check($svc->loginRequestOf($p) === null, 'personal QR is not a login');
try { $svc->qrContext('QABCDEFGHJK', 'r3', $tr); $check(false, 'unknown code refused'); } catch (ApiException $e) { $check($e->errorCode() === 'QR_INVALID', 'unknown code → QR_INVALID'); }
// Ancien QR (jeton complet affiché avant la mise à jour) : toujours accepté.
$jwt = (new Jwt(str_repeat('s', 48)))->encode(['aud' => KioskAuthService::QR_AUDIENCE, 'dev' => 5, 'iat' => time(), 'nbf' => time() - 5, 'exp' => time() + 60]);
$check($svc->qrContext($jwt, 'r4', $tr)->deviceId === 5, 'full token still accepted');
// Photo d'un vieux QR : expiré.
$pdo->exec("UPDATE n_kiosk_qr SET token = '" . (new Jwt(str_repeat('s', 48)))->encode(['aud' => KioskAuthService::QR_AUDIENCE, 'dev' => 5, 'iat' => time() - 300, 'nbf' => time() - 300, 'exp' => time() - 100]) . "' WHERE code = '{$c}'");
try { $svc->qrContext($c, 'r5', $tr); $check(false, 'expired'); } catch (ApiException $e) { $check($e->errorCode() === 'QR_EXPIRED', 'old code → QR_EXPIRED'); }
$pdo->exec("UPDATE n_kiosk_qr SET expires_at = NOW() - INTERVAL 2 DAY WHERE code = '{$c}'");
$svc->erpQr(5, 30, $base, 120);
$check(!$pdo->query("SELECT COUNT(*) FROM n_kiosk_qr WHERE code = '{$c}'")->fetchColumn(), 'codes expired over a day ago are purged');
$pdo->exec('DROP DATABASE erp_kiosk_qr_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
