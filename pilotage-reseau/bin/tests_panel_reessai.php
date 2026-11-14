#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la reconnexion des lectures menées de front (src/panel_api.php, PanelApi::getParallele, 14/11/2026) —
 * contre un faux panel local (`php -S`), sans base : les réglages vivent en mémoire.
 *
 *   php bin/tests_panel_reessai.php
 *
 * Le panel lâche un jeton avant la fin de notre cache : toute la volée revient en 401. Une reconnexion et un second
 * passage, une seule fois. Une volée où une réponse au moins passe ne déclenche rien ; une connexion refusée ne boucle
 * pas ; un jeton imposé (un compte administrateur) n'est jamais remplacé par le compte consultant.
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- Le faux panel : connexion → jeton « neuf » ; une route publique ; le reste exige « Bearer neuf ».
$dir = sys_get_temp_dir() . '/tests_panel_reessai_' . getmypid();
@mkdir($dir);
$journal = $dir . '/connexions';
$refus = $dir . '/refus';
file_put_contents($journal, '');
file_put_contents($dir . '/routeur.php', '<?php
header("Content-Type: application/json");
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($uri === "/api/v1/consultant/auth/login") {
    file_put_contents(' . var_export($journal, true) . ', "connexion\n", FILE_APPEND);
    if (is_file(' . var_export($refus, true) . ')) { http_response_code(401); echo json_encode(["description" => "mot de passe refusé"]); return true; }
    echo json_encode(["access_token" => "neuf"]); return true;
}
if ($uri === "/api/v1/public") { echo json_encode(["data" => ["ouvert" => true]]); return true; }
if (($_SERVER["HTTP_AUTHORIZATION"] ?? "") !== "Bearer neuf") {
    http_response_code(401); echo json_encode(["status" => "error", "description" => "Token nieprawidłowy", "code" => "INVALID_TOKEN"]); return true;
}
echo json_encode(["data" => [["id" => 145, "chemin" => $uri]]]);
return true;
');
$s = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
fclose($s);
$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/routeur.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubes);
$pret = false;
for ($i = 0; $i < 50 && !$pret; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $pret = true; } else { usleep(100000); } }
register_shutdown_function(static function () use ($srv, $dir): void {
    proc_terminate($srv);
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});

// --- Doublures : la base et les réglages en mémoire.
$GLOBALS['REGLAGES'] = [];
final class Db {
  static function config(): array { return ['panelApi' => ['base' => $GLOBALS['BASE'], 'phone' => '0470000000', 'password' => 'x']]; }
  static function exec(string $q, array $a = []): int
  {
      if (str_starts_with($q, 'INSERT INTO ceo_app_setting')) { $GLOBALS['REGLAGES'][$a[0]] = json_decode($a[1], true); }
      if (str_starts_with($q, 'DELETE FROM ceo_app_setting')) { unset($GLOBALS['REGLAGES'][$a[0]]); }
      return 1;
  }
}
function setting(string $k, $d = null) { return $GLOBALS['REGLAGES'][$k] ?? $d; }
$GLOBALS['BASE'] = 'http://127.0.0.1:' . $port . '/api/v1';
require __DIR__ . '/../src/panel_api.php';

verifie('le faux panel répond', $pret);
$connexions = static fn (): int => substr_count((string) file_get_contents($journal), 'connexion');
/** Un jeton en cache que le panel ne reconnaît plus (encore valable vingt minutes pour nous). */
$vieux = static function (): void { PanelApi::oublierJeton(); $GLOBALS['REGLAGES']['panelApiToken'] = ['token' => 'vieux', 'exp' => time() + 1200]; };

$vieux();
$r = PanelApi::getParallele(['4' => '/shops/4/employees', '5' => '/shops/5/employees']);
verifie('toute la volée en 401 : une reconnexion, et les réponses du second passage', ($r['4'][0]['id'] ?? 0) === 145 && ($r['5'][0]['chemin'] ?? '') === '/api/v1/shops/5/employees' && $connexions() === 1, json_encode($r) . ' · ' . $connexions());
verifie('le nouveau jeton est gardé', ($GLOBALS['REGLAGES']['panelApiToken']['token'] ?? '') === 'neuf');
$r = PanelApi::getParallele(['4' => '/shops/4/employees']);
verifie('jeton valable : pas de reconnexion', ($r['4'][0]['id'] ?? 0) === 145 && $connexions() === 1);

$vieux();
$r = PanelApi::getParallele(['p' => '/public', '4' => '/shops/4/employees']);
verifie('une réponse au moins passe : pas de reconnexion, la refusée reste vide', ($r['p']['ouvert'] ?? false) === true && $r['4'] === null && $connexions() === 1, json_encode($r));

$vieux();
touch($refus);
$r = PanelApi::getParallele(['4' => '/shops/4/employees', '5' => '/shops/5/employees']);
verifie('connexion refusée : une tentative, pas de boucle, des réponses vides', $r === ['4' => null, '5' => null] && $connexions() === 2, json_encode($r) . ' · ' . $connexions());
unlink($refus);

$vieux();
PanelApi::$jetonImpose = 'administrateur';
$r = PanelApi::getParallele(['4' => '/shops/4/employees']);
verifie('jeton imposé : jamais remplacé par le compte consultant', $r['4'] === null && $connexions() === 2);
PanelApi::$jetonImpose = null;

$vieux();
$r = PanelApi::getParallele(['4' => '/shops/4/employees'], 4, 25, false, false);
verifie('sans réessai demandé : le 401 reste un 401', $r['4'] === null && $connexions() === 2);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
