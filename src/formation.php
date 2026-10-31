<?php
declare(strict_types=1);

/**
 * Module Formation (page /formation/, demande du 31/10/2026).
 *
 * Le référentiel RH du panel (TFBuddy), lu tel quel et rangé pour être lu :
 *
 *   poste (/positions)
 *     └── niveau = sous-poste (/positions/{id}/levels)
 *           └── compétences du niveau (/position-levels/{id}/competencies)
 *                 ├── formations       (/competencies/{id}?include=trainings)
 *                 ├── procédures       (… include=operational_procedures)
 *                 └── ressources       (… include=knowledge_resources)
 *
 * Et à côté : le catalogue complet des compétences (/competencies, rangé par
 * section › catégorie › sous-catégorie), les formations (/operational-
 * framework/trainings), les procédures opératoires, la base de connaissances,
 * les compétences produit, les postes de travail, et les équipes d'un magasin
 * (compétences acquises face à celles du niveau tenu).
 *
 * LECTURE SEULE. Aucune route de ce fichier n'écrit dans le panel : les POST,
 * PUT, PATCH et DELETE du référentiel sont documentés dans le catalogue de
 * l'écran « API », pas appelés.
 *
 * Pourquoi /positions et pas /career-stages : le swagger du panel déclare
 * toutes les routes « HR career routes » (career-paths, career-stages, leurs
 * compétences et exigences) en 500 « Registered handler requests a missing
 * controller ». Les postes et leurs niveaux vivent sous /positions et
 * /position-levels, qui répondent. Les deux sont sondés par l'écran API.
 *
 * Les routes sans contrat dans le swagger (positions, niveaux, formations,
 * procédures…) n'ont pas de forme garantie : chaque lecture prend le premier
 * champ présent parmi plusieurs noms plausibles, et garde les autres champs
 * scalaires dans `brut` pour que l'écran montre ce qui est vraiment venu.
 */

/** Clés jamais renvoyées au navigateur (secrets, données personnelles, salaire). */
function fmSecret(string $k): bool
{
    return (bool) preg_match('/pass|^pin$|_pin$|^pin_|token|secret|hash|salt|login|phone|e-?mail|money|salary|wage|iban|bank|birth|pesel|^nip$|ssn|address|cookie|session/i', $k);
}

/** Copie récursive sans les clés sensibles, chaînes bornées. */
function fmScrub(mixed $v, int $prof = 0): mixed
{
    if (is_string($v)) { return mb_substr($v, 0, 4000); }
    if (!is_array($v)) { return $v; }
    if ($prof > 6) { return '…'; }
    $o = [];
    $n = 0;
    foreach ($v as $k => $x) {
        if (is_string($k) && fmSecret($k)) { continue; }
        if (++$n > 200) { break; }
        $o[$k] = fmScrub($x, $prof + 1);
    }
    return $o;
}

/** Les champs scalaires d'une ligne (hors secrets) : ce que l'écran affiche en « champs bruts ». */
function fmScalaires(array $r): array
{
    $o = [];
    foreach ($r as $k => $v) {
        if (!is_string($k) || fmSecret($k)) { continue; }
        if (is_scalar($v) || $v === null) { $o[$k] = is_string($v) ? mb_substr($v, 0, 1000) : $v; }
    }
    return $o;
}

/** Première chaîne non vide parmi `$cles`. */
function fmTexte(array $r, array $cles): ?string
{
    foreach ($cles as $k) {
        if (isset($r[$k]) && is_scalar($r[$k]) && trim((string) $r[$k]) !== '') { return trim((string) $r[$k]); }
    }
    return null;
}

/** Premier entier parmi `$cles`. */
function fmInt(array $r, array $cles): ?int
{
    foreach ($cles as $k) {
        if (isset($r[$k]) && is_numeric($r[$k])) { return (int) $r[$k]; }
    }
    return null;
}

/** Une réponse du panel ramenée à une liste de lignes. */
function fmListe(mixed $d): array
{
    if (!is_array($d)) { return []; }
    return PanelApi::liste($d);
}

/** 503 si le compte du panel n'est pas branché — dit où le brancher. */
function fmPret(): ?array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) {
        http_response_code(503);
        return ['error' => 'compte API du panel non configuré', 'indispo' => true,
            'raison' => 'Le module lit le référentiel RH du panel avec le compte consultant du cockpit : renseignez-le dans Paramètres › Panel consultant (ou config/config.php › panelApi).'];
    }
    return null;
}

/** Un identifiant entier strictement positif lu dans la requête, ou un 422. */
function fmId(string $cle = 'id'): ?int
{
    $v = $_GET[$cle] ?? null;
    if (!is_string($v) || !preg_match('/^\d{1,9}$/', $v) || (int) $v <= 0) { return null; }
    return (int) $v;
}

/* ── Normalisation ─────────────────────────────────────────────────────── */

function fmCompetence(array $r): array
{
    // Une ligne de liaison (niveau ↔ compétence) porte son propre `id` : la
    // compétence est alors dans competency_id / id_competency.
    $id = fmInt($r, ['competency_id', 'id_competency', 'competence_id', 'id']);
    return [
        'id'              => $id,
        'nom'             => fmTexte($r, ['name', 'base_name', 'competency_name', 'title']) ?? ('Compétence ' . $id),
        'nomBase'         => fmTexte($r, ['base_name']),
        'description'     => fmTexte($r, ['description', 'base_description']),
        'verification'    => fmTexte($r, ['verification_method']),
        'sectionId'       => fmInt($r, ['section_id', 'id_section']),
        'categorieId'     => fmInt($r, ['category_id', 'id_category']),
        'categorie'       => fmTexte($r, ['category_name', 'base_category_name']),
        'sousCategorieId' => fmInt($r, ['subcategory_id', 'id_subcategory']),
        'sousCategorie'   => fmTexte($r, ['subcategory_name', 'base_subcategory_name']),
        'creeLe'          => fmTexte($r, ['created_at']),
    ];
}

function fmPoste(array $r): array
{
    return [
        'id'          => fmInt($r, ['id', 'position_id', 'id_position']),
        'nom'         => fmTexte($r, ['name', 'base_name', 'title', 'position_name']) ?? 'Poste',
        'description' => fmTexte($r, ['description', 'base_description']),
        'ordre'       => fmInt($r, ['sort_order', 'order', 'position_order']),
        'brut'        => fmScalaires($r),
    ];
}

function fmNiveau(array $r, ?int $posteId): array
{
    return [
        'id'          => fmInt($r, ['id', 'level_id', 'id_level', 'position_level_id']),
        'posteId'     => $posteId ?? fmInt($r, ['position_id', 'id_position']),
        'nom'         => fmTexte($r, ['name', 'level_name', 'base_name', 'title']) ?? 'Niveau',
        'description' => fmTexte($r, ['description', 'level_description', 'base_description']),
        'ordre'       => fmInt($r, ['level_order', 'sort_order', 'order', 'position']),
        'competences' => [],
        'brut'        => fmScalaires($r),
    ];
}

function fmFormation(array $r): array
{
    return [
        'id'              => fmInt($r, ['id', 'training_id', 'id_training']),
        'nom'             => fmTexte($r, ['name', 'base_name', 'title']) ?? 'Formation',
        'description'     => fmTexte($r, ['description', 'base_description']),
        'categorie'       => fmTexte($r, ['category_name', 'base_category_name']),
        'sousCategorieId' => fmInt($r, ['subcategory_id', 'id_subcategory']),
        'sousCategorie'   => fmTexte($r, ['subcategory_name', 'base_subcategory_name']),
        'brut'            => fmScalaires($r),
    ];
}

function fmProcedure(array $r): array
{
    return [
        'id'              => fmInt($r, ['id', 'id_operational_procedure', 'procedure_id']),
        'titre'           => fmTexte($r, ['title', 'base_title', 'name']) ?? 'Procédure',
        'description'     => fmTexte($r, ['description', 'base_description']),
        'categorieId'     => fmInt($r, ['category_id', 'id_category']),
        'categorie'       => fmTexte($r, ['category_name', 'base_category_name']),
        'sousCategorieId' => fmInt($r, ['subcategory_id', 'id_subcategory']),
        'sousCategorie'   => fmTexte($r, ['subcategory_name', 'base_subcategory_name']),
        'majLe'           => fmTexte($r, ['updated_at', 'created_at']),
    ];
}

function fmRessource(array $r): array
{
    return [
        'id'          => fmInt($r, ['id', 'knowledge_resource_id', 'resource_id']),
        'titre'       => fmTexte($r, ['title', 'base_title', 'name']) ?? 'Ressource',
        'description' => fmTexte($r, ['description', 'base_description']),
        'type'        => fmTexte($r, ['type', 'resource_type', 'kind', 'mime_type']),
        'url'         => fmTexte($r, ['url', 'link', 'file_url', 'external_url']),
        'categorie'   => fmTexte($r, ['category_name', 'base_category_name']),
        'brut'        => fmScalaires($r),
    ];
}

function fmSimple(array $r, array $nom = ['name', 'base_name', 'title']): array
{
    return [
        'id'          => fmInt($r, ['id']),
        'nom'         => fmTexte($r, $nom) ?? '—',
        'description' => fmTexte($r, ['description', 'base_description']),
        'brut'        => fmScalaires($r),
    ];
}

/** Une ligne d'appel pour le bandeau « ce que le panel a rendu ». */
function fmAppel(string $cle, string $chemin, array $r, ?int $n = null): array
{
    return ['cle' => $cle, 'chemin' => $chemin, 'code' => $r['code'], 'ms' => $r['ms'],
        'n' => $n ?? (is_array($r['data']) && (array_is_list($r['data']) || fmListe($r['data'])) ? count(fmListe($r['data'])) : null), 'erreur' => $r['erreur']];
}

/** Le résumé d'une volée de N appels du même gabarit (un par poste, un par niveau…). */
function fmAppelVolee(string $cle, string $gabarit, array $res): array
{
    $ok = 0; $err = null; $ms = 0; $codes = [];
    foreach ($res as $r) {
        $ms = max($ms, $r['ms']);
        $codes[(string) $r['code']] = ($codes[(string) $r['code']] ?? 0) + 1;
        if ($r['code'] >= 200 && $r['code'] < 300) { $ok++; } elseif ($err === null) { $err = $r['erreur']; }
    }
    ksort($codes);
    return ['cle' => $cle, 'chemin' => $gabarit, 'code' => $res ? ($ok === count($res) ? 200 : (int) array_key_last($codes)) : null,
        'ms' => $ms, 'n' => count($res), 'ok' => $ok, 'codes' => $codes, 'erreur' => $err];
}

/* ── Lectures ──────────────────────────────────────────────────────────── */

/**
 * GET /formation/referentiel — tout le référentiel en une lecture.
 *
 * Trois volées : les listes (10 routes), puis les niveaux de chaque poste,
 * puis les compétences de chaque niveau. Quatre connexions de front, comme le
 * reste du cockpit. L'écran la demande avec `_cache=600`.
 */
function ep_formation_referentiel(): array
{
    if ($e = fmPret()) { return $e; }
    @set_time_limit(150);
    $chemins = [
        'postes'        => '/positions',
        'niveaux'       => '/position-levels',
        'competences'   => '/competencies?lang_code=fr',
        'formations'    => '/operational-framework/trainings',
        'sections'      => '/operational-framework/structure/sections',
        'categories'    => '/operational-framework/structure/categories',
        'procedures'    => '/operational-procedures',
        'ressources'    => '/knowledge-base/resources',
        'produit'       => '/product-competences',
        'postesTravail' => '/operational-framework/workstations',
    ];
    $v1 = PanelApi::getParalleleDetail($chemins, 4, 25);
    $appels = [];
    foreach ($chemins as $k => $c) { $appels[] = fmAppel($k, $c, $v1[$k]); }

    // --- Postes, puis leurs niveaux (un appel par poste).
    $postes = [];
    foreach (fmListe($v1['postes']['data']) as $r) {
        $p = fmPoste($r);
        if ($p['id'] !== null) { $postes[$p['id']] = $p + ['niveaux' => []]; }
    }
    $niveauxGlobaux = array_map(static fn ($r) => fmNiveau($r, null), fmListe($v1['niveaux']['data']));
    $parPoste = [];
    foreach (array_keys($postes) as $pid) { $parPoste['p' . $pid] = '/positions/' . $pid . '/levels'; }
    $v2 = PanelApi::getParalleleDetail($parPoste, 4, 20);
    if ($v2) { $appels[] = fmAppelVolee('niveauxParPoste', '/positions/{id}/levels', $v2); }
    $niveaux = [];
    foreach ($postes as $pid => $p) {
        $r = $v2['p' . $pid] ?? null;
        $liste = $r !== null && $r['code'] >= 200 && $r['code'] < 300 ? fmListe($r['data']) : null;
        // La route par poste a échoué : la liste globale, si ses lignes disent leur poste.
        $lignes = $liste !== null ? array_map(static fn ($x) => fmNiveau($x, $pid), $liste)
            : array_values(array_filter($niveauxGlobaux, static fn ($n) => $n['posteId'] === $pid));
        foreach ($lignes as $n) {
            if ($n['id'] === null) { continue; }
            $n['posteId'] = $pid;
            $niveaux[$n['id']] = $n;
        }
    }
    // Des niveaux que la liste globale connaît mais qu'aucun poste n'a rendus.
    foreach ($niveauxGlobaux as $n) {
        if ($n['id'] !== null && !isset($niveaux[$n['id']])) { $niveaux[$n['id']] = $n; }
    }

    // --- Compétences : le catalogue, puis celles de chaque niveau.
    $competences = [];
    foreach (fmListe($v1['competences']['data']) as $r) {
        $c = fmCompetence($r);
        if ($c['id'] !== null) { $competences[$c['id']] = $c; }
    }
    $parNiveau = [];
    foreach (array_keys($niveaux) as $nid) { $parNiveau['n' . $nid] = '/position-levels/' . $nid . '/competencies'; }
    $v3 = PanelApi::getParalleleDetail($parNiveau, 4, 20);
    if ($v3) { $appels[] = fmAppelVolee('competencesParNiveau', '/position-levels/{id}/competencies', $v3); }
    foreach ($niveaux as $nid => &$n) {
        $r = $v3['n' . $nid] ?? null;
        $n['competencesLues'] = $r !== null && $r['code'] >= 200 && $r['code'] < 300;
        if (!$n['competencesLues']) { $n['competencesErreur'] = $r['erreur'] ?? null; continue; }
        foreach (fmListe($r['data']) as $x) {
            $c = fmCompetence($x);
            if ($c['id'] === null) { continue; }
            $n['competences'][] = $c['id'];
            // Une compétence du niveau absente du catalogue : on la garde quand même.
            if (!isset($competences[$c['id']])) { $competences[$c['id']] = $c + ['horsCatalogue' => true]; }
        }
        $n['competences'] = array_values(array_unique($n['competences']));
    }
    unset($n);

    // Les niveaux rangés sous leur poste, dans l'ordre du panel.
    $sansPoste = [];
    foreach ($niveaux as $n) {
        if ($n['posteId'] !== null && isset($postes[$n['posteId']])) { $postes[$n['posteId']]['niveaux'][] = $n; }
        else { $sansPoste[] = $n; }
    }
    $tri = static fn ($a, $b) => [$a['ordre'] ?? PHP_INT_MAX, $a['id']] <=> [$b['ordre'] ?? PHP_INT_MAX, $b['id']];
    foreach ($postes as &$p) { usort($p['niveaux'], $tri); }
    unset($p);
    $postes = array_values($postes);
    // L'ordre du panel, sauf s'il en donne un explicite (usort est stable depuis PHP 8).
    usort($postes, static fn ($a, $b) => ($a['ordre'] ?? PHP_INT_MAX) <=> ($b['ordre'] ?? PHP_INT_MAX));
    if ($sansPoste) {
        usort($sansPoste, $tri);
        $postes[] = ['id' => null, 'nom' => 'Niveaux sans poste', 'description' => 'Niveaux rendus par /position-levels qu’aucun poste ne liste.',
            'ordre' => null, 'brut' => [], 'niveaux' => $sansPoste];
    }

    $comp = array_values($competences);
    usort($comp, static fn ($a, $b) => [$a['categorie'] ?? '', $a['sousCategorie'] ?? '', $a['nom']] <=> [$b['categorie'] ?? '', $b['sousCategorie'] ?? '', $b['nom']]);

    $map = static fn (string $k, callable $f) => array_values(array_filter(array_map($f, fmListe($v1[$k]['data'])), static fn ($x) => $x['id'] !== null));
    return [
        'source'      => ['base' => PanelApi::config()['base'], 'lu' => date('c')],
        'postes'      => $postes,
        'competences' => $comp,
        'formations'  => $map('formations', 'fmFormation'),
        'procedures'  => $map('procedures', 'fmProcedure'),
        'ressources'  => $map('ressources', 'fmRessource'),
        'competencesProduit' => $map('produit', static fn ($r) => fmSimple($r)),
        'postesTravail' => $map('postesTravail', static fn ($r) => fmSimple($r)),
        'structure'   => [
            'sections'   => $map('sections', static fn ($r) => fmSimple($r)),
            'categories' => $map('categories', static fn ($r) => fmSimple($r) + ['sectionId' => fmInt($r, ['section_id', 'id_section'])]),
        ],
        'appels'      => $appels,
    ];
}

/** Les liens d'une compétence (procédures, formations, ressources), normalisés. */
function fmLiens(array $r): array
{
    $lire = static function (array $r, string $k, array $idCles, array $nomCles): array {
        $o = [];
        foreach (is_array($r[$k] ?? null) ? $r[$k] : [] as $x) {
            if (!is_array($x)) { continue; }
            $id = fmInt($x, $idCles);
            if ($id === null) { continue; }
            $o[] = ['id' => $id, 'nom' => fmTexte($x, $nomCles) ?? ('#' . $id)];
        }
        return $o;
    };
    return [
        'procedures' => $lire($r, 'operational_procedures', ['id_operational_procedure', 'procedure_id', 'id'], ['title', 'base_title', 'name']),
        'formations' => $lire($r, 'trainings', ['training_id', 'id_training', 'id'], ['name', 'base_name', 'title']),
        'ressources' => $lire($r, 'knowledge_resources', ['knowledge_resource_id', 'resource_id', 'id'], ['title', 'base_title', 'name']),
    ];
}

/**
 * GET /formation/liens — pour CHAQUE compétence du catalogue, ses procédures,
 * formations et ressources. Un appel par compétence (le panel n'a pas de
 * lecture groupée) : c'est la lecture lente du module, demandée avec
 * `_cache=900` et chargée après le référentiel.
 */
function ep_formation_liens(): array
{
    if ($e = fmPret()) { return $e; }
    @set_time_limit(240);
    $l = PanelApi::getParalleleDetail(['c' => '/competencies?lang_code=fr'], 1, 25);
    if ($l['c']['code'] < 200 || $l['c']['code'] >= 300) {
        http_response_code(502);
        return ['error' => 'catalogue des compétences illisible', 'appels' => [fmAppel('competences', '/competencies', $l['c'])]];
    }
    $ids = [];
    foreach (fmListe($l['c']['data']) as $r) { $id = fmInt($r, ['id']); if ($id !== null) { $ids[$id] = true; } }
    // Les compétences des niveaux qui ne sont pas au catalogue, si l'écran les envoie.
    foreach (explode(',', (string) ($_GET['en_plus'] ?? '')) as $x) {
        if (preg_match('/^\d{1,9}$/', trim($x))) { $ids[(int) trim($x)] = true; }
    }
    $ids = array_slice(array_keys($ids), 0, 800);
    $chemins = [];
    foreach ($ids as $id) { $chemins['c' . $id] = '/competencies/' . $id . '?include=operational_procedures,trainings,knowledge_resources&lang_code=fr'; }
    $v = PanelApi::getParalleleDetail($chemins, 4, 20);
    $liens = [];
    foreach ($ids as $id) {
        $r = $v['c' . $id];
        if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($r['data'])) { continue; }
        $liens[(string) $id] = fmLiens($r['data']);
    }
    return ['liens' => (object) $liens, 'lu' => date('c'),
        'appels' => [fmAppel('competences', '/competencies', $l['c']), fmAppelVolee('liensParCompetence', '/competencies/{id}?include=operational_procedures,trainings,knowledge_resources', $v)]];
}

/** GET /formation/competence?id= — une compétence et ses liens. */
function ep_formation_competence(): array
{
    if ($e = fmPret()) { return $e; }
    $id = fmId();
    if ($id === null) { http_response_code(422); return ['error' => 'id attendu']; }
    $c = '/competencies/' . $id . '?include=operational_procedures,trainings,knowledge_resources&lang_code=fr';
    $r = PanelApi::getParalleleDetail(['c' => $c], 1, 20)['c'];
    if ($r['code'] < 200 || $r['code'] >= 300) { http_response_code(502); return ['error' => $r['erreur'] ?? 'lecture impossible', 'appels' => [fmAppel('competence', $c, $r)]]; }
    // Le panel rend [] (avec les clés d'inclusion) pour une compétence absente.
    $d = is_array($r['data']) ? $r['data'] : [];
    if (fmInt($d, ['id']) === null) { http_response_code(404); return ['error' => 'compétence inconnue du panel']; }
    return ['competence' => fmCompetence($d), 'liens' => fmLiens($d), 'appels' => [fmAppel('competence', $c, $r)]];
}

/** GET /formation/niveau?id= — un niveau (sous-poste) : fiche, compétences, exigences, tâches. */
function ep_formation_niveau(): array
{
    if ($e = fmPret()) { return $e; }
    $id = fmId();
    if ($id === null) { http_response_code(422); return ['error' => 'id attendu']; }
    $chemins = ['fiche' => '/position-levels/' . $id, 'competences' => '/position-levels/' . $id . '/competencies',
        'exigences' => '/position-levels/' . $id . '/requirements', 'taches' => '/position-levels/' . $id . '/tasks'];
    $v = PanelApi::getParalleleDetail($chemins, 4, 20);
    $appels = [];
    foreach ($chemins as $k => $c) { $appels[] = fmAppel($k, $c, $v[$k]); }
    $fiche = is_array($v['fiche']['data']) && !array_is_list($v['fiche']['data']) ? fmNiveau($v['fiche']['data'], null) : null;
    return [
        'niveau'      => $fiche,
        'competences' => array_values(array_filter(array_map('fmCompetence', fmListe($v['competences']['data'])), static fn ($c) => $c['id'] !== null)),
        'exigences'   => array_map(static fn ($r) => fmSimple($r, ['name', 'title', 'requirement', 'base_name', 'description']), fmListe($v['exigences']['data'])),
        'taches'      => array_map(static fn ($r) => fmSimple($r, ['name', 'title', 'task_name', 'base_name']), fmListe($v['taches']['data'])),
        'appels'      => $appels,
    ];
}

/**
 * GET /formation/procedure?id= — une procédure opératoire et ses sections :
 * la page du manuel. Le contenu des sections est rendu tel que le panel le
 * tient (souvent du HTML d'éditeur) ; c'est l'écran qui le nettoie avant de
 * l'afficher.
 */
function ep_formation_procedure(): array
{
    if ($e = fmPret()) { return $e; }
    $id = fmId();
    if ($id === null) { http_response_code(422); return ['error' => 'id attendu']; }
    $chemins = ['fiche' => '/operational-procedures/' . $id . '?lang_code=fr', 'pieces' => '/operational-procedures/' . $id . '/attachments'];
    $v = PanelApi::getParalleleDetail($chemins, 2, 20);
    $appels = [fmAppel('fiche', $chemins['fiche'], $v['fiche']), fmAppel('pieces', $chemins['pieces'], $v['pieces'])];
    $d = $v['fiche']['data'];
    if (!is_array($d) || array_is_list($d) || fmInt($d, ['id']) === null) {
        http_response_code($v['fiche']['code'] >= 200 && $v['fiche']['code'] < 300 ? 404 : 502);
        return ['error' => $v['fiche']['erreur'] ?? 'procédure inconnue du panel', 'appels' => $appels];
    }
    $sections = [];
    foreach (is_array($d['sections'] ?? null) ? $d['sections'] : [] as $s) {
        if (!is_array($s)) { continue; }
        $sections[] = ['id' => fmInt($s, ['id']), 'titre' => fmTexte($s, ['title', 'base_title', 'name']),
            'contenu' => fmTexte($s, ['content', 'base_content', 'description']), 'ordre' => fmInt($s, ['sort_order', 'order'])];
    }
    usort($sections, static fn ($a, $b) => [$a['ordre'] ?? PHP_INT_MAX, $a['id'] ?? 0] <=> [$b['ordre'] ?? PHP_INT_MAX, $b['id'] ?? 0]);
    $pieces = [];
    foreach (fmListe($v['pieces']['data']) as $p) {
        $pieces[] = ['id' => fmInt($p, ['id', 'attachment_id']), 'nom' => fmTexte($p, ['label', 'name', 'title', 'file_name', 'original_name']),
            'url' => fmTexte($p, ['url', 'presigned_url', 'file_url', 'link']), 'type' => fmTexte($p, ['mime_type', 'type', 'extension'])];
    }
    return ['procedure' => fmProcedure($d) + ['contenu' => fmTexte($d, ['content', 'base_content'])],
        'sections' => $sections, 'pieces' => $pieces, 'appels' => $appels];
}

/** GET /formation/formation?id= — une formation et ses formateurs. */
function ep_formation_formation(): array
{
    if ($e = fmPret()) { return $e; }
    $id = fmId();
    if ($id === null) { http_response_code(422); return ['error' => 'id attendu']; }
    $chemins = ['fiche' => '/operational-framework/trainings/' . $id, 'formateurs' => '/operational-framework/trainings/' . $id . '/trainers'];
    $v = PanelApi::getParalleleDetail($chemins, 2, 20);
    $d = $v['fiche']['data'];
    $formateurs = [];
    foreach (fmListe($v['formateurs']['data']) as $t) {
        $nom = fmTexte($t, ['display_name', 'full_name', 'name']);
        $nom2 = fmTexte($t, ['surname', 'last_name']);
        $formateurs[] = ['id' => fmInt($t, ['id', 'employee_id', 'admin_id']), 'nom' => trim(($nom ?? '') . ($nom2 !== null && $nom !== null && !str_contains($nom, $nom2) ? ' ' . $nom2 : '')) ?: '—',
            'type' => fmTexte($t, ['type', 'trainer_type', 'kind'])];
    }
    return ['formation' => is_array($d) && !array_is_list($d) ? fmFormation($d) : null, 'formateurs' => $formateurs,
        'appels' => [fmAppel('fiche', $chemins['fiche'], $v['fiche']), fmAppel('formateurs', $chemins['formateurs'], $v['formateurs'])]];
}

/** GET /formation/ressource?id= — une ressource de la base de connaissances. */
function ep_formation_ressource(): array
{
    if ($e = fmPret()) { return $e; }
    $id = fmId();
    if ($id === null) { http_response_code(422); return ['error' => 'id attendu']; }
    $c = '/knowledge-base/resources/' . $id;
    $r = PanelApi::getParalleleDetail(['r' => $c], 1, 20)['r'];
    $d = $r['data'];
    return ['ressource' => is_array($d) && !array_is_list($d) ? fmRessource($d) + ['contenu' => fmTexte($d, ['content', 'body', 'text'])] : null,
        'appels' => [fmAppel('ressource', $c, $r)]];
}

/**
 * GET /formation/equipe?shop= — l'équipe d'un magasin : pour chacun, le poste
 * et le niveau tenus, et les compétences acquises. L'écart avec le niveau se
 * calcule à l'écran (il a le référentiel). Sans `shop` : la liste des
 * magasins. Rien de personnel ne sort : ni téléphone, ni mail, ni taux.
 */
function ep_formation_equipe(): array
{
    if ($e = fmPret()) { return $e; }
    $magasins = [];
    try {
        foreach (ep_stores() as $s) {
            // Le magasin du panel : `pwaId` (table partagée `shops`). Le repli de démonstration
            // (ceo_shop) n'a pas d'identifiant panel — rien à lire chez le panel pour lui.
            $pid = (int) ($s['pwaId'] ?? $s['id']);
            if (($s['status'] ?? '') === 'Fermé' || $pid <= 0) { continue; }
            $magasins[] = ['id' => $pid, 'nom' => (string) $s['nom']];
        }
    } catch (Throwable $e) { /* sans table des magasins : la liste reste vide, l'écran le dit */ }
    $sid = fmId('shop');
    if ($sid === null) { return ['magasins' => $magasins, 'employes' => null]; }
    @set_time_limit(120);
    $c = '/shops/' . $sid . '/employees';
    $l = PanelApi::getParalleleDetail(['e' => $c], 1, 20)['e'];
    $appels = [fmAppel('employes', $c, $l)];
    $emps = [];
    foreach (fmListe($l['data']) as $r) {
        $id = fmInt($r, ['id', 'employee_id']);
        if ($id === null) { continue; }
        $actif = $r['active'] ?? ($r['is_active'] ?? 1);
        if (isset($r['soft_delete']) && (int) $r['soft_delete'] === 1) { continue; }
        $nom = fmTexte($r, ['display_name']) ?? trim((fmTexte($r, ['name']) ?? '') . ' ' . (fmTexte($r, ['surname']) ?? ''));
        $emps[$id] = ['id' => $id, 'nom' => $nom !== '' ? $nom : ('Employé ' . $id), 'actif' => (bool) (int) $actif,
            'role' => fmTexte($r, ['franchise_role', 'role_name', 'employment_type']), 'postes' => [], 'competences' => [], 'lu' => false];
    }
    $emps = array_slice($emps, 0, 120, true);
    $chemins = [];
    foreach (array_keys($emps) as $id) { $chemins['e' . $id] = '/employees/' . $id . '?include=competencies,positions'; }
    $v = PanelApi::getParalleleDetail($chemins, 4, 20);
    if ($v) { $appels[] = fmAppelVolee('fiches', '/employees/{id}?include=competencies,positions', $v); }
    foreach ($emps as $id => &$e2) {
        $r = $v['e' . $id] ?? null;
        if ($r === null || $r['code'] < 200 || $r['code'] >= 300 || !is_array($r['data'])) { continue; }
        $e2['lu'] = true;
        foreach (is_array($r['data']['positions'] ?? null) ? $r['data']['positions'] : [] as $p) {
            if (!is_array($p)) { continue; }
            $e2['postes'][] = ['id' => fmInt($p, ['id', 'position_id']), 'nom' => fmTexte($p, ['name']), 'niveauId' => fmInt($p, ['level_id']),
                'niveau' => fmTexte($p, ['level_name']), 'ordre' => fmInt($p, ['level_order'])];
        }
        foreach (is_array($r['data']['competencies'] ?? null) ? $r['data']['competencies'] : [] as $c2) {
            if (is_array($c2) && ($cid = fmInt($c2, ['competency_id', 'id'])) !== null) { $e2['competences'][] = $cid; }
        }
        $e2['competences'] = array_values(array_unique($e2['competences']));
    }
    unset($e2);
    $out = array_values($emps);
    usort($out, static fn ($a, $b) => [!$a['actif'], $a['nom']] <=> [!$b['actif'], $b['nom']]);
    return ['magasins' => $magasins, 'shop' => $sid, 'employes' => $out, 'appels' => $appels];
}

/** Les routes GET que la sonde accepte : le référentiel formation, rien d'autre. */
const FM_SONDE = '#^/(positions(/\d+(/levels)?)?|position-levels(/\d+(/(competencies|requirements|tasks))?)?|levels/\d+/tasks'
    . '|competencies(/\d+)?|competency-subcategories/\d+/competencies|career-paths(/\d+(/stages)?)?|career-stages(/\d+(/(competencies|requirements))?)?'
    . '|employees/\d+(/(competencies|positions))?|franchisee-employee/\d+/(competences|courses)'
    . '|product-competences(/\d+|/name-aliases|/description-aliases)?|packages(/\d+(/cost)?)?|products/\d+/competences'
    . '|operational-framework/(trainings(/\d+(/trainers)?)?|workstations(/\d+)?|structure/(sections(/\d+)?|categories(/\d+(/subcategories)?)?))'
    . '|operational-procedures(/\d+(/attachments)?)?|operational-procedure-(categories|sections|section-titles)(/\d+)?'
    . '|knowledge-base/(categories(/\d+(/resources)?)?|resources(/\d+)?)|shops/\d+/knowledge-base/resources'
    . '|courses|course/\d+(/competences)?|course-(commissions|groups|sets|subgroups)|course-commission/(courses|\d+(/competences|/participants)?)'
    . '|course-set/\d+(/courses)?|course-group/\d+(/subgroups)?|course-subgroup/\d+|franchisee-employee-roles|franchisee-employee-role/\d+(/course)?'
    . '|shops/workstations(/\d+)?)(\?[A-Za-z0-9_=&,%\-]*)?$#';

/**
 * GET /formation/sonde?chemin=/competencies — l'écran API teste une route :
 * code HTTP, durée, forme de la réponse, champs, premier élément (nettoyé).
 * GET seulement, et seulement les routes du référentiel formation.
 */
function ep_formation_sonde(): array
{
    if ($e = fmPret()) { return $e; }
    $chemin = (string) ($_GET['chemin'] ?? '');
    $chemin = preg_replace('#^/api/v1#', '', $chemin) ?? '';
    if (!preg_match(FM_SONDE, $chemin)) {
        http_response_code(422);
        return ['error' => 'route hors du référentiel formation', 'chemin' => $chemin];
    }
    $r = PanelApi::getParalleleDetail(['s' => $chemin], 1, 25)['s'];
    $d = $r['data'];
    $liste = is_array($d) ? fmListe($d) : [];
    $type = $d === null ? 'vide' : (is_array($d) ? (array_is_list($d) ? 'liste' : ($liste ? 'objet contenant une liste' : 'objet')) : gettype($d));
    $premier = $liste[0] ?? (is_array($d) && !array_is_list($d) ? $d : null);
    $champs = is_array($premier) ? array_values(array_filter(array_keys($premier), static fn ($k) => !is_string($k) || !fmSecret($k))) : [];
    return ['chemin' => $chemin, 'code' => $r['code'], 'ms' => $r['ms'], 'type' => $type,
        'n' => is_array($d) && array_is_list($d) ? count($d) : ($liste ? count($liste) : null),
        'champs' => $champs, 'exemple' => $premier !== null ? fmScrub($premier) : (is_scalar($d) ? $d : null), 'erreur' => $r['erreur']];
}

/**
 * GET /formation/pdf?type=poste|niveau|procedure&id= — le PDF que le panel
 * génère, relayé avec le jeton du cockpit (la tablette n'en a pas).
 */
function ep_formation_pdf(): array
{
    if ($e = fmPret()) { return $e; }
    $id = fmId();
    $gab = ['poste' => '/positions/%d/pdf', 'niveau' => '/position-levels/%d/pdf', 'procedure' => '/operational-procedures/%d/pdf'];
    $type = (string) ($_GET['type'] ?? '');
    if ($id === null || !isset($gab[$type])) { http_response_code(422); return ['error' => 'type (poste, niveau, procedure) et id attendus']; }
    $chemin = sprintf($gab[$type], $id);
    [$code, $octets, $mime] = PanelApi::binaire($chemin);
    if ($code !== 200 || $octets === null || !str_starts_with($octets, '%PDF')) {
        http_response_code(502);
        $det = is_string($octets) ? json_decode($octets, true) : null;
        return ['error' => 'le panel n’a pas rendu de PDF', 'chemin' => $chemin, 'code' => $code, 'type' => $mime,
            'detail' => is_array($det) ? (fmTexte($det, ['description', 'message', 'error']) ?? null) : null];
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $type . '-' . $id . '.pdf"');
    header('Content-Length: ' . strlen($octets));
    echo $octets;
    exit;
}
