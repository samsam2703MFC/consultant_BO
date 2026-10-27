<?php
declare(strict_types=1);

/**
 * GET /suivi/sources — d'où viennent les données des consultants et des visites, et ce qu'il manque au panel pour que
 * tout passe par une API, en lecture et en écriture (demande du 26/10/2026 : « un rapport de quelles API viennent
 * d'atelier.tfbuddy.com, quelles viennent de table locale, et la liste des API manquantes avec la structure nécessaire
 * et la structure de la table. Tout doit être API en lecture et en écriture »).
 *
 * Comme l'inventaire du marketing (src/marketing_sources.php, dont il reprend l'index et le relevé des tables), tout se
 * lit dans le CODE : seule la liste des routes du module est déclarée (SS_ROUTES, le contrat des écrans), et
 * bin/tests_suivi.php vérifie que chacune existe et est routée. Pour chaque route, on suit les fonctions appelées
 * (SS_PROFONDEUR niveaux, et les fonctions passées par leur nom, 'ep_…') et l'on relève :
 *  - les chemins du panel TFBuddy (atelierby.tfbuddy.com/api/v1) et de l'ERP réellement appelés, écrits avec leurs
 *    paramètres ({shopId}, {date}…) — y compris ceux des méthodes de PanelApi / ErpApi qu'elle emploie ;
 *  - les tables nommées dans le SQL, rangées par origine : `con` (les tables du module, CON_TABLES), `cockpit` (une
 *    autre table créée par ce dépôt), `tfbuddy` (la base TFBuddy partagée, lue directement, sans API) ;
 *  - les autres services (Google, SMTP, Anthropic…).
 * Puis la base dit ce qui existe : lignes, colonnes, CREATE TABLE. Les API manquantes se déduisent de là : pour chaque
 * table `con_`, la ressource que le panel devrait exposer (CON_TABLES[…]['api']) avec ses cinq verbes, le corps JSON
 * tiré des colonnes et la structure SQL ; pour chaque table TFBuddy lue en direct, la lecture à ouvrir (SS_TFB).
 *
 * Une analyse par motifs n'est pas un compilateur : un nom de table fabriqué à l'exécution lui échappe, et une fonction
 * suivie compte toutes ses branches. L'écran le dit. Rien n'est gardé : chaque ouverture relit le code et la base.
 */

/** Les routes du module : [écran, méthode, chemin, fonction, ce qu'elle fait]. */
const SS_ROUTES = [
    ['tablette', 'GET', '/visites/app', 'ep_visites_app', 'L’application : visites, checklists, réglages, magasins'],
    ['tablette', 'POST', '/visites', 'wr_visites_post', 'Planifier une visite'],
    ['tablette', 'PUT', '/visites/{id}', 'wr_visites_put', 'Commencer, constater, signer, terminer une visite'],
    ['tablette', 'PUT', '/visites/{id}/points', 'wr_visites_points_put', 'Les réponses de la checklist'],
    ['tablette', 'POST', '/visites/photos', 'wr_visites_photos_post', 'Une photo : contrôle, correction, signature'],
    ['tablette', 'GET', '/visites/boutique/{shop}', 'ep_visites_boutique', 'Le dossier du magasin pour la visite'],
    ['tablette', 'GET', '/visites/kpi/{shop}', 'ep_visites_kpi', 'KPI et santé du magasin'],
    ['tablette', 'GET', '/visites/budget/{shop}', 'ep_visites_budget', 'Le CA face au budget, le mini P&L'],
    ['tablette', 'GET', '/visites/taches/{shop}', 'ep_visites_taches', 'Les tâches des derniers mois'],
    ['tablette', 'GET', '/visites/conformite', 'ep_visites_conformite', 'Assortiment obligatoire, planogramme'],
    ['tablette', 'GET', '/visites/campagnes', 'ep_visites_campagnes', 'Les campagnes en cours'],
    ['tablette', 'GET', '/franchises/meteo', 'ep_franchises_meteo', 'La météo du franchisé, les précédentes'],
    ['tablette', 'POST', '/franchises/meteo', 'wr_franchises_meteo', 'Noter la météo du franchisé'],
    ['tablette', 'POST', '/consultants/taches', 'wr_consultants_tache', 'Une tâche du consultant'],
    ['tablette', 'PUT', '/consultants/taches/{id}', 'wr_consultants_tache_put', 'Faire, reporter une tâche du consultant'],
    ['tablette', 'GET', '/consultants/checklists', 'ep_consultants_checklists', 'Les checklists du cadre et leurs tâches'],
    ['tablette', 'POST', '/consultants/checklists', 'wr_consultants_checklists', 'Cocher une tâche du cadre'],
    ['tablette', 'PUT', '/equipe/{shop}', 'wr_equipe_put', 'L’effectif relevé'],
    ['bureau', 'GET', '/suivi/tableau', 'ep_suivi_tableau', 'Les visites et ce qui attend le bureau'],
    ['bureau', 'GET', '/suivi/preparation', 'ep_suivi_preparation', 'Le dossier de préparation'],
    ['bureau', 'PUT', '/suivi/preparation/{id}', 'wr_suivi_preparation', 'Préparer, envoyer l’ordre du jour'],
    ['bureau', 'GET', '/suivi/rapport', 'ep_suivi_rapport', 'Le rapport de visite'],
    ['bureau', 'GET', '/suivi/rapport/apercu', 'ep_suivi_rapport_apercu', 'L’aperçu du rapport'],
    ['bureau', 'PUT', '/suivi/rapport/{id}', 'wr_suivi_rapport', 'Adapter le rapport, valider une correction'],
    ['bureau', 'POST', '/suivi/rapport/{id}/envoyer', 'wr_suivi_rapport_envoyer', 'Envoyer le rapport au franchisé'],
    ['bureau', 'GET', '/visites/synthese', 'ep_visites_synthese', 'La synthèse de la semaine'],
    ['bureau', 'GET', '/visites/cadre', 'ep_visites_cadre', 'Le cadre de visite des magasins'],
    ['bureau', 'PUT', '/visites/cadre', 'wr_visites_cadre_put', 'Régler le cadre de visite'],
    ['bureau', 'GET', '/consultants/gestion', 'ep_consultants_gestion', 'Les consultants, leur agenda, leurs tâches'],
    ['bureau', 'GET', '/consultants', 'ep_consultants', 'Les consultants du panel'],
    ['bureau', 'POST', '/consultants/note', 'wr_consultant_note', 'Une note du consultant au panel'],
    ['bureau', 'GET', '/visites/reglages', 'ep_visites_reglages', 'Les réglages de la tablette'],
    ['bureau', 'PUT', '/visites/reglages', 'wr_visites_reglages_put', 'Régler la tablette'],
    ['bureau', 'GET', '/visites/cron', 'ep_visites_cron', 'L’horloge des visites (relances, envois)'],
    ['bureau', 'POST', '/visites/tick', 'wr_visites_tick', 'Passer l’horloge à la main'],
    ['agenda', 'GET', '/consultants/{id}/visites.ics', 'vcIcsSortie', 'L’agenda d’un consultant (Google Agenda)'],
    ['mystere', 'GET', '/suivi/msp', 'ep_suivi_msp', 'Les passages du client mystère'],
    ['mystere', 'POST', '/suivi/msp', 'wr_suivi_msp', 'Encoder un passage'],
    ['mystere', 'GET', '/scoring/msp', 'ep_scoring_msp', 'Le client mystère par trimestre'],
    ['mystere', 'POST', '/scoring/msp', 'wr_scoring_msp', 'Encoder un trimestre'],
    ['mystere', 'DELETE', '/scoring/msp/{id}', 'wr_scoring_msp_suppr', 'Retirer un trimestre'],
    ['mystere', 'POST', '/msp', 'wr_msp_post', 'Client mystère, ancien encodage mensuel'],
    ['mystere', 'DELETE', '/msp/{id}', 'wr_msp_delete', 'Retirer un encodage mensuel'],
    ['franchise', 'GET', '/suivi/franchise', 'ep_suivi_franchise', 'La page du franchisé (son lien, sans mot de passe)'],
    ['franchise', 'POST', '/suivi/franchise/correction', 'wr_suivi_franchise_correction', 'La photo de correction du franchisé'],
    ['franchise', 'POST', '/suivi/franchise/lu', 'wr_suivi_franchise_lu', 'Le rapport lu'],
    ['franchise', 'GET', '/suivi/lien', 'ep_suivi_lien', 'Le lien du franchisé et son courriel'],
    ['franchise', 'POST', '/suivi/lien/envoyer', 'wr_suivi_lien_envoyer', 'Envoyer son lien au franchisé'],
    ['regles', 'GET', '/suivi/regles', 'ep_suivi_regles', 'Qui fait quoi, délais, modèles de courriel'],
    ['regles', 'PUT', '/suivi/regles', 'wr_suivi_regles', 'Enregistrer les règles'],
    ['regles', 'POST', '/suivi/modeles/apercu', 'wr_suivi_modeles_apercu', 'L’aperçu d’un courriel'],
    ['regles', 'POST', '/suivi/modeles/essai', 'wr_suivi_modeles_essai', 'Un courriel d’essai'],
    ['fiche', 'GET', '/franchises', 'ep_franchises', 'Le classement du réseau'],
    ['fiche', 'GET', '/franchises/fiche', 'ep_franchises_fiche', 'La fiche d’un franchisé'],
    ['fiche', 'GET', '/scoring', 'ep_scoring', 'Le scoring du trimestre'],
    ['fiche', 'GET', '/scoring/rapport', 'ep_scoring_rapport', 'Le rapport A4 du scoring'],
    ['fiche', 'POST', '/scoring/envoyer', 'wr_scoring_envoyer', 'Envoyer le scoring aux magasins'],
    ['taches', 'GET', '/taches/suivi', 'ep_taches_suivi', 'Les tâches validées et les signalements'],
    ['taches', 'GET', '/controles/qualite', 'ep_controles_qualite', 'Les contrôles qualité du mois'],
    ['taches', 'POST', '/pwa/tasks/releve', 'wr_taches_releve', 'Relever les tâches du jour au panel'],
];

/** Les écrans, dans l'ordre du sous-menu. */
const SS_ECRANS = [
    'tablette' => 'Visite sur tablette', 'bureau' => 'Visites · le bureau', 'agenda' => 'Agenda des consultants',
    'mystere' => 'Client mystère', 'franchise' => 'Page du franchisé', 'regles' => 'Qui fait quoi',
    'fiche' => 'Fiche & scoring', 'taches' => 'Tâches du jour et contrôles',
];

const SS_PROFONDEUR = 6;

/** Les fonctions socle : on ne les suit pas, on dit ce qu'elles touchent (setting() lirait sinon tout le module des primes). */
const SS_FEUILLES = [
    'setting' => ['ceo_app_setting' => 'lecture'],
    'journalAdd' => ['ceo_journal_entry' => 'ecriture'],
    'ensureInstalled' => [], 'schemaOublier' => [], 'isMissingTable' => [],
];

/**
 * Les tables TFBuddy que le module lit directement dans la base partagée : ce qu'elles portent, et la lecture que le
 * panel expose déjà (`existe`) ou devrait exposer (`propose`).
 */
const SS_TFB = [
    'shops' => ['quoi' => 'Les magasins : nom, adresse, statut', 'existe' => 'GET /consultant/shops'],
    'user_membership' => ['quoi' => 'Les comptes et leurs rôles (consultants, franchisés)', 'existe' => 'GET /panel/consultants', 'propose' => 'GET /consultant/memberships?app=CONSULTANT&is_active=1'],
    'user_profile' => ['quoi' => 'Le nom et le courriel des comptes', 'existe' => 'GET /panel/consultants', 'propose' => 'GET /consultant/memberships/{id}/profile'],
    'todo_task' => ['quoi' => 'Les tâches prédéfinies (leurs libellés)', 'propose' => 'GET /consultant/task-templates'],
    'mac_task_review' => ['quoi' => 'Les notes posées sur les tâches faites (contrôle qualité)', 'propose' => 'GET /consultant/task-reviews?shop_id=&date_from=&date_to='],
    'transaction' => ['quoi' => 'Les tickets de caisse', 'existe' => 'GET /shops/{id}/transactions?date='],
    'transaction_product' => ['quoi' => 'Les lignes des tickets', 'existe' => 'GET /transactions/{id}?include=products'],
    'product' => ['quoi' => 'Le catalogue des produits', 'existe' => 'GET /products'],
    'product_recipe' => ['quoi' => 'La recette d’un produit', 'existe' => 'GET /recipes/{id}'],
    'recipe_cost' => ['quoi' => 'Le coût de revient des recettes', 'propose' => 'GET /recipes/{id}/cost'],
    'shop_product' => ['quoi' => 'Les produits d’un magasin et leur prix', 'existe' => 'GET /shops/{id}/products/available', 'propose' => 'GET /shops/{id}/products?include=prices'],
    'product_availability_period' => ['quoi' => 'Les périodes de disponibilité (gammes saisonnières)', 'propose' => 'GET /product-availability-periods'],
    'franchisee_employee' => ['quoi' => 'Les personnes des magasins', 'propose' => 'GET /shops/{id}/employees'],
    'franchisee_employee_schedule' => ['quoi' => 'Les heures du planning', 'existe' => 'GET /employees/{id}/schedule?date_from=&date_to=', 'propose' => 'GET /shops/{id}/schedule?date_from=&date_to='],
    'client' => ['quoi' => 'Les clients (pro, TVA)', 'propose' => 'GET /shops/{id}/clients?type=pro'],
    'mac_google_rating_snapshot' => ['quoi' => 'Le relevé mensuel de la note Google', 'propose' => 'GET /consultant/shops/{id}/google-ratings?date_from=&date_to='],
];

/* --- lire un chemin d'API dans le code ------------------------------------------------------------------------ */

/** Les arguments d'un appel, à partir de la parenthèse ouvrante : découpés aux virgules du premier niveau. */
function ssArguments(string $t, int $ouvre): array
{
    $args = []; $cur = ''; $prof = 0; $q = null; $n = strlen($t);
    for ($i = $ouvre + 1; $i < $n; $i++) {
        $c = $t[$i];
        if ($q !== null) { $cur .= $c; if ($c === '\\') { $cur .= $t[++$i] ?? ''; } elseif ($c === $q) { $q = null; } continue; }
        if ($c === '\'' || $c === '"') { $q = $c; $cur .= $c; continue; }
        if ($c === '(' || $c === '[') { $prof++; $cur .= $c; continue; }
        if ($c === ')' || $c === ']') { if ($prof === 0) { $args[] = trim($cur); return $args; } $prof--; $cur .= $c; continue; }
        if ($c === ',' && $prof === 0) { $args[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    return $args;
}

/** Une expression PHP de chemin rendue lisible : '/shops/' . $shopId . '/tasks?date=' . urlencode($d) → /shops/{shopId}/tasks?date={d}. */
function ssChemin(string $expr): ?string
{
    $parts = []; $cur = ''; $prof = 0; $q = null; $n = strlen($expr);
    for ($i = 0; $i < $n; $i++) {
        $c = $expr[$i];
        if ($q !== null) { $cur .= $c; if ($c === '\\') { $cur .= $expr[++$i] ?? ''; } elseif ($c === $q) { $q = null; } continue; }
        if ($c === '\'' || $c === '"') { $q = $c; $cur .= $c; continue; }
        if ($c === '(' || $c === '[') { $prof++; } elseif ($c === ')' || $c === ']') { $prof--; }
        if ($c === '.' && $prof === 0 && !ctype_digit($expr[$i + 1] ?? '') ) { $parts[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    $parts[] = trim($cur);
    $out = '';
    foreach ($parts as $p) {
        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/s', $p, $m)) { $out .= stripslashes($m[1]); continue; }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $p, $m)) {
            $out .= (string) preg_replace_callback('/\{?\$(\w+)(?:\[[\'"]?(\w+)[\'"]?\])?\}?/', static fn ($x) => '{' . ($x[2] ?? '' ?: $x[1]) . '}', $m[1]);
            continue;
        }
        // Une fonction qui fabrique la requête (http_build_query, self::q…) : la requête, pas une variable.
        if (preg_match('/^(?:[\w\\\\]+::)?(\w+)\s*\(/', $p, $fx) && !in_array($fx[1], ['urlencode', 'rawurlencode', 'intval', 'strval', 'trim', 'date'], true)) { $out .= '{query}'; continue; }
        if (preg_match_all('/\$(\w+)(?:\[[\'"](\w+)[\'"]\])?/', $p, $m, PREG_SET_ORDER)) { $d = end($m); $out .= '{' . (($d[2] ?? '') !== '' ? $d[2] : $d[1]) . '}'; continue; }
        $out .= '{…}';
    }
    if ($out === '' || $out[0] !== '/') { return null; }
    return ssCheminNormal((string) preg_replace('/\{(\w+)\}\{(\w+)\}/', '{$1}', $out));
}

/** Les paramètres d'un chemin sous un seul nom, d'un appel à l'autre : {sid}, {shopId} → {shop} ; {auj}, {j} → {date}. */
function ssCheminNormal(string $c): string
{
    $c = (string) preg_replace('#/shops/\{\w+\}#', '/shops/{shop}', $c);
    $c = (string) preg_replace('#/(transactions|attachments|recipes|products)/\{\w+\}#', '/$1/{id}', $c);
    $c = (string) preg_replace('#\{(?:auj|date|j|d|jour|depuis|du|au|ref|rd|veille)\}#', '{date}', $c);
    $c = (string) preg_replace('#\?\{(?:q|qs|query)\}#', '?{query}', $c);
    return (string) preg_replace('#(?<!\?)\{(?:q|qs)\}$#', '?{query}', $c);
}

/**
 * Les chemins rangés dans une variable avant l'appel : `$base = '/consultant/shops/' . $id . '/pnl';`, puis
 * `$chemins[] = $base . '/daily?' . …;` et `self::premierObjet($chemins)`. Rend variable → chemins.
 */
function ssVariablesChemin(string $corps): array
{
    $vars = [];
    if (!preg_match_all('/\$(\w+)\s*(\[[^\]=]*\])?\s*=(?!=|>)\s*([^;]+);/', $corps, $m, PREG_SET_ORDER)) { return []; }
    foreach ($m as [, $v, $ajout, $expr]) {
        $expr = trim($expr);
        if (!preg_match('/[\'"]\//', $expr) && !preg_match('/^\$\w+\s*\./', $expr)) { continue; }
        // Un tableau de chemins (['cats' => '/consultant/…', …]) : chaque valeur.
        $valeurs = str_starts_with($expr, '[') ? array_map(static fn ($x) => preg_match('/^[^=]*=>\s*(.*)$/s', $x, $y) ? $y[1] : $x, ssArguments($expr, 0)) : [$expr];
        foreach ($valeurs as $val) {
            $c = ssChemin(trim($val));
            if ($c === null && preg_match('/^\s*\$(\w+)\s*\.(.*)$/s', $val, $x) && isset($vars[$x[1]][0])) {
                $c = ssChemin("'" . str_replace("'", "\\'", $vars[$x[1]][0]) . "' ." . $x[2]);
            }
            if ($c === null) { continue; }
            // {base} : une variable de chemin déjà connue, remplacée par sa valeur.
            $c = (string) preg_replace_callback('/^\{(\w+)\}/', static fn ($y) => isset($vars[$y[1]][0]) ? $vars[$y[1]][0] : $y[0], $c);
            if ($ajout || count($valeurs) > 1 || isset($vars[$v]) && str_starts_with($expr, '[')) { $vars[$v][] = $c; } else { $vars[$v] = [$c]; }
        }
    }
    return array_map(static fn ($l) => array_values(array_unique($l)), $vars);
}

/** Les appels à une API dans un corps : [['m' => GET…, 'chemin' => …]]. `$pref` : 'PanelApi::', 'self::'… */
function ssAppelsApi(string $corps, string $pref, array $methodes = []): array
{
    $out = [];
    if (!preg_match_all('/' . preg_quote($pref, '/') . '(\w+)\s*\(/', $corps, $m, PREG_OFFSET_CAPTURE)) { return []; }
    $vars = null;
    foreach ($m[1] as $k => [$meth]) {
        $ouvre = $m[0][$k][1] + strlen($m[0][$k][0]) - 1;
        if (isset($methodes[$meth])) { foreach ($methodes[$meth] as $c) { $out[] = $c; } continue; }
        // envoi('PUT', chemin, corps) : le verbe est son premier argument.
        $VERBES = ['get' => 'GET', 'brut' => 'GET', 'sondeGet' => 'GET', 'sondeTexte' => 'GET', 'post' => 'POST', 'envoi' => null,
            'premierNonVide' => 'GET', 'premierObjet' => 'GET', 'getParallele' => 'GET'];
        if (!array_key_exists($meth, $VERBES)) { continue; }
        $verbe = $VERBES[$meth];
        $args = ssArguments($corps, $ouvre);
        if ($verbe === null) { $verbe = strtoupper(trim($args[0] ?? '', '\'" ')); array_shift($args); if (!preg_match('/^[A-Z]+$/', $verbe)) { $verbe = '?'; } }
        $a = $args[0] ?? '';
        $chemins = [];
        if (preg_match('/^\$(\w+)$/', $a, $x)) {
            $vars ??= ssVariablesChemin($corps);
            $chemins = $vars[$x[1]] ?? [];
        } elseif (str_starts_with($a, '[')) {
            foreach (ssArguments($a, 0) as $x) { if (($c = ssChemin($x)) !== null) { $chemins[] = $c; } }
        } elseif (($c = ssChemin($a)) !== null) { $chemins[] = $c; }
        foreach ($chemins as $c) { $out[] = ['m' => $verbe, 'chemin' => $c]; }
    }
    return $out;
}

/** Les méthodes d'une classe d'API et les chemins qu'elles appellent (shopTasks → GET /consultant/shops/{shopId}/tasks?date={date}). */
function ssMethodesClasse(string $fichier): array
{
    $t = is_file($fichier) ? (string) file_get_contents($fichier) : '';
    if (!preg_match_all('/(?:public|private|protected)\s+static\s+function\s+(\w+)\s*\(/', $t, $m, PREG_OFFSET_CAPTURE)) { return []; }
    // Le socle de la classe (le jeton, l'enveloppe HTTP) n'est pas un appel du module.
    $socle = ['get', 'post', 'envoi', 'brut', 'sondeGet', 'sondeTexte', 'getParallele', 'http', 'token', 'jetonSonde', 'config', 'statut',
        'configured', 'tester', 'liste', 'oublierJeton', 'premierNonVide', 'premierObjet', 'disponible', 'bornes', 'q'];
    $corps = [];
    foreach ($m[1] as $k => [$nom, $pos]) {
        $fin = isset($m[0][$k + 1]) ? $m[0][$k + 1][1] : strlen($t);
        if (!in_array($nom, $socle, true)) { $corps[$nom] = substr($t, $pos, $fin - $pos); }
    }
    $out = [];
    foreach ($corps as $nom => $c) { $out[$nom] = ssAppelsApi($c, 'self::') ?: ssAppelsApi($c, 'static::'); }
    // Une méthode qui en appelle une autre de la classe hérite de ses chemins.
    foreach ($corps as $nom => $c) {
        if ($out[$nom]) { continue; }
        foreach (array_keys($corps) as $autre) { if ($autre !== $nom && preg_match('/(?:self|static)::' . $autre . '\s*\(/', $c)) { $out[$nom] = array_merge($out[$nom], $out[$autre]); } }
    }
    return array_filter($out);
}

/** Une route suivie de proche en proche : ses tables, ses API, ses chemins du panel et de l'ERP. */
function ssSuivre(string $depart, array $index, array $mPanel, array $mErp): array
{
    $vus = [$depart => 0]; $file = [$depart]; $tables = []; $apis = []; $panel = []; $erp = [];
    // Chaque usage compte à part : une table que la route crée (ensureVisites…) sans la lire ni l'écrire n'est pas
    // une donnée de la route, seulement une installation.
    $poser = static function (string $t, string $mode) use (&$tables): void { $tables[$t][$mode] = true; };
    while ($file) {
        $f = array_shift($file);
        if (isset(SS_FEUILLES[$f])) { foreach (SS_FEUILLES[$f] as $t => $mode) { $poser($t, $mode); } if (SS_FEUILLES[$f]) { $apis['base'] = true; } continue; }
        if (!isset($index[$f])) { continue; }
        $corps = $index[$f]['corps'];
        foreach (msTablesDe($corps) as $t => $mode) { $poser($t, $mode); }
        foreach (MS_MOTIFS_API as $api => $motif) { if (preg_match($motif, $corps)) { $apis[$api] = true; } }
        foreach (ssAppelsApi($corps, 'PanelApi::', $mPanel) as $c) { $panel[$c['m'] . ' ' . $c['chemin']] = $c; }
        foreach (ssAppelsApi($corps, 'ErpApi::', $mErp) as $c) { $erp[$c['m'] . ' ' . $c['chemin']] = $c; }
        if ($vus[$f] >= SS_PROFONDEUR) { continue; }
        $suivantes = msAppels($f, $corps, $index);
        // Les fonctions passées par leur nom (vkAvecGet([...], 'ep_exploitation_jour'), rcServi(…, 'exJourCalcul', …)) sont suivies aussi.
        if (preg_match_all('/[\'"]([a-zA-Z_]\w{3,})[\'"]/', $corps, $m)) { foreach ($m[1] as $g) { if (isset($index[$g]) || isset(SS_FEUILLES[$g])) { $suivantes[] = $g; } } }
        foreach (array_unique($suivantes) as $g) {
            if (isset($vus[$g]) || in_array($g, MS_NE_PAS_SUIVRE, true)) { continue; }
            $vus[$g] = $vus[$f] + 1; $file[] = $g;
        }
    }
    if ($panel) { $apis['panel'] = true; }
    if ($erp) { $apis['erp'] = true; }
    // Le mode le plus fort de l'usage : écrite, sinon lue, sinon seulement créée.
    $tables = array_map(static fn ($m) => !empty($m['ecriture']) ? 'ecriture' : (!empty($m['lecture']) ? 'lecture' : 'creation'), $tables);
    ksort($tables); ksort($panel); ksort($erp);
    return ['tables' => $tables, 'apis' => array_keys($apis), 'panel' => array_values($panel), 'erp' => array_values($erp), 'fonctions' => count($vus)];
}

/** D'où vient une table : le module (con_), une autre table du cockpit, ou la base TFBuddy lue en direct. */
function ssOrigine(string $t, array $creees): string
{
    if (isset(CON_TABLES[$t])) { return 'con'; }
    // mar_campaign… : le module marketing autonome (public/api/marketing), sur le même serveur — local, pas TFBuddy.
    if (isset($creees[$t]) || (defined('MAR_TABLES') && isset(MAR_TABLES[$t])) || str_starts_with($t, 'mar_')) { return 'cockpit'; }
    return 'tfbuddy';
}

/* --- la structure, et l'API qu'elle appelle -------------------------------------------------------------------- */

/** Une colonne en JSON : le type, un exemple, obligatoire ou non. `$json` : la colonne texte porte du JSON (objet ou liste). */
function ssChamp(array $c, ?string $json): array
{
    $t = strtolower((string) $c['DATA_TYPE']); $ct = (string) $c['COLUMN_TYPE'];
    $long = preg_match('/\((\d+)\)/', $ct, $m) ? (int) $m[1] : null;
    if (in_array($t, ['int', 'integer', 'smallint', 'mediumint', 'bigint', 'tinyint'], true)) { $type = 'integer'; $ex = 1; }
    elseif (in_array($t, ['decimal', 'float', 'double'], true)) { $type = 'number'; $ex = 1.5; }
    elseif ($t === 'date') { $type = 'date'; $ex = '2026-10-26'; }
    elseif (in_array($t, ['datetime', 'timestamp'], true)) { $type = 'datetime'; $ex = '2026-10-26 09:30:00'; }
    elseif (str_contains($t, 'text') || $t === 'json') { $type = $json !== null ? ($json === 'liste' ? 'array (JSON)' : 'object (JSON)') : 'string'; $ex = $json === 'liste' ? [] : ($json !== null ? (object) [] : '…'); }
    else { $type = 'string' . ($long ? ' ≤ ' . $long : ''); $ex = '…'; }
    $auto = str_contains(strtolower((string) $c['EXTRA']), 'auto_increment');
    // MariaDB écrit « NULL » pour un défaut nul et met les chaînes entre apostrophes ; MySQL, non : les deux se lisent pareil.
    $def = $c['COLUMN_DEFAULT'];
    if ($def === 'NULL') { $def = null; } elseif (is_string($def) && preg_match("/^'(.*)'$/s", $def, $q)) { $def = str_replace("''", "'", $q[1]); }
    $requis = !$auto && $c['IS_NULLABLE'] === 'NO' && $def === null;
    return ['nom' => (string) $c['COLUMN_NAME'], 'type' => $type, 'sql' => $ct, 'requis' => $requis, 'auto' => $auto,
        'nul' => $c['IS_NULLABLE'] === 'YES', 'defaut' => $def, 'cle' => (string) $c['COLUMN_KEY'], 'exemple' => $ex];
}

/** Les cinq verbes d'une table con_, tels que le panel devrait les exposer. */
function ssEndpoints(string $table, array $api, array $champs): array
{
    $chemin = $api['chemin']; $cle = $api['cle'];
    $composee = str_contains($cle, ',');
    $tech = ['cree_le', 'maj_le', 'le'];
    $corps = [];
    foreach ($champs as $c) {
        if ($c['auto'] || in_array($c['nom'], $tech, true) || (isset($api['parent']) && $c['nom'] === $api['parent'])) { continue; }
        $corps[$c['nom']] = $c['exemple'];
    }
    $filtres = array_map(static fn ($f) => $f . '=', $api['filtres'] ?? []);
    $q = $filtres ? '?' . implode('&', $filtres) . '&page=&per_page=' : '?page=&per_page=';
    $un = $chemin . '/{' . ($composee ? 'clé' : $cle) . '}';
    $out = [['m' => 'GET', 'chemin' => $chemin . $q, 'dit' => 'Lister (filtres, pages) · réponse { data: [ligne…], meta: { total, page, per_page } }']];
    if ($composee) {
        $out[] = ['m' => 'PUT', 'chemin' => $chemin, 'dit' => 'Écrire une ligne, créée ou remplacée sur sa clé (' . str_replace(',', ', ', $cle) . ')', 'corps' => $corps];
        $out[] = ['m' => 'DELETE', 'chemin' => $chemin . '?' . implode('&', array_map(static fn ($k) => $k . '=', explode(',', $cle))), 'dit' => 'Retirer une ligne, par sa clé'];
        return $out;
    }
    $out[] = ['m' => 'GET', 'chemin' => $un, 'dit' => 'Lire une ligne'];
    if (!empty($api['fichier'])) {
        $out[] = ['m' => 'POST', 'chemin' => $chemin, 'dit' => 'Déposer (multipart/form-data : le fichier « file » et les champs ci-dessous) · réponse 201 et la ligne', 'corps' => $corps];
        $out[] = ['m' => 'GET', 'chemin' => $un . '/presigned-url', 'dit' => 'Le lien signé du fichier, à durée limitée'];
    } else {
        $out[] = ['m' => 'POST', 'chemin' => $chemin, 'dit' => 'Créer · réponse 201 et la ligne, avec son ' . $cle, 'corps' => $corps];
    }
    $out[] = ['m' => 'PATCH', 'chemin' => $un, 'dit' => 'Modifier (seuls les champs envoyés changent) · réponse : la ligne', 'corps' => array_slice($corps, 0, 3, true)];
    $out[] = ['m' => 'DELETE', 'chemin' => $un, 'dit' => 'Supprimer · réponse 204'];
    return $out;
}

/** GET /suivi/sources */
function ep_suivi_sources(): array
{
    $racine = dirname(__DIR__);
    $index = msIndex($racine);
    $creees = msTablesCreees($racine);
    $mPanel = ssMethodesClasse($racine . '/src/panel_api.php');
    $mErp = ssMethodesClasse($racine . '/src/erp_api.php');
    $apis = msApis();

    // La base : ce qui existe, combien de lignes (estimées ; exactes pour les tables du module).
    $enBase = null; $nomBase = null;
    try {
        $nomBase = (string) (Db::row('SELECT DATABASE() AS b')['b'] ?? '');
        $enBase = [];
        foreach (Db::rows('SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $enBase[strtolower((string) $r['t'])] = $r['n'] !== null ? (int) $r['n'] : null;
        }
    } catch (Throwable $e) { $enBase = null; }

    $routes = []; $tables = []; $chemins = [];
    foreach (SS_ROUTES as [$ecran, $methode, $chemin, $fn, $quoi]) {
        $s = ssSuivre($fn, $index, $mPanel, $mErp);
        $parOrigine = ['con' => [], 'cockpit' => [], 'tfbuddy' => []];
        $installe = [];
        foreach ($s['tables'] as $t => $mode) {
            $o = ssOrigine($t, $creees);
            if ($mode === 'creation') { $installe[] = $t; continue; }
            $parOrigine[$o][] = ['nom' => $t, 'mode' => $mode];
            $tables[$t]['origine'] = $o;
            $tables[$t]['routes'][] = $methode . ' ' . $chemin;
            $tables[$t]['ecrite'] = ($tables[$t]['ecrite'] ?? false) || $mode === 'ecriture';
        }
        foreach (['panel' => $s['panel'], 'erp' => $s['erp']] as $src => $liste) {
            foreach ($liste as $c) { $k = $src . ' ' . $c['m'] . ' ' . $c['chemin']; $chemins[$k] = ($chemins[$k] ?? ['source' => $src] + $c + ['routes' => []]); $chemins[$k]['routes'][] = $methode . ' ' . $chemin; }
        }
        $externes = array_values(array_diff($s['apis'], ['base', 'panel', 'erp']));
        $routes[] = ['ecran' => $ecran, 'methode' => $methode, 'chemin' => $chemin, 'fonction' => $fn, 'quoi' => $quoi,
            'existe' => isset($index[$fn]), 'fichier' => $index[$fn]['fichier'] ?? null, 'fonctions' => $s['fonctions'],
            'panel' => $s['panel'], 'erp' => $s['erp'], 'externes' => $externes,
            'con' => $parOrigine['con'], 'cockpit' => $parOrigine['cockpit'], 'tfbuddy' => $parOrigine['tfbuddy'], 'installe' => $installe];
    }

    // La structure des tables du module (et des tables TFBuddy lues en direct) : colonnes, CREATE TABLE, JSON détecté.
    $colonnes = [];
    $aLire = array_values(array_filter(array_merge(array_keys(CON_TABLES), array_keys(array_filter($tables, static fn ($x) => $x['origine'] === 'tfbuddy'))),
        static fn ($t) => $enBase !== null && array_key_exists($t, $enBase)));
    if ($aLire) {
        try {
            $place = implode(',', array_fill(0, count($aLire), '?'));
            foreach (Db::rows('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $place . ') ORDER BY TABLE_NAME, ORDINAL_POSITION', $aLire) as $c) {
                $colonnes[strtolower((string) $c['TABLE_NAME'])][] = $c;
            }
        } catch (Throwable $e) { $colonnes = []; }
    }
    $migration = null;
    try { $migration = setting('conMigration'); } catch (Throwable $e) { $migration = null; }

    $registre = []; $manquantes = [];
    foreach (CON_TABLES as $nom => $d) {
        $existe = $enBase === null ? null : array_key_exists($nom, $enBase);
        $lignes = null; $ddl = null; $champs = [];
        if ($existe) {
            try { $lignes = (int) (Db::row('SELECT COUNT(*) AS n FROM `' . $nom . '`')['n'] ?? 0); } catch (Throwable $e) { $lignes = $enBase[$nom] ?? null; }
            try { $ddl = (string) (array_values(Db::row('SHOW CREATE TABLE `' . $nom . '`') ?? [])[1] ?? ''); } catch (Throwable $e) { $ddl = null; }
            foreach ($colonnes[$nom] ?? [] as $c) {
                $json = null;
                if (str_contains(strtolower((string) $c['DATA_TYPE']), 'text')) {
                    try {
                        $v = Db::row('SELECT `' . $c['COLUMN_NAME'] . '` AS v FROM `' . $nom . '` WHERE `' . $c['COLUMN_NAME'] . '` IS NOT NULL AND `' . $c['COLUMN_NAME'] . '` <> \'\' LIMIT 1');
                        $x = $v !== null ? json_decode((string) $v['v'], true) : null;
                        if (is_array($x)) { $json = array_is_list($x) ? 'liste' : 'objet'; }
                    } catch (Throwable $e) { /* colonne illisible : texte */ }
                }
                $champs[] = ssChamp($c, $json);
            }
        }
        $registre[] = ['nom' => $nom, 'ancien' => $d['ancien'], 'famille' => $d['famille'], 'quoi' => $d['quoi'], 'existe' => $existe,
            'ancienExiste' => $enBase === null ? null : array_key_exists($d['ancien'], $enBase), 'lignes' => $lignes,
            'routes' => array_values(array_unique($tables[$nom]['routes'] ?? [])), 'ecrite' => (bool) ($tables[$nom]['ecrite'] ?? false),
            'champs' => array_map(static fn ($c) => array_diff_key($c, ['exemple' => 1]), $champs), 'ddl' => $ddl];
        $manquantes[] = ['table' => $nom, 'famille' => $d['famille'], 'quoi' => $d['quoi'], 'ressource' => $d['api']['chemin'], 'cle' => $d['api']['cle'],
            'lecture' => true, 'ecriture' => true, 'endpoints' => $champs ? ssEndpoints($nom, $d['api'], $champs) : [],
            'routes' => array_values(array_unique($tables[$nom]['routes'] ?? [])), 'structureLue' => (bool) $champs];
    }

    // Les tables TFBuddy lues en direct : la lecture à ouvrir au panel (ou celle qui existe déjà et qu'on n'emploie pas).
    $directes = [];
    foreach ($tables as $t => $x) {
        if ($x['origine'] !== 'tfbuddy') { continue; }
        $connu = SS_TFB[$t] ?? null;
        $directes[] = ['nom' => $t, 'quoi' => $connu['quoi'] ?? 'Table de la base TFBuddy partagée', 'existe' => $connu['existe'] ?? null,
            'propose' => $connu['propose'] ?? ($connu ? null : 'GET /consultant/' . str_replace('_', '-', $t)), 'ecrite' => $x['ecrite'],
            'routes' => array_values(array_unique($x['routes'])), 'enBase' => $enBase === null ? null : array_key_exists($t, $enBase), 'lignes' => $enBase[$t] ?? null,
            'colonnes' => array_map(static fn ($c) => ['nom' => (string) $c['COLUMN_NAME'], 'sql' => (string) $c['COLUMN_TYPE']], $colonnes[$t] ?? [])];
    }
    usort($directes, static fn ($a, $b) => count($b['routes']) <=> count($a['routes']) ?: strcmp($a['nom'], $b['nom']));
    $autres = [];
    foreach ($tables as $t => $x) {
        if ($x['origine'] === 'cockpit') { $autres[] = ['nom' => $t, 'ecrite' => $x['ecrite'], 'routes' => array_values(array_unique($x['routes'])), 'lignes' => $enBase[$t] ?? null]; }
    }
    usort($autres, static fn ($a, $b) => count($b['routes']) <=> count($a['routes']) ?: strcmp($a['nom'], $b['nom']));
    $chemins = array_values($chemins);
    usort($chemins, static fn ($a, $b) => strcmp($a['source'], $b['source']) ?: strcmp($a['chemin'], $b['chemin']) ?: strcmp($a['m'], $b['m']));
    foreach ($chemins as &$c) { $c['routes'] = array_values(array_unique($c['routes'])); $c['ecriture'] = $c['m'] !== 'GET'; }
    unset($c);

    return [
        'genere' => date('Y-m-d H:i'),
        'base' => $nomBase, 'baseLue' => $enBase !== null,
        'hotes' => ['panel' => $apis['panel'] ?? null, 'erp' => $apis['erp'] ?? null],
        'apis' => array_values(array_map(static fn ($id, $a) => ['id' => $id] + $a, array_keys($apis), $apis)),
        'ecrans' => SS_ECRANS,
        'routes' => $routes,
        'chemins' => $chemins,
        'registre' => $registre,
        'directes' => $directes,
        'autres' => $autres,
        'manquantes' => $manquantes,
        'migration' => is_array($migration) ? $migration : null,
        'limites' => 'Lu dans le code, par motifs : un nom de table ou un chemin fabriqué à l’exécution échappe à l’analyse, et une fonction suivie compte toutes ses branches — même celles que la route n’emprunte pas.',
    ];
}
