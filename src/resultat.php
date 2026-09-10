<?php
declare(strict_types=1);

/**
 * LE RÉSULTAT SUR UNE ÉTENDUE — la semaine ou le mois — face à l'objectif.
 *
 * L'objectif est posé au MOIS (le budget validé, à défaut le CA théorique de
 * l'étude). Pour dire à un gérant, un jeudi soir, « il vous manque 271
 * clients sur la semaine », il faut ramener ce budget au jour : chaque jour
 * de semaine porte la part que la pondération réseau lui donne (voir
 * ponderation.php), et un jour où le magasin n'ouvre jamais ne prend rien
 * aux autres. La semaine est donc la somme de ses jours, et une semaine à
 * cheval sur deux mois prend ses jours d'août dans le budget d'août et ses
 * jours de septembre dans celui de septembre — sans prorata plat.
 *
 * Trois chiffres se lisent ensemble : l'OBJECTIF de l'étendue, l'ATTENDU à ce
 * jour (les jours déjà passés, la journée en cours comprise) et le RÉALISÉ.
 * L'écart réalisé − attendu, divisé par le panier moyen du magasin, donne le
 * nombre de clients qui manquent — ou qui sont d'avance.
 *
 * Sans pondération adoptée, il n'y a pas d'objectif journalier : l'écran le
 * dit et renvoie vers le calcul, il n'invente pas une répartition plate.
 */

/** GET /exploitation/periode?vue=semaine|mois&date=YYYY-MM-DD */
function ep_exploitation_periode(): array
{
    $auj  = date('Y-m-d');
    $vue  = (string) ($_GET['vue'] ?? 'semaine');
    if (!in_array($vue, ['semaine', 'mois'], true)) { $vue = 'semaine'; }
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $ts = strtotime($date);
    if ($vue === 'semaine') {
        $du = date('Y-m-d', strtotime('monday this week', $ts));
        $au = date('Y-m-d', strtotime($du . ' +6 days'));
    } else {
        $du = date('Y-m-01', $ts);
        $au = date('Y-m-t', $ts);
    }
    // Le dernier jour qui a pu vendre : aujourd'hui au plus tard. Une semaine
    // future n'existe pas, une semaine passée est close.
    $jusqua = min($au, $auj);

    $seuils = ['food' => 32.0, 'labour' => 33.0, 'overhead' => 13.5];
    try {
        foreach (Db::rows("SELECT code, seuil_bas, seuil_haut FROM kpi
                            WHERE code IN ('food','labour','overhead')") as $k) {
            $seuils[(string) $k['code']] = $k['seuil_haut'] !== null
                ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
        }
    } catch (PDOException $e) { /* seuils par défaut */ }

    $pj = pjPoids();
    $out = ['vue' => $vue, 'date' => $date, 'du' => $du, 'au' => $au, 'aujourdhui' => $auj,
        'jusqua' => $jusqua, 'enCours' => $au >= $auj, 'seuils' => $seuils,
        'ponderation' => ['ok' => $pj !== null, 'adopteLe' => $pj['adopteLe'] ?? null,
            'motif' => $pj === null ? 'Aucune pondération des jours adoptée : les objectifs du jour et de la semaine ne se calculent pas. Calculez-la dans Paramètres du budget.' : null],
        'magasins' => [], 'reseau' => null,
        'source' => 'API panel — ventes et marge brute mesurées par jour ; main-d’œuvre et frais généraux du mois courant répartis par jour d’ouverture'];
    if (!PanelApi::configured()) {
        return $out + ['indispo' => true, 'motif' => 'compte consultant non configuré (Mon compte)'];
    }
    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    } catch (PDOException $e) {
        $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name");
    }
    if (!$shops) { return $out + ['indispo' => true, 'motif' => 'aucun magasin actif']; }

    // Les BUDGETS des mois que l'étendue touche : un par (magasin, mois).
    $moisTouches = [];
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $moisTouches[substr($d, 0, 7)] = true; }
    $budgets = [];
    foreach (array_keys($moisTouches) as $ym) {
        try {
            foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf
                                WHERE year = ? AND month = ?', [(int) substr($ym, 0, 4), (int) substr($ym, 5, 2)]) as $b) {
                $v = null; $src = null;
                if ($b['revenue_budget'] !== null && (float) $b['revenue_budget'] > 0) { $v = (float) $b['revenue_budget']; $src = 'budget'; }
                elseif ($b['ca_theorique'] !== null && (float) $b['ca_theorique'] > 0) { $v = (float) $b['ca_theorique']; $src = 'theorique'; }
                if ($v !== null) { $budgets[(string) $b['shop_id']][$ym] = ['montant' => $v, 'source' => $src]; }
            }
        } catch (PDOException $e) { /* pas de budget : pas d'objectif */ }
    }

    // Les ventes par jour : l'étendue, précédée de quatre semaines pour savoir
    // quels jours de la semaine chaque magasin ouvre. `margin-heatmap` ne
    // rend pas plus d'un mois : on découpe.
    $lectDu = date('Y-m-d', strtotime($du . ' -28 days'));
    $fenetres = [];
    for ($cur = $lectDu; $cur <= $jusqua;) {
        $fin = date('Y-m-d', min(strtotime($cur . ' +30 days'), strtotime($jusqua)));
        $fenetres[] = [$cur, $fin];
        $cur = date('Y-m-d', strtotime($fin . ' +1 day'));
    }
    $moisCourant = substr($auj, 0, 7);
    $paths = [];
    foreach ($shops as $s) {
        $id = (int) $s['id'];
        foreach ($fenetres as $k => [$d1, $d2]) {
            $paths['hm' . $id . '_' . $k] = '/consultant/shops/' . $id . '/margin-heatmap?' . http_build_query(['from' => $d1, 'to' => $d2]);
        }
        // Le P&L mensuel du panel ne connaît que le mois COURANT (mesuré : la
        // date transmise est ignorée). Il ne sert qu'aux jours de ce mois-là.
        if (isset($moisTouches[$moisCourant])) {
            $paths['pnlM' . $id] = '/consultant/shops/' . $id . '/pnl?period=month&date=' . $auj;
        }
    }
    $res = PanelApi::getParallele($paths, 6);

    $joursMoisC = (int) date('t', strtotime($auj));
    $premierC   = new DateTimeImmutable(date('Y-m-01', strtotime($auj)));
    $lignes = [];
    foreach ($shops as $s) {
        $id = (int) $s['id']; $nom = (string) $s['name'];
        $parJour = []; $repondu = false;
        foreach (array_keys($fenetres) as $k) {
            $hm = $res['hm' . $id . '_' . $k] ?? null;
            if (!is_array($hm) || !isset($hm['days'])) { continue; }
            $repondu = true;
            foreach ((array) $hm['days'] as $d) {
                $j = (string) ($d['date'] ?? '');
                if ($j === '') { continue; }
                $ca = (float) ($d['ca'] ?? 0);
                $parJour[$j] = ['ca' => $ca, 'mb' => (float) ($d['margin_value'] ?? 0),
                    'tickets' => (int) ($d['tickets'] ?? 0), 'ouvert' => !empty($d['has_data']) && $ca > 0];
            }
        }
        if (!$repondu) {
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => false,
                'motif' => 'margin-heatmap sans réponse pour ce magasin'];
            continue;
        }
        // Les jours de semaine où ce magasin ouvre : vus actifs au moins une
        // fois sur les cinq semaines lues.
        $wdOuverts = [];
        foreach ($parJour as $j => $d) { if ($d['ouvert']) { $wdOuverts[(int) date('N', strtotime($j))] = true; } }

        // Le dénominateur de chaque mois touché : la somme des poids de ses
        // jours OUVERTS. C'est lui qui fait qu'un mois à cinq dimanches ne
        // vaut pas un mois à quatre, et qu'un lundi fermé ne pèse rien.
        $denom = [];
        foreach (array_keys($moisTouches) as $ym) {
            $n = (int) date('t', strtotime($ym . '-01')); $t = 0.0;
            for ($i = 1; $i <= $n; $i++) {
                $wd = (int) date('N', strtotime(sprintf('%s-%02d', $ym, $i)));
                if ($pj !== null && isset($wdOuverts[$wd])) { $t += $pj['poids'][$wd]; }
            }
            $denom[$ym] = $t;
        }

        // La main-d'œuvre et les frais généraux : le mois courant réparti par
        // jour d'ouverture — le même diviseur que Résultat du jour.
        $joursOuvertsC = 0;
        for ($i = 0; $i < $joursMoisC; $i++) {
            if (isset($wdOuverts[(int) $premierC->modify('+' . $i . ' days')->format('N')])) { $joursOuvertsC++; }
        }
        $pnlM = $res['pnlM' . $id] ?? null;
        $labourMois = is_array($pnlM) ? nombreOuNull((array) ($pnlM['labour'] ?? []), ['value', 'amount']) : null;
        $ohMois = is_array($pnlM) ? nombreOuNull((array) ($pnlM['overhead']['breakdown']['month'] ?? []), ['value', 'amount']) : null;
        if (($ohMois === null || $ohMois <= 0) && is_array($pnlM)) {
            $v = nombreOuNull((array) ($pnlM['overhead'] ?? []), ['value', 'amount']);
            if ($v !== null && $v > 0) { $ohMois = $v; }
        }
        $labJ = ($labourMois !== null && $joursOuvertsC > 0) ? $labourMois / $joursOuvertsC : null;
        $ohJ  = ($ohMois !== null && $joursOuvertsC > 0) ? $ohMois / $joursOuvertsC : null;

        $jours = []; $objectif = 0.0; $attendu = 0.0; $prevu = 0.0; $realise = 0.0; $tickets = 0; $mb = 0.0;
        $labour = 0.0; $oh = 0.0; $joursOuvertsPasses = 0; $joursHorsMoisC = 0; $sansBudget = [];
        $objAucun = true; $src = null; $ouvertUnJour = false;
        for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $ym = substr($d, 0, 7); $wd = (int) date('N', strtotime($d));
            $b = $budgets[(string) $id][$ym] ?? null;
            $ferme = !isset($wdOuverts[$wd]);
            $obj = null;
            if ($b !== null && $pj !== null && !$ferme && $denom[$ym] > 0) {
                $obj = $b['montant'] * $pj['poids'][$wd] / $denom[$ym];
                $objAucun = false;
                $src = ($src === 'theorique' || $b['source'] === 'theorique') ? 'theorique' : 'budget';
            } elseif ($b === null && !$ferme) { $sansBudget[$ym] = true; }
            $passe = $d <= $jusqua;
            $x = $parJour[$d] ?? null;
            $ouvert = $passe && $x !== null && $x['ouvert'];
            if ($ouvert) { $ouvertUnJour = true; }
            $ligne = ['date' => $d, 'jour' => $wd, 'court' => PJ_COURTS[$wd] . ' ' . (int) substr($d, 8, 2),
                'objectif' => $obj !== null ? round($obj, 2) : null, 'ferme' => $ferme, 'passe' => $passe,
                'ca' => $ouvert ? round($x['ca'], 2) : null, 'tickets' => $ouvert ? $x['tickets'] : null,
                'aujourdhui' => $d === $auj];
            if ($obj !== null) { $objectif += $obj; if ($passe) { $attendu += $obj; } else { $prevu += $obj; } }
            if ($ouvert) {
                $realise += $x['ca']; $tickets += $x['tickets']; $mb += $x['mb'];
                if ($ym === $moisCourant) {
                    $joursOuvertsPasses++;
                    if ($labJ !== null) { $labour += $labJ; }
                    if ($ohJ !== null) { $oh += $ohJ; }
                } else { $joursHorsMoisC++; }
            }
            $jours[] = $ligne;
        }
        if (!$ouvertUnJour && $jusqua >= $du) {
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => false,
                'motif' => 'aucune vente sur la période', 'jours' => $jours,
                'objectif' => $objAucun ? null : round($objectif, 2)];
            continue;
        }
        $fc = $realise - $mb;
        // Le résultat n'est complet que si chaque jour vendu a sa main-d'œuvre
        // et ses frais : hors du mois courant, le panel ne les rend pas.
        $netOk = $labJ !== null && $ohJ !== null && $joursHorsMoisC === 0 && $joursOuvertsPasses > 0;
        $net = $netOk ? $mb - $labour - $oh : null;
        $panier = $tickets > 0 ? $realise / $tickets : null;
        $ecart = $objAucun ? null : $realise - $attendu;
        $pct = static fn (?float $v) => ($v !== null && $realise > 0) ? round($v / $realise * 100, 1) : null;
        $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => true,
            'objectif' => $objAucun ? null : round($objectif, 2),
            'objectifSource' => $src,
            'sansBudget' => array_keys($sansBudget),
            'attendu' => $objAucun ? null : round($attendu, 2),
            'prevu' => $objAucun ? null : round($prevu, 2),          // ce que la pondération attend encore
            'realise' => round($realise, 2),
            'reste' => $objAucun ? null : round($objectif - $realise, 2),
            'ecart' => $ecart !== null ? round($ecart, 2) : null,
            'atteinte' => (!$objAucun && $attendu > 0) ? round($realise / $attendu, 4) : null,
            'tickets' => $tickets,
            'panier' => $panier !== null ? round($panier, 2) : null,
            // Positif : il manque des clients. Négatif : ils sont d'avance.
            'clientsManquants' => ($ecart !== null && $panier !== null && $panier > 0) ? (int) round(-$ecart / $panier) : null,
            'coutMatiere' => round($fc, 2), 'coutMatierePct' => $pct($fc),
            'margeBrute' => round($mb, 2), 'margeBrutePct' => $pct($mb),
            'labour' => $netOk ? round($labour, 2) : null, 'labourPct' => $netOk ? $pct($labour) : null,
            'overhead' => $netOk ? round($oh, 2) : null, 'overheadPct' => $netOk ? $pct($oh) : null,
            'net' => $net !== null ? round($net, 2) : null, 'netPct' => $pct($net),
            'motifNet' => $netOk ? null : ($joursHorsMoisC > 0
                ? 'main-d’œuvre et frais généraux connus pour le mois courant seulement'
                : 'P&L mensuel sans réponse — main-d’œuvre ou frais généraux indisponibles'),
            'joursOuverts' => array_keys($wdOuverts),
            'jours' => $jours];
    }
    usort($lignes, static fn ($a, $b) => ($b['realise'] ?? -1) <=> ($a['realise'] ?? -1));
    $out['magasins'] = $lignes;

    // Réseau : la somme de ce qui est connu. Un magasin sans objectif ne
    // compte pas pour zéro dans l'objectif — la ligne le dit.
    $t = ['objectif' => 0.0, 'attendu' => 0.0, 'prevu' => 0.0, 'realise' => 0.0, 'tickets' => 0, 'fc' => 0.0,
        'mb' => 0.0, 'labour' => 0.0, 'oh' => 0.0, 'net' => 0.0];
    $nObj = 0; $nOuv = 0; $netComplet = true; $realiseObj = 0.0;
    $parJourR = [];
    foreach ($lignes as $l) {
        if (empty($l['ouvert'])) { continue; }
        $nOuv++;
        $t['realise'] += $l['realise']; $t['tickets'] += $l['tickets']; $t['fc'] += $l['coutMatiere']; $t['mb'] += $l['margeBrute'];
        if ($l['objectif'] !== null) {
            $nObj++; $t['objectif'] += $l['objectif']; $t['attendu'] += $l['attendu']; $t['prevu'] += $l['prevu']; $realiseObj += $l['realise'];
        }
        if ($l['net'] === null) { $netComplet = false; }
        else { $t['labour'] += $l['labour']; $t['oh'] += $l['overhead']; $t['net'] += $l['net']; }
        foreach ($l['jours'] as $j) {
            $e = $parJourR[$j['date']] ?? ['date' => $j['date'], 'court' => $j['court'], 'objectif' => 0.0, 'ca' => 0.0, 'passe' => $j['passe'], 'aujourdhui' => $j['aujourdhui'], 'aObjectif' => false];
            if ($j['objectif'] !== null) { $e['objectif'] += $j['objectif']; $e['aObjectif'] = true; }
            if ($j['ca'] !== null) { $e['ca'] += $j['ca']; }
            $parJourR[$j['date']] = $e;
        }
    }
    $ca = $t['realise'];
    $panierR = $t['tickets'] > 0 ? $ca / $t['tickets'] : null;
    $ecartR = $nObj ? $realiseObj - $t['attendu'] : null;
    $pctR = static fn (?float $v) => ($v !== null && $ca > 0) ? round($v / $ca * 100, 1) : null;
    $out['reseau'] = ['magasins' => $nOuv, 'magasinsAvecObjectif' => $nObj,
        'objectif' => $nObj ? round($t['objectif'], 2) : null,
        'attendu' => $nObj ? round($t['attendu'], 2) : null,
        'prevu' => $nObj ? round($t['prevu'], 2) : null,
        'realise' => round($ca, 2),
        'reste' => $nObj ? round($t['objectif'] - $realiseObj, 2) : null,
        'ecart' => $ecartR !== null ? round($ecartR, 2) : null,
        'atteinte' => ($nObj && $t['attendu'] > 0) ? round($realiseObj / $t['attendu'], 4) : null,
        'tickets' => $t['tickets'], 'panier' => $panierR !== null ? round($panierR, 2) : null,
        'clientsManquants' => ($ecartR !== null && $panierR) ? (int) round(-$ecartR / $panierR) : null,
        'coutMatiere' => round($t['fc'], 2), 'coutMatierePct' => $pctR($t['fc']),
        'margeBrute' => round($t['mb'], 2), 'margeBrutePct' => $pctR($t['mb']),
        'labour' => $netComplet ? round($t['labour'], 2) : null, 'labourPct' => $netComplet ? $pctR($t['labour']) : null,
        'overhead' => $netComplet ? round($t['oh'], 2) : null, 'overheadPct' => $netComplet ? $pctR($t['oh']) : null,
        'net' => $netComplet ? round($t['net'], 2) : null, 'netPct' => $netComplet ? $pctR($t['net']) : null,
        'jours' => array_values(array_map(static fn ($e) => ['date' => $e['date'], 'court' => $e['court'],
            'objectif' => $e['aObjectif'] ? round($e['objectif'], 2) : null, 'ca' => $e['passe'] ? round($e['ca'], 2) : null,
            'passe' => $e['passe'], 'aujourdhui' => $e['aujourdhui']], $parJourR))];
    return $out;
}
