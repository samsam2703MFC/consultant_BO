<?php
declare(strict_types=1);

/**
 * LES CONTRÔLES QUALITÉ DU MOIS (12/10/2026) — chaque contrôle photo de chaque magasin, jour par
 * jour, avec sa note au barème `signalement` (Exemplaire, Conforme, non conforme mineur / majeur /
 * critique) : ce que le cockpit montre en tableau, une photo à vérifier en modale.
 *
 * Tout vient de l'API du panel, en UNE lecture par magasin et par jour :
 * `/consultant/shops/{id}/tasks?date=` porte la tâche, sa checklist, la photo exigée, le statut,
 * la pièce jointe et l'avis (`review_rating`, `review_comment`, `reviewed_at`). Aucune table locale.
 *
 * La réponse du panel est gardée par magasin et par jour (`cqmJ:{id}:{date}`) : cinq minutes pour
 * aujourd'hui, une demi-heure pour hier, six heures avant ; une note posée depuis le cockpit efface
 * la journée concernée (wr_pwa_task_review). Un appel lit au plus `CQM_BUDGET` journées manquantes,
 * quatre de front ; `manque` dit combien restent, l'écran relit jusqu'à zéro.
 *
 * Rien de nominatif ne sort : ni qui a fait la tâche, ni qui l'a notée.
 */

const CQM_BUDGET = 48;

/**
 * Les magasins du consultant, par l'API : [id => nom court]. Le nom vient du référentiel des
 * magasins du cockpit (« Halle », comme partout ailleurs) : le `name` de l'API est la société
 * exploitante, parfois un nom de personne, et ne sort pas.
 */
function cqmMagasins(): array
{
    $courts = [];
    try {
        foreach (function_exists('ep_stores') ? ep_stores() : [] as $st) {
            $nom = (string) ($st['nom'] ?? '');
            if ($nom !== '') { $courts[(int) ($st['id'] ?? 0)] = str_contains($nom, ' - ') ? trim(substr($nom, strrpos($nom, ' - ') + 3)) : $nom; }
        }
    } catch (Throwable $e) { /* référentiel muet : numéros */ }
    $out = [];
    foreach (analyseListe(PanelApi::consultantShops() ?? []) as $sh) {
        $id = (int) ($sh['id'] ?? 0);
        if ($id > 0) { $out[$id] = $courts[$id] ?? ('Magasin ' . $id); }
    }
    ksort($out);
    return $out;
}

/** Durée de garde d'une journée : la journée en cours bouge, une journée ancienne ne bouge plus guère. */
function cqmTtl(string $date, string $auj): int
{
    if ($date >= $auj) { return 300; }
    if ($date === date('Y-m-d', strtotime($auj . ' -1 day'))) { return 1800; }
    return 21600;
}

function cqmCle(int $sid, string $date): string { return 'cqmJ:' . $sid . ':' . $date; }

/** Efface la journée gardée d'un magasin : une note vient d'y être posée. */
function cqmOublier(int $sid, string $date): void
{
    try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [cqmCle($sid, $date)]); } catch (Throwable $e) { /* la garde expirera seule */ }
}

/**
 * Ne garde d'une tâche du panel que ce que le tableau montre. Les tâches sans photo exigée ni
 * pièce jointe ne sont pas des contrôles en photo : elles sortent.
 */
function cqmTache(array $t): ?array
{
    $tid = (int) ($t['task_id'] ?? $t['id'] ?? 0);
    if ($tid <= 0) { return null; }
    $att = (int) ($t['attachment_id'] ?? 0);
    $exige = filter_var($t['requires_photo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$exige && $att <= 0) { return null; }
    $note = isset($t['review_rating']) && $t['review_rating'] !== null && $t['review_rating'] !== '' ? (int) $t['review_rating'] : null;
    $com = trim((string) ($t['review_comment'] ?? ''));
    return [
        'taskId' => $tid,
        'tache' => trim((string) ($t['task_name'] ?? '')) ?: 'Tâche ' . $tid,
        'checklist' => trim((string) ($t['checklist_name'] ?? '')),
        'checklistId' => (int) ($t['checklist_id'] ?? 0) ?: null,
        'completionId' => (int) ($t['completion_id'] ?? 0) ?: null,
        'heure' => !empty($t['execution_time']) ? substr((string) $t['execution_time'], 0, 5) : null,
        'statut' => strtoupper((string) ($t['status'] ?? '')),
        'fait' => !empty($t['completed_at']) ? substr((string) $t['completed_at'], 11, 5) : null,
        'photo' => $att > 0,
        'note' => $note !== null && $note >= 1 && $note <= 5 ? $note : null,
        'commentaire' => $com !== '' ? mb_substr($com, 0, 400) : null,
        'noteLe' => !empty($t['reviewed_at']) ? substr((string) $t['reviewed_at'], 0, 10) : null,
    ];
}

/**
 * GET /controles/qualite?m=YYYY-MM[&shop=ID] — les contrôles photo du mois, un par ligne, et leur
 * répartition au barème. `etat` d'une ligne : la note (1 à 5), sinon `a_noter` (photo rendue, pas
 * notée), `en_attente` (aujourd'hui, pas encore rendue) ou `non_rendue` (journée passée, sans photo).
 */
function ep_controles_qualite(): array
{
    $auj = date('Y-m-d');
    $m = (string) ($_GET['m'] ?? substr($auj, 0, 7));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = substr($auj, 0, 7); }
    $du = $m . '-01';
    $au = min(date('Y-m-t', strtotime($du)), $auj);
    $sig = setting('signalement', []);
    $niveaux = [];
    foreach ((is_array($sig) && is_array($sig['niveaux'] ?? null)) ? $sig['niveaux'] : [] as $nv) {
        if (isset($nv['n'])) { $niveaux[] = ['n' => (int) $nv['n'], 'nom' => (string) ($nv['nom'] ?? ''), 'couleur' => (string) ($nv['couleur'] ?? '')]; }
    }
    $seuil = is_array($sig) && isset($sig['seuil']) ? (int) $sig['seuil'] : 4;
    $out = ['mois' => $m, 'du' => $du, 'au' => $au, 'seuil' => $seuil, 'niveaux' => $niveaux,
        'magasins' => [], 'lignes' => [], 'manque' => 0, 'lus' => 0, 'source' => 'API du panel, une lecture par magasin et par jour',
        'api' => ['configure' => PanelApi::configured(), 'erreur' => null]];
    if ($du > $auj) { return $out; }
    if (!PanelApi::configured()) { $out['api']['erreur'] = 'identifiants API du panel non configurés (Paramètres)'; return $out; }

    $mags = cqmMagasins();
    $filtre = (int) ($_GET['shop'] ?? 0);
    if ($filtre > 0) { $mags = array_intersect_key($mags, [$filtre => true]); }
    foreach ($mags as $id => $nom) { $out['magasins'][] = ['id' => (string) $id, 'nom' => $nom]; }
    if ($mags === []) { $out['api']['erreur'] = 'magasins illisibles'; return $out; }

    // Les journées gardées d'abord, les manquantes ensuite, lues quatre de front dans le budget.
    $jours = [];
    for ($t = strtotime($du); $t <= strtotime($au); $t = strtotime('+1 day', $t)) { $jours[] = date('Y-m-d', $t); }
    $parJour = []; $aLire = [];
    foreach ($mags as $id => $nom) {
        foreach ($jours as $d) {
            $c = setting(cqmCle($id, $d), null);
            if (is_array($c) && isset($c['le'], $c['t']) && time() - (int) $c['le'] < cqmTtl($d, $auj)) { $parJour[$id][$d] = $c['t']; continue; }
            $aLire[$id . '|' . $d] = '/consultant/shops/' . $id . '/tasks?date=' . urlencode($d);
        }
    }
    // Les journées récentes passent devant : c'est là que l'œil va d'abord.
    uksort($aLire, static fn ($a, $b) => strcmp(explode('|', $b)[1], explode('|', $a)[1]) ?: strcmp($a, $b));
    $lot = array_slice($aLire, 0, CQM_BUDGET, true);
    $out['manque'] = count($aLire) - count($lot);
    foreach ($lot ? PanelApi::getParallele($lot) : [] as $k => $rep) {
        [$id, $d] = explode('|', (string) $k);
        if (!is_array($rep)) { $out['manque']++; continue; }   // pas de réponse : relue au prochain appel
        $taches = [];
        foreach (PanelApi::liste($rep) as $t) { if (is_array($t) && ($x = cqmTache($t)) !== null) { $taches[] = $x; } }
        $parJour[(int) $id][$d] = $taches;
        $out['lus']++;
        $j = json_encode(['le' => time(), 't' => $taches], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($j)) { try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [cqmCle((int) $id, $d), $j]); } catch (Throwable $e) { /* sans garde */ } }
    }
    $out['api']['erreur'] = PanelApi::$lastError ?: null;

    foreach ($parJour as $id => $js) {
        foreach ($js as $d => $taches) {
            foreach ($taches as $t) {
                $etat = $t['note'] ?? ($t['photo'] ? 'a_noter' : ($d >= $auj ? 'en_attente' : 'non_rendue'));
                $out['lignes'][] = ['shopId' => (string) $id, 'shop' => $mags[$id] ?? (string) $id, 'date' => $d] + $t + ['etat' => $etat];
            }
        }
    }
    usort($out['lignes'], static fn ($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['shop'], $b['shop']) ?: strcmp((string) ($a['heure'] ?? '99:99'), (string) ($b['heure'] ?? '99:99')) ?: $a['taskId'] <=> $b['taskId']);
    return $out;
}
