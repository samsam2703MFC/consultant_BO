<?php

declare(strict_types=1);

/**
 * Synchronisation du PLANNING depuis l'API du panel (atelierby.tfbuddy.com).
 *
 * La synchro historique (Merisu, cron de 2 h 30) est muette depuis la
 * mi-juillet : dernier planning local le 19/07, sortie du cron dans
 * /dev/null. Ici, le cockpit va chercher lui-même les services à la source —
 * GET /shops/{id}/schedule, la route que le panel sert en production — et
 * les écrit dans la table locale que tous les écrans lisent déjà.
 *
 * La route rend TOUT l'historique (aucun filtre de date, mesuré) : on ne
 * réécrit que la FENÊTRE utile — du lendemain du dernier planning local
 * (premier passage) ou de J-7 (ensuite), jusqu'à J+35. Sur cette fenêtre,
 * l'API fait foi : les lignes locales sont remplacées, jamais dupliquées.
 */

function planningSync(bool $force = false): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré (Mon compte)']; }

    // Un passage par heure suffit : le planning s'encode à la journée.
    $der = setting('planningSyncDerniere', '');
    if (!$force && is_string($der) && substr($der, 0, 13) === date('Y-m-d H')) {
        return ['ok' => true, 'motif' => 'déjà passée cette heure', 'derniere' => $der];
    }

    $du = date('Y-m-d', strtotime('-7 days'));
    $au = date('Y-m-d', strtotime('+35 days'));
    try {
        $max = Db::row('SELECT MAX(work_date) m FROM franchisee_employee_schedule');
        $maxD = (string) ($max['m'] ?? '');
        // Premier rattrapage : reprendre au lendemain du dernier jour local,
        // pour combler le trou (20/07 → aujourd'hui) en un passage.
        if ($maxD !== '' && date('Y-m-d', strtotime($maxD . ' +1 day')) < $du) {
            $du = date('Y-m-d', strtotime($maxD . ' +1 day'));
        }
    } catch (PDOException $e) {
        return ['ok' => false, 'motif' => 'table locale illisible : ' . $e->getMessage()];
    }

    $chemins = [];
    foreach (Db::rows('SELECT id FROM shops WHERE active = 1') as $s) {
        $chemins[(int) $s['id']] = '/shops/' . (int) $s['id'] . '/schedule';
    }
    $lignes = []; $indispo = [];
    foreach (PanelApi::getParallele($chemins, 4) as $sid => $rep) {
        if (!is_array($rep)) { $indispo[] = (int) $sid; continue; }
        foreach (analyseListe($rep) as $r) {
            $d = (string) ($r['work_date'] ?? '');
            if ($d < $du || $d > $au) { continue; }
            $lignes[] = $r;
        }
    }
    // Un magasin muet = on ne touche pas à sa fenêtre : effacer sans pouvoir
    // réécrire transformerait une panne d'API en planning vide.
    if ($indispo !== []) {
        journalAdd('CEO', 'Planning', 'Synchro', 'API muette pour ' . count($indispo) . ' magasin(s) — passage annulé');
        return ['ok' => false, 'motif' => 'API sans réponse pour les magasins ' . implode(', ', $indispo)];
    }

    // Les colonnes RÉELLES de la table locale : on n'écrit que ce qui existe,
    // et la clé du panel (id_schedule) se range dans la colonne qui la porte.
    $cols = array_column(Db::rows('SHOW COLUMNS FROM franchisee_employee_schedule'), 'Field');
    $ordre = [];
    foreach (['id_schedule', 'id', 'id_employee', 'id_shop', 'start_hour', 'end_hour', 'work_date', 'create_timestamp'] as $c) {
        if (in_array($c, $cols, true)) { $ordre[] = $c; }
    }
    if (in_array('id_schedule', $ordre, true) && in_array('id', $ordre, true)) {
        $ordre = array_values(array_diff($ordre, ['id']));
    }
    if (!in_array('work_date', $ordre, true) || !in_array('id_employee', $ordre, true)) {
        return ['ok' => false, 'motif' => 'table locale sans work_date/id_employee — schéma inattendu'];
    }

    // La fenêtre se remplace d'un bloc : l'API est la source de vérité, et
    // c'est ce qui rend le passage rejouable sans jamais doubler une ligne.
    Db::exec('DELETE FROM franchisee_employee_schedule WHERE work_date >= ? AND work_date <= ?', [$du, $au]);
    $n = 0;
    $sql = 'INSERT INTO franchisee_employee_schedule (' . implode(', ', $ordre) . ') VALUES ('
        . implode(', ', array_fill(0, count($ordre), '?')) . ')';
    foreach ($lignes as $r) {
        $vals = [];
        foreach ($ordre as $c) {
            $vals[] = match ($c) {
                'id', 'id_schedule' => (int) ($r['id_schedule'] ?? 0),
                'create_timestamp' => (string) ($r['create_timestamp'] ?? date('Y-m-d H:i:s')),
                default => $r[$c] ?? null,
            };
        }
        try { Db::exec($sql, $vals); $n++; }
        catch (PDOException $e) { /* une ligne refusée (employé inconnu…) ne bloque pas les autres */ }
    }

    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['planningSyncDerniere', json_encode(date('Y-m-d H:i:s'))]);
    $resume = $n . ' service(s) écrits sur ' . $du . ' → ' . $au;
    journalAdd('CEO', 'Planning', 'Synchro', $resume . ' (API panel /shops/{id}/schedule)');
    return ['ok' => true, 'fenetre' => [$du, $au], 'lignes' => $n, 'recues' => count($lignes)];
}

/** POST /planning/sync — le passage à la demande, avec son bilan. */
function wr_planning_sync(): array
{
    return planningSync(true);
}

/** Le battement horaire, accroché au cron des rapports comme les autres. */
function planningSyncCron(): string
{
    $r = planningSync(false);
    return $r['ok'] ? ($r['motif'] ?? ($r['lignes'] . ' service(s)')) : ('échec : ' . ($r['motif'] ?? '?'));
}
