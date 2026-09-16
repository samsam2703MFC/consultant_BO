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
 * « En cours » a un sens précis, et il a fallu le resserrer après mesure : la
 * commande n'a pas été remise (`issuing_timestamp` vide et statut différent de
 * `picked_up`), n'a pas été annulée (`non_collection_id_reason` vide) — ET sa
 * date de retrait tombe dans les huit derniers jours ou plus tard.
 *
 * Sans cette fenêtre, Gosselies afficherait « 114 commandes en retard »,
 * remontant jusqu'au 3 août 2025 : ce sont des fiches dont la remise n'a
 * jamais été enregistrée, pas des clients qu'on attend. Un écran qui crie 114
 * pour rien se fait ignorer en trois jours. Elles ne disparaissent pas pour
 * autant — `dormantes` les compte, et le tiroir le dit.
 *
 * L'API PASSE AVANT LA BASE, et c'est mesuré : `client_order` s'arrête au
 * 14 juillet 2026 à 10 h 55 pour les quatre magasins — la même coupure que
 * `transaction` et `product_movement`. Pendant ce temps
 * `/shops/4/client-orders` rend 588 commandes à Halle, dont une acceptée
 * aujourd'hui pour un retrait la semaine prochaine, là où la copie en compte
 * 431 et s'arrête au 22 juillet. Lire la copie, ici, c'est afficher un magasin
 * qui n'a rien commandé depuis deux mois alors qu'il prépare la semaine.
 *
 * Les livraisons fournisseur, elles, n'ont aucune route : elles restent lues
 * en base, et l'écran dit alors la date à laquelle la copie s'est arrêtée
 * plutôt que de faire passer un gel pour un calme.
 */
const CMD_FENETRE = 8;
/** La copie est relue au plus une fois par quart d'heure : l'API rend TOUTES
 * les commandes du magasin (7 245 à Corbais), ce n'est pas une lecture qu'on
 * refait à chaque ouverture de l'écran. */
const CMD_CACHE_MIN = 15;

/**
 * Les mêmes comptes, à partir de la liste rendue par le panel.
 *
 * On n'y prend que la date, le statut, le volume et le montant : la réponse
 * porte aussi le nom du client et celui de la vendeuse, ils s'arrêtent ici.
 *
 * @param list<array<string,mixed>> $l
 */
function cmdDepuisApi(array $l): array
{
    $auj = date('Y-m-d');
    $maintenant = date('Y-m-d H:i:s');
    $limite = date('Y-m-d', strtotime('-' . CMD_FENETRE . ' day'));
    $n = ['total' => count($l), 'enCours' => 0, 'auj' => 0, 'aVenir' => 0,
        'retard' => 0, 'dormantes' => 0, 'derniere' => null, 'montant' => 0.0, 'lignes' => []];
    foreach ($l as $c) {
        if (!is_array($c)) { continue; }
        $quand = (string) ($c['pick_up_datetime'] ?? '');
        if ($quand !== '' && ($n['derniere'] === null || $quand > $n['derniere'])) { $n['derniere'] = $quand; }
        $remise = ($c['issuing_timestamp'] ?? null) !== null
            || (string) ($c['order_status'] ?? '') === 'picked_up';
        $annulee = ($c['non_collection_id_reason'] ?? null) !== null;
        if ($remise || $annulee) { continue; }
        // La liste arrive déjà bornée par `date_from` ; ce filet ne sert que
        // si le panel devait un jour cesser de l'honorer.
        if ($quand === '' || substr($quand, 0, 10) < $limite) { $n['dormantes']++; continue; }
        $n['enCours']++;
        if (substr($quand, 0, 10) === $auj) { $n['auj']++; }
        elseif ($quand > $maintenant) { $n['aVenir']++; }
        else { $n['retard']++; }
        $montant = (float) ($c['total_value'] ?? 0);
        $n['montant'] += $montant;
        // Le détail n'accompagne pas toujours la liste : sans lui, le nombre
        // d'articles est INCONNU, et il vaut mieux un tiret qu'un zéro — un
        // zéro dirait « commande vide », ce qui est faux.
        $prods = (array) ($c['products'] ?? []);
        $articles = null;
        if ($prods !== []) {
            $q = 0.0; $aQte = false;
            foreach ($prods as $p) {
                if (is_array($p) && isset($p['quantity'])) { $q += (float) $p['quantity']; $aQte = true; }
            }
            $articles = $aQte ? $q : (float) count($prods);
        }
        $n['lignes'][] = ['quand' => $quand, 'statut' => $c['order_status'] ?? null,
            'articles' => $articles, 'montant' => round($montant, 2)];
    }
    usort($n['lignes'], static fn ($a, $b) => strcmp((string) $a['quand'], (string) $b['quand']));
    $n['lignes'] = array_slice($n['lignes'], 0, 20);
    $n['montant'] = round($n['montant'], 2);
    $n['fenetre'] = CMD_FENETRE;
    return $n;
}

function ep_ventes_commandes(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $out = ['shop' => $sid, 'commandes' => null, 'livraisons' => null];

    // --- l'état de la copie ----------------------------------------------
    // La caisse sert de battement de cœur : c'est elle qui dit jusqu'à quand
    // la base a été alimentée. Les livraisons n'ont pas d'autre source, donc
    // l'écran doit pouvoir dire « copie arrêtée le … » plutôt que de faire
    // passer un gel pour un calme.
    try {
        $c = Db::rows('SELECT MAX(insert_timestamp) le FROM `transaction`')[0]['le'] ?? null;
        $out['copie'] = ['arretee' => $c,
            'retard' => $c === null ? null : (int) floor((time() - strtotime((string) $c)) / 86400)];
    } catch (Throwable $e) { $out['copie'] = null; }

    // --- les commandes clients : l'API d'abord ---------------------------
    $cle = 'cmdApi:' . $sid;
    $memo = setting($cle);
    if (is_array($memo) && isset($memo['le'], $memo['v'])
        && (time() - (int) $memo['le']) < CMD_CACHE_MIN * 60) {
        $out['commandes'] = $memo['v'];
    } elseif (PanelApi::configured()) {
        // `date_from` EST supporté, et c'est lui qui rend la route utilisable :
        // mesuré à Corbais, l'appel nu expire à 30 s tandis que le même borné
        // à huit jours répond en 1,5 s avec 140 commandes. Les autres
        // écritures (`from`, `limit`, `per_page`) sont ignorées par le panel,
        // qui repart alors sur tout l'historique — donc expire aussi.
        $depuis = date('Y-m-d', strtotime('-' . CMD_FENETRE . ' day'));
        $r = PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $depuis, 25);
        if ((int) ($r['code'] ?? 0) === 200) {
            $v = cmdDepuisApi(analyseListe(is_array($r['corps']) ? $r['corps'] : []));
            $v['source'] = 'api';
            $v['depuis'] = $depuis;
            // La réponse est déjà bornée : ce qui dort au-delà de la fenêtre
            // n'y figure pas, et la date de la dernière commande non plus
            // quand il n'y en a eu aucune. La copie, elle, porte tout
            // l'historique jusqu'à son gel — elle complète les deux.
            try {
                $b = Db::rows("SELECT MAX(pick_up_datetime) derniere,
                        SUM(issuing_timestamp IS NULL
                            AND (order_status IS NULL OR order_status <> 'picked_up')
                            AND non_collection_id_reason IS NULL
                            AND pick_up_datetime < ?) dormantes
                      FROM client_order WHERE id_shop = ?", [$depuis, $sid])[0] ?? [];
                $d = (string) ($b['derniere'] ?? '');
                if ($d !== '' && ($v['derniere'] === null || $d > $v['derniere'])) { $v['derniere'] = $d; }
                $v['dormantes'] = $b['dormantes'] === null ? null : (int) $b['dormantes'];
            } catch (Throwable $e) { /* la fenêtre suffit */ }
            $out['commandes'] = $v;
            try {
                Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    [$cle, json_encode(['le' => time(), 'v' => $v])]);
            } catch (Throwable $e) { /* mémorisation best-effort */ }
        }
    }

    // --- repli sur la copie, quand le panel ne répond pas -----------------
    // Le montant vient du détail (`client_order_product`) : l'entête ne le
    // porte pas. Une commande sans ligne compte quand même, à 0 €.
    if ($out['commandes'] === null) { try {
        $ouverte = 'co.issuing_timestamp IS NULL
                    AND (co.order_status IS NULL OR co.order_status <> \'picked_up\')
                    AND co.non_collection_id_reason IS NULL';
        $fen = 'co.pick_up_datetime >= DATE_SUB(CURDATE(), INTERVAL ' . CMD_FENETRE . ' DAY)';
        $t = Db::rows("SELECT
                COUNT(*) total,
                SUM($ouverte AND $fen) enCours,
                SUM($ouverte AND $fen AND DATE(co.pick_up_datetime) = CURDATE()) auj,
                SUM($ouverte AND $fen AND co.pick_up_datetime > NOW()) aVenir,
                SUM($ouverte AND $fen AND co.pick_up_datetime < NOW()
                    AND DATE(co.pick_up_datetime) <> CURDATE()) retard,
                SUM($ouverte AND NOT $fen) dormantes,
                MAX(co.pick_up_datetime) derniere
              FROM client_order co WHERE co.id_shop = ?", [$sid])[0] ?? [];
        $lignes = [];
        foreach (Db::rows("SELECT co.id, co.pick_up_datetime quand, co.order_status statut,
                                  COALESCE(SUM(p.quantity), 0) articles,
                                  COALESCE(SUM(p.total_gross_value_after_discount), 0) montant
                             FROM client_order co
                             LEFT JOIN client_order_product p ON p.id_order = co.id
                            WHERE co.id_shop = ? AND $ouverte AND $fen
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
            'retard' => (int) ($t['retard'] ?? 0), 'dormantes' => (int) ($t['dormantes'] ?? 0),
            'fenetre' => CMD_FENETRE, 'derniere' => $t['derniere'] ?? null,
            'montant' => round($montant, 2), 'lignes' => $lignes, 'source' => 'base'];
    } catch (Throwable $e) {
        $out['commandes'] = ['indispo' => true, 'motif' => $e->getMessage()];
    } }

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

/**
 * GET /ventes/commandes/filtres?shop=2 — la route accepte-t-elle d'en rendre
 * moins ?
 *
 * `/shops/{id}/client-orders` rend tout l'historique : 7 245 commandes à
 * Corbais, au-delà de ce qu'un écran peut attendre. Si un paramètre borne la
 * réponse, l'écran devient immédiat ; sinon il faut vivre avec la mémoire de
 * quinze minutes. La sonde mesure le nombre de lignes ET le temps de chaque
 * écriture — c'est le temps qui tranche, pas l'existence d'une réponse.
 */
function ep_commandes_filtres(): array
{
    $sid = (int) ($_GET['shop'] ?? 2);
    if (!PanelApi::configured()) { return ['erreur' => 'compte panel non configuré']; }
    $du = date('Y-m-d', strtotime('-8 day'));
    $base = '/shops/' . $sid . '/client-orders';
    $cands = [$base, $base . '?date_from=' . $du, $base . '?from=' . $du,
        $base . '?pick_up_date_from=' . $du, $base . '?status=new',
        $base . '?limit=50', $base . '?per_page=50&page=1'];
    $out = ['shop' => $sid, 'depuis' => $du, 'essais' => []];
    foreach ($cands as $p) {
        $t0 = microtime(true);
        $r = PanelApi::sondeGet($p, 30);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $l = is_array($r['corps'] ?? null) ? analyseListe($r['corps']) : [];
        $recent = null;
        foreach ($l as $c) {
            $q = is_array($c) ? (string) ($c['pick_up_datetime'] ?? '') : '';
            if ($q !== '' && ($recent === null || $q > $recent)) { $recent = $q; }
        }
        $out['essais'][] = ['route' => $p, 'code' => (int) $r['code'], 'ms' => $ms,
            'n' => $l === [] ? null : count($l), 'plusRecent' => $recent];
    }
    return $out;
}

/**
 * GET /ventes/commandes/courbe — pourquoi plus de commandes depuis le 30 mai ?
 *
 * Une date d'arrêt ne dit rien à elle seule. Ce qui tranche, c'est la FORME de
 * l'arrêt, et elle se lit sur trois axes :
 *  - mois par mois et magasin par magasin : une falaise le même jour partout
 *    est une panne, un tarissement étalé est un usage qui se perd ;
 *  - les autres canaux (`ws_orders`, `pwa_orders`, `pwa_cafe_orders`) : si
 *    l'un démarre quand l'autre s'arrête, la commande a déménagé ;
 *  - la caisse (`transaction`) sur les mêmes mois, comme témoin : si elle
 *    s'arrête aussi, c'est la copie qui a lâché, pas le magasin.
 *
 * Comptes seulement, aucune ligne nominative.
 */
function ep_commandes_courbe(): array
{
    $out = ['aujourdhui' => date('Y-m-d'), 'canaux' => [], 'client_order' => [], 'temoins' => []];
    // 1. Les commandes au comptoir, par mois et par magasin, sur la date de
    //    PRISE (accepting_timestamp) et non de retrait : c'est le moment où
    //    quelqu'un a saisi quelque chose.
    try {
        foreach (Db::rows("SELECT id_shop,
                        DATE_FORMAT(COALESCE(accepting_timestamp, pick_up_datetime), '%Y-%m') mois,
                        COUNT(*) n,
                        COUNT(DISTINCT id_accepting_employee) agents,
                        SUM(id_transaction IS NOT NULL) encaissees,
                        SUM(order_status IS NULL) sansStatut
                   FROM client_order
                  WHERE COALESCE(accepting_timestamp, pick_up_datetime) >= DATE_SUB(CURDATE(), INTERVAL 20 MONTH)
                  GROUP BY id_shop, mois ORDER BY mois, id_shop") as $r) {
            $out['client_order'][] = $r;
        }
        $out['bornes'] = Db::rows('SELECT MIN(accepting_timestamp) premiere, MAX(accepting_timestamp) derniere,
                COUNT(*) total, SUM(accepting_timestamp IS NULL) sansPrise FROM client_order')[0] ?? null;
    } catch (Throwable $e) { $out['client_order'] = ['erreur' => $e->getMessage()]; }
    // 2. Les autres canaux : lequel prend le relais, et quand.
    foreach ([['ws_orders', 'created_at'], ['pwa_orders', 'created_at'],
              ['pwa_cafe_orders', 'created_at'], ['ws_stock_reservation', 'created_at']] as [$t, $c]) {
        try {
            $l = [];
            foreach (Db::rows("SELECT DATE_FORMAT(`$c`, '%Y-%m') mois, COUNT(*) n FROM `$t`
                               GROUP BY mois ORDER BY mois") as $r) { $l[] = $r; }
            $out['canaux'][] = ['table' => $t, 'mois' => $l];
        } catch (Throwable $e) { $out['canaux'][] = ['table' => $t, 'erreur' => $e->getMessage()]; }
    }
    // 3. Les témoins : la caisse et les avis sur les tâches, sur les mêmes
    //    mois. Ils disent si la base reçoit encore quelque chose.
    try {
        $l = [];
        foreach (Db::rows("SELECT DATE_FORMAT(insert_timestamp, '%Y-%m') mois,
                                  COUNT(DISTINCT ticket_key) n FROM `transaction`
                           WHERE insert_timestamp >= DATE_SUB(CURDATE(), INTERVAL 8 MONTH)
                           GROUP BY mois ORDER BY mois") as $r) { $l[] = $r; }
        $out['temoins'][] = ['table' => 'transaction', 'mois' => $l];
    } catch (Throwable $e) { $out['temoins'][] = ['table' => 'transaction', 'erreur' => $e->getMessage()]; }
    try {
        $l = [];
        foreach (Db::rows("SELECT DATE_FORMAT(created_at, '%Y-%m') mois, COUNT(*) n FROM mac_task_review
                           WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 8 MONTH)
                           GROUP BY mois ORDER BY mois") as $r) { $l[] = $r; }
        $out['temoins'][] = ['table' => 'mac_task_review', 'mois' => $l];
    } catch (Throwable $e) { $out['temoins'][] = ['table' => 'mac_task_review', 'erreur' => $e->getMessage()]; }
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
