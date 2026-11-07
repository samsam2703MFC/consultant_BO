<?php
declare(strict_types=1);

/*
 * Le cadre des tâches du panel — l'écran à part /task (demandes du 07/11/2026).
 *
 * Les tâches que les magasins font chaque jour (photos de comptoir, contrôles qualité…) se
 * définissent dans le panel : la tâche (/tasks), sa checklist (/operational-framework/
 * checklists/{id}/tasks), les niveaux de poste qui la portent (/levels/{id}/tasks,
 * /position-levels/{id}/tasks) et ses contrôleurs (/tasks/{id}/controllers). Cet écran les
 * lit, et crée de nouvelles checklists et de nouvelles tâches de deux façons :
 *   - la DUPLICATION rapide : cocher des tâches, changer jours, heure, obligatoire, photo
 *     (« la même photo du comptoir, l'après-midi, samedi et dimanche, à 14:30 ») ;
 *   - l'ASSISTANT, en quatre étapes : la checklist (nouvelle ou existante), ses tâches
 *     (nouvelles ou copiées), qui les fait et qui les contrôle, puis l'aperçu et la création.
 * Les deux passent par le même moteur (tkConstruire).
 *
 * Le document OpenAPI du panel ne décrit ni le corps de POST /tasks ni celui de POST
 * /operational-framework/checklists (« contracts are not inferred »). On part donc de ce que
 * le panel rend : une copie reprend les champs de son modèle et leurs types (1/0 ou vrai/faux,
 * 04:00 ou 04:00:00) ; une tâche nouvelle prend les types d'une tâche existante ; une checklist
 * nouvelle, ceux d'une checklist existante ; les jours et la fréquence se choisissent parmi les
 * valeurs DÉJÀ employées par le panel, jamais devinées.
 *
 * Trois garde-fous, parce que tout ceci écrit dans le panel de production :
 *   1. l'aperçu montre chaque appel et son corps exact, sans rien envoyer ;
 *   2. la création s'arrête au premier refus du panel ; une tâche identique (même nom, même
 *      jour, même heure) n'est jamais recréée — elle est seulement ajoutée à la checklist ;
 *   3. seul ce qui a été créé depuis cet écran (table ceo_cadre_cree) peut y être supprimé.
 *
 * Compte : celui du cockpit (compte consultant, PanelApi) ; ou, si le panel le refuse sur ces
 * routes, un compte administrateur du panel connecté depuis l'écran — son jeton voyage dans
 * l'en-tête X-Panel-Jeton et n'est gardé nulle part côté serveur.
 */

/** Les champs d'une tâche repris du modèle quand il les porte (forme de lecture du panel). */
const TK_CHAMPS_TACHE = ['subcategory_id', 'workstation_id', 'name', 'description', 'day_of_week',
    'execution_time', 'frequency', 'is_mandatory', 'priority', 'requires_photo'];

/** Au-delà, une création se fait en plusieurs fois : le panel encaisse mal les rafales. */
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
    if ($method === 'GET' && $path === '/task/structure') { return tkStructure(); }
    if ($method === 'GET' && preg_match('#^/task/sop/([\w-]{1,32})/proposition$#', $path, $m)) { return tkSopProposition($m[1]); }
    if ($method === 'GET' && preg_match('#^/task/sop/([\w-]{1,32})/controles$#', $path, $m)) { return tkSopControles($m[1]); }
    if ($method === 'GET' && preg_match('#^/task/taches/(\d{1,10})$#', $path, $m)) { return tkTache((int) $m[1]); }
    if ($method === 'POST' && $path === '/task/qui') { return tkQui(body()); }
    if ($method === 'POST' && $path === '/task/apercu') { return tkPlan(body(), false); }
    if ($method === 'POST' && $path === '/task/creer') { return tkPlan(body(), true); }
    if ($method === 'POST' && $path === '/task/assistant/apercu') { return tkConstruire(body(), false, false); }
    if ($method === 'POST' && $path === '/task/assistant/creer') { $b = body(); return tkConstruire($b, true, !empty($b['seulementPremiere'])); }
    if ($method === 'PATCH' && preg_match('#^/task/taches/(\d{1,10})$#', $path, $m)) { return tkModifier((int) $m[1], body()); }
    if ($method === 'DELETE' && preg_match('#^/task/taches/(\d{1,10})$#', $path, $m)) { return tkSupprimer('tache', (int) $m[1]); }
    if ($method === 'DELETE' && preg_match('#^/task/checklists/(\d{1,10})$#', $path, $m)) { return tkSupprimer('checklist', (int) $m[1]); }
    return notFound();
}

/* ─────────── petits outils ─────────── */

/**
 * Une requête au panel qui rend le code et le corps tels quels. `PanelApi::requete()` le fait
 * déjà (module Formation) sous la clé `data` ; l'écran /task lit `corps`.
 *
 * @return array{code:int, corps:mixed, erreur:?string}
 */
function tkReq(string $methode, string $path, ?array $body = null): array
{
    $r = PanelApi::requete($methode, $path, $body);
    return ['code' => (int) $r['code'], 'corps' => $r['data'], 'erreur' => $r['erreur'] === null ? null : $methode . ' ' . $path . ' → ' . $r['erreur']];
}

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
    $r = tkReq('GET', $path);
    if ($r['code'] < 200 || $r['code'] >= 300) { return [null, $r['erreur']]; }
    $corps = is_array($r['corps']) ? $r['corps'] : [];
    $items = PanelApi::liste($corps);
    $meta = is_array($corps['meta'] ?? null) ? $corps['meta'] : (is_array($corps['pagination'] ?? null) ? $corps['pagination'] : $corps);
    $derniere = (int) ($meta['last_page'] ?? $meta['total_pages'] ?? $meta['pages'] ?? 1);
    for ($p = 2; $p <= min($derniere, 30); $p++) {
        $r2 = tkReq('GET', $path . (str_contains($path, '?') ? '&' : '?') . 'page=' . $p);
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

/** Ce que cet écran a créé dans le panel : les seules choses qu'il a le droit d'y supprimer. */
function ensureCadreCree(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_cadre_cree (
        genre VARCHAR(10) NOT NULL,
        panel_id INT NOT NULL,
        nom VARCHAR(190) NOT NULL,
        jour VARCHAR(60) NULL,
        heure VARCHAR(8) NULL,
        checklist_id INT NULL,
        modele_id INT NULL,
        compte VARCHAR(20) NOT NULL DEFAULT \'cockpit\',
        cree_le DATETIME NOT NULL,
        supprime_le DATETIME NULL,
        PRIMARY KEY (genre, panel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function tkTracer(string $genre, int $id, string $nom, string $jour, string $heure, ?int $cid, ?int $modele): void
{
    try {
        ensureCadreCree();
        Db::exec('INSERT INTO ceo_cadre_cree (genre, panel_id, nom, jour, heure, checklist_id, modele_id, compte, cree_le)
                  VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nom = VALUES(nom), supprime_le = NULL',
            [$genre, $id, mb_substr($nom, 0, 190), mb_substr($jour, 0, 60), substr($heure, 0, 8), $cid, $modele,
             PanelApi::$jetonImpose !== null ? 'admin' : 'cockpit', date('Y-m-d H:i:s')]);
    } catch (PDOException $e) { /* le panel a l'objet : la trace locale n'est qu'un confort */ }
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

    $creees = []; $clCreees = [];
    try {
        ensureCadreCree();
        foreach (Db::rows('SELECT * FROM ceo_cadre_cree WHERE supprime_le IS NULL ORDER BY cree_le DESC, panel_id DESC LIMIT 300') as $r) {
            $x = ['id' => (int) $r['panel_id'], 'nom' => (string) $r['nom'], 'jour' => (string) ($r['jour'] ?? ''),
                'heure' => (string) ($r['heure'] ?? ''), 'checklistId' => (int) ($r['checklist_id'] ?? 0),
                'modeleId' => (int) ($r['modele_id'] ?? 0), 'compte' => (string) $r['compte'], 'le' => substr((string) $r['cree_le'], 0, 16)];
            if ($r['genre'] === 'checklist') { $clCreees[] = $x; } else { $creees[] = $x; }
        }
    } catch (PDOException $e) { /* table impossible : les listes restent vides */ }

    // Les tâches reliées à une étape de standard (ceo_sop_tache) : l'écran les étiquette « SOP-… · étape … ».
    $sopLiens = [];
    try {
        ensureSopTache();
        foreach (Db::rows('SELECT l.task_id, l.sop_id, l.etape, s.code, s.titre_fr FROM ceo_sop_tache l LEFT JOIN ceo_sop s ON s.id = l.sop_id') as $l) {
            $sopLiens[(string) $l['task_id']][] = ['sopId' => (string) $l['sop_id'], 'code' => (string) ($l['code'] ?? ''), 'titre' => (string) ($l['titre_fr'] ?? ''), 'etape' => $l['etape']];
        }
    } catch (PDOException $e) { /* sans le module Formation : pas d'étiquette */ }

    return [
        'api' => tkCompte(),
        'sopLiens' => $sopLiens ?: (object) [],
        'checklists' => $checklists,
        'taches' => array_values($taches),
        'horsChecklist' => array_values(array_map(fn ($t) => $t['id'], array_filter($taches, fn ($t) => !isset($dans[$t['id']])))),
        'niveaux' => array_values(array_filter(array_map('tkNiveauNorm', $nivs), fn ($l) => $l['id'] > 0)),
        'options' => ['jours' => tkValeurs($taches, 'day_of_week'), 'frequences' => tkValeurs($taches, 'frequency')],
        'creees' => $creees,
        'checklistsCreees' => $clCreees,
        'erreurs' => $erreurs ?: (object) [],
    ];
}

/**
 * Les niveaux de poste et leurs tâches, sous les deux routes du panel (/levels/{id}/tasks et
 * /position-levels/{id}/tasks) : pour chaque tâche demandée, les niveaux qui la portent ; et,
 * pour toute la base, combien de rattachements chaque route porte — c'est la route la plus
 * employée qui reçoit les rattachements d'une tâche nouvelle.
 *
 * @return array{parTache: array<int, list<array{niveau:int, route:string}>>, familles: array<string,int>}
 */
function tkScanNiveaux(array $taskIds): array
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
    $out = ['parTache' => [], 'familles' => ['levels' => 0, 'position-levels' => 0]];
    foreach ($req ? PanelApi::getParallele($req) : [] as $cle => $rep) {
        [$route, $lid] = explode('|', (string) $cle);
        foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $x) {
            $tid = (int) ($x['task_id'] ?? $x['id'] ?? 0);
            if ($tid <= 0) { continue; }
            $out['familles'][$route]++;
            if (isset($voulu[$tid])) { $out['parTache'][$tid][] = ['niveau' => (int) $lid, 'route' => $route]; }
        }
    }
    return $out;
}

/** @return array<int, list<array{niveau:int, route:string}>> */
function tkNiveauxDe(array $taskIds): array
{
    return tkScanNiveaux($taskIds)['parTache'];
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
    $ctrl = tkReq('GET', '/tasks/' . $id . '/controllers');
    return [
        'id' => $id,
        'tache' => $t !== null ? tkNormTache($t) : null,
        'erreur' => $t === null ? ($e ?? 'tâche absente de GET /tasks') : null,
        'controleurs' => ['code' => $ctrl['code'], 'liste' => PanelApi::liste(is_array($ctrl['corps']) ? $ctrl['corps'] : []),
            'brut' => $ctrl['corps'], 'erreur' => $ctrl['erreur']],
        'niveaux' => tkNiveauxDe([$id])[$id] ?? [],
    ];
}

/**
 * POST /task/qui {ids: [id de tâche]} — pour chaque tâche (80 au plus), qui la fait (les niveaux de
 * poste qui la portent, nommés par /position-levels) et qui la contrôle (GET /tasks/{id}/controllers :
 * « self » = la personne qui la fait, « position » = un poste). Lecture seule : le planning l'affiche.
 *
 * @return array{qui: array<string, array{fait: list<array{id:int, nom:string, poste:string}>, controle: list<array{type:string, nom:string}>, erreur: ?string}>}
 */
function tkQui(array $b): array
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), fn ($i) => $i > 0))), 0, 80);
    if ($ids === []) { return ['qui' => (object) []]; }
    [$nivs] = tkLireListe('/position-levels');
    $noms = [];
    foreach ((array) $nivs as $l) { $n = tkNiveauNorm($l); if ($n['id'] > 0) { $noms[$n['id']] = $n; } }
    $parTache = tkScanNiveaux($ids)['parTache'];
    $req = [];
    foreach ($ids as $id) { $req[$id] = '/tasks/' . $id . '/controllers'; }
    $ctrls = PanelApi::getParallele($req);
    $out = [];
    foreach ($ids as $id) {
        $fait = [];
        foreach ($parTache[$id] ?? [] as $lv) {
            $n = $noms[$lv['niveau']] ?? ['id' => $lv['niveau'], 'nom' => 'Niveau #' . $lv['niveau'], 'poste' => ''];
            $fait[$n['id']] = ['id' => $n['id'], 'nom' => $n['nom'], 'poste' => $n['poste']];
        }
        $rep = $ctrls[$id] ?? null;
        $controle = [];
        foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $c) {
            $type = tkTxt($c['controller_type'] ?? $c['type'] ?? '');
            $nom = $type === 'self' ? 'La personne qui la fait' : tkTxt($c['position_name'] ?? $c['name'] ?? ('#' . ($c['position_id'] ?? '?')));
            $controle[] = ['type' => $type, 'nom' => $nom];
        }
        $out[(string) $id] = ['fait' => array_values($fait), 'controle' => $controle,
            'erreur' => $rep === null ? 'contrôleurs illisibles' : null];
    }
    return ['qui' => $out];
}

/* ─────────── structure (pour l'assistant) ─────────── */

/**
 * GET /task/structure — de quoi remplir l'assistant : les sous-catégories (une tâche nouvelle
 * en demande une), rangées par section et catégorie, et les postes de travail (une checklist
 * nouvelle en porte un). Les sous-catégories viennent de la structure du cadre opérationnel ET
 * des tâches existantes (qui portent leur chemin complet) : si l'une des deux lectures manque,
 * l'autre suffit.
 */
function tkStructure(): array
{
    $erreurs = [];
    [$secs, $e] = tkLireListe('/operational-framework/structure/sections');
    if ($secs === null) { $erreurs['sections'] = $e; $secs = []; }
    [$cats, $e] = tkLireListe('/operational-framework/structure/categories');
    if ($cats === null) { $erreurs['categories'] = $e; $cats = []; }
    [$postes, $e] = tkLireListe('/operational-framework/workstations');
    if ($postes === null) { $erreurs['postes'] = $e; $postes = []; }
    [$brutes] = tkLireListe('/tasks');

    $secNom = [];
    foreach ($secs as $s) { $secNom[(int) ($s['id'] ?? 0)] = tkTxt($s['name'] ?? $s['base_name'] ?? ''); }
    $catInfo = [];
    $req = [];
    foreach ($cats as $c) {
        $id = (int) ($c['id'] ?? 0);
        if ($id <= 0) { continue; }
        $catInfo[$id] = ['nom' => tkTxt($c['name'] ?? $c['base_name'] ?? ''),
            'section' => tkTxt($c['section_name'] ?? $c['base_section_name'] ?? '') ?: ($secNom[(int) ($c['section_id'] ?? 0)] ?? '')];
        $req[$id] = '/operational-framework/structure/categories/' . $id . '/subcategories';
    }
    $sc = [];
    foreach ($req ? PanelApi::getParallele($req) : [] as $cid => $rep) {
        foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $x) {
            $id = (int) ($x['id'] ?? 0);
            if ($id <= 0) { continue; }
            $sc[$id] = ['id' => $id, 'nom' => tkTxt($x['name'] ?? $x['base_name'] ?? ('Sous-catégorie ' . $id)),
                'categorie' => $catInfo[(int) $cid]['nom'] ?? '', 'section' => $catInfo[(int) $cid]['section'] ?? '', 'nb' => 0];
        }
    }
    foreach ((array) $brutes as $t) {
        $id = (int) ($t['subcategory_id'] ?? 0);
        if ($id <= 0) { continue; }
        if (!isset($sc[$id])) {
            $n = tkNormTache($t);
            $sc[$id] = ['id' => $id, 'nom' => $n['sousCategorie'] !== '' ? $n['sousCategorie'] : ('Sous-catégorie ' . $id),
                'categorie' => $n['categorie'], 'section' => $n['section'], 'nb' => 0];
        }
        $sc[$id]['nb']++;
    }
    $sc = array_values($sc);
    usort($sc, fn ($a, $b) => [$a['section'], $a['categorie'], $a['nom']] <=> [$b['section'], $b['categorie'], $b['nom']]);
    $ps = [];
    foreach ($postes as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id > 0) { $ps[] = ['id' => $id, 'nom' => tkTxt($p['name'] ?? $p['base_name'] ?? ('Poste ' . $id))]; }
    }
    usort($ps, fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    return ['sousCategories' => $sc, 'postes' => $ps, 'erreurs' => $erreurs ?: (object) []];
}

/* ─────────── duplication rapide ─────────── */

/**
 * POST /task/apercu et POST /task/creer — dupliquer des tâches (le volet « Dupliquer »).
 *
 * Corps : { modeles: [taskId…], checklistId, nom: {remplacer, par, ajouter},
 *           jours: [valeur…], unParJour, heure: "HH:MM", frequence, obligatoire, photo,
 *           niveaux: bool, controleurs: bool, extra: {…}, seulementPremiere: bool }
 * Un champ absent ou null garde la valeur du modèle. Traduit en lignes pour tkConstruire.
 */
function tkPlan(array $b, bool $executer): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['modeles'] ?? [])), fn ($i) => $i > 0)));
    if ($ids === []) { http_response_code(422); return ['error' => 'choisissez au moins une tâche modèle']; }
    [$brutes, $e] = tkLireListe('/tasks');
    if ($brutes === null) { http_response_code(502); return ['error' => 'lecture des tâches du panel impossible : ' . $e]; }
    $parId = [];
    foreach ($brutes as $t) { $parId[(int) ($t['id'] ?? $t['task_id'] ?? 0)] = $t; }
    $manquent = array_values(array_filter($ids, fn ($i) => !isset($parId[$i])));
    if ($manquent) { http_response_code(422); return ['error' => 'tâche(s) absente(s) du panel : #' . implode(', #', $manquent)]; }

    $nom = is_array($b['nom'] ?? null) ? $b['nom'] : [];
    $remplacer = (string) ($nom['remplacer'] ?? '');
    $par = (string) ($nom['par'] ?? '');
    $ajouter = (string) ($nom['ajouter'] ?? '');
    $avert = [];
    $lignes = [];
    foreach ($ids as $mid) {
        $n = tkNormTache($parId[$mid])['nom'];
        $nouveau = $n;
        if ($remplacer !== '') {
            if (str_contains($nouveau, $remplacer)) { $nouveau = str_replace($remplacer, $par, $nouveau); }
            else { $avert[] = '« ' . $remplacer . ' » n\'apparaît pas dans « ' . $n . ' » : nom gardé'; }
        }
        $lignes[] = ['modeleId' => $mid, 'nom' => trim($nouveau . $ajouter), 'jours' => $b['jours'] ?? [],
            'unParJour' => $b['unParJour'] ?? true, 'heure' => $b['heure'] ?? '', 'frequence' => $b['frequence'] ?? null,
            'obligatoire' => $b['obligatoire'] ?? null, 'photo' => $b['photo'] ?? null,
            'garderNiveaux' => !array_key_exists('niveaux', $b) || !empty($b['niveaux']),
            'garderControleurs' => !array_key_exists('controleurs', $b) || !empty($b['controleurs'])];
    }
    $cid = (int) ($b['checklistId'] ?? 0);
    $spec = ['checklist' => $cid > 0 ? ['mode' => 'existante', 'id' => $cid] : ['mode' => 'aucune'],
        'lignes' => $lignes, 'extra' => is_array($b['extra'] ?? null) ? $b['extra'] : [], 'avertissements' => $avert];
    return tkConstruire($spec, $executer, !empty($b['seulementPremiere']), $parId);
}

/* ─────────── le moteur : assistant et duplication ─────────── */

/** Une tâche qui porte les champs typés, pour donner leur forme à une tâche nouvelle. */
function tkReference(array $parId): ?array
{
    foreach ($parId as $t) {
        if (array_key_exists('is_mandatory', $t) && array_key_exists('requires_photo', $t) && array_key_exists('execution_time', $t)) { return $t; }
    }
    return $parId ? reset($parId) : null;
}

/**
 * Le corps de POST /tasks pour une ligne. Une copie part des champs de son modèle ; une tâche
 * nouvelle ne porte que ce que la ligne dit, avec les types d'une tâche existante.
 */
function tkCorpsLigne(array $l, ?array $modele, ?array $ref, ?string $jour): array
{
    $base = $modele ?? $ref ?? [];
    $c = [];
    if ($modele !== null) {
        foreach (TK_CHAMPS_TACHE as $k) { if (array_key_exists($k, $modele)) { $c[$k] = $modele[$k]; } }
        if (!array_key_exists('description', $c) && array_key_exists('base_description', $modele)) { $c['description'] = $modele['base_description']; }
    }
    $c['name'] = $l['nom'];
    if ($l['description'] !== null) {
        if ($l['description'] !== '' || $modele !== null) { $c['description'] = $l['description']; }
    }
    if ($l['sousCategorieId'] > 0) { $c['subcategory_id'] = $l['sousCategorieId']; }
    if ($jour !== null) { $c['day_of_week'] = $jour; }
    if ($l['heure'] !== '') { $c['execution_time'] = tkHeure($l['heure'], $base['execution_time'] ?? null); }
    if ($l['frequence'] !== null) { $c['frequency'] = $l['frequence'] === '' ? null : $l['frequence']; }
    if ($l['obligatoire'] !== null) { $c['is_mandatory'] = tkMemeType($base['is_mandatory'] ?? null, $l['obligatoire']); }
    if ($l['photo'] !== null) { $c['requires_photo'] = tkMemeType($base['requires_photo'] ?? null, $l['photo']); }
    if ($l['priorite'] !== null) { $c['priority'] = $l['priorite']; }
    return $c;
}

/** Une ligne de l'assistant, lue et vérifiée. Rend [ligne, erreur]. */
function tkLigne(array $l, int $n): array
{
    $nom = trim((string) ($l['nom'] ?? ''));
    $quoi = 'ligne ' . $n . ($nom !== '' ? ' (« ' . $nom . ' »)' : '');
    if ($nom === '') { return [null, $quoi . ' : le nom manque']; }
    $heure = trim((string) ($l['heure'] ?? ''));
    if ($heure !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) { return [null, $quoi . ' : heure attendue au format HH:MM']; }
    $opt = static fn (string $k) => array_key_exists($k, $l) && $l[$k] !== null ? (bool) $l[$k] : null;
    return [[
        'modeleId' => (int) ($l['modeleId'] ?? 0),
        'nom' => $nom,
        'description' => array_key_exists('description', $l) && $l['description'] !== null ? trim((string) $l['description']) : null,
        'sousCategorieId' => (int) ($l['sousCategorieId'] ?? 0),
        'jours' => array_values(array_unique(array_filter(array_map(fn ($v) => tkTxt($v), (array) ($l['jours'] ?? [])), fn ($v) => $v !== ''))),
        'unParJour' => !array_key_exists('unParJour', $l) || !empty($l['unParJour']),
        'heure' => $heure,
        'frequence' => array_key_exists('frequence', $l) && $l['frequence'] !== null ? tkTxt($l['frequence']) : null,
        'obligatoire' => $opt('obligatoire'),
        'photo' => $opt('photo'),
        'priorite' => isset($l['priorite']) && is_numeric($l['priorite']) ? (int) $l['priorite'] : null,
        'garderNiveaux' => !array_key_exists('garderNiveaux', $l) || !empty($l['garderNiveaux']),
        'garderControleurs' => !array_key_exists('garderControleurs', $l) || !empty($l['garderControleurs']),
        // L'étape de SOP que la tâche contrôle (assistant « depuis un standard ») : gardée dans ceo_sop_tache.
        'sop' => is_array($l['sop'] ?? null) && preg_match('/^[\w-]{1,32}$/', (string) ($l['sop']['id'] ?? '')) === 1
            ? ['id' => (string) $l['sop']['id'], 'etape' => mb_substr(trim((string) ($l['sop']['etape'] ?? '')), 0, 20) ?: null] : null,
    ], null];
}

/**
 * POST /task/assistant/apercu et …/creer — la checklist et ses tâches, en un seul plan.
 *
 * Corps :
 *   checklist : {mode: "nouvelle", nom, description, posteId, heure, modeleId, extra}
 *             | {mode: "existante", id} | {mode: "aucune"}
 *   lignes    : [{modeleId?, nom, description?, sousCategorieId?, jours[], unParJour, heure,
 *                 frequence, obligatoire, photo, priorite, garderNiveaux, garderControleurs}]
 *   niveaux   : [id de niveau de poste] — les tâches créées y sont rattachées
 *   routeNiveaux : "levels" | "position-levels" (sinon celle que le panel emploie le plus)
 *   controleursDe : id d'une tâche dont on recopie les contrôleurs
 *   extra     : {…} ajouté tel quel au corps de chaque tâche créée
 *   seulementPremiere : la checklist et la première tâche seulement (essai)
 *
 * Une ligne avec modeleId est une copie : ce qu'elle ne dit pas vient du modèle, et elle garde
 * (si demandé) les niveaux et contrôleurs du modèle. Une ligne identique à une tâche existante
 * (même nom, jour, heure) ne recrée rien : la tâche existante est ajoutée à la checklist.
 */
function tkConstruire(array $spec, bool $executer, bool $seulementPremiere, ?array $parId = null): array
{
    $brutes = null;
    if ($parId === null) {
        [$brutes, $e] = tkLireListe('/tasks');
        if ($brutes === null) { http_response_code(502); return ['error' => 'lecture des tâches du panel impossible : ' . $e]; }
        $parId = [];
        foreach ($brutes as $t) { $parId[(int) ($t['id'] ?? $t['task_id'] ?? 0)] = $t; }
    }
    $lignes = [];
    foreach (array_values(array_filter((array) ($spec['lignes'] ?? []), 'is_array')) as $i => $l) {
        [$ok, $err] = tkLigne($l, $i + 1);
        if ($ok === null) { http_response_code(422); return ['error' => $err]; }
        if ($ok['modeleId'] > 0 && !isset($parId[$ok['modeleId']])) { http_response_code(422); return ['error' => 'ligne ' . ($i + 1) . ' : la tâche modèle #' . $ok['modeleId'] . ' est absente du panel']; }
        if ($ok['modeleId'] <= 0 && $ok['sousCategorieId'] <= 0) {
            http_response_code(422); return ['error' => 'ligne ' . ($i + 1) . ' (« ' . $ok['nom'] . ' ») : choisissez la sous-catégorie de la tâche'];
        }
        $lignes[] = $ok;
    }
    if ($lignes === []) { http_response_code(422); return ['error' => 'ajoutez au moins une tâche']; }
    $ref = tkReference($parId);
    $extra = is_array($spec['extra'] ?? null) ? $spec['extra'] : [];
    $avert = array_values((array) ($spec['avertissements'] ?? []));

    // --- la checklist
    $c = is_array($spec['checklist'] ?? null) ? $spec['checklist'] : ['mode' => 'aucune'];
    $mode = in_array($c['mode'] ?? '', ['nouvelle', 'existante'], true) ? $c['mode'] : 'aucune';
    $clOut = ['mode' => $mode, 'id' => null, 'nom' => null, 'operation' => null];
    $dansCl = [];
    $ordre = 0;
    if ($mode !== 'aucune') {
        [$cls, $eC] = tkLireListe('/operational-framework/checklists');
        if ($cls === null) { http_response_code(502); return ['error' => 'lecture des checklists impossible : ' . $eC]; }
        if ($mode === 'nouvelle') {
            $nomCl = trim((string) ($c['nom'] ?? ''));
            if ($nomCl === '') { http_response_code(422); return ['error' => 'donnez un nom à la nouvelle checklist']; }
            foreach ($cls as $x) {
                if (mb_strtolower(tkTxt($x['name'] ?? '')) === mb_strtolower($nomCl)) {
                    $mode = 'existante'; $c['id'] = (int) ($x['id'] ?? 0);
                    $avert[] = 'Une checklist s\'appelle déjà « ' . $nomCl . ' » (#' . $c['id'] . ') : les tâches y seront ajoutées, elle n\'est pas recréée.';
                    break;
                }
            }
        }
        if ($mode === 'nouvelle') {
            $heureCl = trim((string) ($c['heure'] ?? ''));
            if ($heureCl !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heureCl)) { http_response_code(422); return ['error' => 'heure de la checklist attendue au format HH:MM']; }
            // La forme d'une checklist existante : ses champs, leurs types.
            $modCl = null;
            foreach ($cls as $x) { if ((int) ($x['id'] ?? 0) === (int) ($c['modeleId'] ?? 0)) { $modCl = $x; } }
            $modCl = $modCl ?? ($cls ? reset($cls) : []);
            $corps = ['name' => $nomCl];
            $desc = trim((string) ($c['description'] ?? ''));
            if ($desc !== '' || array_key_exists('description', $modCl)) { $corps['description'] = $desc; }
            $poste = (int) ($c['posteId'] ?? 0);
            if ($poste > 0 || array_key_exists('workstation_id', $modCl)) { $corps['workstation_id'] = $poste > 0 ? $poste : null; }
            if ($heureCl !== '' || array_key_exists('execution_time', $modCl)) { $corps['execution_time'] = $heureCl !== '' ? tkHeure($heureCl, $modCl['execution_time'] ?? null) : null; }
            if (array_key_exists('is_active', $modCl)) { $corps['is_active'] = tkMemeType($modCl['is_active'], true); }
            foreach ((is_array($c['extra'] ?? null) ? $c['extra'] : []) as $k => $v) { $corps[(string) $k] = $v; }
            $clOut = ['mode' => 'nouvelle', 'id' => null, 'nom' => $nomCl,
                'operation' => ['quoi' => 'Créer la checklist « ' . $nomCl . ' »', 'methode' => 'POST', 'chemin' => '/operational-framework/checklists', 'corps' => $corps]];
            $cidRef = '{checklist}';
        } else {
            $cid = (int) ($c['id'] ?? 0);
            $nomCl = null;
            foreach ($cls as $x) { if ((int) ($x['id'] ?? 0) === $cid) { $nomCl = tkTxt($x['name'] ?? ''); } }
            if ($cid <= 0 || $nomCl === null) { http_response_code(422); return ['error' => 'checklist #' . $cid . ' introuvable dans le panel']; }
            [$lus, $eL] = tkLireListe('/operational-framework/checklists/' . $cid . '/tasks');
            if ($lus === null) { http_response_code(502); return ['error' => 'lecture de la checklist #' . $cid . ' impossible : ' . $eL]; }
            foreach ($lus as $i => $x) {
                $ordre = max($ordre, (int) ($x['sort_order'] ?? $i + 1));
                $dansCl[(int) ($x['task_id'] ?? $x['id'] ?? 0)] = true;
            }
            $clOut = ['mode' => 'existante', 'id' => $cid, 'nom' => $nomCl, 'operation' => null];
            $cidRef = (string) $cid;
        }
    }
    $nomClAff = $clOut['nom'] ?? '';

    // --- niveaux de poste et contrôleurs
    $modeles = array_values(array_unique(array_filter(array_map(fn ($l) => $l['modeleId'], $lignes))));
    $niveauxSpec = array_values(array_unique(array_filter(array_map('intval', (array) ($spec['niveaux'] ?? [])), fn ($i) => $i > 0)));
    $avecGarde = array_filter($lignes, fn ($l) => $l['modeleId'] > 0 && $l['garderNiveaux']);
    $scan = ($niveauxSpec || $avecGarde) ? tkScanNiveaux($modeles) : ['parTache' => [], 'familles' => []];
    $route = in_array($spec['routeNiveaux'] ?? '', ['levels', 'position-levels'], true) ? $spec['routeNiveaux']
        : ((($scan['familles']['position-levels'] ?? 0) > ($scan['familles']['levels'] ?? 0)) ? 'position-levels' : 'levels');
    $ctrlDe = (int) ($spec['controleursDe'] ?? 0);
    $aLire = array_values(array_unique(array_merge($ctrlDe > 0 ? [$ctrlDe] : [],
        array_map(fn ($l) => $l['modeleId'], array_filter($lignes, fn ($l) => $l['modeleId'] > 0 && $l['garderControleurs'])))));
    $ctrls = [];
    if ($aLire) {
        $req = [];
        foreach ($aLire as $i) { $req[$i] = '/tasks/' . $i . '/controllers'; }
        foreach (PanelApi::getParallele($req) as $i => $rep) { $ctrls[(int) $i] = PanelApi::liste(is_array($rep) ? $rep : []); }
    }

    // --- les tâches qui existent déjà, par nom + jour + heure
    $existe = [];
    foreach ($parId as $tid => $t) {
        $n = tkNormTache($t);
        $existe[mb_strtolower($n['nom']) . '|' . $n['jour'] . '|' . $n['heure']] = $tid;
    }

    $items = [];
    $vus = [];
    foreach ($lignes as $l) {
        $m = $l['modeleId'] > 0 ? $parId[$l['modeleId']] : null;
        $variantes = $l['jours'] === [] ? [null] : ($l['unParJour'] ? $l['jours'] : [implode(',', $l['jours'])]);
        if ($m !== null && $l['jours'] !== [] && $l['frequence'] === null && tkTxt($m['day_of_week'] ?? '') === '') {
            $avert[] = '« ' . $l['nom'] . ' » : le modèle n\'a pas de jour (fréquence « ' . tkTxt($m['frequency'] ?? '') . ' ») et la fréquence n\'est pas changée'
                . tkFrequencesAJour($parId);
        }
        foreach ($variantes as $jour) {
            $corps = tkCorpsLigne($l, $m, $ref, $jour);
            foreach ($extra as $k => $v) { $corps[(string) $k] = $v; }
            $jourAff = tkTxt($corps['day_of_week'] ?? ($jour ?? ''));
            $heureAff = substr(tkTxt($corps['execution_time'] ?? ''), 0, 5);
            $cle = mb_strtolower($l['nom']) . '|' . $jourAff . '|' . $heureAff;
            $item = ['sop' => $l['sop'] ?? null, 'modeleId' => $l['modeleId'] ?: null, 'modeleNom' => $m !== null ? tkNormTache($m)['nom'] : null,
                'nom' => $l['nom'], 'jour' => $jourAff, 'heure' => $heureAff, 'existe' => null];
            if (isset($vus[$cle])) {
                $items[] = $item + ['mode' => 'doublon', 'operations' => []];
                $avert[] = '« ' . $l['nom'] . ' » (' . ($jourAff ?: 'sans jour') . ', ' . ($heureAff ?: 'sans heure') . ') figure deux fois : la seconde est ignorée.';
                continue;
            }
            $vus[$cle] = true;
            $deja = $existe[$cle] ?? null;
            if ($deja !== null) {
                $item['existe'] = $deja;
                if ($mode !== 'aucune' && !isset($dansCl[$deja])) {
                    $ordre++;
                    $items[] = $item + ['mode' => 'ajouter', 'operations' => [[
                        'quoi' => 'Ajouter la tâche existante #' . $deja . ' à la checklist « ' . $nomClAff . ' »', 'methode' => 'POST',
                        'chemin' => '/operational-framework/checklists/' . $cidRef . '/tasks', 'corps' => ['task_id' => $deja, 'sort_order' => $ordre]]]];
                    $dansCl[$deja] = true;
                } else {
                    $items[] = $item + ['mode' => 'rien', 'operations' => []];
                }
                continue;
            }
            $ops = [['quoi' => 'Créer la tâche', 'methode' => 'POST', 'chemin' => '/tasks', 'corps' => $corps]];
            if ($mode !== 'aucune') {
                $ordre++;
                $ops[] = ['quoi' => 'Ajouter à la checklist « ' . $nomClAff . ' »', 'methode' => 'POST',
                    'chemin' => '/operational-framework/checklists/' . $cidRef . '/tasks', 'corps' => ['task_id' => '{nouvelle}', 'sort_order' => $ordre]];
            }
            // Un niveau, un rattachement : /levels/{id}/tasks et /position-levels/{id}/tasks rendent la
            // même liste (mesuré le 07/11/2026 sur le niveau 27) — rattacher par les deux doublerait le lien.
            $nivs = [];
            foreach ($niveauxSpec as $lid) { $nivs[$lid] = ['niveau' => $lid, 'route' => $route]; }
            if ($m !== null && $l['garderNiveaux']) {
                foreach ($scan['parTache'][$l['modeleId']] ?? [] as $lv) { $nivs[$lv['niveau']] ??= ['niveau' => $lv['niveau'], 'route' => $route]; }
            }
            foreach ($nivs as $lv) {
                $ops[] = ['quoi' => 'Rattacher au niveau de poste #' . $lv['niveau'], 'methode' => 'POST',
                    'chemin' => '/' . $lv['route'] . '/' . $lv['niveau'] . '/tasks', 'corps' => ['task_id' => '{nouvelle}']];
            }
            $cs = [];
            foreach (array_merge($ctrlDe > 0 ? ($ctrls[$ctrlDe] ?? []) : [], ($m !== null && $l['garderControleurs']) ? ($ctrls[$l['modeleId']] ?? []) : []) as $x) {
                $cc = tkCorpsControleur($x);
                $cs[json_encode($cc)] = $cc;
            }
            foreach ($cs as $cc) {
                $ops[] = ['quoi' => 'Ajouter un contrôleur', 'methode' => 'POST', 'chemin' => '/tasks/{nouvelle}/controllers', 'corps' => $cc];
            }
            $items[] = $item + ['mode' => 'creer', 'sansNiveau' => $nivs === [], 'operations' => $ops];
        }
    }
    $aCreer = count(array_filter($items, fn ($i) => $i['mode'] === 'creer'));
    $aAjouter = count(array_filter($items, fn ($i) => $i['mode'] === 'ajouter'));
    if ($aCreer > TK_MAX_CREATIONS) {
        http_response_code(422);
        return ['error' => $aCreer . ' tâches à créer : au-delà de ' . TK_MAX_CREATIONS . ', faites-le en plusieurs fois'];
    }
    $sansNiveau = count(array_filter($items, fn ($i) => !empty($i['sansNiveau'])));
    if ($sansNiveau) {
        $avert[] = $sansNiveau . ' tâche(s) créée(s) sans niveau de poste : aucun employé ne les verra tant qu\'elles n\'y sont pas rattachées.';
    }
    if ($aCreer > 0 && $mode === 'aucune') {
        $avert[] = 'Les tâches ne seront dans aucune checklist.';
    }

    $out = ['api' => tkCompte(), 'sopId' => is_string($spec['sopId'] ?? null) && preg_match('/^[\w-]{1,32}$/', $spec['sopId']) === 1 ? $spec['sopId'] : null,
        'checklist' => $clOut, 'routeNiveaux' => $route, 'items' => $items,
        'aCreer' => $aCreer, 'aAjouter' => $aAjouter, 'avertissements' => array_values(array_unique($avert))];
    if (!$executer) { return $out; }
    return tkExecuter($out, $parId, $seulementPremiere);
}

/** Les fréquences des tâches à jour fixe du panel, pour l'avertissement « modèle sans jour ». */
function tkFrequencesAJour(array $parId): string
{
    $fj = [];
    foreach ($parId as $t) {
        if (tkTxt($t['day_of_week'] ?? '') !== '') { $f = tkTxt($t['frequency'] ?? ''); $fj[$f] = ($fj[$f] ?? 0) + 1; }
    }
    if (!$fj) { return ''; }
    arsort($fj);
    return ' : les tâches à jour fixe du panel emploient ' . implode(', ', array_map(fn ($f, $n) => '« ' . ($f === '' ? '(vide)' : $f) . ' » (' . $n . ')', array_keys($fj), $fj));
}

/** Remplace {nouvelle} et {checklist} par les identifiants obtenus, partout dans une opération. */
function tkSubstituer(mixed $v, array $ids): mixed
{
    if (is_string($v)) {
        if (isset($ids[$v])) { return $ids[$v]; }
        return strtr($v, array_map('strval', $ids));
    }
    if (is_array($v)) { foreach ($v as $k => $x) { $v[$k] = tkSubstituer($x, $ids); } }
    return $v;
}

/** L'identifiant rendu par une création, quelle que soit l'enveloppe. */
function tkIdRendu(mixed $corps): int
{
    if (!is_array($corps)) { return 0; }
    foreach ([$corps['id'] ?? null, $corps['task_id'] ?? null, $corps['checklist_id'] ?? null, $corps['inserted_id'] ?? null,
              $corps['data']['id'] ?? null, $corps['data']['task_id'] ?? null, $corps['data']['checklist_id'] ?? null,
              $corps['data']['inserted_id'] ?? null, $corps['data']['task']['id'] ?? null, $corps['data']['checklist']['id'] ?? null,
              $corps['task']['id'] ?? null, $corps['checklist']['id'] ?? null] as $c) {
        if (is_numeric($c) && (int) $c > 0) { return (int) $c; }
    }
    return 0;
}

/** Joue une opération (après substitution) et y range la réponse du panel. */
function tkJouer(array $op, array $ids): array
{
    $op = tkSubstituer($op, $ids);
    $r = tkReq($op['methode'], $op['chemin'], $op['corps']);
    return $op + ['ok' => $r['erreur'] === null, 'code' => $r['code'], 'reponse' => $r['corps'], 'erreur' => $r['erreur']];
}

function tkExecuter(array $plan, array $avant, bool $seulementPremiere): array
{
    $ids = [];
    $arret = null;
    $plan['creees'] = 0;
    $plan['ajoutees'] = 0;

    // 1. La checklist nouvelle, d'abord : sans elle, rien ne se range.
    $opCl = $plan['checklist']['operation'] ?? null;
    if ($opCl !== null) {
        $connus = [];
        [$cls] = tkLireListe('/operational-framework/checklists');
        foreach ((array) $cls as $x) { $connus[(int) ($x['id'] ?? 0)] = true; }
        $fait = tkJouer($opCl, []);
        $cid = $fait['ok'] ? tkIdRendu($fait['reponse']) : 0;
        if ($fait['ok'] && $cid <= 0) {
            [$apres] = tkLireListe('/operational-framework/checklists');
            foreach ((array) $apres as $x) {
                $xid = (int) ($x['id'] ?? 0);
                if ($xid > 0 && !isset($connus[$xid]) && mb_strtolower(tkTxt($x['name'] ?? '')) === mb_strtolower((string) $plan['checklist']['nom'])) { $cid = $xid; }
            }
        }
        $plan['checklist']['operation'] = $fait;
        if (!$fait['ok']) {
            $arret = 'Le panel a refusé la création de la checklist : ' . $fait['erreur'] . '. Rien d\'autre n\'a été tenté.';
        } elseif ($cid <= 0) {
            $arret = 'La checklist semble créée, mais le panel n\'a pas rendu son identifiant : aucune tâche n\'a été créée. Vérifiez dans le panel avant de recommencer.';
        } else {
            $ids['{checklist}'] = $cid;
            $plan['checklist']['id'] = $cid;
            tkTracer('checklist', $cid, (string) $plan['checklist']['nom'], '', '', null, null);
            // Une checklist née d'un standard : son code entre dans le classement de la SOP (Formation › la fiche).
            if (!empty($plan['sopId'])) { tkSopAjouterCode((string) $plan['sopId'], tkCodeDe((string) $plan['checklist']['nom'])); }
            journalAdd('CEO', 'Cadre des tâches', null, 'Checklist « ' . $plan['checklist']['nom'] . ' » créée au panel (#' . $cid . ')');
        }
    }
    $cidFinal = $ids['{checklist}'] ?? ($plan['checklist']['id'] ?? null);

    // 2. Les tâches, dans l'ordre.
    $connus = array_fill_keys(array_keys($avant), true);
    $faites = 0;
    foreach ($plan['items'] as $n => $item) {
        if ($arret !== null) { $plan['items'][$n]['statut'] = in_array($item['mode'], ['rien', 'doublon'], true) ? ($item['mode'] === 'rien' ? 'existe déjà' : 'doublon ignoré') : 'non tenté'; continue; }
        if ($item['mode'] === 'rien') {
            $plan['items'][$n]['statut'] = 'existe déjà';
            if (!empty($item['sop']) && $item['existe']) { tkLierSop((int) $item['existe'], $item['sop']['id'], $item['sop']['etape']); }
            continue;
        }
        if ($item['mode'] === 'doublon') { $plan['items'][$n]['statut'] = 'doublon ignoré'; continue; }
        if ($seulementPremiere && $faites >= 1) { $plan['items'][$n]['statut'] = 'non tenté (essai)'; continue; }
        $faites++;

        if ($item['mode'] === 'ajouter') {
            $op = tkJouer($item['operations'][0], $ids);
            $plan['items'][$n]['operations'] = [$op];
            $plan['items'][$n]['statut'] = $op['ok'] ? 'ajoutée à la checklist' : 'ajout refusé';
            if ($op['ok']) {
                $plan['ajoutees']++;
                if (!empty($item['sop']) && $item['existe']) { tkLierSop((int) $item['existe'], $item['sop']['id'], $item['sop']['etape']); }
            }
            continue;
        }

        $ops = $item['operations'];
        $ops[0] = tkJouer($ops[0], $ids);
        if (!$ops[0]['ok']) {
            $plan['items'][$n]['operations'] = $ops;
            $plan['items'][$n]['statut'] = 'refusée';
            $arret = 'Le panel a refusé la création de « ' . $item['nom'] . ' » : ' . $ops[0]['erreur'] . '. Rien d\'autre n\'a été tenté.';
            continue;
        }
        $id = tkIdRendu($ops[0]['reponse']);
        if ($id <= 0) {
            // La réponse ne dit pas l'identifiant : la seule tâche nouvelle qui porte ce nom.
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
        $ids['{nouvelle}'] = $id;
        for ($k = 1; $k < count($ops); $k++) { $ops[$k] = tkJouer($ops[$k], $ids); }
        unset($ids['{nouvelle}']);
        $ratees = count(array_filter($ops, fn ($o) => empty($o['ok'])));
        $plan['items'][$n]['operations'] = $ops;
        $plan['items'][$n]['id'] = $id;
        $plan['items'][$n]['statut'] = $ratees === 0 ? 'créée' : 'créée, ' . $ratees . ' étape(s) refusée(s)';
        $plan['creees']++;
        tkTracer('tache', $id, $item['nom'], $item['jour'], $item['heure'], $cidFinal !== null ? (int) $cidFinal : null, $item['modeleId']);
        if (!empty($item['sop'])) { tkLierSop($id, $item['sop']['id'], $item['sop']['etape']); }
        journalAdd('CEO', 'Cadre des tâches', null, 'Tâche « ' . $item['nom'] . ' » créée au panel (#' . $id . ')'
            . ($item['jour'] !== '' ? ', jour ' . $item['jour'] : '') . ($item['heure'] !== '' ? ', ' . $item['heure'] : '')
            . ($item['modeleId'] ? ', copie de #' . $item['modeleId'] : '') . ($ratees ? ' — ' . $ratees . ' étape(s) refusée(s)' : ''));
    }
    $plan['arret'] = $arret;
    return $plan;
}

/* ─────────── les standards (SOP) : checklist depuis un standard, contrôles d'un standard ─────────── */

/*
 * Le lien entre une tâche du panel et l'étape de SOP qu'elle contrôle (07/11/2026, propositions A et D).
 * Le panel n'a aucun champ pour le porter : il vit ici, dans ceo_sop_tache. Une checklist se relie à un
 * standard par son CODE en tête de nom (« CP-07 — Audit visuel intérieur ») : les codes du classement de
 * la SOP (Manuel du réseau, référentiel v25) et le code de la SOP elle-même.
 */
function ensureSopTache(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_sop_tache (
        task_id INT NOT NULL,
        sop_id VARCHAR(32) NOT NULL,
        etape VARCHAR(20) NULL,
        cree_le DATETIME NOT NULL,
        PRIMARY KEY (task_id, sop_id),
        KEY k_sop (sop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function tkLierSop(int $taskId, string $sopId, ?string $etape): void
{
    if ($taskId <= 0) { return; }
    try {
        ensureSopTache();
        Db::exec('INSERT INTO ceo_sop_tache (task_id, sop_id, etape, cree_le) VALUES (?,?,?,?)
                  ON DUPLICATE KEY UPDATE etape = COALESCE(VALUES(etape), etape)', [$taskId, $sopId, $etape, date('Y-m-d H:i:s')]);
    } catch (PDOException $e) { /* le panel a la tâche : le lien n'est qu'un confort */ }
}

/** Le code en tête d'un nom de checklist : « CP-07 — Audit visuel intérieur » → CP-07, « SOP-WKL-001 — … » → SOP-WKL-001. */
function tkCodeDe(string $nom): ?string
{
    // « CP-07 », « CQ-F1 », mais aussi les codes des SOP en trois parties : « SOP-WKL-001 ».
    return preg_match('/^\s*([A-Z]{2,5}(?:-[A-Z0-9]{1,6}){1,3})(?![\w-])/u', $nom, $m) === 1 ? $m[1] : null;
}

/** Un texte {fr, nl} en français (repli sur le néerlandais), ou un texte simple. */
function tkTxtL(mixed $v): string
{
    if (is_array($v)) { $f = trim((string) ($v['fr'] ?? '')); return $f !== '' ? $f : trim((string) ($v['nl'] ?? '')); }
    return trim((string) $v);
}

/** La première heure citée dans un texte (« Phase 1 … (06:00 – 07:00) » → 06:00). */
function tkPremiereHeure(string $s): ?string
{
    return preg_match('/\b([01]?\d|2[0-3])[:hH]([0-5]\d)\b/', $s, $m) === 1 ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : null;
}

/** Un nom de tâche tiré d'un texte : sa première phrase, coupée net. */
function tkResume(string $t, int $max = 90): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    if (preg_match('/^(.{12,}?[.!?:;])\s/u', $t . ' ', $m) === 1 && mb_strlen($m[1]) <= $max) { return rtrim($m[1], '.:;'); }
    return mb_strlen($t) <= $max ? $t : rtrim(mb_substr($t, 0, $max - 1)) . '…';
}

/** Une SOP du module Formation : sa ligne, son contenu en vigueur, son classement. Null si elle n'existe pas. */
function tkSop(string $id): ?array
{
    if (!function_exists('foSopRow') || !function_exists('foContenuVersion')) { return null; }
    foreach (['ensureSop', 'ensureReferentiel'] as $f) { if (function_exists($f)) { try { $f(); } catch (Throwable $e) { /* le module répond sans */ } } }
    $r = foSopRow($id);
    if ($r === null) { return null; }
    $cl = function_exists('rfClassementDe') ? rfClassementDe($r) : ['checklists' => []];
    $codes = array_values(array_unique(array_filter(array_merge((array) ($cl['checklists'] ?? []), [(string) ($r['code'] ?? '')]))));
    return ['row' => $r, 'contenu' => foContenuVersion((string) $r['id'], (string) $r['version']), 'classement' => $cl, 'codes' => $codes];
}

function tkSopResume(array $S): array
{
    $r = $S['row'];
    return ['id' => (string) $r['id'], 'code' => (string) ($r['code'] ?? ''), 'titre' => (string) $r['titre_fr'], 'poste' => (string) $r['poste'],
        'moment' => (string) ($r['moment'] ?? ''), 'objectif' => (string) ($r['objectif_fr'] ?? ''), 'version' => (string) $r['version'],
        'codesChecklists' => $S['codes']];
}

/** Ajoute un code de checklist au classement d'une SOP (sans doublon, sans effacer). */
function tkSopAjouterCode(string $sopId, ?string $code): void
{
    if ($code === null) { return; }
    try {
        $r = Db::row('SELECT checklists, code FROM ceo_sop WHERE id = ?', [$sopId]);
        if ($r === null || (string) ($r['code'] ?? '') === $code) { return; }
        $l = function_exists('rfJsonListe') ? rfJsonListe($r['checklists'] ?? null) : [];
        if (in_array($code, $l, true)) { return; }
        $l[] = $code;
        Db::exec('UPDATE ceo_sop SET checklists = ?, maj_le = ?, maj_par = ? WHERE id = ?',
            [json_encode($l, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), 'Cadre des tâches', $sopId]);
    } catch (PDOException $e) { /* colonne absente : le classement reste celui d'avant */ }
}

/**
 * GET /task/sop/{id}/proposition — proposition A : la checklist d'un standard.
 *
 * Une tâche de contrôle par étape CRITIQUE de la SOP (repli : ses points critiques) : le titre de l'étape
 * pour nom, son texte et la référence « Standard …, étape … » pour description, photo requise quand
 * l'étape a une photo de référence, l'heure de sa phase (« (06:00 – 07:00) »). La checklist est celle du
 * panel dont le code est au classement de la SOP ; sinon une nouvelle, nommée « code — titre ». La
 * sous-catégorie, les niveaux de poste et les contrôleurs sont ceux d'une tâche de référence : la première
 * de cette checklist, ou d'une checklist du même poste de travail. Rien n'est écrit : l'assistant s'ouvre
 * avec ces lignes, on les relit, on crée.
 */
function tkSopProposition(string $id): array
{
    $S = tkSop($id);
    if ($S === null) { http_response_code(404); return ['error' => 'SOP inconnue : ' . $id]; }
    $r = $S['row']; $c = $S['contenu'];
    $codeSop = (string) ($r['code'] ?? '');
    $avert = [];

    [$cls] = tkLireListe('/operational-framework/checklists');
    $cls = (array) $cls;
    $match = null;
    foreach ($cls as $x) {
        $code = tkCodeDe(tkTxt($x['name'] ?? ''));
        if ($code !== null && in_array($code, $S['codes'], true)) { $match = $x; break; }
    }

    // L'heure de la SOP : la première citée dans les titres de ses sections.
    $heureSop = null;
    foreach ((array) ($c['sections'] ?? []) as $sec) { if (($h = tkPremiereHeure(tkTxtL($sec['titre'] ?? ''))) !== null) { $heureSop = $h; break; } }

    // Le poste de travail du panel, par le nom du poste de la SOP.
    [$ws] = tkLireListe('/operational-framework/workstations');
    $mots = ['vente' => ['vente'], 'cuisson' => ['boulangerie', 'cuisson'], 'production' => ['production', 'patisserie', 'pâtisserie', 'traiteur'], 'nettoyage' => ['nettoyage', 'plonge']];
    $posteId = 0;
    foreach ((array) $ws as $w) {
        $n = mb_strtolower(tkTxt($w['name'] ?? ''));
        foreach ($mots[(string) $r['poste']] ?? [] as $mot) { if ($n !== '' && str_contains($n, $mot)) { $posteId = (int) ($w['id'] ?? 0); break 2; } }
    }

    if ($match !== null) {
        $checklist = ['mode' => 'existante', 'id' => (int) ($match['id'] ?? 0), 'nom' => tkTxt($match['name'] ?? '')];
    } else {
        $codeCl = ((array) ($S['classement']['checklists'] ?? []))[0] ?? $codeSop;
        $checklist = ['mode' => 'nouvelle', 'nom' => trim(($codeCl !== '' ? $codeCl . ' — ' : '') . (string) $r['titre_fr']),
            'description' => mb_substr(trim((string) ($r['objectif_fr'] ?? '')), 0, 500), 'posteId' => $posteId, 'heure' => $heureSop ?? ''];
        if ($S['codes'] === [] || $codeCl === $codeSop) {
            $avert[] = 'Le classement de la SOP ne cite aucune checklist du Manuel : la checklist proposée porte le code de la SOP.';
        }
    }

    // Une tâche par étape critique.
    $lignes = [];
    $heureSec = $heureSop;
    foreach ((array) ($c['sections'] ?? []) as $sec) {
        $titreSec = tkTxtL($sec['titre'] ?? '');
        $heureSec = tkPremiereHeure($titreSec) ?? $heureSec;
        foreach ((array) ($sec['etapes'] ?? []) as $e) {
            if (!is_array($e) || empty($e['critique'])) { continue; }
            $titre = tkTxtL($e['titre'] ?? '');
            $texte = tkTxtL($e['texte'] ?? '');
            // L'heure de l'étape quand son titre la porte (« Contrôler les froids et les frigos (06:03) ») : elle
            // prime sur celle de la phase, et quitte le nom de la tâche.
            $heureEtape = null;
            if (preg_match('/\s*\((?:dès\s+|vers\s+)?(([01]?\d|2[0-3])[:hH][0-5]\d)\)\s*$/u', $titre, $mh) === 1) {
                $heureEtape = tkPremiereHeure($mh[1]);
                $titre = trim(mb_substr($titre, 0, mb_strlen($titre) - mb_strlen($mh[0])));
            }
            $nom = $titre !== '' ? $titre : tkResume($texte);
            if ($nom === '') { continue; }
            $num = trim((string) ($e['numero'] ?? ''));
            $lignes[] = ['nom' => mb_substr($nom, 0, 150),
                // Sur une ligne : le champ de l'assistant (et l'app des employés) ne garde pas les retours à la ligne.
                'description' => mb_substr(trim(rtrim($texte) . ' (Standard ' . ($codeSop !== '' ? $codeSop : (string) $r['titre_fr']) . ($num !== '' ? ', étape ' . $num : '') . ')'), 0, 1500),
                'etape' => $num !== '' ? $num : null, 'section' => $titreSec, 'photo' => !empty($e['photo']), 'photoRef' => is_string($e['photo'] ?? null) ? $e['photo'] : null,
                'heure' => $heureEtape ?? $heureSec ?? ''];
        }
    }
    if ($lignes === []) {
        foreach ((array) ($c['points_critiques'] ?? []) as $i => $pc) {
            $t = tkTxtL($pc);
            if ($t === '') { continue; }
            $lignes[] = ['nom' => tkResume($t), 'description' => mb_substr(rtrim($t) . ' (Standard ' . $codeSop . ', point critique ' . ($i + 1) . ')', 0, 1500),
                'etape' => 'PC' . ($i + 1), 'section' => 'Points critiques', 'photo' => false, 'photoRef' => null, 'heure' => $heureSop ?? ''];
        }
        if ($lignes !== []) { $avert[] = 'Aucune étape n’est marquée critique : les lignes viennent des points critiques de la SOP.'; }
    }
    if ($lignes === []) { $avert[] = 'Ni étape critique ni point critique : ajoutez les tâches à la main.'; }

    // La tâche de référence : sous-catégorie, niveaux de poste, contrôleurs.
    $ref = null;
    $refCl = $match !== null ? [$match] : array_values(array_filter($cls, fn ($x) => $posteId > 0 && (int) ($x['workstation_id'] ?? 0) === $posteId));
    // À défaut d'une checklist du même poste qui porte des tâches, la première checklist qui en porte : la
    // référence est alors signalée, ses niveaux et contrôleurs sont à vérifier.
    $ailleurs = false;
    foreach ([$refCl, $match === null ? $cls : []] as $tour => $liste) {
        foreach ($liste as $x) {
            [$lt] = tkLireListe('/operational-framework/checklists/' . (int) ($x['id'] ?? 0) . '/tasks');
            foreach ((array) $lt as $t) { $tid = (int) ($t['task_id'] ?? 0); if ($tid > 0) { $ref = ['id' => $tid, 'checklist' => tkTxt($x['name'] ?? '')]; $ailleurs = $tour === 1; break 3; } }
        }
    }
    if ($ref !== null && $ailleurs) { $avert[] = 'Aucune checklist du même poste de travail ne porte de tâche : la référence vient de « ' . $ref['checklist'] . ' » — vérifiez la sous-catégorie, les niveaux de poste et les contrôleurs.'; }
    $sc = null; $niveaux = [];
    if ($ref !== null) {
        [$brutes] = tkLireListe('/tasks');
        foreach ((array) $brutes as $t) {
            if ((int) ($t['id'] ?? 0) === $ref['id']) { $sc = (int) ($t['subcategory_id'] ?? 0) ?: null; $ref['nom'] = tkNormTache($t)['nom']; break; }
        }
        $niveaux = array_values(array_unique(array_map(fn ($lv) => $lv['niveau'], tkNiveauxDe([$ref['id']])[$ref['id']] ?? [])));
    } else {
        $avert[] = 'Aucune tâche de référence trouvée : choisissez la sous-catégorie, les niveaux de poste et les contrôleurs.';
    }
    return ['sop' => tkSopResume($S), 'checklist' => $checklist, 'lignes' => $lignes, 'reference' => $ref,
        'sousCategorieId' => $sc, 'niveaux' => $niveaux, 'controleursDe' => $ref['id'] ?? null, 'avertissements' => $avert];
}

/**
 * GET /task/sop/{id}/controles — proposition D : ce qui contrôle un standard, et ce que les contrôles disent.
 *
 * Les checklists du panel dont le code est au classement de la SOP, leurs tâches, les tâches reliées à une
 * étape (ceo_sop_tache), et pour chacune les avis des 30 derniers jours (mac_task_review, la table partagée
 * avec le panel) : nombre de notes, moyenne, non-conformités sous le seuil du barème, la dernière avec son
 * magasin et son commentaire. Les codes du classement sans checklist au panel sont rendus à part : ils sont
 * « à créer ».
 */
function tkSopControles(string $id): array
{
    $S = tkSop($id);
    if ($S === null) { http_response_code(404); return ['error' => 'SOP inconnue : ' . $id]; }
    $erreurs = [];
    [$cls, $e] = tkLireListe('/operational-framework/checklists');
    if ($cls === null) { $erreurs[] = $e; $cls = []; }
    $matchs = [];
    foreach ($cls as $x) {
        $code = tkCodeDe(tkTxt($x['name'] ?? ''));
        if ($code !== null && in_array($code, $S['codes'], true)) { $matchs[(int) ($x['id'] ?? 0)] = ['id' => (int) ($x['id'] ?? 0), 'code' => $code, 'nom' => tkTxt($x['name'] ?? ''), 'heure' => substr(tkTxt($x['execution_time'] ?? ''), 0, 5), 'nbTaches' => 0]; }
    }
    $taches = [];   // taskId => [checklist, etape]
    $req = [];
    foreach ($matchs as $cid => $m) { $req[$cid] = '/operational-framework/checklists/' . $cid . '/tasks'; }
    foreach ($req ? PanelApi::getParallele($req) : [] as $cid => $rep) {
        foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $x) {
            $tid = (int) ($x['task_id'] ?? 0);
            if ($tid <= 0) { continue; }
            $matchs[(int) $cid]['nbTaches']++;
            $taches[$tid] = ['checklist' => $matchs[(int) $cid]['nom'], 'checklistId' => (int) $cid, 'etape' => null];
        }
    }
    try {
        ensureSopTache();
        foreach (Db::rows('SELECT task_id, etape FROM ceo_sop_tache WHERE sop_id = ?', [(string) $S['row']['id']]) as $l) {
            $tid = (int) $l['task_id'];
            $taches[$tid] = ['checklist' => $taches[$tid]['checklist'] ?? null, 'checklistId' => $taches[$tid]['checklistId'] ?? null, 'etape' => $l['etape']];
        }
    } catch (PDOException $e2) { /* aucun lien encore */ }

    $parId = [];
    if ($taches) {
        [$brutes, $e] = tkLireListe('/tasks');
        if ($brutes === null) { $erreurs[] = $e; }
        foreach ((array) $brutes as $t) { $parId[(int) ($t['id'] ?? 0)] = $t; }
    }

    // Les avis des 30 derniers jours.
    $seuil = function_exists('seuilSignalement') ? seuilSignalement() : 4;
    $depuis = date('Y-m-d', strtotime('-30 days'));
    $stats = [];
    $noms = [];
    if ($taches) {
        try {
            $in = implode(',', array_fill(0, count($taches), '?'));
            foreach (Db::rows("SELECT id_task, id_shop, review_date, rating, is_accepted, comment FROM mac_task_review
                                WHERE id_task IN ($in) AND review_date >= ? AND rating IS NOT NULL ORDER BY review_date DESC, id DESC",
                         array_merge(array_keys($taches), [$depuis])) as $v) {
                $tid = (int) $v['id_task'];
                $st = $stats[$tid] ?? ['notes' => 0, 'somme' => 0, 'nc' => 0, 'dernierNc' => null, 'derniere' => null];
                $note = (int) $v['rating'];
                $st['notes']++; $st['somme'] += $note;
                $st['derniere'] ??= (string) $v['review_date'];
                if ($note < $seuil || (isset($v['is_accepted']) && (string) $v['is_accepted'] === '0')) {
                    $st['nc']++;
                    $st['dernierNc'] ??= ['date' => (string) $v['review_date'], 'shopId' => (int) $v['id_shop'], 'note' => $note,
                        'commentaire' => $v['comment'] !== null ? mb_substr((string) $v['comment'], 0, 300) : null];
                }
                $stats[$tid] = $st;
            }
            foreach (Db::rows('SELECT id, name FROM shops') as $sh) { $noms[(int) $sh['id']] = (string) $sh['name']; }
        } catch (PDOException $e3) { $erreurs[] = 'avis des contrôles illisibles (mac_task_review)'; }
    }

    $out = [];
    $tot = ['taches' => 0, 'notes' => 0, 'conformes' => 0, 'nc' => 0];
    foreach ($taches as $tid => $info) {
        $t = isset($parId[$tid]) ? tkNormTache($parId[$tid]) : ['id' => $tid, 'nom' => 'Tâche #' . $tid, 'jour' => '', 'heure' => '', 'frequence' => '', 'photo' => false, 'obligatoire' => false, 'actif' => null];
        $st = $stats[$tid] ?? null;
        if ($st !== null && $st['dernierNc'] !== null) { $st['dernierNc']['magasin'] = $noms[$st['dernierNc']['shopId']] ?? ('Boutique #' . $st['dernierNc']['shopId']); }
        $out[] = ['id' => $tid, 'nom' => $t['nom'], 'jour' => $t['jour'], 'heure' => $t['heure'], 'frequence' => $t['frequence'], 'photo' => $t['photo'],
            'obligatoire' => $t['obligatoire'], 'actif' => $t['actif'], 'absente' => !isset($parId[$tid]),
            'checklist' => $info['checklist'], 'checklistId' => $info['checklistId'], 'etape' => $info['etape'],
            'stats' => $st === null ? null : ['notes' => $st['notes'], 'moyenne' => round($st['somme'] / max(1, $st['notes']), 1), 'nc' => $st['nc'],
                'conformite' => (int) round(100 * ($st['notes'] - $st['nc']) / max(1, $st['notes'])), 'derniere' => $st['derniere'], 'dernierNc' => $st['dernierNc']]];
        $tot['taches']++;
        if ($st !== null) { $tot['notes'] += $st['notes']; $tot['nc'] += $st['nc']; $tot['conformes'] += $st['notes'] - $st['nc']; }
    }
    usort($out, fn ($a, $b) => [$a['checklist'] ?? 'zz', $a['etape'] ?? '', $a['nom']] <=> [$b['checklist'] ?? 'zz', $b['etape'] ?? '', $b['nom']]);
    $sansCl = array_values(array_filter($S['codes'], fn ($code) => !in_array($code, array_column($matchs, 'code'), true) && $code !== (string) ($S['row']['code'] ?? '')));
    return ['sop' => tkSopResume($S), 'seuil' => $seuil, 'depuis' => $depuis,
        'checklists' => array_values($matchs), 'codesSansChecklist' => $sansCl, 'taches' => $out,
        'totaux' => $tot + ['conformite' => $tot['notes'] > 0 ? (int) round(100 * $tot['conformes'] / $tot['notes']) : null],
        'erreurs' => $erreurs];
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
        $r = tkReq($op['methode'], $op['chemin'], $op['corps']);
        $ops[$k] += ['ok' => $r['erreur'] === null, 'code' => $r['code'], 'reponse' => $r['corps'], 'erreur' => $r['erreur']];
    }
    $ok = !array_filter($ops, fn ($o) => !$o['ok']);
    journalAdd('CEO', 'Cadre des tâches', null, 'Tâche #' . $id . ' (« ' . tkNormTache($m)['nom'] . ' ») modifiée au panel : '
        . implode(', ', array_keys($corps)) . (array_key_exists('actif', $b) ? ($corps ? ', ' : '') . ($b['actif'] ? 'activée' : 'mise en pause') : '')
        . ($ok ? '' : ' — refusé en partie'));
    if (!$ok) { http_response_code(502); }
    return ['id' => $id, 'ok' => $ok, 'operations' => $ops];
}

/**
 * DELETE /task/taches/{id} et DELETE /task/checklists/{id} — seulement ce qui a été créé depuis
 * cet écran (ceo_cadre_cree). Supprimer une checklist laisse ses tâches dans le panel.
 */
function tkSupprimer(string $genre, int $id): array
{
    ensureCadreCree();
    $r = Db::row('SELECT * FROM ceo_cadre_cree WHERE genre = ? AND panel_id = ? AND supprime_le IS NULL', [$genre, $id]);
    $quoi = $genre === 'checklist' ? 'checklist' : 'tâche';
    if ($r === null) {
        http_response_code(403);
        return ['error' => 'seule une ' . $quoi . ' créée depuis cet écran se supprime ici ; les autres se suppriment dans le panel'];
    }
    $chemin = $genre === 'checklist' ? '/operational-framework/checklists/' . $id : '/tasks/' . $id;
    $rep = tkReq('DELETE', $chemin);
    if ($rep['erreur'] !== null) {
        http_response_code(502);
        return ['error' => $rep['erreur'], 'reponse' => $rep['corps']];
    }
    Db::exec('UPDATE ceo_cadre_cree SET supprime_le = ? WHERE genre = ? AND panel_id = ?', [date('Y-m-d H:i:s'), $genre, $id]);
    journalAdd('CEO', 'Cadre des tâches', null, ucfirst($quoi) . ' « ' . $r['nom'] . ' » (#' . $id . ') supprimée du panel');
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
