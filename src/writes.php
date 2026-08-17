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
    Db::exec('INSERT INTO ceo_project (id, name, famille, status, priority, axe, axes_json, kpis_json, value_txt, starts_on, ends_on, budget, value_est, value_real) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)', [
        $id, (string) $b['nom'], (string) ($b['famille'] ?? 'Organisation & coûts'), (string) ($b['statut'] ?? 'À lancer'),
        (string) ($b['prio'] ?? 'Moyenne'), (string) ($b['axes'][0] ?? ''), json_encode($b['axes'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($b['kpis'] ?? [], JSON_UNESCAPED_UNICODE), (string) ($b['valeurTxt'] ?? ''),
        $b['debut'] ?? null, $b['fin'] ?? null, $b['budget'] ?? null, $b['valeurEst'] ?? null,
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
    return ['ok' => true, 'testOk' => $ok, 'message' => $msg, 'statut' => PanelApi::statut()];
}

/** POST /pwa/compte/test — vérifie la connexion sans rien modifier. */
function wr_pwa_compte_test(): array
{
    [$ok, $msg] = PanelApi::tester();
    return ['ok' => $ok, 'message' => $msg, 'statut' => PanelApi::statut()];
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
        Db::exec('INSERT INTO ceo_shop_budget_month VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE revenue_budget = VALUES(revenue_budget)', [$shopId, $exercice, $m, (float) $ca]);
        Db::exec('INSERT INTO ceo_shop_month_perf (shop_id, year, month, revenue_budget, ca_theorique) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE revenue_budget = VALUES(revenue_budget), ca_theorique = VALUES(ca_theorique)',
            [$shopId, $exercice, $m, (float) $ca, isset($caTheo[$i]) ? (float) $caTheo[$i] : null]);
    }
    if (isset($b['charges'])) {
        Db::exec('DELETE FROM ceo_shop_budget_line WHERE shop_id = ? AND fiscal_year = ?', [$shopId, $exercice]);
        $slugToTag = [];
        foreach (LEVIER_DEFS as $l) { $slugToTag[$l['slug']] = $l['tag']; }
        foreach (array_values($b['charges']) as $i => $c) {
            Db::exec('INSERT INTO ceo_shop_budget_line (shop_id, fiscal_year, label, levid, pct_budget, pct_theorique, real_field, sort_order) VALUES (?,?,?,?,?,?,?,?)', [
                $shopId, $exercice, (string) $c['poste'], $slugToTag[$c['levier'] ?? ''] ?? null,
                (float) ($c['pctBudget'] ?? 0), isset($c['pctTheorique']) ? (float) $c['pctTheorique'] : null,
                $c['champReel'] ?? null, $i,
            ]);
        }
    }
    journalAdd('CEO', 'Budget', $shop['name'], $b['journal'] ?? ("Budget $exercice encodé"));
    return ['ok' => true];
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
        Db::exec('UPDATE pla_meuble SET type = ?, temperature = ?, presentation = ? WHERE id = ?',
            [$court($b['type'] ?? ''), $court($b['temperature'] ?? ''), $court($b['presentation'] ?? ''), $id]);

        // Le meuble peut naître AVEC ses niveaux et leurs emplacements : le
        // déclarer en trois écrans successifs faisait abandonner à mi-chemin,
        // et un meuble sans emplacement ne sert à rien.
        $faits = 0;
        foreach (is_array($b['niveaux'] ?? null) ? $b['niveaux'] : [] as $i => $n) {
            if (!is_array($n)) { continue; }
            $nn = mb_substr(trim((string) ($n['nom'] ?? '')), 0, 80);
            if ($nn === '') { $nn = 'Niveau ' . ($i + 1); }
            Db::exec('INSERT INTO pla_niveau (meuble_id, nom, rang) VALUES (?,?,?)', [$id, $nn, $i + 1]);
            $nid = (int) Db::pdo()->lastInsertId();
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
        if ($faits > 0) { return ['ok' => true, 'id' => $id, 'nom' => $nom, 'slots' => $faits]; }
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
    return ['ok' => true, 'id' => $id];
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
    if ($slots) {
        $in = implode(',', array_fill(0, count($slots), '?'));
        $r = Db::row('SELECT COUNT(*) AS n FROM pla_placement WHERE slot_id IN (' . $in . ')', $slots);
        $n = (int) ($r['n'] ?? 0);
        if ($n > 0 && empty(body()['force'])) {
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
    $s = Db::row('SELECT s.id, s.position, s.capacite, n.nom AS niveau, m.nom AS meuble, z.nom AS zone
                    FROM pla_slot s
                    JOIN pla_niveau n ON n.id = s.niveau_id
                    JOIN pla_meuble m ON m.id = n.meuble_id
                    JOIN pla_zone   z ON z.id = m.zone_id
                   WHERE s.id = ?', [$sid]);
    if ($s === null) { http_response_code(404); return ['error' => 'emplacement inconnu']; }

    $occ = Db::rows('SELECT ref FROM pla_placement WHERE slot_id = ? AND ref <> ?', [$sid, $ref]);
    if ($occ && empty($b['partager'])) {
        http_response_code(409);
        return ['error' => 'emplacement déjà occupé',
            'occupants' => array_map(fn ($o) => (string) $o['ref'], $occ)];
    }

    $fronts = max(1, min(40, (int) ($b['fronts'] ?? 1)));
    $ordre  = max(1, min(40, (int) ($b['ordre'] ?? 1)));
    Db::exec('INSERT INTO pla_placement (ref, zone, meuble, niveau, slot, slot_id, fronts, ordre)'
        . ' VALUES (?,?,?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE zone = VALUES(zone), meuble = VALUES(meuble), niveau = VALUES(niveau),'
        . ' slot = VALUES(slot), slot_id = VALUES(slot_id), fronts = VALUES(fronts), ordre = VALUES(ordre)',
        [$ref, (string) $s['zone'], (string) $s['meuble'], (string) $s['niveau'],
         (int) $s['position'], $sid, $fronts, $ordre]);

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
        . ' · position ' . $s['position'] . ' » (' . $fronts . ' front(s))');
    return ['ok' => true, 'ref' => $ref, 'slotId' => $sid,
        'ou' => $s['zone'] . ' · ' . $s['meuble'] . ' · ' . $s['niveau'] . ' · ' . $s['position']];
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
    if (!in_array($cible, ['ref', 'zone', 'meuble'], true)) {
        http_response_code(422); return ['error' => 'cible inconnue (ref, zone ou meuble)'];
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
    if (!in_array($cible, ['ref', 'zone', 'meuble'], true)) {
        http_response_code(422); return ['error' => 'cible inconnue (ref, zone ou meuble)'];
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
    journalAdd('Paramètres', 'Assistance IA — ' . ($cle !== '' ? 'clé enregistrée' : 'réglage mis à jour'));
    return ['ok' => true] + Anthropic::statut();
}
