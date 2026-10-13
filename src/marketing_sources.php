<?php
declare(strict_types=1);

/**
 * GET /marketing/sources — ce que le module Marketing appelle, et ce que ça touche
 * (demande du 13/10/2026 : « toutes les API utilisées, celles reliées à TFBuddy et
 * celles en local, et l'entièreté des tables »).
 *
 * Comme la carte des sources (bin/carte_sources.php), l'inventaire se lit dans le
 * CODE : une liste tenue à la main ment dès la livraison suivante. Seule la liste
 * des routes que la page appelle est déclarée (MS_ROUTES) — c'est le contrat de la
 * page, et bin/tests.php vérifie que chaque fonction existe et est routée. Pour
 * chaque route, on suit les fonctions appelées (jusqu'à MS_PROFONDEUR niveaux) et
 * l'on relève :
 *  - les tables nommées dans le SQL (FROM, JOIN, INTO, UPDATE, CREATE TABLE…),
 *    lues ou écrites ;
 *  - les API extérieures réellement appelées — le panel et l'ERP TFBuddy
 *    (atelierby.tfbuddy.com), Meta, Google, Anthropic, le serveur SMTP. Un simple
 *    `statut()` ou `configured()` ne compte pas : il ne sort pas du serveur.
 * Puis la base dit ce qui existe vraiment : information_schema, lignes estimées.
 *
 * Une analyse par motifs n'est pas un compilateur : un nom de table fabriqué à
 * l'exécution (`'FROM ' . $t`) lui échappe, et une fonction appelée suit tout son
 * corps, même la branche que la route n'emprunte pas. L'écran le dit.
 */

/** Les routes que la page marketing/ appelle, et celles du module newsletter qu'elle monte. */
const MS_ROUTES = [
    ['GET', '/marketing/hub', 'ep_marketing_hub', 'Tous les canaux en une lecture'],
    ['GET', '/marketing/sources', 'ep_marketing_sources', 'Cet inventaire'],
    ['POST', '/facebook/posts', 'wr_fb_post_create', 'Soumettre un post (ou l’enregistrer en brouillon)'],
    ['POST', '/facebook/posts/{id}/controle', 'wr_fb_controle', 'Contrôler un post, soumettre un brouillon'],
    ['PATCH', '/facebook/posts/{id}', 'wr_fb_decision', 'Valider, refuser, marquer publié'],
    ['PATCH', '/facebook/posts/{id}/ecarts/{ecart}', 'wr_fb_ecart_patch', 'Écarter ou rouvrir un écart'],
    ['GET', '/referentiels/facebook-regles', 'ep_fb_regles', 'Les règles de l’agent de contrôle'],
    ['PUT', '/parametres/fbControle', 'wr_param_put', 'Activer une règle de l’agent, changer le seuil'],
    ['GET', '/marketing/brand-guard/rules', 'ep_bg_rules', 'La charte Brand Guard'],
    ['PUT', '/marketing/brand-guard/rules', 'wr_bg_rules_put', 'Enregistrer la charte'],
    ['GET', '/marketing/brand-guard/pages', 'ep_bg_pages', 'Les pages Facebook des magasins'],
    ['PUT', '/marketing/brand-guard/pages', 'wr_bg_pages_put', 'Enregistrer ou résoudre les pages'],
    ['GET', '/reputation/recherche', 'ep_reputation_recherche', 'Chercher la fiche Google d’un magasin'],
    ['PUT', '/reputation/{magasin}/fiche', 'wr_reputation_fiche', 'Raccorder ou détacher une fiche Google'],
    ['POST', '/reputation/sync', 'wr_reputation_sync', 'Relire les notes et les avis Google'],
    ['PUT', '/parametres/reputationCible', 'wr_param_put', 'La note Google visée'],
    ['GET', '/newsletter', 'ep_newsletter', 'Emailing : campagnes, segments, bases, réglages'],
    ['POST', '/newsletter/campagnes', 'wr_newsletter_campagne_post', 'Créer une newsletter'],
    ['DELETE', '/newsletter/campagnes/{id}', 'wr_newsletter_campagne_delete', 'Supprimer une newsletter'],
    ['POST', '/newsletter/campagnes/{id}/envoyer', 'wr_newsletter_campagne_envoyer', 'Envoyer maintenant'],
    ['POST', '/newsletter/segments', 'wr_newsletter_segment_post', 'Créer un segment'],
    ['PUT', '/newsletter/magasins/{id}', 'wr_newsletter_magasin_put', 'Adresse d’envoi d’un magasin'],
    ['POST', '/newsletter/test', 'wr_newsletter_test_post', 'Envoi test'],
    ['PUT', '/newsletter/reglages', 'wr_newsletter_reglages', 'Activer l’envoi réel, adresses test'],
    ['POST', '/newsletter/tick', 'wr_newsletter_tick', 'Passer l’horloge d’envoi à la main'],
    ['POST', '/newsletter/contacts/import', 'wr_newsletter_contacts_import', 'Importer des contacts (CSV)'],
    ['DELETE', '/newsletter/contacts/lots/{lot}', 'wr_newsletter_lot_delete', 'Supprimer un lot de contacts'],
];

/**
 * Les aiguillages génériques : `wr_param_put` sert TOUS les réglages du cockpit, et
 * son corps touche des tables que nos deux clés n'approchent pas (kpi, budget…).
 * Pour lui, on dit ce que nos clés font réellement.
 */
const MS_SEULEMENT = ['wr_param_put' => ['tables' => [['ceo_app_setting', 'ecriture']], 'apis' => ['base']]];

/** Au-delà, on s'arrête : l'installateur ou un écran voisin finiraient par tout attirer. */
const MS_PROFONDEUR = 6;
/** Les fonctions qu'on ne suit pas : elles créent le schéma entier, pas ce que la route utilise. */
const MS_NE_PAS_SUIVRE = ['ensureInstalled', 'installerSchema', 'schemaOublier'];

/** Les API, une par client du code. `famille` : tfbuddy, externe, locale. */
function msApis(): array
{
    $hote = static function (?string $url): ?string { $h = $url ? parse_url($url, PHP_URL_HOST) : null; return $h ?: null; };
    $panel = class_exists('PanelApi') ? PanelApi::statut() : [];
    $erp = class_exists('ErpApi') ? ErpApi::statut() : [];
    $meta = class_exists('MetaGraph') ? MetaGraph::statut() : [];
    $smtp = class_exists('Smtp') ? Smtp::statut() : [];
    return [
        'base' => ['nom' => 'Base MySQL du cockpit', 'famille' => 'locale', 'hote' => '',
            'quoi' => 'Les tables lues et écrites par le serveur du cockpit — locales, ou partagées avec TFBuddy.', 'configure' => true],
        'panel' => ['nom' => 'Panel TFBuddy (réalm consultant)', 'famille' => 'tfbuddy', 'hote' => $hote($panel['base'] ?? null),
            'quoi' => 'Ventes, P&L, tâches, produits du réseau — atelierby.tfbuddy.com/api/v1.', 'configure' => !empty($panel['configure'])],
        'erp' => ['nom' => 'ERP TFBuddy (réalm admin)', 'famille' => 'tfbuddy', 'hote' => $hote($erp['base'] ?? null),
            'quoi' => 'Redevances, gammes saisonnières, facturation — et la reprise du catalogue de l’assistant de campagne.', 'configure' => !empty($erp['configure'])],
        'meta' => ['nom' => 'Meta Graph (Facebook)', 'famille' => 'externe', 'hote' => 'graph.facebook.com',
            'quoi' => 'Lecture des pages et des posts publiés (Brand Guard). Rien n’est publié.', 'configure' => !empty($meta['configure'])],
        'google' => ['nom' => 'Google Places', 'famille' => 'externe', 'hote' => 'places.googleapis.com',
            'quoi' => 'Note, nombre d’avis et derniers avis des fiches des magasins.', 'configure' => class_exists('GoogleApi') && GoogleApi::configured()],
        'anthropic' => ['nom' => 'Anthropic (assistance IA)', 'famille' => 'externe', 'hote' => 'api.anthropic.com',
            'quoi' => 'Relecture de la charte par le modèle (texte et visuels des posts).', 'configure' => class_exists('Anthropic') && Anthropic::configured()],
        'smtp' => ['nom' => 'Serveur SMTP', 'famille' => 'externe', 'hote' => (string) ($smtp['hote'] ?? ''),
            'quoi' => 'Newsletters, envois test, alertes de posts sauvages.', 'configure' => !empty($smtp['configure'])],
    ];
}

/** Ce qui, dans un corps de fonction, sort vraiment du serveur. */
const MS_MOTIFS_API = [
    'panel' => '/\bPanelApi::(?!configured\b|statut\b|config\b|oublierJeton\b|liste\b)\w+\s*\(/',
    'erp' => '/\bErpApi::(?!configured\b|statut\b|config\b|oublierJeton\b|disponible\b)\w+\s*\(/',
    'meta' => '/\bMetaGraph::(?:http|resoudrePage|resoudrePagePublique|tokensPages|posts)\s*\(/',
    'google' => '/\bGoogleApi::(?!configured\b|statut\b|config\b)\w+\s*\(/',
    'anthropic' => '/\bAnthropic::(?!configured\b|statut\b|config\b)\w+\s*\(/',
    'smtp' => '/\bSmtp::envoyer\s*\(/',
    'base' => '/\bDb::(?:rows|row|exec|pdo)\s*\(/',
];

/** Les fonctions de src/, découpées : nom → fichier et corps. Les classes ne sont pas des fonctions. */
function msIndex(string $racine): array
{
    $fns = [];
    foreach (glob($racine . '/src/*.php') ?: [] as $f) {
        $t = (string) file_get_contents($f);
        if (!preg_match_all('/^(?:function ([a-zA-Z_]\w*)\s*\(|(?:final |abstract )?class \w+)/m', $t, $m, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($m[0] as $i => [, $pos]) {
            $nom = $m[1][$i][0] ?? '';
            if ($nom === '' || ($m[1][$i][1] ?? -1) < 0) { continue; }   // une classe : borne, pas fonction
            $fin = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($t);
            $fns[$nom] = ['fichier' => basename($f), 'corps' => substr($t, $pos, $fin - $pos)];
        }
    }
    return $fns;
}

/** Les tables nommées dans le SQL d'un corps, avec ce qu'on en fait. */
function msTablesDe(string $corps): array
{
    $out = [];
    // « ON DUPLICATE KEY UPDATE nom = … » nomme une colonne, pas une table.
    $corps = (string) preg_replace('/DUPLICATE\s+KEY\s+UPDATE/', 'DUPLICATE KEY SET', $corps);
    $motif = '/\b(DELETE\s+FROM|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|ALTER\s+TABLE|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|UPDATE|FROM|JOIN)\s+`?([a-z][a-z0-9_]{2,})`?/';
    if (!preg_match_all($motif, $corps, $m, PREG_SET_ORDER)) { return []; }
    foreach ($m as [, $verbe, $t]) {
        if (in_array($t, ['information_schema', 'dual', 'select', 'set', 'where', 'values'], true)) { continue; }
        $v = strtoupper((string) preg_replace('/\s+/', ' ', $verbe));
        $mode = str_starts_with($v, 'CREATE') || str_starts_with($v, 'ALTER') ? 'creation'
            : (in_array($v, ['FROM', 'JOIN'], true) ? 'lecture' : 'ecriture');
        // Le mode le plus fort l'emporte : une table créée puis écrite est « créée ».
        $rang = ['lecture' => 1, 'ecriture' => 2, 'creation' => 3];
        if (!isset($out[$t]) || $rang[$mode] > $rang[$out[$t]]) { $out[$t] = $mode; }
    }
    return $out;
}

/** Les fonctions de l'index appelées par un corps (hors sa propre déclaration). */
function msAppels(string $nom, string $corps, array $index): array
{
    if (!preg_match_all('/(?<![\w$>:])([a-zA-Z_]\w*)\s*\(/', $corps, $m)) { return []; }
    return array_values(array_filter(array_unique($m[1]), static fn ($f) => $f !== $nom && isset($index[$f]) && !in_array($f, MS_NE_PAS_SUIVRE, true)));
}

/** Une route suivie de proche en proche : ses tables, ses API, les fonctions traversées. */
function msSuivre(string $depart, array $index): array
{
    if (isset(MS_SEULEMENT[$depart])) {
        $s = MS_SEULEMENT[$depart];
        return ['tables' => array_column($s['tables'], 1, 0), 'apis' => $s['apis'], 'fonctions' => 1, 'fichier' => $index[$depart]['fichier'] ?? null];
    }
    $vus = [$depart => 0]; $file = [$depart]; $tables = []; $apis = [];
    $rang = ['lecture' => 1, 'ecriture' => 2, 'creation' => 3];
    while ($file) {
        $f = array_shift($file);
        if (!isset($index[$f])) { continue; }
        $corps = $index[$f]['corps'];
        foreach (msTablesDe($corps) as $t => $mode) {
            if (!isset($tables[$t]) || $rang[$mode] > $rang[$tables[$t]]) { $tables[$t] = $mode; }
        }
        foreach (MS_MOTIFS_API as $api => $motif) { if (preg_match($motif, $corps)) { $apis[$api] = true; } }
        if ($vus[$f] >= MS_PROFONDEUR) { continue; }
        foreach (msAppels($f, $corps, $index) as $g) {
            if (isset($vus[$g]) || isset(MS_SEULEMENT[$g])) { continue; }
            $vus[$g] = $vus[$f] + 1; $file[] = $g;
        }
    }
    ksort($tables);
    return ['tables' => $tables, 'apis' => array_keys($apis), 'fonctions' => count($vus), 'fichier' => $index[$depart]['fichier'] ?? null];
}

/** Les tables que ce dépôt crée lui-même (src/ et sql/schema.sql) — le reste vient d'ailleurs. */
function msTablesCreees(string $racine): array
{
    $out = [];
    $fichiers = array_merge(glob($racine . '/src/*.php') ?: [], [$racine . '/sql/schema.sql']);
    foreach ($fichiers as $f) {
        if (!is_file($f)) { continue; }
        if (preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([a-z][a-z0-9_]+)`?/i', (string) file_get_contents($f), $m)) {
            foreach ($m[1] as $t) { $out[strtolower($t)] = true; }
        }
    }
    return $out;
}

/**
 * D'où vient une table : créée par le cockpit, héritée du module marketing autonome
 * (mar_*), ou appartenant à la base TFBuddy partagée (shops, crm_settings, of_tag…).
 */
function msOrigine(string $t, array $creees): array
{
    if (str_starts_with($t, 'mar_')) { return ['origine' => 'module', 'origineNom' => 'Module marketing (base partagée)']; }
    if (isset($creees[$t])) { return ['origine' => 'cockpit', 'origineNom' => 'Cockpit (créée ici)']; }
    return ['origine' => 'tfbuddy', 'origineNom' => 'TFBuddy (base partagée)'];
}

/** GET /marketing/sources */
function ep_marketing_sources(): array
{
    $racine = dirname(__DIR__);
    $index = msIndex($racine);
    $apis = msApis();
    $creees = msTablesCreees($racine);

    // La base dit ce qui existe : présence et lignes (estimées par InnoDB).
    $enBase = null; $nomBase = null;
    try {
        $nomBase = (string) (Db::row('SELECT DATABASE() AS b')['b'] ?? '');
        $enBase = [];
        foreach (Db::rows('SELECT TABLE_NAME AS t, TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $enBase[strtolower((string) $r['t'])] = $r['n'] !== null ? (int) $r['n'] : null;
        }
    } catch (Throwable $e) { $enBase = null; }

    $routes = []; $tables = []; $parApi = [];
    foreach (MS_ROUTES as [$methode, $chemin, $fn, $quoi]) {
        $s = msSuivre($fn, $index);
        $ext = array_values(array_diff($s['apis'], ['base']));
        foreach ($s['apis'] as $a) { $parApi[$a][] = $methode . ' ' . $chemin; }
        $routes[] = [
            'methode' => $methode, 'chemin' => '/api/cockpit' . $chemin, 'fonction' => $fn, 'fichier' => $s['fichier'], 'quoi' => $quoi,
            'existe' => isset($index[$fn]), 'fonctions' => $s['fonctions'],
            'apis' => $s['apis'],
            'tfbuddy' => (bool) array_intersect($ext, ['panel', 'erp']),
            'locale' => $ext === [],
            'tables' => array_map(static fn ($t, $m) => ['nom' => $t, 'mode' => $m], array_keys($s['tables']), $s['tables']),
        ];
        foreach ($s['tables'] as $t => $mode) {
            $tables[$t]['routes'][] = $methode . ' ' . $chemin;
            $tables[$t]['ecrite'] = ($tables[$t]['ecrite'] ?? false) || $mode !== 'lecture';
        }
    }
    $listeTables = [];
    foreach ($tables as $t => $x) {
        $listeTables[] = ['nom' => $t] + msOrigine($t, $creees) + [
            'ecrite' => $x['ecrite'], 'routes' => array_values(array_unique($x['routes'])),
            'existe' => $enBase === null ? null : array_key_exists($t, $enBase),
            'lignes' => $enBase[$t] ?? null,
        ];
    }
    $ordre = ['tfbuddy' => 0, 'module' => 1, 'cockpit' => 2];
    usort($listeTables, static fn ($a, $b) => ($ordre[$a['origine']] <=> $ordre[$b['origine']]) ?: strcmp($a['nom'], $b['nom']));
    foreach ($apis as $id => &$a) { $a['id'] = $id; $a['routes'] = array_values(array_unique($parApi[$id] ?? [])); }
    unset($a);

    return [
        'genere' => date('Y-m-d H:i'),
        'base' => $nomBase,
        'baseLue' => $enBase !== null,
        'apis' => array_values($apis),
        'routes' => $routes,
        'tables' => $listeTables,
        // Les applications liées : ouvertes depuis le module, mais avec leur propre API.
        'liees' => [
            ['nom' => 'Assistant de campagne', 'adresse' => 'assistant/', 'api' => '/api/v1/marketing',
                'quoi' => 'Copie de l’API du module marketing autonome (public/api/marketing), tables mar_* ; reprend le catalogue des gammes depuis l’ERP TFBuddy.',
                'tfbuddy' => true, 'configure' => !empty($apis['erp']['configure'])],
            ['nom' => 'Écrans du cockpit', 'adresse' => '#/campagnes, #/brand-guard, #/newsletter…', 'api' => '/api/cockpit',
                'quoi' => 'Calendrier, budget, mesure et note de campagne ; mêmes données que le module.', 'tfbuddy' => false, 'configure' => true],
        ],
        'limites' => 'Lu dans le code, par motifs : un nom de table fabriqué à l’exécution échappe à l’analyse, et une fonction suivie compte toutes ses branches — même celles que la route n’emprunte pas.',
    ];
}
