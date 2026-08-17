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

/* --- Contrôle des posts Facebook -------------------------------------------
 *
 * Le cycle : soumission → contrôle par l'agent → décision du CEO → publication.
 * Aucune étape n'est implicite. En particulier, l'agent ne valide ni ne refuse :
 * il note et liste les écarts, un humain tranche et signe (`decided_by`).
 */

/** Le post tel que l'agent le lit — une seule mise en forme, partagée. */
function fbPostPourAgent(array $row): array
{
    return [
        'id' => $row['id'], 'shopId' => $row['shop_id'], 'shopNom' => $row['shop_name'] ?? null,
        'auteur' => $row['author'], 'format' => $row['format'],
        'message' => $row['message'], 'lien' => $row['link'],
        'medias' => $row['medias_json'] !== null ? (json_decode($row['medias_json'], true) ?: []) : [],
        'publierLe' => $row['planned_at'],
    ];
}

function fbPostRow(string $id): ?array
{
    return Db::row('SELECT p.*, s.name AS shop_name FROM ceo_fb_post p LEFT JOIN ceo_shop s ON s.id = p.shop_id WHERE p.id = ?', [$id]);
}

/**
 * Passe l'agent sur un post et enregistre le résultat.
 *
 * Les écarts sont réécrits : ils décrivent le texte courant. Les codes de règle
 * qu'un humain avait écartés (`ignore`) sont repris tels quels — une dérogation
 * accordée ne se rejoue pas à chaque contrôle, et elle ne pèse pas sur la note.
 */
function fbEnregistrerControle(array $row): array
{
    $ignores = array_map(fn ($r) => (string) $r['rule_code'],
        Db::rows("SELECT rule_code FROM ceo_fb_finding WHERE post_id = ? AND status = 'ignore'", [$row['id']]));
    $res = fbControler(fbPostPourAgent($row), $ignores);

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        Db::exec('DELETE FROM ceo_fb_finding WHERE post_id = ?', [$row['id']]);
        foreach ($res['ecarts'] as $e) {
            Db::exec('INSERT INTO ceo_fb_finding (post_id, rule_code, rule_name, famille, type, gravite, message, extrait, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$row['id'], $e['code'], $e['regle'], $e['famille'], $e['type'], $e['gravite'], $e['message'], $e['extrait'], $e['statut'], date('Y-m-d H:i:s')]);
        }
        Db::exec("UPDATE ceo_fb_post SET agent_note = ?, agent_summary = ?, agent_ran_at = ?, agent_runs = agent_runs + 1,
                    status = CASE WHEN status = 'brouillon' THEN status ELSE 'a_valider' END WHERE id = ?",
            [$res['note'], $res['resume'], date('Y-m-d H:i:s'), $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    journalAdd('Agent de contrôle', 'Contrôle', $row['shop_name'] ?? '—',
        'Post « ' . fbTitre($row['message']) . ' » contrôlé — ' . $res['note'] . '/5 · ' . $res['resume']);
    return $res;
}

/** Les premiers mots d'un post, pour le journal et les toasts. */
function fbTitre(string $message): string
{
    $m = trim((string) preg_replace('/\s+/u', ' ', $message));
    return mb_strlen($m, 'UTF-8') > 60 ? mb_substr($m, 0, 60, 'UTF-8') . '…' : $m;
}

/** POST /facebook/posts — soumission d'un post par un magasin. */
function wr_fb_post_create(): array
{
    $b = body();
    $message = trim((string) ($b['message'] ?? ''));
    if ($message === '') { http_response_code(422); return ['error' => 'message vide']; }
    $shopId = ($b['magasinId'] ?? '') !== '' ? (string) $b['magasinId'] : null;
    if ($shopId !== null && Db::row('SELECT id FROM ceo_shop WHERE id = ?', [$shopId]) === null) {
        http_response_code(422); return ['error' => 'magasin inconnu'];
    }
    // Un brouillon reste au magasin : l'agent ne relit que ce qui est soumis.
    $statut = ($b['statut'] ?? 'a_controler') === 'brouillon' ? 'brouillon' : 'a_controler';
    $id = $b['id'] ?? ('fb' . substr((string) round(microtime(true) * 1000), -8));
    Db::exec('INSERT INTO ceo_fb_post (id, shop_id, author, format, message, link, medias_json, planned_at, submitted_at, status) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $id, $shopId, (string) ($b['auteur'] ?? 'Franchisé'), (string) ($b['format'] ?? 'Photo'),
        $message, ($b['lien'] ?? '') !== '' ? (string) $b['lien'] : null,
        isset($b['medias']) ? json_encode($b['medias'], JSON_UNESCAPED_UNICODE) : null,
        $b['publierLe'] ?? null, date('Y-m-d H:i:s'), $statut,
    ]);
    $row = fbPostRow($id);
    journalAdd((string) ($b['auteur'] ?? 'Franchisé'), 'Post Facebook', $row['shop_name'] ?? '—',
        'Post « ' . fbTitre($message) . ' » ' . ($statut === 'brouillon' ? 'enregistré en brouillon' : 'soumis au contrôle'));
    $res = $statut === 'brouillon' ? null : fbEnregistrerControle($row);
    return ['ok' => true, 'id' => $id, 'controle' => $res];
}

/** POST /facebook/posts/{id}/controle — (re)passer l'agent, après correction. */
function wr_fb_controle(string $id): array
{
    $row = fbPostRow($id);
    if ($row === null) { http_response_code(404); return ['error' => 'post inconnu']; }
    if ($row['status'] === 'publie') { http_response_code(409); return ['error' => 'post déjà publié']; }
    return ['ok' => true, 'controle' => fbEnregistrerControle($row)];
}

/**
 * PATCH /facebook/posts/{id} — la décision du CEO, ou la publication.
 *
 * Deux corps possibles, jamais mélangés :
 *  - { note, decision: valide|refuse, famille, type, commentaire, par }
 *  - { statut: 'publie', fbId? } — uniquement depuis un post validé.
 */
function wr_fb_decision(string $id): array
{
    $b = body();
    $row = fbPostRow($id);
    if ($row === null) { http_response_code(404); return ['error' => 'post inconnu']; }
    $mag = $row['shop_name'] ?? '—';
    $titre = fbTitre($row['message']);

    if (($b['statut'] ?? '') === 'publie') {
        if ($row['status'] !== 'valide') {
            http_response_code(409);
            return ['error' => 'seul un post validé peut être marqué publié'];
        }
        Db::exec("UPDATE ceo_fb_post SET status = 'publie', published_at = ?, fb_post_id = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), ($b['fbId'] ?? '') !== '' ? (string) $b['fbId'] : null, $id]);
        journalAdd((string) ($b['par'] ?? 'CEO'), 'Publication', $mag, 'Post « ' . $titre . ' » marqué publié');
        return ['ok' => true];
    }

    if ($row['status'] === 'publie') { http_response_code(409); return ['error' => 'post déjà publié']; }

    $decision = (string) ($b['decision'] ?? '');
    if ($decision !== 'valide' && $decision !== 'refuse') {
        http_response_code(422); return ['error' => 'decision attendue : valide ou refuse'];
    }
    $note = (int) ($b['note'] ?? 0);
    if ($note < 1 || $note > 5) { http_response_code(422); return ['error' => 'note hors échelle (1..5)']; }

    // Sous le seuil, ou en cas de refus, la famille et le type sont obligatoires :
    // un franchisé à qui l'on renvoie un post doit savoir quoi corriger, et six
    // mois plus tard le suivi doit pouvoir se lire autrement qu'en « Autre ».
    $seuil = fbSeuil();
    $fam = trim((string) ($b['famille'] ?? ''));
    $typ = trim((string) ($b['type'] ?? ''));
    if (($decision === 'refuse' || $note < $seuil) && ($fam === '' || $typ === '')) {
        http_response_code(422);
        return ['error' => 'famille et type obligatoires en cas de refus ou sous la note de ' . $seuil];
    }

    $par = (string) ($b['par'] ?? 'CEO');
    Db::exec('UPDATE ceo_fb_post SET status = ?, note = ?, decision_famille = ?, decision_type = ?, decision_comment = ?, decided_at = ?, decided_by = ? WHERE id = ?', [
        $decision === 'valide' ? 'valide' : 'refuse', $note,
        $fam !== '' ? $fam : null, $typ !== '' ? $typ : null,
        ($b['commentaire'] ?? '') !== '' ? (string) $b['commentaire'] : null,
        date('Y-m-d H:i:s'), $par, $id,
    ]);
    $ecart = $fam !== '' ? ' — ' . $fam . ' · ' . $typ : '';
    journalAdd($par, $decision === 'valide' ? 'Validation' : 'Refus', $mag,
        'Post « ' . $titre . ' » ' . ($decision === 'valide' ? 'validé' : 'refusé') . ' ' . $note . '/5'
        . $ecart . ($row['agent_note'] !== null && (int) $row['agent_note'] !== $note
            ? ' (agent : ' . (int) $row['agent_note'] . '/5)' : ''));
    return ['ok' => true];
}

/**
 * PATCH /facebook/posts/{id}/ecarts/{ecartId} — écarter ou rouvrir un écart.
 *
 * Écarter, c'est accorder une dérogation : l'écart reste visible, sort du
 * calcul de la note, et la note de l'agent est recalculée sur place — sinon
 * l'écran afficherait une note qui ne correspond plus à ce qu'il montre.
 */
function wr_fb_ecart_patch(string $postId, int $ecartId): array
{
    $b = body();
    $e = Db::row('SELECT * FROM ceo_fb_finding WHERE id = ? AND post_id = ?', [$ecartId, $postId]);
    if ($e === null) { http_response_code(404); return ['error' => 'écart inconnu']; }
    $statut = (string) ($b['statut'] ?? '');
    if (!in_array($statut, ['ouvert', 'ignore', 'corrige'], true)) {
        http_response_code(422); return ['error' => 'statut attendu : ouvert, ignore ou corrige'];
    }
    Db::exec('UPDATE ceo_fb_finding SET status = ? WHERE id = ?', [$statut, $ecartId]);

    $row = fbPostRow($postId);
    $ecarts = fbEcartsDuPost($postId);
    $note = fbNote($ecarts);
    $resume = fbResume($note, $ecarts);
    Db::exec('UPDATE ceo_fb_post SET agent_note = ?, agent_summary = ? WHERE id = ?', [$note, $resume, $postId]);
    journalAdd((string) ($b['par'] ?? 'CEO'), 'Contrôle', $row['shop_name'] ?? '—',
        'Écart « ' . $e['type'] . ' » ' . ($statut === 'ignore' ? 'écarté' : ($statut === 'corrige' ? 'marqué corrigé' : 'rouvert'))
        . ' sur « ' . fbTitre($row['message']) . ' » — note de l\'agent ' . $note . '/5');
    return ['ok' => true, 'note' => $note, 'resume' => $resume];
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
