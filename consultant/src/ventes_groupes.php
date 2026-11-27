<?php

declare(strict_types=1);

/**
 * Le CA par groupe de catégories — Traiteur, Boulangerie, Viennoiserie, Quiches, Tartes… — mois par mois et magasin
 * par magasin (27/11/2026, Résultat › Mois : la courbe des douze mois glissants, par catégorie principale).
 *
 * Une lecture du panel par magasin et par mois : /shops/{id}/statistics/sales/product-category-groups?date_from&date_to,
 * dont chaque ligne porte son `group_name` (le cache des analyses produits, apTranches2, ne garde que la catégorie).
 * Le CA est `sales_value` (TTC), sommé par groupe. Un mois clos se grave une fois (ceo_app_setting `vgM{sid}:{AAAA-MM}`),
 * le mois en cours expire à l'heure.
 *
 * Une lecture coûte trois à quatre secondes au panel : la première ouverture en demande jusqu'à une centaine (deux ans,
 * quatre magasins). La route lit donc par passes bornées (VG_PASSE lectures au plus) et dit ce qui reste ; l'écran la
 * rappelle jusqu'à ce que tout soit lu, puis tout vient du cache.
 *
 *   GET /ventes/groupes-mois?du=AAAA-MM&au=AAAA-MM
 *   → { du, au, mois[], magasins[{id, nom}], groupes[], ca: {sid: {AAAA-MM: {groupe: CA}}}, reste, illisibles[] }
 */

const VG_PASSE = 32;        // lectures au panel au plus par appel (8 en parallèle : une quinzaine de secondes)
const VG_MOIS_MAX = 36;     // la fenêtre la plus large qu'on accepte

/** Une réponse product-category-groups → groupe → CA TTC. */
function vgCondense(array $r): array
{
    $g = [];
    foreach (analyseListe($r) as $l) {
        $nom = trim((string) ($l['group_name'] ?? ''));
        if ($nom === '') { $nom = 'Sans groupe'; }
        $g[$nom] = ($g[$nom] ?? 0.0) + (float) ($l['sales_value'] ?? ($l['total_earning'] ?? 0));
    }
    return array_map(static fn ($v) => round($v, 2), $g);
}

/**
 * Les mois demandés, cache d'abord, puis au plus `$budget` lectures au panel en un voyage parallèle.
 *
 * @param array<int,array{0:int,1:string}> $couples [sid, 'AAAA-MM']
 * @return array{0: array<string,?array>, 1: int}  [« sid:AAAA-MM » → groupe → CA (null : illisible), mois encore à lire]
 */
function vgMois(array $couples, int $budget = VG_PASSE): array
{
    $out = []; $chemins = []; $reste = 0;
    $moisC = date('Y-m');
    foreach ($couples as [$sid, $ym]) {
        $k = $sid . ':' . $ym;
        $cache = setting('vgM' . $k);
        $clos = $ym < $moisC;
        if (is_array($cache) && isset($cache['g']) && ($clos || (int) ($cache['quand'] ?? 0) > time() - 3600)) {
            $out[$k] = (array) $cache['g'];
            continue;
        }
        if (count($chemins) >= $budget) { $reste++; continue; }
        $fin = min(date('Y-m-t', strtotime($ym . '-01')), date('Y-m-d'));
        $chemins[$k] = '/shops/' . $sid . '/statistics/sales/product-category-groups?'
            . http_build_query(['date_from' => $ym . '-01', 'date_to' => $fin]);
    }
    if ($chemins !== []) {
        $res = PanelApi::getParallele($chemins, 8, 40);
        foreach ($chemins as $k => $_) {
            $r = $res[$k] ?? null;
            if (!is_array($r)) { $out[$k] = null; continue; }
            $g = vgCondense($r);
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['vgM' . $k, json_encode(['quand' => time(), 'g' => $g], JSON_UNESCAPED_UNICODE)]);
            $out[$k] = $g;
        }
    }
    return [$out, $reste];
}

function ep_ventes_groupes_mois(): array
{
    $mois = static fn (string $v): ?string => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : null;
    $moisC = date('Y-m');
    $au = $mois((string) ($_GET['au'] ?? '')) ?? $moisC;
    if ($au > $moisC) { $au = $moisC; }
    $du = $mois((string) ($_GET['du'] ?? '')) ?? date('Y-m', strtotime($au . '-01 -11 month'));
    if ($du > $au) { http_response_code(422); return ['error' => 'du doit précéder au']; }
    $liste = [];
    for ($t = strtotime($du . '-01'); date('Y-m', $t) <= $au; $t = strtotime(date('Y-m-01', $t) . ' +1 month')) { $liste[] = date('Y-m', $t); }
    if (count($liste) > VG_MOIS_MAX) { http_response_code(422); return ['error' => 'au plus ' . VG_MOIS_MAX . ' mois']; }
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'API du panel non configurée.']; }

    $magasins = [];
    foreach (analyseListe(PanelApi::consultantShops() ?? []) as $sh) {
        $id = (int) ($sh['id'] ?? 0);
        if ($id > 0) { $magasins[] = ['id' => (string) $id, 'nom' => trim((string) ($sh['representative_name'] ?? ($sh['name'] ?? ('Magasin ' . $id))))]; }
    }
    $couples = [];
    foreach ($liste as $ym) { foreach ($magasins as $m) { $couples[] = [(int) $m['id'], $ym]; } }
    [$lu, $reste] = vgMois($couples);

    $ca = []; $tot = []; $illisibles = [];
    foreach ($lu as $k => $g) {
        [$sid, $ym] = explode(':', $k, 2);
        if ($g === null) { $illisibles[] = $k; continue; }
        if ($g === []) { continue; }
        $ca[$sid][$ym] = $g;
        foreach ($g as $nom => $v) { $tot[$nom] = ($tot[$nom] ?? 0.0) + $v; }
    }
    arsort($tot);
    return ['du' => $du, 'au' => $au, 'mois' => $liste, 'magasins' => $magasins, 'groupes' => array_keys($tot),
        'ca' => (object) $ca, 'reste' => $reste, 'illisibles' => $illisibles,
        'source' => 'ventes du panel (product-category-groups), un relevé par magasin et par mois, CA TTC (sales_value) par groupe de catégories ; un mois clos est gardé'];
}
