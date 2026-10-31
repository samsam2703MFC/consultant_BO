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
        // Le lien que le panel attache à une compétence (vidéo, document) — lu sur les données réelles.
        'lien'            => fmTexte($r, ['related_link', 'link', 'url']),
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
    // Les sous-catégories de chaque catégorie, dans la même volée : la création d'une
    // compétence les demande (id_subcategory), et une sous-catégorie encore vide
    // n'apparaît sur aucune compétence.
    $cats = array_values(array_filter(array_map(static fn ($r) => fmInt($r, ['id']), fmListe($v1['categories']['data'])), static fn ($x) => $x !== null));
    foreach ($cats as $cid) { $parPoste['s' . $cid] = '/operational-framework/structure/categories/' . $cid . '/subcategories'; }
    $v2 = PanelApi::getParalleleDetail($parPoste, 4, 20);
    $v2p = array_filter($v2, static fn ($k) => $k[0] === 'p', ARRAY_FILTER_USE_KEY);
    $v2s = array_filter($v2, static fn ($k) => $k[0] === 's', ARRAY_FILTER_USE_KEY);
    if ($v2p) { $appels[] = fmAppelVolee('niveauxParPoste', '/positions/{id}/levels', $v2p); }
    if ($v2s) { $appels[] = fmAppelVolee('sousCategories', '/operational-framework/structure/categories/{id}/subcategories', $v2s); }
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

    // Les sous-catégories : celles du panel, complétées par celles que portent les compétences.
    $sousCats = [];
    foreach ($cats as $cid) {
        $r = $v2['s' . $cid] ?? null;
        if ($r === null || $r['code'] < 200 || $r['code'] >= 300) { continue; }
        foreach (fmListe($r['data']) as $x) {
            $id = fmInt($x, ['id', 'subcategory_id']);
            if ($id !== null) { $sousCats[$id] = ['id' => $id, 'nom' => fmTexte($x, ['name', 'base_name', 'title']) ?? ('#' . $id), 'categorieId' => $cid]; }
        }
    }
    foreach ($competences as $c) {
        if ($c['sousCategorieId'] !== null && !isset($sousCats[$c['sousCategorieId']])) {
            $sousCats[$c['sousCategorieId']] = ['id' => $c['sousCategorieId'], 'nom' => $c['sousCategorie'] ?? ('#' . $c['sousCategorieId']), 'categorieId' => $c['categorieId']];
        }
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
            'sousCategories' => array_values($sousCats),
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
    // MESURÉ sur le panel (31/10/2026) : sans `include=sections`, la fiche ne porte pas ses sections.
    $chemins = ['fiche' => '/operational-procedures/' . $id . '?include=sections&lang_code=fr', 'pieces' => '/operational-procedures/' . $id . '/attachments'];
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

/* ── Écritures (31/10/2026) ────────────────────────────────────────────────
 *
 * Ce que le module écrit dans le panel, et rien d'autre :
 *   - les compétences exigées par un niveau (sous-poste) ;
 *   - les formations, procédures et ressources rattachées à une compétence ;
 *   - le catalogue des compétences (créer, modifier, supprimer) ;
 *   - pour une personne : valider ou retirer une compétence, changer de niveau.
 *
 * Trois règles :
 *   1. TOUTE écriture est relue. Le panel répond « success » à un DELETE qui
 *      n'a rien supprimé et à un POST dont il a ignoré le corps : seule la
 *      relecture dit ce qui est vraiment enregistré. Un écart est une erreur.
 *   2. Un retrait essaie d'abord l'identifiant de la CIBLE (compétence,
 *      formation…), puis, si la relecture la montre encore, celui de la
 *      LIAISON — les routes sans contrat ne disent pas lequel elles attendent.
 *   3. Chaque écriture réussie laisse une ligne au journal du cockpit, et
 *      corrige les lectures gardées en cache (référentiel, liens, équipe) :
 *      l'écran suivant montre l'état écrit, sans tout relire au panel.
 */

/** La clé de cache d'une lecture gardée (même calcul que routeGardee()). */
function fmCleCache(string $route, array $q = []): string
{
    ksort($q);
    return 'rcG:' . md5($route . '?' . http_build_query($q));
}

/** Corrige une lecture gardée en place ; sans copie en cache, rien à faire. */
function fmCachePatch(string $route, array $q, callable $f): void
{
    try {
        $k = fmCleCache($route, $q);
        $c = setting($k);
        if (!is_array($c) || !is_array($c['r'] ?? null)) { return; }
        $c['r'] = $f($c['r']);
        $j = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($j)) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, $j]); }
    } catch (Throwable $e) { /* le cache se refera à sa prochaine lecture */ }
}

/** Le journal du cockpit, s'il est là (les tests n'en ont pas). */
function fmJournal(?string $objet, string $message): void
{
    if (!function_exists('journalAdd')) { return; }
    try { journalAdd('CEO', 'Formation', $objet, $message); } catch (Throwable $e) { /* l'écriture est faite, le journal suivra */ }
}

/** Le corps JSON de la requête (writes.php le fournit ; repli pour les tests). */
function fmCorps(): array
{
    if (function_exists('body')) { return body(); }
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/** Un entier strictement positif lu dans le corps, ou null. */
function fmIdCorps(array $b, string $k): ?int
{
    $v = $b[$k] ?? null;
    if (is_int($v) && $v > 0) { return $v; }
    if (is_string($v) && preg_match('/^\d{1,9}$/', $v) && (int) $v > 0) { return (int) $v; }
    return null;
}

/** Un refus propre : code HTTP et message, avec ce que le panel a répondu. */
function fmRefus(int $http, string $message, array $extra = []): array
{
    http_response_code($http);
    return ['ok' => false, 'error' => $message] + $extra;
}

/** Une ligne d'appel d'écriture pour l'écran (jamais le corps complet). */
function fmAppelEcrit(string $methode, string $chemin, array $r): array
{
    return ['methode' => $methode, 'chemin' => $chemin, 'code' => $r['code'], 'erreur' => $r['erreur'],
        'reponse' => is_array($r['data']) ? fmScrub(array_slice($r['data'], 0, 6, true)) : (is_scalar($r['data']) ? $r['data'] : null)];
}

/** Les compétences d'un niveau, relues : `[ids, liaisons{cid: idLiaison}, appel]`. */
function fmRelireNiveau(int $nid): array
{
    $c = '/position-levels/' . $nid . '/competencies';
    $r = PanelApi::requete('GET', $c);
    $ids = []; $liaisons = [];
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($r['data'])) {
        foreach (fmListe(isset($r['data']['data']) && !array_is_list($r['data']) ? $r['data']['data'] : $r['data']) as $x) {
            $cid = fmInt($x, ['competency_id', 'id_competency', 'competence_id', 'id']);
            if ($cid === null) { continue; }
            $ids[] = $cid;
            $lid = fmInt($x, ['id']);
            if ($lid !== null && $lid !== $cid) { $liaisons[$cid] = $lid; }
        }
    }
    return [array_values(array_unique($ids)), $liaisons, $r];
}

/** Une compétence et ses liens, relus : `[competence|null, liens, brut, appel]`. */
function fmRelireCompetence(int $cid): array
{
    $r = PanelApi::requete('GET', '/competencies/' . $cid . '?include=operational_procedures,trainings,knowledge_resources&lang_code=fr');
    $d = $r['code'] >= 200 && $r['code'] < 300 && is_array($r['data']) ? (isset($r['data']['data']) && is_array($r['data']['data']) && !array_is_list($r['data']) ? $r['data']['data'] : $r['data']) : null;
    if (!is_array($d) || fmInt($d, ['id']) === null) { return [null, fmLiens(is_array($d) ? $d : []), $d, $r]; }
    return [fmCompetence($d), fmLiens($d), $d, $r];
}

/** Les postes et compétences d'une personne, relus. */
function fmRelireEmploye(int $eid): array
{
    $r = PanelApi::requete('GET', '/employees/' . $eid . '?include=competencies,positions');
    $d = $r['code'] >= 200 && $r['code'] < 300 && is_array($r['data']) ? (isset($r['data']['data']) && is_array($r['data']['data']) && !array_is_list($r['data']) ? $r['data']['data'] : $r['data']) : null;
    if (!is_array($d)) { return [null, $r]; }
    $postes = [];
    foreach (is_array($d['positions'] ?? null) ? $d['positions'] : [] as $p) {
        if (!is_array($p)) { continue; }
        $postes[] = ['id' => fmInt($p, ['id', 'position_id']), 'nom' => fmTexte($p, ['name']), 'niveauId' => fmInt($p, ['level_id']),
            'niveau' => fmTexte($p, ['level_name']), 'ordre' => fmInt($p, ['level_order'])];
    }
    $comps = [];
    foreach (is_array($d['competencies'] ?? null) ? $d['competencies'] : [] as $c) {
        if (is_array($c) && ($cid = fmInt($c, ['competency_id', 'id'])) !== null) { $comps[] = $cid; }
    }
    $nom = fmTexte($d, ['display_name']) ?? trim((fmTexte($d, ['name']) ?? '') . ' ' . (fmTexte($d, ['surname']) ?? ''));
    return [['id' => $eid, 'nom' => $nom !== '' ? $nom : null, 'postes' => $postes, 'competences' => array_values(array_unique($comps))], $r];
}

/** Le niveau (et son poste) dans un référentiel en cache — pour le journal et les corrections. */
function fmNiveauDans(array $ref, int $nid): ?array
{
    foreach ($ref['postes'] ?? [] as $p) {
        foreach ($p['niveaux'] ?? [] as $n) {
            if (($n['id'] ?? null) === $nid) { return ['poste' => $p['nom'] ?? '', 'niveau' => $n['nom'] ?? '']; }
        }
    }
    return null;
}

/** Le libellé d'un niveau pour le journal, lu dans le cache du référentiel. */
function fmNomNiveau(int $nid): string
{
    $c = setting(fmCleCache('/formation/referentiel'));
    $n = is_array($c) && is_array($c['r'] ?? null) ? fmNiveauDans($c['r'], $nid) : null;
    return $n ? $n['poste'] . ' › ' . $n['niveau'] : 'niveau #' . $nid;
}

/** Le nom d'une compétence pour le journal (cache, sinon l'identifiant). */
function fmNomCompetence(int $cid): string
{
    $c = setting(fmCleCache('/formation/referentiel'));
    foreach (is_array($c) && is_array($c['r'] ?? null) ? ($c['r']['competences'] ?? []) : [] as $x) {
        if (($x['id'] ?? null) === $cid) { return (string) $x['nom']; }
    }
    return 'compétence #' . $cid;
}

/** Corrige les compétences d'un niveau dans le référentiel en cache. */
function fmPatchNiveau(int $nid, array $ids): void
{
    fmCachePatch('/formation/referentiel', [], static function (array $r) use ($nid, $ids): array {
        foreach ($r['postes'] as &$p) {
            foreach ($p['niveaux'] as &$n) {
                if (($n['id'] ?? null) === $nid) { $n['competences'] = $ids; $n['competencesLues'] = true; }
            }
            unset($n);
        }
        unset($p);
        return $r;
    });
}

/**
 * POST /formation/niveau/{nid}/competences {competence} — exiger une
 * compétence à ce niveau. La route du panel n'a pas de contrat : le corps
 * porte les deux noms usuels (competency_id, id_competency), le panel garde
 * celui qu'il connaît. La relecture dit si c'est passé.
 */
function wr_formation_niveau_ajouter(int $nid): array
{
    if ($e = fmPret()) { return $e; }
    $cid = fmIdCorps(fmCorps(), 'competence');
    if ($cid === null) { return fmRefus(422, 'competence (identifiant) attendu'); }
    [$avant] = fmRelireNiveau($nid);
    if (in_array($cid, $avant, true)) { return ['ok' => true, 'deja' => true, 'niveau' => ['id' => $nid, 'competences' => $avant]]; }
    $chemin = '/position-levels/' . $nid . '/competencies';
    $w = PanelApi::requete('POST', $chemin, ['competency_id' => $cid, 'id_competency' => $cid]);
    $appels = [fmAppelEcrit('POST', $chemin, $w)];
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur'), ['appels' => $appels]); }
    [$apres] = fmRelireNiveau($nid);
    if (!in_array($cid, $apres, true)) {
        return fmRefus(502, 'le panel a répondu HTTP ' . $w['code'] . ' mais la compétence n’apparaît pas au niveau — rien n’a changé', ['appels' => $appels]);
    }
    fmPatchNiveau($nid, $apres);
    fmJournal(fmNomNiveau($nid), 'Compétence exigée ajoutée : « ' . fmNomCompetence($cid) . ' »');
    return ['ok' => true, 'niveau' => ['id' => $nid, 'competences' => $apres], 'appels' => $appels];
}

/** DELETE /formation/niveau/{nid}/competences/{cid} — ne plus l'exiger à ce niveau. */
function wr_formation_niveau_retirer(int $nid, int $cid): array
{
    if ($e = fmPret()) { return $e; }
    [$avant, $liaisons] = fmRelireNiveau($nid);
    if (!in_array($cid, $avant, true)) { return ['ok' => true, 'deja' => true, 'niveau' => ['id' => $nid, 'competences' => $avant]]; }
    $appels = [];
    $essais = [$cid];
    if (isset($liaisons[$cid])) { $essais[] = $liaisons[$cid]; }
    $apres = $avant;
    foreach ($essais as $x) {
        $chemin = '/position-levels/' . $nid . '/competencies/' . $x;
        $w = PanelApi::requete('DELETE', $chemin);
        $appels[] = fmAppelEcrit('DELETE', $chemin, $w);
        [$apres] = fmRelireNiveau($nid);
        if (!in_array($cid, $apres, true)) { break; }
    }
    if (in_array($cid, $apres, true)) {
        return fmRefus(502, 'la compétence est toujours exigée à ce niveau après le retrait — le panel ne l’a pas supprimée', ['appels' => $appels]);
    }
    fmPatchNiveau($nid, $apres);
    fmJournal(fmNomNiveau($nid), 'Compétence exigée retirée : « ' . fmNomCompetence($cid) . ' »');
    return ['ok' => true, 'niveau' => ['id' => $nid, 'competences' => $apres], 'appels' => $appels];
}

/** Les trois sortes de liens d'une compétence : route du panel, champ du corps, clé de fmLiens(). */
const FM_LIENS = [
    'formation'  => ['trainings', 'training_id', 'formations', 'Formation'],
    'procedure'  => ['operational-procedures', 'id_operational_procedure', 'procedures', 'Procédure'],
    'ressource'  => ['knowledge-resources', 'knowledge_resource_id', 'ressources', 'Ressource'],
];

/** L'identifiant de liaison d'un lien, lu dans la compétence brute. */
function fmIdLiaison(array $brut, string $type, int $cible): ?int
{
    $k = ['formation' => 'trainings', 'procedure' => 'operational_procedures', 'ressource' => 'knowledge_resources'][$type];
    $cle = FM_LIENS[$type][1];
    foreach (is_array($brut[$k] ?? null) ? $brut[$k] : [] as $x) {
        if (is_array($x) && fmInt($x, [$cle]) === $cible) {
            $lid = fmInt($x, ['id']);
            return $lid !== null && $lid !== $cible ? $lid : null;
        }
    }
    return null;
}

/** Corrige les liens d'une compétence dans le cache du lot des liens. */
function fmPatchLiens(int $cid, array $liens): void
{
    fmCachePatch('/formation/liens', [], static function (array $r) use ($cid, $liens): array {
        if (!isset($r['liens']) || !is_array($r['liens'])) { $r['liens'] = []; }
        $r['liens'][(string) $cid] = $liens;
        return $r;
    });
}

/** POST /formation/competence/{cid}/liens {type: formation|procedure|ressource, id} — rattacher. */
function wr_formation_lien_ajouter(int $cid): array
{
    if ($e = fmPret()) { return $e; }
    $b = fmCorps();
    $type = (string) ($b['type'] ?? '');
    $id = fmIdCorps($b, 'id');
    if (!isset(FM_LIENS[$type]) || $id === null) { return fmRefus(422, 'type (formation, procedure, ressource) et id attendus'); }
    [$comp, $avant] = fmRelireCompetence($cid);
    if ($comp === null) { return fmRefus(404, 'compétence inconnue du panel'); }
    [$route, $champ, $cle, $lib] = FM_LIENS[$type];
    $deja = static fn (array $l) => in_array($id, array_column($l[$cle], 'id'), true);
    if ($deja($avant)) { return ['ok' => true, 'deja' => true, 'competence' => $cid, 'liens' => $avant]; }
    $chemin = '/competencies/' . $cid . '/' . $route;
    $w = PanelApi::requete('POST', $chemin, [$champ => $id]);
    $appels = [fmAppelEcrit('POST', $chemin, $w)];
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur'), ['appels' => $appels]); }
    [, $apres] = fmRelireCompetence($cid);
    if (!$deja($apres)) { return fmRefus(502, 'le panel a répondu HTTP ' . $w['code'] . ' mais le lien n’apparaît pas — rien n’a changé', ['appels' => $appels]); }
    fmPatchLiens($cid, $apres);
    $nom = array_values(array_filter($apres[$cle], static fn ($x) => $x['id'] === $id))[0]['nom'] ?? ('#' . $id);
    fmJournal($comp['nom'], $lib . ' rattachée : « ' . $nom . ' »');
    return ['ok' => true, 'competence' => $cid, 'liens' => $apres, 'appels' => $appels];
}

/** DELETE /formation/competence/{cid}/liens/{type}/{id} — détacher. */
function wr_formation_lien_retirer(int $cid, string $type, int $id): array
{
    if ($e = fmPret()) { return $e; }
    if (!isset(FM_LIENS[$type])) { return fmRefus(422, 'type inconnu'); }
    [$comp, $avant, $brut] = fmRelireCompetence($cid);
    if ($comp === null) { return fmRefus(404, 'compétence inconnue du panel'); }
    [$route, , $cle, $lib] = FM_LIENS[$type];
    $present = static fn (array $l) => in_array($id, array_column($l[$cle], 'id'), true);
    if (!$present($avant)) { return ['ok' => true, 'deja' => true, 'competence' => $cid, 'liens' => $avant]; }
    $nom = array_values(array_filter($avant[$cle], static fn ($x) => $x['id'] === $id))[0]['nom'] ?? ('#' . $id);
    $essais = [$id];
    if (($lid = fmIdLiaison(is_array($brut) ? $brut : [], $type, $id)) !== null) { $essais[] = $lid; }
    $appels = [];
    $apres = $avant;
    foreach ($essais as $x) {
        $chemin = '/competencies/' . $cid . '/' . $route . '/' . $x;
        $w = PanelApi::requete('DELETE', $chemin);
        $appels[] = fmAppelEcrit('DELETE', $chemin, $w);
        [, $apres] = fmRelireCompetence($cid);
        if (!$present($apres)) { break; }
    }
    if ($present($apres)) { return fmRefus(502, 'le lien est toujours là après le retrait — le panel ne l’a pas supprimé', ['appels' => $appels]); }
    fmPatchLiens($cid, $apres);
    fmJournal($comp['nom'], $lib . ' détachée : « ' . $nom . ' »');
    return ['ok' => true, 'competence' => $cid, 'liens' => $apres, 'appels' => $appels];
}

/** Les champs d'une compétence lus dans le corps : nom, description, vérification, sous-catégorie. */
function fmChampsCompetence(array $b, bool $creation): array
{
    $o = [];
    $txt = static function ($v, int $max): ?string {
        if (!is_string($v)) { return null; }
        $v = trim(str_replace("\0", '', $v));
        return mb_substr($v, 0, $max);
    };
    if (array_key_exists('nom', $b)) { $o['name'] = $txt($b['nom'], 255); }
    if (array_key_exists('description', $b)) { $o['description'] = $txt($b['description'], 5000); }
    if (array_key_exists('verification', $b)) { $o['verification_method'] = $txt($b['verification'], 2000); }
    if (array_key_exists('sousCategorie', $b)) { $o['id_subcategory'] = fmIdCorps($b, 'sousCategorie'); }
    $o = array_filter($o, static fn ($v) => $v !== null);
    if ($creation && (($o['name'] ?? '') === '' || !isset($o['id_subcategory']))) { return []; }
    if (isset($o['name']) && $o['name'] === '') { unset($o['name']); }
    return $o;
}

/** Ajoute ou remplace une compétence dans le référentiel en cache. */
function fmPatchCompetence(array $c): void
{
    fmCachePatch('/formation/referentiel', [], static function (array $r) use ($c): array {
        $trouve = false;
        foreach ($r['competences'] as &$x) {
            if (($x['id'] ?? null) === $c['id']) { $x = $c + ['horsCatalogue' => false]; $trouve = true; }
        }
        unset($x);
        if (!$trouve) { $r['competences'][] = $c; }
        return $r;
    });
}

/** POST /formation/competences {sousCategorie, nom, description?, verification?} — créer. */
function wr_formation_competence_creer(): array
{
    if ($e = fmPret()) { return $e; }
    $champs = fmChampsCompetence(fmCorps(), true);
    if (!$champs) { return fmRefus(422, 'nom et sous-catégorie obligatoires'); }
    $w = PanelApi::requete('POST', '/competencies', $champs);
    $appels = [fmAppelEcrit('POST', '/competencies', $w)];
    $nouv = is_array($w['data']) ? fmInt($w['data'], ['inserted_id', 'id']) : null;
    if ($w['code'] < 200 || $w['code'] >= 300 || $nouv === null) {
        return fmRefus(502, 'le panel n’a pas créé la compétence : ' . ($w['erreur'] ?? 'pas d’identifiant rendu'), ['appels' => $appels]);
    }
    [$comp] = fmRelireCompetence($nouv);
    if ($comp === null) { return fmRefus(502, 'compétence #' . $nouv . ' annoncée créée, mais introuvable à la relecture', ['appels' => $appels]); }
    fmPatchCompetence($comp);
    fmPatchLiens($nouv, ['procedures' => [], 'formations' => [], 'ressources' => []]);
    fmJournal($comp['nom'], 'Compétence créée (#' . $nouv . ', ' . ($comp['sousCategorie'] ?? 'sous-catégorie ' . $champs['id_subcategory']) . ')');
    return ['ok' => true, 'competence' => $comp, 'appels' => $appels];
}

/** PATCH /formation/competences/{cid} {nom?, description?, verification?, sousCategorie?} — modifier. */
function wr_formation_competence_modifier(int $cid): array
{
    if ($e = fmPret()) { return $e; }
    $champs = fmChampsCompetence(fmCorps(), false);
    // Un PATCH vide fait échouer la requête SQL du panel : on ne l'envoie pas.
    if (!$champs) { return fmRefus(422, 'aucun champ à modifier'); }
    [$avant] = fmRelireCompetence($cid);
    if ($avant === null) { return fmRefus(404, 'compétence inconnue du panel'); }
    $w = PanelApi::requete('PATCH', '/competencies/' . $cid, $champs);
    $appels = [fmAppelEcrit('PATCH', '/competencies/' . $cid, $w)];
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur'), ['appels' => $appels]); }
    [$apres, , $brut] = fmRelireCompetence($cid);
    if ($apres === null) { return fmRefus(502, 'compétence introuvable à la relecture', ['appels' => $appels]); }
    // Comparé aux champs BRUTS : `nom` et `description` de l'écran retombent sur base_name /
    // base_description quand le champ est vide, un effacement passerait pour un refus.
    $ecarts = [];
    $lus = ['name' => $brut['name'] ?? '', 'description' => $brut['description'] ?? '', 'verification_method' => $brut['verification_method'] ?? '',
        'id_subcategory' => $brut['id_subcategory'] ?? ($brut['subcategory_id'] ?? '')];
    foreach ($champs as $k => $v) {
        if (trim((string) $lus[$k]) !== trim((string) $v)) { $ecarts[] = $k; }
    }
    if ($ecarts) { return fmRefus(502, 'le panel n’a pas enregistré : ' . implode(', ', $ecarts), ['appels' => $appels, 'competence' => $apres]); }
    fmPatchCompetence($apres);
    $noms = ['name' => 'nom', 'description' => 'description', 'verification_method' => 'méthode de vérification', 'id_subcategory' => 'sous-catégorie'];
    fmJournal($apres['nom'], 'Compétence modifiée : ' . implode(', ', array_map(static fn ($k) => $noms[$k], array_keys($champs)))
        . (isset($champs['name']) && $champs['name'] !== $avant['nom'] ? ' (ancien nom « ' . $avant['nom'] . ' »)' : ''));
    return ['ok' => true, 'competence' => $apres, 'appels' => $appels];
}

/** DELETE /formation/competences/{cid} — supprimer du catalogue. */
function wr_formation_competence_supprimer(int $cid): array
{
    if ($e = fmPret()) { return $e; }
    [$avant] = fmRelireCompetence($cid);
    if ($avant === null) { return ['ok' => true, 'deja' => true, 'competence' => $cid]; }
    $w = PanelApi::requete('DELETE', '/competencies/' . $cid);
    $appels = [fmAppelEcrit('DELETE', '/competencies/' . $cid, $w)];
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur'), ['appels' => $appels]); }
    [$apres] = fmRelireCompetence($cid);
    if ($apres !== null) { return fmRefus(502, 'la compétence existe toujours après la suppression — le panel ne l’a pas supprimée (liée ailleurs ?)', ['appels' => $appels]); }
    fmCachePatch('/formation/referentiel', [], static function (array $r) use ($cid): array {
        $r['competences'] = array_values(array_filter($r['competences'], static fn ($x) => ($x['id'] ?? null) !== $cid));
        foreach ($r['postes'] as &$p) {
            foreach ($p['niveaux'] as &$n) { $n['competences'] = array_values(array_filter($n['competences'], static fn ($x) => $x !== $cid)); }
            unset($n);
        }
        unset($p);
        return $r;
    });
    fmJournal($avant['nom'], 'Compétence supprimée du catalogue (#' . $cid . ')');
    return ['ok' => true, 'competence' => $cid, 'appels' => $appels];
}

/** Corrige la fiche d'une personne dans le cache de l'équipe de son magasin. */
function fmPatchEmploye(?int $shop, array $fiche): void
{
    if ($shop === null) { return; }
    fmCachePatch('/formation/equipe', ['shop' => (string) $shop], static function (array $r) use ($fiche): array {
        foreach ($r['employes'] ?? [] as $i => $e) {
            if (($e['id'] ?? null) === $fiche['id']) {
                $r['employes'][$i]['postes'] = $fiche['postes'];
                $r['employes'][$i]['competences'] = $fiche['competences'];
                $r['employes'][$i]['lu'] = true;
            }
        }
        return $r;
    });
}

/** Le nom d'une personne pour le journal, lu dans le cache de l'équipe. */
function fmNomEmploye(?int $shop, int $eid): string
{
    $c = $shop !== null ? setting(fmCleCache('/formation/equipe', ['shop' => (string) $shop])) : null;
    foreach (is_array($c) && is_array($c['r'] ?? null) ? ($c['r']['employes'] ?? []) : [] as $e) {
        if (($e['id'] ?? null) === $eid) { return (string) $e['nom']; }
    }
    return 'employé #' . $eid;
}

/**
 * PUT /formation/employe/{eid}/competences/{cid} — la personne a validé la
 * compétence. DELETE — on la lui retire. `?shop=` : le magasin, pour corriger
 * l'écran Équipes en cache.
 */
function wr_formation_employe_competence(string $methode, int $eid, int $cid): array
{
    if ($e = fmPret()) { return $e; }
    $shop = fmId('shop');
    [$avant] = fmRelireEmploye($eid);
    if ($avant === null) { return fmRefus(404, 'personne inconnue du panel'); }
    $voulu = $methode === 'PUT';
    if (in_array($cid, $avant['competences'], true) === $voulu) { return ['ok' => true, 'deja' => true, 'employe' => $avant]; }
    $chemin = '/employees/' . $eid . '/competencies' . ($voulu ? '' : '/' . $cid);
    $w = PanelApi::requete($methode, $chemin, $voulu ? ['competency_id' => $cid] : null);
    $appels = [fmAppelEcrit($methode, $chemin, $w)];
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur'), ['appels' => $appels]); }
    [$apres] = fmRelireEmploye($eid);
    if ($apres === null || in_array($cid, $apres['competences'], true) !== $voulu) {
        return fmRefus(502, 'le panel a répondu HTTP ' . $w['code'] . ' mais la fiche n’a pas changé', ['appels' => $appels]);
    }
    fmPatchEmploye($shop, $apres);
    fmJournal(fmNomEmploye($shop, $eid), ($voulu ? 'Compétence validée' : 'Validation retirée') . ' : « ' . fmNomCompetence($cid) . ' »');
    return ['ok' => true, 'employe' => $apres, 'appels' => $appels];
}

/** PUT /formation/employe/{eid}/poste {poste, niveau} — affecter un poste et un niveau. */
function wr_formation_employe_poste(int $eid): array
{
    if ($e = fmPret()) { return $e; }
    $b = fmCorps();
    $poste = fmIdCorps($b, 'poste');
    $niveau = fmIdCorps($b, 'niveau');
    $shop = fmId('shop');
    if ($poste === null || $niveau === null) { return fmRefus(422, 'poste et niveau attendus'); }
    [$avant] = fmRelireEmploye($eid);
    if ($avant === null) { return fmRefus(404, 'personne inconnue du panel'); }
    $tient = static fn (array $f) => (bool) array_filter($f['postes'], static fn ($p) => $p['id'] === $poste && $p['niveauId'] === $niveau);
    if ($tient($avant)) { return ['ok' => true, 'deja' => true, 'employe' => $avant]; }
    $chemin = '/employees/' . $eid . '/positions';
    $w = PanelApi::requete('PUT', $chemin, ['position_id' => $poste, 'level_id' => $niveau]);
    $appels = [fmAppelEcrit('PUT', $chemin, $w)];
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur'), ['appels' => $appels]); }
    [$apres] = fmRelireEmploye($eid);
    if ($apres === null || !$tient($apres)) { return fmRefus(502, 'le panel a répondu HTTP ' . $w['code'] . ' mais le poste n’a pas changé', ['appels' => $appels]); }
    fmPatchEmploye($shop, $apres);
    $ancien = implode(', ', array_map(static fn ($p) => ($p['nom'] ?? 'poste') . ' · ' . ($p['niveau'] ?? 'niveau'), $avant['postes'])) ?: 'aucun';
    $nouveau = fmNomNiveau($niveau);
    fmJournal(fmNomEmploye($shop, $eid), 'Niveau affecté : ' . $nouveau . ' (avant : ' . $ancien . ')');
    return ['ok' => true, 'employe' => $apres, 'appels' => $appels];
}
