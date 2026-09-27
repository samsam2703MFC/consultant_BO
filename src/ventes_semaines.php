<?php
/**
 * LES DERNIÈRES PÉRIODES D'UN MAGASIN — OU DU RÉSEAU — FACE AU N-1.
 *
 * Une question de contrôle, pas de pilotage : le nombre de clients tient-il,
 * période après période, et que faisait le magasin un an plus tôt ? Trois
 * étendues :
 *
 *   - la SEMAINE : six semaines, chacune face à la semaine décalée de 364
 *     jours pour qu'un lundi tombe sur un lundi ;
 *   - le MOIS : six mois, chacun face au même mois civil un an plus tôt ;
 *   - l'ANNÉE : les douze mois de l'année, face aux mêmes mois de l'an passé.
 *
 * La période en cours ne se compare qu'aux jours qu'elle a déjà servis : la
 * fenêtre N-1 s'arrête au même rang de jour.
 *
 * Deux sources, dans cet ordre : le panel (margin-heatmap, mesuré par jour)
 * quand il a la période, la copie de caisse (`transaction`) sinon. Les mois
 * clos du panel se gravent (ceo_app_setting) : un an de réseau ne se relit
 * pas au panel à chaque ouverture. Un magasin trop jeune pour avoir un N-1 le
 * dit, il ne montre pas un zéro.
 */

/** GET /ventes/semaines?shop=4&vue=semaine|mois|annee&date=YYYY-MM-DD&n=6 */
function ep_ventes_semaines(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    [$vue, $date, $n] = vsParams();
    $periodes = vsPeriodes($vue, $date, $n);
    $lu = vsLireMagasin($sid, $periodes);
    $m = vsMagasin($sid, $periodes, $lu);
    return ['shop' => (string) $sid, 'vue' => $vue, 'n' => count($periodes), 'du' => $periodes[0]['du'], 'au' => end($periodes)['au'],
        'aujourdhui' => date('Y-m-d'), 'semaines' => $m['periodes'], 'total' => $m['total'], 'totalN1' => $m['totalN1'],
        'n1Motif' => $m['n1Motif'], 'source' => vsSourceTexte($m['sources'], $vue)];
}

/**
 * GET /ventes/reseau?vue=semaine|mois|annee&date=YYYY-MM-DD — tous les
 * magasins actifs, chacun face à son N-1, et le réseau sommé. Le N-1 du
 * réseau ne compte que les magasins qui en ont un ; `comparables` donne le N
 * de ces mêmes magasins, pour un écart à périmètre constant.
 */
function ep_ventes_reseau(): array
{
    [$vue, $date, $n] = vsParams();
    $periodes = vsPeriodes($vue, $date, $n);
    try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
    catch (PDOException $e) { $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name"); }
    $magasins = []; $sources = [];
    $reseau = array_map(static fn ($p) => ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'ca1' => 0.0, 'tickets1' => 0, 'caComp' => 0.0, 'ticketsComp' => 0, 'magasins' => 0, 'comparables' => 0], $periodes);
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        $lu = vsLireMagasin($sid, $periodes);
        $m = vsMagasin($sid, $periodes, $lu);
        if ($m['total']['jours'] === 0 && $m['totalN1'] === null) { continue; } // jamais vendu : hors réseau
        foreach ($m['sources'] as $k => $v) { $sources[$k] = true; }
        $par = [];
        foreach ($m['periodes'] as $i => $p) {
            $par[] = ['ca' => $p['ca'], 'tickets' => $p['tickets'], 'jours' => $p['jours'], 'source' => $p['source'], 'n1' => $p['n1']];
            if ($p['source'] !== null) { $reseau[$i]['ca'] += $p['ca']; $reseau[$i]['tickets'] += $p['tickets']; $reseau[$i]['jours'] += $p['jours']; $reseau[$i]['magasins']++; }
            if ($p['n1'] !== null) {
                $reseau[$i]['ca1'] += $p['n1']['ca']; $reseau[$i]['tickets1'] += $p['n1']['tickets']; $reseau[$i]['comparables']++;
                if ($p['source'] !== null) { $reseau[$i]['caComp'] += $p['ca']; $reseau[$i]['ticketsComp'] += $p['tickets']; }
            }
        }
        $magasins[] = ['id' => (string) $sid, 'nom' => (string) $s['name'], 'periodes' => $par,
            'total' => $m['total'], 'totalN1' => $m['totalN1'], 'n1Motif' => $m['n1Motif']];
    }
    $out = [];
    foreach ($periodes as $i => $p) {
        $r = $reseau[$i];
        $out[] = ['lab' => $p['lab'], 'iso' => $p['iso'], 'du' => $p['du'], 'au' => $p['au'], 'du1' => $p['du1'], 'enCours' => $p['enCours'],
            'futur' => $p['futur'], 'joursServis' => $p['joursServis'],
            'ca' => round($r['ca'], 2), 'tickets' => $r['tickets'], 'jours' => $r['jours'], 'magasins' => $r['magasins'],
            'panier' => $r['tickets'] > 0 ? round($r['ca'] / $r['tickets'], 2) : null,
            'n1' => $r['comparables'] > 0 ? ['ca' => round($r['ca1'], 2), 'tickets' => $r['tickets1'], 'magasins' => $r['comparables'],
                'caComp' => round($r['caComp'], 2), 'ticketsComp' => $r['ticketsComp']] : null];
    }
    return ['vue' => $vue, 'date' => $date, 'aujourdhui' => date('Y-m-d'), 'du' => $periodes[0]['du'], 'au' => end($periodes)['au'],
        'periodes' => $out, 'magasins' => $magasins, 'source' => vsSourceTexte($sources, $vue)];
}

function vsParams(): array
{
    $auj = date('Y-m-d');
    $vue = in_array($_GET['vue'] ?? '', ['semaine', 'mois', 'annee'], true) ? (string) $_GET['vue'] : 'semaine';
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $n = max(2, min(12, (int) ($_GET['n'] ?? 6)));
    return [$vue, $date, $n];
}

function vsSourceTexte(array $sources, string $vue): string
{
    $s = array_map(static fn ($k) => $k === 'panel' ? 'panel (margin-heatmap, par jour)' : 'copie de caisse (tickets)', array_keys($sources));
    $n1 = $vue === 'semaine' ? 'même semaine décalée de 364 jours' : 'même mois un an plus tôt';
    return implode(' + ', $s) . ' · N-1 = ' . $n1 . ', arrêté au même jour servi';
}

/**
 * Les périodes de l'étendue : [lab, iso, du, au, jusqua, du1, au1, enCours,
 * futur, joursServis]. `au1` s'arrête au même rang de jour que `jusqua`.
 */
function vsPeriodes(string $vue, string $date, int $n): array
{
    $auj = date('Y-m-d');
    $MN = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $out = [];
    if ($vue === 'semaine') {
        $lundi = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        for ($i = $n - 1; $i >= 0; $i--) {
            $du = date('Y-m-d', strtotime($lundi . ' -' . (7 * $i) . ' days'));
            $au = date('Y-m-d', strtotime($du . ' +6 days'));
            $jusqua = min($au, $auj);
            $out[] = ['lab' => 'S' . (int) date('W', strtotime($du)), 'iso' => (int) date('W', strtotime($du)), 'du' => $du, 'au' => $au, 'jusqua' => $jusqua,
                'du1' => date('Y-m-d', strtotime($du . ' -364 days')), 'au1' => date('Y-m-d', strtotime($jusqua . ' -364 days')),
                'enCours' => $au >= $auj, 'futur' => false, 'joursServis' => (int) ((strtotime($jusqua) - strtotime($du)) / 86400) + 1];
        }
        return $out;
    }
    $y = (int) substr($date, 0, 4); $m = (int) substr($date, 5, 2);
    $mois = [];
    if ($vue === 'mois') { for ($i = 5; $i >= 0; $i--) { $t = mktime(12, 0, 0, $m - $i, 1, $y); $mois[] = [(int) date('Y', $t), (int) date('n', $t)]; } }
    else { for ($k = 1; $k <= 12; $k++) { $mois[] = [$y, $k]; } }
    foreach ($mois as [$yy, $mm]) {
        $du = sprintf('%04d-%02d-01', $yy, $mm); $au = date('Y-m-t', strtotime($du));
        $futur = $du > $auj;
        $jusqua = min($au, $auj);
        $du1 = sprintf('%04d-%02d-01', $yy - 1, $mm); $au1 = min(date('Y-m-t', strtotime($du1)), sprintf('%04d-%02d-%s', $yy - 1, $mm, substr($jusqua, 8, 2)));
        $out[] = ['lab' => $MN[$mm], 'iso' => $mm, 'du' => $du, 'au' => $au, 'jusqua' => $futur ? null : $jusqua,
            'du1' => $du1, 'au1' => $au1, 'enCours' => !$futur && $au >= $auj, 'futur' => $futur,
            'joursServis' => $futur ? 0 : (int) ((strtotime($jusqua) - strtotime($du)) / 86400) + 1];
    }
    return $out;
}

/** Les jours d'un magasin, panel puis caisse, sur toutes les fenêtres des périodes. */
function vsLireMagasin(int $sid, array $periodes): array
{
    $fen = [];
    foreach ($periodes as $p) {
        if ($p['futur']) { continue; }
        $fen[] = [$p['du'], $p['jusqua']];
        $fen[] = [$p['du1'], $p['au1']];
    }
    $panel = vsPanelJours($sid, $fen);
    // La caisse ne se lit que pour les fenêtres où le panel n'a rien : un an
    // de tickets ne se relit pas pour confirmer ce que le panel sait déjà.
    $sans = [];
    foreach ($fen as [$d1, $d2]) {
        $vu = false;
        for ($j = $d1; $j <= $d2; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { if (isset($panel[$j])) { $vu = true; break; } }
        if (!$vu) { $sans[] = [$d1, $d2]; }
    }
    return ['panel' => $panel, 'caisse' => vsCaisseJours($sid, $sans)];
}

/** Un magasin : ses périodes sommées, ses totaux, son motif sans N-1. */
function vsMagasin(int $sid, array $periodes, array $lu): array
{
    $out = []; $tot = ['ca' => 0.0, 'tickets' => 0, 'jours' => 0]; $tot1 = ['ca' => 0.0, 'tickets' => 0, 'jours' => 0]; $n1Vu = false; $srcs = [];
    foreach ($periodes as $p) {
        $s = $p['futur'] ? ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'source' => null] : vsSomme($lu['panel'], $lu['caisse'], $p['du'], $p['jusqua']);
        $s1 = $p['futur'] ? $s : vsSomme($lu['panel'], $lu['caisse'], $p['du1'], $p['au1']);
        $ligne = ['lab' => $p['lab'], 'iso' => $p['iso'], 'du' => $p['du'], 'au' => $p['au'], 'enCours' => $p['enCours'], 'futur' => $p['futur'], 'joursServis' => $p['joursServis'],
            'ca' => $s['ca'], 'tickets' => $s['tickets'], 'jours' => $s['jours'], 'source' => $s['source'],
            'panier' => $s['tickets'] > 0 ? round($s['ca'] / $s['tickets'], 2) : null,
            'n1' => $s1['source'] === null ? null : ['du' => $p['du1'], 'ca' => $s1['ca'], 'tickets' => $s1['tickets'], 'jours' => $s1['jours'], 'source' => $s1['source']]];
        if ($s['source'] !== null) { $tot['ca'] += $s['ca']; $tot['tickets'] += $s['tickets']; $tot['jours'] += $s['jours']; $srcs[$s['source']] = true; }
        if ($ligne['n1'] !== null) { $n1Vu = true; $tot1['ca'] += $s1['ca']; $tot1['tickets'] += $s1['tickets']; $tot1['jours'] += $s1['jours']; $srcs[$s1['source']] = true; }
        $out[] = $ligne;
    }
    $tot['ca'] = round($tot['ca'], 2); $tot1['ca'] = round($tot1['ca'], 2);
    $n1Motif = null;
    if (!$n1Vu) {
        $ouv = vsPremiereVente($sid);
        $n1Motif = $ouv !== null && $ouv > $periodes[0]['du1']
            ? 'pas de N-1 : la caisse a sonné pour la première fois le ' . date('d/m/Y', strtotime($ouv))
            : 'pas de N-1 : aucune vente lue un an plus tôt, ni au panel ni dans la copie de caisse';
    }
    return ['periodes' => $out, 'total' => $tot, 'totalN1' => $n1Vu ? $tot1 : null, 'n1Motif' => $n1Motif, 'sources' => $srcs];
}

/**
 * Les jours du panel sur des fenêtres : [date => [ca, tickets]]. Chaque mois
 * civil touché se lit en une fois ; un mois clos se grave et ne se relit plus.
 */
function vsPanelJours(int $sid, array $fenetres): array
{
    if (!PanelApi::configured()) { return []; }
    $auj = date('Y-m-d'); $moisC = substr($auj, 0, 7);
    $mois = [];
    foreach ($fenetres as [$d1, $d2]) {
        for ($ym = substr($d1, 0, 7); $ym <= substr($d2, 0, 7); $ym = date('Y-m', strtotime($ym . '-01 +1 month'))) { $mois[$ym] = true; }
    }
    $out = []; $paths = [];
    foreach (array_keys($mois) as $ym) {
        if ($ym < $moisC) {
            $c = setting('vsP' . $sid . ':' . $ym);
            if (is_array($c) && isset($c['j'])) { foreach ($c['j'] as $j => $v) { $out[$j] = $v; } continue; }
        }
        $paths[$ym] = '/consultant/shops/' . $sid . '/margin-heatmap?' . http_build_query(['from' => $ym . '-01', 'to' => min(date('Y-m-t', strtotime($ym . '-01')), $auj)]);
    }
    if ($paths === []) { return $out; }
    try { $res = PanelApi::getParallele($paths, 6); } catch (Throwable $e) { return $out; }
    foreach ($paths as $ym => $chemin) {
        $hm = $res[$ym] ?? null;
        if (!is_array($hm) || !isset($hm['days'])) { continue; }
        $jours = [];
        foreach ((array) $hm['days'] as $d) {
            $j = (string) ($d['date'] ?? ''); $ca = (float) ($d['ca'] ?? 0);
            if ($j === '' || !(!empty($d['has_data']) && $ca > 0)) { continue; }
            $jours[$j] = ['ca' => $ca, 'tickets' => (int) ($d['tickets'] ?? 0)];
            $out[$j] = $jours[$j];
        }
        // Un mois clos se grave, même vide : un mois sans vente ne vaut pas
        // une relecture par jour du réseau.
        if ($ym < $moisC && function_exists('svGrave')) { try { svGrave('vsP' . $sid . ':' . $ym, ['quand' => time(), 'j' => $jours]); } catch (Throwable $e) { /* sans cache */ } }
    }
    return $out;
}

/** Les jours de la copie de caisse sur des fenêtres : [date => [ca, tickets]]. */
function vsCaisseJours(int $sid, array $fenetres): array
{
    $out = [];
    if ($fenetres === []) { return $out; }
    // Une seule lecture par an civil touché plutôt qu'une par fenêtre.
    $bornes = [];
    foreach ($fenetres as [$d1, $d2]) { $y = substr($d1, 0, 4); $bornes[$y] = [min($bornes[$y][0] ?? $d1, $d1), max($bornes[$y][1] ?? $d2, $d2)]; $y2 = substr($d2, 0, 4); if ($y2 !== $y) { $bornes[$y2] = [min($bornes[$y2][0] ?? $d1, $d1), max($bornes[$y2][1] ?? $d2, $d2)]; } }
    foreach ($bornes as [$d1, $d2]) {
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
