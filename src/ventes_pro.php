<?php
declare(strict_types=1);

/**
 * Clients pro (B2B) — la part professionnelle des ventes, lue dans les tickets
 * du panel : chaque ticket porte `is_client_b2b`, s'il sera facturé
 * (`will_be_invoiced`) et s'il est en paiement différé (`deferral_payment`).
 * Rien d'autre que l'API du panel : ni la table client, ni le webshop. Un
 * ticket sans client pro est une vente comptoir.
 *
 * Pas de nom de client : le panel range sous « société » des noms de
 * personnes. Le relevé ne garde qu'une clé de compte (l'identifiant du
 * client s'il est là, sinon une empreinte du nom) pour compter les sociétés
 * servies, et l'écran ne montre que l'heure et le montant (demande du
 * 03/10/2026).
 *
 * La lecture s'accroche à la moisson des tickets : la LISTE des tickets d'un
 * jour (`/shops/{id}/transactions?date=`) suffit — un appel par jour et par
 * magasin, sans relire chaque ticket — et se grave sous `b` dans le relevé du
 * jour (`svP…`), à côté des produits. Les jours gravés avant cette lecture se
 * complètent au cron, par lots ; le dashboard en relit quelques-uns à la
 * demande. Un jour en cours se relit toutes les dix minutes.
 */

const VP_JOURS = 30;        // la fenêtre des comptes : 30 jours glissants
const VP_BUDGET_CRON = 40;  // listes relues par battement du cron pour compléter les jours d'avant
const VP_TTL = 600;         // un jour en cours : dix minutes, comme le reste du dashboard
const VP_TTL_TVA_OK = 21600; // une fiche pro avec TVA valide : relue toutes les six heures
const VP_TTL_TVA_KO = 3600;  // une fiche sans TVA valide : relue toutes les heures (une fiche complétée au panel compte dans l'heure)

/** La clé de compte d'un ticket pro, jamais le nom : l'identifiant du client, sinon une empreinte du nom ; '' sans l'un ni l'autre. */
function vpCleCompte(array $x): string
{
    foreach (['id_client', 'client_id', 'id_customer', 'customer_id', 'id_company', 'company_id'] as $k) {
        if (isset($x[$k]) && is_scalar($x[$k]) && (string) $x[$k] !== '' && (string) $x[$k] !== '0') { return 'c' . $x[$k]; }
    }
    $nom = trim((string) ($x['client_name'] ?? $x['company_name'] ?? ''));
    return $nom === '' ? '' : 'h' . hash('crc32b', mb_strtolower($nom));
}

/**
 * Pro = TVA valide (11/10/2026, demande de Sam) : un ticket ne compte pro que si le panel le marque client pro
 * (`is_client_b2b`) ET que la fiche du client porte un numéro de TVA valide. Mesuré à Halle le 11/10 : 6 tickets
 * marqués pro pour 469,20 €, une seule fiche avec une TVA (114,50 €) ; les cinq autres, sans TVA ni nom de société.
 *
 * vpTvaValides : [clé de compte => true | false | null] pour les clés « c{id_client} » d'un magasin. La fiche se lit au
 * panel (`/shops/{id}/clients/{id}`, vtFicheClient), le verdict se garde dans `vpTva:{shop}` (six heures s'il est bon,
 * une heure sinon). Une clé sans identifiant de client (empreinte du nom, ou vide) : false, pas de fiche, pas de TVA.
 * Une fiche illisible : null, le ticket garde l'avis du panel (un trou de l'API ne bascule pas le pro au comptoir).
 * Une TVA d'un autre pays de l'UE dont la forme est bonne compte pour valide.
 */
function vpTvaValides(int $sid, array $cles): array
{
    static $memo = [], $lu = [];
    $out = []; $manque = [];
    // Le verdict gardé, lu une fois par magasin et par requête (le Résultat le demande jour par jour).
    if (!array_key_exists($sid, $lu)) {
        $c = function_exists('setting') ? setting('vpTva:' . $sid) : null;
        if (is_string($c)) { $c = json_decode($c, true); }
        $lu[$sid] = is_array($c) ? $c : [];
    }
    $c = $lu[$sid];
    foreach (array_unique(array_map('strval', $cles)) as $k) {
        if (!preg_match('/^c(\d+)$/', $k, $m)) { $out[$k] = false; continue; }
        if (array_key_exists($sid . $k, $memo)) { $out[$k] = $memo[$sid . $k]; continue; }
        $x = $c[$k] ?? null;
        if (is_array($x) && (int) ($x[1] ?? 0) > time() - ((int) $x[0] === 1 ? VP_TTL_TVA_OK : VP_TTL_TVA_KO)) { $out[$k] = $memo[$sid . $k] = (int) $x[0] === 1; continue; }
        $manque[$k] = $m[1];
    }
    if ($manque === []) { return $out; }
    if (!function_exists('vtFicheClient') || !class_exists('PanelApi') || !PanelApi::configured()) { foreach ($manque as $k => $id) { $out[$k] = null; } return $out; }
    $chemins = [];
    foreach ($manque as $k => $id) { $chemins[$k] = '/shops/' . $sid . '/clients/' . rawurlencode((string) $id); }
    $res = PanelApi::getParallele($chemins, 6);
    $neuf = false;
    foreach ($manque as $k => $id) {
        $f = vtFicheClient($res[$k] ?? null);
        if (empty($f['lue'])) { $out[$k] = null; continue; }
        $ok = !empty($f['tva']) && ($f['valide'] ?? null) !== false;
        $out[$k] = $memo[$sid . $k] = $ok;
        $c[$k] = [$ok ? 1 : 0, time()]; $neuf = true;
    }
    if ($neuf && function_exists('svGrave')) { $lu[$sid] = $c; svGrave('vpTva:' . $sid, $c); }
    return $out;
}

/**
 * Le pro d'un jour gravé (`b`), réduit aux tickets dont le client a une TVA valide : `t` et `h` recomptés, `sansTva`
 * dit ce qui est parti au comptoir (tickets, CA). Le gravé garde l'avis brut du panel : une fiche complétée plus tard
 * fait revenir ses tickets au pro, jours passés compris.
 */
function vpFiltreTva(int $sid, ?array $b): ?array
{
    if ($b === null || empty($b['t']) || !is_array($b['t'])) { return $b === null ? null : $b + ['sansTva' => ['tickets' => 0, 'ca' => 0.0]]; }
    $ok = vpTvaValides($sid, array_map(static fn ($t) => (string) ($t[1] ?? ''), $b['t']));
    $t2 = []; $h = []; $nEx = 0; $caEx = 0.0;
    foreach ($b['t'] as $t) {
        if (($ok[(string) ($t[1] ?? '')] ?? null) === false) { $nEx++; $caEx += (float) $t[2]; continue; }
        $t2[] = $t;
        $hh = (string) (int) substr((string) $t[0], 0, 2);
        $h[$hh] = $h[$hh] ?? [0.0, 0];
        $h[$hh][0] += (float) $t[2]; $h[$hh][1]++;
    }
    foreach ($h as $k => $v) { $h[$k] = [round($v[0], 2), $v[1]]; }
    return ['t' => $t2, 'h' => $h, 'sansTva' => ['tickets' => $nEx, 'ca' => round($caEx, 2)]] + $b;
}

/** Le pro d'une liste de tickets du panel : total du jour, tickets pro (heure, clé de compte, montant, facturé, différé), par heure. */
function vpDuListe(array $liste): array
{
    $t = []; $h = []; $ca = 0.0; $n = 0;
    foreach (analyseListe($liste) as $x) {
        if (!is_array($x)) { continue; }
        $m = (float) ($x['total_gross_amount_after_discount'] ?? $x['total_value_after_discount'] ?? 0);
        $n++; $ca += $m;
        if (empty($x['is_client_b2b'])) { continue; }
        $ts = (string) ($x['insert_timestamp'] ?? '');
        $hh = (string) (int) substr($ts, 11, 2);
        $t[] = [substr($ts, 11, 5), vpCleCompte($x), round($m, 2), !empty($x['will_be_invoiced']) ? 1 : 0, !empty($x['deferral_payment']) ? 1 : 0];
        $h[$hh] = $h[$hh] ?? [0.0, 0];
        $h[$hh][0] += $m; $h[$hh][1]++;
    }
    foreach ($h as $k => $v) { $h[$k] = [round($v[0], 2), $v[1]]; }
    usort($t, static fn ($a, $b) => strcmp($a[0], $b[0]));
    return ['q' => time(), 'n' => $n, 'ca' => round($ca, 2), 't' => $t, 'h' => $h];
}

/** Le pro gravé d'un jour vaut-il encore ? Clos s'il a été lu un jour plus tard ; sinon dix minutes. */
function vpValide(mixed $c, string $j): bool
{
    if (!is_array($c) || !isset($c['b']) || !is_array($c['b'])) { return false; }
    $q = (int) ($c['b']['q'] ?? $c['quand'] ?? 0);
    if ($q <= 0) { return true; }
    return date('Y-m-d', $q) > $j || $q > time() - VP_TTL;
}

/**
 * Le pro d'un jour : gravé sous `b` dans le relevé du jour ; sinon, si on peut
 * lire et que le budget le permet, la liste des tickets (un appel). Le relevé
 * des produits garde sa propre validité — on ne touche pas à son horodatage.
 */
function vpJour(int $sid, string $j, bool $lire, int &$appels, int $budget): ?array
{
    $c = setting('svP' . $sid . ':' . $j);
    if (vpValide($c, $j)) { return vpFiltreTva($sid, $c['b']); }
    $ancien = is_array($c) && isset($c['b']) && is_array($c['b']) ? $c['b'] : null;
    if (!$lire || $appels >= $budget || !class_exists('PanelApi') || !PanelApi::configured()) { return vpFiltreTva($sid, $ancien); }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    $appels++;
    if (!is_array($liste)) { return vpFiltreTva($sid, $ancien); }
    return vpGraveListe($sid, $j, $liste);
}

/** Le pro gravé d'un jour s'il vaut encore (clos, ou lu il y a moins de dix minutes) ; sinon null. */
function vpLu(int $sid, string $j): ?array
{
    $c = setting('svP' . $sid . ':' . $j);
    return vpValide($c, $j) ? vpFiltreTva($sid, $c['b']) : null;
}

/** Grave sous `b` le pro d'une liste de tickets déjà lue, sans toucher au relevé des produits. */
function vpGraveListe(int $sid, string $j, array $liste): array
{
    $cle = 'svP' . $sid . ':' . $j;
    $c = setting($cle);
    $b = vpDuListe($liste);
    $c = is_array($c) ? $c : ['quand' => time()];
    $c['b'] = $b;
    svGrave($cle, $c);
    return vpFiltreTva($sid, $b);
}

/** Le split d'une journée pour un tableau : CA et tickets pro, le reste au comptoir (sur le CA de la ligne). */
function vpSplit(?array $b, float $ca, int $tickets): array
{
    if ($b === null) { return ['caPro' => null, 'ticketsPro' => null, 'caComptoir' => null, 'ticketsComptoir' => null, 'partPro' => null]; }
    $caPro = 0.0;
    foreach ($b['t'] as $t) { $caPro += (float) $t[2]; }
    $nPro = count($b['t']);
    $caC = max(0.0, $ca - $caPro); $nC = max(0, $tickets - $nPro);
    return ['caPro' => round($caPro, 2), 'ticketsPro' => $nPro,
        'caComptoir' => round($caC, 2), 'ticketsComptoir' => $nC,
        'partPro' => $ca > 0 ? round(100 * $caPro / $ca, 1) : null,
        'panierPro' => $nPro > 0 ? round($caPro / $nPro, 2) : null,
        'panierComptoir' => $nC > 0 ? round($caC / $nC, 2) : null];
}

/**
 * Le panier moyen hors clients pro (21/10/2026, demande de Sam : « dans le panier moyen sortir les commandes b2b, elles
 * faussent le panier moyen ») : CA comptoir ÷ tickets comptoir, les tickets pro (TVA valide, vpFiltreTva) retirés du CA
 * ET des tickets. Mesuré le 20/10 à Gosselies : deux tickets pro pour 1 110,05 € portaient le panier à 17,92 € quand le
 * comptoir faisait 9,59 € — et le réseau à 13,20 € pour 11,18 €. `panierRetenu` est le panier que le dashboard montre
 * et classe ; `panier` reste le total (les clients manquants sur le CA total, le PDF, le cockpit le lisent ainsi).
 * Sans split lu, le panier retenu reste le total et le dit (`panierBase` = total) : un panier pro compris ne se range
 * jamais à côté d'un panier hors pro. Split lu sans aucun ticket comptoir (que du pro) : base comptoir, panier null.
 */
function vpPanier(?float $caComptoir, ?int $ticketsComptoir, ?float $panierTotal): array
{
    if ($caComptoir !== null && $ticketsComptoir !== null) {
        return ['panierRetenu' => $ticketsComptoir > 0 ? round($caComptoir / $ticketsComptoir, 2) : null, 'panierBase' => 'comptoir'];
    }
    return ['panierRetenu' => $panierTotal !== null ? round($panierTotal, 2) : null, 'panierBase' => 'total'];
}

/**
 * Le pro gravé de plusieurs jours d'un magasin, additionné, sans appel au panel (vpLu) : ['ca', 'tk', 'lus', 'jours',
 * 'h' => [heure => [CA, tickets]]] — la forme que resProSplit attend. Un jour d'avant SV_DEBUT n'a jamais été lu : il
 * compte manquant sans relire de relevé. `$arret` : on s'arrête au premier jour manquant (un split incomplet ne sert
 * pas, autant ne pas lire les relevés suivants). `$lus` : le pro déjà en main, [date => b] (vpJoursLus) — il fait foi,
 * un jour qui n'y est pas est manquant.
 */
function vpProJours(int $sid, array $jours, bool $arret = false, ?array $lus = null): array
{
    $p = ['ca' => 0.0, 'tk' => 0, 'lus' => 0, 'jours' => count($jours), 'h' => []];
    foreach ($jours as $j) {
        $j = (string) $j;
        $b = $lus !== null ? ($lus[$j] ?? null) : ((defined('SV_DEBUT') && $j < SV_DEBUT) ? null : vpLu($sid, $j));
        if ($b === null) { if ($arret) { break; } continue; }
        $p['lus']++; $p['tk'] += count($b['t']);
        foreach ($b['t'] as $t) { $p['ca'] += (float) $t[2]; }
        foreach ((array) ($b['h'] ?? []) as $hh => $v) {
            $p['h'][(int) $hh] = [($p['h'][(int) $hh][0] ?? 0.0) + (float) $v[0], ($p['h'][(int) $hh][1] ?? 0) + (int) $v[1]];
        }
    }
    return $p;
}

/**
 * Le CA et les tickets comptoir d'une étendue dont on a additionné le pro (vpProJours) : le reste du total, seulement si
 * tous ses jours sont lus — sinon null, le comptoir porterait le pro des jours manquants.
 */
function vpComptoirDe(array $p, float $ca, int $tickets): array
{
    if ($p['jours'] <= 0 || $p['lus'] !== $p['jours']) { return ['caComptoir' => null, 'ticketsComptoir' => null]; }
    return ['caComptoir' => round(max(0.0, $ca - $p['ca']), 2), 'ticketsComptoir' => max(0, $tickets - (int) $p['tk'])];
}

/**
 * Les clients manquants au COMPTOIR : (CA comptoir − objectif) ÷ panier
 * comptoir. Le CA des clients pro ne compte pas — ni pour atteindre l'objectif,
 * ni dans le panier : un client qui manque est un client du comptoir. Positif =
 * il en manque, négatif = d'avance. Sans split lu, la ligne garde le calcul sur
 * le CA total et le dit (`clientsBase` = total).
 */
function vpClientsComptoir(array $l, ?float $objectif): array
{
    if ($objectif === null || ($l['caComptoir'] ?? null) === null || empty($l['panierComptoir'])) { return ['clientsBase' => 'total']; }
    $e = (float) $l['caComptoir'] - $objectif;
    return ['ecartComptoir' => round($e, 2), 'clientsManquants' => (int) round(-$e / (float) $l['panierComptoir']), 'clientsBase' => 'comptoir'];
}

/**
 * Le pro gravé de plusieurs jours pour plusieurs magasins — [sid => [date => b]] —
 * complété par au plus $budget listes de tickets lues en parallèle, les jours
 * les plus récents d'abord. Ce qui reste non lu le sera au cron (vpMoisson).
 */
function vpJoursLus(array $sids, array $jours, int $budget = 30): array
{
    $out = []; $manque = [];
    foreach ($sids as $sid) {
        foreach ($jours as $j) {
            $b = vpLu((int) $sid, $j);
            if ($b !== null) { $out[(int) $sid][$j] = $b; } else { $manque[] = [(int) $sid, $j]; }
        }
    }
    if ($manque && $budget > 0 && class_exists('PanelApi') && PanelApi::configured()) {
        usort($manque, static fn ($a, $b) => strcmp($b[1], $a[1]));
        $paths = [];
        foreach (array_slice($manque, 0, $budget) as [$sid, $j]) { $paths[$sid . '|' . $j] = '/shops/' . $sid . '/transactions?date=' . $j; }
        foreach (PanelApi::getParallele($paths, 6) as $k => $liste) {
            if (!is_array($liste)) { continue; }
            [$sid, $j] = explode('|', (string) $k, 2);
            $out[(int) $sid][$j] = vpGraveListe((int) $sid, $j, $liste);
        }
    }
    return $out;
}

/** Les tickets pro d'un jour, mis en forme pour l'écran : l'heure et le montant, jamais le nom du compte. */
function vpTickets(array $b): array
{
    return array_map(static fn ($t) => ['heure' => (string) $t[0], 'montant' => (float) $t[2], 'facture' => (int) $t[3] === 1, 'differe' => (int) $t[4] === 1], $b['t']);
}

/** Les chiffres d'un jour : total, pro, comptoir, à facturer. */
function vpBilanJour(array $b): array
{
    $caPro = 0.0; $aFacturer = 0.0; $differes = 0; $societes = [];
    foreach ($b['t'] as $t) { $caPro += (float) $t[2]; if ((int) $t[3] === 1) { $aFacturer += (float) $t[2]; } if ((int) $t[4] === 1) { $differes++; } if ($t[1] !== '') { $societes[$t[1]] = true; } }
    $n = (int) $b['n']; $nPro = count($b['t']); $ca = (float) $b['ca'];
    return ['ca' => round($ca, 2), 'tickets' => $n, 'caPro' => round($caPro, 2), 'ticketsPro' => $nPro, 'societes' => count($societes),
        'panierPro' => $nPro > 0 ? round($caPro / $nPro, 2) : null, 'panierComptoir' => $n - $nPro > 0 ? round(($ca - $caPro) / ($n - $nPro), 2) : null,
        'part' => $ca > 0 ? round(100 * $caPro / $ca, 1) : null, 'aFacturer' => round($aFacturer, 2), 'differes' => $differes];
}

/**
 * GET /exploitation/pro?shop=5&date=YYYY-MM-DD — la carte « Clients pro » du
 * dashboard : le jour (tickets pro à l'heure et au montant, à facturer), les
 * 30 jours (part, nombre de comptes servis), la série jour par jour. Aucun
 * nom de compte ne sort.
 */
function ep_exploitation_pro(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    @set_time_limit(60);
    $appels = 0;
    $bJ = vpJour($sid, $date, true, $appels, 2);
    $jour = $bJ === null ? null : vpBilanJour($bJ) + ['heures' => (object) $bJ['h'], 'liste' => vpTickets($bJ), 'sansTva' => $bJ['sansTva'] ?? null];
    $serie = []; $comptes = []; $caPro = 0.0; $ca = 0.0; $tkPro = 0; $tk = 0; $lus = 0; $manquants = 0; $sansCle = 0; $nSansTva = 0; $caSansTva = 0.0;
    for ($i = VP_JOURS - 1; $i >= 0; $i--) {
        $j = date('Y-m-d', strtotime($date . ' -' . $i . ' days'));
        if (defined('SV_DEBUT') && $j < SV_DEBUT) { continue; }
        $b = $j === $date ? $bJ : vpJour($sid, $j, true, $appels, 8);
        if ($b === null) { $manquants++; $serie[] = ['j' => $j, 'ca' => null, 'caPro' => null]; continue; }
        if ((int) $b['n'] === 0) { $serie[] = ['j' => $j, 'ca' => 0, 'caPro' => 0, 'ferme' => true]; continue; }
        $lus++;
        $nSansTva += (int) ($b['sansTva']['tickets'] ?? 0); $caSansTva += (float) ($b['sansTva']['ca'] ?? 0);
        $cp = 0.0;
        foreach ($b['t'] as $t) {
            $cp += (float) $t[2];
            if ((string) $t[1] !== '') { $comptes[(string) $t[1]] = true; } else { $sansCle++; }
        }
        $serie[] = ['j' => $j, 'ca' => round((float) $b['ca'], 2), 'caPro' => round($cp, 2), 'tickets' => (int) $b['n'], 'ticketsPro' => count($b['t'])];
        $caPro += $cp; $ca += (float) $b['ca']; $tkPro += count($b['t']); $tk += (int) $b['n'];
    }
    return ['shop' => $sid, 'date' => $date, 'jour' => $jour,
        'mois' => ['du' => $serie[0]['j'] ?? $date, 'au' => $date, 'jours' => $lus, 'manquants' => $manquants, 'caPro' => round($caPro, 2), 'ca' => round($ca, 2),
            'part' => $ca > 0 ? round(100 * $caPro / $ca, 1) : null, 'ticketsPro' => $tkPro, 'tickets' => $tk, 'panierPro' => $tkPro > 0 ? round($caPro / $tkPro, 2) : null,
            'societes' => count($comptes), 'ticketsSansCompte' => $sansCle],
        'serie' => $serie, 'appels' => $appels,
        'sansTva' => ['tickets' => $nSansTva, 'ca' => round($caSansTva, 2)],
        'source' => 'tickets du panel : is_client_b2b, will_be_invoiced, deferral_payment ; pro seulement si la fiche du client porte une TVA valide — un ticket sans client pro est une vente comptoir ; aucun nom de compte ne sort'];
}

/**
 * Au cron : compléter le pro des jours déjà gravés sans lui, du plus récent au
 * plus ancien, par lots — une liste par jour et par magasin.
 */
function vpMoisson(int $budget = VP_BUDGET_CRON): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    try { $shops = array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $appels = 0; $faits = 0; $restants = 0;
    $hier = date('Y-m-d', strtotime('-1 day'));
    $debut = defined('SV_DEBUT') ? SV_DEBUT : date('Y-m-d', strtotime('-60 days'));
    for ($j = $hier; $j >= $debut; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        foreach ($shops as $sid) {
            if (vpValide(setting('svP' . $sid . ':' . $j), $j)) { continue; }
            if ($appels >= $budget) { $restants++; continue; }
            if (vpJour($sid, $j, true, $appels, $budget) !== null) { $faits++; } else { $restants++; }
        }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'listes' => $appels, 'joursRestants' => $restants];
}
