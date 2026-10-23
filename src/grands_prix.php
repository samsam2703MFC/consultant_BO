<?php
declare(strict_types=1);

/**
 * Les grands prix de l'année (23/10/2026, Sam : « des grosses primes de sponsors et de la marque — type refaire ta
 * terrasse —, qui se déroulent sur toute l'année : la meilleure vendeuse, le plus gros B2B, le plus gros ticket… »).
 * Maquettes : la vitrine (B) pour les montrer, la création de D pour les poser ; à deux étages, comme les primes du
 * mois : la marque pour tout le réseau (page admin), un franchisé pour son magasin (page franchisé).
 *
 * Un grand prix : un défi, un lot (un intitulé, ce qu'on gagne, sa valeur, une photo), qui l'offre (la marque, le
 * magasin ou un sponsor), une saison (l'année par défaut, des mois entiers), un minimum pour être classé (des heures,
 * des tickets) qui monte avec la saison. Le classement se calcule avec les données des primes du mois :
 *
 *   vendeuse     CA par heure prestée sur la saison (venteMois : le CA à son nom, les heures du planning)
 *   progression  le CA par heure face aux mêmes mois de l'année d'avant
 *   croisees     la part de ses tickets à deux articles ou plus (la moisson des tickets, pvCroiseesMois)
 *   b2b          le CA HT de ses tickets pro (la base partagée, comme la prime B2B : b2bTickets)
 *   ticket       son plus gros ticket hors clients pro (la base partagée)
 *   magasin      le CA du magasin face à son objectif (réseau seulement : un magasin gagne, son équipe fête)
 *
 * Rangé dans mot_grand_prix (src/motivation.php) ; le classement d'une saison finie s'y grave (« resultat »).
 */

const GP_DEFIS = [
    'vendeuse'    => ['lib' => 'Meilleure vendeuse',      'emo' => '🏆', 'regle' => 'CA par heure prestée sur la saison',             'seuil' => 'heures',  'defaut' => 600, 'reseauSeul' => false],
    'b2b'         => ['lib' => 'Plus gros apport B2B',    'emo' => '🤝', 'regle' => 'CA HT encaissé auprès des clients pro',          'seuil' => null,      'defaut' => null, 'reseauSeul' => false],
    'ticket'      => ['lib' => 'Plus gros ticket',        'emo' => '🧾', 'regle' => 'le plus gros ticket de la saison, hors clients pro', 'seuil' => null,   'defaut' => null, 'reseauSeul' => false],
    'croisees'    => ['lib' => 'Reine des ventes croisées', 'emo' => '🛍️', 'regle' => 'part des tickets à deux articles ou plus',     'seuil' => 'tickets', 'defaut' => 300, 'reseauSeul' => false],
    'progression' => ['lib' => 'Meilleure progression',   'emo' => '📈', 'regle' => 'CA par heure face aux mêmes mois l’an dernier',  'seuil' => 'heures',  'defaut' => 300, 'reseauSeul' => false],
    'magasin'     => ['lib' => 'Magasin de l’année',      'emo' => '🏪', 'regle' => 'CA du magasin face à son objectif',              'seuil' => null,      'defaut' => null, 'reseauSeul' => true],
];
const GP_MAX = 12;                 // douze grands prix au plus par étage (la marque, chaque magasin)
const GP_CACHE_COURS = 3600;       // le classement d'une saison en cours : une heure
const GP_TOP = 10;                 // les dix premiers rendus

/** Une ligne de mot_grand_prix, lue pour l'écran. */
function gpLigne(array $r): array
{
    $d = GP_DEFIS[$r['defi']] ?? GP_DEFIS['vendeuse'];
    return ['id' => (int) $r['id'], 'portee' => $r['portee'] === 'magasin' ? 'magasin' : 'reseau', 'shop' => $r['shop'] !== null ? (string) $r['shop'] : null,
        'defi' => (string) $r['defi'], 'defiLib' => $d['lib'], 'emo' => $d['emo'], 'regle' => $d['regle'],
        'titre' => (string) $r['titre'], 'lot' => (string) ($r['lot'] ?? ''), 'valeur' => (int) $r['valeur'],
        'payeur' => (string) $r['payeur'], 'sponsor' => (string) ($r['sponsor'] ?? ''), 'sponsorSite' => (string) ($r['sponsor_site'] ?? ''),
        'photo' => (string) ($r['photo'] ?? ''), 'seuil' => $r['seuil'] !== null ? (int) $r['seuil'] : null, 'seuilSorte' => $d['seuil'],
        'du' => substr((string) $r['du'], 0, 10), 'au' => substr((string) $r['au'], 0, 10), 'actif' => (bool) (int) $r['actif'],
        'resultat' => $r['resultat'] !== null ? json_decode((string) $r['resultat'], true) : null];
}

/** Les grands prix : tous (page admin), ou ceux du réseau et d'un magasin (page franchisé). */
function gpListe(?string $shop = null): array
{
    motTables();
    $rows = $shop === null
        ? Db::rows('SELECT * FROM mot_grand_prix ORDER BY portee DESC, shop, du DESC, valeur DESC, id')
        : Db::rows("SELECT * FROM mot_grand_prix WHERE portee = 'reseau' OR (portee = 'magasin' AND shop = ?) ORDER BY portee DESC, du DESC, valeur DESC, id", [$shop]);
    return array_map('gpLigne', $rows);
}

/** Les mois entiers d'une saison, bornés à aujourd'hui : ['2026-01', …]. */
function gpMois(string $du, string $au, ?string $auj = null): array
{
    $fin = min(substr($au, 0, 7), substr($auj ?? date('Y-m-d'), 0, 7));
    $out = [];
    for ($m = substr($du, 0, 7); $m <= $fin; $m = date('Y-m', strtotime($m . '-01 +1 month'))) { $out[] = $m; }
    return $out;
}

/** Où en est la saison : à venir, en cours, finie ; la part écoulée (0 à 1), les jours restants. */
function gpSaison(string $du, string $au, ?string $auj = null): array
{
    $auj ??= date('Y-m-d');
    $t0 = strtotime($du); $t1 = strtotime($au . ' +1 day'); $t = strtotime($auj);
    $part = $t1 > $t0 ? max(0.0, min(1.0, ($t - $t0) / ($t1 - $t0))) : 1.0;
    $etat = $auj < $du ? 'a_venir' : ($auj > $au ? 'finie' : 'en_cours');
    return ['etat' => $etat, 'part' => round($part, 4), 'joursRestants' => $etat === 'finie' ? 0 : (int) max(0, round(($t1 - max($t, $t0)) / 86400))];
}

/**
 * Les chiffres d'un mois par vendeuse : CA, heures, magasin (venteMois), tickets et ventes croisées (la moisson).
 * Gardés une heure pour le mois en cours, un jour pour un mois clos.
 */
function gpDonneesMois(string $m): array
{
    $cle = 'grandsPrixMois' . $m;
    $enCours = $m >= date('Y-m');
    $c = motCache($cle);
    if (is_array($c) && isset($c['v']) && (int) ($c['quand'] ?? 0) > time() - ($enCours ? GP_CACHE_COURS : 86400)) { return $c; }
    $v = [];
    $r = venteMoisCache($m, primesMagasins());
    foreach ($r['lignes'] as $l) {
        $v[(string) $l['id']] = ['ca' => (float) $l['ca'], 'h' => (float) $l['heures'], 's' => (string) $l['shopId'], 'n' => (string) $l['nom']];
    }
    $cr = function_exists('pvCroiseesMois') ? pvCroiseesMois($m) : null;
    foreach ((array) ($cr['e'] ?? []) as $id => $x) {
        $id = (string) $id;
        if (!isset($v[$id])) { continue; }
        $v[$id]['t'] = (int) ($x['t'] ?? 0);
        if (isset($x['c'])) { $v[$id]['c'] = (int) $x['c']; }
    }
    $out = ['quand' => time(), 'motif' => $r['motif'], 'v' => $v];
    if ($r['motif'] === null) { motCacheEcrire($cle, $out); }
    return $out;
}

/** Le CA et l'objectif de chaque magasin sur des mois (le mois en cours : l'objectif au prorata des jours passés). */
function gpMagasins(array $mois): array
{
    $out = [];
    $reg = primeMagasinReglages();
    foreach ($mois as $m) {
        $cle = 'grandsPrixMag' . $m;
        $enCours = $m >= date('Y-m');
        $c = motCache($cle);
        if (!(is_array($c) && isset($c['s']) && (int) ($c['quand'] ?? 0) > time() - ($enCours ? GP_CACHE_COURS : 86400))) {
            $bud = primesBudgets($m); $s = [];
            foreach (array_keys(primesMagasins()) as $sid) {
                $pm = primesMagasinMois((int) $sid, $m, $reg, null, 0.0, $bud[(string) $sid] ?? null, 0);
                $obj = $pm['objectif'] ?? null;
                if ($obj !== null && $enCours) {
                    $nb = (int) date('t', strtotime($m . '-01'));
                    $obj = $obj * max(0, min($nb, (int) date('j') - 1)) / $nb;
                }
                $s[(string) $sid] = ['ca' => (float) ($pm['ca'] ?? 0), 'obj' => $obj !== null ? round((float) $obj, 2) : null];
            }
            $c = ['quand' => time(), 's' => $s];
            motCacheEcrire($cle, $c);
        }
        foreach ($c['s'] as $sid => $x) {
            $out[$sid] = $out[$sid] ?? ['ca' => 0.0, 'obj' => 0.0, 'sansObj' => 0];
            $out[$sid]['ca'] += $x['ca'];
            if ($x['obj'] === null) { $out[$sid]['sansObj']++; } else { $out[$sid]['obj'] += $x['obj']; }
        }
    }
    return $out;
}

/** Le CA HT pro et le plus gros ticket (hors pro), par vendeuse, sur la saison : la base partagée, gardés une heure. */
function gpTickets(string $du, string $au, array $shops): array
{
    $fin = min(date('Y-m-d', strtotime($au . ' +1 day')), date('Y-m-d', strtotime('+1 day')));
    $cle = 'grandsPrixTk' . $du . ':' . $fin . ':' . substr(md5(implode(',', $shops)), 0, 8);
    $c = motCache($cle);
    if (is_array($c) && isset($c['b2b']) && (int) ($c['quand'] ?? 0) > time() - GP_CACHE_COURS) { return $c; }
    $b2b = []; $max = []; $jusqua = null; $motif = null;
    foreach ($shops as $sid) {
        $t = function_exists('b2bTickets') ? b2bTickets((int) $sid, $du, $fin) : null;
        if ($t === null) { $motif = 'la base des tickets n’a pas répondu'; continue; }
        foreach ($t as $x) { if ($x['e'] > 0) { $b2b[(string) $x['e']] = round(($b2b[(string) $x['e']] ?? 0.0) + $x['ht'], 2); } }
    }
    try {
        foreach (Db::rows('SELECT /*+ MAX_EXECUTION_TIME(15000) */ t.id_employee e, MAX(t.total_gross_amount_after_discount) mx, MAX(t.insert_timestamp) dernier
                             FROM `transaction` t LEFT JOIN client k ON k.id = t.id_client
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ? AND t.id_employee IS NOT NULL
                              AND COALESCE(k.is_b2b, 0) = 0
                              AND t.id_shop IN (' . implode(',', array_map('intval', $shops ?: [0])) . ')
                            GROUP BY t.id_employee', [$du, $fin]) as $r) {
            $max[(string) $r['e']] = round((float) $r['mx'], 2);
            if ($r['dernier'] !== null && ($jusqua === null || (string) $r['dernier'] > $jusqua)) { $jusqua = (string) $r['dernier']; }
        }
    } catch (Throwable $e) { $motif = 'la base des tickets n’a pas répondu'; }
    $out = ['quand' => time(), 'b2b' => $b2b, 'max' => $max, 'jusqua' => $jusqua !== null ? substr($jusqua, 0, 10) : null, 'motif' => $motif];
    if ($motif === null) { motCacheEcrire($cle, $out); }
    return $out;
}

/** Les chiffres de la saison par vendeuse : CA, heures, tickets, ventes croisées, son magasin, son nom. */
function gpCumul(array $mois): array
{
    $out = []; $motif = null;
    foreach ($mois as $m) {
        $d = gpDonneesMois($m);
        if ($d['motif'] !== null) { $motif = $motif ?? ($m . ' : ' . $d['motif']); }
        foreach ($d['v'] as $id => $x) {
            $o = $out[$id] ?? ['ca' => 0.0, 'h' => 0.0, 't' => 0, 'c' => 0, 'tc' => 0, 's' => $x['s'], 'n' => $x['n']];
            $o['ca'] += $x['ca']; $o['h'] += $x['h'];
            if (isset($x['c'])) { $o['c'] += $x['c']; $o['tc'] += (int) ($x['t'] ?? 0); }
            $o['s'] = $x['s']; $o['n'] = $x['n'];
            $out[$id] = $o;
        }
    }
    return ['v' => $out, 'motif' => $motif];
}

/**
 * Le classement d'un grand prix : [{rang, id, nom, shop, magasin, valeur, txt, detail}] du premier au dernier classé,
 * et ceux qui ne le sont pas encore (le minimum, au prorata de la saison écoulée).
 */
function gpClassement(array $gp, ?string $auj = null): array
{
    $nomDe = primesMagasins();
    $sa = gpSaison($gp['du'], $gp['au'], $auj);
    $res = ['saison' => $sa, 'liste' => [], 'enCourse' => 0, 'horsSeuil' => 0, 'sous' => [], 'seuilCourant' => null, 'motif' => null, 'jusqua' => null];
    if ($sa['etat'] === 'a_venir') { return $res; }
    $mois = gpMois($gp['du'], $gp['au'], $auj);
    $dansScope = static fn (string $sid) => $gp['portee'] !== 'magasin' || $sid === (string) $gp['shop'];
    $fr = static fn (float $v, int $k = 0) => number_format($v, $k, ',', ' ');
    // Le minimum monte avec la saison : à mi-saison, la moitié des heures suffit pour être classée.
    $seuil = $gp['seuil'] !== null ? (int) round($gp['seuil'] * ($sa['etat'] === 'finie' ? 1.0 : $sa['part'])) : 0;
    $res['seuilCourant'] = $gp['seuil'] !== null ? $seuil : null;
    $L = [];
    if ($gp['defi'] === 'magasin') {
        foreach (gpMagasins($mois) as $sid => $x) {
            if (!isset($nomDe[$sid])) { continue; }
            if ($x['obj'] <= 0) { $res['horsSeuil']++; continue; }
            $pct = 100 * $x['ca'] / $x['obj'];
            $L[] = ['id' => $sid, 'nom' => $nomDe[$sid], 'shop' => $sid, 'magasin' => $nomDe[$sid], 'valeur' => round($pct, 1),
                'txt' => $fr($pct, 1) . ' %', 'detail' => $fr($x['ca']) . ' € pour ' . $fr($x['obj']) . ' € d’objectif'];
        }
    } elseif ($gp['defi'] === 'b2b' || $gp['defi'] === 'ticket') {
        $shops = $gp['portee'] === 'magasin' ? [(string) $gp['shop']] : array_map('strval', array_keys($nomDe));
        $tk = gpTickets($gp['du'], $gp['au'], $shops);
        $res['motif'] = $tk['motif']; $res['jusqua'] = $tk['jusqua'];
        $cu = gpCumul($mois)['v'];
        $emps = function_exists('venteEmployes') ? venteEmployes() : [];
        foreach ($gp['defi'] === 'b2b' ? $tk['b2b'] : $tk['max'] as $id => $v) {
            $sid = (string) ($cu[$id]['s'] ?? ($emps[(int) $id]['shop'] ?? ''));
            if ($v <= 0 || !$dansScope($sid)) { continue; }
            $L[] = ['id' => (string) $id, 'nom' => (string) ($cu[$id]['n'] ?? ($emps[(int) $id]['nom'] ?? ('Vendeuse ' . $id))), 'shop' => $sid, 'magasin' => $nomDe[$sid] ?? '',
                'valeur' => $v, 'txt' => $fr($v, $gp['defi'] === 'ticket' ? 2 : 0) . ' €' . ($gp['defi'] === 'b2b' ? ' HT' : ''), 'detail' => ''];
        }
    } else {
        $cu = gpCumul($mois);
        $res['motif'] = $cu['motif'];
        $avant = null;
        if ($gp['defi'] === 'progression') {
            $moisAvant = array_map(static fn ($m) => (string) ((int) substr($m, 0, 4) - 1) . substr($m, 4), $mois);
            $avant = gpCumul($moisAvant)['v'];
        }
        foreach ($cu['v'] as $id => $x) {
            if (!$dansScope($x['s'])) { continue; }
            if ($gp['defi'] === 'vendeuse') {
                if ($x['h'] <= 0 || $x['ca'] <= 0) { continue; }
                if ($x['h'] < $seuil) { $res['horsSeuil']++; $res['sous'][$id] = ['a' => round($x['h']), 'seuil' => $seuil]; continue; }
                $v = $x['ca'] / $x['h'];
                $L[] = ['valeur' => round($v, 2), 'txt' => $fr($v, 1) . ' € / h', 'detail' => $fr($x['ca']) . ' € en ' . $fr($x['h']) . ' h'] + ['x' => $x, 'id' => $id];
            } elseif ($gp['defi'] === 'croisees') {
                if ($x['tc'] <= 0) { continue; }
                if ($x['tc'] < $seuil) { $res['horsSeuil']++; $res['sous'][$id] = ['a' => $x['tc'], 'seuil' => $seuil]; continue; }
                $v = 100 * $x['c'] / $x['tc'];
                $L[] = ['valeur' => round($v, 2), 'txt' => $fr($v, 1) . ' %', 'detail' => $fr($x['c']) . ' tickets sur ' . $fr($x['tc'])] + ['x' => $x, 'id' => $id];
            } else {
                $a = $avant[$id] ?? null;
                if ($x['h'] <= 0 || $x['ca'] <= 0) { continue; }
                if ($a === null || $a['h'] <= 0 || $a['ca'] <= 0 || $x['h'] < $seuil || $a['h'] < $seuil) {
                    $res['horsSeuil']++;
                    // Sans heures l'an dernier, rien à comparer : pas de minimum à lui donner.
                    if ($a !== null && $a['h'] > 0) { $res['sous'][$id] = ['a' => round(min($x['h'], $a['h'])), 'seuil' => $seuil]; }
                    continue;
                }
                $v1 = $x['ca'] / $x['h']; $v0 = $a['ca'] / $a['h'];
                $p = 100 * ($v1 - $v0) / $v0;
                $L[] = ['valeur' => round($p, 2), 'txt' => ($p >= 0 ? '+' : '−') . $fr(abs($p), 1) . ' %', 'detail' => $fr($v0, 1) . ' → ' . $fr($v1, 1) . ' € / h'] + ['x' => $x, 'id' => $id];
            }
        }
        foreach ($L as $i => $l) {
            $L[$i] = ['id' => (string) $l['id'], 'nom' => $l['x']['n'], 'shop' => $l['x']['s'], 'magasin' => $nomDe[$l['x']['s']] ?? '', 'valeur' => $l['valeur'], 'txt' => $l['txt'], 'detail' => $l['detail']];
        }
    }
    usort($L, static fn ($a, $b) => [$b['valeur'], $a['nom']] <=> [$a['valeur'], $b['nom']]);
    foreach ($L as $i => $l) { $L[$i]['rang'] = $i + 1; }
    $res['liste'] = $L;
    $res['enCourse'] = count($L);
    return $res;
}

/** Le classement d'un grand prix pour l'écran, gravé une fois la saison finie (trois jours de marge pour les données). */
function gpResultat(array $gp, ?string $auj = null): array
{
    if (is_array($gp['resultat'])) { return $gp['resultat'] + ['grave' => true, 'sous' => []]; }
    // Le classement se garde quinze minutes : l'écran des primes et l'app des vendeuses le relisent souvent.
    $cle = 'grandsPrixCl' . $gp['id'] . ':' . substr(md5((string) json_encode([$gp['defi'], $gp['portee'], $gp['shop'], $gp['seuil'], $gp['du'], $gp['au']])), 0, 10);
    $c = $gp['id'] > 0 && $auj === null ? motCache($cle) : null;
    if (is_array($c) && isset($c['liste']) && (int) ($c['quand'] ?? 0) > time() - 900 && ($c['saison']['etat'] ?? '') === gpSaison($gp['du'], $gp['au'])['etat']) {
        $c['saison'] = gpSaison($gp['du'], $gp['au']);
        return $c;
    }
    $c = gpClassement($gp, $auj);
    if ($gp['id'] > 0 && $auj === null && $c['motif'] === null) { motCacheEcrire($cle, $c + ['quand' => time()]); }
    if ($gp['id'] > 0 && $c['saison']['etat'] === 'finie' && $c['motif'] === null && date('Y-m-d') > date('Y-m-d', strtotime($gp['au'] . ' +3 days'))) {
        $g = $c; $g['liste'] = array_slice($c['liste'], 0, GP_TOP); $g['grave_le'] = date('c');
        try { Db::exec('UPDATE mot_grand_prix SET resultat = ? WHERE id = ? AND resultat IS NULL', [json_encode($g, JSON_UNESCAPED_UNICODE), $gp['id']]); } catch (Throwable $e) { /* regravé à la prochaine lecture */ }
    }
    return $c;
}

/**
 * GET /ventes/grands-prix[?shop=3] — les grands prix, chacun avec son classement (les dix premiers) ; sur la page d'un
 * magasin, les grands prix de la marque disent aussi la mieux classée du magasin.
 */
function ep_grands_prix(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $shop = preg_match('/^\d{1,9}$/', $shop) ? $shop : null;
    $nomDe = primesMagasins();
    $out = [];
    foreach (gpListe($shop) as $gp) {
        $c = $gp['actif'] || is_array($gp['resultat']) ? gpResultat($gp) : ['saison' => gpSaison($gp['du'], $gp['au']), 'liste' => [], 'enCourse' => 0, 'horsSeuil' => 0, 'seuilCourant' => null, 'motif' => null, 'jusqua' => null];
        $ici = null;
        if ($shop !== null && $gp['portee'] === 'reseau') {
            foreach ($c['liste'] as $l) { if ((string) $l['shop'] === $shop) { $ici = $l; break; } }
        }
        unset($gp['resultat']);
        $out[] = $gp + ['shopNom' => $gp['shop'] !== null ? ($nomDe[$gp['shop']] ?? ('Magasin ' . $gp['shop'])) : null,
            'saison' => $c['saison'], 'classement' => array_slice($c['liste'], 0, GP_TOP), 'enCourse' => $c['enCourse'], 'horsSeuil' => $c['horsSeuil'],
            'seuilCourant' => $c['seuilCourant'], 'motif' => $c['motif'], 'jusqua' => $c['jusqua'], 'grave' => !empty($c['grave']), 'ici' => $ici];
    }
    $defis = [];
    foreach (GP_DEFIS as $k => $d) { $defis[] = ['cle' => $k] + $d; }
    return ['annee' => (int) date('Y'), 'aujourdhui' => date('Y-m-d'), 'shop' => $shop, 'shopNom' => $shop !== null ? ($nomDe[$shop] ?? null) : null,
        'defis' => $defis, 'max' => GP_MAX, 'liste' => $out,
        'magasins' => array_map(static fn ($id, $nom) => ['id' => (string) $id, 'nom' => $nom], array_keys($nomDe), array_values($nomDe))];
}

/** Un grand prix envoyé par l'écran, nettoyé ; ['error' => …] s'il ne tient pas. */
function gpPropre(array $b, array $magasins): array
{
    $portee = ($b['portee'] ?? '') === 'magasin' ? 'magasin' : 'reseau';
    $shop = $portee === 'magasin' ? trim((string) ($b['shop'] ?? '')) : null;
    if ($portee === 'magasin' && !isset($magasins[$shop])) { return ['error' => 'magasin inconnu']; }
    $defi = (string) ($b['defi'] ?? '');
    if (!isset(GP_DEFIS[$defi])) { return ['error' => 'défi inconnu']; }
    if ($portee === 'magasin' && GP_DEFIS[$defi]['reseauSeul']) { return ['error' => 'le magasin de l’année se joue entre les magasins du réseau'] ; }
    $txt = static fn ($k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($b[$k] ?? '')) ?? ''), 0, $max);
    $titre = $txt('titre', 120);
    if (mb_strlen($titre) < 3) { return ['error' => 'l’intitulé du prix manque (« Refaire ta terrasse »)']; }
    $valeur = is_numeric($b['valeur'] ?? null) ? (int) round((float) $b['valeur']) : -1;
    if ($valeur < 0 || $valeur > 1000000) { return ['error' => 'la valeur du prix, en euros, manque']; }
    $payeur = (string) ($b['payeur'] ?? '');
    if (!in_array($payeur, $portee === 'magasin' ? ['magasin', 'sponsor'] : ['marque', 'sponsor'], true)) { $payeur = $portee === 'magasin' ? 'magasin' : 'marque'; }
    $sponsor = $payeur === 'sponsor' ? $txt('sponsor', 120) : '';
    if ($payeur === 'sponsor' && $sponsor === '') { return ['error' => 'le nom du sponsor manque'] ; }
    $site = $payeur === 'sponsor' ? $txt('sponsorSite', 190) : '';
    if ($site !== '' && !preg_match('#^https?://#i', $site)) { $site = 'https://' . $site; }
    if ($site !== '' && !filter_var($site, FILTER_VALIDATE_URL)) { return ['error' => 'l’adresse du site du sponsor n’est pas lisible']; }
    $photo = trim((string) ($b['photo'] ?? ''));
    if ($photo !== '' && !preg_match('#^uploads/primes/[0-9a-f]{20}\.(png|webp|jpg|svg)$#', $photo)) { return ['error' => 'photo inconnue']; }
    $du = (string) ($b['du'] ?? ''); $au = (string) ($b['au'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || strtotime($du) === false || strtotime($au) === false) { return ['error' => 'la saison manque (du, au)']; }
    // Des mois entiers : du le 1er, au le dernier jour.
    $du = substr($du, 0, 7) . '-01'; $au = date('Y-m-t', strtotime(substr($au, 0, 7) . '-01'));
    if ($au < $du) { return ['error' => 'la saison finit avant de commencer']; }
    if ((int) date('Y', strtotime($au)) * 12 + (int) date('n', strtotime($au)) - ((int) date('Y', strtotime($du)) * 12 + (int) date('n', strtotime($du))) >= 24) { return ['error' => 'une saison dure deux ans au plus']; }
    $seuil = null;
    if (GP_DEFIS[$defi]['seuil'] !== null) {
        $seuil = is_numeric($b['seuil'] ?? null) ? max(0, min(100000, (int) round((float) $b['seuil']))) : (int) GP_DEFIS[$defi]['defaut'];
    }
    return ['portee' => $portee, 'shop' => $shop, 'defi' => $defi, 'titre' => $titre, 'lot' => $txt('lot', 255), 'valeur' => $valeur,
        'payeur' => $payeur, 'sponsor' => $sponsor !== '' ? $sponsor : null, 'sponsor_site' => $site !== '' ? $site : null, 'photo' => $photo !== '' ? $photo : null,
        'seuil' => $seuil, 'du' => $du, 'au' => $au, 'actif' => array_key_exists('actif', $b) ? (bool) $b['actif'] : true];
}

/** POST /ventes/grands-prix {id?, portee, shop, defi, titre, lot, valeur, payeur, sponsor, sponsorSite, photo, seuil, du, au, actif}. */
function wr_grands_prix(): array
{
    motTables();
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    $cur = $id > 0 ? Db::row('SELECT * FROM mot_grand_prix WHERE id = ?', [$id]) : null;
    if ($id > 0 && $cur === null) { http_response_code(404); return ['error' => 'grand prix introuvable']; }
    // Seul l'interrupteur : le reste ne bouge pas.
    if ($cur !== null && count($b) === 2 && array_key_exists('actif', $b)) {
        Db::exec('UPDATE mot_grand_prix SET actif = ? WHERE id = ?', [$b['actif'] ? 1 : 0, $id]);
        journalAdd('CEO', 'Paramètre', 'Grands prix', '« ' . $cur['titre'] . ' » ' . ($b['actif'] ? 'activé' : 'éteint'));
        return ['ok' => true, 'id' => $id];
    }
    $p = gpPropre($b, primesMagasins());
    if (isset($p['error'])) { http_response_code(422); return $p; }
    if ($cur === null) {
        $n = (int) (Db::row('SELECT COUNT(*) n FROM mot_grand_prix WHERE portee = ? AND ' . ($p['shop'] === null ? 'shop IS NULL' : 'shop = ?') . ' AND au >= ?',
            array_values(array_filter([$p['portee'], $p['shop'], date('Y-m-d')], static fn ($v) => $v !== null)))['n'] ?? 0);
        if ($n >= GP_MAX) { http_response_code(422); return ['error' => GP_MAX . ' grands prix en cours au plus']; }
    }
    $champs = ['portee', 'shop', 'defi', 'titre', 'lot', 'valeur', 'payeur', 'sponsor', 'sponsor_site', 'photo', 'seuil', 'du', 'au', 'actif'];
    $vals = array_map(static fn ($k) => is_bool($p[$k]) ? (int) $p[$k] : $p[$k], $champs);
    if ($cur === null) {
        Db::exec('INSERT INTO mot_grand_prix (' . implode(', ', $champs) . ', cree_le) VALUES (' . implode(',', array_fill(0, count($champs), '?')) . ', NOW())', $vals);
        $id = (int) (Db::row('SELECT LAST_INSERT_ID() id')['id'] ?? 0);
    } else {
        // Changer le défi ou la saison efface le classement gravé : il se recalcule.
        $efface = $cur['defi'] !== $p['defi'] || substr((string) $cur['du'], 0, 10) !== $p['du'] || substr((string) $cur['au'], 0, 10) !== $p['au'] || (int) ($cur['seuil'] ?? -1) !== (int) ($p['seuil'] ?? -1);
        Db::exec('UPDATE mot_grand_prix SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', $champs)) . ($efface ? ', resultat = NULL' : '') . ' WHERE id = ?', array_merge($vals, [$id]));
    }
    journalAdd('CEO', 'Paramètre', 'Grands prix', ($cur === null ? 'Nouveau grand prix' : 'Grand prix modifié') . ' : « ' . $p['titre'] . ' » (' . GP_DEFIS[$p['defi']]['lib'] . ', '
        . number_format($p['valeur'], 0, ',', ' ') . ' €, ' . ($p['portee'] === 'magasin' ? 'magasin ' . $p['shop'] : 'réseau') . ')');
    return ['ok' => true, 'id' => $id];
}

/** POST /ventes/grands-prix/retirer {id}. */
function wr_grands_prix_retirer(): array
{
    motTables();
    $id = (int) (body()['id'] ?? 0);
    $cur = $id > 0 ? Db::row('SELECT titre FROM mot_grand_prix WHERE id = ?', [$id]) : null;
    if ($cur === null) { http_response_code(404); return ['error' => 'grand prix introuvable']; }
    Db::exec('DELETE FROM mot_grand_prix WHERE id = ?', [$id]);
    journalAdd('CEO', 'Paramètre', 'Grands prix', 'Grand prix retiré : « ' . $cur['titre'] . ' »');
    return ['ok' => true];
}

/**
 * Les grands prix d'une vendeuse, pour « Mes primes » dans l'app (GET /ventes/moi) : ceux de la marque et ceux de son
 * magasin, en cours, à venir, ou finis depuis moins de 45 jours (le gagnant reste affiché). Pour chacun : son rang (ou
 * celui de son magasin pour le magasin de l'année), ce qu'il lui manque pour entrer au classement, le top 3.
 */
function gpPourVendeuse(int $emp, ?string $auj = null): array
{
    $auj ??= date('Y-m-d');
    $emps = venteEmployes();
    $shop = isset($emps[$emp]) ? (string) $emps[$emp]['shop'] : null;
    $nomDe = primesMagasins();
    $court = static fn (string $n) => (string) preg_replace('/^.* - /', '', $n);
    $out = [];
    foreach (gpListe($shop) as $gp) {
        if (!$gp['actif'] || ($gp['portee'] === 'magasin' && $gp['shop'] !== $shop)) { continue; }
        $sa = gpSaison($gp['du'], $gp['au'], $auj);
        if ($sa['etat'] === 'finie' && $auj > date('Y-m-d', strtotime($gp['au'] . ' +45 days'))) { continue; }
        $c = gpResultat($gp, $auj);
        $parMagasin = $gp['defi'] === 'magasin';
        $qui = $parMagasin ? (string) $shop : (string) $emp;
        $moi = null;
        foreach ($c['liste'] as $l) { if ((string) $l['id'] === $qui) { $moi = $l; break; } }
        $sous = $c['sous'][$qui] ?? null;
        $unite = ['vendeuse' => 'eur_h', 'progression' => 'pct_signe', 'croisees' => 'pct', 'b2b' => 'eur_ht', 'ticket' => 'eur', 'magasin' => 'pct'][$gp['defi']] ?? '';
        $top = array_map(static fn ($l) => ['rang' => $l['rang'], 'nom' => $parMagasin ? $court((string) $l['nom']) : (string) $l['nom'],
            'lieu' => $parMagasin ? '' : $court((string) $l['magasin']), 'valeur' => $l['valeur'], 'moi' => (string) $l['id'] === $qui], array_slice($c['liste'], 0, 3));
        $out[] = ['id' => $gp['id'], 'defi' => $gp['defi'], 'portee' => $gp['portee'], 'titre' => $gp['titre'], 'lot' => $gp['lot'], 'valeur' => $gp['valeur'],
            'payeur' => $gp['payeur'], 'sponsor' => $gp['sponsor'], 'sponsorSite' => $gp['sponsorSite'], 'photo' => $gp['photo'],
            'du' => $gp['du'], 'au' => $gp['au'], 'etat' => $sa['etat'], 'part' => $sa['part'], 'joursRestants' => $sa['joursRestants'],
            'seuil' => $gp['seuil'], 'seuilSorte' => $gp['seuilSorte'], 'seuilCourant' => $c['seuilCourant'], 'unite' => $unite,
            'moi' => $moi !== null ? ['rang' => $moi['rang'], 'valeur' => $moi['valeur']] : null,
            'manque' => $moi === null && $sous !== null ? ['n' => max(1, (int) ceil($sous['seuil'] - $sous['a'])), 'sorte' => $gp['seuilSorte']] : null,
            'enCourse' => $c['enCourse'], 'top' => $top, 'magasin' => $shop !== null ? $court((string) ($nomDe[$shop] ?? '')) : ''];
    }
    // La saison en cours d'abord, la plus grosse valeur en tête ; puis ce qui vient, puis ce qui est fini.
    $ordre = ['en_cours' => 0, 'a_venir' => 1, 'finie' => 2];
    usort($out, static fn ($a, $b) => [$ordre[$a['etat']], -$a['valeur'], $a['id']] <=> [$ordre[$b['etat']], -$b['valeur'], $b['id']]);
    return $out;
}
