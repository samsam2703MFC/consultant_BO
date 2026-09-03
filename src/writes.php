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
