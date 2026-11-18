#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la cible du food cost du réseau, la même variable partout (foodCostCible, src/food_cost.php) — sans base.
 *
 *   php bin/tests_food_cost.php
 *
 * Chaque cas tourne dans son propre processus : la cible se garde en mémoire le temps d'une requête.
 */

if (($argv[1] ?? '') === 'cas') {
    $cas = $argv[2];
    if ($cas !== 'sans') {
        // Le référentiel : le seuil d'alerte « food-cost-pct » tel que le Catalogue des KPI le tient.
        $GLOBALS['SEUIL'] = $cas === 'illisible' ? 0.0 : (float) $cas;
        function kpiDefs(): array { return ['food-cost-pct' => ['seuil_alerte' => $GLOBALS['SEUIL']]]; }
    }
    require __DIR__ . '/../src/coach_objectifs.php';
    echo json_encode(['cible' => foodCostCible(), 'vente' => coObjectifsDepart(['foodCost' => 40, 'personnel' => 17, 'clientsScannes' => 25, 'menus' => 15]),
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
// JSON relu : 40.0 revient en 40 ; on compare des nombres (n), null reste null.
$n = static fn ($v) => is_numeric($v) ? (float) $v : $v;
$lancer = static fn (string $cas): array => json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' cas ' . escapeshellarg($cas) . ' 2>&1'), true) ?? [];

echo "La cible du réseau\n";
$R = $lancer('sans');
verifie('sans référentiel : 40 %', $n($R['cible'] ?? null) === 40.0, json_encode($R));
verifie('le Coach Vente part sur la cible : 40 / 17 / 25 / 15', $n($R['vente']['foodCost'] ?? null) === 40.0 && $n($R['vente']['personnel']) === 17.0 && $n($R['vente']['clientsScannes']) === 25.0 && $n($R['vente']['menus']) === 15.0, json_encode($R));
$R = $lancer('38');
verifie('le Catalogue la règle : 38 % partout', $n($R['cible'] ?? null) === 38.0, json_encode($R));
verifie('le Coach Vente suit : 38 %, le reste inchangé', $n($R['vente']['foodCost'] ?? null) === 38.0 && $n($R['vente']['personnel']) === 17.0, json_encode($R));
verifie('un coach sans cible food cost n’en reçoit pas', array_key_exists('foodCost', $R['pierre'] ?? []) && $R['pierre']['foodCost'] === null, json_encode($R));
$R = $lancer('illisible');
verifie('un seuil illisible (0) : le repli, 40 %', $n($R['cible'] ?? null) === 40.0, json_encode($R));

echo "Plus de seconde valeur dans le code\n";
$src = __DIR__ . '/../src';
$seconde = [];
foreach (array_merge(glob($src . '/*.php'), glob($src . '/franchise/*.php')) as $f) {
    $t = (string) file_get_contents($f);
    if (preg_match("/kpiSeuil\\('food-cost-pct'|PC_FOOD|seuilMatiereBudget/", $t)) { $seconde[] = basename($f); }
}
verifie('aucun fichier ne lit le seuil food cost autrement que par foodCostCible', $seconde === [], implode(', ', $seconde));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
