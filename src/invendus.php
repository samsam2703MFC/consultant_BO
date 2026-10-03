<?php
declare(strict_types=1);

/**
 * Invendus et poubelle — ce que le panel sait de ce qui n'a pas été vendu.
 *
 * Une seule route de lecture, mesurée le 03/10/2026 : GET /shops/{id}/products/waste
 * ?date_from=&date_to= rend, par produit, les pièces jetées (`waste_qty`), le coût de
 * recette de ces pièces (`recipe_waste_gross`, TTC : 0,725 € le croissant pour 0,684 €
 * net), la valeur de vente perdue (`ca_waste_net`) et le motif dominant
 * (`top_reason` : expiration, damage, tasting, quality). Les reports au lendemain
 * (« carryover ») n'ont pas de route de lecture : la caisse les écrit comme une
 * production du matin (POST /product-movements), et rien ne les rend. L'écran le dit
 * plutôt que de les inventer.
 *
 * Le coût retenu pour le P&L est NET : le coût de recette du panel pour le magasin
 * × pièces quand il est là et cohérent avec le brut, sinon le brut ÷ 1,06.
 */

const INV_TTL_COURS = 600;    // une fenêtre qui touche aujourd'hui ou hier : dix minutes
const INV_TTL_CLOS = 21600;   // une fenêtre close : six heures (la poubelle se saisit à la fermeture)
const INV_TVA = 1.06;         // repli brut → net : l'alimentaire

/** Le libellé d'un motif du panel. */
function invMotif(?string $m): string
{
    $l = ['expiration' => 'fin de journée', 'damage' => 'casse', 'tasting' => 'dégustation', 'quality' => 'qualité', 'carryover' => 'reporté au lendemain'];
    $m = strtolower(trim((string) $m));
    return $m === '' ? 'sans motif' : ($l[$m] ?? $m);
}

/** Une ligne du panel, normalisée : jamais rien d'autre que le produit. */
function invLigneDe(array $p): ?array
{
    $pid = (int) ($p['id_product'] ?? 0);
    $q = (float) ($p['waste_qty'] ?? 0);
    if ($pid <= 0 || $q <= 0) { return null; }
    return ['pid' => $pid, 'nom' => (string) ($p['product_name'] ?? ''), 'cat' => (string) ($p['category_name'] ?? ''),
        'pieces' => $q, 'coutBrut' => round((float) ($p['recipe_waste_gross'] ?? 0), 2), 'caPerdu' => round((float) ($p['ca_waste_net'] ?? 0), 2),
        'motif' => strtolower(trim((string) ($p['top_reason'] ?? ''))), 'motifN' => (float) ($p['top_reason_count'] ?? 0)];
}

/** La réponse brute du panel → les lignes ; null si ce n'est pas une réponse. */
function invLignesDe(mixed $r): ?array
{
    if (!is_array($r) || !isset($r['products']) || !is_array($r['products'])) { return null; }
    $out = [];
    foreach ($r['products'] as $p) { if (is_array($p) && ($l = invLigneDe($p)) !== null) { $out[] = $l; } }
    return $out;
}

function invCle(int $sid, string $du, string $au): string { return 'inv:' . $sid . ':' . $du . ':' . $au; }
function invTtl(string $au): int { return $au >= date('Y-m-d', strtotime('-1 day')) ? INV_TTL_COURS : INV_TTL_CLOS; }
function invChemin(int $sid, string $du, string $au): string
{
    return '/shops/' . $sid . '/products/waste?' . http_build_query(['date_from' => $du, 'date_to' => $au, 'from' => $du, 'to' => $au]);
}

/**
 * Les lignes d'un magasin sur une fenêtre : le cache s'il vaut encore, sinon le
 * panel ; un panel muet ressert la dernière lecture, même périmée, sinon null.
 */
function invLignes(int $sid, string $du, string $au, bool $lire = true): ?array
{
    $c = setting(invCle($sid, $du, $au));
    $valide = is_array($c) && isset($c['l']) && is_array($c['l']) && (int) ($c['ts'] ?? 0) > time() - invTtl($au);
    if ($valide) { return $c['l']; }
    $ancien = is_array($c) && isset($c['l']) && is_array($c['l']) ? $c['l'] : null;
    if (!$lire || !class_exists('PanelApi') || !PanelApi::configured()) { return $ancien; }
    $l = invLignesDe(PanelApi::get(invChemin($sid, $du, $au)));
    if ($l === null) { return $ancien; }
    svGrave(invCle($sid, $du, $au), ['ts' => time(), 'l' => $l]);
    return $l;
}

/** Les lignes de plusieurs magasins — [sid => lignes|null], les manques lus en parallèle. */
function invLignesMagasins(array $sids, string $du, string $au): array
{
    $out = []; $paths = [];
    foreach ($sids as $sid) {
        $sid = (int) $sid;
        $c = setting(invCle($sid, $du, $au));
        if (is_array($c) && isset($c['l']) && is_array($c['l']) && (int) ($c['ts'] ?? 0) > time() - invTtl($au)) { $out[$sid] = $c['l']; continue; }
        $out[$sid] = is_array($c) && isset($c['l']) && is_array($c['l']) ? $c['l'] : null;
        $paths[$sid] = invChemin($sid, $du, $au);
    }
    if ($paths && class_exists('PanelApi') && PanelApi::configured()) {
        foreach (PanelApi::getParallele($paths, 4, 25) as $sid => $r) {
            $l = invLignesDe($r);
            if ($l === null) { continue; }
            svGrave(invCle((int) $sid, $du, $au), ['ts' => time(), 'l' => $l]);
            $out[(int) $sid] = $l;
        }
    }
    return $out;
}

/**
 * Le coût NET d'une ligne jetée : le coût de recette du panel pour ce magasin
 * × pièces quand il existe et qu'il tient face au brut (entre 70 % et 100 %),
 * sinon le brut ÷ 1,06. [cout, source]
 */
function invCoutNet(int $sid, array $l): array
{
    $brut = (float) $l['coutBrut'];
    $repli = round($brut / INV_TVA, 2);
    $u = null;
    if (function_exists('coutsPanelMagasin')) { $u = coutsPanelMagasin($sid)[$l['pid']] ?? null; }
    if ($u === null && function_exists('catalogueCouts')) { $u = catalogueCouts()[$l['pid']]['mat'] ?? null; }
    if ($u !== null && is_numeric($u) && (float) $u > 0) {
        $net = round((float) $u * (float) $l['pieces'], 2);
        if ($brut <= 0 || ($net >= 0.7 * $brut && $net <= 1.0 * $brut + 0.01)) { return [$net, 'panel']; }
    }
    return [$repli, $brut > 0 ? 'brut ÷ 1,06' : 'sans coût'];
}

/**
 * La valeur de vente perdue d'une ligne : celle du panel quand il la chiffre, sinon
 * pièces × prix de vente du magasin (mesuré : sur une fenêtre d'un jour, le panel rend
 * 0,00 là où la semaine rend 274,16 € pour les mêmes pièces). [valeur, source]
 */
function invValeurPerdue(int $sid, array $l): array
{
    if ((float) $l['caPerdu'] > 0) { return [round((float) $l['caPerdu'], 2), 'panel']; }
    $prix = null;
    if (function_exists('cataloguePrixMagasin')) { $prix = cataloguePrixMagasin($sid)[$l['pid']] ?? null; }
    if ($prix === null && function_exists('cataloguePrix')) { $prix = cataloguePrix()[$l['pid']] ?? null; }
    if ($prix !== null && (float) $prix > 0) { return [round((float) $prix * (float) $l['pieces'], 2), 'catalogue']; }
    return [0.0, 'inconnue'];
}

/** Les pièces vendues du jour par produit, dans le relevé gravé (jamais une lecture de plus). */
function invVendusJour(int $sid, string $j): array
{
    if (!function_exists('svProduitsJour')) { return []; }
    $cout = 0;
    $p = svProduitsJour($sid, $j, $cout, 0);
    if ($p === null) { return []; }
    $v = [];
    foreach ($p as $lst) {
        foreach ((array) $lst as $k => $x) { $pid = (int) $k; $v[$pid] = ($v[$pid] ?? 0.0) + (float) ($x[1] ?? 0); }
    }
    return $v;
}

/**
 * Le bilan d'un magasin sur une fenêtre : les produits (coût net, motif, vendus
 * du jour), les totaux, la répartition par motif. null si le panel est muet.
 */
function invBilan(int $sid, string $du, string $au, ?array $lignes): ?array
{
    if ($lignes === null) { return null; }
    $vendus = $du === $au ? invVendusJour($sid, $du) : [];
    $prods = []; $pieces = 0.0; $cout = 0.0; $brut = 0.0; $perdu = 0.0; $motifs = []; $sources = []; $sansCout = 0; $auCatalogue = 0; $perduCatalogue = 0;
    foreach ($lignes as $l) {
        [$c, $src] = invCoutNet($sid, $l);
        [$v, $vSrc] = invValeurPerdue($sid, $l);
        $sources[$src] = ($sources[$src] ?? 0) + 1;
        if ($c <= 0) { $sansCout++; } elseif ((float) $l['coutBrut'] <= 0) { $auCatalogue++; }
        if ($vSrc === 'catalogue') { $perduCatalogue++; }
        $m = $l['motif'];
        $motifs[$m] = $motifs[$m] ?? ['motif' => $m, 'lib' => invMotif($m), 'pieces' => 0.0, 'cout' => 0.0];
        $motifs[$m]['pieces'] += $l['pieces']; $motifs[$m]['cout'] += $c;
        $r = ['pid' => $l['pid'], 'nom' => function_exists('svNomProduit') ? svNomProduit($l['pid'], $l['nom']) : $l['nom'], 'categorie' => $l['cat'],
            'pieces' => round($l['pieces'], 1), 'cout' => $c, 'coutSource' => $src, 'coutBrut' => $l['coutBrut'], 'caPerdu' => $v, 'caPerduSource' => $vSrc, 'motif' => $m, 'motifLib' => invMotif($m),
            'motifPieces' => round($l['motifN'], 1)];
        if ($vendus !== []) {
            $vd = $vendus[$l['pid']] ?? 0.0;
            $r['vendus'] = round($vd, 1);
            $r['taux'] = $vd + $l['pieces'] > 0 ? round(100 * $l['pieces'] / ($vd + $l['pieces']), 1) : null;
        }
        $prods[] = $r;
        $pieces += $l['pieces']; $cout += $c; $brut += $l['coutBrut']; $perdu += $v;
    }
    usort($prods, static fn ($a, $b) => $b['cout'] <=> $a['cout'] ?: $b['pieces'] <=> $a['pieces']);
    $pm = array_values($motifs);
    usort($pm, static fn ($a, $b) => $b['cout'] <=> $a['cout']);
    foreach ($pm as &$x) { $x['pieces'] = round($x['pieces'], 1); $x['cout'] = round($x['cout'], 2); } unset($x);
    arsort($sources);
    return ['declare' => $prods !== [], 'pieces' => round($pieces, 1), 'cout' => round($cout, 2), 'coutBrut' => round($brut, 2), 'caPerdu' => round($perdu, 2),
        'produits' => $prods, 'parMotif' => $pm, 'references' => count($prods),
        // Ce que le chiffre ne dit pas seul : les références sans aucun coût connu (comptées
        // zéro), celles prises au coût de recette actuel faute de coût au panel, et les
        // valeurs de vente reconstituées au prix du catalogue.
        'sansCout' => $sansCout, 'auCatalogue' => $auCatalogue, 'perduCatalogue' => $perduCatalogue,
        'coutSource' => $sources === [] ? null : (string) array_key_first($sources)];
}

/**
 * Les invendus de plusieurs magasins pour le P&L : [sid => [cout, pieces, declare, source]].
 * `cout` est null quand le panel n'a pas répondu — le résultat ne le retranche pas et le dit.
 */
function invCoutsMagasins(array $sids, string $du, string $au): array
{
    $out = [];
    foreach (invLignesMagasins($sids, $du, $au) as $sid => $l) {
        $b = invBilan((int) $sid, $du, $au, $l);
        $out[(int) $sid] = $b === null ? ['cout' => null, 'pieces' => null, 'declare' => null, 'source' => 'panel muet']
            : ['cout' => $b['cout'], 'pieces' => $b['pieces'], 'declare' => $b['declare'], 'source' => $b['declare'] ? 'coût de production des pièces jetées' . ($b['coutSource'] === 'panel' ? '' : ' · brut ÷ 1,06') : 'rien déclaré au panel'];
    }
    return $out;
}

/** Le CA de chaque magasin sur une fenêtre, en une lecture (/consultant/shops/sales-kpis) — [sid => ca], vide si le panel est muet. */
function invCaMagasins(string $du, string $au): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured() || !method_exists('PanelApi', 'shopsSalesKpisEntre')) { return []; }
    $r = PanelApi::shopsSalesKpisEntre($du, $au);
    if (!is_array($r)) { return []; }
    $liste = function_exists('analyseListe') ? analyseListe($r) : (array_is_list($r) ? $r : []);
    $out = [];
    foreach ($liste as $x) {
        if (!is_array($x)) { continue; }
        $id = 0;
        foreach (['shop_id', 'id_shop', 'id'] as $c) { if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; } }
        if ($id <= 0) { continue; }
        foreach (['ca', 'turnover', 'revenue', 'income', 'net_turnover'] as $c) { if (isset($x[$c]) && is_numeric($x[$c])) { $out[$id] = (float) $x[$c]; break; } }
    }
    return $out;
}

/** Le texte, toujours le même, sur ce que le panel ne rend pas. */
function invReport(): array
{
    return ['dispo' => false, 'motif' => 'Les reports au lendemain ne se lisent pas dans le panel : la caisse les enregistre comme une production du matin (carryover) et aucune route ne les rend.'];
}

/**
 * GET /exploitation/invendus?shop=4&date=YYYY-MM-DD — la carte du jour ;
 * GET /exploitation/invendus?shop=4&du=&au= — la semaine, le mois ;
 * sans magasin : le réseau, magasin par magasin.
 */
function ep_exploitation_invendus(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    $periode = preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) && $du <= $au;
    // Le cockpit parle en périodes glissantes, comme Offres et canaux : jour, 7 ou 30 jours.
    $per = (string) ($_GET['periode'] ?? '');
    if (!$periode && in_array($per, ['7', '30'], true)) { $au = $auj; $du = date('Y-m-d', strtotime($auj . ' -' . ((int) $per - 1) . ' days')); $periode = true; }
    if ($periode) { if ($au > $auj) { $au = $auj; } if ($du > $au) { $du = $au; } }
    else { $du = (string) ($_GET['date'] ?? $auj); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || $du > $auj) { $du = $auj; } $au = $du; }
    @set_time_limit(60);
    $mags = function_exists('jcMagasins') ? jcMagasins() : [];
    $base = ($periode ? ['du' => $du, 'au' => $au] : ['date' => $du]) + ['report' => invReport(),
        'source' => '/shops/{id}/products/waste du panel · coût de production net : coût de recette du panel par magasin, sinon brut ÷ 1,06'];
    if ($sid > 0) {
        $b = invBilan($sid, $du, $au, invLignes($sid, $du, $au));
        return ['shop' => $sid, 'magasin' => $mags[(string) $sid] ?? null, 'lu' => $b !== null] + ($b ?? ['declare' => null, 'pieces' => null, 'cout' => null, 'coutBrut' => null, 'caPerdu' => null, 'produits' => [], 'parMotif' => [], 'references' => 0, 'coutSource' => null]) + $base;
    }
    // Le réseau : chaque magasin, son CA sur la fenêtre (pour la part), les produits
    // additionnés d'un magasin à l'autre, les motifs du réseau.
    $sids = array_map('intval', array_keys($mags));
    $lignes = invLignesMagasins($sids, $du, $au);
    $caM = invCaMagasins($du, $au);
    $out = []; $t = ['pieces' => 0.0, 'cout' => 0.0, 'caPerdu' => 0.0, 'declarent' => 0, 'lus' => 0, 'ca' => 0.0, 'caLus' => 0, 'sansCout' => 0, 'auCatalogue' => 0];
    $agg = []; $motifs = [];
    foreach ($sids as $s) {
        $b = invBilan($s, $du, $au, $lignes[$s] ?? null);
        $ca = $caM[$s] ?? null;
        $out[] = ['shopId' => (string) $s, 'magasin' => $mags[(string) $s] ?? (string) $s, 'lu' => $b !== null, 'declare' => $b['declare'] ?? null,
            'pieces' => $b['pieces'] ?? null, 'cout' => $b['cout'] ?? null, 'caPerdu' => $b['caPerdu'] ?? null, 'references' => $b['references'] ?? 0, 'parMotif' => $b['parMotif'] ?? [],
            'sansCout' => $b['sansCout'] ?? 0, 'auCatalogue' => $b['auCatalogue'] ?? 0,
            'ca' => $ca !== null ? round($ca, 2) : null, 'part' => ($b !== null && $ca !== null && $ca > 0) ? round(100 * $b['cout'] / $ca, 2) : null];
        if ($ca !== null) { $t['ca'] += $ca; $t['caLus']++; }
        if ($b === null) { continue; }
        $t['lus']++; $t['pieces'] += $b['pieces']; $t['cout'] += $b['cout']; $t['caPerdu'] += $b['caPerdu']; $t['sansCout'] += $b['sansCout']; $t['auCatalogue'] += $b['auCatalogue'];
        if ($b['declare']) { $t['declarent']++; }
        foreach ($b['parMotif'] as $m) {
            $motifs[$m['motif']] = $motifs[$m['motif']] ?? ['motif' => $m['motif'], 'lib' => $m['lib'], 'pieces' => 0.0, 'cout' => 0.0];
            $motifs[$m['motif']]['pieces'] += $m['pieces']; $motifs[$m['motif']]['cout'] += $m['cout'];
        }
        foreach ($b['produits'] as $p) {
            $e = $agg[$p['pid']] ?? ['pid' => $p['pid'], 'nom' => $p['nom'], 'categorie' => $p['categorie'], 'pieces' => 0.0, 'cout' => 0.0, 'caPerdu' => 0.0, 'motifs' => [], 'parMagasin' => []];
            $e['pieces'] += $p['pieces']; $e['cout'] += $p['cout']; $e['caPerdu'] += $p['caPerdu'];
            $e['motifs'][$p['motif']] = ($e['motifs'][$p['motif']] ?? 0) + $p['pieces'];
            $e['parMagasin'][(string) $s] = round($p['pieces'], 1);
            $agg[$p['pid']] = $e;
        }
    }
    usort($out, static fn ($a, $b) => ($b['cout'] ?? -1) <=> ($a['cout'] ?? -1));
    $prods = [];
    foreach ($agg as $e) {
        arsort($e['motifs']);
        $m = (string) array_key_first($e['motifs']);
        $prods[] = ['pid' => $e['pid'], 'nom' => $e['nom'], 'categorie' => $e['categorie'], 'pieces' => round($e['pieces'], 1), 'cout' => round($e['cout'], 2), 'caPerdu' => round($e['caPerdu'], 2),
            'motif' => $m, 'motifLib' => invMotif($m), 'magasins' => count($e['parMagasin']), 'parMagasin' => $e['parMagasin']];
    }
    usort($prods, static fn ($a, $b) => $b['cout'] <=> $a['cout'] ?: $b['pieces'] <=> $a['pieces']);
    $pm = array_values($motifs);
    usort($pm, static fn ($a, $b) => $b['cout'] <=> $a['cout']);
    foreach ($pm as &$x) { $x['pieces'] = round($x['pieces'], 1); $x['cout'] = round($x['cout'], 2); } unset($x);
    return ['periode' => $periode ? (in_array($per, ['7', '30'], true) ? $per : 'bornes') : 'jour', 'magasins' => $out, 'produits' => array_slice($prods, 0, 30), 'parMotif' => $pm,
        'reseau' => ['magasins' => count($sids), 'lus' => $t['lus'], 'declarent' => $t['declarent'], 'pieces' => round($t['pieces'], 1), 'cout' => round($t['cout'], 2), 'caPerdu' => round($t['caPerdu'], 2),
            'references' => count($prods), 'sansCout' => $t['sansCout'], 'auCatalogue' => $t['auCatalogue'],
            'ca' => $t['caLus'] ? round($t['ca'], 2) : null, 'part' => ($t['caLus'] && $t['ca'] > 0) ? round(100 * $t['cout'] / $t['ca'], 2) : null, 'caMagasins' => $t['caLus']]] + $base;
}

/**
 * GET /exploitation/invendus/sonde?shop=4&date=YYYY-MM-DD[&q=/shops/4/…]
 * La réponse brute de /shops/{id}/products/waste pour le jour, les motifs
 * connus du journal des mouvements, et l'existence de quelques routes
 * candidates (stock, report, motifs). Produits seulement : jamais un client.
 */
/** Rien de sensible dans une sonde : la fiche magasin se réduit à l'id et au nom, et toute clé qui sent l'identifiant disparaît. */
function invScrub(mixed $v): mixed
{
    if (!is_array($v)) { return $v; }
    if (isset($v['shop']) && is_array($v['shop'])) { $v['shop'] = ['id' => $v['shop']['id'] ?? null, 'nom' => $v['shop']['representative_name'] ?? ($v['shop']['name'] ?? null)]; }
    $o = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && preg_match('/pass|secret|token|mail|phone|iban|bank|street|address|zip|city|vat|tva|login|owner/i', $k)) { continue; }
        $o[$k] = is_array($x) ? invScrub($x) : $x;
    }
    return $o;
}

function ep_exploitation_invendus_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 4);
    $date = (string) ($_GET['date'] ?? date('Y-m-d', strtotime('-1 day')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d', strtotime('-1 day')); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    @set_time_limit(120);
    $out = ['shop' => $sid, 'date' => $date, 'prefixe' => (string) (parse_url((string) (PanelApi::config()['base'] ?? ''), PHP_URL_PATH) ?: '/')];
    $q = http_build_query(['from' => $date, 'date_from' => $date, 'to' => $date, 'date_to' => $date]);
    $w = PanelApi::sondeGet('/shops/' . $sid . '/products/waste?' . $q, 20);
    $out['waste'] = ['code' => $w['code'], 'corps' => invScrub($w['corps'])];
    $out['mouvements'] = null;
    try {
        $out['mouvements'] = Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */ movement_type, reason, COUNT(*) n, MIN(DATE(created_at)) premier, MAX(DATE(created_at)) dernier FROM product_movement WHERE created_at >= ? GROUP BY movement_type, reason ORDER BY movement_type, n DESC", ['2026-06-01 00:00:00']);
        $out['mouvementsColonnes'] = array_keys(Db::rows('SELECT * FROM product_movement ORDER BY id DESC LIMIT 1')[0] ?? []);
        $out['mouvementsDernier'] = Db::rows("SELECT * FROM product_movement WHERE id_shop = ? AND movement_type = 'WASTE' ORDER BY id DESC LIMIT 3", [$sid]);
    } catch (PDOException $e) { $out['mouvementsErreur'] = 'journal des mouvements indisponible'; }
    $cands = [];
    foreach (['/shops/{s}/products/unsold?date={d}', '/shops/{s}/products/leftovers?date={d}', '/shops/{s}/products/stock?date={d}', '/shops/{s}/stock?date={d}',
        '/shops/{s}/inventory?date={d}', '/shops/{s}/products/inventory?date={d}', '/shops/{s}/product-movements?date={d}', '/shops/{s}/products/movements?date={d}',
        '/shops/{s}/movements?date={d}', '/shops/{s}/products/production?date={d}', '/shops/{s}/productions?date={d}', '/shops/{s}/products/transfers?date={d}',
        '/shops/{s}/transfers?date={d}', '/shops/{s}/products/carry-over?date={d}', '/shops/{s}/closing?date={d}', '/shops/{s}/closings?date={d}',
        '/shops/{s}/products/waste/reasons?from={d}&to={d}', '/shops/{s}/products/waste-details?from={d}&to={d}', '/shops/{s}/waste?from={d}&to={d}',
        '/shops/{s}/product-waste?from={d}&to={d}', '/shops/{s}/products/waste?from={d}&to={d}&group_by=reason', '/waste-reasons', '/product-waste-reasons', '/products/waste-reasons',
        '/shops/{s}/waste-reasons', '/shops/{s}/products/waste/summary?from={d}&to={d}'] as $c) { $cands[] = $c; }
    // `q` : d'autres chemins à essayer, séparés par des virgules — produits, stock, mouvements, documentation ; jamais un client.
    $extra = (string) ($_GET['q'] ?? '');
    if ($extra !== '') {
        $cands = [];
        foreach (array_slice(array_filter(array_map('trim', explode(',', $extra))), 0, 30) as $e) {
            if (preg_match('#^/(shops/\d+/(products|stock|inventory|movements|product-movements|productions?|transfers|closings?|waste|product-waste|reports?|day-end|end-of-day)[A-Za-z0-9_\-/.]*|[a-z\-]*waste[a-z\-/]*|(\.\./){0,3}(docs?|api-docs|openapi|swagger|redoc|documentation|schema)[A-Za-z0-9_\-/.]*)(\?[A-Za-z0-9_=&\-%.]*)?$#', $e)) { $cands[] = $e; }
        }
    }
    // `texte` : une page lue telle quelle (documentation) ; `spec` : un document OpenAPI dont on liste les chemins.
    $texte = (string) ($_GET['texte'] ?? '');
    if ($texte !== '' && preg_match('#^/(\.\./){0,3}(docs?|api-docs|openapi|swagger|redoc|documentation|schema)[A-Za-z0-9_\-/.]*(\?[A-Za-z0-9_=&\-%.]*)?$#', $texte)) {
        $t = PanelApi::sondeTexte($texte);
        $out['texte'] = ['chemin' => $texte, 'code' => $t['code'], 'longueur' => $t['texte'] === null ? null : mb_strlen($t['texte']),
            'urls' => $t['texte'] === null ? [] : array_values(array_unique(array_slice(preg_match_all('#["\']([^"\' ]*(?:swagger|openapi|spec|api-docs|\.json|\.yaml)[^"\' ]*)["\']#i', $t['texte'], $m) ? $m[1] : [], 0, 40))),
            'extrait' => $t['texte'] === null ? null : mb_substr($t['texte'], 0, 3000)];
    }
    $out['candidats'] = [];
    foreach ($cands as $c) {
        $p = str_replace(['{s}', '{d}'], [(string) $sid, $date], $c);
        $r = PanelApi::sondeGet($p, 8);
        $b = $r['corps'];
        if (is_string($b) && preg_match('/(href|location)=["\']?([^"\' >]+)/i', $b, $mm)) { $b = ['redirection' => $mm[2]]; }
        $ap = null;
        if (is_array($b) && isset($b['paths']) && is_array($b['paths'])) {
            // Un document OpenAPI : la liste des chemins, et le détail de ceux qui parlent de produits, de pertes, de stock.
            $det = [];
            foreach ($b['paths'] as $ch => $ops) {
                if (!preg_match('/waste|product|stock|movement|carry|unsold|production|inventor|loss|leftover|closing|report/i', (string) $ch) || !is_array($ops)) { continue; }
                foreach ($ops as $meth => $op) {
                    if (!is_array($op)) { continue; }
                    $det[$ch . ' ' . strtoupper((string) $meth)] = ['resume' => $op['summary'] ?? ($op['description'] ?? null), 'params' => array_map(static fn ($x) => is_array($x) ? ($x['name'] ?? '') . (isset($x['in']) ? ' (' . $x['in'] . ')' : '') : $x, (array) ($op['parameters'] ?? []))];
                }
            }
            $ap = ['openapi' => $b['openapi'] ?? ($b['swagger'] ?? null), 'chemins' => array_keys($b['paths']), 'detail' => $det];
        } elseif (is_array($b)) {
            if (array_is_list($b)) { $ap = ['liste' => count($b), 'premier' => is_array($b[0] ?? null) ? array_slice($b[0], 0, 20, true) : ($b[0] ?? null)]; }
            else { $ap = ['cles' => array_slice(array_keys($b), 0, 20), 'extrait' => array_map(static fn ($v) => is_array($v) ? (array_is_list($v) ? ['liste' => count($v), 'premier' => $v[0] ?? null] : array_slice($v, 0, 12, true)) : $v, array_slice($b, 0, 8, true))]; }
        } elseif ($b !== null) { $ap = ['brut' => mb_substr((string) $b, 0, 300)]; }
        $out['candidats'][] = ['chemin' => $p, 'code' => $r['code'], 'erreur' => $r['erreur'] !== null ? mb_substr((string) $r['erreur'], 0, 200) : null, 'apercu' => invScrub($ap)];
    }
    return $out;
}
