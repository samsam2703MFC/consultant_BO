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

/**
 * GET /ventes/semaines?shop=4&vue=semaine|mois|annee&date=YYYY-MM-DD&n=6 (2 à 13 semaines)[&articles=1][&pro=1] — avec
 * `articles=1`, chaque période (et son N-1) porte aussi `articles`, les pièces vendues (dashboard magasin, articles par
 * client, 19/10/2026) ; avec `pro=1`, `caComptoir` et `ticketsComptoir` (le panier hors clients pro, 21/10/2026).
 */
function ep_ventes_semaines(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    [$vue, $date, $n] = vsParams();
    $periodes = vsPeriodes($vue, $date, $n);
    $lu = vsLireMagasin($sid, $periodes);
    $m = vsMagasin($sid, $periodes, $lu);
    if (!empty($_GET['articles'])) {
        $m['periodes'] = vsArticlesLignes((string) $sid, $periodes, $m['periodes'], vsArticles(vsArticlesFenetres($periodes)));
    }
    // `pro=1` (le dashboard magasin, 21/10/2026) : le comptoir de chaque période, pour le panier hors clients pro. À la
    // demande : une centaine de relevés à relire, que le cockpit, qui n'en montre pas le panier, n'a pas à payer.
    if (!empty($_GET['pro'])) { $m['periodes'] = vsComptoirPeriodes($sid, $periodes, $m['periodes'], $lu); }
    return ['shop' => (string) $sid, 'vue' => $vue, 'n' => count($periodes), 'du' => $periodes[0]['du'], 'au' => end($periodes)['au'],
        'aujourdhui' => date('Y-m-d'), 'semaines' => $m['periodes'], 'total' => $m['total'], 'totalN1' => $m['totalN1'],
        'n1Motif' => $m['n1Motif'], 'source' => vsSourceTexte($m['sources'], $vue)];
}

/**
 * GET /ventes/reseau?vue=semaine|mois|annee&date=YYYY-MM-DD[&articles=1] — tous
 * les magasins actifs, chacun face à son N-1, et le réseau sommé. Le N-1 du
 * réseau ne compte que les magasins qui en ont un ; `comparables` donne le N
 * de ces mêmes magasins, pour un écart à périmètre constant. Avec `articles=1`,
 * chaque période d'un magasin porte `articles` (pièces vendues), et son `n1`
 * et son `n1Mois` aussi.
 */
function ep_ventes_reseau(): array
{
    [$vue, $date, $n] = vsParams();
    $periodes = vsPeriodes($vue, $date, $n);
    try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
    catch (PDOException $e) { $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name"); }
    $magasins = []; $sources = [];
    // `articles=1` (Résultat › Année, articles par ticket, 24/10/2026) : les pièces de chaque fenêtre, un appel au panel
    // par fenêtre pour tout le réseau — lues une fois, avant la boucle des magasins.
    $A = !empty($_GET['articles']) ? vsArticles(vsArticlesFenetres($periodes)) : null;
    $reseau = array_map(static fn ($p) => ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'ca1' => 0.0, 'tickets1' => 0, 'caComp' => 0.0, 'ticketsComp' => 0, 'magasins' => 0, 'comparables' => 0], $periodes);
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        $lu = vsLireMagasin($sid, $periodes);
        $m = vsMagasin($sid, $periodes, $lu);
        if ($m['total']['jours'] === 0 && $m['totalN1'] === null) { continue; } // jamais vendu : hors réseau
        if ($A !== null) { $m['periodes'] = vsArticlesLignes((string) $sid, $periodes, $m['periodes'], $A); }
        foreach ($m['sources'] as $k => $v) { $sources[$k] = true; }
        $par = [];
        foreach ($m['periodes'] as $i => $p) {
            $par[] = ['ca' => $p['ca'], 'tickets' => $p['tickets'], 'jours' => $p['jours'], 'source' => $p['source'], 'n1' => $p['n1']]
                + (array_key_exists('n1Mois', $p) ? ['n1Mois' => $p['n1Mois']] : [])
                + (array_key_exists('articles', $p) ? ['articles' => $p['articles']] : []);
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

/** Les fenêtres dont il faut les pièces : chaque période et son N-1 à date, et le mois N-1 entier (vues mois et année). */
function vsArticlesFenetres(array $periodes): array
{
    $fen = [];
    foreach ($periodes as $p) {
        if (!$p['futur']) { $fen[] = [$p['du'], $p['jusqua']]; $fen[] = [$p['du1'], $p['au1']]; }
        if (isset($p['au1Mois'])) { $fen[] = [$p['du1'], $p['au1Mois']]; }
    }
    return $fen;
}

/** Pose `articles` sur les lignes d'un magasin (période, `n1`, `n1Mois`) depuis le relevé de vsArticles. */
function vsArticlesLignes(string $sid, array $periodes, array $lignes, array $A): array
{
    foreach ($lignes as $i => $l) {
        $p = $periodes[$i];
        if (!$p['futur']) {
            $lignes[$i]['articles'] = $A[$p['du'] . '|' . $p['jusqua']][$sid] ?? null;
            if ($l['n1'] !== null) { $lignes[$i]['n1']['articles'] = $A[$p['du1'] . '|' . $p['au1']][$sid] ?? null; }
        }
        if (!empty($l['n1Mois'])) { $lignes[$i]['n1Mois']['articles'] = $A[$p['du1'] . '|' . $p['au1Mois']][$sid] ?? null; }
    }
    return $lignes;
}

/**
 * Les pièces vendues de chaque fenêtre, magasin par magasin : `/consultant/shops/sales-kpis` (`products`, les produits
 * vendus — la même source que les articles par ticket de l'année), un appel par fenêtre pour tout le réseau. Une fenêtre
 * finie avant aujourd'hui se grave : lue un jour plus tard, elle est close. Rend ['du|au' => [magasin => pièces]].
 */
function vsArticles(array $fenetres): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return []; }
    $auj = date('Y-m-d'); $out = []; $paths = [];
    foreach ($fenetres as [$d1, $d2]) {
        $k = $d1 . '|' . $d2;
        if (isset($out[$k]) || isset($paths[$k])) { continue; }
        if ($d2 < $auj) { $c = setting('vsA:' . $k); if (is_array($c) && $c !== []) { $out[$k] = $c; continue; } }
        $paths[$k] = '/consultant/shops/sales-kpis?' . http_build_query(['date_from' => $d1, 'date_to' => $d2]);
    }
    if ($paths === []) { return $out; }
    try { $res = PanelApi::getParallele($paths, 6); } catch (Throwable $e) { return $out; }
    foreach ($paths as $k => $ch) {
        $par = [];
        foreach (analyseListe($res[$k] ?? null) as $x) {
            if (!is_array($x)) { continue; }
            $id = 0;
            foreach (['shop_id', 'id_shop', 'id'] as $c) { if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; } }
            $pr = nombreOuNull($x, ['products', 'items', 'product_count']);
            if ($id > 0 && $pr !== null) { $par[(string) $id] = (int) round($pr); }
        }
        $out[$k] = $par;
        if ($par !== [] && explode('|', $k)[1] < $auj && function_exists('svGrave')) { try { svGrave('vsA:' . $k, $par); } catch (Throwable $e) { /* sans cache */ } }
    }
    return $out;
}

/**
 * Le CA et les tickets comptoir de chaque période et de son N-1 (21/10/2026, le panier moyen hors clients pro du
 * dashboard) : le total moins les tickets pro gravés de ses jours vendus — seulement si chacun de ces jours a son relevé
 * pro, sinon null (le panier de la période reste alors pro compris, et le dashboard ne le compare pas à un hors pro).
 * Les jours des périodes N d'avant les relevés se complètent de quelques listes (vpJoursLus, sept au plus, les plus
 * récents d'abord) ; le N-1 ne se lit qu'au gravé — avant SV_DEBUT, rien n'a été lu.
 */
function vsComptoirPeriodes(int $sid, array $periodes, array $lignes, array $lu): array
{
    if (!function_exists('vpProJours') || !function_exists('vpComptoirDe') || !function_exists('vpJoursLus')) { return $lignes; }
    $vendus = static function (?string $src, string $d1, string $d2) use ($lu): array {
        $jours = $src === null ? [] : array_keys(array_filter($lu[$src] ?? [], static fn ($v, $j) => $j >= $d1 && $j <= $d2, ARRAY_FILTER_USE_BOTH));
        sort($jours);
        return $jours;
    };
    $joursN = [];
    foreach ($lignes as $i => $l) {
        if ($periodes[$i]['futur'] || $l['source'] === null) { continue; }
        foreach ($vendus($l['source'], $periodes[$i]['du'], $periodes[$i]['jusqua']) as $j) { if (!defined('SV_DEBUT') || $j >= SV_DEBUT) { $joursN[] = $j; } }
    }
    $lusN = $joursN === [] ? [] : (vpJoursLus([$sid], array_values(array_unique($joursN)), 7)[$sid] ?? []);
    foreach ($lignes as $i => $l) {
        $p = $periodes[$i];
        if ($p['futur'] || $l['source'] === null) { continue; }
        $lignes[$i] += vpComptoirDe(vpProJours($sid, $vendus($l['source'], $p['du'], $p['jusqua']), true, $lusN), (float) $l['ca'], (int) $l['tickets']);
        if ($l['n1'] !== null) {
            $lignes[$i]['n1'] += vpComptoirDe(vpProJours($sid, $vendus($l['n1']['source'], $p['du1'], $p['au1']), true), (float) $l['n1']['ca'], (int) $l['n1']['tickets']);
        }
    }
    return $lignes;
}

/**
 * GET /ventes/evolution — chaque magasin actif depuis sa première vente, mois
 * par mois, chaque mois face au même mois un an plus tôt (Résultat ›
 * Évolution magasin).
 *
 * Lu par années civiles, de la plus récente à la plus ancienne, avec les
 * mêmes lectures que l'étendue « année » : une année lue porte aussi son N-1,
 * et la lecture s'arrête à la première année dont le N-1 est vide pour tous
 * les magasins — l'année d'avant n'a rien à montrer. Les mois clos du panel
 * sont gravés (vsPanelJours) : seul le mois en cours se relit.
 *
 * Chaque mois porte ses jours vendus (`jours`) et ceux de son N-1 : l'écran
 * en tire la lecture « par jour ouvert », qui neutralise les fermetures. Le
 * mois en cours s'arrête à aujourd'hui, son N-1 au même jour.
 *
 * Chaque mois et son N-1 portent aussi `pro` {clients, ca, joursLus} (13/11/2026,
 * la bascule « ventes : Tout | Comptoir | B2B ») : les tickets des clients pro à
 * TVA valide de ses jours vendus (vsProJours). Le comptoir, c'est le reste ;
 * l'écran ne le calcule que si `joursLus` couvre tous les jours vendus.
 */
function ep_ventes_evolution(): array
{
    $auj = date('Y-m-d');
    try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
    catch (PDOException $e) { $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name"); }
    $par = []; $sources = [];
    $an = (int) substr($auj, 0, 4);
    for ($k = 0; $k < 10; $k++, $an--) {          // garde-fou : dix ans au plus
        $periodes = vsPeriodes('annee', $k === 0 ? $auj : $an . '-12-31', 12);
        $n1Vu = false;
        foreach ($shops as $s) {
            $sid = (int) $s['id'];
            $lu = vsLireMagasin($sid, $periodes);
            $m = vsMagasin($sid, $periodes, $lu);
            // Les jours vendus de chaque mois et de son N-1, dans la source qui les a comptés : le B2B se lit sur
            // ces jours-là, une fois pour toute l'année (vsProJours).
            $vendus = static fn (?string $src, string $d1, string $d2): array => $src === null ? []
                : array_values(array_filter(array_map('strval', array_keys($lu[$src] ?? [])), static fn ($j) => $j >= $d1 && $j <= $d2));
            $jours = []; $tous = [];
            foreach ($m['periodes'] as $i => $p) {
                if ($p['futur'] || $p['source'] === null) { continue; }
                $P = $periodes[$i];
                $jours[$i] = [$vendus($p['source'], $P['du'], $P['jusqua']), $p['n1'] === null ? [] : $vendus($p['n1']['source'], $P['du1'], $P['au1'])];
                foreach (array_merge($jours[$i][0], $jours[$i][1]) as $j) { $tous[$j] = true; }
            }
            $pro = vsProJours($sid, array_keys($tous));
            foreach ($m['periodes'] as $i => $p) {
                if ($p['futur'] || $p['source'] === null) { continue; }
                $P = $periodes[$i];
                $sources[$p['source']] = true;
                $par[$sid]['nom'] = (string) $s['name'];
                $par[$sid]['mois'][substr($P['du'], 0, 7)] = [
                    'mois' => substr($P['du'], 0, 7), 'an' => (int) substr($P['du'], 0, 4), 'mo' => (int) $P['iso'], 'lab' => $P['lab'],
                    'enCours' => $P['enCours'], 'joursMois' => (int) date('t', strtotime($P['du'])), 'joursServis' => $P['joursServis'],
                    'clients' => $p['tickets'], 'ca' => $p['ca'], 'jours' => $p['jours'], 'source' => $p['source'], 'pro' => vsProSomme($pro, $jours[$i][0]),
                    'n1' => $p['n1'] === null ? null : ['clients' => $p['n1']['tickets'], 'ca' => $p['n1']['ca'], 'jours' => $p['n1']['jours'], 'source' => $p['n1']['source'],
                        'pro' => vsProSomme($pro, $jours[$i][1])]];
            }
            if ($m['totalN1'] !== null) { $n1Vu = true; }
        }
        if (!$n1Vu) { break; }
    }
    $magasins = [];
    foreach ($par as $sid => $x) {
        ksort($x['mois']);
        $mois = array_values($x['mois']);
        $magasins[] = ['id' => (string) $sid, 'nom' => $x['nom'], 'premier' => $mois[0]['mois'], 'mois' => $mois];
    }
    // Le plus ancien d'abord : l'histoire du réseau se lit dans l'ordre des ouvertures.
    usort($magasins, static fn ($a, $b) => strcmp($a['premier'], $b['premier']) ?: strcmp($a['nom'], $b['nom']));
    return ['aujourdhui' => $auj, 'du' => $magasins === [] ? null : min(array_column($magasins, 'premier')),
        'magasins' => $magasins, 'source' => vsSourceTexte($sources, 'mois'),
        'sourcePro' => 'B2B : tickets des clients pro à TVA valide — relevé des tickets du panel depuis le ' . date('d/m/Y', strtotime(defined('SV_DEBUT') ? SV_DEBUT : $auj))
            . ', copie de caisse avant (client marqué pro) ; comptoir = le reste'];
}

/* --- Le B2B de l'Évolution magasin (13/11/2026) ---------------------------------------------------------------------
 * La bascule « ventes : Tout | Comptoir | B2B » de Résultat › Évolution magasin. Le B2B, ce sont les tickets des
 * clients pro à TVA valide (vpTvaValides, la règle de tout le cockpit) ; le comptoir, le reste du total.
 *
 * Deux sources, jour par jour, dans cet ordre :
 *  - le relevé des tickets du panel (`svP{shop}:{jour}`, clé `b`, vpLu) — celui du dashboard et d'Analyse réseau ;
 *    il n'existe que depuis SV_DEBUT ; un jour du mois en cours qui n'est pas relevé se lit (vpJour, quelques listes) ;
 *  - la copie de caisse (`transaction` ⨝ `client.is_b2b`, comme la prime B2B) pour tout l'historique d'avant. Un mois
 *    clos que la copie couvre se grave brut, client par client (`vsPro{shop}:{AAAA-MM}`) : le filtre TVA s'applique à
 *    chaque lecture, une fiche complétée plus tard fait revenir ses tickets au pro.
 * Un jour qu'aucune des deux ne connaît n'est pas lu : il manque à `joursLus`, il ne compte pas pour zéro. */

const VS_PRO_LISTES = 8;   // listes du panel lues au plus par magasin pour compléter le mois en cours

/**
 * Le B2B d'un magasin, jour par jour, sur ses jours vendus : [date => ['ca' => float, 'clients' => int]] pour chaque
 * jour dont le pro est connu (zéro compris).
 */
function vsProJours(int $sid, array $jours): array
{
    $jours = array_values(array_unique(array_map('strval', $jours)));
    if ($jours === []) { return []; }
    sort($jours);
    $auj = date('Y-m-d'); $moisC = substr($auj, 0, 7);
    $deb = defined('SV_DEBUT') ? SV_DEBUT : '9999-12-31';
    // 1. La copie de caisse, mois par mois : gravée pour un mois clos, relue sinon (une lecture pour les mois à lire).
    $brut = []; $aLire = [];
    foreach (array_unique(array_map(static fn ($j) => substr($j, 0, 7), $jours)) as $ym) {
        $c = $ym < $moisC ? setting('vsPro' . $sid . ':' . $ym) : null;
        if (is_array($c) && isset($c['j']) && is_array($c['j'])) { foreach ($c['j'] as $j => $v) { $brut[(string) $j] = (array) $v; } continue; }
        $aLire[] = $ym;
    }
    if ($aLire !== []) {
        sort($aLire);
        $lu = vsProCopie($sid, $aLire[0] . '-01', date('Y-m-t', strtotime(end($aLire) . '-01')));
        if ($lu !== null) {
            $copieAu = vsCopieAu();
            foreach ($aLire as $ym) {
                $mois = array_filter($lu, static fn ($j) => substr((string) $j, 0, 7) === $ym, ARRAY_FILTER_USE_KEY);
                foreach ($mois as $j => $v) { $brut[(string) $j] = $v; }
                // Gravé seulement si la copie va au-delà du mois : un mois que la copie n'a pas fini reste à relire.
                if ($ym < $moisC && $copieAu !== null && $copieAu > date('Y-m-t', strtotime($ym . '-01')) && function_exists('svGrave')) {
                    try { svGrave('vsPro' . $sid . ':' . $ym, ['j' => $mois]); } catch (Throwable $e) { /* relu la prochaine fois */ }
                }
            }
        }
    }
    // 2. Le filtre TVA : un client pro sans TVA valide reste au comptoir ; une fiche illisible garde l'avis du panel.
    $cles = [];
    foreach ($brut as $v) { foreach ($v as $k => $x) { $cles[(string) $k] = true; } }
    $ok = $cles !== [] && function_exists('vpTvaValides') ? vpTvaValides($sid, array_keys($cles)) : [];
    $out = [];
    foreach ($jours as $j) {
        if (!isset($brut[$j])) { continue; }
        $ca = 0.0; $n = 0;
        foreach ($brut[$j] as $k => $x) {
            if (($ok[(string) $k] ?? null) === false) { continue; }
            $ca += (float) ($x[0] ?? 0); $n += (int) ($x[1] ?? 0);
        }
        $out[$j] = ['ca' => round($ca, 2), 'clients' => $n];
    }
    // 3. Le relevé du panel prime là où il existe ; un jour du mois en cours sans relevé valable se lit.
    if (function_exists('vpLu')) {
        $appels = 0;
        foreach ($jours as $j) {
            if ($j < $deb) { continue; }
            $b = vpLu($sid, $j);
            if ($b === null && substr($j, 0, 7) === $moisC && function_exists('vpJour')) { $b = vpJour($sid, $j, true, $appels, VS_PRO_LISTES); }
            if ($b === null || !is_array($b['t'] ?? null)) { continue; }
            $ca = 0.0;
            foreach ($b['t'] as $t) { $ca += (float) ($t[2] ?? 0); }   // le montant de caisse : le comptoir en est le reste
            $out[$j] = ['ca' => round($ca, 2), 'clients' => count($b['t'])];
        }
    }
    ksort($out);
    return $out;
}

/** Le B2B d'une liste de jours vendus : la somme des jours connus, et combien le sont. */
function vsProSomme(array $pro, array $jours): array
{
    $s = ['clients' => 0, 'ca' => 0.0, 'joursLus' => 0];
    foreach ($jours as $j) {
        $x = $pro[(string) $j] ?? null;
        if ($x === null) { continue; }
        $s['clients'] += (int) $x['clients']; $s['ca'] += (float) $x['ca']; $s['joursLus']++;
    }
    $s['ca'] = round($s['ca'], 2);
    return $s;
}

/**
 * La copie de caisse d'un magasin entre deux dates, jour par jour : [date => [clé de compte => [CA, tickets]]] — les
 * clients marqués pro seulement ; un jour vendu sans ticket pro a sa ligne, vide (la copie le connaît : zéro, pas
 * « pas lu »). Une lecture par an civil touché, comme vsCaisseJours. Null si la base ne répond pas.
 */
function vsProCopie(int $sid, string $d1, string $d2): ?array
{
    $out = []; $lu = false;
    for ($y = (int) substr($d1, 0, 4); $y <= (int) substr($d2, 0, 4); $y++) {
        $a = max($d1, $y . '-01-01'); $b = min($d2, $y . '-12-31');
        try {
            $rows = Db::rows("SELECT /*+ MAX_EXECUTION_TIME(9000) */ DATE(t.insert_timestamp) j, IF(k.is_b2b = 1, t.id_client, 0) cli,
                                     SUM(t.total_gross_amount_after_discount) ca, COUNT(DISTINCT t.ticket_key) n
                                FROM `transaction` t LEFT JOIN client k ON k.id = t.id_client
                               WHERE t.id_shop = ? AND t.insert_timestamp >= ? AND t.insert_timestamp < ?
                               GROUP BY j, cli", [$sid, $a . ' 00:00:00', date('Y-m-d', strtotime($b . ' +1 day')) . ' 00:00:00']);
        } catch (Throwable $e) { continue; }
        $lu = true;
        foreach ($rows as $r) {
            $j = substr((string) ($r['j'] ?? ''), 0, 10);
            if ($j === '') { continue; }
            $out[$j] = $out[$j] ?? [];
            $cli = (int) ($r['cli'] ?? 0);
            if ($cli > 0 && (int) ($r['n'] ?? 0) > 0) { $out[$j]['c' . $cli] = [round((float) ($r['ca'] ?? 0), 2), (int) $r['n']]; }
        }
    }
    return $lu ? $out : null;
}

/** Le dernier jour de la copie de caisse (lu une fois par requête, `$relire` le relit) ; null si la base ne répond pas. */
function vsCopieAu(bool $relire = false): ?string
{
    static $au = false;
    if ($au !== false && !$relire) { return $au; }
    try { $r = Db::row('SELECT /*+ MAX_EXECUTION_TIME(4000) */ MAX(insert_timestamp) le FROM `transaction`'); }
    catch (Throwable $e) { return $au = null; }
    $le = $r === null ? '' : (string) ($r['le'] ?? '');
    return $au = ($le === '' ? null : substr($le, 0, 10));
}

function vsParams(): array
{
    $auj = date('Y-m-d');
    $vue = in_array($_GET['vue'] ?? '', ['semaine', 'mois', 'annee'], true) ? (string) $_GET['vue'] : 'semaine';
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    // 13 au plus : douze semaines closes et celle en cours (Résultat › Trafic clients, 19/10/2026).
    $n = max(2, min(13, (int) ($_GET['n'] ?? 6)));
    return [$vue, $date, $n];
}

function vsSourceTexte(array $sources, string $vue): string
{
    $s = array_map(static fn ($k) => $k === 'panel' ? 'panel (margin-heatmap, par jour)' : 'copie de caisse (tickets)', array_keys($sources));
    $n1 = $vue === 'semaine' ? 'même semaine décalée de 364 jours' : 'même mois un an plus tôt';
    return implode(' + ', $s) . ' · N-1 = ' . $n1 . ', arrêté au même jour servi';
}

/**
 * Les périodes de l'étendue : [lab, iso, du, au, jusqua, du1, au1, au1Mois,
 * enCours, futur, joursServis]. `au1` s'arrête au même rang de jour que
 * `jusqua` ; `au1Mois` (vues mois et année) est la fin du mois civil N-1.
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
            'du1' => $du1, 'au1' => $au1, 'au1Mois' => date('Y-m-t', strtotime($du1)), 'enCours' => !$futur && $au >= $auj, 'futur' => $futur,
            'joursServis' => $futur ? 0 : (int) ((strtotime($jusqua) - strtotime($du)) / 86400) + 1];
    }
    return $out;
}

/** Les jours d'un magasin, panel puis caisse, sur toutes les fenêtres des périodes. */
function vsLireMagasin(int $sid, array $periodes): array
{
    $fen = [];
    foreach ($periodes as $p) {
        // Le mois N-1 entier se lit même pour un mois à venir : c'est le mois à battre.
        if (isset($p['au1Mois'])) { $fen[] = [$p['du1'], $p['au1Mois']]; }
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
        // Le mois civil N-1 entier (vues mois et année), hors des totaux : la courbe de l'an passé
        // sur douze mois, le mois en cours et les mois à venir compris (Résultat › Année, 24/10/2026).
        // L'écart, lui, reste sur `n1`, arrêté au même jour servi.
        if (isset($p['au1Mois'])) {
            $sM = vsSomme($lu['panel'], $lu['caisse'], $p['du1'], $p['au1Mois']);
            $ligne['n1Mois'] = $sM['source'] === null ? null : ['ca' => $sM['ca'], 'tickets' => $sM['tickets'], 'jours' => $sM['jours'], 'source' => $sM['source']];
        }
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
