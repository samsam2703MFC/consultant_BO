<?php
declare(strict_types=1);

/**
 * Commandes clients et livraisons.
 *
 * Ce que la base partagée porte vraiment, mesuré plutôt que supposé :
 *
 *  - `client_order` (9 670 lignes) — la commande passée au comptoir : un
 *    magasin, un client, une date de retrait, un statut, et les horodatages
 *    d'acceptation, d'achèvement et de remise. Son détail est dans
 *    `client_order_product`.
 *  - `ws_orders` (48 lignes) — la boutique en ligne : `mode` distingue le
 *    retrait de la livraison, `delivery_date` porte le jour, `delivered_at`
 *    la remise effective, et les sites de livraison sont dans
 *    `ws_office_delivery_sites`.
 *  - `material_order` (192 lignes) — la livraison qui ARRIVE : commande
 *    fournisseur avec date attendue (`expected_date`,
 *    `supplier_planned_delivery_date`) et date livrée (`delivered_on`).
 *
 * Le panel n'expose aucune de ces trois par son API (`/shops/{id}/orders`,
 * `/deliveries`, `/preorders` : 404 sur toutes). La lecture se fait donc en
 * base — mesuré le 16 septembre 2026.
 *
 * La sonde ci-dessous rend des COMPTES et des DATES, jamais le contenu d'une
 * ligne : une commande porte un nom et un téléphone, ils n'ont rien à faire
 * dans un diagnostic.
 */

/** GET /ventes/commandes/sonde — statuts, volumes et fraîcheur, par table. */
function ep_commandes_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 3);
    $out = ['shop' => $sid, 'aujourdhui' => date('Y-m-d'), 'tables' => []];
    // [table, colonne magasin, colonne statut, colonne date de référence]
    $plan = [
        ['client_order', 'id_shop', 'order_status', 'pick_up_datetime'],
        ['ws_orders', 'shop_id', 'status', 'delivery_date'],
        ['material_order', 'id_shop', 'status', 'expected_date'],
        ['pwa_orders', 'shop_id', 'state', 'created_at'],
    ];
    foreach ($plan as [$t, $cShop, $cEtat, $cDate]) {
        $bloc = ['nom' => $t, 'statuts' => [], 'bornes' => null, 'erreur' => null];
        try {
            $bloc['bornes'] = Db::rows("SELECT COUNT(*) n, MIN(`$cDate`) tot, MAX(`$cDate`) recent
                                         FROM `$t` WHERE `$cShop` = ?", [$sid])[0] ?? null;
            foreach (Db::rows("SELECT `$cEtat` etat, COUNT(*) n,
                                      SUM(`$cDate` >= CURDATE()) aVenir,
                                      MAX(`$cDate`) recent
                                 FROM `$t` WHERE `$cShop` = ?
                                GROUP BY `$cEtat` ORDER BY n DESC", [$sid]) as $r) {
                $bloc['statuts'][] = $r;
            }
            // Les autres colonnes intéressantes, mesurées et non devinées.
            if ($t === 'client_order') {
                $bloc['extra'] = Db::rows('SELECT COUNT(*) n,
                        SUM(completion_timestamp IS NULL) sansFin,
                        SUM(issuing_timestamp IS NULL) sansRemise,
                        SUM(non_collection_id_reason IS NOT NULL) nonRetirees,
                        SUM(DATE(pick_up_datetime) = CURDATE()) retraitAuj
                    FROM client_order WHERE id_shop = ?', [$sid])[0] ?? null;
            }
            if ($t === 'material_order') {
                $bloc['extra'] = Db::rows('SELECT COUNT(*) n,
                        SUM(delivered_on IS NULL) enRoute,
                        MAX(delivered_on) derniereLivraison,
                        MIN(CASE WHEN delivered_on IS NULL AND expected_date >= CURDATE()
                                 THEN expected_date END) prochaine
                    FROM material_order WHERE id_shop = ?', [$sid])[0] ?? null;
            }
            if ($t === 'ws_orders') {
                $bloc['extra'] = Db::rows('SELECT mode, COUNT(*) n, MAX(delivery_date) recent
                    FROM ws_orders WHERE shop_id = ? GROUP BY mode', [$sid]);
            }
        } catch (Throwable $e) {
            $bloc['erreur'] = $e->getMessage();
        }
        $out['tables'][] = $bloc;
    }
    // Le même compte sur TOUT le réseau : un magasin peut n'avoir rien reçu.
    try {
        $out['reseau'] = [
            'client_order' => Db::rows('SELECT id_shop, COUNT(*) n, MAX(pick_up_datetime) recent
                FROM client_order GROUP BY id_shop ORDER BY id_shop'),
            'ws_orders' => Db::rows('SELECT shop_id, COUNT(*) n, MAX(delivery_date) recent
                FROM ws_orders GROUP BY shop_id ORDER BY shop_id'),
            'material_order' => Db::rows('SELECT id_shop, COUNT(*) n, MAX(expected_date) attendue,
                MAX(delivered_on) livree FROM material_order GROUP BY id_shop ORDER BY id_shop'),
        ];
    } catch (Throwable $e) {
        $out['reseau'] = ['erreur' => $e->getMessage()];
    }
    return $out;
}
