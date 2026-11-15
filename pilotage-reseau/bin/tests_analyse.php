#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des règles de Vendre › Analyse (src/franchise/dashboard_analyse.php), sans base ni panel :
 *   php bin/tests_analyse.php
 * la marge réalisée (à la pièce, au taux de la recette pour un produit vendu en portions, recette invraisemblable),
 * l'évolution sur les semaines de l'onglet Produits, la famille d'une catégorie.
 * Les nombres sont des cas de calcul, pas des données du réseau.
 */

const AD_AVANT = [0, 5];
const AD_APRES = [6, 11];
require __DIR__ . '/../src/franchise/dashboard_analyse.php';

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

echo "Évolution\n";
verifie('6 semaines face aux 6 d’avant, sans la semaine en cours', $proche(daEvo([10, 10, 10, 10, 10, 10, 12, 12, 12, 12, 12, 12, 999]), 20.0));
verifie('rien avant : pas d’évolution', daEvo([0, 0, 0, 0, 0, 0, 5, 5, 5, 5, 5, 5, 5]) === null);

echo "Famille\n";
verifie('premier segment du groupe', daGroupeDe('Viennoiserie Ind.', ['Viennoiserie Ind.' => 'Viennoiserie · Individuelle']) === 'Viennoiserie');
verifie('catégorie inconnue : Autres', daGroupeDe('?', []) === 'Autres');

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
