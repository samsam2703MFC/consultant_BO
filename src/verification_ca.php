<?php
declare(strict_types=1);

/**
 * Vérification — lot 5 : le chiffre d'affaires (31/10/2026). Plan : docs/plan-verification.md § 3.4 ; maquette d.png.
 *
 * La CAISSE fait référence : les tickets du panel, jour par jour (`/shops/{id}/transactions?date=`, TTC après remise
 * et TVA — le relevé du CA net, `caNet{magasin}:{jour}`, src/ca_net_mois.php). Chaque autre chiffre se lit en écart :
 *  · le P&L du panel (`/consultant/shops/{id}/pnl/daily`, `revenue`, sommé sur le mois — TTC : mesuré le 31/10, il
 *    reprend la caisse au centime à Halle en septembre) ;
 *  · la base des redevances (`/consultant/shops/sales-kpis` × les taux de la fiche boutique — le calcul du Fonds,
 *    fondsRoyaltiesCalcul) ;
 *  · le CA attendu par la matière (lot 4) : sur les semaines d'inventaire du mois, la matière consommée (hors poubelle
 *    déclarée) rapportée à celle que les ventes expliquent, appliquée à la caisse. Il ne vaut que si les entrées sont
 *    validées et les recettes fiables ;
 *  · à part : les lignes de caisse sans produit (« divers »), les remises sur le ticket entier, les corrections de
 *    ventes après encaissement et les anomalies relevées par le panel (routes mesurées par la sonde, lues si elles
 *    répondent), et les « jours à regarder ».
 *
 * Chaque jour clos entre dans `ceo_verif_ca` (caisse, P&L, sans produit, remises) : la base quotidienne du CA.
 *
 * GET /verification/ca[?mois=AAAA-MM][&shop=] — le mois (celui d'hier par défaut), le réseau, chaque magasin, les jours
 * à regarder d'un magasin (le premier à regarder par défaut) et le réseau mois par mois.
 * GET /verification/ca/sonde?shop=&mois= — lecture seule : la forme des routes de corrections et d'anomalies.
 */

const VC_SEUILS = [1.0, 4.0];       // écart de CA (% de la caisse) : vert ≤ 1 %, orange ≤ 4 % (plan § 7)
const VC_SEC_DEMANDE = 20;          // secondes de lecture des tickets à la demande (cnMois) ; le cron fait le reste
const VC_JOUR_MIN_EUR = 20.0;       // un jour où P&L et caisse diffèrent de moins de 20 € n'est pas « à regarder »
const VC_SP_MIN_EUR = 30.0;         // lignes sans produit ou remises sur le ticket entier : à regarder au-delà de 30 €
const VC_MOIS_HISTO = 6;

function vcNb(float $v, int $d = 0): string { return number_format($v, $d, ',', ' '); }

/** Les jours d'un mois, jusqu'à hier. */
function vcJours(string $ym): array
{
    $deb = $ym . '-01';
    $fin = min(date('Y-m-t', strtotime($deb)), date('Y-m-d', strtotime('-1 day')));
    $out = [];
    for ($t = strtotime($deb . ' 12:00:00'); date('Y-m-d', $t) <= $fin; $t += 86400) { $out[] = date('Y-m-d', $t); }
    return $out;
}

/** La caisse d'un magasin, jour par jour : [jour => [ttc, tva, tickets]] (les jours lus seulement). */
function vcCaisse(int $sid, array $jours): array
{
    $out = [];
    foreach ($jours as $j) {
        try { $c = setting('caNet' . $sid . ':' . $j); } catch (Throwable $e) { $c = null; }
        if (is_array($c) && isset($c['b'])) { $out[$j] = [(float) $c['b'], (float) ($c['t'] ?? 0), (int) ($c['n'] ?? 0)]; }
    }
    return $out;
}

/** Le P&L quotidien de chaque magasin sur le mois : [sid => [jour => revenue]] ; gardé 24 h (mois clos) ou 1 h. */
function vcPnl(array $sids, string $ym): array
{
    $out = []; $ch = [];
    $ttl = $ym < date('Y-m') ? 86400 : 3600;
    foreach ($sids as $sid) {
        try { $c = setting('vcPnl:' . $sid . ':' . $ym); } catch (Throwable $e) { $c = null; }
        if (is_array($c) && isset($c['le'], $c['v']) && time() - (int) $c['le'] < $ttl) { $out[$sid] = (array) $c['v']; continue; }
        $du = $ym . '-01'; $au = min(date('Y-m-t', strtotime($du)), date('Y-m-d'));
        $ch[$sid] = '/consultant/shops/' . $sid . '/pnl/daily?' . http_build_query(['date_from' => $du, 'date_to' => $au, 'from' => $du, 'to' => $au]);
    }
    if ($ch !== [] && class_exists('PanelApi') && PanelApi::configured()) {
        foreach (PanelApi::getParallele($ch, 4) as $sid => $r) {
            if (!is_array($r)) { continue; }
            $jours = (array) ($r['days'] ?? (array_is_list($r) ? $r : []));
            $v = [];
            foreach ($jours as $x) {
                if (!is_array($x)) { continue; }
                $d = substr((string) ($x['date'] ?? ''), 0, 10);
                if ($d !== '' && is_numeric($x['revenue'] ?? null)) { $v[$d] = round((float) $x['revenue'], 2); }
            }
            $out[(int) $sid] = $v;
            try { svGrave('vcPnl:' . $sid . ':' . $ym, ['le' => time(), 'v' => $v]); } catch (Throwable $e) { /* relu la prochaine fois */ }
        }
    }
    return $out;
}

/** La base des redevances du mois, par magasin : [sid => [base, active, du]] (le calcul du Fonds) ; gardée 1 h. */
function vcRedevances(string $ym): array
{
    try { $c = setting('vcRed:' . $ym); } catch (Throwable $e) { $c = null; }
    if (is_array($c) && isset($c['le'], $c['v']) && time() - (int) $c['le'] < 3600) { return (array) $c['v']; }
    if (!function_exists('fondsRoyaltiesCalcul')) { return []; }
    try { $r = fondsRoyaltiesCalcul($ym); } catch (Throwable $e) { $r = null; }
    if (!is_array($r)) { return []; }
    $out = [];
    foreach ((array) ($r['shops'] ?? []) as $s) {
        $sid = (int) ($s['shop_id'] ?? 0);
        if ($sid <= 0) { continue; }
        $du = 0.0; foreach ((array) ($s['sortes'] ?? []) as $x) { $du += (float) ($x['du'] ?? 0); }
        $out[$sid] = ['base' => $s['ca'] === null ? null : round((float) $s['ca'], 2), 'active' => (bool) ($s['enabled'] ?? false), 'du' => round($du, 2)];
    }
    try { svGrave('vcRed:' . $ym, ['le' => time(), 'v' => $out]); } catch (Throwable $e) { /* sans cache */ }
    return $out;
}

/** Les lignes sans produit et les remises sur le ticket entier, jour par jour (le relevé des tickets, `svP`). */
function vcSansProduit(int $sid, array $jours): array
{
    $cle = 'vcSp:' . $sid . ':' . substr((string) ($jours[0] ?? ''), 0, 7) . ':' . count($jours);
    try { $c = setting($cle); } catch (Throwable $e) { $c = null; }
    if (is_array($c) && isset($c['le'], $c['v']) && time() - (int) $c['le'] < 3600) { return (array) $c['v']; }
    $out = [];
    foreach ($jours as $j) {
        try { $c = setting('svP' . $sid . ':' . $j); } catch (Throwable $e) { $c = null; }
        if (!is_array($c) || !isset($c['sp'])) { continue; }
        $x = ['spEur' => 0.0, 'spN' => 0, 'remEur' => 0.0, 'remN' => 0, 'ecartEur' => 0.0, 'noms' => []];
        foreach ((array) $c['sp'] as $l) {
            $v = (float) ($l['v'] ?? 0);
            if (($l['type'] ?? '') === 'ligne') { $x['spEur'] += $v; $x['spN']++; $x['noms'][(string) ($l['nom'] ?? '')] = true; }
            elseif ($v < 0) { $x['remEur'] += -$v; $x['remN']++; }
            else { $x['ecartEur'] += $v; }
        }
        $x['noms'] = array_slice(array_keys($x['noms']), 0, 3);
        $out[$j] = $x;
    }
    if ($jours !== []) { try { svGrave($cle, ['le' => time(), 'v' => $out]); } catch (Throwable $e) { /* sans cache */ } }
    return $out;
}

/**
 * Le CA attendu par la matière (lot 4) : sur les semaines d'inventaire closes dans le mois, entrées validées,
 * R = (consommé − poubelle déclarée) ÷ ventes, au coût. Fonction pure sur les lignes de ceo_verif_inventaire.
 */
function vcRapportMatiere(array $rows): ?array
{
    $s = 0.0; $w = 0.0; $v = 0.0; $n = 0; $jours = 0.0; $exclues = 0;
    foreach ($rows as $r) {
        if ((float) ($r['entrees_eur'] ?? 0) <= 0) { $exclues++; continue; }   // sans entrée validée : faux par défaut
        $s += (float) $r['sorties_eur']; $w += (float) $r['poubelle_eur']; $v += (float) $r['ventes_eur']; $n++; $jours += (float) ($r['jours'] ?? 7);
    }
    if ($n === 0 || $v <= 0) { return $exclues > 0 ? ['r' => null, 'semaines' => 0, 'exclues' => $exclues] : null; }
    return ['r' => round(($s - $w) / $v, 4), 'semaines' => $n, 'jours' => round($jours, 1), 'exclues' => $exclues];
}

function vcAttenduMois(int $sid, string $ym): ?array
{
    try {
        if (function_exists('vmTable')) { vmTable(); }
        $rows = Db::rows('SELECT jours, entrees_eur, sorties_eur, poubelle_eur, ventes_eur FROM ceo_verif_inventaire WHERE shop_id = ? AND a_at >= ? AND a_at < ?',
            [$sid, $ym . '-01', date('Y-m-d', strtotime($ym . '-01 +1 month'))]);
    } catch (Throwable $e) { return null; }
    return vcRapportMatiere($rows);
}

/** La part du CA à recettes fiables (lot 1), moyenne des photos du mois. */
function vcRecettesFiables(int $sid, string $ym): ?float
{
    try {
        $r = Db::row('SELECT SUM(recettes_ca_fiable) f, SUM(recettes_ca_total) t FROM ceo_verif_jour WHERE shop_id = ? AND jour >= ? AND jour < ? AND recettes_ca_total > 0',
            [$sid, $ym . '-01', date('Y-m-d', strtotime($ym . '-01 +1 month'))]);
    } catch (Throwable $e) { return null; }
    return $r !== null && (float) ($r['t'] ?? 0) > 0 ? round(100 * (float) $r['f'] / (float) $r['t'], 1) : null;
}

/** Une liste de corrections ou d'anomalies du panel, lue sans contrat : [n, montant|null] ; null si la route ne répond pas. */
function vcListeMontants(mixed $r): ?array
{
    if (!is_array($r)) { return null; }
    $l = array_is_list($r) ? $r : (array) ($r['data'] ?? $r['items'] ?? $r['corrections'] ?? $r['anomalies'] ?? $r['results'] ?? []);
    $n = 0; $m = 0.0; $vu = false;
    foreach ($l as $x) {
        if (!is_array($x)) { continue; }
        $n++;
        foreach (['gross_delta', 'amount', 'total_amount', 'difference', 'amount_difference', 'value', 'total_gross_amount', 'gross_amount', 'total'] as $k) {
            if (isset($x[$k]) && is_numeric($x[$k])) { $m += abs((float) $x[$k]); $vu = true; break; }
        }
    }
    return [$n, $vu ? round($m, 2) : null];
}

/**
 * Les corrections de ventes après encaissement d'un mois — fonction pure. Mesuré le 31/10 : `/shops/{id}/sales-corrections`
 * rend `items[]` {correction_number, status, original_insert_timestamp, gross_delta, created_at, cancelled_at,
 * employee_name} et ignore le filtre de dates : le mois se choisit ici, sur `created_at`. Rend [n, € (|gross_delta|), détails].
 */
function vcCorrectionsMois(mixed $r, string $ym): ?array
{
    if (!is_array($r)) { return null; }
    $l = array_is_list($r) ? $r : (array) ($r['items'] ?? $r['data'] ?? []);
    $n = 0; $e = 0.0; $det = [];
    foreach ($l as $x) {
        if (!is_array($x) || !empty($x['cancelled_at']) || substr((string) ($x['created_at'] ?? ''), 0, 7) !== $ym) { continue; }
        $v = (float) ($x['gross_delta'] ?? 0);
        $n++; $e += abs($v);
        $det[] = ['date' => substr((string) $x['created_at'], 0, 10), 'heure' => substr((string) $x['created_at'], 11, 5), 'eur' => round($v, 2),
            'numero' => (string) ($x['correction_number'] ?? ''), 'qui' => (string) ($x['employee_name'] ?? ''), 'ticket' => substr((string) ($x['original_insert_timestamp'] ?? ''), 0, 16)];
    }
    return [$n, round($e, 2), $det];
}

/** Corrections après encaissement, par magasin, sur le mois ; gardées 1 h. Les anomalies du panel (`/panel/sales-anomalies`)
 *  refusent le compte consultant (403 « Shop context required », mesuré le 31/10) : elles restent nulles. */
function vcCorrections(array $sids, string $ym): array
{
    try { $c = setting('vcCorr2:' . $ym); } catch (Throwable $e) { $c = null; }
    if (is_array($c) && isset($c['le'], $c['v']) && time() - (int) $c['le'] < 3600) { return (array) $c['v']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return []; }
    $ch = [];
    foreach ($sids as $sid) { $ch[$sid] = '/shops/' . $sid . '/sales-corrections?per_page=100&page=1'; }
    $rep = PanelApi::getParallele($ch, 4);
    $out = [];
    foreach ($sids as $sid) {
        $x = vcCorrectionsMois($rep[$sid] ?? null, $ym);
        $out[$sid] = ['corrections' => $x === null ? null : [$x[0], $x[1]], 'details' => $x[2] ?? [], 'anomalies' => null];
    }
    try { svGrave('vcCorr2:' . $ym, ['le' => time(), 'v' => $out]); } catch (Throwable $e) { /* sans cache */ }
    return $out;
}

/** Un écart à la caisse : montant, % et ton. */
function vcEcart(?float $x, float $caisse): ?array
{
    if ($x === null || $caisse <= 0) { return null; }
    $d = $x - $caisse; $p = 100 * $d / $caisse;
    return ['valeur' => round($x, 2), 'ecart' => round($d, 2), 'pct' => round($p, 1), 'ton' => abs($p) <= VC_SEUILS[0] ? 'ok' : (abs($p) <= VC_SEUILS[1] ? 'att' : 'ko')];
}

/**
 * La lecture d'un magasin sur le mois — fonction pure (bin/tests_verification.php) : les quatre chiffres, les lignes
 * à part, le verdict et les jours à regarder.
 */
function vcLecture(array $caisse, array $pnl, ?array $red, ?array $mat, array $sp, ?array $corr, int $nJours): array
{
    $ttc = 0.0; $tva = 0.0; $n = 0;
    foreach ($caisse as [$b, $t, $k]) { $ttc += $b; $tva += $t; $n += $k; }
    $joursLus = count($caisse);
    // Le P&L sur les seuls jours où la caisse est lue : on compare ce qui se compare.
    $pnlLu = 0.0; $pnlTout = array_sum($pnl);
    foreach ($caisse as $j => $_) { $pnlLu += (float) ($pnl[$j] ?? 0); }
    $complet = $joursLus >= $nJours;
    $P = $pnl === [] ? null : vcEcart($pnlLu, $ttc);
    $R = ($red !== null && $red['base'] !== null && $complet) ? vcEcart((float) $red['base'], $ttc) : null;
    if ($R !== null) { $R += ['active' => $red['active'], 'du' => $red['du']]; }
    // L'attendu : la caisse rapportée à ce que la matière appelle (caisse ÷ attendu − 1) ; négatif, il manque des ventes.
    $A = null;
    if ($mat !== null && ($mat['r'] ?? null) !== null && $ttc > 0 && (float) $mat['r'] > 0) {
        $att = $ttc * (float) $mat['r']; $p = 100 * ($ttc - $att) / $att;
        $A = ['valeur' => round($att, 2), 'ecart' => round($att - $ttc, 2), 'pct' => round($p, 1), 'ton' => abs($p) <= VC_SEUILS[0] ? 'ok' : (abs($p) <= VC_SEUILS[1] ? 'att' : 'ko'), 'semaines' => $mat['semaines']];
    }
    $spE = 0.0; $spN = 0; $remE = 0.0; $remN = 0;
    foreach ($sp as $x) { $spE += $x['spEur']; $spN += $x['spN']; $remE += $x['remEur']; $remN += $x['remN']; }
    // Les jours à regarder : P&L et caisse qui divergent, lignes sans produit, remises sur le ticket entier.
    $jr = [];
    foreach ($caisse as $j => [$b]) {
        if (isset($pnl[$j]) && abs($pnl[$j] - $b) >= VC_JOUR_MIN_EUR) {
            $d = $pnl[$j] - $b;
            $jr[] = ['date' => $j, 'ton' => abs($d) >= 100 ? 'ko' : 'att', 'eur' => abs($d), 'type' => 'pnl',
                'texte' => $d < 0 ? 'Le P&L (base des redevances) compte ' . vcNb(-$d, 2) . ' € de moins que la caisse (' . vcNb($b, 2) . ' €) : une vente corrigée, annulée ou sortie du P&L après encaissement.'
                    : 'Le P&L compte ' . vcNb($d, 2) . ' € de plus que la caisse (' . vcNb($b, 2) . ' €) : une vente ajoutée hors caisse, ou un ticket du jour pas encore lu.'];
        }
        if (!isset($pnl[$j]) && $b > 0 && $pnl !== []) { $jr[] = ['date' => $j, 'ton' => 'att', 'eur' => $b, 'type' => 'pnl', 'texte' => 'Une caisse de ' . vcNb($b, 2) . ' € sans jour au P&L.']; }
    }
    foreach ($sp as $j => $x) {
        if ($x['spEur'] >= VC_SP_MIN_EUR || $x['spN'] >= 3) {
            $jr[] = ['date' => $j, 'ton' => $x['spEur'] >= 100 ? 'ko' : 'att', 'eur' => $x['spEur'], 'type' => 'divers',
                'texte' => $x['spN'] . ' ligne' . ($x['spN'] > 1 ? 's' : '') . ' sans produit (' . vcNb($x['spEur'], 2) . ' €' . ($x['noms'] ? ' : ' . implode(', ', $x['noms']) : '') . ') — rien ne sort du stock pour elles.'];
        }
        if ($x['remEur'] >= VC_SP_MIN_EUR) {
            $jr[] = ['date' => $j, 'ton' => 'att', 'eur' => $x['remEur'], 'type' => 'remise', 'texte' => $x['remN'] . ' remise' . ($x['remN'] > 1 ? 's' : '') . ' sur le ticket entier (' . vcNb($x['remEur'], 2) . ' €).'];
        }
    }
    foreach ((array) ($corr['details'] ?? []) as $k) {
        $jr[] = ['date' => $k['date'], 'ton' => 'att', 'eur' => abs($k['eur']), 'type' => 'correction',
            'texte' => 'Correction ' . $k['numero'] . ($k['qui'] !== '' ? ' par ' . $k['qui'] : '') . ' à ' . $k['heure'] . ' : ' . vcNb($k['eur'], 2) . ' € sur un ticket encaissé le ' . date('d/m à H:i', strtotime($k['ticket'])) . '.'];
    }
    usort($jr, static fn ($a, $b) => $b['eur'] <=> $a['eur']);
    // Le verdict : la caisse face au P&L et aux redevances d'abord (de l'argent qui sort de la base), puis la matière.
    $pire = null;
    foreach ([$P, $R] as $x) { if ($x !== null && ($pire === null || abs($x['pct']) > abs($pire['pct']))) { $pire = $x; } }
    $verdict = ['ton' => 'mu', 'nom' => 'Pas de caisse lue', 'pourquoi' => ''];
    if ($ttc > 0) {
        if ($pire !== null && $pire['ton'] !== 'ok') {
            $verdict = ['ton' => $pire['ton'], 'nom' => $pire['ecart'] < 0 ? 'Base sous la caisse' : 'Base au-dessus de la caisse',
                'pourquoi' => 'Le P&L et la base des redevances comptent ' . vcNb(abs($pire['ecart'])) . ' € ' . ($pire['ecart'] < 0 ? 'de moins' : 'de plus') . ' que les tickets (' . vcNb(abs($pire['pct']), 1) . ' %).'];
        } elseif ($A !== null && $A['ton'] === 'ko') {
            $verdict = ['ton' => 'ko', 'nom' => 'À regarder', 'pourquoi' => 'La matière consommée appelle ' . vcNb($A['valeur'] - $ttc) . ' € de ventes de plus que la caisse (' . vcNb(abs($A['pct']), 1) . ' %).'];
        } elseif ($pire !== null && $pire['ecart'] < 0 && abs($pire['ecart']) >= VC_JOUR_MIN_EUR) {
            $verdict = ['ton' => 'ok', 'nom' => 'Concorde', 'pourquoi' => 'Écarts sous ' . vcNb(VC_SEUILS[0]) . ' % ; ' . vcNb(abs($pire['ecart'])) . ' € de base en moins sur le mois.'];
        } else {
            $verdict = ['ton' => 'ok', 'nom' => 'Tout concorde', 'pourquoi' => 'Écarts sous ' . vcNb(VC_SEUILS[0]) . ' %.'];
        }
        if (!$complet) { $verdict['pourquoi'] .= ' Caisse lue sur ' . $joursLus . ' jour' . ($joursLus > 1 ? 's' : '') . ' sur ' . $nJours . '.'; }
    }
    return ['caisse' => ['ttc' => round($ttc, 2), 'ht' => round($ttc - $tva, 2), 'tva' => round($tva, 2), 'tickets' => $n, 'joursLus' => $joursLus, 'jours' => $nJours, 'complet' => $complet],
        'pnl' => $P, 'pnlMois' => round($pnlTout, 2), 'redevances' => $R, 'attendu' => $A, 'matiere' => $mat,
        'sansProduit' => ['eur' => round($spE, 2), 'n' => $spN], 'remises' => ['eur' => round($remE, 2), 'n' => $remN],
        'corrections' => $corr['corrections'] ?? null, 'anomalies' => $corr['anomalies'] ?? null,
        'verdict' => $verdict, 'joursARegarder' => array_slice($jr, 0, 15), 'nJoursARegarder' => count($jr)];
}

/* ---------------------------------------------------------------------------------------------
 * La base quotidienne du CA
 * ------------------------------------------------------------------------------------------- */

function vcTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_verif_ca ('
        . 'shop_id INT NOT NULL, jour DATE NOT NULL, caisse_ttc DECIMAL(12,2) NULL, caisse_tva DECIMAL(12,2) NULL, tickets INT NULL,'
        . 'pnl DECIMAL(12,2) NULL, sans_produit_eur DECIMAL(12,2) NULL, sans_produit_n SMALLINT NULL, remises_ticket_eur DECIMAL(12,2) NULL,'
        . 'calcule_le DATETIME NULL, PRIMARY KEY (shop_id, jour), KEY (jour)'
        . ') DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

function vcEnregistre(int $sid, array $caisse, array $pnl, array $sp): int
{
    $n = 0;
    try {
        vcTable();
        foreach ($caisse as $j => [$b, $t, $k]) {
            $x = $sp[$j] ?? null;
            Db::exec('INSERT INTO ceo_verif_ca (shop_id, jour, caisse_ttc, caisse_tva, tickets, pnl, sans_produit_eur, sans_produit_n, remises_ticket_eur, calcule_le)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE caisse_ttc = VALUES(caisse_ttc), caisse_tva = VALUES(caisse_tva), tickets = VALUES(tickets),'
                . ' pnl = VALUES(pnl), sans_produit_eur = VALUES(sans_produit_eur), sans_produit_n = VALUES(sans_produit_n), remises_ticket_eur = VALUES(remises_ticket_eur), calcule_le = VALUES(calcule_le)',
                [$sid, $j, round($b, 2), round($t, 2), $k, isset($pnl[$j]) ? round((float) $pnl[$j], 2) : null,
                    $x === null ? null : round($x['spEur'], 2), $x === null ? null : $x['spN'], $x === null ? null : round($x['remEur'], 2), date('Y-m-d H:i:s')]);
            $n++;
        }
    } catch (Throwable $e) { /* sans base : la prochaine lecture réécrit */ }
    return $n;
}

/* ---------------------------------------------------------------------------------------------
 * Les routes
 * ------------------------------------------------------------------------------------------- */

/** Le réseau mois par mois : caisse (lue), P&L, attendu par la matière, recettes fiables — depuis le début des ventes gravées. */
function vcHistorique(array $sids, string $jusqua): array
{
    $out = [];
    $debut = max(substr(defined('SV_DEBUT') ? SV_DEBUT : '2026-08-01', 0, 7), date('Y-m', strtotime($jusqua . '-01 -' . (VC_MOIS_HISTO - 1) . ' month')));
    for ($m = $debut; $m <= $jusqua; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $jours = vcJours($m);
        $pnl = vcPnl($sids, $m);
        $c = 0.0; $p = 0.0; $pB = 0.0; $att = 0.0; $attBase = 0.0; $lus = 0; $tot = 0; $rf = [];
        foreach ($sids as $sid) {
            $ca = vcCaisse($sid, $jours);
            $lus += count($ca); $tot += count($jours);
            $ttc = 0.0; foreach ($ca as $j => [$b]) { $ttc += $b; if (isset($pnl[$sid][$j])) { $p += (float) $pnl[$sid][$j]; $pB += $b; } }
            $c += $ttc;
            $mat = vcAttenduMois($sid, $m);
            if ($mat !== null && ($mat['r'] ?? null) !== null) { $att += $ttc * $mat['r']; $attBase += $ttc; }
            $f = vcRecettesFiables($sid, $m); if ($f !== null) { $rf[] = $f; }
        }
        $A = null;
        if ($attBase > 0 && $att > 0) { $pp = 100 * ($attBase - $att) / $att; $A = ['valeur' => round($att, 2), 'pct' => round($pp, 1), 'ton' => abs($pp) <= VC_SEUILS[0] ? 'ok' : (abs($pp) <= VC_SEUILS[1] ? 'att' : 'ko'), 'part' => round(100 * $attBase / max($c, 1))]; }
        $out[] = ['mois' => $m, 'caisse' => round($c, 2), 'pnl' => $pB > 0 ? vcEcart($p, $pB) : null, 'attendu' => $A,
            'recettes' => $rf === [] ? null : round(array_sum($rf) / count($rf), 1), 'complet' => $lus >= $tot, 'joursLus' => $lus, 'jours' => $tot];
    }
    return $out;
}

/** GET /verification/ca[?mois=AAAA-MM][&shop=] */
function ep_verification_ca(): array
{
    @set_time_limit(120);
    $hier = date('Y-m', strtotime('-1 day'));
    $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? (string) $_GET['mois'] : $hier;
    if ($ym > $hier) { $ym = $hier; }
    $mags = vfMagasins();
    $sids = array_keys($mags);
    $jours = vcJours($ym);
    // Les tickets du mois qui manquent : lus dans le budget (le cron lit le reste).
    $aLire = 0;
    if (function_exists('cnMois')) { try { $cn = cnMois($ym, VC_SEC_DEMANDE); $aLire = (int) ($cn['aLire'] ?? 0); } catch (Throwable $e) { $aLire = -1; } }
    $pnl = vcPnl($sids, $ym);
    $red = vcRedevances($ym);
    $corr = vcCorrections($sids, $ym);
    $out = []; $T = ['ttc' => 0.0, 'pnl' => 0.0, 'pnlBase' => 0.0, 'red' => 0.0, 'redBase' => 0.0, 'att' => 0.0, 'attBase' => 0.0, 'sp' => 0.0, 'spN' => 0, 'rem' => 0.0, 'corrN' => 0, 'corrE' => null, 'anoN' => 0];
    foreach ($mags as $sid => $nom) {
        $ca = vcCaisse($sid, $jours);
        $sp = vcSansProduit($sid, $jours);
        $L = vcLecture($ca, (array) ($pnl[$sid] ?? []), $red[$sid] ?? null, vcAttenduMois($sid, $ym), $sp, $corr[$sid] ?? null, count($jours));
        $L['recettesFiables'] = vcRecettesFiables($sid, $ym);
        if ($L['attendu'] !== null && $L['recettesFiables'] !== null && $L['recettesFiables'] < 90 && $L['verdict']['nom'] === 'À regarder') {
            $L['verdict'] = ['ton' => 'att', 'nom' => 'Attendu peu fiable', 'pourquoi' => vcNb(100 - $L['recettesFiables']) . ' % du CA sans recette fiable : le CA attendu se corrige avec les recettes.'];
        }
        $out[] = ['id' => (string) $sid, 'nom' => $nom] + $L;
        $T['ttc'] += $L['caisse']['ttc'];
        if ($L['pnl']) { $T['pnl'] += $L['pnl']['valeur']; $T['pnlBase'] += $L['caisse']['ttc']; }
        if ($L['redevances']) { $T['red'] += $L['redevances']['valeur']; $T['redBase'] += $L['caisse']['ttc']; }
        if ($L['attendu']) { $T['att'] += $L['attendu']['valeur']; $T['attBase'] += $L['caisse']['ttc']; }
        $T['sp'] += $L['sansProduit']['eur']; $T['spN'] += $L['sansProduit']['n']; $T['rem'] += $L['remises']['eur'];
        if ($L['corrections']) { $T['corrN'] += $L['corrections'][0]; if ($L['corrections'][1] !== null) { $T['corrE'] = ($T['corrE'] ?? 0) + $L['corrections'][1]; } }
        if ($L['anomalies']) { $T['anoN'] += $L['anomalies'][0]; }
    }
    $reseau = ['caisse' => round($T['ttc'], 2), 'pnl' => vcEcart($T['pnl'], $T['pnlBase']), 'redevances' => vcEcart($T['red'], $T['redBase']),
        'attendu' => $T['attBase'] > 0 ? vcEcart($T['att'], $T['attBase']) + ['part' => round(100 * $T['attBase'] / max($T['ttc'], 1))] : null,
        'sansProduit' => ['eur' => round($T['sp'], 2), 'n' => $T['spN']], 'remises' => round($T['rem'], 2),
        'corrections' => [$T['corrN'], $T['corrE'] === null ? null : round($T['corrE'], 2)], 'anomalies' => $T['anoN']];
    if ($reseau['attendu']) { $p = 100 * ($T['attBase'] - $T['att']) / $T['att']; $reseau['attendu']['pct'] = round($p, 1); $reseau['attendu']['ton'] = abs($p) <= VC_SEUILS[0] ? 'ok' : (abs($p) <= VC_SEUILS[1] ? 'att' : 'ko'); }
    // Les jours à regarder : le magasin demandé, ou le premier à regarder.
    $choix = (string) ($_GET['shop'] ?? '');
    if ($choix === '' || !isset($mags[(int) $choix])) {
        $rang = ['ko' => 0, 'att' => 1, 'ok' => 2, 'mu' => 3];
        $tri = $out; usort($tri, static fn ($a, $b) => ($rang[$a['verdict']['ton']] <=> $rang[$b['verdict']['ton']]) ?: ($b['nJoursARegarder'] <=> $a['nJoursARegarder']));
        $choix = $tri[0]['id'] ?? '';
    }
    return ['mois' => $ym, 'du' => $jours[0] ?? null, 'au' => $jours[count($jours) - 1] ?? null, 'enCours' => $ym === date('Y-m'), 'aLire' => $aLire,
        'magasins' => $out, 'reseau' => $reseau, 'shopJours' => $choix, 'historique' => vcHistorique($sids, $ym), 'seuils' => VC_SEUILS,
        'lu' => date('c'),
        'source' => 'caisse : les tickets du panel (/shops/{id}/transactions, TTC après remise) ; P&L : /consultant/shops/{id}/pnl/daily (revenue) ; redevances : /consultant/shops/sales-kpis × taux des fiches boutique (calcul du Fonds) ; attendu : les semaines d’inventaire (lot 4) ; sans produit et remises : le relevé des tickets · lecture seule'];
}

/** GET /verification/ca/sonde?shop=&mois= — lecture seule : la forme des routes de corrections, d'anomalies et de rapport. */
function ep_verification_ca_sonde(): array
{
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { $sid = (int) (array_key_first(vfMagasins()) ?? 0); }
    $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? (string) $_GET['mois'] : date('Y-m', strtotime('-1 day'));
    $du = $ym . '-01'; $au = min(date('Y-m-t', strtotime($du)), date('Y-m-d'));
    $q = http_build_query(['date_from' => $du, 'date_to' => $au, 'from' => $du, 'to' => $au]);
    $routes = [
        'corrections' => '/shops/' . $sid . '/sales-corrections?' . $q,
        'correctionsSansDate' => '/shops/' . $sid . '/sales-corrections',
        'anomalies' => '/panel/sales-anomalies?' . $q . '&shop_id=' . $sid,
        'anomaliesSansDate' => '/panel/sales-anomalies',
        'correctionsTardives' => '/shops/' . $sid . '/reports/sales/original-period-later-corrections?' . $q,
        'mensuel' => '/shops/' . $sid . '/reports/sales/monthly?' . $q,
    ];
    $court = static function ($r) use (&$court) {
        if (!is_array($r)) { return $r; }
        $o = [];
        foreach ($r as $k => $v) { $o[$k] = is_array($v) && array_is_list($v) ? ['_n' => count($v), '_premiers' => array_map($court, array_slice($v, 0, 2))] : (is_array($v) ? $court($v) : $v); }
        return $o;
    };
    $out = ['shop' => $sid, 'mois' => $ym];
    foreach ($routes as $k => $r) {
        PanelApi::$lastError = null;
        $x = PanelApi::get($r);
        $out[$k] = ['route' => $r, 'erreur' => $x === null ? (PanelApi::$lastError ?: 'sans réponse') : null, 'reponse' => $court($x), 'lu' => vcListeMontants($x)];
    }
    return $out;
}

/** Le cron : les tickets du mois d'hier (et du précédent les premiers jours), puis la base quotidienne du CA. */
function vcCron(): string
{
    if (!function_exists('cnMois')) { return 'module CA net absent'; }
    $msg = [];
    $mois = [date('Y-m', strtotime('-1 day'))];
    if ((int) date('j') <= 5) { $mois[] = date('Y-m', strtotime(date('Y-m-01') . ' -1 month')); }
    $sids = array_keys(vfMagasins());
    foreach ($mois as $ym) {
        try { $cn = cnMois($ym, 30); } catch (Throwable $e) { $msg[] = $ym . ' : échec'; continue; }
        $jours = vcJours($ym); $pnl = vcPnl($sids, $ym); $n = 0;
        foreach ($sids as $sid) {
            $ca = array_filter(vcCaisse($sid, $jours), static fn ($j) => $j < date('Y-m-d'), ARRAY_FILTER_USE_KEY);
            $n += vcEnregistre($sid, $ca, (array) ($pnl[$sid] ?? []), vcSansProduit($sid, $jours));
        }
        $msg[] = $ym . ' : ' . (int) ($cn['aLire'] ?? 0) . ' journée(s) de tickets à lire, ' . $n . ' ligne(s) dans ceo_verif_ca';
    }
    return implode(' ; ', $msg);
}
