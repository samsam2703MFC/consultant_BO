<?php
declare(strict_types=1);

/*
 * SONDE — les profils de fidélité des clients identifiés de Halle (12/11/2026, demande de Sam : « une page orientée
 * fidélité — niveau de fidélité — spider sur les profils client (récurrence, type d'achat) — maquette, puis on
 * décide »).
 *
 * Aucun client n'a encore la fidélité active : les profils se calculent sur les clients PARTICULIERS rattachés à un
 * ticket de Halle (`id_client`) sur les 90 derniers jours. Pour chacun : les jours de passage, les tickets, le montant,
 * la part en commande, l'heure, et les catégories achetées (lignes du ticket, `/transactions/{id}?include=products`).
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-profils-halle.yml` :
 *     php -- /var/www/consulant_bo < tools/sonde_profils_halle.php
 *
 * LECTURE SEULE : des GET au panel, aucune table locale. Rien de nominatif : un client est un rang (C1, C2…), jamais
 * son identifiant ni son nom ; les lignes de ticket ne donnent que leur catégorie.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require_once "$dir/src/$f";
}

const SP_SHOP = 4;
const SP_JOURS = 90;

$hier = date('Y-m-d', strtotime('-1 day'));
$du = date('Y-m-d', strtotime($hier . ' -' . (SP_JOURS - 1) . ' days'));
$j0 = intdiv((int) strtotime($du . ' 12:00:00'), 86400);
$jour = static fn (string $d): int => intdiv((int) strtotime(substr($d, 0, 10) . ' 12:00:00'), 86400) - $j0;

echo "=== période : $du → $hier (" . SP_JOURS . " jours), Halle ===\n";
if (!PanelApi::configured()) { echo "aucun identifiant\n"; exit(1); }

// 1. Les listes de tickets du jour.
$chemins = [];
for ($d = $du; $d <= $hier; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $chemins[$d] = '/shops/' . SP_SHOP . '/transactions?date=' . $d; }
$t0 = microtime(true);
$res = PanelApi::getParalleleDetail($chemins, 4, 40);
$totTickets = 0; $totPart = 0; $illisibles = 0;
$tickets = [];   // tickets particuliers identifiés
foreach ($chemins as $d => $ch) {
    $r = $res[$d] ?? null;
    if (!is_array($r['data'] ?? null)) { $illisibles++; continue; }
    foreach (analyseListe($r['data']) as $t) {
        if (!is_array($t)) { continue; }
        $totTickets++;
        if (!empty($t['is_client_b2b'])) { continue; }
        $totPart++;
        $cli = (int) ($t['id_client'] ?? 0);
        if ($cli <= 0) { continue; }
        $tickets[] = ['id' => (int) $t['id'], 'cli' => $cli, 'ts' => (string) ($t['insert_timestamp'] ?? ''),
            'eur' => (float) ($t['total_gross_amount_after_discount'] ?? 0),
            'cmd' => strtolower((string) ($t['source'] ?? '')) === 'webshop' || (int) ($t['order_ref'] ?? 0) > 0];
    }
}
printf("listes : %d lues, %d illisibles, %.1f s · tickets %d · particuliers %d · identifiés %d\n", count($chemins) - $illisibles, $illisibles, microtime(true) - $t0, $totTickets, $totPart, count($tickets));

// 1b. Le catalogue par l'API : produit → catégorie → groupe.
$cle = static function (array $x, array $noms) { foreach ($noms as $k) { if (isset($x[$k]) && is_scalar($x[$k]) && (string) $x[$k] !== '') { return $x[$k]; } } return null; };
$rp = PanelApi::sondeGet('/products', 60);
$prodL = is_array($rp['corps']) ? PanelApi::liste($rp['corps']) : [];
echo "\n/products : HTTP ", $rp['code'], ' · ', count($prodL), " produits\n";
if ($prodL) { echo '  champs : ', implode(', ', array_keys($prodL[0])), "\n"; }
$rc = PanelApi::sondeGet('/product-categories', 30);
$catL = is_array($rc['corps']) ? PanelApi::liste($rc['corps']) : [];
echo '/product-categories : HTTP ', $rc['code'], ' · ', count($catL), " catégories\n";
if ($catL) { echo '  champs : ', implode(', ', array_keys($catL[0])), "\n"; }
$rg = PanelApi::sondeGet('/product-category-groups', 30);
$grpL = is_array($rg['corps']) ? PanelApi::liste($rg['corps']) : [];
echo '/product-category-groups : HTTP ', $rg['code'], ' · ', count($grpL), " groupes\n";
if ($grpL) { echo '  champs : ', implode(', ', array_keys($grpL[0])), ' · exemple ', json_encode(array_map(static fn ($v) => is_array($v) ? (array_is_list($v) ? 'liste(' . count($v) . ')' : 'objet') : $v, $grpL[0]), JSON_UNESCAPED_UNICODE), "\n"; }
$nomCat = []; $grpCat = [];
foreach ($catL as $c) {
    $id = (int) ($cle($c, ['id', 'id_category']) ?? 0);
    $nomCat[$id] = (string) ($cle($c, ['name', 'category_name']) ?? ('cat#' . $id));
    $g = $cle($c, ['group_name', 'category_group_name']);
    if ($g !== null) { $grpCat[$id] = (string) $g; }
}
foreach ($grpL as $g) {
    $gn = (string) ($cle($g, ['name', 'group_name']) ?? '?');
    foreach (['categories', 'category_ids', 'id_categories'] as $k) {
        foreach ((array) ($g[$k] ?? []) as $c) { $id = (int) (is_array($c) ? ($c['id'] ?? $c['id_category'] ?? 0) : $c); if ($id > 0) { $grpCat[$id] ??= $gn; } }
    }
}
$famille = [];   // id_product → « groupe › catégorie »
foreach ($prodL as $pr) {
    $id = (int) ($cle($pr, ['id', 'id_product']) ?? 0);
    $ic = (int) ($cle($pr, ['id_category', 'category_id']) ?? 0);
    $cn = (string) ($cle($pr, ['category_name']) ?? ($nomCat[$ic] ?? ''));
    if (is_array($pr['category'] ?? null)) { $ic = $ic ?: (int) ($pr['category']['id'] ?? 0); $cn = $cn ?: (string) ($pr['category']['name'] ?? ''); }
    if ($id > 0) { $famille[$id] = ($grpCat[$ic] ?? '—') . ' › ' . ($cn !== '' ? $cn : ('cat#' . $ic)); }
}
echo 'produits rattachés à une famille : ', count($famille), "\n";

// 2. Les lignes des tickets identifiés : la catégorie de chaque article.
$ch2 = [];
foreach ($tickets as $i => $t) { $ch2[$i] = '/transactions/' . $t['id'] . '?include=products'; }
$t0 = microtime(true);
$res2 = $ch2 ? PanelApi::getParalleleDetail($ch2, 4, 30) : [];
$clesProduit = null; $cats = []; $sansDetail = 0;
foreach ($tickets as $i => &$t) {
    $d = $res2[$i]['data'] ?? null;
    $d = is_array($d) && isset($d['data']) && is_array($d['data']) && !array_is_list($d['data']) ? $d['data'] : $d;
    $prods = is_array($d['products'] ?? null) ? $d['products'] : [];
    if ($prods === []) { $sansDetail++; }
    $t['cats'] = [];
    foreach ($prods as $p) {
        if (!is_array($p)) { continue; }
        $clesProduit ??= array_keys($p);
        $c = $famille[(int) ($p['id_product'] ?? 0)] ?? ('? › produit ' . (int) ($p['id_product'] ?? 0) . ' ' . mb_substr((string) ($p['product_name'] ?? ''), 0, 24));
        $q = (float) ($p['quantity'] ?? 1);
        $t['cats'][$c] = ($t['cats'][$c] ?? 0) + max(1, $q);
        $cats[$c] = ($cats[$c] ?? 0) + max(1, $q);
    }
}
unset($t);
printf("détails : %d lus en %.1f s · %d sans lignes\n", count($ch2), microtime(true) - $t0, $sansDetail);
echo 'champs d\'une ligne : ', $clesProduit ? implode(', ', $clesProduit) : '—', "\n";
arsort($cats);
$code = []; $n = 0;
foreach ($cats as $c => $q) { $code[$c] = 'k' . (++$n); }
echo "catégories (code · articles) :\n";
foreach ($cats as $c => $q) { printf("  %-4s %-60s %6.0f\n", $code[$c], mb_substr($c, 0, 60), $q); }

// 3. Un vecteur par client, rangé par nombre de jours de passage.
$C = [];
foreach ($tickets as $t) {
    $c = &$C[$t['cli']];
    $c ??= ['jours' => [], 'tk' => 0, 'eur' => 0.0, 'cmd' => 0, 'h' => [0, 0, 0], 'cats' => []];
    $j = $jour($t['ts']);
    $c['jours'][$j] = true;
    $c['tk']++; $c['eur'] += $t['eur']; $c['cmd'] += $t['cmd'] ? 1 : 0;
    $h = (int) substr($t['ts'], 11, 2);
    $c['h'][$h < 11 ? 0 : ($h < 14 ? 1 : 2)]++;
    foreach ($t['cats'] as $k => $q) { $c['cats'][$code[$k]] = ($c['cats'][$code[$k]] ?? 0) + $q; }
    unset($c);
}
uasort($C, static fn ($a, $b) => [count($b['jours']), $b['eur']] <=> [count($a['jours']), $a['eur']]);
echo "\nclients identifiés : " . count($C) . "\n";
$rep = [];
foreach ($C as $c) { $v = count($c['jours']); $k = $v >= 6 ? '6+' : (string) $v; $rep[$k] = ($rep[$k] ?? 0) + 1; }
ksort($rep);
echo 'jours de passage par client : ', json_encode($rep), "\n";

// 4. Les profils pour la maquette : niveau de fidélité, et les deux radars (récurrence, type d'achat).
// Le type d'achat se lit sur le libellé « groupe › catégorie » (ou « produit … » quand le catalogue ne le range pas).
$familleDe = static function (string $lib): string {
    if (preg_match('/réduction/iu', $lib)) { return 'antigaspi'; }
    if (preg_match('/quiche|soupe|boisson|sandwich|traiteur/iu', $lib)) { return 'sale'; }
    if (preg_match('/viennoiserie/iu', $lib)) { return 'vienno'; }
    if (preg_match('/pâtisserie individuelle|cookie|cake|individuel|brownie|brook/iu', $lib)) { return 'gouter'; }
    if (preg_match('/pain|boulangerie|pistolet/iu', $lib)) { return 'pain'; }
    return 'tartes';
};
$FAM = ['pain', 'vienno', 'antigaspi', 'tartes', 'gouter', 'sale'];
$codeFam = [];
foreach ($code as $lib => $k) { $codeFam[$k] = $familleDe($lib); }
echo "\nfamilles : ", json_encode(array_count_values($codeFam)), "\n";
$niveau = static fn (int $v): int => $v >= 6 ? 4 : ($v >= 3 ? 3 : ($v >= 2 ? 2 : 1));
$P = []; $parNiveau = [];
$famClients = array_fill_keys($FAM, 0);
foreach ($C as $c) {
    $js = array_keys($c['jours']); sort($js);
    $v = count($js);
    $f = array_fill_keys($FAM, 0.0);
    foreach ($c['cats'] as $k => $q) { $f[$codeFam[$k]] += $q; }
    $tot = array_sum($f) ?: 1;
    foreach ($FAM as $k) { if ($f[$k] > 0) { $famClients[$k]++; } }
    $part = array_map(static fn ($x) => round(100 * $x / $tot), $f);
    $recence = SP_JOURS - 1 - end($js);
    $comp = [
        'frequence' => min(100, round(100 * ($v / 3) / 4)),            // 4 passages par mois = 100
        'recence' => max(0, round(100 * (1 - $recence / SP_JOURS))),
        'anciennete' => round(100 * (end($js) - $js[0]) / (SP_JOURS - 1)),
        'panier' => min(100, round(100 * ($c['eur'] / $c['tk']) / 60)),  // 60 € = 100
        'diversite' => round(100 * count(array_filter($f)) / count($FAM)),
        'comptoir' => round(100 * ($c['tk'] - $c['cmd']) / $c['tk']),
    ];
    $n = $niveau($v);
    $P[] = ['niv' => $n, 'jours' => $js, 'tk' => $c['tk'], 'eur' => round($c['eur'], 2), 'cmd' => $c['cmd'], 'h' => $c['h'], 'recence' => $recence, 'comp' => $comp, 'fam' => $part];
    $x = &$parNiveau[$n];
    $x ??= ['n' => 0, 'tk' => 0, 'eur' => 0.0, 'cmd' => 0, 'h' => [0, 0, 0], 'comp' => array_fill_keys(array_keys($comp), 0), 'fam' => array_fill_keys($FAM, 0), 'rec' => []];
    $x['n']++; $x['tk'] += $c['tk']; $x['eur'] += $c['eur']; $x['cmd'] += $c['cmd'];
    foreach ([0, 1, 2] as $i) { $x['h'][$i] += $c['h'][$i]; }
    foreach ($comp as $k => $val) { $x['comp'][$k] += $val; }
    foreach ($FAM as $k) { $x['fam'][$k] += $part[$k]; }
    $x['rec'][] = $recence;
    unset($x);
}
krsort($parNiveau);
echo "\nNIVEAUX (moyennes par client) :\n";
foreach ($parNiveau as $n => $x) {
    sort($x['rec']);
    echo json_encode(['niv' => $n, 'clients' => $x['n'], 'tickets' => $x['tk'], 'eur' => round($x['eur'], 2), 'panier' => round($x['eur'] / max(1, $x['tk']), 2),
        'cmd' => $x['cmd'], 'h' => $x['h'], 'comp' => array_map(static fn ($v) => round($v / $x['n']), $x['comp']),
        'fam' => array_map(static fn ($v) => round($v / $x['n']), $x['fam']), 'recence_mediane' => $x['rec'][intdiv(count($x['rec']), 2)]], JSON_UNESCAPED_UNICODE), "\n";
}
echo "\nclients par famille achetée : ", json_encode($famClients), "\n";
echo "\nLES 10 PREMIERS CLIENTS :\n";
foreach (array_slice($P, 0, 10) as $i => $x) { echo 'C', $i + 1, ' ', json_encode($x), "\n"; }
echo "\nfin de la sonde\n";
