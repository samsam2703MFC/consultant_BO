#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des cibles du réseau, une variable chacune, la même partout (src/cibles_reseau.php) : food cost 40 %,
 * personnel 17 % — sans base.
 *
 *   php bin/tests_cibles_reseau.php
 *
 * Chaque cas tourne dans son propre processus : les cibles se gardent en mémoire le temps d'une requête. Le cas
 * « rapports » charge src/rapports.php et appelle rapportSeuils() pour de vrai (18/11/2026 : un commentaire posé au
 * milieu de sa ligne avait fait disparaître le seuil de main-d'œuvre sans qu'aucune vérification le voie).
 */

if (($argv[1] ?? '') === 'cas') {
    $cas = $argv[2];
    if ($cas === 'rapports') {
        final class Db
        {
            public static function rows(string $q, array $a = []): array { return []; }
            public static function row(string $q, array $a = []): ?array { return null; }
            public static function exec(string $q, array $a = []): int { return 0; }
            public static function config(): array { return []; }
        }
        function setting(string $k, $d = null) { return $d; }
        function body(): array { return []; }
        function journalAdd(string $a, string $b, ?string $c, string $m, ?string $t = null): void {}
        function kpiDefs(): array { return ['food-cost-pct' => ['seuil_alerte' => 39.0], 'labour-pct' => ['seuil_alerte' => 18.0]]; }
        function kpiSeuil(string $code, float $d): float { return $d; }
        require __DIR__ . '/../src/rapports.php';
        echo json_encode(rapportSeuils()) . "\n";
        exit(0);
    }
    if ($cas !== 'sans') {
        [$fc, $pe] = array_map('floatval', explode('/', $cas));
        $GLOBALS['K'] = ['food-cost-pct' => ['seuil_alerte' => $fc], 'labour-pct' => ['seuil_alerte' => $pe]];
        function kpiDefs(): array { return $GLOBALS['K']; }
    }
    require __DIR__ . '/../src/coach_objectifs.php';
    echo json_encode(['food' => foodCostCible(), 'personnel' => personnelCible(),
        'vente' => coObjectifsDepart(['foodCost' => 40, 'personnel' => 17, 'clientsScannes' => 25, 'menus' => 15]),
        'pierre' => coObjectifsDepart([])]) . "\n";
    exit(0);
}

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
// JSON relu : 40.0 revient en 40 ; on compare des nombres, null reste null.
$n = static fn ($v) => is_numeric($v) ? (float) $v : $v;
$lancer = static fn (string $cas): array => json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' cas ' . escapeshellarg($cas) . ' 2>&1'), true) ?? [];

echo "Les cibles du réseau\n";
$R = $lancer('sans');
verifie('sans référentiel : food cost 40 %, personnel 17 %', $n($R['food'] ?? null) === 40.0 && $n($R['personnel'] ?? null) === 17.0, json_encode($R));
verifie('le Coach Vente part sur les cibles : 40 / 17 / 25 / 15', $n($R['vente']['foodCost'] ?? null) === 40.0 && $n($R['vente']['personnel'] ?? null) === 17.0
    && $n($R['vente']['clientsScannes'] ?? null) === 25.0 && $n($R['vente']['menus'] ?? null) === 15.0, json_encode($R));
$R = $lancer('38/15');
verifie('le Catalogue les règle : 38 % et 15 % partout', $n($R['food'] ?? null) === 38.0 && $n($R['personnel'] ?? null) === 15.0, json_encode($R));
verifie('le Coach Vente suit : 38 / 15, le reste inchangé', $n($R['vente']['foodCost'] ?? null) === 38.0 && $n($R['vente']['personnel'] ?? null) === 15.0 && $n($R['vente']['menus'] ?? null) === 15.0, json_encode($R));
verifie('un coach sans cible n’en reçoit pas', array_key_exists('foodCost', $R['pierre'] ?? []) && $R['pierre']['foodCost'] === null && $R['pierre']['personnel'] === null, json_encode($R));
$R = $lancer('0/0');
verifie('un seuil illisible (0) : les replis, 40 % et 17 %', $n($R['food'] ?? null) === 40.0 && $n($R['personnel'] ?? null) === 17.0, json_encode($R));

echo "Les rapports lisent les deux cibles\n";
$R = $lancer('rapports');
verifie('rapportSeuils : food 39 et labour 18, venus du Catalogue', $n($R['food'] ?? null) === 39.0 && $n($R['labour'] ?? null) === 18.0, json_encode($R));
verifie('rapportSeuils garde ses autres seuils', isset($R['overhead'], $R['caEtp']), json_encode($R));

echo "Plus de seconde valeur dans le code\n";
$src = __DIR__ . '/../src';
$seconde = [];
foreach (array_merge(glob($src . '/*.php'), glob($src . '/franchise/*.php')) as $f) {
    $t = (string) file_get_contents($f);
    if (preg_match("/kpiSeuil\\('(food-cost|labour)-pct'|PC_FOOD|seuilMatiereBudget|'labour' => 33/", $t)) { $seconde[] = basename($f); }
}
verifie('aucun fichier ne lit food cost ni personnel autrement que par leur cible', $seconde === [], implode(', ', $seconde));
$js = '';
foreach (['/../public/assets/js/api.js', '/../public/assets/js/app.js', '/../public/dashboard/dashboard.js', '/../public/dashboard/offres.js'] as $f) { $js .= (string) file_get_contents(__DIR__ . $f); }
verifie('les replis des écrans valent les cibles (aucun 32 / 33 / 35 / 20 pour la matière ou la main-d’œuvre)',
    !preg_match('/food: ?3[25]\b|labour: ?33\b|labour\) \|\| 33|food \|\| 32|p <= 20 \? .v.|VALO_PERSONNEL = 20/', $js));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
