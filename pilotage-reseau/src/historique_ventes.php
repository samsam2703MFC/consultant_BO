<?php
declare(strict_types=1);

/**
 * L'historique des ventes sur dix-huit mois (14/11/2026, Sam : « l'agent doit avoir accès à tous les 18 mois de
 * data », puis « fais le moissonnage »). C'est ce que l'assistant du dashboard (src/assistant_magasin.php) lit quand
 * une question dépasse sa fiche de faits : les tickets heure par heure et les produits vendus heure par heure, de
 * chaque magasin, jour par jour.
 *
 * Pourquoi une moisson : l'ERP rend sur 18 mois le CA et les tickets de chaque heure (hourly-distribution) et les
 * produits d'une période (product-category-groups) — mesuré le 14/11/2026 sur le 17/05/2025 à Corbais —, mais pas les
 * produits HEURE PAR HEURE. Pour savoir ce qui se vend le matin, il faut lire les tickets un par un : la liste du jour
 * (un appel), puis chaque ticket avec ses lignes (un appel chacun). Environ 300 tickets un samedi à Corbais : pour 18
 * mois et quatre magasins, de l'ordre de 500 000 tickets. Le relevé du dashboard (svP…, src/stats_ventes.php) ne
 * remonte qu'au 01/08/2026 et serait trop lourd sur 18 mois dans ceo_app_setting : l'historique a ses tables,
 * compactes, interrogeables en SQL.
 *
 *   ceo_hist_jour     un jour-magasin lu : tickets, CA, la part des clients pro ; sa présence veut dire « jour lu »
 *   ceo_hist_heure    le même jour, heure par heure
 *   ceo_hist_produit  les produits vendus, heure par heure : quantité, ventes TTC, et la part des clients pro
 *
 * La moisson va d'hier vers le passé, tous les magasins actifs, par passes bornées (un budget de tickets et de
 * temps) : la nuit par bin/historique_moisson.php (cron posé par bin/deploy.sh, toutes les dix minutes de 20 h à
 * 6 h, quand personne ne lit le dashboard), à la demande par POST /historique/ventes/moisson. Un jour clos ne change
 * plus : lu une fois, il est gardé. Un jour à moitié lu ne s'écrit pas : la passe suivante le reprend. Tolérance :
 * quelques tickets qui ne répondent pas (un ticket supprimé répond 404 pour toujours) n'empêchent pas d'écrire le
 * jour ; ils comptent dans les totaux (la liste les porte), pas dans les produits, et `sans_lignes` le dit.
 * GET /historique/ventes dit où elle en est.
 *
 * Le jour en cours n'y est pas : l'assistant le lit en direct (la liste des tickets du jour, gardée cinq minutes, et
 * les produits par le relevé du dashboard, svProduitsJour).
 */

const HV_MOIS = 18;                  // la profondeur de l'historique
const HV_BUDGET_NUIT = 4000;         // tickets lus au plus par passe de nuit
const HV_MINUTES_NUIT = 8;           // minutes au plus par passe de nuit (le cron repasse toutes les dix minutes)
const HV_BUDGET_DEMANDE = 1500;      // une passe forcée depuis l'API
const HV_SECONDES_DEMANDE = 50;
const HV_TTL_AUJ = 300;              // la liste des tickets du jour en cours, gardée cinq minutes
const HV_FRONT = 8;                  // tickets lus en parallèle — comme le relevé du dashboard

/* ---------------------------------------------------------------------------
 * Les tables
 * ------------------------------------------------------------------------- */
function hvSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_hist_jour (
        shop_id INT NOT NULL,
        jour DATE NOT NULL,
        tickets INT NOT NULL,
        ca DECIMAL(12,2) NOT NULL,
        tickets_pro INT NOT NULL,
        ca_pro DECIMAL(12,2) NOT NULL,
        sans_lignes INT NOT NULL DEFAULT 0,
        lu_le DATETIME NOT NULL,
        PRIMARY KEY (shop_id, jour), KEY k_jour (jour)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_hist_heure (
        shop_id INT NOT NULL,
        jour DATE NOT NULL,
        heure TINYINT NOT NULL,
        tickets INT NOT NULL,
        ca DECIMAL(12,2) NOT NULL,
        tickets_pro INT NOT NULL,
        ca_pro DECIMAL(12,2) NOT NULL,
        PRIMARY KEY (shop_id, jour, heure)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_hist_produit (
        shop_id INT NOT NULL,
        jour DATE NOT NULL,
        heure TINYINT NOT NULL,
        produit_id INT NOT NULL,
        portion_id INT NOT NULL DEFAULT 0,
        nom VARCHAR(160) NOT NULL,
        quantite DECIMAL(12,3) NOT NULL,
        ventes DECIMAL(12,2) NOT NULL,
        quantite_pro DECIMAL(12,3) NOT NULL DEFAULT 0,
        ventes_pro DECIMAL(12,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (shop_id, jour, heure, produit_id, portion_id),
        KEY k_produit (produit_id, jour)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le premier jour de l'historique : dix-huit mois avant aujourd'hui. */
function hvDebut(): string
{
    return date('Y-m-d', strtotime('-' . HV_MOIS . ' months'));
}

/* ---------------------------------------------------------------------------
 * La lecture d'un jour — fonctions pures, testées par bin/tests_historique.php
 * ------------------------------------------------------------------------- */
/** L'heure (0-23) d'un horodatage du panel (« 2026-11-14 07:23:11 », heure locale), null s'il n'en a pas. */
function hvHeure(mixed $ts): ?int
{
    return preg_match('/^\d{4}-\d{2}-\d{2}[ T](\d{2}):/', (string) $ts, $m) ? (int) $m[1] : null;
}

/**
 * La liste des tickets d'un jour — {id: [heure|null, total|null, pro]}. L'heure est celle du ticket, le total remise
 * déduite, « pro » un client professionnel (is_client_b2b), que le panier moyen du dashboard met à part.
 */
function hvTickets(mixed $liste): array
{
    $out = [];
    foreach (analyseListe($liste) as $t) {
        if (!is_array($t) || (int) ($t['id'] ?? 0) <= 0) { continue; }
        $tot = $t['total_gross_amount_after_discount'] ?? $t['total_value_after_discount'] ?? null;
        $out[(int) $t['id']] = [hvHeure($t['insert_timestamp'] ?? null), is_numeric($tot) ? round((float) $tot, 2) : null, !empty($t['is_client_b2b'])];
    }
    return $out;
}

/** Les tickets, heure par heure — {h: [tickets, ca, tickets_pro, ca_pro]}. Un ticket sans heure n'y est pas. */
function hvHeuresDe(array $tickets): array
{
    $h = [];
    foreach ($tickets as $t) {
        [$hh, $tot, $pro] = $t;
        if ($hh === null) { continue; }
        $x = $h[$hh] ?? [0, 0.0, 0, 0.0];
        $x[0]++; $x[1] += (float) $tot;
        if ($pro) { $x[2]++; $x[3] += (float) $tot; }
        $h[$hh] = $x;
    }
    ksort($h);
    return array_map(static fn ($x) => [$x[0], round($x[1], 2), $x[2], round($x[3], 2)], $h);
}

/** Les totaux du jour — [tickets, ca, tickets_pro, ca_pro], tickets sans heure compris. */
function hvTotaux(array $tickets): array
{
    $x = [0, 0.0, 0, 0.0];
    foreach ($tickets as [, $tot, $pro]) {
        $x[0]++; $x[1] += (float) $tot;
        if ($pro) { $x[2]++; $x[3] += (float) $tot; }
    }
    return [$x[0], round($x[1], 2), $x[2], round($x[3], 2)];
}

/**
 * Les produits de tickets lus, heure par heure — {« h|pid|portion »: [h, pid, portion, nom, q, ventes, q_pro, ventes_pro]}.
 *
 * $tickets : hvTickets ; $details : {id: le ticket et ses lignes (include=products)} ; $catalogue : svCatalogue. Le
 * nom est celui du catalogue réseau, en français — la caisse de Halle nomme en néerlandais —, la portion ajoutée
 * (« Brownies — 1/4 ») comme dans le relevé du dashboard. Une ligne sans produit (un libellé de caisse) n'est pas un
 * produit. Complète au passage l'heure et le total d'un ticket que la liste ne donnait pas.
 */
function hvProduitsDe(array &$tickets, array $details, array $catalogue): array
{
    $p = [];
    foreach ($tickets as $id => $t) {
        $d = $details[$id] ?? null;
        if (!is_array($d)) { continue; }
        [$hh, $tot, $pro] = $t;
        if ($hh === null) { $hh = hvHeure($d['insert_timestamp'] ?? null); }
        $somme = 0.0;
        foreach ((array) ($d['products'] ?? []) as $l) {
            if (!is_array($l)) { continue; }
            $v = (float) ($l['total_gross_value_after_discount'] ?? 0);
            $somme += $v;
            $pid = (int) ($l['id_product'] ?? 0);
            if ($pid <= 0 || $hh === null) { continue; }
            $q = (float) ($l['quantity'] ?? 0);
            $por = svPortion($l);
            $k = $hh . '|' . $pid . '|' . $por['id'];
            if (!isset($p[$k])) {
                $nom = trim((string) ($catalogue[$pid]['nom'] ?? ''));
                if ($nom === '') { $nom = trim((string) ($l['product_name'] ?? '')) ?: ('Produit ' . $pid); }
                if ($por['id'] > 0) { $nom .= ' — ' . $por['libelle']; }
                $p[$k] = [$hh, $pid, $por['id'], mb_substr($nom, 0, 160), 0.0, 0.0, 0.0, 0.0];
            }
            $p[$k][4] += $q; $p[$k][5] += $v;
            if ($pro) { $p[$k][6] += $q; $p[$k][7] += $v; }
        }
        $tickets[$id] = [$hh, $tot ?? round($somme, 2), $pro];
    }
    foreach ($p as $k => $x) { $p[$k] = [$x[0], $x[1], $x[2], $x[3], round($x[4], 3), round($x[5], 2), round($x[6], 3), round($x[7], 2)]; }
    return $p;
}

/** Combien de tickets peuvent manquer sans empêcher d'écrire le jour : deux, ou un pour cent. */
function hvTolerance(int $n): int
{
    return max(2, intdiv($n, 100));
}

/* ---------------------------------------------------------------------------
 * La moisson
 * ------------------------------------------------------------------------- */
/**
 * Lit et écrit un jour-magasin. « fait », « budget » (le jour attend la passe suivante : il ne tient pas dans ce qui
 * reste) ou « echec » (le panel n'a pas répondu). $cout compte les appels au panel.
 */
function hvLireJour(int $sid, string $j, int &$cout, int $budget): string
{
    $premier = $cout === 0;
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $j);
    $cout++;
    if (!is_array($liste)) { return 'echec'; }
    $tickets = hvTickets($liste);
    $ids = array_keys($tickets);
    // Le premier jour d'une passe se lit quoi qu'il coûte : sans cela, un samedi de 400 tickets ne passerait jamais.
    if (!$premier && $cout + count($ids) > $budget) { return 'budget'; }
    $details = []; $manquants = 0; $tol = hvTolerance(count($ids));
    foreach (array_chunk($ids, 40) as $lot) {
        $chemins = [];
        foreach ($lot as $id) { $chemins[$id] = '/transactions/' . $id . '?include=products'; }
        $res = PanelApi::getParallele($chemins, HV_FRONT);
        $cout += count($lot);
        $encore = [];
        foreach ($lot as $id) { if (is_array($res[$id] ?? null)) { $details[$id] = $res[$id]; } else { $encore[$id] = $chemins[$id]; } }
        if ($encore !== []) {
            // Une seconde chance, à deux de front : le panel lâche quand on le presse.
            $res = PanelApi::getParallele($encore, 2);
            $cout += count($encore);
            foreach (array_keys($encore) as $id) { if (is_array($res[$id] ?? null)) { $details[$id] = $res[$id]; } else { $manquants++; } }
        }
        if ($manquants > $tol) { return 'echec'; }   // un jour à moitié lu ne s'écrit pas
    }
    $produits = hvProduitsDe($tickets, $details, svCatalogue());
    hvEcrire($sid, $j, $tickets, $produits, $manquants);
    return 'fait';
}

/** INSERT de plusieurs lignes, par lots. */
function hvInserer(string $table, array $cols, array $lignes): void
{
    $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    foreach (array_chunk($lignes, 250) as $lot) {
        Db::exec('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ' . implode(',', array_fill(0, count($lot), $place)), array_merge(...$lot));
    }
}

/** Écrit un jour-magasin d'un seul tenant : ses heures, ses produits, puis la ligne du jour qui le dit lu. */
function hvEcrire(int $sid, string $j, array $tickets, array $produits, int $sansLignes = 0): void
{
    hvSchema();
    $lh = [];
    foreach (hvHeuresDe($tickets) as $h => $x) { $lh[] = [$sid, $j, $h, $x[0], $x[1], $x[2], $x[3]]; }
    $lp = [];
    foreach ($produits as $x) { $lp[] = [$sid, $j, $x[0], $x[1], $x[2], $x[3], $x[4], $x[5], $x[6], $x[7]]; }
    $tot = hvTotaux($tickets);
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        Db::exec('DELETE FROM ceo_hist_heure WHERE shop_id = ? AND jour = ?', [$sid, $j]);
        Db::exec('DELETE FROM ceo_hist_produit WHERE shop_id = ? AND jour = ?', [$sid, $j]);
        hvInserer('ceo_hist_heure', ['shop_id', 'jour', 'heure', 'tickets', 'ca', 'tickets_pro', 'ca_pro'], $lh);
        hvInserer('ceo_hist_produit', ['shop_id', 'jour', 'heure', 'produit_id', 'portion_id', 'nom', 'quantite', 'ventes', 'quantite_pro', 'ventes_pro'], $lp);
        Db::exec('INSERT INTO ceo_hist_jour (shop_id, jour, tickets, ca, tickets_pro, ca_pro, sans_lignes, lu_le) VALUES (?,?,?,?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE tickets = VALUES(tickets), ca = VALUES(ca), tickets_pro = VALUES(tickets_pro),
                  ca_pro = VALUES(ca_pro), sans_lignes = VALUES(sans_lignes), lu_le = VALUES(lu_le)',
            [$sid, $j, $tot[0], $tot[1], $tot[2], $tot[3], $sansLignes]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Une passe de moisson : d'hier vers le passé, les jours-magasins pas encore lus, $budget appels au panel et
 * $secondes au plus. Dit ce qu'elle a fait et ce qui reste.
 */
function hvMoisson(int $budget = HV_BUDGET_NUIT, int $secondes = HV_MINUTES_NUIT * 60): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    hvSchema();
    try { $shops = array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id')); }
    catch (Throwable $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $debut = hvDebut();
    $hier = date('Y-m-d', strtotime('-1 day'));
    $lus = [];
    foreach (Db::rows('SELECT shop_id, jour FROM ceo_hist_jour WHERE jour >= ?', [$debut]) as $r) { $lus[(int) $r['shop_id'] . '|' . $r['jour']] = true; }
    $t0 = microtime(true);
    $cout = 0; $faits = 0; $echecs = 0; $restants = 0; $arret = false; $plusAncien = null; $suite = 0;
    // Du plus récent au plus ancien : on interroge d'abord les derniers mois.
    for ($j = $hier; $j >= $debut; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        foreach ($shops as $sid) {
            if (isset($lus[$sid . '|' . $j])) { continue; }
            if (!$arret && ($cout >= $budget || microtime(true) - $t0 > $secondes)) { $arret = true; }
            if ($arret) { $restants++; continue; }
            $etat = hvLireJour($sid, $j, $cout, $budget);
            if ($etat === 'fait') { $faits++; $plusAncien = $j; $suite = 0; continue; }
            $restants++;
            if ($etat === 'budget') { $arret = true; continue; }   // la suite attend la passe suivante
            $echecs++;
            // Cinq échecs de suite : le panel ne répond plus, inutile de l'appeler jour après jour.
            if (++$suite >= 5) { $arret = true; }
        }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'appels' => $cout, 'echecs' => $echecs, 'joursRestants' => $restants,
        'plusAncienLu' => $plusAncien, 'secondes' => round(microtime(true) - $t0, 1),
        'etat' => $restants === 0 ? 'à jour sur ' . HV_MOIS . ' mois' : ($suite >= 5 ? 'arrêtée : le panel ne répond plus, ' : '') . $restants . ' jour(s)-magasin restants'];
}

/** GET /historique/ventes — où en est l'historique, magasin par magasin. */
function ep_historique_etat(): array
{
    hvSchema();
    $debut = hvDebut();
    $hier = date('Y-m-d', strtotime('-1 day'));
    $attendus = (int) round((strtotime($hier) - strtotime($debut)) / 86400) + 1;
    $mags = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY id') as $s) {
        $mags[(int) $s['id']] = ['magasin' => (string) $s['name'], 'joursLus' => 0, 'depuis' => null, 'jusqua' => null, 'tickets' => 0, 'ticketsSansLignes' => 0];
    }
    foreach (Db::rows('SELECT shop_id, COUNT(*) n, MIN(jour) du, MAX(jour) au, SUM(tickets) t, SUM(sans_lignes) s
                       FROM ceo_hist_jour WHERE jour >= ? GROUP BY shop_id', [$debut]) as $r) {
        $sid = (int) $r['shop_id'];
        if (!isset($mags[$sid])) { continue; }
        $mags[$sid] = array_merge($mags[$sid], ['joursLus' => (int) $r['n'], 'depuis' => $r['du'], 'jusqua' => $r['au'],
            'tickets' => (int) $r['t'], 'ticketsSansLignes' => (int) $r['s']]);
    }
    $lus = array_sum(array_column($mags, 'joursLus'));
    $total = $attendus * max(1, count($mags));
    return ['depuis' => $debut, 'jusqua' => $hier, 'joursParMagasin' => $attendus, 'magasins' => array_values($mags),
        'avancement' => round(100 * $lus / $total, 1), 'complet' => $lus >= $total];
}

/** POST /historique/ventes/moisson {budget?} — forcer une passe (bornée), voir l'état. */
function wr_historique_moisson(): array
{
    $b = body();
    $budget = max(50, min(HV_BUDGET_DEMANDE, (int) ($b['budget'] ?? HV_BUDGET_DEMANDE)));
    return hvMoisson($budget, HV_SECONDES_DEMANDE);
}

/* ---------------------------------------------------------------------------
 * La lecture, pour l'assistant
 * ------------------------------------------------------------------------- */
/** Le jour en cours d'un magasin, en direct : la liste de ses tickets (un appel), gardée cinq minutes. Null si illisible. */
function hvAujourdhui(int $sid): ?array
{
    $auj = date('Y-m-d');
    $cle = 'hvAuj' . $sid;
    $c = setting($cle);
    $garde = is_array($c) && ($c['jour'] ?? '') === $auj && is_array($c['t'] ?? null) ? $c['t'] : null;
    if ($garde !== null && (int) ($c['quand'] ?? 0) > time() - HV_TTL_AUJ) { return $garde; }
    $liste = PanelApi::get('/shops/' . $sid . '/transactions?date=' . $auj);
    if (!is_array($liste)) { return $garde; }
    $t = hvTickets($liste);
    try { Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['jour' => $auj, 'quand' => time(), 't' => $t])]); }
    catch (Throwable $e) { /* sans mémoire : on relira */ }
    return $t;
}

/** Le jour $j est-il gardé par le filtre des jours de semaine (1 = lundi … 7 = dimanche ; vide = tous) ? */
function hvJourGarde(string $j, array $jours): bool
{
    return $jours === [] || in_array((int) date('N', strtotime($j)), $jours, true);
}

/** Le nombre de jours de [$du, $au] que garde le filtre ; zéro si la période est vide. */
function hvJoursAttendus(string $du, string $au, array $jours): int
{
    $n = 0;
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { if (hvJourGarde($j, $jours)) { $n++; } }
    return $n;
}

/** La clause WHERE commune : les magasins, la période, les jours de semaine. [sql, paramètres]. */
function hvFiltre(array $shops, string $du, string $au, array $jours): array
{
    $w = 'shop_id IN (' . implode(',', array_fill(0, count($shops), '?')) . ') AND jour BETWEEN ? AND ?';
    $a = array_merge(array_map('intval', $shops), [$du, $au]);
    if ($jours !== []) {
        $w .= ' AND WEEKDAY(jour) + 1 IN (' . implode(',', array_fill(0, count($jours), '?')) . ')';
        $a = array_merge($a, $jours);
    }
    return [$w, $a];
}

/** Ce que l'historique a lu de la période : jours-magasins lus, ouverts (au moins un ticket), attendus. */
function hvCouverture(array $shops, string $du, string $au, array $jours): array
{
    $hier = date('Y-m-d', strtotime('-1 day'));
    $fin = min($au, $hier);
    if ($fin < $du) { return ['lus' => 0, 'ouverts' => 0, 'attendus' => 0]; }
    [$w, $a] = hvFiltre($shops, $du, $fin, $jours);
    $r = Db::row('SELECT COUNT(*) n, COALESCE(SUM(tickets > 0), 0) o FROM ceo_hist_jour WHERE ' . $w, $a) ?? ['n' => 0, 'o' => 0];
    return ['lus' => (int) $r['n'], 'ouverts' => (int) $r['o'], 'attendus' => hvJoursAttendus($du, $fin, $jours) * count($shops)];
}

/**
 * Les heures d'une période, sommées sur les jours et les magasins demandés — {heures: {h: [tickets, ca, tickets_pro,
 * ca_pro]}, couverture, aujourdhui}. $jours : jours de semaine gardés (1 = lundi … 7 = dimanche ; vide = tous) ;
 * $hDebut comprise, $hFin non (« avant 8 h » = fin 8). Le passé vient de l'historique ; aujourd'hui, s'il est dans la
 * période, de la liste du jour en direct.
 */
function hvHeures(array $shops, string $du, string $au, array $jours = [], ?int $hDebut = null, ?int $hFin = null): array
{
    hvSchema();
    $auj = date('Y-m-d');
    $hier = date('Y-m-d', strtotime('-1 day'));
    $h = [];
    if (min($au, $hier) >= $du) {
        [$w, $a] = hvFiltre($shops, $du, min($au, $hier), $jours);
        if ($hDebut !== null) { $w .= ' AND heure >= ?'; $a[] = $hDebut; }
        if ($hFin !== null) { $w .= ' AND heure < ?'; $a[] = $hFin; }
        foreach (Db::rows('SELECT heure, SUM(tickets) t, SUM(ca) ca, SUM(tickets_pro) tp, SUM(ca_pro) cp FROM ceo_hist_heure WHERE ' . $w . ' GROUP BY heure', $a) as $r) {
            $h[(int) $r['heure']] = [(int) $r['t'], (float) $r['ca'], (int) $r['tp'], (float) $r['cp']];
        }
    }
    $direct = null;
    if ($du <= $auj && $au >= $auj && hvJourGarde($auj, $jours)) {
        $direct = ['lus' => 0, 'illisibles' => 0, 'ouverts' => 0];
        foreach ($shops as $sid) {
            $t = hvAujourdhui((int) $sid);
            if ($t === null) { $direct['illisibles']++; continue; }
            $direct['lus']++;
            if ($t !== []) { $direct['ouverts']++; }
            foreach (hvHeuresDe($t) as $hh => $x) {
                if (($hDebut !== null && $hh < $hDebut) || ($hFin !== null && $hh >= $hFin)) { continue; }
                $y = $h[$hh] ?? [0, 0.0, 0, 0.0];
                $h[$hh] = [$y[0] + $x[0], round($y[1] + $x[1], 2), $y[2] + $x[2], round($y[3] + $x[3], 2)];
            }
        }
    }
    ksort($h);
    return ['heures' => $h, 'couverture' => hvCouverture($shops, $du, $au, $jours), 'aujourdhui' => $direct];
}

/**
 * Les produits d'une période, sommés sur les jours, les heures et les magasins demandés — {produits: {« pid:portion »:
 * [pid, portion, nom, q, ventes, q_pro, ventes_pro]}, couverture, aujourdhui}. Mêmes filtres que hvHeures. Aujourd'hui
 * vient du relevé du dashboard (svProduitsJour, qui ne lit que les tickets nouveaux) : la part pro n'y est pas séparée.
 */
function hvProduits(array $shops, string $du, string $au, array $jours = [], ?int $hDebut = null, ?int $hFin = null): array
{
    hvSchema();
    $auj = date('Y-m-d');
    $hier = date('Y-m-d', strtotime('-1 day'));
    $p = [];
    if (min($au, $hier) >= $du) {
        [$w, $a] = hvFiltre($shops, $du, min($au, $hier), $jours);
        if ($hDebut !== null) { $w .= ' AND heure >= ?'; $a[] = $hDebut; }
        if ($hFin !== null) { $w .= ' AND heure < ?'; $a[] = $hFin; }
        foreach (Db::rows('SELECT produit_id, portion_id, MAX(nom) nom, SUM(quantite) q, SUM(ventes) v, SUM(quantite_pro) qp, SUM(ventes_pro) vp
                           FROM ceo_hist_produit WHERE ' . $w . ' GROUP BY produit_id, portion_id', $a) as $r) {
            $p[(int) $r['produit_id'] . ':' . (int) $r['portion_id']] = [(int) $r['produit_id'], (int) $r['portion_id'], (string) $r['nom'],
                (float) $r['q'], (float) $r['v'], (float) $r['qp'], (float) $r['vp']];
        }
    }
    $direct = null;
    if ($du <= $auj && $au >= $auj && hvJourGarde($auj, $jours) && function_exists('svProduitsJour')) {
        $direct = ['lus' => 0, 'illisibles' => 0];
        foreach ($shops as $sid) {
            $cout = 0;
            $pj = svProduitsJour((int) $sid, $auj, $cout, 400);
            if (!is_array($pj)) { $direct['illisibles']++; continue; }
            $direct['lus']++;
            foreach ($pj as $hh => $lst) {
                $hh = (int) $hh;
                if (($hDebut !== null && $hh < $hDebut) || ($hFin !== null && $hh >= $hFin)) { continue; }
                foreach ((array) $lst as $k => $x) {
                    [$pid, $por] = array_map('intval', explode(':', (string) $k) + [1 => 0]);
                    $cle = $pid . ':' . $por;
                    $y = $p[$cle] ?? [$pid, $por, (string) ($x[0] ?? ('Produit ' . $pid)), 0.0, 0.0, 0.0, 0.0];
                    $y[3] += (float) ($x[1] ?? 0); $y[4] += (float) ($x[2] ?? 0);
                    $p[$cle] = $y;
                }
            }
        }
    }
    return ['produits' => $p, 'couverture' => hvCouverture($shops, $du, $au, $jours), 'aujourdhui' => $direct];
}
