<?php
declare(strict_types=1);

/*
 * LA RECETTE D'UN PRODUIT — l'onglet « Recette & marge » de la modale produit du dashboard
 * magasin (demande du 08/10/2026).
 *
 * GET /analyse/produits/recette?pid=2300010&shop=4[&rafraichir=1][&colonnes=1] rend la recette
 * du produit telle que la copie locale du panel la porte, sans appel au panel :
 *   product.id_recipe → product_recipe (nom, rendement, unité, is_subrecipe)
 *   → product_recipe_material_connection (parent_recipe_id, child_ingredient_id | child_recipe_id,
 *     quantity — dans l'unité de base de la matière, l'unité de la recette pour une sous-recette)
 *   → material (nom, id_category → material_category, id_unit → unit : name, smaller_unit_name,
 *     conversion_factor)
 *   → shop_material_price_list (base_unit_price_net par matière et magasin, le plus récent ;
 *     à défaut celui d'un autre magasin)
 *   → recipe_cost (calculated_cost_net par recette et magasin, le plus récent ; sinon id_shop 0).
 * Mesuré le 08/10/2026 sur la copie : ce sont les seuls prix de matière qu'elle porte ; une
 * matière sans prix garde sa quantité et sa ligne reste sans coût (`complet` le dit).
 *
 * Les colonnes ne sont pas supposées : chaque table est lue par information_schema et l'on
 * prend celles qui existent (plusieurs noms candidats par rôle) ; `colonnes` dit ce qui a été
 * retenu, `&colonnes=1` ajoute les listes complètes et les lignes brutes pour cartographier.
 * Le résultat est gardé 24 h par produit et magasin (ceo_app_setting `recetteProduit:<pid>:<shop>`).
 */

const RP_HEURES = 24;
const RP_PROFONDEUR = 4;

/** Les colonnes d'une table de la copie, en minuscules ; [] si la table manque. Mémorisé le temps de la requête. */
function rpColonnes(string $table): array
{
    static $memo = [];
    if ($table === '') { $memo = []; return []; }   // remise à zéro (harnais)
    if (!isset($memo[$table])) {
        try {
            $memo[$table] = array_map(static fn ($r) => strtolower((string) $r['COLUMN_NAME']),
                Db::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]));
        } catch (Throwable $e) { $memo[$table] = []; }
    }
    return $memo[$table];
}

/** La première colonne candidate qui existe, null sinon. */
function rpChoix(array $cols, array $cands): ?string
{
    foreach ($cands as $c) { if (in_array($c, $cols, true)) { return $c; } }
    return null;
}

/** Les tables de la copie dont le nom contient un motif. */
function rpTables(string $motif): array
{
    try {
        return array_map(static fn ($r) => strtolower((string) $r['TABLE_NAME']),
            Db::rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', ['%' . $motif . '%']));
    } catch (Throwable $e) { return []; }
}

/** « kg », « g », « pce »… à partir d'un nom d'unité du panel ; '' si inconnu. */
function rpUniteCourte(string $u): string
{
    $u = strtolower(trim($u));
    return match ($u) {
        '' => '',
        'gr', 'gram', 'gramme', 'grammes', 'g' => 'g',
        'kilo', 'kilogram', 'kilogramme', 'kilogrammes', 'kg' => 'kg',
        'liter', 'litre', 'litres', 'l' => 'l',
        'milliliter', 'millilitre', 'ml' => 'ml', 'cl', 'centilitre' => 'cl',
        'pc', 'pcs', 'piece', 'pièce', 'pièces', 'pieces', 'unit', 'unité', 'stuk', 'stuks', 'st', 'pce' => 'pce',
        default => $u,
    };
}

/** Ce que la copie locale sait des tables de recette : les tables, les colonnes retenues, les unités, les catégories. */
function rpContexte(): array
{
    $conn = rpColonnes('product_recipe_material_connection');
    $mat = rpColonnes('material');
    $rec = rpColonnes('product_recipe');
    $cout = rpColonnes('recipe_cost');
    $prix = rpColonnes('shop_material_price_list');
    $ctx = [
        'conn' => ['table' => $conn !== [] ? 'product_recipe_material_connection' : null,
            'recette' => rpChoix($conn, ['parent_recipe_id', 'id_product_recipe', 'id_recipe', 'recipe_id', 'product_recipe_id']),
            'matiere' => rpChoix($conn, ['child_ingredient_id', 'id_material', 'material_id', 'id_ingredient', 'ingredient_id']),
            'sous' => rpChoix($conn, ['child_recipe_id', 'id_sub_recipe', 'id_subrecipe', 'id_child_recipe', 'sub_recipe_id']),
            'qte' => rpChoix($conn, ['quantity', 'qty', 'amount', 'quantity_net']),
            'unite' => rpChoix($conn, ['id_unit', 'unit_id', 'unit'])],
        'mat' => ['table' => $mat !== [] ? 'material' : null,
            'nom' => rpChoix($mat, ['name', 'label']), 'unite' => rpChoix($mat, ['id_unit', 'unit_id', 'unit']),
            'cat' => rpChoix($mat, ['id_category', 'id_material_category', 'category_name']),
            'perte' => rpChoix($mat, ['waste_amount_perc', 'waste_perc'])],
        'rec' => ['table' => $rec !== [] ? 'product_recipe' : null,
            'nom' => rpChoix($rec, ['name', 'label']), 'rendement' => rpChoix($rec, ['yield_quantity', 'yield', 'portions']),
            'unite' => rpChoix($rec, ['id_unit', 'unit_id']), 'sousFlag' => rpChoix($rec, ['is_subrecipe'])],
        'cout' => ['table' => $cout !== [] ? 'recipe_cost' : null,
            'recette' => rpChoix($cout, ['id_recipe', 'recipe_id']), 'shop' => rpChoix($cout, ['id_shop', 'shop_id']),
            'cout' => rpChoix($cout, ['calculated_cost_net', 'cost_net', 'net_cost', 'calculated_cost', 'cost']),
            'type' => rpChoix($cout, ['price_type']),
            'date' => rpChoix($cout, ['calculated_at', 'calculation_date', 'created_at', 'updated_at', 'id'])],
        'prix' => ['table' => $prix !== [] ? 'shop_material_price_list' : null,
            'matiere' => rpChoix($prix, ['id_material', 'material_id']), 'shop' => rpChoix($prix, ['id_shop', 'shop_id']),
            'prix' => rpChoix($prix, ['base_unit_price_net', 'price_net', 'unit_price', 'price']),
            'date' => rpChoix($prix, ['created_at', 'updated_at', 'valid_from', 'id'])],
        'unites' => [], 'categories' => [],
    ];
    // Les unités : nom, petite unité et facteur (kg → g × 1000).
    $u = rpColonnes('unit');
    if ($u !== []) {
        $n = rpChoix($u, ['name', 'abbreviation', 'label']); $p = rpChoix($u, ['smaller_unit_name']); $f = rpChoix($u, ['conversion_factor']);
        try {
            foreach (Db::rows('SELECT * FROM `unit`') as $r) {
                $ctx['unites'][(int) $r['id']] = ['nom' => rpUniteCourte((string) ($n !== null ? $r[$n] : '')),
                    'petite' => rpUniteCourte((string) ($p !== null ? ($r[$p] ?? '') : '')), 'facteur' => $f !== null && is_numeric($r[$f] ?? null) && (float) $r[$f] > 0 ? (float) $r[$f] : 1.0];
            }
        } catch (Throwable $e) { /* sans unités */ }
    }
    $mc = rpColonnes('material_category');
    if ($mc !== [] && in_array('name', $mc, true)) {
        try { foreach (Db::rows('SELECT `id`, `name` FROM `material_category`') as $r) { $ctx['categories'][(int) $r['id']] = (string) $r['name']; } }
        catch (Throwable $e) { /* sans catégories */ }
    }
    return $ctx;
}

/** Une recette : nom, rendement, unité ; null si absente. */
function rpRecette(int $rid, array $ctx): ?array
{
    $R = $ctx['rec'];
    if ($R['table'] === null || $rid <= 0) { return null; }
    try { $r = Db::row('SELECT * FROM `' . $R['table'] . '` WHERE `id` = ?', [$rid]); } catch (Throwable $e) { return null; }
    if ($r === null) { return null; }
    $rend = $R['rendement'] !== null && is_numeric($r[$R['rendement']] ?? null) && (float) $r[$R['rendement']] > 0 ? (float) $r[$R['rendement']] : 1.0;
    $un = $R['unite'] !== null ? ($ctx['unites'][(int) ($r[$R['unite']] ?? 0)]['nom'] ?? '') : '';
    return ['id' => $rid, 'nom' => $R['nom'] !== null ? (string) ($r[$R['nom']] ?? '') : '', 'rendement' => $rend, 'unite' => $un,
        'sousRecette' => $R['sousFlag'] !== null ? (bool) ($r[$R['sousFlag']] ?? false) : null];
}

/** Les matières d'une liste d'id : nom, catégorie, unité de base, perte. */
function rpMatieres(array $ids, array $ctx): array
{
    $M = $ctx['mat']; $out = [];
    if ($M['table'] === null || $ids === []) { return $out; }
    $ph = implode(', ', array_fill(0, count($ids), '?'));
    try { $rows = Db::rows('SELECT * FROM `' . $M['table'] . '` WHERE `id` IN (' . $ph . ')', $ids); } catch (Throwable $e) { return $out; }
    foreach ($rows as $r) {
        $uid = $M['unite'] !== null ? (int) ($r[$M['unite']] ?? 0) : 0;
        $cid = $M['cat'] !== null ? $r[$M['cat']] ?? null : null;
        $out[(int) $r['id']] = ['nom' => $M['nom'] !== null ? (string) ($r[$M['nom']] ?? '') : 'matière ' . $r['id'],
            'cat' => is_numeric($cid) ? ($ctx['categories'][(int) $cid] ?? '') : (string) ($cid ?? ''),
            'unite' => $ctx['unites'][$uid] ?? ['nom' => '', 'petite' => '', 'facteur' => 1.0],
            'perte' => $M['perte'] !== null && is_numeric($r[$M['perte']] ?? null) ? (float) $r[$M['perte']] : 0.0];
    }
    return $out;
}

/** Le prix de base des matières : celui du magasin le plus récent, sinon le plus récent d'un autre magasin. */
function rpPrix(array $ids, int $sid, array $ctx): array
{
    $P = $ctx['prix']; $out = [];
    if ($P['table'] === null || $P['matiere'] === null || $P['prix'] === null || $ids === []) { return $out; }
    $ph = implode(', ', array_fill(0, count($ids), '?'));
    try {
        $rows = Db::rows('SELECT `' . $P['matiere'] . '` AS m, ' . ($P['shop'] !== null ? '`' . $P['shop'] . '` AS s, ' : '0 AS s, ') . '`' . $P['prix'] . '` AS p FROM `' . $P['table'] . '` WHERE `' . $P['matiere'] . '` IN (' . $ph . ')'
            . ($P['date'] !== null ? ' ORDER BY `' . $P['date'] . '` DESC' : ''), $ids);
    } catch (Throwable $e) { return $out; }
    $vus = [];
    foreach ($rows as $r) {
        if (!is_numeric($r['p']) || (float) $r['p'] <= 0) { continue; }
        $m = (int) $r['m']; $s = (int) $r['s'];
        $k = $m . ':' . $s;
        if (isset($vus[$k])) { continue; }   // le plus récent de chaque couple matière × magasin
        $vus[$k] = true;
        if ($s === $sid) { $out[$m] = ['prix' => (float) $r['p'], 'source' => 'magasin']; }
        elseif (!isset($out[$m])) { $out[$m] = ['prix' => (float) $r['p'], 'source' => $s === 0 ? 'réseau' : 'autre magasin']; }
    }
    return $out;
}

/** Le coût de recette gravé : celui du magasin le plus récent, sinon du réseau (id_shop 0), sinon la moyenne des magasins. */
function rpCoutRecette(int $rid, int $sid, array $ctx, ?array &$brut = null): ?array
{
    $K = $ctx['cout'];
    if ($K['table'] === null || $K['cout'] === null || $K['recette'] === null) { return null; }
    try {
        $rows = Db::rows('SELECT * FROM `' . $K['table'] . '` WHERE `' . $K['recette'] . '` = ?' . ($K['date'] !== null ? ' ORDER BY `' . $K['date'] . '` DESC' : ''), [$rid]);
    } catch (Throwable $e) { return null; }
    if ($brut !== null) { $brut = array_slice($rows, 0, 12); }
    $mag = null; $res = null; $autres = []; $vus = []; $type = null;
    foreach ($rows as $r) {
        $c = $r[$K['cout']] ?? null;
        if (!is_numeric($c) || (float) $c <= 0) { continue; }
        $s = $K['shop'] !== null ? (int) ($r[$K['shop']] ?? 0) : 0;
        if (isset($vus[$s])) { continue; }   // le premier de chaque magasin = le plus récent
        $vus[$s] = true;
        if ($s === $sid) { $mag = (float) $c; $type = $K['type'] !== null ? (string) ($r[$K['type']] ?? '') : null; }
        elseif ($s === 0) { $res = (float) $c; }
        else { $autres[] = (float) $c; }
    }
    if ($mag !== null) { return ['net' => $mag, 'source' => 'recette du magasin (copie locale)', 'type' => $type]; }
    if ($res !== null) { return ['net' => $res, 'source' => 'recette réseau (copie locale)']; }
    if ($autres !== []) { return ['net' => array_sum($autres) / count($autres), 'source' => 'moyenne des magasins (copie locale)']; }
    return null;
}

/**
 * Les lignes d'une recette, sous-recettes dépliées (profondeur bornée, chaque recette une fois).
 * `$facteur` : la part de la recette qui entre dans la pièce (quantité demandée ÷ rendement).
 */
function rpLignes(int $rid, float $facteur, int $prof, ?string $sous, int $sid, array $ctx, array &$vus): array
{
    if ($rid <= 0 || $prof > RP_PROFONDEUR || isset($vus[$rid])) { return []; }
    $vus[$rid] = true;
    $C = $ctx['conn'];
    if ($C['table'] === null || $C['recette'] === null || $C['qte'] === null) { return []; }
    try { $rows = Db::rows('SELECT * FROM `' . $C['table'] . '` WHERE `' . $C['recette'] . '` = ?', [$rid]); } catch (Throwable $e) { return []; }
    $ids = [];
    foreach ($rows as $r) { $m = $C['matiere'] !== null ? (int) ($r[$C['matiere']] ?? 0) : 0; if ($m > 0) { $ids[] = $m; } }
    $mats = rpMatieres(array_values(array_unique($ids)), $ctx);
    $prix = rpPrix(array_keys($mats), $sid, $ctx);
    $out = [];
    foreach ($rows as $r) {
        $qte = is_numeric($r[$C['qte']] ?? null) ? (float) $r[$C['qte']] : null;
        $srid = $C['sous'] !== null ? (int) ($r[$C['sous']] ?? 0) : 0;
        $mid = $C['matiere'] !== null ? (int) ($r[$C['matiere']] ?? 0) : 0;
        if ($srid > 0) {
            // Une sous-recette : ses lignes au prorata de la quantité demandée sur son rendement.
            $sr = rpRecette($srid, $ctx);
            $rend = $sr !== null && $sr['rendement'] > 0 ? $sr['rendement'] : 1.0;
            $part = ($qte !== null ? $qte / $rend : 1.0) * $facteur;
            $nom = $sr['nom'] ?? ('sous-recette ' . $srid);
            $lignes = rpLignes($srid, $part, $prof + 1, $nom, $sid, $ctx, $vus);
            if ($lignes === []) {
                $cout = rpCoutRecette($srid, $sid, $ctx);
                $out[] = ['nom' => $nom, 'cat' => 'sous-recette', 'qte' => $qte !== null ? round($qte * $facteur, 3) : null, 'unite' => $sr['unite'] ?? '',
                    'prixUnite' => null, 'cout' => $cout !== null ? round($cout['net'] * $part, 4) : null, 'sous' => $sous, 'type' => 'recette', 'id' => $srid];
            } else { foreach ($lignes as $l) { $out[] = $l; } }
            continue;
        }
        if ($mid <= 0) { continue; }
        $m = $mats[$mid] ?? ['nom' => 'matière ' . $mid, 'cat' => '', 'unite' => ['nom' => '', 'petite' => '', 'facteur' => 1.0], 'perte' => 0.0];
        $p = $prix[$mid] ?? null;
        $q = $qte !== null ? $qte * $facteur : null;
        $out[] = ['nom' => $m['nom'], 'cat' => $m['cat'], 'qte' => $q !== null ? round($q, 4) : null, 'unite' => $m['unite']['nom'], 'petite' => $m['unite']['petite'], 'facteur' => $m['unite']['facteur'],
            'prixUnite' => $p['prix'] ?? null, 'prixSource' => $p['source'] ?? null, 'prixParUnite' => $m['unite']['nom'],
            'cout' => $q !== null && $p !== null ? round($q * $p['prix'], 4) : null, 'motif' => $p === null ? 'pas de prix pour cette matière dans la copie locale' : null,
            'sous' => $sous, 'type' => 'matiere', 'id' => $mid];
    }
    return $out;
}

/**
 * GET /analyse/produits/recette?pid=2300010&shop=4[&rafraichir=1][&colonnes=1]
 */
function ep_analyse_produit_recette(): array
{
    $pid = (int) ($_GET['pid'] ?? 0); $sid = (int) ($_GET['shop'] ?? 0);
    if ($pid <= 0 || $sid <= 0) { http_response_code(400); return ['error' => 'pid et shop requis']; }
    // L'API du panel d'abord (08/10/2026, « api only ») : le coût de la recette calculé par le panel pour ce magasin, aux
    // prix du jour, relu passée la minute. La copie locale ne sert plus que si l'API ne répond pas.
    if (empty($_GET['colonnes'])) {
        $api = rpApi($pid, $sid, !empty($_GET['rafraichir']));
        if ($api !== null) { $api['cache'] = ['le' => date('c'), 'age' => 0]; return $api; }
    }
    $cle = 'recetteProduit:' . $pid . ':' . $sid;
    if (!empty($_GET['colonnes'])) {
        // Diagnostic, lecture seule : les colonnes de chaque table lue, les lignes brutes du produit, les coûts bruts.
        $out = rpCalcul($pid, $sid, true);
        $listes = [];
        foreach (['product_recipe_material_connection', 'material', 'product_recipe', 'recipe_cost', 'unit', 'material_category', 'shop_material_price_list', 'product'] as $t) { $listes[$t] = rpColonnes($t); }
        $out['colonnes']['listes'] = $listes;
        return $out;
    }
    if (empty($_GET['rafraichir'])) {
        try { $c = setting($cle); } catch (Throwable $e) { $c = null; }
        if (is_array($c) && isset($c['le'], $c['r']) && is_array($c['r']) && time() - (int) $c['le'] < RP_HEURES * 3600) {
            $r = $c['r']; $r['cache'] = ['le' => date('c', (int) $c['le']), 'age' => time() - (int) $c['le']];
            return $r;
        }
    }
    $indice = is_numeric($_GET['cout'] ?? null) && (float) $_GET['cout'] > 0 ? (float) $_GET['cout'] : null;
    $out = rpCalcul($pid, $sid, false, $indice);
    if (empty($out['error'])) {
        try {
            $j = json_encode(['le' => time(), 'r' => $out], JSON_UNESCAPED_UNICODE);
            if ($j !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
        } catch (Throwable $e) { /* sans cache */ }
    }
    $out['cache'] = ['le' => date('c'), 'age' => 0];
    $out['source'] = 'copie locale du panel, l’API du panel n’ayant pas répondu : ' . ($out['source'] ?? '');
    return $out;
}

/**
 * La recette par l'API du panel : `/products/{pid}` donne la recette (gardé 24 h), `/shops/{sid}/recipes/{rid}/cost` le
 * coût calculé par le panel pour ce magasin, sous-recettes dépliées et quantités déjà ramenées à la recette (relu passée
 * la minute, MF_SEC_PRIX). Une matière sans prix dans ce magasin (`price_source` MISSING) est prise au prix médian des
 * autres magasins (`/shops/{id}/materials`), comme le faisait la copie ; elle est dite « autre magasin ».
 * Rend null quand l'API ne répond pas (le compte n'est pas configuré, la route échoue) : la copie prend le relais.
 */
function rpApi(int $pid, int $sid, bool $frais): ?array
{
    if (!PanelApi::configured()) { return null; }
    $cleP = 'recetteDuProduit:' . $pid;
    $c = mfCache($cleP, $frais);
    $rid = isset($c['rid']) ? (int) $c['rid'] : null; $nomP = (string) ($c['nom'] ?? '');
    if ($rid === null) {
        $p = PanelApi::get('/products/' . $pid);
        if (!is_array($p) || !isset($p['id'])) { return null; }
        $rid = (int) ($p['id_recipe'] ?? 0); $nomP = trim((string) ($p['name'] ?? ''));
        mfGarder($cleP, ['rid' => $rid, 'nom' => $nomP]);
    }
    $src = 'API du panel : /shops/{magasin}/recipes/{recette}/cost (le coût de la recette calculé par le panel pour ce magasin, sous-recettes dépliées, aux prix du jour) ; une matière sans prix dans ce magasin est prise au prix médian des autres magasins (/shops/{id}/materials) ; relu passée la minute';
    if ($rid <= 0) { return ['pid' => $pid, 'nom' => $nomP, 'sansRecette' => true, 'motif' => 'le produit n’a pas de recette au panel', 'lignes' => [], 'nLignes' => 0, 'source' => $src, 'api' => true]; }
    $cleC = 'recetteCout:' . $rid . ':' . $sid;
    $cout = mfCache($cleC, $frais, MF_SEC_PRIX);
    if ($cout === null) {
        $cout = PanelApi::get('/shops/' . $sid . '/recipes/' . $rid . '/cost');
        if (!is_array($cout) || !isset($cout['elements']) || !is_array($cout['elements'])) { return null; }
        mfGarder($cleC, $cout);
    }
    // Les prix des autres magasins, pour les matières sans prix ici ; la catégorie des matières par la même lecture.
    $noms = mfMagasins(); $sids = array_keys($noms); if (!in_array($sid, $sids, true)) { $sids[] = $sid; } sort($sids);
    $luLe = []; $parMagasin = mfMatieresApi($sids, $frais, $sid, $luLe);
    $autre = static function (int $mid) use ($parMagasin, $sid): ?float {
        $v = [];
        foreach ($parMagasin as $s => $m) { if ($s === $sid) { continue; } $p = $m[(string) $mid]['prix'] ?? null; if ($p !== null && $p > 0) { $v[] = $p; } }
        return $v === [] ? null : mfMediane($v);
    };
    $rend = mfNombre($cout['yield_quantity'] ?? null); $rend = $rend !== null && $rend > 0 ? $rend : 1.0; $fac = 1.0 / $rend;
    $lignes = [];
    $marche = static function (array $els, ?string $sous, int $prof) use (&$marche, &$lignes, $autre, $parMagasin, $sid, $fac): void {
        foreach ($els as $e) {
            if (!is_array($e)) { continue; }
            $type = strtolower((string) ($e['type'] ?? 'ingredient'));
            if ($type === 'sub-recipe' || $type === 'subrecipe') {
                if ($prof < RP_PROFONDEUR) { $n = trim((string) ($e['name'] ?? '')); $marche((array) ($e['elements'] ?? []), $n !== '' ? $n : $sous, $prof + 1); }
                continue;
            }
            $mid = (int) ($e['material_id'] ?? $e['ingredient_id'] ?? 0);
            $q = mfNombre($e['required_quantity'] ?? $e['quantity_cost'] ?? $e['recipe_quantity'] ?? null); $q = $q !== null ? $q * $fac : null;
            $u = rpUniteCourte((string) ($e['unit_name'] ?? ''));
            $prix = mfNombre($e['price_net'] ?? null); $ps = strtoupper((string) ($e['price_source'] ?? '')); $c2 = mfNombre($e['calculated_req_price_net'] ?? null); $motif = null; $srcTxt = null;
            if ($prix !== null && $prix > 0) {
                $srcTxt = $ps === 'LOCAL' || $ps === '' ? 'magasin' : ($ps === 'REFERENCE' ? 'référence' : ($ps === 'SUPPLIER' ? 'fournisseur' : strtolower($ps)));
                $c2 = $c2 !== null ? $c2 * $fac : ($q !== null ? $q * $prix : null);
            } else {
                $a = $autre($mid);
                if ($a !== null) { $prix = $a; $srcTxt = 'autre magasin'; $c2 = $q !== null ? $q * $prix : null; }
                else { $prix = null; $c2 = null; $motif = 'pas de prix pour cette matière, dans aucun magasin'; }
            }
            $f = trim((string) ($e['supplier_name'] ?? ''));
            $lignes[] = ['nom' => trim((string) ($e['name'] ?? ('matière ' . $mid))), 'cat' => (string) ($parMagasin[$sid][(string) $mid]['cat'] ?? ''), 'qte' => $q !== null ? round($q, 4) : null, 'unite' => $u,
                'prixUnite' => $prix !== null ? round($prix, 6) : null, 'prixSource' => $srcTxt, 'prixParUnite' => $u, 'cout' => $c2 !== null ? round($c2, 4) : null, 'motif' => $motif, 'sous' => $sous,
                'type' => 'matiere', 'id' => $mid, 'part' => null, 'perte' => mfNombre($e['waste_percent'] ?? $e['waste_amount_perc'] ?? null),
                'fournisseur' => $f !== '' ? ['nom' => $f, 'colis' => mfNombre($e['supplier_price_net'] ?? null), 'taille' => mfNombre($e['supplier_package_size'] ?? null), 'unite' => (string) ($e['supplier_package_unit'] ?? '')] : null];
        }
    };
    $marche($cout['elements'], null, 0);
    usort($lignes, static fn ($a, $b) => (($b['cout'] ?? -1) <=> ($a['cout'] ?? -1)) ?: strcmp($a['nom'], $b['nom']));
    $total = 0.0; $sansPrix = 0;
    foreach ($lignes as $l) { if ($l['cout'] !== null) { $total += $l['cout']; } else { $sansPrix++; } }
    foreach ($lignes as &$l) { $l['part'] = $total > 0 && $l['cout'] !== null ? round(100 * $l['cout'] / $total, 1) : null; }
    unset($l);
    $net = mfNombre($cout['cost_net'] ?? null);
    return ['pid' => $pid, 'nom' => $nomP !== '' ? $nomP : trim((string) ($cout['name'] ?? '')),
        'recette' => ['id' => $rid, 'nom' => trim((string) ($cout['name'] ?? '')), 'rendement' => $rend, 'unite' => rpUniteCourte((string) ($cout['unit_name'] ?? '')), 'sousRecette' => (int) ($cout['is_subrecipe'] ?? 0) === 1],
        'cout' => ['net' => $net !== null ? round($net * $fac, 4) : null, 'source' => 'calcul du panel pour ce magasin, ' . (($cout['cost_complete'] ?? false) ? 'complet' : 'partiel : des matières sans prix ici'), 'type' => (string) ($cout['cost_status'] ?? ''),
            'complet' => (bool) ($cout['cost_complete'] ?? false), 'manquants' => array_values(array_map(static fn ($m) => trim((string) (is_array($m) ? ($m['name'] ?? '') : $m)), (array) ($cout['missing_materials'] ?? [])))],
        'lignes' => $lignes, 'total' => round($total, 4), 'complet' => $lignes !== [] && $sansPrix === 0, 'sansPrix' => $sansPrix, 'nLignes' => count($lignes), 'hypothese' => 'api', 'reference' => null,
        'source' => $src, 'lu' => ['le' => date('c'), 'secondesPrix' => MF_SEC_PRIX], 'api' => true];
}

/** Le calcul lui-même, sans cache. `$brut` : joindre les lignes brutes (diagnostic) ; `$indice` : le coût de la pièce
 *  gravé avec les tickets, passé par le dashboard, qui guide le choix d'unité avant le coût de la copie. */
function rpCalcul(int $pid, int $sid, bool $brut = false, ?float $indice = null): array
{
    $ctx = rpContexte();
    $diag = ['tables' => array_values(array_filter(array_map(static fn ($k) => $ctx[$k]['table'], ['conn', 'mat', 'rec', 'cout', 'prix']))),
        'liaison' => $ctx['conn'], 'matiere' => $ctx['mat'], 'recette' => $ctx['rec'], 'cout' => $ctx['cout'], 'prix' => $ctx['prix'],
        'unites' => count($ctx['unites']), 'categories' => count($ctx['categories'])];
    try { $p = Db::row('SELECT id, name, id_recipe FROM product WHERE id = ?', [$pid]); }
    catch (Throwable $e) { return ['pid' => $pid, 'indispo' => true, 'motif' => 'copie locale du catalogue indisponible', 'colonnes' => $diag]; }
    if ($p === null) { return ['pid' => $pid, 'indispo' => true, 'motif' => 'produit inconnu de la copie locale', 'colonnes' => $diag]; }
    $nom = (string) ($p['name'] ?? '');
    $rid = $p['id_recipe'] !== null ? (int) $p['id_recipe'] : 0;
    if ($rid <= 0) { return ['pid' => $pid, 'nom' => $nom, 'sansRecette' => true, 'motif' => 'aucune recette rattachée à ce produit dans la copie locale', 'colonnes' => $diag]; }
    if ($ctx['conn']['table'] === null || $ctx['rec']['table'] === null) {
        return ['pid' => $pid, 'nom' => $nom, 'indispo' => true, 'motif' => 'la copie locale ne porte pas les lignes de recette (product_recipe_material_connection)', 'colonnes' => $diag];
    }
    $rec = rpRecette($rid, $ctx) ?? ['id' => $rid, 'nom' => '', 'rendement' => 1.0, 'unite' => '', 'sousRecette' => null];
    $vus = [];
    $facteur = 1.0 / max(0.000001, $rec['rendement']);
    $lignes = rpLignes($rid, $facteur, 0, null, $sid, $ctx, $vus);
    $couts = $brut ? [] : null;
    $cout = rpCoutRecette($rid, $sid, $ctx, $couts);
    if ($cout !== null && $rec['rendement'] > 0) { $cout['net'] = round($cout['net'] * $facteur, 4); }
    // Les quantités sont-elles dans l'unité de base (kg) ou dans la petite unité (g) ? Quand la copie porte un coût
    // de recette, l'hypothèse dont le total s'en approche le plus gagne ; sans coût, l'unité de base.
    $t1 = 0.0; $t2 = 0.0; $n = 0;
    foreach ($lignes as $l) { if ($l['cout'] !== null && $l['type'] === 'matiere') { $t1 += $l['cout']; $t2 += $l['cout'] / max(1.0, (float) ($l['facteur'] ?? 1.0)); $n++; } }
    $hyp = 'base';
    $ref = $indice ?? ($cout !== null && $cout['net'] > 0 ? $cout['net'] : null);
    if ($n > 0 && $ref !== null && abs($t2 - $ref) < abs($t1 - $ref) && $t1 !== $t2) {
        $hyp = 'petite';
        foreach ($lignes as &$l) {
            if ($l['type'] === 'matiere' && ($l['facteur'] ?? 1.0) > 1 && $l['cout'] !== null) { $l['cout'] = round($l['cout'] / $l['facteur'], 4); $l['unite'] = $l['petite'] ?: $l['unite']; }
        }
        unset($l);
    }
    $total = 0.0; $sansPrix = 0;
    foreach ($lignes as $l) { if ($l['cout'] !== null) { $total += $l['cout']; } else { $sansPrix++; } }
    usort($lignes, static fn ($a, $b) => (($b['cout'] ?? -1) <=> ($a['cout'] ?? -1)) ?: strcmp($a['nom'], $b['nom']));
    $base = $total > 0 ? $total : ($cout['net'] ?? 0);
    foreach ($lignes as &$l) {
        $l['part'] = $l['cout'] !== null && $base > 0 ? round(100 * $l['cout'] / $base, 1) : null;
        unset($l['petite'], $l['facteur']);
    }
    unset($l);
    $out = ['pid' => $pid, 'nom' => $nom, 'recette' => $rec, 'cout' => $cout, 'lignes' => $lignes,
        'total' => $lignes !== [] && $total > 0 ? round($total, 4) : null,
        'complet' => $lignes !== [] && $sansPrix === 0, 'sansPrix' => $sansPrix, 'nLignes' => count($lignes), 'hypothese' => $hyp, 'reference' => $ref,
        'source' => 'copie locale du panel : product_recipe_material_connection, material, shop_material_price_list (prix de base, le plus récent), recipe_cost',
        'colonnes' => $diag];
    if ($brut) {
        $C = $ctx['conn'];
        try { $out['colonnes']['brut'] = Db::rows('SELECT * FROM `' . $C['table'] . '` WHERE `' . $C['recette'] . '` = ?', [$rid]); } catch (Throwable $e) { $out['colonnes']['brut'] = $e->getMessage(); }
        $out['colonnes']['couts'] = $couts;
    }
    return $out;
}
