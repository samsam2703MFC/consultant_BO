<?php

declare(strict_types=1);

/**
 * KPI ET SANTÉ DU MAGASIN — l'étape des chiffres du contrôle, sur la tablette du consultant (26/10/2026).
 *
 * GET /visites/kpi/{shop} — trois lectures, chacune avec sa fenêtre et sa source :
 *
 *  - le CA PAR ETP des trois derniers mois clos : le CA du mois (relevé des ventes du panel, pvKpisMois) ÷ les ETP
 *    planifiés (heures du planning ÷ heuresEtpMois, ep_stores_etp — la règle de l'alerte CA/ETP des rapports), face au
 *    réseau (CA total ÷ ETP totaux des magasins qui ont les deux) et au seuil de la Table KPI (`ca-etp`). Un mois sans
 *    planning n'a pas d'ETP : il le dit, il ne le devine pas ;
 *  - l'ANNÉE À DATE face à l'an passé : clients, panier moyen, articles par ticket, mois par mois — la lecture de
 *    Résultat › Année (/ventes/reseau?vue=annee&articles=1). Le cumul ne compte que les mois où le magasin a un N-1
 *    (le mois en cours à date, face aux mêmes jours de l'an passé) ; le réseau, à périmètre constant, situe l'écart ;
 *  - la STRUCTURE DES VENTES par catégorie sur les trois derniers mois clos : la part de chaque catégorie dans le CA du
 *    magasin face à sa part dans le CA du reste du réseau (/consultant/shops/category-sales, un appel par mois pour
 *    tout le réseau, gravé une fois lu : les trois mois sont clos).
 *
 * Gardé une heure (rcServi) et servi périmé six heures pendant le recalcul : des chiffres qui ne bougent pas pendant
 * une visite. La tablette les lit dès l'ouverture du contrôle, avant d'arriver à l'étape.
 */

const VK_TTL_S = 3600;
const VK_PERIME_S = 21600;

/** GET /visites/kpi/{shop}[?rafraichir=1] */
function ep_visites_kpi(string $shop): array
{
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['shop' => $shop, 'indispo' => true, 'motif' => 'compte panel non configuré (Mon compte)']; }
    return rcServi('vkKpi:' . $shop, static fn (): array => vkCalcul((int) $shop),
        static fn (array $x): int => empty($x['manques']) ? VK_TTL_S : 300, !empty($_GET['rafraichir']), '/visites/kpi/' . $shop, VK_PERIME_S);
}

/** Les trois lectures ; une qui échoue (ou un mois du panel resté muet) laisse les autres, se nomme dans `manques` et se relit dans cinq minutes. */
function vkCalcul(int $sid): array
{
    $mois = vkMoisClos(3);
    $out = ['shop' => (string) $sid, 'lu' => date('Y-m-d H:i')];
    $manques = [];
    foreach (['caEtp' => 'vkCaEtp', 'annee' => 'vkAnnee', 'structure' => 'vkStructure'] as $k => $f) {
        try { $out[$k] = $f($sid, $mois); }
        catch (Throwable $e) { $out[$k] = null; $manques[] = $k; }
    }
    if (is_array($out['structure']) && $out['structure']['moisLus'] < $out['structure']['moisDemandes']) { $manques[] = 'structure'; }
    $out['manques'] = $manques;
    return $out;
}

/** Les n derniers mois clos, du plus ancien au plus récent ('AAAA-MM'). */
function vkMoisClos(int $n): array
{
    $out = [];
    for ($i = $n; $i >= 1; $i--) { $out[] = date('Y-m', mktime(12, 0, 0, (int) date('n') - $i, 1, (int) date('Y'))); }
    return $out;
}

function vkMoisLab(string $m): string
{
    $MN = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $MN[(int) substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
}

/** Un endpoint appelé avec d'autres paramètres de requête, puis la requête rendue telle qu'elle était. */
function vkAvecGet(array $get, callable $ep): mixed
{
    $avant = $_GET;
    $_GET = $get + $_GET;
    try { return $ep(); } finally { $_GET = $avant; }
}

/** Un écart en %, arrondi au dixième ; null sans base. */
function vkEcart(?float $n, ?float $n1): ?float
{
    return ($n !== null && $n1 !== null && $n1 > 0) ? round(($n / $n1 - 1) * 100, 1) : null;
}

/* --- CA par ETP ------------------------------------------------------------------ */

function vkCaEtp(int $sid, array $mois): array
{
    $shops = array_map('intval', array_keys(viMagasins()));
    $paires = [];
    foreach ($shops as $s) { foreach ($mois as $m) { $paires[] = [$s, $m]; } }
    if (function_exists('pvKpisMoisLot')) { pvKpisMoisLot($paires); }
    $annees = array_values(array_unique(array_map(static fn (string $m): int => (int) substr($m, 0, 4), $mois)));
    $etp = [];
    foreach ((array) vkAvecGet(['annees' => implode(',', $annees)], 'ep_stores_etp') as $r) {
        if (($r['etp'] ?? null) !== null) { $etp[(int) $r['storeId']][sprintf('%04d-%02d', (int) $r['annee'], (int) $r['mois'])] = (float) $r['etp']; }
    }
    $lignes = [];
    foreach ($mois as $m) {
        $par = [];
        foreach ($shops as $s) {
            $k = pvKpisMois($s, $m); $e = $etp[$s][$m] ?? null;
            $ca = $k !== null && $k['ca'] > 0 ? (float) $k['ca'] : null;
            $par[$s] = ['ca' => $ca, 'etp' => $e, 'caEtp' => ($ca !== null && $e !== null && $e > 0) ? (int) round($ca / $e) : null];
        }
        $avec = array_filter($par, static fn (array $x): bool => $x['caEtp'] !== null);
        $caR = array_sum(array_column($avec, 'ca')); $etpR = array_sum(array_column($avec, 'etp'));
        $ici = $par[$sid] ?? ['ca' => null, 'etp' => null, 'caEtp' => null];
        $lignes[] = ['mois' => $m, 'lab' => vkMoisLab($m), 'ca' => $ici['ca'] !== null ? round($ici['ca']) : null, 'etp' => $ici['etp'], 'caEtp' => $ici['caEtp'],
            'reseau' => $etpR > 0 ? (int) round($caR / $etpR) : null,
            'rang' => $ici['caEtp'] !== null ? 1 + count(array_filter($avec, static fn (array $x): bool => $x['caEtp'] > $ici['caEtp'])) : null,
            'sur' => count($avec)];
    }
    return ['seuil' => function_exists('kpiSeuil') ? kpiSeuil('ca-etp', 13000.0) : 13000.0, 'heuresEtp' => (float) setting('heuresEtpMois', 168),
        'mois' => $lignes, 'source' => 'CA : relevé des ventes du panel (mois clos) · ETP : heures du planning ÷ ' . (float) setting('heuresEtpMois', 168) . ' h'];
}

/* --- L'année à date face à l'an passé -------------------------------------------- */

/** Les articles par ticket d'une fenêtre : null si les pièces manquent ou ne valent rien face à des clients. */
function vkApt(mixed $articles, int $tickets): ?float
{
    return (is_numeric($articles) && (float) $articles > 0 && $tickets > 0) ? round((float) $articles / $tickets, 2) : null;
}

function vkAnnee(int $sid, array $mois): array
{
    $r = vkAvecGet(['vue' => 'annee', 'date' => date('Y-m-d'), 'articles' => '1'], 'ep_ventes_reseau');
    $ici = null;
    foreach ($r['magasins'] ?? [] as $m) { if ((string) $m['id'] === (string) $sid) { $ici = $m; } }
    $vide = static fn (): array => ['t' => 0, 'ca' => 0.0, 'j' => 0, 't1' => 0, 'ca1' => 0.0, 'j1' => 0, 'a' => 0.0, 'at' => 0, 'a1' => 0.0, 'at1' => 0, 'n' => 0];
    // Une période comparable s'ajoute au cumul ; ses pièces seulement si elles sont lues des deux côtés.
    $cumule = static function (array &$c, array $l): void {
        $n1 = $l['n1'];
        $c['t'] += (int) $l['tickets']; $c['ca'] += (float) $l['ca']; $c['j'] += (int) $l['jours'];
        $c['t1'] += (int) $n1['tickets']; $c['ca1'] += (float) $n1['ca']; $c['j1'] += (int) ($n1['jours'] ?? 0); $c['n']++;
        if (vkApt($l['articles'] ?? null, (int) $l['tickets']) !== null && vkApt($n1['articles'] ?? null, (int) $n1['tickets']) !== null) {
            $c['a'] += (float) $l['articles']; $c['at'] += (int) $l['tickets']; $c['a1'] += (float) $n1['articles']; $c['at1'] += (int) $n1['tickets'];
        }
    };
    $cI = $vide(); $cR = $vide(); $series = []; $magasinsR = [];
    foreach ($r['periodes'] ?? [] as $i => $p) {
        if (!empty($p['futur'])) { continue; }
        foreach ($r['magasins'] ?? [] as $m) {
            $l = $m['periodes'][$i] ?? null;
            if ($l !== null && $l['source'] !== null && $l['n1'] !== null) { $cumule($cR, $l); $magasinsR[$m['id']] = true; }
        }
        $l = $ici['periodes'][$i] ?? null;
        $vu = $l !== null && $l['source'] !== null; $n1 = $l['n1'] ?? null;
        $series[] = ['lab' => $p['lab'], 'enCours' => !empty($p['enCours']), 'jours' => $vu ? (int) $l['jours'] : null, 'joursN1' => $n1 !== null ? (int) ($n1['jours'] ?? 0) : null,
            'clients' => $vu ? (int) $l['tickets'] : null, 'clientsN1' => $n1 !== null ? (int) $n1['tickets'] : null,
            'panier' => $vu && $l['tickets'] > 0 ? round($l['ca'] / $l['tickets'], 2) : null,
            'panierN1' => $n1 !== null && $n1['tickets'] > 0 ? round($n1['ca'] / $n1['tickets'], 2) : null,
            'articles' => $vu ? vkApt($l['articles'] ?? null, (int) $l['tickets']) : null,
            'articlesN1' => $n1 !== null ? vkApt($n1['articles'] ?? null, (int) $n1['tickets']) : null];
        if ($vu && $n1 !== null) { $cumule($cI, $l); }
    }
    $trois = static function (array $c): array {
        $p = $c['t'] > 0 ? $c['ca'] / $c['t'] : null; $p1 = $c['t1'] > 0 ? $c['ca1'] / $c['t1'] : null;
        $a = $c['at'] > 0 ? $c['a'] / $c['at'] : null; $a1 = $c['at1'] > 0 ? $c['a1'] / $c['at1'] : null;
        return ['clients' => ['n' => $c['t'], 'n1' => $c['t1'], 'ecart' => vkEcart((float) $c['t'], (float) $c['t1'])],
            'panier' => ['n' => $p !== null ? round($p, 2) : null, 'n1' => $p1 !== null ? round($p1, 2) : null, 'ecart' => vkEcart($p, $p1)],
            'articles' => ['n' => $a !== null ? round($a, 2) : null, 'n1' => $a1 !== null ? round($a1, 2) : null, 'ecart' => vkEcart($a, $a1)],
            'jours' => ['n' => $c['j'], 'n1' => $c['j1']], 'mois' => $c['n']];
    };
    $annee = (int) date('Y');
    return ['annee' => $annee, 'du' => $annee . '-01-01', 'au' => date('Y-m-d'), 'mois' => $series,
        'cumul' => $cI['n'] > 0 ? $trois($cI) : null, 'reseau' => $cR['n'] > 0 ? $trois($cR) + ['magasins' => count($magasinsR)] : null,
        'n1Motif' => $ici['n1Motif'] ?? ($ici === null ? 'aucune vente lue cette année' : null),
        'source' => (string) ($r['source'] ?? '') . ' · articles : relevé des ventes du panel (pièces vendues)'];
}

/* --- La structure des ventes face au reste du réseau ------------------------------- */

/** Une réponse category-sales ventilée : [magasin => [catégorie => CA]]. */
function vkCatsParMagasin(mixed $rep): array
{
    $par = [];
    $liste = is_array($rep) ? ($rep['shops'] ?? (array_is_list($rep) ? $rep : [])) : [];
    foreach ((array) $liste as $e) {
        if (!is_array($e) || !isset($e['categories']) || !is_array($e['categories'])) { continue; }
        $id = (int) (nombreOuNull($e, ['shop_id', 'id_shop', 'id']) ?? 0);
        if ($id <= 0) { continue; }
        foreach ($e['categories'] as $c) {
            if (!is_array($c)) { continue; }
            $v = nombreOuNull($c, ['ca', 'value', 'amount', 'turnover']);
            $nom = trim((string) ($c['name'] ?? $c['label'] ?? ''));
            if ($nom === '' || $v === null) { continue; }
            $par[$id][$nom] = ($par[$id][$nom] ?? 0) + $v;
        }
    }
    return $par;
}

function vkStructure(int $sid, array $mois): array
{
    $par = []; $paths = [];
    foreach ($mois as $m) {
        $c = setting('vkCat:' . $m);
        if (is_array($c) && $c !== []) { $par[$m] = $c; continue; }
        $du = $m . '-01';
        $paths[$m] = '/consultant/shops/category-sales?' . http_build_query(['date_from' => $du, 'date_to' => date('Y-m-t', strtotime($du))]);
    }
    if ($paths !== []) {
        foreach (PanelApi::getParallele($paths, 3) as $m => $rep) {
            $c = vkCatsParMagasin($rep);
            if ($c === []) { continue; }
            $par[$m] = $c;
            try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['vkCat:' . $m, json_encode($c)]); }
            catch (Throwable $e) { /* sans gravure : relu au prochain calcul */ }
        }
    }
    // Le reste du réseau : les autres magasins ouverts — pas les entrées techniques que la route rend parfois.
    $ouverts = array_map('intval', array_keys(viMagasins()));
    $ici = []; $reste = []; $autres = [];
    foreach ($par as $c) {
        foreach ($c as $s => $cats) {
            $s = (int) $s;
            if ($s !== $sid && !in_array($s, $ouverts, true)) { continue; }
            foreach ($cats as $nom => $v) {
                if ($s === $sid) { $ici[$nom] = ($ici[$nom] ?? 0) + (float) $v; }
                else { $reste[$nom] = ($reste[$nom] ?? 0) + (float) $v; $autres[$s] = true; }
            }
        }
    }
    $tI = array_sum($ici); $tR = array_sum($reste);
    $lignes = [];
    foreach (array_unique(array_merge(array_keys($ici), array_keys($reste))) as $nom) {
        $pI = $tI > 0 ? ($ici[$nom] ?? 0) / $tI * 100 : null; $pR = $tR > 0 ? ($reste[$nom] ?? 0) / $tR * 100 : null;
        $lignes[] = ['categorie' => (string) $nom, 'ca' => round($ici[$nom] ?? 0), 'part' => $pI !== null ? round($pI, 1) : null, 'partReste' => $pR !== null ? round($pR, 1) : null,
            'ecart' => $pI !== null && $pR !== null ? round($pI - $pR, 1) : null];
    }
    usort($lignes, static fn (array $a, array $b): int => [$b['ca'], $b['partReste'] ?? 0] <=> [$a['ca'], $a['partReste'] ?? 0]);
    $lus = array_values(array_filter($mois, static fn (string $m): bool => isset($par[$m])));
    return ['du' => $lus ? $lus[0] . '-01' : null, 'au' => $lus ? date('Y-m-t', strtotime(end($lus) . '-01')) : null, 'moisLus' => count($lus), 'moisDemandes' => count($mois),
        'ca' => round($tI), 'caReste' => round($tR), 'autres' => count($autres), 'categories' => $tI > 0 ? $lignes : [],
        'source' => 'panel : ventes par catégorie (/consultant/shops/category-sales), mois clos'];
}
