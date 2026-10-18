#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du 20/80 du catalogue à imprimer (POST /products/pareto.pdf) — sans base, sans moteur PDF.
 *
 *   php bin/tests_catalogue.php
 *
 * La synthèse par magasin (vendues, prix face au réseau, plus cher / moins cher, volume à taille égale, alignement,
 * absentes) et le document : le tableau du 20/80, la page des magasins, la grille référence × magasin.
 */
function body(): array { return []; }
function setting(string $k, mixed $d = null): mixed { return $d; }
final class Db { public static function rows(string $s, array $a = []): array { return []; } public static function row(string $s, array $a = []): ?array { return null; } public static function exec(string $s, array $a = []): void {} }
require __DIR__ . '/../src/analyse_produits.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$mags = [['id' => '2', 'nom' => 'Corbais'], ['id' => '3', 'nom' => 'Halle']];
$lignes = [
    ['nom' => 'Croissant', 'cat' => 'Viennoiseries', 'ca' => 5000, 'vol' => 3000, 'prix' => 1.6, 'mn' => 74, 'score' => 96, 'decision' => 'Garder', 'oblig' => true, 'med' => 1.60, 'ecart' => 6,
        'mag' => ['2' => ['p' => 1.55, 'q' => 1500, 'rel' => 12.0, 'auMed' => 75.0], '3' => ['p' => 1.70, 'q' => 1500, 'rel' => -8.0, 'auMed' => -150.0]]],
    ['nom' => 'Cookie', 'cat' => 'Cookies', 'ca' => 3000, 'vol' => 1250, 'prix' => 2.4, 'mn' => 75, 'score' => 82, 'decision' => 'Garder', 'oblig' => false, 'med' => 2.40, 'ecart' => 0,
        'mag' => ['2' => ['p' => 2.40, 'q' => 700, 'rel' => 4.0], '3' => ['p' => 2.41, 'q' => 550, 'rel' => -4.0]]],
    ['nom' => 'Quiche', 'cat' => 'Salé', 'ca' => 2000, 'vol' => 430, 'prix' => 4.6, 'mn' => 65, 'score' => 69, 'decision' => 'Modifier', 'oblig' => true, 'med' => 4.60, 'ecart' => 0,
        'mag' => ['2' => ['p' => 4.60, 'q' => 430, 'rel' => 0.0]]],
];

echo "La synthèse par magasin\n";
$S = ppSynthese($lignes, $mags);
verifie('vendues et absentes : Halle ne vend pas la quiche', $S['2']['vendues'] === 3 && $S['3']['vendues'] === 2 && $S['3']['absentes'] === ['Quiche'] && $S['2']['absentes'] === [], json_encode($S, JSON_UNESCAPED_UNICODE));
verifie('plus cher, moins cher : 2 % et 5 centimes au moins (le cookie à 2,41 € n’est pas plus cher)', $S['2']['moinsCher'] === 1 && $S['2']['cher'] === 0 && $S['3']['cher'] === 1 && $S['3']['moinsCher'] === 0);
verifie('le prix face au réseau : l’écart moyen en %', abs($S['2']['prixIndice'] - (100 * (1.55 / 1.6 - 1)) / 3) < 0.01 && $S['3']['prixIndice'] > 0, json_encode([$S['2']['prixIndice'], $S['3']['prixIndice']]));
verifie('le volume à taille égale : la moyenne des écarts aux autres', abs($S['2']['volIndice'] - 16 / 3) < 0.01 && $S['3']['volIndice'] === -6.0);
verifie('l’alignement sur le prix réseau : la somme en €/mois', $S['2']['auMed'] === 75.0 && $S['3']['auMed'] === -150.0);

echo "Le document\n";
$doc = ppDoc(['periode' => 'septembre 2026', 'periodePrix' => 'septembre 2026', 'seuil' => 80, 'caTotal' => 12500, 'totalRefs' => 15,
    'courbe' => [0, 40, 64, 80, 90, 100], 'magasins' => $mags, 'lignes' => array_reverse($lignes)]);
verifie('le titre et les tuiles : 3 références sur 15 (20 %), 80 % du chiffre', str_contains($doc, 'Les références qui font 80 % du chiffre') && str_contains($doc, '3 / 15') && str_contains($doc, '20 % des références vendues') && str_contains($doc, '80,0 % du chiffre du réseau'));
verifie('le tableau rangé du plus gros chiffre au plus petit, avec le cumul', strpos($doc, '>Croissant<') < strpos($doc, '>Cookie<') && strpos($doc, '>Cookie<') < strpos($doc, '>Quiche<') && str_contains($doc, '64 %') && str_contains($doc, '40,0 %'));
verifie('la marge nette pondérée par le chiffre', str_contains($doc, number_format((74 * 5000 + 75 * 3000 + 65 * 2000) / 10000, 0, ',', ' ') . ' %'));
verifie('la page des magasins et la grille : prix coloré, pièces, volume, « — » pour l’absente', str_contains($doc, 'Le prix et le volume, magasin par magasin') && str_contains($doc, 'Référence par référence')
    && str_contains($doc, '<span class="bas">1,55 €</span>') && str_contains($doc, '<span class="haut">1,70 €</span>') && str_contains($doc, '▲12 %') && str_contains($doc, '<td class="c mu">—</td>'));
verifie('la courbe de Pareto dessinée', str_contains($doc, '<polyline points="0,70 50,42'));
$doc2 = ppDoc(['periode' => 'x', 'caTotal' => 100, 'totalRefs' => 1, 'magasins' => [], 'lignes' => [$lignes[0]]]);
verifie('sans les prix par magasin : la liste seule, dite', str_contains($doc2, 'n’ont pas pu être lus') && !str_contains($doc2, 'Référence par référence'));
verifie('les noms échappés', !str_contains(ppDoc(['lignes' => [['nom' => '<b>x</b>', 'ca' => 1]], 'magasins' => []]), '<b>x</b>'));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
