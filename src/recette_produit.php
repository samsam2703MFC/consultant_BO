<?php
declare(strict_types=1);

/*
 * LA RECETTE D'UN PRODUIT — l'onglet « Recette & marge » de la modale produit du dashboard
 * magasin (demande du 08/10/2026).
 *
 * GET /analyse/produits/recette?pid=2300010&shop=4[&rafraichir=1] rend la recette du produit
 * telle que la copie locale du panel la porte : product → product_recipe →
 * product_recipe_material_connection → material, les sous-recettes dépliées, la quantité,
 * l'unité et le coût de chaque ligne quand la matière a un prix, et le coût de recette gravé
 * (recipe_cost : celui du magasin, sinon celui du réseau, divisé par le rendement).
 *
 * Les colonnes de la copie n'ont jamais été cartographiées en entier : chaque table est lue par
 * information_schema et l'on prend les colonnes qui existent (quantité, unité, prix…).
 * `colonnes` dit ce qui a été trouvé ; une matière sans prix garde sa quantité et sa ligne
 * reste sans coût, `complet` le dit. Rien ici n'appelle le panel. Le résultat est gardé 24 h par
 * produit et magasin (ceo_app_setting `recetteProduit:<pid>:<shop>`).
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

/** Le nom d'une unité : « kg », « g », « pièce »… ; '' si inconnu. */
function rpUnite(mixed $v, array $unites): string
{
    if ($v === null || $v === '') { return ''; }
    if (is_numeric($v) && isset($unites[(int) $v])) { return $unites[(int) $v]; }
    return is_numeric($v) ? '' : strtolower(trim((string) $v));
}

/** Combien d'unités de prix dans une unité de ligne : 1 g = 0,001 kg. null quand on ne sait pas convertir. */
function rpFacteurUnite(string $ligne, string $prix): ?float
{
    $n = static function (string $u): string {
        $u = strtolower(trim($u));
        return match ($u) { 'gr', 'gramme', 'grammes' => 'g', 'kilo', 'kilogramme', 'kilogrammes' => 'kg', 'litre', 'litres' => 'l', 'millilitre' => 'ml', 'pc', 'pcs', 'piece', 'pièce', 'pièces', 'pieces', 'unit', 'unité', 'stuk', 'st' => 'pce', default => $u };
    };
    $a = $n($ligne); $b = $n($prix);
    if ($a === '' || $b === '' || $a === $b) { return 1.0; }
    $t = ['g' => ['kg' => 0.001], 'kg' => ['g' => 1000.0], 'ml' => ['l' => 0.001, 'cl' => 0.1], 'cl' => ['l' => 0.01, 'ml' => 10.0], 'l' => ['ml' => 1000.0, 'cl' => 100.0]];
    return $t[$a][$b] ?? null;
}

/**
 * Les lignes d'une recette, sous-recettes dépliées (profondeur bornée, chaque recette une fois).
 * `$facteur` : la part de la sous-recette qui entre dans le produit (quantité ÷ rendement).
 */
function rpLignes(int $rid, float $facteur, int $prof, ?string $sous, int $sid, array $ctx, array &$vus): array
{
    if ($rid <= 0 || $prof > RP_PROFONDEUR || isset($vus[$rid])) { return []; }
    $vus[$rid] = true;
    $C = $ctx['conn'];
    if ($C['table'] === null || $C['recette'] === null) { return []; }
    $sel = array_values(array_unique(array_filter([$C['matiere'], $C['sous'], $C['qte'], $C['unite']])));
    $rows = Db::rows('SELECT ' . implode(', ', array_map(static fn ($c) => '`' . $c . '`', $sel)) . ' FROM `' . $C['table'] . '` WHERE `' . $C['recette'] . '` = ?', [$rid]);
    $ids = [];
    foreach ($rows as $r) { if ($C['matiere'] !== null && (int) ($r[$C['matiere']] ?? 0) > 0) { $ids[] = (int) $r[$C['matiere']]; } }
    $mats = $ids !== [] ? rpMatieres($ids, $sid, $ctx) : [];
    $out = [];
    foreach ($rows as $r) {
        $qte = $C['qte'] !== null && is_numeric($r[$C['qte']] ?? null) ? (float) $r[$C['qte']] * $facteur : null;
        $unite = $C['unite'] !== null ? rpUnite($r[$C['unite']] ?? null, $ctx['unites']) : '';
        $srid = $C['sous'] !== null ? (int) ($r[$C['sous']] ?? 0) : 0;
        $mid = $C['matiere'] !== null ? (int) ($r[$C['matiere']] ?? 0) : 0;
        if ($srid > 0 && $mid <= 0) {
            // Une sous-recette : son nom, son rendement, et ses lignes au prorata.
            $sr = rpRecette($srid, $ctx);
            $rend = $sr !== null && $sr['rendement'] > 0 ? $sr['rendement'] : 1.0;
            $part = $qte !== null ? $qte / $rend : $facteur;
            $lignes = rpLignes($srid, $part, $prof + 1, $sr['nom'] ?? ('sous-recette ' . $srid), $sid, $ctx, $vus);
            if ($lignes === []) {
                // Pas de lignes lisibles : la sous-recette vaut son coût gravé.
                $cout = rpCoutRecette($srid, $sid, $ctx);
                $out[] = ['nom' => $sr['nom'] ?? ('sous-recette ' . $srid), 'cat' => 'sous-recette', 'qte' => $qte, 'unite' => $unite,
                    'prixUnite' => null, 'cout' => $cout !== null ? round($cout['net'] * $part, 3) : null, 'sous' => $sous, 'type' => 'recette'];
            } else { foreach ($lignes as $l) { $out[] = $l; } }
            continue;
        }
        if ($mid <= 0) { continue; }
        $m = $mats[$mid] ?? ['nom' => 'matière ' . $mid, 'cat' => '', 'prix' => null, 'unite' => ''];
        $cout = null; $motif = null;
        if ($qte !== null && $m['prix'] !== null) {
            $f = rpFacteurUnite($unite, $m['unite']);
            if ($f !== null) { $cout = round($qte * $f * $m['prix'], 4); }
            else { $motif = 'unités ' . $unite . ' / ' . $m['unite'] . ' non converties'; }
        }
        $out[] = ['nom' => $m['nom'], 'cat' => $m['cat'], 'qte' => $qte !== null ? round($qte, 3) : null, 'unite' => $unite,
            'prixUnite' => $m['prix'], 'prixParUnite' => $m['unite'], 'cout' => $cout, 'motif' => $motif, 'sous' => $sous, 'type' => 'matiere'];
    }
    return $out;
}

/** Les matières d'une liste d'id : nom, catégorie, prix (celui du magasin s'il existe, sinon celui de la fiche) et son unité. */
function rpMatieres(array $ids, int $sid, array $ctx): array
{
    $M = $ctx['mat'];
    $out = [];
    if ($M['table'] === null) { return $out; }
    $sel = array_values(array_unique(array_filter(['id', $M['nom'], $M['prix'], $M['unite'], $M['cat']])));
    $ph = implode(', ', array_fill(0, count($ids), '?'));
    foreach (Db::rows('SELECT ' . implode(', ', array_map(static fn ($c) => '`' . $c . '`', $sel)) . ' FROM `' . $M['table'] . '` WHERE `id` IN (' . $ph . ')', $ids) as $r) {
        $out[(int) $r['id']] = ['nom' => $M['nom'] !== null ? (string) $r[$M['nom']] : 'matière ' . $r['id'],
            'cat' => $M['cat'] !== null ? (string) ($r[$M['cat']] ?? '') : '',
            'prix' => $M['prix'] !== null && is_numeric($r[$M['prix']] ?? null) && (float) $r[$M['prix']] > 0 ? (float) $r[$M['prix']] : null,
            'unite' => $M['unite'] !== null ? rpUnite($r[$M['unite']] ?? null, $ctx['unites']) : ''];
    }
    // Le prix du magasin quand la copie en porte un.
    $S = $ctx['shopMat'];
    if ($S['table'] !== null && $S['prix'] !== null && $S['matiere'] !== null && $S['shop'] !== null) {
        try {
            foreach (Db::rows('SELECT `' . $S['matiere'] . '` AS m, `' . $S['prix'] . '` AS p FROM `' . $S['table'] . '` WHERE `' . $S['shop'] . '` = ? AND `' . $S['matiere'] . '` IN (' . $ph . ')', array_merge([$sid], $ids)) as $r) {
                if (isset($out[(int) $r['m']]) && is_numeric($r['p']) && (float) $r['p'] > 0) { $out[(int) $r['m']]['prix'] = (float) $r['p']; $out[(int) $r['m']]['prixMagasin'] = true; }
            }
        } catch (Throwable $e) { /* pas de prix magasin */ }
    }
    return $out;
}

/** Une recette : son nom et son rendement ; null si absente. */
function rpRecette(int $rid, array $ctx): ?array
{
    $R = $ctx['rec'];
    if ($R['table'] === null || $rid <= 0) { return null; }
    $sel = array_values(array_unique(array_filter(['id', $R['nom'], $R['rendement']])));
    $r = Db::row('SELECT ' . implode(', ', array_map(static fn ($c) => '`' . $c . '`', $sel)) . ' FROM `' . $R['table'] . '` WHERE `id` = ?', [$rid]);
    if ($r === null) { return null; }
    $rend = $R['rendement'] !== null && is_numeric($r[$R['rendement']] ?? null) && (float) $r[$R['rendement']] > 0 ? (float) $r[$R['rendement']] : 1.0;
    return ['id' => $rid, 'nom' => $R['nom'] !== null ? (string) ($r[$R['nom']] ?? '') : '', 'rendement' => $rend];
}

/** Le coût de recette gravé : celui du magasin, sinon celui du réseau (id_shop 0), sinon la moyenne des magasins. */
function rpCoutRecette(int $rid, int $sid, array $ctx): ?array
{
    $K = $ctx['cout'];
    if ($K['table'] === null || $K['cout'] === null || $K['recette'] === null) { return null; }
    try {
        // La table peut porter plusieurs calculs par recette et magasin : le plus récent fait foi.
        $rows = Db::rows('SELECT ' . ($K['shop'] !== null ? '`' . $K['shop'] . '` AS s, ' : '0 AS s, ') . '`' . $K['cout'] . '` AS c FROM `' . $K['table'] . '` WHERE `' . $K['recette'] . '` = ?'
            . (!empty($K['date']) ? ' ORDER BY `' . $K['date'] . '` DESC' : ''), [$rid]);
    } catch (Throwable $e) { return null; }
    $mag = null; $res = null; $autres = []; $vusShop = [];
    foreach ($rows as $r) {
        if (!is_numeric($r['c']) || (float) $r['c'] <= 0) { continue; }
        $s = (int) $r['s'];
        if (isset($vusShop[$s])) { continue; }   // le premier de chaque magasin = le plus récent
        $vusShop[$s] = true;
        if ($s === $sid) { $mag = (float) $r['c']; } elseif ($s === 0) { $res = (float) $r['c']; } else { $autres[] = (float) $r['c']; }
    }
    if ($mag !== null) { return ['net' => $mag, 'source' => 'recette du magasin (copie locale)']; }
    if ($res !== null) { return ['net' => $res, 'source' => 'recette réseau (copie locale)']; }
    if ($autres !== []) { return ['net' => array_sum($autres) / count($autres), 'source' => 'moyenne des magasins (copie locale)']; }
    return null;
}

/** Ce que la copie locale sait des tables de recette : noms de tables et colonnes retenues. */
function rpContexte(): array
{
    $conn = rpColonnes('product_recipe_material_connection');
    $mat = rpColonnes('material');
    $rec = rpColonnes('product_recipe');
    $cout = rpColonnes('recipe_cost');
    $shopMat = rpColonnes('shop_material');
    $ctx = [
        'conn' => ['table' => $conn !== [] ? 'product_recipe_material_connection' : null,
            'recette' => rpChoix($conn, ['id_product_recipe', 'id_recipe', 'recipe_id', 'product_recipe_id']),
            'matiere' => rpChoix($conn, ['id_material', 'material_id']),
            'sous' => rpChoix($conn, ['id_sub_recipe', 'id_subrecipe', 'id_child_recipe', 'id_recipe_child', 'sub_recipe_id', 'id_product_recipe_child', 'id_product_recipe_sub']),
            'qte' => rpChoix($conn, ['quantity', 'qty', 'amount', 'quantity_net', 'net_quantity', 'quantity_gross']),
            'unite' => rpChoix($conn, ['id_unit', 'unit_id', 'id_measurement_unit', 'id_measure_unit', 'unit', 'unit_name'])],
        'mat' => ['table' => $mat !== [] ? 'material' : null,
            'nom' => rpChoix($mat, ['name', 'label', 'material_name']),
            'prix' => rpChoix($mat, ['price', 'unit_price', 'price_per_unit', 'price_net', 'net_price', 'purchase_price', 'cost', 'cost_per_unit', 'average_price', 'last_price', 'current_price']),
            'unite' => rpChoix($mat, ['id_unit', 'unit_id', 'id_measurement_unit', 'id_measure_unit', 'unit', 'unit_name', 'price_unit']),
            'cat' => rpChoix($mat, ['category_name', 'category', 'id_material_category', 'id_category'])],
        'shopMat' => ['table' => $shopMat !== [] ? 'shop_material' : null,
            'shop' => rpChoix($shopMat, ['id_shop', 'shop_id']), 'matiere' => rpChoix($shopMat, ['id_material', 'material_id']),
            'prix' => rpChoix($shopMat, ['price', 'unit_price', 'price_per_unit', 'price_net', 'purchase_price', 'cost'])],
        'rec' => ['table' => $rec !== [] ? 'product_recipe' : null,
            'nom' => rpChoix($rec, ['name', 'label', 'title']),
            'rendement' => rpChoix($rec, ['yield_quantity', 'yield', 'output_quantity', 'portions', 'number_of_portions'])],
        'cout' => ['table' => $cout !== [] ? 'recipe_cost' : null,
            'recette' => rpChoix($cout, ['id_recipe', 'recipe_id', 'id_product_recipe']), 'shop' => rpChoix($cout, ['id_shop', 'shop_id']),
            'cout' => rpChoix($cout, ['calculated_cost_net', 'cost_net', 'net_cost', 'calculated_cost', 'cost']),
            'date' => rpChoix($cout, ['calculated_at', 'calculation_date', 'created_at', 'updated_at', 'date', 'timestamp', 'insert_timestamp', 'id'])],
        'unites' => [],
    ];
    // Les sous-recettes peuvent vivre dans une table de liaison à part : on la cherche par ses colonnes.
    if ($ctx['conn']['sous'] === null) {
        foreach (rpTables('recipe') as $t) {
            if ($t === 'product_recipe_material_connection' || !str_contains($t, 'connection')) { continue; }
            $c = rpColonnes($t);
            $rc = rpChoix($c, ['id_product_recipe', 'id_recipe', 'recipe_id', 'id_parent_recipe', 'id_recipe_parent']);
            $sc = rpChoix($c, ['id_sub_recipe', 'id_subrecipe', 'id_child_recipe', 'id_recipe_child', 'sub_recipe_id', 'id_product_recipe_child', 'id_product_recipe_sub']);
            if ($rc !== null && $sc !== null) {
                $ctx['sousConn'] = ['table' => $t, 'recette' => $rc, 'sous' => $sc, 'qte' => rpChoix($c, ['quantity', 'qty', 'amount']), 'unite' => rpChoix($c, ['id_unit', 'unit_id', 'unit'])];
                break;
            }
        }
    }
    // Le nom des unités.
    foreach (['unit', 'measurement_unit', 'measure_unit', 'material_unit'] as $t) {
        $c = rpColonnes($t);
        if ($c === []) { continue; }
        $n = rpChoix($c, ['abbreviation', 'short_name', 'symbol', 'code', 'name', 'label']);
        if ($n === null) { continue; }
        try { foreach (Db::rows('SELECT `id`, `' . $n . '` AS n FROM `' . $t . '`') as $r) { $ctx['unites'][(int) $r['id']] = strtolower(trim((string) $r['n'])); } }
        catch (Throwable $e) { /* sans noms d'unités */ }
        $ctx['uniteTable'] = $t;
        break;
    }
    return $ctx;
}

/** Les lignes d'une table de liaison de sous-recettes à part (quand la table des matières ne les porte pas). */
function rpSousLignes(int $rid, float $facteur, int $prof, ?string $sous, int $sid, array $ctx, array &$vus): array
{
    $S = $ctx['sousConn'] ?? null;
    if ($S === null || $prof > RP_PROFONDEUR) { return []; }
    $sel = array_values(array_unique(array_filter([$S['sous'], $S['qte'], $S['unite']])));
    try { $rows = Db::rows('SELECT ' . implode(', ', array_map(static fn ($c) => '`' . $c . '`', $sel)) . ' FROM `' . $S['table'] . '` WHERE `' . $S['recette'] . '` = ?', [$rid]); }
    catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $srid = (int) ($r[$S['sous']] ?? 0);
        if ($srid <= 0 || isset($vus[$srid])) { continue; }
        $sr = rpRecette($srid, $ctx);
        $qte = $S['qte'] !== null && is_numeric($r[$S['qte']] ?? null) ? (float) $r[$S['qte']] * $facteur : null;
        $rend = $sr !== null && $sr['rendement'] > 0 ? $sr['rendement'] : 1.0;
        $part = $qte !== null ? $qte / $rend : $facteur;
        $nom = $sr['nom'] ?? ('sous-recette ' . $srid);
        $lignes = array_merge(rpLignes($srid, $part, $prof + 1, $nom, $sid, $ctx, $vus), rpSousLignes($srid, $part, $prof + 1, $nom, $sid, $ctx, $vus));
        if ($lignes === []) {
            $cout = rpCoutRecette($srid, $sid, $ctx);
            $out[] = ['nom' => $nom, 'cat' => 'sous-recette', 'qte' => $qte, 'unite' => $S['unite'] !== null ? rpUnite($r[$S['unite']] ?? null, $ctx['unites']) : '',
                'prixUnite' => null, 'cout' => $cout !== null ? round($cout['net'] * $part, 3) : null, 'sous' => $sous, 'type' => 'recette'];
        } else { foreach ($lignes as $l) { $out[] = $l; } }
    }
    return $out;
}

/**
 * GET /analyse/produits/recette?pid=2300010&shop=4[&rafraichir=1]
 */
function ep_analyse_produit_recette(): array
{
    $pid = (int) ($_GET['pid'] ?? 0); $sid = (int) ($_GET['shop'] ?? 0);
    if ($pid <= 0 || $sid <= 0) { http_response_code(400); return ['error' => 'pid et shop requis']; }
    $cle = 'recetteProduit:' . $pid . ':' . $sid;
    if (!empty($_GET['colonnes'])) {
        // Diagnostic, lecture seule : les colonnes de chaque table lue et les tables voisines, pour cartographier la copie.
        $out = rpCalcul($pid, $sid);
        $listes = [];
        foreach (['product_recipe_material_connection', 'material', 'product_recipe', 'recipe_cost', 'unit', 'product'] as $t) { $listes[$t] = rpColonnes($t); }
        $voisines = [];
        foreach (array_unique(array_merge(rpTables('material'), rpTables('recipe'), rpTables('price'), rpTables('cost'), rpTables('supplier'), rpTables('ingredient'))) as $t) {
            if (count($voisines) >= 40) { break; }
            $voisines[$t] = rpColonnes($t);
        }
        $out['colonnes']['listes'] = $listes; $out['colonnes']['voisines'] = $voisines;
        try { $out['colonnes']['echantillon'] = Db::rows('SELECT * FROM `product_recipe_material_connection` LIMIT 3'); } catch (Throwable $e) { $out['colonnes']['echantillon'] = $e->getMessage(); }
        return $out;
    }
    if (empty($_GET['rafraichir'])) {
        try { $c = setting($cle); } catch (Throwable $e) { $c = null; }
        if (is_array($c) && isset($c['le'], $c['r']) && is_array($c['r']) && time() - (int) $c['le'] < RP_HEURES * 3600) {
            $r = $c['r']; $r['cache'] = ['le' => date('c', (int) $c['le']), 'age' => time() - (int) $c['le']];
            return $r;
        }
    }
    $out = rpCalcul($pid, $sid);
    if (empty($out['error'])) {
        try {
            $j = json_encode(['le' => time(), 'r' => $out], JSON_UNESCAPED_UNICODE);
            if ($j !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
        } catch (Throwable $e) { /* sans cache */ }
    }
    $out['cache'] = ['le' => date('c'), 'age' => 0];
    return $out;
}

/** Le calcul lui-même, sans cache. */
function rpCalcul(int $pid, int $sid): array
{
    $ctx = rpContexte();
    $diag = ['tables' => array_values(array_filter(array_map(static fn ($k) => $ctx[$k]['table'], ['conn', 'mat', 'rec', 'cout', 'shopMat']))),
        'liaison' => $ctx['conn'], 'matiere' => $ctx['mat'], 'recette' => $ctx['rec'], 'cout' => $ctx['cout'], 'prixMagasin' => $ctx['shopMat'],
        'sousRecettes' => $ctx['sousConn'] ?? null, 'unites' => $ctx['uniteTable'] ?? null];
    try { $p = Db::row('SELECT id, name, id_recipe FROM product WHERE id = ?', [$pid]); }
    catch (Throwable $e) { return ['pid' => $pid, 'indispo' => true, 'motif' => 'copie locale du catalogue indisponible', 'colonnes' => $diag]; }
    if ($p === null) { return ['pid' => $pid, 'indispo' => true, 'motif' => 'produit inconnu de la copie locale', 'colonnes' => $diag]; }
    $nom = (string) ($p['name'] ?? '');
    $rid = $p['id_recipe'] !== null ? (int) $p['id_recipe'] : 0;
    if ($rid <= 0) { return ['pid' => $pid, 'nom' => $nom, 'sansRecette' => true, 'motif' => 'aucune recette rattachée à ce produit dans la copie locale', 'colonnes' => $diag]; }
    if ($ctx['conn']['table'] === null || $ctx['rec']['table'] === null) {
        return ['pid' => $pid, 'nom' => $nom, 'indispo' => true, 'motif' => 'la copie locale ne porte pas les lignes de recette (product_recipe_material_connection)', 'colonnes' => $diag];
    }
    $rec = rpRecette($rid, $ctx) ?? ['id' => $rid, 'nom' => '', 'rendement' => 1.0];
    $vus = [];
    $lignes = array_merge(rpLignes($rid, 1.0 / max(0.000001, $rec['rendement']), 0, null, $sid, $ctx, $vus),
        rpSousLignes($rid, 1.0 / max(0.000001, $rec['rendement']), 0, null, $sid, $ctx, $vus));
    $cout = rpCoutRecette($rid, $sid, $ctx);
    if ($cout !== null && $rec['rendement'] > 0) { $cout['net'] = round($cout['net'] / $rec['rendement'], 4); }
    $total = 0.0; $sansPrix = 0;
    foreach ($lignes as $l) { if ($l['cout'] !== null) { $total += $l['cout']; } else { $sansPrix++; } }
    usort($lignes, static fn ($a, $b) => (($b['cout'] ?? -1) <=> ($a['cout'] ?? -1)) ?: strcmp($a['nom'], $b['nom']));
    $part = $total > 0 ? $total : ($cout['net'] ?? 0);
    foreach ($lignes as &$l) { $l['part'] = $l['cout'] !== null && $part > 0 ? round(100 * $l['cout'] / $part, 1) : null; }
    unset($l);
    return ['pid' => $pid, 'nom' => $nom, 'recette' => $rec, 'cout' => $cout, 'lignes' => $lignes,
        'total' => $lignes !== [] && $sansPrix === 0 ? round($total, 4) : ($total > 0 ? round($total, 4) : null),
        'complet' => $lignes !== [] && $sansPrix === 0, 'sansPrix' => $sansPrix, 'nLignes' => count($lignes),
        'source' => 'copie locale du panel : product_recipe_material_connection, material, recipe_cost ; prix du magasin quand shop_material en porte un',
        'colonnes' => $diag];
}
