<?php
declare(strict_types=1);

/**
 * La prime B2B (23/10/2026, demande : « donner envie d'aller chercher les écoles… ») — une prime que la marque ou un
 * franchisé pose pour aller chercher les clients pro. Elle s'écrit en une phrase (l'option D des maquettes,
 * docs/maquettes/primes-b2b) : « Pour chaque nouveau client pro, la vendeuse touche 5 % de chaque commande pendant
 * 3 mois. »
 *
 * Les règles (décidées le 23/10/2026) :
 *  - un NOUVEAU client pro : un client marqué pro au panel (client.is_b2b) avec une TVA valide — la règle des clients
 *    pro du cockpit (vpTvaValides) —, sans aucune commande pro dans le magasin les 12 mois d'avant ;
 *  - la prime va à la vendeuse qui encaisse sa 1re commande (transaction.id_employee), et à elle seule, toute la durée ;
 *  - un pourcentage du montant HT (le TTC moins la TVA du ticket) de chaque commande comptée — la 1re seulement, ou
 *    toutes pendant 1, 3 ou 12 mois, ou sans limite —, ou un montant fixe à sa 1re commande ;
 *  - au choix, une commande minimum (HT) et un plafond par client sur toute la durée ;
 *  - seuls comptent les clients dont la 1re commande tombe dans la période de la prime (du : le jour où elle est
 *    créée, par défaut ; au : sans fin). Une prime terminée paie encore les clients déjà ramenés, jusqu'au bout de leur
 *    durée ; éteinte, elle ne paie plus.
 *
 * Les tickets se lisent dans la base partagée (transaction ⨝ client), magasin par magasin, gardés quinze minutes pour
 * le mois en cours, un jour pour un mois clos. Aucun nom de client ne sort (la règle du 03/10/2026) : l'app montre
 * « un client du 12/10 », ses commandes et ce qu'il rapporte.
 */

const B2B_NOUVEAU_MOIS = 12;        // sans commande pro depuis 12 mois : un nouveau client
const B2B_SANS_LIMITE_MOIS = 24;    // « sans limite » : les clients ramenés depuis 24 mois au plus
const B2B_TVA_ESTIMEE = 0.06;       // sans la TVA du ticket dans la base : le HT se déduit au taux alimentaire
const B2B_MAX = 3;                  // trois primes B2B au plus par magasin, trois pour la marque
const B2B_MONTANT = 30;             // le montant fixe par défaut
const B2B_CACHE_COURS = 900;
const B2B_CACHE_CLOS = 86400;

/** Les durées : la 1re commande seulement, 1, 3 ou 12 mois, sans limite. */
function b2bDurees(): array { return ['premiere', 1, 3, 12, 'sans']; }

/**
 * La règle d'une prime B2B, nettoyée : {mode: pct | fixe, taux (en %), duree, plafond (€ par client, 0 : aucun), min
 * (€ HT par commande, 0 : aucune)}. Un montant fixe se gagne à la 1re commande.
 */
function b2bRegle(array $x): array
{
    $mode = ($x['mode'] ?? 'pct') === 'fixe' ? 'fixe' : 'pct';
    $taux = isset($x['taux']) && is_numeric($x['taux']) ? max(0.5, min(50.0, round((float) $x['taux'] * 2) / 2)) : 5.0;
    $d = $x['duree'] ?? 3;
    $duree = in_array($d, ['premiere', 'sans'], true) ? $d : (in_array((int) $d, [1, 3, 12], true) ? (int) $d : 3);
    if ($mode === 'fixe') { $duree = 'premiere'; }
    $ent = static fn ($v) => is_numeric($v) && (float) $v > 0 ? min(100000, (int) round((float) $v)) : 0;
    return ['mode' => $mode, 'taux' => $taux, 'duree' => $duree, 'plafond' => $ent($x['plafond'] ?? 0), 'min' => $ent($x['min'] ?? 0)];
}

/** Le premier jour où une fenêtre ouverte peut encore payer le mois $m. */
function b2bDepart(array $r, string $m): string
{
    $debM = $m . '-01';
    if ($r['duree'] === 'premiere') { return $debM; }
    $mois = $r['duree'] === 'sans' ? B2B_SANS_LIMITE_MOIS : (int) $r['duree'];
    return date('Y-m-d', strtotime($debM . ' -' . $mois . ' months'));
}

/** La fin (exclue) de la fenêtre d'un client ramené le $debut : null sans limite ; la 1re commande seulement, le lendemain. */
function b2bFin(string $debut, $duree): ?string
{
    if ($duree === 'sans') { return null; }
    return date('Y-m-d', strtotime($debut . ($duree === 'premiere' ? ' +1 day' : ' +' . (int) $duree . ' months')));
}

/**
 * Le calcul, sans base. $tickets : [{c: le client, e: la vendeuse, j: « AAAA-MM-JJ hh:mm:ss », ht}] ; $r : la règle
 * (b2bRegle) avec montant (le fixe), du, au ; $m : le mois. Rend, par vendeuse : {montant, nouveaux, clients: [{cle,
 * debut, fin, commandes, ht, gain, total, plafond, ouvert}]} — les clients qu'elle a ramenés et qui comptent ce mois-là.
 */
function b2bCalcul(array $tickets, array $r, string $m): array
{
    $debM = $m . '-01'; $finM = date('Y-m-t', strtotime($debM));
    $dpt = b2bDepart($r, $m);
    $du = $r['du'] ?? null; $au = $r['au'] ?? null;
    $taux = (float) $r['taux']; $min = (float) $r['min']; $plaf = (float) $r['plafond']; $fixe = (float) ($r['montant'] ?? 0);
    $parC = [];
    foreach ($tickets as $t) { $parC[(string) $t['c']][] = $t; }
    $out = [];
    foreach ($parC as $client => $ts) {
        usort($ts, static fn ($a, $b) => strcmp((string) $a['j'], (string) $b['j']));
        $avant = null; $wi = null; $F = [];
        foreach ($ts as $t) {
            $j = substr((string) $t['j'], 0, 10);
            // Nouveau : sa 1re commande pro, ou la 1re après douze mois sans.
            $nouveau = $avant === null || $j >= date('Y-m-d', strtotime($avant . ' +' . B2B_NOUVEAU_MOIS . ' months'));
            $avant = $j;
            if ($nouveau) {
                $wi = null;
                if ($j >= $dpt && $j <= $finM && ($du === null || $j >= $du) && ($au === null || $j <= $au)) {
                    $F[] = ['e' => (int) $t['e'], 'debut' => $j, 'fin' => b2bFin($j, $r['duree']), 'n' => 0, 'gagne' => 0.0, 'commandes' => 0, 'ht' => 0.0, 'gain' => 0.0];
                    $wi = count($F) - 1;
                }
            } elseif ($wi !== null && ($r['duree'] === 'premiere' || ($F[$wi]['fin'] !== null && $j >= $F[$wi]['fin']))) {
                // Hors de sa durée : le client reste au magasin, la prime s'arrête.
                $wi = null;
            }
            if ($wi === null) { continue; }
            $F[$wi]['n']++;
            $ht = (float) $t['ht'];
            $gain = 0.0;
            if ($ht > 0 && $ht + 1e-9 >= $min) {
                $gain = $r['mode'] === 'fixe' ? ($F[$wi]['n'] === 1 ? $fixe : 0.0) : round($taux / 100 * $ht, 2);
                if ($plaf > 0) { $gain = max(0.0, min($gain, $plaf - $F[$wi]['gagne'])); }
            }
            $F[$wi]['gagne'] = round($F[$wi]['gagne'] + $gain, 2);
            if (substr($j, 0, 7) === $m) { $F[$wi]['commandes']++; $F[$wi]['ht'] = round($F[$wi]['ht'] + $ht, 2); $F[$wi]['gain'] = round($F[$wi]['gain'] + $gain, 2); }
        }
        foreach ($F as $f) {
            // Compte ce mois-là : ramené au plus tard ce mois-ci, et sa durée pas finie avant le 1er.
            if ($f['debut'] > $finM || ($f['fin'] !== null && $f['fin'] <= $debM)) { continue; }
            $e = $f['e'];
            $out[$e] = $out[$e] ?? ['montant' => 0.0, 'nouveaux' => 0, 'clients' => []];
            $out[$e]['montant'] = round($out[$e]['montant'] + $f['gain'], 2);
            if (substr($f['debut'], 0, 7) === $m) { $out[$e]['nouveaux']++; }
            $out[$e]['clients'][] = ['cle' => 'k' . substr(md5($client . '|' . $f['debut']), 0, 8), 'debut' => $f['debut'], 'fin' => $r['duree'] === 'premiere' ? null : $f['fin'],
                'commandes' => $f['commandes'], 'ht' => $f['ht'], 'gain' => $f['gain'], 'total' => $f['gagne'],
                'plafond' => $plaf > 0 && $f['gagne'] + 1e-9 >= $plaf, 'ouvert' => $r['duree'] !== 'premiere' && ($f['fin'] === null || $f['fin'] > $finM)];
        }
    }
    foreach ($out as $e => $x) { usort($out[$e]['clients'], static fn ($a, $b) => strcmp($b['debut'], $a['debut'])); }
    return $out;
}

/**
 * Les tickets pro d'un magasin entre deux dates (au exclu), dans la base partagée : [{c, e, j, ht}] — les clients sans TVA
 * valide retirés (la règle des clients pro, vpTvaValides) ; le HT, le TTC moins la TVA du ticket (sans elle, déduit au
 * taux alimentaire). Null si la base ne répond pas.
 */
function b2bTickets(int $sid, string $du, string $au): ?array
{
    static $avecTva = null;
    $sql = static fn (bool $tva) => 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ t.id_client c, t.id_employee e, t.insert_timestamp j, t.total_gross_amount_after_discount ttc'
        . ($tva ? ', t.tax_amount_after_discount tva' : '') . '
          FROM `transaction` t JOIN client c ON c.id = t.id_client
         WHERE t.id_shop = ? AND c.is_b2b = 1 AND t.insert_timestamp >= ? AND t.insert_timestamp < ?
         ORDER BY t.insert_timestamp';
    try {
        $rows = Db::rows($sql($avecTva !== false), [$sid, $du, $au]);
        $avecTva ??= true;
    } catch (Throwable $e) {
        if ($avecTva === false) { return null; }
        $avecTva = false;
        try { $rows = Db::rows($sql(false), [$sid, $du, $au]); } catch (Throwable $e2) { return null; }
    }
    $cles = array_values(array_unique(array_map(static fn ($x) => 'c' . (int) ($x['c'] ?? 0), $rows)));
    $tva = ($cles !== [] && function_exists('vpTvaValides')) ? vpTvaValides($sid, $cles) : [];
    $out = [];
    foreach ($rows as $x) {
        $k = 'c' . (int) ($x['c'] ?? 0);
        if ($k === 'c0' || ($tva[$k] ?? null) === false) { continue; }
        $ttc = (float) ($x['ttc'] ?? 0);
        $ht = isset($x['tva']) && is_numeric($x['tva']) ? $ttc - (float) $x['tva'] : $ttc / (1 + B2B_TVA_ESTIMEE);
        $out[] = ['c' => $k, 'e' => (int) ($x['e'] ?? 0), 'j' => (string) ($x['j'] ?? ''), 'ht' => round($ht, 2)];
    }
    return $out;
}

/** La règle de calcul d'une entrée de la liste des primes : sa règle, son montant fixe, sa période. */
function b2bRegleDe(array $e): array
{
    return b2bRegle($e) + ['montant' => (int) ($e['montant'] ?? B2B_MONTANT), 'du' => $e['du'] ?? null, 'au' => $e['au'] ?? null];
}

/** Une prime B2B sur un mois dans un magasin, par vendeuse (b2bCalcul) ; gardée quinze minutes (un jour, un mois clos). Null : base muette. */
function b2bMois(int $sid, string $m, array $e): ?array
{
    $r = b2bRegleDe($e);
    $cle = 'b2bM' . $sid . ':' . $m . ':' . substr(md5((string) json_encode($r)), 0, 10);
    $enCours = $m === date('Y-m');
    $c = setting($cle);
    if (is_array($c) && isset($c['p']) && (int) ($c['q'] ?? 0) > time() - ($enCours ? B2B_CACHE_COURS : B2B_CACHE_CLOS)) { return (array) $c['p']; }
    $du = date('Y-m-d', strtotime(b2bDepart($r, $m) . ' -' . B2B_NOUVEAU_MOIS . ' months'));
    $au = date('Y-m-d', strtotime(date('Y-m-t', strtotime($m . '-01')) . ' +1 day'));
    $t = b2bTickets($sid, $du, $au);
    if ($t === null) { return null; }
    $p = b2bCalcul($t, $r, $m);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['q' => time(), 'p' => (object) $p], JSON_UNESCAPED_UNICODE)]);
    return $p;
}

/** Les primes B2B actives d'un magasin : les siennes et celles de la marque. */
function b2bEntrees(string $shop): array
{
    if (!function_exists('primesAppDuType')) { return []; }
    return array_values(array_filter(primesAppDuType('b2b'), static fn ($e) => ($e['famille'] ?? 'marque') !== 'magasin' || (string) ($e['shop'] ?? '') === $shop));
}

/**
 * La brique B2B d'une personne sur un mois : {montant, liste: [{cle, lib, montant, nouveaux, clients, lu, mode, taux,
 * duree, plafond, min, fixe, du, au}]} — une ligne par prime B2B de son magasin.
 */
function b2bBrique(int $emp, string $shop, string $m): array
{
    $liste = []; $total = 0.0;
    foreach (b2bEntrees($shop) as $e) {
        $p = b2bMois((int) $shop, $m, $e);
        $moi = $p[$emp] ?? ['montant' => 0.0, 'nouveaux' => 0, 'clients' => []];
        $total += (float) $moi['montant'];
        $liste[] = ['cle' => $e['cle'], 'lib' => $e['lib'], 'montant' => round((float) $moi['montant'], 2), 'nouveaux' => (int) $moi['nouveaux'],
            'clients' => array_values((array) $moi['clients']), 'lu' => $p !== null, 'fixe' => (int) ($e['montant'] ?? B2B_MONTANT),
            'du' => $e['du'] ?? null, 'au' => $e['au'] ?? null] + b2bRegle($e);
    }
    return ['montant' => round($total, 2), 'liste' => $liste];
}

/** Ce que les primes B2B d'un magasin donnent sur un mois : [vendeuse => {montant, parCle: {clé: montant}, clients}]. */
function b2bMagasinMois(string $shop, string $m): array
{
    $out = [];
    foreach (b2bEntrees($shop) as $e) {
        foreach ((array) (b2bMois((int) $shop, $m, $e) ?? []) as $emp => $x) {
            $v = round((float) ($x['montant'] ?? 0), 2);
            $o = $out[(int) $emp] ?? ['montant' => 0.0, 'parCle' => [], 'clients' => 0];
            $o['montant'] = round($o['montant'] + $v, 2);
            $o['parCle'][$e['cle']] = round(($o['parCle'][$e['cle']] ?? 0) + $v, 2);
            $o['clients'] += count((array) ($x['clients'] ?? []));
            $out[(int) $emp] = $o;
        }
    }
    return $out;
}

/** La phrase d'une prime B2B, pour le cockpit et le journal : « 5 % HT de chaque commande pendant 3 mois ». */
function b2bPhrase(array $e): string
{
    $r = b2bRegle($e);
    if ($r['mode'] === 'fixe') { return (int) ($e['montant'] ?? B2B_MONTANT) . ' € à sa 1re commande'; }
    $t = str_replace('.', ',', (string) $r['taux']) . ' % HT ';
    return $t . match ($r['duree']) { 'premiere' => 'de sa 1re commande', 'sans' => 'de chaque commande, sans limite', default => 'de chaque commande pendant ' . $r['duree'] . ' mois' };
}
