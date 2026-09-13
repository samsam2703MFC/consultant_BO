<?php

declare(strict_types=1);

/**
 * Statistiques de vente — par semaine, par jour, par heure : clients, panier,
 * ventes, coût matière, marge brute, coût du travail, résultat de l'heure, et
 * le top 5 des produits de chaque heure avec leur marge.
 *
 * Tout vient des endpoints du panel ; rien n'est supposé. La sonde ci-dessous
 * dit ce que chaque route rend réellement (clés et un échantillon), c'est
 * elle qui a fixé les champs lus plus bas.
 */

/** GET /ventes/stats/sonde?shop=2&date=2026-09-06 — les routes, telles qu'elles répondent. */
function ep_stats_ventes_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 2);
    $date = (string) ($_GET['date'] ?? date('Y-m-d', strtotime('-1 day')));
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $chemins = [
        'hourly' => '/shops/' . $sid . '/statistics/sales/hourly-distribution/' . $date,
        'heat'   => '/consultant/shops/' . $sid . '/margin-heatmap?from=' . $date . '&to=' . $date,
        'trans'  => '/shops/' . $sid . '/transactions?date=' . $date,
        'sched'  => '/shops/' . $sid . '/schedule?date=' . $date,
        'kpis'   => '/shops/' . $sid . '/statistics/sales/kpis?date_from=' . $date . '&date_to=' . $date,
        'daily'  => '/shops/' . $sid . '/statistics/daily-summary?date=' . $date,
    ];
    $res = PanelApi::getParallele($chemins, 6);
    $coupe = static function ($v, int $n = 3) {
        if (!is_array($v)) { return $v; }
        $l = analyseListe($v);
        if ($l !== []) { return ['n' => count($l), 'cles' => array_keys((array) $l[0]), 'exemples' => array_slice($l, 0, $n)]; }
        return ['cles' => array_keys($v), 'valeur' => array_map(static fn ($x) => is_array($x) ? ['n' => count($x), 'premier' => array_slice($x, 0, 2)] : $x, $v)];
    };
    $out = ['shop' => $sid, 'date' => $date];
    foreach ($chemins as $k => $p) { $out[$k] = ['route' => $p, 'reponse' => $coupe($res[$k] ?? null)]; }
    // Un ticket avec ses produits : l'heure du ticket et les champs d'une ligne.
    $lt = analyseListe($res['trans'] ?? null);
    if ($lt !== []) {
        $id = (int) ($lt[0]['id'] ?? 0);
        $t = $id > 0 ? PanelApi::get('/transactions/' . $id . '?include=products') : null;
        $out['ticket'] = ['route' => '/transactions/' . $id . '?include=products',
            'cles' => is_array($t) ? array_keys($t) : null,
            'sansProduits' => is_array($t) ? array_filter($t, static fn ($v) => !is_array($v)) : $t,
            'produit0' => is_array($t) && isset($t['products'][0]) ? $t['products'][0] : null];
    }
    return $out;
}
