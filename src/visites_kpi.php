<?php

declare(strict_types=1);

/**
 * KPI ET SANTÉ DU MAGASIN — l'étape des chiffres du contrôle, sur la tablette du consultant (26/10/2026).
 *
 * GET /visites/kpi/{shop} — deux lectures, chacune avec sa fenêtre et sa source :
 *
 *  - le CA PAR ETP des trois derniers mois clos : le CA du mois (relevé des ventes du panel, pvKpisMois) ÷ les ETP
 *    planifiés (heures du planning ÷ heuresEtpMois, ep_stores_etp — la règle de l'alerte CA/ETP des rapports), face au
 *    réseau (CA total ÷ ETP totaux des magasins qui ont les deux) et au seuil de la Table KPI (`ca-etp`). Un mois sans
 *    planning n'a pas d'ETP : il le dit, il ne le devine pas ;
 *  - la STRUCTURE DES VENTES par catégorie sur les trois derniers mois clos : la part de chaque catégorie dans le CA du
 *    magasin face à sa part dans le CA du reste du réseau (/consultant/shops/category-sales, un appel par mois pour
 *    tout le réseau, gravé une fois lu : les trois mois sont clos).
 *
 * Gardé une heure (rcServi) et servi périmé six heures pendant le recalcul : des chiffres qui ne bougent pas pendant
 * une visite. La tablette les lit dès l'ouverture du contrôle, avant d'arriver à l'étape. L'année face à l'an passé et
 * les six leviers, elle les lit aux routes du dashboard magasin (/ventes/semaines, /leviers/magasins) et les dessine
 * avec ses cartes (assets/js/cartes-magasin.js).
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

/** Les deux lectures ; une qui échoue (ou un mois du panel resté muet) laisse les autres, se nomme dans `manques` et se relit dans cinq minutes. */
function vkCalcul(int $sid): array
{
    $mois = vkMoisClos(3);
    $out = ['shop' => (string) $sid, 'lu' => date('Y-m-d H:i')];
    $manques = [];
    foreach (['caEtp' => 'vkCaEtp', 'structure' => 'vkStructure'] as $k => $f) {
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
