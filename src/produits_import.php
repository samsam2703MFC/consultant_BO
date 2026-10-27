<?php
declare(strict_types=1);

/**
 * Encoder l'épicerie à partir du PDF d'un fournisseur — demandes du 27/10/2026.
 *
 * Le réseau revend en épicerie des articles achetés tels quels. Dans TFBuddy,
 * chaque article est une CHAÎNE : fournisseur → article de son catalogue (réf.,
 * colis, prix) → matière → recette (1 × la matière) → produit de vente, rangé
 * dans une catégorie. On scanne le tarif du fournisseur ; un assistant en cinq
 * étapes encode la chaîne et la fait valider :
 *
 *  1. DOCUMENT. Le PDF part à Claude, côté serveur (la clé ne quitte jamais le
 *     cockpit) ; la réponse est un JSON contraint par schéma.
 *  2. FOURNISSEUR. Celui du PDF, rapproché de /material-suppliers (nom ou n° de
 *     TVA) : existant ou à créer.
 *  3. ARTICLES & MATIÈRES. Chaque article, son colis et son prix d'achat ; la
 *     matière existe déjà (rapprochée par la référence du catalogue
 *     fournisseur, puis par le nom) ou sera créée.
 *  4. PRODUITS DE VENTE. Nom, catégorie, TVA, prix ; le coefficient (prix HT ÷
 *     coût d'achat de la pièce) face à la cible de la catégorie
 *     (`price_coefficient` de /product-categories).
 *  5. VALIDATION. Des contrôles BLOQUANTS (rien ne se valide incomplet) et des
 *     avertissements ; la validation est signée (qui, quand) et fige le
 *     brouillon.
 *
 * Puis la création chez TFBuddy, ligne par ligne, par UNE route atomique —
 * `POST /products/resale` — telle que demandée dans docs/spec-api-panel.md §7.
 * Au 27/10/2026 elle n'existe pas : le Swagger ne porte que des GET sur
 * fournisseurs, matières et produits. La vérification (OPTIONS, rien d'écrit)
 * le constate et l'écran propose l'export CSV pour encoder à la main.
 */

/** 8 Mo : la limite des autres dépôts PDF du cockpit (annexes, plan trimestriel). */
const PI_MAX_OCTETS = 8 * 1024 * 1024;
const PI_MAX_LIGNES = 200;

/** La route de création demandée à TFBuddy (spec §7), et celles que la vérification interroge. */
const PI_ROUTE = '/products/resale';
const PI_ROUTES_SONDE = ['/products/resale', '/products', '/materials', '/material-suppliers'];

/** Les 14 allergènes du règlement UE, codes du panel (GET /allergens) → libellé. */
const PI_ALLERGENES = ['cereals_gluten' => 'Gluten', 'crustaceans' => 'Crustacés', 'eggs' => 'Œufs', 'fish' => 'Poisson',
    'peanuts' => 'Arachides', 'soybeans' => 'Soja', 'milk' => 'Lait', 'nuts' => 'Fruits à coque', 'celery' => 'Céleri',
    'mustard' => 'Moutarde', 'sesame_seeds' => 'Sésame', 'sulphur_dioxide_sulphites' => 'Sulfites', 'lupin' => 'Lupin',
    'molluscs' => 'Mollusques'];

/** Sous ce coefficient, la marge brute passe sous 40 % : la zone rouge du réseau (produits_coef.php). */
const PI_COEF_ROUGE = 100 / 60;

function piTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_epicerie_import (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(190) NOT NULL,
        size_bytes INT NOT NULL DEFAULT 0,
        sha1 CHAR(40) NOT NULL,
        model VARCHAR(80) NULL,
        doc_json TEXT NULL,
        supplier_json TEXT NULL,
        lines_json MEDIUMTEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT \'brouillon\',
        validated_by VARCHAR(120) NULL,
        validated_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY k_sha1 (sha1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Un nom comparable : minuscules, sans accent, ponctuation réduite à l'espace. */
function piNorm(string $s): string
{
    $s = mb_strtolower(trim($s));
    // Accents retirés par décomposition Unicode : iconv//TRANSLIT dépend de la
    // locale du serveur, et rend « ? » pour « é » en locale C.
    if (class_exists('Normalizer')) {
        $s = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D));
    } else {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') { $s = $t; }
    }
    // « 250 g » et « 250g. » sont la même contenance.
    $s = (string) preg_replace('/(\d)\s+(?=[a-z])/', '$1', $s);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/**
 * Les référentiels de TFBuddy, lus une fois par requête : catégories produit
 * (avec leur coefficient cible), catégories matière, fournisseurs, matières et
 * produits existants (par nom normalisé, pour les rapprochements).
 */
function piRefs(): array
{
    static $r = null;
    if ($r !== null) { return $r; }
    $r = ['catsProduit' => [], 'catsMatiere' => [], 'fournisseurs' => [], 'matieres' => [], 'produits' => []];
    if (!PanelApi::configured()) { return $r; }
    $res = PanelApi::getParallele(['mc' => '/material-categories', 'f' => '/material-suppliers', 'm' => '/materials']);
    foreach (PanelApi::productCategories() as $c) {
        $id = (int) ($c['id'] ?? 0);
        $nom = trim((string) ($c['name'] ?? $c['base_name'] ?? ''));
        if ($id <= 0 || $nom === '' || (isset($c['is_active']) && !(int) $c['is_active'])) { continue; }
        $cible = isset($c['price_coefficient']) && is_numeric($c['price_coefficient']) && (float) $c['price_coefficient'] > 0
            ? round((float) $c['price_coefficient'], 2) : null;
        $r['catsProduit'][$id] = ['nom' => $nom, 'cible' => $cible];
    }
    uasort($r['catsProduit'], static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    foreach (analyseListe($res['mc'] ?? null) as $c) {
        $id = (int) ($c['id'] ?? 0); $nom = trim((string) ($c['name'] ?? ''));
        if ($id > 0 && $nom !== '') { $r['catsMatiere'][$id] = $nom; }
    }
    asort($r['catsMatiere'], SORT_NATURAL | SORT_FLAG_CASE);
    foreach (analyseListe($res['f'] ?? null) as $f) {
        $id = (int) ($f['id'] ?? 0); $nom = trim((string) ($f['name'] ?? ''));
        if ($id > 0 && $nom !== '') {
            $r['fournisseurs'][$id] = ['nom' => $nom, 'tva' => trim((string) ($f['tax_number'] ?? '')), 'ville' => trim((string) ($f['city'] ?? ''))];
        }
    }
    uasort($r['fournisseurs'], static fn ($a, $b) => strnatcasecmp($a['nom'], $b['nom']));
    foreach (analyseListe($res['m'] ?? null) as $m) {
        $k = piNorm((string) ($m['name'] ?? ''));
        if ($k !== '' && !isset($r['matieres'][$k])) { $r['matieres'][$k] = ['id' => (int) ($m['id'] ?? 0), 'nom' => trim((string) $m['name'])]; }
    }
    foreach (analyseListe(PanelApi::produits() ?? []) as $p) {
        $k = piNorm((string) ($p['name'] ?? ''));
        if ($k !== '' && !isset($r['produits'][$k])) { $r['produits'][$k] = ['id' => (int) ($p['id'] ?? 0), 'nom' => trim((string) $p['name'])]; }
    }
    return $r;
}

/**
 * Les matières déjà rattachées au catalogue d'un fournisseur, par référence :
 * /material-suppliers/{id}/catalog-mappings (`catalog_product_sku` → `material_id`).
 *
 * @return array<string,array{id:int,nom:string}>
 */
function piMatieresDuFournisseur(int $fid): array
{
    static $memo = [];
    if ($fid <= 0 || !PanelApi::configured()) { return []; }
    if (isset($memo[$fid])) { return $memo[$fid]; }
    $out = [];
    foreach (analyseListe(PanelApi::get('/material-suppliers/' . $fid . '/catalog-mappings') ?? []) as $x) {
        $sku = trim((string) ($x['catalog_product_sku'] ?? ''));
        if ($sku !== '' && !empty($x['material_id'])) {
            $out[mb_strtolower($sku)] = ['id' => (int) $x['material_id'], 'nom' => trim((string) ($x['catalog_product_name'] ?? ''))];
        }
    }
    return $memo[$fid] = $out;
}

/** Le schéma de la réponse attendue du modèle : le tarif d'un fournisseur. */
function piSchema(): array
{
    $ou = static fn (string $t): array => ['anyOf' => [['type' => $t], ['type' => 'null']]];
    $article = [
        'type' => 'object',
        'properties' => [
            'nom'                => ['type' => 'string', 'description' => 'Désignation de l’article telle qu’écrite (marque et contenance comprises)'],
            'nomNl'              => $ou('string') + ['description' => 'Désignation néerlandaise si le document la donne'],
            'marque'             => $ou('string'),
            'ref'                => $ou('string') + ['description' => 'Référence ou code article du fournisseur'],
            'ean'                => $ou('string') + ['description' => 'Code-barres EAN de l’unité de vente'],
            'contenance'         => $ou('string') + ['description' => 'Poids ou volume d’une unité, ex. « 250 g », « 75 cl »'],
            'unitesParColis'     => $ou('integer') + ['description' => 'Nombre d’unités dans le colis commandé'],
            'prixColisHt'        => $ou('number') + ['description' => 'Prix d’achat HT du colis, en euros'],
            'prixUnitaireHt'     => $ou('number') + ['description' => 'Prix d’achat HT d’une unité, seulement s’il est écrit'],
            'tvaAchat'           => $ou('number') + ['description' => 'Taux de TVA de l’article (6, 12 ou 21)'],
            'prixConseilleTtc'   => $ou('number') + ['description' => 'Prix de vente conseillé au consommateur, TVA comprise, s’il est écrit'],
            'categorieProduitId' => $ou('integer') + ['description' => 'Catégorie de VENTE : identifiant de la liste fournie, sinon null'],
            'categorieMatiereId' => $ou('integer') + ['description' => 'Catégorie MATIÈRE : identifiant de la liste fournie, sinon null'],
            'categorieLue'       => $ou('string') + ['description' => 'La rubrique du document'],
            'allergenes'         => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(PI_ALLERGENES)]],
            'conservation'       => $ou('string') + ['description' => 'DLUO, durée de conservation, stockage'],
            'page'               => $ou('integer'),
            'confiance'          => ['type' => 'string', 'enum' => ['haute', 'moyenne', 'faible']],
            'remarque'           => $ou('string') + ['description' => 'Ce qui est ambigu ou illisible sur cette ligne'],
        ],
        'additionalProperties' => false,
    ];
    $article['required'] = array_keys($article['properties']);
    $fournisseur = [
        'type' => 'object',
        'properties' => [
            'nom' => $ou('string'), 'tva' => $ou('string') + ['description' => 'Numéro de TVA / d’entreprise'],
            'email' => $ou('string'), 'telephone' => $ou('string'), 'adresse' => $ou('string'), 'ville' => $ou('string'),
            'web' => $ou('string'),
        ],
        'additionalProperties' => false,
    ];
    $fournisseur['required'] = array_keys($fournisseur['properties']);
    return [
        'type' => 'object',
        'properties' => [
            'document' => [
                'type' => 'object',
                'properties' => [
                    'type'        => ['type' => 'string', 'description' => 'Ex. tarif fournisseur, catalogue, offre de prix'],
                    'fournisseur' => $fournisseur,
                    'tarifDu'     => $ou('string') + ['description' => 'Date de début de validité des prix, AAAA-MM-JJ'],
                    'remarque'    => $ou('string'),
                ],
                'required' => ['type', 'fournisseur', 'tarifDu', 'remarque'],
                'additionalProperties' => false,
            ],
            'articles' => ['type' => 'array', 'items' => $article],
        ],
        'required' => ['document', 'articles'],
        'additionalProperties' => false,
    ];
}

function piConsignes(): string
{
    return "Tu lis le tarif ou le catalogue d'un fournisseur pour le réseau de boulangeries belge L'Atelier by, qui"
        . " revend ces articles en ÉPICERIE, tels quels. Chaque article deviendra une matière achetée chez ce fournisseur et"
        . " un produit de vente. Tu ne crées rien : un humain relit chaque ligne.\n\n"
        . "Règles :\n"
        . "- Une entrée par article commandable. Pas les titres de rubrique, pas les conditions de vente, pas les frais.\n"
        . "- Recopie désignations, références et EAN tels qu'écrits.\n"
        . "- N'invente rien : une information absente vaut null. Ne calcule pas un prix qui n'est pas écrit.\n"
        . "- Prix en euros, en nombre (41,40 € → 41.4). Un prix d'achat est HT : prixColisHt pour le colis, prixUnitaireHt"
        . " seulement si le prix à l'unité est écrit. Un prix de vente conseillé TTC va dans prixConseilleTtc.\n"
        . "- unitesParColis : le nombre d'unités de vente dans ce que l'on commande (« carton de 12 » → 12 ; vendu à"
        . " l'unité → 1).\n"
        . "- Une TVA générale du document (pied de page, en-tête) vaut pour chaque ligne qui n'en porte pas d'autre.\n"
        . "- categorieProduitId et categorieMatiereId : uniquement des identifiants des listes fournies, quand la"
        . " correspondance est claire ; sinon null.\n"
        . "- Le fournisseur est l'émetteur du document (en-tête, pied de page), pas le client.\n"
        . "- confiance « faible » dès qu'une valeur est devinée ou mal lisible, et dis pourquoi dans remarque.";
}

/** Un nombre lu dans le modèle ou l'écran (« 2,50 » compris), ou null. */
function piNombre($v): ?float
{
    if (is_int($v) || is_float($v)) { return (float) $v; }
    if (!is_string($v)) { return null; }
    $v = str_replace([' ', '€', "\u{a0}"], '', str_replace(',', '.', trim($v)));
    return is_numeric($v) ? (float) $v : null;
}

/** Un poids écrit (« 450 g », « 1,2 kg ») en grammes, ou null. */
function piGrammes(?string $poids): ?int
{
    if ($poids === null || !preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|g)\b/i', $poids, $m)) { return null; }
    $n = (float) str_replace(',', '.', $m[1]);
    return (int) round(strtolower($m[2]) === 'kg' ? $n * 1000 : $n);
}

function piTexte($v, int $max = 500): ?string
{
    if (!is_string($v) && !is_int($v) && !is_float($v)) { return null; }
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** Un identifiant présent dans un référentiel, sinon null. */
function piId($v, array $ref): ?int
{
    return is_numeric($v) && isset($ref[(int) $v]) ? (int) $v : null;
}

/** Un prix TTC arrondi aux 5 centimes supérieurs. */
function piArrondi5(float $p): float
{
    return round(ceil($p * 20 - 1e-9) / 20, 2);
}

/**
 * Le fournisseur du brouillon : `existant` (un id du référentiel) ou `nouveau`
 * (les coordonnées à créer). `lu` garde ce que le PDF disait.
 */
function piFournisseur(array $f, array $refs): array
{
    $id = piId($f['id'] ?? null, $refs['fournisseurs']);
    $mode = ($f['mode'] ?? '') === 'existant' && $id !== null ? 'existant' : 'nouveau';
    $out = ['mode' => $mode, 'id' => $mode === 'existant' ? $id : null, 'idCree' => isset($f['idCree']) && is_numeric($f['idCree']) ? (int) $f['idCree'] : null];
    foreach (['nom' => 190, 'tva' => 40, 'email' => 190, 'telephone' => 40, 'adresse' => 190, 'ville' => 80, 'web' => 190] as $k => $max) {
        $out[$k] = piTexte($f[$k] ?? null, $max);
    }
    $out['tarifDu'] = is_string($f['tarifDu'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['tarifDu']) ? $f['tarifDu'] : null;
    $out['lu'] = is_array($f['lu'] ?? null) ? $f['lu'] : null;
    return $out;
}

/**
 * Une ligne du brouillon, propre : ce que le modèle a lu OU ce que l'écran a
 * corrigé, ramené aux mêmes types et aux référentiels. Le suivi (statut, ids
 * créés, message) n'appartient qu'au serveur : l'appelant le repasse.
 */
function piLigne(array $p, array $refs, string $cle): array
{
    $a = is_array($p['article'] ?? null) ? $p['article'] : [];
    $m = is_array($p['matiere'] ?? null) ? $p['matiere'] : [];
    $d = is_array($p['produit'] ?? null) ? $p['produit'] : [];
    $unites = isset($a['unites']) && is_numeric($a['unites']) && (int) $a['unites'] >= 1 ? (int) $a['unites'] : null;
    $colis = piNombre($a['prixColisHt'] ?? null);
    $unit = piNombre($a['prixUnitaireHt'] ?? null);
    // Le coût d'une pièce : le colis divisé par ses unités ; à défaut, le prix
    // à l'unité écrit dans le tarif.
    $cout = $colis !== null && $unites !== null ? $colis / $unites : $unit;
    $contenance = piTexte($a['contenance'] ?? null, 40);
    $tvaAchat = piNombre($a['tva'] ?? null);
    $matId = is_numeric($m['id'] ?? null) && (int) $m['id'] > 0 ? (int) $m['id'] : null;
    $prodId = is_numeric($d['id'] ?? null) && (int) $d['id'] > 0 ? (int) $d['id'] : null;
    $statut = ($p['statut'] ?? null) === 'cree' ? 'cree' : (($p['statut'] ?? null) === 'refuse' ? 'refuse' : null);
    return [
        'cle' => $cle,
        'inclure' => $statut === 'cree' ? true : (bool) ($p['inclure'] ?? true),
        'page' => isset($p['page']) && is_numeric($p['page']) ? (int) $p['page'] : null,
        'confiance' => in_array($p['confiance'] ?? '', ['haute', 'moyenne', 'faible'], true) ? $p['confiance'] : null,
        'remarque' => piTexte($p['remarque'] ?? null, 300),
        'article' => [
            'nom' => (string) piTexte($a['nom'] ?? '', 190), 'nomNl' => piTexte($a['nomNl'] ?? null, 190),
            'marque' => piTexte($a['marque'] ?? null, 80), 'ref' => piTexte($a['ref'] ?? null, 60),
            'ean' => ($e = preg_replace('/\D+/', '', (string) ($a['ean'] ?? ''))) !== '' ? $e : null,
            'contenance' => $contenance, 'poidsG' => piGrammes($contenance),
            'unites' => $unites, 'prixColisHt' => $colis, 'prixUnitaireHt' => $unit, 'tva' => $tvaAchat,
            'pvc' => piNombre($a['pvc'] ?? null),
            'allergenes' => array_values(array_intersect(array_keys(PI_ALLERGENES), (array) ($a['allergenes'] ?? []))),
            'conservation' => piTexte($a['conservation'] ?? null, 200),
            'categorieLue' => piTexte($a['categorieLue'] ?? null, 120),
        ],
        'coutHt' => $cout !== null ? round($cout, 4) : null,
        'matiere' => [
            'id' => $matId, 'via' => $matId !== null ? (piTexte($m['via'] ?? null, 10) ?? 'choix') : null,
            'nom' => (string) piTexte($m['nom'] ?? '', 190),
            'cat' => piId($m['cat'] ?? null, $refs['catsMatiere']),
            // Ce que le rapprochement a trouvé : l'écran propose de le reprendre.
            'trouvee' => is_array($m['trouvee'] ?? null) ? $m['trouvee'] : null,
        ],
        'produit' => [
            'id' => $prodId, 'nom' => (string) piTexte($d['nom'] ?? '', 190),
            'cat' => piId($d['cat'] ?? null, $refs['catsProduit']),
            'tva' => piNombre($d['tva'] ?? null), 'prixTtc' => ($x = piNombre($d['prixTtc'] ?? null)) !== null ? round($x, 2) : null,
            'existe' => is_array($d['existe'] ?? null) ? $d['existe'] : null,
        ],
        'statut' => $statut,
        'ids' => is_array($p['ids'] ?? null) ? $p['ids'] : null,
        'message' => piTexte($p['message'] ?? null, 400),
    ];
}

/**
 * Les chiffres d'une ligne : prix HT de vente, coefficient, cible de la
 * catégorie, prix TTC qui l'atteindrait. Calculés au serveur, une seule fois.
 */
function piChiffres(array $l, array $refs): array
{
    $tva = $l['produit']['tva'];
    $ttc = $l['produit']['prixTtc'];
    $ht = $ttc !== null && $tva !== null ? $ttc / (1 + $tva / 100) : null;
    $coef = $ht !== null && $l['coutHt'] !== null && $l['coutHt'] > 0 ? round($ht / $l['coutHt'], 2) : null;
    $cible = $l['produit']['cat'] !== null ? ($refs['catsProduit'][$l['produit']['cat']]['cible'] ?? null) : null;
    $prixCible = $cible !== null && $l['coutHt'] !== null && $tva !== null ? piArrondi5($l['coutHt'] * $cible * (1 + $tva / 100)) : null;
    return ['prixHt' => $ht !== null ? round($ht, 4) : null, 'coef' => $coef, 'cible' => $cible, 'prixCible' => $prixCible,
        'margePct' => $coef !== null && $coef > 0 ? round((1 - 1 / $coef) * 100) : null,
        'zone' => $coef === null ? 'mu' : ($coef < PI_COEF_ROUGE ? 'ko' : ($cible !== null && $coef < $cible ? 'att' : 'ok'))];
}

/**
 * Les contrôles de la validation. BLOQUANT : la chaîne ne pourrait pas être
 * créée ; AVERTISSEMENT : elle le pourrait, mais quelqu'un doit l'avoir vu.
 * Chacun dit à quelle étape le corriger.
 */
function piControles(array $f, array $lignes, array $refs): array
{
    $bl = []; $av = [];
    if ($f['mode'] === 'existant' ? $f['id'] === null : ($f['nom'] ?? null) === null) {
        $bl[] = ['etape' => 2, 'cle' => null, 'texte' => 'Fournisseur : choisissez-le dans la liste, ou donnez le nom du nouveau.'];
    }
    $inclus = array_values(array_filter($lignes, static fn ($l) => $l['inclure']));
    if ($inclus === []) { $bl[] = ['etape' => 3, 'cle' => null, 'texte' => 'Aucun article coché.']; }
    foreach ($inclus as $l) {
        if ($l['statut'] === 'cree') { continue; }
        $n = $l['article']['nom'] !== '' ? $l['article']['nom'] : $l['cle'];
        if ($l['coutHt'] === null || $l['coutHt'] <= 0) { $bl[] = ['etape' => 3, 'cle' => $l['cle'], 'texte' => $n . ' : prix d’achat ou unités par colis manquants.']; }
        if ($l['matiere']['id'] === null && ($l['matiere']['nom'] === '' || $l['matiere']['cat'] === null)) {
            $bl[] = ['etape' => 3, 'cle' => $l['cle'], 'texte' => $n . ' : la nouvelle matière demande un nom et une catégorie matière.'];
        }
        if ($l['produit']['existe'] !== null && $l['produit']['nom'] !== '' && piNorm($l['produit']['nom']) === piNorm((string) $l['produit']['existe']['nom'])) {
            $bl[] = ['etape' => 4, 'cle' => $l['cle'], 'texte' => $n . ' : « ' . $l['produit']['existe']['nom'] . ' » existe déjà au catalogue — décochez la ligne ou renommez le produit.'];
        }
        if ($l['produit']['nom'] === '' || $l['produit']['cat'] === null) { $bl[] = ['etape' => 4, 'cle' => $l['cle'], 'texte' => $n . ' : le produit demande un nom et une catégorie.']; }
        if ($l['produit']['prixTtc'] === null || $l['produit']['prixTtc'] <= 0 || $l['produit']['tva'] === null) {
            $bl[] = ['etape' => 4, 'cle' => $l['cle'], 'texte' => $n . ' : prix de vente TTC et TVA obligatoires.'];
        }
        $c = piChiffres($l, $refs);
        if ($c['zone'] === 'ko') { $av[] = ['etape' => 4, 'cle' => $l['cle'], 'texte' => $n . ' : coefficient × ' . number_format((float) $c['coef'], 2, ',', '') . ', marge brute sous 40 %.']; }
        elseif ($c['zone'] === 'att') { $av[] = ['etape' => 4, 'cle' => $l['cle'], 'texte' => $n . ' : coefficient × ' . number_format((float) $c['coef'], 2, ',', '') . ' sous la cible de la catégorie (× ' . number_format((float) $c['cible'], 2, ',', '') . ').']; }
        if ($l['article']['pvc'] !== null && $l['produit']['prixTtc'] !== null && $l['produit']['prixTtc'] > $l['article']['pvc'] + 0.001) {
            $av[] = ['etape' => 4, 'cle' => $l['cle'], 'texte' => $n . ' : vendu au-dessus du prix conseillé par le fournisseur (' . number_format($l['article']['pvc'], 2, ',', '') . ' €).'];
        }
        if ($l['confiance'] === 'faible') { $av[] = ['etape' => 3, 'cle' => $l['cle'], 'texte' => $n . ' : lecture incertaine — ' . ($l['remarque'] ?? 'à vérifier sur le PDF') . '.']; }
    }
    return ['bloquants' => $bl, 'avertissements' => $av];
}

/** Le brouillon tel que l'écran le reçoit. */
function piSortie(array $r, array $refs): array
{
    $f = piFournisseur(json_decode((string) ($r['supplier_json'] ?? '{}'), true) ?: [], $refs);
    $lignes = json_decode((string) $r['lines_json'], true) ?: [];
    foreach ($lignes as &$l) { $l['chiffres'] = piChiffres($l, $refs); }
    unset($l);
    $inclus = array_filter($lignes, static fn ($l) => $l['inclure']);
    return ['id' => (int) $r['id'], 'fichier' => (string) $r['file_name'], 'octets' => (int) $r['size_bytes'],
        'modele' => $r['model'], 'document' => json_decode((string) ($r['doc_json'] ?? 'null'), true),
        'le' => (string) $r['created_at'], 'statut' => (string) $r['status'],
        'validePar' => $r['validated_by'], 'valideLe' => $r['validated_at'],
        'fournisseur' => $f, 'lignes' => $lignes, 'controles' => piControles($f, $lignes, $refs),
        'recap' => ['n' => count($lignes), 'inclus' => count($inclus),
            'matieresNouvelles' => count(array_filter($inclus, static fn ($l) => $l['matiere']['id'] === null)),
            'matieresExistantes' => count(array_filter($inclus, static fn ($l) => $l['matiere']['id'] !== null)),
            'crees' => count(array_filter($lignes, static fn ($l) => $l['statut'] === 'cree'))],
        'refs' => piRefsEcran($refs), 'allergenesNoms' => PI_ALLERGENES];
}

/** Les référentiels pour les listes déroulantes de l'écran. */
function piRefsEcran(array $refs): array
{
    $l = static fn (array $a, callable $f) => array_map($f, array_keys($a), $a);
    return [
        'catsProduit' => $l($refs['catsProduit'], static fn ($id, $c) => ['id' => $id, 'nom' => $c['nom'], 'cible' => $c['cible']]),
        'catsMatiere' => $l($refs['catsMatiere'], static fn ($id, $n) => ['id' => $id, 'nom' => $n]),
        'fournisseurs' => $l($refs['fournisseurs'], static fn ($id, $f) => ['id' => $id, 'nom' => $f['nom'], 'ville' => $f['ville']]),
    ];
}

function piRow(int $id): ?array
{
    return Db::row('SELECT * FROM ceo_epicerie_import WHERE id = ?', [$id]);
}

/** La destination des créations : le compte qui appellera POST /products/resale. */
function piDestination(): ?array
{
    $d = setting('produitImport');
    if (!is_array($d) || !in_array($d['compte'] ?? '', ['erp', 'panel'], true)) { return null; }
    return ['compte' => $d['compte'], 'route' => PI_ROUTE, 'verifiee' => !empty($d['verifiee']), 'le' => $d['le'] ?? null];
}

/** Le message d'erreur d'une réponse d'API : description, message, errors… */
function piMessageApi($res): string
{
    if (!is_array($res)) { return ''; }
    foreach (['description', 'message', 'detail', 'error'] as $k) {
        if (!empty($res[$k]) && is_string($res[$k])) { return mb_substr($res[$k], 0, 300); }
        if (!empty($res[$k]['message']) && is_string($res[$k]['message'])) { return mb_substr($res[$k]['message'], 0, 300); }
    }
    if (!empty($res['errors'])) { return mb_substr((string) json_encode($res['errors'], JSON_UNESCAPED_UNICODE), 0, 300); }
    return '';
}

/** GET /produits/import — le dernier brouillon et l'état des comptes. */
function ep_produits_import(): array
{
    piTables();
    $refs = piRefs();
    $r = Db::row('SELECT * FROM ceo_epicerie_import ORDER BY id DESC LIMIT 1');
    return [
        'ia' => Anthropic::statut(),
        'comptes' => ['erp' => ErpApi::disponible(), 'panel' => PanelApi::configured()],
        'destination' => piDestination(),
        'maxOctets' => PI_MAX_OCTETS,
        'refs' => piRefsEcran($refs),
        'dernier' => $r !== null ? piSortie($r, $refs) : null,
    ];
}

/**
 * POST /produits/import/pdf — `{ nom, fichier: "data:application/pdf;base64,…" }`.
 * Lit le tarif, rapproche fournisseur, matières et produits, rend le
 * brouillon. Rien n'est créé chez TFBuddy.
 */
function wr_produits_import_pdf(): array
{
    piTables();
    $b = body();
    $data = (string) ($b['fichier'] ?? '');
    if (!preg_match('#^data:application/pdf;base64,#', $data)) {
        http_response_code(422); return ['error' => 'seuls les PDF sont acceptés'];
    }
    $pdf = base64_decode(substr($data, strpos($data, ',') + 1) ?: '', true);
    if ($pdf === false || !str_starts_with($pdf, '%PDF')) {
        http_response_code(422); return ['error' => 'ce fichier ne commence pas par un en-tête PDF'];
    }
    if (strlen($pdf) > PI_MAX_OCTETS) {
        http_response_code(422); return ['error' => 'PDF trop lourd : 8 Mo au plus — coupez-le en plusieurs fichiers'];
    }
    if (!Anthropic::configured()) {
        http_response_code(503); return ['error' => 'la lecture du PDF demande la clé Anthropic — Paramètres › IA'];
    }
    @set_time_limit(320);
    $refs = piRefs();
    $liste = static fn (array $a) => $a === [] ? '(référentiel indisponible : toujours null)'
        : implode("\n", array_map(static fn ($id, $v) => $id . ' — ' . (is_array($v) ? $v['nom'] : $v), array_keys($a), $a));
    $rep = Anthropic::lirePdf($pdf, piConsignes(),
        "Catégories de VENTE du réseau (identifiant — nom) :\n" . $liste($refs['catsProduit'])
        . "\n\nCatégories MATIÈRE du réseau (identifiant — nom) :\n" . $liste($refs['catsMatiere'])
        . "\n\nRelève le fournisseur et chaque article de ce document.", piSchema());
    if (isset($rep['erreur'])) { http_response_code(502); return ['error' => $rep['erreur']]; }

    // Le fournisseur : rapproché par n° de TVA, puis par nom.
    $lu = is_array($rep['document']['fournisseur'] ?? null) ? $rep['document']['fournisseur'] : [];
    $fid = null;
    $tvaLue = preg_replace('/[^0-9]/', '', (string) ($lu['tva'] ?? ''));
    foreach ($refs['fournisseurs'] as $id => $x) {
        $tvaRef = preg_replace('/[^0-9]/', '', $x['tva']);
        if (($tvaLue !== '' && $tvaRef !== '' && $tvaLue === $tvaRef)
            || (piNorm((string) ($lu['nom'] ?? '')) !== '' && piNorm((string) ($lu['nom'] ?? '')) === piNorm($x['nom']))) { $fid = $id; break; }
    }
    $fournisseur = piFournisseur($lu + ['mode' => $fid !== null ? 'existant' : 'nouveau', 'id' => $fid,
        'tarifDu' => $rep['document']['tarifDu'] ?? null, 'lu' => $lu], $refs);
    $parRef = $fid !== null ? piMatieresDuFournisseur($fid) : [];

    $lignes = []; $vus = [];
    foreach (array_slice((array) ($rep['articles'] ?? []), 0, PI_MAX_LIGNES) as $i => $a) {
        if (!is_array($a) || trim((string) ($a['nom'] ?? '')) === '') { continue; }
        $nom = trim((string) $a['nom']);
        // La matière : par la référence du catalogue de CE fournisseur, puis par le nom.
        $mat = isset($a['ref']) && is_string($a['ref']) ? ($parRef[mb_strtolower(trim($a['ref']))] ?? null) : null;
        $via = $mat !== null ? 'ref' : null;
        if ($mat === null && isset($refs['matieres'][piNorm($nom)])) { $mat = $refs['matieres'][piNorm($nom)]; $via = 'nom'; }
        $existe = $refs['produits'][piNorm($nom)] ?? null;
        $k = piNorm($nom);
        $lignes[] = piLigne([
            // Déjà vendu, ou répété dans le document : décoché d'office.
            'inclure' => $existe === null && !isset($vus[$k]),
            'page' => $a['page'] ?? null, 'confiance' => $a['confiance'] ?? null, 'remarque' => $a['remarque'] ?? null,
            'article' => ['nom' => $nom, 'nomNl' => $a['nomNl'] ?? null, 'marque' => $a['marque'] ?? null, 'ref' => $a['ref'] ?? null,
                'ean' => $a['ean'] ?? null, 'contenance' => $a['contenance'] ?? null, 'unites' => $a['unitesParColis'] ?? null,
                'prixColisHt' => $a['prixColisHt'] ?? null, 'prixUnitaireHt' => $a['prixUnitaireHt'] ?? null,
                'tva' => $a['tvaAchat'] ?? null, 'pvc' => $a['prixConseilleTtc'] ?? null, 'allergenes' => $a['allergenes'] ?? [],
                'conservation' => $a['conservation'] ?? null, 'categorieLue' => $a['categorieLue'] ?? null],
            'matiere' => ['id' => $mat['id'] ?? null, 'via' => $via, 'nom' => $mat['nom'] ?? $nom,
                'cat' => $a['categorieMatiereId'] ?? null, 'trouvee' => $mat !== null ? $mat + ['via' => $via] : null],
            'produit' => ['nom' => $nom, 'cat' => $a['categorieProduitId'] ?? null, 'tva' => $a['tvaAchat'] ?? null,
                'prixTtc' => $a['prixConseilleTtc'] ?? null, 'existe' => $existe],
        ], $refs, 'l' . ($i + 1));
        $vus[$k] = true;
    }
    // Sans prix conseillé, le prix proposé est celui de la cible de la catégorie.
    foreach ($lignes as &$l) {
        if ($l['produit']['prixTtc'] === null) { $l['produit']['prixTtc'] = piChiffres($l, $refs)['prixCible']; }
    }
    unset($l);
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 190) ?: 'document.pdf';
    $doc = ['type' => $rep['document']['type'] ?? null, 'remarque' => $rep['document']['remarque'] ?? null];
    $maintenant = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_epicerie_import (file_name, size_bytes, sha1, model, doc_json, supplier_json, lines_json, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?,?)',
        [$nom, strlen($pdf), sha1($pdf), (string) ($rep['modele'] ?? ''), json_encode($doc, JSON_UNESCAPED_UNICODE),
         json_encode($fournisseur, JSON_UNESCAPED_UNICODE), json_encode($lignes, JSON_UNESCAPED_UNICODE), $maintenant, $maintenant]);
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd('CEO', 'Épicerie', $nom, 'Tarif lu : ' . count($lignes) . ' article(s), fournisseur '
        . ($fid !== null ? '« ' . $refs['fournisseurs'][$fid]['nom'] . ' » (existant)' : 'à créer') . ' — à encoder et valider');
    return ['ok' => true, 'import' => piSortie(piRow($id), $refs)];
}

/** PUT /produits/import/{id} — `{ fournisseur?, lignes? }` : les corrections de l'assistant. */
function wr_produits_import_maj(int $id): array
{
    piTables();
    $r = piRow($id);
    if ($r === null) { http_response_code(404); return ['error' => 'brouillon inconnu']; }
    if ($r['status'] === 'valide') { http_response_code(409); return ['error' => 'encodage validé : rouvrez-le pour le modifier']; }
    $refs = piRefs();
    $b = body();
    if (is_array($b['fournisseur'] ?? null)) {
        $old = json_decode((string) $r['supplier_json'], true) ?: [];
        // `lu` et l'id déjà créé restent ceux du serveur.
        $f = piFournisseur(['lu' => $old['lu'] ?? null, 'idCree' => $old['idCree'] ?? null] + $b['fournisseur'], $refs);
        Db::exec('UPDATE ceo_epicerie_import SET supplier_json = ? WHERE id = ?', [json_encode($f, JSON_UNESCAPED_UNICODE), $id]);
    }
    if (is_array($b['lignes'] ?? null)) {
        $avant = [];
        foreach ((array) json_decode((string) $r['lines_json'], true) as $l) { $avant[(string) ($l['cle'] ?? '')] = $l; }
        $lignes = [];
        foreach ($b['lignes'] as $l) {
            if (!is_array($l)) { continue; }
            $cle = (string) ($l['cle'] ?? '');
            $old = $avant[$cle] ?? null;
            // Une ligne créée ne se modifie plus ; le suivi n'appartient qu'au serveur.
            if ($old !== null && ($old['statut'] ?? null) === 'cree') { $lignes[] = $old; continue; }
            foreach (['statut', 'ids', 'message'] as $k) { $l[$k] = $old[$k] ?? null; }
            if (is_array($l['produit'] ?? null)) { $l['produit']['existe'] = $old['produit']['existe'] ?? null; }
            if (is_array($l['matiere'] ?? null)) { $l['matiere']['trouvee'] = $old['matiere']['trouvee'] ?? null; }
            $lignes[] = piLigne($l, $refs, $cle !== '' ? $cle : 'm' . bin2hex(random_bytes(3)));
        }
        Db::exec('UPDATE ceo_epicerie_import SET lines_json = ? WHERE id = ?', [json_encode($lignes, JSON_UNESCAPED_UNICODE), $id]);
    }
    Db::exec('UPDATE ceo_epicerie_import SET updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
    return ['ok' => true, 'import' => piSortie(piRow($id), $refs)];
}

/** POST /produits/import/{id}/valider — `{ par }` : signe l'encodage s'il n'a plus de bloquant. */
function wr_produits_import_valider(int $id): array
{
    piTables();
    $r = piRow($id);
    if ($r === null) { http_response_code(404); return ['error' => 'brouillon inconnu']; }
    $refs = piRefs();
    $s = piSortie($r, $refs);
    if ($s['controles']['bloquants'] !== []) {
        http_response_code(422);
        return ['error' => count($s['controles']['bloquants']) . ' point(s) bloquant(s) à corriger avant de valider', 'import' => $s];
    }
    $par = mb_substr(trim((string) (body()['par'] ?? '')), 0, 120) ?: 'CEO';
    Db::exec('UPDATE ceo_epicerie_import SET status = ?, validated_by = ?, validated_at = ?, updated_at = ? WHERE id = ?',
        ['valide', $par, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    journalAdd($par, 'Épicerie', (string) $r['file_name'], 'Encodage validé : ' . $s['recap']['inclus'] . ' article(s), '
        . $s['recap']['matieresNouvelles'] . ' matière(s) nouvelle(s), ' . count($s['controles']['avertissements']) . ' avertissement(s) vu(s)');
    return ['ok' => true, 'import' => piSortie(piRow($id), $refs)];
}

/** POST /produits/import/{id}/rouvrir — repasse en brouillon, tant que rien n'est créé. */
function wr_produits_import_rouvrir(int $id): array
{
    piTables();
    $r = piRow($id);
    if ($r === null) { http_response_code(404); return ['error' => 'brouillon inconnu']; }
    $refs = piRefs();
    if (piSortie($r, $refs)['recap']['crees'] > 0) {
        http_response_code(409); return ['error' => 'des articles sont déjà créés chez TFBuddy : corrigez-les au panel'];
    }
    Db::exec('UPDATE ceo_epicerie_import SET status = ?, validated_by = NULL, validated_at = NULL, updated_at = ? WHERE id = ?',
        ['brouillon', date('Y-m-d H:i:s'), $id]);
    journalAdd('CEO', 'Épicerie', (string) $r['file_name'], 'Encodage rouvert pour correction');
    return ['ok' => true, 'import' => piSortie(piRow($id), $refs)];
}

/**
 * GET /produits/import/sonde — les routes d'écriture de TFBuddy, compte par
 * compte. N'ÉCRIT RIEN : OPTIONS (l'en-tête Allow dit si POST est accepté).
 */
function ep_produits_import_sonde(): array
{
    $comptes = [];
    if (ErpApi::disponible()) { $comptes['erp'] = ['nom' => 'Admin ERP', 'base' => ErpApi::config()['base']]; }
    if (PanelApi::configured()) { $comptes['panel'] = ['nom' => 'Consultant (panel)', 'base' => PanelApi::config()['base']]; }
    if ($comptes === []) {
        http_response_code(503);
        return ['error' => 'aucun compte TFBuddy configuré — Paramètres › compte admin ERP ou compte consultant'];
    }
    $out = [];
    foreach ($comptes as $c => $info) {
        $routes = [];
        foreach (PI_ROUTES_SONDE as $r) {
            $o = $c === 'erp' ? ErpApi::options($r) : PanelApi::options($r);
            $verbes = $o['allow'] !== null ? array_map('trim', explode(',', strtoupper($o['allow']))) : [];
            $routes[] = ['route' => $r, 'code' => $o['code'], 'allow' => $o['allow'],
                // Vrai : POST annoncé. Faux : la route répond sans POST, ou n'existe pas. Null : on ne sait pas.
                'post' => in_array('POST', $verbes, true) ? true : ($verbes !== [] || $o['code'] === 404 ? false : null)];
        }
        $out[] = $info + ['compte' => $c, 'routes' => $routes];
    }
    $prets = [];
    foreach ($out as $c) { foreach ($c['routes'] as $r) { if ($r['route'] === PI_ROUTE && $r['post'] === true) { $prets[] = $c['compte']; } } }
    return ['comptes' => $out, 'route' => PI_ROUTE, 'proposition' => $prets[0] ?? null, 'destination' => piDestination(), 'le' => date('c')];
}

/** PUT /produits/import/destination — `{ compte, verifiee }`. */
function wr_produits_import_destination(): array
{
    $b = body();
    $compte = (string) ($b['compte'] ?? '');
    if (!in_array($compte, ['erp', 'panel'], true)) { http_response_code(422); return ['error' => 'compte inconnu']; }
    $d = ['compte' => $compte, 'verifiee' => !empty($b['verifiee']), 'le' => date('c')];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['produitImport', json_encode($d)]);
    journalAdd('CEO', 'Épicerie', null, 'Création chez TFBuddy : ' . ($compte === 'erp' ? 'compte admin ERP' : 'compte consultant')
        . ', ' . PI_ROUTE . ($d['verifiee'] ? ' (POST annoncé par TFBuddy)' : ' (non vérifiée)'));
    return ['ok' => true, 'destination' => piDestination()];
}

/** Le corps de POST /products/resale pour une ligne (docs/spec-api-panel.md §7.2). */
function piCharge(int $impId, array $f, array $l, bool $actif): array
{
    $fid = $f['mode'] === 'existant' ? $f['id'] : $f['idCree'];
    $a = $l['article'];
    return [
        'client_ref' => 'ep' . $impId . '-' . $l['cle'],
        'supplier' => $fid !== null ? ['id' => $fid] : array_filter(['name' => $f['nom'], 'tax_number' => $f['tva'], 'email' => $f['email'],
            'phone' => $f['telephone'], 'address' => $f['adresse'], 'city' => $f['ville'], 'website_url' => $f['web']], static fn ($v) => $v !== null),
        'catalog_product' => array_filter(['sku' => $a['ref'], 'name' => $a['nom'], 'ean' => $a['ean'], 'package_size' => $a['unites'] ?? 1,
            'package_unit' => 'pcs', 'vat_rate' => $a['tva']], static fn ($v) => $v !== null),
        'price' => ['price_net' => $a['prixColisHt'] ?? round((float) $l['coutHt'], 4), 'valid_from' => $f['tarifDu'] ?? date('Y-m-d'), 'shop_ids' => []],
        'material' => $l['matiere']['id'] !== null ? ['id' => $l['matiere']['id']]
            : array_filter(['name' => $l['matiere']['nom'], 'material_category_id' => $l['matiere']['cat'], 'unit_name' => 'pcs',
                'vat_rate' => $a['tva']], static fn ($v) => $v !== null),
        'units_per_pack' => $a['unites'] ?? 1,
        'product' => array_filter(['name' => $l['produit']['nom'], 'id_category' => $l['produit']['cat'],
            'suggested_sale_price' => $l['produit']['prixTtc'], 'tax_value_percent' => $l['produit']['tva'], 'single_weight' => $a['poidsG'],
            'allergene' => $a['allergenes'] ? implode(', ', array_map(static fn ($x) => PI_ALLERGENES[$x], $a['allergenes'])) : null,
            'is_active' => $actif ? 1 : 0], static fn ($v) => $v !== null),
        'recipe_quantity' => 1,
    ];
}

/**
 * POST /produits/import/{id}/creer — `{ actifs }` : un encodage VALIDÉ part chez
 * TFBuddy, ligne par ligne, par POST /products/resale (tout ou rien par ligne).
 * Une ligne créée garde ses identifiants et n'est jamais renvoyée. Arrêt net
 * au premier refus qui vaudrait pour toutes (route absente, droits, panne).
 */
function wr_produits_import_creer(int $id): array
{
    piTables();
    $r = piRow($id);
    if ($r === null) { http_response_code(404); return ['error' => 'brouillon inconnu']; }
    if ($r['status'] !== 'valide') { http_response_code(409); return ['error' => 'validez d’abord l’encodage (étape 5)']; }
    $dest = piDestination();
    if ($dest === null) { http_response_code(409); return ['error' => 'choisissez d’abord le compte TFBuddy (« Vérifier l’API »)']; }
    $refs = piRefs();
    $actifs = !empty(body()['actifs']);
    $f = piFournisseur(json_decode((string) $r['supplier_json'], true) ?: [], $refs);
    $lignes = (array) json_decode((string) $r['lines_json'], true);
    @set_time_limit(300);
    $crees = 0; $refuses = 0; $arret = null; $refusSeuls = 0; $noms = [];
    foreach ($lignes as $i => $l) {
        if (empty($l['inclure']) || ($l['statut'] ?? null) === 'cree') { continue; }
        $charge = piCharge($id, $f, $l, $actifs);
        if ($dest['compte'] === 'erp') {
            ErpApi::$lastError = null;
            [$code, $res] = ErpApi::envoi('POST', PI_ROUTE, $charge);
            $msg = piMessageApi($res) ?: (string) ErpApi::$lastError;
        } else {
            PanelApi::$lastError = null;
            [$ok, $res] = PanelApi::envoi('POST', PI_ROUTE, $charge);
            $code = $ok ? 201 : (preg_match('/HTTP (\d+)/', (string) PanelApi::$lastError, $m) ? (int) $m[1] : 0);
            $msg = piMessageApi($res) ?: (string) PanelApi::$lastError;
        }
        if ($code >= 200 && $code < 300) {
            $d = is_array($res) && isset($res['data']) && is_array($res['data']) ? $res['data'] : (array) $res;
            $lignes[$i]['statut'] = 'cree';
            $lignes[$i]['ids'] = array_filter(['fournisseur' => $d['supplier_id'] ?? null, 'article' => $d['catalog_product_id'] ?? null,
                'matiere' => $d['material_id'] ?? null, 'produit' => $d['product_id'] ?? null, 'recette' => $d['recipe_id'] ?? null],
                static fn ($v) => $v !== null);
            $lignes[$i]['message'] = 'créé le ' . date('d/m/Y à H:i') . ($actifs ? '' : ' — produit inactif');
            // Un fournisseur créé par la première ligne sert aux suivantes.
            if ($f['mode'] === 'nouveau' && $f['idCree'] === null && isset($d['supplier_id'])) { $f['idCree'] = (int) $d['supplier_id']; }
            $crees++; $refusSeuls = 0; $noms[] = $l['produit']['nom'];
            continue;
        }
        if (in_array($code, [0, 401, 403, 404, 405], true) || $code >= 500) {
            $arret = 'TFBuddy → HTTP ' . $code . ($msg !== '' ? ' : ' . $msg : '')
                . ($code === 404 || $code === 405 ? ' — la route ' . PI_ROUTE . ' n’existe pas encore (demande : spec §7)' : '')
                . ($code === 401 || $code === 403 ? ' — ce compte n’a pas le droit de créer' : '');
            break;
        }
        $lignes[$i]['statut'] = 'refuse';
        $lignes[$i]['message'] = 'HTTP ' . $code . ($msg !== '' ? ' : ' . $msg : '');
        $refuses++;
        if (++$refusSeuls >= 3 && $crees === 0) { $arret = 'trois refus de suite : ' . $lignes[$i]['message']; break; }
    }
    Db::exec('UPDATE ceo_epicerie_import SET supplier_json = ?, lines_json = ?, updated_at = ? WHERE id = ?',
        [json_encode($f, JSON_UNESCAPED_UNICODE), json_encode(array_values($lignes), JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
    if ($crees > 0 || $arret !== null) {
        journalAdd('CEO', 'Épicerie', (string) $r['file_name'],
            $crees . ' article(s) créé(s) dans TFBuddy' . ($noms ? ' : ' . mb_substr(implode(', ', $noms), 0, 400) : '')
            . ($refuses ? ' ; ' . $refuses . ' refusé(s)' : '') . ($arret !== null ? ' ; arrêt — ' . $arret : ''));
    }
    return ['ok' => true, 'crees' => $crees, 'refuses' => $refuses, 'arret' => $arret, 'import' => piSortie(piRow($id), $refs)];
}
