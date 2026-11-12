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
        $c = '';
        foreach (['category_name', 'category', 'product_category_name', 'group_name', 'category_group_name'] as $k) {
            if (isset($p[$k]) && is_scalar($p[$k]) && trim((string) $p[$k]) !== '') { $c = trim((string) $p[$k]); break; }
        }
        if ($c === '') { $c = 'cat#' . (string) ($p['id_category'] ?? '?'); }
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
foreach ($cats as $c => $q) { printf("  %-4s %-40s %6.0f\n", $code[$c], mb_substr($c, 0, 40), $q); }

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
echo "\nrang | jours de passage (0 = $du) | tickets | € | en commande | matin/midi/après-midi | catégories\n";
$i = 0;
foreach ($C as $c) {
    $i++;
    $js = array_keys($c['jours']); sort($js);
    arsort($c['cats']);
    printf("C%d|%s|%d|%.2f|%d|%s|%s\n", $i, implode(',', $js), $c['tk'], $c['eur'], $c['cmd'], implode('/', $c['h']), json_encode(array_map(static fn ($q) => round($q, 1), $c['cats'])));
}
echo "\nfin de la sonde\n";
