<?php
/**
 * Les fiches techniques des produits (19/11/2026, Sam : « créer un nouveau manuel : fiches des recettes et fiches
 * techniques, voir le repo kitchen pour les données à récupérer ; une feuille par produit, triée par catégorie,
 * standard, saison »).
 *
 * La source est celle de l'application cuisine (dépôt pwa_kitchen, TechnicalSheetRepository) : la fiche technique du
 * panel TF Buddy, GET /products/{id}/technical-sheet/raw — le produit (catégorie, conservation, durée de vie, remise en
 * température, allergènes), sa recette (ingrédients et quantités), ses étapes de préparation, son emballage, ses
 * compétences et ses photos. La liste des produits et leur gamme (standard, saison) viennent du catalogue du cockpit
 * (ep_prod_catalogue, shared/src/endpoints.php).
 *
 * Le standard qualité de chaque produit (26/11/2026) vient des grilles d'autocontrôle du cockpit (aqStandardProduit,
 * consultant/src/autocontrole.php) : les critères par étape, la réponse attendue, ce qui est à surveiller ou pas bon.
 *
 * Routes (branchées par rfRoute, src/referentiel.php) :
 *   GET /formation/ref/fiches               — les fiches, catégorie par catégorie ; ?rafraichir=1 relit le panel
 *   GET /formation/ref/fiches/photo/{id}    — la photo d'un produit : redirige vers l'adresse signée du panel
 *
 * Lire 500 fiches prend une minute : la lecture se fait par lots (FT_BUDGET secondes par appel), gardée dans le
 * dossier privé du cockpit ; la réponse dit combien il en manque, et la page rappelle jusqu'à ce qu'il n'en manque plus.
 * Les adresses des photos du panel sont signées pour une heure : on ne les garde pas, la route photo les redemande.
 */

const FT_DUREE = 6 * 3600;   // une fiche lue reste bonne six heures
const FT_BUDGET = 20;        // secondes de lecture du panel par appel
const FT_LOT = 24;           // fiches demandées par volée

function ftRoute(string $method, string $path): ?array
{
    if ($method !== 'GET') { return null; }
    if ($path === '/formation/ref/fiches') { return ep_ft_fiches(!empty($_GET['rafraichir'])); }
    if (preg_match('#^/formation/ref/fiches/photo/(\d{1,12})$#', $path, $m) === 1) { return ep_ft_photo((int) $m[1]); }
    return null;
}

/** Le dossier du cache : le dossier privé du cockpit (hors du site, que la livraison ne touche pas), sous fiches/. */
function ftDossier(): ?string
{
    static $d = false;
    if ($d !== false) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__, 2) . '/var/prive']) as $c) {
        $f = rtrim($c, '/') . '/fiches';
        if ((is_dir($f) || @mkdir($f, 0700, true)) && is_writable($f)) { return $d = $f; }
    }
    return $d = null;
}

function ftCacheLire(): array
{
    $dir = ftDossier();
    $j = $dir !== null && is_file($dir . '/fiches.json') ? json_decode((string) file_get_contents($dir . '/fiches.json'), true) : null;
    return is_array($j) ? $j + ['fiches' => []] : ['fiches' => []];
}

function ftCacheEcrire(array $c): void
{
    $dir = ftDossier();
    if ($dir === null) { return; }
    $tmp = $dir . '/fiches.json.' . getmypid();
    if (@file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) { @rename($tmp, $dir . '/fiches.json'); }
}

/** Le texte d'une valeur du panel, sans espaces autour ; '' si vide. */
function ftTxt(mixed $v): string { return is_scalar($v) ? trim((string) $v) : ''; }

/** Les noms d'une liste d'allergènes du panel (chaînes ou objets {name}). */
function ftAllergenes(mixed $l): array
{
    $out = [];
    foreach (is_array($l) ? $l : [] as $a) {
        $n = is_array($a) ? ftTxt($a['name'] ?? $a['nom'] ?? $a['label'] ?? '') : ftTxt($a);
        if ($n !== '') { $out[mb_strtolower($n)] = $n; }
    }
    return array_values($out);
}

/**
 * Ce que le manuel garde d'une fiche technique du panel. Fonction pure (testée dans bin/tests_formation.php).
 * Les champs vides restent vides : la page les montre « à compléter dans TF Buddy ».
 */
function ftResume(array $d): array
{
    $o = is_array($d['object'] ?? null) ? $d['object'] : [];
    $r = is_array($d['recipe'] ?? null) ? $d['recipe'] : [];
    $ingr = []; $allerg = ftAllergenes(explode(',', ftTxt($o['allergene'] ?? '')));
    foreach (is_array($r['elements'] ?? null) ? $r['elements'] : [] as $e) {
        if (!is_array($e)) { continue; }
        $q = $e['recipe_quantity'] ?? $e['required_quantity'] ?? null;
        $ingr[] = ['nom' => ftTxt($e['name'] ?? ''), 'qte' => is_numeric($q) ? (float) $q : null, 'unite' => ftTxt($e['unit_name'] ?? ''),
            'emballage' => !empty($e['is_part_of_package']), 'sousRecette' => ($e['type'] ?? '') === 'recipe'];
        foreach (ftAllergenes($e['allergens'] ?? []) as $a) { if (!in_array($a, $allerg, true)) { $allerg[] = $a; } }
    }
    $etapes = [];
    $prep = is_array($d['preparation'] ?? null) ? $d['preparation'] : [];
    foreach (is_array($prep['steps'] ?? null) ? $prep['steps'] : [] as $s) {
        $t = is_array($s) ? ftTxt($s['step_description'] ?? $s['description'] ?? '') : ftTxt($s);
        if ($t !== '') { $etapes[] = preg_replace('/\s*\R\s*/u', ' ', $t); }
    }
    $types = [];
    foreach (is_array($prep['types'] ?? null) ? $prep['types'] : [] as $t) { $n = is_array($t) ? ftTxt($t['name'] ?? '') : ftTxt($t); if ($n !== '') { $types[] = $n; } }
    $emb = null;
    $p = $d['package'] ?? null;
    if (is_array($p) && ftTxt($p['name'] ?? '') !== '') {
        $mats = [];
        foreach (is_array($p['materials'] ?? null) ? $p['materials'] : [] as $m) { $n = is_array($m) ? ftTxt($m['name'] ?? '') : ''; if ($n !== '') { $mats[] = $n; } }
        $emb = ['nom' => ftTxt($p['name']), 'materiaux' => $mats];
    }
    $comp = [];
    foreach (is_array($d['competences'] ?? null) ? $d['competences'] : [] as $c) { $n = is_array($c) ? ftTxt($c['name'] ?? '') : ''; if ($n !== '') { $comp[] = $n; } }
    $ph = is_array($d['photos'] ?? null) ? $d['photos'] : [];
    $num = static fn ($v) => is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    return [
        'nom' => ftTxt($o['name'] ?? ''),
        'categorie' => ftTxt($o['category_name'] ?? ''),
        'recette' => ftTxt($r['name'] ?? ''),
        'rendement' => $num($r['yield_quantity'] ?? null),
        'ingredients' => $ingr,
        'etapes' => $etapes,
        'types' => $types,
        'emballage' => $emb,
        'competences' => $comp,
        'allergenes' => $allerg,
        'vegetarien' => !empty($o['is_vegetarian']),
        'temperature' => isset($o['storage_temperature']) && is_numeric($o['storage_temperature']) ? (float) $o['storage_temperature'] : null,
        'vieMinutes' => $num($o['shelf_life_minutes'] ?? null),
        'rechauffe' => $num($o['reheating_time_minutes'] ?? null) !== null || $num($o['reheating_temperature_celsius'] ?? null) !== null
            ? ['minutes' => $num($o['reheating_time_minutes'] ?? null), 'degres' => $num($o['reheating_temperature_celsius'] ?? null)] : null,
        'avantVente' => !empty($o['is_prepared_before_sales']),
        'delaiHeures' => $num($o['preparation_lead_time_hours'] ?? null),
        'photo' => (bool) array_filter(array_map('ftTxt', $ph)),
    ];
}

/** La gamme d'un produit du catalogue : standard, saison (avec ses noms) ou sans gamme. Fonction pure. */
function ftGamme(array $p): array
{
    $noms = array_values(array_filter(array_map(static fn ($x) => trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) $x)), is_array($p['periods'] ?? null) ? $p['periods'] : [])));
    if (!empty($p['standard'])) { return ['gamme' => 'standard', 'saisons' => array_values(array_filter($noms, static fn ($n) => mb_strtolower($n) !== 'standard'))]; }
    if (!empty($p['saisonnier']) || $noms !== []) { return ['gamme' => 'saison', 'saisons' => $noms]; }
    return ['gamme' => 'aucune', 'saisons' => []];
}

/** Un texte ramené à ses lettres pour le tri : minuscules, sans accents (« Épicerie » se range avec les E). Fonction pure. */
function ftCleTri(string $s): string
{
    $s = mb_strtolower(trim($s));
    if (class_exists('Normalizer')) { $s = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D)); }
    else { $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'œ' => 'oe']); }
    return (string) $s;
}

/** L'ordre du manuel : groupe, catégorie ; standard, saison, hors gamme ; nom. Fonction pure. */
function ftOrdre(array $p): array
{
    return [ftCleTri((string) ($p['groupe'] ?? '')), ftCleTri((string) ($p['categorie'] ?? '')),
        ['standard' => 0, 'saison' => 1, 'aucune' => 2][$p['gamme'] ?? 'aucune'] ?? 2, ftCleTri((string) ($p['nom'] ?? ''))];
}

/** GET /formation/ref/fiches — les fiches techniques, lues au panel par lots et gardées six heures. */
function ep_ft_fiches(bool $rafraichir = false): array
{
    if (!function_exists('ep_prod_catalogue')) { return foRefus(503, 'catalogue des produits indisponible'); }
    $cache = ftCacheLire();
    $maintenant = time();
    // La liste des produits : relue une fois par période de cache.
    if ($rafraichir || !is_array($cache['produits'] ?? null) || ($cache['listeLue'] ?? 0) < $maintenant - FT_DUREE) {
        $cat = ep_prod_catalogue();
        $prods = [];
        foreach (is_array($cat) ? $cat : [] as $p) {
            if (!is_array($p) || empty($p['pwaId'])) { continue; }
            $g = ftGamme($p);
            $prods[] = ['id' => (int) $p['pwaId'], 'ref' => (string) ($p['ref'] ?? ''), 'nom' => (string) ($p['nom'] ?? ''),
                'categorie' => (string) ($p['categorie'] ?? ''), 'groupe' => (string) ($p['groupe'] ?? ''), 'gamme' => $g['gamme'], 'saisons' => $g['saisons'],
                'prix' => isset($p['prix']) && is_numeric($p['prix']) ? (float) $p['prix'] : null, 'dlvHeures' => isset($p['dlv']) ? (int) $p['dlv'] : null];
        }
        if ($prods !== []) { $cache['produits'] = $prods; $cache['listeLue'] = $maintenant; }
        if ($rafraichir) { foreach ($cache['fiches'] as $k => $f) { $cache['fiches'][$k]['lu'] = 0; } }
    }
    $prods = is_array($cache['produits'] ?? null) ? $cache['produits'] : [];
    // Les fiches à lire : absentes ou trop vieilles, par lots, tant que le budget de temps le permet.
    $aLire = array_values(array_filter($prods, static fn ($p) => ($cache['fiches'][(string) $p['id']]['lu'] ?? 0) < $maintenant - FT_DUREE));
    $debut = microtime(true);
    $erreurs = 0;
    if ($aLire !== [] && class_exists('PanelApi') && PanelApi::configured()) {
        foreach (array_chunk($aLire, FT_LOT) as $lot) {
            if (microtime(true) - $debut > FT_BUDGET) { break; }
            $chemins = [];
            foreach ($lot as $p) { $chemins[(string) $p['id']] = '/products/' . $p['id'] . '/technical-sheet/raw?lang_code=fr'; }
            $res = PanelApi::getParallele($chemins, 4, 20);
            foreach ($chemins as $id => $c) {
                $d = $res[$id] ?? null;
                if (is_array($d) && isset($d['data']) && is_array($d['data'])) { $d = $d['data']; }
                if (is_array($d) && is_array($d['object'] ?? null)) { $cache['fiches'][$id] = ['lu' => time(), 'fiche' => ftResume($d)]; }
                else { $erreurs++; $cache['fiches'][$id] = ['lu' => time() - FT_DUREE + 600, 'fiche' => $cache['fiches'][$id]['fiche'] ?? null, 'erreur' => true]; }
            }
        }
        ftCacheEcrire($cache);
    }
    $out = []; $manquent = 0;
    foreach ($prods as $p) {
        $c = $cache['fiches'][(string) $p['id']] ?? null;
        if ($c === null || ($c['fiche'] ?? null) === null) { $manquent += empty($c['erreur']) ? 1 : 0; }
        // Le standard qualité (26/11/2026) : ce qui est regardé, ce qui est bon, ce qui ne l'est pas, selon les grilles
        // d'autocontrôle publiées du cockpit (consultant/src/autocontrole.php) ; calculé à chaque lecture, jamais gardé.
        $out[] = $p + ['fiche' => $c['fiche'] ?? null, 'qualite' => function_exists('aqStandardProduit') ? aqStandardProduit($p) : null];
    }
    usort($out, static fn ($a, $b) => ftOrdre($a) <=> ftOrdre($b));
    return ['produits' => $out, 'total' => count($out), 'manquent' => $manquent, 'erreurs' => $erreurs,
        'source' => 'panel TF Buddy : /products/{id}/technical-sheet/raw (comme l’application cuisine) · catalogue du cockpit',
        'lu' => date('c')];
}

/** GET /formation/ref/fiches/photo/{id} — la photo du produit : l'adresse signée du panel, redemandée à chaque fois. */
function ep_ft_photo(int $id): ?array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return foRefus(503, 'panel non configuré'); }
    $d = PanelApi::getParallele(['f' => '/products/' . $id . '/technical-sheet/raw?lang_code=fr'], 1, 15)['f'] ?? null;
    if (is_array($d) && isset($d['data']) && is_array($d['data'])) { $d = $d['data']; }
    $ph = is_array($d['photos'] ?? null) ? $d['photos'] : [];
    foreach (['shop_photo_path', 'main_photo_path', 'photo_1_path', 'photo_2_path', 'photo_3_path'] as $k) {
        $u = ftTxt($ph[$k] ?? '');
        if (preg_match('#^https://#', $u) === 1) {
            header('Location: ' . $u, true, 302);
            header('Cache-Control: private, max-age=1800');
            exit;
        }
    }
    return foRefus(404, 'pas de photo pour ce produit');
}
