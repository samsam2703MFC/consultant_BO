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
        if (is_string($k) && (preg_match('/pass|secret|token|mail|phone|iban|bank|street|address|zip|city|vat|tva|login|owner|client|customer|first_?name|last_?name|company|note|comment|remark|invoice|person|contact|delivery_info|recipient/i', $k) || preg_match('/^(name|surname|nom)$/i', $k))) { continue; }
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
            // Produits, stock, production, périodes de vente : jamais un client ni un employé.
            if (preg_match('#^/(shops/\d+/(products|stock|inventory|movements|product-movements|productions?|transfers|closings?|waste|product-waste|reports?|day-end|end-of-day|statistics/production-planning|product-availability-periods)[A-Za-z0-9_\-/.]*|[a-z\-]*waste[a-z\-/]*|admin/sales-dayparts[A-Za-z0-9_\-/]*|franchisee-shop/\d+/client-orders/\d{4}-\d{2}-\d{2}/products|client-orders?/\d+(/products)?|shops/production-areas[A-Za-z0-9_\-/]*|production-areas[A-Za-z0-9_\-/]*|(recipe-)?preparation-types[A-Za-z0-9_\-/]*|product-availability-periods[A-Za-z0-9_\-/]*|products/\d+/availability-periods|(\.\./){0,3}(docs?|api-docs|openapi|swagger|redoc|documentation|schema)[A-Za-z0-9_\-/.]*|consultant/shops(/\d+)?/(products|product-movements|movements|waste|evidences?|pnl|statistics)[A-Za-z0-9_\-/.]*|(product-)?movements[A-Za-z0-9_\-/.]*|evidences?[A-Za-z0-9_\-/.]*|products/(evidences?|movements|waste)[A-Za-z0-9_\-/.]*)(\?[A-Za-z0-9_=&\-%.,]*)?$#', $e)) { $cands[] = $e; }
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
            else { $ap = ['cles' => array_slice(array_keys($b), 0, 80), 'extrait' => array_map(static fn ($v) => is_array($v) ? (array_is_list($v) ? ['liste' => count($v), 'premier' => $v[0] ?? null] : array_slice($v, 0, 12, true)) : $v, array_slice($b, 0, 8, true)),
                'listes' => array_map(static fn ($v) => ['liste' => count($v), 'premier' => $v[0] ?? null], array_filter($b, static fn ($v) => is_array($v) && array_is_list($v)))]; }
        } elseif ($b !== null) { $ap = ['brut' => mb_substr((string) $b, 0, 300)]; }
        $out['candidats'][] = ['chemin' => $p, 'code' => $r['code'], 'erreur' => $r['erreur'] !== null ? mb_substr((string) $r['erreur'], 0, 200) : null, 'apercu' => invScrub($ap)];
    }
    // `tables=1` : les tables de la base partagée qui parlent de mouvements, de pertes ou de caisse — leur
    // nom, leur taille estimée, leurs colonnes, leur dernière date. Rien d'autre qu'information_schema et un MAX().
    if (!empty($_GET['tables'])) {
        $out['tables'] = [];
        try {
            $ts = Db::rows("SELECT TABLE_NAME n, TABLE_ROWS lignes, UPDATE_TIME maj, CREATE_TIME cree FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '%movement%' OR TABLE_NAME LIKE '%waste%' OR TABLE_NAME LIKE '%evidence%' OR TABLE_NAME LIKE '%transaction%'
                                OR TABLE_NAME LIKE '%receipt%' OR TABLE_NAME LIKE '%ticket%' OR TABLE_NAME LIKE '%sale%' OR TABLE_NAME LIKE '%pos%' OR TABLE_NAME LIKE '%stock%' OR TABLE_NAME LIKE '%production%')
                             ORDER BY TABLE_NAME");
            foreach ($ts as $t) {
                $n = (string) $t['n'];
                $e = ['table' => $n, 'lignes' => $t['lignes'] === null ? null : (int) $t['lignes'], 'maj' => $t['maj'], 'cree' => $t['cree'], 'colonnes' => [], 'derniere' => null];
                try {
                    $cols = array_map(static fn ($c) => (string) $c['COLUMN_NAME'], Db::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$n]));
                    $e['colonnes'] = array_slice($cols, 0, 40);
                    foreach (['created_at', 'insert_timestamp', 'updated_at', 'date', 'created', 'timestamp'] as $c) {
                        if (in_array($c, $cols, true)) { $r = Db::row('SELECT /*+ MAX_EXECUTION_TIME(4000) */ MAX(`' . $c . '`) d FROM `' . $n . '`'); $e['derniere'] = [$c, $r['d'] ?? null]; break; }
                    }
                } catch (Throwable $ex) { $e['erreur'] = 'colonnes illisibles'; }
                $out['tables'][] = $e;
            }
        } catch (Throwable $ex) { $out['tablesErreur'] = 'information_schema indisponible'; }
    }
    return $out;
}

/* ---------- le détail des saisies : l'heure et l'opérateur de chaque pièce jetée (09/10/2026) ---------- */

const INV_JOURNAL_TTL = 600;   // jusqu'où va le journal : relu toutes les dix minutes

/** « Prénom N. » depuis une fiche du panel, quelle que soit la forme de ses champs ; jamais plus que ça. */
function invNomCourt(array $em): string
{
    $p = trim((string) ($em['first_name'] ?? $em['firstname'] ?? $em['firstName'] ?? ''));
    $n = trim((string) ($em['last_name'] ?? $em['lastname'] ?? $em['lastName'] ?? $em['surname'] ?? ''));
    if ($p === '' && $n === '') {
        $full = trim((string) ($em['name'] ?? $em['display_name'] ?? $em['full_name'] ?? $em['username'] ?? ''));
        $parts = $full === '' ? [] : (preg_split('/\s+/', $full) ?: []);
        $p = (string) ($parts[0] ?? ''); $n = count($parts) > 1 ? (string) end($parts) : '';
    }
    if ($p === '') { return $n !== '' ? $n : 'opérateur ' . (int) ($em['id'] ?? 0); }
    return $p . ($n !== '' ? ' ' . mb_strtoupper(mb_substr($n, 0, 1)) . '.' : '');
}

/**
 * Les opérateurs d'un magasin, id → nom court, depuis /shops/{id}/employees du panel (gardé un jour). Rien
 * d'autre que le nom court n'est retenu : la route porte des données personnelles qui n'ont rien à faire ici.
 */
function invOperateurs(int $sid): array
{
    $cle = 'inv:ops:' . $sid;
    $c = setting($cle);
    $ancien = is_array($c) && isset($c['v']) && is_array($c['v']) ? $c['v'] : [];
    if ($ancien !== [] && (int) ($c['ts'] ?? 0) > time() - 86400) { return $ancien; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return $ancien; }
    $r = PanelApi::get('/shops/' . $sid . '/employees');
    $liste = is_array($r) ? (function_exists('analyseListe') ? analyseListe($r) : (array_is_list($r) ? $r : [])) : [];
    $out = [];
    foreach ($liste as $em) {
        if (!is_array($em)) { continue; }
        $id = (int) ($em['id'] ?? 0);
        if ($id > 0) { $out[(string) $id] = invNomCourt($em); }
    }
    if ($out === []) { return $ancien; }
    svGrave($cle, ['ts' => time(), 'v' => $out]);
    return $out;
}

/** Jusqu'où va le journal des mouvements pour ce magasin (dernière pièce jetée écrite) ; null s'il est vide ou illisible. */
function invJournalDerniere(int $sid): ?string
{
    $cle = 'inv:journal:' . $sid;
    $c = setting($cle);
    if (is_array($c) && array_key_exists('d', $c) && (int) ($c['ts'] ?? 0) > time() - INV_JOURNAL_TTL) { return $c['d'] === null ? null : (string) $c['d']; }
    try {
        $r = Db::row("SELECT /*+ MAX_EXECUTION_TIME(4000) */ MAX(created_at) d FROM product_movement WHERE id_shop = ? AND movement_type = 'WASTE'", [$sid]);
    } catch (Throwable $e) { return null; }
    $d = isset($r['d']) && $r['d'] !== null && $r['d'] !== '' ? (string) $r['d'] : null;
    svGrave($cle, ['ts' => time(), 'd' => $d]);
    return $d;
}

/**
 * Les saisies de poubelle du journal sur une fenêtre, dans l'ordre du temps : heure, opérateur, produit, pièces,
 * motif. null quand le journal est illisible (table absente, base en défaut).
 */
function invSaisies(int $sid, string $du, string $au): ?array
{
    try {
        $rows = Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */ id, id_employee, id_product, quantity, reason, source, created_at FROM product_movement
                           WHERE id_shop = ? AND movement_type = 'WASTE' AND created_at >= ? AND created_at < ? ORDER BY created_at, id LIMIT 2000",
            [$sid, $du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00']);
    } catch (Throwable $e) { return null; }
    $ops = $rows === [] ? [] : invOperateurs($sid);
    $out = [];
    foreach ($rows as $r) {
        $pid = (int) ($r['id_product'] ?? 0); $q = (float) ($r['quantity'] ?? 0);
        if ($q <= 0) { continue; }
        $ts = (string) ($r['created_at'] ?? ''); $ide = (int) ($r['id_employee'] ?? 0);
        $m = strtolower(trim((string) ($r['reason'] ?? '')));
        $out[] = ['id' => (int) ($r['id'] ?? 0), 'le' => substr($ts, 0, 10), 'heure' => substr($ts, 11, 5),
            'operateurId' => $ide > 0 ? $ide : null, 'operateur' => $ide > 0 ? ($ops[(string) $ide] ?? ('opérateur ' . $ide)) : null,
            'pid' => $pid, 'produit' => function_exists('svNomProduit') ? svNomProduit($pid, '') : '', 'categorie' => '',
            'pieces' => round($q, 1), 'motif' => $m, 'motifLib' => invMotif($m), 'source' => (string) ($r['source'] ?? '')];
    }
    return $out;
}

/**
 * GET /exploitation/invendus/detail?shop=4&date=YYYY-MM-DD (ou &du=&au=) — le détail des pièces jetées d'un
 * magasin : le total par produit du panel (comme la carte), et chaque saisie de caisse — heure, opérateur,
 * produit, quantité, motif — quand le journal des mouvements de la base partagée couvre la fenêtre. Quand il
 * s'arrête avant, la réponse le dit (`journal`) plutôt que de laisser croire à une journée sans saisie.
 */
function ep_exploitation_invendus_detail(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    $periode = preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) && $du <= $au;
    if ($periode) { if ($au > $auj) { $au = $auj; } if ($du > $au) { $du = $au; } }
    else { $du = (string) ($_GET['date'] ?? $auj); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || $du > $auj) { $du = $auj; } $au = $du; }
    @set_time_limit(60);
    $mags = function_exists('jcMagasins') ? jcMagasins() : [];
    $b = invBilan($sid, $du, $au, invLignes($sid, $du, $au));
    $saisies = invSaisies($sid, $du, $au);
    $derniere = invJournalDerniere($sid);
    $dJ = $derniere !== null ? substr($derniere, 0, 10) : null;
    // Les noms et catégories que le journal ne porte pas : ceux du total par produit du panel.
    $noms = [];
    foreach ($b['produits'] ?? [] as $p) { $noms[(int) $p['pid']] = [(string) $p['nom'], (string) $p['categorie']]; }
    $parOp = []; $parHeure = []; $total = 0.0;
    foreach ($saisies ?? [] as $i => $s) {
        if (isset($noms[$s['pid']])) { if ($s['produit'] === '') { $s['produit'] = $noms[$s['pid']][0]; } $s['categorie'] = $noms[$s['pid']][1]; }
        if ($s['produit'] === '') { $s['produit'] = 'produit ' . $s['pid']; }
        $saisies[$i] = $s;
        $k = $s['operateur'] ?? '—';
        $parOp[$k] = $parOp[$k] ?? ['operateur' => $s['operateur'], 'pieces' => 0.0, 'saisies' => 0];
        $parOp[$k]['pieces'] += $s['pieces']; $parOp[$k]['saisies']++;
        $h = substr($s['heure'], 0, 2) . ' h';
        $parHeure[$h] = ($parHeure[$h] ?? 0.0) + $s['pieces'];
        $total += $s['pieces'];
    }
    $po = array_values($parOp);
    usort($po, static fn ($x, $y) => $y['pieces'] <=> $x['pieces']);
    foreach ($po as &$x) { $x['pieces'] = round($x['pieces'], 1); } unset($x);
    $ph = []; foreach ($parHeure as $h => $q) { $ph[] = ['heure' => $h, 'pieces' => round($q, 1)]; }
    if ($saisies === null) {
        $journal = ['dispo' => false, 'derniere' => null, 'couvre' => false,
            'motif' => 'Le journal des mouvements de la base partagée n’est pas lisible : seul le total par produit du panel est montré, sans l’heure ni l’opérateur.'];
    } elseif ($dJ === null || $dJ < $du) {
        $journal = ['dispo' => true, 'derniere' => $derniere, 'couvre' => false,
            'motif' => ($dJ === null ? 'Le journal des mouvements de la base partagée n’a aucune saisie pour ce magasin' : 'Le journal des mouvements de la base partagée s’arrête au ' . date('d/m/Y', strtotime($dJ)) . ' pour ce magasin')
                . ' : l’heure et l’opérateur de chaque saisie ne sont pas connus ' . ($periode ? 'sur cette période' : 'ce jour') . ', le panel ne rend qu’un total par produit.'];
    } else {
        $journal = ['dispo' => true, 'derniere' => $derniere, 'couvre' => $dJ >= $au,
            'motif' => $dJ >= $au ? '' : 'Le journal des mouvements s’arrête au ' . date('d/m/Y', strtotime($dJ)) . ' : les saisies des jours suivants ne sont pas connues.'];
    }
    return ['shop' => $sid, 'magasin' => $mags[(string) $sid] ?? null] + ($periode ? ['du' => $du, 'au' => $au] : ['date' => $du])
        + ['lu' => $b !== null, 'declare' => $b['declare'] ?? null, 'pieces' => $b['pieces'] ?? null, 'cout' => $b['cout'] ?? null, 'caPerdu' => $b['caPerdu'] ?? null,
            'references' => $b['references'] ?? 0, 'produits' => $b['produits'] ?? [], 'parMotif' => $b['parMotif'] ?? [],
            'saisies' => $saisies ?? [], 'saisiesPieces' => round($total, 1), 'parOperateur' => $po, 'parHeure' => $ph, 'journal' => $journal, 'report' => invReport(),
            'remarques' => invRemarques($sid, $du, $au), 'motifsQualite' => INV_MOTIFS_QUALITE,
            'source' => 'total par produit : /shops/{id}/products/waste du panel · saisies : journal product_movement de la base partagée (heure, opérateur, quantité, motif) · opérateurs : /shops/{id}/employees, nom court seulement'];
}

/* ---------- agir sur une pièce jetée pour un problème de qualité (09/10/2026) ---------- */

// Les motifs du panel qui disent un problème de qualité, et non un invendu de fin de journée.
const INV_MOTIFS_QUALITE = ['quality', 'damage'];

/** La table des remarques faites aux opérateurs : leurs évaluations, ligne par ligne. */
function ensureOperateurRemarques(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_operateur_remarque (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NOT NULL,
        employe_id INT NULL,
        employe_nom VARCHAR(80) NOT NULL DEFAULT \'\',
        le DATE NOT NULL,
        heure VARCHAR(5) NULL,
        produit_id INT NULL,
        produit_nom VARCHAR(200) NOT NULL DEFAULT \'\',
        pieces DECIMAL(10,1) NULL,
        motif VARCHAR(40) NOT NULL DEFAULT \'\',
        texte VARCHAR(1000) NOT NULL,
        source VARCHAR(40) NOT NULL DEFAULT \'invendus\',
        saisie_id BIGINT NULL,
        auteur VARCHAR(60) NOT NULL DEFAULT \'\',
        cree_le DATETIME NOT NULL,
        KEY idx_shop_le (shop_id, le), KEY idx_emp (employe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Une ligne de la table → ce que l'écran en montre. */
function invRemarqueDe(array $r): array
{
    return ['id' => (int) $r['id'], 'employeId' => $r['employe_id'] !== null ? (int) $r['employe_id'] : null, 'employe' => (string) $r['employe_nom'],
        'le' => substr((string) $r['le'], 0, 10), 'heure' => $r['heure'] !== null ? (string) $r['heure'] : null,
        'pid' => $r['produit_id'] !== null ? (int) $r['produit_id'] : null, 'produit' => (string) $r['produit_nom'],
        'pieces' => $r['pieces'] !== null ? (float) $r['pieces'] : null, 'motif' => (string) $r['motif'], 'motifLib' => invMotif((string) $r['motif']),
        'texte' => (string) $r['texte'], 'source' => (string) $r['source'], 'saisieId' => $r['saisie_id'] !== null ? (int) $r['saisie_id'] : null,
        'auteur' => (string) $r['auteur'], 'creeLe' => (string) $r['cree_le']];
}

/** Les remarques d'un magasin sur une fenêtre, ou d'une personne ; [] si la table manque ou si la base est en défaut. */
function invRemarques(int $sid, ?string $du = null, ?string $au = null, ?int $employe = null, int $max = 500): array
{
    try {
        ensureOperateurRemarques();
        $w = ['shop_id = ?']; $p = [$sid];
        if ($du !== null) { $w[] = 'le >= ?'; $p[] = $du; }
        if ($au !== null) { $w[] = 'le <= ?'; $p[] = $au; }
        if ($employe !== null) { $w[] = 'employe_id = ?'; $p[] = $employe; }
        $rows = Db::rows('SELECT * FROM ceo_operateur_remarque WHERE ' . implode(' AND ', $w) . ' ORDER BY le DESC, id DESC LIMIT ' . max(1, min(2000, $max)), $p);
    } catch (Throwable $e) { return []; }
    return array_map('invRemarqueDe', $rows);
}

/** Qui a produit la référence ce jour-là, d'après le journal (la production déclarée en caisse) ; null quand il ne le dit pas. */
function invProducteur(int $sid, int $pid, string $date): ?array
{
    try {
        $r = Db::row("SELECT /*+ MAX_EXECUTION_TIME(4000) */ id_employee, created_at FROM product_movement WHERE id_shop = ? AND id_product = ? AND movement_type = 'PRODUCTION' AND created_at >= ? AND created_at < ? ORDER BY created_at DESC LIMIT 1",
            [$sid, $pid, $date . ' 00:00:00', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00']);
    } catch (Throwable $e) { return null; }
    $ide = (int) ($r['id_employee'] ?? 0);
    if ($ide <= 0) { return null; }
    return ['id' => $ide, 'nom' => invOperateurs($sid)[(string) $ide] ?? ('opérateur ' . $ide), 'le' => substr((string) ($r['created_at'] ?? ''), 0, 16), 'source' => 'production déclarée en caisse ce jour'];
}

/**
 * GET /exploitation/invendus/actions?shop=4&date=YYYY-MM-DD&pid=… — de quoi agir sur une pièce jetée pour un
 * problème de qualité. La réclamation au fournisseur : les matières de la recette du produit (une seule pour un
 * produit acheté fini), rapprochées des références réclamables du panel, les livraisons et les motifs. La remarque
 * à l'opérateur : qui a produit la référence ce jour-là d'après le journal, les opérateurs du magasin, et les
 * remarques déjà faites sur ce produit ce jour.
 */
function ep_exploitation_invendus_actions(): array
{
    $sid = (int) ($_GET['shop'] ?? 0); $pid = (int) ($_GET['pid'] ?? 0);
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    if ($sid <= 0 || $pid <= 0) { http_response_code(400); return ['error' => 'shop et pid requis']; }
    @set_time_limit(60);
    $nom = function_exists('svNomProduit') ? svNomProduit($pid, '') : '';
    // 1. La recette : les matières du produit. Un produit acheté fini n'en a qu'une — lui-même.
    $recette = null; $lignes = [];
    if (function_exists('rpApi')) { try { $recette = rpApi($pid, $sid, false); } catch (Throwable $e) { $recette = null; } }
    if (is_array($recette)) {
        if ($nom === '') { $nom = (string) ($recette['nom'] ?? ''); }
        foreach ((array) ($recette['lignes'] ?? []) as $l) {
            if (!is_array($l) || (int) ($l['id'] ?? 0) <= 0) { continue; }
            $lignes[] = ['id' => (int) $l['id'], 'nom' => (string) ($l['nom'] ?? ''), 'part' => $l['part'] ?? null, 'fournisseur' => is_array($l['fournisseur'] ?? null) ? (string) ($l['fournisseur']['nom'] ?? '') : null];
        }
    }
    // 2. Les références réclamables, et parmi elles celles de la recette.
    $refs = function_exists('rcRefsCache') ? rcRefsCache($sid) : ['indispo' => true, 'motif' => 'module des réclamations absent'];
    $recl = ['indispo' => !empty($refs['indispo']), 'motif' => $refs['motif'] ?? null, 'motifs' => [], 'candidates' => [], 'acheteFini' => false,
        'matieres' => [], 'livraisons' => [], 'fournisseurs' => [], 'motifSuggere' => 'product_quality'];
    if (empty($refs['indispo'])) {
        $recl['motifs'] = array_values((array) ($refs['motifs'] ?? []));
        $parId = [];
        foreach ((array) ($refs['matieres'] ?? []) as $m) { if (is_array($m) && isset($m['id'])) { $parId[(string) $m['id']] = $m; } }
        foreach ($lignes as $l) { if (isset($parId[(string) $l['id']])) { $recl['candidates'][] = $parId[(string) $l['id']] + ['part' => $l['part']]; } }
        $recl['acheteFini'] = count($lignes) === 1 && $recl['candidates'] !== [];
        $recl['matieres'] = array_values((array) ($refs['matieres'] ?? []));
        $recl['fournisseurs'] = array_values((array) ($refs['fournisseurs'] ?? []));
        $recl['livraisons'] = array_values((array) ($refs['livraisons'] ?? []));
        $codes = array_map(static fn ($m) => (string) ($m['code'] ?? ''), $recl['motifs']);
        if (!in_array('product_quality', $codes, true) && $codes !== []) { $recl['motifSuggere'] = $codes[0]; }
    }
    // 3. La remarque : qui a produit, les opérateurs du magasin, ce qui a déjà été dit sur ce produit ce jour.
    $liste = [];
    foreach (invOperateurs($sid) as $id => $n) { $liste[] = ['id' => (int) $id, 'nom' => $n]; }
    usort($liste, static fn ($a, $b) => strcmp($a['nom'], $b['nom']));
    return ['shop' => $sid, 'date' => $date, 'pid' => $pid, 'produit' => $nom,
        'recette' => ['lue' => $recette !== null, 'sansRecette' => $recette === null ? null : !empty($recette['sansRecette']), 'lignes' => $lignes],
        'reclamation' => $recl, 'producteur' => invProducteur($sid, $pid, $date), 'operateurs' => $liste,
        'remarques' => array_values(array_filter(invRemarques($sid, $date, $date), static fn ($r) => $r['pid'] === $pid)),
        'source' => 'recette : /products/{pid} puis /shops/{id}/recipes/{rid}/cost · références réclamables : fournisseurs matière du panel, gardées dix minutes · producteur : journal product_movement (PRODUCTION) · opérateurs : /shops/{id}/employees'];
}

/**
 * POST /equipe/remarques {shop, employeId|employeNom, texte, le, heure, pid, produit, pieces, motif, saisieId, auteur}
 * — une remarque à un opérateur (un problème de qualité en production), gardée dans ses évaluations.
 */
function wr_equipe_remarque_creer(): array
{
    $b = body();
    $sid = (int) ($b['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    $texte = trim((string) ($b['texte'] ?? ''));
    if ($texte === '') { http_response_code(422); return ['error' => 'un mot, au moins : la remarque est vide']; }
    $ide = isset($b['employeId']) && is_numeric($b['employeId']) && (int) $b['employeId'] > 0 ? (int) $b['employeId'] : null;
    $nomE = mb_substr(trim((string) ($b['employeNom'] ?? '')), 0, 80);
    if ($ide === null && $nomE === '') { http_response_code(422); return ['error' => 'à qui ? choisissez l’opérateur']; }
    if ($ide !== null && $nomE === '') { $nomE = invOperateurs($sid)[(string) $ide] ?? ('opérateur ' . $ide); }
    $le = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['le'] ?? '')) ? (string) $b['le'] : date('Y-m-d');
    $heure = preg_match('/^\d{2}:\d{2}$/', (string) ($b['heure'] ?? '')) ? (string) $b['heure'] : null;
    $pid = isset($b['pid']) && is_numeric($b['pid']) && (int) $b['pid'] > 0 ? (int) $b['pid'] : null;
    $pieces = isset($b['pieces']) && is_numeric($b['pieces']) ? round((float) $b['pieces'], 1) : null;
    $motif = mb_substr(strtolower(trim((string) ($b['motif'] ?? ''))), 0, 40);
    $saisie = isset($b['saisieId']) && is_numeric($b['saisieId']) && (int) $b['saisieId'] > 0 ? (int) $b['saisieId'] : null;
    $auteur = mb_substr(trim((string) ($b['auteur'] ?? '')), 0, 60);
    $prod = mb_substr(trim((string) ($b['produit'] ?? '')), 0, 200);
    $texte = mb_substr($texte, 0, 1000);
    try {
        ensureOperateurRemarques();
        Db::exec('INSERT INTO ceo_operateur_remarque (shop_id, employe_id, employe_nom, le, heure, produit_id, produit_nom, pieces, motif, texte, source, saisie_id, auteur, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$sid, $ide, $nomE, $le, $heure, $pid, $prod, $pieces, $motif, $texte, 'invendus', $saisie, $auteur, date('Y-m-d H:i:s')]);
        $id = (int) Db::pdo()->lastInsertId();
    } catch (Throwable $e) { http_response_code(503); return ['error' => 'la remarque n’a pas pu être gardée : base indisponible']; }
    journalAdd('CEO', 'Équipe', function_exists('magasinNom') ? magasinNom((string) $sid) : (string) $sid,
        'Remarque à l’opérateur — ' . $nomE . ($prod !== '' ? ' · ' . $prod : '') . ($pieces !== null ? ' · ' . $pieces . ' pièce(s)' : '') . ' · ' . invMotif($motif) . ' · « ' . mb_substr($texte, 0, 120) . ' »');
    $r = ['id' => $id, 'employeId' => $ide, 'employe' => $nomE, 'le' => $le, 'heure' => $heure, 'pid' => $pid, 'produit' => $prod, 'pieces' => $pieces,
        'motif' => $motif, 'motifLib' => invMotif($motif), 'texte' => $texte, 'source' => 'invendus', 'saisieId' => $saisie, 'auteur' => $auteur, 'creeLe' => date('Y-m-d H:i:s')];
    return ['ok' => true, 'id' => $id, 'remarque' => $r];
}

/** GET /equipe/remarques?shop=4[&employe=90][&du=&au=] — les remarques faites aux opérateurs d'un magasin : leurs évaluations. */
function ep_equipe_remarques(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['du'] ?? '')) ? (string) $_GET['du'] : null;
    $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['au'] ?? '')) ? (string) $_GET['au'] : null;
    $emp = isset($_GET['employe']) && is_numeric($_GET['employe']) ? (int) $_GET['employe'] : null;
    $r = invRemarques($sid, $du, $au, $emp);
    $par = [];
    foreach ($r as $x) {
        $k = $x['employeId'] !== null ? 'e' . $x['employeId'] : 'n' . $x['employe'];
        $par[$k] = $par[$k] ?? ['employeId' => $x['employeId'], 'employe' => $x['employe'], 'n' => 0, 'derniere' => $x['le']];
        $par[$k]['n']++;
    }
    return ['shop' => $sid, 'du' => $du, 'au' => $au, 'employe' => $emp, 'remarques' => $r, 'parOperateur' => array_values($par),
        'source' => 'ceo_operateur_remarque : les remarques faites depuis la modale des invendus du dashboard magasin'];
}
