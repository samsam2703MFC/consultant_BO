<?php
/**
 * LES SIX DERNIÈRES SEMAINES D'UN MAGASIN, FACE AU N-1.
 *
 * Une question de contrôle, pas de pilotage : le nombre de clients tient-il,
 * semaine après semaine, et que faisait le magasin la même semaine un an plus
 * tôt ? Une semaine se compare à la semaine de même rang de l'an passé,
 * décalée de 364 jours pour que le lundi tombe sur un lundi ; la semaine en
 * cours, elle, ne se compare qu'aux jours qu'elle a déjà servis.
 *
 * Deux sources, dans cet ordre : le panel (margin-heatmap, mesuré par jour)
 * quand il a la semaine, la copie de caisse (`transaction`) sinon — c'est
 * elle qui porte 2025. Un magasin trop jeune pour avoir un N-1 le dit, il ne
 * montre pas un zéro.
 */

/** GET /ventes/semaines?shop=4&date=YYYY-MM-DD&n=6 */
function ep_ventes_semaines(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $n = max(2, min(12, (int) ($_GET['n'] ?? 6)));
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }

    $lundi = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $du = date('Y-m-d', strtotime($lundi . ' -' . (7 * ($n - 1)) . ' days'));
    $au = date('Y-m-d', strtotime($lundi . ' +6 days'));
    $jusqua = min($au, $auj);
    $du1 = date('Y-m-d', strtotime($du . ' -364 days'));
    $au1 = date('Y-m-d', strtotime($jusqua . ' -364 days'));

    $panel = vsPanelJours($sid, [[$du, $jusqua], [$du1, $au1]]);
    $caisse = vsCaisseJours($sid, [[$du, $jusqua], [$du1, $au1]]);

    $semaines = []; $tot = ['ca' => 0.0, 'tickets' => 0, 'jours' => 0]; $tot1 = ['ca' => 0.0, 'tickets' => 0, 'jours' => 0];
    $n1Vu = false; $srcs = [];
    for ($i = 0; $i < $n; $i++) {
        $sDu = date('Y-m-d', strtotime($du . ' +' . (7 * $i) . ' days'));
        $sAu = date('Y-m-d', strtotime($sDu . ' +6 days'));
        $enCours = $sAu >= $auj;
        $lu = min($sAu, $auj);
        $s = vsSomme($panel, $caisse, $sDu, $lu);
        // Le N-1 s'arrête au même jour de la semaine que ce qui est servi ici.
        $s1 = vsSomme($panel, $caisse, date('Y-m-d', strtotime($sDu . ' -364 days')), date('Y-m-d', strtotime($lu . ' -364 days')));
        $iso = (int) date('W', strtotime($sDu));
        $ligne = ['iso' => $iso, 'du' => $sDu, 'au' => $sAu, 'enCours' => $enCours,
            'joursServis' => $enCours ? (int) ((strtotime($lu) - strtotime($sDu)) / 86400) + 1 : 7,
            'ca' => $s['ca'], 'tickets' => $s['tickets'], 'jours' => $s['jours'], 'source' => $s['source'],
            'panier' => $s['tickets'] > 0 ? round($s['ca'] / $s['tickets'], 2) : null,
            'n1' => $s1['source'] === null ? null : ['du' => date('Y-m-d', strtotime($sDu . ' -364 days')),
                'ca' => $s1['ca'], 'tickets' => $s1['tickets'], 'jours' => $s1['jours'], 'source' => $s1['source']]];
        if ($s['source'] !== null) { $tot['ca'] += $s['ca']; $tot['tickets'] += $s['tickets']; $tot['jours'] += $s['jours']; $srcs[$s['source']] = true; }
        if ($ligne['n1'] !== null) { $n1Vu = true; $tot1['ca'] += $s1['ca']; $tot1['tickets'] += $s1['tickets']; $tot1['jours'] += $s1['jours']; $srcs[$s1['source']] = true; }
        $semaines[] = $ligne;
    }
    $tot['ca'] = round($tot['ca'], 2); $tot1['ca'] = round($tot1['ca'], 2);
    $n1Motif = null;
    if (!$n1Vu) {
        $ouv = vsPremiereVente($sid);
        $n1Motif = $ouv !== null && $ouv > $du1
            ? 'pas de N-1 : la caisse a sonné pour la première fois le ' . date('d/m/Y', strtotime($ouv))
            : 'pas de N-1 : aucune vente lue un an plus tôt, ni au panel ni dans la copie de caisse';
    }
    return ['shop' => (string) $sid, 'n' => $n, 'du' => $du, 'au' => $au, 'jusqua' => $jusqua, 'aujourdhui' => $auj,
        'semaines' => $semaines, 'total' => $tot, 'totalN1' => $n1Vu ? $tot1 : null, 'n1Motif' => $n1Motif,
        'source' => implode(' + ', array_map(static fn ($k) => $k === 'panel' ? 'panel (margin-heatmap, par jour)' : 'copie de caisse (tickets)', array_keys($srcs)))
            . ' · N-1 = même semaine décalée de 364 jours, arrêtée au même jour servi'];
}

/** Les jours du panel sur des fenêtres, découpées par mois : [date => [ca, tickets, ouvert]]. */
function vsPanelJours(int $sid, array $fenetres): array
{
    if (!PanelApi::configured()) { return []; }
    $paths = []; $k = 0;
    foreach ($fenetres as [$d1, $d2]) {
        for ($cur = $d1; $cur <= $d2;) {
            $fin = date('Y-m-d', min(strtotime($cur . ' +30 days'), strtotime($d2)));
            $paths['w' . ($k++)] = '/consultant/shops/' . $sid . '/margin-heatmap?' . http_build_query(['from' => $cur, 'to' => $fin]);
            $cur = date('Y-m-d', strtotime($fin . ' +1 day'));
        }
    }
    $out = [];
    try { $res = PanelApi::getParallele($paths, 6); } catch (Throwable $e) { return []; }
    foreach ($res as $hm) {
        if (!is_array($hm) || !isset($hm['days'])) { continue; }
        foreach ((array) $hm['days'] as $d) {
            $j = (string) ($d['date'] ?? '');
            $ca = (float) ($d['ca'] ?? 0);
            if ($j === '' || !(!empty($d['has_data']) && $ca > 0)) { continue; }
            $out[$j] = ['ca' => $ca, 'tickets' => (int) ($d['tickets'] ?? 0)];
        }
    }
    return $out;
}

/** Les jours de la copie de caisse sur des fenêtres : [date => [ca, tickets]]. */
function vsCaisseJours(int $sid, array $fenetres): array
{
    $out = [];
    foreach ($fenetres as [$d1, $d2]) {
        try {
            foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(9000) */ DATE(insert_timestamp) j,
                                      SUM(total_gross_amount_after_discount) ca, COUNT(DISTINCT ticket_key) t
                                 FROM `transaction`
                                WHERE id_shop = ? AND insert_timestamp >= ? AND insert_timestamp < ?
                                GROUP BY j", [$sid, $d1 . ' 00:00:00', date('Y-m-d', strtotime($d2 . ' +1 day')) . ' 00:00:00']) as $r) {
                $t = (int) $r['t'];
                if ($t > 0) { $out[(string) $r['j']] = ['ca' => (float) $r['ca'], 'tickets' => $t]; }
            }
        } catch (Throwable $e) { /* pas de caisse sur cette base */ }
    }
    return $out;
}

/** La somme d'une fenêtre : le panel s'il a au moins un jour, la caisse sinon. */
function vsSomme(array $panel, array $caisse, string $d1, string $d2): array
{
    foreach ([['panel', $panel], ['caisse', $caisse]] as [$nom, $jours]) {
        $s = ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'source' => null];
        for ($j = $d1; $j <= $d2; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            if (!isset($jours[$j])) { continue; }
            $s['ca'] += $jours[$j]['ca']; $s['tickets'] += $jours[$j]['tickets']; $s['jours']++;
        }
        if ($s['jours'] > 0) { $s['ca'] = round($s['ca'], 2); $s['source'] = $nom; return $s; }
    }
    return ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'source' => null];
}

/**
 * Le premier jour vendu, pour dire pourquoi un N-1 manque. La table porte des
 * dates parasites (une ligne en 1900 chez Corbais) : on ignore tout ce qui
 * précède 2015.
 */
function vsPremiereVente(int $sid): ?string
{
    try {
        $r = Db::row("SELECT MIN(DATE(insert_timestamp)) d FROM `transaction` WHERE id_shop = ? AND insert_timestamp >= '2015-01-01'", [$sid]);
        $d = $r === null ? null : (string) ($r['d'] ?? '');
        return $d !== null && $d !== '' ? substr($d, 0, 10) : null;
    } catch (Throwable $e) { return null; }
}
