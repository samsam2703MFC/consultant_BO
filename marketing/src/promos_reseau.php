<?php
declare(strict_types=1);

/**
 * Le Tableau d'analyse des promotions (26/10/2026, maquette A de docs/maquettes/promotions-cockpit, demande de Sam).
 *
 * La création des promotions quitte le dashboard du magasin : elle vit au cockpit, dans la section Promotions du rail
 * (Édition : l'assistant du dashboard, avec le magasin à choisir ; Tableau d'analyse : ici). Le tableau montre TOUTES
 * les promotions du panel — celles de la marque et celles réservées à un magasin —, et ce qu'elles ont fait sur 7 ou 30
 * jours dans les tickets déjà lus (`svP…` : les tickets à remise `r`, reconnus par pvTicket, comme l'onglet Promotions
 * des ventes et la carte « Où les promotions sont utilisées » du dashboard, magasin par magasin). Rien n'est lu au
 * panel en plus des règles gardées (pvRegles), des commandes du webshop (coCommandes, pour la part webshop) et des prix
 * et coûts du coefficient (pmProduits). Lecture seule.
 */

/** Les ventes d'un jour lu : la somme des produits heure par heure ({h: {pid: [nom, q, ventes, coût]}}). */
function prCaJour($p): float
{
    $ca = 0.0;
    foreach (is_array($p) ? $p : [] as $h) {
        foreach (is_array($h) ? $h : [] as $x) { if (is_array($x) && isset($x[2]) && is_numeric($x[2])) { $ca += (float) $x[2]; } }
    }
    return $ca;
}

/**
 * Un magasin sur ses jours, sans lecture : $J {jour: ['r' => tickets à remise, 'ca' => ventes] | null (pas lu)}, les
 * règles normalisées (pvNormaliser), $tx {n° de transaction: canal} (les tickets du webshop). Rend {regles: {id|'x':
 * {fois, tickets, v, r, web}}, ca, lus, sans} — fois : les fois où la promotion s'applique (au moins une par ticket qui
 * la porte, comme pmCanaux) ; v : ce que le client paie pour les pièces en promotion ; r : la remise ; web : les fois
 * sur un ticket du webshop.
 */
function prMagasin(array $J, array $regles, array $tx, array $catId, array $catNom, int $sid): array
{
    $agg = []; $ca = 0.0; $lus = 0; $sans = 0;
    foreach ($J as $j => $d) {
        if (!is_array($d)) { $sans++; continue; }
        $lus++;
        $ca += (float) ($d['ca'] ?? 0);
        $regJ = array_values(array_filter($regles, static fn ($r) => pvApplicable($r, $sid, (string) $j)));
        foreach ((array) ($d['r'] ?? []) as $t) {
            if (!is_array($t) || !is_array($t['l'] ?? null)) { continue; }
            $res = pvTicket($t['l'], $regJ, $catId, $catNom);
            if ($res === []) { continue; }
            $web = isset($tx[(int) ($t['t'] ?? 0)]);
            foreach ($res as $rid => $x) {
                $a =& $agg[(string) $rid];
                $a ??= ['fois' => 0, 'tickets' => 0, 'v' => 0.0, 'r' => 0.0, 'web' => 0];
                $n = max(1, (int) ($x['fois'] ?? 0));
                $a['fois'] += $n; $a['tickets']++;
                if ($web) { $a['web'] += $n; }
                foreach ((array) ($x['l'] ?? []) as $l) { $a['v'] += (float) ($l[1] ?? 0); $a['r'] += (float) ($l[2] ?? 0); }
                unset($a);
            }
        }
    }
    return ['regles' => $agg, 'ca' => round($ca, 2), 'lus' => $lus, 'sans' => $sans];
}

/**
 * L'état d'une promotion pour le tableau, dans les mots de la liste du dashboard : une promotion du magasin active au
 * panel est « en caisse » (inactive : un brouillon) ; celles de la marque suivent pmEtatPanel (active → en caisse).
 * Une période passée la termine, une période à venir l'annonce.
 */
function prEtat(array $r, string $auj, bool $duMagasin): string
{
    if (($r['au'] ?? null) !== null && $r['au'] < $auj) { return 'terminee'; }
    $actif = strtoupper((string) ($r['statut'] ?? '')) === 'ACTIVE';
    if (!$actif) { return $duMagasin ? 'brouillon' : 'inactive'; }
    return ($r['du'] ?? null) !== null && $r['du'] > $auj ? 'a_venir' : 'en_caisse';
}

/**
 * Le tableau, sans lecture : les règles du panel, les comptes de chaque magasin (prMagasin, par n° de magasin), les
 * magasins ouverts [{id, nom}], le premier jour de la fenêtre et aujourd'hui. `$coef(règle, magasins)` rend le
 * coefficient du lot ({lot, magasin, x, y}) ou null. Une promotion terminée avant la fenêtre et sans effet dedans
 * n'y est plus : le tableau n'est pas l'historique du panel.
 */
function prReseau(array $regles, array $par, array $mags, string $premier, string $auj, ?callable $coef = null): array
{
    $ids = array_map(static fn ($m) => (int) $m['id'], $mags);
    $zero = ['fois' => 0, 'tickets' => 0, 'v' => 0.0, 'r' => 0.0, 'web' => 0];
    $promos = [];
    foreach ($regles as $r) {
        $rid = (string) (int) ($r['id'] ?? 0);
        if ($rid === '0') { continue; }
        $shops = is_array($r['shops'] ?? null) ? array_values(array_map('intval', $r['shops'])) : null;
        $duMag = ($r['type'] ?? '') === 'buyxgety' && $shops !== null && count($shops) === 1;
        $etat = prEtat($r, $auj, $duMag);
        $s = $zero; $parMag = [];
        foreach ($par as $sid => $P) {
            $x = $P['regles'][$rid] ?? null;
            if ($x === null) { continue; }
            foreach ($zero as $k => $v) { $s[$k] += $x[$k]; }
            $parMag[(string) $sid] = ['fois' => $x['fois'], 'v' => round($x['v'], 2), 'r' => round($x['r'], 2)];
        }
        if ($etat === 'terminee' && $s['fois'] === 0 && (string) ($r['au'] ?? '') < $premier) { continue; }
        $c = null;
        if ($coef !== null && ($r['type'] ?? '') === 'buyxgety' && $etat !== 'terminee') { $c = $coef($r, $shops ?? $ids); }
        $promos[] = [
            'id' => (int) $rid, 'src' => $duMag ? 'mag' : 'marque', 'shop' => $duMag ? $shops[0] : null, 'shops' => $shops,
            'nom' => (string) ($r['nom'] ?? ''), 'type' => ($r['type'] ?? '') === 'bundle' ? 'bundle' : 'xy', 'etat' => $etat,
            'du' => $r['duH'] ?? (($r['du'] ?? null) !== null ? $r['du'] . ' 00:00' : null),
            'au' => $r['auH'] ?? (($r['au'] ?? null) !== null ? $r['au'] . ' 23:59' : null),
            'texte' => function_exists('pvTexte') ? pvTexte($r) : '',
            'canaux' => $r['canaux'] ?? null, 'seuil' => $r['seuil'] ?? null,
            'bon' => strtoupper((string) ($r['activation'] ?? '')) === 'VOUCHER_ONLY',
            'creneaux' => (array) ($r['creneaux'] ?? []), 'repetable' => !empty($r['repetable']), 'maxParTicket' => $r['maxParTicket'] ?? null,
            'coef' => $c,
            'fois' => $s['fois'], 'tickets' => $s['tickets'], 'v' => round($s['v'], 2), 'r' => round($s['r'], 2), 'web' => $s['web'],
            // Une promotion de la marque, magasin par magasin ; celle d'un magasin n'en a pas besoin.
            'parMagasin' => $duMag ? null : (object) $parMag,
        ];
    }
    usort($promos, static fn ($a, $b) => $b['fois'] <=> $a['fois'] ?: strcmp((string) $b['du'], (string) $a['du']));
    $x = $zero;
    foreach ($par as $P) { $y = $P['regles']['x'] ?? null; if ($y !== null) { $x['fois'] += $y['fois']; $x['r'] += $y['r']; } }
    $total = $zero + ['ca' => 0.0];
    foreach ($promos as $p) { foreach ($zero as $k => $v) { $total[$k] += $p[$k]; } }
    $magasins = [];
    foreach ($mags as $m) {
        $P = $par[(int) $m['id']] ?? $par[(string) $m['id']] ?? null;
        $total['ca'] += (float) ($P['ca'] ?? 0);
        $magasins[] = ['id' => (int) $m['id'], 'nom' => (string) $m['nom'], 'ca' => $P['ca'] ?? null, 'lus' => $P['lus'] ?? 0, 'sans' => $P['sans'] ?? 0,
            'commandes' => $P['commandes'] ?? null];
    }
    foreach (['v', 'r', 'ca'] as $k) { $total[$k] = round($total[$k], 2); }
    return ['promos' => $promos, 'magasins' => $magasins, 'total' => $total, 'nonReconnue' => ['fois' => $x['fois'], 'r' => round($x['r'], 2)]];
}

/**
 * GET /promotions/reseau?jours=30 (7 | 30)[&frais=1] — le Tableau d'analyse : {jours, du, au, promos, magasins, total,
 * nonReconnue, lu, luTs, erreurPanel, regles: {coefMin}}. Le cockpit le demande avec `_cache` (une lecture lente : les
 * tickets de chaque magasin, et les prix et coûts du coefficient).
 */
function ep_promotions_reseau(): array
{
    $n = (int) ($_GET['jours'] ?? 30) === 7 ? 7 : 30;
    $jours = [];
    for ($i = $n - 1; $i >= 0; $i--) { $jours[] = date('Y-m-d', strtotime('-' . $i . ' day')); }
    if (!function_exists('pvRegles') || !function_exists('pvTicket')) { http_response_code(503); return ['error' => 'la reconnaissance des promotions n’est pas chargée']; }
    $R = pvRegles(!empty($_GET['frais']));
    $catId = []; $catNom = function_exists('svCategories') ? svCategories() : [];
    if (function_exists('panelCatalogue')) { foreach ((array) (panelCatalogue()['produits'] ?? []) as $pid => $x) { if (!empty($x['catId'])) { $catId[(int) $pid] = (int) $x['catId']; } } }
    $mags = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id') as $s) { $mags[] = ['id' => (int) $s['id'], 'nom' => (string) preg_replace('/^.* - /', '', (string) $s['name'])]; } }
    catch (Throwable $e) { $mags = []; }
    $par = [];
    foreach ($mags as $m) {
        $sid = $m['id'];
        $J = [];
        foreach ($jours as $j) {
            $g = setting('svP' . $sid . ':' . $j);
            $J[$j] = is_array($g) && array_key_exists('r', $g) ? ['r' => (array) $g['r'], 'ca' => prCaJour($g['p'] ?? [])] : null;
        }
        $cmd = function_exists('coCommandes') ? coCommandes($sid, $jours[0]) : null;
        $tx = [];
        foreach ((array) $cmd as $c) { if (is_array($c) && in_array($c['canal'] ?? '', ['cc', 'liv'], true) && (int) ($c['tx'] ?? 0) > 0) { $tx[(int) $c['tx']] = $c['canal']; } }
        $par[$sid] = prMagasin($J, $R['regles'], $tx, $catId, $catNom, $sid) + ['commandes' => $cmd !== null];
    }
    $coef = static function (array $r, array $shops): ?array {
        if (!function_exists('pmDemande') || !function_exists('pmCoefMagasins')) { return null; }
        $d = pmDemande($r);
        $d['shops'] = $shops;
        [$c] = pmCoefMagasins($d);
        return $c === null ? null : ['lot' => $c['lot'], 'magasin' => $c['magasin'] ?? null, 'x' => $c['x'] ?? null, 'y' => $c['y'] ?? null];
    };
    $ts = (int) ($R['ts'] ?? 0);
    return ['jours' => $n, 'du' => $jours[0], 'au' => $jours[$n - 1]]
        + prReseau($R['regles'], $par, $mags, $jours[0], date('Y-m-d'), $coef)
        + ['lu' => $ts > 0 ? date('H:i', $ts) : null, 'luTs' => $ts, 'luJour' => $ts > 0 ? date('Y-m-d', $ts) : null,
            'erreurPanel' => $R['erreur'] ?? null, 'regles' => ['coefMin' => defined('PM_COEF_MIN') ? PM_COEF_MIN : 2.0]];
}
