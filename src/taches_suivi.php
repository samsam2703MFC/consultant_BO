<?php
declare(strict_types=1);

/**
 * Suivi mensuel des tâches — la heatmap magasin × mois de l'écran Contrôle
 * des tâches : combien de tâches FAITES, combien PAS FAITES, mois par mois.
 *
 * « Faite » = rendue dans le panel ce jour-là : notée par un consultant, ou
 * photographiée, ou au statut DONE. « Pas faite » = attendue et restée sans
 * rendu (nonRendue). La note du contrôle est une autre affaire — ici on
 * mesure si le travail est fait.
 *
 * L'API du panel coûte ~1 seconde par journée lue : impossible de relire un
 * an à chaque affichage. Chaque JOURNÉE RÉVOLUE est donc relevée UNE fois
 * dans `ceo_tache_jour` (une ligne par tâche × magasin × jour), et l'écran ne
 * lit que cette table. Le relevé avance tout seul : à chaque passage du cron
 * horaire des rapports, la veille d'abord, puis l'historique à reculons par
 * lots — en quelques heures, les douze mois sont là. La couverture s'affiche
 * à l'écran plutôt que de laisser croire qu'un mois à moitié relevé est un
 * mois faible.
 */

const TACHES_SUIVI_BUDGET_S = 20;   // budget d'un lot de relevés (cron ou bouton)
const TACHES_SUIVI_PROFONDEUR = 380; // jours d'historique visés (12 mois + marge)

const TACHES_SUIVI_SCHEMA = 1;

function tachesSuiviTables(): void
{
    // Le DDL une seule fois — jamais à chaque requête (verrou de métadonnées).
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    if ((int) setting('tachesSuiviSchema', 0) >= TACHES_SUIVI_SCHEMA) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_tache_jour ('
        . 'jour DATE NOT NULL,'
        . 'id_shop INT NOT NULL,'
        . 'id_task INT NOT NULL,'
        . 'nom VARCHAR(200) NOT NULL DEFAULT "",'
        . 'fait TINYINT NOT NULL DEFAULT 0,'
        . 'statut VARCHAR(12) NOT NULL DEFAULT "",'
        . 'note TINYINT NULL,'          // 1..5, la cote posée par le panel — NULL = pas notée
        . 'commentaire VARCHAR(500) NULL,'
        . 'PRIMARY KEY (jour, id_shop, id_task),'
        . 'KEY idx_tj_shop (id_shop, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    tachesJourEnsureNote();
    // Le journal des jours relevés : « zéro tâche » et « jamais relevé » sont
    // deux états différents, cette table les sépare.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_tache_jour_etat ('
        . 'jour DATE PRIMARY KEY,'
        . 'releve_le DATETIME NOT NULL,'
        . 'nb INT NOT NULL DEFAULT 0'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['tachesSuiviSchema', json_encode(TACHES_SUIVI_SCHEMA)]);
}

/**
 * Migration d'une base déjà en service : les colonnes note/commentaire
 * n'existaient pas avant — sans elles, le contrôle des tâches du dossier
 * d'analyse ne peut ni compter les 5/5 ni lister les non-conformes.
 * Idempotent, appelée à chaque `tachesSuiviTables()`.
 */
function tachesJourEnsureNote(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    $r = Db::row("SELECT COUNT(*) AS n FROM information_schema.columns"
        . " WHERE table_schema = DATABASE() AND table_name = 'ceo_tache_jour' AND column_name = 'note'");
    if ((int) ($r['n'] ?? 0) === 0) {
        Db::exec('ALTER TABLE ceo_tache_jour ADD COLUMN note TINYINT NULL, ADD COLUMN commentaire VARCHAR(500) NULL');
    }
}

/**
 * Relève UNE journée depuis le panel (via ep_pwa_tasks, le même chemin que
 * l'écran du jour) et l'écrit dans le cache. Retourne le nombre de lignes,
 * ou null si le panel n'a pas répondu — le jour reste alors à relever.
 */
function tachesJourReleve(string $date): ?int
{
    tachesSuiviTables();
    if (!PanelApi::configured()) { return null; }
    $d = rapAppel('ep_pwa_tasks', ['date' => $date]);
    if (!is_array($d) || !empty($d['indispo'])) { return null; }
    Db::exec('DELETE FROM ceo_tache_jour WHERE jour = ?', [$date]);
    $n = 0;
    foreach (($d['shops'] ?? []) as $s) {
        foreach (($s['taches'] ?? []) as $t) {
            $st = (string) ($t['statut'] ?? '');
            $fait = $st !== 'nonRendue' ? 1 : 0;
            $note = isset($t['note']) && $t['note'] !== null ? (int) $t['note'] : null;
            Db::exec('INSERT INTO ceo_tache_jour (jour, id_shop, id_task, nom, fait, statut, note, commentaire) VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE nom = VALUES(nom), fait = VALUES(fait), statut = VALUES(statut), note = VALUES(note), commentaire = VALUES(commentaire)',
                [$date, (int) $s['shopId'], (int) $t['taskId'], mb_substr((string) $t['tache'], 0, 200), $fait, mb_substr($st, 0, 12),
                 $note, isset($t['comment']) && $t['comment'] !== null ? mb_substr((string) $t['comment'], 0, 500) : null]);
            $n++;
        }
    }
    Db::exec('INSERT INTO ceo_tache_jour_etat (jour, releve_le, nb) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE releve_le = VALUES(releve_le), nb = VALUES(nb)',
        [$date, date('Y-m-d H:i:s'), $n]);
    return $n;
}

/**
 * Force la relève d'une FENÊTRE de jours, même déjà relevés — sert à
 * rattraper note/commentaire sur des jours moissonnés avant que ces colonnes
 * n'existent. Budgetée en secondes comme tachesSuiviLot ; POST
 * /pwa/tasks/releve-fenetre rappelle jusqu'à ce que « reste » tombe à zéro.
 */
function tachesReleveFenetre(string $du, string $au, int $budgetS = 25): array
{
    tachesSuiviTables();
    if (!PanelApi::configured()) { return ['releves' => 0, 'reste' => null, 'note' => 'API du panel non configurée']; }
    $debut = microtime(true);
    $releves = 0; $reste = 0; $rate = 0;
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($d > date('Y-m-d')) { continue; }
        if (microtime(true) - $debut > $budgetS) { $reste++; continue; }
        if (tachesJourReleve($d) === null) {
            $reste++;
            if (++$rate >= 2) { continue; }
            continue;
        }
        $releves++;
    }
    return ['releves' => $releves, 'reste' => $reste, 'note' => null];
}

/** POST /pwa/tasks/releve-fenetre {du, au, budget} — rattrapage forcé note/commentaire. */
function wr_taches_releve_fenetre(): array
{
    $b = body();
    $du = (string) ($b['du'] ?? '');
    $au = (string) ($b['au'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $du > $au) {
        http_response_code(422);
        return ['error' => 'du et au (YYYY-MM-DD) sont requis, du <= au'];
    }
    return ['ok' => true] + tachesReleveFenetre($du, $au, (int) ($b['budget'] ?? 25));
}

/** Les jours révolus encore à relever, du plus récent au plus ancien. */
function tachesJoursManquants(int $max = 400): array
{
    tachesSuiviTables();
    $faits = [];
    foreach (Db::rows('SELECT jour FROM ceo_tache_jour_etat') as $r) { $faits[(string) $r['jour']] = true; }
    $out = [];
    for ($i = 1; $i <= TACHES_SUIVI_PROFONDEUR && count($out) < $max; $i++) {
        $j = date('Y-m-d', strtotime('-' . $i . ' days'));
        if (!isset($faits[$j])) { $out[] = $j; }
    }
    return $out;
}

/**
 * Un LOT de relevés dans le budget de temps — appelé par le cron horaire des
 * rapports (l'historique se complète tout seul, heure après heure) et par le
 * bouton « Compléter l'historique » de l'écran.
 */
function tachesSuiviLot(): array
{
    if (!PanelApi::configured()) { return ['releves' => 0, 'reste' => null, 'note' => 'API du panel non configurée']; }
    $debut = microtime(true);
    $releves = 0; $rate = 0;
    foreach (tachesJoursManquants() as $j) {
        if (microtime(true) - $debut > TACHES_SUIVI_BUDGET_S) { break; }
        if (tachesJourReleve($j) === null) {
            // Deux refus de suite = le panel ne répond pas : inutile d'insister.
            if (++$rate >= 2) { break; }
            continue;
        }
        $releves++;
    }
    return ['releves' => $releves, 'reste' => count(tachesJoursManquants()), 'note' => null];
}

/** Le battement du cron : un lot par passage, silencieux quand tout est là. */
function tachesSuiviCron(): string
{
    $manquants = tachesJoursManquants(1);
    if ($manquants === []) { return 'historique complet'; }
    $b = tachesSuiviLot();
    return $b['releves'] . ' jour(s) relevé(s), ' . ($b['reste'] ?? '?') . ' restant(s)'
        . ($b['note'] !== null ? ' — ' . $b['note'] : '');
}

/** POST /pwa/tasks/releve — un lot à la demande, depuis l'écran. */
function wr_taches_releve(): array
{
    return ['ok' => true] + tachesSuiviLot();
}

/**
 * GET /pwa/tasks/heatmap — la heatmap : 12 mois glissants × magasins.
 * Chaque cellule : faites, pasFaites, part, couverture (jours relevés sur
 * jours révolus du mois). Le mois en cours se compte au jour le jour.
 */
function ep_taches_heatmap(): array
{
    tachesSuiviTables();
    $shopNames = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
            $shopNames[(string) $s['id']] = (string) $s['name'];
        }
    } catch (PDOException $e) { /* liste vide : l'écran le dira */ }

    $mois = [];
    for ($i = 11; $i >= 0; $i--) { $mois[] = date('Y-m', strtotime('first day of -' . $i . ' months')); }

    // Agrégat en une requête : mois × magasin.
    $agg = [];
    foreach (Db::rows("SELECT DATE_FORMAT(jour, '%Y-%m') m, id_shop, SUM(fait) f, COUNT(*) t
                       FROM ceo_tache_jour WHERE jour >= ? GROUP BY m, id_shop", [$mois[0] . '-01']) as $r) {
        $agg[(string) $r['m']][(string) $r['id_shop']] = ['f' => (int) $r['f'], 't' => (int) $r['t']];
    }
    $couv = [];
    foreach (Db::rows("SELECT DATE_FORMAT(jour, '%Y-%m') m, COUNT(*) n FROM ceo_tache_jour_etat
                       WHERE jour >= ? GROUP BY m", [$mois[0] . '-01']) as $r) {
        $couv[(string) $r['m']] = (int) $r['n'];
    }

    $jRevolus = static function (string $m): int {
        $fin = min(strtotime($m . '-' . date('t', strtotime($m . '-01'))), strtotime('yesterday'));
        $deb = strtotime($m . '-01');
        return $fin < $deb ? 0 : (int) round(($fin - $deb) / 86400) + 1;
    };

    $lignes = [];
    foreach ($shopNames as $sid => $nom) {
        $cells = []; $F = 0; $T = 0;
        foreach ($mois as $m) {
            $a = $agg[$m][$sid] ?? null;
            $f = $a['f'] ?? 0; $t = $a['t'] ?? 0;
            $F += $f; $T += $t;
            $cells[] = ['m' => $m, 'faites' => $f, 'pasFaites' => $t - $f,
                'part' => $t > 0 ? round(100 * $f / $t) : null,
                'couverture' => $couv[$m] ?? 0, 'joursRevolus' => $jRevolus($m)];
        }
        $lignes[] = ['shopId' => $sid, 'shop' => $nom, 'cellules' => $cells,
            'faites' => $F, 'pasFaites' => $T - $F, 'part' => $T > 0 ? round(100 * $F / $T) : null];
    }
    $reste = count(tachesJoursManquants());
    return ['mois' => $mois, 'lignes' => $lignes,
        'joursReleves' => array_sum($couv), 'joursManquants' => $reste,
        'panelOk' => PanelApi::configured()];
}

/**
 * GET /pwa/tasks/heatmap/mois?m=2026-08 — la vue PAR MOIS, celle qui s'ouvre
 * en premier : magasins × jours du mois, chaque cellule un jour (part faite,
 * faites, pas faites). Les jours non relevés se distinguent des jours à zéro.
 */
function ep_taches_heatmap_mois(): array
{
    tachesSuiviTables();
    // Deux façons de borner : ?m=YYYY-MM (le mois entier), ou ?du=…&au=…
    // (une plage libre — la vue Semaine s'en sert, 62 jours au plus).
    $du = trim((string) ($_GET['du'] ?? ''));
    $au = trim((string) ($_GET['au'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au)
        && $du <= $au && (strtotime($au) - strtotime($du)) / 86400 <= 62) {
        $m = substr($du, 0, 7);
        $deb = $du; $fin = $au;
    } else {
        $m = trim((string) ($_GET['m'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m'); }
        $deb = $m . '-01';
        $fin = $m . '-' . date('t', strtotime($m . '-01'));
    }
    $shopNames = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
            $shopNames[(string) $s['id']] = (string) $s['name'];
        }
    } catch (PDOException $e) { /* liste vide */ }

    $releves = array_map(fn ($r2) => (string) $r2['jour'],
        Db::rows('SELECT jour FROM ceo_tache_jour_etat WHERE jour BETWEEN ? AND ? ORDER BY jour', [$deb, $fin]));
    $releves = array_flip($releves);

    $parJour = [];
    foreach (Db::rows("SELECT jour, id_shop, SUM(fait) f, COUNT(*) t FROM ceo_tache_jour
                       WHERE jour BETWEEN ? AND ? GROUP BY jour, id_shop", [$deb, $fin]) as $r2) {
        $parJour[(string) $r2['jour']][(string) $r2['id_shop']] = ['f' => (int) $r2['f'], 't' => (int) $r2['t']];
    }

    $jours = [];
    for ($ts = strtotime($deb); $ts <= strtotime($fin); $ts += 86400) { $jours[] = date('Y-m-d', $ts); }

    $lignes = [];
    foreach ($shopNames as $sid => $nom) {
        $cells = []; $F = 0; $T = 0;
        foreach ($jours as $j) {
            $a = $parJour[$j][$sid] ?? $parJour[$j][(int) $sid] ?? null;
            $f = $a['f'] ?? 0; $t = $a['t'] ?? 0;
            $F += $f; $T += $t;
            $cells[] = ['j' => $j, 'releve' => isset($releves[$j]), 'faites' => $f, 'pasFaites' => $t - $f,
                'part' => $t > 0 ? round(100 * $f / $t) : null];
        }
        $lignes[] = ['shopId' => (string) $sid, 'shop' => $nom, 'jours' => $cells,
            'faites' => $F, 'pasFaites' => $T - $F, 'part' => $T > 0 ? round(100 * $F / $T) : null];
    }
    $moisDispo = [];
    for ($i = 11; $i >= 0; $i--) { $moisDispo[] = date('Y-m', strtotime('first day of -' . $i . ' months')); }
    return ['m' => $m, 'mois' => $moisDispo, 'jours' => $jours, 'lignes' => $lignes,
        'joursReleves' => count($releves), 'joursManquants' => count(tachesJoursManquants())];
}

/**
 * GET /pwa/tasks/heatmap/detail?shop=4&m=2026-02 — le détail d'une cellule :
 * chaque tâche du mois (les moins faites d'abord) ET la grille jour par jour
 * — fait / pas fait / pas attendue ce jour-là.
 */
function ep_taches_heatmap_detail(): array
{
    tachesSuiviTables();
    $sid = trim((string) ($_GET['shop'] ?? ''));
    $m = trim((string) ($_GET['m'] ?? ''));
    if ($sid === '' || !preg_match('/^\d{4}-\d{2}$/', $m)) {
        http_response_code(422);
        return ['error' => 'shop et m (YYYY-MM) sont requis'];
    }
    $nomShop = '';
    try {
        $r = Db::row('SELECT name FROM shops WHERE id = ?', [(int) $sid]);
        $nomShop = (string) ($r['name'] ?? '');
    } catch (PDOException $e) { /* nom vide */ }

    $jours = array_map(fn ($r2) => (string) $r2['jour'],
        Db::rows("SELECT jour FROM ceo_tache_jour_etat WHERE jour LIKE ? ORDER BY jour", [$m . '-%']));

    $taches = [];
    foreach (Db::rows('SELECT jour, id_task, nom, fait, statut FROM ceo_tache_jour
                       WHERE id_shop = ? AND jour LIKE ? ORDER BY jour', [(int) $sid, $m . '-%']) as $r2) {
        $tid = (string) $r2['id_task'];
        if (!isset($taches[$tid])) {
            $taches[$tid] = ['taskId' => $tid, 'tache' => (string) $r2['nom'], 'attendues' => 0, 'faites' => 0, 'jours' => []];
        }
        if ((string) $r2['nom'] !== '') { $taches[$tid]['tache'] = (string) $r2['nom']; }
        $taches[$tid]['attendues']++;
        if ((int) $r2['fait'] === 1) { $taches[$tid]['faites']++; }
        $taches[$tid]['jours'][(string) $r2['jour']] = ['fait' => (int) $r2['fait'] === 1, 'statut' => (string) $r2['statut']];
    }
    $taches = array_values($taches);
    foreach ($taches as $i2 => $t2) {
        $taches[$i2]['part'] = $t2['attendues'] > 0 ? round(100 * $t2['faites'] / $t2['attendues']) : null;
    }
    // Les moins faites d'abord : c'est là que la conversation commence.
    usort($taches, fn ($a, $b2) => ($a['part'] ?? 101) <=> ($b2['part'] ?? 101));

    $F = array_sum(array_column($taches, 'faites'));
    $T = array_sum(array_column($taches, 'attendues'));
    return ['shopId' => $sid, 'shop' => $nomShop, 'm' => $m, 'jours' => $jours,
        'faites' => $F, 'pasFaites' => $T - $F, 'part' => $T > 0 ? round(100 * $F / $T) : null,
        'taches' => $taches];
}
