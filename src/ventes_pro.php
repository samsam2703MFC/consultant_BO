<?php
declare(strict_types=1);

/**
 * Clients pro (B2B) — la part professionnelle des ventes, lue dans les tickets
 * du panel : chaque ticket porte `is_client_b2b`, le nom de la société, s'il
 * sera facturé (`will_be_invoiced`) et s'il est en paiement différé
 * (`deferral_payment`). Rien d'autre que l'API du panel : ni la table client,
 * ni le webshop. Un ticket sans client pro est une vente comptoir.
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

/** Le pro d'une liste de tickets du panel : total du jour, tickets pro (heure, société, montant, facturé, différé), par heure. */
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
        $t[] = [substr($ts, 11, 5), mb_substr(trim((string) ($x['client_name'] ?? '')), 0, 80), round($m, 2), !empty($x['will_be_invoiced']) ? 1 : 0, !empty($x['deferral_payment']) ? 1 : 0];
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
    $cle = 'svP' . $sid . ':' . $j;
    $c = setting($cle);
    if (vpValide($c, $j)) { return $c['b']; }
    $ancien = is_array($c) && isset($c['b']) && is_array($c['b']) ? $c['b'] : null;
    if (!$lire || $appels >= $budget || !class_exists('PanelApi') || !PanelApi::configured()) { return $ancien; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    $appels++;
    if (!is_array($liste)) { return $ancien; }
    $b = vpDuListe($liste);
    $c = is_array($c) ? $c : ['quand' => time()];
    $c['b'] = $b;
    svGrave($cle, $c);
    return $b;
}

/** Les tickets pro d'un jour, mis en forme pour l'écran. */
function vpTickets(array $b): array
{
    return array_map(static fn ($t) => ['heure' => (string) $t[0], 'societe' => (string) $t[1], 'montant' => (float) $t[2], 'facture' => (int) $t[3] === 1, 'differe' => (int) $t[4] === 1], $b['t']);
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
 * dashboard : le jour (tickets pro, à facturer), les 30 jours (part, comptes),
 * la série jour par jour.
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
    $jour = $bJ === null ? null : vpBilanJour($bJ) + ['heures' => (object) $bJ['h'], 'liste' => vpTickets($bJ)];
    $serie = []; $comptes = []; $caPro = 0.0; $ca = 0.0; $tkPro = 0; $tk = 0; $lus = 0; $manquants = 0;
    for ($i = VP_JOURS - 1; $i >= 0; $i--) {
        $j = date('Y-m-d', strtotime($date . ' -' . $i . ' days'));
        if (defined('SV_DEBUT') && $j < SV_DEBUT) { continue; }
        $b = $j === $date ? $bJ : vpJour($sid, $j, true, $appels, 8);
        if ($b === null) { $manquants++; $serie[] = ['j' => $j, 'ca' => null, 'caPro' => null]; continue; }
        if ((int) $b['n'] === 0) { $serie[] = ['j' => $j, 'ca' => 0, 'caPro' => 0, 'ferme' => true]; continue; }
        $lus++;
        $cp = 0.0;
        foreach ($b['t'] as $t) {
            $cp += (float) $t[2];
            $s = $t[1] !== '' ? $t[1] : 'Client pro sans nom';
            $comptes[$s] = $comptes[$s] ?? ['societe' => $s, 'n' => 0, 'ca' => 0.0, 'dernier' => $j, 'factures' => 0];
            $comptes[$s]['n']++; $comptes[$s]['ca'] += (float) $t[2]; $comptes[$s]['factures'] += (int) $t[3];
            if ($j > $comptes[$s]['dernier']) { $comptes[$s]['dernier'] = $j; }
        }
        $serie[] = ['j' => $j, 'ca' => round((float) $b['ca'], 2), 'caPro' => round($cp, 2), 'tickets' => (int) $b['n'], 'ticketsPro' => count($b['t'])];
        $caPro += $cp; $ca += (float) $b['ca']; $tkPro += count($b['t']); $tk += (int) $b['n'];
    }
    usort($comptes, static fn ($a, $b) => $b['ca'] <=> $a['ca']);
    $comptes = array_map(static fn ($c) => ['societe' => $c['societe'], 'n' => $c['n'], 'ca' => round($c['ca'], 2), 'panier' => round($c['ca'] / max(1, $c['n']), 2), 'dernier' => $c['dernier'], 'factures' => $c['factures']], array_slice($comptes, 0, 12));
    return ['shop' => $sid, 'date' => $date, 'jour' => $jour,
        'mois' => ['du' => $serie[0]['j'] ?? $date, 'au' => $date, 'jours' => $lus, 'manquants' => $manquants, 'caPro' => round($caPro, 2), 'ca' => round($ca, 2),
            'part' => $ca > 0 ? round(100 * $caPro / $ca, 1) : null, 'ticketsPro' => $tkPro, 'tickets' => $tk, 'panierPro' => $tkPro > 0 ? round($caPro / $tkPro, 2) : null, 'comptes' => $comptes],
        'serie' => $serie, 'appels' => $appels,
        'source' => 'tickets du panel : is_client_b2b, client_name, will_be_invoiced, deferral_payment — un ticket sans client pro est une vente comptoir'];
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
