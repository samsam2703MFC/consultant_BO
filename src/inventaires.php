<?php
declare(strict_types=1);

/**
 * Entre deux inventaires — l'écart de stock d'un magasin et la justesse de ses ventes
 * (demande du 12/10/2026).
 *
 * Le stock matière du panel ne se décompte PAS avec les ventes (mesuré dans son grand livre,
 * material_inventory_log) : il vaut le dernier comptage plus les livraisons. À chaque inventaire
 * (scanner, une fois par semaine), le panel efface ce solde (OUT ERASE), puis pose le compté
 * (ADJUST STOCKTAKING). Entre deux inventaires A et B, pour chaque matière :
 *
 *   sorties réelles  = solde effacé avant B (compté en A + livré − pertes matière déclarées)
 *                      − compté en B ;
 *   sorties expliquées = Σ ventes de caisse × recette aplatie (portions comprises)
 *                      + Σ poubelle déclarée × recette ;
 *   écart = sorties réelles − sorties expliquées ; justesse des ventes = expliquées ÷ réelles.
 *
 * Valorisé au prix moyen de la matière (celui de l'inventaire), le tout dit en % ce que la
 * caisse et la poubelle expliquent de ce qui a quitté le stock.
 *
 * Sources : la copie locale du panel (stocktaking, stocktaking_summary, material_inventory_log,
 * transaction_product, product_movement, flattened_recipe_ingredient) — arrêtée à la mi-juillet
 * 2026 ; au-delà, les inventaires relevés en direct par le cockpit (voir le relevé plus bas).
 *
 * GET /stock/inventaires?shop=          — les inventaires du magasin et la justesse déjà calculée
 *                                          de chaque semaine ;
 * GET /stock/inventaires/ecart?shop=&de=&a= — le détail entre deux inventaires ;
 * GET /stock/inventaires/sonde?shop=4   — ce que la copie locale sait des inventaires et des
 *                                          tables voisines (lecture seule, produits et matières
 *                                          seulement — jamais un client).
 */

/** Les tables de la copie dont le nom contient l'un des motifs, avec colonnes, volume et dernière date. */
function invTablesSonde(array $motifs): array
{
    $out = [];
    $w = implode(' OR ', array_fill(0, count($motifs), 'TABLE_NAME LIKE ?'));
    try {
        $ts = Db::rows('SELECT TABLE_NAME n, TABLE_ROWS lignes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (' . $w . ') ORDER BY TABLE_NAME',
            array_map(static fn ($m) => '%' . $m . '%', $motifs));
    } catch (Throwable $e) { return ['erreur' => 'information_schema indisponible']; }
    foreach ($ts as $t) {
        $n = (string) $t['n'];
        if (str_starts_with($n, 'ceo_')) { continue; }
        $e = ['table' => $n, 'lignes' => $t['lignes'] === null ? null : (int) $t['lignes'], 'colonnes' => [], 'derniere' => null];
        try {
            $cols = array_map(static fn ($c) => (string) $c['COLUMN_NAME'],
                Db::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$n]));
            $e['colonnes'] = array_slice($cols, 0, 40);
            foreach (['created_at', 'insert_timestamp', 'updated_at', 'received_at', 'date', 'created'] as $c) {
                if (in_array($c, $cols, true)) {
                    $r = Db::row('SELECT /*+ MAX_EXECUTION_TIME(4000) */ MAX(`' . $c . '`) d FROM `' . $n . '`');
                    $e['derniere'] = [$c, $r['d'] ?? null];
                    break;
                }
            }
        } catch (Throwable $ex) { $e['erreur'] = 'colonnes illisibles'; }
        $out[] = $e;
    }
    return $out;
}

function ep_stock_inventaires_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 4);
    @set_time_limit(90);
    $out = ['shop' => $sid];
    $out['tables'] = invTablesSonde(['stocktak', 'material', 'inventor', 'recipe', 'receipt', 'proforma', 'requisition', 'deliver', 'product_movement']);
    $q = static function (string $cle, string $sql, array $args = []) use (&$out): void {
        try { $out[$cle] = Db::rows($sql, $args); } catch (Throwable $e) { $out[$cle] = ['erreur' => mb_substr($e->getMessage(), 0, 160)]; }
    };
    $q('typesInventaire', 'SELECT /*+ MAX_EXECUTION_TIME(6000) */ id_shop, type, was_manual_change, COUNT(*) n, MIN(created_at) premier, MAX(created_at) dernier FROM stocktaking GROUP BY id_shop, type, was_manual_change ORDER BY id_shop, type');
    $q('inventaires', 'SELECT /*+ MAX_EXECUTION_TIME(6000) */ s.id, s.type, s.was_manual_change, s.created_at, s.updated_at,
            (SELECT COUNT(*) FROM stocktaking_item i WHERE i.id_stocktaking = s.id) items,
            (SELECT COUNT(*) FROM stocktaking_summary m WHERE m.id_stockatking = s.id) resume,
            (SELECT ROUND(SUM(m.difference), 2) FROM stocktaking_summary m WHERE m.id_stockatking = s.id) ecart,
            (SELECT ROUND(SUM(m.difference * m.avg_base_unit_price_net), 2) FROM stocktaking_summary m WHERE m.id_stockatking = s.id) ecartValeur
          FROM stocktaking s WHERE s.id_shop = ? ORDER BY s.created_at DESC LIMIT 14', [$sid]);
    $q('raisonsMouvementMatiere', 'SELECT * FROM material_movement_reason LIMIT 20');
    // `journal=1` : le grand livre matière — les types de mouvement, et la suite d'une matière d'un inventaire au suivant
    if (!empty($_GET['journal'])) {
        $q('journalTypes', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ change_type, reason, COUNT(*) n, ROUND(SUM(change_quantity_in_base_unit), 1) somme,
                SUM(id_order IS NOT NULL) avecCommande, SUM(id_stocktaking IS NOT NULL) avecInventaire, MIN(created_at) premier, MAX(created_at) dernier
              FROM material_inventory_log WHERE id_shop = ? AND created_at >= ? GROUP BY change_type, reason ORDER BY n DESC', [$sid, '2026-06-01 00:00:00']);
        $mat = (int) ($_GET['matiere'] ?? 150);
        $q('journalMatiere', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ created_at, change_type, reason, change_quantity_in_base_unit q, previous_balance avant, new_balance apres, id_order, id_stocktaking
              FROM material_inventory_log WHERE id_shop = ? AND id_material = ? AND created_at >= ? ORDER BY created_at, id LIMIT 80', [$sid, $mat, '2026-06-20 00:00:00']);
        $q('livraisons', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ DATE(d.created_at) jour, COUNT(*) lignes, ROUND(SUM(d.delivered_pack_qty * d.pack_size_in_base_unit), 0) unites, ROUND(SUM(d.delivered_pack_qty * d.pack_price), 2) valeur
              FROM material_order_item_delivery_log d JOIN material_order o ON o.id = d.id_order WHERE o.id_shop = ? AND d.created_at >= ? GROUP BY DATE(d.created_at) ORDER BY jour', [$sid, '2026-06-01 00:00:00']);
        $q('ventesMouvements', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ movement_type, reason, COUNT(*) n, ROUND(SUM(quantity), 1) q FROM product_movement WHERE id_shop = ? AND created_at >= ? GROUP BY movement_type, reason', [$sid, '2026-06-01 00:00:00']);
    }
    // `recettes=pid,pid` : le produit, sa recette, son rendement et ses matières aplaties
    if (!empty($_GET['recettes'])) {
        $pids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $_GET['recettes'])))), 0, 12);
        if ($pids !== []) {
            $in = implode(',', array_fill(0, count($pids), '?'));
            $q('recettesProduits', 'SELECT p.id pid, p.id_recipe, r.name recette, r.yield_quantity rendement, r.id_unit unite, r.is_subrecipe
                  FROM product p LEFT JOIN product_recipe r ON r.id = p.id_recipe WHERE p.id IN (' . $in . ')', $pids);
            $q('recettesMatieres', 'SELECT p.id pid, f.id_material, m.name matiere, m.id_unit, f.quantity
                  FROM product p JOIN flattened_recipe_ingredient f ON f.id_recipe = p.id_recipe LEFT JOIN material m ON m.id = f.id_material
                  WHERE p.id IN (' . $in . ') ORDER BY p.id, f.id_material', $pids);
            $q('recettesDirectes', 'SELECT p.id pid, c.child_ingredient_id id_material, c.child_recipe_id sous, c.quantity
                  FROM product p JOIN product_recipe_material_connection c ON c.parent_recipe_id = p.id_recipe WHERE p.id IN (' . $in . ')', $pids);
        }
        $q('portions', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ product_portion_type, COUNT(*) n, ROUND(SUM(quantity), 1) q FROM transaction_product WHERE id_transaction > (SELECT MAX(id) - 40000 FROM `transaction`) GROUP BY product_portion_type');
        $q('unites', 'SELECT * FROM unit LIMIT 20');
    }
    // `heures=1` : à quelle heure tombent tickets, poubelle et grand livre — leur fuseau (UTC ou heure de Bruxelles)
    if (!empty($_GET['heures'])) {
        $q('heuresTickets', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ HOUR(insert_timestamp) h, COUNT(*) n FROM `transaction` WHERE id_shop = ? AND insert_timestamp >= ? AND insert_timestamp < ? GROUP BY h ORDER BY h', [$sid, '2026-07-07 00:00:00', '2026-07-12 00:00:00']);
        $q('heuresPoubelle', "SELECT /*+ MAX_EXECUTION_TIME(8000) */ HOUR(created_at) h, COUNT(*) n FROM product_movement WHERE id_shop = ? AND created_at >= ? AND created_at < ? GROUP BY h ORDER BY h", [$sid, '2026-06-01 00:00:00', '2026-07-14 00:00:00']);
        $q('heuresLivre', 'SELECT /*+ MAX_EXECUTION_TIME(8000) */ HOUR(created_at) h, COUNT(*) n FROM material_inventory_log WHERE id_shop = ? AND created_at >= ? GROUP BY h ORDER BY h', [$sid, '2026-06-01 00:00:00']);
        $q('heuresInventaires', 'SELECT HOUR(created_at) h, COUNT(*) n FROM stocktaking WHERE id_shop = ? GROUP BY h ORDER BY h', [$sid]);
        $q('maintenant', 'SELECT NOW() maintenant, UTC_TIMESTAMP() utc, @@session.time_zone tz');
    }
    $dernier = is_array($out['inventaires'] ?? null) && isset($out['inventaires'][0]['id']) ? (int) $out['inventaires'][0]['id'] : 0;
    if ($dernier > 0) {
        $q('resumeExemple', 'SELECT m.id_material, mt.name matiere, m.previous_balance_in_base_unit avant, m.counted_quantity_in_base_unit compte, m.difference, m.avg_base_unit_price_net prix
            FROM stocktaking_summary m LEFT JOIN material mt ON mt.id = m.id_material WHERE m.id_stockatking = ? ORDER BY ABS(m.difference * m.avg_base_unit_price_net) DESC LIMIT 12', [$dernier]);
        $q('itemsExemple', 'SELECT i.id_material, mt.name matiere, i.pack_quantity, i.package_size_in_base_unit, i.total_quantity_in_base_unit, i.raw_code, i.pack_price_net
            FROM stocktaking_item i LEFT JOIN material mt ON mt.id = i.id_material WHERE i.id_stocktaking = ? LIMIT 8', [$dernier]);
    }
    return $out;
}

/* ---------- les inventaires et l'écart entre deux ---------- */

const INV_CACHE_V = 1;          // version du calcul : une paire gardée sous une autre version se recalcule
const INV_PAIRES_MAX = 16;      // l'historique : les seize dernières semaines au plus

/**
 * L'heure de Bruxelles d'un horodatage UTC. Mesuré le 12/10/2026 : `stocktaking.created_at` est en UTC
 * (l'inventaire 165 de Halle, 09:13:46, est passé à 11:13:46 dans le grand livre), alors que le grand livre,
 * les tickets et la poubelle de la copie sont à l'heure locale (premiers tickets à 6 h, poubelle à 17-18 h).
 */
function invLocal(string $utc): string
{
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Brussels'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) { return substr($utc, 0, 19); }
}

/** Les inventaires de la copie pour un magasin, du plus récent au plus ancien : [{id: 'c165', at (heure locale), n, source, type}]. */
function invCopieListe(int $sid): array
{
    try {
        $rows = Db::rows('SELECT /*+ MAX_EXECUTION_TIME(8000) */ s.id, s.created_at, s.type, COUNT(m.id) n
              FROM stocktaking s LEFT JOIN stocktaking_summary m ON m.id_stockatking = s.id
             WHERE s.id_shop = ? GROUP BY s.id, s.created_at, s.type ORDER BY s.created_at DESC', [$sid]);
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        if ((int) $r['n'] <= 0) { continue; }
        $out[] = ['id' => 'c' . (int) $r['id'], 'at' => invLocal((string) $r['created_at']), 'n' => (int) $r['n'],
            'source' => 'copie', 'type' => (string) $r['type']];
    }
    return $out;
}

/** Tous les inventaires du magasin : la copie, puis ceux relevés en direct ; du plus récent au plus ancien. */
function invListe(int $sid): array
{
    $l = array_merge(invCopieListe($sid), function_exists('invReleveListe') ? invReleveListe($sid) : []);
    usort($l, static fn ($a, $b) => strcmp($b['at'], $a['at']));
    return $l;
}

/** Les unités du panel : id => [court, facteur vers l'unité de base]. L'unité de base est la plus petite (g, ml, pièce). */
function invUnites(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $memo = [];
    try {
        foreach (Db::rows('SELECT id, name, smaller_unit_name, conversion_factor FROM unit') as $u) {
            $petite = trim((string) ($u['smaller_unit_name'] ?? ''));
            $nom = $petite !== '' ? $petite : (string) $u['name'];
            $c = function_exists('rpUniteCourte') ? rpUniteCourte($nom) : strtolower($nom);
            $memo[(int) $u['id']] = $c === '' ? 'pce' : $c;
        }
    } catch (Throwable $e) { /* sans unités : tout en pièces */ }
    return $memo;
}

/** Les matières : id => [nom, categorie, unite (de base : g, ml, pce)]. */
function invMatieres(array $mids): array
{
    $mids = array_values(array_unique(array_filter(array_map('intval', $mids))));
    if ($mids === []) { return []; }
    $un = invUnites();
    $cats = [];
    try { foreach (Db::rows('SELECT id, name FROM material_category') as $c) { $cats[(int) $c['id']] = (string) $c['name']; } } catch (Throwable $e) { /* sans catégories */ }
    $out = [];
    foreach (array_chunk($mids, 400) as $lot) {
        try {
            foreach (Db::rows('SELECT id, name, id_category, id_unit FROM material WHERE id IN (' . implode(',', $lot) . ')') as $m) {
                $out[(int) $m['id']] = ['nom' => trim(preg_replace('/\s+/u', ' ', (string) $m['name'])), 'categorie' => $cats[(int) $m['id_category']] ?? '',
                    'unite' => $un[(int) $m['id_unit']] ?? 'pce'];
            }
        } catch (Throwable $e) { /* matières inconnues : noms par défaut */ }
    }
    foreach ($mids as $id) { if (!isset($out[$id])) { $out[$id] = ['nom' => 'Matière ' . $id, 'categorie' => '', 'unite' => 'pce']; } }
    return $out;
}

/**
 * Les recettes aplaties : pid => [mid => quantité de matière (unité de base) pour UNE pièce du produit].
 * La recette aplatie porte les sous-recettes déjà fondues ; elle vaut pour le rendement de la recette.
 */
function invRecettes(array $pids): array
{
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids))));
    $out = [];
    foreach (array_chunk($pids, 400) as $lot) {
        try {
            foreach (Db::rows('SELECT p.id pid, f.id_material mid, f.quantity q, r.yield_quantity y
                  FROM product p JOIN flattened_recipe_ingredient f ON f.id_recipe = p.id_recipe
                  LEFT JOIN product_recipe r ON r.id = p.id_recipe WHERE p.id IN (' . implode(',', $lot) . ')') as $r) {
                $y = is_numeric($r['y'] ?? null) && (float) $r['y'] > 0 ? (float) $r['y'] : 1.0;
                $q = (float) $r['q'] / $y;
                if ($q <= 0) { continue; }
                $out[(int) $r['pid']][(int) $r['mid']] = ($out[(int) $r['pid']][(int) $r['mid']] ?? 0.0) + $q;
            }
        } catch (Throwable $e) { /* recettes illisibles : rien d'expliqué */ }
    }
    return $out;
}

/** Les noms de produits : le catalogue du panel (nom réseau), la copie à défaut. */
function invNomsProduits(array $pids): array
{
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids))));
    $cat = function_exists('svCatalogue') ? svCatalogue() : [];
    $out = []; $manque = [];
    foreach ($pids as $p) { if (isset($cat[$p]['nom']) && $cat[$p]['nom'] !== '') { $out[$p] = (string) $cat[$p]['nom']; } else { $manque[] = $p; } }
    foreach (array_chunk($manque, 400) as $lot) {
        try { foreach (Db::rows('SELECT id, name FROM product WHERE id IN (' . implode(',', $lot) . ')') as $r) { $out[(int) $r['id']] = trim((string) $r['name']); } }
        catch (Throwable $e) { /* sans noms */ }
    }
    foreach ($pids as $p) { if (!isset($out[$p])) { $out[$p] = 'Produit ' . $p; } }
    return $out;
}

/** Le prix d'achat moyen (HTVA, par unité de base) de chaque matière pour le magasin : la liste de prix de la copie. */
function invPrixListe(int $sid, array $mids): array
{
    $mids = array_values(array_unique(array_filter(array_map('intval', $mids))));
    $out = [];
    foreach (array_chunk($mids, 400) as $lot) {
        try {
            foreach (Db::rows('SELECT id_material mid, id_shop, base_unit_price_net p FROM shop_material_price_list
                  WHERE id_material IN (' . implode(',', $lot) . ') ORDER BY (id_shop = ?) , created_at, id', [$sid]) as $r) {
                // trié : les autres magasins d'abord, ce magasin ensuite, le plus récent en dernier — le dernier lu gagne
                if (is_numeric($r['p']) && (float) $r['p'] > 0) { $out[(int) $r['mid']] = (float) $r['p']; }
            }
        } catch (Throwable $e) { /* sans prix */ }
    }
    return $out;
}

/** Une portion vendue (½, ¼ d'une tarte) : la fraction de la pièce. */
function invFraction(?string $type, int $idPortion): float
{
    if ($idPortion <= 0 && ($type === null || $type === '')) { return 1.0; }
    if (function_exists('svPortion')) {
        $p = svPortion(['id_product_portion' => $idPortion > 0 ? $idPortion : 1, 'product_portion_type' => (string) $type]);
        return $p['fraction'] > 0 ? (float) $p['fraction'] : 1.0;
    }
    return ['ONE_HALF' => 0.5, 'HALF' => 0.5, 'ONE_THIRD' => 1 / 3, 'ONE_QUARTER' => 0.25, 'QUARTER' => 0.25, 'THREE_QUARTERS' => 0.75, 'ONE_SIXTH' => 1 / 6, 'ONE_EIGHTH' => 0.125][strtoupper((string) $type)] ?? 1.0;
}

/**
 * Ce que la copie sait entre deux inventaires A et B (identifiants de stocktaking) :
 * comptages, grand livre, ventes et poubelle, jour par jour. null si un inventaire manque.
 */
function invBaseCopie(int $sid, int $idA, int $idB): ?array
{
    try {
        $sA = Db::row('SELECT id, created_at FROM stocktaking WHERE id = ? AND id_shop = ?', [$idA, $sid]);
        $sB = Db::row('SELECT id, created_at FROM stocktaking WHERE id = ? AND id_shop = ?', [$idB, $sid]);
    } catch (Throwable $e) { return null; }
    if ($sA === null || $sB === null) { return null; }
    $tA = invLocal((string) $sA['created_at']); $tB = invLocal((string) $sB['created_at']);
    if ($tA >= $tB) { [$idA, $idB, $tA, $tB] = [$idB, $idA, $tB, $tA]; }
    $m = []; $prix = []; $prixA = [];
    $mat = static function (int $mid) use (&$m): void {
        if (!isset($m[$mid])) { $m[$mid] = ['cA' => null, 'cB' => null, 'entrees' => 0.0, 'sortiesDecl' => 0.0, 'corrections' => 0.0, 'efface' => null, 'mouv' => []]; }
    };
    foreach (Db::rows('SELECT id_stockatking st, id_material mid, counted_quantity_in_base_unit c, avg_base_unit_price_net p FROM stocktaking_summary WHERE id_stockatking IN (?, ?)', [$idA, $idB]) as $r) {
        $mid = (int) $r['mid']; $mat($mid);
        $c = (float) $r['c'];
        if ((int) $r['st'] === $idA) { $m[$mid]['cA'] = ($m[$mid]['cA'] ?? 0.0) + $c; if (is_numeric($r['p']) && (float) $r['p'] > 0) { $prixA[$mid] = (float) $r['p']; } }
        else { $m[$mid]['cB'] = ($m[$mid]['cB'] ?? 0.0) + $c; if (is_numeric($r['p']) && (float) $r['p'] > 0) { $prix[$mid] = (float) $r['p']; } }
    }
    $jours = [];
    // Le grand livre entre A et B : livraisons (IN), pertes matière déclarées (OUT hors effacement), corrections,
    // et l'effacement qui ouvre l'inventaire B — le solde que le panel attendait.
    foreach (Db::rows('SELECT /*+ MAX_EXECUTION_TIME(8000) */ id_material mid, change_type t, reason r, change_quantity_in_base_unit q, previous_balance pb, new_balance nb, id_stocktaking st, created_at at
          FROM material_inventory_log WHERE id_shop = ? AND created_at > ? AND created_at <= ? ORDER BY created_at, id', [$sid, $tA, $tB]) as $r) {
        $mid = (int) $r['mid']; $t = strtoupper((string) $r['t']); $re = strtoupper((string) $r['r']); $q = abs((float) $r['q']);
        $at = substr((string) $r['at'], 0, 16); $j = substr($at, 0, 10);
        if ($re === 'STOCKTAKING') { continue; }                     // le comptage lui-même : lu dans le résumé
        $mat($mid);
        if ($t === 'OUT' && $re === 'ERASE') { $m[$mid]['efface'] = ($m[$mid]['efface'] ?? 0.0) + $q; $m[$mid]['mouv'][] = [$at, 'attendu', round($q, 3)]; continue; }
        if ($t === 'IN') {
            $m[$mid]['entrees'] += $q; $m[$mid]['mouv'][] = [$at, $re === 'ORDER' ? 'livraison' : strtolower($re ?: 'entree'), round($q, 3)];
            $jours[$j]['entrees'][$mid] = ($jours[$j]['entrees'][$mid] ?? 0.0) + $q;
            continue;
        }
        if ($t === 'OUT') { $m[$mid]['sortiesDecl'] += $q; $m[$mid]['mouv'][] = [$at, 'perte ' . strtolower($re ?: 'matière'), -round($q, 3)]; continue; }
        // ADJUST hors inventaire : une correction à la main, dans le sens que dit le solde
        $d = is_numeric($r['nb']) && is_numeric($r['pb']) ? (float) $r['nb'] - (float) $r['pb'] : (float) $r['q'];
        $m[$mid]['corrections'] += $d; $m[$mid]['mouv'][] = [$at, 'correction', round($d, 3)];
    }
    foreach ($prixA as $mid => $p) { if (!isset($prix[$mid])) { $prix[$mid] = $p; } }
    // Les ventes de caisse, jour par jour et produit par produit (portions ramenées à la pièce)
    $ventes = []; $ca = []; $pidsV = [];
    foreach (Db::rows('SELECT /*+ MAX_EXECUTION_TIME(20000) */ DATE(t.insert_timestamp) j, l.id_product pid, l.product_portion_type pt, l.id_product_portion pp,
              SUM(l.quantity) q, SUM(l.total_gross_value_after_discount) v
            FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
           WHERE t.id_shop = ? AND t.insert_timestamp > ? AND t.insert_timestamp <= ?
           GROUP BY j, l.id_product, l.product_portion_type, l.id_product_portion', [$sid, $tA, $tB]) as $r) {
        $pid = (int) $r['pid'];
        if ($pid <= 0) { continue; }
        $f = invFraction($r['pt'] !== null ? (string) $r['pt'] : null, (int) ($r['pp'] ?? 0));
        $j = (string) $r['j'];
        $ventes[$j][$pid] = ($ventes[$j][$pid] ?? 0.0) + (float) $r['q'] * $f;
        $ca[$pid] = ($ca[$pid] ?? 0.0) + (float) $r['v'];
        $pidsV[$pid] = true;
    }
    // La poubelle déclarée en caisse
    $pertes = [];
    foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(8000) */ DATE(created_at) j, id_product pid, SUM(quantity) q FROM product_movement
          WHERE id_shop = ? AND movement_type = 'WASTE' AND created_at > ? AND created_at <= ? GROUP BY j, id_product", [$sid, $tA, $tB]) as $r) {
        $pid = (int) $r['pid'];
        if ($pid <= 0) { continue; }
        $pertes[(string) $r['j']][$pid] = ($pertes[(string) $r['j']][$pid] ?? 0.0) + abs((float) $r['q']);
    }
    // Les reports au lendemain (PRODUCTION carryover) : montrés, ils ne touchent pas le stock matière
    $reports = [];
    try {
        foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(8000) */ DATE(created_at) j, SUM(quantity) q FROM product_movement
              WHERE id_shop = ? AND movement_type = 'PRODUCTION' AND created_at > ? AND created_at <= ? GROUP BY j", [$sid, $tA, $tB]) as $r) {
            $reports[(string) $r['j']] = round((float) $r['q'], 1);
        }
    } catch (Throwable $e) { /* sans reports */ }
    return ['de' => ['id' => 'c' . $idA, 'at' => substr($tA, 0, 19)], 'a' => ['id' => 'c' . $idB, 'at' => substr($tB, 0, 19)], 'source' => 'copie',
        'matieres' => $m, 'prix' => $prix, 'ventes' => $ventes, 'ca' => $ca, 'pertes' => $pertes, 'jours' => $jours, 'reports' => $reports,
        'ventesCompletes' => true, 'notes' => []];
}

/**
 * Le calcul commun aux deux sources : de la base (comptages, mouvements, ventes, poubelle) aux
 * lignes par matière, aux totaux valorisés et à la suite des jours.
 */
function invCalcul(int $sid, array $b): array
{
    $m = $b['matieres'];
    $pids = [];
    foreach ([$b['ventes'], $b['pertes']] as $src) { foreach ($src as $lst) { foreach (array_keys($lst) as $p) { $pids[(int) $p] = true; } } }
    $rec = invRecettes(array_keys($pids));
    // ventes et poubelle ramenées aux matières : total, par jour, et par produit (qui a consommé quoi)
    $vM = []; $wM = []; $parProduit = []; $vJour = []; $wJour = []; $sansRecette = [];
    foreach ($b['ventes'] as $j => $lst) {
        foreach ($lst as $pid => $q) {
            $pid = (int) $pid;
            if (!isset($rec[$pid])) { $sansRecette[$pid] = ($sansRecette[$pid] ?? 0.0) + $q; continue; }
            foreach ($rec[$pid] as $mid => $qm) {
                $x = $q * $qm;
                $vM[$mid] = ($vM[$mid] ?? 0.0) + $x;
                $vJour[$j][$mid] = ($vJour[$j][$mid] ?? 0.0) + $x;
                $parProduit[$mid][$pid] = ($parProduit[$mid][$pid] ?? 0.0) + $x;
            }
        }
    }
    foreach ($b['pertes'] as $j => $lst) {
        foreach ($lst as $pid => $q) {
            $pid = (int) $pid;
            foreach ($rec[$pid] ?? [] as $mid => $qm) {
                $x = $q * $qm;
                $wM[$mid] = ($wM[$mid] ?? 0.0) + $x;
                $wJour[$j][$mid] = ($wJour[$j][$mid] ?? 0.0) + $x;
            }
        }
    }
    $infos = invMatieres(array_merge(array_keys($m), array_keys($vM)));
    $prix = $b['prix'] + invPrixListe($sid, array_merge(array_keys($m), array_keys($vM)));
    $noms = invNomsProduits(array_merge(array_keys($pids)));
    $lignes = [];
    $T = ['debut' => 0.0, 'entrees' => 0.0, 'pertesMatiere' => 0.0, 'fin' => 0.0, 'sorties' => 0.0, 'ventes' => 0.0, 'poubelle' => 0.0, 'explique' => 0.0, 'manquant' => 0.0, 'excedent' => 0.0, 'sansPrix' => 0];
    foreach ($m as $mid => $x) {
        $counted = $x['cA'] !== null || $x['cB'] !== null || $x['efface'] !== null;
        if (!$counted) { continue; }                                       // jamais comptée : hors inventaire
        $cA = (float) ($x['cA'] ?? 0); $cB = (float) ($x['cB'] ?? 0);
        $attendu = $x['efface'] ?? max(0.0, $cA + $x['entrees'] - $x['sortiesDecl'] + $x['corrections']);
        $sorties = $attendu - $cB;
        $v = $vM[$mid] ?? 0.0; $w = $wM[$mid] ?? 0.0; $e = $v + $w;
        if (abs($sorties) < 1e-9 && $e < 1e-9 && $cA < 1e-9 && $cB < 1e-9 && $x['entrees'] < 1e-9) { continue; }   // rien ne bouge
        $p = $prix[$mid] ?? null;
        $ecart = $sorties - $e;
        $info = $infos[$mid];
        $prods = $parProduit[$mid] ?? [];
        arsort($prods);
        $l = ['id' => $mid, 'nom' => $info['nom'], 'categorie' => $info['categorie'], 'unite' => $info['unite'], 'prix' => $p !== null ? round($p, 5) : null,
            'debut' => round($cA, 3), 'compteDebut' => $x['cA'] !== null, 'entrees' => round($x['entrees'], 3), 'pertesMatiere' => round($x['sortiesDecl'], 3), 'corrections' => round($x['corrections'], 3),
            'attendu' => round($attendu, 3), 'fin' => round($cB, 3), 'compteFin' => $x['cB'] !== null, 'sorties' => round($sorties, 3),
            'ventes' => round($v, 3), 'poubelle' => round($w, 3), 'explique' => round($e, 3), 'ecart' => round($ecart, 3),
            'justesse' => $sorties > 0 ? round(100 * $e / $sorties, 1) : null,
            'valeur' => $p !== null ? ['sorties' => round($sorties * $p, 2), 'explique' => round($e * $p, 2), 'ecart' => round($ecart * $p, 2)] : null,
            'produits' => array_map(static fn ($pid, $q) => ['id' => (int) $pid, 'nom' => $noms[(int) $pid] ?? ('Produit ' . $pid), 'q' => round($q, 3)], array_keys(array_slice($prods, 0, 6, true)), array_slice($prods, 0, 6, true)),
            'mouvements' => $x['mouv']];
        $lignes[] = $l;
        if ($p === null) { $T['sansPrix']++; continue; }
        $T['debut'] += $cA * $p; $T['entrees'] += $x['entrees'] * $p; $T['pertesMatiere'] += $x['sortiesDecl'] * $p; $T['fin'] += $cB * $p;
        $T['sorties'] += $sorties * $p; $T['ventes'] += $v * $p; $T['poubelle'] += $w * $p; $T['explique'] += $e * $p;
        if ($ecart > 0) { $T['manquant'] += $ecart * $p; } else { $T['excedent'] += -$ecart * $p; }
    }
    usort($lignes, static fn ($a, $b) => (abs($b['valeur']['ecart'] ?? 0) <=> abs($a['valeur']['ecart'] ?? 0)) ?: strcmp($a['nom'], $b['nom']));
    // Ce que la caisse a vendu sur des matières jamais comptées (emballages, boissons hors inventaire…)
    $horsInv = 0.0;
    foreach ($vM as $mid => $q) { if (!isset($m[$mid]) || ($m[$mid]['cA'] === null && $m[$mid]['cB'] === null && $m[$mid]['efface'] === null)) { $horsInv += $q * ($prix[$mid] ?? 0); } }
    // La suite des jours : livré, sorti par les ventes, jeté — valorisés
    $jours = [];
    $tousJours = array_unique(array_merge(array_keys($b['jours']), array_keys($vJour), array_keys($wJour), array_keys($b['reports'] ?? [])));
    sort($tousJours);
    foreach ($tousJours as $j) {
        $val = static function (array $l) use ($prix): float { $s = 0.0; foreach ($l as $mid => $q) { $s += $q * ($prix[$mid] ?? 0); } return $s; };
        $jours[] = ['date' => $j, 'livre' => round($val($b['jours'][$j]['entrees'] ?? []), 2), 'vendu' => round($val($vJour[$j] ?? []), 2),
            'jete' => round($val($wJour[$j] ?? []), 2), 'reports' => $b['reports'][$j] ?? null];
    }
    $sr = [];
    arsort($sansRecette);
    foreach (array_slice($sansRecette, 0, 12, true) as $pid => $q) { $sr[] = ['id' => (int) $pid, 'nom' => $noms[(int) $pid] ?? ('Produit ' . $pid), 'q' => round($q, 1), 'ca' => round((float) ($b['ca'][$pid] ?? 0), 2)]; }
    $caSans = 0.0; foreach ($sansRecette as $pid => $q) { $caSans += (float) ($b['ca'][$pid] ?? 0); }
    $caTot = array_sum(array_map('floatval', $b['ca']));
    $heures = (strtotime($b['a']['at']) - strtotime($b['de']['at'])) / 3600;
    $tot = [];
    foreach ($T as $k => $v) { $tot[$k] = is_float($v) ? round($v, 2) : $v; }
    $tot['ecart'] = round($T['sorties'] - $T['explique'], 2);
    $tot['justesse'] = $T['sorties'] > 0 ? round(100 * $T['explique'] / $T['sorties'], 1) : null;
    $tot['ventesSeules'] = $T['sorties'] > 0 ? round(100 * $T['ventes'] / $T['sorties'], 1) : null;
    $tot['ca'] = round($caTot, 2);
    $tot['caSansRecette'] = round($caSans, 2);
    $tot['horsInventaire'] = round($horsInv, 2);
    return ['shop' => $sid, 'de' => $b['de'], 'a' => $b['a'], 'source' => $b['source'], 'jours' => round($heures / 24, 1),
        'totaux' => $tot, 'matieres' => $lignes, 'suite' => $jours, 'sansRecette' => $sr, 'ventesCompletes' => $b['ventesCompletes'], 'notes' => $b['notes'],
        'methode' => 'Sorties réelles = compté au premier inventaire + livré − pertes matière déclarées − compté au second (le solde que le panel efface en ouvrant l’inventaire, moins le compté). Sorties expliquées = ventes de caisse × recette aplatie (portions ramenées à la pièce) + poubelle déclarée × recette. Valeurs au prix d’achat moyen HTVA de la matière (celui de l’inventaire).'];
}

/** Le résumé d'une paire, gardé pour l'historique : la copie ne bouge plus, une paire relevée se garde une fois ses ventes complètes. */
function invResume(array $r): array
{
    $t = $r['totaux'];
    return ['v' => INV_CACHE_V, 'de' => $r['de']['id'], 'a' => $r['a']['id'], 'deAt' => $r['de']['at'], 'aAt' => $r['a']['at'], 'jours' => $r['jours'],
        'justesse' => $t['justesse'], 'ventesSeules' => $t['ventesSeules'], 'sorties' => $t['sorties'], 'explique' => $t['explique'], 'ecart' => $t['ecart'],
        'manquant' => $t['manquant'], 'excedent' => $t['excedent'], 'complet' => (bool) $r['ventesCompletes'], 'le' => date('c')];
}

/** L'écart entre deux inventaires, quelle que soit leur source ; null si la paire ne se calcule pas. */
function invEcart(int $sid, string $de, string $a): ?array
{
    if (preg_match('/^c(\d+)$/', $de, $mA) && preg_match('/^c(\d+)$/', $a, $mB)) {
        $b = invBaseCopie($sid, (int) $mA[1], (int) $mB[1]);
        return $b === null ? null : invCalcul($sid, $b);
    }
    if (function_exists('invBaseReleve')) {
        $b = invBaseReleve($sid, $de, $a);
        return $b === null ? null : invCalcul($sid, $b);
    }
    return null;
}

/** GET /stock/inventaires?shop= — les inventaires du magasin et, pour chaque semaine, la justesse déjà calculée. */
function ep_stock_inventaires(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'paramètre shop requis']; }
    $l = invListe($sid);
    $paires = [];
    for ($i = 0; $i + 1 < count($l) && count($paires) < INV_PAIRES_MAX; $i++) {
        $B = $l[$i]; $A = $l[$i + 1];
        if ($A['source'] !== $B['source']) { continue; }                 // la copie et le relevé ne se raccordent pas
        $c = setting('invPaire:' . $sid . ':' . $A['id'] . ':' . $B['id']);
        $paires[] = ['de' => $A['id'], 'a' => $B['id'], 'deAt' => $A['at'], 'aAt' => $B['at'],
            'resume' => is_array($c) && (int) ($c['v'] ?? 0) === INV_CACHE_V ? $c : null];
    }
    $finCopie = null;
    foreach ($l as $x) { if ($x['source'] === 'copie') { $finCopie = $x['at']; break; } }
    return ['shop' => (string) $sid, 'inventaires' => $l, 'paires' => $paires, 'finCopie' => $finCopie,
        'releve' => function_exists('invReleveEtat') ? invReleveEtat($sid) : null];
}

/** GET /stock/inventaires/ecart?shop=&de=&a=[&resume=1] — le détail entre deux inventaires. */
function ep_stock_inventaires_ecart(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $de = (string) ($_GET['de'] ?? ''); $a = (string) ($_GET['a'] ?? '');
    if ($sid <= 0 || !preg_match('/^[cr]\d{1,12}$/', $de) || !preg_match('/^[cr]\d{1,12}$/', $a)) { http_response_code(400); return ['error' => 'shop, de et a requis']; }
    @set_time_limit(120);
    $r = invEcart($sid, $de, $a);
    if ($r === null) { http_response_code(404); return ['error' => 'inventaire introuvable pour ce magasin']; }
    if ($r['ventesCompletes']) { svGrave('invPaire:' . $sid . ':' . $r['de']['id'] . ':' . $r['a']['id'], invResume($r)); }
    if (!empty($_GET['resume'])) { return ['shop' => (string) $sid, 'resume' => invResume($r)]; }
    return $r;
}
