<?php
declare(strict_types=1);

/**
 * LE PLAN DE TRÉSORERIE D'UN MAGASIN (dashboard magasin › Trésorerie, 14/11/2026).
 *
 * Le modèle : « Plan de trésorerie — L'Atelier By » (Sombreffe, tiré de son fichier Excel). Une seule question :
 * est-ce que le magasin peut payer tout le monde dans les semaines qui viennent ? Semaine par semaine, en € TTC, du
 * premier lundi du plan à la fin de l'année : les ventes prévues, les fournisseurs, les paiements récurrents, les
 * imprévus, le cash de fin de semaine — et, chaque dimanche soir, le solde RÉEL lu dans la banque.
 *
 * Ce que le module reprend tout seul, au lieu de le faire retaper (demande du 14/11/2026) :
 *   - l'ANNÉE PASSÉE : le CA de la même semaine un an plus tôt, décalée de 364 jours pour qu'un lundi tombe sur un
 *     lundi (comme partout dans le cockpit), et le CA de chaque mois civil N-1 ;
 *   - le BUDGET VALIDÉ : le CA budget de chaque mois (`ceo_shop_month_perf.revenue_budget`, à défaut le CA théorique
 *     de l'étude — la règle de /visites/budget), réparti sur ses jours au profil des mêmes jours de l'an passé (à
 *     défaut d'il y a deux ans, à défaut à parts égales) ; s'il a été validé (`ceo_shop_budget.validated_on`) ; les
 *     taux du budget (matière, personnel, frais généraux : seuilsCharges, Budget › Paramètres) et ses postes de charges
 *     (les paiements proposés d'un plan neuf) ;
 *   - CE QUI A ÉTÉ FAIT : le CA réel de chaque semaine close, qui remplace la prévision, et de chaque mois.
 * Les ventes viennent du panel (margin-heatmap, jour par jour : vsPanelJours, mois clos gravés), la copie de caisse
 * en repli pour un mois que le panel n'a pas. CA TTC encaissé, comme le budget auquel il se compare.
 *
 * Ce que le franchisé tape (solde du dimanche, ventes corrigées, fournisseurs, paiements, dettes hors plan) est
 * gardé en base, un plan par magasin, avec une version : deux écrans ouverts (le franchisé et son consultant)
 * ne s'écrasent pas sans le savoir — le second reçoit un 409 et le plan tel qu'il est.
 *
 *   GET  /tresorerie/references?shop=4&debut=2026-11-16   les repères (lourd : gardé par `_cache`)
 *   GET  /tresorerie/plan?shop=4                          le plan enregistré (léger, jamais gardé)
 *   GET  /tresorerie/budget?shop=4&debut=2026-11-16       l'onglet « Budget & étude » : budget, étude, charges, P&L réel
 *   POST /tresorerie/plan {shop, version, donnees}        l'enregistrer ; `donnees: null` l'efface
 *
 * Table : `pil_tresorerie_plan`, au préfixe du portail Pilotage (docs/PORTAILS.md) — une table neuve n'a pas à
 * passer par `ceo_` pour être renommée ensuite.
 */

const TR_TAILLE_MAX = 600000;   // octets de JSON : 15 fournisseurs × 80 semaines et 50 paiements y tiennent dix fois

function ensureTresorerie(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS pil_tresorerie_plan ('
        . 'shop_id VARCHAR(16) NOT NULL PRIMARY KEY,'
        . 'donnees MEDIUMTEXT NOT NULL,'          // le plan, en JSON (format v2, voir tresorerie.js)
        . 'version INT UNSIGNED NOT NULL DEFAULT 1,'
        . 'maj_le DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- les dates ------------------------------------------------------------------------------------------------ */

/** Une date AAAA-MM-JJ qui existe. */
function trDateValide(?string $d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
        && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

/** Une date décalée de N jours. */
function trJour(string $d, int $n): string
{
    return (new DateTimeImmutable($d . ' 12:00:00'))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

/** Le lundi de la semaine d'une date. */
function trLundi(string $d): string
{
    return trJour($d, 1 - (int) (new DateTimeImmutable($d . ' 12:00:00'))->format('N'));
}

/**
 * Le premier lundi proposé pour un plan neuf : celui de la semaine en cours, ou le suivant le samedi et le dimanche
 * — la routine du dimanche soir prépare la semaine qui vient (le modèle : saisi le 15/11, le plan part du 16/11).
 */
function trDebutDefaut(string $auj): string
{
    $n = (int) (new DateTimeImmutable($auj . ' 12:00:00'))->format('N');
    return $n >= 6 ? trJour($auj, 8 - $n) : trLundi($auj);
}

/**
 * Le dernier lundi du plan : celui de la dernière semaine qui commence dans l'année où tombe le début + 26 semaines.
 * Un plan ouvert en novembre 2026 va jusqu'au 27/12/2027 (dimanche 02/01/2028, 59 semaines, comme le modèle) ; ouvert
 * en janvier, il couvre l'année. Jamais moins de 26 semaines devant soi.
 */
function trFin(string $debut): string
{
    $y = (int) substr(trJour($debut, 182), 0, 4);
    $d = $y . '-12-31';
    return trJour($d, 1 - (int) (new DateTimeImmutable($d . ' 12:00:00'))->format('N'));
}

/** Les lundis du plan, du début à la fin comprise. */
function trLundis(string $debut, string $fin): array
{
    $out = [];
    for ($m = $debut; $m <= $fin; $m = trJour($m, 7)) { $out[] = $m; }
    return $out;
}

/** Les jours d'un mois AAAA-MM. */
function trJoursDuMois(string $ym): array
{
    $n = (int) (new DateTimeImmutable($ym . '-01 12:00:00'))->format('t');
    $out = [];
    for ($j = 1; $j <= $n; $j++) { $out[] = $ym . '-' . str_pad((string) $j, 2, '0', STR_PAD_LEFT); }
    return $out;
}

/* --- les calculs (purs : un relevé de jours en entrée) --------------------------------------------------------- */

/** Le CA d'une fenêtre [d1, d2] dans un relevé `date => ca` ; null si aucun jour n'y a vendu. */
function trSomme(array $jours, string $d1, string $d2): ?float
{
    $s = 0.0; $vu = false;
    for ($j = $d1; $j <= $d2; $j = trJour($j, 1)) {
        if (isset($jours[$j])) { $s += (float) $jours[$j]; $vu = true; }
    }
    return $vu ? round($s, 2) : null;
}

/**
 * Le budget de chaque jour : le budget du mois réparti au profil des mêmes jours de l'an passé (364 jours plus tôt),
 * à défaut d'il y a deux ans (728), à défaut à parts égales. Un profil ne sert que s'il est entièrement passé et qu'au
 * moins dix de ses jours ont vendu : un mois N-1 à moitié lu ou à moitié fermé déformerait la répartition. Les jours
 * fermés l'an passé reçoivent zéro — la semaine de Noël pèse ce qu'elle pesait.
 *
 * $budgetMois : `AAAA-MM => ['ca' => float, 'source' => 'budget'|'theorique']`. Rend `date => ['v', 'source', 'profil']`.
 */
function trBudgetJours(array $budgetMois, array $jours, string $auj): array
{
    $out = [];
    foreach ($budgetMois as $ym => $b) {
        $ca = (float) ($b['ca'] ?? 0);
        if ($ca <= 0) { continue; }
        $dates = trJoursDuMois((string) $ym);
        $poids = null; $profil = 'egal';
        foreach ([364 => 'n1', 728 => 'n2'] as $dec => $nom) {
            $w = []; $vendus = 0; $complet = true;
            foreach ($dates as $d) {
                $r = trJour($d, -$dec);
                if ($r >= $auj) { $complet = false; break; }
                $v = (float) ($jours[$r] ?? 0);
                $w[$d] = $v > 0 ? $v : 0.0;
                if ($v > 0) { $vendus++; }
            }
            if ($complet && $vendus >= 10 && array_sum($w) > 0) { $poids = $w; $profil = $nom; break; }
        }
        if ($poids === null) { $poids = array_fill_keys($dates, 1.0); }
        $t = array_sum($poids);
        foreach ($dates as $d) {
            $out[$d] = ['v' => $ca * $poids[$d] / $t, 'source' => (string) ($b['source'] ?? 'budget'), 'profil' => $profil];
        }
    }
    return $out;
}

/**
 * Les semaines du plan : pour chaque lundi, le CA de la même semaine N-1 (entière et passée, sinon null), le budget
 * de la semaine (ses sept jours budgétés, sinon null), le CA réalisé à ce jour et si la semaine est close.
 */
function trSemaines(array $lundis, array $jours, array $budgetJours, string $auj): array
{
    $out = [];
    foreach ($lundis as $m) {
        $dim = trJour($m, 6);
        $m1 = trJour($m, -364); $d1 = trJour($m1, 6);
        $b = 0.0; $nb = 0; $srcs = []; $profils = [];
        for ($j = $m; $j <= $dim; $j = trJour($j, 1)) {
            if (!isset($budgetJours[$j])) { continue; }
            $b += $budgetJours[$j]['v']; $nb++;
            $srcs[$budgetJours[$j]['source']] = true; $profils[$budgetJours[$j]['profil']] = true;
        }
        $complet = $dim < $auj;
        // Une semaine à cheval sur un mois sans budget (la dernière de l'année, quand l'exercice suivant n'est pas
        // encodé) : quatre jours budgétés au moins suffisent, la semaine est extrapolée à sept jours et le dit.
        $ok = $nb >= 4;
        $out[] = [
            'du' => $m,
            'n1' => $d1 < $auj ? trSomme($jours, $m1, $d1) : null,
            'budget' => $ok ? round($b * 7 / $nb, 2) : null,
            'budgetSource' => $ok ? (count($srcs) > 1 ? 'mixte' : (string) array_key_first($srcs)) : null,
            'budgetProfil' => $ok ? implode('+', array_keys($profils)) : null,
            'budgetJours' => $nb,
            'realise' => $m <= $auj ? trSomme($jours, $m, min($dim, $auj)) : null,
            'complet' => $complet,
        ];
    }
    return $out;
}

/**
 * Les mois civils de `de` à `a` (AAAA-MM) : le réalisé à ce jour, le mois N-1 entier, le budget du mois, et le réalisé des
 * jours qui précèdent le premier lundi du plan (`avantPlan`) — avec les ventes du plan, l'atterrissage du mois.
 */
function trMois(string $de, string $a, array $jours, array $budgetMois, string $auj, ?string $debut = null): array
{
    $out = [];
    for ($ym = $de; $ym <= $a; $ym = (new DateTimeImmutable($ym . '-01 12:00:00'))->modify('+1 month')->format('Y-m')) {
        $du = $ym . '-01';
        $au = (new DateTimeImmutable($du . ' 12:00:00'))->format('Y-m-t');
        $ym1 = sprintf('%04d-%s', (int) substr($ym, 0, 4) - 1, substr($ym, 5, 2));
        $au1 = (new DateTimeImmutable($ym1 . '-01 12:00:00'))->format('Y-m-t');
        $b = $budgetMois[$ym] ?? null;
        $out[] = [
            'ym' => $ym,
            'realise' => $du <= $auj ? trSomme($jours, $du, min($au, $auj)) : null,
            'complet' => $au < $auj,
            'avantPlan' => $debut !== null && $du < $debut && $du <= $auj ? trSomme($jours, $du, min($au, trJour($debut, -1), $auj)) : null,
            'n1' => $au1 < $auj ? trSomme($jours, $ym1 . '-01', $au1) : null,
            'budget' => $b !== null && (float) $b['ca'] > 0 ? round((float) $b['ca'], 2) : null,
            'budgetSource' => $b !== null && (float) $b['ca'] > 0 ? (string) $b['source'] : null,
        ];
    }
    return $out;
}

/**
 * La tendance : les 13 dernières semaines closes face aux mêmes semaines N-1 (seules celles qui ont les deux). Le
 * repère proposé pour la croissance à appliquer à l'an passé.
 */
function trTendance(array $jours, string $auj): ?array
{
    $lundi = trLundi($auj);
    $ca = 0.0; $n1 = 0.0; $n = 0;
    for ($k = 1; $k <= 13; $k++) {
        $m = trJour($lundi, -7 * $k);
        $a = trSomme($jours, $m, trJour($m, 6));
        $b = trSomme($jours, trJour($m, -364), trJour($m, -358));
        if ($a === null || $b === null || $b <= 0) { continue; }
        $ca += $a; $n1 += $b; $n++;
    }
    if ($n < 4) { return null; }
    return ['semaines' => $n, 'ca' => round($ca, 2), 'n1' => round($n1, 2), 'pct' => round(100 * ($ca / $n1 - 1), 1)];
}

/* --- les lectures ----------------------------------------------------------------------------------------------- */

/**
 * Le CA de chaque jour d'un magasin sur [d1, d2] (bornée à aujourd'hui) : le panel, la copie de caisse pour un mois
 * que le panel n'a pas. Rend [`date => ca`, sources].
 */
function trLireJours(int $sid, string $d1, string $d2, string $auj): array
{
    $d2 = min($d2, $auj);
    if ($d2 < $d1) { return [[], []]; }
    $panel = function_exists('vsPanelJours') ? vsPanelJours($sid, [[$d1, $d2]]) : [];
    $sans = [];
    for ($ym = substr($d1, 0, 7); $ym <= substr($d2, 0, 7); $ym = (new DateTimeImmutable($ym . '-01 12:00:00'))->modify('+1 month')->format('Y-m')) {
        $vu = false;
        foreach ($panel as $j => $v) { if (str_starts_with((string) $j, $ym)) { $vu = true; break; } }
        if (!$vu) { $sans[] = [max($d1, $ym . '-01'), min($d2, (new DateTimeImmutable($ym . '-01 12:00:00'))->format('Y-m-t'))]; }
    }
    $caisse = $sans !== [] && function_exists('vsCaisseJours') ? vsCaisseJours($sid, $sans) : [];
    $out = []; $src = [];
    foreach ($panel as $j => $v) { if ((float) $v['ca'] > 0) { $out[(string) $j] = (float) $v['ca']; $src['panel'] = true; } }
    foreach ($caisse as $j => $v) {
        if (!isset($out[$j]) && (float) $v['ca'] > 0 && $j >= $d1 && $j <= $d2) { $out[(string) $j] = (float) $v['ca']; $src['caisse'] = true; }
    }
    ksort($out);
    return [$out, array_keys($src)];
}

/** Le budget de chaque mois des années demandées : le budget encodé, à défaut le CA théorique de l'étude. */
function trBudgetMois(string $shop, array $annees): array
{
    $out = [];
    if ($annees === []) { return $out; }
    $in = implode(',', array_fill(0, count($annees), '?'));
    try {
        foreach (Db::rows("SELECT year, month, revenue_budget, ca_theorique FROM ceo_shop_month_perf
                            WHERE shop_id = ? AND year IN ($in)", array_merge([$shop], $annees)) as $r) {
            $ym = sprintf('%04d-%02d', (int) $r['year'], (int) $r['month']);
            $bud = $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : 0.0;
            $theo = $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : 0.0;
            if ($bud > 0) { $out[$ym] = ['ca' => $bud, 'source' => 'budget']; }
            elseif ($theo > 0) { $out[$ym] = ['ca' => $theo, 'source' => 'theorique']; }
        }
    } catch (PDOException $e) { /* table du budget absente : pas de budget */ }
    ksort($out);
    return $out;
}

/** Le budget de chaque exercice : encodé ou non, validé ou non (et quand). */
function trBudgetsExercices(string $shop, array $annees, array $budgetMois): array
{
    $val = [];
    if ($annees !== []) {
        $in = implode(',', array_fill(0, count($annees), '?'));
        try {
            foreach (Db::rows("SELECT fiscal_year, validated_on FROM ceo_shop_budget WHERE shop_id = ? AND fiscal_year IN ($in)",
                array_merge([$shop], $annees)) as $r) {
                $val[(int) $r['fiscal_year']] = $r['validated_on'] !== null ? substr((string) $r['validated_on'], 0, 10) : null;
            }
        } catch (PDOException $e) { /* table du budget absente */ }
    }
    $out = [];
    foreach ($annees as $y) {
        $mb = 0; $mt = 0; $tot = 0.0;
        foreach ($budgetMois as $ym => $b) {
            if ((int) substr((string) $ym, 0, 4) !== $y) { continue; }
            $tot += (float) $b['ca'];
            if ($b['source'] === 'budget') { $mb++; } else { $mt++; }
        }
        $out[] = ['annee' => $y, 'fiche' => array_key_exists($y, $val), 'valideLe' => $val[$y] ?? null,
            'moisBudget' => $mb, 'moisTheorique' => $mt, 'total' => round($tot, 2)];
    }
    return $out;
}

/**
 * Les postes de charges du budget (Budget › Paramètres, réglage `budgetCharges`, aligné sur le Compte de résultat du
 * CRM) : catégorie, poste, taux en % du CA — le « % validé », à défaut le « % réseau ». Le plan en tire ses paiements
 * proposés ; la catégorie que la marge brute retranche (la matière) est marquée : elle passe par les fournisseurs.
 */
function trChargesBudget(): array
{
    $out = [];
    foreach (trChargesDetail()['lignes'] as $l) {
        $pct = $l['pctValide'] > 0 ? $l['pctValide'] : $l['pctReseau'];
        if ($pct <= 0 || $pct >= 100) { continue; }
        $out[] = ['categorie' => $l['categorie'], 'poste' => $l['poste'], 'pct' => round($pct, 2), 'matiere' => $l['matiere']];
    }
    return $out;
}

/**
 * Le détail des postes du budget : chaque ligne avec son « % validé » (le taux du Cockpit) et son « % réseau » (celui
 * du CRM), la catégorie que la marge brute retranche (`matiere`) et les étapes (paliers) du compte de résultat.
 */
function trChargesDetail(): array
{
    try { $m = function_exists('setting') ? setting('budgetCharges') : null; } catch (Throwable $e) { return ['lignes' => [], 'paliers' => []]; }
    if (!is_array($m) || !is_array($m['categories'] ?? null)) { return ['lignes' => [], 'paliers' => []]; }
    $nomMat = null; $paliers = [];
    foreach ((array) ($m['paliers'] ?? []) as $p) {
        if (!is_array($p)) { continue; }
        $d = (string) ($p['droite'] ?? '');
        if ($nomMat === null && str_starts_with($d, 'cat:')) { $nomMat = substr($d, 4); }
        $paliers[] = ['nom' => (string) ($p['nom'] ?? ''), 'droite' => $d];
    }
    $out = [];
    foreach ($m['categories'] as $c) {
        if (!is_array($c)) { continue; }
        $cat = (string) ($c['nom'] ?? '');
        $mat = $nomMat !== null ? $cat === $nomMat : preg_match('/^mati[eè]re/iu', $cat) === 1;
        foreach ((array) ($c['lignes'] ?? []) as $l) {
            if (!is_array($l)) { continue; }
            $v = is_numeric($l['pct'] ?? null) ? (float) $l['pct'] : 0.0;
            $r = is_numeric($l['pctTheo'] ?? null) ? (float) $l['pctTheo'] : 0.0;
            if ($v <= 0 && $r <= 0) { continue; }
            $out[] = ['categorie' => $cat, 'poste' => (string) ($l['poste'] ?? ''), 'pctValide' => round($v, 2), 'pctReseau' => round($r, 2), 'matiere' => $mat];
        }
    }
    return ['lignes' => $out, 'paliers' => $paliers];
}

/**
 * Les exercices du plan, tels que le cockpit les tient (lecture seule) : la fiche du budget (validée ou non, quand),
 * l'étude de marché (date, source, potentiel, année d'exploitation, montée en régime, saisonnalité, annexe), et chaque
 * mois son CA au budget et son CA théorique. Pur : les lignes de `ceo_shop_budget` et de `ceo_shop_month_perf` en entrée.
 */
function trExercices(array $annees, array $fiches, array $mois, array $saisReseau = []): array
{
    $jsonOu = static function ($v): mixed { if ($v === null || $v === '') { return null; } $d = is_string($v) ? json_decode($v, true) : $v; return is_array($d) ? $d : null; };
    $fi = []; foreach ($fiches as $f) { $fi[(int) $f['fiscal_year']] = $f; }
    $mm = []; foreach ($mois as $r) { $mm[(int) $r['year']][(int) $r['month']] = $r; }
    $out = [];
    foreach ($annees as $y) {
        $f = $fi[$y] ?? null;
        $sais = $f !== null ? $jsonOu($f['saisonnalite'] ?? null) : null;
        $saisSrc = 'etude';
        if (!is_array($sais) || count($sais) !== 12) { $sais = count($saisReseau) === 12 ? array_values($saisReseau) : null; $saisSrc = $sais !== null ? 'reseau' : null; }
        $lm = []; $tb = 0.0; $tt = 0.0; $nb = 0; $nt = 0;
        for ($m = 1; $m <= 12; $m++) {
            $r = $mm[$y][$m] ?? null;
            $b = $r !== null && $r['revenue_budget'] !== null && (float) $r['revenue_budget'] > 0 ? round((float) $r['revenue_budget'], 2) : null;
            $t = $r !== null && $r['ca_theorique'] !== null && (float) $r['ca_theorique'] > 0 ? round((float) $r['ca_theorique'], 2) : null;
            if ($b !== null) { $tb += $b; $nb++; }
            if ($t !== null) { $tt += $t; $nt++; }
            $lm[] = ['mois' => $m, 'budget' => $b, 'theorique' => $t];
        }
        $out[] = [
            'annee' => $y, 'fiche' => $f !== null,
            'valideLe' => $f !== null && !empty($f['validated_on']) ? substr((string) $f['validated_on'], 0, 10) : null,
            'panierEngagement' => $f !== null && $f['basket_target'] !== null ? (float) $f['basket_target'] : null,
            'caTheoriqueAn' => $f !== null && $f['ca_theorique_an'] !== null ? (float) $f['ca_theorique_an'] : ($nt ? round($tt, 2) : null),
            'etude' => $f === null ? null : [
                'date' => $f['etude_date'] ?? null, 'source' => $f['etude_source'] ?? null,
                'potentielMenages' => isset($f['etude_potentiel_menages']) ? (int) $f['etude_potentiel_menages'] : null,
                'potentielMaturite' => isset($f['etude_potentiel_maturite']) ? (float) $f['etude_potentiel_maturite'] : null,
                'anneeExploitation' => isset($f['annee_exploitation']) ? (int) $f['annee_exploitation'] : null,
                'monteeEnRegime' => $jsonOu($f['montee_regime'] ?? null), 'annexe' => $jsonOu($f['etude_annexe'] ?? null),
            ],
            'saisonnalite' => $sais, 'saisonnaliteSource' => $saisSrc,
            'mois' => $lm, 'moisBudget' => $nb, 'moisTheorique' => $nt,
            'totalBudget' => $nb ? round($tb, 2) : null, 'totalTheorique' => $nt ? round($tt, 2) : null,
        ];
    }
    return $out;
}

/**
 * Le P&L réel du magasin, mois par mois (matière, personnel, frais généraux, marge nette) : le calcul de Résultat
 * (ep_perf, /stores/perf), relu pour ce seul magasin — de janvier de l'année d'avant le plan au mois en cours.
 */
function trPnlReel(string $shop, array $annees): array
{
    if (!function_exists('ep_perf') || $annees === []) { return []; }
    $ans = range(min($annees) - 1, min(max($annees), (int) date('Y')));
    $garde = $_GET;
    $_GET = ['granularite' => 'mois', 'annees' => implode(',', $ans)];
    try { $cells = ep_perf(); } catch (Throwable $e) { $cells = []; } finally { $_GET = $garde; }
    $out = [];
    foreach ((array) $cells as $c) {
        if ((string) ($c['storeId'] ?? '') !== $shop || ($c['ca'] ?? null) === null) { continue; }
        $out[] = ['ym' => sprintf('%04d-%02d', (int) $c['annee'], (int) $c['mois']), 'ca' => (float) $c['ca'],
            'matiere' => $c['foodCostPct'] ?? null, 'personnel' => $c['labourCostPct'] ?? null, 'fraisGeneraux' => $c['overheadPct'] ?? null,
            'marge' => isset($c['margePct']) && $c['margePct'] !== null ? round(100 * (float) $c['margePct'], 1) : null];
    }
    usort($out, static fn ($a, $b) => strcmp($a['ym'], $b['ym']));
    return $out;
}

/**
 * GET /tresorerie/budget?shop=4&debut=AAAA-MM-JJ — l'onglet « Budget & étude » (lecture seule) : les exercices du
 * plan (fiche du budget, étude de marché, CA au budget et théorique de chaque mois), les postes de charges et les
 * étapes du compte de résultat, le P&L réel du magasin, les liens pour modifier (cockpit, CRM). Ce magasin seulement.
 */
function ep_tresorerie_budget(): array
{
    $shop = trShop($_GET['shop'] ?? null);
    if ($shop === null) { http_response_code(400); return ['error' => 'magasin manquant']; }
    $debut = (string) ($_GET['debut'] ?? '');
    $debut = trDateValide($debut) ? trLundi($debut) : trDebutDefaut(date('Y-m-d'));
    $annees = range((int) substr($debut, 0, 4), (int) substr(trFin($debut), 0, 4));
    $in = implode(',', array_fill(0, count($annees), '?'));
    $fiches = []; $mois = [];
    try { $fiches = Db::rows("SELECT * FROM ceo_shop_budget WHERE shop_id = ? AND fiscal_year IN ($in)", array_merge([$shop], $annees)); } catch (PDOException $e) { /* pas de fiche */ }
    try { $mois = Db::rows("SELECT year, month, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE shop_id = ? AND year IN ($in)", array_merge([$shop], $annees)); } catch (PDOException $e) { /* pas de mois */ }
    $sr = function_exists('setting') ? setting('saisonnaliteReseau', []) : [];
    $sr = is_array($sr) && count($sr) === 12 ? array_map('floatval', array_values($sr)) : [];
    $ch = trChargesDetail();
    $etat = function_exists('bcEtat') ? bcEtat() : [];
    return ['shop' => $shop, 'annees' => $annees, 'exercices' => trExercices($annees, $fiches, $mois, $sr),
        'charges' => $ch['lignes'], 'paliers' => $ch['paliers'], 'reel' => trPnlReel($shop, $annees),
        'taux' => function_exists('seuilsCharges') ? seuilsCharges() : null,
        'liens' => ['crm' => is_string($etat['modifier'] ?? null) ? $etat['modifier'] : null]];
}

/** Le magasin de la requête : un identifiant du panel, rien d'autre. */
function trShop(mixed $v): ?string
{
    $s = trim((string) ($v ?? ''));
    return preg_match('/^\d{1,10}$/', $s) === 1 && (int) $s > 0 ? $s : null;
}

/**
 * GET /tresorerie/references?shop=4&debut=AAAA-MM-JJ — l'année passée, le budget et le réalisé, semaine par semaine
 * sur la fenêtre du plan et mois par mois de janvier de son année à décembre de sa dernière.
 */
function ep_tresorerie_references(): array
{
    $shop = trShop($_GET['shop'] ?? null);
    if ($shop === null) { http_response_code(400); return ['error' => 'magasin manquant']; }
    $auj = date('Y-m-d');
    $debut = (string) ($_GET['debut'] ?? '');
    $debut = trDateValide($debut) ? trLundi($debut) : trDebutDefaut($auj);
    $fin = trFin($debut);
    $lundis = trLundis($debut, $fin);
    $y0 = (int) substr($debut, 0, 4); $y1 = (int) substr($fin, 0, 4);
    $dernier = trJour($fin, 6);

    // Une lecture des jours pour tout : les mois N-1 (depuis janvier de l'année d'avant), les profils du budget (deux
    // ans plus tôt) et la tendance des 13 dernières semaines. Les mois clos du panel sont gravés : relus une fois.
    $de = min(($y0 - 1) . '-01-01', trJour(substr($debut, 0, 7) . '-01', -728), trJour(trLundi($auj), -13 * 7 - 364));
    [$jours, $sources] = trLireJours((int) $shop, $de, $dernier, $auj);

    $annees = range($y0, (int) substr($dernier, 0, 4));
    $budgetMois = trBudgetMois($shop, $annees);
    $budgetJours = trBudgetJours($budgetMois, $jours, $auj);
    $taux = function_exists('seuilsCharges') ? seuilsCharges() : ['food' => 32.0, 'labour' => 33.0, 'overhead' => 13.5];

    return [
        'shop' => $shop, 'aujourdhui' => $auj, 'debut' => $debut, 'fin' => $fin, 'dernierJour' => $dernier,
        'semaines' => trSemaines($lundis, $jours, $budgetJours, $auj),
        'mois' => trMois($y0 . '-01', $y1 . '-12', $jours, $budgetMois, $auj, $debut),
        'tendance' => trTendance($jours, $auj),
        'budgets' => trBudgetsExercices($shop, range($y0, $y1), $budgetMois),
        'taux' => ['matiere' => (float) $taux['food'], 'personnel' => (float) $taux['labour'], 'fraisGeneraux' => (float) $taux['overhead'],
            'matiereDuBudget' => function_exists('seuilMatiereBudget') && seuilMatiereBudget() !== null],
        'charges' => trChargesBudget(),
        'sources' => $sources,
        'premiereVente' => $jours !== [] ? (string) array_key_first($jours) : null,
    ];
}

/** Le plan enregistré d'un magasin, ou null. */
function trPlanLu(string $shop): ?array
{
    $r = Db::row('SELECT donnees, version, maj_le FROM pil_tresorerie_plan WHERE shop_id = ?', [$shop]);
    if ($r === null) { return null; }
    $d = json_decode((string) $r['donnees'], true);
    return ['plan' => is_array($d) ? $d : null, 'version' => (int) $r['version'], 'majLe' => (string) $r['maj_le']];
}

/** GET /tresorerie/plan?shop=4 — le plan tel qu'il est enregistré, sa version, et le premier lundi proposé. */
function ep_tresorerie_plan(): array
{
    ensureTresorerie();
    $shop = trShop($_GET['shop'] ?? null);
    if ($shop === null) { http_response_code(400); return ['error' => 'magasin manquant']; }
    $p = trPlanLu($shop);
    return ['shop' => $shop, 'plan' => $p['plan'] ?? null, 'version' => $p['version'] ?? 0, 'majLe' => $p['majLe'] ?? null,
        'debutPropose' => trDebutDefaut(date('Y-m-d'))];
}

/** Ce qu'un plan doit être pour s'enregistrer ; null s'il l'est, sinon le motif. */
function trPlanInvalide(mixed $d): ?string
{
    if (!is_array($d) || array_is_list($d)) { return 'plan illisible'; }
    if (($d['v'] ?? null) !== 2) { return 'version du format inconnue (2 attendue)'; }
    $deb = $d['debut'] ?? null;
    if (!trDateValide(is_string($deb) ? $deb : null) || trLundi((string) $deb) !== $deb) { return 'premier lundi du plan invalide'; }
    foreach (['ventes', 'ponct', 'reel'] as $k) {
        if (isset($d[$k]) && !is_array($d[$k])) { return "« $k » doit être une table par semaine"; }
    }
    // Les montants saisis pour une semaine (double-clic) : { ligne : { lundi : montant } }.
    if (isset($d['forces'])) {
        if (!is_array($d['forces'])) { return '« forces » doit être une table par ligne'; }
        foreach ($d['forces'] as $m) { if (!is_array($m)) { return '« forces » : une table par semaine pour chaque ligne'; } }
    }
    foreach (['fournisseurs' => 15, 'paiements' => 50, 'hors' => 50] as $k => $max) {
        if (isset($d[$k]) && (!is_array($d[$k]) || !array_is_list($d[$k]))) { return "« $k » doit être une liste"; }
        if (isset($d[$k]) && count($d[$k]) > $max) { return "« $k » : $max lignes au plus"; }
    }
    return null;
}

/**
 * Les tables par semaine (`lundi => montant`) restent des objets JSON même vides : relu par PHP, `{}` devient un
 * tableau vide que json_encode réécrirait `[]` — et une liste JavaScript perd les clés qu'on y range ensuite.
 */
function trPlanTables(array $d): array
{
    foreach (['ventes', 'ponct', 'reel'] as $k) { $d[$k] = (object) (is_array($d[$k] ?? null) ? $d[$k] : []); }
    $d['forces'] = (object) array_map(static fn ($m) => (object) $m, is_array($d['forces'] ?? null) ? $d['forces'] : []);
    foreach ((array) ($d['fournisseurs'] ?? []) as $i => $f) {
        if (is_array($f)) { $d['fournisseurs'][$i]['montants'] = (object) (is_array($f['montants'] ?? null) ? $f['montants'] : []); }
    }
    return $d;
}

/**
 * POST /tresorerie/plan — {shop, version, donnees}. La version est celle qu'on a lue : si le plan a changé depuis
 * (un autre écran), rien n'est écrit, 409 et le plan tel qu'il est. `donnees: null` efface le plan (repartir de zéro).
 */
function wr_tresorerie_plan(): array
{
    ensureTresorerie();
    $b = body();
    $shop = trShop($b['shop'] ?? null);
    if ($shop === null) { http_response_code(400); return ['error' => 'magasin manquant']; }
    $version = (int) ($b['version'] ?? -1);
    if ($version < 0) { http_response_code(400); return ['error' => 'version manquante']; }
    $d = $b['donnees'] ?? null;
    $conflit = static function () use ($shop): array {
        http_response_code(409);
        $p = trPlanLu($shop);
        return ['error' => 'le plan a été modifié sur un autre écran', 'plan' => $p['plan'] ?? null,
            'version' => $p['version'] ?? 0, 'majLe' => $p['majLe'] ?? null];
    };

    if ($d === null) {
        $n = Db::exec('DELETE FROM pil_tresorerie_plan WHERE shop_id = ? AND version = ?', [$shop, $version]);
        if ($n === 0 && trPlanLu($shop) !== null) { return $conflit(); }
        return ['ok' => true, 'version' => 0, 'majLe' => null];
    }
    $motif = trPlanInvalide($d);
    if ($motif !== null) { http_response_code(422); return ['error' => $motif]; }
    $json = json_encode(trPlanTables($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > TR_TAILLE_MAX) { http_response_code(422); return ['error' => 'plan trop lourd']; }

    if ($version === 0) {
        try {
            Db::exec('INSERT INTO pil_tresorerie_plan (shop_id, donnees, version, maj_le) VALUES (?, ?, 1, NOW())', [$shop, $json]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') { return $conflit(); }   // créé entre-temps sur un autre écran
            throw $e;
        }
        $v = 1;
    } else {
        $n = Db::exec('UPDATE pil_tresorerie_plan SET donnees = ?, version = version + 1, maj_le = NOW()
                        WHERE shop_id = ? AND version = ?', [$json, $shop, $version]);
        if ($n === 0) { return $conflit(); }
        $v = $version + 1;
    }
    $p = trPlanLu($shop);
    return ['ok' => true, 'version' => $p['version'] ?? $v, 'majLe' => $p['majLe'] ?? date('Y-m-d H:i:s')];
}
