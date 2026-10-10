<?php
declare(strict_types=1);

/*
 * Les promotions du panel reconnues dans les tickets (10/10/2026).
 *
 * Le panel n'écrit pas sur la ligne de ticket quelle promotion l'a remisée : la ligne porte son prix
 * unitaire, sa quantité et sa remise (item_discount_value). Les règles se lisent avec le compte ADMIN
 * du panel (ErpApi, /admin/promotions/buy-x-get-y et son détail : produits et catégories qui
 * déclenchent, qui reçoivent, le type et la valeur de la récompense). Une ligne remisée se rattache
 * à la règle dont la récompense explique la remise au centime près :
 *   FIXED_PRICE v           → remise par pièce = prix − v
 *   FIXED_AMOUNT_DISCOUNT v → remise par pièce = v
 *   FREE                    → remise par pièce = prix
 *   PERCENTAGE_DISCOUNT v   → remise par pièce = prix × v / 100
 * Les pièces qui déclenchent (« 2 achetés ») se prennent dans le même ticket : le reste de la ligne
 * remisée d'abord, puis les autres lignes des produits ou catégories qui déclenchent. Une remise
 * qu'aucune règle n'explique reste « remise non reconnue ».
 * Rien n'est écrit au panel : lecture seule.
 */

const PV_CACHE_REGLES = 600;   // les règles relues toutes les 10 minutes

/** Les règles de promotion du panel, normalisées. Gardées 10 minutes ; si le panel se tait, la dernière lecture ressert. */
function pvRegles(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $c = setting('pvRegles');
    if (is_string($c)) { $c = json_decode($c, true); }
    if (is_array($c) && isset($c['ts'], $c['r']) && time() - (int) $c['ts'] < PV_CACHE_REGLES) { return $memo = ['regles' => (array) $c['r'], 'erreur' => null]; }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) {
        return $memo = ['regles' => is_array($c) ? (array) ($c['r'] ?? []) : [], 'erreur' => 'compte admin du panel non configuré'];
    }
    ErpApi::$lastError = null;
    $liste = ErpApi::get('/admin/promotions/buy-x-get-y?limit=100');
    $l = is_array($liste) ? (array_is_list($liste) ? $liste : ($liste['items'] ?? $liste['data'] ?? [])) : null;
    if (!is_array($l)) { return $memo = ['regles' => is_array($c) ? (array) ($c['r'] ?? []) : [], 'erreur' => ErpApi::$lastError ?: 'règles illisibles']; }
    $out = [];
    foreach ($l as $x) {
        $id = (int) ($x['id'] ?? 0);
        if ($id <= 0) { continue; }
        $bundle = ($x['promotion_type'] ?? '') === 'BUNDLE_PROMOTION';
        $d = ErpApi::get('/admin/promotions/' . ($bundle ? 'bundles/' : 'buy-x-get-y/') . $id);
        if (!is_array($d)) { continue; }
        $out[] = pvNormaliser($d);
    }
    try { svGrave('pvRegles', ['ts' => time(), 'r' => $out]); } catch (Throwable $e) { /* sans mémo */ }
    return $memo = ['regles' => $out, 'erreur' => null];
}

/** Une règle du panel ramenée à ce que la reconnaissance utilise. */
function pvNormaliser(array $d): array
{
    $cible = static function ($c): array {
        $c = is_array($c) ? $c : [];
        $p = [];
        foreach ((array) ($c['products'] ?? []) as $x) {
            $pid = (int) ($x['id_product'] ?? $x['id'] ?? 0);
            $por = (int) ($x['id_product_portion'] ?? $x['product_portion_id'] ?? 0);
            if ($pid > 0) { $p[$por > 0 ? $pid . ':' . $por : (string) $pid] = trim((string) ($x['name'] ?? '')); }
        }
        foreach ((array) ($c['product_ids'] ?? []) as $pid) { if ((int) $pid > 0 && !isset($p[(string) (int) $pid])) { $p[(string) (int) $pid] = ''; } }
        $cats = [];
        foreach ((array) ($c['categories'] ?? []) as $x) { if ((int) ($x['id'] ?? 0) > 0) { $cats[(string) (int) $x['id']] = trim((string) ($x['name'] ?? '')); } }
        foreach ((array) ($c['category_ids'] ?? []) as $cid) { if ((int) $cid > 0 && !isset($cats[(string) (int) $cid])) { $cats[(string) (int) $cid] = ''; } }
        $exc = [];
        foreach ((array) ($c['excluded_product_ids'] ?? []) as $pid) { if ((int) $pid > 0) { $exc[] = (int) $pid; } }
        return ['q' => (float) ($c['quantity'] ?? 1) ?: 1.0, 'p' => $p, 'c' => $cats, 'x' => $exc];
    };
    $bundle = ($d['promotion_type'] ?? '') === 'BUNDLE_PROMOTION';
    $items = [];
    foreach ((array) ($d['items'] ?? []) as $it) {
        $pid = (int) ($it['id_product'] ?? 0);
        $por = (int) ($it['id_product_portion'] ?? $it['product_portion_id'] ?? 0);
        if ($pid <= 0) { continue; }
        $pu = (float) ($it['calculation_sale_price'] ?? 0); $pr = (float) ($it['calculation_discounted_sale_price'] ?? 0);
        $items[] = ['k' => $por > 0 ? $pid . ':' . $por : (string) $pid, 'nom' => trim((string) ($it['product_name'] ?? '')), 'q' => (float) ($it['quantity'] ?? 1) ?: 1.0, 'remise' => $pu > 0 && $pr > 0 ? round($pu - $pr, 2) : null];
    }
    $rw = is_array($d['reward'] ?? null) ? $d['reward'] : [];
    $shops = array_values(array_map('intval', (array) ($d['shop_ids'] ?? [])));
    return [
        'id' => (int) $d['id'], 'nom' => trim((string) preg_replace('/\s+/u', ' ', (string) ($d['name'] ?? ('Promotion ' . $d['id'])))), 'type' => $bundle ? 'bundle' : 'buyxgety',
        'statut' => (string) ($d['status'] ?? ''), 'prio' => (int) ($d['priority'] ?? 0),
        'shops' => ($d['shop_scope_type'] ?? '') === 'ALL_SHOPS' ? null : $shops,
        'du' => isset($d['valid_from']) ? substr((string) $d['valid_from'], 0, 10) : null, 'au' => isset($d['valid_to']) ? substr((string) $d['valid_to'], 0, 10) : null,
        'declenche' => $bundle ? null : $cible($d['trigger'] ?? []), 'recoit' => $bundle ? null : $cible($rw),
        'recompense' => $bundle ? null : ['type' => strtoupper((string) ($rw['type'] ?? '')), 'valeur' => isset($rw['value']) ? (float) $rw['value'] : null],
        'bundle' => $bundle ? ['prix' => isset($d['bundle_promotion']['bundle_price']) ? (float) $d['bundle_promotion']['bundle_price'] : null, 'items' => $items] : null,
    ];
}

/** La règle en mots : « 2 achetés, le 3e −1,40 € », « Pain Doré à 1,50 € avec Tartes – Ø 28 cm », « bundle à 19,90 € ». */
function pvTexte(array $r): string
{
    $eur = static fn (float $v): string => number_format($v, 2, ',', ' ') . ' €';
    if ($r['type'] === 'bundle') {
        $noms = array_filter(array_map(static fn ($i) => $i['nom'], (array) ($r['bundle']['items'] ?? [])));
        return 'bundle' . ($r['bundle']['prix'] !== null ? ' à ' . $eur((float) $r['bundle']['prix']) : '') . ($noms ? ' · ' . implode(' + ', array_slice($noms, 0, 3)) : '');
    }
    $T = $r['declenche']; $R = $r['recoit']; $rc = $r['recompense'];
    $tq = (int) round($T['q']); $rq = (int) round($R['q']);
    $gain = match ($rc['type']) {
        'FIXED_PRICE' => 'à ' . $eur((float) $rc['valeur']),
        'FIXED_AMOUNT_DISCOUNT' => '−' . $eur((float) $rc['valeur']),
        'FREE' => $rq > 1 ? 'offerts' : 'offert',
        'PERCENTAGE_DISCOUNT' => '−' . rtrim(rtrim(number_format((float) $rc['valeur'], 1, ',', ''), '0'), ',') . ' %',
        default => 'remisé',
    };
    $lib = static function (array $c): string {
        $n = array_values(array_filter(array_merge(array_values($c['p']), array_values($c['c']))));
        return $n ? implode(', ', array_slice($n, 0, 2)) . (count($n) > 2 ? '…' : '') : 'produits choisis';
    };
    if ($T['p'] === $R['p'] && $T['c'] === $R['c']) {
        if ($rc['type'] === 'FREE') { return $tq . ' acheté' . ($tq > 1 ? 's' : '') . ', ' . $rq . ' ' . $gain; }
        return $tq . ' acheté' . ($tq > 1 ? 's' : '') . ', ' . ($rq > 1 ? 'les ' . $rq . ' suivants ' : 'le ' . ($tq + 1) . 'e ') . $gain;
    }
    return ($rq > 1 ? $rq . ' × ' : '') . $lib($R) . ' ' . $gain . ' avec ' . ($tq > 1 ? $tq . ' × ' : '') . $lib($T);
}

/** La règle vaut-elle pour ce magasin et ce jour ? */
function pvApplicable(array $r, int $sid, string $j): bool
{
    if ($r['shops'] !== null && !in_array($sid, $r['shops'], true)) { return false; }
    if ($r['du'] !== null && $j < $r['du']) { return false; }
    if ($r['au'] !== null && $j > $r['au']) { return false; }
    return true;
}

/** Le produit (clé « pid » ou « pid:portion ») est-il dans la cible ? Par produit, portion, catégorie (identifiant ou nom). */
function pvDans(array $cible, string $k, int $pid, array $catId, array $catNom): bool
{
    if (in_array($pid, $cible['x'], true)) { return false; }
    if (isset($cible['p'][$k]) || isset($cible['p'][(string) $pid])) { return true; }
    $cid = $catId[$pid] ?? null;
    if ($cid !== null && isset($cible['c'][(string) $cid])) { return true; }
    $cn = $catNom[$pid] ?? null;
    return $cn !== null && $cn !== '' && in_array($cn, $cible['c'], true);
}

/**
 * Un ticket : à quelle promotion chaque pièce remisée ou déclenchante appartient.
 * $lignes : [[clé, pid, q, prix, remise, total], …]. Rend [idRègle|'x' => ['fois' => n, 'l' => [clé => [q, v, remise]]]].
 */
function pvTicket(array $lignes, array $regles, array $catId, array $catNom): array
{
    $libre = [];
    foreach ($lignes as $i => $l) { $libre[$i] = (float) $l[2]; }
    $out = [];
    $ajoute = static function (string|int $rid, string $k, float $q, float $v, float $rem) use (&$out): void {
        $o =& $out[$rid]['l'][$k];
        $o = [($o[0] ?? 0) + $q, ($o[1] ?? 0) + $v, ($o[2] ?? 0) + $rem];
    };
    // L'explication au centime d'abord ; entre deux, une règle active avant une inactive, puis la priorité du panel.
    $rang = static fn ($c) => [$c['exact'] ? 1 : 0, $c['r']['statut'] === 'ACTIVE' ? 1 : 0, $c['r']['prio']];
    // Les lignes remisées d'abord, la plus grosse remise en tête.
    $ordre = array_keys($lignes);
    usort($ordre, static fn ($a, $b) => $lignes[$b][4] <=> $lignes[$a][4]);
    foreach ($ordre as $i) {
        [$k, $pid, $q, $pu, $rem] = $lignes[$i];
        if ($rem <= 0.004 || $q <= 0) { continue; }
        $choix = null;
        foreach ($regles as $r) {
            if ($r['type'] === 'bundle') {
                foreach ($r['bundle']['items'] as $ix => $it) {
                    if (($it['k'] === $k || $it['k'] === (string) $pid) && ($it['remise'] ?? 0) > 0) {
                        $n = (int) round($rem / $it['remise']);
                        // Un bundle se compte une fois, sur son premier article.
                        if ($n >= 1 && $n <= $q + 1e-6 && abs($n * $it['remise'] - $rem) <= 0.02 + 0.005 * $n) { $choix = ['r' => $r, 'u' => $n, 'fois' => $ix === 0 ? $n / $it['q'] : 0, 'exact' => true]; break 2; }
                    }
                }
                continue;
            }
            if (!pvDans($r['recoit'], $k, (int) $pid, $catId, $catNom)) { continue; }
            $v = $r['recompense']['valeur'];
            $u = match ($r['recompense']['type']) {
                'FIXED_PRICE' => $v !== null ? $pu - $v : null,
                'FIXED_AMOUNT_DISCOUNT' => $v,
                'FREE' => $pu,
                'PERCENTAGE_DISCOUNT' => $v !== null ? $pu * $v / 100 : null,
                default => null,
            };
            $n = $u !== null && $u > 0.004 ? (int) round($rem / $u) : 0;
            $exact = $n >= 1 && $n <= $q + 1e-6 && abs($n * $u - $rem) <= 0.02 + 0.005 * $n;
            $cand = ['r' => $r, 'u' => $exact ? $n : max(1, min((int) ceil($q), $n)), 'fois' => 0, 'exact' => $exact];
            if ($choix === null || $rang($cand) > $rang($choix)) { $choix = $cand; }
        }
        if ($choix === null) { $ajoute('x', $k, $q, (float) $lignes[$i][5], $rem); $out['x']['fois'] = ($out['x']['fois'] ?? 0) + 1; $libre[$i] = 0; continue; }
        $r = $choix['r'];
        $nR = min((float) $choix['u'], $q);                       // les pièces remisées
        $libre[$i] -= $nR;
        $ajoute($r['id'], $k, $nR, $nR * $pu - $rem, $rem);
        if ($r['type'] === 'bundle') { $out[$r['id']]['fois'] = ($out[$r['id']]['fois'] ?? 0) + (int) round($choix['fois']); continue; }
        $fois = max(1, (int) ceil($nR / max(1.0, $r['recoit']['q']) - 1e-6));
        $out[$r['id']]['fois'] = ($out[$r['id']]['fois'] ?? 0) + $fois;
        // Les pièces qui déclenchent : le reste de cette ligne d'abord, puis les lignes sans remise, puis les autres.
        $aPrendre = $fois * $r['declenche']['q'];
        $file = array_merge([$i], array_filter(array_keys($lignes), static fn ($j) => $j !== $i && $lignes[$j][4] <= 0.004), array_filter(array_keys($lignes), static fn ($j) => $j !== $i && $lignes[$j][4] > 0.004));
        foreach ($file as $j) {
            if ($aPrendre <= 1e-6) { break; }
            if ($libre[$j] <= 1e-6 || !pvDans($r['declenche'], (string) $lignes[$j][0], (int) $lignes[$j][1], $catId, $catNom)) { continue; }
            $pris = min($libre[$j], $aPrendre);
            $libre[$j] -= $pris; $aPrendre -= $pris;
            $ajoute($r['id'], (string) $lignes[$j][0], $pris, $pris * (float) $lignes[$j][3], 0.0);
        }
    }
    return $out;
}

/**
 * Les promotions d'un magasin sur des jours lus : par règle (fois, tickets, pièces, ventes, remise) et par produit
 * ({clé: {règle: [q, v, remise, fois]}}), la clé produit étant celle des catégories de /ventes/stats.
 */
function pvPromotions(int $sid, array $jours): array
{
    $R = pvRegles();
    $catId = []; $catNom = function_exists('svCategories') ? svCategories() : [];
    if (function_exists('panelCatalogue')) { foreach ((array) (panelCatalogue()['produits'] ?? []) as $pid => $x) { if (!empty($x['catId'])) { $catId[(int) $pid] = (int) $x['catId']; } } }
    $parId = [];
    foreach ($R['regles'] as $r) { $parId[$r['id']] = $r; }
    $agg = []; $prod = []; $tickets = 0; $joursAvec = []; $joursSans = [];
    foreach ($jours as $j) {
        $g = setting('svP' . $sid . ':' . $j);
        if (!is_array($g) || !array_key_exists('r', $g)) { $joursSans[] = $j; continue; }
        $joursAvec[] = $j;
        $regJ = array_values(array_filter($R['regles'], static fn ($r) => pvApplicable($r, $sid, $j)));
        foreach ((array) $g['r'] as $t) {
            if (!is_array($t) || !is_array($t['l'] ?? null)) { continue; }
            $res = pvTicket($t['l'], $regJ, $catId, $catNom);
            if ($res === []) { continue; }
            $tickets++;
            foreach ($res as $rid => $x) {
                $a =& $agg[$rid];
                $a = ($a ?? ['fois' => 0, 'tickets' => 0, 'q' => 0.0, 'v' => 0.0, 'r' => 0.0]);
                $a['fois'] += (int) ($x['fois'] ?? 0); $a['tickets']++;
                foreach ((array) ($x['l'] ?? []) as $k => [$q, $v, $rem]) {
                    $a['q'] += $q; $a['v'] += $v; $a['r'] += $rem;
                    $p =& $prod[(string) $k][(string) $rid];
                    $p = [($p[0] ?? 0) + $q, ($p[1] ?? 0) + $v, ($p[2] ?? 0) + $rem, ($p[3] ?? 0) + 1];
                    unset($p);
                }
                unset($a);
            }
        }
    }
    $regles = [];
    foreach ($agg as $rid => $a) {
        $r = $parId[$rid] ?? null;
        $regles[] = ['id' => $rid === 'x' ? 'x' : (int) $rid, 'nom' => $r['nom'] ?? 'Remise non reconnue', 'type' => $r['type'] ?? 'remise',
            'texte' => $r ? pvTexte($r) : 'remise sur la ligne qu’aucune promotion du panel n’explique', 'statut' => $r['statut'] ?? null,
            'fois' => $a['fois'], 'tickets' => $a['tickets'], 'q' => round($a['q'], 1), 'v' => round($a['v'], 2), 'r' => round($a['r'], 2)];
    }
    usort($regles, static fn ($a, $b) => (($a['id'] === 'x') <=> ($b['id'] === 'x')) ?: $b['v'] <=> $a['v']);
    $dernier = $jours === [] ? date('Y-m-d') : max($jours);
    $autres = [];
    foreach ($R['regles'] as $r) {
        if (isset($agg[$r['id']]) || !pvApplicable($r, $sid, $dernier)) { continue; }
        $autres[] = ['id' => $r['id'], 'nom' => $r['nom'], 'type' => $r['type'], 'texte' => pvTexte($r), 'statut' => $r['statut']];
    }
    foreach ($prod as $k => $parR) { foreach ($parR as $rid => $x) { $prod[$k][$rid] = [round($x[0], 2), round($x[1], 2), round($x[2], 2), $x[3]]; } }
    return ['erreur' => $R['erreur'], 'regles' => $regles, 'autres' => $autres, 'produits' => (object) $prod, 'tickets' => $tickets,
        'v' => round(array_sum(array_column($regles, 'v')), 2), 'r' => round(array_sum(array_column($regles, 'r')), 2), 'q' => round(array_sum(array_column($regles, 'q')), 1),
        'joursLus' => $joursAvec, 'joursSans' => $joursSans];
}
