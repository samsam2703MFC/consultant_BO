<?php
declare(strict_types=1);

/**
 * La Récurrence des six leviers : le taux de retour des clients PWA (23/10/2026, demande de Sam : « mets la
 * récurrence avec le taux de retour des clients PWA »).
 *
 * La mesure, celle du panel consultant (« taux de fidélisation : clients avec ≥ 2 visites ÷ total clients, sur la
 * période ») restreinte aux clients de l'app : parmi les clients PWA identifiés à la caisse d'un magasin sur la
 * période, la part venus au moins deux jours différents. Un passage = un jour avec au moins un ticket ; deux tickets
 * du même matin ne font pas un retour.
 *
 * Les sources, celles du panel :
 *   - les listes de tickets du jour (`/shops/{id}/transactions?date=`) : chaque ticket porte `id_client` quand le
 *     client est identifié (app scannée, commande) et `is_client_b2b` — les tickets pro ne comptent pas ;
 *   - la fiche du client (`/shops/{id}/clients/{id}`) : `pwa_user` dit s'il a un compte dans l'app, `fidelity_active`
 *     et `member_since` sont gardés à côté.
 * La copie de la base du panel s'arrête au 14/07/2026 (/audit/fraicheur) : rien n'est lu dans `transaction` ni `client`.
 *
 * Ce qui est gardé : l'identifiant du client, jamais son nom — les jours où il est passé dans un magasin
 * (`ceo_pwa_visite`), les jours relevés (`ceo_pwa_jour`), et son statut dans l'app (`ceo_pwa_client`). Rien ne sort
 * que des comptes.
 *
 * Le relevé avance au cron, du plus récent au plus ancien jusqu'au 1er janvier, un lot par battement ; une liste lue
 * pour le pro du dashboard (vpGraveListe) se relève au passage. Une période se mesure quand tous ses jours sont relevés
 * (le jour en cours compte s'il est lu) : avant, elle le dit (« lecture : 12 / 22 jours »).
 */

const RP_DEBUT = '2026-01-01';      // premier jour relevé : le cumul de l'année
const RP_BUDGET_CRON = 80;          // listes de tickets relues par battement du cron
const RP_TEMPS_CRON = 120;          // secondes au plus par battement
const RP_FICHES_CRON = 120;         // fiches clients lues par battement
const RP_BUDGET_DEMANDE = 8;        // listes lues pendant une requête (le jour en cours, un jour manquant du mois)
const RP_FICHES_DEMANDE = 24;       // fiches lues pendant une requête
const RP_TTL_JOUR = 600;            // le jour en cours se relit après dix minutes
const RP_TTL_PWA = 30;              // jours : un client PWA reste PWA, sa fiche se relit au mois
const RP_TTL_AUTRE = 7;             // jours : un client sans app peut l'installer, sa fiche se relit à la semaine
const RP_ESSAIS = 3;                // une liste illisible trois fois de suite n'est plus demandée (le jour reste non relevé)

/** Les trois tables du relevé, créées une fois par requête. */
function rpSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_pwa_jour ('
        . 'shop_id INT NOT NULL, jour DATE NOT NULL,'
        . 'tickets INT NOT NULL DEFAULT 0,'           // tickets du jour
        . 'identifies INT NOT NULL DEFAULT 0,'        // tickets non pro rattachés à un client
        . 'lu_le DATETIME NULL, essais TINYINT NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (shop_id, jour)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_pwa_visite ('
        . 'shop_id INT NOT NULL, jour DATE NOT NULL, client_id BIGINT NOT NULL,'
        . 'tickets SMALLINT NOT NULL DEFAULT 1,'
        . 'PRIMARY KEY (shop_id, jour, client_id), KEY k_client (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_pwa_client ('
        . 'client_id BIGINT NOT NULL PRIMARY KEY,'
        . 'pwa TINYINT NULL,'                         // pwa_user de la fiche ; NULL : fiche lue sans le champ
        . 'fidelite TINYINT NULL, membre_depuis DATE NULL,'
        . 'lu_le DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Les clients identifiés d'une liste de tickets du jour : [id_client => tickets], hors tickets pro ; et les comptes. */
function rpDuListe(array $liste): array
{
    $c = []; $n = 0; $id = 0;
    foreach (analyseListe($liste) as $x) {
        if (!is_array($x)) { continue; }
        $n++;
        if (!empty($x['is_client_b2b'])) { continue; }
        $k = $x['id_client'] ?? null;
        if (!is_scalar($k) || !ctype_digit((string) $k) || (int) $k <= 0) { continue; }
        $id++;
        $c[(int) $k] = ($c[(int) $k] ?? 0) + 1;
    }
    return ['tickets' => $n, 'identifies' => $id, 'clients' => $c];
}

/** Grave le relevé d'un jour depuis sa liste de tickets déjà lue (le pro du dashboard l'appelle aussi). */
function rpGraveListe(int $sid, string $j, array $liste): void
{
    rpSchema();
    $r = rpDuListe($liste);
    Db::exec('DELETE FROM ceo_pwa_visite WHERE shop_id = ? AND jour = ?', [$sid, $j]);
    foreach (array_chunk($r['clients'], 200, true) as $lot) {
        $v = []; $p = [];
        foreach ($lot as $cid => $tk) { $v[] = '(?,?,?,?)'; array_push($p, $sid, $j, $cid, min(32000, $tk)); }
        Db::exec('INSERT INTO ceo_pwa_visite (shop_id, jour, client_id, tickets) VALUES ' . implode(',', $v), $p);
    }
    Db::exec('INSERT INTO ceo_pwa_jour (shop_id, jour, tickets, identifies, lu_le, essais) VALUES (?,?,?,?,NOW(),0)
              ON DUPLICATE KEY UPDATE tickets = VALUES(tickets), identifies = VALUES(identifies), lu_le = VALUES(lu_le), essais = 0',
        [$sid, $j, $r['tickets'], $r['identifies']]);
}

/** Un jour relevé vaut-il encore ? Clos s'il a été lu le lendemain ou après ; le jour en cours, dix minutes. */
function rpJourValide(?array $r, string $j): bool
{
    if ($r === null || $r['lu_le'] === null) { return false; }
    $lu = strtotime((string) $r['lu_le']);
    return date('Y-m-d', $lu) > $j || $lu > time() - RP_TTL_JOUR;
}

/**
 * Relit les listes de tickets des jours-magasins donnés ([[shop, jour], …]), quatre de front. Une liste illisible
 * compte un essai ; rend le nombre de jours relevés.
 */
function rpLireJours(array $paires): int
{
    if ($paires === [] || !class_exists('PanelApi') || !PanelApi::configured()) { return 0; }
    rpSchema();
    $chemins = [];
    foreach ($paires as $i => [$sid, $j]) { $chemins[$i] = '/shops/' . (int) $sid . '/transactions?date=' . $j; }
    $res = PanelApi::getParallele($chemins, 4);
    $ok = 0;
    foreach ($paires as $i => [$sid, $j]) {
        $l = $res[$i] ?? null;
        if (is_array($l)) { rpGraveListe((int) $sid, $j, $l); $ok++; continue; }
        Db::exec('INSERT INTO ceo_pwa_jour (shop_id, jour, essais) VALUES (?,?,1) ON DUPLICATE KEY UPDATE essais = essais + 1', [(int) $sid, $j]);
    }
    return $ok;
}

/** Les jours-magasins à relever entre deux dates (du plus récent au plus ancien), au plus $n. */
function rpAManquer(array $sids, string $du, string $au, int $n): array
{
    rpSchema();
    $auj = date('Y-m-d');
    if ($au > $auj) { $au = $auj; }
    if ($du < RP_DEBUT) { $du = RP_DEBUT; }
    if ($du > $au || $n <= 0) { return []; }
    $lus = [];
    foreach (Db::rows('SELECT shop_id, jour, lu_le, essais FROM ceo_pwa_jour WHERE jour BETWEEN ? AND ?', [$du, $au]) as $r) {
        $lus[(int) $r['shop_id'] . '|' . $r['jour']] = $r;
    }
    $out = [];
    for ($j = $au; $j >= $du; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        foreach ($sids as $sid) {
            $r = $lus[(int) $sid . '|' . $j] ?? null;
            if (rpJourValide($r, $j) || ($r !== null && (int) $r['essais'] >= RP_ESSAIS)) { continue; }
            $out[] = [(int) $sid, $j];
            if (count($out) >= $n) { return $out; }
        }
    }
    return $out;
}

/** Le statut d'une fiche client du panel : pwa_user, fidelity_active, member_since — rien d'autre n'est lu. */
function rpLitFiche(mixed $r): ?array
{
    if (!is_array($r)) { return null; }
    $f0 = isset($r['data']) && is_array($r['data']) && !array_is_list($r['data']) ? $r['data'] : $r;
    $f = [];
    foreach ($f0 as $k => $v) {
        if (is_array($v) && $v !== [] && !array_is_list($v)) { foreach ($v as $k2 => $v2) { if (!is_array($v2)) { $f[(string) $k2] ??= $v2; } } }
        elseif (!is_array($v)) { $f[(string) $k] = $v; }
    }
    if ($f === []) { return null; }
    $drapeau = static fn ($v): ?int => $v === null || $v === '' ? null : ((int) filter_var($v, FILTER_VALIDATE_BOOLEAN) === 1 || (string) $v === '1' ? 1 : 0);
    $membre = isset($f['member_since']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $f['member_since']) ? substr((string) $f['member_since'], 0, 10) : null;
    return ['pwa' => array_key_exists('pwa_user', $f) ? $drapeau($f['pwa_user']) : null,
        'fidelite' => array_key_exists('fidelity_active', $f) ? $drapeau($f['fidelity_active']) : null, 'membre' => $membre];
}

/** Lit les fiches des clients sans statut frais ([client => magasin où il est passé]), quatre de front ; rend le nombre lu. */
function rpLireFiches(array $clients): int
{
    if ($clients === [] || !class_exists('PanelApi') || !PanelApi::configured()) { return 0; }
    $chemins = [];
    foreach ($clients as $cid => $sid) { $chemins[$cid] = '/shops/' . (int) $sid . '/clients/' . (int) $cid; }
    $res = PanelApi::getParallele($chemins, 4);
    $ok = 0;
    foreach ($clients as $cid => $sid) {
        $f = rpLitFiche($res[$cid] ?? null);
        if ($f === null) { continue; }
        Db::exec('INSERT INTO ceo_pwa_client (client_id, pwa, fidelite, membre_depuis, lu_le) VALUES (?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE pwa = VALUES(pwa), fidelite = VALUES(fidelite), membre_depuis = VALUES(membre_depuis), lu_le = VALUES(lu_le)',
            [(int) $cid, $f['pwa'], $f['fidelite'], $f['membre']]);
        $ok++;
    }
    return $ok;
}

/** Les clients passés entre deux dates dont la fiche manque ou a vieilli : [client => magasin], les plus récents d'abord. */
function rpFichesAManquer(string $du, string $au, int $n): array
{
    rpSchema();
    if ($n <= 0) { return []; }
    $out = [];
    foreach (Db::rows('SELECT v.client_id, MAX(v.shop_id) shop, MAX(v.jour) dernier
                         FROM ceo_pwa_visite v LEFT JOIN ceo_pwa_client c ON c.client_id = v.client_id
                        WHERE v.jour BETWEEN ? AND ?
                          AND (c.client_id IS NULL
                               OR (c.pwa = 1 AND c.lu_le < NOW() - INTERVAL ' . RP_TTL_PWA . ' DAY)
                               OR ((c.pwa IS NULL OR c.pwa = 0) AND c.lu_le < NOW() - INTERVAL ' . RP_TTL_AUTRE . ' DAY))
                        GROUP BY v.client_id ORDER BY dernier DESC LIMIT ' . (int) $n, [$du, $au]) as $r) {
        $out[(int) $r['client_id']] = (int) $r['shop'];
    }
    return $out;
}

/** Les magasins ouverts du panel (la table partagée), comme la moisson des tickets. */
function rpMagasins(): array
{
    try { return array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return []; }
}

/** Au cron : relever les jours manquants (du plus récent au 1er janvier), puis les fiches des clients vus. */
function rpMoisson(int $budget = RP_BUDGET_CRON): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    rpSchema();
    $sids = rpMagasins();
    if ($sids === []) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $t0 = microtime(true); $lus = 0; $fiches = 0;
    $hier = date('Y-m-d', strtotime('-1 day'));
    while ($lus < $budget && microtime(true) - $t0 < RP_TEMPS_CRON) {
        $lot = rpAManquer($sids, RP_DEBUT, $hier, min(12, $budget - $lus));
        if ($lot === []) { break; }
        $n = rpLireJours($lot);
        $lus += count($lot);
        if ($n === 0) { break; }   // le panel ne répond plus : le prochain battement reprendra
    }
    while ($fiches < RP_FICHES_CRON && microtime(true) - $t0 < RP_TEMPS_CRON) {
        $lot = rpFichesAManquer(RP_DEBUT, date('Y-m-d'), min(24, RP_FICHES_CRON - $fiches));
        if ($lot === []) { break; }
        $n = rpLireFiches($lot);
        $fiches += count($lot);
        if ($n === 0) { break; }
    }
    $reste = count(rpAManquer($sids, RP_DEBUT, $hier, 100000));
    return ['ok' => true, 'listes' => $lus, 'fiches' => $fiches, 'joursRestants' => $reste];
}

/** Le battement horaire (rapports/cron) : une phrase pour le journal du cron. */
function rpCron(): string
{
    $r = rpMoisson();
    return $r['ok'] ? $r['listes'] . ' liste(s), ' . $r['fiches'] . ' fiche(s) ; ' . ($r['joursRestants'] === 0 ? 'à jour' : $r['joursRestants'] . ' jour(s)-magasin restants')
        : 'échec : ' . ($r['motif'] ?? '?');
}

/**
 * Avant de mesurer une période pendant une requête : le jour en cours et quelques jours manquants se relisent, puis les
 * fiches manquantes de la période — dans un budget. Le reste vient du cron.
 */
function rpCompleter(array $sids, string $du, string $au): void
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return; }
    try {
        rpLireJours(rpAManquer($sids, $du, $au, RP_BUDGET_DEMANDE));
        rpLireFiches(rpFichesAManquer($du, $au, RP_FICHES_DEMANDE));
    } catch (Throwable $e) { /* une lecture manquée : la période dira ce qui manque */ }
}

/**
 * Le taux de retour des clients PWA sur [du, au], par magasin et pour le réseau, mois par mois si `$parMois`.
 *
 * Rend, par magasin (et `reseau`) : `v` le taux (%) — null tant que la période n'est pas entièrement relevée ou sans
 * client PWA —, `pwa` les clients PWA identifiés, `revenus` ceux venus au moins deux jours, `identifies` tous les
 * clients identifiés (non pro), `inconnus` ceux dont la fiche n'est pas encore lue, `ticketsPwa` et `tickets` (la
 * part des tickets faite par les clients PWA), `joursLus` / `jours` (la couverture ; le jour en cours compte s'il est lu).
 */
function rpTaux(array $sids, string $du, string $au): array
{
    rpSchema();
    $auj = date('Y-m-d');
    if ($au > $auj) { $au = $auj; }
    $vide = static fn (): array => ['v' => null, 'pwa' => 0, 'revenus' => 0, 'identifies' => 0, 'inconnus' => 0, 'ticketsPwa' => 0, 'tickets' => 0, 'joursLus' => 0, 'jours' => 0, 'complet' => false];
    $out = ['du' => $du, 'au' => $au, 'magasins' => [], 'reseau' => $vide()];
    foreach ($sids as $sid) { $out['magasins'][(int) $sid] = $vide(); }
    if ($du > $au) { return $out; }
    // la couverture : les jours clos attendus (jusqu'à hier), le jour en cours s'il est lu ; avant le 1er relevé, jamais complet
    $dDu = max($du, RP_DEBUT); $dAu = min($au, date('Y-m-d', strtotime($auj . ' -1 day')));
    $clos = $dDu <= $dAu ? (int) round((strtotime($dAu . ' 12:00') - strtotime($dDu . ' 12:00')) / 86400) + 1 : 0;
    $avantDebut = $du < RP_DEBUT;
    foreach (Db::rows('SELECT shop_id, COUNT(*) n, SUM(jour = ?) auj, SUM(tickets) tk FROM ceo_pwa_jour
                        WHERE jour BETWEEN ? AND ? AND lu_le IS NOT NULL GROUP BY shop_id', [$auj, $du, $au]) as $r) {
        $sid = (int) $r['shop_id'];
        if (!isset($out['magasins'][$sid])) { continue; }
        $x = &$out['magasins'][$sid];
        $x['joursLus'] = (int) $r['n']; $x['jours'] = $clos + ((int) $r['auj'] > 0 ? 1 : 0); $x['tickets'] = (int) $r['tk'];
        $x['complet'] = !$avantDebut && $x['joursLus'] >= $x['jours'];
        unset($x);
    }
    foreach ($out['magasins'] as $sid => $x) { if ($x['jours'] === 0) { $out['magasins'][$sid]['jours'] = $clos; } }
    // les passages : un jour avec au moins un ticket, par client et par magasin
    $parClient = [];
    foreach (Db::rows('SELECT v.shop_id, v.client_id, COUNT(*) passages, SUM(v.tickets) tk, c.pwa, c.client_id lu
                         FROM ceo_pwa_visite v LEFT JOIN ceo_pwa_client c ON c.client_id = v.client_id
                        WHERE v.jour BETWEEN ? AND ? GROUP BY v.shop_id, v.client_id', [$du, $au]) as $r) {
        $sid = (int) $r['shop_id'];
        if (!isset($out['magasins'][$sid])) { continue; }
        $x = &$out['magasins'][$sid];
        $x['identifies']++;
        if ($r['lu'] === null) { $x['inconnus']++; }
        if ((int) $r['pwa'] === 1 && $r['pwa'] !== null) {
            $x['pwa']++; $x['ticketsPwa'] += (int) $r['tk'];
            if ((int) $r['passages'] >= 2) { $x['revenus']++; }
            $parClient[(int) $r['client_id']] = true;
        }
        unset($x);
    }
    // le réseau : un client PWA compte une fois, ses jours de passage dans tous les magasins
    $res = $vide();
    if ($parClient !== []) {
        $jours = [];
        $in = implode(',', array_map('intval', array_keys($out['magasins'])));
        foreach (Db::rows('SELECT DISTINCT v.client_id, v.jour FROM ceo_pwa_visite v JOIN ceo_pwa_client c ON c.client_id = v.client_id AND c.pwa = 1
                            WHERE v.jour BETWEEN ? AND ? AND v.shop_id IN (' . $in . ')', [$du, $au]) as $r) {
            $jours[(int) $r['client_id']] = ($jours[(int) $r['client_id']] ?? 0) + 1;
        }
        $res['pwa'] = count($jours);
        $res['revenus'] = count(array_filter($jours, static fn ($n) => $n >= 2));
    }
    $complet = true;
    foreach ($out['magasins'] as $sid => $x) {
        $out['magasins'][$sid]['v'] = $x['complet'] && $x['pwa'] > 0 ? round(100 * $x['revenus'] / $x['pwa'], 1) : null;
        $res['identifies'] += $x['identifies']; $res['inconnus'] += $x['inconnus']; $res['ticketsPwa'] += $x['ticketsPwa'];
        $res['tickets'] += $x['tickets']; $res['joursLus'] += $x['joursLus']; $res['jours'] += $x['jours'];
        $complet = $complet && $x['complet'];
    }
    $res['complet'] = $complet;
    $res['v'] = $complet && $res['pwa'] > 0 ? round(100 * $res['revenus'] / $res['pwa'], 1) : null;
    $out['reseau'] = $res;
    return $out;
}

/** Le taux mois par mois sur des mois donnés ('AAAA-MM') : [shop => [v|null, …]] dans l'ordre des mois. */
function rpSerie(array $sids, array $mois): array
{
    $out = [];
    foreach ($sids as $sid) { $out[(int) $sid] = []; }
    foreach ($mois as $ym) {
        $fin = date('Y-m-t', strtotime($ym . '-01'));
        $t = $ym . '-01' > date('Y-m-d') ? null : rpTaux($sids, $ym . '-01', $fin);
        foreach ($sids as $sid) { $out[(int) $sid][] = $t['magasins'][(int) $sid]['v'] ?? null; }
    }
    return $out;
}

/** Le KPI de la Table KPI qui porte les seuils de la Récurrence : le KPI dérivé clients PWA revenus ÷ clients PWA, s'il existe. */
function rpSeuilKpi(): ?array
{
    try {
        foreach (Db::rows('SELECT code, calcul, seuil_alerte, seuil_critique, sens FROM ceo_kpi_def WHERE actif = 1 ORDER BY ordre, id') as $r) {
            $c = json_decode((string) $r['calcul'], true);
            if (!is_array($c) || ($c['num'] ?? '') !== 'pwaRevenus' || ($c['den'] ?? '') !== 'pwaClients') { continue; }
            $pct = ($c['echelle'] ?? '') === 'pct';
            // un KPI à l'échelle « unité » garde des seuils en fraction (0,30) : ramenés en % comme le taux
            $f = static fn ($v) => $v === null ? null : (float) $v * ($pct ? 1 : 100);
            return ['code' => (string) $r['code'], 'alerte' => $f($r['seuil_alerte']), 'critique' => $f($r['seuil_critique']), 'sens' => (string) $r['sens']];
        }
    } catch (PDOException $e) { /* Table KPI absente */ }
    return null;
}
