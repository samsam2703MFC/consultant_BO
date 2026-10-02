<?php
declare(strict_types=1);

/*
 * LA TABLETTE DES VENDEUSES — « Book vendeuses ».
 *
 * L'application compilée est servie depuis public/tablette/ (dépôt
 * pwa_sales_tablet, copiée ici comme l'assistant) ; ce fichier lui fournit ses
 * DONNÉES : GET /tablette/book rend le catalogue du réseau dans les noms de
 * champs de la tablette (BookData : categories, seasons, products), et
 * POST /tablette/photos télécharge les photos de recette du panel qui manquent,
 * avec leur vignette carrée de 640 px. Contrat : docs/contrat-api.md.
 *
 * Tout vient de ce que le BO lit déjà : le catalogue (ep_prod_catalogue, gammes
 * comprises), la fiche produit de la base partagée (UNE lecture groupée de
 * `product`), les gammes saisonnières (ep_prod_saisons), les relevés de ventes
 * (psVentesJour) et les photos du planogramme (ceo_plano_std_photo). Chaque
 * source est lue à part : une source muette laisse ses champs vides et le dit
 * dans `sources`, le book part quand même — seul un catalogue illisible rend
 * une erreur (la tablette garde alors ses données d'exemple).
 *
 * Les textes sont des paires [FR, NL] ; le néerlandais manque presque partout
 * (texte vide : la tablette retombe sur le français).
 *
 * ALLERGÈNES. D'abord `product.allergene` : il fait foi quand TOUT le texte se
 * lit comme une liste d'allergènes nommés, sans mot de trace ni de négation.
 * Mesuré en ligne le 02/10/2026 : vide partout. Sinon, la recette du produit au
 * panel (GET /recipes/{id_recipe}, ses sous-recettes GET /subrecipes/{id}) et
 * les allergènes de chacune de ses matières premières (GET /materials/{id}) :
 * `al` = leur union, `alKnown: true` seulement si tout l'arbre a été lu et que
 * CHAQUE matière (hors emballages) porte une liste non vide — une liste vide
 * veut dire « jamais saisie », pas « sans allergène » (le beurre, les œufs, les
 * fromages du traiteur en ont une vide). Sinon `alKnown: false` avec les
 * allergènes connus, que la tablette montre « contient » en laissant le reste
 * « à vérifier sur l'étiquette », jamais « sans » ; `alRaw` dit ce qui manque.
 * Les traces n'existent nulle part : `tr: []`, `trKnown: false`. Recettes et
 * matières sont gardées 24 h (ceo_app_setting `tabletteAllergenes`) et lues en
 * parallèle, 8 s au plus par calcul : le reste sera lu au calcul suivant.
 *
 * CONSERVATION. La fiche de la base partagée ne porte que l'id du lieu de
 * stockage : son nom et sa consigne viennent de GET /products du panel (une
 * lecture pour tout le catalogue, gardée 24 h dans `tablettePanelProduits`),
 * avec la réchauffe et la durée de vie quand la base n'en a pas.
 */

const TB_SCHEMA = 1;
const TB_CACHE_HEURES = 6;
const TB_BEST = 8;                         // meilleures ventes marquées
const TB_BEST_JOURS = 28;
const TB_SAISON_AVANT_JOURS = 45;          // une gamme qui ouvre dans 45 jours entre au comptoir
const TB_VIGNETTE_PX = 640;                // côté des vignettes (carrées)
const TB_VIGNETTE_FIN = '-640c.jpg';       // fin du nom d'une vignette : « c » = carrée (le nom a changé avec le format, les tablettes rechargent)
const TB_VIGNETTES_LECTURE = 40;           // vignettes faites au plus par GET /tablette/book…
const TB_VIGNETTES_LECTURE_S = 3.0;        // … et en 3 s au plus : la tablette n'attend que 6 s
const TB_VIGNETTES_ECRITURE = 80;          // par POST /tablette/photos
const TB_VIGNETTES_ECRITURE_S = 25.0;
const TB_PANEL_FRONT = 6;                  // GET menés de front au panel (au-delà, l'API laisse des requêtes sans réponse)
const TB_PANEL_S = 8.0;                    // recettes et matières lues au panel : 8 s au plus par calcul du book…
const TB_PANEL_PRODUITS_S = 6;             // … et GET /products (tout le catalogue) : 6 s au plus
const TB_PANEL_HEURES = 24;                // une recette, une matière, la liste des produits : relues après 24 h…
const TB_PANEL_AVANCE_HEURES = 6;          // … et dès 18 à 24 h (étalé par entrée) quand le temps le permet
const TB_PANEL_OUBLI_JOURS = 30;           // une entrée du cache que plus rien ne relit est oubliée
const TB_AL_PROFONDEUR = 6;                // niveaux de sous-recettes lus au plus
const TB_CACHE_INCOMPLET_MIN = 30;         // un book aux lectures du panel inachevées se recalcule après 30 min

/** Les champs de GET /products que le book garde (`tablettePanelProduits`). */
const TB_PANEL_PRODUIT_CHAMPS = ['storage_name', 'storage_description', 'reheating_time_minutes', 'reheating_temperature_celsius',
    'shelf_life_minutes', 'id_recipe'];

/** Les 14 allergènes UE, dans l'ordre et sous les identifiants de la tablette. */
const TB_ALLERGENES = ['gluten', 'crust', 'oeufs', 'poisson', 'arach', 'soja', 'lait', 'noix', 'celeri', 'moutarde', 'sesame', 'sulfites', 'lupin', 'mollusques'];

/** Les codes allergènes du panel (GET /allergens, les 14 du règlement UE) → identifiants de la tablette. */
const TB_ALLERGENES_PANEL = ['cereals_gluten' => 'gluten', 'crustaceans' => 'crust', 'eggs' => 'oeufs', 'fish' => 'poisson',
    'peanuts' => 'arach', 'soybeans' => 'soja', 'milk' => 'lait', 'nuts' => 'noix', 'celery' => 'celeri', 'mustard' => 'moutarde',
    'sesame_seeds' => 'sesame', 'sulphur_dioxide_sulphites' => 'sulfites', 'lupin' => 'lupin', 'molluscs' => 'mollusques'];

/** Leurs noms reconnus (minuscules, sans accent) : français, néerlandais, anglais. */
const TB_ALLERGENES_MOTS = [
    'gluten' => ['gluten', 'cereales contenant du gluten', 'cereales contenant gluten', 'glutenbevattende granen', 'granen die gluten bevatten',
        'ble', 'froment', 'seigle', 'orge', 'avoine', 'epeautre', 'kamut', 'tarwe', 'rogge', 'gerst', 'haver', 'spelt', 'wheat', 'rye', 'barley', 'oats', 'oat'],
    'crust' => ['crustaces', 'crustace', 'schaaldieren', 'schaaldier', 'crustaceans', 'crustacean'],
    'oeufs' => ['oeufs', 'oeuf', 'eieren', 'ei', 'eggs', 'egg'],
    'poisson' => ['poissons', 'poisson', 'vis', 'fish'],
    'arach' => ['arachides', 'arachide', 'cacahuetes', 'cacahuete', 'cacahouetes', 'cacahouete', 'pindas', 'pinda', 'pindanoten', 'aardnoten', 'peanuts', 'peanut'],
    'soja' => ['soja', 'soya', 'soy', 'sojabonen'],
    'lait' => ['lait', 'lactose', 'melk', 'milk'],
    'noix' => ['fruits a coque', 'fruit a coque', 'noix', 'noten', 'schaalvruchten', 'nuts', 'tree nuts', 'amandes', 'amande', 'noisettes', 'noisette',
        'noix de cajou', 'cajou', 'pistaches', 'pistache', 'noix de pecan', 'noix du bresil', 'noix de macadamia', 'noix de queensland',
        'amandelen', 'hazelnoten', 'walnoten', 'cashewnoten', 'pecannoten', 'paranoten', 'pistachenoten', 'macadamianoten',
        'almonds', 'hazelnuts', 'walnuts', 'cashews', 'pistachios'],
    'celeri' => ['celeri', 'selderij', 'celery'],
    'moutarde' => ['moutarde', 'mosterd', 'mustard'],
    'sesame' => ['graines de sesame', 'sesame', 'sesamzaad', 'sesamzaadjes', 'sesam', 'sesame seeds'],
    'sulfites' => ['anhydride sulfureux et sulfites', 'anhydride sulfureux', 'dioxyde de soufre', 'sulfites', 'sulfite', 'sulfieten', 'sulfiet',
        'zwaveldioxide', 'sulphites', 'sulfur dioxide', 'sulphur dioxide'],
    'lupin' => ['lupin', 'lupine', 'lupines', 'lupinen'],
    'mollusques' => ['mollusques', 'mollusque', 'weekdieren', 'weekdier', 'molluscs', 'mollusks'],
];

/** Les mots qui relient les noms d'une liste (« Lait et produits à base de lait »), sans rien ajouter. */
const TB_ALLERGENES_LIENS = ['produits a base de', 'produits a base d', 'y compris', 'a savoir', 'ou leurs souches hybridees', 'ces cereales', 'ces fruits',
    'allergenes', 'allergene', 'allergenen', 'allergens', 'allergen', 'contient', 'bevat', 'contains', 'et', 'ou', 'en', 'of', 'and', 'or',
    'le', 'la', 'les', 'l', 'du', 'de', 'des', 'd', 'het', 'van', 'the'];

/** Ce qui interdit de lire une liste : une trace, une négation, un doute. */
const TB_ALLERGENES_PIEGES = '/\b(traces?|sporen|spoor|peut contenir|peuvent contenir|kan bevatten|kunnen bevatten|may contain|sans|zonder|geen|aucun|aucune|none|pas de|pas d|niet|non|nvt|nihil|neant)\b|vrij|free|n\/a|\?/';

/**
 * Les groupes de produits du BO (product_category_group, 12 au 02/10/2026) et
 * leur nom néerlandais — BROUILLON, à faire valider ; l'ordre est celui du book.
 */
const TB_GROUPES_NL = [
    'Viennoiserie' => 'Viennoiserie',
    'Boulangerie' => 'Brood',
    'Pâtisserie' => 'Gebak',
    'Tartes' => 'Taarten',
    'Quiches' => 'Quiches',
    'Traiteur' => 'Traiteur',
    'Biscuiterie' => 'Koekjes',
    'Épicerie' => 'Kruidenierswaren',
    'Boissons' => 'Dranken',
    'Fêtes & Occasions' => 'Feesten & gelegenheden',
    'Bundle & Promotion' => 'Bundels & promoties',
    'B. 2 B.' => 'B2B',
];

/** Mot-clé du nom de la gamme → illustration embarquée dans la tablette (sinon aucune). */
const TB_SAISONS_IMG = [
    '/\b(noel|nouvel an|kerst|christmas)/' => 'img/s/christmas-new-year-range.png',
    '/\b(nicolas|sinterklaas|nicholas)\b/' => 'img/s/saint-nicholas-range.png',
    '/\b(epiphanie|galettes?|rois|driekoningen|epiphany)\b/' => 'img/s/epiphany-galette-des-rois.png',
    '/\b(valentin|valentijn|valentine)/' => 'img/s/valentines-day-range.png',
    '/\b(paques|pascale?|pasen|easter)\b/' => 'img/s/easter-range.png',
    '/\b(meres|moederdag|mothers?)\b/' => 'img/s/mothers-day-range.png',
    '/\b(glaces?|ijs|ice|estivale?|ete|zomer|summer)\b/' => 'img/s/ice-cream-range.png',
    '/\b(automn\w*|herfst|autumn)\b/' => 'img/s/autumn-range.png',
    '/\b(hiver\w*|winter)\b/' => 'img/s/winter-range.png',
];

const TB_MOIS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const TB_MOIS_NL = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

const TB_ACCENTS = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ñ' => 'n',
    'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
    'ÿ' => 'y', 'ß' => 'ss', '’' => "'", '‘' => "'", '–' => '-', '—' => '-'];

/* --- Les routes -------------------------------------------------------------------------------- */

/**
 * GET /tablette/book?shop=<shops.id>[&ensemble=comptoir|tout][&rafraichir=1]
 *
 * `comptoir` (défaut) : les produits du planogramme standard, ceux des gammes
 * saisonnières ouvertes ou qui ouvrent sous 45 jours, et les obligatoires
 * exigées ; planogramme vide → tout le catalogue actif. `tout` : tout le
 * catalogue actif. Sans magasin (ou magasin inconnu), les meilleures ventes
 * sont celles du réseau. Calculé une fois pour six heures (ceo_app_setting
 * `tabletteBook:<shop|reseau>:<ensemble>`) ; les photos, elles, se relisent à
 * chaque appel — une photo arrivée change `version`, et la tablette se recharge.
 */
function ep_tablette_book(): array
{
    @set_time_limit(120);
    try {
        $shop = tbMagasin(tbIdMagasin($_GET['shop'] ?? null));
        $ensemble = ($_GET['ensemble'] ?? '') === 'tout' ? 'tout' : 'comptoir';
        return tbReponse(tbBase($shop, $ensemble, !empty($_GET['rafraichir'])));
    } catch (Throwable $e) {
        http_response_code(500);
        return ['erreur' => 'book indisponible : ' . $e->getMessage()];
    }
}

/**
 * POST /tablette/photos — {shop, ensemble?} : au plus PS_PHOTOS_PAR_APPEL photos
 * du panel téléchargées (recette lue par l'id_recipe du catalogue, sans passer
 * par un magasin), puis les vignettes carrées de 640 px. L'écran BO rappelle tant que
 * `restants` > 0 ; la tablette ne l'appelle jamais.
 * `faites` : références traitées par cet appel (photo lue au panel, absence
 * notée pour sept jours, ou vignette faite) ; `restants` : ce qu'un prochain
 * appel ferait encore ; `manquantes` : produits du book toujours sans photo.
 */
function wr_tablette_photos(): array
{
    @set_time_limit(300);
    $b = body();
    $shop = tbMagasin(tbIdMagasin($b['shop'] ?? null));
    $ensemble = ($b['ensemble'] ?? '') === 'tout' ? 'tout' : 'comptoir';
    $base = tbBase($shop, $ensemble, false);
    $refs = []; $recettes = [];
    foreach ($base['book']['products'] as $p) {
        $r = (string) ($p['_ref'] ?? $p['id']);
        $refs[] = $r;
        if ((int) ($p['_recette'] ?? 0) > 0) { $recettes[$r] = (int) $p['_recette']; }
    }
    $panel = PanelApi::configured();
    $lus = $panel ? psPhotosResoudre($refs, $shop !== null ? (int) $shop['id'] : 0, $recettes)['lus'] : 0;
    $e = tbPhotosEtat($refs, TB_VIGNETTES_ECRITURE, TB_VIGNETTES_ECRITURE_S);
    $out = ['faites' => $lus + $e['faites'], 'restants' => $e['restants'], 'manquantes' => $e['manquantes']];
    if (!$panel && $e['aLire'] > 0) {
        // Sans compte panel, les photos à lire ne viendront jamais : on le dit
        // plutôt que de laisser l'écran rappeler en boucle.
        http_response_code(503);
        return ['error' => 'compte API du panel non configuré — les photos ne peuvent pas être lues'] + $out;
    }
    return $out;
}

/* --- Le book : construit, gardé six heures, complété des photos à chaque lecture --------------- */

/**
 * Le book de base (sans les photos) : depuis le cache s'il a moins de six heures, sinon recalculé. Un book dont les
 * lectures au panel n'ont pas abouti (temps écoulé, panel muet) ne se garde que trente minutes : le calcul suivant
 * lit ce qui manque.
 */
function tbBase(?array $shop, string $ensemble, bool $forcer): array
{
    $cle = 'tabletteBook:' . ($shop['id'] ?? 'reseau') . ':' . $ensemble;
    if (!$forcer) {
        try { $c = setting($cle); } catch (Throwable $e) { $c = null; }
        $duree = !empty($c['base']['aCompleter']) ? TB_CACHE_INCOMPLET_MIN * 60 : TB_CACHE_HEURES * 3600;
        if (is_array($c) && isset($c['le'], $c['base']['book']['products']) && time() - (int) $c['le'] < $duree) {
            return $c['base'];
        }
    }
    $base = tbConstruire($shop, $ensemble);
    $j = json_encode(['le' => time(), 'base' => $base], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($j)) {
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
        catch (Throwable $e) { /* sans cache : le book part quand même, recalculé au prochain appel */ }
    }
    return $base;
}

/**
 * Le book recalculé. Chaque produit garde `_ref` (la référence des photos) et
 * `_recette` (son id_recipe) pour la suite ; tbReponse les retire.
 */
function tbConstruire(?array $shop, string $ensemble): array
{
    $sources = ['produits' => '', 'photos' => '', 'best' => '', 'saisons' => '', 'allergenes' => ''];

    // 1. Le catalogue (gammes comprises) : sans lui, pas de book.
    $cat = ep_prod_catalogue();
    if (!$cat) { throw new RuntimeException('catalogue vide'); }

    // 2. Les gammes saisonnières, et leur nom néerlandais quand le panel en a un.
    $saisons = []; $nl = [];
    try {
        $s = tbSaisons(date('Y-m-d'));
        [$saisons, $nl, $sources['saisons']] = [$s['saisons'], $s['nl'], $s['source']];
    } catch (Throwable $e) {
        $sources['saisons'] = 'gammes saisonnières illisibles (' . $e->getMessage() . ') : aucune saison';
    }
    $parSaison = [];
    foreach ($saisons as $s) { $parSaison[(int) $s['id']] = $s; }

    // 3. Le choix des produits.
    $retenus = $cat;
    $actifs = count($cat);
    $choix = 'tout le catalogue actif';
    if ($ensemble === 'comptoir') {
        $proches = array_keys(array_filter($parSaison, static fn ($s) => is_array($s['fenetre'] ?? null)
            && (!empty($s['fenetre']['ouverte']) || (int) $s['fenetre']['jours'] <= TB_SAISON_AVANT_JOURS)));
        $sel = []; $nPlano = 0; $nSaison = 0; $nOblig = 0;
        foreach ($cat as $c) {
            $plano = ($c['zone'] ?? null) !== null;
            $saison = (bool) array_intersect(array_map('intval', (array) ($c['saisons'] ?? [])), $proches);
            $oblig = !empty($c['exigible'] ?? $c['must'] ?? false);
            $nPlano += (int) $plano; $nSaison += (int) $saison; $nOblig += (int) $oblig;
            if ($plano || $saison || $oblig) { $sel[] = $c; }
        }
        if ($nPlano === 0) {
            $choix = 'planogramme standard vide : tout le catalogue actif';
        } else {
            $retenus = $sel;
            $choix = 'comptoir : ' . $nPlano . ' au planogramme standard, ' . $nSaison . ' de gamme ouverte ou qui ouvre sous '
                . TB_SAISON_AVANT_JOURS . ' jours, ' . $nOblig . ' obligatoire(s) exigée(s) — un produit peut cumuler';
        }
    }
    $ids = [];
    foreach ($retenus as $c) { if (($c['pwaId'] ?? null) !== null && (int) $c['pwaId'] > 0) { $ids[] = (int) $c['pwaId']; } }

    // 4. La fiche produit de la base partagée, lue en UNE fois.
    $fiches = [];
    $noteFiches = '';
    try {
        $fiches = tbFiches($ids);
        $vides = 0;
        foreach ($fiches as $f) { if (trim((string) ($f['allergene'] ?? '')) === '') { $vides++; } }
        $noteFiches = 'fiche produit (allergènes, régime, conservation, DLC, unité) : table product, ' . count($fiches) . ' fiche(s) lue(s), '
            . 'allergènes vides sur ' . $vides;
    } catch (Throwable $e) {
        $noteFiches = 'fiche produit illisible (' . $e->getMessage() . ') : allergènes, régime et conservation vides';
    }

    // 4 bis. La fiche du panel (GET /products, tout le catalogue en une lecture) : le stockage que la base n'a pas.
    $aCompleter = false;
    try {
        $pp = tbProduitsPanel();
        [$panelProduits, $notePanel] = [$pp['produits'], $pp['note']];
        $aCompleter = $pp['aCompleter'];
    } catch (Throwable $e) {
        [$panelProduits, $notePanel] = [[], 'fiche du panel illisible (' . $e->getMessage() . ')'];
    }

    // 4 ter. Les allergènes des recettes du panel, pour les produits dont la fiche ne dit rien de lisible.
    $rids = [];
    foreach ($retenus as $c) {
        $pid = ($c['pwaId'] ?? null) !== null ? (int) $c['pwaId'] : 0;
        $rid = (int) ($c['recetteId'] ?? 0) ?: (int) ($panelProduits[$pid]['id_recipe'] ?? 0);
        if ($rid > 0 && !tbAllergenes(tbTexte((string) ($fiches[$pid]['allergene'] ?? '')))['alKnown']) { $rids[$rid] = true; }
    }
    try {
        $lu = tbAllergenesPanel(array_keys($rids));
        $aCompleter = $aCompleter || $lu['aCompleter'];
    } catch (Throwable $e) {
        // Jamais rencontré : tbAllergenesPanel garde ses erreurs. Par prudence, le cache tel quel.
        $lu = ['cache' => tbCacheAllergenes(), 'limite' => time() - TB_PANEL_HEURES * 3600,
            'note' => 'lecture au panel en échec (' . $e->getMessage() . ')'];
        $aCompleter = true;
    }

    // 5. Les meilleures ventes.
    $book = [];
    foreach ($retenus as $c) { $book[] = tbIdProduit($c); }
    $best = [];
    try {
        [$top, $sources['best']] = tbBest($shop, $book);
        $best = array_fill_keys($top, true);
    } catch (Throwable $e) {
        $sources['best'] = 'relevés de ventes illisibles (' . $e->getMessage() . ') : aucune meilleure vente';
    }

    // 6. Catégories, saisons et produits, dans les noms de champs de la tablette.
    $cats = []; $produits = [];
    $etats = ['fiche' => 0, 'complet' => 0, 'partiel' => 0, 'aucun' => 0, 'sansRecette' => 0];
    $aSaisir = [];
    $n = ['keep' => 0, 'keepPanel' => 0, 'dlc' => 0, 'dlcPanel' => 0];
    foreach ($retenus as $c) {
        $id = tbIdProduit($c);
        $pid = ($c['pwaId'] ?? null) !== null ? (int) $c['pwaId'] : 0;
        $f = $fiches[$pid] ?? [];
        $fp = $panelProduits[$pid] ?? [];
        $k = tbCategorie($c['groupe'] ?? null, $c['categorie'] ?? null);
        $cats[$k['id']] = $k['fr'];
        $brut = tbTexte(isset($f['allergene']) ? (string) $f['allergene'] : '');
        $rid = (int) ($c['recetteId'] ?? 0) ?: (int) ($fp['id_recipe'] ?? 0);
        $al = tbAllergenesProduit($brut, $rid, $lu['cache'], (int) $lu['limite']);
        $etats[$al['etat']]++;
        foreach ($al['vides'] as $m) { $aSaisir[$m] = ($aSaisir[$m] ?? 0) + 1; }
        $poids = (int) ($c['poids'] ?? 0) ?: (int) ($f['single_weight'] ?? 0);
        // La durée de vie : celle du BO (catalogue, sinon fiche de la base), celle du panel seulement à défaut.
        $minutes = (int) ($c['dlv'] ?? 0) > 0 ? (int) $c['dlv'] * 60 : (int) ($f['shelf_life_minutes'] ?? 0);
        if ($minutes <= 0 && (int) ($fp['shelf_life_minutes'] ?? 0) > 0) { $minutes = (int) $fp['shelf_life_minutes']; $n['dlcPanel']++; }
        $n['dlc'] += (int) ($minutes > 0);
        $keep = tbConserver(tbFicheConservation($f, $fp));
        if ($keep[0] !== '') { $n['keep']++; $n['keepPanel'] += (int) ($keep[0] !== tbConserver($f)[0]); }
        $p = ['id' => $id, 'cat' => $k['id']];
        $sid = tbSaisonProduit((array) ($c['saisons'] ?? []), $parSaison);
        if ($sid !== null) { $p['season'] = 's' . $sid; }
        $produits[] = $p + [
            'img' => '',
            'price' => isset($c['prix']) && is_numeric($c['prix']) ? round((float) $c['prix'], 2) : null,
            'unit' => tbUnite($poids, (int) ($f['is_piece_based'] ?? 0) === 1),
            'best' => isset($best[$id]),
            'name' => [tbTexte((string) ($c['nom'] ?? '')), ''],
            'desc' => [tbTexte(isset($f['positioning_description']) ? (string) $f['positioning_description'] : ''), ''],
            'pitch' => ['', ''],
            'ingr' => ['', ''],
            'al' => $al['al'], 'tr' => [], 'alKnown' => $al['alKnown'], 'trKnown' => false, 'alRaw' => $al['alRaw'],
            'diet' => (int) ($f['is_vegetarian'] ?? 0) === 1 ? 'vege' : null,
            'keep' => $keep,
            'dlc' => tbDlc($minutes),
            'cross' => [],
            'crossLine' => ['', ''],
            '_ref' => (string) ($c['ref'] ?? $id),
            '_recette' => $rid,
        ];
    }
    $ordre = [];
    foreach ($cats as $cid => $fr) { $ordre[$cid] = [tbOrdreGroupe($fr), tbCle($fr)]; }
    uasort($ordre, static fn ($a, $b) => $a <=> $b);
    $rang = array_flip(array_keys($ordre));
    usort($produits, static fn ($a, $b) => [$rang[$a['cat']], tbCle($a['name'][0]), $a['id']] <=> [$rang[$b['cat']], tbCle($b['name'][0]), $b['id']]);
    $categories = [];
    foreach (array_keys($ordre) as $cid) { $categories[] = ['id' => $cid, 'n' => [$cats[$cid], tbGroupeNl($cats[$cid])]]; }

    $seasons = [];
    foreach ($saisons as $s) {
        $seasons[] = ['id' => 's' . (int) $s['id'], 'img' => tbImageSaison(($s['nomPanel'] ?? '') . ' ' . ($s['nom'] ?? '')),
            'm' => tbMoisSaison($s['debut'] ?? null, $s['fin'] ?? null), 'n' => [tbTexte((string) ($s['nom'] ?? '')), $nl[(int) $s['id']] ?? ''],
            'dates' => tbDatesSaison($s['debut'] ?? null, $s['fin'] ?? null), 'tip' => ['', ''], '_debut' => (string) ($s['debut'] ?? '99-99')];
    }
    // Dans l'ordre du calendrier : la tablette cherche « la prochaine saison » dans l'ordre des données.
    usort($seasons, static fn ($a, $b) => [$a['_debut'], $a['id']] <=> [$b['_debut'], $b['id']]);
    foreach ($seasons as &$s) { unset($s['_debut']); }
    unset($s);

    $nb = count($produits);
    $sources['produits'] = 'catalogue du BO, ' . $actifs . ' produit(s) actif(s) ; ' . $choix . ' → ' . $nb . ' retenu(s) ; '
        . $noteFiches . ' ; conservation : ' . $n['keep'] . '/' . $nb . ' (dont ' . $n['keepPanel'] . ' depuis le panel), DLC connue : '
        . $n['dlc'] . '/' . $nb . ' (dont ' . $n['dlcPanel'] . ' depuis le panel) — fiche du panel : ' . $notePanel
        . ' ; prix réseau du catalogue ; noms, descriptions et argumentaires néerlandais absents';
    arsort($aSaisir);
    $top = [];
    foreach (array_slice($aSaisir, 0, 5, true) as $m => $k) { $top[] = $m . ' (' . $k . ')'; }
    $sources['allergenes'] = 'allergènes des matières premières du panel (recette → sous-recettes → matières, emballages exclus) : '
        . $etats['complet'] . ' produit(s) complet(s), ' . $etats['partiel'] . ' partiel(s) (allergènes connus, liste à compléter), '
        . $etats['aucun'] . ' sans allergène connu, ' . $etats['sansRecette'] . ' sans recette'
        . ($etats['fiche'] > 0 ? ', ' . $etats['fiche'] . ' lu(s) sur la fiche produit' : '')
        . ($top ? ' ; matières sans allergènes saisis au panel (nombre de produits) : ' . implode(', ', $top) : '')
        . ' ; ' . $lu['note'] . ' ; traces jamais renseignées';
    return ['genereLe' => date('c'), 'shop' => $shop, 'ensemble' => $ensemble,
        'book' => ['categories' => $categories, 'seasons' => $seasons, 'products' => $produits],
        'sources' => $sources, 'aCompleter' => $aCompleter];
}

/** La réponse : le book de base, ses photos relues (et vignettes faites), ce qui manque, sa version. */
function tbReponse(array $base): array
{
    $produits = $base['book']['products'];
    $refs = array_map(static fn ($p) => (string) ($p['_ref'] ?? $p['id']), $produits);
    try {
        $e = tbPhotosEtat($refs, TB_VIGNETTES_LECTURE, TB_VIGNETTES_LECTURE_S);
        $avec = count($produits) - $e['manquantes'];
        $source = 'photo de recette du panel gardée sous uploads/plano/panel/, vignette carrée ' . TB_VIGNETTE_PX . ' px sous uploads/tablette/ : '
            . $avec . '/' . count($produits) . ' produit(s) avec photo, ' . $e['vignettes'] . ' en vignette'
            . ($e['restants'] > 0 ? ' ; ' . $e['restants'] . ' à récupérer (POST /tablette/photos)' : '');
    } catch (Throwable $ex) {
        $e = ['img' => [], 'restants' => 0];
        $source = 'photos illisibles (' . $ex->getMessage() . ') : aucune photo';
    }
    foreach ($produits as $i => &$p) {
        $p['img'] = $e['img'][$refs[$i]] ?? '';
        unset($p['_ref'], $p['_recette']);
    }
    unset($p);
    $book = ['categories' => $base['book']['categories'], 'seasons' => $base['book']['seasons'], 'products' => $produits];
    return [
        'schema' => TB_SCHEMA,
        'version' => tbVersion($book),
        'genereLe' => (string) $base['genereLe'],
        'shop' => $base['shop'] ?? null,
        'ensemble' => (string) $base['ensemble'],
        'book' => $book,
        'manque' => tbManque($produits),
        'photosRestantes' => (int) $e['restants'],
        'sources' => ['produits' => (string) ($base['sources']['produits'] ?? ''), 'photos' => $source,
            'best' => (string) ($base['sources']['best'] ?? ''), 'saisons' => (string) ($base['sources']['saisons'] ?? ''),
            'allergenes' => (string) ($base['sources']['allergenes'] ?? '')],
    ];
}

/* --- Les sources ------------------------------------------------------------------------------- */

/** L'identifiant de magasin demandé, ou null (absent, vide ou mal formé). */
function tbIdMagasin(mixed $v): ?string
{
    $s = is_scalar($v) ? trim((string) $v) : '';
    return preg_match('/^[A-Za-z0-9_-]{1,12}$/', $s) ? $s : null;
}

/** Le magasin {id, nom court} ; null si on n'en demande pas ou s'il est inconnu (le book est alors celui du réseau). */
function tbMagasin(?string $id): ?array
{
    if ($id === null) { return null; }
    try {
        $r = Db::row('SELECT id, name FROM shops WHERE id = ?', [$id]);
    } catch (PDOException $e) {
        try { $r = Db::row('SELECT id, name FROM ceo_shop WHERE id = ?', [$id]); } catch (PDOException $e2) { $r = null; }
    }
    return $r === null ? null : ['id' => (string) $r['id'], 'nom' => psCourt((string) $r['name'])];
}

/**
 * Les gammes saisonnières du book : celles de /production/saisons (actives, ni
 * permanentes ni terminées), avec leur nom néerlandais tiré des alias du panel.
 *
 * @return array{saisons:list<array>, nl:array<int,string>, source:string}
 */
function tbSaisons(string $jour): array
{
    $sauve = $_GET;
    try {
        $_GET = ['date' => $jour];            // ni rafraîchissement forcé, ni autre date que demandée
        $r = ep_prod_saisons();
        $saisons = is_array($r['saisons'] ?? null) ? $r['saisons'] : [];
        $nl = []; $noteNl = '';
        try {
            $_GET = [];
            foreach ((ep_prod_periodes()['periodes'] ?? []) as $p) {
                foreach ((array) ($p['alias'] ?? []) as $lang => $v) {
                    if (str_starts_with(strtolower((string) $lang), 'nl') && is_string($v) && trim($v) !== '' && $p['id'] !== null) {
                        [, $court] = aoNom($v);
                        $nl[(int) $p['id']] = tbTexte($court);
                        break;
                    }
                }
            }
            $noteNl = count($nl) . ' nom(s) néerlandais saisi(s) au panel';
        } catch (Throwable $e) {
            $noteNl = 'noms néerlandais illisibles (' . $e->getMessage() . ')';
        }
    } finally {
        $_GET = $sauve;
    }
    return ['saisons' => $saisons, 'nl' => $nl,
        'source' => 'gammes saisonnières du panel : ' . count($saisons) . ' saison(s) (dates : base partagée ; produits : API du panel) ; '
            . $noteNl . ' ; illustrations de la tablette choisies par mot-clé ; conseils absents'];
}

/**
 * La fiche produit de la base partagée, pour tous les produits du book en UNE
 * requête. `SELECT *` comme la fiche de /production/produit/fiche : les colonnes
 * varient d'une installation à l'autre, et en nommer une absente ferait échouer
 * toute la lecture. Les colonnes absentes laissent leurs champs vides.
 *
 * @param list<int> $ids
 * @return array<int,array<string,mixed>>
 */
function tbFiches(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
    if (!$ids) { return []; }
    $out = [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (Db::rows('SELECT * FROM product WHERE id IN (' . $in . ')', $ids) as $r) { $out[(int) $r['id']] = $r; }
    return $out;
}

/**
 * La fiche du panel de tout le catalogue — GET /products, UNE lecture — pour ce que la base partagée n'a pas : le nom
 * et la consigne du lieu de stockage (la base n'en a que l'id), et à défaut la réchauffe, la durée de vie et l'id de
 * recette. Gardée 24 h (ceo_app_setting `tablettePanelProduits`, les seuls champs utiles). Panel muet : la dernière
 * liste lue, sinon rien — la fiche de la base reste seule, et le book se recalcule dans trente minutes.
 *
 * @return array{produits: array<int,array<string,mixed>>, note: string, aCompleter: bool}
 */
function tbProduitsPanel(): array
{
    try { $c = setting('tablettePanelProduits'); } catch (Throwable $e) { $c = null; }
    $connus = is_array($c) && is_array($c['produits'] ?? null) ? $c['produits'] : [];
    $le = is_array($c) ? (int) ($c['le'] ?? 0) : 0;
    if ($connus && time() - $le < TB_PANEL_HEURES * 3600) {
        return ['produits' => $connus, 'note' => count($connus) . ' produit(s) lus au panel le ' . date('d/m/Y à H:i', $le), 'aCompleter' => false];
    }
    $pourquoi = 'compte API du panel non configuré';
    if (PanelApi::configured()) {
        $st = ['lus' => 0, 'echecs' => 0, 'remis' => 0, 'muet' => false, 'erreur' => ''];
        $r = tbPanelLire(['produits' => '/products'], microtime(true) + TB_PANEL_PRODUITS_S, $st)['produits'] ?? null;
        $out = [];
        foreach (is_array($r) ? PanelApi::liste($r) : [] as $p) {
            if ((int) ($p['id'] ?? 0) > 0) { $out[(int) $p['id']] = array_intersect_key($p, array_flip(TB_PANEL_PRODUIT_CHAMPS)); }
        }
        if ($out) {
            $j = json_encode(['le' => time(), 'produits' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($j)) {
                try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['tablettePanelProduits', $j]); }
                catch (Throwable $e) { /* sans cache : relue au prochain calcul */ }
            }
            return ['produits' => $out, 'note' => count($out) . ' produit(s) lus au panel (GET /products)', 'aCompleter' => false];
        }
        $pourquoi = 'GET /products sans réponse' . ($st['erreur'] !== '' ? ' (' . $st['erreur'] . ')' : '');
    }
    if ($connus) {
        return ['produits' => $connus, 'note' => $pourquoi . ' : liste du ' . date('d/m/Y à H:i', $le) . ' reprise', 'aCompleter' => PanelApi::configured()];
    }
    return ['produits' => [], 'note' => $pourquoi . ' : fiche de la base partagée seule', 'aCompleter' => PanelApi::configured()];
}

/**
 * Les recettes des produits, leurs sous-recettes et leurs matières premières, avec le référentiel des allergènes du
 * panel (GET /allergens) : depuis le cache (`tabletteAllergenes`), complété au panel de ce qui manque ou a plus de
 * 24 h — en parallèle, 8 s au plus ; ce qui n'est pas lu à temps le sera au calcul suivant (`aCompleter`). Le temps
 * qui reste relit d'avance les entrées de 18 à 24 h (l'heure exacte étalée par entrée) : sans cela tout le cache
 * expirerait d'un bloc, et chaque jour un calcul ne suffirait pas à tout relire. Une sous-recette se lit sous
 * /subrecipes/{id} (GET /recipes/{id} rend `[]` pour elle). Rien ici ne lève : une panne du panel laisse le cache tel
 * quel et le dit dans `note`. `limite` : une entrée lue avant n'est plus à jour.
 *
 * @param list<int> $rids recettes des produits
 * @return array{cache: array, limite: int, note: string, aCompleter: bool}
 */
function tbAllergenesPanel(array $rids): array
{
    $t0 = microtime(true);
    $fin = $t0 + TB_PANEL_S;
    $limite = time() - TB_PANEL_HEURES * 3600;
    $c = tbCacheAllergenes();
    $st = ['lus' => 0, 'echecs' => 0, 'remis' => 0, 'muet' => false, 'erreur' => ''];
    $lire = PanelApi::configured();
    $frais = static fn ($e): bool => is_array($e) && (int) ($e['le'] ?? 0) >= $limite;
    // 2 : absente ou périmée, à lire d'abord ; 1 : dans sa fenêtre d'avance (de 18 à 24 h, décalée par entrée) ; 0 : à jour.
    $aLire = static function ($e, string $cle) use ($limite, $lire): int {
        if (!$lire) { return 0; }
        if (!is_array($e) || (int) ($e['le'] ?? 0) < $limite) { return 2; }
        return (int) $e['le'] < $limite + crc32($cle) % (TB_PANEL_AVANCE_HEURES * 3600) ? 1 : 0;
    };
    $panne = null;
    try {
        // Le référentiel : les 14 allergènes du panel, id → code.
        if ($aLire($c['reference'], 'ref') > 0) {
            $r = tbPanelLire(['ref' => '/allergens'], $fin, $st)['ref'] ?? null;
            $ref = tbReferenceLue($r);
            if ($ref) { $c['reference'] = ['codes' => $ref, 'le' => time()]; }
        }
        // Les recettes, puis les sous-recettes niveau par niveau (chacune une fois).
        $niveau = array_map(static fn ($r) => ['recettes', (int) $r], $rids);
        $vus = [];
        for ($p = 0; $niveau && $p <= TB_AL_PROFONDEUR; $p++) {
            $chemins = [[], [], []];
            foreach ($niveau as [$t, $id]) {
                $k = $t . ':' . $id;
                if ($id <= 0 || isset($vus[$k])) { continue; }
                $vus[$k] = [$t, $id];
                $chemins[$aLire($c[$t][$id] ?? null, $k)][$k] = ($t === 'recettes' ? '/recipes/' : '/subrecipes/') . $id;
            }
            foreach (tbPanelLire($chemins[2] + $chemins[1], $fin, $st, count($chemins[2])) as $k => $d) {
                [$t, $id] = $vus[$k];
                $e = tbRecetteLue($d, $id);
                if ($e !== null) { $c[$t][$id] = $e + ['le' => time()]; }
            }
            $suivant = [];
            foreach ($niveau as [$t, $id]) {
                foreach ((array) ($c[$t][$id]['sous'] ?? []) as $s) { $suivant[] = ['sousRecettes', (int) $s]; }
            }
            $niveau = $suivant;
        }
        // Les matières premières de tout l'arbre, emballages compris : un emballage qui porterait des allergènes compterait.
        $chemins = [[], [], []];
        foreach ($vus as [$t, $id]) {
            foreach ((array) ($c[$t][$id]['materiaux'] ?? []) as $m) {
                $mid = (int) ($m['id'] ?? 0);
                if ($mid > 0) { $chemins[$aLire($c['materiaux'][$mid] ?? null, 'm:' . $mid)]['m:' . $mid] = '/materials/' . $mid; }
            }
        }
        foreach (tbPanelLire($chemins[2] + $chemins[1], $fin, $st, count($chemins[2])) as $k => $d) {
            $mid = (int) substr($k, 2);
            $e = tbMatiereLue($d, $mid);
            if ($e !== null) { $c['materiaux'][$mid] = $e + ['le' => time()]; }
        }
    } catch (Throwable $e) {
        $panne = $e->getMessage();
    }
    if ($st['lus'] > 0) { tbCacheAllergenesEcrire($c); }

    $ref = $c['reference']['codes'] ?? [];
    $hors = array_values(array_diff(array_values($ref), array_keys(TB_ALLERGENES_PANEL)));
    $note = ($ref ? 'référentiel : les ' . count($ref) . ' allergènes de GET /allergens du panel'
            . ($frais($c['reference']) ? '' : ' (lu le ' . date('d/m/Y', (int) ($c['reference']['le'] ?? 0)) . ', à relire)')
            . ($hors ? ', dont ' . count($hors) . ' inconnu(s) de la tablette (' . implode(', ', $hors) . ') : produits concernés à vérifier' : '')
            : 'référentiel GET /allergens jamais lu : codes UE du panel en dur')
        . ' ; en cache : ' . count($c['recettes']) . ' recette(s), ' . count($c['sousRecettes']) . ' sous-recette(s), '
        . count($c['materiaux']) . ' matière(s), gardées ' . TB_PANEL_HEURES . ' h';
    if (!$lire) {
        $note .= ' ; compte API du panel non configuré : rien n’a été lu';
    } else {
        $note .= ' ; ' . $st['lus'] . ' lecture(s) au panel en ' . str_replace('.', ',', (string) round(microtime(true) - $t0, 1)) . ' s';
        if ($st['remis'] > 0) { $note .= ' ; ' . $st['remis'] . ' remise(s) au calcul suivant (' . ($st['muet'] ? 'panel muet' : TB_PANEL_S . ' s écoulées') . ')'; }
        if ($st['echecs'] > 0) { $note .= ' ; ' . $st['echecs'] . ' en échec' . ($st['erreur'] !== '' ? ' (' . $st['erreur'] . ')' : ''); }
    }
    if ($panne !== null) { $note .= ' ; lecture interrompue (' . $panne . ')'; }
    // Relu dans trente minutes si une partie n'a pas été lue faute de temps, si le panel n'a rien rendu, ou en panne.
    $aCompleter = $lire && ($st['remis'] > 0 || ($st['lus'] === 0 && $st['echecs'] > 0) || $panne !== null);
    return ['cache' => $c, 'limite' => $limite, 'note' => $note, 'aCompleter' => $aCompleter];
}

/**
 * Des GET au panel, TB_PANEL_FRONT de front, tant que le temps le permet : un paquet ne part que s'il reste au moins
 * une seconde avant `$fin`, et chacune de ses requêtes n'a que le temps restant (deux secondes au moins). Un paquet
 * entièrement en 401 est un jeton périmé (la lecture parallèle ne se reconnecte pas) : une lecture simple se
 * reconnecte et le paquet repart une fois. Un paquet d'au moins trois chemins sans AUCUNE réponse, autrement qu'en
 * 404 : le panel est muet, rien d'autre ne part (un chemin seul en échec, un 404, ne sont qu'une recette illisible).
 * `$st` compte les réponses lues, les échecs et les lectures remises (non tentées) parmi les `$requis` premiers
 * chemins — les suivants, des relectures d'avance, ne manquent à personne s'ils attendent.
 *
 * @param array<string,string> $chemins clé → chemin
 * @return array<string,array> les réponses lues, par clé
 */
function tbPanelLire(array $chemins, float $fin, array &$st, ?int $requis = null): array
{
    $out = [];
    $requis ??= count($chemins);
    $i = 0;
    foreach (array_chunk($chemins, TB_PANEL_FRONT, true) as $lot) {
        $reste = $fin - microtime(true);
        if ($st['muet'] || $reste < 1.0) { $st['remis'] += max(0, min(count($lot), $requis - $i)); $i += count($lot); continue; }
        $i += count($lot);
        $t = (int) max(2, min(15, ceil($reste)));
        PanelApi::$lastError = null;
        $res = PanelApi::getParallele($lot, TB_PANEL_FRONT, $t);
        if (!array_filter($res, 'is_array') && str_contains((string) PanelApi::$lastError, 'HTTP 401')
            && PanelApi::get((string) reset($lot)) !== null) {
            $res = PanelApi::getParallele($lot, TB_PANEL_FRONT, $t);
        }
        $lus = 0;
        foreach ($lot as $k => $_) {
            if (is_array($res[$k] ?? null)) { $out[$k] = $res[$k]; $lus++; }
        }
        $st['lus'] += $lus;
        $st['echecs'] += count($lot) - $lus;
        if ($lus < count($lot)) { $st['erreur'] = (string) (PanelApi::$lastError ?? 'sans réponse'); }
        if ($lus === 0 && count($lot) >= 3 && !str_contains($st['erreur'], 'HTTP 404')) { $st['muet'] = true; }
    }
    return $out;
}

/** Le cache des lectures du panel pour les allergènes, vide s'il est illisible. */
function tbCacheAllergenes(): array
{
    try { $c = setting('tabletteAllergenes'); } catch (Throwable $e) { $c = null; }
    $out = ['reference' => [], 'recettes' => [], 'sousRecettes' => [], 'materiaux' => []];
    foreach ($out as $k => $_) { if (is_array($c[$k] ?? null)) { $out[$k] = $c[$k]; } }
    return $out;
}

/**
 * Le cache réécrit, fusionné avec ce qu'un calcul voisin (un autre magasin) a pu écrire entre-temps — l'entrée la plus
 * récente gagne —, sans les entrées que plus rien n'a relues depuis TB_PANEL_OUBLI_JOURS jours.
 */
function tbCacheAllergenesEcrire(array $c): void
{
    $avant = tbCacheAllergenes();
    if ((int) ($avant['reference']['le'] ?? 0) > (int) ($c['reference']['le'] ?? 0)) { $c['reference'] = $avant['reference']; }
    $oubli = time() - TB_PANEL_OUBLI_JOURS * 86400;
    foreach (['recettes', 'sousRecettes', 'materiaux'] as $k) {
        foreach ($avant[$k] as $id => $e) {
            if (!isset($c[$k][$id]) || (int) ($e['le'] ?? 0) > (int) ($c[$k][$id]['le'] ?? 0)) { $c[$k][$id] = $e; }
        }
        $c[$k] = array_filter($c[$k], static fn ($e) => is_array($e) && (int) ($e['le'] ?? 0) >= $oubli);
    }
    $j = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($j)) { return; }
    try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['tabletteAllergenes', $j]); }
    catch (Throwable $e) { /* sans cache : relu au prochain calcul */ }
}

/**
 * Les meilleures ventes : le top 8 au comptoir du magasin sur 28 jours, parmi
 * les produits du book ; sans magasin, ou sans aucune vente relevée pour lui, le
 * top du réseau. Relevés quotidiens gravés (psVentesJour), aucun appel au panel.
 *
 * @param list<string> $ids produits du book
 * @return array{0:list<string>,1:string}
 */
function tbBest(?array $shop, array $ids): array
{
    if ($shop !== null) {
        $v = tbVentes([(string) $shop['id']]);
        if ($v['ventes']) {
            return [tbTop($v['ventes'], $ids, TB_BEST), 'les ' . TB_BEST . ' meilleures ventes au comptoir de ' . $shop['nom'] . ' du '
                . $v['du'] . ' au ' . $v['au'] . ' (relevés quotidiens du panel)'];
        }
    }
    $mags = array_map(static fn ($m) => (string) $m['id'], psMagasins());
    $v = tbVentes($mags);
    $pourquoi = $shop === null ? 'aucun magasin demandé' : $shop['nom'] . ' n’a aucune vente relevée sur ' . TB_BEST_JOURS . ' jours';
    return [tbTop($v['ventes'], $ids, TB_BEST), 'réseau (' . $pourquoi . ') : les ' . TB_BEST . ' meilleures ventes au comptoir de '
        . count($mags) . ' magasin(s) du ' . $v['du'] . ' au ' . $v['au']];
}

/** Unités vendues par produit sur les 28 derniers jours (hier compris) — [pid => unités]. */
function tbVentes(array $magasins): array
{
    $au = date('Y-m-d', strtotime('-1 day'));
    $du = date('Y-m-d', strtotime($au . ' -' . (TB_BEST_JOURS - 1) . ' days'));
    $v = []; $cout = 0;
    foreach ($magasins as $sid) {
        for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $r = psVentesJour((string) $sid, $j, $cout, 0);
            foreach ($r['u'] as $pid => $q) { $v[(string) $pid] = ($v[(string) $pid] ?? 0.0) + (float) $q; }
        }
    }
    return ['ventes' => $v, 'du' => $du, 'au' => $au];
}

/**
 * L'état des photos des produits : le chemin à servir pour chacun, et les
 * vignettes faites au passage (au plus `$max`, en `$secondes` au plus).
 * `restants` : photos à lire au panel (jamais lues, fichier perdu, absence de
 * plus de sept jours) + vignettes pas encore faites faute de temps ; une
 * vignette qui échoue n'y compte pas (elle échouerait encore).
 *
 * @param list<string> $refs
 * @return array{img:array<string,string>, restants:int, aLire:int, manquantes:int, vignettes:int, faites:int}
 */
function tbPhotosEtat(array $refs, int $max, float $secondes): array
{
    ensurePlanoStd();
    $refs = array_values(array_unique(array_map('strval', $refs)));
    $lignes = [];
    if ($refs) {
        $in = implode(',', array_fill(0, count($refs), '?'));
        foreach (Db::rows('SELECT ref, fichier, maj FROM ceo_plano_std_photo WHERE ref IN (' . $in . ')', $refs) as $r) { $lignes[(string) $r['ref']] = $r; }
    }
    $pub = __DIR__ . '/../public/';
    $t0 = microtime(true);
    $out = ['img' => [], 'restants' => 0, 'aLire' => 0, 'manquantes' => 0, 'vignettes' => 0, 'faites' => 0];
    foreach ($refs as $ref) {
        $l = $lignes[$ref] ?? null;
        $orig = $l !== null && !empty($l['fichier']) && is_file($pub . $l['fichier']) ? (string) $l['fichier'] : null;
        if ($orig === null) {
            $out['img'][$ref] = '';
            $out['manquantes']++;
            if ($l === null || !empty($l['fichier']) || time() - (strtotime((string) $l['maj']) ?: 0) >= 7 * 86400) { $out['aLire']++; }
            continue;
        }
        $vig = 'uploads/tablette/' . tbFichierRef($ref) . TB_VIGNETTE_FIN;
        $ok = is_file($pub . $vig) && (int) filemtime($pub . $vig) >= (int) filemtime($pub . $orig);
        if (!$ok) {
            if ($out['faites'] < $max && microtime(true) - $t0 < $secondes) {
                $out['faites']++;
                $ok = tbVignette($ref, $orig) !== null;
            } else {
                $out['restants']++;
            }
        }
        $out['vignettes'] += (int) $ok;
        $out['img'][$ref] = $ok ? $vig : $orig;
    }
    $out['restants'] += $out['aLire'];
    return $out;
}

/**
 * La vignette d'une photo : un carré de 640 px (moins si l'original est plus
 * petit), JPEG qualité 80, sous public/uploads/tablette/<ref>-640c.jpg — les
 * originaux du panel montent à 8 Mo, de quoi remplir une tablette hors ligne.
 * La tablette montre les photos en carré : une photo qui ne l'est pas est
 * posée entière au milieu et ses bords sont prolongés jusqu'au carré (le fond
 * continue, rien n'est coupé — l'étiquette d'un sachet reste lisible). Refaite
 * quand l'original est plus récent. Le chemin relatif à public/, ou null (pas
 * d'original, image illisible, trop grande pour la mémoire disponible).
 */
function tbVignette(string $ref, ?string $source = null): ?string
{
    if (!function_exists('imagecreatefromstring')) { return null; }
    $pub = __DIR__ . '/../public/';
    $nom = tbFichierRef($ref);
    if ($source === null) {
        foreach (['jpg', 'png', 'webp'] as $x) {
            if (is_file($pub . 'uploads/plano/panel/' . $nom . '.' . $x)) { $source = 'uploads/plano/panel/' . $nom . '.' . $x; break; }
        }
        if ($source === null) { return null; }
    }
    $src = $pub . $source;
    if (!is_file($src)) { return null; }
    $rel = 'uploads/tablette/' . $nom . TB_VIGNETTE_FIN;
    $dst = $pub . $rel;
    if (is_file($dst) && (int) filemtime($dst) >= (int) filemtime($src)) { return $rel; }

    $info = @getimagesize($src);
    if ($info === false || $info[0] < 1 || $info[1] < 1) { return null; }
    // Décodée, l'image pèse ~5 octets par pixel : sans la place, GD tuerait la
    // requête entière (erreur fatale, pas une exception).
    if (!tbMemoirePour((int) $info[0] * (int) $info[1] * 5 + (int) filesize($src) + 32 * 1024 * 1024)) { return null; }
    $bin = @file_get_contents($src);
    $im = is_string($bin) ? @imagecreatefromstring($bin) : false;
    unset($bin);
    if ($im === false) { return null; }
    // L'orientation EXIF d'une photo d'appareil : le navigateur l'applique à
    // l'original, GD non — sans ce redressement la vignette sortirait couchée.
    if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)] ?? 0;
        if ($angle !== 0) {
            $tourne = imagerotate($im, $angle, 0);
            if ($tourne !== false) { $im = $tourne; }
        }
    }
    $w = imagesx($im); $h = imagesy($im);
    $c = min(TB_VIGNETTE_PX, max($w, $h));
    $k = $c / max($w, $h);
    $nw = max(1, min($c, (int) round($w * $k))); $nh = max(1, min($c, (int) round($h * $k)));
    $p = imagecreatetruecolor($nw, $nh);
    // Fond blanc : la transparence d'un PNG ne passe pas en JPEG (elle virerait au noir).
    imagefill($p, 0, 0, (int) imagecolorallocate($p, 255, 255, 255));
    imagecopyresampled($p, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    unset($im);
    // Le carré : la photo au milieu, puis une colonne (ou ligne) prise près de
    // chaque bord étirée sur la marge, sans lissage (pixel pour pixel). Prise à
    // 3 px du bord, qu'elle recouvre aussi : certaines photos du panel ont un
    // liseré sombre d'un pixel, qui deviendrait sinon une bande noire.
    $x = intdiv($c - $nw, 2); $y = intdiv($c - $nh, 2);
    $v = imagecreatetruecolor($c, $c);
    imagecopy($v, $p, $x, $y, 0, 0, $nw, $nh);
    if ($x > 0) {
        $i = min(3, intdiv($nw, 50));
        imagecopyresized($v, $p, 0, $y, $i, 0, $x + $i, $nh, 1, $nh);
        imagecopyresized($v, $p, $x + $nw - $i, $y, $nw - 1 - $i, 0, $c - $x - $nw + $i, $nh, 1, $nh);
    }
    if ($y > 0) {
        $i = min(3, intdiv($nh, 50));
        imagecopyresized($v, $p, $x, 0, 0, $i, $nw, $y + $i, $nw, 1);
        imagecopyresized($v, $p, $x, $y + $nh - $i, 0, $nh - 1 - $i, $nw, $c - $y - $nh + $i, $nw, 1);
    }
    unset($p);
    $dos = dirname($dst);
    if (!is_dir($dos) && !@mkdir($dos, 0775, true) && !is_dir($dos)) { return null; }
    // Écrite à côté puis renommée : jamais une vignette à moitié écrite servie.
    $tmp = $dst . '.' . getmypid() . '.tmp';
    $ok = @imagejpeg($v, $tmp, 80) && @rename($tmp, $dst);
    if (!$ok) { @unlink($tmp); }
    return $ok ? $rel : null;
}

/** Assez de mémoire pour `$octets` de plus ? Relève la limite jusqu'à 512 Mo s'il le faut. */
function tbMemoirePour(int $octets): bool
{
    $lim = static function (): int {
        $l = trim((string) ini_get('memory_limit'));
        if ($l === '' || $l === '-1') { return PHP_INT_MAX; }
        $n = (int) $l;
        return match (strtolower(substr($l, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    };
    $besoin = memory_get_usage() + $octets;
    if ($lim() >= $besoin) { return true; }
    if ($besoin <= 512 * 1024 * 1024) { @ini_set('memory_limit', '512M'); }
    return $lim() >= $besoin;
}

/* --- Les transformations (pures) --------------------------------------------------------------- */

/** Minuscules sans accents, espaces resserrés : la clé de comparaison des intitulés. */
function tbCle(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', strtr(mb_strtolower($s), TB_ACCENTS)));
}

/** « Fêtes & Occasions » → « fetes-occasions ». */
function tbSlug(string $s): string
{
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', tbCle($s)), '-');
    return $s !== '' ? $s : 'autres';
}

/** Un texte affichable : UTF-8 valide, espaces (tabulations comprises) resserrés. */
function tbTexte(?string $s): string
{
    $s = (string) $s;
    if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); }
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** Le nom de fichier d'une référence, comme psTelecharger l'écrit. */
function tbFichierRef(string $ref): string
{
    return (string) preg_replace('/[^\w.-]/', '_', $ref);
}

/** L'identifiant tablette d'un produit : l'id du panel (`pwaId`), sinon la référence. */
function tbIdProduit(array $c): string
{
    return ($c['pwaId'] ?? null) !== null ? (string) (int) $c['pwaId'] : (string) ($c['ref'] ?? '');
}

/**
 * La catégorie tablette d'un produit : son groupe du BO (le premier, quand la
 * catégorie est rattachée à deux — « Tartes · Pâtisserie »), sinon sa catégorie.
 *
 * @return array{id:string, fr:string}
 */
function tbCategorie(?string $groupe, ?string $categorie): array
{
    $fr = tbTexte(explode(' · ', (string) $groupe)[0]);
    if ($fr === '') { $fr = tbTexte($categorie); }
    if ($fr === '') { $fr = 'Autres'; }
    return ['id' => 'g-' . tbSlug($fr), 'fr' => $fr];
}

/** Le nom néerlandais (brouillon) d'un groupe, '' s'il n'est pas connu. */
function tbGroupeNl(string $fr): string
{
    $k = tbCle($fr);
    foreach (TB_GROUPES_NL as $g => $nl) { if (tbCle($g) === $k) { return $nl; } }
    return '';
}

/** Le rang d'un groupe dans le book : l'ordre de TB_GROUPES_NL, les inconnus ensuite. */
function tbOrdreGroupe(string $fr): int
{
    $i = array_search(tbCle($fr), array_map('tbCle', array_keys(TB_GROUPES_NL)), true);
    return $i === false ? 999 : (int) $i;
}

/**
 * `product.allergene` → identifiants UE. Lu seulement si TOUT le texte est une
 * liste d'allergènes nommés (français, néerlandais ou anglais ; séparateurs et
 * « et », « produits à base de »… admis) ; un mot inconnu, un chiffre, une
 * trace, une négation (« sans », « glutenvrij »), un doute (« ? ») ou un texte
 * vide → `alKnown: false` et `al: []`. Une liste lue complètement ne dit rien
 * des traces : la tablette ne peut pas en conclure « sans ».
 *
 * @return array{al:list<string>, alKnown:bool}
 */
function tbAllergenes(string $brut): array
{
    static $mots = null, $liens = null;
    $inconnu = ['al' => [], 'alKnown' => false];
    $t = tbCle($brut);
    if ($t === '' || preg_match(TB_ALLERGENES_PIEGES, $t)) { return $inconnu; }
    if ($mots === null) {
        $mots = [];
        foreach (TB_ALLERGENES_MOTS as $id => $l) { foreach ($l as $m) { $mots[$m] = $id; } }
        uksort($mots, static fn ($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));   // le plus long d'abord : « noix de cajou » avant « noix »
        $liens = TB_ALLERGENES_LIENS;
        usort($liens, static fn ($a, $b) => strlen($b) <=> strlen($a));
    }
    $vus = [];
    foreach ($mots as $m => $id) {
        $n = 0;
        $t = (string) preg_replace('/(?<![a-z0-9])' . preg_quote($m, '/') . '(?![a-z0-9])/', ' ', $t, -1, $n);
        if ($n > 0) { $vus[$id] = true; }
    }
    foreach ($liens as $m) { $t = (string) preg_replace('/(?<![a-z0-9])' . preg_quote($m, '/') . '(?![a-z0-9])/', ' ', $t); }
    if (!$vus || preg_replace('/[\s,;:\/|.\-•·()\[\]&+*\'"]+/u', '', $t) !== '') { return $inconnu; }
    return ['al' => array_values(array_filter(TB_ALLERGENES, static fn ($id) => isset($vus[$id]))), 'alKnown' => true];
}

/**
 * Les allergènes d'un produit du book. `product.allergene` fait foi quand il se lit en entier (tbAllergenes). Sinon
 * l'arbre de sa recette au panel : `al` = l'union des allergènes de ses matières premières, `alKnown: true` seulement
 * si l'arbre est lu en entier et à jour, qu'il compte au moins une matière (hors emballages), que CHACUNE porte une
 * liste d'allergènes non vide et entièrement reconnue, et que la fiche ne dit rien d'autre (un texte de fiche
 * illisible garde le produit « à vérifier », et reste affiché). Sinon les allergènes connus restent dans `al` — la
 * tablette les montre « contient », le reste « à vérifier » — et `alRaw` dit ce qui manque, en une ou deux phrases.
 * `etat` (fiche, complet, partiel, aucun, sansRecette) et `vides` (matières sans allergènes saisis) servent `sources`.
 *
 * @param array $cache le cache des lectures du panel (tbCacheAllergenes) ; `$limite` : lu avant, plus à jour
 * @return array{al: list<string>, alKnown: bool, alRaw: string, etat: string, vides: list<string>}
 */
function tbAllergenesProduit(string $brut, int $rid, array $cache, int $limite): array
{
    $fiche = tbAllergenes($brut);
    if ($fiche['alKnown']) { return $fiche + ['alRaw' => $brut, 'etat' => 'fiche', 'vides' => []]; }
    if ($rid <= 0) { return ['al' => [], 'alKnown' => false, 'alRaw' => tbPhrases([$brut, 'Pas de recette au panel.']), 'etat' => 'sansRecette', 'vides' => []]; }
    $a = tbArbreAllergenes($rid, $cache, $limite);
    $manque = [];
    if ($a['absente']) { $manque[] = 'Recette introuvable au panel.'; }
    if ($a['nonLu'] || $a['perime']) { $manque[] = 'Recette du panel pas encore lue en entier.'; }
    if ($a['trop']) { $manque[] = 'Recette du panel trop imbriquée pour être lue en entier.'; }
    if ($a['matieres'] === 0 && !$manque) { $manque[] = 'Recette du panel sans matière première.'; }
    if ($a['vides']) { $manque[] = 'Allergènes non renseignés au panel pour : ' . tbListeCourte($a['vides']) . '.'; }
    if ($a['inconnus']) { $manque[] = 'Allergène du panel non reconnu : ' . tbListeCourte($a['inconnus']) . '.'; }
    $al = array_values(array_filter(TB_ALLERGENES, static fn ($id) => isset($a['codes'][$id])));
    $complet = !$manque && $brut === '';
    return ['al' => $al, 'alKnown' => $complet, 'alRaw' => $complet ? '' : tbPhrases(array_merge([$brut], $manque)),
        'etat' => $complet ? 'complet' : ($al ? 'partiel' : 'aucun'), 'vides' => $a['vides']];
}

/**
 * Ce que l'arbre d'une recette dit de ses allergènes, lu dans le cache : la recette, ses sous-recettes (chacune une
 * fois, TB_AL_PROFONDEUR niveaux au plus) et leurs matières premières (chacune une fois). Un emballage (catégorie
 * « … (Emballage) ») ne compte pas, sauf s'il porte des allergènes — `is_part_of_package` n'en est PAS un indice
 * (0 même pour eux, et il pourrait marquer une matière d'un lot). `codes` : identifiants tablette trouvés ;
 * `vides` : matières à la liste vide ; `inconnus` : allergènes hors référentiel ; `nonLu` : une recette ou une
 * matière absente du cache ; `perime` : lue il y a plus de 24 h ; `absente` : recette que le panel n'a pas ;
 * `trop` : sous-recettes trop profondes ; `matieres` : matières comptées (hors emballages).
 *
 * @return array{codes: array<string,true>, vides: list<string>, inconnus: list<string>, nonLu: bool, perime: bool,
 *               absente: bool, trop: bool, matieres: int}
 */
function tbArbreAllergenes(int $rid, array $cache, int $limite): array
{
    $a = ['codes' => [], 'vides' => [], 'inconnus' => [], 'nonLu' => false, 'perime' => false, 'absente' => false, 'trop' => false, 'matieres' => 0];
    $ref = is_array($cache['reference']['codes'] ?? null) && $cache['reference']['codes'] ? $cache['reference']['codes'] : null;
    $vues = []; $mats = [];
    $file = [['recettes', $rid, 0]];
    while ($file) {
        [$t, $id, $prof] = array_shift($file);              // en largeur : chaque sous-recette vue à son niveau le plus haut
        if (isset($vues[$t . ':' . $id])) { continue; }
        $vues[$t . ':' . $id] = true;
        if ($prof > TB_AL_PROFONDEUR) { $a['trop'] = true; continue; }
        $r = $id > 0 ? ($cache[$t][$id] ?? null) : null;
        if (!is_array($r)) { $a['nonLu'] = true; continue; }
        if ((int) ($r['le'] ?? 0) < $limite) { $a['perime'] = true; }
        if (!empty($r['absente'])) { $a['absente'] = true; continue; }
        foreach ((array) ($r['materiaux'] ?? []) as $m) {
            $mid = (int) ($m['id'] ?? 0);
            if ($mid > 0) {
                if (isset($mats[$mid])) { continue; }
                $mats[$mid] = true;
            }
            $x = $mid > 0 && is_array($cache['materiaux'][$mid] ?? null) ? $cache['materiaux'][$mid] : null;
            $emb = tbEmballage((string) ($x !== null && ($x['cat'] ?? '') !== '' ? $x['cat'] : ($m['cat'] ?? '')));
            $liste = $x !== null && is_array($x['allergenes'] ?? null) ? $x['allergenes'] : null;
            if ($liste === null || $liste === []) {
                if ($emb) { continue; }                     // un emballage sans allergène ne compte pas, lu ou non
                $a['matieres']++;
                if ($liste === null) { $a['nonLu'] = true; continue; }
                $a['vides'][] = tbTexte((string) ($x['nom'] ?? '')) ?: tbTexte((string) ($m['nom'] ?? '')) ?: 'matière #' . $mid;
            } else {
                $a['matieres'] += (int) !$emb;
                foreach ($liste as $al) {
                    $l = tbAllergenePanel(is_array($al) ? $al : [], $ref);
                    foreach ($l['ids'] as $i) { $a['codes'][$i] = true; }
                    if (!$l['ok']) { $a['inconnus'][] = (is_array($al) ? tbTexte((string) ($al['code'] ?? '')) : '') ?: '#' . (int) ($al['id'] ?? 0); }
                }
            }
            if ((int) ($x['le'] ?? 0) < $limite) { $a['perime'] = true; }
        }
        foreach ((array) ($r['sous'] ?? []) as $s) { $file[] = ['sousRecettes', (int) $s, $prof + 1]; }
    }
    $a['vides'] = array_values(array_unique($a['vides']));
    $a['inconnus'] = array_values(array_unique($a['inconnus']));
    return $a;
}

/**
 * Un allergène d'une matière du panel ({id, code}) → identifiants de la tablette. Le référentiel du panel (GET
 * /allergens, id → code) fait foi : l'id d'abord, puis le code. `ok: false` — le produit reste « à vérifier » — dès
 * que quelque chose ne colle pas : un id hors du référentiel, un code qui contredit celui de son id, un code qu'aucun
 * des 14 identifiants UE ne reprend. Les identifiants reconnus restent (la tablette les montre « contient ») : rien
 * n'est écarté en silence. Sans référentiel (jamais lu) : le code seul, par TB_ALLERGENES_PANEL.
 *
 * @param array<int,string>|null $ref id → code
 * @return array{ids: list<string>, ok: bool}
 */
function tbAllergenePanel(array $al, ?array $ref): array
{
    $id = is_numeric($al['id'] ?? null) ? (int) $al['id'] : 0;
    $code = is_scalar($al['code'] ?? null) ? strtolower(trim((string) $al['code'])) : '';
    $codes = []; $ok = true;
    if ($ref !== null && $id > 0 && isset($ref[$id])) {
        $codes[] = (string) $ref[$id];
        if ($code !== '' && $code !== (string) $ref[$id]) { $codes[] = $code; $ok = false; }
    } else {
        if ($ref !== null && $id > 0) { $ok = false; }
        if ($code === '' || ($ref !== null && !in_array($code, $ref, true))) { $ok = false; }
        if ($code !== '') { $codes[] = $code; }
    }
    $ids = [];
    foreach ($codes as $c) {
        if (isset(TB_ALLERGENES_PANEL[$c])) { $ids[] = TB_ALLERGENES_PANEL[$c]; } else { $ok = false; }
    }
    return ['ids' => array_values(array_unique($ids)), 'ok' => $ok && $ids !== []];
}

/** Une matière d'emballage, à sa catégorie (« Matières premières (Emballage) ») ? */
function tbEmballage(string $categorie): bool
{
    return preg_match('/\b(emballages?|packaging|verpakking(en)?)\b/', tbCle($categorie)) === 1;
}

/**
 * Le référentiel des allergènes du panel (GET /allergens : {"1": {id, code, name}, …}) → [id => code] ; vide pour une
 * réponse illisible.
 *
 * @return array<int,string>
 */
function tbReferenceLue(mixed $d): array
{
    $out = [];
    $l = is_array($d) ? (PanelApi::liste($d) ?: array_values(array_filter($d, 'is_array'))) : [];
    foreach ($l as $a) {
        $id = is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0;
        $code = is_scalar($a['code'] ?? null) ? strtolower(trim((string) $a['code'])) : '';
        if ($id > 0 && $code !== '') { $out[$id] = $code; }
    }
    return $out;
}

/**
 * Une recette (ou sous-recette) du panel telle que le cache la garde : son nom, ses matières premières {id, nom, cat}
 * et les id de ses sous-recettes (0 : ligne illisible, l'arbre ne sera jamais complet). `['absente' => true]` quand le
 * panel répond sans recette (GET /recipes/<id> d'un id qui n'en est pas une rend `200 []`) ; null pour une réponse
 * illisible, relue au calcul suivant.
 */
function tbRecetteLue(mixed $d, int $id): ?array
{
    if (!is_array($d)) { return null; }
    if ($d === []) { return ['absente' => true]; }
    foreach (['data', 'recipe', 'subrecipe'] as $k) { if (isset($d[$k]) && is_array($d[$k]) && !array_is_list($d[$k])) { $d = $d[$k]; } }
    if (array_is_list($d) || (isset($d['id']) && (int) $d['id'] !== $id)) { return null; }
    if (!is_array($d['materials'] ?? null) && !is_array($d['subrecipes'] ?? null)) { return null; }
    $txt = static fn ($v): string => is_scalar($v) ? tbTexte((string) $v) : '';
    $num = static function (array $l, array $cles): int {
        foreach ($cles as $k) { if (is_numeric($l[$k] ?? null)) { return (int) $l[$k]; } }
        return 0;
    };
    $mat = [];
    foreach ((array) ($d['materials'] ?? []) as $m) {
        $m = is_array($m) ? $m : [];
        $mat[] = ['id' => $num($m, ['id_material', 'material_id', 'id']), 'nom' => $txt($m['name'] ?? ''), 'cat' => $txt($m['category_name'] ?? '')];
    }
    $sous = [];
    foreach ((array) ($d['subrecipes'] ?? []) as $s) { $sous[] = $num(is_array($s) ? $s : [], ['id_subrecipe', 'subrecipe_id', 'id']); }
    return ['nom' => $txt($d['name'] ?? ''), 'materiaux' => $mat, 'sous' => $sous];
}

/**
 * Une matière première du panel (GET /materials/{id} — la liste /materials ne porte pas les allergènes) telle que le
 * cache la garde : nom, catégorie, allergènes [{id, code}] — `[]` : jamais saisis (pas « sans allergène ») ; null :
 * la réponse n'en dit rien. null pour une réponse illisible, relue au calcul suivant.
 */
function tbMatiereLue(mixed $d, int $id): ?array
{
    if (!is_array($d) || $d === []) { return null; }
    foreach (['data', 'material'] as $k) { if (isset($d[$k]) && is_array($d[$k]) && !array_is_list($d[$k])) { $d = $d[$k]; } }
    if (array_is_list($d) || (isset($d['id']) && (int) $d['id'] !== $id)) { return null; }
    $al = null;
    if (is_array($d['allergens'] ?? null)) {
        $al = [];
        foreach ($d['allergens'] as $x) {
            $x = is_array($x) ? $x : ['code' => $x];
            $al[] = ['id' => is_numeric($x['id'] ?? null) ? (int) $x['id'] : 0,
                'code' => is_scalar($x['code'] ?? null) ? strtolower(trim((string) $x['code'])) : ''];
        }
    }
    return ['nom' => is_scalar($d['name'] ?? null) ? tbTexte((string) $d['name']) : '',
        'cat' => is_scalar($d['category_name'] ?? null) ? tbTexte((string) $d['category_name']) : '', 'allergenes' => $al];
}

/** « A, B et C » ; au-delà de `$n` noms, « A, B, C et 4 autres ». */
function tbListeCourte(array $noms, int $n = 3): string
{
    $noms = array_values(array_unique(array_filter(array_map('strval', $noms), static fn ($s) => $s !== '')));
    if (count($noms) <= 1) { return $noms[0] ?? ''; }
    if (count($noms) > $n) {
        $r = count($noms) - $n;
        return implode(', ', array_slice($noms, 0, $n)) . ' et ' . $r . ' autre' . ($r > 1 ? 's' : '');
    }
    return implode(', ', array_slice($noms, 0, -1)) . ' et ' . $noms[count($noms) - 1];
}

/** Des phrases mises bout à bout, les vides écartées, chacune terminée par un point. */
function tbPhrases(array $phrases): string
{
    $out = [];
    foreach ($phrases as $p) {
        $p = tbTexte((string) $p);
        if ($p !== '') { $out[] = preg_match('/[.!?…]$/u', $p) ? $p : $p . '.'; }
    }
    return implode(' ', $out);
}

/**
 * La durée de vie en jours entiers, comme la tablette l'affiche (0 « immédiat »,
 * 1 « jour même »). Arrondie vers le BAS, à une heure près (1 444 min = 1 jour,
 * pas 2) : une DLC ne s'allonge jamais. Moins de six heures, ou inconnue → 0.
 */
function tbDlc(int $minutes): int
{
    if ($minutes < 360) { return 0; }
    return max(1, intdiv($minutes + 60, 1440));
}

/**
 * L'unité de vente : le poids quand la fiche en porte un (« 600 g », « 1,2 kg » ;
 * 0 ou 1 g ne sont pas des poids), sinon « pièce » pour un produit vendu à la
 * pièce, sinon rien.
 *
 * @return array{0:string,1:string}
 */
function tbUnite(int $poids, bool $piece): array
{
    if ($poids >= 10) {
        $t = $poids < 1000 ? $poids . ' g' : str_replace('.', ',', (string) round($poids / 1000, 2)) . ' kg';
        return [$t, $t];
    }
    return $piece ? ['pièce', 'stuk'] : ['', ''];
}

/**
 * La fiche de la base partagée complétée de celle du panel (GET /products) pour la conservation : le nom et la
 * consigne du lieu de stockage (la base n'en porte que l'id) et la réchauffe — la valeur du panel quand elle est
 * renseignée (texte non vide, nombre > 0), sinon celle de la base.
 */
function tbFicheConservation(array $f, array $panel): array
{
    foreach (['storage_name', 'storage_description', 'reheating_time_minutes', 'reheating_temperature_celsius'] as $k) {
        $v = $panel[$k] ?? null;
        if (is_numeric($v) ? (float) $v > 0 : (is_string($v) && tbTexte($v) !== '')) { $f[$k] = $v; }
    }
    return $f;
}

/**
 * La conservation, en français : le lieu de stockage et sa consigne quand la
 * fiche en porte (« Conservation : Comptoir Frigo - 1 (2°C – 4°C). »), jamais la
 * seule température (elle vaut 4 °C par défaut, pain compris), puis la
 * réchauffe (0 = pas de réchauffe).
 *
 * @return array{0:string,1:string}
 */
function tbConserver(array $f): array
{
    $vides = ['none', 'n/a', 'na', 'null', '-', 'aucun', 'aucune'];
    $nom = tbTexte(isset($f['storage_name']) ? (string) $f['storage_name'] : '');
    $desc = tbTexte(isset($f['storage_description']) ? (string) $f['storage_description'] : '');
    if (in_array(mb_strtolower($nom), $vides, true)) { $nom = ''; }
    if (in_array(mb_strtolower($desc), $vides, true)) { $desc = ''; }
    $bouts = [];
    $lieu = $nom !== '' && $desc !== '' && !str_contains(tbCle($desc), tbCle($nom))
        ? rtrim($nom, '. ') . ' (' . rtrim($desc, '. ') . ')'
        : ($desc !== '' ? $desc : $nom);
    if ($lieu !== '') { $bouts[] = 'Conservation : ' . rtrim($lieu, '. ') . '.'; }
    $min = (int) ($f['reheating_time_minutes'] ?? 0);
    $deg = (int) ($f['reheating_temperature_celsius'] ?? 0);
    if ($min > 0) { $bouts[] = 'Réchauffer ' . $min . ' min' . ($deg > 0 ? ' à ' . $deg . ' °C' : '') . '.'; }
    return [implode(' ', $bouts), ''];
}

/** « MM-JJ » valide ? */
function tbMmJj(?string $s): bool
{
    return $s !== null && preg_match('/^(\d{2})-(\d{2})$/', $s, $m) === 1
        && (int) $m[1] >= 1 && (int) $m[1] <= 12 && (int) $m[2] >= 1 && (int) $m[2] <= 31;
}

/**
 * Les mois d'une saison, du premier au dernier, en passant le Nouvel An s'il le
 * faut : 11-01 → 01-15 donne [11, 12, 1]. La tablette lit la saison « à venir »
 * sur le premier mois.
 *
 * @return list<int>
 */
function tbMoisSaison(?string $debut, ?string $fin): array
{
    if (!tbMmJj($debut) || !tbMmJj($fin)) { return []; }
    $m1 = (int) substr($debut, 0, 2); $m2 = (int) substr($fin, 0, 2);
    $n = ($m1 === $m2 && $fin < $debut) ? 11 : ($m2 - $m1 + 12) % 12;
    $out = [];
    for ($i = 0; $i <= $n; $i++) { $out[] = ($m1 - 1 + $i) % 12 + 1; }
    return $out;
}

/**
 * Les dates d'une saison en toutes lettres : « 1er au 31 décembre » /
 * « 1 t/m 31 december », « 1er novembre au 15 janvier » / « 1 november t/m 15 januari ».
 *
 * @return array{0:string,1:string}
 */
function tbDatesSaison(?string $debut, ?string $fin): array
{
    if (!tbMmJj($debut) || !tbMmJj($fin)) { return ['', '']; }
    $m1 = (int) substr($debut, 0, 2); $j1 = (int) substr($debut, 3, 2);
    $m2 = (int) substr($fin, 0, 2); $j2 = (int) substr($fin, 3, 2);
    $jf = static fn (int $j) => $j === 1 ? '1er' : (string) $j;
    $fr1 = TB_MOIS_FR[$m1 - 1]; $nl1 = TB_MOIS_NL[$m1 - 1];
    $fr2 = TB_MOIS_FR[$m2 - 1]; $nl2 = TB_MOIS_NL[$m2 - 1];
    if ($debut === $fin) { return [$jf($j1) . ' ' . $fr1, $j1 . ' ' . $nl1]; }
    if ($m1 === $m2 && $j1 < $j2) { return [$jf($j1) . ' au ' . $j2 . ' ' . $fr1, $j1 . ' t/m ' . $j2 . ' ' . $nl1]; }
    return [$jf($j1) . ' ' . $fr1 . ' au ' . $jf($j2) . ' ' . $fr2, $j1 . ' ' . $nl1 . ' t/m ' . $j2 . ' ' . $nl2];
}

/** L'illustration embarquée de la tablette qui va avec le nom d'une gamme, '' sinon. */
function tbImageSaison(string $nom): string
{
    $k = tbCle($nom);
    foreach (TB_SAISONS_IMG as $motif => $img) { if (preg_match($motif, $k)) { return $img; } }
    return '';
}

/**
 * La saison qu'un produit montre, parmi ses gammes présentes au book : celle
 * qui est ouverte, sinon celle qui ouvre le plus tôt.
 *
 * @param array<int,array> $parSaison id gamme → ligne de /production/saisons
 */
function tbSaisonProduit(array $ids, array $parSaison): ?int
{
    $c = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => isset($parSaison[$i]))));
    if (!$c) { return null; }
    $cle = static function (int $i) use ($parSaison): array {
        $f = $parSaison[$i]['fenetre'] ?? null;
        return is_array($f) ? [empty($f['ouverte']) ? 1 : 0, (int) $f['jours'], $i] : [2, 0, $i];
    };
    usort($c, static fn ($a, $b) => $cle($a) <=> $cle($b));
    return $c[0];
}

/**
 * Les `$n` meilleures ventes parmi les produits du book (à égalité, l'id le
 * plus petit) ; un produit sans vente n'est jamais une meilleure vente.
 *
 * @param array<string|int,float> $ventes
 * @param list<string>            $ids
 * @return list<string>
 */
function tbTop(array $ventes, array $ids, int $n): array
{
    $l = [];
    foreach (array_unique($ids) as $id) {
        $q = (float) ($ventes[$id] ?? 0);
        if ($q > 0) { $l[] = [$q, (string) $id]; }
    }
    usort($l, static fn ($a, $b) => ($b[0] <=> $a[0]) ?: strcmp($a[1], $b[1]));
    return array_map(static fn ($x) => $x[1], array_slice($l, 0, $n));
}

/** Ce qui manque au book, produit par produit (`total` : produits à qui il manque au moins une des quatre). */
function tbManque(array $produits): array
{
    $m = ['total' => 0, 'photos' => 0, 'nl' => 0, 'allergenes' => 0, 'descriptions' => 0];
    foreach ($produits as $p) {
        $a = [
            'photos' => ($p['img'] ?? '') === '',
            'nl' => ($p['name'][1] ?? '') === '',
            'allergenes' => empty($p['alKnown']),
            'descriptions' => ($p['desc'][0] ?? '') === '',
        ];
        foreach ($a as $k => $x) { $m[$k] += (int) $x; }
        $m['total'] += (int) in_array(true, $a, true);
    }
    return $m;
}

/** La version du book : l'empreinte de son contenu (la tablette se recharge quand elle change). */
function tbVersion(array $book): string
{
    return sha1((string) json_encode($book, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}
