<?php

declare(strict_types=1);

/**
 * Le DOSSIER D'ANALYSE : le mois (ou le trimestre) d'un magasin en un PDF A4
 * imprimable : synthèse, budget & compte de résultat, produits & assortiment,
 * équipe de vente, croisements. Tout vient des endpoints du panel et des
 * agrégats gravés du cockpit (moisson, tranches produits), jamais de la
 * table locale morte.
 *
 * GET /dossier.pdf?shop=2&m=2026-08&n=1|3
 */

/** Le P&L d'un JOUR par l'endpoint daily-summary, gravé une fois le jour passé. */
function dossierJour(int $sid, string $jour): ?array
{
    $cle = 'dJ' . $sid . ':' . $jour;
    $cache = setting($cle);
    if (is_array($cache) && isset($cache['ca'])) { return $cache; }
    $r = PanelApi::get('/shops/' . $sid . '/statistics/daily-summary?date=' . $jour);
    if (!is_array($r) || !isset($r['income'])) { return null; }
    // `transactions` n'est PAS le nombre de tickets : au 15/08 à Halle, le
    // daily-summary en compte 473 quand l'endpoint des KPIs en compte 167
    // pour le même CA. Le champ est gardé pour mémoire, jamais pour le
    // panier — le compte de tickets vient de pvKpisMois.
    $d = ['ca' => (float) $r['income'], 'tickets' => (int) ($r['transactions'] ?? 0),
        'mo' => (float) ($r['employee_cost'] ?? 0), 'mat' => (float) ($r['material_cost'] ?? 0),
        'frais' => (float) ($r['shop_cost'] ?? 0), 'resultat' => (float) ($r['profit'] ?? 0)];
    if ($jour < date('Y-m-d')) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$cle, json_encode($d)]);
    }
    return $d;
}

/**
 * Les KPIs JOUR PAR JOUR d'une fenêtre : CA, tickets, panier — la seule
 * source qui compte juste les tickets (le `transactions` du daily-summary
 * compte autre chose). Un jour passé se grave une fois ; les jours manquants
 * partent en un seul voyage parallèle plutôt qu'un aller-retour chacun.
 *
 * @return array<string, array{ca: float, tickets: int, panier: ?float}>
 */
function dossierJoursKpis(int $sid, string $du, string $au): array
{
    $out = []; $chemins = [];
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        $cle = 'dK' . $sid . ':' . $j;
        $cache = setting($cle);
        if (is_array($cache) && isset($cache['ca'])) { $out[$j] = $cache; continue; }
        $chemins[$j] = '/shops/' . $sid . '/statistics/sales/kpis?'
            . http_build_query(['date_from' => $j, 'date_to' => $j]);
    }
    if ($chemins !== []) {
        foreach (PanelApi::getParallele($chemins, 8) as $j => $r) {
            if (!is_array($r) || !isset($r['ca'])) { continue; }
            $d = ['ca' => (float) $r['ca'], 'tickets' => (int) ($r['tickets'] ?? 0),
                'panier' => isset($r['avg_basket']) ? (float) $r['avg_basket'] : null];
            $out[(string) $j] = $d;
            if ((string) $j < date('Y-m-d')) {
                Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    ['dK' . $sid . ':' . $j, json_encode($d)]);
            }
        }
    }
    return $out;
}

/** Le P&L d'un MOIS : la somme des jours, avec la série quotidienne. */
function dossierMoisPnl(int $sid, string $m): array
{
    $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
    $tot = ['ca' => 0.0, 'tickets' => 0, 'mo' => 0.0, 'mat' => 0.0, 'frais' => 0.0, 'resultat' => 0.0];
    $serie = [];
    for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        $d = dossierJour($sid, $j);
        if ($d === null) { $serie[] = null; continue; }
        foreach ($tot as $k => $v) { $tot[$k] += $d[$k]; }
        $serie[] = $d;
    }
    $tot['serie'] = $serie;
    return $tot;
}

/** L'équipe d'un magasin sur n mois : les lignes venteMois agrégées. */
function dossierEquipe(int $sid, string $m, int $n): array
{
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    $par = [];
    $parMois = [];
    for ($i = 0; $i < $n; $i++) {
        $mI = date('Y-m', strtotime($m . '-01 -' . $i . ' month'));
        $r = venteMois($mI, $nomDe);
        $parMois[$mI] = $r['motif'] === null ? $r['lignes'] : null;
        if ($r['motif'] !== null) { continue; }
        foreach ($r['lignes'] as $l) {
            if ((string) $l['shopId'] !== (string) $sid) { continue; }
            $id = (string) $l['id'];
            if (!isset($par[$id])) {
                $par[$id] = ['nom' => $l['nom'], 'ca' => 0.0, 'tickets' => 0, 'heures' => 0.0,
                    'lignes' => 0.0, 'lignesOk' => true, 'coef' => $l['coefCreneau']];
            }
            $par[$id]['ca'] += (float) $l['ca'];
            $par[$id]['tickets'] += (int) $l['tickets'];
            $par[$id]['heures'] += (float) $l['heures'];
            if ($l['lignesTicket'] !== null) { $par[$id]['lignes'] += $l['lignesTicket'] * (int) $l['tickets']; }
            else { $par[$id]['lignesOk'] = false; }
        }
    }
    foreach ($par as $id => $x) {
        $par[$id]['score'] = $x['heures'] > 0 && $x['ca'] > 0
            ? (int) round($x['ca'] / ($x['heures'] + VENTE_LISSAGE_HEURES) * (float) ($x['coef'] ?? 1)) : null;
        $par[$id]['caH'] = $x['heures'] > 0 ? (int) round($x['ca'] / $x['heures']) : null;
        $par[$id]['lignesT'] = ($x['lignesOk'] && $x['tickets'] > 0) ? round($x['lignes'] / $x['tickets'], 1) : null;
        $par[$id]['etp'] = round($x['heures'] / 164, 2);
    }
    uasort($par, fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1));
    return ['equipe' => $par, 'parMois' => $parMois];
}

/** Les produits d'un magasin sur la fenêtre, face à l'attendu réseau. */
function dossierProduits(int $sid, string $du, string $au): array
{
    $couples = [];
    $sids = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1'));
    foreach ($sids as $s2) { $couples[] = [$s2, $du, $au]; }
    $lu = apTranches2($couples);
    $prods = []; $tailles = [];
    foreach ($sids as $s2) {
        $p = $lu[$s2 . ':' . $du] ?? null;
        if ($p === null) { continue; }
        $tailles[$s2] = 0.0;
        foreach ($p as $pid => $x) {
            $tailles[$s2] += (float) $x[3];
            if (!isset($prods[$pid])) {
                $prods[$pid] = ['nom' => $x[0], 'cat' => $x[1], 'reseau' => 0.0, 'ca' => 0.0,
                    'ici' => 0.0, 'iciCa' => 0.0, 'vendeurs' => 0];
            }
            $prods[$pid]['reseau'] += (float) $x[2];
            $prods[$pid]['ca'] += (float) $x[3];
            if ((float) $x[2] > 0) { $prods[$pid]['vendeurs']++; }
            if ($s2 === $sid) { $prods[$pid]['ici'] = (float) $x[2]; $prods[$pid]['iciCa'] = (float) $x[3]; }
        }
    }
    $caR = array_sum($tailles);
    $part = $caR > 0 ? (($tailles[$sid] ?? 0) / $caR) : 0.25;
    return ['prods' => $prods, 'part' => $part, 'nbShops' => count($sids)];
}

/**
 * Les tâches boutique NOTÉES pour CE magasin sur la fenêtre — la même source
 * que le Contrôle des tâches et le rapport hebdomadaire : `ceo_tache_jour`,
 * la relève quotidienne du panel (ep_pwa_tasks), pas les tâches de projet
 * consultant (`ceo_project_task`), qui sont une tout autre affaire.
 *
 * Le compte des 5/5 (exemplaires) d'abord, puis le détail des non-conformes
 * (note 3, 2 ou 1) — les 4/5 (conformes, le travail attendu) ne se listent
 * pas, ils ne disent rien de plus qu'une tâche faite normalement.
 */
function dossierTaches(int $sid, string $du, string $au): array
{
    tachesSuiviTables();
    $rows = []; $attendues = 0; $faites = 0;
    try {
        $rows = Db::rows(
            'SELECT jour, id_task, nom, note, commentaire, fait_le, fait_par FROM ceo_tache_jour'
            . ' WHERE id_shop = ? AND jour BETWEEN ? AND ? AND note IS NOT NULL'
            . ' ORDER BY note ASC, jour DESC',
            [$sid, $du, $au]);
        // L'exécution brute, notée ou pas : un magasin qui ne fait AUCUNE tâche
        // n'a évidemment aucune note — sans ce compte, la page laisserait
        // croire à un défaut de relève là où il n'y a que du travail non fait.
        $c = Db::row('SELECT COUNT(*) AS t, SUM(fait) AS f FROM ceo_tache_jour'
            . ' WHERE id_shop = ? AND jour BETWEEN ? AND ?', [$sid, $du, $au]);
        $attendues = (int) ($c['t'] ?? 0);
        $faites = (int) ($c['f'] ?? 0);
    } catch (PDOException $e) { /* table absente : la section le dira */ }
    $exemplaires = []; $nonConformes = [];
    foreach ($rows as $r) {
        $note = (int) $r['note'];
        if ($note === 4) { continue; }
        $l = ['nom' => (string) $r['nom'], 'note' => $note, 'taskId' => (string) $r['id_task'],
            'jour' => (string) $r['jour'], 'commentaire' => $r['commentaire'],
            'heure' => $r['fait_le'] !== null ? substr((string) $r['fait_le'], 11, 5) : null,
            'par' => $r['fait_par']];
        if ($note === 5) { $exemplaires[] = $l; } else { $nonConformes[] = $l; }
    }
    return ['total' => count($rows), 'n5' => count($exemplaires), 'exemplaires' => $exemplaires,
        'nonConformes' => $nonConformes, 'attendues' => $attendues, 'faites' => $faites];
}

function ep_dossier_pdf(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte panel non configuré']; }
    $sid = (int) ($_GET['shop'] ?? 0);
    $m = trim((string) ($_GET['m'] ?? date('Y-m', strtotime('first day of last month'))));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m', strtotime('first day of last month')); }
    $n = (int) ($_GET['n'] ?? 1);
    if (!in_array($n, [1, 3], true)) { $n = 1; }

    $shops = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $shops[(int) $s['id']] = (string) $s['name'];
    }
    if (!isset($shops[$sid])) { $sid = (int) array_key_first($shops); }
    $court = fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $nomC = $court($shops[$sid]);
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur0 = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $n1 = fn ($v) => number_format((float) $v, 1, ',', ' ');
    $mDeb = $n === 1 ? $m : date('Y-m', strtotime($m . '-01 -2 month'));
    $du = $mDeb . '-01';
    $au = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
    $libP = $n === 1 ? strftime_fr(strtotime($m . '-01'), 'M Y')
        : 'Trimestre ' . strftime_fr(strtotime($du), 'M') . ' – ' . strftime_fr(strtotime($m . '-01'), 'M Y');

    // --- Les données, une fois pour toutes les pages.
    $pnl = ['ca' => 0.0, 'tickets' => 0, 'mo' => 0.0, 'mat' => 0.0, 'frais' => 0.0, 'resultat' => 0.0, 'serie' => []];
    $pnlPrec = null;
    for ($i = 0; $i < $n; $i++) {
        $mI = date('Y-m', strtotime($m . '-01 -' . $i . ' month'));
        $p = dossierMoisPnl($sid, $mI);
        foreach (['ca', 'tickets', 'mo', 'mat', 'frais', 'resultat'] as $k) { $pnl[$k] += $p[$k]; }
        $pnl['serie'] = array_merge($p['serie'], $pnl['serie']);
    }
    // Le compte de TICKETS vient des KPIs, pas du P&L quotidien : le champ
    // `transactions` du daily-summary compte autre chose (473 contre 167 le
    // même jour) et écraserait le panier moyen de moitié.
    $tkP = 0; $tkOk = true;
    for ($i = 0; $i < $n; $i++) {
        $kP = pvKpisMois($sid, date('Y-m', strtotime($m . '-01 -' . $i . ' month')));
        if ($kP === null) { $tkOk = false; break; }
        $tkP += (int) $kP['tickets'];
    }
    if ($tkOk && $tkP > 0) { $pnl['tickets'] = $tkP; }
    $mPrec = date('Y-m', strtotime($mDeb . '-01 -1 month'));
    $pp = dossierMoisPnl($sid, $mPrec);
    $pnlPrec = $pp['ca'] > 0 ? $pp : null;
    $eq = dossierEquipe($sid, $m, $n);
    $prodD = dossierProduits($sid, $du, $au);
    $tachesD = dossierTaches($sid, $du, $au);
    $reg = venteRecordReglages();

    // ETP du magasin et du réseau (heures venteMois du dernier mois de la fenêtre).
    $etpDe = []; $caEtpDe = [];
    foreach ($shops as $s2 => $nom2) {
        $h2 = 0.0;
        foreach (($eq['parMois'][$m] ?? []) ?: [] as $l) {
            if ((string) $l['shopId'] === (string) $s2) { $h2 += (float) $l['heures']; }
        }
        $etpDe[$s2] = $h2 > 0 ? round($h2 / 164, 1) : null;
        $k2 = pvKpisMois((int) $s2, $m);
        $caEtpDe[$s2] = ($etpDe[$s2] && $k2) ? (int) round($k2['ca'] / $etpDe[$s2]) : null;
    }
    $budget = null;
    try {
        $b2 = Db::row('SELECT revenue_budget, ca_theorique FROM ceo_shop_month_perf
                        WHERE shop_id = ? AND year = ? AND month = ?',
            [(string) $sid, (int) substr($m, 0, 4), (int) substr($m, 5, 2)]);
        if ($b2 !== null) {
            $budget = (float) ($b2['revenue_budget'] ?? 0) > 0 ? (float) $b2['revenue_budget']
                : ((float) ($b2['ca_theorique'] ?? 0) > 0 ? (float) $b2['ca_theorique'] : null);
            if ($budget !== null) { $budget *= $n; }
        }
    } catch (PDOException $eB) { /* sans budget */ }
    $crois = setting('pvCrois' . $m);
    $crois = is_array($crois) ? $crois : null;

    // --- Le squelette et les briques graphiques.
    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:10.5pt}
      .serif{font-family:Georgia,"DejaVu Serif",serif}
      .cap{font-size:7.5pt;font-weight:bold;text-transform:uppercase;letter-spacing:0.09em;color:#8b8177}
      .mut{color:#8b8177}.acc{color:#8D1D2C}.vert{color:#2d7a3e}.rouge{color:#C0182B}
      .sec{font-family:Georgia,serif;font-size:13pt;border-bottom:1.2px solid #8D1D2C;padding-bottom:1mm;margin:6mm 0 2.5mm}
      .tuile{border:1.2px solid #E8C9A0;background:#FFF9EC;border-radius:3mm;padding:2.6mm 2mm;text-align:center}
      table.t{border-collapse:collapse;width:100%;font-size:8.5pt}
      table.t th{font-size:7pt;text-transform:uppercase;letter-spacing:0.05em;color:#8b8177;text-align:right;padding:1.2mm 1.6mm;border-bottom:0.5pt solid #E5E0D8}
      table.t td{padding:1.4mm 1.6mm;border-bottom:0.4pt solid #F0EDE7;text-align:right}
      table.t th.l,table.t td.l{text-align:left}
      .saut{page-break-before:always}
      /* Un titre ne se separe jamais de ce quil annonce, et les petits
         tableaux ne se coupent pas en deux : sans ces deux regles, le
         rythme de la semaine restait seul en bas de page, ses sept lignes
         parties sur la suivante. */
      .sec{page-break-after:avoid}
      table.nocut{page-break-inside:avoid}
      /* Le moteur PDF ignore page-break-after sur un titre : seule une
         enveloppe insecable garde ensemble un titre et son tableau. */
      div.bloc{page-break-inside:avoid}
    </style>';
    $logo = rapLogoDataUri();
    $entete = fn (string $sous) => '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2.5px solid #8D1D2C;padding-bottom:2mm;margin-bottom:4mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:34px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:8.5pt;color:#7a736a;line-height:1.5"><b style="color:#221E1A;font-size:10.5pt">Dossier d’analyse : ' . $e($nomC) . '</b><br>' . $e($libP) . ' · ' . $e($sous) . '</td></tr></table>';
    $jauge = function (int $delta) {
        $demi = min(100, abs($delta)) / 2;
        $pos = $delta >= 0;
        $w = (int) round($demi * 0.28 * 2);
        return '<table cellpadding="0" cellspacing="0" style="margin-left:auto"><tr>'
            . '<td style="width:14mm;text-align:right">' . (!$pos ? '<div style="height:2.2mm;width:' . max(1, $w) . 'mm;background:#C0182B;border-radius:1mm;margin-left:auto"></div>' : '') . '</td>'
            . '<td style="width:0.6mm;background:#D9D2C6"></td>'
            . '<td style="width:14mm">' . ($pos ? '<div style="height:2.2mm;width:' . max(1, $w) . 'mm;background:#2d7a3e;border-radius:1mm"></div>' : '') . '</td>'
            . '<td style="width:11mm;font-size:7.5pt;font-weight:bold;color:' . ($pos ? '#2d7a3e' : '#C0182B') . ';text-align:right">' . ($pos ? '+ ' : '− ') . abs($delta) . ' %</td></tr></table>';
    };

    $caPrecTxt = $pnlPrec !== null && $pnlPrec['ca'] > 0
        ? (($pnl['ca'] >= $pnlPrec['ca'] ? '+ ' : '− ') . $n1(abs($pnl['ca'] / $pnlPrec['ca'] - 1) * 100) . ' % vs ' . strftime_fr(strtotime($mPrec . '-01'), 'M')) : '';
    $etpIci = $etpDe[$sid] ?? null;
    $caEtpIci = $caEtpDe[$sid] ?? null;
    $foodPct = $pnl['ca'] > 0 ? $pnl['mat'] / $pnl['ca'] * 100 : null;
    $moPct = $pnl['ca'] > 0 ? $pnl['mo'] / $pnl['ca'] * 100 : null;
    $meilleure = null;
    foreach ($eq['equipe'] as $x) { if ($x['score'] !== null) { $meilleure = $x; break; } }

    // ============ PAGE 1 : SYNTHÈSE ============
    $h = $css . '<div class="doc">' . $entete('vue d’ensemble')
        . '<div class="serif" style="font-size:19pt;margin:1mm 0 0.5mm">Le ' . ($n === 1 ? 'mois' : 'trimestre') . ' en un coup d’œil</div>'
        . '<div style="font-size:8.5pt;color:#5d564e;margin-bottom:4mm">Chiffres exacts des endpoints du réseau, gravés à la clôture. Fenêtre : du ' . $e(date('d/m', strtotime($du))) . ' au ' . $e(date('d/m/Y', strtotime($au))) . '.</div>';
    $tuiles = [
        ['CA', $eur0($pnl['ca']), $caPrecTxt, $pnl['ca'] >= ($pnlPrec['ca'] ?? 0) ? 'vert' : 'rouge'],
        ['Tickets', number_format($pnl['tickets'], 0, ',', ' '), 'panier ' . ($pnl['tickets'] > 0 ? number_format($pnl['ca'] / $pnl['tickets'], 2, ',', ' ') . ' €' : ''), 'mut'],
        ['Objectif budget', $budget !== null && $budget > 0 ? round($pnl['ca'] / $budget * 100) . ' %' : '', $budget !== null ? $eur0($budget) . ' visés' : 'pas de budget posé', 'acc'],
        ['CA par ETP', $caEtpIci !== null ? $eur0($caEtpIci) : '', $etpIci !== null ? str_replace('.', ',', (string) $etpIci) . ' ETP (heures ÷ 164)' : 'planning incomplet', 'vert'],
        ['Food cost', $foodPct !== null ? $n1($foodPct) . ' %' : '', 'seuil ' . $n1(kpiSeuil('food-cost-pct', 32.0)) . ' %' . ($foodPct !== null && $foodPct > kpiSeuil('food-cost-pct', 32.0) ? ' dépassé' : ' tenu'), $foodPct !== null && $foodPct > kpiSeuil('food-cost-pct', 32.0) ? 'rouge' : 'vert'],
        ['Main-d’œuvre', $moPct !== null ? $n1($moPct) . ' %' : '', 'seuil ' . $n1(kpiSeuil('labour-pct', 33.0)) . ' %' . ($moPct !== null && $moPct > kpiSeuil('labour-pct', 33.0) ? ' dépassé' : ' tenu'), $moPct !== null && $moPct > kpiSeuil('labour-pct', 33.0) ? 'rouge' : 'vert'],
        ['Meilleure vendeuse', $meilleure !== null ? $meilleure['nom'] : '', $meilleure !== null ? 'score ' . $meilleure['score'] : '', 'mut'],
        ['Résultat', $eur0($pnl['resultat']), $pnl['ca'] > 0 ? $n1($pnl['resultat'] / $pnl['ca'] * 100) . ' % du CA' : '', $pnl['resultat'] >= 0 ? 'vert' : 'rouge'],
    ];
    $h .= '<table width="100%" cellpadding="0" cellspacing="4"><tr>';
    foreach ($tuiles as $i => $t) {
        if ($i === 4) { $h .= '</tr><tr>'; }
        $h .= '<td width="25%" class="tuile"><div class="cap">' . $e($t[0]) . '</div>'
            . '<div class="serif" style="font-size:13.5pt;color:#8D1D2C;margin-top:1mm">' . $e($t[1]) . '</div>'
            . '<div class="' . $t[3] . '" style="font-size:7pt;margin-top:0.5mm">' . $e($t[2]) . '</div></td>';
    }
    $h .= '</tr></table>';
    // CA/ETP réseau
    $h .= '<div class="sec">CA par ETP dans le réseau</div>';
    $maxCaEtp = max(array_filter($caEtpDe) ?: [1]);
    foreach ($shops as $s2 => $nom2) {
        $v = $caEtpDe[$s2];
        $w = $v !== null ? (int) round($v / $maxCaEtp * 110) : 0;
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:1.2mm"><tr>'
            . '<td style="width:26mm;font-size:8.5pt;' . ($s2 === $sid ? 'font-weight:bold' : '') . '">' . $e($court($nom2)) . '</td>'
            . '<td><div style="height:3.4mm;background:#F4F1EB;border-radius:2mm"><div style="height:3.4mm;width:' . $w . 'mm;background:' . ($s2 === $sid ? '#8D1D2C' : '#D9BCBF') . ';border-radius:2mm"></div></div></td>'
            . '<td style="width:38mm;text-align:right;font-size:8.5pt"><b>' . ($v !== null ? $eur0($v) : '') . '</b> / ETP <span class="mut">· ' . ($etpDe[$s2] !== null ? str_replace('.', ',', (string) $etpDe[$s2]) : '') . ' ETP</span></td></tr></table>';
    }
    // à retenir
    $h .= '<div class="sec">À retenir</div><div style="font-size:9pt;line-height:1.7">';
    $points = [];
    if ($foodPct !== null && $foodPct > kpiSeuil('food-cost-pct', 32.0)) {
        $points[] = ['rouge', 'Le food cost dépasse le seuil de ' . $n1($foodPct - kpiSeuil('food-cost-pct', 32.0)) . ' point(s).'];
    }
    if ($budget !== null && $budget > 0) {
        $att = $pnl['ca'] / $budget;
        $points[] = [$att >= 1 ? 'vert' : 'rouge', 'Budget ' . ($att >= 1 ? 'atteint : ' : 'manqué : ') . round($att * 100) . ' % du visé.'];
    }
    if ($caEtpIci !== null) {
        $seuilEtp = kpiSeuil('ca-etp', 13000.0) * $n;
        $points[] = [$caEtpIci >= $seuilEtp ? 'vert' : 'rouge',
            $eur0($caEtpIci) . ' de CA par ETP, seuil réseau ' . $eur0($seuilEtp) . ($caEtpIci >= $seuilEtp ? ' dépassé.' : ' manqué.')];
    }
    if ($meilleure !== null && $pnl['ca'] > 0) {
        $deux = array_slice(array_values($eq['equipe']), 0, 2);
        $partDeux = array_sum(array_map(fn ($x) => $x['ca'], $deux)) / $pnl['ca'] * 100;
        if ($partDeux > 45) { $points[] = ['mut', 'Les deux meilleures font ' . round($partDeux) . ' % du CA attribué : dépendance élevée.']; }
    }
    // L'exécution des tâches quand elle décroche : à 0 %, c'est le premier
    // sujet de la période, avant n'importe quel point de food cost.
    if ($tachesD['attendues'] > 0) {
        $tauxT0 = round($tachesD['faites'] / $tachesD['attendues'] * 100);
        if ($tauxT0 < 70) {
            array_unshift($points, ['rouge', 'Tâches quotidiennes : ' . $tauxT0 . ' % d’exécution ('
                . number_format($tachesD['faites'], 0, ',', ' ') . ' faites sur '
                . number_format($tachesD['attendues'], 0, ',', ' ') . ').']);
        }
    }
    foreach (array_slice($points, 0, 5) as $p2) {
        $h .= '<div><span class="' . $p2[0] . '" style="font-weight:bold">&#9679;</span> ' . $e($p2[1]) . '</div>';
    }
    $h .= '</div>';

    // Contrôle des tâches : le compte des exemplaires (5/5), le détail des
    // non-conformes (3, 2, 1). Les conformes (4/5) ne se listent pas.
    $h .= '<div class="sec">Contrôle des tâches</div>';
    if ($tachesD['attendues'] === 0) {
        $h .= '<div style="font-size:9pt" class="mut">Aucune tâche relevée sur la période.</div>';
    } else {
        $tauxT = round($tachesD['faites'] / $tachesD['attendues'] * 100);
        $h .= '<div style="font-size:9pt;margin-bottom:1.5mm"><span class="' . ($tauxT >= 70 ? 'vert' : 'rouge') . '" style="font-weight:bold">&#9679;</span> '
            . number_format($tachesD['faites'], 0, ',', ' ') . ' tâche(s) faite(s) sur '
            . number_format($tachesD['attendues'], 0, ',', ' ') . ' demandée(s), soit <b>' . $tauxT . ' %</b> d’exécution.</div>';
    }
    if ($tachesD['total'] === 0) {
        if ($tachesD['attendues'] > 0) {
            $h .= '<div style="font-size:9pt" class="mut">Aucune tâche notée : sans passage noté, ni exemplaire ni non-conformité à relever.</div>';
        }
    } else {
        $h .= '<div style="font-size:9pt;margin-bottom:1.5mm"><span class="vert" style="font-weight:bold">&#9679;</span> '
            . $tachesD['n5'] . ' tâche(s) exemplaire(s) (5/5).</div>';
        if ($tachesD['nonConformes'] === []) {
            $h .= '<div style="font-size:9pt" class="vert">Aucune non-conformité sur la période.</div>';
        } else {
            $coulNote = [3 => '#D97706', 2 => '#C0182B', 1 => '#8D1D2C'];
            $h .= '<table class="t"><tr><th class="l">Tâche</th><th class="l">Le</th><th class="l">Opérateur</th><th>Note</th><th class="l">Commentaire</th></tr>';
            foreach (array_slice($tachesD['nonConformes'], 0, 8) as $t3) {
                $h .= '<tr><td class="l">' . $e($t3['nom']) . '</td>'
                    . '<td class="l mut">' . $e(date('d/m', strtotime($t3['jour'])))
                    . ($t3['heure'] !== null ? ' à ' . $e($t3['heure']) : '') . '</td>'
                    . '<td class="l mut">' . $e($t3['par'] !== null ? mb_substr((string) $t3['par'], 0, 28) : '') . '</td>'
                    . '<td style="font-weight:bold;color:' . ($coulNote[$t3['note']] ?? '#C0182B') . '">' . $t3['note'] . '/5</td>'
                    . '<td class="l mut">' . $e($t3['commentaire'] !== null ? mb_substr((string) $t3['commentaire'], 0, 70) : '') . '</td></tr>';
            }
            $h .= '</table>';
            $reste2 = count($tachesD['nonConformes']) - 8;
            if ($reste2 > 0) { $h .= '<div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">+ ' . $reste2 . ' autre(s) non-conformité(s) sur la période.</div>'; }
        }
    }

    // ============ PAGE 2 : BUDGET & P&L ============
    $h .= '<div class="saut"></div>' . $entete('budget & compte de résultat');
    $h .= '<div class="sec" style="margin-top:0">Le compte de résultat de la période</div>';
    $lignesP = [
        ['Chiffre d’affaires', $pnl['ca'], 100.0, null, false],
        ['Coût matière', -$pnl['mat'], $foodPct, kpiSeuil('food-cost-pct', 32.0), true],
        ['Marge brute', $pnl['ca'] - $pnl['mat'], $pnl['ca'] > 0 ? ($pnl['ca'] - $pnl['mat']) / $pnl['ca'] * 100 : null, null, false],
        ['Main-d’œuvre', -$pnl['mo'], $moPct, kpiSeuil('labour-pct', 33.0), true],
        ['Frais généraux', -$pnl['frais'], $pnl['ca'] > 0 ? $pnl['frais'] / $pnl['ca'] * 100 : null, kpiSeuil('overhead-pct', 13.5), true],
        ['Résultat', $pnl['resultat'], $pnl['ca'] > 0 ? $pnl['resultat'] / $pnl['ca'] * 100 : null, null, false],
    ];
    $h .= '<table class="t"><tr><th class="l">Poste</th><th></th><th>Montant</th><th>% CA</th><th>Seuil</th></tr>';
    foreach ($lignesP as [$lib, $mnt, $pct, $seuil, $cout]) {
        $depasse = $cout && $seuil !== null && $pct !== null && $pct > $seuil;
        $w = $pct !== null ? (int) round(min(100, abs($pct)) * 0.5) : 0;
        $h .= '<tr><td class="l" style="font-weight:bold">' . $e($lib) . '</td>'
            . '<td style="width:52mm"><div style="height:2.6mm;background:#F4F1EB;border-radius:2mm"><div style="height:2.6mm;width:' . $w . 'mm;background:' . ($depasse ? '#C0182B' : ($lib === 'Chiffre d’affaires' ? '#5B7FA6' : '#7A9E7E')) . ';border-radius:2mm"></div></div></td>'
            . '<td style="font-weight:bold">' . ($mnt < 0 ? '− ' : '') . $eur0(abs($mnt)) . '</td>'
            . '<td>' . ($pct !== null ? $n1($pct) . ' %' : '') . '</td>'
            . '<td class="' . ($depasse ? 'rouge' : 'mut') . '">' . ($seuil !== null ? $n1($seuil) . ' %' . ($depasse ? ' dépassé' : '') : '') . '</td></tr>';
    }
    $h .= '</table>';
    // budget 5 mois
    $h .= '<div class="sec">Budget réalisé contre visé</div>';
    $cinqMois = [];   // les mêmes chiffres que les barres, en clair sous elles
    $h .= '<table width="100%" cellpadding="0" cellspacing="2"><tr>';
    for ($i = 4; $i >= 0; $i--) {
        $mB = date('Y-m', strtotime($m . '-01 -' . $i . ' month'));
        $kB = pvKpisMois($sid, $mB);
        $real = $kB !== null ? $kB['ca'] : null;
        $bB = null;
        try {
            $r2 = Db::row('SELECT revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE shop_id = ? AND year = ? AND month = ?',
                [(string) $sid, (int) substr($mB, 0, 4), (int) substr($mB, 5, 2)]);
            if ($r2 !== null) {
                $bB = (float) ($r2['revenue_budget'] ?? 0) > 0 ? (float) $r2['revenue_budget']
                    : ((float) ($r2['ca_theorique'] ?? 0) > 0 ? (float) $r2['ca_theorique'] : null);
            }
        } catch (PDOException $eB2) { /* rien */ }
        $hR = $real !== null ? (int) round($real / 1700) : 0;
        $hB = $bB !== null ? (int) round($bB / 1700) : 0;
        $att = ($real !== null && $bB !== null && $bB > 0) ? round($real / $bB * 100) : null;
        $cinqMois[] = ['m' => $mB, 'ca' => $real, 'budget' => $bB, 'att' => $att];
        $h .= '<td width="20%" align="center" style="vertical-align:bottom">'
            . '<table cellpadding="0" cellspacing="1"><tr>'
            . '<td style="vertical-align:bottom"><div style="width:6mm;height:' . max(2, $hR) . 'mm;background:#8D1D2C;border-radius:1mm 1mm 0 0"></div></td>'
            . '<td style="vertical-align:bottom"><div style="width:6mm;height:' . max(2, $hB) . 'mm;background:#E8C9A0;border-radius:1mm 1mm 0 0"></div></td></tr></table>'
            . '<div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">' . $e(strftime_fr(strtotime($mB . '-01'), 'M')) . '</div>'
            . '<div style="font-size:8pt;font-weight:bold;color:' . ($att !== null && $att >= 100 ? '#2d7a3e' : '#C0182B') . '">' . ($att !== null ? $att . ' %' : '') . '</div></td>';
    }
    $h .= '</tr></table><div style="font-size:7.5pt;color:#8b8177">Bordeaux : réalisé (endpoints) · sable : budget validé (à défaut le CA théorique de l’étude).</div>';
    // Les mêmes cinq mois en chiffres : une barre se compare, elle ne se lit
    // pas — le montant du budget, celui du CA et l'écart, en clair.
    $h .= '<table class="t nocut" style="margin-top:2mm"><tr><th class="l">Mois</th><th>Budget</th><th>CA réalisé</th><th>Écart</th><th>Atteinte</th></tr>';
    $totB5 = 0.0; $totC5 = 0.0;
    foreach ($cinqMois as $c5) {
        $ec5 = ($c5['ca'] !== null && $c5['budget'] !== null) ? $c5['ca'] - $c5['budget'] : null;
        $coul5 = $c5['att'] === null ? '#8b8177' : ($c5['att'] >= 100 ? '#2d7a3e' : ($c5['att'] >= 90 ? '#D97706' : '#C0182B'));
        if ($c5['ca'] !== null) { $totC5 += $c5['ca']; }
        if ($c5['budget'] !== null) { $totB5 += $c5['budget']; }
        $h .= '<tr' . ($c5['m'] === $m ? ' style="background:#FFF9EC"' : '') . '>'
            . '<td class="l" style="font-weight:bold">' . $e(strftime_fr(strtotime($c5['m'] . '-01'), 'M Y')) . '</td>'
            . '<td class="mut">' . ($c5['budget'] !== null ? $eur0($c5['budget']) : '') . '</td>'
            . '<td style="font-weight:bold">' . ($c5['ca'] !== null ? $eur0($c5['ca']) : '') . '</td>'
            . '<td style="color:' . $coul5 . '">' . ($ec5 !== null ? (($ec5 >= 0 ? '+ ' : '− ') . $eur0(abs($ec5))) : '') . '</td>'
            . '<td style="font-weight:bold;color:' . $coul5 . '">' . ($c5['att'] !== null ? $c5['att'] . ' %' : '') . '</td></tr>';
    }
    $ecT5 = $totC5 - $totB5;
    $attT5 = $totB5 > 0 ? round($totC5 / $totB5 * 100) : null;
    $coulT5 = $attT5 === null ? '#8b8177' : ($attT5 >= 100 ? '#2d7a3e' : '#C0182B');
    $h .= '<tr style="background:#F7F3EC"><td class="l" style="font-weight:bold">Cinq mois</td>'
        . '<td class="mut" style="font-weight:bold">' . $eur0($totB5) . '</td>'
        . '<td style="font-weight:bold">' . $eur0($totC5) . '</td>'
        . '<td style="font-weight:bold;color:' . $coulT5 . '">' . ($ecT5 >= 0 ? '+ ' : '− ') . $eur0(abs($ecT5)) . '</td>'
        . '<td style="font-weight:bold;color:' . $coulT5 . '">' . ($attT5 !== null ? $attT5 . ' %' : '') . '</td></tr></table>';
    // jours
    $h .= '<div class="sec">Les jours de la période</div>';
    // Une HEATMAP de calendrier plutôt que des barres : la hauteur d'une barre
    // dit un montant, elle ne dit pas la RENTABILITÉ du jour. Chaque case porte
    // le résultat en % du CA du jour, et se range sous son jour de semaine —
    // c'est là qu'on voit qu'un magasin perd tous les lundis.
    $serie = $pnl['serie'];
    $parJourP = [];
    $jCur = $du;
    foreach ($serie as $d2) {
        $parJourP[$jCur] = $d2;
        $jCur = date('Y-m-d', strtotime($jCur . ' +1 day'));
    }
    $coulPct = function (?float $p3): array {
        if ($p3 === null) { return ['#F4F1EB', '#b8b2a8']; }
        if ($p3 >= 20) { return ['#2d7a3e', '#fff']; }
        if ($p3 >= 15) { return ['#5f9e5f', '#fff']; }
        if ($p3 >= 10) { return ['#D9A73E', '#3d382f']; }
        if ($p3 >= 0)  { return ['#CC7A4A', '#fff']; }
        return ['#C0182B', '#fff'];
    };
    $joursSem = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    for ($iM = $n - 1; $iM >= 0; $iM--) {
        $mCal = date('Y-m', strtotime($m . '-01 -' . $iM . ' month'));
        if ($n > 1) { $h .= '<div style="font-size:8.5pt;font-weight:bold;margin:2mm 0 1mm">' . $e(strftime_fr(strtotime($mCal . '-01'), 'M Y')) . '</div>'; }
        $h .= '<table class="t" style="table-layout:fixed"><tr>';
        foreach ($joursSem as $js) { $h .= '<th style="text-align:center">' . $js . '</th>'; }
        $h .= '</tr><tr>';
        $prem = (int) date('N', strtotime($mCal . '-01'));
        for ($v = 1; $v < $prem; $v++) { $h .= '<td style="background:#FAF8F4"></td>'; }
        $col = $prem - 1;
        $dernier = (int) date('t', strtotime($mCal . '-01'));
        for ($jj = 1; $jj <= $dernier; $jj++) {
            if ($col === 7) { $h .= '</tr><tr>'; $col = 0; }
            $cle4 = sprintf('%s-%02d', $mCal, $jj);
            $dJ = $parJourP[$cle4] ?? null;
            $pctJ = ($dJ !== null && (float) $dJ['ca'] > 0) ? (float) $dJ['resultat'] / (float) $dJ['ca'] * 100 : null;
            [$bg4, $fg4] = $coulPct($pctJ);
            $h .= '<td style="text-align:center;background:' . $bg4 . ';color:' . $fg4 . ';border-bottom:1pt solid #fff;padding:1.1mm 0">'
                . '<div style="font-size:6pt;opacity:0.75">' . $jj . '</div>'
                . '<div style="font-size:8pt;font-weight:bold">' . ($pctJ !== null ? round($pctJ) . ' %' : '') . '</div></td>';
            $col++;
        }
        while ($col < 7) { $h .= '<td style="background:#FAF8F4"></td>'; $col++; }
        $h .= '</tr></table>';
    }
    $h .= '<div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">Résultat du jour en % de son chiffre d’affaires. Vert foncé ≥ 20 · vert ≥ 15 · ambre ≥ 10 · orange ≥ 0 · rouge : jour en perte · gris : jour sans donnée. Du ' . $e(date('d/m', strtotime($du))) . ' au ' . $e(date('d/m', strtotime($au))) . '.</div>';

    // LE RYTHME DE LA SEMAINE : la fréquentation moyenne d'un lundi, d'un
    // samedi, et la part de chiffre que chaque jour porte. C'est ce qui dit
    // où mettre les heures — une moyenne mensuelle ne le dira jamais.
    $kJ = dossierJoursKpis($sid, $du, $au);
    $sem = [];
    foreach ($kJ as $jour4 => $k4) {
        $dow4 = (int) date('N', strtotime($jour4));
        if (!isset($sem[$dow4])) { $sem[$dow4] = ['n' => 0, 'ca' => 0.0, 'tk' => 0]; }
        $sem[$dow4]['n']++;
        $sem[$dow4]['ca'] += $k4['ca'];
        $sem[$dow4]['tk'] += $k4['tickets'];
    }
    $caSem = array_sum(array_column($sem, 'ca'));
    if ($sem !== [] && $caSem > 0) {
        $h .= '<div class="bloc"><div class="sec">Le rythme de la semaine</div>';
        $h .= '<table class="t nocut"><tr><th class="l">Jour</th><th>Jours comptés</th><th>Clients / jour</th><th>CA moyen</th><th>Part du CA</th><th></th></tr>';
        $maxPart = max(array_map(fn ($x4) => $x4['ca'] / $caSem, $sem));
        for ($dow4 = 1; $dow4 <= 7; $dow4++) {
            $x4 = $sem[$dow4] ?? null;
            if ($x4 === null || $x4['n'] === 0) {
                $h .= '<tr><td class="l" style="font-weight:bold">' . $joursSem[$dow4 - 1] . '</td><td colspan="5" class="mut">fermé ou sans donnée</td></tr>';
                continue;
            }
            $part4 = $x4['ca'] / $caSem * 100;
            $w4 = (int) round($part4 / max(0.01, $maxPart * 100) * 32);
            $h .= '<tr><td class="l" style="font-weight:bold">' . $joursSem[$dow4 - 1] . '</td>'
                . '<td class="mut">' . $x4['n'] . '</td>'
                . '<td style="font-weight:bold">' . number_format($x4['tk'] / $x4['n'], 0, ',', ' ') . '</td>'
                . '<td>' . $eur0($x4['ca'] / $x4['n']) . '</td>'
                . '<td style="font-weight:bold">' . $n1($part4) . ' %</td>'
                . '<td style="width:34mm"><div style="height:2.4mm;width:' . max(1, $w4) . 'mm;background:#8D1D2C;border-radius:1mm"></div></td></tr>';
        }
        $h .= '</table><div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">Clients / jour : la moyenne des tickets de ce jour de semaine sur la période. Part du CA : ce que ce jour pèse dans le chiffre de la période, tous ses passages cumulés.</div></div>';
    }

    // ============ PAGE 2bis : LE BUDGET DE L'ANNÉE + HEATMAP ============
    $annee = (int) substr($m, 0, 4);
    // Le budget d'un mois, et SA NATURE : validé par le réseau (revenue_budget)
    // ou repris du CA théorique de l'étude. Les deux se somment séparément —
    // un budget d'année à moitié théorique ne s'annonce pas comme un budget
    // arrêté.
    $budDetailDe = function (int $s3, int $mo) use ($annee): array {
        try {
            $r3 = Db::row('SELECT revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE shop_id = ? AND year = ? AND month = ?',
                [(string) $s3, $annee, $mo]);
        } catch (PDOException $e3) { return ['v' => null, 'valide' => false]; }
        if ($r3 === null) { return ['v' => null, 'valide' => false]; }
        if ((float) ($r3['revenue_budget'] ?? 0) > 0) { return ['v' => (float) $r3['revenue_budget'], 'valide' => true]; }
        if ((float) ($r3['ca_theorique'] ?? 0) > 0) { return ['v' => (float) $r3['ca_theorique'], 'valide' => false]; }
        return ['v' => null, 'valide' => false];
    };
    $budDe = fn (int $s3, int $mo): ?float => $budDetailDe($s3, $mo)['v'];
    // Seuls les mois CLOS comptent : le mois en cours n'a que quelques jours,
    // son atteinte partielle mentirait en rouge.
    $moisMax = $annee < (int) date('Y') ? 12 : ($annee > (int) date('Y') ? 0 : (int) date('n') - 1);
    $h .= '<div class="saut"></div>' . $entete('le budget de l’année ' . $annee);
    $h .= '<div class="sec" style="margin-top:0">Les douze mois face au budget</div>';
    $h .= '<table class="t"><tr><th class="l">Mois</th><th>Budget</th><th>Réalisé</th><th>Atteinte</th><th>Écart</th><th>Clients manqués/j</th><th>Moyenne/j visée</th><th>Cumul réalisé</th><th>Cumul budget</th></tr>';
    $cumR = 0.0; $cumB = 0.0;
    $budAnnee = 0.0; $budValide = 0.0; $moisValides = 0; $cumTickets = 0;
    for ($mo = 1; $mo <= 12; $mo++) {
        $mM = sprintf('%04d-%02d', $annee, $mo);
        $bD = $budDetailDe($sid, $mo);
        $bM = $bD['v'];
        if ($bM !== null) {
            $budAnnee += $bM;
            if ($bD['valide']) { $budValide += $bM; $moisValides++; }
        }
        $kM = $mo <= $moisMax ? pvKpisMois($sid, $mM) : null;
        $rM = $kM !== null ? $kM['ca'] : null;
        if ($rM !== null) { $cumR += $rM; $cumTickets += (int) ($kM['tickets'] ?? 0); }
        if ($bM !== null && $mo <= $moisMax) { $cumB += $bM; }
        $att = ($rM !== null && $bM !== null && $bM > 0) ? $rM / $bM * 100 : null;
        $coulA = $att === null ? '#8b8177' : ($att >= 100 ? '#2d7a3e' : ($att >= 90 ? '#D97706' : '#C0182B'));
        // L'écart négatif traduit en clients : l'écart manqué ÷ le panier moyen
        // du mois donne le nombre de clients qu'il aurait fallu en plus, ÷ 30
        // pour le ramener à un manque par jour — un chiffre qui parle en salle.
        $panierM = $kM['panier'] ?? null;
        $clientsJ = ($rM !== null && $bM !== null && $rM < $bM && $panierM !== null && $panierM > 0)
            ? (($bM - $rM) / $panierM) / 30 : null;
        // La moyenne par jour VISÉE, indépendamment du réalisé : le budget du
        // mois ÷ le panier moyen ÷ 30 jours, arrondie au client entier
        // supérieur — le repère fixe à côté du manque constaté.
        $cibleJ = ($bM !== null && $panierM !== null && $panierM > 0) ? (int) ceil($bM / $panierM / 30) : null;
        $h .= '<tr' . ($mM === $m ? ' style="background:#FFF9EC"' : '') . '>'
            . '<td class="l" style="font-weight:bold">' . $e(strftime_fr(strtotime($mM . '-01'), 'M')) . '</td>'
            . '<td class="mut">' . ($bM !== null ? $eur0($bM) : '') . '</td>'
            . '<td style="font-weight:bold">' . ($rM !== null ? $eur0($rM) : '') . '</td>'
            . '<td style="font-weight:bold;color:' . $coulA . '">' . ($att !== null ? round($att) . ' %' : '') . '</td>'
            . '<td style="color:' . $coulA . '">' . (($rM !== null && $bM !== null) ? (($rM >= $bM ? '+ ' : '− ') . $eur0(abs($rM - $bM))) : '') . '</td>'
            . '<td class="rouge">' . ($clientsJ !== null ? number_format($clientsJ, 1, ',', ' ') : '') . '</td>'
            . '<td class="mut">' . ($cibleJ !== null ? $cibleJ : '') . '</td>'
            . '<td>' . ($mo <= $moisMax ? $eur0($cumR) : '') . '</td>'
            . '<td class="mut">' . ($mo <= $moisMax ? $eur0($cumB) : '') . '</td></tr>';
    }
    $attAn = $cumB > 0 ? round($cumR / $cumB * 100) : null;
    $h .= '<tr style="background:#F7F3EC"><td class="l" style="font-weight:bold">Année à date</td><td class="mut" style="font-weight:bold">' . $eur0($cumB) . '</td>'
        . '<td style="font-weight:bold">' . $eur0($cumR) . '</td>'
        . '<td style="font-weight:bold;color:' . ($attAn !== null && $attAn >= 100 ? '#2d7a3e' : '#C0182B') . '">' . ($attAn !== null ? $attAn . ' %' : '') . '</td>'
        . '<td colspan="5"></td></tr></table>';
    $h .= '<div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">Clients manqués/j : le mois en écart négatif traduit en clients, écart ÷ panier moyen du mois, ÷ 30 jours. Moyenne/j visée : budget du mois ÷ panier moyen ÷ 30 jours, arrondi au client supérieur.</div>';

    // La synthèse de l'année : le budget des douze mois, ce qui en est
    // réellement arrêté, le réalisé à date, et l'écart dit trois fois — en
    // milliers d'euros, en pourcentage, et en clients par jour, la seule
    // unité qui se pilote en salle.
    $ecartAn = $cumR - $cumB;
    $ecartPct = $cumB > 0 ? $ecartAn / $cumB * 100 : null;
    $panierAn = $cumTickets > 0 ? $cumR / $cumTickets : null;
    // L'écart CUMULÉ couvre tous les mois clos : le ramener à un jour, c'est
    // diviser par 30 jours ET par le nombre de mois écoulés — sinon un écart
    // de huit mois s'annonce comme le manque d'une seule journée.
    $clientsAn = ($panierAn !== null && $panierAn > 0 && $moisMax > 0)
        ? abs($ecartAn) / $panierAn / 30 / $moisMax : null;
    $kE = fn (float $v) => ($v >= 0 ? '+ ' : '− ') . number_format(abs($v) / 1000, 1, ',', ' ') . ' k€';
    $tuilesAn = [
        ['Budget de l’année', $eur0($budAnnee), $annee . ' · douze mois', 'mut'],
        ['Dont validé', $eur0($budValide), $moisValides . ' mois arrêté(s) sur 12', $moisValides === 12 ? 'vert' : 'mut'],
        ['Cumul de l’année', $eur0($cumR), 'réalisé sur ' . $moisMax . ' mois clos', 'acc'],
        ['Écart au budget', $kE($ecartAn), $ecartPct !== null ? (($ecartPct >= 0 ? '+ ' : '− ') . $n1(abs($ecartPct)) . ' % du budget à date') : '', $ecartAn >= 0 ? 'vert' : 'rouge'],
        ['Écart en clients', $clientsAn !== null ? ($ecartAn >= 0 ? '+ ' : '− ') . number_format($clientsAn, 1, ',', ' ') : '',
            $panierAn !== null ? 'par jour sur ' . $moisMax . ' mois · panier ' . number_format($panierAn, 2, ',', ' ') . ' €' : 'panier moyen inconnu',
            $ecartAn >= 0 ? 'vert' : 'rouge'],
    ];
    $h .= '<table width="100%" cellpadding="0" cellspacing="4" style="margin-top:3mm"><tr>';
    foreach ($tuilesAn as $tA) {
        $h .= '<td width="20%" class="tuile"><div class="cap">' . $e($tA[0]) . '</div>'
            . '<div class="serif" style="font-size:12pt;color:#8D1D2C;margin-top:1mm">' . $e($tA[1]) . '</div>'
            . '<div class="' . $tA[3] . '" style="font-size:6.5pt;margin-top:0.5mm">' . $e($tA[2]) . '</div></td>';
    }
    $h .= '</tr></table>';

    // Le MÊME écart, mais sur la seule période analysée — l'année à date dit
    // la trajectoire, ce bloc dit le mois qu'on vient de fermer.
    $ecartM = $budget !== null ? $pnl['ca'] - $budget : null;
    $ecartMPct = ($budget !== null && $budget > 0) ? $ecartM / $budget * 100 : null;
    $panierP = $pnl['tickets'] > 0 ? $pnl['ca'] / $pnl['tickets'] : null;
    $clientsM = ($ecartM !== null && $panierP !== null && $panierP > 0)
        ? abs($ecartM) / $panierP / (30 * $n) : null;
    $h .= '<div class="sec">L’écart de ' . $e($libP) . ' seul</div>';
    $tuilesM = [
        ['Budget ' . ($n === 1 ? 'du mois' : 'du trimestre'), $budget !== null ? $eur0($budget) : '',
            $budget !== null ? 'visé sur la période' : 'pas de budget posé', 'mut'],
        ['Réalisé', $eur0($pnl['ca']), number_format($pnl['tickets'], 0, ',', ' ') . ' tickets', 'acc'],
        ['Écart CA', $ecartM !== null ? $kE($ecartM) : '',
            $ecartMPct !== null ? (($ecartMPct >= 0 ? '+ ' : '− ') . $n1(abs($ecartMPct)) . ' % du budget') : '',
            ($ecartM !== null && $ecartM >= 0) ? 'vert' : 'rouge'],
        ['Écart en clients', $clientsM !== null ? (($ecartM >= 0 ? '+ ' : '− ') . number_format($clientsM, 1, ',', ' ')) : '',
            $panierP !== null ? 'par jour · panier ' . number_format($panierP, 2, ',', ' ') . ' €' : 'panier inconnu',
            ($ecartM !== null && $ecartM >= 0) ? 'vert' : 'rouge'],
    ];
    $h .= '<table width="100%" cellpadding="0" cellspacing="4"><tr>';
    foreach ($tuilesM as $tM) {
        $h .= '<td width="25%" class="tuile"><div class="cap">' . $e($tM[0]) . '</div>'
            . '<div class="serif" style="font-size:12.5pt;color:#8D1D2C;margin-top:1mm">' . $e($tM[1]) . '</div>'
            . '<div class="' . $tM[3] . '" style="font-size:6.5pt;margin-top:0.5mm">' . $e($tM[2]) . '</div></td>';
    }
    $h .= '</tr></table>';
    // La HEATMAP réseau : magasins × mois, colorée à l'atteinte du budget.
    $h .= '<div class="sec">La heatmap de l’année</div>';
    $h .= '<table class="t" style="table-layout:fixed"><tr><th class="l" style="width:22mm">Magasin</th>';
    $moisCourt = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
    for ($mo = 1; $mo <= 12; $mo++) { $h .= '<th style="text-align:center">' . $moisCourt[$mo - 1] . '</th>'; }
    $h .= '</tr>';
    foreach ($shops as $s3 => $nom3) {
        $h .= '<tr><td class="l" style="font-weight:' . ($s3 === $sid ? 'bold' : 'normal') . '">' . $e($court($nom3)) . '</td>';
        for ($mo = 1; $mo <= 12; $mo++) {
            if ($mo > $moisMax) { $h .= '<td style="text-align:center;background:#FAF8F4"></td>'; continue; }
            $bM = $budDe((int) $s3, $mo);
            $kM = pvKpisMois((int) $s3, sprintf('%04d-%02d', $annee, $mo));
            $att = ($kM !== null && $bM !== null && $bM > 0) ? $kM['ca'] / $bM * 100 : null;
            if ($att === null) { $h .= '<td style="text-align:center;background:#F4F1EB;color:#b8b2a8;font-size:6.5pt"></td>'; continue; }
            $bg = $att >= 105 ? '#2d7a3e' : ($att >= 100 ? '#5f9e5f' : ($att >= 90 ? '#D9A73E' : ($att >= 80 ? '#CC7A4A' : '#C0182B')));
            $h .= '<td style="text-align:center;background:' . $bg . ';color:#fff;font-weight:bold;font-size:7pt;border-bottom:1pt solid #fff">' . round($att) . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</table><div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">Chaque case : le % d’atteinte du budget du mois (réalisé endpoints ÷ budget validé, à défaut le CA théorique). Vert foncé ≥ 105 · vert ≥ 100 · ambre ≥ 90 · orange ≥ 80 · rouge en dessous · gris : pas de budget posé.</div>';

    // ============ PAGE 3 : PRODUITS ============
    $h .= '<div class="saut"></div>' . $entete('produits & assortiment');
    $prods = $prodD['prods']; $part = $prodD['part'];
    $lTop = array_filter($prods, fn ($p2) => $p2['ici'] > 0);
    uasort($lTop, fn ($a, $b) => $b['ici'] <=> $a['ici']);
    $lTop = array_slice($lTop, 0, 14, true);
    $jours = max(1, (int) ((strtotime($au) - strtotime($du)) / 86400) + 1);
    $liss = $jours * 0.5;
    $h .= '<div class="sec" style="margin-top:0">Top 14 références</div>';
    $h .= '<table class="t"><tr><th class="l">Référence</th><th class="l">Catégorie</th><th>Pièces</th><th>Attendu</th><th>Jauge</th></tr>';
    foreach ($lTop as $p2) {
        $attendu = $p2['reseau'] * $part;
        $delta = $attendu > 0 ? (int) round(($p2['ici'] / $attendu - 1) * ($attendu / ($attendu + $liss)) * 100) : 0;
        $h .= '<tr><td class="l" style="font-weight:bold">' . $e($p2['nom']) . '</td><td class="l mut">' . $e($p2['cat']) . '</td>'
            . '<td>' . number_format($p2['ici'], 0, ',', ' ') . '</td><td class="mut">' . number_format($attendu, 0, ',', ' ') . '</td>'
            . '<td>' . $jauge($delta) . '</td></tr>';
    }
    $h .= '</table>';
    // trous
    $trous = [];
    foreach ($prods as $p2) {
        if ($p2['ici'] > 0 || $p2['reseau'] <= 0) { continue; }
        $ailleurs = $p2['vendeurs'];
        if ($ailleurs < 2) { continue; }
        $prix = $p2['reseau'] > 0 ? $p2['ca'] / $p2['reseau'] : 0;
        $trous[] = ['nom' => $p2['nom'], 'cat' => $p2['cat'], 'attendu' => $p2['reseau'] * $part,
            'ailleurs' => $ailleurs . '/' . ($prodD['nbShops'] - 1), 'manque' => $p2['reseau'] * $part * $prix];
    }
    usort($trous, fn ($a, $b) => $b['manque'] <=> $a['manque']);
    $h .= '<div class="sec rouge" style="border-color:#C0182B;color:#C0182B">Pas vendu ici, vendu ailleurs (manque estimé : ' . $eur0(array_sum(array_column($trous, 'manque'))) . ')</div>';
    if ($trous === []) {
        $h .= '<div style="font-size:9pt" class="vert">Aucun trou : tout ce que le réseau vend se vend aussi ici.</div>';
    } else {
        $h .= '<table class="t"><tr><th class="l">Référence</th><th class="l">Catégorie</th><th>Attendu ici</th><th>Ailleurs dans</th><th>Manque</th></tr>';
        foreach (array_slice($trous, 0, 10) as $t2) {
            $h .= '<tr style="background:#FDF6F4"><td class="l" style="font-weight:bold">' . $e($t2['nom']) . '</td><td class="l mut">' . $e($t2['cat']) . '</td>'
                . '<td>' . number_format($t2['attendu'], 0, ',', ' ') . ' pcs</td><td>' . $e($t2['ailleurs']) . '</td>'
                . '<td class="rouge" style="font-weight:bold">' . $eur0($t2['manque']) . '</td></tr>';
        }
        $h .= '</table>';
    }
    // couverture
    $cats = [];
    foreach ($prods as $p2) {
        if ($p2['reseau'] <= 0) { continue; }
        $c2 = $p2['cat'] !== '' ? $p2['cat'] : 'Sans catégorie';
        if (!isset($cats[$c2])) { $cats[$c2] = [0, 0]; }
        $cats[$c2][0]++;
        if ($p2['ici'] <= 0) { $cats[$c2][1]++; }
    }
    uasort($cats, fn ($a, $b) => ($a[0] - $a[1]) / max(1, $a[0]) <=> ($b[0] - $b[1]) / max(1, $b[0]));
    $h .= '<div class="sec">La couverture de l’assortiment, par catégorie</div><table width="100%" cellpadding="2" cellspacing="0"><tr>';
    $iC = 0;
    foreach (array_slice($cats, 0, 12, true) as $c2 => $x2) {
        if ($iC > 0 && $iC % 3 === 0) { $h .= '</tr><tr>'; }
        $pc = (int) round(100 * ($x2[0] - $x2[1]) / max(1, $x2[0]));
        $coul = $pc >= 85 ? '#2d7a3e' : ($pc >= 70 ? '#D97706' : '#C0182B');
        $h .= '<td width="33%" style="padding:1mm 2mm"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-size:8pt;font-weight:bold">' . $e(mb_substr($c2, 0, 24)) . '</td>'
            . '<td align="right" style="font-size:8pt;font-weight:bold;color:' . $coul . '">' . $pc . ' %</td></tr></table>'
            . '<div style="height:2.2mm;background:#F0EDE7;border-radius:2mm"><div style="height:2.2mm;width:' . (int) ($pc * 0.55) . 'mm;background:' . $coul . ';border-radius:2mm"></div></div>'
            . '<div style="font-size:6.5pt;color:#8b8177">' . $x2[1] . ' non vendue(s) / ' . $x2[0] . '</div></td>';
        $iC++;
    }
    $h .= '</tr></table>';

    // ============ PAGE 4 : ÉQUIPE ============
    $h .= '<div class="saut"></div>' . $entete('l’équipe de vente');
    $h .= '<div class="sec" style="margin-top:0">Le classement de la période</div>';
    $h .= '<table class="t"><tr><th class="l">#</th><th class="l">Vendeuse</th><th>Score</th><th>CA</th><th>Tickets</th><th>Heures</th><th>CA/h</th><th>Lignes/t</th><th>ETP</th><th>CA/ETP</th></tr>';
    $rg = 0;
    foreach ($eq['equipe'] as $x) {
        if ($x['ca'] <= 0) { continue; }
        $rg++;
        if ($rg > 14) { break; }
        $caEtp2 = $x['etp'] > 0 ? (int) round($x['ca'] / $x['etp']) : null;
        $h .= '<tr' . ($rg === 1 ? ' style="background:#FFF9EC"' : '') . '><td class="l mut">' . $rg . '.</td>'
            . '<td class="l" style="font-weight:bold">' . $e($x['nom']) . '</td>'
            . '<td style="font-weight:bold;' . ($rg === 1 ? 'color:#8D1D2C' : '') . '">' . ($x['score'] ?? '') . '</td>'
            . '<td>' . $eur0($x['ca']) . '</td><td>' . number_format($x['tickets'], 0, ',', ' ') . '</td>'
            . '<td>' . $n1($x['heures']) . ' h</td><td>' . ($x['caH'] !== null ? $x['caH'] . ' €' : '') . '</td>'
            . '<td>' . ($x['lignesT'] !== null ? str_replace('.', ',', (string) $x['lignesT']) : '') . '</td>'
            . '<td class="mut">' . str_replace('.', ',', (string) $x['etp']) . '</td>'
            . '<td>' . ($caEtp2 !== null ? $eur0($caEtp2) : '') . '</td></tr>';
    }
    $h .= '</table>';
    // records
    $h .= '<div class="sec">Bats ton record</div>';
    if ($n !== 1) {
        $h .= '<div style="font-size:9pt" class="mut">Les records se jouent au mois : voir le dossier mensuel de chaque mois du trimestre.</div>';
    } else {
        $h .= '<table class="t"><tr><th class="l">Vendeuse</th><th>Record 12 mois</th><th>Moyenne du mois</th><th>Écart</th><th>Prime</th></tr>';
        $parMoisR = [];
        foreach (venteFenetreRecord($m) as $mF) { $parMoisR[$mF] = null; }
        $nomDe2 = [];
        foreach ($shops as $s3 => $n3) { $nomDe2[(string) $s3] = $n3; }
        foreach (array_keys($parMoisR) as $mF) {
            $rF = venteMois($mF, $nomDe2);
            $parMoisR[$mF] = $rF['motif'] === null ? $rF['lignes'] : null;
        }
        $nR = 0;
        foreach (($eq['parMois'][$m] ?? []) ?: [] as $l) {
            if ((string) $l['shopId'] !== (string) $sid || ($l['tickets'] ?? 0) < VENTE_CROSS_MIN_TICKETS || $l['lignesTicket'] === null) { continue; }
            $rec = venteRecordVendeuse($parMoisR, (string) $l['id'], $m);
            $pr = venteRecordPrime((float) $l['lignesTicket'], $rec, $reg['eurDixieme'], $reg['maxDixiemes']);
            $d2 = $rec !== null ? round((float) $l['lignesTicket'] - $rec, 2) : null;
            $nR++;
            if ($nR > 12) { break; }
            $paie = ($pr['prime'] ?? 0) > 0;
            $h .= '<tr' . ($paie ? ' style="background:#F2F7F3"' : '') . '><td class="l" style="font-weight:bold">' . $e($l['nom']) . '</td>'
                . '<td class="mut">' . ($rec !== null ? str_replace('.', ',', (string) $rec) : 'sans référence') . '</td>'
                . '<td style="font-weight:bold">' . str_replace('.', ',', (string) $l['lignesTicket']) . '</td>'
                . '<td class="' . ($d2 !== null && $d2 > 0 ? 'vert' : 'mut') . '">' . ($d2 === null ? '' : (($d2 > 0 ? '+ ' : ($d2 < 0 ? '− ' : '＝ ')) . str_replace('.', ',', (string) abs($d2)))) . '</td>'
                . '<td class="' . ($paie ? 'vert' : 'mut') . '" style="font-weight:bold">' . ($paie ? $pr['prime'] . ' € (' . $pr['tranches'] . ' dixième(s))' : '') . '</td></tr>';
        }
        if ($nR === 0) { $h .= '<tr><td class="l mut" colspan="5">Les lignes par ticket du mois ne sont pas encore moissonnées : la section se remplira toute seule.</td></tr>'; }
        $h .= '</table>';
    }

    // ============ PAGE 5 : CROISEMENTS ============
    $h .= '<div class="saut"></div>' . $entete('les croisements');
    if ($crois === null || ($crois['jours'] ?? []) === []) {
        $h .= '<div style="font-size:9.5pt" class="mut">La moisson des croisements de ' . $e($libP) . ' n’est pas encore passée : cette page se remplira toute seule (POST /ventes/crois-moisson pour forcer).</div>';
    } else {
        $parEmp = (array) ($crois['emp'][$sid] ?? []);
        $fT = array_sum(array_map(fn ($x2) => (int) ($x2['f'] ?? 0), $parEmp));
        $fbT = array_sum(array_map(fn ($x2) => (int) ($x2['fb'] ?? 0), $parEmp));
        $prixB = (float) ($crois['prixBoisson'][$sid] ?? 0);
        $h .= '<div class="sec" style="margin-top:0">L’attache Flip &amp; Flap → boisson</div>'
            . '<table width="100%" cellpadding="0" cellspacing="4"><tr>'
            . '<td width="25%" class="tuile"><div class="cap">Tickets F&amp;F</div><div class="serif" style="font-size:14pt;color:#8D1D2C;margin-top:1mm">' . number_format($fT, 0, ',', ' ') . '</div></td>'
            . '<td width="25%" class="tuile"><div class="cap">… avec boisson</div><div class="serif" style="font-size:14pt;color:#8D1D2C;margin-top:1mm">' . number_format($fbT, 0, ',', ' ') . '</div></td>'
            . '<td width="25%" class="tuile"><div class="cap">Taux d’attache</div><div class="serif" style="font-size:14pt;color:#8D1D2C;margin-top:1mm">' . ($fT > 0 ? round($fbT / $fT * 100) : 0) . ' %</div></td>'
            . '<td width="25%" class="tuile"><div class="cap">Laissé au comptoir</div><div class="serif" style="font-size:14pt;color:#C0182B;margin-top:1mm">' . $eur0(($fT - $fbT) * $prixB) . '</div>'
            . '<div style="font-size:6.5pt" class="mut">tickets sans boisson × prix moyen encaissé</div></td></tr></table>';
        // par vendeuse
        $emps = venteEmployes();
        $lignesA = [];
        foreach ($parEmp as $eid => $x2) {
            $f2 = (int) ($x2['f'] ?? 0);
            if ($f2 < 20) { continue; }
            $emp2 = $emps[(int) $eid] ?? null;
            if ($emp2 === null || (string) $emp2['shop'] !== (string) $sid) { continue; }
            $lignesA[] = ['nom' => $emp2['nom'], 'f' => $f2, 'fb' => (int) ($x2['fb'] ?? 0),
                'taux' => round((int) ($x2['fb'] ?? 0) / $f2 * 100)];
        }
        usort($lignesA, fn ($a, $b) => $b['taux'] <=> $a['taux']);
        $tauxMag = $fT > 0 ? $fbT / $fT * 100 : 0;
        $h .= '<div class="sec">L’attache par vendeuse</div>';
        $h .= '<table class="t"><tr><th class="l">Vendeuse</th><th>Tickets F&amp;F</th><th>Avec boisson</th><th>Taux</th><th>Jauge vs magasin</th></tr>';
        foreach (array_slice($lignesA, 0, 12) as $l2) {
            $d2 = $tauxMag > 0 ? (int) round(($l2['taux'] / $tauxMag - 1) * 100) : 0;
            $h .= '<tr><td class="l" style="font-weight:bold">' . $e($l2['nom']) . '</td><td>' . $l2['f'] . '</td><td>' . $l2['fb'] . '</td>'
                . '<td style="font-weight:bold">' . $l2['taux'] . ' %</td><td>' . $jauge($d2) . '</td></tr>';
        }
        $h .= '</table>';
        // paires
        $paires = (array) ($crois['paires'][$sid] ?? []);
        arsort($paires);
        $h .= '<div class="sec">Les paires de la période</div>';
        $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%" style="vertical-align:top;padding-right:4mm">';
        $iP = 0;
        foreach (array_slice($paires, 0, 12, true) as $paire => $nP) {
            if ($iP === 6) { $h .= '</td><td width="50%" style="vertical-align:top;padding-left:4mm">'; }
            [$a2, $b2] = explode('|', (string) $paire) + ['', ''];
            $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:0.4pt solid #F0EDE7"><tr>'
                . '<td style="font-size:8.5pt;padding:1mm 0"><b>' . $e($a2) . '</b> <span class="mut">+</span> <b>' . $e($b2) . '</b></td>'
                . '<td align="right" style="font-size:8.5pt;color:#8b8177">' . number_format((int) $nP, 0, ',', ' ') . ' tickets</td></tr></table>';
            $iP++;
        }
        $h .= '</td></tr></table>';
    }
    // ============ PAGE 6 : RÉPUTATION DIGITALE ============
    $h .= '<div class="saut"></div>' . $entete('la réputation digitale');
    $rep = null; $repMag = null;
    try {
        $rep = ep_reputation();
        foreach ((array) ($rep['magasins'] ?? []) as $mg2) {
            if ((string) ($mg2['id'] ?? '') === (string) $sid) { $repMag = $mg2; break; }
        }
    } catch (Throwable $eR) { /* connecteur muet */ }
    if ($repMag === null) {
        $h .= '<div style="font-size:9.5pt" class="mut">La fiche Google de ce magasin n’est pas raccordée : voir l’écran Réputation digitale.</div>';
    } else {
        $cible = (float) ($rep['cible'] ?? 4.5);
        $moyR = (float) (($rep['reseau'] ?? [])['moyenne'] ?? 0);
        $note = (float) ($repMag['note'] ?? 0);
        $h .= '<table width="100%" cellpadding="0" cellspacing="4"><tr>'
            . '<td width="25%" class="tuile"><div class="cap">Note Google</div><div class="serif" style="font-size:16pt;color:' . ($note >= $cible ? '#2d7a3e' : '#C0182B') . ';margin-top:1mm">' . str_replace('.', ',', (string) $note) . ' ★</div></td>'
            . '<td width="25%" class="tuile"><div class="cap">Avis publiés</div><div class="serif" style="font-size:16pt;color:#8D1D2C;margin-top:1mm">' . (int) ($repMag['avis'] ?? 0) . '</div></td>'
            . '<td width="25%" class="tuile"><div class="cap">Cible réseau</div><div class="serif" style="font-size:16pt;color:#8D1D2C;margin-top:1mm">' . str_replace('.', ',', (string) $cible) . ' ★</div>'
            . '<div style="font-size:6.5pt" class="' . ($note >= $cible ? 'vert' : 'rouge') . '">' . ($note >= $cible ? 'tenue' : ('écart ' . str_replace('.', ',', (string) round($note - $cible, 1)))) . '</div></td>'
            . '<td width="25%" class="tuile"><div class="cap">Avis 5★ pour la cible</div><div class="serif" style="font-size:16pt;color:#C0182B;margin-top:1mm">' . (int) ($repMag['avis5Requis'] ?? 0) . '</div>'
            . '<div style="font-size:6.5pt" class="mut">avis 5★ nécessaires pour atteindre ' . str_replace('.', ',', (string) $cible) . '</div></td></tr></table>';
        $h .= '<div class="sec">Le magasin dans le réseau</div>';
        $h .= '<table class="t"><tr><th class="l">Magasin</th><th>Note</th><th>Avis</th><th>Écart à la cible</th><th>Avis 5★ requis</th></tr>';
        foreach ((array) ($rep['magasins'] ?? []) as $mg2) {
            $n2 = (float) ($mg2['note'] ?? 0);
            $h .= '<tr' . ((string) ($mg2['id'] ?? '') === (string) $sid ? ' style="background:#FFF9EC"' : '') . '>'
                . '<td class="l" style="font-weight:bold">' . $e($court((string) ($mg2['nom'] ?? ''))) . '</td>'
                . '<td style="font-weight:bold;color:' . ($n2 >= $cible ? '#2d7a3e' : '#C0182B') . '">' . str_replace('.', ',', (string) $n2) . ' ★</td>'
                . '<td>' . (int) ($mg2['avis'] ?? 0) . '</td>'
                . '<td class="' . ((float) ($mg2['ecart'] ?? 0) >= 0 ? 'vert' : 'rouge') . '">' . str_replace('.', ',', (string) ($mg2['ecart'] ?? '')) . '</td>'
                . '<td>' . (int) ($mg2['avis5Requis'] ?? 0) . '</td></tr>';
        }
        $h .= '<tr style="background:#F7F3EC"><td class="l" style="font-weight:bold">Réseau (moyenne)</td>'
            . '<td style="font-weight:bold">' . str_replace('.', ',', (string) $moyR) . ' ★</td>'
            . '<td>' . (int) (($rep['reseau'] ?? [])['avis'] ?? 0) . '</td><td colspan="2"></td></tr></table>';
        $niv = (array) ((($repMag['repartition'] ?? [])['niveaux'] ?? []));
        if ($niv !== []) {
            $h .= '<div class="sec">Les derniers avis lus</div>';
            $maxN = max(array_map(fn ($x2) => (int) ($x2['n'] ?? 0), $niv) ?: [1]) ?: 1;
            foreach ($niv as $x2) {
                $w = (int) round((int) ($x2['n'] ?? 0) / $maxN * 90);
                $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:1mm"><tr>'
                    . '<td style="width:12mm;font-size:8.5pt">' . (int) ($x2['note'] ?? 0) . ' ★</td>'
                    . '<td><div style="height:3mm;background:#F4F1EB;border-radius:2mm"><div style="height:3mm;width:' . $w . 'mm;background:' . ((int) ($x2['note'] ?? 0) >= 4 ? '#2d7a3e' : '#C0182B') . ';border-radius:2mm"></div></div></td>'
                    . '<td style="width:10mm;text-align:right;font-size:8.5pt">' . (int) ($x2['n'] ?? 0) . '</td></tr></table>';
            }
        }
    }

    // ============ PAGE 7 : LES PRIX FACE AU RÉSEAU ============
    $h .= '<div class="saut"></div>' . $entete('les prix encaissés face au réseau');
    $h .= '<div class="sec" style="margin-top:0">Prix moyen encaissé par référence, contre la moyenne réseau</div>'
        . '<div style="font-size:8pt;color:#5d564e;margin-bottom:2mm">Prix encaissé = CA de la référence ÷ pièces vendues, remises comprises : le prix que paie vraiment le client. Le delta dit ce qu’il faudrait bouger pour rejoindre la moyenne du réseau.</div>';
    $lPrix = [];
    foreach ($prodD['prods'] as $p2) {
        if ($p2['ici'] < 30 || $p2['reseau'] <= 0) { continue; }
        $pIci = $p2['iciCa'] / $p2['ici'];
        $pRes = $p2['ca'] / $p2['reseau'];
        if ($pRes <= 0) { continue; }
        $lPrix[] = ['nom' => $p2['nom'], 'cat' => $p2['cat'], 'pieces' => $p2['ici'],
            'ici' => $pIci, 'res' => $pRes, 'delta' => ($pIci / $pRes - 1) * 100,
            'euro' => $pRes - $pIci, 'enjeu' => abs($pRes - $pIci) * $p2['ici']];
    }
    usort($lPrix, fn ($a, $b) => $b['enjeu'] <=> $a['enjeu']);
    $h .= '<table class="t"><tr><th class="l">Référence</th><th class="l">Catégorie</th><th>Pièces ici</th><th>Prix ici</th><th>Prix réseau</th><th>Delta</th><th>Pour rejoindre la moyenne</th></tr>';
    foreach (array_slice($lPrix, 0, 18) as $l2) {
        $d2 = (int) round($l2['delta']);
        $h .= '<tr' . (abs($d2) >= 5 ? ' style="background:#FDF6F4"' : '') . '>'
            . '<td class="l" style="font-weight:bold">' . $e($l2['nom']) . '</td><td class="l mut">' . $e($l2['cat']) . '</td>'
            . '<td>' . number_format($l2['pieces'], 0, ',', ' ') . '</td>'
            . '<td style="font-weight:bold">' . number_format($l2['ici'], 2, ',', ' ') . ' €</td>'
            . '<td class="mut">' . number_format($l2['res'], 2, ',', ' ') . ' €</td>'
            . '<td style="font-weight:bold;color:' . ($d2 >= 0 ? '#2d7a3e' : '#C0182B') . '">' . ($d2 >= 0 ? '+ ' : '− ') . abs($d2) . ' %</td>'
            . '<td class="' . ($l2['euro'] >= 0 ? 'rouge' : 'vert') . '">' . ($l2['euro'] >= 0 ? '+ ' : '− ') . number_format(abs($l2['euro']), 2, ',', ' ') . ' € / pièce</td></tr>';
    }
    $h .= '</table><div style="font-size:7.5pt;color:#8b8177;margin-top:1mm">Trié par enjeu (écart × volume du magasin). Fond rosé : delta d’au moins 5 % (remise locale, prix mal encodé ou grille différente, à vérifier en caisse).</div>';

    // ============ PAGES PHOTOS : LES 5/5, PUIS LES 3-2-1 ============
    // Les mêmes fiches que le rapport hebdomadaire : la photo prise en
    // boutique, ses repères dessinés, la date et la cote. Douze par page,
    // ce qu'une A4 porte exactement — au-delà, on dit combien restent.
    $fichesDe = function (array $liste, int $max) use ($sid, $nomC): array {
        $cartes = [];
        foreach (array_slice($liste, 0, $max) as $t4) {
            $f = rapFicheTache((string) $sid, $t4['taskId'], $t4['jour'], $t4['nom'], $nomC,
                (int) $t4['note'], (string) ($t4['commentaire'] ?? ''), true);
            if ($f !== '') { $cartes[] = rapFondNote((int) $t4['note']) + ['html' => $f]; }
        }
        return $cartes;
    };
    // Les exemplaires tiennent en UNE page : douze félicitations suffisent.
    // Les écarts, eux, se déroulent en entier — c'est la matière de la
    // reprise en boutique, on n'en garde pas la moitié pour soi. La borne
    // haute n'est là que pour qu'un mois catastrophique ne fasse pas un
    // document de cinquante pages.
    foreach ([['exemplaires', 'les tâches exemplaires (5/5)', 'Ce qui a été bien fait sur la période, photo à l’appui.', 12],
              ['nonConformes', 'les non-conformités (3, 2, 1)', 'Chaque écart avec sa photo et ses repères, de quoi le reprendre en boutique.', 60]] as [$cle, $sousT, $chapeau, $max]) {
        $liste = $tachesD[$cle] ?? [];
        if ($liste === []) { continue; }
        $cartes = $fichesDe($liste, $max);
        if ($cartes === []) { continue; }
        // Douze vignettes par A4, et CHAQUE page garde son en-tête : une
        // grille paginée d'un seul tenant laisserait les pages suivantes
        // sans titre ni magasin.
        foreach (array_chunk($cartes, 12) as $iPage => $paquet) {
            $h .= '<div class="saut"></div>' . $entete($sousT . ($iPage > 0 ? ' (suite)' : ''));
            if ($iPage === 0) {
                $h .= '<div style="font-size:8.5pt;color:#5d564e;margin-bottom:3mm">' . $e($chapeau) . '</div>';
            }
            $h .= rapFichesGrille($paquet, 3, 4, false, false);
        }
        $reste3 = count($liste) - count($cartes);
        if ($reste3 > 0) {
            $h .= '<div style="font-size:7.5pt;color:#8b8177;margin-top:2mm">+ ' . $reste3
                . ' autre(s) sur la période, à consulter dans le cockpit.</div>';
        }
    }

    $h .= '</div>';

    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Dossier : ' . $e($nomC) . '</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => $nomC, 'rapport' => 'Dossier d’analyse ' . $libP,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="dossier-' . mktSlug($nomC) . '-' . $m . ($n === 3 ? '-trimestre' : '') . '.pdf"');
    echo $pdf;
    exit;
}
