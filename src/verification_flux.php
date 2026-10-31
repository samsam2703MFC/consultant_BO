<?php
declare(strict_types=1);

/**
 * Vérification — lot 2 : l'audit des flux et les fuites par magasin (demande du 31/10/2026).
 * Plan : docs/plan-verification.md § 3.1 et § 3.2 ; maquettes : docs/maquettes/verification/a, a-jour, b, g.
 *
 * Pour un magasin et un jour, produit par produit, la clôture de la Production (ep_production_flux_cloture,
 * appelée telle quelle : les mêmes chiffres que l'écran Clôture) donne :
 *   gardé d'hier (report0) + sorti (cuissons validées, sinon le plan) = disponible ;
 *   vendu (tickets, commandes, webshop), poubelle déclarée en caisse, invendu gardé pour demain (la clôture
 *   enregistrée, sinon sa proposition) ; non expliqué = disponible − vendu − poubelle − invendu.
 *
 * MESURÉ ou ESTIMÉ : une pièce est mesurée quand toutes ses cuissons du jour sont validées ; sinon le sorti est
 * le plan, une prévision, et l'écart n'est qu'un écart au plan. Relevé du 31/10/2026 : aucun des quatre magasins
 * ne valide ses cuissons ni n'enregistre sa clôture. L'écran le dit, et ne qualifie de fuite qu'une période dont
 * au moins la moitié du disponible est mesurée (VX_MESURE_MIN).
 *
 * Une journée close se calcule une fois et se grave (`vxJ:{magasin}:{jour}`), avec la signature de ses cuissons
 * et de ses clôtures : une validation faite après coup la fait recalculer ; les sept derniers jours se recalculent
 * une fois par jour (ventes et poubelle déclarées en retard). Le cron horaire (/rapports/cron) remplit les jours
 * manquants des cinq dernières semaines par lots ; l'écran calcule au plus VX_SEC_DEMANDE secondes et dit ce qui
 * reste.
 *
 * GET /verification/flux?vue=jour|semaine|mois&date=AAAA-MM-JJ[&shop=]  — l'audit (réseau, ou un magasin) ;
 * GET /verification/fuites?vue=semaine|mois|jour&date=                  — un tableau par magasin, même structure.
 * Lecture seule.
 */

const VX_SEC_DEMANDE = 20;        // secondes de calcul au plus par ouverture d'écran
const VX_BUDGET_CRON = 40;        // journées calculées au plus par battement du cron
const VX_SEC_CRON = 120;          // et secondes au plus
const VX_JOURS_CRON = 35;         // le cron remplit les cinq dernières semaines
const VX_MESURE_MIN = 50.0;       // % du disponible mesuré pour qualifier une fuite
const VX_SEUILS = ['n' => [3.0, 6.0], 'p' => [6.0, 9.0], 'i' => [8.0, 12.0]];   // décision du 31/10/2026 : 3 % / 6 %

/* ---------------------------------------------------------------------------------------------
 * Une journée d'un magasin
 * ------------------------------------------------------------------------------------------- */

/** La signature de ce qui, saisi après coup, change une journée : ses cuissons validées et ses clôtures. */
function vxSig(int $sid, string $date): string
{
    $veille = date('Y-m-d', strtotime($date . ' 12:00:00 -1 day'));
    $v = [];
    foreach (['ppFait:' . $sid . ':' . $date, 'pfCloture:' . $sid . ':' . $date, 'pfCloture:' . $sid . ':' . $veille] as $k) {
        try { $v[] = setting($k); } catch (Throwable $e) { $v[] = null; }
    }
    return md5((string) json_encode($v));
}

/** La journée recalculée par la clôture de la Production ; null quand les ventes n'ont pas pu être lues. */
function vxJourCalcule(int $sid, string $date): ?array
{
    if (!function_exists('ep_production_flux_cloture')) { return null; }
    $get = $_GET; $code = http_response_code();
    $_GET = ['shop' => (string) $sid, 'date' => $date];
    try { $r = ep_production_flux_cloture(); } catch (Throwable $e) { $r = null; }
    finally { $_GET = $get; if (is_int($code)) { http_response_code($code); } }
    if (!is_array($r) || !is_array($r['lignes'] ?? null) || empty($r['ventesLues'])) { return null; }
    $l = [];
    foreach ($r['lignes'] as $x) {
        if (!is_array($x) || (int) ($x['pid'] ?? 0) <= 0) { continue; }
        $l[] = ['p' => (int) $x['pid'], 'n' => (string) ($x['nom'] ?? ''), 'c' => (string) ($x['cat'] ?? ''), 'g' => (string) ($x['groupe'] ?? ''),
            'x' => isset($x['prix']) && is_numeric($x['prix']) ? round((float) $x['prix'], 2) : null, 'k' => !empty($x['garde']) ? 1 : 0, 'v' => (string) ($x['vie'] ?? ''),
            's0' => round((float) ($x['report0'] ?? 0), 1), 'so' => round((float) ($x['sorti'] ?? 0), 1), 'ok' => !empty($x['sortiValide']) ? 1 : 0,
            'vd' => round((float) ($x['vendu'] ?? 0), 1), 'jd' => round((float) ($x['jeteDeclare'] ?? 0), 1), 'r' => round((float) ($x['report'] ?? 0), 1),
            'co' => isset($x['compte']) && is_numeric($x['compte']) ? round((float) $x['compte'], 1) : null];
    }
    return ['l' => $l, 'enr' => !empty($r['enregistree']), 'cv' => (int) ($r['cuissonsValidees'] ?? 0), 'cu' => (int) ($r['cuissons'] ?? 0), 'pl' => !empty($r['poubelleLue'])];
}

/** Une gravure encore bonne : calculée après la fin du jour, même signature ; les sept derniers jours, recalculés chaque jour. */
function vxGraveValide(mixed $g, string $date, string $sig): bool
{
    if (!is_array($g) || !isset($g['quand'], $g['sig'], $g['j']) || $g['sig'] !== $sig) { return false; }
    $auj = date('Y-m-d');
    $calcule = date('Y-m-d', (int) $g['quand']);
    if ($date >= $auj) { return (int) $g['quand'] > time() - 600; }       // la journée en cours : dix minutes
    if ($calcule <= $date) { return false; }                               // calculée avant la fin du jour
    return $date <= date('Y-m-d', strtotime('-8 day')) || $calcule === $auj;
}

/** La journée d'un magasin, gravée ; `$calculer` faux : seulement ce qui est déjà gravé. */
function vxJour(int $sid, string $date, bool $calculer): ?array
{
    $cle = 'vxJ:' . $sid . ':' . $date;
    $sig = vxSig($sid, $date);
    try { $g = setting($cle); } catch (Throwable $e) { $g = null; }
    if (vxGraveValide($g, $date, $sig)) { return $g['j']; }
    if (!$calculer) { return is_array($g) && is_array($g['j'] ?? null) ? $g['j'] + ['perime' => true] : null; }
    $j = vxJourCalcule($sid, $date);
    if ($j === null) { return null; }
    try {
        $v = json_encode(['quand' => time(), 'sig' => $sig, 'j' => $j], JSON_UNESCAPED_UNICODE);
        if ($v !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $v]); }
    } catch (Throwable $e) { /* sans gravure : recalculée la prochaine fois */ }
    return $j;
}

/**
 * Les journées demandées, magasin par magasin : [sid => [jour => journée|null]]. Calcule ce qui manque tant que le
 * temps le permet ($secondes ; 0 : rien), les plus récentes d'abord ; `$calculees` reçoit le nombre calculé.
 */
function vxCollecte(array $sids, array $jours, float $secondes, int &$calculees = 0, int $plafond = PHP_INT_MAX): array
{
    $t0 = microtime(true); $calculees = 0;
    $out = [];
    foreach ($sids as $sid) { foreach ($jours as $j) { $out[$sid][$j] = vxJour((int) $sid, $j, false); } }
    if ($secondes <= 0) { return $out; }
    $ordre = $jours; rsort($ordre);
    foreach ($ordre as $j) {
        foreach ($sids as $sid) {
            $g = $out[$sid][$j];
            if ($g !== null && empty($g['perime'])) { continue; }
            if (microtime(true) - $t0 > $secondes || $calculees >= $plafond) { break 2; }
            $n = vxJour((int) $sid, $j, true);
            if ($n !== null) { $out[$sid][$j] = $n; $calculees++; }
        }
    }
    return $out;
}

/* ---------------------------------------------------------------------------------------------
 * Les sommes — fonctions pures (bin/tests_verification.php)
 * ------------------------------------------------------------------------------------------- */

/** Un compteur vide : pièces et euros (au prix de vente). */
function vxVide(): array
{
    $z = ['garde0' => 0.0, 'prod' => 0.0, 'dispo' => 0.0, 'vendu' => 0.0, 'poubelle' => 0.0, 'invendu' => 0.0, 'nonExpl' => 0.0];
    return ['pcs' => $z, 'eur' => $z, 'mesureEur' => 0.0, 'lignes' => 0, 'jours' => 0, 'cuissonsValidees' => 0, 'cuissons' => 0, 'clotures' => 0, 'comptes' => 0];
}

/** Ajoute une ligne de produit (forme gravée) à un compteur. */
function vxAjouteLigne(array &$a, array $x): void
{
    $px = isset($x['x']) && $x['x'] !== null ? (float) $x['x'] : 0.0;
    $q = ['garde0' => (float) $x['s0'], 'prod' => (float) $x['so'], 'vendu' => (float) $x['vd'], 'poubelle' => (float) $x['jd'], 'invendu' => (float) $x['r']];
    $q['dispo'] = $q['garde0'] + $q['prod'];
    $q['nonExpl'] = $q['dispo'] - $q['vendu'] - $q['poubelle'] - $q['invendu'];
    foreach ($q as $k => $v) { $a['pcs'][$k] += $v; $a['eur'][$k] += $v * $px; }
    if (!empty($x['ok'])) { $a['mesureEur'] += $q['dispo'] * $px; }
    if (($x['co'] ?? null) !== null) { $a['comptes']++; }
    $a['lignes']++;
}

/** Le compteur d'une journée (ses lignes, filtrées au besoin par catégorie). */
function vxAgregeJour(?array $j, ?string $cat = null): array
{
    $a = vxVide();
    if ($j === null) { return $a; }
    foreach ((array) ($j['l'] ?? []) as $x) { if ($cat === null || ($x['c'] ?? '') === $cat) { vxAjouteLigne($a, $x); } }
    $a['jours'] = 1;
    if ($cat === null) { $a['cuissonsValidees'] = (int) ($j['cv'] ?? 0); $a['cuissons'] = (int) ($j['cu'] ?? 0); $a['clotures'] = !empty($j['enr']) ? 1 : 0; }
    return $a;
}

/** La somme de compteurs (une période = la somme de ses journées : on lit le taux d'une journée moyenne). */
function vxSomme(array $as): array
{
    $s = vxVide();
    foreach ($as as $a) {
        foreach (['pcs', 'eur'] as $u) { foreach ($s[$u] as $k => $v) { $s[$u][$k] += $a[$u][$k]; } }
        foreach (['mesureEur', 'lignes', 'jours', 'cuissonsValidees', 'cuissons', 'clotures', 'comptes'] as $k) { $s[$k] += $a[$k]; }
    }
    return $s;
}

/** Les taux (en % du disponible, au prix de vente), la part mesurée, le verdict. */
function vxLecture(array $a): array
{
    $e = $a['eur']; $d = $e['dispo'];
    $pct = static fn (float $v) => $d > 0 ? round(100 * $v / $d, 1) : null;
    $t = ['vendu' => $pct($e['vendu']), 'poubelle' => $pct($e['poubelle']), 'invendu' => $pct($e['invendu']), 'nonExpl' => $pct($e['nonExpl'])];
    $mesure = $d > 0 ? round(100 * $a['mesureEur'] / $d, 1) : null;
    $ton = static fn (?float $v, array $s) => $v === null ? 'mu' : ($v <= $s[0] ? 'ok' : ($v <= $s[1] ? 'att' : 'ko'));
    // Un non-expliqué négatif : il s'est vendu plus que ce qui est sorti (des cuissons ou des entrées non saisies) — jugé sur sa valeur absolue.
    $tons = ['nonExpl' => $ton($t['nonExpl'] === null ? null : abs($t['nonExpl']), VX_SEUILS['n']), 'poubelle' => $ton($t['poubelle'], VX_SEUILS['p']), 'invendu' => $ton($t['invendu'], VX_SEUILS['i'])];
    if ($d <= 0) { $v = ['mu', 'Pas de données', 'aucune journée calculée sur la période']; }
    elseif ($mesure < VX_MESURE_MIN) {
        $v = ['mu', 'Non mesuré', 'cuissons non validées (' . $a['cuissonsValidees'] . ' sur ' . $a['cuissons'] . ') : le produit est le plan, l’écart de ' . str_replace('.', ',', (string) $t['nonExpl']) . ' % est un écart au plan, pas une fuite mesurée'];
        $tons['nonExpl'] = 'mu'; $tons['invendu'] = 'mu';   // sans clôture mesurée, l'invendu n'est qu'une proposition
    }
    elseif ($t['nonExpl'] < -VX_SEUILS['n'][0]) { $v = ['att', 'Vendu plus que produit', str_replace('.', ',', (string) abs($t['nonExpl'])) . ' % vendus au-delà de ce qui est sorti : des cuissons ou des entrées non saisies']; }
    elseif ($t['nonExpl'] > VX_SEUILS['n'][1]) { $v = ['ko', 'À regarder', str_replace('.', ',', (string) $t['nonExpl']) . ' % du disponible sans trace (' . number_format($e['nonExpl'], 0, ',', ' ') . ' €)']; }
    elseif ($t['poubelle'] > VX_SEUILS['p'][1]) { $v = ['att', 'Poubelle haute', str_replace('.', ',', (string) $t['poubelle']) . ' % jeté et déclaré']; }
    elseif ($t['nonExpl'] > VX_SEUILS['n'][0]) { $v = ['att', 'À suivre', str_replace('.', ',', (string) $t['nonExpl']) . ' % non expliqué']; }
    else { $v = ['ok', 'Tout s’explique', 'à ' . str_replace('.', ',', (string) $t['nonExpl']) . ' % près']; }
    $r2 = static fn (array $x) => array_map(static fn ($y) => round((float) $y, 1), $x);
    return ['pcs' => $r2($a['pcs']), 'eur' => $r2($a['eur']), 'taux' => $t, 'tons' => $tons, 'mesure' => $mesure, 'verdict' => ['ton' => $v[0], 'nom' => $v[1], 'pourquoi' => $v[2]],
        'jours' => $a['jours'], 'cuissonsValidees' => $a['cuissonsValidees'], 'cuissons' => $a['cuissons'], 'clotures' => $a['clotures'], 'comptes' => $a['comptes']];
}

/** Les bornes d'une vue : [du, au, jours] ; le passé et aujourd'hui seulement. */
function vxBornes(string $vue, string $date): array
{
    $auj = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $vue === 'jour' ? $auj : date('Y-m-d', strtotime('-1 day')); }
    $t = strtotime($date . ' 12:00:00');
    if ($vue === 'jour') { $du = $au = $date; }
    elseif ($vue === 'mois') { $du = date('Y-m-01', $t); $au = date('Y-m-t', $t); }
    else { $du = date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' day', $t)); $au = date('Y-m-d', strtotime($du . ' 12:00:00 +6 day')); }
    if ($vue !== 'jour') { $hier = date('Y-m-d', strtotime('-1 day')); if ($au > $hier) { $au = max($du, $hier); } }
    $jours = [];
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' 12:00:00 +1 day'))) { $jours[] = $j; }
    return [$du, $au, $jours, $date];
}

/* ---------------------------------------------------------------------------------------------
 * Routes
 * ------------------------------------------------------------------------------------------- */

/** GET /verification/flux?vue=jour|semaine|mois&date=&shop= — l'audit des flux. */
function ep_verification_flux(): array
{
    $vue = in_array($_GET['vue'] ?? '', ['jour', 'semaine', 'mois'], true) ? (string) $_GET['vue'] : 'semaine';
    [$du, $au, $jours, $date] = vxBornes($vue, (string) ($_GET['date'] ?? ''));
    $mags = vfMagasins();
    $shop = (int) ($_GET['shop'] ?? 0);
    $sids = $shop > 0 && isset($mags[$shop]) ? [$shop] : array_keys($mags);
    @set_time_limit(120);
    $n = 0;
    $C = vxCollecte($sids, $jours, VX_SEC_DEMANDE, $n);
    $magasins = []; $tousJ = []; $manquants = 0;
    foreach ($sids as $sid) {
        $parJour = []; $aj = [];
        foreach ($jours as $j) {
            $g = $C[$sid][$j] ?? null;
            if ($g === null) { $manquants++; }
            $a = vxAgregeJour($g); $aj[] = $a; $tousJ[] = $a;
            $parJour[] = ['date' => $j, 'calcule' => $g !== null] + ($g !== null ? vxLecture($a) : []);
        }
        $magasins[] = ['id' => (string) $sid, 'nom' => $mags[$sid] ?? ('Magasin ' . $sid), 'parJour' => $parJour] + vxLecture(vxSomme($aj));
    }
    $out = ['vue' => $vue, 'date' => $date, 'du' => $du, 'au' => $au, 'jours' => $jours, 'shop' => $shop > 0 ? (string) $shop : '',
        'magasins' => $magasins, 'reseau' => vxLecture(vxSomme($tousJ)), 'manquants' => $manquants, 'calculesCePassage' => $n,
        'seuils' => ['nonExpl' => VX_SEUILS['n'], 'poubelle' => VX_SEUILS['p'], 'invendu' => VX_SEUILS['i'], 'mesureMin' => VX_MESURE_MIN],
        'lu' => date('c'),
        'source' => 'la clôture de la Production, jour par jour : sorti = cuissons validées (sinon le plan), vendu = tickets du panel avec commandes et webshop, poubelle = déclarée en caisse (/shops/{id}/products/waste), invendu = clôture enregistrée (sinon sa proposition) · au prix de vente · lecture seule'];
    // Un magasin : par catégorie sur la période ; et, sur un jour, produit par produit.
    if ($shop > 0 && count($sids) === 1) {
        $cats = [];
        foreach ($jours as $j) { $g = $C[$shop][$j] ?? null; foreach ((array) ($g['l'] ?? []) as $x) { $cats[$x['c']] ??= ['cat' => $x['c'], 'groupe' => $x['g'], 'a' => vxVide()]; vxAjouteLigne($cats[$x['c']]['a'], $x); } }
        $out['categories'] = array_values(array_map(static fn ($c) => ['cat' => $c['cat'], 'groupe' => $c['groupe']] + vxLecture($c['a']), $cats));
        usort($out['categories'], static fn ($a, $b) => ($b['taux']['nonExpl'] ?? -999) <=> ($a['taux']['nonExpl'] ?? -999));
        if ($vue === 'jour') {
            $g = $C[$shop][$jours[0]] ?? null;
            $out['produits'] = [];
            foreach ((array) ($g['l'] ?? []) as $x) {
                $a = vxVide(); vxAjouteLigne($a, $x);
                $out['produits'][] = ['pid' => $x['p'], 'nom' => $x['n'], 'cat' => $x['c'], 'groupe' => $x['g'], 'prix' => $x['x'], 'vie' => $x['v'], 'garde' => (bool) $x['k'],
                    'mesure' => (bool) $x['ok'], 'compte' => $x['co']] + vxLecture($a);
            }
            usort($out['produits'], static fn ($a, $b) => [($b['taux']['nonExpl'] ?? -999), $b['pcs']['dispo']] <=> [($a['taux']['nonExpl'] ?? -999), $a['pcs']['dispo']]);
            $out['journee'] = $g !== null ? ['clotureEnregistree' => !empty($g['enr']), 'cuissonsValidees' => (int) $g['cv'], 'cuissons' => (int) $g['cu'], 'poubelleLue' => !empty($g['pl'])] : null;
        }
    }
    return $out;
}

/**
 * GET /verification/fuites?vue=semaine|mois|jour&date= — un tableau par magasin, tous de la même structure :
 * catégories (dans le même ordre partout) dépliables en produits ; gardé d'hier + produit − gardé pour demain = sorti ;
 * sorti − ventes − poubelle = delta (somme des non-expliqués des jours). Et la matrice catégories × magasins.
 */
function ep_verification_fuites(): array
{
    $vue = in_array($_GET['vue'] ?? '', ['jour', 'semaine', 'mois'], true) ? (string) $_GET['vue'] : 'semaine';
    [$du, $au, $jours, $date] = vxBornes($vue, (string) ($_GET['date'] ?? ''));
    $mags = vfMagasins();
    $sids = array_keys($mags);
    @set_time_limit(120);
    $n = 0;
    $C = vxCollecte($sids, $jours, VX_SEC_DEMANDE, $n);
    // Les catégories, dans le même ordre pour tous les magasins : groupe, puis nom.
    $ordre = []; $P = [];
    foreach ($sids as $sid) {
        foreach ($jours as $j) {
            foreach ((array) (($C[$sid][$j] ?? [])['l'] ?? []) as $x) {
                $ordre[$x['c']] ??= $x['g'];
                $P[$sid][$x['c']][$x['p']] ??= ['pid' => $x['p'], 'nom' => $x['n'], 'prix' => $x['x'], 'a' => vxVide()];
                vxAjouteLigne($P[$sid][$x['c']][$x['p']]['a'], $x);
            }
        }
    }
    uksort($ordre, static fn ($a, $b) => [$ordre[$a], $a] <=> [$ordre[$b], $b]);
    $manquants = 0; $magasins = []; $mat = [];
    foreach ($sids as $sid) {
        foreach ($jours as $j) { if (($C[$sid][$j] ?? null) === null) { $manquants++; } }
        $cats = []; $toutes = [];
        foreach ($ordre as $cat => $groupe) {
            $ps = array_values($P[$sid][$cat] ?? []);
            $ac = vxSomme(array_column($ps, 'a'));
            $toutes[] = $ac;
            $prods = array_map(static fn ($p) => ['pid' => $p['pid'], 'nom' => $p['nom'], 'prix' => $p['prix']] + vxLecture($p['a']), $ps);
            usort($prods, static fn ($a, $b) => $b['eur']['nonExpl'] <=> $a['eur']['nonExpl']);
            $cats[] = ['cat' => $cat, 'groupe' => $groupe, 'produits' => $prods] + vxLecture($ac);
            $mat[$cat][(string) $sid] = $ac;
        }
        $joursA = array_map(static fn ($j) => vxAgregeJour($C[$sid][$j] ?? null), $jours);
        $tot = vxSomme($joursA);
        $magasins[] = ['id' => (string) $sid, 'nom' => $mags[$sid] ?? ('Magasin ' . $sid), 'categories' => $cats] + vxLecture($tot);
    }
    $matrice = [];
    foreach ($ordre as $cat => $groupe) {
        $matrice[] = ['cat' => $cat, 'groupe' => $groupe, 'magasins' => array_map(static fn ($a) => vxLecture($a), $mat[$cat] ?? []),
            'reseau' => vxLecture(vxSomme(array_values($mat[$cat] ?? [])))];
    }
    $res = vxSomme(array_map(static fn ($sid) => vxSomme(array_map(static fn ($j) => vxAgregeJour($C[$sid][$j] ?? null), $jours)), $sids));
    return ['vue' => $vue, 'date' => $date, 'du' => $du, 'au' => $au, 'jours' => $jours, 'magasins' => $magasins, 'matrice' => $matrice,
        'reseau' => vxLecture($res), 'manquants' => $manquants, 'calculesCePassage' => $n,
        'seuils' => ['nonExpl' => VX_SEUILS['n'], 'mesureMin' => VX_MESURE_MIN], 'lu' => date('c'),
        'source' => 'la clôture de la Production, jour par jour, sommée sur la période : gardé d’hier et gardé pour demain sont les sommes des journées · au prix de vente · lecture seule'];
}

/** Le battement du cron horaire : les journées closes des cinq dernières semaines qui manquent, les plus récentes d'abord. */
function vxCron(): string
{
    $mags = function_exists('vfMagasins') ? vfMagasins() : [];
    if ($mags === []) { return 'aucun magasin'; }
    @set_time_limit(300);
    $jours = [];
    for ($i = VX_JOURS_CRON; $i >= 1; $i--) { $jours[] = date('Y-m-d', strtotime('-' . $i . ' day')); }
    $n = 0;
    $C = vxCollecte(array_keys($mags), $jours, VX_SEC_CRON, $n, VX_BUDGET_CRON);
    $reste = 0;
    foreach ($C as $js) { foreach ($js as $g) { if ($g === null || !empty($g['perime'])) { $reste++; } } }
    return $n . ' journée(s) calculée(s), ' . $reste . ' à calculer sur ' . (count($mags) * count($jours));
}
