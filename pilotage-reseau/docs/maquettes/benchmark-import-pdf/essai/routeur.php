<?php
declare(strict_types=1);

/**
 * Banc d'essai de l'écran Benchmark (14/11/2026) : le vrai cockpit (public/index.html) et le vrai code du serveur
 * (src/benchmark.php, src/benchmark_import.php), sans MySQL, sans TFBuddy et sans clé Anthropic.
 *
 *   php -S 127.0.0.1:8099 -t . docs/maquettes/benchmark-import-pdf/essai/routeur.php      (depuis pilotage-reseau/)
 *   node docs/maquettes/benchmark-import-pdf/essai/capturer-code.js
 *
 *  - la base : SQLite (fichier dans BENCH_ESSAI, /tmp par défaut), le SQL MySQL du code traduit au vol ;
 *  - TFBuddy : le module Benchmark simulé (les manies de tests_benchmark.php), son état gardé en JSON ;
 *  - le catalogue de Sombreffe : catalogue-sombreffe.json (noms, catégories, prix TTC, poids lus le 14/11/2026, sans coûts) ;
 *  - l'IA : un transport qui rend releve-exemple.json — un EXEMPLE construit pour la maquette, pas un vrai tarif.
 * Toute autre route du cockpit répond vide.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
if (!str_starts_with($uri, '/public/api/cockpit')) { return false; }
$path = rtrim(substr($uri, strlen('/public/api/cockpit')), '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];
$DIR = __DIR__;
$ETAT = rtrim(getenv('BENCH_ESSAI') ?: sys_get_temp_dir() . '/bench-essai', '/');
@mkdir($ETAT, 0777, true);

final class Db
{
    private static ?PDO $pdo = null;
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . $GLOBALS['ETAT'] . '/base.sqlite');
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->exec('CREATE TABLE IF NOT EXISTS ceo_app_setting (k TEXT PRIMARY KEY, value TEXT)');
        }
        return self::$pdo;
    }
    /** Le SQL de MySQL tel que le code l'écrit → SQLite. */
    private static function sql(string $q): string
    {
        $q = preg_replace('/\)\s*ENGINE=.*$/s', ')', $q);
        $q = str_replace(['INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INT AUTO_INCREMENT PRIMARY KEY'], 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
        $q = preg_replace('/,\s*KEY [a-z_]+ \([^)]*\)/i', '', $q);
        if (str_contains($q, 'INTO ceo_app_setting VALUES')) { return 'INSERT OR REPLACE INTO ceo_app_setting VALUES (?, ?)'; }
        if (str_contains($q, 'INTO ceo_bench_correspondance')) {
            $q = preg_replace('/ON DUPLICATE KEY UPDATE.*$/s', 'ON CONFLICT(concurrent, libelle) DO UPDATE SET n = CASE WHEN product_id = excluded.product_id THEN n + 1 ELSE 1 END, product_id = excluded.product_id, maj_le = excluded.maj_le', $q);
        }
        return $q;
    }
    public static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare(self::sql($q)); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public static function row(string $q, array $a = []): ?array { return self::rows($q, $a)[0] ?? null; }
    public static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::sql($q)); $s->execute($a); return $s->rowCount(); }
    public static function config(): array { return ['prive' => $GLOBALS['ETAT'] . '/prive']; }
}
function setting(string $k, mixed $d = null): mixed
{
    if ($k === 'anthropic') { return ['cle' => 'essai', 'modeleBenchmark' => 'claude-opus-5-5']; }
    $r = Db::row('SELECT value FROM ceo_app_setting WHERE k = ?', [$k]);
    if (!$r) { return $d; }
    $v = json_decode((string) $r['value'], true);
    return $v === null ? $r['value'] : $v;
}
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { $d = json_decode((string) file_get_contents('php://input'), true); return is_array($d) ? $d : []; }

/** TFBuddy simulé : /shops, le catalogue de Sombreffe, le module Benchmark (état gardé en JSON). */
final class PanelApi
{
    public static ?string $lastError = null;
    private static ?array $e = null;
    public static function configured(): bool { return true; }
    private static function &etat(): array
    {
        if (self::$e === null) {
            $f = $GLOBALS['ETAT'] . '/tfbuddy.json';
            self::$e = is_file($f) ? json_decode((string) file_get_contents($f), true) : ['comp' => [], 'obs' => [], 'items' => [], 'seq' => 500];
        }
        return self::$e;
    }
    public static function garder(): void { if (self::$e !== null) { file_put_contents($GLOBALS['ETAT'] . '/tfbuddy.json', json_encode(self::$e, JSON_UNESCAPED_UNICODE)); } }
    public static function get(string $path): mixed
    {
        if ($path === '/shops') {
            return [['id' => 2, 'representative_name' => 'Atelier by Berlo - Corbais', 'name' => 'Berlo', 'zip' => '1435', 'city' => 'Corbais'],
                ['id' => 3, 'representative_name' => 'Atelier by Max & Sandra - Gosselies', 'name' => 'Max & Sandra', 'zip' => '6041', 'city' => 'Gosselies'],
                ['id' => 4, 'representative_name' => 'Atelier by - Halle', 'name' => 'Neutralles SRL', 'zip' => '1500', 'city' => 'Halle'],
                ['id' => 5, 'representative_name' => 'Atelier by Harmonie - Sombreffe', 'name' => 'Harmonie', 'zip' => '5140', 'city' => 'Sombreffe']];
        }
        return null;
    }
    public static function liste(array $d): array { return array_values(array_filter($d, 'is_array')); }
    public static function produitsDisponibles(int $shop): array { return json_decode((string) file_get_contents($GLOBALS['DIR'] . '/catalogue-sombreffe.json'), true); }
    public static function sonde(string $m, string $path, ?array $b = null, int $t = 15): array
    {
        $E = &self::etat();
        $r = fn (int $c, mixed $corps) => ['code' => $c, 'corps' => $corps, 'ms' => 1, 'erreur' => $c >= 300 ? "HTTP $c" : null];
        $u = parse_url($path); $p = $u['path']; parse_str($u['query'] ?? '', $q);
        $creer = function (string $t, array $row) use (&$E, $r) { $id = ++$E['seq']; $E[$t][(string) $id] = ['id' => $id] + $row; self::garder(); return $r(201, ['status' => 'success', 'inserted_id' => $id]); };
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors$#', $p, $x)) {
            if ($m === 'POST') { return $creer('comp', ($b ?? []) + ['shop_id' => (int) $x[1]]); }
            $l = array_values(array_filter($E['comp'], fn ($c) => $c['shop_id'] === (int) $x[1]));
            if (($q['include'] ?? '') === 'observations') { foreach ($l as &$c) { $c['observations'] = array_values(array_filter($E['obs'], fn ($o) => $o['competitor_id'] === $c['id'])); } }
            return $r(200, $l);
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)/observations$#', $p, $x)) {
            if ($m === 'POST') { return $creer('obs', ($b ?? []) + ['shop_id' => (int) $x[1], 'competitor_id' => (int) $x[2]]); }
            return $r(200, array_values(array_filter($E['obs'], fn ($o) => $o['competitor_id'] === (int) $x[2])));
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/observations/(\d+)/items$#', $p, $x)) { return $r(200, array_values(array_filter($E['items'], fn ($i) => $i['observation_id'] === (int) $x[2]))); }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)/observations/(\d+)/items$#', $p, $x) && $m === 'POST') {
            return $creer('items', ($b ?? []) + ['shop_id' => (int) $x[1], 'competitor_id' => (int) $x[2], 'observation_id' => (int) $x[3]]);
        }
        return $r(404, ['status' => 'error']);
    }
}

require __DIR__ . '/../../../../src/doc.php';
require __DIR__ . '/../../../../src/benchmark.php';
require __DIR__ . '/../../../../src/anthropic.php';
require __DIR__ . '/../../../../src/benchmark_import.php';

// L'IA simulée : le relevé d'exemple, PDF ou web selon la requête.
Anthropic::$transport = function (array $corps, array $ent) use ($DIR) {
    usleep(900000);
    $R = json_decode((string) file_get_contents($DIR . '/releve-exemple.json'), true);
    if (isset($corps['tools'])) {
        return [200, ['model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 18400, 'output_tokens' => 900],
            'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'enregistrer_releve', 'input' => $R['web']]]]];
    }
    return [200, ['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 9100, 'output_tokens' => 3200],
        'content' => [['type' => 'text', 'text' => json_encode($R['pdf'], JSON_UNESCAPED_UNICODE)]]]];
};

$META = ['reseau' => ['nom' => 'L’Atelier by', 'sousTitre' => ''], 'utilisateur' => ['initiales' => '', 'nom' => '', 'role' => 'CEO'], 'aujourdhui' => '2026-11-14',
    'dateLabel' => 'samedi 14 novembre 2026', 'periodeLabel' => 'Novembre 2026', 'exercice' => 2026, 'moisLabels' => ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
    'seuils' => ['food' => 32, 'labour' => 33, 'overhead' => 13.5, 'royalties' => 3, 'financieres' => 2.2, 'caEtp' => 13000], 'contribOuverture' => 0, 'notes' => (object) [], 'familles' => [], 'reportTypes' => []];
$OBJ = ['/reporting' => ['reports' => [], 'alertRules' => []], '/pwa/reports' => ['base' => '', 'magasins' => [], 'partages' => []],
    '/pwa/tasks' => ['date' => '', 'dates' => [], 'shops' => [], 'consultants' => [], 'totals' => ['taches' => 0, 'valides' => 0, 'refuses' => 0, 'aValider' => 0, 'noteMoy' => null], 'indispo' => true],
    '/pwa/compte' => ['base' => '', 'phone' => '', 'motDePasseDefini' => false, 'configure' => false], '/erp/compte' => ['base' => '', 'phone' => '', 'motDePasseDefini' => false, 'configure' => false, 'jetonValide' => false],
    '/production/groupes' => ['groupes' => []], '/production/categories' => ['categories' => []], '/production/periodes' => ['periodes' => []], '/referentiels/roles' => ['source' => null, 'roles' => []]];

header('Content-Type: application/json; charset=utf-8');
try {
    $rep = match (true) {
        $path === '/auth/status' => ['setup' => true, 'authed' => true, 'disabled' => true],
        $path === '/meta' => $META,
        $method === 'GET' && $path === '/benchmarks/import' => ep_bench_import(),
        $method === 'GET' && (bool) preg_match('#^/benchmarks/import/(\d+)$#', $path, $m1) => ep_bench_import_fiche((int) $m1[1]),
        $method === 'GET' && $path === '/benchmarks/classement' => ep_bench_classement(),
        // L'onglet Concurrence du dashboard magasin, sans les contrôles web d'atelierby.be (pas de réseau sur le banc).
        $method === 'GET' && $path === '/benchmarks/magasin' => (function () { $_GET['web'] = '0'; return ep_benchmark_magasin(); })(),
        $method === 'POST' && $path === '/benchmarks/import/pdf' => wr_bench_import_pdf(),
        $method === 'POST' && $path === '/benchmarks/import/web' => wr_bench_import_web(),
        $method === 'PUT' && $path === '/benchmarks/import' => wr_bench_import_maj(),
        $method === 'POST' && $path === '/benchmarks/import/encoder' => wr_bench_import_encoder(),
        $method === 'DELETE' && (bool) preg_match('#^/benchmarks/import/(\d+)$#', $path, $m2) => wr_bench_import_suppr((int) $m2[1]),
        $method === 'PUT' && $path === '/benchmarks/zones' => wr_bench_zones(),
        $method === 'GET' && isset($OBJ[$path]) => $OBJ[$path],
        $method === 'GET' => [],
        default => ['ok' => true],
    };
} catch (Throwable $e) {
    http_response_code(500);
    $rep = ['error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
}
PanelApi::garder();
echo json_encode($rep, JSON_UNESCAPED_UNICODE);
