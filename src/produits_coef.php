<?php
/**
 * La liste des produits d'un magasin avec leur coefficient (prix de vente HT ÷ coût de recette net), leur
 * catégorie et leur groupe — demande du 09/10/2026 (« une liste des produits avec les coefficients, couleur,
 * catégorie et sous-catégories »).
 *
 * Source : l'API du panel seulement. `/shops/{id}/products/available` porte, pour chaque produit vendu dans le
 * magasin, le prix pratiqué (`portion_price`, `portion_price_net`), la TVA, le coût de recette du jour
 * (`recipe_cost_net`, `recipe_cost_gross`), la catégorie (objet `category` : `price_coefficient`, `groups`) ;
 * `/product-categories` donne le coefficient cible de chaque catégorie (`price_coefficient`, mesuré le
 * 09/10/2026 : « 2.1500 » pour Assiette) ; `/product-category-groups` nomme les groupes (la famille : Tartes,
 * Traiteur…). Les zones de couleur sont celles de l'onglet « Recette & marge » du dashboard : rouge sous × 1,67
 * (marge brute < 40 %), orange sous × 2,5 (marge < 60 %), vert au-delà ; l'objectif du réseau est × 3,13
 * (matière 32 % du prix).
 *
 * Lecture seule ; relu à chaque appel (le panel recalcule le coût de recette à chaque édition de prix).
 */
declare(strict_types=1);

const PC_ZONES = ['ko' => 100 / 60, 'att' => 2.5];
const PC_FOOD = 32.0;

/** Première valeur numérique parmi des clés. */
function pcNum(array $x, array $cles): ?float
{
    foreach ($cles as $k) { if (isset($x[$k]) && is_numeric($x[$k])) { return (float) $x[$k]; } }
    return null;
}

/** Première valeur texte non vide parmi des clés. */
function pcTexte(array $x, array $cles): string
{
    foreach ($cles as $k) { if (!empty($x[$k]) && is_string($x[$k])) { return trim($x[$k]); } }
    return '';
}

function pcMediane(array $v): ?float
{
    $v = array_values(array_filter($v, static fn ($x) => $x !== null));
    if ($v === []) { return null; }
    sort($v); $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/**
 * La zone d'un coefficient : ko (rouge), att (orange), ok (vert), mu (sans coût ni prix), ab (recette à vérifier :
 * un coût au-dessus du prix, ou sous 5 % de celui-ci — la règle de vraisemblance du P&L, `svCoutPlausible`).
 */
function pcZone(?float $coef): string
{
    if ($coef === null) { return 'mu'; }
    if ($coef <= 1 || $coef > 20) { return 'ab'; }
    return $coef < PC_ZONES['ko'] ? 'ko' : ($coef < PC_ZONES['att'] ? 'att' : 'ok');
}

/** Un prix TTC arrondi aux 5 centimes supérieurs. */
function pcArrondi5(float $p): float
{
    return round(ceil($p * 20 - 1e-9) / 20, 2);
}

/**
 * GET /analyse/produits/coefficients?shop=4[&inactifs=1][&sonde=1] — chaque produit disponible au magasin avec
 * son prix, son coût de recette du jour, son coefficient, sa zone, sa catégorie (et la cible de la catégorie),
 * son groupe ; agrégats par catégorie et par groupe. `sonde=1` rend les premières lignes brutes du panel.
 */
function ep_analyse_produits_coefficients(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'API panel non configurée (Mon compte)']; }
    $catsBrut = PanelApi::productCategories();
    $groupesBrut = PanelApi::productCategoryGroups();
    $lignes = PanelApi::produitsDisponibles($sid);
    if ($lignes === []) { http_response_code(502); return ['error' => 'le panel ne rend aucun produit pour ce magasin', 'motif' => PanelApi::$lastError]; }
    if (!empty($_GET['sonde'])) {
        return ['nProduits' => count($lignes), 'nCategories' => count($catsBrut), 'nGroupes' => count($groupesBrut),
            'clesProduit' => array_keys($lignes[0]), 'produit' => $lignes[0], 'categorie' => $catsBrut[0] ?? null, 'groupe' => $groupesBrut[0] ?? null,
            'divisibles' => array_values(array_slice(array_filter($lignes, static fn ($l) => (int) ($l['is_divisible'] ?? 0) === 1), 0, 3))];
    }
    $groupes = [];
    foreach ($groupesBrut as $g) {
        $id = (int) (pcNum($g, ['id', 'id_group', 'group_id']) ?? 0); $n = pcTexte($g, ['base_name', 'name', 'group_name']);
        if ($id > 0 && $n !== '') { $groupes[$id] = $n; }
    }
    $cats = [];
    $catDe = static function (array $c, string $nomRepli = ''): array {
        $v = pcNum($c, ['price_coefficient']);
        return ['nom' => pcTexte($c, ['base_name', 'name']) ?: $nomRepli, 'cible' => $v !== null && $v > 0 ? round($v, 2) : null,
            'actif' => isset($c['is_active']) ? (int) $c['is_active'] : 1, 'atelier' => pcTexte($c, ['base_production_area_name', 'production_area_name']), 'groupe' => null];
    };
    foreach ($catsBrut as $c) { $id = (int) (pcNum($c, ['id', 'id_category']) ?? 0); if ($id > 0) { $cats[$id] = ['id' => $id] + $catDe($c); } }
    $inactifs = !empty($_GET['inactifs']);
    $prods = [];
    foreach ($lignes as $l) {
        $pid = (int) (pcNum($l, ['id', 'product_id', 'id_product']) ?? 0);
        if ($pid <= 0) { continue; }
        $actif = isset($l['is_active']) ? (int) $l['is_active'] : 1;
        if (!$actif && !$inactifs) { continue; }
        $co = isset($l['category']) && is_array($l['category']) ? $l['category'] : [];
        $cid = (int) (pcNum($l, ['id_category', 'category_id']) ?? 0);
        if ($cid <= 0 && $co !== []) { $cid = (int) (pcNum($co, ['id']) ?? 0); }
        if ($cid > 0 && !isset($cats[$cid])) { $cats[$cid] = ['id' => $cid] + $catDe($co, pcTexte($l, ['base_category_name', 'category_name'])); }
        if ($cid > 0 && $co !== []) {
            if ($cats[$cid]['cible'] === null) { $v = pcNum($co, ['price_coefficient']); if ($v !== null && $v > 0) { $cats[$cid]['cible'] = round($v, 2); } }
            if ($cats[$cid]['groupe'] === null && isset($co['groups']) && is_array($co['groups'])) {
                $noms = [];
                foreach ($co['groups'] as $g) {
                    $n = is_array($g) ? (pcTexte($g, ['base_name', 'name', 'group_name']) ?: ($groupes[(int) (pcNum($g, ['id', 'id_group', 'group_id']) ?? 0)] ?? '')) : (is_numeric($g) ? ($groupes[(int) $g] ?? '') : trim((string) $g));
                    if ($n !== '') { $noms[$n] = true; }
                }
                if ($noms !== []) { $cats[$cid]['groupe'] = implode(' · ', array_keys($noms)); }
            }
        }
        // Le prix de la pièce entière : un produit divisible vendu à la portion porte le prix de la portion et la
        // part qu'elle représente (portion_size) ; le coût de recette, lui, est celui de la pièce entière.
        $divisible = (int) ($l['is_divisible'] ?? 0) === 1;
        $taille = pcNum($l, ['portion_size']);
        $part = $divisible && $taille !== null && $taille > 0 && $taille <= 1 ? $taille : 1.0;
        $ttcPortion = pcNum($l, ['portion_price_gross', 'portion_price']); $htPortion = pcNum($l, ['portion_price_net']);
        $tva = pcNum($l, ['tax_value_percent', 'tax_val_perc']);
        if (($htPortion === null || $htPortion <= 0) && $ttcPortion !== null && $ttcPortion > 0) { $htPortion = $tva !== null ? $ttcPortion / (1 + $tva / 100) : null; }
        $ttc = $ttcPortion !== null && $ttcPortion > 0 ? $ttcPortion / $part : null;
        $ht = $htPortion !== null && $htPortion > 0 ? $htPortion / $part : null;
        $cout = pcNum($l, ['recipe_cost_net']); if ($cout !== null && $cout <= 0) { $cout = null; }
        $coutTtc = pcNum($l, ['recipe_cost_gross']); if ($coutTtc !== null && $coutTtc <= 0) { $coutTtc = null; }
        $coef = $ht !== null && $cout !== null ? $ht / $cout : null;
        $coefTtc = $ttc !== null && $cout !== null ? $ttc / $cout : null;
        $prods[] = ['id' => $pid, 'nom' => pcTexte($l, ['base_name', 'name', 'product_name']), 'catId' => $cid,
            'cat' => pcTexte($l, ['base_category_name', 'category_name']) ?: (string) ($cats[$cid]['nom'] ?? ''),
            'secteur' => pcTexte($l, ['sector_name']), 'vie' => pcTexte($l, ['shelf_life_category']), 'actif' => $actif, 'webshop' => (int) ($l['webshop_active'] ?? 0),
            'divisible' => $divisible ? 1 : 0, 'part' => $part, 'tva' => $tva,
            'prixTtc' => $ttc !== null ? round($ttc, 2) : null, 'prixHt' => $ht !== null ? round($ht, 4) : null, 'conseille' => pcNum($l, ['suggested_sale_price']),
            'cout' => $cout !== null ? round($cout, 4) : null, 'coutTtc' => $coutTtc !== null ? round($coutTtc, 4) : null,
            'coef' => $coef !== null ? round($coef, 2) : null, 'coefTtc' => $coefTtc !== null ? round($coefTtc, 2) : null,
            'margePct' => $coef !== null && $coef > 0 ? round(100 * (1 - 1 / $coef), 1) : null, 'foodPct' => $coef !== null && $coef > 0 ? round(100 / $coef, 1) : null,
            'margeHt' => $ht !== null && $cout !== null ? round($ht - $cout, 2) : null, 'zone' => pcZone($coef),
            'recette' => (int) (pcNum($l, ['id_recipe']) ?? 0) ?: null, 'stock' => pcNum($l, ['in_stock'])];
    }
    foreach ($prods as &$p) {
        $c = $cats[$p['catId']] ?? null;
        $p['groupe'] = (string) ($c['groupe'] ?? '') !== '' ? (string) $c['groupe'] : $p['cat'];
        $p['atelier'] = (string) ($c['atelier'] ?? '');
        $p['cible'] = $c['cible'] ?? null;
        $p['ecartCible'] = $p['coef'] !== null && $p['cible'] !== null ? round($p['coef'] - $p['cible'], 2) : null;
        // Le prix TTC de la pièce qui atteindrait la cible de sa catégorie (coefficient sur le prix HT), aux 5 centimes.
        $p['prixCible'] = $p['cout'] !== null && $p['cible'] !== null ? pcArrondi5($p['cout'] * $p['cible'] * (1 + ($p['tva'] ?? 0) / 100)) : null;
        $p['prixObjectif'] = $p['cout'] !== null ? pcArrondi5($p['cout'] * 100 / PC_FOOD * (1 + ($p['tva'] ?? 0) / 100)) : null;
    }
    unset($p);
    usort($prods, static fn ($a, $b) => [$a['groupe'], $a['cat'], $a['nom']] <=> [$b['groupe'], $b['cat'], $b['nom']]);
    $agg = static function (array $liste, string $cle): array {
        $g = [];
        foreach ($liste as $p) { $g[$p[$cle]][] = $p; }
        $out = [];
        foreach ($g as $k => $ps) {
            $coefs = array_column(array_filter($ps, static fn ($p) => $p['coef'] !== null && $p['zone'] !== 'ab'), 'coef');
            $zones = ['ok' => 0, 'att' => 0, 'ko' => 0, 'mu' => 0, 'ab' => 0];
            foreach ($ps as $p) { $zones[$p['zone']]++; }
            $out[] = ['nom' => (string) $k, 'n' => count($ps), 'nChiffres' => count($coefs), 'coefMedian' => pcMediane($coefs) !== null ? round(pcMediane($coefs), 2) : null,
                'coefMin' => $coefs !== [] ? min($coefs) : null, 'coefMax' => $coefs !== [] ? max($coefs) : null, 'zones' => $zones];
        }
        return $out;
    };
    $parCat = [];
    foreach ($agg($prods, 'cat') as $a) {
        $c = null; foreach ($cats as $x) { if ($x['nom'] === $a['nom']) { $c = $x; break; } }
        $a['id'] = $c['id'] ?? null; $a['groupe'] = (string) ($c['groupe'] ?? '') !== '' ? (string) $c['groupe'] : $a['nom']; $a['cible'] = $c['cible'] ?? null; $a['atelier'] = (string) ($c['atelier'] ?? '');
        $parCat[] = $a;
    }
    usort($parCat, static fn ($a, $b) => [$a['groupe'], $a['nom']] <=> [$b['groupe'], $b['nom']]);
    $nom = 'Magasin ' . $sid;
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$sid]); if ($r !== null) { $nom = (string) $r['name']; } } catch (Throwable $e) { /* sans nom */ }
    $coefs = array_column(array_filter($prods, static fn ($p) => $p['coef'] !== null && $p['zone'] !== 'ab'), 'coef');
    $zones = ['ok' => 0, 'att' => 0, 'ko' => 0, 'mu' => 0, 'ab' => 0];
    foreach ($prods as $p) { $zones[$p['zone']]++; }
    return ['shop' => (string) $sid, 'magasin' => $nom, 'court' => preg_replace('/^.* - /', '', $nom), 'n' => count($prods), 'nChiffres' => count($coefs),
        'coefMedian' => pcMediane($coefs) !== null ? round(pcMediane($coefs), 2) : null, 'zones' => $zones,
        'seuils' => ['ko' => round(PC_ZONES['ko'], 2), 'att' => PC_ZONES['att'], 'objectif' => round(100 / PC_FOOD, 2), 'food' => PC_FOOD],
        'produits' => $prods, 'categories' => $parCat, 'groupes' => $agg($prods, 'groupe'), 'lu' => date('c'),
        'source' => 'API du panel : /shops/{id}/products/available (prix pratiqué, TVA, coût de recette du jour, catégorie), /product-categories (coefficient cible), /product-category-groups ; coefficient = prix HT de la pièce ÷ coût de recette net'];
}
