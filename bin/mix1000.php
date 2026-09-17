<?php
declare(strict_types=1);
/**
 * Cockpit CEO — le mix par 1 000 € vendus, la base de calcul du réseau.
 *
 *   php bin/mix1000.php                      dernier mois clos
 *   php bin/mix1000.php --fenetre=trimestre  trois derniers mois clos
 *   php bin/mix1000.php --fenetre=annee      douze derniers mois clos
 *   php bin/mix1000.php --top=40             n'en détailler que 40 (défaut : toutes)
 *   php bin/mix1000.php --ca=10000           ajoute la colonne « pour ce CA-là »
 *   php bin/mix1000.php --csv                sortie CSV (une ligne par référence puis par catégorie)
 *
 * Pour 1 000 € encaissés, combien d'unités de chaque référence — et, en
 * récapitulatif, combien d'euros par catégorie. C'est le mix du RÉSEAU : les
 * ventes des magasins actifs additionnées, puis ramenées à 1 000 €. L'en-tête
 * nomme les magasins comptés, pour qu'on voie sur quoi la base repose. Le rapport est SANS ÉCHELLE : il vaut pour un magasin
 * à 8 000 €/semaine comme pour un à 25 000 €, et c'est ce qui en fait une base
 * de calcul — dimensionner une production, une commande, le prévisionnel d'une
 * ouverture se ramène à multiplier par le CA visé ÷ 1 000.
 *
 * Source : ep_products(), celle de l'écran Scoring des références — le panel
 * d'abord, la caisse locale en repli, le catalogue mensuel en dernier. Deux
 * écrans ne doivent pas calculer la même chose de deux façons ; le CA d'une
 * référence est donc volume × prix moyen, comme le fait l'écran.
 *
 * Le dénominateur est le CA des références comptées, pas le CA du P&L : les
 * euros par catégorie somment alors exactement à 1 000 €. Un magasin dont le
 * P&L dépasse ce total (B2B, prestations hors caisse) applique la base à sa
 * seule part caisse.
 *
 * À exécuter depuis le déploiement servi : utilise config/config.php, comme
 * l'application.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/endpoints.php';
require __DIR__ . '/../src/analyse_produits.php';
require __DIR__ . '/../src/panel_api.php';

@ini_set('memory_limit', '512M');
set_time_limit(0);

$args = array_slice($argv, 1);
$opt = static function (string $nom, ?string $defaut = null) use ($args): ?string {
    foreach ($args as $a) { if (str_starts_with($a, "--$nom=")) { return substr($a, strlen($nom) + 3); } }
    return $defaut;
};
$fenetre = (string) $opt('fenetre', 'mois');
if (!in_array($fenetre, ['mois', 'trimestre', 'annee'], true)) {
    fwrite(STDERR, "fenêtre inconnue : $fenetre (attendu mois, trimestre ou annee)\n");
    exit(1);
}
$top = (int) $opt('top', '0');           // 0 = toutes les références
$caCible = (float) $opt('ca', '0');
$csv = in_array('--csv', $args, true);

/**
 * Le mix, à partir des lignes de /products/scoring.
 *
 * Fonction pure : elle ne connaît ni la base ni la fenêtre, seulement des
 * références {nom, categorie, volume, prix}. Une référence sans vente ou sans
 * prix ne pèse rien et sort du lot — elle ne doit ni gonfler le dénominateur
 * ni occuper une ligne.
 *
 * @return array{ca: float, unites: float, refs: list<array>, cats: list<array>}
 */
function mix1000(array $refs): array
{
    $lot = [];
    foreach ($refs as $r) {
        $vol = (float) ($r['volume'] ?? 0);
        $prix = (float) ($r['prix'] ?? 0);
        if ($vol <= 0 || $prix <= 0) { continue; }
        $lot[] = ['nom' => (string) ($r['nom'] ?? '?'), 'cat' => (string) ($r['categorie'] ?? 'Non catégorisé'),
            'vol' => $vol, 'prix' => $prix, 'ca' => $vol * $prix, 'magasins' => (int) ($r['magasins'] ?? 0)];
    }
    $ca = 0.0; $unites = 0.0;
    foreach ($lot as $l) { $ca += $l['ca']; $unites += $l['vol']; }
    if ($ca <= 0) { return ['ca' => 0.0, 'unites' => 0.0, 'refs' => [], 'cats' => []]; }

    $k = 1000 / $ca;                       // le facteur d'échelle : tout en découle
    $refs2 = []; $parCat = [];
    foreach ($lot as $l) {
        $ligne = ['nom' => $l['nom'], 'cat' => $l['cat'], 'prix' => $l['prix'], 'magasins' => $l['magasins'],
            'u1000' => $l['vol'] * $k, 'e1000' => $l['ca'] * $k, 'part' => $l['ca'] / $ca];
        $refs2[] = $ligne;
        $c = $l['cat'];
        if (!isset($parCat[$c])) { $parCat[$c] = ['nom' => $c, 'refs' => 0, 'u1000' => 0.0, 'e1000' => 0.0, 'part' => 0.0]; }
        $parCat[$c]['refs']++;
        $parCat[$c]['u1000'] += $ligne['u1000'];
        $parCat[$c]['e1000'] += $ligne['e1000'];
        $parCat[$c]['part']  += $ligne['part'];
    }
    usort($refs2, static fn ($a, $b) => $b['e1000'] <=> $a['e1000']);
    $cats = array_values($parCat);
    usort($cats, static fn ($a, $b) => $b['e1000'] <=> $a['e1000']);
    return ['ca' => $ca, 'unites' => $unites, 'refs' => $refs2, 'cats' => $cats];
}

$_GET['fenetre'] = $fenetre;
try {
    $refs = ep_products();
} catch (Throwable $e) {
    fwrite(STDERR, 'ventes illisibles : ' . $e->getMessage() . "\n");
    exit(1);
}
$m = mix1000($refs);
if ($m['ca'] <= 0) {
    fwrite(STDERR, "aucune vente sur la fenêtre demandée — rien à calculer\n");
    exit(1);
}

// La fenêtre réellement servie, telle que ep_products vient de la graver. Son
// dernier repli — ceo_product_month_sales — ne la grave PAS : le réglage
// porterait alors la fenêtre d'un appel précédent. On ne s'en sert donc que
// s'il parle bien de la fenêtre demandée, sinon on dit qu'on ne sait pas
// plutôt que d'étiqueter la base d'une source qui n'est pas la sienne.
$f = function_exists('setting') ? setting('periodeProduitsFenetre') : null;
if (!is_array($f) || ($f['fenetre'] ?? '') !== $fenetre) { $f = null; }
$lib = is_array($f) ? (string) ($f['libelle'] ?? $fenetre) : $fenetre;
$src = is_array($f) && ($f['source'] ?? '') !== '' ? (string) $f['source'] : 'non déclarée';
// Les magasins comptés. Le panel sert la ventilation par magasin : on en tire
// les noms, et le mix se lit sur des magasins nommés plutôt que sur un nombre.
// Les autres sources ne la servent pas ; on retombe alors sur le seul nombre
// porté par les références, en le disant.
$mag = 0;
foreach ($m['refs'] as $r) { $mag = max($mag, $r['magasins']); }
$noms = [];
foreach ($refs as $r) {
    foreach ((array) ($r['parMagasin'] ?? []) as $pm) {
        if ((float) ($pm['vol'] ?? 0) > 0) { $noms[(string) ($pm['nom'] ?? '')] = true; }
    }
}
unset($noms['']);
$noms = array_keys($noms);
sort($noms);
$quiMag = $noms !== []
    ? 'mix de ' . count($noms) . ' magasins : ' . implode(', ', $noms)
    : 'mix de ' . $mag . ' magasins (cette source ne dit pas lesquels)';

$n2 = static fn (float $v, int $d = 2): string => number_format($v, $d, ',', ' ');
// printf compte les OCTETS : « Pâtisserie » décalait toute sa ligne. On cale
// donc les colonnes sur le nombre de caractères.
$pad = static function (string $s, int $n, bool $droite = false): string {
    if (mb_strlen($s) > $n) { $s = mb_substr($s, 0, $n - 1) . '…'; }
    $e = str_repeat(' ', max(0, $n - mb_strlen($s)));
    return $droite ? $e . $s : $s . $e;
};

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['niveau', 'categorie', 'reference', 'unites_par_1000_eur', 'euros_par_1000_eur',
        'part_pct', 'prix_moyen_eur', 'magasins'], ';');
    foreach ($m['refs'] as $r) {
        fputcsv($out, ['reference', $r['cat'], $r['nom'], round($r['u1000'], 3), round($r['e1000'], 2),
            round($r['part'] * 100, 3), round($r['prix'], 2), $r['magasins']], ';');
    }
    foreach ($m['cats'] as $c) {
        fputcsv($out, ['categorie', $c['nom'], '', round($c['u1000'], 2), round($c['e1000'], 2),
            round($c['part'] * 100, 2), '', ''], ';');
    }
    fclose($out);
    exit(0);
}

$cible = static fn (float $u): string => $caCible > 0 ? '  ' . str_pad(number_format($u * $caCible / 1000, 0, ',', ' '), 16, ' ', STR_PAD_LEFT) : '';

echo "Mix par 1 000 € vendus — $lib · source : $src\n";
echo ucfirst($quiMag) . "\n";
echo 'CA de référence : ' . $n2($m['ca'], 0) . ' € · ' . count($m['refs']) . ' références vendues · '
    . $n2($m['unites'], 0) . " unités\n";
if ($caCible > 0) { echo 'Dernière colonne : les mêmes unités pour ' . $n2($caCible, 0) . " € de CA\n"; }

// Les références d'abord : c'est la base qu'on vient chercher. Les catégories
// ne sont qu'un récapitulatif, elles ferment le tableau.
$liste = $top > 0 ? array_slice($m['refs'], 0, $top) : $m['refs'];
echo "\nPar référence — pour 1 000 € encaissés" . ($top > 0 && count($m['refs']) > $top
    ? ' (les ' . $top . ' premières sur ' . count($m['refs']) . ')' : '') . "\n";
echo '  ' . $pad('Référence', 34) . $pad('Catégorie', 20) . $pad('unités', 11, true)
    . $pad('€ / 1 000 €', 14, true) . $pad('prix', 10, true)
    . ($caCible > 0 ? '  ' . $pad('CA visé', 16, true) : '') . "\n";
foreach ($liste as $r) {
    echo '  ' . $pad($r['nom'], 34) . $pad($r['cat'], 20) . $pad($n2($r['u1000'], 1), 11, true)
        . $pad($n2($r['e1000']) . ' €', 14, true) . $pad($n2($r['prix']) . ' €', 10, true)
        . $cible($r['u1000']) . "\n";
}

echo "\nRécapitulatif par catégorie — pour 1 000 € encaissés\n";
echo '  ' . $pad('Catégorie', 30) . $pad('€ / 1 000 €', 14, true) . $pad('unités', 12, true)
    . $pad('part', 9, true) . ($caCible > 0 ? '  ' . $pad('CA visé', 16, true) : '') . "\n";
foreach ($m['cats'] as $c) {
    echo '  ' . $pad($c['nom'], 30) . $pad($n2($c['e1000']) . ' €', 14, true) . $pad($n2($c['u1000'], 1), 12, true)
        . $pad($n2($c['part'] * 100, 1) . ' %', 9, true) . $cible($c['u1000']) . "\n";
}
echo '  ' . $pad('Total', 30) . $pad($n2(array_sum(array_column($m['cats'], 'e1000'))) . ' €', 14, true)
    . $pad($n2($m['unites'] * 1000 / $m['ca'], 1), 12, true)
    . $pad($n2(array_sum(array_column($m['cats'], 'part')) * 100, 1) . ' %', 9, true)
    . $cible($m['unites'] * 1000 / $m['ca']) . "\n";
