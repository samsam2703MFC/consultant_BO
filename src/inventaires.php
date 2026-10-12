<?php
declare(strict_types=1);

/**
 * Entre deux inventaires — l'écart de stock d'un magasin et la justesse de ses ventes
 * (demande du 12/10/2026).
 *
 * GET /stock/inventaires/sonde?shop=4 — ce que la copie locale du panel sait des inventaires
 * (stocktaking, stocktaking_item, stocktaking_summary), des mouvements matière et des tables
 * voisines : noms, colonnes, volumes, dernière date. Lecture seule, produits et matières
 * seulement — jamais un client.
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
    $dernier = is_array($out['inventaires'] ?? null) && isset($out['inventaires'][0]['id']) ? (int) $out['inventaires'][0]['id'] : 0;
    if ($dernier > 0) {
        $q('resumeExemple', 'SELECT m.id_material, mt.name matiere, m.previous_balance_in_base_unit avant, m.counted_quantity_in_base_unit compte, m.difference, m.avg_base_unit_price_net prix
            FROM stocktaking_summary m LEFT JOIN material mt ON mt.id = m.id_material WHERE m.id_stockatking = ? ORDER BY ABS(m.difference * m.avg_base_unit_price_net) DESC LIMIT 12', [$dernier]);
        $q('itemsExemple', 'SELECT i.id_material, mt.name matiere, i.pack_quantity, i.package_size_in_base_unit, i.total_quantity_in_base_unit, i.raw_code, i.pack_price_net
            FROM stocktaking_item i LEFT JOIN material mt ON mt.id = i.id_material WHERE i.id_stocktaking = ? LIMIT 8', [$dernier]);
    }
    return $out;
}
