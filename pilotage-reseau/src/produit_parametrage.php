<?php
declare(strict_types=1);

/**
 * Produits › Paramétrage (14/11/2026, maquette docs/maquettes/parametrage-produits, « tout sur une page ») : la fiche
 * d'un produit telle que le panel la rend, tous ses champs modifiables, ses gammes (saisons), son prix dans chaque
 * magasin, et les liaisons de l'API qui touchent un produit, mesurées.
 *
 * GET  /produits/parametrage/liste         — les produits (catalogue du panel, sinon ceux des magasins), leur catégorie,
 *                                           leurs gammes, les magasins qui les proposent.
 * GET  /produits/parametrage?pid=          — la fiche : le produit lu (GET /products/{id}), ses champs rangés en sections
 *                                           (seulement les clés que le panel rend : on n'écrit pas une clé devinée), les
 *                                           listes de référence, les gammes, chaque magasin, les liaisons.
 * POST /produits/parametrage {pid, apercu?, champs, gammes?, prix?, formats?}
 *      champs  → PATCH /products/{id}, les seules clés changées, dans la forme lue ; relu, constaté, au registre
 *                (« Annuler » remet l'avant) ;
 *      gammes  → [ids] : le format réglable `ppmFormatGammes` (PATCH /products/{id}/availability-periods par défaut),
 *                relu par /product-availability-periods/{id}/products ;
 *      prix    → {magasin: prix TTC} : le format réglable `ppmFormatPrix` (PATCH /shops/{s}/products/{p}/price), relu
 *                par /shops/{s}/products/{p}.
 *      Les routes de gammes et de prix n'ont pas de contrat au swagger : le format se voit et se règle dans l'aperçu,
 *      la réponse brute du panel s'affiche, et le format accepté se garde.
 * « CEO seul » : routes de la session du cockpit (routeGardee), comme les corrections de recettes.
 */

const PPM_SEC_REF = 86400;
const PPM_SEC_GAMMES = 3600;

/** Les champs de la fiche : clé du panel => [libellé, type, section, aide]. Ordre de l'écran. */
const PPM_CHAMPS = [
    'name' => ['Nom', 'texte', 'general', ''],
    'id_category' => ['Catégorie', 'categorie', 'general', ''],
    'sector_id' => ['Secteur', 'secteur', 'general', ''],
    'tax_val_perc' => ['TVA (%)', 'tva', 'general', ''],
    'is_active' => ['Actif', 'oui', 'general', ''],
    'is_pdm' => ['Produit du mois', 'oui', 'general', ''],
    'is_mandatory_assortment' => ['Assortiment obligatoire', 'oui', 'general', 'chaque magasin doit le proposer'],
    'suggested_sale_price' => ['Prix de vente conseillé (TTC)', 'prix', 'vente', ''],
    'expected_margin' => ['Marge attendue', 'nombre', 'vente', 'telle que le panel la tient (coefficient)'],
    'min_coefficient' => ['Coefficient minimum', 'nombre', 'vente', ''],
    'webshop_active' => ['Webshop', 'oui', 'vente', ''],
    'webshop_bulk_quantity_enabled' => ['Vente au volume (webshop)', 'oui', 'vente', ''],
    'click_and_collect' => ['Click & collect', 'oui', 'vente', ''],
    'office_delivery' => ['Livraison bureaux', 'oui', 'vente', ''],
    'is_personalization_addon' => ['Supplément personnalisable', 'oui', 'vente', ''],
    'id_recipe' => ['Recette', 'recette', 'fabrication', 'numéro de la recette au panel'],
    'is_piece_based' => ['Vendu à la pièce', 'oui', 'fabrication', ''],
    'single_weight' => ['Poids d’une pièce (g)', 'nombre', 'fabrication', ''],
    'is_divisible' => ['Divisible en portions', 'oui', 'fabrication', ''],
    'is_prepared_before_sales' => ['Préparé avant la vente', 'oui', 'fabrication', ''],
    'preparation_lead_time_hours' => ['Délai de préparation (h)', 'nombre', 'fabrication', ''],
    'production_planning_enabled' => ['Planning de production', 'oui', 'fabrication', ''],
    'id_storage' => ['Lieu de stockage', 'stockage', 'conservation', ''],
    'storage_temperature' => ['Température de stockage (°C)', 'nombre', 'conservation', ''],
    'shelf_life_minutes' => ['Durée de vie (minutes)', 'nombre', 'conservation', '1 440 = un jour'],
    'shelf_life_category' => ['Catégorie de durée', 'valeurs', 'conservation', ''],
    'id_positioning' => ['Position en vitrine', 'position', 'conservation', ''],
    'id_package' => ['Emballage', 'emballage', 'conservation', ''],
    'reheating_time_minutes' => ['Réchauffage — durée (min)', 'nombre', 'conservation', ''],
    'reheating_temperature_celsius' => ['Réchauffage — température (°C)', 'nombre', 'conservation', ''],
    'allergene' => ['Allergènes (texte)', 'texte', 'etiquette', 'ceux de la recette : « grouped_allergens »'],
    'nutriscore' => ['Nutri-Score', 'valeurs', 'etiquette', ''],
    'is_vegetarian' => ['Végétarien', 'oui', 'etiquette', ''],
    'label_size' => ['Taille de l’étiquette', 'valeurs', 'etiquette', ''],
    'quantity_per_label' => ['Pièces par étiquette', 'nombre', 'etiquette', ''],
];
const PPM_SECTIONS = ['general' => 'Général', 'vente' => 'Vente & canaux', 'fabrication' => 'Fabrication', 'conservation' => 'Conservation & vitrine', 'etiquette' => 'Étiquette & allergènes'];

/** Les liaisons d'un produit dans l'API : [famille, nom, route lue pour la mesurer (ou null), routes]. */
const PPM_LIAISONS = [
    ['definit', 'Catégorie', '/product-categories', 'GET|PATCH /product-categories/{id}'],
    ['definit', 'Groupes de catégories', '/product-category-groups', 'GET|PATCH /product-category-groups · POST /product-categories/{id}/groups'],
    ['definit', 'Zones de production', '/shops/production-areas', 'GET|POST /shops/production-areas'],
    ['definit', 'Secteurs', '/consultant/product-sectors', 'GET /consultant/product-sectors · /admin/product-sectors'],
    ['definit', 'Saisons & gammes', '/product-availability-periods', 'GET|POST /product-availability-periods · PATCH /products/{id}/availability-periods'],
    ['definit', 'Traductions', '/products/aliases', 'GET /products/aliases · PATCH /products/{id}/aliases'],
    ['fabrique', 'Recette', null, 'GET|PATCH /recipes/{id} · /shops/{id}/recipes/{r}/cost'],
    ['fabrique', 'Sous-recettes', '/subrecipe-categories', 'GET /subrecipes · /subrecipe-categories'],
    ['fabrique', 'Portions', null, 'GET|POST /products/{id}/portions · PATCH /product-portions/{id}'],
    ['fabrique', 'Compétences', '/product-competences', 'GET /product-competences · POST /products/{id}/competences'],
    ['fabrique', 'Planning de production', null, 'GET /shops/{id}/statistics/production-planning'],
    ['range', 'Lieux de stockage', '/product-storage-locations', 'GET|POST /product-storage-locations'],
    ['range', 'Positions en vitrine', '/product-display-positions', 'GET|POST /product-display-positions'],
    ['range', 'Emballages', '/materials/pack-options', 'GET /materials/pack-options · /product-packages'],
    ['range', 'Fiches techniques', '/products/technical-sheets', 'GET /products/{id}/technical-sheet'],
    ['range', 'Allergènes', '/allergens', 'GET /allergens · /products/allergens/document'],
    ['vend', 'Magasins : présence', null, 'GET /shops/{id}/products/available · /shops/{id}/categories'],
    ['vend', 'Magasins : prix', null, 'PATCH /shops/{id}/products/{p}/price · GET /shops/{id}/products/{p}/portions/prices'],
    ['vend', 'Promotions groupées', '/admin/promotions/bundles', 'GET|POST /admin/promotions/bundles'],
    ['vend', '« x achetés, y offerts »', '/admin/promotions/buy-x-get-y', 'GET|POST /admin/promotions/buy-x-get-y'],
    ['vend', 'Prix au volume', '/admin/promotions/quantity', 'GET|POST /admin/promotions/quantity'],
    ['vend', 'Remises programmées', '/admin/promotions/scheduled-product-discount', 'GET|POST /admin/promotions/scheduled-product-discount'],
    ['vend', 'Codes promo', '/promo-codes', 'GET|POST /promo-codes'],
    ['mesure', 'Ventes', null, 'GET /shops/{id}/statistics/sales/… · /transactions/{id}/products'],
    ['mesure', 'Pertes', null, 'GET /shops/{id}/products/{p}/waste'],
    ['mesure', 'Concurrence', null, 'GET /shops/{id}/benchmarks/analysis/my-products'],
];

/** Une liste de référence du panel, gardée un jour : [id => [nom, …extra]]. */
function ppmReference(string $cle, string $chemin, array $extra = []): array
{
    $c = vfCache($cle, PPM_SEC_REF);
    if ($c !== null) { return $c['v']; }
    $out = [];
    $r = PanelApi::get($chemin);
    foreach (is_array($r) ? PanelApi::liste($r) : [] as $x) {
        $id = $x['id'] ?? null;
        if (!is_scalar($id) || (string) $id === '') { continue; }
        $n = trim(vfTxt($x, ['name', 'base_name', 'label', 'code']));
        $o = ['nom' => $n !== '' ? $n : (string) $id];
        foreach ($extra as $k) { if (array_key_exists($k, $x) && (is_scalar($x[$k]) || $x[$k] === null)) { $o[$k] = $x[$k]; } }
        $out[(string) $id] = $o;
    }
    if ($out !== []) { vfGarder($cle, $out); }
    return $out;
}

/** Les gammes du panel, et les produits de chacune (gardés une heure) : [gammes, pid => [ids de gammes]]. */
function ppmGammes(bool $frais = false): array
{
    $c = $frais ? null : vfCache('ppmGammes', PPM_SEC_GAMMES);
    if ($c !== null) { return $c['v']; }
    $gammes = [];
    foreach (PanelApi::liste((array) PanelApi::get('/product-availability-periods')) as $g) {
        if (!is_numeric($g['id'] ?? null)) { continue; }
        $gammes[(string) (int) $g['id']] = ['id' => (int) $g['id'], 'nom' => trim((string) ($g['name'] ?? '')), 'du' => (int) ($g['from_md'] ?? 0), 'au' => (int) ($g['to_md'] ?? 0),
            'recurrente' => (bool) ($g['is_recurring'] ?? false), 'active' => (bool) ($g['is_active'] ?? false), 'webshop' => (bool) ($g['webshop_active'] ?? false), 'n' => null];
    }
    $chemins = [];
    foreach ($gammes as $id => $g) { $chemins[$id] = '/product-availability-periods/' . $id . '/products'; }
    $par = [];
    foreach (PanelApi::getParallele($chemins) as $id => $r) {
        $ids = ppmIdsProduits($r);
        if ($ids === null) { continue; }
        $gammes[$id]['n'] = count($ids);
        foreach ($ids as $pid) { $par[(string) $pid][] = (int) $id; }
    }
    $v = ['gammes' => array_values($gammes), 'par' => $par, 'lu' => date('c')];
    if ($gammes !== []) { vfGarder('ppmGammes', $v); }
    return $v;
}

/** Les produits d'une gamme, quelle que soit la forme : [pid] ; null si la réponse n'est pas lisible. Fonction pure. */
function ppmIdsProduits(mixed $r): ?array
{
    if (!is_array($r)) { return null; }
    $l = PanelApi::liste($r);
    if ($l === [] && array_is_list($r)) { $l = $r; }
    $ids = [];
    foreach ($l as $x) {
        if (is_numeric($x)) { $ids[] = (int) $x; continue; }
        if (!is_array($x)) { continue; }
        $id = $x['id_product'] ?? ($x['product_id'] ?? ($x['pivot']['id_product'] ?? ($x['id'] ?? null)));
        if (is_numeric($id)) { $ids[] = (int) $id; }
    }
    return array_values(array_unique($ids));
}

/** Les valeurs vues dans les produits des magasins pour les champs à valeurs (jamais une liste devinée). */
function ppmValeursVues(array $sids): array
{
    $vues = ['shelf_life_category' => [], 'label_size' => [], 'nutriscore' => ['' => ''], 'positions' => [], 'stockages' => []];
    foreach ($sids as $sid) {
        foreach (PanelApi::produitsDisponibles((int) $sid) as $l) {
            foreach (['shelf_life_category', 'label_size', 'nutriscore'] as $k) { if (is_scalar($l[$k] ?? null) && (string) $l[$k] !== '') { $vues[$k][(string) $l[$k]] = (string) $l[$k]; } }
        }
    }
    foreach (['A', 'B', 'C', 'D', 'E'] as $n) { $vues['nutriscore'][$n] = $n; }   // l'échelle officielle du Nutri-Score
    foreach ($vues as $k => $v) { ksort($vues[$k]); $vues[$k] = array_values($vues[$k]); }
    return $vues;
}

/** Les produits du réseau : le catalogue du panel s'il se lit, sinon ceux des magasins. */
function ep_produits_parametrage_liste(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).', 'produits' => []]; }
    $frais = !empty($_GET['rafraichir']);
    $c = $frais ? null : vfCache('ppmListe', 1800);
    if ($c !== null) { return $c['v'] + ['cache' => date('c', (int) $c['le'])]; }
    @set_time_limit(120);
    $mags = vfMagasins(); $sids = array_keys($mags);
    $P = [];
    $catalogue = PanelApi::get('/products');
    $source = 'magasins';
    foreach (is_array($catalogue) ? PanelApi::liste($catalogue) : [] as $x) {
        if (!is_numeric($x['id'] ?? null)) { continue; }
        $source = 'catalogue';
        $P[(int) $x['id']] = ['pid' => (int) $x['id'], 'nom' => trim((string) ($x['name'] ?? '')), 'cat' => trim((string) ($x['category_name'] ?? '')), 'actif' => (bool) ($x['is_active'] ?? true),
            'webshop' => (bool) ($x['webshop_active'] ?? false), 'recette' => is_numeric($x['id_recipe'] ?? null) ? (int) $x['id_recipe'] : null, 'magasins' => []];
    }
    foreach ($sids as $sid) {
        foreach (PanelApi::produitsDisponibles((int) $sid) as $x) {
            if (!is_numeric($x['id'] ?? null)) { continue; }
            $pid = (int) $x['id'];
            $P[$pid] ??= ['pid' => $pid, 'nom' => trim((string) ($x['name'] ?? '')), 'cat' => trim((string) ($x['category_name'] ?? '')), 'actif' => (bool) ($x['is_active'] ?? true),
                'webshop' => (bool) ($x['webshop_active'] ?? false), 'recette' => is_numeric($x['id_recipe'] ?? null) ? (int) $x['id_recipe'] : null, 'magasins' => []];
            $P[$pid]['magasins'][] = (string) $sid;
        }
    }
    $G = ppmGammes($frais);
    foreach ($P as $pid => $p) { $P[$pid]['gammes'] = $G['par'][(string) $pid] ?? []; }
    usort($P, static fn ($a, $b) => [$a['cat'], $a['nom']] <=> [$b['cat'], $b['nom']]);
    $v = ['produits' => array_values($P), 'gammes' => $G['gammes'], 'magasins' => array_map(static fn ($id, $n) => ['id' => (string) $id, 'nom' => $n], $sids, array_values($mags)),
        'source' => $source === 'catalogue' ? 'GET /products (le catalogue du panel) · présence : /shops/{id}/products/available · gammes : /product-availability-periods/{id}/products'
            : 'GET /shops/{id}/products/available (le catalogue /products ne se lit pas : seuls les produits des magasins) · gammes : /product-availability-periods/{id}/products',
        'lu' => date('c')];
    vfGarder('ppmListe', $v);
    return $v + ['cache' => null];
}

/** La mesure des liaisons : chaque route de lecture, son code HTTP et le nombre d'éléments rendus (gardée un jour). */
function ppmLiaisons(bool $frais = false): array
{
    $c = $frais ? null : vfCache('ppmLiaisons', PPM_SEC_REF);
    if ($c !== null) { return $c['v']; }
    $chemins = [];
    foreach (PPM_LIAISONS as $i => $l) { if ($l[2] !== null) { $chemins[$i] = $l[2]; } }
    $D = PanelApi::getParalleleDetail($chemins, 4, 20, false);
    $out = [];
    foreach (PPM_LIAISONS as $i => [$fam, $nom, $lue, $routes]) {
        $d = $D[$i] ?? null;
        $code = $d['code'] ?? null;
        $n = $d !== null && is_array($d['data'] ?? null) ? count(PanelApi::liste($d['data'])) : null;
        $out[] = ['famille' => $fam, 'nom' => $nom, 'routes' => $routes, 'lue' => $lue, 'code' => $code, 'n' => $n,
            'etat' => $lue === null ? 'fiche' : ($code !== null && $code >= 200 && $code < 300 ? 'lisible' : 'refusee')];
    }
    vfGarder('ppmLiaisons', $out);
    return $out;
}

/** Le produit dans chaque magasin : présence, prix TTC et HT, coût de la recette, stock (products/available). */
function ppmMagasins(int $pid, array $mags): array
{
    $out = [];
    foreach ($mags as $sid => $nom) {
        $l = null;
        foreach (PanelApi::produitsDisponibles((int) $sid) as $x) { if ((int) ($x['id'] ?? 0) === $pid) { $l = $x; break; } }
        $ttc = $l !== null ? vfNum($l, ['portion_price_gross', 'portion_price']) : null;
        $ht = $l !== null ? vfNum($l, ['portion_price_net']) : null;
        $cout = $l !== null ? vfNum($l, ['recipe_cost_net']) : null;
        $out[] = ['id' => (string) $sid, 'nom' => $nom, 'present' => $l !== null, 'ttc' => $ttc, 'ht' => $ht !== null ? round($ht, 4) : null, 'cout' => $cout !== null ? round($cout, 4) : null,
            'coef' => $cout > 0 && $ht !== null ? round($ht / $cout, 2) : null, 'food' => $cout !== null && $ht > 0 ? round(100 * $cout / $ht, 1) : null,
            'max' => $l !== null ? vfNum($l, ['max_portion_price']) : null, 'stock' => $l !== null ? vfNum($l, ['in_stock']) : null];
    }
    return $out;
}

/** GET /produits/parametrage?pid= — la fiche. */
function ep_produits_parametrage(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    $pid = (int) ($_GET['pid'] ?? 0);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'pid requis']; }
    @set_time_limit(90);
    $o = vrcObjet('produit', $pid);
    if ($o === null) { return ['ok' => false, 'motif' => 'Le produit ' . $pid . ' n’a pas pu être lu au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $mags = vfMagasins();
    $vues = ppmValeursVues(array_keys($mags));
    $refs = [
        'categories' => ppmReference('ppmCategories', '/product-categories', ['price_coefficient', 'production_area_name', 'is_active']),
        'secteurs' => ppmReference('ppmSecteurs', '/consultant/product-sectors'),
        'stockages' => ppmReference('ppmStockages', '/product-storage-locations', ['description']),
        'positions' => ppmReference('ppmPositions', '/product-display-positions', ['description']),
        'emballages' => ppmReference('ppmEmballages', '/materials/pack-options'),
        'groupes' => ppmReference('ppmGroupes', '/product-category-groups'),
    ];
    // Ce que le produit porte déjà, quand la liste de référence ne se lit pas : la valeur courante garde son nom.
    $courant = ['secteurs' => ['sector_id', 'sector_name'], 'stockages' => ['id_storage', 'storage_name'], 'positions' => ['id_positioning', 'positioning_name'], 'categories' => ['id_category', 'category_name']];
    foreach ($courant as $r => [$k, $n]) { if (is_scalar($o[$k] ?? null) && !isset($refs[$r][(string) $o[$k]])) { $refs[$r][(string) $o[$k]] = ['nom' => trim((string) ($o[$n] ?? '')) ?: ('n° ' . $o[$k])]; } }
    if (is_scalar($o['id_package'] ?? null) && !isset($refs['emballages'][(string) $o['id_package']])) { $refs['emballages'][(string) $o['id_package']] = ['nom' => 'n° ' . $o['id_package']]; }
    $champs = [];
    foreach (PPM_CHAMPS as $k => [$lib, $type, $sec, $aide]) {
        $champs[] = ['cle' => $k, 'lib' => $lib, 'type' => $type, 'section' => $sec, 'aide' => $aide, 'valeur' => $o[$k] ?? null, 'modifiable' => array_key_exists($k, $o),
            'valeurs' => $type === 'valeurs' ? array_values(array_unique(array_merge($vues[$k] ?? [], is_scalar($o[$k] ?? null) ? [(string) $o[$k]] : []))) : null];
    }
    $G = ppmGammes();
    $dispo = null;
    foreach (array_keys($mags) as $sid) { foreach (PanelApi::produitsDisponibles((int) $sid) as $x) { if ((int) ($x['id'] ?? 0) === $pid) { $dispo = $x; break 2; } } }
    $rid = is_numeric($o['id_recipe'] ?? null) ? (int) $o['id_recipe'] : null;
    $recette = null;
    if ($rid) { $R = vfRecettesEnCache([$rid])[$rid] ?? null; $recette = ['rid' => $rid, 'nom' => $R['nom'] ?? null, 'lignes' => count((array) ($R['lignes'] ?? [])), 'cout' => $R['coutNet'] ?? null]; }
    $cles = array_keys(PPM_CHAMPS);
    return ['ok' => true, 'pid' => $pid, 'nom' => vfTxt($o, ['name']), 'champs' => $champs, 'sections' => PPM_SECTIONS,
        'autres' => array_values(array_diff(array_keys($o), $cles)), 'brut' => array_intersect_key($o, array_flip(array_diff(array_keys($o), $cles))),
        'lecture' => $dispo === null ? null : array_intersect_key($dispo, array_flip(['base_name', 'grouped_allergens', 'label_ingredients', 'recipe_cost_net', 'max_portion_price', 'inventory_initialized_at'])),
        'categorie' => $o['category'] ?? ($dispo['category'] ?? null), 'recette' => $recette, 'competences' => $o['required_competences'] ?? [],
        'refs' => $refs + ['tva' => [0, 6, 12, 21]],
        'gammes' => $G['gammes'], 'gammesProduit' => $G['par'][(string) $pid] ?? [], 'gammesLues' => $G['lu'] ?? null,
        'magasins' => ppmMagasins($pid, $mags), 'liaisons' => ppmLiaisons(!empty($_GET['mesurer'])),
        'formats' => ['gammes' => ppmFormat('gammes'), 'prix' => ppmFormat('prix')],
        'lienPanel' => str_replace(['{pid}', '{id}', '{rid}'], [(string) $pid, (string) $pid, (string) ($rid ?? '')], vfLienPanelModele()),
        'lu' => date('c'), 'source' => 'GET /products/{id} (le produit, pour tout le réseau) · listes : /product-categories, /consultant/product-sectors, /product-storage-locations, /product-display-positions, /materials/pack-options · gammes : /product-availability-periods · magasins : /shops/{id}/products/available'];
}

/** Le format d'envoi des gammes ou du prix d'un magasin : réglé (gardé quand le panel l'a pris), sinon celui par défaut. */
function ppmFormat(string $quoi): array
{
    $def = $quoi === 'gammes'
        ? ['methode' => 'PATCH', 'route' => '/products/{pid}/availability-periods', 'corps' => ['availability_period_ids' => '{gammes}']]
        : ['methode' => 'PATCH', 'route' => '/shops/{sid}/products/{pid}/price', 'corps' => ['price' => '{prix}']];
    $f = []; try { $f = setting('ppmFormat_' . $quoi, []); } catch (Throwable $e) { $f = []; }
    return is_array($f) && ppmFormatValable($f, $quoi) !== null ? ppmFormatValable($f, $quoi) : $def;
}

/** Un format saisi : méthode POST|PATCH|PUT, route du panel permise pour ce geste, corps nom → valeur. Null sinon. */
function ppmFormatValable(mixed $f, string $quoi): ?array
{
    if (!is_array($f)) { return null; }
    $m = strtoupper((string) ($f['methode'] ?? ''));
    $r = (string) ($f['route'] ?? '');
    $permises = $quoi === 'gammes' ? ['#^/products/\{pid\}/availability-periods$#', '#^/product-availability-periods/\{gid\}/products$#'] : ['#^/shops/\{sid\}/products/\{pid\}(/price)?$#'];
    $ok = false; foreach ($permises as $re) { if (preg_match($re, $r)) { $ok = true; } }
    if (!in_array($m, ['POST', 'PATCH', 'PUT'], true) || !$ok || !is_array($f['corps'] ?? null)) { return null; }
    $corps = [];
    foreach ($f['corps'] as $k => $v) { $k = trim((string) $k); if ($k !== '' && preg_match('/^[A-Za-z_][\w.]{0,60}$/', $k) && (is_scalar($v) || $v === null)) { $corps[$k] = $v; } }
    return $corps === [] ? null : ['methode' => $m, 'route' => $r, 'corps' => $corps];
}

/** Le corps d'un format, valeurs posées : {gammes} → liste d'ids, {prix} → nombre, {pid} {sid} {gid} → entiers. Fonction pure. */
function ppmCorps(array $f, array $vals): array
{
    $c = [];
    foreach ($f['corps'] as $k => $v) {
        if (is_string($v) && array_key_exists($v, $vals)) { $c[$k] = $vals[$v]; }
        elseif (is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v)) { $c[$k] = $v + 0; }
        else { $c[$k] = $v; }
    }
    return $c;
}

/** Les changements des champs du produit : seules les clés que le panel rend, et qui changent. Fonction pure. */
function ppmChangements(array $o, array $champs): array
{
    $corps = []; $inverse = []; $lisible = []; $erreurs = [];
    foreach ($champs as $k => $v) {
        if (!isset(PPM_CHAMPS[$k])) { $erreurs[] = 'Champ inconnu : ' . $k . '.'; continue; }
        [$lib, $type] = PPM_CHAMPS[$k];
        if (!array_key_exists($k, $o)) { $erreurs[] = '« ' . $lib . ' » : le panel ne le rend pas pour ce produit, il ne s’écrit pas d’ici.'; continue; }
        $avant = $o[$k];
        if ($type === 'oui') {
            $b = $v === true || $v === 1 || $v === '1' || $v === 'true';
            if ((bool) (int) $avant === $b) { continue; }
            $corps[$k] = is_bool($avant) ? $b : ($b ? 1 : 0);
            $lisible[] = $lib . ' : ' . ($b ? 'oui' : 'non');
        } elseif (in_array($type, ['nombre', 'prix', 'tva'], true)) {
            if ($v === null || $v === '') {
                if ($avant === null || $avant === '') { continue; }
                $corps[$k] = null; $lisible[] = $lib . ' : vidé';
            } else {
                $t = str_replace([' ', ','], ['', '.'], (string) $v);
                if (!is_numeric($t) || (float) $t < -50) { $erreurs[] = '« ' . $lib . ' » : valeur invalide.'; continue; }
                if ($avant !== null && is_numeric($avant) && abs((float) $avant - (float) $t) <= 1e-9 * max(1.0, abs((float) $t))) { continue; }
                // La forme lue : un texte décimal reste un texte (« 21.90 »), un entier reste un entier.
                $corps[$k] = is_string($avant) ? (string) (float) $t : ((float) $t == (int) $t && (is_int($avant) || $avant === null) ? (int) $t : (float) $t);
                if (is_string($avant) && preg_match('/^-?\d+\.(\d+)$/', $avant, $mm)) { $corps[$k] = number_format((float) $t, strlen($mm[1]), '.', ''); }
                $lisible[] = $lib . ' : ' . ($avant === null || $avant === '' ? '—' : $avant) . ' → ' . $corps[$k];
            }
        } elseif (in_array($type, ['categorie', 'secteur', 'stockage', 'position', 'emballage', 'recette'], true)) {
            if ($v === null || $v === '') {
                if ($avant === null) { continue; }
                if (in_array($k, ['id_category'], true)) { $erreurs[] = '« ' . $lib . ' » ne peut pas être vide.'; continue; }
                $corps[$k] = null; $lisible[] = $lib . ' : retiré';
            } else {
                if (!is_numeric($v) || (int) $v <= 0) { $erreurs[] = '« ' . $lib . ' » : valeur invalide.'; continue; }
                if ($avant !== null && (int) $avant === (int) $v) { continue; }
                $corps[$k] = (int) $v;
                $lisible[] = $lib . ' : ' . ($avant ?? '—') . ' → ' . (int) $v;
            }
        } else {
            $t = trim((string) ($v ?? ''));
            if ($t === '' && $k === 'name') { $erreurs[] = 'Le nom ne peut pas être vide.'; continue; }
            if ((string) ($avant ?? '') === $t) { continue; }
            $corps[$k] = mb_substr($t, 0, 300);
            $lisible[] = $lib . ' : « ' . ($avant ?? '') . ' » → « ' . $corps[$k] . ' »';
        }
        $inverse[$k] = $avant;
    }
    return ['corps' => $corps, 'inverse' => $inverse, 'lisible' => $lisible, 'erreurs' => $erreurs];
}

/** Les envois des gammes : un seul (route du produit) ou un par gamme changée (route de la gamme). Fonction pure. */
function ppmPlanGammes(array $f, int $pid, array $avant, array $apres, array $produitsDe): array
{
    sort($apres);
    if (str_contains($f['route'], '{gid}')) {
        $ec = [];
        foreach (array_unique(array_merge(array_diff($apres, $avant), array_diff($avant, $apres))) as $gid) {
            $ids = $produitsDe[$gid] ?? [];
            $ids = in_array($gid, $apres, true) ? array_values(array_unique(array_merge($ids, [$pid]))) : array_values(array_diff($ids, [$pid]));
            $ec[] = ['methode' => $f['methode'], 'route' => str_replace('{gid}', (string) $gid, $f['route']), 'corps' => ppmCorps($f, ['{produits}' => $ids, '{pid}' => $pid, '{gid}' => $gid, '{gammes}' => $apres]), 'gid' => $gid];
        }
        return $ec;
    }
    return [['methode' => $f['methode'], 'route' => str_replace('{pid}', (string) $pid, $f['route']), 'corps' => ppmCorps($f, ['{gammes}' => $apres, '{pid}' => $pid]), 'gid' => null]];
}

/** Envoie au panel, garde la réponse brute. Rend [ok, http, réponse]. */
function ppmEnvoi(string $m, string $route, array $corps): array
{
    PanelApi::$lastError = null;
    [$ok, $res] = PanelApi::envoi($m, $route, $corps);
    $http = preg_match('/HTTP (\d{3})/', (string) PanelApi::$lastError, $mm) ? (int) $mm[1] : ($ok ? 200 : 0);
    return [$ok, $http, mb_substr((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 3000), $res];
}

/** POST /produits/parametrage — les champs, puis les gammes, puis les prix des magasins. */
function wr_produits_parametrage(): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'error' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    @set_time_limit(120);
    $b = body();
    $pid = (int) ($b['pid'] ?? 0); $apercu = !empty($b['apercu']);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'pid requis']; }
    $o = vrcObjet('produit', $pid);
    if ($o === null) { return ['ok' => false, 'error' => 'Le produit ' . $pid . ' n’a pas pu être lu au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $nom = vfTxt($o, ['name']) ?: ('produit ' . $pid);
    $m = ppmChangements($o, is_array($b['champs'] ?? null) ? $b['champs'] : []);
    if ($m['erreurs'] !== []) { return ['ok' => false, 'error' => implode(' ', $m['erreurs'])]; }
    // Les formats saisis dans l'aperçu (gammes, prix) : valables, sinon refusés avant tout envoi.
    $formats = [];
    foreach (['gammes', 'prix'] as $q) {
        if (isset($b['formats'][$q])) {
            $f = ppmFormatValable($b['formats'][$q], $q);
            if ($f === null) { return ['ok' => false, 'error' => 'Le format d’envoi ' . ($q === 'gammes' ? 'des gammes' : 'des prix') . ' n’est pas valable (méthode POST, PATCH ou PUT ; route de ' . ($q === 'gammes' ? 'gamme' : 'prix') . ' du panel ; champs nom → valeur).']; }
            $formats[$q] = $f;
        } else { $formats[$q] = ppmFormat($q); }
    }
    $G = ppmGammes(true);
    $avantG = $G['par'][(string) $pid] ?? []; sort($avantG);
    $apresG = null;
    if (isset($b['gammes']) && is_array($b['gammes'])) {
        $connues = array_map(static fn ($g) => (int) $g['id'], $G['gammes']);
        $apresG = array_values(array_unique(array_filter(array_map('intval', $b['gammes']), static fn ($g) => in_array($g, $connues, true)))); sort($apresG);
        if ($apresG === $avantG) { $apresG = null; }
    }
    $produitsDe = [];
    foreach ($G['par'] as $p => $gs) { foreach ($gs as $g) { $produitsDe[$g][] = (int) $p; } }
    $mags = vfMagasins();
    $avantP = []; foreach (ppmMagasins($pid, $mags) as $x) { $avantP[(int) $x['id']] = $x['ttc']; }
    $prix = [];
    foreach ((array) ($b['prix'] ?? []) as $sid => $p) {
        $t = str_replace([' ', ','], ['', '.'], (string) $p);
        if ((int) $sid > 0 && isset($mags[(int) $sid]) && is_numeric($t) && (float) $t > 0 && abs((float) $t - (float) ($avantP[(int) $sid] ?? 0)) > 0.004) { $prix[(int) $sid] = round((float) $t, 2); }
    }
    if ($m['corps'] === [] && $apresG === null && $prix === []) { return ['ok' => false, 'error' => 'Rien à changer.']; }
    $plansG = $apresG !== null ? ppmPlanGammes($formats['gammes'], $pid, $avantG, $apresG, $produitsDe) : [];
    $nomG = []; foreach ($G['gammes'] as $g) { $nomG[$g['id']] = $g['nom']; }
    $resumeG = $apresG === null ? '' : 'Gammes : ' . ($apresG === [] ? 'aucune' : implode(', ', array_map(static fn ($g) => $nomG[$g] ?? (string) $g, $apresG)));
    $plansP = [];
    foreach ($prix as $s => $p) {
        $f = $formats['prix'];
        $plansP[$s] = ['methode' => $f['methode'], 'route' => str_replace(['{sid}', '{pid}'], [(string) $s, (string) $pid], $f['route']), 'corps' => ppmCorps($f, ['{prix}' => $p, '{pid}' => $pid, '{sid}' => $s]),
            'resume' => 'Prix à ' . preg_replace('/^.* - /', '', $mags[$s]) . ' : ' . ($avantP[$s] === null ? '—' : vfEur((float) $avantP[$s], 2)) . ' → ' . vfEur($p, 2) . ' TTC'];
    }
    if ($apercu) {
        $ec = [];
        if ($m['corps'] !== []) { $ec[] = ['methode' => 'PATCH', 'route' => vrcChemin('produit', $pid), 'corps' => $m['corps'], 'resume' => implode(' · ', $m['lisible']), 'quoi' => 'champs']; }
        foreach ($plansG as $pl) { $ec[] = $pl + ['resume' => $resumeG . ($pl['gid'] ? ' (gamme ' . ($nomG[$pl['gid']] ?? $pl['gid']) . ')' : ''), 'quoi' => 'gammes']; }
        foreach ($plansP as $pl) { $ec[] = $pl + ['quoi' => 'prix']; }
        return ['apercu' => true, 'resume' => $nom . ' : ' . count($ec) . ' envoi(s)', 'ecritures' => $ec, 'formats' => $formats];
    }
    $base = ['geste' => 'produit', 'cle' => 'prod:' . $pid, 'pid' => $pid, 'nom' => $nom, 'shop' => null];
    $faites = [];
    if ($m['corps'] !== []) {
        $r = vrcEcrireObjet($base + ['objet' => 'produit', 'id' => $pid, 'objetId' => $pid, 'corps' => $m['corps'], 'inverse' => $m['inverse'], 'resume' => implode(' · ', $m['lisible'])]);
        $faites[] = vrcLigneRegistre($r);
    }
    if ($plansG !== []) {
        $okG = true; $rep = []; $http = 0;
        foreach ($plansG as $pl) { [$ok, $http, $rep[]] = ppmEnvoi($pl['methode'], $pl['route'], $pl['corps']); $okG = $okG && $ok; if (!$ok) { break; } }
        $r = $base + ['objet' => 'gammesProduit', 'objetId' => $pid, 'methode' => $plansG[0]['methode'], 'route' => $plansG[0]['route'], 'corps' => $plansG[0]['corps'], 'http' => $http, 'resume' => $resumeG, 'inverse' => null];
        if (!$okG) { $r['resultat'] = 'refuse'; $r['message'] = 'Le panel a refusé.'; }
        else {
            vrcOublier('ppmGammes'); vrcOublier('ppmListe');
            $lu = ppmGammes(true)['par'][(string) $pid] ?? []; sort($lu);
            $r['resultat'] = $lu === $apresG ? 'ok' : 'sans_effet';
            $r['message'] = $r['resultat'] === 'ok' ? '' : 'Le panel a accepté, mais les gammes relues sont : ' . ($lu === [] ? 'aucune' : implode(', ', array_map(static fn ($g) => $nomG[$g] ?? (string) $g, $lu))) . ' — le format attendu diffère.';
            if ($r['resultat'] === 'ok' && !empty($b['garder']) && isset($b['formats']['gammes'])) { vfGarderBrut('ppmFormat_gammes', $formats['gammes']); }
        }
        $r['id'] = vrcNoter($r);
        $faites[] = vrcLigneRegistre($r + ['reponse' => implode("\n", $rep), 'httpPanel' => $http]);
    }
    foreach ($plansP as $s => $pl) {
        [$ok, $http, $rep] = ppmEnvoi($pl['methode'], $pl['route'], $pl['corps']);
        $r = $base + ['shop' => $s, 'objet' => 'prixProduit', 'objetId' => $pid, 'methode' => $pl['methode'], 'route' => $pl['route'], 'corps' => $pl['corps'], 'http' => $http, 'resume' => $pl['resume'], 'inverse' => null];
        if (!$ok) { $r['resultat'] = 'refuse'; $r['message'] = vrcMessage(json_decode($rep, true)); }
        else {
            $relu = vrcDeballer(PanelApi::get('/shops/' . $s . '/products/' . $pid));
            $lu = $relu !== null ? vfNum($relu, ['portion_price_gross', 'portion_price', 'price', 'sale_price']) : null;
            $r['resultat'] = $lu === null || abs($lu - $prix[$s]) < 0.005 ? 'ok' : 'sans_effet';
            $r['message'] = $lu === null ? 'Accepté ; le prix n’a pas pu être relu (/shops/' . $s . '/products/' . $pid . ').' : ($r['resultat'] === 'ok' ? '' : 'Le panel a accepté, mais le prix relu vaut ' . vfEur($lu, 2) . ' : le format attendu diffère.');
            if ($r['resultat'] === 'ok' && $lu !== null && !empty($b['garder']) && isset($b['formats']['prix'])) { vfGarderBrut('ppmFormat_prix', $formats['prix']); }
        }
        $r['id'] = vrcNoter($r);
        $faites[] = vrcLigneRegistre($r + ['reponse' => $rep, 'httpPanel' => $http]);
    }
    if (array_filter($faites, static fn ($f) => $f['resultat'] === 'ok') !== []) {
        vrcOublier('ppmListe'); vrcOublier('vfAnalyse');
        if (method_exists(PanelApi::class, 'oublierDispo')) { PanelApi::oublierDispo(); }
        if (function_exists('vrcOublierDispo')) { vrcOublierDispo(); }
    }
    $ok = $faites !== [] && array_reduce($faites, static fn ($a, $f) => $a && $f['resultat'] === 'ok', true);
    return ['ok' => $ok, 'ecritures' => $faites];
}
