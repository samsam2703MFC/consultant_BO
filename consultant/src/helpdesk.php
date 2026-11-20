<?php
declare(strict_types=1);

/**
 * Le helpdesk des franchisés (15/11/2026, maquettes docs/maquettes/helpdesk-franchises, validées « ok tout ») :
 * les demandes que les franchisés adressent au bureau (le helpdesk du panel, routes /cases), lues et traitées au
 * cockpit, reprises en mission de visite, et envoyées depuis le dashboard franchisé (l'assistant du magasin).
 *
 * Cockpit (session CEO) :
 *   GET  /helpdesk/demandes                      — toutes les demandes (GET /cases, compte administrateur du cockpit),
 *                                                  les missions de visite, les visites à venir de chaque magasin
 *   GET  /helpdesk/demandes/{id}/consultants      — les consultants du domaine de la demande
 *   POST /helpdesk/demandes/{id}/repondre {reponse, statut?}       — PATCH /cases/{id}/reply (+ /status)
 *   POST /helpdesk/demandes/{id}/statut {statut}                   — PATCH /cases/{id}/status
 *   POST /helpdesk/demandes/{id}/consultant {consultant}           — PATCH /cases/{id}/consultant
 *   POST /helpdesk/demandes/{id}/visite {visite, quand, points[]}  — la demande devient une mission de la visite :
 *        deux tâches du consultant (contrôler pendant, répondre après : con_consultant_tache, source « helpdesk »,
 *        que l'application terrain montre sur la fiche) et ses points à contrôler ; la demande passe « En cours »
 *   POST /helpdesk/demandes/{id}/delai {jours, statut?, shop?, ref?} — la date limite que le consultant fixe à la
 *        réception (2, 5, 10, 30 ou 90 jours ; 0 la retire) ; une demande « Nouvelle » passe « En cours » (20/11/2026)
 *   GET  /helpdesk/sms · PUT /helpdesk/sms {actif, destinataires[{nom, tel}]} — à qui part le SMS de chaque nouvelle
 *        demande (20/11/2026, plus bas : « Le SMS au bureau »)
 *   POST /helpdesk/missions/{id} {points[{etat, constat}], cloturer?, repondre?}
 *        — le constat de chaque point ; à la clôture, le constat repart en réponse et le statut suit (Résolue si tout
 *          est conforme, sinon En cours), les tâches de la mission sont faites
 * Dashboard franchisé :
 *   GET  /helpdesk/magasin?shop=                 — les domaines et les demandes du magasin (GET /shops/{id}/cases)
 *   POST /helpdesk/magasin {shop, domaine, titre, description, passage, photos[]} — POST /cases ; les photos sont gardées
 *        par le cockpit (public/uploads/helpdesk, nom aléatoire) et leurs liens partent dans la description
 *        ; un SMS avec le résumé de la demande part au bureau (réglage `helpdeskSms`)
 *
 * Contrat : swagger test.tfbuddy.com, lu le 15/11/2026 (POST /cases {*area_key, *title, *description, shop_id}).
 */

const HDK_DOMAINES = ['strategy' => 'Stratégie', 'product' => 'Produit', 'operational_activities' => 'Activités opérationnelles',
    'sales' => 'Ventes', 'app_development' => 'Application', 'social_media' => 'Réseaux sociaux'];
const HDK_STATUTS = ['NEW' => 'Nouvelle', 'IN_PROGRESS' => 'En cours', 'RESOLVED' => 'Résolue', 'CLOSED' => 'Fermée', 'CANCELLED' => 'Annulée'];
const HDK_ETATS = ['conforme', 'a_suivre', 'non_conforme'];
/** Les délais que le consultant donne à une demande à sa réception, en jours (Sam, 20/11/2026 : « 2 5 10 30 j +90 »). */
const HDK_DELAIS = [2, 5, 10, 30, 90];

function hdkTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS con_hdk_mission (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT NOT NULL,
        case_ref VARCHAR(40) NOT NULL DEFAULT \'\',
        titre VARCHAR(255) NOT NULL DEFAULT \'\',
        shop_id VARCHAR(32) NOT NULL DEFAULT \'\',
        visite_id INT NOT NULL,
        quand VARCHAR(8) NOT NULL DEFAULT \'pendant\',
        points TEXT NULL,
        tache_ids VARCHAR(80) NOT NULL DEFAULT \'\',
        cree_le DATETIME NOT NULL,
        maj_le DATETIME NOT NULL,
        cloture_le DATETIME NULL,
        reponse TEXT NULL,
        KEY k_case (case_id), KEY k_visite (visite_id))');
    Db::exec('CREATE TABLE IF NOT EXISTS con_hdk_demande (
        case_id INT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL DEFAULT \'\',
        passage TINYINT NOT NULL DEFAULT 0,
        photos TEXT NULL,
        cree_le DATETIME NOT NULL)');
    // La date limite que le consultant fixe à la réception (20/11/2026) : 2, 5, 10, 30 ou 90 jours.
    foreach (['delai_jours INT NULL', 'echeance DATE NULL', "echeance_par VARCHAR(120) NOT NULL DEFAULT ''", 'echeance_le DATETIME NULL'] as $col) { hdkColonne('con_hdk_demande', $col); }
    $fait = true;
}

/** Une colonne ajoutée si elle manque (la table existe déjà en ligne). */
function hdkColonne(string $table, string $def): void
{
    if (function_exists('suColonne')) { suColonne($table, $def); return; }
    try { Db::exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $def); } catch (Throwable $e) { /* déjà là */ }
}

/** Le jeton du compte administrateur du cockpit (Paramètres › ERP) pour les appels qui suivent ; false s'il manque. */
function hdkAdmin(): bool
{
    if (PanelApi::$jetonImpose !== null) { return true; }
    if (!class_exists('ErpApi')) { return false; }
    $t = ErpApi::jetonSonde();
    if ($t === null) { return false; }
    PanelApi::$jetonImpose = $t;
    return true;
}

/** Une demande du panel, ramenée à ce que l'écran montre (jamais le courriel du demandeur). Fonction pure. */
function hdkDemande(array $c): array
{
    $st = (string) ($c['status'] ?? 'NEW');
    return ['id' => (int) ($c['id'] ?? 0), 'ref' => (string) ($c['ref'] ?? ''), 'domaine' => (string) ($c['area_key'] ?? ''), 'domaineNom' => HDK_DOMAINES[$c['area_key'] ?? ''] ?? (string) ($c['area_key'] ?? ''),
        'statut' => $st, 'statutNom' => HDK_STATUTS[$st] ?? $st, 'ouverte' => in_array($st, ['NEW', 'IN_PROGRESS'], true),
        'titre' => trim((string) ($c['title'] ?? '')), 'description' => trim((string) ($c['description'] ?? '')),
        'shop' => (string) ($c['shop_id'] ?? ''), 'magasin' => trim((string) ($c['shop_name'] ?? '')), 'demandeur' => trim((string) ($c['requester_name'] ?? '')),
        'reponse' => trim((string) ($c['admin_reply'] ?? '')), 'reponseLe' => $c['admin_reply_at'] ?? null,
        'cree' => (string) ($c['created_at'] ?? ''), 'maj' => (string) ($c['updated_at'] ?? ''),
        'consultant' => trim((string) ($c['consultant_name'] ?? '')), 'consultantId' => isset($c['suggested_membership_id']) ? (int) $c['suggested_membership_id'] : null,
        'reunion' => $c['meeting_date'] ?? null];
}

/** Les missions de visite d'une liste de demandes (ou toutes les ouvertes) : [case_id => [missions]]. */
function hdkMissions(?array $caseIds = null): array
{
    hdkTable();
    $rows = $caseIds === null ? Db::rows('SELECT * FROM con_hdk_mission ORDER BY id DESC') : ($caseIds === [] ? [] : Db::rows('SELECT * FROM con_hdk_mission WHERE case_id IN (' . implode(',', array_map('intval', $caseIds)) . ') ORDER BY id DESC'));
    $vis = [];
    $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r['visite_id'], $rows)));
    if ($ids !== []) { foreach (Db::rows('SELECT id, shop_id, prevu_le, statut, consultant_nom, motif FROM con_visite WHERE id IN (' . implode(',', $ids) . ')') as $v) { $vis[(int) $v['id']] = $v; } }
    $out = [];
    foreach ($rows as $r) {
        $v = $vis[(int) $r['visite_id']] ?? null;
        $out[(int) $r['case_id']][] = ['id' => (int) $r['id'], 'caseId' => (int) $r['case_id'], 'ref' => (string) $r['case_ref'], 'titre' => (string) $r['titre'], 'shop' => (string) $r['shop_id'],
            'visite' => (int) $r['visite_id'], 'visiteLe' => $v['prevu_le'] ?? null, 'visiteStatut' => $v['statut'] ?? null, 'consultant' => $v['consultant_nom'] ?? '',
            'quand' => (string) $r['quand'], 'points' => json_decode((string) ($r['points'] ?? '[]'), true) ?: [], 'cloture' => $r['cloture_le'], 'reponse' => $r['reponse'], 'cree' => $r['cree_le']];
    }
    return $out;
}

/** GET /helpdesk/demandes — toutes les demandes, leurs missions, les visites à venir de chaque magasin. */
function ep_helpdesk_demandes(): array
{
    if (!PanelApi::configured() && !class_exists('ErpApi')) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    $admin = hdkAdmin();
    PanelApi::$lastError = null;
    $r = PanelApi::get('/cases');
    if (!is_array($r)) { return ['indispo' => true, 'motif' => 'Le helpdesk du panel n’a pas répondu (' . (PanelApi::$lastError ?? 'pas de réponse') . ').' . ($admin ? '' : ' Le compte administrateur du cockpit (Paramètres › ERP) n’est pas configuré : seules les demandes d’un magasin se lisent.')]; }
    $D = array_map('hdkDemande', array_values(array_filter(PanelApi::liste($r) ?: (array_is_list($r) ? $r : []), 'is_array')));
    usort($D, static fn ($a, $b) => strcmp($b['cree'], $a['cree']));
    $M = hdkMissions(array_column($D, 'id'));
    $E = hdkEcheances(array_column($D, 'id'));
    foreach ($D as &$d) { $d['missions'] = $M[$d['id']] ?? []; $d += hdkEcheanceVue($E[$d['id']] ?? null, $d['ouverte']); } unset($d);
    // Les visites où reprendre une demande : celles des 14 derniers jours et à venir, non annulées, par magasin.
    $visites = [];
    try {
        if (function_exists('ensureVisites')) { ensureVisites(); }
        foreach (Db::rows('SELECT id, shop_id, prevu_le, statut, consultant_id, consultant_nom, motif FROM con_visite WHERE prevu_le >= DATE_SUB(CURDATE(), INTERVAL 21 DAY) AND statut NOT IN (\'annulee\', \'terminee\') ORDER BY prevu_le') as $v) {
            $visites[(string) $v['shop_id']][] = ['id' => (int) $v['id'], 'le' => (string) $v['prevu_le'], 'statut' => (string) $v['statut'], 'consultant' => (string) $v['consultant_nom'], 'type' => (string) $v['motif']];
        }
    } catch (Throwable $e) { /* pas de visites lisibles : la reprise se fera plus tard */ }
    $mags = [];
    foreach ((function_exists('viMagasins') ? viMagasins() : []) as $id => $m) { $mags[] = ['id' => (string) $id, 'nom' => $m['court'] ?? $m['nom']]; }
    return ['demandes' => $D, 'domaines' => HDK_DOMAINES, 'statuts' => HDK_STATUTS, 'delais' => HDK_DELAIS, 'visites' => $visites, 'magasins' => $mags, 'compte' => $admin ? 'admin' : 'consultant',
        'lu' => date('c'), 'source' => 'helpdesk du panel : GET /cases (compte administrateur du cockpit) · missions : visites du cockpit (con_visite, con_consultant_tache)'];
}

/** GET /helpdesk/demandes/{id}/consultants — les consultants que le panel juge éligibles pour la demande. */
function ep_helpdesk_consultants(int $id): array
{
    hdkAdmin();
    $r = PanelApi::get('/cases/' . $id . '/eligible-consultants');
    $out = [];
    foreach (is_array($r) ? (PanelApi::liste($r) ?: (array_is_list($r) ? $r : [])) : [] as $c) {
        if (!is_array($c)) { continue; }
        $mid = $c['membership_id'] ?? ($c['id'] ?? null);
        if (!is_numeric($mid)) { continue; }
        $out[] = ['id' => (int) $mid, 'nom' => trim((string) ($c['display_name'] ?? ($c['name'] ?? ('consultant ' . $mid))))];
    }
    return ['consultants' => $out, 'erreur' => $out === [] ? PanelApi::$lastError : null];
}

/** Un envoi au panel, avec la réponse brute en cas de refus. */
function hdkEnvoi(string $m, string $route, array $corps): array
{
    PanelApi::$lastError = null;
    [$ok, $res] = PanelApi::envoi($m, $route, $corps);
    if ($ok) { return ['ok' => true, 'res' => $res]; }
    $msg = is_array($res) ? (string) ($res['description'] ?? ($res['message'] ?? '')) : '';
    return ['ok' => false, 'error' => 'Le panel a refusé ' . $m . ' ' . $route . ($msg !== '' ? ' : ' . $msg : '') . ' (' . (PanelApi::$lastError ?? 'pas de réponse') . ')'];
}

function hdkJournal(string $quoi): void
{
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Helpdesk', null, $quoi); }
}

/** POST /helpdesk/demandes/{id}/repondre {reponse, statut?} */
function wr_helpdesk_repondre(int $id): array
{
    $b = body();
    $txt = trim((string) ($b['reponse'] ?? ''));
    if ($txt === '') { http_response_code(422); return ['ok' => false, 'error' => 'La réponse est vide.']; }
    if (!hdkAdmin()) { return ['ok' => false, 'error' => 'Le compte administrateur du cockpit (Paramètres › ERP) n’est pas configuré.']; }
    $r = hdkEnvoi('PATCH', '/cases/' . $id . '/reply', ['admin_reply' => mb_substr($txt, 0, 5000)]);
    if (!$r['ok']) { return $r; }
    $st = (string) ($b['statut'] ?? '');
    if ($st !== '' && isset(HDK_STATUTS[$st])) { $s = hdkEnvoi('PATCH', '/cases/' . $id . '/status', ['status' => $st]); if (!$s['ok']) { return ['ok' => false, 'error' => 'Réponse envoyée, mais ' . $s['error']]; } }
    hdkJournal('Réponse à la demande ' . $id . ($st !== '' ? ' (statut ' . (HDK_STATUTS[$st] ?? $st) . ')' : ''));
    return ['ok' => true];
}

/** POST /helpdesk/demandes/{id}/statut {statut} */
function wr_helpdesk_statut(int $id): array
{
    $st = (string) (body()['statut'] ?? '');
    if (!isset(HDK_STATUTS[$st])) { http_response_code(422); return ['ok' => false, 'error' => 'Statut inconnu.']; }
    if (!hdkAdmin()) { return ['ok' => false, 'error' => 'Le compte administrateur du cockpit (Paramètres › ERP) n’est pas configuré.']; }
    $r = hdkEnvoi('PATCH', '/cases/' . $id . '/status', ['status' => $st]);
    if ($r['ok']) { hdkJournal('Demande ' . $id . ' : ' . HDK_STATUTS[$st]); }
    return $r['ok'] ? ['ok' => true] : $r;
}

/** POST /helpdesk/demandes/{id}/consultant {consultant} */
function wr_helpdesk_consultant(int $id): array
{
    $c = (int) (body()['consultant'] ?? 0);
    if ($c <= 0) { http_response_code(422); return ['ok' => false, 'error' => 'Consultant requis.']; }
    if (!hdkAdmin()) { return ['ok' => false, 'error' => 'Le compte administrateur du cockpit (Paramètres › ERP) n’est pas configuré.']; }
    $r = hdkEnvoi('PATCH', '/cases/' . $id . '/consultant', ['consultant_id' => $c]);
    if ($r['ok']) { hdkJournal('Demande ' . $id . ' attribuée au consultant ' . $c); }
    return $r['ok'] ? ['ok' => true] : $r;
}

/** POST /helpdesk/demandes/{id}/visite {visite, quand, points[], ref?, titre?, shop?} — la demande devient une mission. */
function wr_helpdesk_visite(int $id): array
{
    hdkTable();
    if (function_exists('ensureVisitesCadre')) { ensureVisitesCadre(); }
    $b = body();
    $vid = (int) ($b['visite'] ?? 0);
    $quand = in_array($b['quand'] ?? '', ['avant', 'pendant', 'apres'], true) ? $b['quand'] : 'pendant';
    $points = array_values(array_filter(array_map(static fn ($p) => mb_substr(trim((string) $p), 0, 300), (array) ($b['points'] ?? [])), static fn ($p) => $p !== ''));
    $v = $vid > 0 ? Db::row('SELECT * FROM con_visite WHERE id = ?', [$vid]) : null;
    if ($v === null) { http_response_code(422); return ['ok' => false, 'error' => 'Visite inconnue.']; }
    if ($points === []) { http_response_code(422); return ['ok' => false, 'error' => 'Dites ce qu’il faut vérifier sur place (un point par ligne).']; }
    $ref = mb_substr(trim((string) ($b['ref'] ?? ('demande ' . $id))), 0, 40);
    $titre = mb_substr(trim((string) ($b['titre'] ?? '')), 0, 160);
    if (Db::row('SELECT id FROM con_hdk_mission WHERE case_id = ? AND visite_id = ? AND cloture_le IS NULL', [$id, $vid]) !== null) { return ['ok' => false, 'error' => 'Cette demande est déjà une mission de cette visite.']; }
    $now = date('Y-m-d H:i:s');
    $ech = static fn (string $q) => function_exists('vcEcheance') ? vcEcheance((string) $v['prevu_le'], $q, $q === 'pendant' ? 0 : 1) : (string) $v['prevu_le'];
    $taches = [];
    foreach ([[$quand, 'Contrôler la demande du franchisé « ' . $titre . ' »', implode("\n", array_map(static fn ($p) => '• ' . $p, $points))],
              ['apres', 'Répondre au franchisé sur sa demande « ' . $titre . ' »', 'Le constat de la visite repart en réponse au panel (helpdesk ' . $ref . ').']] as $i => [$q, $t, $det]) {
        Db::exec('INSERT INTO con_consultant_tache (client_id, consultant_id, consultant_nom, shop_id, visite_id, source, quand, delai, titre, detail, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            ['h' . $id . ':' . $vid . ':' . $i, (string) $v['consultant_id'], (string) $v['consultant_nom'], (string) $v['shop_id'], $vid, 'helpdesk', $q, $q === 'pendant' ? 0 : 1,
             mb_substr($t, 0, 190), mb_substr('Helpdesk ' . $ref . "\n" . $det, 0, 2000), $ech($q), 'a_faire', 'helpdesk', $now, $now]);
        $taches[] = (int) Db::pdo()->lastInsertId();
    }
    $pts = array_map(static fn ($p) => ['nom' => $p, 'etat' => null, 'constat' => ''], $points);
    Db::exec('INSERT INTO con_hdk_mission (case_id, case_ref, titre, shop_id, visite_id, quand, points, tache_ids, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$id, $ref, $titre, (string) $v['shop_id'], $vid, $quand, json_encode($pts, JSON_UNESCAPED_UNICODE), implode(',', $taches), $now, $now]);
    $avert = null;
    if (hdkAdmin()) { $s = hdkEnvoi('PATCH', '/cases/' . $id . '/status', ['status' => 'IN_PROGRESS']); if (!$s['ok']) { $avert = 'Mission créée ; le statut n’a pas pu passer « En cours » : ' . $s['error']; } }
    hdkJournal('Demande ' . $ref . ' reprise dans la visite ' . $vid . ' (' . count($points) . ' point(s))');
    return ['ok' => true, 'avertissement' => $avert, 'missions' => hdkMissions([$id])[$id] ?? []];
}

/** Le constat d'une mission, en texte pour le franchisé. Fonction pure. */
function hdkConstat(array $points, string $date, string $consultant): string
{
    $lib = ['conforme' => 'conforme', 'a_suivre' => 'à suivre', 'non_conforme' => 'non conforme'];
    $l = ['Constat de la visite du ' . ($date !== '' ? date('d/m/Y', strtotime($date)) : '—') . ($consultant !== '' ? ' (' . $consultant . ')' : '') . ' :'];
    foreach ($points as $p) { $l[] = '• ' . $p['nom'] . ' — ' . ($lib[$p['etat'] ?? ''] ?? 'non vu') . (trim((string) ($p['constat'] ?? '')) !== '' ? ' : ' . trim((string) $p['constat']) : ''); }
    return implode("\n", $l);
}

/** POST /helpdesk/missions/{id} {points[{etat, constat}], cloturer?, repondre?} */
function wr_helpdesk_mission(int $mid): array
{
    hdkTable();
    $m = Db::row('SELECT * FROM con_hdk_mission WHERE id = ?', [$mid]);
    if ($m === null) { http_response_code(404); return ['ok' => false, 'error' => 'Mission inconnue.']; }
    if ($m['cloture_le'] !== null) { return ['ok' => false, 'error' => 'Cette mission est déjà clôturée.']; }
    $b = body();
    $pts = json_decode((string) ($m['points'] ?? '[]'), true) ?: [];
    foreach ((array) ($b['points'] ?? []) as $i => $p) {
        if (!isset($pts[$i]) || !is_array($p)) { continue; }
        if (array_key_exists('etat', $p)) { $pts[$i]['etat'] = in_array($p['etat'], HDK_ETATS, true) ? $p['etat'] : null; }
        if (array_key_exists('constat', $p)) { $pts[$i]['constat'] = mb_substr(trim((string) $p['constat']), 0, 1000); }
    }
    $now = date('Y-m-d H:i:s');
    Db::exec('UPDATE con_hdk_mission SET points = ?, maj_le = ? WHERE id = ?', [json_encode($pts, JSON_UNESCAPED_UNICODE), $now, $mid]);
    if (empty($b['cloturer'])) { return ['ok' => true, 'missions' => hdkMissions([(int) $m['case_id']])[(int) $m['case_id']] ?? []]; }
    if (array_filter($pts, static fn ($p) => ($p['etat'] ?? null) === null) !== []) { return ['ok' => false, 'error' => 'Chaque point doit être vu (conforme, à suivre ou non conforme) avant de clôturer.']; }
    $v = Db::row('SELECT prevu_le, consultant_nom FROM con_visite WHERE id = ?', [(int) $m['visite_id']]) ?? [];
    $texte = hdkConstat($pts, (string) ($v['prevu_le'] ?? ''), (string) ($v['consultant_nom'] ?? ''));
    $tout = array_filter($pts, static fn ($p) => $p['etat'] !== 'conforme') === [];
    $statut = $tout ? 'RESOLVED' : 'IN_PROGRESS';
    $err = null;
    if (!empty($b['repondre'])) {
        if (!hdkAdmin()) { return ['ok' => false, 'error' => 'Le compte administrateur du cockpit (Paramètres › ERP) n’est pas configuré : la réponse ne peut pas partir.']; }
        $r = hdkEnvoi('PATCH', '/cases/' . (int) $m['case_id'] . '/reply', ['admin_reply' => mb_substr($texte, 0, 5000)]);
        if (!$r['ok']) { return $r; }
        $s = hdkEnvoi('PATCH', '/cases/' . (int) $m['case_id'] . '/status', ['status' => $statut]);
        if (!$s['ok']) { $err = 'Réponse envoyée, mais le statut n’a pas suivi : ' . $s['error']; }
    }
    Db::exec('UPDATE con_hdk_mission SET cloture_le = ?, reponse = ?, maj_le = ? WHERE id = ?', [$now, $texte, $now, $mid]);
    foreach (array_filter(array_map('intval', explode(',', (string) $m['tache_ids']))) as $t) { Db::exec('UPDATE con_consultant_tache SET statut = \'fait\', fait_le = ?, maj_le = ? WHERE id = ?', [$now, $now, $t]); }
    hdkJournal('Mission ' . $m['case_ref'] . ' clôturée : ' . ($tout ? 'résolue' : 'à suivre') . (!empty($b['repondre']) ? ', constat envoyé au franchisé' : ''));
    return ['ok' => true, 'statut' => $statut, 'reponse' => $texte, 'avertissement' => $err, 'missions' => hdkMissions([(int) $m['case_id']])[(int) $m['case_id']] ?? []];
}

/* ---------------------------------------------------------------------------------------------
 * La date limite (20/11/2026)
 * Demande de Sam : « lors de la réception, le consultant met une date limite (2, 5, 10, 30 j, +90) qu'il est censé
 * respecter suivant ses demandes ». Gardée au cockpit (con_hdk_demande), le panel n'a pas de champ pour elle. Une demande
 * ouverte au-delà de sa date est en retard ; le franchisé voit la date (« réponse prévue d'ici le … »).
 * ------------------------------------------------------------------------------------------- */

/** Les dates limites d'une liste de demandes : [case_id => {delai, echeance, par, le}]. */
function hdkEcheances(array $caseIds): array
{
    $ids = array_values(array_filter(array_map('intval', $caseIds)));
    if ($ids === []) { return []; }
    hdkTable();
    $out = [];
    try {
        foreach (Db::rows('SELECT case_id, delai_jours, echeance, echeance_par, echeance_le FROM con_hdk_demande WHERE echeance IS NOT NULL AND case_id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['case_id']] = ['delai' => $r['delai_jours'] !== null ? (int) $r['delai_jours'] : null, 'echeance' => substr((string) $r['echeance'], 0, 10),
                'par' => (string) $r['echeance_par'], 'le' => $r['echeance_le'] !== null ? (string) $r['echeance_le'] : null];
        }
    } catch (Throwable $e) { /* pas de dates limites lisibles */ }
    return $out;
}

/** La date limite d'une demande, telle que le cockpit la montre : jours restants, retard (ouverte au-delà). Fonction pure. */
function hdkEcheanceVue(?array $e, bool $ouverte, ?string $auj = null): array
{
    if ($e === null || ($e['echeance'] ?? '') === '') { return ['echeance' => null, 'delai' => null, 'echeancePar' => null, 'echeanceLe' => null, 'joursRestants' => null, 'enRetard' => false]; }
    $auj = $auj ?? date('Y-m-d');
    $reste = (int) round((strtotime($e['echeance'] . ' 12:00') - strtotime($auj . ' 12:00')) / 86400);
    return ['echeance' => $e['echeance'], 'delai' => $e['delai'] ?? null, 'echeancePar' => ($e['par'] ?? '') !== '' ? $e['par'] : null, 'echeanceLe' => $e['le'] ?? null,
        'joursRestants' => $reste, 'enRetard' => $ouverte && $reste < 0];
}

/**
 * POST /helpdesk/demandes/{id}/delai {jours, statut?, shop?, ref?} — la date limite, aujourd'hui + 2, 5, 10, 30 ou 90
 * jours ; `jours` 0 la retire. Une demande encore « Nouvelle » (`statut` NEW) passe « En cours » : elle est reçue.
 */
function wr_helpdesk_delai(int $id): array
{
    hdkTable();
    $b = body();
    $j = (int) ($b['jours'] ?? -1);
    if ($id <= 0) { http_response_code(422); return ['ok' => false, 'error' => 'Demande inconnue.']; }
    $ref = trim((string) ($b['ref'] ?? '')) ?: (string) $id;
    $existe = Db::row('SELECT case_id FROM con_hdk_demande WHERE case_id = ?', [$id]) !== null;
    if ($j === 0) {
        if ($existe) { Db::exec('UPDATE con_hdk_demande SET delai_jours = NULL, echeance = NULL, echeance_par = \'\', echeance_le = NULL WHERE case_id = ?', [$id]); }
        hdkJournal('Demande ' . $ref . ' : date limite retirée');
        return ['ok' => true, 'echeance' => null, 'delai' => null];
    }
    if (!in_array($j, HDK_DELAIS, true)) { http_response_code(422); return ['ok' => false, 'error' => 'Délai inconnu : ' . implode(', ', HDK_DELAIS) . ' jours.']; }
    $ech = date('Y-m-d', strtotime('+' . $j . ' days'));
    $qui = function_exists('accesIdentite') ? trim((string) ((accesIdentite() ?? [])['nom'] ?? '')) : '';
    $qui = mb_substr($qui !== '' ? $qui : 'Cockpit', 0, 120);
    $now = date('Y-m-d H:i:s');
    if ($existe) { Db::exec('UPDATE con_hdk_demande SET delai_jours = ?, echeance = ?, echeance_par = ?, echeance_le = ? WHERE case_id = ?', [$j, $ech, $qui, $now, $id]); }
    else {
        Db::exec('INSERT INTO con_hdk_demande (case_id, shop_id, passage, photos, cree_le, delai_jours, echeance, echeance_par, echeance_le) VALUES (?,?,?,?,?,?,?,?,?)',
            [$id, preg_replace('/\D/', '', (string) ($b['shop'] ?? '')) ?? '', 0, null, $now, $j, $ech, $qui, $now]);
    }
    $avert = null;
    if ((string) ($b['statut'] ?? '') === 'NEW' && hdkAdmin()) {
        $s = hdkEnvoi('PATCH', '/cases/' . $id . '/status', ['status' => 'IN_PROGRESS']);
        if (!$s['ok']) { $avert = 'Date limite enregistrée, mais la demande n’est pas passée « En cours » : ' . $s['error']; }
    }
    hdkJournal('Demande ' . $ref . ' : date limite à ' . $j . ' j, avant le ' . date('d/m/Y', strtotime($ech)) . ' (' . $qui . ')');
    return ['ok' => true, 'echeance' => $ech, 'delai' => $j, 'avertissement' => $avert];
}

/* ---------------------------------------------------------------------------------------------
 * Le dashboard franchisé
 * ------------------------------------------------------------------------------------------- */

/** GET /helpdesk/magasin?shop= — les domaines et les demandes du magasin. */
function ep_helpdesk_magasin(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    $r = PanelApi::get('/shops/' . $shop . '/cases');
    if (!is_array($r) && hdkAdmin()) { $r = PanelApi::get('/shops/' . $shop . '/cases'); }
    if (!is_array($r)) { return ['domaines' => HDK_DOMAINES, 'demandes' => [], 'erreur' => 'Vos demandes n’ont pas pu être lues (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $D = array_values(array_filter(array_map('hdkDemande', array_values(array_filter(PanelApi::liste($r) ?: (array_is_list($r) ? $r : []), 'is_array'))), static fn ($d) => $d['shop'] === '' || $d['shop'] === (string) $shop));
    usort($D, static fn ($a, $b) => strcmp($b['cree'], $a['cree']));
    $M = hdkMissions(array_column($D, 'id'));
    $E = hdkEcheances(array_column($D, 'id'));
    foreach ($D as &$d) {
        $d['echeance'] = $E[$d['id']]['echeance'] ?? null;   // « réponse prévue d'ici le … » ; ni qui l'a fixée ni le retard
        $ms = array_values(array_filter($M[$d['id']] ?? [], static fn ($x) => $x['cloture'] === null));
        $d['visite'] = $ms !== [] ? ['le' => $ms[0]['visiteLe'], 'consultant' => $ms[0]['consultant']] : null;
        unset($d['demandeur']);
    }
    unset($d);
    return ['domaines' => HDK_DOMAINES, 'demandes' => $D, 'lu' => date('c')];
}

/** Une photo de la demande (data-URL JPEG, PNG ou WebP, 6 Mo au plus) sous public/uploads/helpdesk, nom aléatoire. */
function hdkPhoto(string $data, int $shop): array
{
    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { return ['error' => 'photo illisible']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 64 || strlen($bin) > 6 * 1024 * 1024) { return ['error' => 'photo illisible ou trop lourde (6 Mo au plus)']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { return ['error' => 'format non accepté (JPEG, PNG ou WebP)']; }
    $chemin = 'uploads/helpdesk/' . $shop . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $abs = __DIR__ . '/../../public/' . $chemin;
    if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) { return ['error' => 'dossier des photos impossible à créer']; }
    if (@file_put_contents($abs, $bin) === false) { return ['error' => 'écriture de la photo impossible']; }
    return ['chemin' => $chemin];
}

/** POST /helpdesk/magasin {shop, domaine, titre, description, passage, photos[]} — la demande part au bureau. */
function wr_helpdesk_magasin(): array
{
    hdkTable();
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $dom = (string) ($b['domaine'] ?? '');
    $titre = mb_substr(trim((string) ($b['titre'] ?? '')), 0, 190);
    $desc = trim((string) ($b['description'] ?? ''));
    if ($shop <= 0 || !isset(HDK_DOMAINES[$dom])) { http_response_code(422); return ['ok' => false, 'error' => 'Choisissez le sujet de la demande.']; }
    if ($titre === '' || $desc === '') { http_response_code(422); return ['ok' => false, 'error' => 'Le titre et la demande sont requis.']; }
    $liens = [];
    foreach (array_slice((array) ($b['photos'] ?? []), 0, 4) as $p) {
        $x = hdkPhoto((string) $p, $shop);
        if (isset($x['error'])) { return ['ok' => false, 'error' => 'Photo : ' . $x['error']]; }
        $liens[] = $x['chemin'];
    }
    $base = rtrim((string) (function_exists('setting') ? (setting('urlPublique', '') ?: '') : ''), '/');
    if ($base === '' && !empty($_SERVER['HTTP_HOST'])) { $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . preg_replace('#/api/.*$#', '', (string) ($_SERVER['SCRIPT_NAME'] ?? '')); }
    $texte = mb_substr($desc, 0, 8000);
    if (!empty($b['passage'])) { $texte .= "\n\n— Passage du consultant souhaité à la prochaine visite."; }
    if ($liens !== []) { $texte .= "\n\nPhotos :\n" . implode("\n", array_map(static fn ($c) => $base . '/' . $c, $liens)); }
    $corps = ['area_key' => $dom, 'title' => $titre, 'description' => $texte, 'shop_id' => $shop];
    $r = hdkEnvoi('POST', '/cases', $corps);
    if (!$r['ok'] && hdkAdmin()) { $r = hdkEnvoi('POST', '/cases', $corps); }
    if (!$r['ok']) { return $r; }
    $c = is_array($r['res']) ? (vrcDeballer($r['res'], ['data', 'case']) ?? $r['res']) : [];
    $id = (int) ($c['id'] ?? 0);
    if ($id > 0) { Db::exec('INSERT INTO con_hdk_demande (case_id, shop_id, passage, photos, cree_le) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE passage = VALUES(passage)', [$id, (string) $shop, !empty($b['passage']) ? 1 : 0, json_encode($liens), date('Y-m-d H:i:s')]); }
    // Le SMS au bureau (20/11/2026) : la demande est enregistrée ; un SMS qui ne part pas ne la fait jamais échouer.
    $sms = '';
    try {
        $s = hdkSmsBureau(['shop' => (string) $shop, 'magasin' => trim((string) ($c['shop_name'] ?? '')), 'domaine' => HDK_DOMAINES[$dom], 'titre' => $titre, 'description' => $desc,
            'passage' => !empty($b['passage']), 'photos' => count($liens), 'ref' => (string) ($c['ref'] ?? ''), 'demandeur' => trim((string) ($c['requester_name'] ?? ''))]);
        $sms = ' · ' . $s['resume'];
    } catch (Throwable $e) { $sms = ' · SMS au bureau non parti : ' . $e->getMessage(); }
    if (function_exists('journalAdd')) { journalAdd('Magasin ' . $shop, 'Helpdesk', null, 'Nouvelle demande au bureau : ' . $titre . $sms); }
    return ['ok' => true, 'id' => $id, 'ref' => (string) ($c['ref'] ?? '')];
}

/* ---------------------------------------------------------------------------------------------
 * Le SMS au bureau (20/11/2026)
 * Demande de Sam : « fais en sorte que je reçoive un SMS (+48725038049) et Pierre Boseret avec un résumé de la demande
 * quand un franchisé en fait une ». À chaque demande envoyée depuis le dashboard franchisé, un SMS part à chaque numéro
 * du réglage `helpdeskSms` (cockpit › Demandes des franchisés › SMS au bureau), par le fournisseur en place
 * (shared/src/sms.php : SMSAPI, sinon Twilio). Le dernier envoi se garde (`helpdeskSmsDernier`) et se lit au cockpit.
 * Le franchisé ne voit ni les numéros ni le résultat de l'envoi.
 * ------------------------------------------------------------------------------------------- */

/** Le réglage de départ, tant que le cockpit n'en a pas enregistré : Sam, et Pierre Boseret (numéro à compléter). */
const HDK_SMS_DEFAUT = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '']]];
const HDK_SMS_RESUME = 220;   // caractères de la demande repris dans le SMS (le SMS entier tient en trois SMS de 153)

/** Les destinataires, nettoyés : un nom, un numéro tel que tapé ; une ligne sans nom ni numéro tombe ; 10 au plus. */
function hdkSmsDestinataires(mixed $liste): array
{
    $out = [];
    foreach (is_array($liste) ? $liste : [] as $x) {
        if (!is_array($x)) { continue; }
        $nom = mb_substr(trim((string) ($x['nom'] ?? '')), 0, 80);
        $tel = mb_substr(trim((string) ($x['tel'] ?? '')), 0, 32);
        if ($nom === '' && $tel === '') { continue; }
        $out[] = ['nom' => $nom, 'tel' => $tel];
    }
    return array_slice($out, 0, 10);
}

/** Le réglage tel qu'il s'applique : {actif, destinataires[{nom, tel}]}. */
function hdkSmsReglage(): array
{
    $s = setting('helpdeskSms', null);
    $s = is_array($s) ? $s : HDK_SMS_DEFAUT;
    return ['actif' => array_key_exists('actif', $s) ? (bool) $s['actif'] : true, 'destinataires' => hdkSmsDestinataires($s['destinataires'] ?? [])];
}

/**
 * Un texte ramené à l'alphabet des SMS (GSM 03.38) : apostrophes et guillemets droits, accents hors alphabet sans
 * accent (ê → e, ç → c), le reste (émojis…) retiré. Un seul caractère hors alphabet fait passer tout le SMS à 70
 * caractères au lieu de 160. Fonction pure.
 */
function hdkGsm(string $t): string
{
    $t = (string) preg_replace(['/«[\s\x{00A0}\x{202F}]*/u', '/[\s\x{00A0}\x{202F}]*»/u'], ['"', '"'], $t);   // « fête » → "fete"
    $t = strtr($t, ['’' => "'", '‘' => "'", 'ʼ' => "'", '´' => "'", '`' => "'", '“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"', '…' => '...', '–' => '-', '—' => '-', '•' => '-',
        "\u{00A0}" => ' ', "\u{202F}" => ' ', "\t" => ' ', "\r" => '', 'œ' => 'oe', 'Œ' => 'OE',
        'â' => 'a', 'á' => 'a', 'ã' => 'a', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ó' => 'o', 'õ' => 'o', 'û' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ç' => 'c',
        'À' => 'A', 'Â' => 'A', 'Á' => 'A', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Û' => 'U', 'Ù' => 'U', 'Ÿ' => 'Y']);
    $t = (string) preg_replace('/[^A-Za-z0-9 \n@£$¥èéùìòÇØøÅåÆæßÉÄÖÑÜäöñüà§¿¡!"#%&\'()*+,\-.\/:;<=>?_€\[\]{}~^|\\\\]/u', '', $t);
    return trim((string) preg_replace(['/ {2,}/', '/ *\n */', '/\n{3,}/'], [' ', "\n", "\n\n"], $t));
}

/** Les premiers mots de la demande, sur une ligne, coupés à `$max` caractères entre deux mots. Fonction pure. */
function hdkResume(string $t, int $max = HDK_SMS_RESUME): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    if (mb_strlen($t) <= $max) { return $t; }
    $c = mb_substr($t, 0, $max);
    $p = mb_strrpos($c, ' ');
    return rtrim($p !== false && $p > $max * 0.6 ? mb_substr($c, 0, $p) : $c, " ,;:.-") . '...';
}

/**
 * Le SMS d'une demande : le magasin, le sujet, qui l'envoie, le titre, le début de la demande, le passage souhaité, les
 * photos, la référence, le lien du cockpit. Fonction pure.
 *
 * @param array{magasin: string, domaine: string, titre: string, description: string, passage?: bool, photos?: int, ref?: string, demandeur?: string, lien?: string} $d
 */
function hdkSmsTexte(array $d): string
{
    $suite = [];
    if (!empty($d['passage'])) { $suite[] = 'Passage du consultant souhaité.'; }
    $n = (int) ($d['photos'] ?? 0);
    if ($n > 0) { $suite[] = $n . ' photo' . ($n > 1 ? 's' : '') . '.'; }
    $ref = preg_replace('/^CASE-/', '', (string) ($d['ref'] ?? ''));
    if ($ref !== '') { $suite[] = 'Réf. ' . $ref; }
    $l = ['Demande au bureau - ' . $d['magasin'] . ' (' . $d['domaine'] . ')' . (trim((string) ($d['demandeur'] ?? '')) !== '' ? ', de ' . trim((string) $d['demandeur']) : ''),
        $d['titre'], hdkResume($d['description'])];
    if ($suite !== []) { $l[] = implode(' ', $suite); }
    if (trim((string) ($d['lien'] ?? '')) !== '') { $l[] = trim((string) $d['lien']); }
    return hdkGsm(implode("\n", array_filter($l, static fn ($x) => trim((string) $x) !== '')));
}

/**
 * Le SMS d'une nouvelle demande à chaque destinataire du réglage (un numéro en double ne reçoit qu'un SMS). Rend
 * {texte, destinataires[{nom, ok, motif}], resume} ; jamais d'exception de l'envoi lui-même.
 */
function hdkSmsBureau(array $d): array
{
    $R = hdkSmsReglage();
    $out = ['texte' => '', 'destinataires' => [], 'resume' => ''];
    if (!$R['actif']) { $out['resume'] = 'SMS au bureau coupés'; return $out; }
    if (!function_exists('smsPartir')) { $out['resume'] = 'SMS au bureau : module SMS absent'; return $out; }
    if (trim((string) ($d['magasin'] ?? '')) === '') {
        try { $d['magasin'] = function_exists('viMagasins') ? (viMagasins()[(string) ($d['shop'] ?? '')]['court'] ?? '') : ''; } catch (Throwable $e) { $d['magasin'] = ''; }
    }
    $d['magasin'] = preg_replace('/^.* - /', '', trim((string) $d['magasin'])) ?: ('magasin ' . ($d['shop'] ?? '?'));
    $base = function_exists('rapBaseUrl') ? rtrim(rapBaseUrl(), '/') : '';
    $d['lien'] = $base !== '' ? $base . '/#/franchises/demandes' : '';
    $out['texte'] = $texte = hdkSmsTexte($d);
    $vus = [];
    foreach ($R['destinataires'] as $x) {
        $nom = $x['nom'] !== '' ? $x['nom'] : $x['tel'];
        $num = smsNorme($x['tel']);
        if ($num === null) { $out['destinataires'][] = ['nom' => $nom, 'ok' => false, 'motif' => $x['tel'] === '' ? 'pas de numéro' : 'numéro illisible']; continue; }
        if (isset($vus[$num])) { continue; }
        $vus[$num] = true;
        $r = smsPartir($num, $texte);
        $out['destinataires'][] = ['nom' => $nom, 'ok' => (bool) $r['ok'], 'motif' => $r['erreur']];
    }
    $ok = array_column(array_filter($out['destinataires'], static fn ($x) => $x['ok']), 'nom');
    $ko = array_map(static fn ($x) => $x['nom'] . ' (' . $x['motif'] . ')', array_filter($out['destinataires'], static fn ($x) => !$x['ok']));
    $out['resume'] = $out['destinataires'] === [] ? 'SMS au bureau : aucun destinataire' : trim(($ok ? 'SMS à ' . implode(', ', $ok) : '') . ($ko ? ($ok ? ' ; ' : '') . 'SMS non parti : ' . implode(', ', $ko) : ''));
    try {
        settingEcrire('helpdeskSmsDernier', ['le' => date('c'), 'ref' => (string) ($d['ref'] ?? ''), 'magasin' => $d['magasin'], 'titre' => (string) $d['titre'], 'destinataires' => $out['destinataires']]);
    } catch (Throwable $e) { /* le journal garde la trace */ }
    return $out;
}

/** GET /helpdesk/sms — le réglage des SMS au bureau, le fournisseur en place, le dernier envoi. */
function ep_helpdesk_sms(): array
{
    $R = hdkSmsReglage();
    $R['destinataires'] = array_map(static fn ($x) => $x + ['lisible' => ($n = smsNorme($x['tel'])) !== null ? smsLisible($n) : null], $R['destinataires']);
    $dernier = setting('helpdeskSmsDernier', null);
    return $R + ['fournisseur' => function_exists('smsFournisseur') ? smsFournisseur() : null, 'dernier' => is_array($dernier) ? $dernier : null];
}

/** PUT /helpdesk/sms {actif, destinataires[{nom, tel}]} — le réglage des SMS au bureau. Un numéro illisible est refusé. */
function wr_helpdesk_sms(): array
{
    $b = body();
    $dest = hdkSmsDestinataires($b['destinataires'] ?? []);
    foreach ($dest as $x) {
        if ($x['tel'] !== '' && smsNorme($x['tel']) === null) { http_response_code(422); return ['ok' => false, 'error' => 'Numéro illisible : ' . $x['tel'] . ' (format international, par exemple +32 470 12 34 56).']; }
    }
    $s = ['actif' => array_key_exists('actif', $b) ? (bool) $b['actif'] : true, 'destinataires' => $dest];
    settingEcrire('helpdeskSms', $s);
    hdkJournal('SMS au bureau : ' . ($s['actif'] ? 'envoyés à ' . (implode(', ', array_map(static fn ($x) => $x['nom'] !== '' ? $x['nom'] : $x['tel'], $dest)) ?: 'personne') : 'coupés'));
    return ['ok' => true] + ep_helpdesk_sms();
}
