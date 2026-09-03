<?php
declare(strict_types=1);

/**
 * Cockpit CEO — routes d'écriture.
 * Chaque écriture journalise dans ceo_journal_entry (l'écran Journal en dépend).
 */

function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void
{
    Db::exec('INSERT INTO ceo_journal_entry (happened_at, actor, kind, project, message) VALUES (?,?,?,?,?)',
        [$ts ?? date('Y-m-d H:i:s'), $actor, $kind, $project ?? '—', $message]);
}

/** POST /journal — trace une action du cockpit. */
function wr_journal(): array
{
    $b = body();
    journalAdd((string) ($b['qui'] ?? 'CEO'), (string) ($b['type'] ?? 'Action'), $b['projet'] ?? null, (string) ($b['msg'] ?? ''), $b['ts'] ?? null);
    return ['ok' => true];
}

/** POST /projects — assistant « Nouveau projet » (4 étapes). */
function wr_project_create(): array
{
    $b = body();
    $id = $b['id'] ?? ('px' . substr((string) round(microtime(true) * 1000), -8));
    Db::exec('INSERT INTO ceo_project (id, name, famille, status, priority, axe, axes_json, kpis_json, value_txt, starts_on, ends_on, budget, value_est, value_real, economie_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)', [
        $id, (string) $b['nom'], (string) ($b['famille'] ?? 'Organisation & coûts'), (string) ($b['statut'] ?? 'À lancer'),
        (string) ($b['prio'] ?? 'Moyenne'), (string) ($b['axes'][0] ?? ''), json_encode($b['axes'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($b['kpis'] ?? [], JSON_UNESCAPED_UNICODE), (string) ($b['valeurTxt'] ?? ''),
        $b['debut'] ?? null, $b['fin'] ?? null, $b['budget'] ?? null, $b['valeurEst'] ?? null,
        // Les hypothèses économiques voyagent telles quelles : on n'en garde
        // que les nombres, et seulement ceux que l'écran a posés.
        isset($b['economie']) && is_array($b['economie'])
            ? json_encode(array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $b['economie']), JSON_UNESCAPED_UNICODE)
            : null,
    ]);
    $slugToTag = [];
    foreach (LEVIER_DEFS as $l) { $slugToTag[$l['slug']] = $l['tag']; }
    foreach ($b['leviers'] ?? [] as $slug) {
        if (isset($slugToTag[$slug])) {
            Db::exec('INSERT IGNORE INTO ceo_project_levid VALUES (?,?)', [$id, $slugToTag[$slug]]);
        }
    }
    foreach (array_values($b['jalons'] ?? []) as $i => $j) {
        Db::exec('INSERT INTO ceo_project_milestone (project_id, name, target_on, done_on, sort_order) VALUES (?,?,?,?,?)',
            [$id, (string) $j['nom'], $j['cible'] ?? null, $j['reel'] ?? null, $i]);
    }
    foreach ($b['couts'] ?? [] as $c) {
        Db::exec('INSERT INTO ceo_project_cost (project_id, label, planned, actual) VALUES (?,?,?,?)',
            [$id, (string) $c['poste'], (float) ($c['prevu'] ?? 0), (float) ($c['reel'] ?? 0)]);
    }
    foreach ($b['taches'] ?? [] as $i => $t) {
        Db::exec('INSERT INTO ceo_project_task (id, project_id, name, owner_kind, owner_id, shop_id, due_on, done_on) VALUES (?,?,?,?,?,?,?,NULL)',
            [$t['id'] ?? ('t' . $id . $i), $id, (string) $t['nom'], (string) $t['owner']['t'], (string) $t['owner']['id'], $t['magasinId'] ?? null, $t['due'] ?? null]);
    }
    journalAdd('CEO', 'Création', (string) $b['nom'], $b['journal'] ?? ('Projet créé — statut « À lancer »'));
    return ['ok' => true, 'id' => $id];
}

/**
 * DELETE /projects/{id} — supprime le projet et tout son suivi.
 *
 * Purge en cascade dans l'ordre des dépendances (signalements → tâches, puis
 * jalons / coûts / leviers / CRM, enfin le projet). Le journal est CONSERVÉ :
 * c'est une trace d'audit, la suppression y ajoute une ligne au lieu d'en
 * retirer. Tout est encapsulé dans une transaction.
 */
function wr_project_delete(string $id): array
{
    $p = Db::row('SELECT name FROM ceo_project WHERE id = ?', [$id]);
    if ($p === null) { http_response_code(404); return ['error' => 'projet inconnu']; }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        Db::exec('DELETE FROM ceo_task_issue WHERE task_id IN (SELECT id FROM ceo_project_task WHERE project_id = ?)', [$id]);
        Db::exec('DELETE FROM ceo_project_task WHERE project_id = ?', [$id]);
        Db::exec('DELETE FROM ceo_project_milestone WHERE project_id = ?', [$id]);
        Db::exec('DELETE FROM ceo_project_cost WHERE project_id = ?', [$id]);
        Db::exec('DELETE FROM ceo_project_levid WHERE project_id = ?', [$id]);
        Db::exec('DELETE FROM ceo_project_crm WHERE project_id = ?', [$id]);
        Db::exec('DELETE FROM ceo_project WHERE id = ?', [$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    journalAdd('CEO', 'Suppression', $p['name'], 'Projet « ' . $p['name'] . ' » supprimé (fiche projet)');
    return ['ok' => true];
}

/**
 * POST /pwa/tasks/validate — l'Owner (CEO) valide ou retire un avis consultant.
 *
 * Écrit la validation dans la table partagée du panel `mac_task_review` (mêmes
 * colonnes que setOwnerValidation côté panel). Ne CRÉE jamais de ligne : on ne
 * valide que ce qui a été évalué — 0 ligne touchée ⇒ aucun avis à valider.
 * L'identité de l'Owner est l'utilisateur du cockpit (réglage `utilisateur`).
 */
function wr_pwa_task_validate(): array
{
    $b = body();
    $shopId = (int) ($b['shopId'] ?? $b['shop_id'] ?? 0);
    $taskId = (int) ($b['taskId'] ?? $b['task_id'] ?? 0);
    $date   = (string) ($b['date'] ?? $b['review_date'] ?? '');
    $on     = !empty($b['validated']);
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }

    // Colonnes de validation Owner : ajoutées seulement si une version
    // antérieure du panel a créé la table sans elles (tolérant, idempotent).
    try {
        $have = [];
        foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mac_task_review'") as $r2) {
            $have[strtolower((string) $r2['COLUMN_NAME'])] = true;
        }
        foreach (['owner_validated_at' => 'DATETIME NULL', 'id_owner' => 'BIGINT UNSIGNED NULL', 'owner_name' => 'VARCHAR(190) NULL'] as $col => $type) {
            if (!isset($have[$col])) {
                try { Db::exec("ALTER TABLE mac_task_review ADD COLUMN `$col` $type"); } catch (Throwable $e) { /* course : déjà ajoutée */ }
            }
        }
    } catch (Throwable $e) { /* information_schema indisponible : l'UPDATE tranchera */ }

    $u = setting('utilisateur', []);
    $ownerName = is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 190) : 'CEO';

    try {
        $n = Db::exec(
            'UPDATE mac_task_review SET owner_validated_at = ?, id_owner = ?, owner_name = ?, updated_at = ?'
            . ' WHERE id_shop = ? AND id_task = ? AND review_date = ?',
            [$on ? date('Y-m-d H:i:s') : null, $on ? 0 : null, $on ? $ownerName : null, date('Y-m-d H:i:s'), $shopId, $taskId, $date]
        );
    } catch (PDOException $e) {
        http_response_code(503);
        return ['error' => 'table des avis (mac_task_review) indisponible'];
    }
    if ($n === 0) {
        http_response_code(422);
        return ['error' => 'aucun avis consultant à valider pour cette tâche à cette date'];
    }
    journalAdd('CEO', 'Validation', null, ($on ? 'Avis validé' : 'Validation retirée')
        . ' — boutique #' . $shopId . ', tâche #' . $taskId . ' (' . $date . ')');
    return ['ok' => true, 'validated' => $on, 'by' => $on ? $ownerName : null, 'at' => $on ? date('Y-m-d H:i:s') : null];
}

/**
 * PUT /pwa/compte — identifiants du compte consultant utilisé par le cockpit
 * pour lire les tâches/photos et déposer les notes sur l'API du panel.
 *
 * Le mot de passe doit rester réutilisable (l'API exige téléphone + mot de
 * passe à chaque connexion) : il est donc stocké tel quel dans
 * `ceo_app_setting`, et n'est JAMAIS renvoyé par une lecture — l'écran ne voit
 * que « défini / non défini ». Laisser le champ vide conserve le mot de passe
 * existant (on ne l'efface pas par inadvertance en modifiant le téléphone).
 */
function wr_pwa_compte(): array
{
    $b = body();
    $cur = setting('panelApi', []);
    if (!is_array($cur)) { $cur = []; }
    $conf = [
        'base'  => trim((string) ($b['base'] ?? $cur['base'] ?? '')),
        'phone' => trim((string) ($b['phone'] ?? $cur['phone'] ?? '')),
        'password' => (string) ($b['password'] ?? ''),
    ];
    if ($conf['password'] === '') { $conf['password'] = (string) ($cur['password'] ?? ''); }
    if ($conf['base'] === '') { unset($conf['base']); }               // → défaut du client

    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['panelApi', json_encode($conf, JSON_UNESCAPED_UNICODE)]);
    PanelApi::oublierJeton();
    journalAdd('CEO', 'Paramètre', null, 'Compte consultant de l’API panel mis à jour ('
        . ($conf['phone'] !== '' ? $conf['phone'] : 'téléphone vide') . ')');

    // Test immédiat : un réglage enregistré mais refusé doit se voir tout de suite.
    [$ok, $msg] = PanelApi::tester();
    connecteurNote('panel', $ok, $msg);
    return ['ok' => true, 'testOk' => $ok, 'message' => $msg, 'statut' => PanelApi::statut()];
}

/** POST /pwa/compte/test — vérifie la connexion sans rien modifier. */
function wr_pwa_compte_test(): array
{
    [$ok, $msg] = PanelApi::tester();
    connecteurNote('panel', $ok, $msg);
    return ['ok' => $ok, 'message' => $msg, 'statut' => PanelApi::statut()];
}

/** PUT /erp/compte — compte ADMIN de l'API ERP (reprise de l'assistant). */
function wr_erp_compte(): array
{
    $b = body();
    $cur = setting('erpAdmin', []);
    if (!is_array($cur)) { $cur = []; }
    $conf = [
        'base'  => trim((string) ($b['base'] ?? $cur['base'] ?? '')),
        'phone' => trim((string) ($b['phone'] ?? $cur['phone'] ?? '')),
        'password' => (string) ($b['password'] ?? ''),
    ];
    if ($conf['password'] === '') { $conf['password'] = (string) ($cur['password'] ?? ''); }
    if ($conf['base'] === '') { unset($conf['base']); }               // → défaut du client

    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['erpAdmin', json_encode($conf, JSON_UNESCAPED_UNICODE)]);
    ErpApi::oublierJeton();
    journalAdd('CEO', 'Paramètre', null, 'Compte admin de l’API ERP mis à jour ('
        . ($conf['phone'] !== '' ? $conf['phone'] : 'téléphone vide') . ')');

    // Test immédiat : un réglage enregistré mais refusé doit se voir tout de suite.
    [$ok, $msg] = ErpApi::tester();
    connecteurNote('erp', $ok, $msg);
    return ['ok' => true, 'testOk' => $ok, 'message' => $msg, 'statut' => ErpApi::statut()];
}

/** POST /erp/compte/test — vérifie la connexion sans rien modifier. */
function wr_erp_compte_test(): array
{
    [$ok, $msg] = ErpApi::tester();
    connecteurNote('erp', $ok, $msg);
    return ['ok' => $ok, 'message' => $msg, 'statut' => ErpApi::statut()];
}

/**
 * PUT /centrale/fournisseur-pct { id, marge?, redevance? } — les pourcentages
 * d'un fournisseur : marge centrale → franchisé et redevance
 * fournisseur → centrale. Réglage du cockpit (le panel ne les porte pas),
 * saisi d'un clic sur la cellule du suivi. Une valeur vide efface.
 */
function wr_ca_fournisseur_pct(): array
{
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); return ['error' => 'id fournisseur requis']; }
    $pct = setting('caFournPct', []);
    if (!is_array($pct)) { $pct = []; }
    $cle = (string) $id;
    $cur = is_array($pct[$cle] ?? null) ? $pct[$cle] : [];
    foreach (['marge', 'redevance'] as $k) {
        if (!array_key_exists($k, $b)) { continue; }
        $v = $b[$k];
        if ($v === null || $v === '') { unset($cur[$k]); continue; }
        $n = (float) str_replace(',', '.', (string) $v);
        if ($n < 0 || $n > 100) { http_response_code(422); return ['error' => $k . ' hors échelle (0..100)']; }
        $cur[$k] = round($n, 2);
    }
    if ($cur === []) { unset($pct[$cle]); } else { $pct[$cle] = $cur; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caFournPct', json_encode($pct)]);
    journalAdd('CEO', 'Paramètre', null, 'Pourcentages du fournisseur #' . $id . ' mis à jour ('
        . (isset($cur['marge']) ? 'marge ' . $cur['marge'] . ' %' : 'marge —') . ', '
        . (isset($cur['redevance']) ? 'redevance ' . $cur['redevance'] . ' %' : 'redevance —') . ')');
    return ['ok' => true, 'id' => $id, 'pct' => $cur];
}

/**
 * POST /pwa/tasks/review — noter une tâche (note 1-5, conformité, commentaire).
 *
 * L'API du panel est la SOURCE DE VÉRITÉ (c'est elle qui porte review_rating /
 * review_is_accepted / review_comment) ; `mac_task_review` en est le journal
 * local, avec l'auteur. On écrit donc d'abord l'API : si elle refuse, on
 * n'écrit rien en base — sinon le cockpit afficherait une note que le panel
 * ignore. Mêmes champs que le panel (ChecklistController::submitReview).
 */
function wr_pwa_task_review(): array
{
    $b = body();
    $shopId = (int) ($b['shopId'] ?? 0);
    $taskId = (int) ($b['taskId'] ?? 0);
    $date   = (string) ($b['date'] ?? '');
    $note   = isset($b['note']) && $b['note'] !== null ? (int) $b['note'] : null;
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }
    if ($note === null || $note < 1 || $note > 5) {
        http_response_code(422);
        return ['error' => 'note hors échelle (1..5)'];
    }
    if (!PanelApi::configured()) {
        http_response_code(503);
        return ['error' => 'compte consultant de l’API panel non configuré (Paramètres)'];
    }

    // Conformité déduite du barème partagé (seuil du réglage `signalement`),
    // jamais d'un 4 en dur : si le seuil bouge, les deux écrans suivent.
    $sigC = setting('signalement', []);
    $seuilC = (is_array($sigC) && isset($sigC['seuil'])) ? (int) $sigC['seuil'] : 4;
    $accepte = array_key_exists('accepte', $b) ? (bool) $b['accepte'] : ($note >= $seuilC);
    // Sous le seuil, le commentaire est exigé — même règle que l'écran de
    // validation : une non-conformité sans motif ne s'analyse pas.
    if ($note < $seuilC && trim((string) ($b['comment'] ?? '')) === '') {
        http_response_code(422);
        return ['error' => 'commentaire obligatoire pour une non-conformité'];
    }
    $comment = trim((string) ($b['comment'] ?? ''));
    $payload = [
        'shop_id'       => $shopId,
        'task_id'       => $taskId,
        'review_date'   => $date,
        'rating'        => $note,
        'is_accepted'   => $accepte ? 1 : 0,
        'comment'       => $comment !== '' ? $comment : null,
    ];
    if (!empty($b['checklistId']))  { $payload['checklist_id'] = (int) $b['checklistId']; }
    if (!empty($b['completionId'])) { $payload['completion_id'] = (int) $b['completionId']; }

    [$ok, $res] = PanelApi::submitReview($shopId, $payload);
    if (!$ok) {
        http_response_code(502);
        return ['error' => 'l’API du panel a refusé la note : ' . (PanelApi::$lastError ?? 'erreur inconnue')];
    }

    // Journal local (miroir). L'auteur du cockpit est la DIRECTION : on le
    // consigne dans les colonnes owner_*, et on NE TOUCHE PAS à
    // `consultant_name` quand un avis existe déjà — sinon renoter depuis le
    // cockpit efface qui a évalué sur le terrain, et la trace du contrôle
    // remplace celle du contrôlé.
    $u = setting('utilisateur', []);
    $auteur = is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 190) : 'CEO';
    $now = date('Y-m-d H:i:s');
    try {
        $exist = Db::row('SELECT consultant_name FROM mac_task_review WHERE id_shop = ? AND id_task = ? AND review_date = ?',
            [$shopId, $taskId, $date]);
        if ($exist !== null) {
            Db::exec(
                'UPDATE mac_task_review SET rating = ?, is_accepted = ?, comment = ?,'
                . ' owner_validated_at = ?, owner_name = ?, updated_at = ?'
                . ' WHERE id_shop = ? AND id_task = ? AND review_date = ?',
                [$note, $accepte ? 1 : 0, $payload['comment'], $now, $auteur, $now, $shopId, $taskId, $date]
            );
        } else {
            // Aucun avis terrain : la direction est le premier évaluateur.
            Db::exec(
                'INSERT INTO mac_task_review (id_shop, id_checklist, id_task, review_date, completion_id,'
                . ' id_consultant, consultant_name, rating, is_accepted, comment,'
                . ' owner_validated_at, owner_name, created_at, updated_at)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$shopId, $payload['checklist_id'] ?? null, $taskId, $date, $payload['completion_id'] ?? null,
                 0, $auteur, $note, $accepte ? 1 : 0, $payload['comment'], $now, $auteur, $now, $now]
            );
        }
    } catch (PDOException $e) { /* miroir best-effort : l'API a déjà la note */ }

    journalAdd('CEO', 'Notation', null, 'Tâche #' . $taskId . ' (boutique #' . $shopId . ', ' . $date . ') notée '
        . $note . '/5 — ' . ($accepte ? 'conforme' : 'non conforme') . ($comment !== '' ? ' : ' . $comment : ''));
    return ['ok' => true, 'note' => $note, 'accepte' => $accepte];
}

/**
 * PUT /pwa/tasks/annotation — les repères posés sur une photo de contrôle.
 *
 * L'écriture est un REMPLACEMENT complet de la liste : le navigateur tient
 * l'ordre et la numérotation à l'écran, un patch repère par repère ferait
 * diverger les deux à la première suppression.
 *
 * Une liste vide EFFACE les repères — c'est le « tout effacer » de l'écran, et
 * garder une ligne à zéro laisserait un « annoté » trompeur sur la tâche.
 */
function wr_pwa_annotation(): array
{
    $b = body();
    $shopId = (int) ($b['shopId'] ?? 0);
    $taskId = (int) ($b['taskId'] ?? 0);
    $date   = (string) ($b['date'] ?? '');
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }
    $liste = annotationNormalise(is_array($b['reperes'] ?? null) ? $b['reperes'] : []);

    $u = setting('utilisateur', []);
    $auteur = is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 190) : 'CEO';
    $now = date('Y-m-d H:i:s');

    if (!$liste) {
        Db::exec('DELETE FROM ceo_task_annotation WHERE id_shop = ? AND id_task = ? AND annot_date = ?',
            [$shopId, $taskId, $date]);
        return ['ok' => true, 'n' => 0];
    }
    Db::exec('INSERT INTO ceo_task_annotation (id_shop, id_task, annot_date, reperes, auteur, maj_le)'
        . ' VALUES (?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE reperes = VALUES(reperes), auteur = VALUES(auteur), maj_le = VALUES(maj_le)',
        [$shopId, $taskId, $date, json_encode($liste, JSON_UNESCAPED_UNICODE), $auteur, $now]);
    return ['ok' => true, 'n' => count($liste), 'maj' => $now, 'auteur' => $auteur];
}

/** PATCH /projects/{id} — statut et/ou famille. */
function wr_project_patch(string $id): array
{
    $b = body();
    $p = Db::row('SELECT name, status, famille FROM ceo_project WHERE id = ?', [$id]);
    if ($p === null) { http_response_code(404); return ['error' => 'projet inconnu']; }
    if (isset($b['statut'])) {
        Db::exec('UPDATE ceo_project SET status = ? WHERE id = ?', [(string) $b['statut'], $id]);
        journalAdd('CEO', 'Statut', $p['name'], 'Statut passé de « ' . $p['status'] . ' » à « ' . $b['statut'] . ' » (fiche projet)');
    }
    if (isset($b['famille'])) {
        Db::exec('UPDATE ceo_project SET famille = ? WHERE id = ?', [(string) $b['famille'], $id]);
        journalAdd('CEO', 'Famille', $p['name'], 'Famille passée de « ' . $p['famille'] . ' » à « ' . $b['famille'] . ' » (kanban)');
    }
    return ['ok' => true];
}

/** POST /projects/{id}/tasks — assistant « Nouvelle tâche ». */
function wr_task_create(string $projectId): array
{
    $b = body();
    $p = Db::row('SELECT name FROM ceo_project WHERE id = ?', [$projectId]);
    if ($p === null) { http_response_code(404); return ['error' => 'projet inconnu']; }
    $id = $b['id'] ?? ('nt' . substr((string) round(microtime(true) * 1000), -8));
    Db::exec('INSERT INTO ceo_project_task (id, project_id, name, owner_kind, owner_id, shop_id, due_on, done_on) VALUES (?,?,?,?,?,?,?,NULL)',
        [$id, $projectId, (string) $b['nom'], (string) $b['owner']['t'], (string) $b['owner']['id'], $b['magasinId'] ?? null, $b['due'] ?? null]);
    journalAdd('CEO', 'Tâche', $p['name'], $b['journal'] ?? ('Tâche « ' . $b['nom'] . ' » créée'));
    return ['ok' => true, 'id' => $id];
}

/**
 * Le seuil de conformité — le réglage, jamais une constante.
 *
 * Une note en dessous ouvre un signalement. Le jour où 3 doit passer pour
 * acceptable, c'est une ligne de `ceo_app_setting`, pas un déploiement.
 */
function seuilSignalement(): int
{
    $c = setting('signalement', []);
    $s = is_array($c) && isset($c['seuil']) ? (int) $c['seuil'] : 4;
    return $s >= 1 && $s <= 5 ? $s : 4;
}

/** Le libellé d'un niveau, pour le journal — lu au même endroit que l'écran. */
function libelleNiveau(int $n): string
{
    $c = setting('signalement', []);
    foreach ((is_array($c) && isset($c['niveaux']) ? $c['niveaux'] : []) as $l) {
        if ((int) ($l['n'] ?? 0) === $n) { return (string) ($l['nom'] ?? $n . '/5'); }
    }
    return $n . '/5';
}

/**
 * PATCH /projects/{id}/tasks/{taskId} — done / magasinId / note + signalement.
 *
 * La note et le signalement partent dans la MÊME requête que la clôture. Une
 * tâche close dont le signalement s'est perdu en route est pire que les deux
 * ensemble : la trace du problème disparaît, la clôture reste.
 */
function wr_task_patch(string $projectId, string $taskId): array
{
    $b = body();
    $t = Db::row('SELECT t.name, t.done_on, p.name AS pname FROM ceo_project_task t JOIN ceo_project p ON p.id = t.project_id WHERE t.id = ? AND t.project_id = ?', [$taskId, $projectId]);
    if ($t === null) { http_response_code(404); return ['error' => 'tâche inconnue']; }

    // Une note hors 1..5 n'est pas une note : mieux vaut la refuser que
    // l'écrire et voir un « 0 » remonter dans les moyennes du mois.
    $note = null;
    if (array_key_exists('note', $b) && $b['note'] !== null) {
        $note = (int) $b['note'];
        if ($note < 1 || $note > 5) { http_response_code(422); return ['error' => 'note hors échelle (1..5)']; }
    }
    $seuil = seuilSignalement();
    // Sous le seuil, la famille et le type sont obligatoires. Sans eux, six
    // mois plus tard la moitié du suivi est en « Autre » et rien ne s'analyse.
    if ($note !== null && $note < $seuil) {
        $fam = trim((string) ($b['famille'] ?? ''));
        $typ = trim((string) ($b['type'] ?? ''));
        if ($fam === '' || $typ === '') {
            http_response_code(422);
            return ['error' => 'famille et type de problème obligatoires en dessous de ' . $seuil];
        }
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        if (array_key_exists('done', $b)) {
            Db::exec('UPDATE ceo_project_task SET done_on = ? WHERE id = ?', [$b['done'], $taskId]);
            journalAdd('CEO', 'Tâche', $t['pname'], 'Tâche « ' . $t['name'] . ' » ' . ($b['done'] ? 'marquée faite le ' . $b['done'] : 'rouverte'));
        }
        if (array_key_exists('magasinId', $b)) {
            Db::exec('UPDATE ceo_project_task SET shop_id = ? WHERE id = ?', [$b['magasinId'], $taskId]);
        }
        if (array_key_exists('note', $b)) {
            $par = (string) ($b['par'] ?? 'CEO');
            Db::exec('UPDATE ceo_project_task SET note = ?, validated_by = ?, validated_at = ? WHERE id = ?',
                [$note, $note === null ? null : $par, $note === null ? null : date('Y-m-d H:i:s'), $taskId]);
            if ($note !== null) {
                // La note vaut clôture : valider sans cocher laisserait la tâche
                // dans « À valider » alors qu'elle vient d'être jugée.
                if (!array_key_exists('done', $b) && $t['done_on'] === null) {
                    Db::exec('UPDATE ceo_project_task SET done_on = ? WHERE id = ?', [date('Y-m-d'), $taskId]);
                }
                journalAdd('CEO', 'Validation', $t['pname'],
                    'Tâche « ' . $t['name'] . ' » validée ' . $note . '/5 — ' . libelleNiveau($note) . ' (' . $par . ')');
            }
            if ($note !== null && $note < $seuil) {
                $copie = $b['copie'] ?? [];
                Db::exec('INSERT INTO ceo_task_issue (task_id, note, famille, type, comment, recipients, status, created_at, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [
                    $taskId, $note, (string) $b['famille'], (string) $b['type'],
                    $b['commentaire'] ?? null,
                    is_array($copie) ? implode(',', $copie) : (string) $copie,
                    'nouveau', date('Y-m-d H:i:s'), $par,
                ]);
                journalAdd('CEO', 'Signalement', $t['pname'],
                    'Signalement ouvert sur « ' . $t['name'] . ' » — ' . $b['famille'] . ' · ' . $b['type']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

/**
 * PATCH /task-issues/{id} — le cycle de vie d'un signalement.
 *
 * Trois états : `nouveau` → `vu` → `traite`. Voir n'est pas régler : le passage
 * en « vu » dit seulement que quelqu'un a lu, il ne clôt rien. Seul « traite »
 * ferme, avec sa date et son auteur.
 *
 * Rouvrir est possible et laisse une trace : un signalement clos trop vite se
 * corrige, mais pas en silence.
 */
function wr_task_issue_patch(string $issueId): array
{
    $b = body();
    $i = Db::row('SELECT i.*, t.name AS tache, p.name AS projet FROM ceo_task_issue i'
        . ' JOIN ceo_project_task t ON t.id = i.task_id'
        . ' JOIN ceo_project p ON p.id = t.project_id WHERE i.id = ?', [$issueId]);
    if ($i === null) { http_response_code(404); return ['error' => 'signalement inconnu']; }

    $statut = (string) ($b['statut'] ?? '');
    if (!in_array($statut, ['nouveau', 'vu', 'traite'], true)) {
        http_response_code(422);
        return ['error' => 'statut attendu : nouveau, vu ou traite'];
    }
    $par = (string) ($b['par'] ?? 'CEO');
    $note = trim((string) ($b['commentaire'] ?? ''));

    // Clore sans dire ce qui a été fait, c'est perdre la seule information que
    // le suivi cherchait à produire.
    if ($statut === 'traite' && $note === '') {
        http_response_code(422);
        return ['error' => 'un commentaire est obligatoire pour traiter un signalement'];
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $maintenant = date('Y-m-d H:i:s');
        if ($statut === 'vu') {
            Db::exec('UPDATE ceo_task_issue SET status = ?, seen_at = COALESCE(seen_at, ?),'
                . ' closed_at = NULL, closed_by = NULL WHERE id = ?', ['vu', $maintenant, $issueId]);
        } elseif ($statut === 'traite') {
            Db::exec('UPDATE ceo_task_issue SET status = ?, seen_at = COALESCE(seen_at, ?),'
                . ' closed_at = ?, closed_by = ?, comment = CONCAT(COALESCE(comment, \'\'), ?) WHERE id = ?',
                ['traite', $maintenant, $maintenant, $par, "\n— traité : " . $note, $issueId]);
        } else {
            Db::exec('UPDATE ceo_task_issue SET status = ?, closed_at = NULL, closed_by = NULL WHERE id = ?',
                ['nouveau', $issueId]);
        }
        $verbe = ['nouveau' => 'rouvert', 'vu' => 'marqué vu', 'traite' => 'traité'][$statut];
        journalAdd('CEO', 'Signalement', $i['projet'],
            'Signalement sur « ' . $i['tache'] . ' » ' . $verbe . ' (' . $par . ')'
            . ($note !== '' ? ' — ' . $note : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true];
}

/** PATCH /projects/{id}/milestones/{index} — date réelle du jalon. */
function wr_milestone_patch(string $projectId, int $index): array
{
    $b = body();
    $rows = Db::rows('SELECT m.id, m.name, p.name AS pname FROM ceo_project_milestone m JOIN ceo_project p ON p.id = m.project_id WHERE m.project_id = ? ORDER BY m.sort_order, m.id', [$projectId]);
    if (!isset($rows[$index])) { http_response_code(404); return ['error' => 'jalon inconnu']; }
    Db::exec('UPDATE ceo_project_milestone SET done_on = ? WHERE id = ?', [$b['reel'], $rows[$index]['id']]);
    journalAdd('CEO', 'Jalon', $rows[$index]['pname'], 'Jalon « ' . $rows[$index]['name'] . ' » ' . ($b['reel'] ? 'atteint le ' . $b['reel'] : 'rouvert'));
    return ['ok' => true];
}

/** POST /tasks/{id}/reminder — relance email d'une tâche. */
function wr_task_reminder(string $taskId): array
{
    $b = body();
    $t = Db::row('SELECT t.name, p.name AS pname FROM ceo_project_task t JOIN ceo_project p ON p.id = t.project_id WHERE t.id = ?', [$taskId]);
    if ($t === null) { http_response_code(404); return ['error' => 'tâche inconnue']; }
    Db::exec('UPDATE ceo_project_task SET reminded_on = ? WHERE id = ?', [$b['date'] ?? date('Y-m-d'), $taskId]);
    journalAdd('CEO', 'Relance', $t['pname'], $b['journal'] ?? ('Relance manuelle — tâche « ' . $t['name'] . ' »'));
    return ['ok' => true];
}

/**
 * Le magasin existe-t-il, et son miroir local est-il prêt à recevoir ?
 *
 * L'autorité sur les magasins est la table PARTAGÉE `shops` du panel — c'est
 * elle que `ep_stores()` sert à l'écran. Mais `ceo_shop_budget` et
 * `ceo_shop_month_perf` portent une clé étrangère vers `ceo_shop`, qui n'est
 * qu'un miroir local et reste vide sur une installation branchée sur la vraie
 * base.
 *
 * Conséquence, avant ce correctif : l'écran proposait les vrais magasins, et
 * l'encodage du budget répondait « magasin inconnu » en 404 pour chacun d'eux.
 * Rien n'était enregistré, et le client n'affichait aucune erreur — le budget
 * semblait saisi et disparaissait.
 *
 * On recopie donc le magasin dans le miroir au moment où l'on en a besoin.
 * Rend le nom du magasin, ou null s'il n'existe nulle part.
 */
function magasinConnu(string $shopId): ?string
{
    $local = Db::row('SELECT name FROM ceo_shop WHERE id = ?', [$shopId]);
    if ($local !== null) { return (string) $local['name']; }

    try {
        $ext = Db::row('SELECT id, slug, name, legal_name, operator, city, zone, region, active, since_year
                        FROM shops WHERE id = ?', [$shopId]);
    } catch (PDOException $e) {
        return null;                       // table partagée absente : rien à mirroir
    }
    if ($ext === null) { return null; }

    // `franchisee` et `zone` sont NOT NULL : on retombe sur une chaîne vide
    // plutôt que de faire échouer l'insertion sur un champ décoratif.
    Db::exec('INSERT INTO ceo_shop (id, code, name, franchisee, zone, status, opened_on, pwa_shop_id)
              VALUES (?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code),
                franchisee = VALUES(franchisee), zone = VALUES(zone), status = VALUES(status)', [
        (string) $ext['id'],
        $ext['slug'] !== null ? strtoupper((string) $ext['slug']) : (string) $ext['id'],
        (string) $ext['name'],
        (string) ($ext['operator'] ?: ($ext['legal_name'] ?: '')),
        (string) ($ext['zone'] ?: ($ext['region'] ?: ($ext['city'] ?: ''))),
        ((int) $ext['active'] === 1) ? 'Ouvert' : 'Fermé',
        $ext['since_year'] ? sprintf('%04d-01-01', (int) $ext['since_year']) : null,
        (int) $ext['id'],
    ]);
    return (string) $ext['name'];
}

/** PUT /stores/{id}/budget — écran « Encodage du budget ». */
function wr_budget_put(string $shopId): array
{
    $b = body();
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    $nomShop = magasinConnu($shopId);
    if ($nomShop === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $shop = ['name' => $nomShop];
    $em = $b['etudeMarche'] ?? [];
    $caTheo = $b['caTheoriqueMensuel'] ?? [];
    $caTheoAn = array_sum(array_map('floatval', $caTheo));
    Db::exec('INSERT INTO ceo_shop_budget (shop_id, fiscal_year, validated_on, basket_target, ca_theorique_an, etude_date, etude_source, etude_potentiel_menages, etude_potentiel_maturite, annee_exploitation, montee_regime, saisonnalite, etude_annexe)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE validated_on = VALUES(validated_on), basket_target = VALUES(basket_target),
                ca_theorique_an = VALUES(ca_theorique_an), etude_date = VALUES(etude_date), etude_source = VALUES(etude_source),
                etude_potentiel_menages = VALUES(etude_potentiel_menages), etude_potentiel_maturite = VALUES(etude_potentiel_maturite),
                annee_exploitation = VALUES(annee_exploitation), montee_regime = VALUES(montee_regime),
                saisonnalite = VALUES(saisonnalite), etude_annexe = VALUES(etude_annexe)', [
        $shopId, $exercice, date('Y-m-d'), $b['panierEngagement'] ?? null, $caTheoAn ?: null,
        $em['date'] ?? null, $em['source'] ?? null, $em['potentielMenages'] ?? null, $em['potentielMaturite'] ?? null,
        $em['anneeExploitation'] ?? null,
        isset($em['monteeEnRegime']) ? json_encode($em['monteeEnRegime']) : null,
        isset($em['saisonnalite']) ? json_encode($em['saisonnalite']) : null,
        isset($em['annexe']) ? json_encode($em['annexe'], JSON_UNESCAPED_UNICODE) : null,
    ]);
    foreach (array_values($b['caMensuel'] ?? []) as $i => $ca) {
        $m = $i + 1;
        // Une case vide n'est pas un zéro : un mois sans budget négocié reste
        // NULL, sinon enregistrer l'étude d'un magasin qui ouvre stampait
        // douze zéros et l'écran affichait « 0 » partout — comme si le budget
        // avait été fixé à rien.
        $montant = ($ca === '' || $ca === null || (float) $ca == 0.0) ? null : (float) $ca;
        $theoM = isset($caTheo[$i]) && (float) $caTheo[$i] != 0.0 ? (float) $caTheo[$i] : null;
        // `ceo_shop_budget_month.revenue_budget` n'accepte pas NULL : un mois
        // vide s'y EFFACE au lieu de s'y écrire. (Mesuré en ligne : écrire
        // NULL renvoyait une erreur d'intégrité et l'enregistrement échouait.)
        if ($montant === null) {
            Db::exec('DELETE FROM ceo_shop_budget_month WHERE shop_id = ? AND fiscal_year = ? AND month = ?',
                [$shopId, $exercice, $m]);
        } else {
            Db::exec('INSERT INTO ceo_shop_budget_month VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE revenue_budget = VALUES(revenue_budget)', [$shopId, $exercice, $m, $montant]);
        }
        Db::exec('INSERT INTO ceo_shop_month_perf (shop_id, year, month, revenue_budget, ca_theorique) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE revenue_budget = VALUES(revenue_budget), ca_theorique = VALUES(ca_theorique)',
            [$shopId, $exercice, $m, $montant, $theoM]);
    }
    // Le théorique des exercices suivants (et du courant si sa grille est
    // vide) : même règle pour l'enregistrement du budget et pour une
    // saisonnalité poussée d'un magasin à l'autre.
    $projection = budgetProjeter($shopId, $exercice, $em, $caTheoAn <= 0);

    // Les charges ne s'écrivent plus magasin par magasin : leurs taux valent
    // pour tout le réseau et vivent dans le réglage `budgetCharges`, enregistré
    // par PUT /parametres/budgetCharges. Deux copies du même taux finissaient
    // par diverger, et c'est celle qu'on ne regardait pas qui servait au suivi.

    // `journal: ''` = enregistrement AUTOMATIQUE (la saisie au fil de l'eau) :
    // pas de ligne de journal, sinon douze champs remplis en écriraient douze
    // et le journal ne raconterait plus rien. Le bouton « Enregistrer le
    // budget », lui, envoie son texte et laisse sa trace.
    if (($b['journal'] ?? null) !== '') {
        journalAdd('CEO', 'Budget', $shop['name'], ($b['journal'] ?? ("Budget $exercice encodé"))
            . ($projection !== [] ? ' — théorique projeté : ' . implode(', ', array_keys($projection)) : ''));
    }
    return ['ok' => true, 'projection' => $projection];
}

/** PATCH /reporting/reports/{id} — fréquence, destinataires, postes, actif. */
function wr_report_patch(string $id): array
{
    $b = body();
    $r = Db::row('SELECT name FROM ceo_report_schedule WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    $map = ['freq' => 'frequency', 'destId' => 'dest_id', 'ccId' => 'cc_id'];
    foreach ($map as $key => $col) {
        if (array_key_exists($key, $b)) {
            Db::exec("UPDATE ceo_report_schedule SET $col = ? WHERE id = ?", [$b[$key] === '' ? null : $b[$key], $id]);
        }
    }
    if (array_key_exists('postes', $b)) {
        Db::exec('UPDATE ceo_report_schedule SET postes_json = ? WHERE id = ?', [json_encode($b['postes']), $id]);
    }
    if (array_key_exists('actif', $b)) {
        Db::exec('UPDATE ceo_report_schedule SET active = ? WHERE id = ?', [$b['actif'] ? 1 : 0, $id]);
    }
    return ['ok' => true];
}

/** POST /reporting/reports/{id}/send — génération/envoi (simulé côté serveur : trace + last_run). */
function wr_report_send(string $id): array
{
    $b = body();
    $r = Db::row('SELECT name FROM ceo_report_schedule WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    Db::exec('UPDATE ceo_report_schedule SET last_run = ? WHERE id = ?', [date('Y-m-d'), $id]);
    journalAdd('CEO', 'Rapport', null, $b['journal'] ?? ('Rapport « ' . $r['name'] . ' » envoyé'));
    return ['ok' => true];
}

/** PATCH /reporting/alerts/{id} — activer/désactiver une règle. */
function wr_alert_patch(string $id): array
{
    $b = body();
    Db::exec('UPDATE ceo_alert_rule SET active = ? WHERE id = ?', [!empty($b['actif']) ? 1 : 0, $id]);
    return ['ok' => true];
}

/** PUT /parametres/{key} — seuils, modèles d'email, templates de projet. */
function wr_param_put(string $key): array
{
    $b = body();
    // Le modèle de charges vaut pour TOUT le réseau : un taux de loyer à 7 %
    // est le même à Corbais et à Halle, seul le chiffre d'affaires diffère. Il
    // s'enregistre donc une fois, ici, et non magasin par magasin.
    if ($key === 'budgetCharges') {
        $cats = [];
        foreach ((array) ($b['valeur']['categories'] ?? []) as $cat) {
            if (!is_array($cat)) { continue; }
            $lignes = [];
            foreach ((array) ($cat['lignes'] ?? []) as $l) {
                if (!is_array($l)) { continue; }
                $poste = mb_substr(trim((string) ($l['poste'] ?? '')), 0, 120);
                if ($poste === '') { continue; }
                // Un identifiant STABLE par poste : les montants encodés chaque
                // mois s'y rattachent, et renommer « Énergie » en « Fluides »
                // ne doit pas décrocher douze mois de saisie.
                $id = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($l['id'] ?? '')));
                if ($id === '') { $id = 'p' . bin2hex(random_bytes(4)); }
                $lignes[] = [
                    'id' => $id,
                    'poste' => $poste,
                    'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 200),
                    'gestion' => mb_substr(trim((string) ($l['gestion'] ?? '')), 0, 120),
                    'pcmn' => mb_substr(trim((string) ($l['pcmn'] ?? '')), 0, 20),
                    'pct' => max(0, min(100, (float) ($l['pct'] ?? 0))),
                    'pctTheo' => max(0, min(100, (float) ($l['pctTheo'] ?? ($l['pct'] ?? 0)))),
                ];
            }
            $cats[] = ['nom' => mb_substr(trim((string) ($cat['nom'] ?? '')), 0, 80) ?: 'Sans catégorie',
                'lignes' => $lignes];
        }
        // Les étapes intermédiaires : « marge brute = CA − coût matière ». Une
        // étape ne porte AUCUN montant propre — elle nomme une soustraction
        // entre deux valeurs déjà présentes, et se recalcule donc toute seule
        // quand un taux bouge.
        $paliers = [];
        foreach ((array) ($b['valeur']['paliers'] ?? []) as $pa) {
            if (!is_array($pa)) { continue; }
            $nom = mb_substr(trim((string) ($pa['nom'] ?? '')), 0, 80);
            if ($nom === '') { continue; }
            $paliers[] = [
                'nom' => $nom,
                'gauche' => mb_substr(trim((string) ($pa['gauche'] ?? 'ca')), 0, 120),
                'droite' => mb_substr(trim((string) ($pa['droite'] ?? '')), 0, 120),
                'apres' => mb_substr(trim((string) ($pa['apres'] ?? '')), 0, 80),
            ];
        }
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['budgetCharges', json_encode(['categories' => $cats, 'paliers' => $paliers], JSON_UNESCAPED_UNICODE)]);
        $n = 0;
        foreach ($cats as $c) { $n += count($c['lignes']); }
        journalAdd('CEO', 'Budget', null, 'Modèle de charges du réseau enregistré — '
            . count($cats) . ' catégorie(s), ' . $n . ' poste(s), ' . count($paliers) . ' étape(s)');
        return ['ok' => true, 'categories' => count($cats), 'postes' => $n, 'paliers' => count($paliers)];
    }
    if (str_starts_with($key, 'seuil-')) {                       // seuil-food | seuil-labour
        $code = substr($key, 6);
        Db::exec('UPDATE kpi SET seuil_haut = ? WHERE code = ?', [(float) $b['valeur'], $code]);
        return ['ok' => true];
    }
    if (str_starts_with($key, 'email-')) {                       // email-e1 → corps du modèle
        Db::exec('UPDATE ceo_email_template SET body = ? WHERE id = ?', [(string) $b['corps'], substr($key, 6)]);
        return ['ok' => true];
    }
    if (str_starts_with($key, 'template-')) {                    // template-<axe> → jalons + coûts
        Db::exec('INSERT INTO ceo_project_template VALUES (?,?,?) ON DUPLICATE KEY UPDATE jalons_json = VALUES(jalons_json), couts_json = VALUES(couts_json)', [
            substr($key, 9),
            json_encode($b['jalons'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['couts'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return ['ok' => true];
    }
    if ($key === 'saisonnaliteReseau') {                          // douze parts, somme non nulle
        $v = array_map('floatval', (array) ($b['valeur'] ?? []));
        if (count($v) !== 12 || array_sum($v) <= 0) {
            http_response_code(422);
            return ['error' => 'douze valeurs attendues, de somme non nulle'];
        }
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, json_encode($v)]);
        journalAdd('CEO', 'Budget', null, 'Variation par mois du réseau enregistrée');
        return ['ok' => true];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, json_encode($b['valeur'] ?? null, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true];
}

/**
 * Fiche de production d'une référence — assortiment et paramètres four.
 *
 * Le catalogue vient d'`atelierby_db` ; ces attributs-là n'existent nulle part
 * ailleurs et appartiennent au réseau. La ligne cockpit est donc créée à la
 * volée, la première fois qu'on touche une référence : rien n'est pré-rempli,
 * et une référence jamais éditée n'occupe aucune place.
 */
function wr_prod_produit(string $ref): array
{
    $b = body();
    $ref = trim($ref);
    if ($ref === '') { http_response_code(400); return ['error' => 'référence requise']; }

    // Intitulé et catégorie recopiés depuis le catalogue : la table cockpit ne
    // sert à rien si l'on ne peut pas relire ses lignes sans la base partagée.
    $nom = ''; $cat = '';
    try {
        $p = Db::rows('SELECT p.name, c.name AS cat FROM product p
                    LEFT JOIN product_category c ON c.id = p.id_category
                       WHERE p.id = ?', [(int) $ref]);
        if ($p) { $nom = (string) $p[0]['name']; $cat = (string) ($p[0]['cat'] ?? ''); }
    } catch (PDOException $e) { /* catalogue indisponible : on garde ce qui est fourni */ }
    if ($nom === '') { $nom = (string) ($b['nom'] ?? $ref); }

    $num = static fn ($v, $def = 0) => is_numeric($v) ? (float) $v : $def;
    Db::exec(
        'INSERT INTO ceo_prod_product (ref, nom, categorie, pwa_id, must, qmin, mat, prix,
             prep, cuisson, fin, bmin, bmult, four, dlv, profil, actif)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
             must = VALUES(must), qmin = VALUES(qmin), mat = VALUES(mat), prix = VALUES(prix),
             prep = VALUES(prep), cuisson = VALUES(cuisson), fin = VALUES(fin),
             bmin = VALUES(bmin), bmult = VALUES(bmult), four = VALUES(four),
             dlv = VALUES(dlv), profil = VALUES(profil)',
        [$ref, $nom, $cat, ctype_digit($ref) ? (int) $ref : null,
         !empty($b['must']) ? 1 : 0, (int) $num($b['qmin'] ?? 0),
         isset($b['mat']) && $b['mat'] !== '' ? $num($b['mat']) : null,
         isset($b['prix']) && $b['prix'] !== '' ? $num($b['prix']) : null,
         (int) $num($b['prep'] ?? 0), (int) $num($b['cuisson'] ?? 0), (int) $num($b['fin'] ?? 0),
         (int) $num($b['bmin'] ?? 0), (int) $num($b['bmult'] ?? 1), (int) $num($b['four'] ?? 0),
         (int) $num($b['dlv'] ?? 0), (string) ($b['profil'] ?? '')]
    );
    journalAdd('CEO', 'Référentiel produit', $nom,
        (string) ($b['journal'] ?? 'Fiche de production mise à jour'));
    return ['ok' => true, 'ref' => $ref];
}

/**
 * Emplacement d'une référence au comptoir.
 * Une zone vide efface l'emplacement plutôt que d'enregistrer un blanc : une
 * référence « posée nulle part » et une référence « jamais placée » doivent se
 * distinguer à l'écran.
 */
function wr_prod_planogramme(string $ref): array
{
    $b = body();
    $ref = trim($ref);
    if ($ref === '') { http_response_code(400); return ['error' => 'référence requise']; }
    $zone = trim((string) ($b['zone'] ?? ''));
    if ($zone === '') {
        Db::exec('DELETE FROM pla_placement WHERE ref = ?', [$ref]);
        return ['ok' => true, 'ref' => $ref, 'retire' => true];
    }
    // Cette route désigne l'emplacement par ses NOMS. Depuis que le comptoir a
    // une structure, un placement doit s'y rattacher : sans `slot_id` il serait
    // compté comme placé dans le catalogue tout en restant invisible sur le
    // plan. On résout donc les noms, et on refuse plutôt que d'écrire un
    // placement qui ne désigne rien.
    $s = Db::row('SELECT s.id FROM pla_slot s
                    JOIN pla_niveau n ON n.id = s.niveau_id
                    JOIN pla_meuble m ON m.id = n.meuble_id
                    JOIN pla_zone   z ON z.id = m.zone_id
                   WHERE z.nom = ? AND m.nom = ? AND n.nom = ? AND s.position = ?',
        [$zone, trim((string) ($b['meuble'] ?? '')), trim((string) ($b['niveau'] ?? '')),
         isset($b['slot']) && is_numeric($b['slot']) ? (int) $b['slot'] : 0]);
    if ($s === null) {
        http_response_code(422);
        return ['error' => 'aucun emplacement de ce nom au comptoir — déclarez-le dans le planogramme, '
            . 'ou utilisez PUT /planogramme/placement/{ref} avec son slotId'];
    }
    return wr_plano_placer($ref, array_merge($b, ['slotId' => (int) $s['id']]));
}

/* --- Planogramme : structure du comptoir, placements, consignes ------------- */

/** Les trois niveaux de structure partagent leur forme : un nom, un rang, un parent. */
const PLANO_NIVEAUX = [
    'zone'   => ['table' => 'pla_zone',   'parent' => null,        'enfant' => 'pla_meuble', 'fkEnfant' => 'zone_id'],
    'meuble' => ['table' => 'pla_meuble', 'parent' => 'zone_id',   'enfant' => 'pla_niveau', 'fkEnfant' => 'meuble_id'],
    'niveau' => ['table' => 'pla_niveau', 'parent' => 'meuble_id', 'enfant' => 'pla_slot',   'fkEnfant' => 'niveau_id'],
];

/**
 * POST /planogramme/{zone|meuble|niveau} — créer un élément de structure.
 *
 * Le comptoir se déclare une fois. Tant qu'il n'est pas déclaré, aucun
 * emplacement n'existe et rien ne peut être placé : c'est pour cela que cette
 * écriture précède tout le reste.
 */
function wr_plano_creer(string $type): array
{
    $def = PLANO_NIVEAUX[$type] ?? null;
    if ($def === null) { http_response_code(404); return ['error' => 'niveau inconnu']; }
    $b = body();
    $nom = trim((string) ($b['nom'] ?? ''));
    if ($nom === '') { http_response_code(422); return ['error' => 'un nom est requis']; }
    $nom = mb_substr($nom, 0, 80);
    $rang = isset($b['rang']) ? max(0, (int) $b['rang']) : 0;

    // Deux éléments de même nom sous le même parent ne se distinguent pas à
    // l'écran : on les créait sans broncher, et deux zones « Tartes »
    // identiques sont nées d'un simple double-clic. On refuse, en le disant —
    // plutôt que de laisser un doublon qu'il faudra retrouver.
    $pid = $def['parent'] !== null ? (int) ($b['parentId'] ?? 0) : 0;
    $ou = $def['parent'] !== null ? ' AND ' . $def['parent'] . ' = ?' : '';
    $args = $def['parent'] !== null ? [$nom, $pid] : [$nom];
    $dej = Db::row('SELECT id FROM ' . $def['table'] . ' WHERE nom = ?' . $ou, $args);
    if ($dej !== null) {
        http_response_code(409);
        return ['error' => '« ' . $nom . ' » existe déjà à cet endroit', 'id' => (int) $dej['id']];
    }

    if ($def['parent'] !== null) {
        if ($pid <= 0) { http_response_code(422); return ['error' => 'le parent est requis']; }
        Db::exec('INSERT INTO ' . $def['table'] . ' (' . $def['parent'] . ', nom, rang) VALUES (?,?,?)',
            [$pid, $nom, $rang]);
    } else {
        Db::exec('INSERT INTO ' . $def['table'] . ' (nom, rang) VALUES (?,?)', [$nom, $rang]);
    }
    $id = (int) Db::pdo()->lastInsertId();

    // Un meuble porte aussi son type, sa température et son mode de
    // présentation — ce qui décide de ce qu'on peut y poser. Écrits à part pour
    // que l'insertion reste commune aux trois niveaux de structure.
    if ($type === 'meuble') {
        $court = static fn ($v) => mb_substr(trim((string) $v), 0, 40);
        Db::exec('UPDATE pla_meuble SET type = ?, temperature = ?, presentation = ?, periodes = ? WHERE id = ?',
            [$court($b['type'] ?? ''), $court($b['temperature'] ?? ''), $court($b['presentation'] ?? ''),
                planoPeriodes($b['periodes'] ?? null), $id]);

        // Le meuble peut naître AVEC ses niveaux et leurs emplacements : le
        // déclarer en trois écrans successifs faisait abandonner à mi-chemin,
        // et un meuble sans emplacement ne sert à rien.
        $faits = 0;
        $crees = [];
        foreach (is_array($b['niveaux'] ?? null) ? $b['niveaux'] : [] as $i => $n) {
            if (!is_array($n)) { continue; }
            $nn = mb_substr(trim((string) ($n['nom'] ?? '')), 0, 80);
            if ($nn === '') { $nn = 'Niveau ' . ($i + 1); }
            Db::exec('INSERT INTO pla_niveau (meuble_id, nom, rang) VALUES (?,?,?)', [$id, $nn, $i + 1]);
            $nid = (int) Db::pdo()->lastInsertId();
            // Les identifiants créés sont RENDUS : sans eux, l'assistant ne peut
            // pas rattacher la photo qu'il vient de collecter pour ce niveau —
            // il faudrait recharger l'arbre et rapprocher par le nom, ce qui se
            // trompe dès que deux niveaux s'appellent pareil.
            $crees[] = ['id' => $nid, 'nom' => $nn, 'rang' => $i + 1];
            $ns = max(0, min(40, (int) ($n['slots'] ?? 0)));
            for ($k = 1; $k <= $ns; $k++) {
                Db::exec('INSERT INTO pla_slot (niveau_id, position, largeur_mm, longueur_mm, hauteur_mm, capacite)'
                    . ' VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE position = position',
                    [$nid, $k,
                     planoDim($b['largeurMm'] ?? null), planoDim($b['longueurMm'] ?? null),
                     planoDim($b['hauteurMm'] ?? null),
                     isset($b['capacite']) ? max(0, min(999, (int) $b['capacite'])) : null]);
                $faits++;
            }
        }
        if ($crees) { return ['ok' => true, 'id' => $id, 'nom' => $nom, 'slots' => $faits, 'niveaux' => $crees]; }
    }

    // Un niveau créé avec un nombre d'emplacements : les poser tout de suite
    // évite de saisir douze fois la même chose. `slots` absent = niveau vide.
    $poses = 0;
    if ($type === 'niveau' && isset($b['slots'])) {
        $n = max(0, min(40, (int) $b['slots']));
        for ($i = 1; $i <= $n; $i++) {
            Db::exec('INSERT INTO pla_slot (niveau_id, position, largeur_mm, capacite) VALUES (?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE position = position',
                [$id, $i, isset($b['largeurMm']) ? (int) $b['largeurMm'] : null,
                 isset($b['capacite']) ? (int) $b['capacite'] : null]);
            $poses++;
        }
    }
    return ['ok' => true, 'id' => $id, 'nom' => $nom, 'slots' => $poses];
}

/** PATCH /planogramme/{type}/{id} — renommer ou réordonner. */
function wr_plano_patch(string $type, int $id): array
{
    $def = PLANO_NIVEAUX[$type] ?? null;
    if ($def === null) { http_response_code(404); return ['error' => 'niveau inconnu']; }
    $b = body();
    if (isset($b['nom'])) {
        $nom = trim((string) $b['nom']);
        if ($nom === '') { http_response_code(422); return ['error' => 'un nom est requis']; }
        Db::exec('UPDATE ' . $def['table'] . ' SET nom = ? WHERE id = ?', [mb_substr($nom, 0, 80), $id]);
    }
    if (isset($b['rang'])) {
        Db::exec('UPDATE ' . $def['table'] . ' SET rang = ? WHERE id = ?', [max(0, (int) $b['rang']), $id]);
    }
    // Les moments de la journée se corrigent sans refaire le meuble : un
    // comptoir chaud qu'on décide finalement de monter aussi le matin ne doit
    // pas obliger à resaisir ses niveaux et ses emplacements.
    if ($type === 'meuble' && array_key_exists('periodes', $b)) {
        Db::exec('UPDATE pla_meuble SET periodes = ? WHERE id = ?', [planoPeriodes($b['periodes']), $id]);
    }
    // Le type, la température et la présentation se corrigent aussi après
    // coup : l'assistant rouvre un meuble existant, il ne doit pas obliger à
    // le détruire pour changer « ambiante » en « réfrigérée ».
    if ($type === 'meuble') {
        $court = static fn ($v) => mb_substr(trim((string) $v), 0, 40);
        foreach (['type', 'temperature', 'presentation'] as $ch) {
            if (array_key_exists($ch, $b)) {
                Db::exec('UPDATE pla_meuble SET ' . $ch . ' = ? WHERE id = ?', [$court($b[$ch]), $id]);
            }
        }
    }
    return ['ok' => true, 'id' => $id];
}

/**
 * Les moments de la journée d'un meuble, normalisés.
 *
 * Rendus sous forme de liste séparée par des virgules, dans l'ordre du
 * référentiel — « midi,matin » et « matin,midi » désignent le même comptoir et
 * doivent se relire pareil. Une liste VIDE veut dire « toute la journée » :
 * c'est ce que portent les meubles saisis avant l'ajout de la notion, et c'est
 * la lecture la moins fausse pour eux.
 */
function planoPeriodes(mixed $v): string
{
    $ref = [];
    foreach ((array) (setting('planogramme')['periodes'] ?? []) as $p) {
        if (is_array($p) && isset($p['slug'])) { $ref[] = (string) $p['slug']; }
    }
    if (!$ref) { $ref = ['matin', 'midi', 'apresmidi']; }
    $vus = [];
    foreach ((array) $v as $x) {
        $slug = is_array($x) ? (string) ($x['slug'] ?? '') : (string) $x;
        if (in_array($slug, $ref, true) && !in_array($slug, $vus, true)) { $vus[] = $slug; }
    }
    // Les trois : c'est « toute la journée », qu'on écrit comme tel — une liste
    // complète et une liste vide voudraient dire la même chose, autant n'en
    // garder qu'une seule écriture.
    if (count($vus) === count($ref)) { return ''; }
    usort($vus, static fn ($a, $b2) => array_search($a, $ref, true) <=> array_search($b2, $ref, true));
    return implode(',', $vus);
}

/**
 * DELETE /planogramme/{type}/{id} — supprimer, avec ce qu'il porte.
 *
 * Une suppression en cascade est REFUSÉE tant qu'un produit est placé dessous :
 * supprimer une vitrine ne doit pas retirer silencieusement dix références de
 * l'assortiment du comptoir. On dit combien, et l'écran demande confirmation.
 */
function wr_plano_supprimer(string $type, int $id): array
{
    $def = PLANO_NIVEAUX[$type] ?? null;
    if ($def === null) { http_response_code(404); return ['error' => 'niveau inconnu']; }

    $slots = planoSlotsSous($type, $id);
    $force = !empty(body()['force']);

    // Ce qui est PORTÉ compte autant que ce qui est placé. La première version
    // ne refusait que sur des références placées : une zone entière, ses meubles
    // et ses niveaux disparaissaient sans confirmation dès qu'aucun produit n'y
    // était encore posé. C'est ainsi qu'une structure déclarée a été perdue.
    if (!$force) {
        $porte = [];
        if ($type === 'zone') {
            $m = Db::row('SELECT COUNT(*) AS n FROM pla_meuble WHERE zone_id = ?', [$id]);
            if ((int) ($m['n'] ?? 0) > 0) { $porte[] = (int) $m['n'] . ' meuble(s)'; }
        } elseif ($type === 'meuble') {
            $n2 = Db::row('SELECT COUNT(*) AS n FROM pla_niveau WHERE meuble_id = ?', [$id]);
            if ((int) ($n2['n'] ?? 0) > 0) { $porte[] = (int) $n2['n'] . ' niveau(x)'; }
        }
        if ($slots) { $porte[] = count($slots) . ' emplacement(s)'; }
        if ($porte) {
            http_response_code(409);
            return ['error' => 'cet élément porte ' . implode(' et ', $porte)
                . ' — tout serait supprimé', 'porte' => $porte];
        }
    }
    if ($slots) {
        $in = implode(',', array_fill(0, count($slots), '?'));
        $r = Db::row('SELECT COUNT(*) AS n FROM pla_placement WHERE slot_id IN (' . $in . ')', $slots);
        $n = (int) ($r['n'] ?? 0);
        if ($n > 0 && !$force) {
            http_response_code(409);
            return ['error' => $n . ' référence(s) y sont placées — la suppression les retirerait du comptoir',
                'placees' => $n];
        }
        Db::exec('DELETE FROM pla_placement WHERE slot_id IN (' . $in . ')', $slots);
        Db::exec('DELETE FROM pla_slot WHERE id IN (' . $in . ')', $slots);
    }
    // Puis la descendance de structure, du bas vers le haut.
    if ($type === 'zone') {
        $ms = array_map(fn ($x) => (int) $x['id'], Db::rows('SELECT id FROM pla_meuble WHERE zone_id = ?', [$id]));
        foreach ($ms as $m) {
            Db::exec('DELETE FROM pla_niveau WHERE meuble_id = ?', [$m]);
        }
        Db::exec('DELETE FROM pla_meuble WHERE zone_id = ?', [$id]);
    } elseif ($type === 'meuble') {
        Db::exec('DELETE FROM pla_niveau WHERE meuble_id = ?', [$id]);
    }
    Db::exec('DELETE FROM ' . $def['table'] . ' WHERE id = ?', [$id]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Une dimension en millimètres, ou rien.
 *
 * Zéro n'est pas une dimension : un emplacement de 0 mm n'existe pas. Le
 * garder ferait afficher « 0 mm » là où la mesure est simplement inconnue.
 */
function planoDim($v): ?int
{
    if ($v === null || $v === '') { return null; }
    $n = (int) $v;
    return ($n > 0 && $n <= 5000) ? $n : null;
}

/** Les identifiants d'emplacement situés sous un élément de structure. */
function planoSlotsSous(string $type, int $id): array
{
    if ($type === 'niveau') {
        $q = 'SELECT id FROM pla_slot WHERE niveau_id = ?';
    } elseif ($type === 'meuble') {
        $q = 'SELECT s.id FROM pla_slot s JOIN pla_niveau n ON n.id = s.niveau_id WHERE n.meuble_id = ?';
    } else {
        $q = 'SELECT s.id FROM pla_slot s JOIN pla_niveau n ON n.id = s.niveau_id'
           . ' JOIN pla_meuble m ON m.id = n.meuble_id WHERE m.zone_id = ?';
    }
    return array_map(fn ($x) => (int) $x['id'], Db::rows($q, [$id]));
}

/** POST /planogramme/emplacement — ajouter des emplacements à un niveau. */
function wr_plano_slots(): array
{
    $b = body();
    $nid = (int) ($b['niveauId'] ?? 0);
    if ($nid <= 0) { http_response_code(422); return ['error' => 'le niveau est requis']; }
    $n = max(1, min(40, (int) ($b['nombre'] ?? 1)));
    $r = Db::row('SELECT COALESCE(MAX(position), 0) AS p FROM pla_slot WHERE niveau_id = ?', [$nid]);
    $depart = (int) ($r['p'] ?? 0);
    for ($i = 1; $i <= $n; $i++) {
        Db::exec('INSERT INTO pla_slot (niveau_id, position, largeur_mm, longueur_mm, hauteur_mm, capacite)'
            . ' VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE position = position',
            [$nid, $depart + $i, planoDim($b['largeurMm'] ?? null), planoDim($b['longueurMm'] ?? null),
             planoDim($b['hauteurMm'] ?? null),
             isset($b['capacite']) ? max(0, min(999, (int) $b['capacite'])) : null]);
    }
    return ['ok' => true, 'niveauId' => $nid, 'ajoutes' => $n];
}

/** DELETE /planogramme/emplacement/{id} — retirer un emplacement. */
function wr_plano_slot_supprimer(int $id): array
{
    $r = Db::row('SELECT COUNT(*) AS n FROM pla_placement WHERE slot_id = ?', [$id]);
    if ((int) ($r['n'] ?? 0) > 0 && empty(body()['force'])) {
        http_response_code(409);
        return ['error' => 'une référence y est placée — elle serait retirée du comptoir'];
    }
    Db::exec('DELETE FROM pla_placement WHERE slot_id = ?', [$id]);
    Db::exec('DELETE FROM pla_slot WHERE id = ?', [$id]);
    return ['ok' => true, 'id' => $id];
}

/**
 * PUT /planogramme/placement/{ref} — placer une référence sur un emplacement.
 *
 * L'écriture recopie zone / meuble / niveau / position en clair dans la table :
 * le référentiel produit les lit déjà sous cette forme, et un écran qui affiche
 * « Vitrine 1 · haut · 4 » ne doit pas avoir à recharger tout l'arbre pour le
 * dire. Le `slot_id` reste la vérité ; le texte n'est qu'une commodité, rafraîchi
 * à chaque écriture.
 *
 * Un emplacement plein est REFUSÉ avec son occupant : l'écran propose alors
 * l'échange, au lieu d'empiler deux produits au même endroit sans le dire.
 */
/**
 * POST /planogramme/referentiel/{formats|contenants} — ajouter une position.
 *
 * La liste s'édite là où elle se lit : quand le format n'y est pas, on finit
 * d'écrire et on l'ajoute. Un doublon n'est PAS une erreur — on renvoie la
 * position existante, sinon deux magasins créeraient deux « 60 × 40 cm ».
 *
 * Un nom de la forme « 60 × 40 » suffit à déduire les dimensions : les écrire
 * une seconde fois dans deux champs serait une occasion de se contredire.
 */
function wr_plano_referentiel_creer(string $type): array
{
    $b = body();
    $nom = trim((string) ($b['nom'] ?? ''));
    if ($nom === '') { http_response_code(400); return ['error' => 'nom requis']; }
    $nom = mb_substr($nom, 0, 60);

    if ($type === 'contenants') {
        $ex = Db::row('SELECT id FROM pla_contenant WHERE nom = ?', [$nom]);
        if ($ex !== null) { return ['ok' => true, 'id' => (int) $ex['id'], 'nom' => $nom, 'existait' => true]; }
        $r = Db::row('SELECT COALESCE(MAX(rang), 0) AS n FROM pla_contenant');
        Db::exec('INSERT INTO pla_contenant (nom, rang) VALUES (?,?)', [$nom, (int) $r['n'] + 1]);
        journalAdd('CEO', 'Planogramme', $nom, 'Contenant ajouté à la liste');
        return ['ok' => true, 'nom' => $nom];
    }

    // Dimensions : celles qui sont transmises, sinon celles que le nom porte.
    $lar = isset($b['largeurMm']) ? (int) $b['largeurMm'] : null;
    $hau = isset($b['hauteurMm']) ? (int) $b['hauteurMm'] : null;
    if (($lar === null || $hau === null)
        && preg_match('/(\d+(?:[.,]\d+)?)\s*[x×*]\s*(\d+(?:[.,]\d+)?)/ui', $nom, $m)) {
        $a = (float) str_replace(',', '.', $m[1]);
        $c = (float) str_replace(',', '.', $m[2]);
        // Écrit en centimètres neuf fois sur dix ; au-delà de 200, c'est
        // déjà des millimètres — un emplacement de 2 mètres de large n'existe
        // pas dans un comptoir, un de 300 mm si.
        $mm = fn ($v) => (int) round($v > 200 ? $v : $v * 10);
        $lar = $lar ?? $mm($a);
        $hau = $hau ?? $mm($c);
    }
    $lar = $lar !== null ? max(0, min(5000, $lar)) : null;
    $hau = $hau !== null ? max(0, min(5000, $hau)) : null;

    $ex = Db::row('SELECT id FROM pla_format WHERE nom = ?', [$nom]);
    if ($ex !== null) { return ['ok' => true, 'id' => (int) $ex['id'], 'nom' => $nom, 'existait' => true]; }
    $r = Db::row('SELECT COALESCE(MAX(rang), 0) AS n FROM pla_format');
    Db::exec('INSERT INTO pla_format (nom, largeur_mm, hauteur_mm, rang) VALUES (?,?,?,?)',
        [$nom, $lar, $hau, (int) $r['n'] + 1]);
    journalAdd('CEO', 'Planogramme', $nom, 'Format d’emplacement ajouté à la liste');
    return ['ok' => true, 'nom' => $nom, 'largeurMm' => $lar, 'hauteurMm' => $hau];
}

/**
 * DELETE /planogramme/referentiel/{formats|contenants}/{id} — la croix.
 *
 * Ce qui disparaît est la PROPOSITION, pas ce qui a été posé : les
 * emplacements gardent le format et le contenant qu'ils portent. On dit
 * combien en sont concernés, pour qu'on sache ce qu'on retire.
 */
function wr_plano_referentiel_supprimer(string $type, int $id): array
{
    $table = $type === 'contenants' ? 'pla_contenant' : 'pla_format';
    $col = $type === 'contenants' ? 'contenant' : 'format';
    $r = Db::row('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'position inconnue']; }
    $nom = (string) $r['nom'];
    $u = Db::row('SELECT COUNT(*) AS n FROM pla_slot WHERE ' . $col . ' = ?', [$nom]);
    Db::exec('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
    journalAdd('CEO', 'Planogramme', $nom, 'Position retirée de la liste');
    return ['ok' => true, 'id' => $id, 'nom' => $nom, 'utilise' => (int) ($u['n'] ?? 0)];
}

/**
 * PATCH /planogramme/emplacement/{id} — le format et le contenant d'un slot.
 *
 * Choisir un format REPORTE ses dimensions sur l'emplacement : c'est ce qui
 * fait qu'un 60 × 15 ne se remplit pas comme un 30 × 30. Un format inconnu de
 * la liste est accepté tel quel — le référentiel propose, il n'interdit pas.
 */
function wr_plano_slot_maj(int $id): array
{
    $b = body();
    $s = Db::row('SELECT * FROM pla_slot WHERE id = ?', [$id]);
    if ($s === null) { http_response_code(404); return ['error' => 'emplacement inconnu']; }

    $sets = []; $args = []; $dit = [];
    if (array_key_exists('format', $b)) {
        $nom = mb_substr(trim((string) $b['format']), 0, 60);
        $sets[] = 'format = ?'; $args[] = $nom; $dit[] = 'format « ' . ($nom !== '' ? $nom : '—') . ' »';
        $f = $nom !== '' ? Db::row('SELECT * FROM pla_format WHERE nom = ?', [$nom]) : null;
        if ($f !== null && $f['largeur_mm'] !== null) { $sets[] = 'largeur_mm = ?'; $args[] = (int) $f['largeur_mm']; }
        if ($f !== null && $f['hauteur_mm'] !== null) { $sets[] = 'hauteur_mm = ?'; $args[] = (int) $f['hauteur_mm']; }
    }
    if (array_key_exists('contenant', $b)) {
        $nom = mb_substr(trim((string) $b['contenant']), 0, 60);
        $sets[] = 'contenant = ?'; $args[] = $nom; $dit[] = 'contenant « ' . ($nom !== '' ? $nom : '—') . ' »';
    }
    if (!$sets) { http_response_code(400); return ['error' => 'rien à modifier']; }
    $args[] = $id;
    Db::exec('UPDATE pla_slot SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
    journalAdd('CEO', 'Planogramme', 'emplacement ' . $id, implode(', ', $dit));
    return ['ok' => true, 'id' => $id];
}

function wr_plano_placer(string $ref, ?array $payload = null): array
{
    $ref = trim($ref);
    if ($ref === '') { http_response_code(400); return ['error' => 'référence requise']; }
    // `payload` permet à la route par NOMS de déléguer ici après avoir résolu
    // l'emplacement : une seule règle d'occupation pour les deux chemins.
    $b = $payload ?? body();

    if (empty($b['slotId'])) {
        Db::exec('DELETE FROM pla_placement WHERE ref = ?', [$ref]);
        journalAdd('CEO', 'Planogramme', $ref, 'Référence retirée du comptoir');
        return ['ok' => true, 'ref' => $ref, 'retire' => true];
    }
    $sid = (int) $b['slotId'];
    $s = Db::row('SELECT s.id, s.position, s.capacite, s.largeur_mm, s.hauteur_mm,
                         n.nom AS niveau, m.nom AS meuble, z.nom AS zone
                    FROM pla_slot s
                    JOIN pla_niveau n ON n.id = s.niveau_id
                    JOIN pla_meuble m ON m.id = n.meuble_id
                    JOIN pla_zone   z ON z.id = m.zone_id
                   WHERE s.id = ?', [$sid]);
    if ($s === null) { http_response_code(404); return ['error' => 'emplacement inconnu']; }

    // L'état d'avant : d'où la référence vient, et ce qu'elle portait. Il est
    // lu ICI parce que l'échange en a besoin avant de toucher à quoi que ce soit.
    $ancien = Db::row('SELECT slot_id, par_slot, grille_cols, grille_rangs, periodes FROM pla_placement WHERE ref = ?', [$ref]);
    $venaitDe = ($ancien['slot_id'] ?? null) !== null ? (int) $ancien['slot_id'] : null;

    // Les moments demandés pour CE placement — transmis, sinon ceux déjà
    // enregistrés : poser un ordre ne doit pas effacer un « matin seulement ».
    $perDemande = array_key_exists('periodes', $b) ? planoPeriodes($b['periodes'])
        : (string) ($ancien['periodes'] ?? '');

    // Deux références peuvent partager un emplacement si elles n'y sont PAS au
    // même moment : les croissants du matin et le traiteur de midi occupent la
    // même case sans jamais s'y croiser. Le conflit se juge donc par
    // chevauchement — une liste vide vaut « toute la journée » et croise tout.
    $chevauche = static function (string $a, string $c): bool {
        if ($a === '' || $c === '') { return true; }
        return (bool) array_intersect(explode(',', $a), explode(',', $c));
    };
    $tous = Db::rows('SELECT ref, periodes FROM pla_placement WHERE slot_id = ? AND ref <> ?', [$sid, $ref]);
    $occ = array_values(array_filter($tous,
        fn ($o) => $chevauche($perDemande, (string) ($o['periodes'] ?? ''))));
    // L'ÉCHANGE : glisser une référence sur un emplacement occupé, quand elle
    // vient elle-même d'un emplacement, se lit comme « ces deux-là permutent ».
    // C'est le geste qu'on fait au comptoir ; le refuser obligerait à vider un
    // emplacement d'abord, en laissant une référence nulle part entre-temps.
    $echange = null;
    if ($occ && !empty($b['echange']) && $venaitDe !== null && $venaitDe !== $sid) {
        if (count($occ) > 1) {
            http_response_code(409);
            return ['error' => 'emplacement partagé — l’échange ne saurait pas qui déplacer',
                'occupants' => array_map(fn ($o) => (string) $o['ref'], $occ)];
        }
        $autre = (string) $occ[0]['ref'];
        // L'autre part d'abord vers l'emplacement libéré ; `partager` lève le
        // contrôle d'occupation le temps de la permutation, puisque la
        // référence déplacée n'a pas encore quitté sa place.
        $r = wr_plano_placer($autre, ['slotId' => $venaitDe, 'partager' => true]);
        if (($r['ok'] ?? false) !== true) { return $r; }
        $echange = $autre;
        $occ = [];
    }
    if ($occ && empty($b['partager'])) {
        http_response_code(409);
        return ['error' => 'emplacement déjà occupé',
            'occupants' => array_map(fn ($o) => (string) $o['ref'], $occ)];
    }

    $ordre  = max(1, min(40, (int) ($b['ordre'] ?? 1)));

    // La grille. `parSlot` est ce qu'on décide ; les colonnes et les rangées
    // s'en déduisent — la ligne juste d'office, une ligne imposée si on l'a
    // choisie. Sans `parSlot`, rien n'est inventé : la grille reste ce qu'elle
    // était et `fronts` garde son ancien sens.
    $grille = null;
    if (isset($b['parSlot']) && $b['parSlot'] !== '' && $b['parSlot'] !== null) {
        $grille = planoGrille((int) $b['parSlot'],
            $s['largeur_mm'] !== null ? (int) $s['largeur_mm'] : null,
            ($s['hauteur_mm'] ?? null) !== null ? (int) $s['hauteur_mm'] : null,
            isset($b['cols']) && (int) $b['cols'] > 0 ? (int) $b['cols'] : null);
    }
    // Vu de face, on voit les colonnes : c'est cela, un front.
    $fronts = $grille !== null && $grille['cols'] > 0
        ? max(1, min(40, $grille['cols']))
        : max(1, min(40, (int) ($b['fronts'] ?? 1)));

    // Sans nouvelle grille, celle qui était enregistrée est RELUE et remise :
    // enregistrer un ordre ne doit pas effacer un nombre par emplacement.
    $gParSlot = $grille !== null ? $grille['parSlot']
        : (($ancien['par_slot'] ?? null) !== null ? (int) $ancien['par_slot'] : null);
    $gCols = $grille !== null ? $grille['cols']
        : (($ancien['grille_cols'] ?? null) !== null ? (int) $ancien['grille_cols'] : null);
    $gRangs = $grille !== null ? $grille['rangs']
        : (($ancien['grille_rangs'] ?? null) !== null ? (int) $ancien['grille_rangs'] : null);

    // Un déménagement change la forme de la case : six éclairs tiennent en
    // 6 × 2 dans un 60 × 40, pas dans un 60 × 15. Quand l'emplacement d'arrivée
    // n'a pas les mêmes dimensions que celui de départ, la ligne juste est
    // REFAITE pour le nouveau format — le nombre par emplacement, lui, est ce
    // qu'on a décidé et ne bouge pas.
    $regrille = null;
    if ($grille === null && $gParSlot !== null && $venaitDe !== null && $venaitDe !== $sid) {
        $av = Db::row('SELECT largeur_mm, hauteur_mm FROM pla_slot WHERE id = ?', [$venaitDe]);
        $memeForme = $av !== null
            && (int) ($av['largeur_mm'] ?? 0) === (int) ($s['largeur_mm'] ?? 0)
            && (int) ($av['hauteur_mm'] ?? 0) === (int) ($s['hauteur_mm'] ?? 0);
        if (!$memeForme) {
            $regrille = planoGrille($gParSlot,
                $s['largeur_mm'] !== null ? (int) $s['largeur_mm'] : null,
                ($s['hauteur_mm'] ?? null) !== null ? (int) $s['hauteur_mm'] : null);
            $gCols = $regrille['cols']; $gRangs = $regrille['rangs'];
            $fronts = max(1, min(40, $regrille['cols']));
        }
    }

    Db::exec('INSERT INTO pla_placement (ref, zone, meuble, niveau, slot, slot_id, fronts, ordre,'
        . ' par_slot, grille_cols, grille_rangs, periodes)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE zone = VALUES(zone), meuble = VALUES(meuble), niveau = VALUES(niveau),'
        . ' slot = VALUES(slot), slot_id = VALUES(slot_id), fronts = VALUES(fronts), ordre = VALUES(ordre),'
        . ' par_slot = VALUES(par_slot), grille_cols = VALUES(grille_cols), grille_rangs = VALUES(grille_rangs),'
        . ' periodes = VALUES(periodes)',
        [$ref, (string) $s['zone'], (string) $s['meuble'], (string) $s['niveau'],
         (int) $s['position'], $sid, $fronts, $ordre, $gParSlot, $gCols, $gRangs, $perDemande]);

    // Le minimum d'assortiment est le même chiffre que celui du comptoir : le
    // saisir ici évite d'ouvrir un second écran pour la même idée.
    //
    // Mais un minimum de ZÉRO ne rend rien obligatoire. La première version
    // posait `must = 1` dès qu'un `qmin` était transmis — et l'écran en transmet
    // toujours un : placer une référence la déclarait obligatoire au réseau sans
    // que personne ne l'ait demandé. C'est le minimum qui engage, pas la place.
    if (isset($b['qmin'])) {
        $q = max(0, min(9999, (int) $b['qmin']));
        try {
            if ($q > 0) {
                Db::exec('INSERT INTO ceo_prod_product (ref, nom, categorie, qmin, must) VALUES (?,?,?,?,1)'
                    . ' ON DUPLICATE KEY UPDATE qmin = VALUES(qmin), must = 1',
                    [$ref, mb_substr(trim((string) ($b['nom'] ?? $ref)), 0, 190), '', $q]);
            } else {
                // Zéro : on ne touche NI au drapeau, ni à une fiche inexistante.
                Db::exec('UPDATE ceo_prod_product SET qmin = 0 WHERE ref = ?', [$ref]);
            }
        } catch (PDOException $e) { /* fiche produit indisponible : le placement tient quand même */ }
    }
    journalAdd('CEO', 'Planogramme', $ref,
        'Placée en « ' . $s['zone'] . ' · ' . $s['meuble'] . ' · ' . $s['niveau']
        . ' · position ' . $s['position'] . ' » ('
        . ($grille !== null
            ? $grille['parSlot'] . ' par emplacement, ' . $grille['cols'] . ' × ' . $grille['rangs']
              . ($grille['reste'] > 0 ? ', ' . $grille['reste'] . ' hors grille' : '')
            : $fronts . ' front(s)') . ')');
    $out = ['ok' => true, 'ref' => $ref, 'slotId' => $sid,
        'ou' => $s['zone'] . ' · ' . $s['meuble'] . ' · ' . $s['niveau'] . ' · ' . $s['position']];
    if ($grille !== null) { $out['grille'] = $grille; }
    if ($echange !== null) { $out['echange'] = $echange; }
    if ($regrille !== null) {
        $out['regrille'] = $regrille;
        journalAdd('CEO', 'Planogramme', $ref,
            'Grille refaite pour le nouvel emplacement : ' . $regrille['cols'] . ' × ' . $regrille['rangs']
            . ' (' . $regrille['parSlot'] . ' par emplacement)');
    }
    return $out;
}

/**
 * PUT /planogramme/note — la consigne de présentation.
 *
 * Trois cibles possibles : une référence, un meuble, une zone. Une consigne de
 * meuble vaut pour tout ce qu'il contient — c'est ce qui permet d'écrire une
 * fois « étiquettes face client » pour une vitrine entière.
 *
 * Un texte vide EFFACE la consigne : garder une ligne vide laisserait un
 * « consigne présente » trompeur à l'écran.
 */
function wr_plano_note(): array
{
    $b = body();
    $cible = (string) ($b['cible'] ?? '');
    if (!in_array($cible, ['ref', 'zone', 'meuble', 'niveau'], true)) {
        http_response_code(422); return ['error' => 'cible inconnue (ref, zone, meuble ou niveau)'];
    }
    $id = trim((string) ($b['cibleId'] ?? ''));
    if ($id === '') { http_response_code(422); return ['error' => 'la cible est requise']; }
    $texte = trim((string) ($b['texte'] ?? ''));

    if ($texte === '') {
        Db::exec('DELETE FROM pla_note WHERE cible = ? AND cible_id = ?', [$cible, $id]);
        return ['ok' => true, 'efface' => true];
    }
    $texte = mb_substr($texte, 0, 2000);
    // La gravité suit le barème partagé — jamais une échelle inventée ici.
    $sig = setting('signalement', []);
    $ech = [];
    foreach ((is_array($sig) && is_array($sig['niveaux'] ?? null)) ? $sig['niveaux'] : [] as $nv) {
        if (isset($nv['n'])) { $ech[(int) $nv['n']] = true; }
    }
    $g = (int) ($b['gravite'] ?? 3);
    if (!isset($ech[$g])) { $g = isset($ech[3]) ? 3 : (int) (array_key_first($ech) ?? 3); }
    $jour = static function ($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $u = setting('utilisateur', []);
    $auteur = is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 190) : 'CEO';

    Db::exec('INSERT INTO pla_note (cible, cible_id, texte, epinglee, gravite, du, au, auteur, maj_le)'
        . ' VALUES (?,?,?,?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE texte = VALUES(texte), epinglee = VALUES(epinglee),'
        . ' gravite = VALUES(gravite), du = VALUES(du), au = VALUES(au),'
        . ' auteur = VALUES(auteur), maj_le = VALUES(maj_le)',
        [$cible, $id, $texte, !empty($b['epinglee']) ? 1 : 0, $g,
         $jour($b['du'] ?? ''), $jour($b['au'] ?? ''), $auteur, date('Y-m-d H:i:s')]);
    return ['ok' => true, 'cible' => $cible, 'cibleId' => $id, 'gravite' => $g];
}

/**
 * POST /planogramme/photo — annexer une photo à un meuble, une zone, une
 * référence placée.
 *
 * La photo est rangée sur le DISQUE, sa seule référence en base : un fichier de
 * deux mégaoctets stocké en colonne serait relu à chaque lecture du
 * planogramme, pour n'être affiché que sur une fiche.
 *
 * Le contenu arrive en data-URL parce que tout le reste de l'application parle
 * JSON ; on ne bascule pas une seule route en multipart pour le plaisir. Le
 * type est déduit des OCTETS, jamais de l'extension annoncée : une extension
 * n'est qu'une affirmation du client.
 */
function wr_plano_photo(): array
{
    $b = body();
    $cible = (string) ($b['cible'] ?? '');
    if (!in_array($cible, ['ref', 'zone', 'meuble', 'niveau'], true)) {
        http_response_code(422); return ['error' => 'cible inconnue (ref, zone, meuble ou niveau)'];
    }
    $id = trim((string) ($b['cibleId'] ?? ''));
    if ($id === '') { http_response_code(422); return ['error' => 'la cible est requise']; }

    $dossier = __DIR__ . '/../public/uploads/plano';
    $rel = 'uploads/plano/' . $cible . '-' . preg_replace('/[^\w-]/', '', $id);

    // Corps vide = on retire la photo.
    $data = (string) ($b['data'] ?? '');
    if (trim($data) === '') {
        $ancien = Db::row('SELECT photo FROM pla_note WHERE cible = ? AND cible_id = ?', [$cible, $id]);
        if ($ancien !== null && !empty($ancien['photo'])) {
            $f = __DIR__ . '/../public/' . $ancien['photo'];
            if (is_file($f)) { @unlink($f); }
        }
        Db::exec('UPDATE pla_note SET photo = NULL WHERE cible = ? AND cible_id = ?', [$cible, $id]);
        // Une note qui ne porte plus ni texte ni photo n'a plus lieu d'être :
        // la garder laisserait un « consigne présente » trompeur à l'écran.
        Db::exec('DELETE FROM pla_note WHERE cible = ? AND cible_id = ?'
            . ' AND (texte IS NULL OR texte = \'\') AND photo IS NULL', [$cible, $id]);
        return ['ok' => true, 'photo' => null, 'retiree' => true];
    }

    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) {
        http_response_code(422); return ['error' => 'image illisible (data-URL attendue)'];
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 64) {
        http_response_code(422); return ['error' => 'image illisible'];
    }
    if (strlen($bin) > 4 * 1024 * 1024) {
        http_response_code(413); return ['error' => 'image trop lourde — 4 Mo au maximum'];
    }
    // Le type vient des octets : l'extension annoncée n'engage que le client.
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) {
        http_response_code(415); return ['error' => 'format non accepté — JPEG, PNG ou WebP'];
    }

    if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
        http_response_code(500); return ['error' => 'dossier des photos impossible à créer sur le serveur'];
    }
    // Une seule photo par cible : les anciennes extensions sont retirées, sans
    // quoi un JPEG remplacé par un PNG laisserait le premier orphelin.
    foreach (['jpg', 'png', 'webp'] as $e) {
        $f = __DIR__ . '/../public/' . $rel . '.' . $e;
        if (is_file($f)) { @unlink($f); }
    }
    $chemin = $rel . '.' . $ext;
    if (@file_put_contents(__DIR__ . '/../public/' . $chemin, $bin) === false) {
        http_response_code(500); return ['error' => 'écriture de la photo impossible sur le serveur'];
    }

    $u = setting('utilisateur', []);
    $auteur = is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 190) : 'CEO';
    Db::exec('INSERT INTO pla_note (cible, cible_id, photo, auteur, maj_le) VALUES (?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE photo = VALUES(photo), auteur = VALUES(auteur), maj_le = VALUES(maj_le)',
        [$cible, $id, $chemin, $auteur, date('Y-m-d H:i:s')]);
    return ['ok' => true, 'photo' => $chemin, 'octets' => strlen($bin),
        'largeur' => $info[0] ?? null, 'hauteur' => $info[1] ?? null];
}

/**
 * Enregistre la clé Anthropic. Elle ne repart JAMAIS vers l'écran : le
 * formulaire l'envoie, le serveur la garde, et l'état ne rend qu'une empreinte.
 * Un champ pré-rempli avec la clé la ferait apparaître dans chaque réponse HTTP,
 * dans le cache du navigateur et dans les captures d'écran.
 */
function wr_ia_compte(): array
{
    $b = body();
    $cur = setting('anthropic');
    if (!is_array($cur)) { $cur = []; }
    $cle = trim((string) ($b['cle'] ?? ''));
    // Champ laissé vide = on ne touche pas à la clé en place. Sans cette règle,
    // changer le modèle effacerait la clé.
    if ($cle !== '') { $cur['cle'] = $cle; }
    if (array_key_exists('effacer', $b) && !empty($b['effacer'])) { unset($cur['cle']); }
    $mod = trim((string) ($b['modele'] ?? ''));
    if ($mod !== '') { $cur['modele'] = $mod; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['anthropic', json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', '—', 'Assistance IA — ' . ($cle !== '' ? 'clé enregistrée' : 'réglage mis à jour'));
    return ['ok' => true] + Anthropic::statut();
}

/* --- fonds & redevances : écriture DANS le module marketing ------------------
 *
 * Le cockpit ne tient pas de second grand livre. Ces fonctions relaient la
 * saisie vers le module qui, lui, valide et écrit — et son refus revient tel
 * quel à l'écran. Recopier ici les règles du fonds (sens du mouvement, montant
 * positif, boutique du périmètre) donnerait deux jeux de règles à maintenir, et
 * c'est celui qui diverge qu'on découvrirait trop tard.
 */

/** Les champs qu'un mouvement accepte, tels que le module les nomme. */
function fondsChamps(array $b): array
{
    $out = [];
    foreach (['direction', 'shop_id', 'campaign_id', 'lever_id', 'movement_date',
        'period_from', 'period_to', 'label', 'amount', 'source', 'supplier_name',
        'document_ref'] as $k) {
        if (array_key_exists($k, $b)) { $out[$k] = $b[$k] === '' ? null : $b[$k]; }
    }
    return $out;
}

/**
 * La réponse du module, rendue à l'écran sans être réécrite.
 *
 * Un 422 « Le libellé du mouvement est obligatoire » est plus utile que
 * « échec » : c'est la phrase du module, elle dit quoi corriger.
 */
function fondsRendu(array $r, string $quoi): array
{
    if ($r['erreur'] !== null) {
        http_response_code($r['code'] >= 400 ? $r['code'] : 502);
        return ['error' => $r['erreur']];
    }
    $brut = $r['brut'] ?? [];
    return ['ok' => true, 'quoi' => $quoi,
        'id' => $brut['inserted_id'] ?? ($r['corps']['inserted_id'] ?? null),
        'message' => $brut['message'] ?? ($r['corps']['message'] ?? null),
        'reponse' => $r['corps']];
}

/**
 * Lecture commune des champs d'un mouvement, validée comme le module le
 * faisait : sens IN/OUT, montant strictement positif, date réelle, libellé.
 */
function fondsMouvementLit(array $b): array
{
    $dir = strtoupper(trim((string) ($b['direction'] ?? '')));
    if (!in_array($dir, ['IN', 'OUT'], true)) { throw new RuntimeException('sens du mouvement inconnu : attendu IN ou OUT'); }
    $montant = (float) ($b['amount'] ?? 0);
    if ($montant <= 0) { throw new RuntimeException('le montant doit être strictement positif'); }
    $date = trim((string) ($b['movement_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { throw new RuntimeException('date attendue au format AAAA-MM-JJ'); }
    $label = mb_substr(trim((string) ($b['label'] ?? '')), 0, 300);
    if ($label === '') { throw new RuntimeException('le libellé du mouvement est obligatoire'); }
    $sources = ['ROYALTY', 'REMISE_FOURNISSEUR', 'AGENCE', 'PRODUCTION', 'MEDIA', 'AUTRE'];
    $source = strtoupper(trim((string) ($b['source'] ?? 'AUTRE')));
    if (!in_array($source, $sources, true)) { $source = 'AUTRE'; }
    return ['direction' => $dir, 'amount' => $montant, 'movement_date' => $date, 'label' => $label,
        'source' => $source,
        'shop_id' => ($b['shop_id'] ?? null) ? (int) $b['shop_id'] : null,
        'campaign_id' => ($b['campaign_id'] ?? null) ? (int) $b['campaign_id'] : null,
        'lever_id' => ($b['lever_id'] ?? null) ? (int) $b['lever_id'] : null,
        'supplier_name' => mb_substr(trim((string) ($b['supplier_name'] ?? '')), 0, 160) ?: null,
        'document_ref' => mb_substr(trim((string) ($b['document_ref'] ?? '')), 0, 120) ?: null,
        // Un investissement est une DÉPENSE qualifiée : sur une entrée, le
        // drapeau n'a pas de sens et n'est pas retenu.
        'is_investment' => ($dir === 'OUT' && !empty($b['is_investment'])) ? 1 : 0];
}

/** POST /fonds/mouvement — ou PATCH quand un identifiant est donné. */
function wr_fonds_mouvement(?int $id): array
{
    $b = body();
    fournisseurAssure($b['supplier_name'] ?? null);
    try { $v = fondsMouvementLit($b); }
    catch (RuntimeException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }

    if ($id === null) {
        Db::exec('INSERT INTO mar_fund_movement (direction, shop_id, campaign_id, lever_id, movement_date, label, amount, source, supplier_name, document_ref, is_investment)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $v['direction'], $v['shop_id'], $v['campaign_id'], $v['lever_id'], $v['movement_date'],
            $v['label'], $v['amount'], $v['source'], $v['supplier_name'], $v['document_ref'],
            $v['is_investment'],
        ]);
        $nid = (int) Db::pdo()->lastInsertId();
        journalAdd('CEO', 'Fonds', $v['label'], ($v['direction'] === 'IN' ? 'Alimentation' : 'Dépense')
            . ' de ' . number_format($v['amount'], 2, ',', ' ') . ' € écrite au fonds');
        return ['ok' => true, 'id' => $nid, 'inserted_id' => $nid];
    }
    $dej = Db::row('SELECT label, recurrence_id FROM mar_fund_movement WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'mouvement introuvable']; }
    // Une échéance née d'un frais récurrent se corrige sur son modèle : la
    // corriger ici la ferait diverger des autres sans que rien ne le dise.
    if ($dej['recurrence_id'] !== null) {
        http_response_code(409);
        return ['error' => 'cette écriture vient d’un frais récurrent — corrigez le frais, pas l’échéance'];
    }
    Db::exec('UPDATE mar_fund_movement SET direction = ?, shop_id = ?, campaign_id = ?, lever_id = ?,
              movement_date = ?, label = ?, amount = ?, source = ?, supplier_name = ?, document_ref = ?,
              is_investment = ?
              WHERE id = ?', [
        $v['direction'], $v['shop_id'], $v['campaign_id'], $v['lever_id'], $v['movement_date'],
        $v['label'], $v['amount'], $v['source'], $v['supplier_name'], $v['document_ref'],
        $v['is_investment'], $id,
    ]);
    journalAdd('CEO', 'Fonds', $v['label'], 'Écriture ' . $id . ' corrigée');
    return ['ok' => true, 'id' => $id];
}

function wr_fonds_mouvement_suppr(int $id): array
{
    $dej = Db::row('SELECT label FROM mar_fund_movement WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'mouvement introuvable']; }
    Db::exec('DELETE FROM mar_fund_movement WHERE id = ?', [$id]);
    journalAdd('CEO', 'Fonds', $dej['label'], 'Écriture ' . $id . ' supprimée du grand livre');
    return ['ok' => true];
}

/**
 * POST /fonds/recurrence — un frais qui revient, écrit en autant d'échéances.
 *
 * Le modèle ET ses échéances s'écrivent d'un coup, comme le module le
 * faisait ; la suppression du modèle emporte les échéances (FK en CASCADE).
 */
function wr_fonds_recurrence(): array
{
    $b = body();
    fournisseurAssure($b['supplier_name'] ?? null);
    try { $v = fondsMouvementLit(array_merge($b, ['movement_date' => $b['starts_on'] ?? ''])); }
    catch (RuntimeException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }
    $freq = (string) ($b['frequency'] ?? '');
    if (!in_array($freq, ['month', 'quarter', 'year'], true)) {
        http_response_code(422); return ['error' => 'fréquence inconnue : month, quarter ou year'];
    }
    $fin = trim((string) ($b['ends_on'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) || $fin < $v['movement_date']) {
        http_response_code(422); return ['error' => 'un frais récurrent est borné : donnez une fin postérieure au début'];
    }

    Db::exec('INSERT INTO mar_fund_recurrence (direction, frequency, label, amount, starts_on, ends_on, shop_id, campaign_id, lever_id, source, supplier_name, document_ref)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $v['direction'], $freq, $v['label'], $v['amount'], $v['movement_date'], $fin,
        $v['shop_id'], $v['campaign_id'], $v['lever_id'], $v['source'], $v['supplier_name'], $v['document_ref'],
    ]);
    $rid = (int) Db::pdo()->lastInsertId();

    $pas = ['month' => '+1 month', 'quarter' => '+3 months', 'year' => '+1 year'][$freq];
    $d = new DateTimeImmutable($v['movement_date']);
    $limite = new DateTimeImmutable($fin);
    $n = 0;
    while ($d <= $limite && $n < 120) {
        Db::exec('INSERT INTO mar_fund_movement (direction, shop_id, campaign_id, lever_id, movement_date, label, amount, source, supplier_name, document_ref, recurrence_id)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $v['direction'], $v['shop_id'], $v['campaign_id'], $v['lever_id'], $d->format('Y-m-d'),
            $v['label'], $v['amount'], $v['source'], $v['supplier_name'], $v['document_ref'], $rid,
        ]);
        $n++;
        $d = $d->modify($pas);
    }
    journalAdd('CEO', 'Fonds', $v['label'], 'Frais récurrent créé — ' . $n . ' échéance(s), '
        . number_format($v['amount'] * $n, 2, ',', ' ') . ' € au total');
    return ['ok' => true, 'inserted_id' => $rid, 'occurrences' => $n, 'total_amount' => $v['amount'] * $n];
}

function wr_fonds_recurrence_suppr(int $id): array
{
    $dej = Db::row('SELECT label FROM mar_fund_recurrence WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'frais récurrent introuvable']; }
    // La FK est en CASCADE : les échéances écrites partent avec le modèle.
    Db::exec('DELETE FROM mar_fund_recurrence WHERE id = ?', [$id]);
    journalAdd('CEO', 'Fonds', $dej['label'], 'Frais récurrent supprimé, échéances comprises');
    return ['ok' => true];
}

/** PUT /fonds/royalties — taux et chiffres d'affaires du mois, ensemble. */
function wr_fonds_royalties(): array
{
    $b = body();
    return fondsRendu(marketingAppel('PUT', '/funds/royalties', [
        'month' => (string) ($b['month'] ?? ''),
        'rates' => is_array($b['rates'] ?? null) ? $b['rates'] : [],
        'revenues' => is_array($b['revenues'] ?? null) ? $b['revenues'] : [],
    ]), 'redevances');
}

/**
 * POST /fonds/royalties/generer — les redevances du mois écrites au fonds,
 * UNE écriture par magasin, pour la SEULE sorte Marketing : c'est le fonds
 * MARKETING qu'on alimente ici. Les autres sortes (Marque, Assistance)
 * restent des revenus de la marque — les verser au fonds gonflerait
 * un solde que le réseau croirait disponible pour ses campagnes. Le module
 * marketing autonome a disparu : on écrit ses tables en direct, comme le
 * reste du grand livre.
 */
function wr_fonds_royalties_generer(): array
{
    $b = body();
    // `shop_id` restreint l'écriture à UN client : c'est le bouton « Insérer
    // au grand livre » de sa ligne. Absent, tout le réseau part d'un coup.
    return fondsRoyaltiesEcrire((string) ($b['month'] ?? date('Y-m')), !empty($b['apercu']),
        !empty($b['shop_id']) ? (int) $b['shop_id'] : null);
}

/**
 * Chaque écriture porte la période couverte (period_from/period_to) et une
 * pièce canonique « ROY AAAA-MM SORTE #magasin » : c'est elle qui rend la
 * génération IDEMPOTENTE — regénérer un mois n'écrit que ce qui manque,
 * jamais deux fois la même redevance. `apercu` rend les lignes sans rien
 * écrire : on voit ce qui partirait, on confirme ensuite.
 */
function fondsRoyaltiesEcrire(string $mois, bool $apercu, ?int $seulShop = null): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
        http_response_code(422);
        return ['error' => 'mois attendu au format AAAA-MM'];
    }
    $don = fondsRoyaltiesCalcul($mois);
    if ($don === null) {
        http_response_code(502);
        return ['error' => 'grille des taux ou chiffres d’affaires illisibles — configurez le compte consultant (Mon compte)'];
    }
    // Ce que le mois porte déjà, rapproché par la pièce canonique —
    // l'index (source, period_from, shop_id) est taillé pour cette lecture.
    $deja = [];
    foreach (Db::rows("SELECT document_ref FROM mar_fund_movement
                       WHERE source = 'ROYALTY' AND period_from = ?", [$don['du']]) as $m) {
        if (($m['document_ref'] ?? '') !== '') { $deja[(string) $m['document_ref']] = true; }
    }
    // Le FK n'accepte que les magasins présents dans mar_shop : un magasin du
    // panel qui n'y serait pas s'écrit sans rattachement — le libellé et la
    // pièce le nomment, la ligne le signale.
    $refShops = [];
    foreach (Db::rows('SELECT id FROM mar_shop') as $r) { $refShops[(int) $r['id']] = true; }

    $lignes = []; $ecrites = 0; $dejaN = 0; $aEcrire = 0.0;
    foreach ($don['shops'] as $s) {
        if ($seulShop !== null && (int) $s['shop_id'] !== $seulShop) { continue; }
        if (!$s['enabled'] || $s['ca'] === null) { continue; }
        foreach ($s['sortes'] as $k) {
            // Seule la redevance MARKETING entre au fonds : les autres sortes
            // reviennent à la marque, pas à la caisse commune des campagnes.
            if (($k['code'] ?? '') !== 'MARKETING') { continue; }
            if ($k['du'] === null || $k['du'] <= 0) { continue; }
            $piece = 'ROY ' . $mois . ' ' . $k['code'] . ' #' . $s['shop_id'];
            $estLa = isset($deja[$piece]);
            $horsRef = !isset($refShops[$s['shop_id']]);
            $lignes[] = ['shop_id' => $s['shop_id'], 'magasin' => $s['shop_name'],
                'sorte' => $k['label'], 'taux_pct' => round($k['taux'] * 100, 2),
                'montant' => $k['du'], 'piece' => $piece, 'deja' => $estLa, 'horsRef' => $horsRef];
            if ($estLa) { $dejaN++; continue; }
            $aEcrire += $k['du'];
            if ($apercu) { continue; }
            Db::exec('INSERT INTO mar_fund_movement (direction, shop_id, movement_date, period_from, period_to, label, amount, source, document_ref)
                      VALUES (?,?,?,?,?,?,?,?,?)', [
                'IN', $horsRef ? null : $s['shop_id'], $don['au'], $don['du'], $don['fin'],
                'Redevance ' . $k['label'] . ' ' . $mois . ' — ' . $s['shop_name'],
                $k['du'], 'ROYALTY', $piece,
            ]);
            $ecrites++;
        }
    }
    if (!$apercu && $ecrites > 0) {
        journalAdd('CEO', 'Fonds', 'Redevances ' . $mois,
            $ecrites . ' écriture(s) au fonds — la redevance marketing de chaque magasin');
    }
    return ['ok' => true, 'apercu' => $apercu, 'month' => $mois, 'lignes' => $lignes,
        'aEcrire' => round($aEcrire, 2), 'ecrites' => $ecrites, 'dejaPassees' => $dejaN];
}

/**
 * Le fournisseur nommé dans une écriture rejoint le référentiel.
 *
 * On ne crée PAS une seconde table de fournisseurs : c'est `ceo_supplier`, celle
 * de la centrale d'achat, qui s'enrichit. Taper un nom dans le grand livre le
 * rend disponible partout ensuite — et évite les dix orthographes du même
 * fournisseur qu'une saisie libre finit toujours par produire.
 *
 * Rapprochement insensible à la casse et aux espaces : « Sodexo » et « sodexo »
 * sont le même fournisseur.
 */
function fournisseurAssure(?string $nom): void
{
    $nom = trim((string) $nom);
    if ($nom === '') { return; }
    // 120 : la borne de ceo_supplier.name — au-delà, l'insert claque en mode
    // strict et emporte l'écriture comptable avec lui.
    $nom = mb_substr($nom, 0, 120);
    try {
        $dej = Db::row('SELECT id FROM ceo_supplier WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))', [$nom]);
        if ($dej !== null) { return; }
        // email '' et non null : la colonne est NOT NULL (centrale d'achat).
        // Avec null, TOUTE écriture portant un fournisseur nouveau mourait ici
        // en « base de données indisponible » — avant même le mouvement.
        Db::exec('INSERT INTO ceo_supplier (id, name, perimeter, email) VALUES (?,?,?,?)',
            ['f' . bin2hex(random_bytes(4)), $nom, 'Fonds marketing', '']);
        journalAdd('CEO', 'Fournisseur', $nom, 'Fournisseur ajouté depuis le grand livre du fonds');
    } catch (Throwable $e) {
        // Enrichir le référentiel est un confort : son échec ne bloque jamais
        // l'écriture du grand livre qui suit.
    }
}

/**
 * PUT /taches/maitrise — la main sur le contrôle par exception.
 *
 * Trois gestes, et un seul principe : le calcul propose, l'humain dispose.
 *  - « rouvrir » : un nouveau gérant, des travaux, une réclamation — le
 *    contrôle revient quelle que soit la moyenne, et tient une cadence
 *    complète (sinon le calcul l'effacerait au rendu suivant).
 *  - « permanent » / « auto » : hygiène et sécurité ne se gagnent pas au
 *    mérite ; on les sort du dispositif, ou on les y remet.
 */
function wr_taches_maitrise(): array
{
    $b = body();
    $sid = (int) ($b['shopId'] ?? 0);
    $tid = (int) ($b['taskId'] ?? 0);
    $action = (string) ($b['action'] ?? '');
    if ($sid <= 0 || $tid <= 0 || !in_array($action, ['rouvrir', 'permanent', 'auto'], true)) {
        http_response_code(422);
        return ['error' => 'shopId, taskId et action (rouvrir | permanent | auto) sont requis'];
    }
    $u = setting('utilisateur', []);
    $qui = is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 80) : 'CEO';
    $now = date('Y-m-d H:i:s');

    if ($action === 'rouvrir') {
        Db::exec('INSERT INTO ceo_task_maitrise (id_shop, id_task, etat, depuis, motif, force_par, force_le)
                  VALUES (?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE etat = VALUES(etat), depuis = VALUES(depuis),
                    recontrole_le = NULL, motif = VALUES(motif),
                    force_par = VALUES(force_par), force_le = VALUES(force_le)',
            [$sid, $tid, 'visible', $now, 'Rouvert manuellement par ' . $qui, $qui, $now]);
        journalAdd('CEO', 'Contrôle', 'Boutique ' . $sid, 'Contrôle rouvert — tâche ' . $tid);
        return ['ok' => true, 'etat' => 'visible'];
    }

    $perm = $action === 'permanent' ? 1 : 0;
    Db::exec('INSERT INTO ceo_task_maitrise (id_shop, id_task, etat, depuis, jamais_masquer, motif)
              VALUES (?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE jamais_masquer = VALUES(jamais_masquer),
                etat = VALUES(etat), depuis = VALUES(depuis), motif = VALUES(motif)',
        [$sid, $tid, $perm ? 'visible' : 'visible', $now, $perm,
         $perm ? 'Contrôle permanent — jamais masqué' : 'Remis en contrôle automatique']);
    journalAdd('CEO', 'Contrôle', 'Boutique ' . $sid,
        ($perm ? 'Contrôle rendu permanent' : 'Contrôle remis en automatique') . ' — tâche ' . $tid);
    return ['ok' => true, 'permanent' => (bool) $perm];
}

/**
 * PUT /stores/{id}/charges?exercice=…&mois=… — les charges du mois.
 *
 * Les charges s'encodent chaque mois : un montant par poste, pour ce
 * magasin-là. Un montant vide EFFACE la case au lieu d'écrire zéro — « pas
 * encore encodé » et « rien dépensé » ne sont pas la même nouvelle, et l'écran
 * de suivi doit pouvoir les distinguer.
 */
function wr_shop_charges(string $shopId): array
{
    $shop = Db::row('SELECT name FROM ceo_shop WHERE id = ?', [$shopId]);
    if ($shop === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $exercice = (int) ($_GET['exercice'] ?? date('Y'));
    $mois = (int) ($_GET['mois'] ?? 0);
    if ($mois < 1 || $mois > 12) { http_response_code(422); return ['error' => 'mois attendu entre 1 et 12']; }

    $b = body();
    $postes = is_array($b['postes'] ?? null) ? $b['postes'] : [];
    $ecrits = 0; $effaces = 0;
    foreach ($postes as $id => $montant) {
        $id = preg_replace('/[^a-z0-9]+/', '', strtolower((string) $id));
        if ($id === '') { continue; }
        if ($montant === null || $montant === '') {
            Db::exec('DELETE FROM ceo_shop_charge_month WHERE shop_id = ? AND fiscal_year = ? AND month = ? AND poste_id = ?',
                [$shopId, $exercice, $mois, $id]);
            $effaces++;
            continue;
        }
        Db::exec('INSERT INTO ceo_shop_charge_month (shop_id, fiscal_year, month, poste_id, amount)
                  VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
            [$shopId, $exercice, $mois, $id, (float) $montant]);
        $ecrits++;
    }
    // `journal=0` : l'écran écrit à CHAQUE saisie. Douze cases remplies
    // feraient douze lignes de journal, et le journal ne raconterait plus
    // rien. Le bouton « Enregistrer les charges du mois », lui, journalise.
    if (($_GET['journal'] ?? '') !== '0') {
        journalAdd('CEO', 'Budget', $shop['name'], 'Charges encodées — ' . $exercice . '/' . str_pad((string) $mois, 2, '0', STR_PAD_LEFT)
            . ' : ' . $ecrits . ' poste(s)' . ($effaces ? ', ' . $effaces . ' effacé(s)' : ''));
    }
    return ['ok' => true, 'ecrits' => $ecrits, 'effaces' => $effaces];
}

/**
 * PUT /production/fin/{ref} — programmer (ou annuler) l'arrêt d'une référence.
 *
 * Une date vide ANNULE l'arrêt : la ligne disparaît, la référence redevient
 * ordinaire. On n'écrit pas un « statut annulé » qu'il faudrait filtrer
 * partout — l'absence dit déjà tout.
 */
function wr_prod_fin(string $ref): array
{
    $b = body();
    $date = trim((string) ($b['date'] ?? ''));
    if ($date === '') {
        Db::exec('DELETE FROM ceo_prod_fin WHERE ref = ?', [$ref]);
        journalAdd('CEO', 'Produit', $ref, 'Arrêt de la référence annulé');
        return ['ok' => true, 'annule' => true];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(422);
        return ['error' => 'date attendue au format AAAA-MM-JJ'];
    }
    $note = mb_substr(trim((string) ($b['note'] ?? '')), 0, 300);
    Db::exec('INSERT INTO ceo_prod_fin (ref, end_on, note) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE end_on = VALUES(end_on), note = VALUES(note)', [$ref, $date, $note]);
    journalAdd('CEO', 'Produit', $ref, 'Arrêt programmé au ' . $date . ($note !== '' ? ' — ' . $note : ''));
    return ['ok' => true, 'date' => $date];
}

/**
 * POST /consultants/note — une note au consultant, envoyée comme tâche.
 *
 * La tâche vit dans un projet dédié « Suivi consultants », créé au premier
 * envoi : l'API du panel n'expose aucun dépôt de tâche consultant (mesuré),
 * et une note qui ne vit nulle part ne serait jamais suivie. Le projet la
 * porte, l'écran Tâches consultants la montre, l'échéance la rappelle.
 */
/**
 * Déposer la note dans le panel — le seul canal qui la fasse apparaître côté
 * consultant.
 *
 * Trois choses ont demandé une mesure, et sont écrites ici pour qu'on n'ait pas
 * à les redécouvrir :
 *  · la note s'attache à un MAGASIN, pas à une personne — le panel ne sait pas
 *    adresser une note à quelqu'un ;
 *  · `consultant_id` est obligatoire dans le corps, et ce n'est PAS le
 *    `membership_id` (6) mais l'`auth_user_id` du compte (104) ;
 *  · les types du panel sont un référentiel fermé (VISIT, AUDIT, COACHING,
 *    ISSUE, OTHER) : les nôtres s'y rangent, et le libellé exact reste écrit en
 *    tête du contenu pour que rien ne se perde à la traduction.
 *
 * Un échec ne fait PAS échouer la note : elle vit d'abord dans le cockpit. Le
 * motif remonte à l'écran plutôt que de disparaître.
 */
function notePanelDeposer($shopId, string $type, string $texte, string $contexte): array
{
    $out = ['id' => null, 'motif' => null];
    $sid = (int) ($shopId ?? 0);
    if ($sid <= 0) { $out['motif'] = 'note réseau : le panel n’attache une note qu’à un magasin'; return $out; }
    if (!PanelApi::configured()) { $out['motif'] = 'compte consultant non configuré'; return $out; }
    $cid = consultantAuthId();
    if ($cid === null) { $out['motif'] = 'identifiant consultant du compte inconnu'; return $out; }

    $vers = ['À corriger sur place' => 'ISSUE', 'Rappel de procédure' => 'AUDIT',
             'Point de formation' => 'COACHING', 'Félicitations' => 'OTHER'];
    $code = $vers[$type] ?? 'OTHER';
    $tid = 0; $premier = 0;
    foreach (PanelApi::noteTypes() as $t) {
        $id = (int) ($t['id'] ?? 0);
        if ($premier === 0) { $premier = $id; }
        if ((string) ($t['code'] ?? '') === $code) { $tid = $id; }
    }
    if ($tid === 0) { $tid = $premier; }
    if ($tid === 0) { $out['motif'] = 'aucun type de note dans le panel'; return $out; }

    $u = setting('utilisateur', []);
    $qui = is_array($u) && !empty($u['nom']) ? (string) $u['nom'] : 'la direction';
    $contenu = '[' . $type . '] ' . $texte
        . ($contexte !== '' ? "\n\nContexte : " . $contexte : '')
        . "\n\n— déposé depuis le Cockpit CEO par " . $qui;

    [$ok, $res] = PanelApi::noterMagasin($sid, $tid, $contenu, $cid);
    if (!$ok) { $out['motif'] = PanelApi::$lastError ?? 'le panel a refusé la note'; return $out; }
    foreach ([$res['inserted_id'] ?? null, $res['id'] ?? null] as $v) {
        if (is_numeric($v)) { $out['id'] = (int) $v; break; }
    }
    return $out;
}

/**
 * Le nom d'un consultant, cherché LÀ OÙ L'ÉCRAN L'A PRIS.
 *
 * `/consultants` rend « u<id de user_membership> » quand les tables du panel
 * sont là, et l'identifiant de `ceo_consultant` sinon (installation autonome).
 * L'écriture, elle, ne consultait que `ceo_consultant` : « u8 » n'y étant pas,
 * chaque note au consultant repartait en « consultant inconnu » — l'écran
 * disait « Note non envoyée », et RIEN n'était écrit. Deux référentiels pour
 * une même question, c'est toujours l'écriture qui perd. On interroge donc les
 * deux, dans l'ordre où la lecture les sert.
 */
function consultantNom(string $id): ?string
{
    if (preg_match('/^u(\d+)$/', $id, $m)) {
        try {
            $r = Db::row("SELECT COALESCE(NULLIF(TRIM(p.display_name), ''),
                                 NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))), ''),
                                 CONCAT('Consultant #', m.id)) AS nom
                            FROM user_membership m
                            LEFT JOIN user_profile p ON p.auth_user_id = m.auth_user_id
                           WHERE m.app = 'CONSULTANT' AND m.is_active = 1 AND m.id = ?", [(int) $m[1]]);
            if ($r !== null) { return (string) $r['nom']; }
        } catch (PDOException $e) { /* tables du panel absentes : repli local */ }
    }
    try {
        $r = Db::row('SELECT name FROM ceo_consultant WHERE id = ?', [$id]);
        if ($r !== null) { return (string) $r['name']; }
    } catch (PDOException $e) { /* pas de référentiel local non plus */ }
    return null;
}

function wr_consultant_note(): array
{
    $b = body();
    $qui = (string) ($b['consultantId'] ?? '');
    $texte = trim((string) ($b['note'] ?? ''));
    if ($qui === '' || $texte === '') {
        http_response_code(422);
        return ['error' => 'le consultant et la note sont requis'];
    }
    $type = mb_substr(trim((string) ($b['type'] ?? 'Note')), 0, 40);
    $nomC = consultantNom($qui);
    if ($nomC === null) { http_response_code(404); return ['error' => 'consultant inconnu']; }
    $cons = ['name' => $nomC];

    $contexte = trim((string) ($b['contexte'] ?? ''));
    $journal = 'Note au consultant ' . $cons['name'] . ' — [' . $type . '] ' . mb_substr($texte, 0, 160)
        . ($contexte !== '' ? ' (' . $contexte . ')' : '');

    // ── Le dépôt dans le PANEL, pour que la note existe là où le consultant
    // travaille. Elle s'attache au MAGASIN (le panel ne sait pas adresser une
    // note à quelqu'un) : sans magasin, il n'y a rien à déposer.
    $panel = notePanelDeposer($b['shopId'] ?? null, $type, $texte, $contexte);

    if (($b['comme'] ?? 'tache') !== 'tache') {
        journalAdd('CEO', 'Note consultant', $cons['name'], $journal
            . ($panel['id'] ? ' → panel #' . $panel['id'] : ''));
        return ['ok' => true, 'comme' => 'note', 'panel' => $panel];
    }

    // VARCHAR(16) sur ceo_project.id : l'identifiant reste court.
    $pid = 'px-suivi-cons';
    if (Db::row('SELECT id FROM ceo_project WHERE id = ?', [$pid]) === null) {
        Db::exec('INSERT INTO ceo_project (id, name, famille, status, priority, axe, axes_json, kpis_json, value_txt, starts_on, ends_on, budget, value_est, value_real) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL)', [
            $pid, 'Suivi consultants', 'Organisation & coûts', 'En cours', 'Moyenne', '',
            json_encode([]), json_encode([]),
            'Les notes posées depuis le contrôle des tâches : chaque note envoyée comme tâche vit ici.',
            date('Y-m-d'), null,
        ]);
    }
    // L'identifiant tient sur VARCHAR(16). L'horodatage seul suffisait tant que
    // deux notes ne partaient pas dans la même milliseconde : à la deuxième,
    // la clé primaire refusait l'écriture et l'écran recevait une erreur 500
    // sans rien comprendre. Deux chiffres tirés au sort ferment la porte.
    $tid = 'cn' . substr((string) round(microtime(true) * 1000), -8) . random_int(10, 99);
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['due'] ?? '')) ? $b['due'] : date('Y-m-d', time() + 7 * 86400);
    // La tâche de contrôle d'origine voyage avec la note : c'est elle qui
    // porte la photo et ses repères.
    $srcTask = trim((string) ($b['srcTaskId'] ?? ''));
    $srcDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['srcDate'] ?? '')) ? (string) $b['srcDate'] : null;
    $shopId = ($b['shopId'] ?? null) ?: null;
    Db::exec('INSERT INTO ceo_project_task (id, project_id, name, owner_kind, owner_id, shop_id, due_on, done_on, description, src_shop, src_task, src_date, panel_note) VALUES (?,?,?,?,?,?,?,NULL,?,?,?,?,?)', [
        // owner_kind est un ENUM('c','s') : « c » = consultant.
        $tid, $pid, '[' . $type . '] ' . mb_substr($texte, 0, 160), 'c', $qui,
        $shopId, $due,
        $texte . ($contexte !== '' ? "\n\nContexte : " . $contexte : ''),
        $srcTask !== '' ? $shopId : null, $srcTask !== '' ? $srcTask : null,
        $srcTask !== '' ? $srcDate : null, $panel['id'],
    ]);
    journalAdd('CEO', 'Note consultant', $cons['name'], $journal . ' → tâche ' . $tid . ', échéance ' . $due
        . ($panel['id'] ? ', panel #' . $panel['id'] : ''));
    return ['ok' => true, 'comme' => 'tache', 'tacheId' => $tid, 'due' => $due, 'panel' => $panel];
}

/* --- campagnes marketing : le cockpit écrit dans les tables mar_* ------------
 *
 * Le module autonome disparaît ; ces écritures reprennent le strict nécessaire
 * de son assistant — créer, corriger, supprimer une campagne, et tenir le
 * référentiel des types. Les règles reprises sont celles du module : un statut
 * doit exister dans mar_campaign_status, les dates restent des dates.
 */

/** POST /marketing/campagne — ou PATCH /marketing/campagne/{id}. */
function wr_mkt_campagne(?int $id): array
{
    $b = body();
    $statuts = array_column(Db::rows('SELECT code FROM mar_campaign_status'), 'code');
    $lit = static function (array $b) use ($statuts): array {
        $out = [];
        if (array_key_exists('nom', $b)) {
            $nom = mb_substr(trim((string) $b['nom']), 0, 200);
            if ($nom === '') { throw new RuntimeException('le nom de la campagne est requis'); }
            $out['name'] = $nom;
        }
        if (array_key_exists('typeId', $b)) { $out['type_id'] = $b['typeId'] ? (int) $b['typeId'] : null; }
        if (array_key_exists('scope', $b)) { $out['scope'] = $b['scope'] === 'LOCALE' ? 'LOCALE' : 'RESEAU'; }
        if (array_key_exists('statut', $b)) {
            if (!in_array($b['statut'], $statuts, true)) { throw new RuntimeException('statut inconnu : ' . $b['statut']); }
            $out['status_code'] = $b['statut'];
        }
        foreach (['debut' => 'starts_on', 'fin' => 'ends_on'] as $k => $col) {
            if (!array_key_exists($k, $b)) { continue; }
            $v = trim((string) $b[$k]);
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { throw new RuntimeException($k . ' : date attendue au format AAAA-MM-JJ'); }
            $out[$col] = $v === '' ? null : $v;
        }
        if (array_key_exists('budget', $b)) { $out['budget_amount'] = max(0, (float) $b['budget']); }
        return $out;
    };

    try { $vals = $lit($b); }
    catch (RuntimeException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }

    if ($id === null) {
        if (!isset($vals['name'])) { http_response_code(422); return ['error' => 'le nom de la campagne est requis']; }
        $marque = Db::row('SELECT id FROM mar_brand ORDER BY id LIMIT 1');
        if ($marque === null) { http_response_code(422); return ['error' => 'aucune marque dans mar_brand']; }
        Db::exec('INSERT INTO mar_campaign (brand_id, type_id, name, scope, status_code, starts_on, ends_on, budget_amount)
                  VALUES (?,?,?,?,?,?,?,?)', [
            (int) $marque['id'], $vals['type_id'] ?? null, $vals['name'],
            $vals['scope'] ?? 'RESEAU', $vals['status_code'] ?? 'draft',
            $vals['starts_on'] ?? null, $vals['ends_on'] ?? null, $vals['budget_amount'] ?? 0,
        ]);
        $nid = (int) Db::pdo()->lastInsertId();
        journalAdd('CEO', 'Campagne', $vals['name'], 'Campagne créée depuis le pilotage réseau');
        return ['ok' => true, 'id' => $nid];
    }

    $dej = Db::row('SELECT name FROM mar_campaign WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($vals === []) { return ['ok' => true, 'id' => $id]; }
    $set = []; $args = [];
    foreach ($vals as $col => $v) { $set[] = $col . ' = ?'; $args[] = $v; }
    $args[] = $id;
    Db::exec('UPDATE mar_campaign SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    journalAdd('CEO', 'Campagne', $dej['name'], 'Campagne modifiée — ' . implode(', ', array_keys($vals)));
    return ['ok' => true, 'id' => $id];
}

/** DELETE /marketing/campagne/{id}. */
function wr_mkt_campagne_suppr(int $id): array
{
    $dej = Db::row('SELECT name FROM mar_campaign WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    // Les campagnes locales greffées dessus seraient orphelines : on refuse,
    // en le disant, plutôt que de les détacher en silence.
    $filles = Db::row('SELECT COUNT(*) n FROM mar_campaign WHERE parent_campaign_id = ?', [$id]);
    if ((int) $filles['n'] > 0) {
        http_response_code(409);
        return ['error' => $filles['n'] . ' campagne(s) locale(s) sont greffées sur celle-ci — supprimez-les d’abord'];
    }
    Db::exec('DELETE FROM mar_campaign_shop WHERE campaign_id = ?', [$id]);
    Db::exec('DELETE FROM mar_campaign WHERE id = ?', [$id]);
    journalAdd('CEO', 'Campagne', $dej['name'], 'Campagne supprimée');
    return ['ok' => true];
}

/** POST /marketing/type — ou PATCH /marketing/type/{id}, DELETE. */
function wr_mkt_type(?int $id): array
{
    $b = body();
    $cols = mktTypeColonnes();
    // On n'écrit que les colonnes que la base porte réellement : le schéma des
    // tables mar_* vient des migrations du module, et une base restée en
    // arrière ferait échouer l'UPDATE entier sur une colonne absente.
    $gardeCols = static function (array $champs) use ($cols): array {
        return array_filter($champs, fn ($k) => in_array($k, $cols, true), ARRAY_FILTER_USE_KEY);
    };

    // Le corps de l'écran parle français, la table parle le schéma du module.
    // Une seule traduction, ici, pour la création comme pour la modification.
    $duCorps = static function (array $b): array {
        $out = [];
        foreach ([['nom', 'label'], ['description', 'description'], ['couleur', 'color_hex'],
                  ['icone', 'icon_key'], ['badge', 'lever_badge_label'], ['kpi', 'default_kpi_label']] as [$k, $col]) {
            if (array_key_exists($k, $b)) { $out[$col] = $b[$k]; }
        }
        if (array_key_exists('levierId', $b)) { $out['lever_id'] = $b['levierId']; }
        return $out;
    };

    if ($id === null) {
        try { $champs = mktTypeValide($duCorps($b)); }
        catch (RuntimeException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }

        // Le code se dérive du nom quand il n'est pas donné : il n'est ni
        // affiché ni traduit, et le faire saisir n'apporte qu'une occasion de
        // se tromper. Il reste modifiable à la création, jamais après.
        $code = mktSlug(trim((string) ($b['code'] ?? '')) !== '' ? (string) $b['code'] : $champs['label']);
        if ($code === '') { http_response_code(422); return ['error' => 'Le nom ne donne aucun code exploitable : ajoutez des lettres.']; }
        if (Db::row('SELECT 1 FROM mar_campaign_type WHERE code = ?', [$code]) !== null) {
            http_response_code(422); return ['error' => 'Un type porte déjà le code « ' . $code . ' ».'];
        }

        // En fin de liste : un type nouveau n'a pas à s'insérer avant ceux que
        // l'équipe a l'habitude de voir en premier.
        $rang = (int) Db::row('SELECT COALESCE(MAX(sort_order), 0) + 1 r FROM mar_campaign_type')['r'];
        $ins = $gardeCols($champs) + ['code' => $code, 'sort_order' => $rang, 'is_active' => 1];
        $noms = array_keys($ins);
        Db::exec('INSERT INTO mar_campaign_type (' . implode(', ', $noms) . ') VALUES ('
            . implode(', ', array_fill(0, count($noms), '?')) . ')', array_values($ins));
        // L'id se lit AVANT le journal : `lastInsertId()` porte le dernier
        // AUTO_INCREMENT de la connexion, et journalAdd() en insère un autre.
        // L'écran recevait jusqu'ici l'identifiant de la ligne de journal.
        $nid = (int) Db::pdo()->lastInsertId();
        journalAdd('CEO', 'Paramètre', $champs['label'], 'Type de campagne créé');
        return ['ok' => true, 'id' => $nid];
    }

    $lecture = 'SELECT ' . implode(', ', array_intersect(
        ['label', 'description', 'color_hex', 'icon_key', 'icon_path', 'lever_id',
         'lever_badge_label', 'default_kpi_label', 'is_active'], $cols)) . ' FROM mar_campaign_type WHERE id = ?';
    $dej = Db::row($lecture, [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'type inconnu']; }

    // Fusion, pas remplacement. Un appel qui ne porte que le nom ne doit pas
    // effacer l'icône, la couleur et le levier au passage : c'est ce que fait
    // une mise à jour partielle interprétée comme une réécriture, et rien ne le
    // signale — la carte se vide, simplement.
    $recu = $duCorps($b);
    $fusion = [];
    foreach (['label', 'description', 'color_hex', 'icon_key', 'lever_id', 'lever_badge_label', 'default_kpi_label'] as $col) {
        $fusion[$col] = array_key_exists($col, $recu) ? $recu[$col] : ($dej[$col] ?? null);
        // Vider un champ reste possible : c'est la chaîne vide qui l'exprime,
        // explicitement, là où l'absence de clé ne dit rien.
        if (array_key_exists($col, $recu) && ($recu[$col] === '' || $recu[$col] === null)) { $fusion[$col] = null; }
    }

    try { $champs = mktTypeValide($fusion); }
    catch (RuntimeException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }

    // Les types livrés portent un tracé sans clé : ils sont antérieurs à la
    // bibliothèque. Redériver le tracé depuis la clé les effacerait au premier
    // changement de nom — l'icône disparaîtrait de la carte sans qu'on y ait
    // touché. Tant que l'appel ne parle pas d'icône, on laisse la sienne.
    if (!array_key_exists('icone', $b)) {
        $champs['icon_key'] = $dej['icon_key'] ?? null;
        $champs['icon_path'] = $dej['icon_path'] ?? null;
    }
    // Absent du corps = inchangé : l'éditeur n'envoie pas toujours l'activation,
    // et la ramener à 1 par défaut réactiverait un type qu'on venait de retirer.
    $champs['is_active'] = array_key_exists('actif', $b)
        ? (filter_var($b['actif'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
        : (int) ($dej['is_active'] ?? 1);

    $maj = $gardeCols($champs);
    if ($maj !== []) {
        $set = implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($maj)));
        Db::exec('UPDATE mar_campaign_type SET ' . $set . ' WHERE id = ?', [...array_values($maj), $id]);
    }
    journalAdd('CEO', 'Paramètre', $champs['label'], 'Type de campagne modifié');
    return ['ok' => true, 'id' => $id];
}

function wr_mkt_type_suppr(int $id): array
{
    $dej = Db::row('SELECT label FROM mar_campaign_type WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'type inconnu']; }

    // Refuser plutôt que délier ou désactiver d'office : les campagnes passées
    // portent cet identifiant, et l'effacer rendrait muet le pilotage par type,
    // les couleurs du calendrier et les briefs déjà imprimés. Le nombre part
    // avec le refus pour que l'écran propose la désactivation en connaissance
    // de cause — c'est le contrat du module, et deux comportements différents
    // pour un même geste finissent par surprendre quelqu'un.
    $n = (int) Db::row('SELECT COUNT(*) n FROM mar_campaign WHERE type_id = ?', [$id])['n'];
    if ($n > 0) {
        http_response_code(409);
        return ['error' => $n . ' campagne(s) portent ce type : désactivez-le plutôt que de le supprimer.',
            'campagnes' => $n];
    }
    Db::exec('DELETE FROM mar_campaign_type WHERE id = ?', [$id]);
    journalAdd('CEO', 'Paramètre', $dej['label'], 'Type de campagne supprimé');
    return ['ok' => true];
}

/**
 * PUT /marketing/types/ordre — l'ordre d'affichage, tel que l'écran vient de le
 * composer. La grille de cartes de l'assistant suit `sort_order` : ranger les
 * types ici, c'est ranger la première étape de la création de campagne.
 */
function wr_mkt_types_ordre(): array
{
    $b = body();
    $ids = array_values(array_filter(array_map('intval', (array) ($b['ids'] ?? []))));
    if ($ids === []) { http_response_code(422); return ['error' => 'liste d\'identifiants vide']; }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $rang = 0;
        foreach ($ids as $tid) { Db::exec('UPDATE mar_campaign_type SET sort_order = ? WHERE id = ?', [++$rang, $tid]); }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    journalAdd('CEO', 'Paramètre', '—', 'Ordre des types de campagne modifié (' . count($ids) . ' types)');
    return ['ok' => true, 'n' => count($ids)];
}

/* --- nettoyage du module marketing : une action EXPLICITE --------------------
 *
 * Le module autonome est supprimé ; seuls restent au cockpit le Calendrier,
 * les Campagnes, les Types (mar_campaign*), le Fonds & Royalties (mar_fund*,
 * mar_royalt*) et leurs référentiels (mar_brand, mar_shop, mar_lever).
 *
 * Des DROP en masse ne partent PAS tout seuls au déploiement : l'écran
 * Diagnostic montre d'abord la liste exacte de ce qui tomberait (GET), et
 * l'exécution exige une confirmation explicite (POST {confirmer:true}).
 * Chaque objet supprimé est journalisé — un DROP ne se rejoue pas, la liste
 * doit pouvoir se relire.
 */

/** Une table mar_* est-elle nécessaire à ce que le cockpit sert encore ? */
function marTableGardee(string $t): bool
{
    foreach (['mar_brand', 'mar_shop', 'mar_lever'] as $exact) {
        if ($t === $exact) { return true; }
    }
    foreach (['mar_campaign', 'mar_fund', 'mar_royalt'] as $pfx) {
        if (str_starts_with($t, $pfx)) { return true; }
    }
    return false;
}

/** GET /admin/marketing-nettoyage — ce qui serait gardé, ce qui tomberait. */
function ep_mar_nettoyage(): array
{
    $fait = setting('marNettoyage');
    try {
        $objets = Db::rows(
            "SELECT TABLE_NAME nom, TABLE_TYPE type, TABLE_ROWS lignes FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'mar\\\\_%' ORDER BY TABLE_NAME");
    } catch (PDOException $e) {
        return ['indispo' => true, 'raison' => 'information_schema inaccessible'];
    }
    $garde = []; $tombe = [];
    foreach ($objets as $o) {
        $l = ['nom' => $o['nom'], 'vue' => $o['type'] === 'VIEW',
            'lignes' => $o['type'] === 'VIEW' ? null : (int) $o['lignes']];
        if (marTableGardee($o['nom'])) { $garde[] = $l; } else { $tombe[] = $l; }
    }
    return ['garde' => $garde, 'tombe' => $tombe, 'dejaFait' => is_array($fait) ? $fait : null];
}

/** POST /admin/marketing-nettoyage — exécute, sur confirmation explicite. */
function wr_mar_nettoyage(): array
{
    $b = body();
    if (($b['confirmer'] ?? false) !== true) {
        http_response_code(422);
        return ['error' => 'confirmation requise : ces suppressions sont définitives'];
    }
    $etat = ep_mar_nettoyage();
    if (!empty($etat['indispo'])) { http_response_code(500); return ['error' => $etat['raison']]; }
    $tombees = [];
    Db::exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        // Les vues d'abord : elles dépendent des tables.
        foreach ($etat['tombe'] as $o) {
            if (!$o['vue']) { continue; }
            Db::exec('DROP VIEW IF EXISTS `' . str_replace('`', '', $o['nom']) . '`');
            $tombees[] = $o['nom'] . ' (vue)';
        }
        foreach ($etat['tombe'] as $o) {
            if ($o['vue']) { continue; }
            Db::exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $o['nom']) . '`');
            $tombees[] = $o['nom'];
        }
    } finally {
        Db::exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['marNettoyage', json_encode(['fait' => date('c'), 'supprimees' => $tombees], JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Maintenance', null, 'Nettoyage du module marketing : '
        . count($tombees) . ' objet(s) supprimé(s) — ' . implode(', ', $tombees));
    return ['ok' => true, 'supprimees' => $tombees];
}

/**
 * POST /admin/marketing-restaure — les référentiels de l'assistant de campagne.
 *
 * Le nettoyage a emporté les tables dont l'ASSISTANT du module a besoin
 * (canaux, formats, tons, cibles, mécaniques de promotion, rétroplanning,
 * catalogue d'offres). Constructif uniquement : CREATE TABLE IF NOT EXISTS et
 * seeds rejoués depuis le dépôt marketing, versés dans sql/mar-referentiels.sql
 * — puis le catalogue d'offres est REPEUPLÉ depuis la table `product` de
 * l'ERP, exactement comme la reprise du module le faisait (sku_ref = ref).
 */
function wr_mar_restaure(): array
{
    $fichier = __DIR__ . '/../sql/mar-referentiels.sql';
    if (!is_file($fichier)) { http_response_code(500); return ['error' => 'sql/mar-referentiels.sql absent']; }
    $sql = (string) file_get_contents($fichier);
    // Les commentaires se retirent AVANT le découpage sur « ; » : un
    // point-virgule dans un commentaire (« à l'import ; sinon… ») coupait un
    // CREATE en deux fragments invalides, ignorés en silence à chaque passage.
    $sql = implode("\n", array_filter(explode("\n", $sql),
        static fn ($l) => !str_starts_with(trim($l), '--')));
    $faits = 0; $ignores = 0; $tables = [];
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') { continue; }
        // Un INSERT de seed ne se rejoue pas sur une table déjà garnie : les
        // clés uniques le refuseraient, et doubler des libellés n'aide pas.
        if (preg_match('/^INSERT INTO (\w+)/i', $stmt, $m)) {
            $t = $m[1];
            if (!isset($tables[$t])) {
                try { $n = Db::row('SELECT COUNT(*) n FROM ' . $t); $tables[$t] = (int) $n['n']; }
                catch (PDOException $e) { $tables[$t] = -1; }
            }
            if ($tables[$t] !== 0) { $ignores++; continue; }
        }
        try { Db::exec($stmt); $faits++; }
        catch (PDOException $e) { $ignores++; }
    }

    // La clé unique de la migration 020 avant toute reprise : sans elle,
    // chaque reprise recréerait le catalogue au lieu de le mettre à jour.
    try {
        Db::exec('DELETE a FROM mar_offer_item a
                  JOIN mar_offer_item b ON b.sku_ref = a.sku_ref AND b.id < a.id
                  WHERE a.sku_ref IS NOT NULL');
        try { Db::exec('ALTER TABLE mar_offer_item ADD UNIQUE KEY uq_mar_offer_item_sku (sku_ref)'); }
        catch (PDOException $e) { /* clé déjà en place */ }
    } catch (PDOException $e) { /* table absente : la reprise le dira */ }

    // Le catalogue, les gammes saisonnières et leurs liens — par la reprise du
    // MODULE lui-même, portée dans le cockpit : familles produits, gammes
    // (catégorie « saison »), liaisons produit ↔ gamme, boutiques et vivier
    // B2B. C'est elle qui rend l'étape « Offre » identique à l'origine.
    $items = 0; $reprise = null;
    try {
        require_once __DIR__ . '/../public/api/marketing/src/autoload.php';
        \Marketing\Support\Database::setConnection(Db::pdo());
        \Marketing\Support\AuthContext::set(1, 'BRAND_ADMIN');
        $marque = Db::row('SELECT id FROM mar_brand ORDER BY id LIMIT 1');
        $sync = (new \Marketing\Repository\ErpSyncRepository())
            ->sync(\Marketing\Support\AuthContext::current(), (int) ($marque['id'] ?? 1));
        $bilan = static fn ($p) => isset($p['error']) ? $p
            : ['lus' => $p['read'] ?? null, 'crees' => $p['created'] ?? null, 'maj' => $p['updated'] ?? null];
        $reprise = [
            'produits' => $bilan($sync['products'] ?? []),
            'saisons'  => $bilan($sync['seasons'] ?? []),
            'liens'    => $bilan($sync['season_links'] ?? []),
            'vivier'   => $bilan($sync['prospects'] ?? []),
        ];
        // Purge d'un repeuplement simplifié antérieur (sku_ref numérique nu,
        // tout en catégorie « produit », sans famille ni gamme). APRÈS la
        // reprise, pour que chaque référence encore utilisée (objectifs par
        // produit — FK sans cascade —, lignes d'offre) soit d'abord REMAPPÉE
        // vers son équivalent « erp-<id> » : même produit, vraie fiche.
        foreach (['mar_campaign_shop_item_target', 'mar_campaign_offer_item'] as $tRef) {
            try {
                Db::exec("UPDATE $tRef r
                          JOIN mar_offer_item vieux ON vieux.id = r.offer_item_id
                               AND vieux.sku_ref REGEXP '^[0-9]+\$'
                          JOIN mar_offer_item neuf ON neuf.sku_ref = CONCAT('erp-', vieux.sku_ref)
                          SET r.offer_item_id = neuf.id");
            } catch (PDOException $e) { /* table absente ou collision : la désactivation couvrira */ }
        }
        try { Db::exec("DELETE FROM mar_offer_item WHERE sku_ref REGEXP '^[0-9]+\$'"); }
        catch (PDOException $e) { /* une référence résiste (FK) : au moins la cacher */
            try { Db::exec("UPDATE mar_offer_item SET is_active = 0 WHERE sku_ref REGEXP '^[0-9]+\$'"); }
            catch (PDOException $e2) { /* rien de plus à faire */ }
        }

        // Raffinage par l'API ERP (« on ne travaille qu'avec des API ») : dès
        // que le compte admin est configuré — ou qu'un jeton frais est déposé —
        // les gammes, leurs noms d'affichage (alias) et les liens
        // produit ↔ gamme viennent de l'API. Repli silencieux sur la reprise
        // par tables, déjà faite ci-dessus, si l'API ne répond pas.
        if (ErpApi::disponible()) {
            $gammes = ErpApi::get('/product-availability-periods');
            if (is_array($gammes)) {
                $api = ['gammes' => 0, 'alias' => 0, 'liens' => 0];
                $alias = ErpApi::get('/product-availability-periods/name-aliases');
                $nomPar = [];
                foreach (is_array($alias) ? $alias : [] as $a) {
                    if (!empty($a['alias_value']) && isset($a['fk_id'])) {
                        $nomPar[(int) $a['fk_id']] = (string) ($a['effective_value'] ?? $a['alias_value']);
                    }
                }
                $liens = [];
                foreach ($gammes as $g) {
                    if (!isset($g['id'])) { continue; }
                    $gid = (int) $g['id'];
                    $nom = $nomPar[$gid] ?? trim((string) ($g['name'] ?? ''));
                    if ($nom === '') { continue; }
                    Db::exec("INSERT INTO mar_offer_item (category, sku_ref, name, is_active)
                              VALUES ('saison', ?, ?, ?)
                              ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active)",
                        ['erp-saison-' . $gid, mb_substr($nom, 0, 200), (int) ($g['is_active'] ?? 1)]);
                    $api['gammes']++;
                    if (isset($nomPar[$gid])) { $api['alias']++; }
                    if ((int) ($g['is_active'] ?? 1) !== 1) { continue; }
                    foreach ((array) (ErpApi::get('/product-availability-periods/' . $gid . '/products') ?? []) as $p) {
                        if (isset($p['id'])) { $liens[] = [$gid, (int) $p['id']]; }
                    }
                }
                // Les liens se reconstruisent en bloc, par sku_ref : mêmes clés
                // que la reprise du module (erp-<id> / erp-saison-<id>).
                if ($liens !== []) {
                    Db::exec('DELETE FROM mar_offer_item_season');
                    $st = Db::pdo()->prepare(
                        'INSERT IGNORE INTO mar_offer_item_season (item_id, season_item_id)
                         SELECT p.id, s.id FROM mar_offer_item p JOIN mar_offer_item s
                             ON p.sku_ref = ? AND s.sku_ref = ?');
                    foreach ($liens as [$gid, $pid]) {
                        $st->execute(['erp-' . $pid, 'erp-saison-' . $gid]);
                        $api['liens'] += $st->rowCount();
                    }
                }
                $reprise['api'] = $api;
            } else {
                $reprise['api'] = 'repli tables — ' . (ErpApi::$lastError ?? 'API ERP muette');
            }
        } else {
            $reprise['api'] = 'repli tables — compte admin ERP non configuré';
        }

        $n = Db::row("SELECT COUNT(*) n FROM mar_offer_item WHERE is_active = 1");
        $items = (int) $n['n'];
    } catch (Throwable $e) {
        $reprise = ['erreur' => $e->getMessage()];
    }

    journalAdd('CEO', 'Maintenance', null, 'Référentiels de l’assistant de campagne restaurés — '
        . $faits . ' instruction(s), catalogue repris depuis l’ERP (' . $items . ' référence(s) actives)');
    return ['ok' => true, 'instructions' => $faits, 'ignorees' => $ignores,
        'offres' => $items, 'reprise' => $reprise];
}

/**
 * POST /admin/erp-token { token } — dépose un jeton d'API ERP (TFBuddy, appli
 * ADMIN) dans la table des réglages (ceo_app_setting, clé erpAdminToken).
 *
 * Le jeton ne sert QUE côté serveur : c'est le cockpit qui appelle l'ERP avec,
 * jamais le navigateur — même règle que panelApiToken et que la clé Anthropic.
 * Les jetons ADMIN expirent vite (~30 min) : en redéposer un avant une
 * campagne d'essais.
 */
function wr_erp_token(): array
{
    $t = trim((string) (body()['token'] ?? ''));
    if ($t === '' || substr_count($t, '.') !== 2) {
        http_response_code(422);
        return ['error' => 'Jeton JWT attendu : { "token": "…" }'];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['erpAdminToken', json_encode($t)]);
    $exp = null; $iss = null;
    $claims = json_decode((string) base64_decode(strtr(explode('.', $t)[1], '-_', '+/')), true);
    if (is_array($claims)) {
        $exp = isset($claims['exp']) ? date('c', (int) $claims['exp']) : null;
        $iss = $claims['iss'] ?? null;
    }
    journalAdd('CEO', 'Maintenance', null, 'Jeton d’API ERP déposé (expire ' . ($exp ?? '?') . ')');
    return ['ok' => true, 'expire' => $exp, 'emetteur' => $iss];
}

/**
 * GET /admin/erp-essai?routes=a,b&compte=admin|panel — sonde des routes de
 * l'API ERP DEPUIS LE SERVEUR. Renvoie par route le code HTTP et un extrait du
 * corps : de quoi découvrir les contrats que le swagger ne documente pas.
 * Lecture seule : uniquement des GET, uniquement vers l'hôte de l'API du panel.
 *
 * compte=panel : passe par le compte consultant (PanelApi), qui gère lui-même
 * sa connexion — utile pour les routes du réalm /consultant/…
 */
function ep_erp_essai(): array
{
    if (($_GET['compte'] ?? '') === 'panel') {
        if (!PanelApi::configured()) { return ['error' => 'Compte panel non configuré.']; }
        $demande = trim((string) ($_GET['routes'] ?? ''));
        $routes = array_filter(array_map('trim', explode(',', $demande)));
        $out = [];
        foreach (array_slice($routes, 0, 8) as $r) {
            if (!preg_match('#^[a-z0-9/_-]+(\?[a-z0-9=&_\[\]-]*)?$#i', $r) || str_contains($r, '..')) {
                $out[$r] = ['erreur' => 'chemin refusé']; continue;
            }
            PanelApi::$lastError = null;
            $res = PanelApi::get('/' . ltrim($r, '/'));
            $out[$r] = $res === null
                ? ['erreur' => PanelApi::$lastError ?? 'réponse vide']
                : ['code' => 200, 'extrait' => mb_substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 2000)];
        }
        return ['hote' => PanelApi::config()['base'], 'compte' => 'panel', 'routes' => $out];
    }

    $tok = setting('erpAdminToken', '');
    if ((!is_string($tok) || $tok === '') && !ErpApi::configured()) {
        return ['error' => 'Aucun jeton déposé ni compte admin — POST /admin/erp-token ou Paramètres.'];
    }
    // Même hôte que l'API du panel (config Paramètres / config.php / défaut).
    $demande = trim((string) ($_GET['routes'] ?? ''));
    $routes = $demande === ''
        ? ['product-availability-periods', 'product-availability-periods/name-aliases',
           'categories-availability/all-shops']
        : array_filter(array_map('trim', explode(',', $demande)));
    $out = [];
    foreach (array_slice($routes, 0, 8) as $r) {
        // Chemin borné : pas de schéma, pas d'hôte, pas de « .. » — l'appelant
        // choisit une route de l'API, jamais une autre destination.
        if (!preg_match('#^[a-z0-9/_-]+(\?[a-z0-9=&_\[\]-]*)?$#i', $r) || str_contains($r, '..')) {
            $out[$r] = ['erreur' => 'chemin refusé']; continue;
        }
        ErpApi::$lastError = null;
        $res = ErpApi::get('/' . ltrim($r, '/'));
        $out[$r] = $res === null
            ? ['erreur' => ErpApi::$lastError ?? 'réponse vide']
            : ['code' => 200, 'extrait' => mb_substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 2000)];
    }
    return ['hote' => ErpApi::config()['base'], 'compte' => 'admin', 'routes' => $out];
}

/* --- Réputation digitale : le connecteur Google ------------------------------
 *
 * La synchronisation est un GESTE, pas un effet de bord d'affichage : chaque
 * ouverture de l'écran déclencherait autant d'appels facturés que de magasins,
 * pour des notes qui bougent de quelques centièmes par semaine. L'écran lit la
 * base, le bouton « Synchroniser » va chercher chez Google, et la date de
 * dernière synchro est affichée pour qu'on sache ce qu'on regarde.
 */

/** PUT /parametres/google-cle — la clé, qui ne repart jamais vers l'écran. */
function wr_google_compte(): array
{
    $b = body();
    $cur = setting('google');
    if (!is_array($cur)) { $cur = []; }
    $cle = trim((string) ($b['cle'] ?? ''));
    // Champ laissé vide = on ne touche pas à la clé en place. Sans cette règle,
    // changer la langue effacerait la clé.
    if ($cle !== '') { $cur['cle'] = $cle; }
    if (!empty($b['effacer'])) { unset($cur['cle']); }
    $langue = trim((string) ($b['langue'] ?? ''));
    if ($langue !== '') { $cur['langue'] = mb_substr($langue, 0, 8); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['google', json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', '—', 'Connecteur Google — '
        . (!empty($b['effacer']) ? 'clé effacée' : ($cle !== '' ? 'clé enregistrée' : 'réglage mis à jour')));
    return ['ok' => true] + GoogleApi::statut();
}

/**
 * GET /reputation/recherche?q=… — les fiches Google candidates.
 *
 * Le raccordement se fait à la main, une fois par magasin : deux boulangeries
 * de la même enseigne dans la même ville ne se distinguent que par l'adresse,
 * et un rapprochement automatique sur le nom se tromperait en silence.
 */
function ep_reputation_recherche(): array
{
    if (!GoogleApi::configured()) {
        http_response_code(422);
        return ['error' => 'Clé Google absente — renseignez-la dans Paramètres.'];
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') { http_response_code(422); return ['error' => 'recherche vide']; }
    GoogleApi::$lastError = null;
    $res = GoogleApi::chercher($q);
    if ($res === null) {
        http_response_code(502);
        return ['error' => 'Google : ' . (GoogleApi::$lastError ?? 'réponse vide')];
    }
    return ['candidats' => $res];
}

/** PUT /reputation/{shopId}/fiche — raccorde un magasin à une fiche Google. */
function wr_reputation_fiche(string $shopId): array
{
    $b = body();
    // L'écran propose les magasins de la table partagée `shops`, mais les tables
    // de réputation portent une clé vers le miroir `ceo_shop` — même situation
    // que l'encodage budget : on recopie le magasin au moment du raccordement,
    // sinon tout magasin jamais budgété répondait « magasin inconnu ».
    $nomShop = magasinConnu($shopId);
    if ($nomShop === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }

    $placeId = trim((string) ($b['placeId'] ?? ''));
    if ($placeId === '') {
        // Détacher : la fiche s'en va, les avis déjà rapatriés restent. Ils
        // décrivent ce qui a été dit ; les effacer réécrirait l'histoire.
        Db::exec('DELETE FROM ceo_shop_reputation WHERE shop_id = ?', [$shopId]);
        journalAdd('CEO', 'Paramètre', $nomShop, 'Fiche Google détachée');
        return ['ok' => true, 'detache' => true];
    }

    $dejaPris = Db::row('SELECT shop_id FROM ceo_shop_reputation WHERE place_id = ? AND shop_id <> ?', [$placeId, $shopId]);
    if ($dejaPris !== null) {
        http_response_code(409);
        return ['error' => 'Cette fiche Google est déjà raccordée à un autre magasin.'];
    }

    Db::exec('INSERT INTO ceo_shop_reputation (shop_id, place_id) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE place_id = VALUES(place_id)', [$shopId, $placeId]);
    journalAdd('CEO', 'Paramètre', $nomShop, 'Fiche Google raccordée (' . $placeId . ')');
    // Synchroniser dans la foulée : raccorder sans lire laisserait une carte
    // vide, et personne ne saurait si le raccordement a pris.
    $r = reputationSynchroniser($shopId);
    return ['ok' => true] + $r;
}

/** POST /reputation/sync — va chercher chez Google, pour un magasin ou tous. */
function wr_reputation_sync(): array
{
    $b = body();
    if (!GoogleApi::configured()) {
        http_response_code(422);
        return ['error' => 'Clé Google absente — renseignez-la dans Paramètres.'];
    }
    $un = trim((string) ($b['magasinId'] ?? ''));
    return reputationSynchroniser($un !== '' ? $un : null);
}

/**
 * La synchronisation elle-même.
 *
 * Les avis sont AJOUTÉS, jamais remplacés : Google n'en rend que cinq et fait
 * tourner sa sélection. Effacer les anciens à chaque passage nous ferait perdre
 * ce qu'il a cessé de montrer — et l'écran des cinq derniers deviendrait « les
 * cinq que Google a bien voulu rendre aujourd'hui ».
 */
function reputationSynchroniser(?string $shopId): array
{
    $sql = "SELECT s.id, s.name, r.place_id FROM ceo_shop s
              JOIN ceo_shop_reputation r ON r.shop_id = s.id
             WHERE r.place_id IS NOT NULL AND r.place_id <> ''";
    $args = [];
    if ($shopId !== null) { $sql .= ' AND s.id = ?'; $args[] = $shopId; }
    $cibles = Db::rows($sql . ' ORDER BY s.name', $args);

    $faits = 0; $nouveaux = 0; $erreurs = [];
    foreach ($cibles as $c) {
        GoogleApi::$lastError = null;
        $lieu = GoogleApi::lieu((string) $c['place_id']);
        if ($lieu === null) {
            $erreurs[] = $c['name'] . ' : ' . (GoogleApi::$lastError ?? 'réponse vide');
            continue;
        }
        Db::exec('UPDATE ceo_shop_reputation SET rating_avg = ?, rating_count = ?, profile_url = ?, synced_at = ? WHERE shop_id = ?',
            [$lieu['note'], $lieu['avis'], $lieu['url'], date('Y-m-d H:i:s'), $c['id']]);
        foreach ($lieu['derniers'] as $a) {
            // INSERT IGNORE sur (source, external_id) : un avis déjà connu ne
            // se réécrit pas — son texte peut avoir été traduit d'un appel à
            // l'autre, et le premier reçu fait foi.
            $avant = Db::row("SELECT id FROM ceo_shop_review WHERE source = 'google' AND external_id = ?", [$a['externalId']]);
            if ($avant !== null) { continue; }
            Db::exec('INSERT INTO ceo_shop_review (shop_id, source, external_id, author, rating, comment, reviewed_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$c['id'], 'google', $a['externalId'], $a['auteur'], $a['note'], $a['texte'], $a['le']]);
            $nouveaux++;
        }
        $faits++;
    }

    $msg = $faits . ' magasin(s) synchronisé(s), ' . $nouveaux . ' nouvel(s) avis'
        . ($erreurs !== [] ? ' — ' . count($erreurs) . ' en échec' : '');
    // L'état du connecteur : une synchro qui n'atteint aucune fiche est un
    // échec, pas un succès à zéro — sinon « dernier succès » ment.
    connecteurNote('google', $faits > 0, $erreurs !== [] ? $msg . ' · ' . $erreurs[0] : $msg, $nouveaux);
    journalAdd('CEO', 'Réputation', $shopId !== null ? ($cibles[0]['name'] ?? '—') : '—', 'Synchronisation Google : ' . $msg);
    return ['ok' => true, 'magasins' => $faits, 'nouveaux' => $nouveaux, 'erreurs' => $erreurs,
        'aucuneFiche' => $cibles === []];
}

/**
 * Écrit le CA THÉORIQUE d'un magasin sur les exercices à venir.
 *
 * L'étude de marché ne parle pas d'une année : elle dit un potentiel à
 * maturité et la vitesse à laquelle un magasin l'atteint. L'encoder une fois
 * doit donc suffire à poser le théorique de la montée en régime — sinon il
 * faudrait revenir le saisir chaque janvier, et le suivi n'aurait aucune
 * référence pour comparer une année qui commence.
 *
 * Seul le THÉORIQUE est écrit : le budget validé se négocie avec le franchisé,
 * année après année, et ne se projette pas. Les lignes déjà posées gardent
 * donc leur revenue_budget.
 *
 * `$inclureCourant` : l'exercice en cours n'est repris que si sa grille
 * théorique est vide — une série saisie à la main garde la main.
 */
function budgetProjeter(string $shopId, int $exercice, array $em, bool $inclureCourant): array
{
    $projection = [];
    $potentiel = (float) ($em['potentielMaturite'] ?? 0);
    $sais = array_map('floatval', (array) ($em['saisonnalite'] ?? []));
    $sommeSais = array_sum($sais);
    $anEx = (int) ($em['anneeExploitation'] ?? 1);
    $ramp = (array) ($em['monteeEnRegime'] ?? []);
    if ($potentiel <= 0 || $sommeSais <= 0 || count($sais) !== 12) { return []; }
    for ($k = ($inclureCourant ? 0 : 1); $k <= 3; $k++) {
        $an = min(4, $anEx + $k);
        $coef = $an === 1 ? (float) ($ramp['a1'] ?? 70)
            : ($an === 2 ? (float) ($ramp['a2'] ?? 80)
            : ($an === 3 ? (float) ($ramp['a3'] ?? 90) : 100.0));
        $caAn = $potentiel * $coef / 100;
        $exo = $exercice + $k;
        // L'exercice courant a déjà sa ligne : on n'y touche que le total
        // théorique, pour ne pas effacer la date de validation ni l'engagement
        // panier.
        if ($k === 0) {
            Db::exec('UPDATE ceo_shop_budget SET ca_theorique_an = ? WHERE shop_id = ? AND fiscal_year = ?',
                [$caAn, $shopId, $exo]);
        } else {
            Db::exec('INSERT INTO ceo_shop_budget (shop_id, fiscal_year, ca_theorique_an, etude_date, etude_source,
                        etude_potentiel_menages, etude_potentiel_maturite, annee_exploitation, montee_regime, saisonnalite)
                      VALUES (?,?,?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE ca_theorique_an = VALUES(ca_theorique_an),
                        etude_date = VALUES(etude_date), etude_source = VALUES(etude_source),
                        etude_potentiel_menages = VALUES(etude_potentiel_menages),
                        etude_potentiel_maturite = VALUES(etude_potentiel_maturite),
                        annee_exploitation = VALUES(annee_exploitation), montee_regime = VALUES(montee_regime),
                        saisonnalite = VALUES(saisonnalite)', [
                $shopId, $exo, $caAn, $em['date'] ?? null, $em['source'] ?? null,
                $em['potentielMenages'] ?? null, $potentiel, $an,
                isset($em['monteeEnRegime']) ? json_encode($em['monteeEnRegime']) : null,
                json_encode($sais),
            ]);
        }
        for ($m = 1; $m <= 12; $m++) {
            Db::exec('INSERT INTO ceo_shop_month_perf (shop_id, year, month, ca_theorique) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE ca_theorique = VALUES(ca_theorique)',
                [$shopId, $exo, $m, round($caAn * $sais[$m - 1] / $sommeSais, 2)]);
        }
        $projection[(string) $exo] = ['annee' => $an, 'coef' => $coef, 'ca' => round($caAn, 2)];
    }
    return $projection;
}

/**
 * PUT /stores/{id}/saisonnalite — poser la variation par mois d'un magasin.
 *
 * Sert à propager une courbe d'un magasin aux autres : elle n'écrit QUE la
 * saisonnalité, jamais le budget ni l'étude, puis recalcule le théorique du
 * magasin s'il a un potentiel — sans quoi la courbe changerait à l'écran sans
 * rien changer aux chiffres.
 */
function wr_shop_saisonnalite(string $shopId): array
{
    $nom = magasinConnu($shopId);
    if ($nom === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    $b = body();
    $sais = array_map('floatval', (array) ($b['saisonnalite'] ?? []));
    if (count($sais) !== 12 || array_sum($sais) <= 0) {
        http_response_code(422);
        return ['error' => 'douze valeurs attendues, de somme non nulle'];
    }
    // L'étude du magasin, relue AVANT d'écrire : on ne pose que la courbe, tout
    // le reste (potentiel, montée en régime, année) reste le sien.
    $r = Db::row('SELECT etude_potentiel_maturite p, annee_exploitation a, montee_regime m, etude_date d, etude_source s,
                         etude_potentiel_menages me
                  FROM ceo_shop_budget WHERE shop_id = ? AND fiscal_year = ?', [$shopId, $exercice]);
    Db::exec('INSERT INTO ceo_shop_budget (shop_id, fiscal_year, saisonnalite) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE saisonnalite = VALUES(saisonnalite)',
        [$shopId, $exercice, json_encode($sais)]);
    $em = [
        'potentielMaturite' => (float) ($r['p'] ?? 0),
        'anneeExploitation' => (int) ($r['a'] ?? 1),
        'monteeEnRegime' => json_decode((string) ($r['m'] ?? ''), true) ?: [],
        'saisonnalite' => $sais,
        'date' => $r['d'] ?? null, 'source' => $r['s'] ?? null, 'potentielMenages' => $r['me'] ?? null,
    ];
    $theoDejaSaisi = (int) (Db::row('SELECT COUNT(*) n FROM ceo_shop_month_perf
                                     WHERE shop_id = ? AND year = ? AND ca_theorique IS NOT NULL AND ca_theorique > 0',
        [$shopId, $exercice])['n'] ?? 0) > 0;
    $projection = budgetProjeter($shopId, $exercice, $em, true);
    journalAdd('CEO', 'Budget', $nom, 'Variation par mois posée pour ' . $exercice
        . ($projection !== [] ? ' — théorique recalculé : ' . implode(', ', array_keys($projection)) : ''));
    return ['ok' => true, 'projection' => $projection, 'theoriqueExistait' => $theoDejaSaisi];
}

/**
 * PUT /marketing/campagnes/{id}/objectifs — l'objectif de CA par magasin.
 *
 * Une case vidée EFFACE la ligne : « pas d'objectif » n'est pas « objectif à
 * zéro », et le tableau doit pouvoir dire la différence.
 */
function wr_campagne_objectifs(int $id): array
{
    ensureCampagneObjectifs();
    try {
        $camp = Db::row('SELECT id, name FROM mar_campaign WHERE id = ?', [$id]);
    } catch (PDOException $e) {
        http_response_code(503);
        return ['error' => 'les tables du module marketing sont absentes de cette base'];
    }
    if ($camp === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $b = body();
    $ecrits = 0; $effaces = 0;
    foreach ((array) ($b['objectifs'] ?? []) as $shopId => $montant) {
        $sid = (string) $shopId;
        if ($sid === '' || magasinConnu($sid) === null) { continue; }
        if ($montant === '' || $montant === null || (float) $montant <= 0) {
            Db::exec('DELETE FROM ceo_campagne_objectif WHERE campagne_id = ? AND shop_id = ?', [$id, $sid]);
            $effaces++;
            continue;
        }
        Db::exec('INSERT INTO ceo_campagne_objectif (campagne_id, shop_id, objectif, maj) VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE objectif = VALUES(objectif), maj = VALUES(maj)',
            [$id, $sid, (float) $montant, date('Y-m-d H:i:s')]);
        $ecrits++;
    }
    // `journal=0` : l'écran écrit à chaque saisie, comme le budget. Le journal
    // ne garde que les enregistrements explicites.
    if (($_GET['journal'] ?? '') !== '0') {
        journalAdd('CEO', 'Campagne', (string) $camp['name'], 'Objectifs par magasin — '
            . $ecrits . ' posé(s)' . ($effaces ? ', ' . $effaces . ' effacé(s)' : ''));
    }
    return ['ok' => true, 'ecrits' => $ecrits, 'effaces' => $effaces];
}

/**
 * POST /ecrans/vue — une ouverture d'écran.
 *
 * Compté, jamais journalisé : ouvrir un écran n'est pas une action, et le
 * journal deviendrait illisible. L'écriture est agrégée par jour et par
 * personne — un compteur, pas un historique de navigation.
 */
function wr_ecran_vue(): array
{
    ensureEcranVues();
    $b = body();
    $ecran = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($b['ecran'] ?? ''));
    if ($ecran === '') { http_response_code(422); return ['error' => 'écran attendu']; }
    $acteur = mb_substr(trim((string) ($b['qui'] ?? '')), 0, 80);
    Db::exec('INSERT INTO ceo_ecran_vue (ecran, jour, acteur, n) VALUES (?,?,?,1)
              ON DUPLICATE KEY UPDATE n = n + 1', [$ecran, date('Y-m-d'), $acteur]);
    return ['ok' => true];
}

/**
 * DELETE /projects/{projet}/tasks/{tache} — supprimer une tâche.
 *
 * Supprimer efface aussi ses SIGNALEMENTS : un signalement orphelin resterait
 * dans le suivi, rattaché à une tâche qui n'existe plus, et rien ne permettrait
 * de le clore. Le journal garde la trace — c'est lui la mémoire, pas la ligne
 * effacée.
 */
function wr_task_delete(string $projectId, string $taskId): array
{
    $t = Db::row('SELECT t.name, t.done_on, t.note, t.panel_note, p.name AS pname
                    FROM ceo_project_task t JOIN ceo_project p ON p.id = t.project_id
                   WHERE t.id = ? AND t.project_id = ?', [$taskId, $projectId]);
    if ($t === null) { http_response_code(404); return ['error' => 'tâche inconnue']; }

    // La note déposée dans le panel part avec la tâche : la laisser derrière
    // laisserait au consultant une consigne que plus personne ne suit ici.
    $panel = null;
    $nid = isset($t['panel_note']) && $t['panel_note'] !== null ? (int) $t['panel_note'] : 0;
    if ($nid > 0 && PanelApi::configured()) {
        [$ok] = PanelApi::envoi('DELETE', '/consultant/notes/' . $nid, []);
        $panel = $ok ? ('note #' . $nid . ' retirée du panel')
                     : ('note #' . $nid . ' non retirée du panel — ' . (PanelApi::$lastError ?? 'refus'));
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $sig = 0;
        try {
            $sig = count(Db::rows('SELECT id FROM ceo_task_issue WHERE task_id = ?', [$taskId]));
            Db::exec('DELETE FROM ceo_task_issue WHERE task_id = ?', [$taskId]);
        } catch (PDOException $e) { /* table absente : rien à détacher */ }
        Db::exec('DELETE FROM ceo_project_task WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
        $pdo->commit();
        journalAdd('CEO', 'Tâche', (string) $t['pname'], 'Tâche « ' . $t['name'] . ' » supprimée'
            . ($t['note'] !== null ? ' (elle était validée ' . (int) $t['note'] . '/5)' : '')
            . ($sig > 0 ? ' — ' . $sig . ' signalement(s) fermé(s) avec elle' : '')
            . ($panel !== null ? ' — ' . $panel : ''));
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'signalements' => $sig, 'panel' => $panel];
}

/* --- Scouting commercial ----------------------------------------------------- */

/** PUT /scouting/tiles/{i} — dépose un secteur du cache OpenStreetMap (partagé entre navigateurs). */
function wr_scouting_tile_put(int $sector): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 12 * 1024 * 1024) { http_response_code(413); return ['error' => 'secteur trop volumineux']; }
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['c'], $d['b']) || !is_array($d['c']) || !is_array($d['b'])) {
        http_response_code(400); return ['error' => 'secteur attendu : { t, c, b, p }'];
    }
    $ts = (int) round(((float) ($d['t'] ?? 0)) / 1000);
    Db::exec('INSERT INTO ceo_scouting_tile (sector, fetched_at, payload) VALUES (?,?,?)'
        . ' ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), payload = VALUES(payload)',
        [$sector, date('Y-m-d H:i:s', $ts > 0 ? $ts : time()), $raw]);
    return ['ok' => true, 'communes' => count($d['c']), 'commerces' => count($d['b'])];
}

/** PUT /scouting/competitors — note, avis, source et commentaire terrain par commerce (lot ≤ 500).
 *  Seules les clés présentes dans une ligne sont modifiées ; une ligne sans note,
 *  sans commentaire et hors interrogation Google disparaît. */
function wr_scouting_competitors_put(): array
{
    $b = body();
    $rows = $b['rows'] ?? [];
    if (!is_array($rows) || $rows === []) { http_response_code(400); return ['error' => 'rows attendu']; }
    $n = 0; $rated = 0;
    $id = ''; $name = ''; $commune = ''; $rating = null; $comment = null;
    foreach (array_slice(array_values($rows), 0, 500) as $r) {
        if (!is_array($r)) { continue; }
        $id = trim((string) ($r['id'] ?? ''));
        if (!preg_match('/^[nwr]\d{1,15}$/', $id)) { continue; }
        $cur = Db::row('SELECT * FROM ceo_scouting_competitor WHERE osm_id = ?', [$id]);
        $rating  = array_key_exists('rating', $r)  ? ($r['rating'] === null ? null : max(0, min(5, round((float) $r['rating'], 1)))) : ($cur['rating'] ?? null);
        $reviews = array_key_exists('reviews', $r) ? ($r['reviews'] === null ? null : max(0, (int) $r['reviews'])) : ($cur['reviews'] ?? null);
        $source  = array_key_exists('source', $r)  ? (in_array($r['source'], ['google', 'manuel'], true) ? $r['source'] : null) : ($cur['rating_source'] ?? null);
        $comment = array_key_exists('comment', $r) ? ($r['comment'] === null || $r['comment'] === '' ? null : mb_substr((string) $r['comment'], 0, 200)) : ($cur['comment'] ?? null);
        $name    = ($r['name'] ?? '') !== ''    ? mb_substr((string) $r['name'], 0, 200)    : ($cur['name'] ?? '');
        $commune = ($r['commune'] ?? '') !== '' ? mb_substr((string) $r['commune'], 0, 120) : ($cur['commune'] ?? '');
        $arr     = ($r['arr'] ?? '') !== ''     ? mb_substr((string) $r['arr'], 0, 60)      : ($cur['arrondissement'] ?? '');
        if ($rating === null && $comment === null && $source !== 'google') {
            Db::exec('DELETE FROM ceo_scouting_competitor WHERE osm_id = ?', [$id]);
        } else {
            Db::exec('INSERT INTO ceo_scouting_competitor (osm_id, name, commune, arrondissement, rating, reviews, rating_source, comment, updated_at) VALUES (?,?,?,?,?,?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE name = VALUES(name), commune = VALUES(commune), arrondissement = VALUES(arrondissement),'
                . ' rating = VALUES(rating), reviews = VALUES(reviews), rating_source = VALUES(rating_source), comment = VALUES(comment), updated_at = VALUES(updated_at)',
                [$id, $name, $commune, $arr, $rating, $reviews, $source, $comment, date('Y-m-d H:i:s')]);
        }
        $n++;
        if ($rating !== null) { $rated++; }
    }
    if ($n === 0) { http_response_code(400); return ['error' => 'aucune ligne valide (id OSM attendu : n123, w456, r789)']; }
    $who = ($name !== '' ? $name : $id) . ($commune !== '' ? ' (' . $commune . ')' : '');
    $first = array_values($rows)[0];
    if (!is_array($first)) { $first = []; }
    if (count($rows) === 1 && array_key_exists('rating', $first)) {
        journalAdd('CEO', 'Scouting', $name !== '' ? $name : $id,
            ($rating === null ? 'Note terrain retirée' : 'Note terrain ' . str_replace('.', ',', (string) $rating) . '/5 saisie') . ' — ' . $who);
    } elseif (count($rows) === 1 && array_key_exists('comment', $first)) {
        journalAdd('CEO', 'Scouting', $name !== '' ? $name : $id,
            ($comment === null ? 'Commentaire terrain retiré' : 'Commentaire terrain : « ' . $comment . ' »') . ' — ' . $who);
    } else {
        journalAdd('CEO', 'Scouting', 'Notes Google', 'Enrichissement Google Places — ' . $n . ' commerces traités, ' . $rated . ' notés');
    }
    return ['ok' => true, 'n' => $n];
}

/**
 * POST /scouting/notes — les notes Google d'un lot de commerces (≤ 40).
 *
 * La clé est celle du connecteur Google de Paramètres, la même que pour la
 * réputation des magasins : elle ne quitte pas le serveur, et l'écran n'a plus
 * rien à saisir. Chaque commerce est cherché par son nom près de sa position
 * OSM. Une clé refusée ou un quota épuisé arrête le lot : ce qui a été trouvé
 * avant est gardé et renvoyé, et l'erreur est dite telle que Google la formule.
 * Une note terrain (« manuel ») n'est jamais écrasée par Google.
 */
function wr_scouting_notes(): array
{
    if (!GoogleApi::configured()) {
        http_response_code(422);
        return ['error' => 'Clé Google absente — renseignez-la dans Paramètres.'];
    }
    $b = body();
    $rows = $b['rows'] ?? [];
    if (!is_array($rows) || $rows === []) { http_response_code(400); return ['error' => 'rows attendu']; }
    $out = []; $rated = 0; $erreur = null;
    foreach (array_slice(array_values($rows), 0, 40) as $r) {
        if (!is_array($r)) { continue; }
        $id = trim((string) ($r['id'] ?? ''));
        if (!preg_match('/^[nwr]\d{1,15}$/', $id)) { continue; }
        $name    = mb_substr(trim((string) ($r['name'] ?? '')), 0, 200);
        $commune = mb_substr(trim((string) ($r['commune'] ?? '')), 0, 120);
        $arr     = mb_substr(trim((string) ($r['arr'] ?? '')), 0, 60);
        $lat = (float) ($r['lat'] ?? 0); $lng = (float) ($r['lng'] ?? 0);
        if ($name === '' || $lat === 0.0 || $lng === 0.0) { continue; }
        $ou = trim((string) ($r['addr'] ?? ''));
        GoogleApi::$lastError = null;
        $res = GoogleApi::noteProche($name . ' ' . ($ou !== '' ? $ou : $commune) . ' Belgique', $lat, $lng);
        if ($res === null) {
            $msg = GoogleApi::$lastError ?? 'réponse vide';
            // Clé refusée, quota épuisé, facturation absente, réseau : les
            // commerces suivants échoueraient pareil — on s'arrête et on le dit.
            if (preg_match('/HTTP (0|400|401|403|429|5\d\d)\b|PERMISSION_DENIED|quota|billing|API key|appel impossible/i', $msg)) {
                $erreur = 'Google Places : ' . $msg;
                break;
            }
            $res = ['note' => null, 'avis' => 0];   // fiche introuvable : retenu, pour ne pas redemander
        }
        Db::exec('INSERT INTO ceo_scouting_competitor (osm_id, name, commune, arrondissement, rating, reviews, rating_source, comment, updated_at) VALUES (?,?,?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), commune = VALUES(commune), arrondissement = VALUES(arrondissement),'
            . " rating = IF(rating_source = 'manuel', rating, VALUES(rating)),"
            . " reviews = IF(rating_source = 'manuel', reviews, VALUES(reviews)),"
            . " rating_source = IF(rating_source = 'manuel', rating_source, VALUES(rating_source)),"
            . ' updated_at = VALUES(updated_at)',
            [$id, $name, $commune, $arr, $res['note'], $res['avis'], 'google', null, date('Y-m-d H:i:s')]);
        $out[] = ['id' => $id, 'rating' => $res['note'], 'reviews' => $res['avis']];
        if ($res['note'] !== null) { $rated++; }
    }
    if ($out === [] && $erreur === null) {
        http_response_code(400);
        return ['error' => 'aucune ligne valide (id OSM, nom, lat, lng attendus)'];
    }
    if ($out !== []) {
        journalAdd('CEO', 'Scouting', 'Notes Google', 'Enrichissement Google Places — ' . count($out) . ' commerces traités, '
            . $rated . ' notés' . ($erreur !== null ? ' — interrompu : ' . $erreur : ''));
    }
    if ($out === [] && $erreur !== null) { http_response_code(502); return ['error' => $erreur, 'rows' => []]; }
    return ['ok' => true, 'rows' => $out, 'n' => count($out), 'notes' => $rated, 'erreur' => $erreur];
}

/** POST /scouting/candidates — zone candidate retenue depuis sa fiche. */
function wr_scouting_candidate_post(): array
{
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) { $id = (int) round(microtime(true) * 1000); }
    $name = mb_substr((string) ($b['name'] ?? ''), 0, 200);
    $commune = mb_substr((string) ($b['commune'] ?? ''), 0, 120);
    Db::exec('INSERT INTO ceo_scouting_candidate (id, name, commune, arrondissement, province, lat, lng, households, market, emprise, revenue, score, shops, strong, revenue_m2, created_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [
        $id, $name, $commune, mb_substr((string) ($b['arr'] ?? ''), 0, 60), mb_substr((string) ($b['prov'] ?? ''), 0, 60),
        (float) ($b['lat'] ?? 0), (float) ($b['lng'] ?? 0),
        max(0, (int) round((float) ($b['hh'] ?? 0))), max(0, (int) round((float) ($b['market'] ?? 0))),
        max(0.0, min(1.0, (float) ($b['emprise'] ?? 0))), max(0, (int) round((float) ($b['ca'] ?? 0))),
        max(0, min(100, (int) ($b['score'] ?? 0))), max(0, (int) ($b['n'] ?? 0)), max(0, (int) ($b['strong'] ?? 0)),
        max(0, (int) round((float) ($b['m2'] ?? 0))), date('Y-m-d H:i:s'),
    ]);
    journalAdd('CEO', 'Scouting', $commune !== '' ? $commune : '—',
        'Zone candidate retenue — ' . $name . ' · CA estimé ' . number_format((float) ($b['ca'] ?? 0), 0, ',', '.') . ' € · score ' . (int) ($b['score'] ?? 0) . '/100');
    return ['ok' => true, 'id' => $id];
}

/** DELETE /scouting/candidates/{id} */
function wr_scouting_candidate_delete(int $id): array
{
    $c = Db::row('SELECT name, commune FROM ceo_scouting_candidate WHERE id = ?', [$id]);
    if ($c === null) { http_response_code(404); return ['error' => 'zone inconnue']; }
    Db::exec('DELETE FROM ceo_scouting_candidate WHERE id = ?', [$id]);
    journalAdd('CEO', 'Scouting', $c['commune'], 'Zone candidate retirée — ' . $c['name']);
    return ['ok' => true];
}

/** PUT /scouting/populations — import CSV StatBel : { "populations": { "NIS": population } }. */
function wr_scouting_populations_put(): array
{
    $b = body();
    $pops = $b['populations'] ?? [];
    if (!is_array($pops) || $pops === []) { http_response_code(400); return ['error' => 'populations attendu : { "NIS": population }']; }
    $n = 0;
    foreach ($pops as $ins => $pop) {
        $ins = (string) $ins; $pop = (int) $pop;
        if (!preg_match('/^\d{5}$/', $ins) || $pop <= 0) { continue; }
        Db::exec('INSERT INTO ceo_scouting_population (ins, population, imported_at) VALUES (?,?,?)'
            . ' ON DUPLICATE KEY UPDATE population = VALUES(population), imported_at = VALUES(imported_at)',
            [$ins, $pop, date('Y-m-d H:i:s')]);
        $n++;
    }
    $fichier = isset($b['fichier']) ? ' (' . mb_substr((string) $b['fichier'], 0, 80) . ')' : '';
    journalAdd('CEO', 'Scouting', 'Population', 'Import StatBel — ' . $n . ' communes mises à jour' . $fichier);
    return ['ok' => true, 'n' => $n];
}
