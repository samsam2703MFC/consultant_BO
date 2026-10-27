<?php
declare(strict_types=1);

/**
 * Encoder de nouveaux produits à partir d'un PDF — demande du 27/10/2026
 * (« dans Produits, encoder des nouveaux produits via un upload PDF, puis les
 * créer par l'API atelierby.tfbuddy.com »).
 *
 * Trois temps, et un humain entre chacun :
 *
 *  1. LIRE. Le PDF (catalogue fournisseur, fiche technique, liste de prix de la
 *     gamme) part à Claude, côté serveur — la clé ne quitte jamais le cockpit.
 *     La réponse est un JSON contraint par schéma ; chaque catégorie est
 *     ramenée au référentiel du réseau (/product-categories), une catégorie
 *     inventée retombe à « à choisir ». Un nom déjà au catalogue (/products)
 *     est signalé et décoché d'office : on ne crée pas deux fois la tarte au
 *     citron.
 *  2. RELIRE. Le brouillon est gardé (`ceo_produit_import`) ; chaque
 *     correction à l'écran s'y enregistre.
 *  3. CRÉER. Ligne par ligne, POST sur la route de création de TFBuddy, avec
 *     le compte choisi (admin ERP ou consultant). Une ligne créée garde l'id
 *     rendu par TFBuddy et n'est jamais renvoyée : recliquer « Créer » ne fait
 *     pas de doublon.
 *
 * LA ROUTE DE CRÉATION N'EST PAS DOCUMENTÉE. Le cockpit ne fait que LIRE les
 * produits du panel ; aucune écriture produit n'a encore été mesurée. D'où la
 * vérification (GET /produits/import/sonde), qui n'écrit rien : OPTIONS sur les
 * routes candidates (l'en-tête Allow dit si POST est accepté), et lecture d'une
 * fiche existante pour connaître les noms de champs. La fiche envoyée ne porte
 * QUE des champs que l'API rend elle-même en lecture — un champ inconnu ferait
 * refuser toute la ligne par un validateur strict.
 */

/** 8 Mo : la limite des autres dépôts PDF du cockpit (annexes, plan trimestriel). */
const PI_MAX_OCTETS = 8 * 1024 * 1024;
const PI_MAX_LIGNES = 200;

/** Les routes de création essayées par la vérification, dans l'ordre. */
const PI_ROUTES = ['/products', '/admin/products', '/consultant/products'];

/** Les 14 allergènes du règlement UE, codes du panel (GET /allergens) → libellé. */
const PI_ALLERGENES = ['cereals_gluten' => 'Gluten', 'crustaceans' => 'Crustacés', 'eggs' => 'Œufs', 'fish' => 'Poisson',
    'peanuts' => 'Arachides', 'soybeans' => 'Soja', 'milk' => 'Lait', 'nuts' => 'Fruits à coque', 'celery' => 'Céleri',
    'mustard' => 'Moutarde', 'sesame_seeds' => 'Sésame', 'sulphur_dioxide_sulphites' => 'Sulfites', 'lupin' => 'Lupin',
    'molluscs' => 'Mollusques'];

/**
 * Champ du brouillon → noms possibles côté TFBuddy, le plus probable d'abord.
 * Les noms viennent de ce que l'API rend en lecture (table `product`,
 * /products/{id}, /shops/{id}/products/available). Le premier présent dans la
 * fiche lue est retenu ; sans fiche lue, seuls les champs de PI_CHAMPS_SURS
 * partent.
 */
const PI_CHAMPS = [
    'nom'         => ['name'],
    'categorieId' => ['id_category', 'category_id'],
    'prixTtc'     => ['suggested_sale_price', 'portion_price', 'price'],
    'prixHt'      => ['portion_price_net', 'price_net'],
    'tva'         => ['tax_value_percent', 'tax_val_perc'],
    'poidsG'      => ['single_weight'],
    'divisible'   => ['is_divisible'],
    'portions'    => ['portion_size'],
    'allergenes'  => ['allergene'],
    'description' => ['description'],
    'actif'       => ['is_active'],
];
const PI_CHAMPS_SURS = ['nom', 'categorieId', 'prixTtc', 'actif'];

function piTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_produit_import (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(190) NOT NULL,
        size_bytes INT NOT NULL DEFAULT 0,
        sha1 CHAR(40) NOT NULL,
        model VARCHAR(80) NULL,
        doc_json TEXT NULL,
        lines_json MEDIUMTEXT NOT NULL,
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
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/**
 * Les catégories actives du réseau : [id => nom]. Panel d'abord, la copie
 * locale `product_category` en repli.
 *
 * @return array<int,string>
 */
function piCategories(): array
{
    $out = [];
    if (PanelApi::configured()) {
        foreach (PanelApi::productCategories() as $c) {
            $id = (int) ($c['id'] ?? 0);
            $nom = trim((string) ($c['name'] ?? $c['base_name'] ?? ''));
            if ($id <= 0 || $nom === '' || (isset($c['is_active']) && !(int) $c['is_active'])) { continue; }
            $out[$id] = $nom;
        }
    }
    if ($out === []) {
        try {
            foreach (Db::rows('SELECT id, name FROM product_category ORDER BY name') as $c) {
                $out[(int) $c['id']] = (string) $c['name'];
            }
        } catch (Throwable $e) { /* ni panel ni copie : la catégorie se choisira à la main */ }
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Le catalogue existant, par nom normalisé → [id, nom]. Sert à signaler un
 * produit déjà créé.
 *
 * @return array<string,array{id:int,nom:string}>
 */
function piCatalogue(): array
{
    $lignes = [];
    if (PanelApi::configured()) { $lignes = analyseListe(PanelApi::produits() ?? []); }
    if ($lignes === []) {
        try { $lignes = Db::rows('SELECT id, name FROM product'); } catch (Throwable $e) { $lignes = []; }
    }
    $out = [];
    foreach ($lignes as $p) {
        $nom = trim((string) ($p['name'] ?? ''));
        $k = piNorm($nom);
        if ($k !== '' && !isset($out[$k])) { $out[$k] = ['id' => (int) ($p['id'] ?? 0), 'nom' => $nom]; }
    }
    return $out;
}

/** Le schéma de la réponse attendue du modèle. */
function piSchema(): array
{
    $ou = static fn (string $t): array => ['anyOf' => [['type' => $t], ['type' => 'null']]];
    $produit = [
        'type' => 'object',
        'properties' => [
            'nom'          => ['type' => 'string', 'description' => 'Nom commercial tel qu’écrit, en français si le document est bilingue'],
            'nomNl'        => $ou('string') + ['description' => 'Nom néerlandais s’il figure dans le document'],
            'categorieId'  => $ou('integer') + ['description' => 'Identifiant pris dans la liste des catégories fournie, sinon null'],
            'categorieLue' => $ou('string') + ['description' => 'La rubrique ou famille telle qu’écrite dans le document'],
            'prixTtc'      => $ou('number') + ['description' => 'Prix de vente TVA comprise, en euros'],
            'prixHt'       => $ou('number') + ['description' => 'Prix hors TVA, en euros, seulement s’il est écrit'],
            'tva'          => $ou('number') + ['description' => 'Taux de TVA en pour cent (6, 12 ou 21), seulement s’il est écrit'],
            'poids'        => $ou('string') + ['description' => 'Poids ou contenance tel qu’écrit, ex. « 450 g »'],
            'portions'     => $ou('integer') + ['description' => 'Nombre de parts si le produit se vend aussi à la part'],
            'description'  => $ou('string'),
            'allergenes'   => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(PI_ALLERGENES)],
                'description' => 'Seulement les allergènes écrits dans le document'],
            'conservation' => $ou('string') + ['description' => 'Durée de vie, DLC, consigne de stockage'],
            'ean'          => $ou('string') + ['description' => 'Code-barres EAN s’il est écrit'],
            'page'         => $ou('integer'),
            'confiance'    => ['type' => 'string', 'enum' => ['haute', 'moyenne', 'faible']],
            'remarque'     => $ou('string') + ['description' => 'Ce qui est ambigu ou illisible sur cette ligne'],
        ],
        'additionalProperties' => false,
    ];
    $produit['required'] = array_keys($produit['properties']);
    return [
        'type' => 'object',
        'properties' => [
            'document' => [
                'type' => 'object',
                'properties' => [
                    'type'        => ['type' => 'string', 'description' => 'Ex. catalogue fournisseur, fiche technique, liste de prix'],
                    'fournisseur' => $ou('string'),
                    'remarque'    => $ou('string'),
                ],
                'required' => ['type', 'fournisseur', 'remarque'],
                'additionalProperties' => false,
            ],
            'produits' => ['type' => 'array', 'items' => $produit],
        ],
        'required' => ['document', 'produits'],
        'additionalProperties' => false,
    ];
}

function piConsignes(): string
{
    return "Tu lis un document PDF pour le réseau de boulangeries-pâtisseries belge L'Atelier by, et tu en"
        . " relèves les PRODUITS DE VENTE, pour qu'ils soient encodés au catalogue du réseau. Tu ne crées rien :"
        . " un humain relit chaque ligne avant création.\n\n"
        . "Règles :\n"
        . "- Une entrée par produit vendu au client. Pas les ingrédients, pas les emballages, pas les titres de rubrique.\n"
        . "- Une variante vendue à part (taille, parfum) est un produit à part.\n"
        . "- Recopie les noms tels qu'écrits, sans les traduire ni les embellir.\n"
        . "- N'invente rien : une information absente du document vaut null. Ne déduis pas une TVA, un prix HT ou un"
        . " allergène qui n'est pas écrit.\n"
        . "- Prix en euros, en nombre (2,50 € → 2.5). Un prix « HTVA » ou « hors TVA » va dans prixHt, pas dans prixTtc.\n"
        . "- categorieId : uniquement un identifiant de la liste fournie, quand la correspondance est claire ; sinon null,"
        . " et la rubrique du document dans categorieLue.\n"
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
    if (!is_string($v)) { return null; }
    $v = trim($v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/**
 * Une ligne du brouillon, propre : ce que le modèle a lu OU ce que l'écran a
 * corrigé, ramené aux mêmes types et au référentiel. Les champs de suivi
 * (cle, statut, tfbId, message) sont repris tels quels.
 */
function piLigne(array $p, array $cats, string $cle): array
{
    $tva = piNombre($p['tva'] ?? null);
    $ttc = piNombre($p['prixTtc'] ?? null);
    $ht = piNombre($p['prixHt'] ?? null);
    // Un des deux prix et la TVA suffisent à l'autre. Sans TVA, rien n'est déduit.
    if ($ttc === null && $ht !== null && $tva !== null) { $ttc = round($ht * (1 + $tva / 100), 2); }
    if ($ht === null && $ttc !== null && $tva !== null) { $ht = round($ttc / (1 + $tva / 100), 4); }
    $cat = isset($p['categorieId']) && is_numeric($p['categorieId']) ? (int) $p['categorieId'] : null;
    if ($cat !== null && !isset($cats[$cat])) { $cat = null; }
    $portions = isset($p['portions']) && is_numeric($p['portions']) && (int) $p['portions'] > 1 ? (int) $p['portions'] : null;
    $poids = piTexte($p['poids'] ?? null, 60);
    $statut = in_array($p['statut'] ?? null, ['cree', 'refuse'], true) ? $p['statut'] : null;
    return [
        'cle' => $cle,
        'nom' => (string) piTexte($p['nom'] ?? '', 190),
        'nomNl' => piTexte($p['nomNl'] ?? null, 190),
        'categorieId' => $cat,
        'categorieLue' => piTexte($p['categorieLue'] ?? null, 120),
        'prixTtc' => $ttc !== null ? round($ttc, 2) : null,
        'prixHt' => $ht,
        'tva' => $tva,
        'poids' => $poids,
        'poidsG' => piGrammes($poids),
        'portions' => $portions,
        'description' => piTexte($p['description'] ?? null, 1000),
        'allergenes' => array_values(array_intersect(array_keys(PI_ALLERGENES), (array) ($p['allergenes'] ?? []))),
        'conservation' => piTexte($p['conservation'] ?? null, 200),
        'ean' => piTexte($p['ean'] ?? null, 20),
        'page' => isset($p['page']) && is_numeric($p['page']) ? (int) $p['page'] : null,
        'confiance' => in_array($p['confiance'] ?? '', ['haute', 'moyenne', 'faible'], true) ? $p['confiance'] : null,
        'remarque' => piTexte($p['remarque'] ?? null, 300),
        'existe' => is_array($p['existe'] ?? null) ? $p['existe'] : null,
        'creer' => $statut === 'cree' ? false : (bool) ($p['creer'] ?? true),
        'statut' => $statut,
        'tfbId' => $p['tfbId'] ?? null,
        'message' => piTexte($p['message'] ?? null, 400),
    ];
}

/** Le brouillon tel que l'écran le reçoit. */
function piSortie(array $r, array $cats): array
{
    $lignes = json_decode((string) $r['lines_json'], true) ?: [];
    return ['id' => (int) $r['id'], 'fichier' => (string) $r['file_name'], 'octets' => (int) $r['size_bytes'],
        'modele' => $r['model'], 'document' => json_decode((string) ($r['doc_json'] ?? 'null'), true),
        'le' => (string) $r['created_at'], 'lignes' => $lignes,
        'n' => count($lignes), 'nCrees' => count(array_filter($lignes, static fn ($l) => ($l['statut'] ?? null) === 'cree')),
        'categories' => array_map(static fn ($id, $nom) => ['id' => $id, 'nom' => $nom], array_keys($cats), $cats),
        'allergenesNoms' => PI_ALLERGENES];
}

/** La destination des créations : compte + route, telle que choisie après vérification. */
function piDestination(): ?array
{
    $d = setting('produitImport');
    if (!is_array($d) || !in_array($d['compte'] ?? '', ['erp', 'panel'], true) || !in_array($d['route'] ?? '', PI_ROUTES, true)) {
        return null;
    }
    return ['compte' => $d['compte'], 'route' => $d['route'], 'verifiee' => !empty($d['verifiee']), 'le' => $d['le'] ?? null];
}

/**
 * Les noms de champs d'une fiche produit existante, lus avec le compte qui
 * créera — ce sont les seuls que la création enverra. Gardés 24 h.
 *
 * @return list<string>
 */
function piModele(string $compte, bool $frais = false): array
{
    $cache = setting('produitImportModele');
    if (!$frais && is_array($cache) && is_array($cache[$compte] ?? null)
        && (int) ($cache[$compte]['le'] ?? 0) > time() - 86400 && !empty($cache[$compte]['cles'])) {
        return (array) $cache[$compte]['cles'];
    }
    $get = static fn (string $p) => $compte === 'erp' ? ErpApi::get($p) : PanelApi::get($p);
    $liste = analyseListe($get('/products') ?? []);
    $id = (int) ($liste[0]['id'] ?? 0);
    $fiche = $id > 0 ? $get('/products/' . $id) : null;
    if (is_array($fiche) && isset($fiche['data']) && is_array($fiche['data'])) { $fiche = $fiche['data']; }
    $cles = is_array($fiche) && isset($fiche['id']) ? array_keys($fiche) : (isset($liste[0]) ? array_keys($liste[0]) : []);
    if ($cles !== []) {
        $cache = is_array($cache) ? $cache : [];
        $cache[$compte] = ['le' => time(), 'cles' => $cles, 'exemple' => $id];
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['produitImportModele', json_encode($cache, JSON_UNESCAPED_UNICODE)]);
    }
    return $cles;
}

/**
 * La fiche envoyée à TFBuddy pour une ligne : seulement des champs présents
 * dans la fiche lue (`$cles`), ou, sans fiche lue, le strict nécessaire.
 */
function piCharge(array $l, array $cles, bool $actif): array
{
    $valeurs = [
        'nom' => $l['nom'], 'categorieId' => $l['categorieId'], 'prixTtc' => $l['prixTtc'], 'prixHt' => $l['prixHt'],
        'tva' => $l['tva'], 'poidsG' => $l['poidsG'], 'divisible' => $l['portions'] !== null ? 1 : 0,
        'portions' => $l['portions'],
        'allergenes' => $l['allergenes'] ? implode(', ', array_map(static fn ($a) => PI_ALLERGENES[$a], $l['allergenes'])) : null,
        'description' => $l['description'], 'actif' => $actif ? 1 : 0,
    ];
    $connus = array_fill_keys($cles, true);
    $out = [];
    foreach (PI_CHAMPS as $champ => $noms) {
        $v = $valeurs[$champ] ?? null;
        if ($v === null) { continue; }
        if ($connus === []) {
            if (in_array($champ, PI_CHAMPS_SURS, true)) { $out[$noms[0]] = $v; }
            continue;
        }
        foreach ($noms as $n) { if (isset($connus[$n])) { $out[$n] = $v; break; } }
    }
    return $out;
}

/** Le message d'erreur d'une réponse d'API : description, message, errors… */
function piMessageApi($res): string
{
    if (!is_array($res)) { return ''; }
    foreach (['description', 'message', 'detail', 'error'] as $k) {
        if (!empty($res[$k]) && is_string($res[$k])) { return mb_substr($res[$k], 0, 300); }
    }
    if (!empty($res['errors'])) { return mb_substr((string) json_encode($res['errors'], JSON_UNESCAPED_UNICODE), 0, 300); }
    return '';
}

/** GET /produits/import — le dernier brouillon, les catégories, l'état des comptes. */
function ep_produits_import(): array
{
    piTables();
    $cats = piCategories();
    $r = Db::row('SELECT * FROM ceo_produit_import ORDER BY id DESC LIMIT 1');
    return [
        'ia' => Anthropic::statut(),
        'comptes' => ['erp' => ErpApi::disponible(), 'panel' => PanelApi::configured()],
        'destination' => piDestination(),
        'routes' => PI_ROUTES,
        'maxOctets' => PI_MAX_OCTETS,
        'categories' => array_map(static fn ($id, $nom) => ['id' => $id, 'nom' => $nom], array_keys($cats), $cats),
        'dernier' => $r !== null ? piSortie($r, $cats) : null,
    ];
}

/**
 * POST /produits/import/pdf — `{ nom, fichier: "data:application/pdf;base64,…" }`.
 * Lit le PDF, rend le brouillon. Rien n'est créé chez TFBuddy.
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
    $cats = piCategories();
    $liste = $cats === [] ? '(référentiel indisponible : categorieId toujours null)'
        : implode("\n", array_map(static fn ($id, $nom) => $id . ' — ' . $nom, array_keys($cats), $cats));
    $rep = Anthropic::lirePdf($pdf, piConsignes(), "Catégories du réseau (identifiant — nom) :\n" . $liste
        . "\n\nRelève les produits de vente de ce document.", piSchema());
    if (isset($rep['erreur'])) { http_response_code(502); return ['error' => $rep['erreur']]; }

    $catalogue = piCatalogue();
    $lignes = []; $vus = [];
    foreach (array_slice((array) ($rep['produits'] ?? []), 0, PI_MAX_LIGNES) as $i => $p) {
        if (!is_array($p) || trim((string) ($p['nom'] ?? '')) === '') { continue; }
        $k = piNorm((string) $p['nom']);
        $p['existe'] = $catalogue[$k] ?? null;
        // Deux fois le même nom dans le document : la seconde est décochée,
        // comme un produit déjà au catalogue.
        $p['creer'] = $p['existe'] === null && !isset($vus[$k]);
        $vus[$k] = true;
        $lignes[] = piLigne($p, $cats, 'l' . ($i + 1));
    }
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 190) ?: 'document.pdf';
    $doc = is_array($rep['document'] ?? null) ? $rep['document'] : null;
    $maintenant = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_produit_import (file_name, size_bytes, sha1, model, doc_json, lines_json, created_at, updated_at)
              VALUES (?,?,?,?,?,?,?,?)',
        [$nom, strlen($pdf), sha1($pdf), (string) ($rep['modele'] ?? ''), json_encode($doc, JSON_UNESCAPED_UNICODE),
         json_encode($lignes, JSON_UNESCAPED_UNICODE), $maintenant, $maintenant]);
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd('CEO', 'Produits', $nom, 'PDF lu : ' . count($lignes) . ' produit(s) relevé(s), à relire avant création');
    return ['ok' => true, 'import' => piSortie(Db::row('SELECT * FROM ceo_produit_import WHERE id = ?', [$id]), $cats)];
}

/** PUT /produits/import/{id} — `{ lignes }` : les corrections de l'écran. */
function wr_produits_import_maj(int $id): array
{
    piTables();
    $r = Db::row('SELECT * FROM ceo_produit_import WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'brouillon inconnu']; }
    $cats = piCategories();
    $avant = [];
    foreach ((array) json_decode((string) $r['lines_json'], true) as $l) { $avant[(string) ($l['cle'] ?? '')] = $l; }
    $lignes = [];
    foreach ((array) (body()['lignes'] ?? []) as $l) {
        if (!is_array($l)) { continue; }
        $cle = (string) ($l['cle'] ?? '');
        $old = $avant[$cle] ?? null;
        // Le suivi (créée, id TFBuddy) n'appartient qu'au serveur : l'écran ne
        // peut ni l'effacer ni l'inventer. Une ligne créée ne se modifie plus.
        if ($old !== null && ($old['statut'] ?? null) === 'cree') { $lignes[] = $old; continue; }
        foreach (['statut', 'tfbId', 'message', 'existe'] as $k) { $l[$k] = $old[$k] ?? null; }
        $lignes[] = piLigne($l, $cats, $cle !== '' ? $cle : 'm' . bin2hex(random_bytes(3)));
    }
    Db::exec('UPDATE ceo_produit_import SET lines_json = ?, updated_at = ? WHERE id = ?',
        [json_encode($lignes, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
    return ['ok' => true, 'import' => piSortie(Db::row('SELECT * FROM ceo_produit_import WHERE id = ?', [$id]), $cats)];
}

/**
 * GET /produits/import/sonde — quelle route de TFBuddy accepte une création,
 * avec quel compte. N'ÉCRIT RIEN : OPTIONS sur chaque route candidate, puis
 * lecture d'une fiche existante pour les noms de champs.
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
        foreach (PI_ROUTES as $r) {
            $o = $c === 'erp' ? ErpApi::options($r) : PanelApi::options($r);
            $verbes = $o['allow'] !== null ? array_map('trim', explode(',', strtoupper($o['allow']))) : [];
            $routes[] = ['route' => $r, 'code' => $o['code'], 'allow' => $o['allow'],
                // Vrai : POST annoncé. Faux : la route répond sans POST, ou n'existe pas. Null : on ne sait pas.
                'post' => in_array('POST', $verbes, true) ? true : ($verbes !== [] || $o['code'] === 404 ? false : null)];
        }
        $cles = piModele($c, true);
        $out[] = $info + ['compte' => $c, 'routes' => $routes, 'champs' => $cles,
            'envoyes' => array_keys(piCharge(['nom' => 'x', 'categorieId' => 1, 'prixTtc' => 1.0, 'prixHt' => 1.0, 'tva' => 6.0,
                'poidsG' => 1, 'portions' => 2, 'allergenes' => ['milk'], 'description' => 'x'], $cles, false))];
    }
    // Une seule route qui annonce POST : c'est la proposition.
    $sures = [];
    foreach ($out as $c) { foreach ($c['routes'] as $r) { if ($r['post'] === true) { $sures[] = ['compte' => $c['compte'], 'route' => $r['route']]; } } }
    return ['comptes' => $out, 'proposition' => count($sures) === 1 ? $sures[0] : null,
        'destination' => piDestination(), 'le' => date('c')];
}

/** PUT /produits/import/destination — `{ compte, route, verifiee }`. */
function wr_produits_import_destination(): array
{
    $b = body();
    $compte = (string) ($b['compte'] ?? '');
    $route = (string) ($b['route'] ?? '');
    if (!in_array($compte, ['erp', 'panel'], true) || !in_array($route, PI_ROUTES, true)) {
        http_response_code(422); return ['error' => 'compte ou route inconnus'];
    }
    $d = ['compte' => $compte, 'route' => $route, 'verifiee' => !empty($b['verifiee']), 'le' => date('c')];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['produitImport', json_encode($d)]);
    journalAdd('CEO', 'Produits', null, 'Création de produits : ' . ($compte === 'erp' ? 'compte admin ERP' : 'compte consultant')
        . ', route ' . $route . ($d['verifiee'] ? ' (POST annoncé par TFBuddy)' : ' (non vérifiée)'));
    return ['ok' => true, 'destination' => piDestination()];
}

/**
 * POST /produits/import/{id}/creer — `{ actifs }` : crée chez TFBuddy les
 * lignes cochées et pas encore créées. Une à une, dans l'ordre ; arrêt net au
 * premier refus qui vaudrait pour toutes (route absente, droits, panne).
 */
function wr_produits_import_creer(int $id): array
{
    piTables();
    $r = Db::row('SELECT * FROM ceo_produit_import WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'brouillon inconnu']; }
    $dest = piDestination();
    if ($dest === null) {
        http_response_code(409); return ['error' => 'choisissez d’abord la route de création TFBuddy (bouton « Vérifier l’API »)'];
    }
    $actifs = !empty(body()['actifs']);
    $cats = piCategories();
    $lignes = (array) json_decode((string) $r['lines_json'], true);
    $aFaire = [];
    foreach ($lignes as $i => $l) {
        if (!empty($l['creer']) && ($l['statut'] ?? null) !== 'cree') { $aFaire[] = $i; }
    }
    if ($aFaire === []) { return ['ok' => true, 'crees' => 0, 'refuses' => 0, 'arret' => null, 'import' => piSortie($r, $cats)]; }
    @set_time_limit(300);
    $cles = piModele($dest['compte']);
    $crees = 0; $refuses = 0; $arret = null; $refusSeuls = 0; $noms = [];
    foreach ($aFaire as $i) {
        $l = $lignes[$i];
        // Une ligne sans nom ou sans catégorie n'a aucune chance : elle est
        // signalée sans appel, plutôt que de faire refuser la création par l'API.
        if (trim((string) ($l['nom'] ?? '')) === '' || empty($l['categorieId'])) {
            $lignes[$i]['statut'] = 'refuse'; $lignes[$i]['message'] = 'nom et catégorie obligatoires'; $refuses++;
            continue;
        }
        $charge = piCharge($l, $cles, $actifs);
        if ($dest['compte'] === 'erp') {
            ErpApi::$lastError = null;
            [$code, $res] = ErpApi::envoi('POST', $dest['route'], $charge);
            $msg = piMessageApi($res) ?: (string) ErpApi::$lastError;
        } else {
            PanelApi::$lastError = null;
            [$ok, $res] = PanelApi::envoi('POST', $dest['route'], $charge);
            $code = $ok ? 201 : (preg_match('/HTTP (\d+)/', (string) PanelApi::$lastError, $m) ? (int) $m[1] : 0);
            $msg = piMessageApi($res) ?: (string) PanelApi::$lastError;
        }
        if ($code >= 200 && $code < 300) {
            $d = is_array($res) && isset($res['data']) && is_array($res['data']) ? $res['data'] : $res;
            $lignes[$i]['statut'] = 'cree';
            $lignes[$i]['creer'] = false;
            $lignes[$i]['tfbId'] = is_array($d) && isset($d['id']) ? $d['id'] : null;
            $lignes[$i]['message'] = 'créé le ' . date('d/m/Y à H:i') . ($actifs ? '' : ' — inactif');
            $crees++; $refusSeuls = 0; $noms[] = $l['nom'];
            continue;
        }
        // Route absente, verbe refusé, droits, panne : la même réponse attend
        // toutes les lignes suivantes — on s'arrête au lieu d'en envoyer trente.
        if (in_array($code, [0, 401, 403, 404, 405], true) || $code >= 500) {
            $arret = 'TFBuddy → HTTP ' . $code . ($msg !== '' ? ' : ' . $msg : '')
                . ($code === 404 || $code === 405 ? ' — cette route ne crée pas de produit : relancez « Vérifier l’API »' : '')
                . ($code === 401 || $code === 403 ? ' — ce compte n’a pas le droit de créer un produit' : '');
            break;
        }
        $lignes[$i]['statut'] = 'refuse';
        $lignes[$i]['message'] = 'HTTP ' . $code . ($msg !== '' ? ' : ' . $msg : '');
        $refuses++;
        // Trois refus de suite sans une seule création : c'est la forme de la
        // fiche qui ne convient pas, pas les lignes. Le message de l'API le dit.
        if (++$refusSeuls >= 3 && $crees === 0) { $arret = 'trois refus de suite : ' . $lignes[$i]['message']; break; }
    }
    Db::exec('UPDATE ceo_produit_import SET lines_json = ?, updated_at = ? WHERE id = ?',
        [json_encode(array_values($lignes), JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
    if ($crees > 0 || $arret !== null) {
        journalAdd('CEO', 'Produits', (string) $r['file_name'],
            $crees . ' produit(s) créé(s) dans TFBuddy' . ($noms ? ' : ' . mb_substr(implode(', ', $noms), 0, 400) : '')
            . ($refuses ? ' ; ' . $refuses . ' refusé(s)' : '') . ($arret !== null ? ' ; arrêt — ' . $arret : ''));
    }
    return ['ok' => true, 'crees' => $crees, 'refuses' => $refuses, 'arret' => $arret,
        'import' => piSortie(Db::row('SELECT * FROM ceo_produit_import WHERE id = ?', [$id]), $cats)];
}
