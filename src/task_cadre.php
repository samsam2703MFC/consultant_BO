<?php
declare(strict_types=1);

/*
 * Le cadre des tâches du panel — l'écran à part /task (demande du 07/11/2026).
 *
 * Les tâches que les magasins font chaque jour (photos de comptoir, contrôles qualité…) se
 * définissent dans le panel : la tâche (/tasks), sa checklist (/operational-framework/
 * checklists/{id}/tasks), les niveaux de poste qui la portent (/levels/{id}/tasks,
 * /position-levels/{id}/tasks) et ses contrôleurs (/tasks/{id}/controllers). Cet écran les
 * lit et permet d'en créer de nouvelles en DUPLIQUANT des tâches existantes : « la même photo
 * du comptoir, l'après-midi, le samedi et le dimanche, obligatoire, à 14:30 ».
 *
 * Pourquoi dupliquer plutôt que remplir un formulaire vierge : le document OpenAPI du panel
 * ne décrit ni le corps de POST /tasks ni le format des jours et de la fréquence (« contracts
 * are not inferred »). Partir d'une tâche qui marche, c'est reprendre exactement ses champs,
 * leurs types (1/0 ou vrai/faux, 04:00 ou 04:00:00) et ses rattachements ; les jours et la
 * fréquence se choisissent parmi les valeurs DÉJÀ employées par le panel, jamais devinées.
 *
 * Trois garde-fous, parce que tout ceci écrit dans le panel de production :
 *   1. l'aperçu (POST /task/apercu) montre chaque appel et son corps exact, sans rien envoyer ;
 *   2. la création s'arrête au premier refus du panel, et une tâche identique (même nom, même
 *      jour, même heure) n'est jamais recréée ;
 *   3. seule une tâche créée depuis cet écran (table ceo_task_cree) peut y être supprimée.
 *
 * Compte : celui du cockpit (compte consultant, PanelApi) ; ou, si le panel le refuse sur ces
 * routes, un compte administrateur du panel connecté depuis l'écran — son jeton voyage dans
 * l'en-tête X-Panel-Jeton et n'est gardé nulle part côté serveur.
 */

/** Les champs d'une tâche repris du modèle quand il les porte (forme de lecture du panel). */
const TK_CHAMPS_TACHE = ['subcategory_id', 'workstation_id', 'name', 'description', 'day_of_week',
    'execution_time', 'frequency', 'is_mandatory', 'priority', 'requires_photo'];

/** Au-delà, une duplication se fait en deux fois : le panel encaisse mal les rafales. */
const TK_MAX_CREATIONS = 60;

function tkRoute(string $method, string $path): array
{
    $j = trim((string) ($_SERVER['HTTP_X_PANEL_JETON'] ?? ''));
    if ($j !== '') { PanelApi::$jetonImpose = $j; }

    if ($method === 'POST' && $path === '/task/connexion') { return tkConnexion(body()); }
    if ($method === 'POST' && $path === '/task/connexion/renouveler') { return tkRenouveler(body()); }
    if (!PanelApi::configured() && PanelApi::$jetonImpose === null) {
        http_response_code(503);
        return ['error' => 'aucun compte du panel : renseignez le compte consultant du cockpit (Paramètres) '
            . 'ou connectez-vous avec un compte administrateur du panel', 'connexionRequise' => true];
    }
    if ($method === 'GET' && $path === '/task/cadre') { return tkCadre(); }
    if ($method === 'GET' && preg_match('#^/task/taches/(\d{1,10})$#', $path, $m)) { return tkTache((int) $m[1]); }
    if ($method === 'POST' && $path === '/task/apercu') { return tkPlan(body(), false); }
    if ($method === 'POST' && $path === '/task/creer') { return tkPlan(body(), true); }
    if ($method === 'PATCH' && preg_match('#^/task/taches/(\d{1,10})$#', $path, $m)) { return tkModifier((int) $m[1], body()); }
    if ($method === 'DELETE' && preg_match('#^/task/taches/(\d{1,10})$#', $path, $m)) { return tkSupprimer((int) $m[1]); }
    return notFound();
}

/* ─────────── petits outils ─────────── */

function tkTxt(mixed $v): string
{
    if ($v === null || $v === 'NULL') { return ''; }
    if (is_array($v)) { return json_encode($v, JSON_UNESCAPED_UNICODE) ?: ''; }
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    return trim((string) $v);
}

function tkVrai(mixed $v): bool
{
    return $v === true || (is_numeric($v) && (int) $v === 1) || $v === 'true';
}

/** Un booléen écrit comme le modèle l'écrit : vrai/faux, 1/0 ou "1"/"0". */
function tkMemeType(mixed $modele, bool $v): mixed
{
    if (is_bool($modele)) { return $v; }
    if (is_string($modele)) {
        $m = strtolower(trim($modele));
        if ($m === 'true' || $m === 'false') { return $v ? 'true' : 'false'; }
        return $v ? '1' : '0';
    }
    return $v ? 1 : 0;
}

/** L'heure au format du modèle : 14:30:00 si le modèle a des secondes (ou rien), 14:30 sinon. */
function tkHeure(string $hhmm, mixed $modele): string
{
    $m = tkTxt($modele);
    if ($m !== '' && preg_match('/^\d{1,2}:\d{2}$/', $m)) { return $hhmm; }
    return $hhmm . ':00';
}

/** La liste d'une réponse du panel, pages suivantes comprises si elle est paginée. */
function tkLireListe(string $path): array
{
    $r = PanelApi::requete('GET', $path);
    if ($r['code'] < 200 || $r['code'] >= 300) { return [null, $r['erreur']]; }
    $corps = is_array($r['corps']) ? $r['corps'] : [];
    $items = PanelApi::liste($corps);
    $meta = is_array($corps['meta'] ?? null) ? $corps['meta'] : (is_array($corps['pagination'] ?? null) ? $corps['pagination'] : $corps);
    $derniere = (int) ($meta['last_page'] ?? $meta['total_pages'] ?? $meta['pages'] ?? 1);
    for ($p = 2; $p <= min($derniere, 30); $p++) {
        $r2 = PanelApi::requete('GET', $path . (str_contains($path, '?') ? '&' : '?') . 'page=' . $p);
        if ($r2['code'] < 200 || $r2['code'] >= 300) { break; }
        $suite = PanelApi::liste(is_array($r2['corps']) ? $r2['corps'] : []);
        if ($suite === []) { break; }
        $items = array_merge($items, $suite);
    }
    return [$items, null];
}

function tkNormTache(array $t): array
{
    $actif = null;
    foreach (['is_active', 'active', 'enabled'] as $k) {
        if (array_key_exists($k, $t)) { $actif = tkVrai($t[$k]); break; }
    }
    return [
        'id'          => (int) ($t['id'] ?? $t['task_id'] ?? 0),
        'nom'         => tkTxt($t['name'] ?? $t['base_name'] ?? ''),
        'description' => tkTxt($t['description'] ?? $t['base_description'] ?? ''),
        'jour'        => tkTxt($t['day_of_week'] ?? ''),
        'heure'       => substr(tkTxt($t['execution_time'] ?? ''), 0, 5),
        'frequence'   => tkTxt($t['frequency'] ?? ''),
        'obligatoire' => tkVrai($t['is_mandatory'] ?? 0),
        'photo'       => tkVrai($t['requires_photo'] ?? 0),
        'priorite'    => is_numeric($t['priority'] ?? null) ? (int) $t['priority'] : null,
        'actif'       => $actif,
        'section'     => tkTxt($t['section_name'] ?? $t['base_section_name'] ?? ''),
        'categorie'   => tkTxt($t['category_name'] ?? $t['base_category_name'] ?? ''),
        'sousCategorie' => tkTxt($t['subcategory_name'] ?? $t['base_subcategory_name'] ?? ''),
        'brut'        => $t,
    ];
}

/** Les valeurs employées par le panel pour un champ, avec leur nombre, les plus courantes d'abord. */
function tkValeurs(array $taches, string $champ): array
{
    $n = [];
    foreach ($taches as $t) {
        $v = tkTxt($t['brut'][$champ] ?? '');
        $n[$v] = ($n[$v] ?? 0) + 1;
    }
    arsort($n);
    $out = [];
    foreach ($n as $v => $c) { $out[] = ['valeur' => (string) $v, 'nb' => $c]; }
    return $out;
}

function tkNiveauNorm(array $l): array
{
    return ['id' => (int) ($l['id'] ?? $l['level_id'] ?? 0),
        'nom' => tkTxt($l['name'] ?? $l['level_name'] ?? $l['base_name'] ?? ''),
        'poste' => tkTxt($l['position_name'] ?? ($l['position']['name'] ?? '')),
        'posteId' => (int) ($l['position_id'] ?? ($l['position']['id'] ?? 0))];
}

/** Le compte avec lequel on parle au panel, pour l'écran. */
function tkCompte(): array
{
    return ['compte' => PanelApi::$jetonImpose !== null ? 'admin' : 'cockpit',
        'base' => PanelApi::config()['base'], 'configure' => PanelApi::configured()];
}

function ensureTaskCree(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_task_cree (
        task_id INT NOT NULL PRIMARY KEY,
        nom VARCHAR(190) NOT NULL,
        jour VARCHAR(60) NULL,
        heure VARCHAR(8) NULL,
        checklist_id INT NULL,
        modele_id INT NULL,
        compte VARCHAR(20) NOT NULL DEFAULT \'cockpit\',
        cree_le DATETIME NOT NULL,
        supprime_le DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* ─────────── lecture ─────────── */

/**
 * GET /task/cadre — les checklists du cadre opérationnel et leurs tâches, toutes les tâches,
 * les niveaux de poste, les valeurs employées pour les jours et la fréquence, et ce que cet
 * écran a déjà créé.
 */
function tkCadre(): array
{
    $erreurs = [];
    [$brutes, $e] = tkLireListe('/tasks');
    if ($brutes === null) { $erreurs['taches'] = $e; $brutes = []; }
    [$cls, $e] = tkLireListe('/operational-framework/checklists');
    if ($cls === null) { $erreurs['checklists'] = $e; $cls = []; }
    [$nivs, $e] = tkLireListe('/position-levels');
    if ($nivs === null) { $erreurs['niveaux'] = $e; $nivs = []; }

    $taches = [];
    foreach ($brutes as $t) {
        $n = tkNormTache($t);
        if ($n['id'] > 0) { $taches[$n['id']] = $n; }
    }

    $req = [];
    foreach ($cls as $c) {
        $cid = (int) ($c['id'] ?? $c['checklist_id'] ?? 0);
        if ($cid > 0) { $req[$cid] = '/operational-framework/checklists/' . $cid . '/tasks'; }
    }
    $contenus = $req ? PanelApi::getParallele($req) : [];
    $dans = [];
    $checklists = [];
    foreach ($cls as $c) {
        $cid = (int) ($c['id'] ?? $c['checklist_id'] ?? 0);
        if ($cid <= 0) { continue; }
        $lignes = [];
        $rep = $contenus[$cid] ?? null;
        if ($rep === null) { $erreurs['checklist ' . $cid] = 'GET /operational-framework/checklists/' . $cid . '/tasks : pas de réponse'; }
        foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $i => $x) {
            $tid = (int) ($x['task_id'] ?? $x['id'] ?? 0);
            if ($tid <= 0) { continue; }
            $lignes[] = ['taskId' => $tid, 'ordre' => (int) ($x['sort_order'] ?? $x['position'] ?? $i + 1),
                'note' => tkTxt($x['note'] ?? '')];
            $dans[$tid] = true;
            // Une tâche absente de /tasks (inactive, filtrée) reste lisible par sa ligne de checklist.
            if (!isset($taches[$tid])) {
                $n = tkNormTache(['id' => $tid] + $x);
                $n['horsListe'] = true;
                $taches[$tid] = $n;
            }
        }
        usort($lignes, fn ($a, $b) => $a['ordre'] <=> $b['ordre']);
        $checklists[] = ['id' => $cid, 'nom' => tkTxt($c['name'] ?? $c['base_name'] ?? ('Checklist ' . $cid)),
            'description' => tkTxt($c['description'] ?? ''), 'poste' => tkTxt($c['workstation_name'] ?? ''),
            'heure' => substr(tkTxt($c['execution_time'] ?? ''), 0, 5), 'taches' => $lignes, 'brut' => $c];
    }
    usort($checklists, fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));

    $creees = [];
    try {
        ensureTaskCree();
        foreach (Db::rows('SELECT * FROM ceo_task_cree WHERE supprime_le IS NULL ORDER BY cree_le DESC LIMIT 200') as $r) {
            $creees[] = ['id' => (int) $r['task_id'], 'nom' => (string) $r['nom'], 'jour' => (string) ($r['jour'] ?? ''),
                'heure' => (string) ($r['heure'] ?? ''), 'checklistId' => (int) ($r['checklist_id'] ?? 0),
                'modeleId' => (int) ($r['modele_id'] ?? 0), 'compte' => (string) $r['compte'], 'le' => substr((string) $r['cree_le'], 0, 16)];
        }
    } catch (PDOException $e) { /* table impossible : la liste reste vide */ }

    return [
        'api' => tkCompte(),
        'checklists' => $checklists,
        'taches' => array_values($taches),
        'horsChecklist' => array_values(array_map(fn ($t) => $t['id'], array_filter($taches, fn ($t) => !isset($dans[$t['id']])))),
        'niveaux' => array_values(array_filter(array_map('tkNiveauNorm', $nivs), fn ($l) => $l['id'] > 0)),
        'options' => ['jours' => tkValeurs($taches, 'day_of_week'), 'frequences' => tkValeurs($taches, 'frequency')],
        'creees' => $creees,
        'erreurs' => $erreurs ?: (object) [],
    ];
}

/**
 * Les niveaux de poste qui portent chacune des tâches demandées, sous les deux routes du panel
 * (/levels/{id}/tasks et /position-levels/{id}/tasks) — on recopie dans celle où on l'a trouvée.
 *
 * @return array<int, list<array{niveau:int, route:string}>>
 */
function tkNiveauxDe(array $taskIds): array
{
    [$nivs] = tkLireListe('/position-levels');
    $req = [];
    foreach ((array) $nivs as $l) {
        $lid = (int) ($l['id'] ?? $l['level_id'] ?? 0);
        if ($lid <= 0) { continue; }
        $req['levels|' . $lid] = '/levels/' . $lid . '/tasks';
        $req['position-levels|' . $lid] = '/position-levels/' . $lid . '/tasks';
    }
    $voulu = array_fill_keys(array_map('intval', $taskIds), true);
    $out = [];
    foreach ($req ? PanelApi::getParallele($req) : [] as $cle => $rep) {
        [$route, $lid] = explode('|', (string) $cle);
        foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $x) {
            $tid = (int) ($x['task_id'] ?? $x['id'] ?? 0);
            if (isset($voulu[$tid])) { $out[$tid][] = ['niveau' => (int) $lid, 'route' => $route]; }
        }
    }
    return $out;
}

/** Le corps d'un contrôleur à recopier : ses identifiants et son type, sans les champs de lecture. */
function tkCorpsControleur(array $c): array
{
    $out = [];
    foreach ($c as $k => $v) {
        if (!is_scalar($v) && $v !== null) { continue; }
        if (in_array($k, ['id', 'task_id', 'created_at', 'updated_at', 'deleted_at'], true)) { continue; }
        if (str_ends_with((string) $k, '_id') || in_array($k, ['type', 'kind', 'role', 'scope', 'scope_type', 'controller_type'], true)) {
            $out[$k] = $v;
        }
    }
    if ($out === []) {
        foreach ($c as $k => $v) {
            if ((is_scalar($v) || $v === null) && !in_array($k, ['id', 'task_id', 'created_at', 'updated_at', 'deleted_at'], true)
                && !str_ends_with((string) $k, '_name')) { $out[$k] = $v; }
        }
    }
    return $out;
}

/** GET /task/taches/{id} — une tâche : la donnée brute, ses contrôleurs, ses niveaux, ses checklists. */
function tkTache(int $id): array
{
    [$brutes, $e] = tkLireListe('/tasks');
    $t = null;
    foreach ((array) $brutes as $x) { if ((int) ($x['id'] ?? $x['task_id'] ?? 0) === $id) { $t = $x; break; } }
    $ctrl = PanelApi::requete('GET', '/tasks/' . $id . '/controllers');
    return [
        'id' => $id,
        'tache' => $t !== null ? tkNormTache($t) : null,
        'erreur' => $t === null ? ($e ?? 'tâche absente de GET /tasks') : null,
        'controleurs' => ['code' => $ctrl['code'], 'liste' => PanelApi::liste(is_array($ctrl['corps']) ? $ctrl['corps'] : []),
            'brut' => $ctrl['corps'], 'erreur' => $ctrl['erreur']],
        'niveaux' => tkNiveauxDe([$id])[$id] ?? [],
    ];
}

/* ─────────── duplication ─────────── */

/**
 * POST /task/apercu et POST /task/creer — dupliquer des tâches.
 *
 * Corps : { modeles: [taskId…], checklistId, nom: {remplacer, par, ajouter},
 *           jours: [valeur…], unParJour, heure: "HH:MM", frequence, obligatoire, photo,
 *           niveaux: bool, controleurs: bool, extra: {…}, seulementPremiere: bool }
 * Un champ absent ou null garde la valeur du modèle.
 */
function tkPlan(array $b, bool $executer): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['modeles'] ?? [])), fn ($i) => $i > 0)));
    if ($ids === []) { http_response_code(422); return ['error' => 'choisissez au moins une tâche modèle']; }

    $jours = array_values(array_filter(array_map(fn ($v) => tkTxt($v), (array) ($b['jours'] ?? [])), fn ($v) => $v !== ''));
    $unParJour = !array_key_exists('unParJour', $b) || !empty($b['unParJour']);
    $heure = trim((string) ($b['heure'] ?? ''));
    if ($heure !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) {
        http_response_code(422); return ['error' => 'heure attendue au format HH:MM'];
    }
    $freq = array_key_exists('frequence', $b) && $b['frequence'] !== null ? tkTxt($b['frequence']) : null;
    $obl = array_key_exists('obligatoire', $b) && $b['obligatoire'] !== null ? (bool) $b['obligatoire'] : null;
    $photo = array_key_exists('photo', $b) && $b['photo'] !== null ? (bool) $b['photo'] : null;
    $nom = is_array($b['nom'] ?? null) ? $b['nom'] : [];
    $remplacer = (string) ($nom['remplacer'] ?? '');
    $par = (string) ($nom['par'] ?? '');
    $ajouter = (string) ($nom['ajouter'] ?? '');
    $extra = is_array($b['extra'] ?? null) ? $b['extra'] : [];
    $cid = (int) ($b['checklistId'] ?? 0);
    $copierNiveaux = !array_key_exists('niveaux', $b) || !empty($b['niveaux']);
    $copierCtrl = !array_key_exists('controleurs', $b) || !empty($b['controleurs']);

    [$brutes, $e] = tkLireListe('/tasks');
    if ($brutes === null) { http_response_code(502); return ['error' => 'lecture des tâches du panel impossible : ' . $e]; }
    $parId = [];
    foreach ($brutes as $t) { $parId[(int) ($t['id'] ?? $t['task_id'] ?? 0)] = $t; }
    $manquent = array_values(array_filter($ids, fn ($i) => !isset($parId[$i])));
    if ($manquent) { http_response_code(422); return ['error' => 'tâche(s) absente(s) du panel : #' . implode(', #', $manquent)]; }

    // La checklist cible : son nom, et la place de la prochaine tâche (après la dernière).
    $clNom = null; $ordre = 0;
    if ($cid > 0) {
        [$cls] = tkLireListe('/operational-framework/checklists');
        foreach ((array) $cls as $c) { if ((int) ($c['id'] ?? 0) === $cid) { $clNom = tkTxt($c['name'] ?? ''); } }
        [$lignes, $eL] = tkLireListe('/operational-framework/checklists/' . $cid . '/tasks');
        if ($lignes === null) { http_response_code(502); return ['error' => 'lecture de la checklist #' . $cid . ' impossible : ' . $eL]; }
        foreach ($lignes as $i => $x) { $ordre = max($ordre, (int) ($x['sort_order'] ?? $i + 1)); }
    }

    $niveaux = $copierNiveaux ? tkNiveauxDe($ids) : [];
    $ctrls = [];
    if ($copierCtrl) {
        $req = [];
        foreach ($ids as $i) { $req[$i] = '/tasks/' . $i . '/controllers'; }
        foreach (PanelApi::getParallele($req) as $i => $rep) { $ctrls[(int) $i] = PanelApi::liste(is_array($rep) ? $rep : []); }
    }

    // Les tâches qui existent déjà, par nom + jour + heure : une seconde création serait un doublon.
    $existe = [];
    foreach ($parId as $tid => $t) {
        $n = tkNormTache($t);
        $existe[mb_strtolower($n['nom']) . '|' . $n['jour'] . '|' . $n['heure']] = $tid;
    }

    $items = [];
    $avert = [];
    foreach ($ids as $mid) {
        $m = $parId[$mid];
        $mn = tkNormTache($m);
        $nouveau = $mn['nom'];
        if ($remplacer !== '') {
            if (str_contains($nouveau, $remplacer)) { $nouveau = str_replace($remplacer, $par, $nouveau); }
            else { $avert[] = '« ' . $remplacer . ' » n\'apparaît pas dans « ' . $mn['nom'] . ' » : nom gardé'; }
        }
        $nouveau = trim($nouveau . $ajouter);
        if ($nouveau === '') { http_response_code(422); return ['error' => 'le nom de la copie de #' . $mid . ' serait vide']; }

        $variantes = $jours === [] ? [null] : ($unParJour ? $jours : [implode(',', $jours)]);
        foreach ($variantes as $jour) {
            $corps = [];
            foreach (TK_CHAMPS_TACHE as $k) { if (array_key_exists($k, $m)) { $corps[$k] = $m[$k]; } }
            if (!array_key_exists('description', $corps) && array_key_exists('base_description', $m)) { $corps['description'] = $m['base_description']; }
            $corps['name'] = $nouveau;
            if ($jour !== null) { $corps['day_of_week'] = $jour; }
            if ($heure !== '') { $corps['execution_time'] = tkHeure($heure, $m['execution_time'] ?? null); }
            if ($freq !== null) { $corps['frequency'] = $freq === '' ? null : $freq; }
            if ($obl !== null) { $corps['is_mandatory'] = tkMemeType($m['is_mandatory'] ?? null, $obl); }
            if ($photo !== null) { $corps['requires_photo'] = tkMemeType($m['requires_photo'] ?? null, $photo); }
            foreach ($extra as $k => $v) { $corps[(string) $k] = $v; }

            $cle = mb_strtolower($nouveau) . '|' . tkTxt($corps['day_of_week'] ?? '') . '|' . substr(tkTxt($corps['execution_time'] ?? ''), 0, 5);
            $deja = $existe[$cle] ?? null;
            $ops = [['quoi' => 'Créer la tâche', 'methode' => 'POST', 'chemin' => '/tasks', 'corps' => $corps]];
            if ($cid > 0) {
                if ($deja === null) { $ordre++; }   // une tâche qui existe déjà ne prend pas de place
                $ops[] = ['quoi' => 'Ajouter à la checklist « ' . ($clNom ?? ('#' . $cid)) . ' »', 'methode' => 'POST',
                    'chemin' => '/operational-framework/checklists/' . $cid . '/tasks',
                    'corps' => ['task_id' => '{nouvelle}', 'sort_order' => $ordre]];
            }
            foreach ($niveaux[$mid] ?? [] as $lv) {
                $ops[] = ['quoi' => 'Rattacher au niveau de poste #' . $lv['niveau'], 'methode' => 'POST',
                    'chemin' => '/' . $lv['route'] . '/' . $lv['niveau'] . '/tasks', 'corps' => ['task_id' => '{nouvelle}']];
            }
            foreach ($ctrls[$mid] ?? [] as $c) {
                $ops[] = ['quoi' => 'Ajouter le contrôleur du modèle', 'methode' => 'POST',
                    'chemin' => '/tasks/{nouvelle}/controllers', 'corps' => tkCorpsControleur($c)];
            }
            $items[] = ['modeleId' => $mid, 'modeleNom' => $mn['nom'], 'nom' => $nouveau,
                'jour' => tkTxt($corps['day_of_week'] ?? ''), 'heure' => substr(tkTxt($corps['execution_time'] ?? ''), 0, 5),
                'existe' => $deja, 'operations' => $ops];
        }
    }
    $aCreer = count(array_filter($items, fn ($i) => $i['existe'] === null));
    if ($aCreer > TK_MAX_CREATIONS) {
        http_response_code(422);
        return ['error' => $aCreer . ' tâches à créer : au-delà de ' . TK_MAX_CREATIONS . ', faites-le en plusieurs fois'];
    }
    // Des jours imposés à un modèle quotidien, sans changer la fréquence : la copie porterait
    // un jour ET une fréquence de tous les jours. On dit quelle fréquence les tâches à jour
    // fixe du panel emploient, plutôt que de la deviner.
    if ($jours !== [] && $freq === null) {
        $sansJour = array_filter($ids, fn ($i) => tkTxt($parId[$i]['day_of_week'] ?? '') === '');
        if ($sansJour) {
            $fj = [];
            foreach ($parId as $t) {
                if (tkTxt($t['day_of_week'] ?? '') !== '') { $f = tkTxt($t['frequency'] ?? ''); $fj[$f] = ($fj[$f] ?? 0) + 1; }
            }
            arsort($fj);
            $avert[] = count($sansJour) . ' modèle(s) n\'ont pas de jour (fréquence « ' . tkTxt($parId[reset($sansJour)]['frequency'] ?? '') . ' ») '
                . 'et la fréquence n\'est pas changée'
                . ($fj ? ' : les tâches à jour fixe du panel emploient ' . implode(', ', array_map(fn ($f, $n) => '« ' . ($f === '' ? '(vide)' : $f) . ' » (' . $n . ')', array_keys($fj), $fj)) : '');
        }
    }
    if ($copierNiveaux && array_filter($ids, fn ($i) => empty($niveaux[$i]))) {
        $avert[] = 'certains modèles ne sont rattachés à aucun niveau de poste : leurs copies ne le seront pas non plus';
    }

    $out = ['api' => tkCompte(), 'checklist' => $cid > 0 ? ['id' => $cid, 'nom' => $clNom] : null,
        'items' => $items, 'aCreer' => $aCreer, 'avertissements' => array_values(array_unique($avert))];
    if (!$executer) { return $out; }
    return tkExecuter($out, $parId, !empty($b['seulementPremiere']));
}

/** Remplace {nouvelle} par l'identifiant de la tâche créée, partout dans une opération. */
function tkSubstituer(mixed $v, int $id): mixed
{
    if (is_string($v)) { return $v === '{nouvelle}' ? $id : str_replace('{nouvelle}', (string) $id, $v); }
    if (is_array($v)) { foreach ($v as $k => $x) { $v[$k] = tkSubstituer($x, $id); } }
    return $v;
}

/** L'identifiant rendu par POST /tasks, quelle que soit l'enveloppe. */
function tkIdRendu(mixed $corps): int
{
    if (!is_array($corps)) { return 0; }
    foreach ([$corps['id'] ?? null, $corps['task_id'] ?? null, $corps['data']['id'] ?? null,
              $corps['data']['task_id'] ?? null, $corps['data']['task']['id'] ?? null, $corps['task']['id'] ?? null] as $c) {
        if (is_numeric($c) && (int) $c > 0) { return (int) $c; }
    }
    return 0;
}

function tkExecuter(array $plan, array $avant, bool $seulementPremiere): array
{
    ensureTaskCree();
    $compte = PanelApi::$jetonImpose !== null ? 'admin' : 'cockpit';
    $connus = array_fill_keys(array_keys($avant), true);
    $crees = 0;
    $arret = null;
    foreach ($plan['items'] as $n => $item) {
        if ($arret !== null) { $plan['items'][$n]['statut'] = 'non tenté'; continue; }
        if ($item['existe'] !== null) { $plan['items'][$n]['statut'] = 'existe déjà'; continue; }
        if ($seulementPremiere && $crees >= 1) { $plan['items'][$n]['statut'] = 'non tenté (essai)'; continue; }

        $ops = $item['operations'];
        $r = PanelApi::requete('POST', '/tasks', $ops[0]['corps']);
        $ops[0] += ['ok' => $r['erreur'] === null, 'code' => $r['code'], 'reponse' => $r['corps'], 'erreur' => $r['erreur']];
        if ($r['erreur'] !== null) {
            $plan['items'][$n]['operations'] = $ops;
            $plan['items'][$n]['statut'] = 'refusée';
            $arret = 'Le panel a refusé la création de « ' . $item['nom'] . ' » : ' . $r['erreur'] . '. Rien d\'autre n\'a été tenté.';
            continue;
        }
        $id = tkIdRendu($r['corps']);
        if ($id <= 0) {
            // La réponse ne dit pas l'identifiant : on le retrouve dans la liste, la seule tâche
            // nouvelle qui porte ce nom.
            [$apres] = tkLireListe('/tasks');
            foreach ((array) $apres as $t) {
                $tid = (int) ($t['id'] ?? 0);
                if ($tid > 0 && !isset($connus[$tid]) && mb_strtolower(tkNormTache($t)['nom']) === mb_strtolower($item['nom'])) { $id = $tid; }
            }
        }
        if ($id <= 0) {
            $plan['items'][$n]['operations'] = $ops;
            $plan['items'][$n]['statut'] = 'créée, identifiant inconnu';
            $arret = 'La tâche « ' . $item['nom'] . ' » semble créée, mais le panel n\'a pas rendu son identifiant : '
                . 'la checklist, les niveaux et les contrôleurs n\'ont pas été faits. Vérifiez dans le panel avant de continuer.';
            continue;
        }
        $connus[$id] = true;
        $crees++;
        for ($k = 1; $k < count($ops); $k++) {
            $op = tkSubstituer($ops[$k], $id);
            $rk = PanelApi::requete($op['methode'], $op['chemin'], $op['corps']);
            $ops[$k] = $op + ['ok' => $rk['erreur'] === null, 'code' => $rk['code'], 'reponse' => $rk['corps'], 'erreur' => $rk['erreur']];
        }
        $plan['items'][$n]['operations'] = $ops;
        $plan['items'][$n]['id'] = $id;
        $ratees = count(array_filter($ops, fn ($o) => empty($o['ok'])));
        $plan['items'][$n]['statut'] = $ratees === 0 ? 'créée' : 'créée, ' . $ratees . ' étape(s) refusée(s)';
        $cidLien = null;
        foreach ($ops as $o) { if (preg_match('#^/operational-framework/checklists/(\d+)/tasks$#', $o['chemin'], $mm)) { $cidLien = (int) $mm[1]; } }
        try {
            Db::exec('INSERT INTO ceo_task_cree (task_id, nom, jour, heure, checklist_id, modele_id, compte, cree_le)
                      VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nom = VALUES(nom), supprime_le = NULL',
                [$id, mb_substr($item['nom'], 0, 190), mb_substr($item['jour'], 0, 60), $item['heure'], $cidLien,
                 $item['modeleId'], $compte, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) { /* le panel a la tâche : la trace locale n'est qu'un confort */ }
        journalAdd('CEO', 'Cadre des tâches', null, 'Tâche « ' . $item['nom'] . ' » créée au panel (#' . $id . ')'
            . ($item['jour'] !== '' ? ', jour ' . $item['jour'] : '') . ($item['heure'] !== '' ? ', ' . $item['heure'] : '')
            . ', copie de #' . $item['modeleId'] . ($ratees ? ' — ' . $ratees . ' étape(s) refusée(s)' : ''));
    }
    $plan['creees'] = $crees;
    $plan['arret'] = $arret;
    return $plan;
}

/* ─────────── modifier, supprimer ─────────── */

/**
 * PATCH /task/taches/{id} — changer une tâche existante. Corps : les champs à changer parmi
 * nom, description, jour, heure, frequence, obligatoire, photo, priorite, actif ; `apercu: true`
 * rend les appels sans les envoyer.
 */
function tkModifier(int $id, array $b): array
{
    [$brutes, $e] = tkLireListe('/tasks');
    $m = null;
    foreach ((array) $brutes as $x) { if ((int) ($x['id'] ?? $x['task_id'] ?? 0) === $id) { $m = $x; break; } }
    if ($m === null) { http_response_code(404); return ['error' => 'tâche #' . $id . ' absente du panel' . ($e ? ' : ' . $e : '')]; }

    $corps = [];
    if (array_key_exists('nom', $b)) {
        $n = trim((string) $b['nom']);
        if ($n === '') { http_response_code(422); return ['error' => 'le nom ne peut pas être vide']; }
        $corps['name'] = $n;
    }
    if (array_key_exists('description', $b)) { $corps['description'] = (string) $b['description']; }
    if (array_key_exists('jour', $b)) { $j = tkTxt($b['jour']); $corps['day_of_week'] = $j === '' ? null : $j; }
    if (array_key_exists('heure', $b)) {
        $h = trim((string) $b['heure']);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)) { http_response_code(422); return ['error' => 'heure attendue au format HH:MM']; }
        $corps['execution_time'] = tkHeure($h, $m['execution_time'] ?? null);
    }
    if (array_key_exists('frequence', $b)) { $f = tkTxt($b['frequence']); $corps['frequency'] = $f === '' ? null : $f; }
    if (array_key_exists('obligatoire', $b)) { $corps['is_mandatory'] = tkMemeType($m['is_mandatory'] ?? null, (bool) $b['obligatoire']); }
    if (array_key_exists('photo', $b)) { $corps['requires_photo'] = tkMemeType($m['requires_photo'] ?? null, (bool) $b['photo']); }
    if (array_key_exists('priorite', $b) && is_numeric($b['priorite'])) { $corps['priority'] = (int) $b['priorite']; }

    $ops = [];
    if ($corps) { $ops[] = ['quoi' => 'Modifier la tâche', 'methode' => 'PATCH', 'chemin' => '/tasks/' . $id, 'corps' => $corps]; }
    if (array_key_exists('actif', $b)) {
        $mod = $m['is_active'] ?? ($m['active'] ?? null);
        $ops[] = ['quoi' => $b['actif'] ? 'Activer' : 'Mettre en pause', 'methode' => 'PATCH', 'chemin' => '/tasks/' . $id . '/active',
            'corps' => ['is_active' => tkMemeType($mod, (bool) $b['actif'])]];
    }
    if (!$ops) { http_response_code(422); return ['error' => 'rien à changer']; }
    if (!empty($b['apercu'])) { return ['id' => $id, 'operations' => $ops]; }

    foreach ($ops as $k => $op) {
        $r = PanelApi::requete($op['methode'], $op['chemin'], $op['corps']);
        $ops[$k] += ['ok' => $r['erreur'] === null, 'code' => $r['code'], 'reponse' => $r['corps'], 'erreur' => $r['erreur']];
    }
    $ok = !array_filter($ops, fn ($o) => !$o['ok']);
    journalAdd('CEO', 'Cadre des tâches', null, 'Tâche #' . $id . ' (« ' . tkNormTache($m)['nom'] . ' ») modifiée au panel : '
        . implode(', ', array_keys($corps)) . (array_key_exists('actif', $b) ? ($corps ? ', ' : '') . ($b['actif'] ? 'activée' : 'mise en pause') : '')
        . ($ok ? '' : ' — refusé en partie'));
    if (!$ok) { http_response_code(502); }
    return ['id' => $id, 'ok' => $ok, 'operations' => $ops];
}

/** DELETE /task/taches/{id} — seulement une tâche créée depuis cet écran (ceo_task_cree). */
function tkSupprimer(int $id): array
{
    ensureTaskCree();
    $r = Db::row('SELECT * FROM ceo_task_cree WHERE task_id = ? AND supprime_le IS NULL', [$id]);
    if ($r === null) {
        http_response_code(403);
        return ['error' => 'seule une tâche créée depuis cet écran se supprime ici ; les autres se suppriment dans le panel'];
    }
    $rep = PanelApi::requete('DELETE', '/tasks/' . $id);
    if ($rep['erreur'] !== null) {
        http_response_code(502);
        return ['error' => $rep['erreur'], 'reponse' => $rep['corps']];
    }
    Db::exec('UPDATE ceo_task_cree SET supprime_le = ? WHERE task_id = ?', [date('Y-m-d H:i:s'), $id]);
    journalAdd('CEO', 'Cadre des tâches', null, 'Tâche « ' . $r['nom'] . ' » (#' . $id . ') supprimée du panel');
    return ['ok' => true, 'id' => $id, 'reponse' => $rep['corps']];
}

/* ─────────── connexion administrateur ─────────── */

/** Le jeton d'une réponse de connexion, quelle que soit l'enveloppe. */
function tkJetons(mixed $c): array
{
    $c = is_array($c) ? $c : [];
    $d = is_array($c['data'] ?? null) ? $c['data'] : [];
    $acces = $c['access_token'] ?? $d['access_token'] ?? $c['token'] ?? $d['token'] ?? null;
    return ['jeton' => is_string($acces) && $acces !== '' ? $acces : null,
        'renouvellement' => (string) ($c['refresh_token'] ?? $d['refresh_token'] ?? ''),
        'expire' => time() + (int) ($c['expires_in'] ?? $d['expires_in'] ?? 1800)];
}

/** POST /task/connexion {phone, password} — compte administrateur du panel. */
function tkConnexion(array $b): array
{
    $phone = trim((string) ($b['phone'] ?? ''));
    $pw = (string) ($b['password'] ?? '');
    if ($phone === '' || $pw === '') { http_response_code(422); return ['error' => 'téléphone et mot de passe requis']; }
    $r = PanelApi::connexionPanel($phone, $pw);
    $j = tkJetons($r['corps']);
    if ($j['jeton'] === null) {
        usleep(400000);
        http_response_code($r['code'] === 0 ? 502 : 401);
        $msg = is_array($r['corps']) ? (string) ($r['corps']['description'] ?? $r['corps']['message'] ?? '') : '';
        return ['error' => 'connexion refusée par le panel' . ($r['code'] ? ' (HTTP ' . $r['code'] . ')' : '') . ($msg !== '' ? ' : ' . $msg : '')];
    }
    return ['ok' => true] + $j;
}

/** POST /task/connexion/renouveler {renouvellement} — un nouveau jeton sans redemander le mot de passe. */
function tkRenouveler(array $b): array
{
    $rt = trim((string) ($b['renouvellement'] ?? ''));
    if ($rt === '') { http_response_code(422); return ['error' => 'jeton de renouvellement requis']; }
    $r = PanelApi::renouvelerPanel($rt);
    $j = tkJetons($r['corps']);
    if ($j['jeton'] === null) { http_response_code(401); return ['error' => 'session du panel expirée — reconnectez-vous']; }
    if ($j['renouvellement'] === '') { $j['renouvellement'] = $rt; }
    return ['ok' => true] + $j;
}
