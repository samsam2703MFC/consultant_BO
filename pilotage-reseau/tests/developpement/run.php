<?php
/**
 * Test du calcul de l'onglet Produits (GET /analyse/developpement/magasin), sans réseau ni base :
 * les ventes réelles d'Atelier by - Halle lues pour les maquettes (docs/maquettes/onglet-produits/reel-halle.json,
 * S34 → S46) remises au format des tranches gravées, puis adCalculer().
 *   php tests/developpement/run.php
 */
declare(strict_types=1);
require __DIR__ . '/../../src/analyse_produits.php';

$R = json_decode((string) file_get_contents(__DIR__ . '/../../docs/maquettes/onglet-produits/reel-halle.json'), true);
$sid = (int) $R['shop'];
$tranches = array_map(static fn ($s) => [$s['du'], $s['au'], $s['s']], $R['semaines']);
$grpDe = []; foreach ($R['categories'] as $c) { $grpDe[$c['nom']] = $c['groupe']; }
$lu = []; $catDe = [];
foreach ($tranches as $i => [$du]) { $lu[$sid . ':' . $du] = []; }
foreach ($R['produits'] as $p) {
    $pid = (int) explode(':', (string) $p['id'])[0];           // les portions vont à leur produit, comme dans les tranches
    $catDe[$pid] = $catDe[$pid] ?? $p['cat'];
    foreach ($tranches as $i => [$du]) {
        if (!isset($lu[$sid . ':' . $du][$pid])) { $lu[$sid . ':' . $du][$pid] = [preg_replace('/\s*—\s*1\/\d$/u', '', $p['nom']), $p['cat'], 0.0, 0.0]; }
        $lu[$sid . ':' . $du][$pid][2] += $p['q'][$i]; $lu[$sid . ':' . $du][$pid][3] += $p['v'][$i];
    }
}
$prix = [1610006 => ['ttc' => 1.8, 'ht' => 1.6981, 'cout' => 0.6838], 1300012 => ['ttc' => 0.9, 'ht' => 0.849, 'cout' => 0.9]];
$D = adCalculer($tranches, $lu, $sid, $catDe, $grpDe, $prix);

$ok = 0; $ko = 0;
$vrai = static function (bool $c, string $quoi) use (&$ok, &$ko) { if ($c) { $ok++; } else { $ko++; fwrite(STDERR, "ÉCHEC : $quoi\n"); } };
$g = []; foreach ($D['groupes'] as $x) { $g[$x['nom']] = $x; }
$p = []; foreach ($D['produits'] as $x) { $p[$x['id']] = $x; }

$vrai(count($D['semaines']['tranches']) === 13 && $D['semaines']['tranches'][0] === 'S34' && $D['semaines']['tranches'][12] === 'S46', '13 tranches, S34 → S46');
$vrai($D['semaines']['complet'] === true, 'aucune semaine muette');
$vrai(abs($D['total']['evo']['pct'] - 11.9) < 0.1 && $D['total']['evo']['verdict'] === 'monte', 'le magasin : + 11,9 %, monte (' . $D['total']['evo']['pct'] . ')');
$vrai(abs($D['total']['evo']['apres'] - 13397.38) < 1 && abs($D['total']['evo']['avant'] - 11971.49) < 1, 'le magasin : 11 971 € → 13 397 € par semaine');
$vrai(array_key_first($g) === 'Viennoiserie' && $g['Viennoiserie']['evo']['verdict'] === 'monte' && abs($g['Viennoiserie']['evo']['pct'] - 14.7) < 0.1, 'Viennoiserie en tête, + 14,7 %');
$vrai($g['Épicerie']['evo']['verdict'] === 'baisse', 'Épicerie baisse');
$vrai($g['Pâtisserie']['evo']['verdict'] === 'stable' && $g['Boissons']['evo']['verdict'] === 'stable', 'Pâtisserie et Boissons stables');
$vrai($g['Fêtes & Occasions']['evo']['verdict'] === 'nouveau' && $g['Fêtes & Occasions']['evo']['depuis'] === 'S44', 'Fêtes & Occasions : nouveau depuis S44');
$vrai(abs($p[1610006]['marge'] - 59.7) < 0.1 && $p[1610006]['prix'] === 1.8, 'Croissant : 1,80 €, marge 59,7 % au coût de la recette');
$vrai($p[1300012]['marge'] === null && $p[1300012]['margeMotif'] === 'recette à vérifier', 'Sandwich : coût = prix → recette à vérifier');
$vrai($p[1610004]['prix'] === null && $p[1610004]['marge'] === null && $p[1610004]['margeMotif'] === null, 'Pain au chocolat hors catalogue : ni prix ni marge');
$vrai(abs(array_sum(array_map(static fn ($x) => array_sum($x['v']), $D['groupes'])) - array_sum($D['total']['v'])) < 0.05, 'les catégories font le total');
$vrai($D['produits'][0]['nom'] === 'Croissant', 'le premier produit : Croissant');
// Une série vide trois semaines de suite à la fin : arrêtée.
$e = adEvolution([5, 5, 5, 5, 5, 5, 5, 5, 5, 0, 0, 0, 0], [5, 5, 5, 5, 5, 5, 5, 5, 5, 0, 0, 0, 0], $D['semaines']['tranches']);
$vrai($e['verdict'] === 'arrete' && $e['depuis'] === 'S42', 'arrêté, dernière vente S42');
$e = adEvolution([1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 0], [1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 0], $D['semaines']['tranches']);
$vrai($e['verdict'] === 'peu', 'trop peu vendu pour juger');
echo "$ok vérifications réussies, $ko échec(s)\n";
foreach (array_slice($D['groupes'], 0, 12) as $x) { printf("  %-22s %8.0f € → %8.0f € /sem  %6s %%  %s%s\n", $x['nom'], $x['evo']['avant'], $x['evo']['apres'], $x['evo']['pct'] ?? '—', $x['evo']['verdict'], $x['evo']['depuis'] ? ' ' . $x['evo']['depuis'] : ''); }
exit($ko > 0 ? 1 : 0);
