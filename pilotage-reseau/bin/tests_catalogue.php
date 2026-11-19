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
// L'assortiment proposé : celui de la Structure de gamme, envoyé par l'écran (19/11/2026, docs/audit-rail.md § 4.2).
$N = static fn (string $id, string $nom, bool $oblig = false) => ['id' => $id, 'nom' => $nom, 'oblig' => $oblig];
$structure = ['regles' => ['base' => 'ca', 'std' => 80, 'max' => 6, 'sai' => 4, 'seuil' => 3],
    'saisons' => [['id' => 'hiver', 'nom' => 'Hiver', 'lib' => 'déc. – févr.']], 'periodes' => [['id' => 'noel', 'nom' => 'Noël', 'lib' => '15 nov. – 26 déc.']],
    'cats' => [['cat' => 'Viennoiseries', 'grp' => 'Sucré', 'refs' => 11,
        'standard' => [$N('1', 'Croissant'), $N('2', 'Pain au chocolat'), $N('3', 'Brioche')], 'garder' => [$N('5', 'Pain aux raisins')],
        'saisons' => ['hiver' => [$N('7', 'Galette')]], 'periodes' => ['noel' => [$N('8', 'Bûche'), $N('10', 'Bûche chocolat')]],
        'arbitrer' => [$N('9', 'Bûche mini')], 'observation' => [$N('11', 'Bûche nouvelle')], 'desactiver' => [$N('4', 'Chausson', true), $N('6', 'Kouign')]]]];
$doc3 = ppDoc(['periode' => 'septembre 2026', 'caTotal' => 11200, 'totalRefs' => 6, 'magasins' => [], 'lignes' => [$lignes[0]], 'refs' => $gamme, 'structure' => $structure]);
verifie('le document : le 20/80 par catégorie, puis l’assortiment proposé', str_contains($doc3, 'Le 20/80, catégorie par catégorie') && str_contains($doc3, 'L’assortiment proposé, catégorie par catégorie')
    && strpos($doc3, 'Le 20/80, catégorie par catégorie') < strpos($doc3, 'L’assortiment proposé') && str_contains($doc3, 'Sans vente sur la période : Sans catégorie'));
verifie('l’assortiment proposé est celui de la Structure de gamme, ses règles dites', str_contains($doc3, 'Comptoir › Structure de gamme')
    && str_contains($doc3, 'font 80 % du chiffre de la catégorie, 6 au plus') && str_contains($doc3, '4 au plus') && str_contains($doc3, 'Moins de 3 % du chiffre'));
verifie('le total en clair : 4 toute l’année, 3 de saison, 2 à arbitrer, 2 à retirer dont 1 obligatoire',
    str_contains($doc3, '<b>11 produits</b> aujourd’hui') && str_contains($doc3, '<b>4 toute l’année</b>') && str_contains($doc3, '<b>3 de saison</b>')
    && str_contains($doc3, '<b>2 à arbitrer</b>') && str_contains($doc3, '<b>2 à retirer</b>') && str_contains($doc3, '1 obligatoire aujourd’hui'));
verifie('une fiche par catégorie, en quatre colonnes : toute l’année, saisons et périodes, à arbitrer, à retirer',
    str_contains($doc3, '1</span><b>Toute l’année') && str_contains($doc3, '2</span><b>Saisons et périodes') && str_contains($doc3, '3</span><b>À arbitrer') && str_contains($doc3, '4</span><b>À retirer')
    && str_contains($doc3, '— aujourd’hui 11 produits → 4 toute l’année + 3 de saison, 2 à arbitrer, 2 à retirer'));
verifie('la gardée à la main, la nouveauté, l’obligatoire à retirer et les périodes dites, sans emoji',
    str_contains($doc3, 'Pain aux raisins<div style="font-size:8.8pt;margin-top:0.2mm"><span class="mu">gardée à la main')
    && str_contains($doc3, 'Bûche nouvelle<div style="font-size:8.8pt;margin-top:0.2mm"><span class="mu">nouveauté')
    && str_contains($doc3, 'Chausson<div style="font-size:8.8pt;margin-top:0.2mm"><b class="ko">obligatoire aujourd’hui')
    && str_contains($doc3, '<b>Noël</b> <span class="mu">15 nov. – 26 déc.</span>') && !str_contains($doc3, '🎄'));
$doc4 = ppDoc(['periode' => 'x', 'caTotal' => 100, 'totalRefs' => 1, 'magasins' => [], 'lignes' => [$lignes[0]], 'refs' => $gamme]);
verifie('sans la structure de gamme : dit, rien d’inventé', str_contains($doc4, 'La structure de gamme n’a pas pu être lue') && !str_contains($doc4, 'Toute l’année'));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
