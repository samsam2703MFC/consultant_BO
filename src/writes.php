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
            Db::exec('UPDATE ceo_project_task SET note = ?, validated_by = ? WHERE id = ?', [$note, $note === null ? null : $par, $taskId]);
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

/** PUT /stores/{id}/budget — écran « Encodage du budget ». */
function wr_budget_put(string $shopId): array
{
    $b = body();
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    $shop = Db::row('SELECT name FROM ceo_shop WHERE id = ?', [$shopId]);
    if ($shop === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
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
