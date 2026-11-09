#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du 20/80 du catalogue à imprimer (POST /products/pareto.pdf) — sans base, sans moteur PDF.
 *
 *   php bin/tests_catalogue.php
 *
 * La synthèse par magasin (vendues, prix face au réseau, plus cher / moins cher, volume à taille égale, alignement,
 * absentes) et le document : le tableau du 20/80, la page des magasins, la grille référence × magasin. Le 20/80 de
 * chaque catégorie et l'assortiment proposé (le 20/80 de la catégorie, 6 classiques, 2 saisonnières par saison).
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

echo "Le 20/80 par catégorie et l'assortiment proposé\n";
$R = static fn (string $k, string $nom, string $cat, float $ca, ?float $score, array $x = []) => array_merge(['k' => $k, 'nom' => $nom, 'cat' => $cat, 'ca' => $ca, 'score' => $score,
    'decision' => $score === null ? '' : ($score > 60 ? 'Garder' : ($score >= 55 ? 'Modifier' : 'Effacer')), 'oblig' => false, 'saisonnier' => false, 'saisons' => [], 'scoreAn' => null, 'caAn' => 0], $x);
$gamme = [
    $R('1', 'Croissant', 'Viennoiseries', 5000, 96), $R('2', 'Pain au chocolat', 'Viennoiseries', 3000, 90), $R('3', 'Brioche', 'Viennoiseries', 1000, 70),
    $R('4', 'Chausson', 'Viennoiseries', 600, 50, ['oblig' => true]), $R('5', 'Pain aux raisins', 'Viennoiseries', 400, 60), $R('6', 'Kouign', 'Viennoiseries', 0, null),
    $R('7', 'Galette', 'Viennoiseries', 0, null, ['saisonnier' => true, 'saisons' => ['7'], 'caAn' => 900, 'scoreAn' => 80]),
    $R('8', 'Bûche', 'Viennoiseries', 0, null, ['saisonnier' => true, 'saisons' => ['8'], 'caAn' => 1200, 'scoreAn' => 75]),
    $R('9', 'Bûche mini', 'Viennoiseries', 0, null, ['saisonnier' => true, 'saisons' => ['8'], 'caAn' => 300, 'scoreAn' => 40]),
    $R('10', 'Bûche chocolat', 'Viennoiseries', 0, null, ['saisonnier' => true, 'saisons' => ['8', '7'], 'caAn' => 500, 'scoreAn' => 60]),
    $R('11', 'Bûche nouvelle', 'Viennoiseries', 0, null, ['saisonnier' => true, 'saisons' => ['8']]),
    $R('12', 'Cookie', 'Cookies', 900, 82), $R('13', 'Cookie Halloween', 'Cookies', 300, 64, ['saisonnier' => true, 'saisons' => ['9'], 'caAn' => 300, 'scoreAn' => 70]),
    $R('14', 'Sablé', '', 0, null),
];
$PC = ppParCategorie($gamme, 80);
$parNom = array_column($PC, null, 'cat');
verifie('le 20/80 d’une catégorie : jusqu’à 80 % de SON chiffre, la plus grosse d’abord', array_column($parNom['Viennoiseries']['top'], 'nom') === ['Croissant', 'Pain au chocolat'] && $parNom['Viennoiseries']['vendues'] === 5 && $parNom['Viennoiseries']['refs'] === 11, json_encode(array_column($parNom['Viennoiseries']['top'], 'nom')));
verifie('une saisonnière vendue sur la période peut être du 20/80 ; la catégorie au plus gros chiffre en tête ; sans catégorie rangée à part', array_column($parNom['Cookies']['top'], 'nom') === ['Cookie', 'Cookie Halloween']
    && $PC[0]['cat'] === 'Viennoiseries' && isset($parNom['Sans catégorie']) && $parNom['Sans catégorie']['top'] === []);
$saisons = [['id' => '8', 'nom' => '🎄 Noël', 'du' => '2026-12-01', 'au' => '2026-12-31'], ['id' => '7', 'nom' => '👑 Épiphanie', 'du' => '2027-01-02', 'au' => '2027-01-20'], ['id' => '9', 'nom' => '🎃 Halloween', 'du' => '2026-10-20', 'au' => '2026-10-31']];
$PR = array_column(ppPropositions($gamme, $saisons, $PC, 2, 2), null, 'cat');
$V = $PR['Viennoiseries'];
verifie('les classiques : au meilleur score, hors 20/80 et hors saisonnières, vendues sur la période', array_column($V['classiques'], 'nom') === ['Brioche', 'Pain aux raisins'] && $V['manque'] === 0, json_encode(array_column($V['classiques'], 'nom')));
verifie('les saisonnières : les 2 au meilleur score sur 12 mois, le reste compté', array_column($V['saisons']['8']['retenues'], 'nom') === ['Bûche', 'Bûche chocolat'] && $V['saisons']['8']['autres'] === 2, json_encode($V['saisons']['8']));
verifie('une saisonnière de deux gammes sert les deux saisons', array_column($V['saisons']['7']['retenues'], 'nom') === ['Galette', 'Bûche chocolat'] && !isset($V['saisons']['9']));
verifie('ce qui sort : la classique de trop (même obligatoire), la jamais vendue, les saisonnières non retenues', array_column($V['sortent'], 'nom') === ['Chausson', 'Bûche mini', 'Bûche nouvelle', 'Kouign'], json_encode(array_column($V['sortent'], 'nom'), JSON_UNESCAPED_UNICODE));
verifie('la saisonnière déjà du 20/80 n’est pas reproposée pour sa saison', !isset($PR['Cookies']['saisons']['9']) && $PR['Cookies']['sortent'] === [] && $PR['Cookies']['classiques'] === []);
$P6 = array_column(ppPropositions($gamme, $saisons, $PC), null, 'cat');
verifie('6 classiques par défaut : les places sans classique vendue restent libres', count($P6['Viennoiseries']['classiques']) === 3 && $P6['Viennoiseries']['manque'] === 3);
$doc3 = ppDoc(['periode' => 'septembre 2026', 'caTotal' => 11200, 'totalRefs' => 6, 'magasins' => [], 'lignes' => [$lignes[0]], 'refs' => $gamme, 'saisons' => $saisons, 'saisonsLues' => true]);
verifie('le document : le 20/80 par catégorie, puis l’assortiment proposé', str_contains($doc3, 'Le 20/80, catégorie par catégorie') && str_contains($doc3, 'L’assortiment proposé, catégorie par catégorie')
    && strpos($doc3, 'Le 20/80, catégorie par catégorie') < strpos($doc3, 'L’assortiment proposé') && str_contains($doc3, 'Sans vente sur la période : Sans catégorie'));
verifie('le total en clair : 14 aujourd’hui, 7 toute l’année (4 à garder + 3 classiques), 3 de saison, 4 à retirer',
    str_contains($doc3, '<b>14 produits</b> aujourd’hui') && str_contains($doc3, '<b>7 toute l’année</b> (4 à garder + 3 classiques)') && str_contains($doc3, '<b>3 de saison</b>') && str_contains($doc3, '<b>4 à retirer</b>'), '');
verifie('une fiche par catégorie, en quatre colonnes : à garder, classiques, saison, à retirer', str_contains($doc3, '1</span><b>À garder') && str_contains($doc3, '2</span><b>Les 6 classiques') && str_contains($doc3, '3</span><b>2 produits par saison') && str_contains($doc3, '4</span><b>À retirer')
    && str_contains($doc3, '— aujourd’hui 11 produits → 5 toute l’année + 2 par saison, 3 à retirer'));
verifie('le classique au score faible à remplacer ; les classiques qui manquent dits ; les saisons datées, sans emoji',
    str_contains($doc3, 'Chausson<div style="font-size:8.8pt;margin-top:0.2mm"><span class="ko">⚠ score faible') && str_contains($doc3, 'Il manque 3 classiques') && str_contains($doc3, '<b>Noël</b> <span class="mu">du 01/12 au 31/12</span>') && !str_contains($doc3, '🎄'));
verifie('à retirer : jamais vendu, de saison pas dans les 2 meilleurs', str_contains($doc3, 'Bûche nouvelle<div style="font-size:8.8pt;margin-top:0.2mm"><span class="mu">jamais vendu') && str_contains($doc3, 'Bûche mini<div style="font-size:8.8pt;margin-top:0.2mm"><span class="mu">de saison, pas dans les 2 meilleurs'));
$doc4 = ppDoc(['periode' => 'x', 'caTotal' => 100, 'totalRefs' => 1, 'magasins' => [], 'lignes' => [$lignes[0]], 'refs' => $gamme, 'saisons' => []]);
verifie('sans les saisons du panel : dit, pas de produit de saison', str_contains($doc4, 'Les saisons du panel n’ont pas pu être lues') && str_contains($doc4, 'Pas de produit de saison dans cette catégorie.'));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
