<?php
declare(strict_types=1);

/**
 * Les canaux de vente et les offres.
 *
 *  - `GET /exploitation/canaux?shop=4&date=2026-10-02` — par où passent les
 *    commandes d'une journée : le comptoir (tickets caisse), le click &
 *    collect et la livraison (les commandes webshop du panel, retirées en
 *    boutique ou livrées). Les 14 derniers jours, la liste du jour, ce qui
 *    reste à préparer, demain. Sans `shop` : tout le réseau, magasin par
 *    magasin, sur `periode` = jour | 7 | 30.
 *  - `GET /exploitation/offres?shop=4&date=2026-10-02` — ce que les offres
 *    rapportent : les bundles (les produits de la catégorie « Bundle &
 *    Promotion » du panel, lus dans les tickets) et les promotions posées par
 *    le cockpit sur les jours creux (avec leur effet face à la référence des
 *    4 semaines d'avant). Une ligne par offre, un verdict. Sans `shop` : le
 *    réseau, les magasins en colonnes.
 *
 * LA SOURCE DES CANAUX, mesurée : `/shops/{id}/client-orders` du panel rend
 * chaque commande avec `is_webshop`, `fulfilment_mode`, `total_value`,
 * `pick_up_datetime`, `order_status`, `issuing_timestamp`, `id_transaction`.
 * Une commande webshop ENCAISSÉE EN CAISSE (`id_transaction` posé) est déjà
 * dans les tickets : le comptoir, c'est les tickets moins ces commandes-là ;
 * une commande payée en ligne s'ajoute. Rien de nominatif ne sort d'ici.
 */

const CO_CACHE_MIN = 10;          // les commandes du panel : dix minutes
const CO_BUNDLE = '/bundle|promotion/iu';   // la catégorie des bundles, dans le nom
const CO_SEUIL_MARGE = 50;        // sous ce taux, un bundle sans référence est « à ajuster »

/* --- les commandes du panel, normalisées ------------------------------------ */

/** Le canal d'une commande : comptoir (précommande au magasin), cc (webshop, retrait), liv (webshop, livraison). */
function coCanal(array $c): string
{
    $fm = strtolower(trim((string) ($c['fulfilment_mode'] ?? $c['fulfillment_mode'] ?? $c['mode'] ?? '')));
    $liv = preg_match('/deliv|livr|ship|office/', $fm) === 1;
    $web = !empty($c['is_webshop']) || $liv;
    if (!$web) { return 'compt'; }
    return $liv ? 'liv' : 'cc';
}

/** L'état lisible d'une commande, dans son canal. */
function coStatut(array $c, string $canal): string
{
    if (($c['non_collection_id_reason'] ?? null) !== null) { return 'annulée'; }
    $st = strtolower((string) ($c['order_status'] ?? ''));
    // Remise : le panel pose `issuing_timestamp` ; mesuré à Halle, une commande encaissée en
    // caisse (`id_transaction`) reste pourtant « new » — l'encaissement vaut remise.
    if (($c['issuing_timestamp'] ?? null) !== null || (int) ($c['id_transaction'] ?? 0) > 0 || in_array($st, ['picked_up', 'delivered', 'completed', 'done', 'issued'], true)) {
        return $canal === 'liv' ? 'livrée' : 'remise';
    }
    if (preg_match('/ship|transit|route|out_for/', $st) === 1) { return 'en route'; }
    if (($c['completion_timestamp'] ?? null) !== null || preg_match('/ready|prepared|prete|prête/', $st) === 1) { return 'prête'; }
    if (($c['accepting_timestamp'] ?? null) !== null || preg_match('/prepar|progress|processing/', $st) === 1) { return 'en préparation'; }
    return 'à préparer';
}

/**
 * Les commandes d'un magasin depuis `$depuis` (date de retrait), lues sur
 * l'API du panel et gardées dix minutes. Chaque ligne : quand, canal, montant,
 * articles, statut, encaissée (déjà dans un ticket caisse).
 */
function coCommandes(int $sid, string $depuis): ?array
{
    $cle = 'coCmd:' . $sid . ':' . $depuis;
    $memo = setting($cle);
    if (is_array($memo) && isset($memo['le'], $memo['v']) && (time() - (int) $memo['le']) < CO_CACHE_MIN * 60) { return $memo['v']; }
    if (!PanelApi::configured()) { return null; }
    $r = PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $depuis, 25);
    if ((int) ($r['code'] ?? 0) !== 200) { return is_array($memo) && isset($memo['v']) ? $memo['v'] : null; }
    $out = [];
    foreach (analyseListe(is_array($r['corps']) ? $r['corps'] : []) as $c) {
        if (!is_array($c)) { continue; }
        $quand = (string) ($c['pick_up_datetime'] ?? '');
        if ($quand === '' || substr($quand, 0, 10) < $depuis) { continue; }
        $canal = coCanal($c);
        $statut = coStatut($c, $canal);
        if ($statut === 'annulée') { continue; }
        $prods = (array) ($c['products'] ?? []);
        $articles = null;
        if ($prods !== []) {
            $q = 0.0; $aQte = false;
            foreach ($prods as $p) { if (is_array($p) && isset($p['quantity'])) { $q += (float) $p['quantity']; $aQte = true; } }
            $articles = $aQte ? round($q, 1) : (float) count($prods);
        }
        $out[] = ['quand' => substr($quand, 0, 16), 'canal' => $canal, 'montant' => round((float) ($c['total_value'] ?? 0), 2),
            'articles' => $articles, 'statut' => $statut, 'encaissee' => (int) ($c['id_transaction'] ?? 0) > 0];
    }
    usort($out, static fn ($a, $b) => strcmp($a['quand'], $b['quand']));
    try {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$cle, json_encode(['le' => time(), 'v' => $out], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* sans mémo */ }
    return $out;
}

/** La caisse d'un magasin jour par jour (CA, tickets) sur une liste de jours, depuis les heures gravées. */
function coCaisse(int $sid, array $jours): array
{
    $out = [];
    foreach (svHeuresJours($sid, $jours) as $j => $hs) {
        $ca = 0.0; $tk = 0;
        foreach ((array) $hs as $l) { $ca += (float) ($l['ca'] ?? 0); $tk += (int) ($l['tickets'] ?? 0); }
        $out[$j] = ['ca' => round($ca, 2), 'tickets' => $tk];
    }
    return $out;
}

/** Les jours d'une période finissant à `$date` : jour | 7 | 30. */
function coJours(string $date, string $periode): array
{
    $n = $periode === '7' ? 7 : ($periode === '30' ? 30 : 1);
    $jours = [];
    for ($i = $n - 1; $i >= 0; $i--) { $jours[] = date('Y-m-d', strtotime($date . ' -' . $i . ' day')); }
    return $jours;
}

/** Un magasin sur des jours : comptoir, click & collect, livraison, et la série jour par jour. */
function coMagasin(int $sid, array $jours, ?array $cmds): array
{
    $caisse = coCaisse($sid, $jours);
    $serie = []; $tot = ['caisse' => 0.0, 'tickets' => 0, 'comptoir' => 0.0, 'cc' => ['n' => 0, 'ca' => 0.0], 'liv' => ['n' => 0, 'ca' => 0.0], 'encaisse' => 0.0];
    $parJour = [];
    foreach ((array) $cmds as $c) {
        $j = substr($c['quand'], 0, 10);
        if (!isset($parJour[$j])) { $parJour[$j] = ['cc' => [0, 0.0], 'liv' => [0, 0.0], 'enc' => 0.0]; }
        if ($c['canal'] === 'compt') { continue; }
        $parJour[$j][$c['canal']][0]++; $parJour[$j][$c['canal']][1] += $c['montant'];
        if ($c['encaissee']) { $parJour[$j]['enc'] += $c['montant']; }
    }
    foreach ($jours as $j) {
        $k = $caisse[$j] ?? null; $w = $parJour[$j] ?? ['cc' => [0, 0.0], 'liv' => [0, 0.0], 'enc' => 0.0];
        $lu = $k !== null;
        $comptoir = $lu ? max(0.0, $k['ca'] - $w['enc']) : null;
        $serie[] = ['j' => $j, 'lu' => $lu, 'caisse' => $lu ? $k['ca'] : null, 'tickets' => $lu ? $k['tickets'] : null,
            'comptoir' => $comptoir === null ? null : round($comptoir, 2), 'cc' => round($w['cc'][1], 2), 'liv' => round($w['liv'][1], 2),
            'ccN' => $w['cc'][0], 'livN' => $w['liv'][0]];
        if ($lu) { $tot['caisse'] += $k['ca']; $tot['tickets'] += $k['tickets']; $tot['comptoir'] += $comptoir; }
        $tot['cc']['n'] += $w['cc'][0]; $tot['cc']['ca'] += $w['cc'][1]; $tot['liv']['n'] += $w['liv'][0]; $tot['liv']['ca'] += $w['liv'][1]; $tot['encaisse'] += $w['enc'];
    }
    $web = $tot['cc']['ca'] + $tot['liv']['ca'];
    $total = $tot['comptoir'] + $web;
    return ['joursLus' => count(array_filter($serie, static fn ($s) => $s['lu'])), 'jours' => count($jours),
        'caisse' => round($tot['caisse'], 2), 'tickets' => $tot['tickets'], 'comptoir' => round($tot['comptoir'], 2),
        'cc' => ['n' => $tot['cc']['n'], 'ca' => round($tot['cc']['ca'], 2)], 'liv' => ['n' => $tot['liv']['n'], 'ca' => round($tot['liv']['ca'], 2)],
        'webshop' => round($web, 2), 'encaisse' => round($tot['encaisse'], 2), 'total' => round($total, 2),
        'part' => $total > 0 ? round(100 * $web / $total, 1) : null, 'serie' => $serie,
        'indispo' => $cmds === null];
}

/** GET /exploitation/canaux */
function ep_exploitation_canaux(): array
{
    $auj = date('Y-m-d');
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $periode = (string) ($_GET['periode'] ?? 'jour');
    if (!in_array($periode, ['jour', '7', '30'], true)) { $periode = 'jour'; }
    $shop = (int) ($_GET['shop'] ?? 0);
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    @set_time_limit(120);
    if ($shop > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) && $du <= $au && $du <= $auj) {
        return coCanauxPeriode($shop, $du, $au, $auj);
    }
    if ($shop > 0) {
        // Un magasin : la journée, les 14 derniers jours, la liste du jour, demain.
        $jours14 = []; for ($i = 13; $i >= 0; $i--) { $jours14[] = date('Y-m-d', strtotime($date . ' -' . $i . ' day')); }
        $cmds = coCommandes($shop, $jours14[0]);
        $m14 = coMagasin($shop, $jours14, $cmds);
        $mJ = coMagasin($shop, [$date], $cmds);
        $demain = date('Y-m-d', strtotime($date . ' +1 day'));
        $liste = []; $aPreparer = 0; $dem = ['n' => 0, 'ca' => 0.0];
        // La liste du jour porte TOUTES les commandes, précommandes au comptoir comprises : c'est
        // ce que le magasin doit préparer. Le split, lui, ne compte que le webshop.
        foreach ((array) $cmds as $c) {
            $j = substr($c['quand'], 0, 10);
            if ($j === $date) {
                $liste[] = ['heure' => substr($c['quand'], 11, 5), 'canal' => $c['canal'], 'articles' => $c['articles'], 'montant' => $c['montant'], 'statut' => $c['statut']];
                if (in_array($c['statut'], ['à préparer', 'en préparation'], true)) { $aPreparer++; }
            } elseif ($j === $demain) { $dem['n']++; $dem['ca'] += $c['montant']; }
        }
        $nWeb = count(array_filter($liste, static fn ($c) => $c['canal'] !== 'compt'));
        return ['shop' => (string) $shop, 'date' => $date, 'jour' => $mJ, 'serie' => $m14['serie'], 'quatorze' => ['webshop' => $m14['webshop'], 'total' => $m14['total'], 'cc' => $m14['cc'], 'liv' => $m14['liv']],
            'liste' => $liste, 'nWebshop' => $nWeb, 'aPreparer' => $aPreparer, 'demain' => ['n' => $dem['n'], 'ca' => round($dem['ca'], 2)],
            'indispo' => $cmds === null, 'source' => $cmds === null ? 'panel muet' : 'commandes du panel + tickets caisse'];
    }
    // Le réseau : chaque magasin sur la période.
    $jours = coJours($date, $periode);
    $noms = jcMagasins();
    $out = ['date' => $date, 'periode' => $periode, 'du' => $jours[0], 'au' => $jours[count($jours) - 1], 'magasins' => [], 'reseau' => null];
    $r = ['comptoir' => 0.0, 'cc' => ['n' => 0, 'ca' => 0.0], 'liv' => ['n' => 0, 'ca' => 0.0], 'livrent' => 0, 'vendent' => 0];
    foreach ($noms as $sid => $nom) {
        $m = coMagasin((int) $sid, $jours, coCommandes((int) $sid, $jours[0]));
        $m['shop'] = (string) $sid; $m['nom'] = $nom; unset($m['serie']);
        $out['magasins'][] = $m;
        $r['comptoir'] += $m['comptoir']; $r['cc']['n'] += $m['cc']['n']; $r['cc']['ca'] += $m['cc']['ca']; $r['liv']['n'] += $m['liv']['n']; $r['liv']['ca'] += $m['liv']['ca'];
        if ($m['liv']['n']) { $r['livrent']++; }
        if ($m['cc']['n'] || $m['liv']['n']) { $r['vendent']++; }
    }
    $web = $r['cc']['ca'] + $r['liv']['ca']; $total = $r['comptoir'] + $web;
    $out['reseau'] = ['comptoir' => round($r['comptoir'], 2), 'cc' => ['n' => $r['cc']['n'], 'ca' => round($r['cc']['ca'], 2)], 'liv' => ['n' => $r['liv']['n'], 'ca' => round($r['liv']['ca'], 2)],
        'webshop' => round($web, 2), 'total' => round($total, 2), 'part' => $total > 0 ? round(100 * $web / $total, 1) : null, 'livrent' => $r['livrent'], 'vendent' => $r['vendent']];
    return $out;
}

/**
 * Un magasin sur une période close ou en cours (la semaine, le mois du dashboard) : le split
 * sur les jours lus, la série jour par jour (les jours à venir portent déjà les commandes
 * prises), le compte par jour, par semaine au-delà de quinze jours, ce qui reste à préparer
 * et ce qui est déjà pris pour la suite.
 */
function coCanauxPeriode(int $sid, string $du, string $au, string $auj): array
{
    $jours = []; for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $jours[] = $d; if (count($jours) > 62) { break; } }
    $lus = array_values(array_filter($jours, static fn ($j) => $j <= $auj));
    $cmds = coCommandes($sid, $du);
    $m = coMagasin($sid, $lus, $cmds);
    $serie = $m['serie']; unset($m['serie']);
    $parJourW = [];
    foreach ((array) $cmds as $c) { $j = substr($c['quand'], 0, 10); $parJourW[$j][] = $c; }
    // Les jours à venir : pas de caisse, mais les commandes déjà prises.
    foreach ($jours as $j) {
        if ($j <= $auj) { continue; }
        $w = ['cc' => [0, 0.0], 'liv' => [0, 0.0]];
        foreach ($parJourW[$j] ?? [] as $c) { if ($c['canal'] !== 'compt') { $w[$c['canal']][0]++; $w[$c['canal']][1] += $c['montant']; } }
        $serie[] = ['j' => $j, 'lu' => false, 'caisse' => null, 'tickets' => null, 'comptoir' => null, 'cc' => round($w['cc'][1], 2), 'liv' => round($w['liv'][1], 2), 'ccN' => $w['cc'][0], 'livN' => $w['liv'][0]];
    }
    $maintenant = date('Y-m-d H:i');
    $parJour = []; $liste = []; $aPreparer = 0; $aVenir = ['n' => 0, 'ca' => 0.0]; $nTot = 0; $nWeb = 0;
    foreach ($jours as $j) {
        $L = $parJourW[$j] ?? [];
        $pj = ['j' => $j, 'lu' => $j <= $auj, 'n' => count($L), 'compt' => 0, 'ccN' => 0, 'cc' => 0.0, 'livN' => 0, 'liv' => 0.0, 'aPreparer' => 0];
        foreach ($L as $c) {
            $nTot++;
            if ($c['canal'] === 'compt') { $pj['compt']++; } else { $nWeb++; $pj[$c['canal'] . 'N']++; $pj[$c['canal']] += $c['montant']; }
            $ouverte = in_array($c['statut'], ['à préparer', 'en préparation'], true);
            if ($ouverte && $c['quand'] <= $maintenant) { $pj['aPreparer']++; $aPreparer++; }
            if ($c['quand'] > $maintenant) { $aVenir['n']++; $aVenir['ca'] += $c['montant']; }
            if (count($jours) <= 7) { $liste[] = ['jour' => $j, 'heure' => substr($c['quand'], 11, 5), 'canal' => $c['canal'], 'articles' => $c['articles'], 'montant' => $c['montant'], 'statut' => $c['statut']]; }
        }
        $pj['cc'] = round($pj['cc'], 2); $pj['liv'] = round($pj['liv'], 2);
        $parJour[] = $pj;
    }
    // Par semaine, quand la période dépasse quinze jours : le lundi de chaque semaine.
    $parSemaine = [];
    if (count($jours) > 15) {
        $S = [];
        foreach ($serie as $x) {
            $lundi = date('Y-m-d', strtotime($x['j'] . ' monday this week'));
            // Les bornes de la semaine restent dans la période : la première et la dernière sont tronquées.
            if (!isset($S[$lundi])) { $S[$lundi] = ['du' => $x['j'], 'au' => $x['j'], 'joursLus' => 0, 'caisse' => 0.0, 'comptoir' => 0.0, 'ccN' => 0, 'cc' => 0.0, 'livN' => 0, 'liv' => 0.0]; }
            if ($x['j'] > $S[$lundi]['au']) { $S[$lundi]['au'] = $x['j']; }
            if ($x['lu']) { $S[$lundi]['joursLus']++; $S[$lundi]['caisse'] += (float) $x['caisse']; $S[$lundi]['comptoir'] += (float) $x['comptoir']; }
            $S[$lundi]['ccN'] += $x['ccN']; $S[$lundi]['cc'] += $x['cc']; $S[$lundi]['livN'] += $x['livN']; $S[$lundi]['liv'] += $x['liv'];
        }
        foreach ($S as $w) { $web = $w['cc'] + $w['liv']; $tot = $w['comptoir'] + $web; $w['webshop'] = round($web, 2); $w['part'] = $tot > 0 && $w['joursLus'] > 0 ? round(100 * $web / $tot, 1) : null; $w['caisse'] = round($w['caisse'], 2); $w['comptoir'] = round($w['comptoir'], 2); $w['cc'] = round($w['cc'], 2); $w['liv'] = round($w['liv'], 2); $parSemaine[] = $w; }
    }
    return ['shop' => (string) $sid, 'du' => $du, 'au' => $au, 'periode' => $m + ['du' => $du, 'au' => $au, 'joursLus' => $m['joursLus'], 'joursEcoules' => count($lus)],
        'serie' => $serie, 'parJour' => $parJour, 'parSemaine' => $parSemaine, 'liste' => $liste, 'nCommandes' => $nTot, 'nWebshop' => $nWeb,
        'aPreparer' => $aPreparer, 'aVenir' => ['n' => $aVenir['n'], 'ca' => round($aVenir['ca'], 2)],
        'indispo' => $cmds === null, 'source' => $cmds === null ? 'panel muet' : 'commandes du panel + tickets caisse'];
}

/* --- les offres : bundles et promotions ------------------------------------------- */

/** Les ventes des bundles d'un magasin, jour par jour sur `$jours` : {pid: {nom, jours: {j: [q, v, c|null]}}}. */
function coBundlesJours(int $sid, array $jours, int &$cout, int $budget, array &$joursLus, float $tempsMax = SV_TEMPS_DEMANDE): array
{
    $cats = svCategories();
    $ids = [];
    foreach ($cats as $pid => $nom) { if (preg_match(CO_BUNDLE, (string) $nom) === 1) { $ids[(int) $pid] = true; } }
    $out = [];
    if ($ids === []) { return $out; }
    $t0 = microtime(true);
    foreach (array_reverse($jours) as $j) {
        if ($j < SV_DEBUT) { continue; }
        $dejaLu = svGraveValide(setting('svP' . $sid . ':' . $j), 'p', $j);
        if (!$dejaLu && microtime(true) - $t0 > $tempsMax) { continue; }
        $p = svProduitsJour($sid, $j, $cout, $budget);
        if ($p === null) { continue; }
        $joursLus[$j] = true;
        foreach ($p as $h => $lst) {
            foreach ((array) $lst as $pid => $x) {
                if (!isset($ids[(int) $pid])) { continue; }
                if (!isset($out[$pid])) { $out[$pid] = ['nom' => svNomProduit($pid, (string) $x[0]), 'jours' => []]; }
                if (!isset($out[$pid]['jours'][$j])) { $out[$pid]['jours'][$j] = [0.0, 0.0, 0.0]; }
                $out[$pid]['jours'][$j][0] += $x[1]; $out[$pid]['jours'][$j][1] += $x[2];
                if ($x[3] === null) { $out[$pid]['jours'][$j][2] = null; } elseif ($out[$pid]['jours'][$j][2] !== null) { $out[$pid]['jours'][$j][2] += $x[3]; }
            }
        }
    }
    return $out;
}

/** Le verdict d'un bundle : trop tôt, garder, ajuster, arrêter — face aux 4 semaines d'avant, ou à sa marge. */
function coVerdictBundle(int $joursLus, ?float $delta, ?float $marge): array
{
    if ($joursLus < 5) { return ['tot', 'trop tôt']; }
    if ($delta !== null) { return $delta >= 8 ? ['garder', 'garder'] : ($delta >= -3 ? ['ajuster', 'ajuster'] : ['arreter', 'arrêter ou revoir']); }
    if ($marge === null) { return ['tot', 'coût matière inconnu']; }
    return $marge >= CO_SEUIL_MARGE ? ['garder', 'garder'] : ['ajuster', 'ajuster'];
}

/** Les offres d'un magasin : bundles (tickets) et promotions (jours creux), sur la période, avec le 7 jours et le jour. */
function coOffresMagasin(int $sid, string $date, string $periode, int &$cout, float $tempsMax = SV_TEMPS_DEMANDE, ?string $du = null, ?string $au = null): array
{
    $auj = date('Y-m-d');
    if ($du !== null && $au !== null) {
        // La période du dashboard (la semaine, le mois), jusqu'à aujourd'hui au plus.
        $jours = []; for ($d = $du; $d <= $au && $d <= $auj; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $jours[] = $d; if (count($jours) > 62) { break; } }
        if ($jours === []) { $jours = [$du]; }
        $date = $jours[count($jours) - 1];
    } else { $jours = coJours($date, $periode); }
    $sept = coJours($date, '7');
    // La fenêtre qui porte la marge, la tendance et le verdict : la période dès qu'elle fait
    // sept jours, sinon les sept derniers jours. La référence : les 4 semaines d'avant.
    $fen = count($jours) >= 7 ? $jours : $sept;
    $refJours = []; for ($i = 28; $i >= 1; $i--) { $refJours[] = date('Y-m-d', strtotime($fen[0] . ' -' . $i . ' day')); }
    $fenetre = array_values(array_unique(array_merge($refJours, $fen, $sept, $jours)));
    sort($fenetre);
    $lus = [];
    $B = coBundlesJours($sid, $fenetre, $cout, SV_BUDGET_DEMANDE, $lus, $tempsMax);
    $caisse = coCaisse($sid, $jours);
    $caPeriode = 0.0; foreach ($caisse as $k) { $caPeriode += $k['ca']; }
    $offres = [];
    $fenLus = count(array_filter($fen, static fn ($j) => isset($lus[$j])));
    foreach ($B as $pid => $b) {
        $som = static function (array $js) use ($b): array { $q = 0.0; $v = 0.0; $c = 0.0; $cInc = false; foreach ($js as $j) { $x = $b['jours'][$j] ?? null; if ($x === null) { continue; } $q += $x[0]; $v += $x[1]; if ($x[2] === null) { $cInc = true; } else { $c += $x[2]; } } return [$q, $v, $cInc ? null : $c]; };
        [$qP, $vP, $cP] = $som($jours); [$q7, $v7, $c7] = $som($sept); [$qJ, $vJ] = $som([$date]); [$qR, $vR] = $som($refJours); [$qF, $vF, $cF] = $som($fen);
        $marge = ($cF !== null && $vF > 0) ? round(100 * ($vF - $cF) / $vF, 1) : null;
        $joursVendus = array_keys(array_filter($b['jours'], static fn ($x) => $x[0] > 0));
        sort($joursVendus);
        $depuis = $joursVendus[0] ?? null;
        $refLus = count(array_filter($refJours, static fn ($j) => isset($lus[$j])));
        $refParJour = $refLus > 0 && $qR > 0 ? $qR / $refLus : null;
        $delta = ($refParJour !== null && $fenLus > 0) ? round(100 * (($qF / $fenLus) - $refParJour) / $refParJour, 1) : null;
        // Un bundle qui n'a rien vendu ni sur la période ni sur la fenêtre n'est plus une offre en cours.
        if ($qP <= 0 && $qF <= 0) { continue; }
        [$verdict, $lib] = coVerdictBundle(count(array_filter($joursVendus, static fn ($j) => $j >= $fen[0] && $j <= $fen[count($fen) - 1])), $delta, $marge);
        $mot = $delta !== null ? 'de pièces par jour face aux 4 semaines d’avant'
            : ($depuis !== null && $depuis >= $refJours[0] ? 'nouveau : pas de référence' : 'pas de vente sur les 4 semaines d’avant');
        if ($marge !== null) { $mot .= ' · marge ' . number_format($marge, 0, ',', ' ') . ' %'; }
        $offres[] = ['type' => 'bundle', 'id' => 'b' . $pid, 'nom' => $b['nom'], 'regle' => 'bundle du panel', 'canaux' => ['comptoir'], 'depuis' => $depuis,
            'periode' => ['pieces' => round($qP, 1), 'ca' => round($vP, 2)], 'auj' => ['pieces' => round($qJ, 1), 'ca' => round($vJ, 2)], 'sept' => ['pieces' => round($q7, 1), 'ca' => round($v7, 2)],
            'fen' => ['pieces' => round($qF, 1), 'ca' => round($vF, 2)],
            'marge' => $marge, 'coef' => ($marge !== null && $marge < 100) ? round(1 / (1 - $marge / 100), 2) : null,
            'spark' => array_map(static fn ($j) => round(($b['jours'][$j][0] ?? 0.0), 1), $fen),
            'delta' => $delta, 'verdict' => $verdict, 'verdictLib' => $lib, 'mot' => $mot];
    }
    // Les promotions des jours creux : en cours, ou finies depuis moins de 30 jours.
    if (function_exists('ensureJoursCreux')) {
        ensureJoursCreux();
        $limite = date('Y-m-d', strtotime($date . ' -30 day'));
        try {
            foreach (Db::rows("SELECT * FROM ceo_promo WHERE shop_id = ? AND statut IN ('en_cours','terminee','arretee') AND au >= ? AND du <= ? ORDER BY du DESC, id DESC", [(string) $sid, $limite, $date]) as $r) {
                $p = jcPromoLigne($r); $e = jcEffet($p, $date);
                $nh = max(1, $p['heureA'] - $p['heureDe'] + 1);
                $caDe = static function (array $js) use ($e, $nh): float { $s = 0.0; foreach ($js as $j) { $s += (float) (($e['parJour'][$j]['caH'] ?? 0) * $nh); } return $s; };
                $offres[] = ['type' => 'promo', 'id' => 'p' . $p['id'], 'nom' => $p['nom'], 'regle' => trim(($p['regle'] ?: $p['offre']) . ' · ' . jcNomBloc($p['jours'], range($p['heureDe'], $p['heureA'])), ' ·'),
                    'canaux' => $p['canaux'] ?: ['comptoir'], 'depuis' => $p['du'], 'au' => $p['au'], 'statut' => $p['statut'], 'levier' => $p['levier'],
                    'periode' => ['pieces' => null, 'ca' => round($caDe($jours), 2)], 'auj' => ['pieces' => null, 'ca' => round($caDe([$date]), 2)], 'sept' => ['pieces' => null, 'ca' => round($caDe($sept), 2)],
                    'fen' => ['pieces' => null, 'ca' => round($caDe($fen), 2)],
                    'marge' => null, 'coef' => null, 'spark' => array_map(static fn ($j) => round((float) (($e['parJour'][$j]['caH'] ?? 0)), 1), $fen),
                    'delta' => $e['deltaCaPct'], 'deltaTk' => $e['deltaTkPct'], 'verdict' => $e['verdict'], 'verdictLib' => $e['verdictLib'],
                    'mot' => $e['caH'] !== null ? ($e['deltaCaPct'] !== null ? 'de CA/h sur le créneau · ' : '') . number_format($e['caH'], 0, ',', ' ') . ' €/h contre ' . number_format((float) ($p['ref']['caH'] ?? 0), 0, ',', ' ') . ' €/h en référence · ' . $e['joursLus'] . ' jour' . ($e['joursLus'] > 1 ? 's' : '') . ' lu' . ($e['joursLus'] > 1 ? 's' : '') : 'pas encore de jour lu'];
            }
        } catch (PDOException $e) { /* sans table : pas de promotion */ }
    }
    usort($offres, static fn ($a, $b) => ($b['periode']['ca'] <=> $a['periode']['ca']) ?: strcmp($a['nom'], $b['nom']));
    $caOff = 0.0; $caOffJ = 0.0; $pieces = 0.0; $mV = 0.0; $mM = 0.0;
    foreach ($offres as $o) {
        $caOff += $o['periode']['ca']; $caOffJ += $o['auj']['ca']; $pieces += (float) ($o['periode']['pieces'] ?? 0);
        if ($o['marge'] !== null) { $mV += $o['fen']['ca']; $mM += $o['fen']['ca'] * $o['marge'] / 100; }
    }
    return ['offres' => $offres, 'fen' => ['du' => $fen[0], 'au' => $fen[count($fen) - 1], 'jours' => count($fen), 'lus' => $fenLus],
        'periodeJours' => ['du' => $jours[0], 'au' => $jours[count($jours) - 1], 'jours' => count($jours), 'lus' => count(array_filter($jours, static fn ($j) => isset($lus[$j])))],
        'kpi' => ['ca' => round($caOff, 2), 'caJour' => round($caOffJ, 2), 'pieces' => round($pieces, 1), 'caPeriode' => round($caPeriode, 2),
        'part' => $caPeriode > 0 ? round(100 * $caOff / $caPeriode, 1) : null, 'marge' => $mV > 0 ? round(100 * $mM / $mV, 1) : null,
        'bundles' => count(array_filter($offres, static fn ($o) => $o['type'] === 'bundle')), 'promos' => count(array_filter($offres, static fn ($o) => $o['type'] === 'promo')),
        'aAjuster' => count(array_filter($offres, static fn ($o) => in_array($o['verdict'], ['ajuster', 'arreter'], true))),
        'joursLus' => count($lus), 'ticketsLus' => $cout]];
}

/** GET /exploitation/offres */
function ep_exploitation_offres(): array
{
    $auj = date('Y-m-d');
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $periode = (string) ($_GET['periode'] ?? '7');
    if (!in_array($periode, ['jour', '7', '30'], true)) { $periode = '7'; }
    $shop = (int) ($_GET['shop'] ?? 0);
    @set_time_limit(180);
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    $parBornes = preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) && $du <= $au && $du <= $auj;
    if ($shop > 0) {
        $cout = 0;
        if ($parBornes) { return ['shop' => (string) $shop, 'date' => $date, 'periode' => 'bornes', 'du' => $du, 'au' => $au] + coOffresMagasin($shop, $date, $periode, $cout, SV_TEMPS_DEMANDE, $du, $au); }
        return ['shop' => (string) $shop, 'date' => $date, 'periode' => $periode] + coOffresMagasin($shop, $date, $periode, $cout);
    }
    // Le réseau : les offres en lignes, les magasins en colonnes.
    $noms = jcMagasins();
    $lignes = []; $mags = []; $kpi = ['ca' => 0.0, 'caJour' => 0.0, 'caPeriode' => 0.0, 'aAjuster' => 0];
    $cout = 0; $parMag = max(6.0, SV_TEMPS_DEMANDE / max(1, count($noms)));
    foreach ($noms as $sid => $nom) {
        $m = coOffresMagasin((int) $sid, $date, $periode, $cout, $parMag);
        $mags[] = ['shop' => (string) $sid, 'nom' => $nom, 'kpi' => $m['kpi']];
        $kpi['ca'] += $m['kpi']['ca']; $kpi['caJour'] += $m['kpi']['caJour']; $kpi['caPeriode'] += $m['kpi']['caPeriode']; $kpi['aAjuster'] += $m['kpi']['aAjuster'];
        foreach ($m['offres'] as $o) {
            $k = $o['type'] . '|' . mb_strtolower($o['nom']);
            if (!isset($lignes[$k])) { $lignes[$k] = ['type' => $o['type'], 'nom' => $o['nom'], 'regle' => $o['regle'], 'canaux' => $o['canaux'], 'magasins' => [], 'pieces' => 0.0, 'ca' => 0.0]; }
            $lignes[$k]['magasins'][(string) $sid] = ['pieces' => $o['periode']['pieces'], 'ca' => $o['periode']['ca'], 'verdict' => $o['verdict'], 'verdictLib' => $o['verdictLib'], 'mot' => $o['mot'], 'delta' => $o['delta']];
            $lignes[$k]['pieces'] += (float) ($o['periode']['pieces'] ?? 0); $lignes[$k]['ca'] += $o['periode']['ca'];
        }
    }
    $L = array_values($lignes);
    usort($L, static fn ($a, $b) => $b['ca'] <=> $a['ca']);
    return ['date' => $date, 'periode' => $periode, 'offres' => $L, 'magasins' => $mags,
        'kpi' => ['ca' => round($kpi['ca'], 2), 'caJour' => round($kpi['caJour'], 2), 'part' => $kpi['caPeriode'] > 0 ? round(100 * $kpi['ca'] / $kpi['caPeriode'], 1) : null,
            'bundles' => count(array_filter($L, static fn ($o) => $o['type'] === 'bundle')), 'promos' => count(array_filter($L, static fn ($o) => $o['type'] === 'promo')), 'aAjuster' => $kpi['aAjuster']]];
}

/**
 * GET /exploitation/bundles/sonde — la forme des promotions « bundle » et « buy X get Y » du panel (10/10/2026),
 * lecture seule : la liste, le détail des deux premiers bundles, et le contrat Swagger des routes de promotions.
 */
function ep_bundles_sonde(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $out = ['routes' => []];
    // ?admin=1 (10/10/2026) : les mêmes routes avec le compte ADMIN du panel (ErpApi, même serveur, réalm admin) :
    // le compte consultant reçoit 403 sur /admin/promotions. Lecture seule ; les noms, règles et produits seulement.
    if (!empty($_GET['admin'])) {
        if (!class_exists('ErpApi') || !ErpApi::disponible()) { return ['admin' => 'compte admin non configuré']; }
        $propre = static function ($x) use (&$propre) { if (!is_array($x)) { return $x; } $o = []; foreach ($x as $k => $v) { if (is_string($k) && preg_match('/password|token|secret|phone|email|mail/i', $k)) { continue; } $o[$k] = $propre($v); } return $o; };
        // &detail=11-18 : le détail de chaque promotion (produits et catégories qui déclenchent, qui reçoivent).
        if (preg_match('/^(\d{1,4})-(\d{1,4})$/', (string) ($_GET['detail'] ?? ''), $m)) {
            for ($id = (int) $m[1]; $id <= min((int) $m[2], (int) $m[1] + 30); $id++) {
                foreach (['buy-x-get-y', 'bundles'] as $type) {
                    ErpApi::$lastError = null; $r = ErpApi::get('/admin/promotions/' . $type . '/' . $id);
                    $out['detail'][$type][$id] = ['erreur' => ErpApi::$lastError, 'corps' => $propre($r)];
                }
            }
            return $out;
        }
        foreach (['bundles' => '/admin/promotions/bundles?limit=100', 'buy-x-get-y' => '/admin/promotions/buy-x-get-y?limit=100', 'quantity' => '/admin/promotions/quantity?limit=100', 'remises-programmees' => '/admin/promotions/scheduled-product-discount?limit=100'] as $nom => $ch) {
            ErpApi::$lastError = null; $r = ErpApi::get($ch);
            $l = is_array($r) ? (array_is_list($r) ? $r : ($r['items'] ?? $r['data'] ?? $r['results'] ?? $r)) : [];
            $out['admin'][$nom] = ['chemin' => $ch, 'erreur' => ErpApi::$lastError, 'n' => is_array($l) ? count($l) : 0, 'liste' => $propre(is_array($l) ? array_slice($l, 0, 40) : $l)];
        }
        return $out;
    }
    // ?ids=1-12 (10/10/2026) : le détail par identifiant (GET …/{id}), quand la liste est refusée.
    if (preg_match('/^(\d{1,4})-(\d{1,4})$/', (string) ($_GET['ids'] ?? ''), $m)) {
        $de = (int) $m[1]; $a = min((int) $m[2], $de + 30);
        foreach (['buy-x-get-y', 'bundles', 'quantity'] as $type) {
            for ($id = $de; $id <= $a; $id++) {
                $r = PanelApi::sondeGet('/admin/promotions/' . $type . '/' . $id, 10); $c = is_array($r['corps'] ?? null) ? $r['corps'] : [];
                $x = isset($c['data']) && is_array($c['data']) ? $c['data'] : $c;
                $out['parId'][$type][$id] = ['code' => $r['code'] ?? null, 'nom' => $x['name'] ?? null, 'statut' => $x['status'] ?? null, 'type' => $x['promotion_type'] ?? null,
                    'erreur' => $x['description'] ?? null, 'cles' => array_slice(array_keys($x), 0, 30)];
            }
        }
        return $out;
    }
    $lus = [];
    foreach (['bundles' => '/admin/promotions/bundles', 'bundles-actifs' => '/admin/promotions/bundles?active=1', 'buy-x-get-y' => '/admin/promotions/buy-x-get-y', 'promotions' => '/admin/promotions',
        // 10/10/2026 : les promotions par quantité (« 2 pour 5,90 € ») et les remises programmées, filtrées sur un magasin aussi.
        'quantity' => '/admin/promotions/quantity', 'quantity-halle' => '/admin/promotions/quantity?id_shop=4', 'remises-programmees' => '/admin/promotions/scheduled-product-discount',
        'buy-x-get-y-halle' => '/admin/promotions/buy-x-get-y?id_shop=4', 'bundles-halle' => '/admin/promotions/bundles?id_shop=4'] as $nom => $ch) {
        $r = PanelApi::sondeGet($ch, 20);
        $lus[$nom] = $r['corps'];
        $l = PanelApi::liste(is_array($r['corps']) ? $r['corps'] : []);
        $out['routes'][$nom] = ['chemin' => $ch, 'code' => $r['code'], 'erreur' => $r['erreur'] ?? null, 'cles' => is_array($r['corps']) ? array_keys($r['corps']) : null,
            'n' => count($l), 'premiers' => array_slice($l, 0, 3), 'meta' => is_array($r['corps']) ? array_diff_key($r['corps'], array_flip(['data', 'items'])) : null];
    }
    foreach (array_slice(PanelApi::liste(is_array($lus['bundles']) ? $lus['bundles'] : []), 0, 2) as $b) {
        $id = $b['id'] ?? null;
        if ($id === null) { continue; }
        $r = PanelApi::sondeGet('/admin/promotions/bundles/' . rawurlencode((string) $id), 20);
        $out['routes']['bundle-' . $id] = ['code' => $r['code'], 'corps' => $r['corps']];
    }
    $doc = PanelApi::sondeGet('/../swagger/openapi.json', 20);
    $paths = is_array($doc['corps']['paths'] ?? null) ? $doc['corps']['paths'] : [];
    $out['swagger'] = [];
    foreach ($paths as $ch => $ops) {
        if (!preg_match('#promotions#', (string) $ch) || !is_array($ops)) { continue; }
        foreach ($ops as $m => $op) { if (is_array($op) && strtolower((string) $m) === 'get') { $out['swagger']['GET ' . $ch] = ['parametres' => $op['parameters'] ?? null, 'reponses' => $op['responses'] ?? null]; } }
    }
    $sch = is_array($doc['corps']['components']['schemas'] ?? null) ? $doc['corps']['components']['schemas'] : [];
    $out['schemas'] = array_filter($sch, static fn ($k) => preg_match('/bundle|buyxgety|promotion/i', (string) $k) === 1, ARRAY_FILTER_USE_KEY);
    return $out;
}

