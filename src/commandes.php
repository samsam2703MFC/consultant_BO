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
 * Côté API du panel, une seule des trois répond : `/shops/{id}/client-orders`,
 * et elle rend EXACTEMENT ce que porte la base — 337 commandes pour Gosselies,
 * la dernière au 30 mai 2026, des deux côtés. Les livraisons fournisseur n'ont
 * aucune route (`/material-orders`, `/deliveries` : 404). La lecture se fait
 * donc en base, qui est ici la source et non une copie en retard.
 *
 * Mesuré le 16 septembre 2026. Et ce que la mesure dit aussi, c'est que ce
 * réseau ne prend plus guère de commandes : la dernière remonte au 30 mai à
 * Gosselies, au 24 août à Corbais. L'écran doit donc savoir afficher « aucune »
 * sans avoir l'air en panne — d'où la date de la dernière, toujours rendue.
 *
 * Rien de nominatif ne sort d'ici : une commande porte un nom et un téléphone,
 * on n'en garde que la date, le nombre d'articles et le montant.
 */

/**
 * GET /ventes/commandes?shop=3 — les commandes clients en cours et les
 * livraisons fournisseur attendues, pour un magasin.
 *
 * « En cours » a un sens précis : la commande n'a pas été remise
 * (`issuing_timestamp` vide et statut différent de `picked_up`) et n'a pas été
 * annulée (`non_collection_id_reason` vide). Trois sous-ensembles comptent
 * pour l'écran — celles à retirer aujourd'hui, celles à venir, et celles dont
 * l'heure de retrait est passée sans que personne soit venu.
 */
function ep_ventes_commandes(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $out = ['shop' => $sid, 'commandes' => null, 'livraisons' => null];

    // --- les commandes clients -------------------------------------------
    // Le montant vient du détail (`client_order_product`) : l'entête ne le
    // porte pas. Une commande sans ligne compte quand même, à 0 €.
    try {
        $enCours = 'co.issuing_timestamp IS NULL
                    AND (co.order_status IS NULL OR co.order_status <> \'picked_up\')
                    AND co.non_collection_id_reason IS NULL';
        $t = Db::rows("SELECT
                COUNT(*) total,
                SUM($enCours) enCours,
                SUM($enCours AND DATE(co.pick_up_datetime) = CURDATE()) auj,
                SUM($enCours AND co.pick_up_datetime > NOW()) aVenir,
                SUM($enCours AND co.pick_up_datetime < NOW()
                    AND DATE(co.pick_up_datetime) <> CURDATE()) retard,
                MAX(co.pick_up_datetime) derniere
              FROM client_order co WHERE co.id_shop = ?", [$sid])[0] ?? [];
        $lignes = [];
        foreach (Db::rows("SELECT co.id, co.pick_up_datetime quand, co.order_status statut,
                                  COALESCE(SUM(p.quantity), 0) articles,
                                  COALESCE(SUM(p.total_gross_value_after_discount), 0) montant
                             FROM client_order co
                             LEFT JOIN client_order_product p ON p.id_order = co.id
                            WHERE co.id_shop = ? AND $enCours
                            GROUP BY co.id, co.pick_up_datetime, co.order_status
                            ORDER BY co.pick_up_datetime ASC LIMIT 20", [$sid]) as $r) {
            // Ni nom ni téléphone : la date, le volume, le montant.
            $lignes[] = ['quand' => (string) $r['quand'], 'statut' => $r['statut'],
                'articles' => (float) $r['articles'], 'montant' => round((float) $r['montant'], 2)];
        }
        $montant = 0.0;
        foreach ($lignes as $l) { $montant += $l['montant']; }
        $out['commandes'] = [
            'total' => (int) ($t['total'] ?? 0), 'enCours' => (int) ($t['enCours'] ?? 0),
            'auj' => (int) ($t['auj'] ?? 0), 'aVenir' => (int) ($t['aVenir'] ?? 0),
            'retard' => (int) ($t['retard'] ?? 0),
            'derniere' => $t['derniere'] ?? null,
            'montant' => round($montant, 2), 'lignes' => $lignes];
    } catch (Throwable $e) {
        $out['commandes'] = ['indispo' => true, 'motif' => $e->getMessage()];
    }

    // --- les livraisons fournisseur --------------------------------------
    // Le nom du fournisseur n'est pas dans `material_order` : il faut la table
    // de référence, dont le nom varie selon les versions du panel. On la
    // cherche plutôt que de la deviner.
    $tFourn = null;
    try {
        foreach (Db::rows("SELECT TABLE_NAME n FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'material\\_supplier%'
                            ORDER BY CHAR_LENGTH(TABLE_NAME) LIMIT 1") as $r) {
            $tFourn = (string) $r['n'];
        }
    } catch (Throwable $e) { /* on se passera du nom */ }
    try {
        $t = Db::rows("SELECT COUNT(*) total,
                SUM(delivered_on IS NULL AND status NOT IN ('ARCHIVED','CANCELLED')) enRoute,
                MIN(CASE WHEN delivered_on IS NULL AND status NOT IN ('ARCHIVED','CANCELLED')
                         THEN COALESCE(supplier_planned_delivery_date, expected_date,
                                       in_transit_expected_date) END) prochaine,
                MAX(delivered_on) derniere
              FROM material_order WHERE id_shop = ?", [$sid])[0] ?? [];
        $sel = $tFourn !== null ? 'f.name' : 'NULL';
        $join = $tFourn !== null ? "LEFT JOIN `$tFourn` f ON f.id = o.id_supplier" : '';
        $lignes = [];
        foreach (Db::rows("SELECT o.id, $sel fournisseur, o.status statut, o.order_date passee,
                                  COALESCE(o.supplier_planned_delivery_date, o.expected_date,
                                           o.in_transit_expected_date) attendue,
                                  (SELECT COUNT(*) FROM material_order_item i WHERE i.id_order = o.id) refs
                             FROM material_order o $join
                            WHERE o.id_shop = ? AND o.delivered_on IS NULL
                              AND o.status NOT IN ('ARCHIVED','CANCELLED')
                            ORDER BY attendue IS NULL, attendue ASC LIMIT 12", [$sid]) as $r) {
            $lignes[] = ['fournisseur' => $r['fournisseur'] !== null ? (string) $r['fournisseur'] : null,
                'statut' => (string) $r['statut'], 'passee' => $r['passee'],
                'attendue' => $r['attendue'], 'refs' => (int) $r['refs']];
        }
        $out['livraisons'] = ['total' => (int) ($t['total'] ?? 0), 'enRoute' => (int) ($t['enRoute'] ?? 0),
            'prochaine' => $t['prochaine'] ?? null, 'derniere' => $t['derniere'] ?? null,
            'lignes' => $lignes];
    } catch (Throwable $e) {
        $out['livraisons'] = ['indispo' => true, 'motif' => $e->getMessage()];
    }
    return $out;
}

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
    // Ce que l'API du panel accepte de servir : la base est en retard de deux
    // mois sur ces tables (cf. /audit/fraicheur), donc si l'écran doit dire
    // « en cours », la donnée doit venir d'une route, pas d'une copie.
    if (PanelApi::configured()) {
        $cands = [
            '/shops/' . $sid . '/client-orders',
            '/client-orders?shop_id=' . $sid,
            '/shops/' . $sid . '/orders/pending',
            '/shops/' . $sid . '/pickups',
            '/shops/' . $sid . '/material-orders',
            '/material-orders?shop_id=' . $sid,
            '/shops/' . $sid . '/material-orders/in-transit',
            '/shops/' . $sid . '/webshop/orders',
            '/webshop/orders?shop_id=' . $sid,
            '/shops/' . $sid . '/ws-orders',
            '/consultant/shops/' . $sid . '/client-orders',
            '/consultant/shops/' . $sid . '/deliveries',
            '/shops/' . $sid . '/material-requisitions',
        ];
        foreach ($cands as $p) {
            $r = PanelApi::sondeGet($p);
            $c = $r['corps'] ?? null;
            $l = is_array($c) ? analyseListe($c) : [];
            $ligne = ['route' => $p, 'code' => (int) $r['code'], 'n' => $l !== [] ? count($l) : null,
                'cles' => $l !== [] && is_array($l[0]) ? array_keys($l[0]) : null];
            // La FRAÎCHEUR, jamais le contenu : la plus récente des dates
            // portées par la réponse suffit à dire si la route sert le jour même.
            if ($l !== []) {
                $dates = [];
                foreach ($l as $e) {
                    if (!is_array($e)) { continue; }
                    foreach ($e as $k => $v) {
                        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) && preg_match('/date|_at|time/i', (string) $k)) {
                            $dates[$k] = max($dates[$k] ?? '', substr($v, 0, 10));
                        }
                    }
                }
                $ligne['plusRecent'] = $dates;
            }
            $out['panel'][] = $ligne;
        }
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
