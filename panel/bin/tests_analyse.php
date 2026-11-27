#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des règles de Vendre › Analyse (src/franchise/dashboard_analyse.php), sans base ni panel :
 *   php bin/tests_analyse.php
 * la marge réalisée (à la pièce, au taux de la recette pour un produit vendu en portions, recette invraisemblable),
 * la découpe d'une période, l'évolution face à la période d'avant (au jour), la famille d'une catégorie.
 * Les nombres sont des cas de calcul, pas des données du réseau.
 */

require __DIR__ . '/../src/dashboard_analyse.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$proche = static fn (?float $a, float $b): bool => $a !== null && abs($a - $b) < 0.005;

echo "Marge\n";
$c = ['ttc' => 1.80, 'ht' => 1.70, 'cout' => 0.68];
[$caHt, $m, $motif, $methode] = daMarge(100, 180.0, $c);
verifie('à la pièce : CA HT − pièces × recette', $proche($caHt, 170.0) && $proche($m, 102.0) && $methode === 'pieces', json_encode([$caHt, $m, $methode]));
[$caHt, $m, , $methode] = daMarge(100, 90.0, ['ttc' => 18.0, 'ht' => 17.0, 'cout' => 6.8]);
verifie('vendu en portions : le taux de la recette', $proche($caHt, 85.0) && $proche($m, 85.0 * 0.6) && $methode === 'taux', json_encode([$caHt, $m, $methode]));
[, $m, $motif] = daMarge(10, 18.0, ['ttc' => 1.8, 'ht' => 1.7, 'cout' => 2.0]);
verifie('recette plus chère que le prix : pas de marge', $m === null && $motif === 'recette à vérifier');
[, $m, $motif] = daMarge(10, 18.0, ['ttc' => 1.8, 'ht' => 1.7, 'cout' => null]);
verifie('recette sans coût : pas de marge, le motif', $m === null && $motif === 'coût de la recette manquant');
[, $m] = daMarge(0, 0.0, $c);
verifie('rien vendu : marge nulle', $m === 0.0);
[, $m, $motif] = daMarge(5, 9.0, null);
verifie('hors catalogue : pas de marge', $m === null && $motif === 'prix du catalogue inconnu');

echo "Période\n";
[$pas, $T] = daDecoupe('2026-11-09', '2026-11-15');
verifie('une semaine : 7 jours', $pas === 'jour' && count($T) === 7 && $T[6] === ['2026-11-15', '2026-11-15']);
[$pas, $T] = daDecoupe('2026-10-01', '2026-12-31');
verifie('un trimestre : des semaines coupées aux bornes', $pas === 'semaine' && $T[0] === ['2026-10-01', '2026-10-04'] && end($T) === ['2026-12-28', '2026-12-31'], json_encode([$T[0], end($T)]));
[$pas, $T] = daDecoupe('2026-01-01', '2026-12-31');
verifie('une année : 12 mois', $pas === 'mois' && count($T) === 12 && $T[1] === ['2026-02-01', '2026-02-28']);
verifie('les jours, bornes comprises', daJours('2026-10-01', '2026-10-31') === 31);

echo "Évolution\n";
$e = daEvoPeriode(310.0, 100.0, 31, 300.0, 95.0, 30, array_fill(0, 31, 10.0));
verifie('au jour : 310 € sur 31 j face à 300 € sur 30 j, stable', $e['verdict'] === 'stable' && abs($e['pct'] - 0.0) < 0.05, json_encode($e));
$e = daEvoPeriode(120.0, 60.0, 7, 100.0, 50.0, 7, array_fill(0, 7, 1.0));
verifie('+20 % : en hausse', $e['verdict'] === 'monte' && abs($e['pct'] - 20.0) < 0.05);
$e = daEvoPeriode(100.0, 50.0, 7, 0.0, 0.0, 7, [0, 0, 0, 20, 30, 25, 25]);
verifie('rien avant : nouveau, depuis la 4e tranche', $e['verdict'] === 'nouveau' && $e['depuisI'] === 3, json_encode($e));
$e = daEvoPeriode(0.0, 0.0, 7, 100.0, 50.0, 7, array_fill(0, 7, 0.0));
verifie('plus rien : arrêté', $e['verdict'] === 'arrete');

echo "Famille\n";
verifie('premier segment du groupe', daGroupeDe('Viennoiserie Ind.', ['Viennoiserie Ind.' => 'Viennoiserie · Individuelle']) === 'Viennoiserie');
verifie('catégorie inconnue : Autres', daGroupeDe('?', []) === 'Autres');

echo "Cumuls par famille et par catégorie\n";
$X = [
    1 => ['cat' => 'Pain', 'groupe' => 'Boulangerie', 'v' => [100.0, 110.0, 120.0], 'q' => [40.0, 44.0, 48.0], 'vp' => 300.0, 'qp' => 120.0],
    2 => ['cat' => 'Petite Boulangerie', 'groupe' => 'Boulangerie', 'v' => [50.0, 50.0, 50.0], 'q' => [100.0, 100.0, 100.0], 'vp' => 150.0, 'qp' => 300.0],
    3 => ['cat' => 'Pain', 'groupe' => 'Boulangerie', 'v' => [0.0, 0.0, 0.0], 'q' => [0.0, 0.0, 0.0], 'vp' => 60.0, 'qp' => 20.0],
    4 => ['cat' => 'Viennoiserie Ind.', 'groupe' => 'Viennoiserie', 'v' => [200.0, 210.0, 190.0], 'q' => [150.0, 160.0, 140.0], 'vp' => 600.0, 'qp' => 450.0],
];
$G = daCumuls($X, 'groupe', 3, 21, 21);
verifie('les familles, du plus gros chiffre au plus petit', array_column($G, 'nom') === ['Viennoiserie', 'Boulangerie'], json_encode(array_column($G, 'nom')));
verifie('une famille : ses tranches additionnées', $G[1]['v'] === [150.0, 160.0, 170.0] && $G[1]['q'] === [140.0, 144.0, 148.0], json_encode($G[1]));
verifie('une famille : la référence arrêtée compte avant, pas dans n', $G[1]['vp'] === 510.0 && $G[1]['n'] === 2, json_encode($G[1]));
verifie('une famille : 480 € face à 510 €, en baisse de 5,9 %', abs($G[1]['evo']['pct'] - (-5.9)) < 0.05, json_encode($G[1]['evo']));
$C = daCumuls($X, 'cat', 3, 21, 21);
$pain = array_values(array_filter($C, static fn ($c) => $c['nom'] === 'Pain'))[0] ?? null;
verifie('une catégorie garde sa famille', $pain !== null && $pain['groupe'] === 'Boulangerie' && !isset($G[0]['groupe']), json_encode($pain));
verifie('une catégorie : 330 € face à 360 € (dont 60 € d’une référence arrêtée)', $pain !== null && $pain['vp'] === 360.0 && abs($pain['evo']['pct'] - (-8.3)) < 0.05, json_encode($pain));
verifie('trois catégories, sans ligne vide', count($C) === 3);
$C = daCumuls([5 => ['cat' => 'Noël', 'groupe' => 'Fêtes & Occasions', 'v' => [0.0, 0.0], 'q' => [0.0, 0.0], 'vp' => 80.0, 'qp' => 4.0]], 'cat', 2, 14, 14);
verifie('une catégorie vendue seulement avant : pas de ligne', $C === []);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
