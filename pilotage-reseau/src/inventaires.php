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
        // l'horodatage du stock courant (material_inventory.last_modified) face au grand livre : le même fuseau ?
        $q('modifStock', 'SELECT i.id_material, i.last_modified, (SELECT MAX(l.created_at) FROM material_inventory_log l WHERE l.id_shop = i.id_shop AND l.id_material = i.id_material) dernierLivre
              FROM material_inventory i WHERE i.id_shop = ? AND i.id_material IN (150, 148, 63, 48) ', [$sid]);
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

const INV_CACHE_V = 5;          // version du calcul : une paire gardée sous une autre version se recalcule
const INV_PAIRES_MAX = 16;      // l'historique : les seize dernières semaines au plus
const INV_RAPPORT_MAX = 6.0;    // vendu et sorti à plus de six fois l'un de l'autre : une unité ou une recette à vérifier, pas une perte

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

/**
 * L'unité de chaque matière telle que le panel la montre aujourd'hui (/shops/{id}/material-inventory) :
 * mid => g, ml ou pce. La copie a des matières mal unitées (des inserts de tarte en grammes) ; gardé une heure.
 */
function invUnitesLive(int $sid): array
{
    $c = setting('invUnites:' . $sid);
    if (is_array($c) && (int) ($c['quand'] ?? 0) > time() - 3600) { return (array) $c['u']; }
    $u = [];
    if (class_exists('PanelApi') && PanelApi::configured()) {
        foreach (analyseListe(PanelApi::get('/shops/' . $sid . '/material-inventory') ?? []) as $m) {
            $n = trim((string) ($m['unit_name'] ?? ''));
            if ((int) ($m['id_material'] ?? 0) > 0 && $n !== '') { $u[(int) $m['id_material']] = function_exists('rpUniteCourte') ? rpUniteCourte($n) : strtolower($n); }
        }
        if ($u !== []) { svGrave('invUnites:' . $sid, ['quand' => time(), 'u' => $u]); }
    }
    if ($u === [] && is_array($c)) { return (array) $c['u']; }
    return $u;
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
 * Ce que la copie sait du stock entre deux inventaires CONSÉCUTIFS A et B (identifiants de stocktaking) :
 * comptages, grand livre, solde attendu et sorties réelles par matière. null si un inventaire manque.
 */
function invStockCopie(int $sid, int $idA, int $idB): ?array
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
        if (!isset($m[$mid])) { $m[$mid] = ['cA' => null, 'cB' => null, 'entrees' => 0.0, 'sortiesDecl' => 0.0, 'corrections' => 0.0, 'efface' => null, 'apres' => 0.0, 'mouv' => []]; }
    };
    $nB = 0; $nBinchanges = 0;
    foreach (Db::rows('SELECT id_stockatking st, id_material mid, previous_balance_in_base_unit av, counted_quantity_in_base_unit c, difference d, avg_base_unit_price_net p FROM stocktaking_summary WHERE id_stockatking IN (?, ?)', [$idA, $idB]) as $r) {
        $mid = (int) $r['mid']; $mat($mid);
        $c = (float) $r['c'];
        if ((int) $r['st'] === $idA) { $m[$mid]['cA'] = ($m[$mid]['cA'] ?? 0.0) + $c; if (is_numeric($r['p']) && (float) $r['p'] > 0) { $prixA[$mid] = (float) $r['p']; } }
        else {
            $m[$mid]['cB'] = ($m[$mid]['cB'] ?? 0.0) + $c; if (is_numeric($r['p']) && (float) $r['p'] > 0) { $prix[$mid] = (float) $r['p']; }
            // le solde que le panel attendait au comptage (Corbais, Gosselies, Sombreffe : pas d'effacement, le résumé le porte)
            if (is_numeric($r['av'])) { $m[$mid]['avantB'] = ($m[$mid]['avantB'] ?? 0.0) + (float) $r['av']; }
            $nB++;
            if (abs((float) $r['d']) < 1e-9 && $c > 0) { $nBinchanges++; }
        }
    }
    $livr = []; $livrZero = [];
    $jours = [];
    // Le grand livre entre A et B : livraisons (IN), pertes matière déclarées (OUT hors effacement), corrections,
    // et l'effacement qui ouvre l'inventaire B — le solde que le panel attendait. Ce qui est livré ou corrigé
    // APRÈS l'effacement (pendant le comptage) s'y ajoute : `apres` le garde à part.
    foreach (Db::rows('SELECT /*+ MAX_EXECUTION_TIME(8000) */ id_material mid, change_type t, reason r, change_quantity_in_base_unit q, previous_balance pb, new_balance nb, id_stocktaking st, id_order o, created_at at
          FROM material_inventory_log WHERE id_shop = ? AND created_at > ? AND created_at <= ? ORDER BY created_at, id', [$sid, $tA, $tB]) as $r) {
        $mid = (int) $r['mid']; $t = strtoupper((string) $r['t']); $re = strtoupper((string) $r['r']); $q = abs((float) $r['q']);
        $at = substr((string) $r['at'], 0, 16); $j = substr($at, 0, 10);
        if ($re === 'STOCKTAKING') { continue; }                     // le comptage lui-même : lu dans le résumé
        $mat($mid);
        if ($t === 'OUT' && $re === 'ERASE') {
            $m[$mid]['efface'] = ($m[$mid]['efface'] ?? 0.0) + $q; $m[$mid]['apres'] = 0.0;
            $m[$mid]['mouv'][] = [$at, 'attendu', round($q, 3)];
            continue;
        }
        $d = 0.0;
        if ($t === 'IN') {
            if ($re === 'ORDER') { if ($q > 0) { $livr[(string) ($r['o'] ?? substr($at, 0, 13))] = true; } else { $livrZero[$mid] = true; } }
            $d = $q; $m[$mid]['entrees'] += $q; $m[$mid]['mouv'][] = [$at, $re === 'ORDER' ? 'livraison' : strtolower($re ?: 'entree'), round($q, 3)];
            $jours[$j]['entrees'][$mid] = ($jours[$j]['entrees'][$mid] ?? 0.0) + $q;
        } elseif ($t === 'OUT') {
            $d = -$q; $m[$mid]['sortiesDecl'] += $q; $m[$mid]['mouv'][] = [$at, 'perte ' . strtolower($re ?: 'matière'), -round($q, 3)];
        } else {
            // ADJUST hors inventaire : une correction à la main, dans le sens que dit le solde
            $d = is_numeric($r['nb']) && is_numeric($r['pb']) ? (float) $r['nb'] - (float) $r['pb'] : (float) $r['q'];
            $m[$mid]['corrections'] += $d; $m[$mid]['mouv'][] = [$at, 'correction', round($d, 3)];
        }
        if ($m[$mid]['efface'] !== null) { $m[$mid]['apres'] += $d; }
    }
    // Par matière : le solde attendu avant le comptage de B, et les sorties réelles. Une matière ni comptée ni
    // effacée en B n'était pas de l'inventaire : son stock court toujours, ses sorties ne se mesurent pas.
    foreach ($m as $mid => &$x) {
        $flux = $x['entrees'] - $x['sortiesDecl'] + $x['corrections'];
        if ($x['cB'] === null && $x['efface'] === null) { $x['sorties'] = null; $x['attendu'] = null; $x['debut'] = (float) ($x['cA'] ?? 0); continue; }
        $x['attendu'] = $x['efface'] !== null ? $x['efface'] + $x['apres']
            : (isset($x['avantB']) ? (float) $x['avantB'] : max(0.0, (float) ($x['cA'] ?? 0) + $flux));
        $x['sorties'] = $x['attendu'] - (float) ($x['cB'] ?? 0);
        // pas compté en A : le début se lit dans le solde du panel (ce qu'il attendait, moins ce qui est entré depuis)
        $x['debut'] = $x['cA'] !== null ? (float) $x['cA'] : max(0.0, $x['attendu'] - $flux);
    }
    unset($x);
    foreach ($prixA as $mid => $p) { if (!isset($prix[$mid])) { $prix[$mid] = $p; } }
    // ce qui dit si la semaine se lit : des livraisons enregistrées, aucune à zéro, un inventaire vraiment compté
    $qual = ['livraisons' => count($livr), 'livraisonsZero' => count($livrZero), 'compteesB' => $nB,
        'inchangeesB' => $nBinchanges, 'inventaireRecopie' => $nB >= 20 && $nBinchanges / $nB >= 0.6, 'effacement' => false];
    foreach ($m as $x) { if ($x['efface'] !== null) { $qual['effacement'] = true; break; } }
    return ['de' => ['id' => 'c' . $idA, 'at' => substr($tA, 0, 19)], 'a' => ['id' => 'c' . $idB, 'at' => substr($tB, 0, 19)], 'source' => 'copie',
        'matieres' => $m, 'prix' => $prix, 'jours' => $jours, 'qualite' => [$qual + ['de' => substr($tA, 0, 16), 'a' => substr($tB, 0, 16)]]];
}

/** Les ventes de caisse, la poubelle et les reports de la copie entre deux instants (heure locale). */
function invVentesCopie(int $sid, string $tA, string $tB): array
{
    // jour par jour et produit par produit (portions ramenées à la pièce)
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
    return ['ventes' => $ventes, 'ca' => $ca, 'pertes' => $pertes, 'reports' => $reports, 'ventesCompletes' => true, 'notes' => []];
}

/**
 * Plusieurs semaines bout à bout : chaque inventaire remet le solde du panel au compté, les sorties se
 * mesurent donc semaine par semaine et s'additionnent. Le début est le premier compté, la fin le dernier.
 */
function invFusionStock(array $bases): array
{
    $f = array_shift($bases);
    foreach ($bases as $b) {
        foreach ($b['matieres'] as $mid => $x) {
            if (!isset($f['matieres'][$mid])) { $f['matieres'][$mid] = $x; continue; }
            $y = &$f['matieres'][$mid];
            foreach (['entrees', 'sortiesDecl', 'corrections'] as $k) { $y[$k] += $x[$k]; }
            if ($x['sorties'] !== null) { $y['sorties'] = ($y['sorties'] ?? 0.0) + $x['sorties']; }
            if ($x['cB'] !== null || $x['efface'] !== null) { $y['cB'] = $x['cB']; $y['efface'] = $x['efface']; }
            if ($y['sorties'] !== null) { $y['attendu'] = (float) ($y['cB'] ?? 0) + $y['sorties']; }
            $y['mouv'] = array_merge($y['mouv'], $x['mouv']);
            unset($y);
        }
        foreach ($b['prix'] as $mid => $p) { $f['prix'][$mid] = $p; }
        foreach ($b['jours'] as $j => $e) {
            foreach ($e['entrees'] ?? [] as $mid => $q) { $f['jours'][$j]['entrees'][$mid] = ($f['jours'][$j]['entrees'][$mid] ?? 0.0) + $q; }
        }
        $f['a'] = $b['a'];
        $f['qualite'] = array_merge($f['qualite'] ?? [], $b['qualite'] ?? []);
    }
    return $f;
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
    // l'unité du panel d'aujourd'hui prime : g et kg ramenés au gramme, l et cl au millilitre, le reste en pièces
    foreach (invUnitesLive($sid) as $mid => $u) {
        if (!isset($infos[$mid])) { continue; }
        $infos[$mid]['unite'] = in_array($u, ['g', 'kg'], true) ? 'g' : (in_array($u, ['ml', 'cl', 'l'], true) ? 'ml' : 'pce');
    }
    $prix = $b['prix'] + invPrixListe($sid, array_merge(array_keys($m), array_keys($vM)));
    $noms = invNomsProduits(array_merge(array_keys($pids)));
    $lignes = [];
    $T = ['debut' => 0.0, 'entrees' => 0.0, 'pertesMatiere' => 0.0, 'fin' => 0.0, 'sorties' => 0.0, 'ventes' => 0.0, 'poubelle' => 0.0, 'explique' => 0.0, 'manquant' => 0.0, 'excedent' => 0.0, 'sansPrix' => 0,
        // la justesse ne se lit que sur les matières FIABLES : une matière qu'aucune vente ne consomme (recette
        // manquante), ou dont le vendu et le sorti ne sont pas du même ordre (unité, recette), se montre à part
        'fiableSorties' => 0.0, 'fiableExplique' => 0.0, 'fiableCouvert' => 0.0, 'sansRecetteSorties' => 0.0, 'aVerifier' => 0.0, 'nFiables' => 0, 'nSansRecette' => 0, 'nAVerifier' => 0, 'nSurplus' => 0];
    foreach ($m as $mid => $x) {
        if ($x['sorties'] === null) { continue; }                          // pas de l'inventaire B : ne se mesure pas
        $cA = (float) $x['debut']; $cB = (float) ($x['cB'] ?? 0);
        $attendu = (float) $x['attendu'];
        $sorties = (float) $x['sorties'];
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
            // fiable : sorti et vendu du même ordre ; sansRecette : sorti, aucune vente ne la consomme ; surplus : vendu sans
            // être sorti (livraison hors panel ?) ; aVerifier : un ordre de grandeur d'écart (unité, recette) ; calme : rien ne bouge
            'statut' => $sorties > 0 && $e > 0 ? (max($sorties / $e, $e / $sorties) >= INV_RAPPORT_MAX ? 'aVerifier' : 'fiable')
                : ($sorties > 0 ? 'sansRecette' : ($e > 0 ? 'surplus' : 'calme')),
            'valeur' => $p !== null ? ['sorties' => round($sorties * $p, 2), 'explique' => round($e * $p, 2), 'ecart' => round($ecart * $p, 2)] : null,
            'produits' => array_map(static fn ($pid, $q) => ['id' => (int) $pid, 'nom' => $noms[(int) $pid] ?? ('Produit ' . $pid), 'q' => round($q, 3)], array_keys(array_slice($prods, 0, 6, true)), array_slice($prods, 0, 6, true)),
            'mouvements' => $x['mouv']];
        $lignes[] = $l;
        if ($p === null) { $T['sansPrix']++; continue; }
        $T['debut'] += $cA * $p; $T['entrees'] += $x['entrees'] * $p; $T['pertesMatiere'] += $x['sortiesDecl'] * $p; $T['fin'] += $cB * $p;
        $T['sorties'] += $sorties * $p; $T['ventes'] += $v * $p; $T['poubelle'] += $w * $p; $T['explique'] += $e * $p;
        $st = $l['statut'];
        if ($st === 'fiable') {
            $T['fiableSorties'] += $sorties * $p; $T['fiableExplique'] += $e * $p; $T['nFiables']++;
            // la part couverte : ce que la caisse et la poubelle expliquent, au plus ce qui est sorti — une matière
            // vendue au-delà de son stock (livraison hors panel) ne rachète pas le manquant d'une autre
            $T['fiableCouvert'] += min($e, $sorties) * $p;
            if ($ecart > 0) { $T['manquant'] += $ecart * $p; } else { $T['excedent'] += -$ecart * $p; }
        } elseif ($st === 'sansRecette') { $T['sansRecetteSorties'] += $sorties * $p; $T['nSansRecette']++; }
        elseif ($st === 'surplus') { $T['excedent'] += $e * $p - max(0.0, $sorties) * $p; $T['nSurplus']++; }
        elseif ($st === 'aVerifier') { $T['aVerifier'] += abs($ecart) * $p; $T['nAVerifier']++; }
    }
    usort($lignes, static fn ($a, $b) => (abs($b['valeur']['ecart'] ?? 0) <=> abs($a['valeur']['ecart'] ?? 0)) ?: strcmp($a['nom'], $b['nom']));
    // Ce que la caisse a vendu sur des matières jamais comptées (emballages, boissons hors inventaire…)
    $horsInv = 0.0;
    foreach ($vM as $mid => $q) { if (!isset($m[$mid]) || $m[$mid]['sorties'] === null) { $horsInv += $q * ($prix[$mid] ?? 0); } }
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
    $tot['justesse'] = $T['fiableSorties'] > 0 ? round(100 * $T['fiableCouvert'] / $T['fiableSorties'], 1) : null;
    $tot['rapportVenduSorti'] = $T['fiableSorties'] > 0 ? round(100 * $T['fiableExplique'] / $T['fiableSorties'], 1) : null;
    $tot['justesseBrute'] = $T['sorties'] > 0 ? round(100 * $T['explique'] / $T['sorties'], 1) : null;
    $tot['ventesSeules'] = $T['sorties'] > 0 ? round(100 * $T['ventes'] / $T['sorties'], 1) : null;
    $tot['ca'] = round($caTot, 2);
    $tot['caSansRecette'] = round($caSans, 2);
    $tot['horsInventaire'] = round($horsInv, 2);
    return ['shop' => $sid, 'de' => $b['de'], 'a' => $b['a'], 'source' => $b['source'], 'jours' => round($heures / 24, 1), 'semaines' => (int) ($b['semaines'] ?? 1),
        'totaux' => $tot, 'matieres' => $lignes, 'suite' => $jours, 'sansRecette' => $sr, 'ventesCompletes' => $b['ventesCompletes'], 'notes' => $b['notes'],
        'qualite' => $b['qualite'] ?? [],
        'methode' => 'Sorties réelles = compté au premier inventaire + livré − pertes matière déclarées − compté au second (le solde que le panel attendait au comptage, moins le compté). Sorties expliquées = ventes de caisse × recette aplatie (portions ramenées à la pièce) + poubelle déclarée × recette. Justesse des ventes = part des sorties réelles que la caisse et la poubelle expliquent, matière par matière (au plus ce qui est sorti : une matière vendue au-delà de son stock ne rachète pas le manquant d’une autre), sur les matières fiables. Valeurs au prix d’achat moyen HTVA de la matière (celui de l’inventaire).'];
}

/** Le résumé d'une paire, gardé pour l'historique : la copie ne bouge plus, une paire relevée se garde une fois ses ventes complètes. */
function invResume(array $r): array
{
    $t = $r['totaux'];
    return ['v' => INV_CACHE_V, 'de' => $r['de']['id'], 'a' => $r['a']['id'], 'deAt' => $r['de']['at'], 'aAt' => $r['a']['at'], 'jours' => $r['jours'],
        'justesse' => $t['justesse'], 'ventesSeules' => $t['ventesSeules'], 'sorties' => $t['sorties'], 'explique' => $t['explique'], 'ecart' => $t['ecart'],
        'manquant' => $t['manquant'], 'excedent' => $t['excedent'], 'complet' => (bool) $r['ventesCompletes'], 'le' => date('c'),
        'fiableSorties' => $t['fiableSorties'], 'fiableCouvert' => $t['fiableCouvert'],
        // une semaine sans aucune livraison enregistrée (Corbais) ne se lit pas : l'écran la grise
        'livraisons' => array_sum(array_map(static fn ($q) => (int) ($q['livraisons'] ?? 0), $r['qualite'] ?? [])), 'nQualite' => count($r['qualite'] ?? [])];
}

/** L'écart entre deux inventaires, quelle que soit leur source ; null si la paire ne se calcule pas. */
function invEcart(int $sid, string $de, string $a): ?array
{
    if (preg_match('/^c\d+$/', $de) && preg_match('/^c\d+$/', $a)) {
        $l = array_reverse(invCopieListe($sid));                       // du plus ancien au plus récent
        $ids = array_column($l, 'id');
        $iA = array_search($de, $ids, true); $iB = array_search($a, $ids, true);
        if ($iA === false || $iB === false || $iA === $iB) { return null; }
        if ($iA > $iB) { [$iA, $iB] = [$iB, $iA]; }
        if ($iB - $iA > 26) { $iA = $iB - 26; }                        // six mois au plus
        $bases = [];
        for ($i = $iA; $i < $iB; $i++) {
            $x = invStockCopie($sid, (int) substr($ids[$i], 1), (int) substr($ids[$i + 1], 1));
            if ($x !== null) { $bases[] = $x; }
        }
        if ($bases === []) { return null; }
        $st = invFusionStock($bases);
        $v = invVentesCopie($sid, $st['de']['at'], $st['a']['at']);
        return invCalcul($sid, $st + $v + ['source' => 'copie', 'semaines' => count($bases)]);
    }
    if (function_exists('invBaseReleve')) {
        $b = invBaseReleve($sid, $de, $a);
        return $b === null ? null : invCalcul($sid, $b);
    }
    return null;
}

/**
 * L'historique : une fenêtre de quatre semaines glissantes par inventaire (une semaine seule est bruitée, les
 * livraisons tombent autour du comptage et passent d'une semaine à l'autre). Pour chaque inventaire B, le début A
 * est le dernier inventaire de même source passé 25 jours au moins avant lui. Les inventaires partiels
 * (moins de la moitié des matières d'un comptage habituel, Sombreffe) ne bornent pas de fenêtre.
 */
function invFenetres(array $l): array
{
    $ns = array_map(static fn ($x) => (int) $x['n'], $l);
    sort($ns);
    $med = $ns === [] ? 0 : $ns[intdiv(count($ns), 2)];
    $pleins = array_values(array_filter($l, static fn ($x) => (int) $x['n'] >= 0.5 * $med));
    $out = [];
    foreach ($pleins as $i => $B) {
        if (count($out) >= INV_PAIRES_MAX) { break; }
        $lim = date('Y-m-d H:i:s', strtotime($B['at']) - 25 * 86400);   // quatre intervalles hebdomadaires
        $A = null;
        for ($k = $i + 1; $k < count($pleins); $k++) {
            if ($pleins[$k]['source'] === $B['source'] && $pleins[$k]['at'] <= $lim) { $A = $pleins[$k]; break; }
        }
        if ($A !== null) { $out[] = [$A, $B]; }
    }
    return $out;
}

/** GET /stock/inventaires?shop= — les inventaires du magasin et, pour chaque fenêtre de quatre semaines, la justesse déjà calculée. */
function ep_stock_inventaires(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'paramètre shop requis']; }
    $l = invListe($sid);
    $paires = [];
    foreach (invFenetres($l) as [$A, $B]) {
        $c = setting('invPaire:' . $sid . ':' . $A['id'] . ':' . $B['id']);
        $paires[] = ['de' => $A['id'], 'a' => $B['id'], 'deAt' => $A['at'], 'aAt' => $B['at'],
            'resume' => is_array($c) && (int) ($c['v'] ?? 0) === INV_CACHE_V ? $c : null];
    }
    $finCopie = null;
    foreach ($l as $x) { if ($x['source'] === 'copie') { $finCopie = $x['at']; break; } }
    return ['shop' => (string) $sid, 'inventaires' => $l, 'paires' => $paires, 'finCopie' => $finCopie,
        'releve' => function_exists('invReleveEtat') ? invReleveEtat($sid) : null];
}

/**
 * Le calcul d'avance : à chaque passage du relevé, une fenêtre de quatre semaines qui n'est pas encore gardée se
 * calcule (une seule, et seulement s'il reste du temps) — l'historique de chaque magasin est prêt quand on l'ouvre.
 */
function invPrecalcul(array $shops, float $t0, float $budget = 50.0): array
{
    $faits = [];
    foreach ($shops as $sid) {
        foreach (invFenetres(invListe((int) $sid)) as [$A, $B]) {
            $c = setting('invPaire:' . $sid . ':' . $A['id'] . ':' . $B['id']);
            if (is_array($c) && (int) ($c['v'] ?? 0) === INV_CACHE_V) { continue; }
            if (microtime(true) - $t0 > $budget) { return $faits; }
            $r = invEcart((int) $sid, $A['id'], $B['id']);
            if ($r !== null && $r['ventesCompletes']) { svGrave('invPaire:' . $sid . ':' . $r['de']['id'] . ':' . $r['a']['id'], invResume($r)); }
            $faits[] = ['shop' => (int) $sid, 'de' => $A['id'], 'a' => $B['id'], 'justesse' => $r['totaux']['justesse'] ?? null];
        }
    }
    return $faits;
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

/* ---------- le relevé en direct : chaque inventaire du panel gardé dès qu'il passe ---------- */
/*
 * L'API du panel ne rend ni les inventaires passés, ni le grand livre, ni les livraisons : seulement le stock
 * courant de chaque matière et l'heure de sa dernière modification (/shops/{id}/material-inventory). Le cockpit
 * le relit toutes les cinq minutes (bin/stock_cron.sh) et garde ce qui a changé. Un inventaire, comme une
 * livraison, modifie d'un coup des dizaines de matières à la même seconde : une RAFALE. Une rafale où des
 * quantités baissent est un inventaire (le compté remplace le solde) ; une rafale qui ne fait que monter est
 * une livraison ; une rafale qui met tout à zéro ouvre un inventaire (l'effacement de Halle) et la rafale
 * suivante, qui pose le compté, est l'inventaire.
 *
 * Entre deux inventaires relevés, pour chaque matière comptée au second : sorties réelles = le solde juste
 * avant le comptage (avant l'effacement s'il y en a eu un) − le compté. Les ventes viennent des tickets du
 * panel heure par heure, la poubelle de /products/waste jour par jour.
 */

const INV_RAFALE = 15;          // un même horodatage sur quinze matières au moins : un inventaire ou une livraison
const INV_RELEVE_PAS = 240;     // un relevé toutes les quatre minutes au plus, qui que soit l'appelant
const INV_LECTURE_TICKETS = 1500;   // tickets lus au plus par calcul d'une paire relevée ; le reste au suivant

/** Les deux tables du relevé, créées au premier besoin. */
function invReleveTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_stock_releve (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NOT NULL, material_id INT NOT NULL,
        avant DECIMAL(14,3) NULL, qty DECIMAL(14,3) NOT NULL,
        modif DATETIME NULL, vu DATETIME NOT NULL, evenement_id BIGINT NULL,
        KEY k_shop_mat (shop_id, material_id, vu), KEY k_shop_vu (shop_id, vu), KEY k_ev (evenement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_stock_evenement (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NOT NULL, type VARCHAR(16) NOT NULL, at DATETIME NOT NULL,
        n INT NOT NULL DEFAULT 0, hausses INT NOT NULL DEFAULT 0, baisses INT NOT NULL DEFAULT 0, zeros INT NOT NULL DEFAULT 0,
        vu DATETIME NOT NULL,
        UNIQUE KEY u_shop_at_type (shop_id, at, type), KEY k_shop_at (shop_id, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Une matière du stock du panel : [mid, quantité, horodatage] ; null si elle n'est pas de l'inventaire. */
function invLigneStock(array $m): ?array
{
    if ((int) ($m['exist_in_inventory'] ?? 0) !== 1 || (int) ($m['id_material'] ?? 0) <= 0) { return null; }
    $mod = substr((string) ($m['last_modified'] ?? ''), 0, 19);
    return [(int) $m['id_material'], round((float) ($m['current_quantity'] ?? 0), 3), preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mod) ? $mod : null];
}

/** Enregistre un événement (une rafale ou un mouvement isolé) et rend son id. */
function invEvenement(int $sid, string $type, string $at, int $n, int $h, int $b, int $z, string $vu): int
{
    Db::exec('INSERT INTO ceo_stock_evenement (shop_id, type, at, n, hausses, baisses, zeros, vu) VALUES (?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE n = n + VALUES(n), hausses = hausses + VALUES(hausses), baisses = baisses + VALUES(baisses), zeros = zeros + VALUES(zeros)',
        [$sid, $type, $at, $n, $h, $b, $z, $vu]);
    $r = Db::row('SELECT id FROM ceo_stock_evenement WHERE shop_id = ? AND at = ? AND type = ?', [$sid, $at, $type]);
    return (int) ($r['id'] ?? 0);
}

/**
 * Un relevé d'un magasin : compare le stock du panel à l'état gardé, écrit ce qui a changé et reconnaît les
 * rafales. Le premier relevé pose l'état de départ et prend la dernière rafale pour un inventaire.
 */
function invReleverMagasin(int $sid, array $stock, string $vu): array
{
    invReleveTables();
    $cur = [];
    foreach (analyseListe($stock) as $m) { $l = invLigneStock((array) $m); if ($l !== null) { $cur[$l[0]] = [$l[1], $l[2]]; } }
    if ($cur === []) { return ['shop' => $sid, 'lu' => 0]; }
    $etat = setting('stockEtat:' . $sid);
    $prev = is_array($etat) && is_array($etat['m'] ?? null) ? $etat['m'] : null;
    $out = ['shop' => $sid, 'lu' => count($cur), 'changes' => 0, 'evenements' => []];
    if ($prev === null) {
        // départ : l'état entier, et comme premier inventaire la plus grosse rafale des huit derniers jours
        // (un inventaire touche plus de matières qu'une livraison : 60 à 80 contre une quarantaine)
        $parModif = [];
        foreach ($cur as $mid => [$q, $mod]) { if ($mod !== null) { $parModif[$mod][] = $mid; } }
        $base = null; $nMax = 0;
        foreach ($parModif as $mod => $ids) {
            if (count($ids) >= INV_RAFALE && count($ids) > $nMax && $mod >= date('Y-m-d H:i:s', strtotime($vu) - 8 * 86400)) { $base = (string) $mod; $nMax = count($ids); }
        }
        $ev = $base !== null ? invEvenement($sid, 'inventaire', $base, count($parModif[$base]), 0, 0, 0, $vu) : 0;
        foreach ($cur as $mid => [$q, $mod]) {
            Db::exec('INSERT INTO ceo_stock_releve (shop_id, material_id, avant, qty, modif, vu, evenement_id) VALUES (?,?,?,?,?,?,?)',
                [$sid, $mid, null, $q, $mod, $vu, ($base !== null && $mod === $base) ? $ev : null]);
        }
        svGrave('stockEtat:' . $sid, ['quand' => time(), 'depuis' => $vu, 'm' => $cur]);
        $out['depart'] = true; $out['inventaire'] = $base;
        return $out;
    }
    // ce qui a changé, groupé par horodatage du panel
    $grp = [];
    foreach ($cur as $mid => [$q, $mod]) {
        $p = $prev[$mid] ?? null;
        if ($p !== null && abs((float) $p[0] - $q) < 1e-6 && (string) ($p[1] ?? '') === (string) ($mod ?? '')) { continue; }
        $grp[(string) ($mod ?? $vu)][$mid] = [$p !== null ? (float) $p[0] : null, $q, $mod];
    }
    ksort($grp);
    foreach ($grp as $mod => $lst) {
        $n = count($lst); $h = 0; $b = 0; $z = 0;
        foreach ($lst as [$av, $q]) {
            if ($av === null || $q > $av + 1e-9) { $h++; } elseif ($q < $av - 1e-9) { $b++; }
            if ($q == 0.0 && ($av ?? 0) > 0) { $z++; }
        }
        if ($n >= INV_RAFALE) {
            if ($z >= 0.8 * $n) { $type = 'effacement'; }
            elseif ($b === 0 && $z === 0) {
                // que des hausses : une livraison — sauf si l'inventaire vient d'être ouvert par un effacement
                $eff = Db::row("SELECT id FROM ceo_stock_evenement WHERE shop_id = ? AND type = 'effacement' AND at >= ? AND at <= ? ORDER BY at DESC LIMIT 1",
                    [$sid, date('Y-m-d H:i:s', strtotime($mod) - 6 * 3600), $mod]);
                $type = $eff !== null ? 'inventaire' : 'livraison';
            } else { $type = 'inventaire'; }
        } else { $type = 'mouvement'; }
        $ev = invEvenement($sid, $type, $mod, $n, $h, $b, $z, $vu);
        foreach ($lst as $mid => [$av, $q, $md]) {
            Db::exec('INSERT INTO ceo_stock_releve (shop_id, material_id, avant, qty, modif, vu, evenement_id) VALUES (?,?,?,?,?,?,?)', [$sid, $mid, $av, $q, $md, $vu, $ev]);
        }
        $out['changes'] += $n;
        $out['evenements'][] = ['type' => $type, 'at' => $mod, 'n' => $n];
    }
    svGrave('stockEtat:' . $sid, ['quand' => time(), 'depuis' => $etat['depuis'] ?? $vu, 'm' => $cur]);
    return $out;
}

/** GET /stock/inventaires/releve — un relevé de tous les magasins ; quatre minutes au moins entre deux (le cron passe toutes les cinq). */
function ep_stock_inventaires_releve(): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    $c = setting('stockReleveQuand');
    if (is_numeric($c) && (int) $c > time() - INV_RELEVE_PAS && empty($_GET['force'])) { return ['ok' => true, 'attente' => INV_RELEVE_PAS - (time() - (int) $c)]; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['stockReleveQuand', json_encode(time())]);
    @set_time_limit(110);
    $t0 = microtime(true);
    try { $shops = array_map(static fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); } catch (Throwable $e) { $shops = []; }
    $chemins = [];
    foreach ($shops as $sid) { $chemins[$sid] = '/shops/' . $sid . '/material-inventory'; }
    $vu = date('Y-m-d H:i:s');
    $out = [];
    foreach (PanelApi::getParallele($chemins) as $sid => $r) {
        if (!is_array($r)) { $out[] = ['shop' => $sid, 'erreur' => 'le panel n’a pas répondu']; continue; }
        try { $out[] = invReleverMagasin((int) $sid, $r, $vu); }
        catch (Throwable $e) { $out[] = ['shop' => $sid, 'erreur' => mb_substr($e->getMessage(), 0, 160)]; }
    }
    // le temps qui reste : une fenêtre de l'historique d'un magasin se calcule d'avance
    $pre = null;
    try { $pre = invPrecalcul($shops, $t0); } catch (Throwable $e) { $pre = ['erreur' => mb_substr($e->getMessage(), 0, 160)]; }
    return ['ok' => true, 'vu' => $vu, 'magasins' => $out, 'precalcul' => $pre];
}

/** Les inventaires relevés en direct pour un magasin : [{id: 'r12', at, n, source: 'releve'}], du plus récent au plus ancien. */
function invReleveListe(int $sid): array
{
    try {
        invReleveTables();
        $rows = Db::rows("SELECT id, at, n FROM ceo_stock_evenement WHERE shop_id = ? AND type = 'inventaire' ORDER BY at DESC", [$sid]);
    } catch (Throwable $e) { return []; }
    return array_map(static fn ($r) => ['id' => 'r' . (int) $r['id'], 'at' => (string) $r['at'], 'n' => (int) $r['n'], 'source' => 'releve', 'type' => 'relevé'], $rows);
}

/** L'état du relevé d'un magasin : depuis quand, le dernier passage, les derniers événements. */
function invReleveEtat(int $sid): array
{
    $e = setting('stockEtat:' . $sid);
    $evs = [];
    try {
        invReleveTables();
        foreach (Db::rows('SELECT type, at, n FROM ceo_stock_evenement WHERE shop_id = ? AND type <> ? ORDER BY at DESC LIMIT 8', [$sid, 'mouvement']) as $r) {
            $evs[] = ['type' => (string) $r['type'], 'at' => (string) $r['at'], 'n' => (int) $r['n']];
        }
    } catch (Throwable $e2) { /* pas encore de relevé */ }
    $q = setting('stockReleveQuand');
    return ['actif' => is_array($e), 'depuis' => is_array($e) ? ($e['depuis'] ?? null) : null,
        'dernier' => is_numeric($q) ? date('Y-m-d H:i:s', (int) $q) : null, 'evenements' => $evs];
}

/**
 * La base d'une paire d'inventaires relevés (même forme que la copie) : par matière comptée au second, le solde
 * juste avant le comptage et le compté ; les hausses entre les deux sont les livraisons. Les ventes et la poubelle
 * viennent du panel ; `ventesCompletes` dit si tous les tickets ont pu être lus.
 */
function invBaseReleve(int $sid, string $de, string $a): ?array
{
    if (!preg_match('/^r(\d+)$/', $de, $mA) || !preg_match('/^r(\d+)$/', $a, $mB)) { return null; }
    try {
        invReleveTables();
        $eA = Db::row("SELECT id, at FROM ceo_stock_evenement WHERE id = ? AND shop_id = ? AND type = 'inventaire'", [(int) $mA[1], $sid]);
        $eB = Db::row("SELECT id, at FROM ceo_stock_evenement WHERE id = ? AND shop_id = ? AND type = 'inventaire'", [(int) $mB[1], $sid]);
    } catch (Throwable $e) { return null; }
    if ($eA === null || $eB === null) { return null; }
    if ((string) $eA['at'] > (string) $eB['at']) { [$eA, $eB] = [$eB, $eA]; }
    $tA = (string) $eA['at']; $tB = (string) $eB['at'];
    $m = []; $jours = [];
    $mat = static function (int $mid) use (&$m): void {
        if (!isset($m[$mid])) { $m[$mid] = ['cA' => null, 'cB' => null, 'entrees' => 0.0, 'sortiesDecl' => 0.0, 'corrections' => 0.0, 'efface' => null, 'apres' => 0.0, 'mouv' => [], 'avantB' => null]; }
    };
    // le compté en A
    foreach (Db::rows('SELECT material_id mid, qty FROM ceo_stock_releve WHERE shop_id = ? AND evenement_id = ?', [$sid, (int) $eA['id']]) as $r) {
        $mat((int) $r['mid']); $m[(int) $r['mid']]['cA'] = (float) $r['qty'];
    }
    // entre A et B : livraisons (hausses), effacements, mouvements isolés ; puis le comptage de B
    foreach (Db::rows("SELECT r.material_id mid, r.avant, r.qty, r.modif, r.vu, e.type, e.id ev FROM ceo_stock_releve r JOIN ceo_stock_evenement e ON e.id = r.evenement_id
          WHERE r.shop_id = ? AND e.at > ? AND e.at <= ? ORDER BY e.at, r.id", [$sid, $tA, $tB]) as $r) {
        $mid = (int) $r['mid']; $mat($mid);
        $av = $r['avant'] !== null ? (float) $r['avant'] : null; $q = (float) $r['qty'];
        $at = substr((string) ($r['modif'] ?? $r['vu']), 0, 16); $j = substr($at, 0, 10);
        if ((int) $r['ev'] === (int) $eB['id']) {
            $m[$mid]['cB'] = $q;
            if ($m[$mid]['efface'] === null) { $m[$mid]['avantB'] = $av; }
            continue;
        }
        if ($r['type'] === 'effacement') { $m[$mid]['efface'] = ($m[$mid]['efface'] ?? 0.0) + (float) ($av ?? 0); $m[$mid]['apres'] = 0.0; $m[$mid]['mouv'][] = [$at, 'attendu', round((float) ($av ?? 0), 3)]; continue; }
        $d = $q - (float) ($av ?? 0);
        if ($d > 0) { $m[$mid]['entrees'] += $d; $m[$mid]['mouv'][] = [$at, 'livraison', round($d, 3)]; $jours[$j]['entrees'][$mid] = ($jours[$j]['entrees'][$mid] ?? 0.0) + $d; }
        elseif ($d < 0) { $m[$mid]['corrections'] += $d; $m[$mid]['mouv'][] = [$at, 'correction', round($d, 3)]; }
        if ($m[$mid]['efface'] !== null) { $m[$mid]['apres'] += $d; }
    }
    foreach ($m as $mid => &$x) {
        $flux = $x['entrees'] - $x['sortiesDecl'] + $x['corrections'];
        if ($x['cB'] === null) { $x['sorties'] = null; $x['attendu'] = null; $x['debut'] = (float) ($x['cA'] ?? 0); continue; }
        $x['attendu'] = $x['efface'] !== null ? $x['efface'] + $x['apres'] : ($x['avantB'] !== null ? (float) $x['avantB'] : max(0.0, (float) ($x['cA'] ?? 0) + $flux));
        $x['sorties'] = $x['attendu'] - (float) $x['cB'];
        $x['debut'] = $x['cA'] !== null ? (float) $x['cA'] : max(0.0, $x['attendu'] - $flux);
    }
    unset($x);
    // les prix d'aujourd'hui : la liste des besoins du panel porte le prix net par unité de base
    $prix = [];
    foreach (analyseListe(PanelApi::get('/shops/' . $sid . '/material-requisitions/list') ?? []) as $r) {
        if ((int) ($r['id_material'] ?? 0) > 0 && is_numeric($r['base_unit_price_net'] ?? null) && (float) $r['base_unit_price_net'] > 0) { $prix[(int) $r['id_material']] = (float) $r['base_unit_price_net']; }
    }
    // les ventes : tickets du panel, heure par heure, de l'heure de A à l'heure de B
    $ventes = []; $ca = []; $cout = 0; $lus = 0; $manque = [];
    $dA = substr($tA, 0, 10); $dB = substr($tB, 0, 10); $hA = (int) substr($tA, 11, 2); $hB = (int) substr($tB, 11, 2);
    for ($d = $dA; $d <= $dB; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $p = function_exists('svProduitsJour') ? svProduitsJour($sid, $d, $cout, INV_LECTURE_TICKETS) : null;
        if ($p === null) { $manque[] = $d; continue; }
        $lus++;
        foreach ($p as $h => $lst) {
            $h = (int) $h;
            if (($d === $dA && $h < $hA) || ($d === $dB && $h >= $hB)) { continue; }
            foreach ($lst as $k => $x) {
                $pid = (int) explode(':', (string) $k)[0];
                $f = 1.0;
                if (str_contains((string) $k, ':') && function_exists('svPortionDuNom')) { $pp = svPortionDuNom((string) $x[0]); $f = isset($pp['f']) && (float) $pp['f'] > 0 ? (float) $pp['f'] : 1.0; }
                $ventes[$d][$pid] = ($ventes[$d][$pid] ?? 0.0) + (float) $x[1] * $f;
                $ca[$pid] = ($ca[$pid] ?? 0.0) + (float) $x[2];
            }
        }
    }
    // la poubelle : jour par jour (la poubelle se déclare le soir — le jour de A compte si A est passé avant 17 h,
    // le jour de B si B est passé après 18 h 30)
    $pertes = [];
    $ch = [];
    for ($d = $dA; $d <= $dB; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($d === $dA && $tA >= $dA . ' 17:00:00') { continue; }
        if ($d === $dB && $tB < $dB . ' 18:30:00') { continue; }
        $ch[$d] = '/shops/' . $sid . '/products/waste?' . http_build_query(['from' => $d, 'date_from' => $d, 'to' => $d, 'date_to' => $d]);
    }
    foreach (PanelApi::getParallele($ch) as $d => $r) {
        $lst = is_array($r) && isset($r['products']) && is_array($r['products']) ? $r['products'] : analyseListe($r);
        foreach ($lst as $w) {
            $pid = (int) ($w['id_product'] ?? 0); $q = (float) ($w['waste_qty'] ?? 0);
            if ($pid > 0 && $q > 0) { $pertes[(string) $d][$pid] = ($pertes[(string) $d][$pid] ?? 0.0) + $q; }
        }
    }
    $notes = [];
    if ($manque !== []) { $notes[] = 'Tickets pas encore lus pour ' . count($manque) . ' jour(s) (' . implode(', ', $manque) . ') : relancer le calcul les lira.'; }
    return ['de' => ['id' => 'r' . (int) $eA['id'], 'at' => $tA], 'a' => ['id' => 'r' . (int) $eB['id'], 'at' => $tB], 'source' => 'releve',
        'matieres' => $m, 'prix' => $prix, 'jours' => $jours, 'ventes' => $ventes, 'ca' => $ca, 'pertes' => $pertes, 'reports' => [],
        'ventesCompletes' => $manque === [], 'notes' => $notes, 'qualite' => []];
}
