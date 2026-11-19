<?php
declare(strict_types=1);

/**
 * La commande d'échantillon dans le système de commande du magasin (portail Commercial, 19/11/2026, demande :
 * « Commander un échantillon doit fonctionner comme une commande pour les shops (prendre les produits et catégories du
 * shop) et faire le POST comme une commande spécifique »).
 *
 *  - Le catalogue : les produits du magasin et leurs catégories, tels que TF Buddy les rend
 *    (GET /shops/{id}/products/available, par PanelApi::produitsDisponibles) : nom, catégorie, prix de vente, coût de
 *    la recette, livrable au bureau. GET /commercial/catalogue-magasin?shop=2.
 *  - La commande : POST /client-orders de TF Buddy (contrat de son API : id_shop, id_client, comment, pick_up_datetime,
 *    id_order_status, products[id_product, name, quantity, unit_gross_price, item_discount_value,
 *    total_gross_value_after_discount] ; corps en formulaire, comme le documente l'API), avec le compte admin du
 *    cockpit (ErpApi). Ce qui en fait une commande « échantillon » : le commentaire commence par
 *    « ÉCHANTILLON COMMERCIAL », chaque ligne est offerte (remise égale à sa valeur, total 0 €), sans client de caisse ;
 *    elle arrive « nouvelle » (statut 1) dans les commandes du magasin, à préparer pour la date dite.
 *  - Le numéro de la commande TF Buddy est gardé sur l'échantillon (sm_echantillon.commande_id) ; une erreur aussi
 *    (commande_erreur), et la commande se relance d'ici.
 */

const SM_CMD_MARQUE = 'ÉCHANTILLON COMMERCIAL';
const SM_CMD_STATUT_NOUVELLE = 1;   // id_order_status : 1 new, 2 completed, 3 picked_up, 4 unpicked, 5 in_production

/** Le catalogue lu chez TF Buddy, mis à plat : les produits actifs, rangés par catégorie. Fonction pure. */
function smCatalogueDe(array $brut): array
{
    $nombre = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
    $cats = [];
    foreach ($brut as $p) {
        if (!is_array($p) || (int) ($p['id'] ?? 0) <= 0 || isset($p['is_active']) && !(int) $p['is_active']) { continue; }
        $cat = trim((string) ($p['category_name'] ?? $p['base_category_name'] ?? '')) ?: 'Sans catégorie';
        $catId = (int) ($p['id_category'] ?? 0);
        $prix = $nombre($p['portion_price_gross'] ?? $p['portion_price'] ?? null);
        $cats[$cat] ??= ['id' => $catId, 'nom' => $cat, 'produits' => []];
        $cats[$cat]['produits'][] = ['id' => (int) $p['id'], 'nom' => trim((string) ($p['name'] ?? $p['base_name'] ?? '')), 'prix' => $prix !== null ? round($prix, 2) : null,
            'cout' => ($c = $nombre($p['recipe_cost_net'] ?? null)) !== null && $c > 0 ? round($c, 4) : null, 'tva' => $nombre($p['tax_val_perc'] ?? null),
            'bureau' => !empty($p['office_delivery']), 'webshop' => !empty($p['webshop_active'])];
    }
    foreach ($cats as &$c) { usort($c['produits'], static fn ($a, $b) => strcasecmp($a['nom'], $b['nom'])); }
    unset($c);
    $cats = array_values($cats);
    usort($cats, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
    return $cats;
}

/** Les produits du catalogue, par numéro : [id => produit + nom de la catégorie]. Fonction pure. */
function smCatalogueIndex(array $categories): array
{
    $out = [];
    foreach ($categories as $c) { foreach ($c['produits'] as $p) { $out[$p['id']] = $p + ['cat' => $c['nom']]; } }
    return $out;
}

/** Le catalogue d'un magasin (null : TF Buddy ne répond pas). */
function smCatalogueMagasin(string $shop): ?array
{
    if (!ctype_digit($shop) || !class_exists('PanelApi') || !PanelApi::configured()) { return null; }
    $l = PanelApi::produitsDisponibles((int) $shop);
    return $l === [] ? null : smCatalogueDe($l);
}

/** GET /commercial/catalogue-magasin?shop=2 — les catégories et les produits du magasin, pour commander un échantillon. */
function ep_sm_catalogue_magasin(): array
{
    $shop = (string) ($_GET['shop'] ?? '');
    if (!isset(smMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $c = smCatalogueMagasin($shop);
    if ($c === null) { http_response_code(502); return ['error' => 'le catalogue du magasin ne se lit pas chez TF Buddy (réessayez, ou notez les produits à la main)']; }
    return ['shop' => $shop, 'categories' => $c, 'n' => array_sum(array_map(static fn ($x) => count($x['produits']), $c)), 'source' => 'TF Buddy : produits du magasin (GET /shops/{id}/products/available)'];
}

/**
 * Les lignes demandées ({id, q}), vérifiées contre le catalogue du magasin : [lignes, erreur]. Une ligne garde le nom,
 * la catégorie, le prix et le coût du catalogue. Fonction pure.
 */
function smLignesCatalogue(array $demandees, array $index): array
{
    $out = [];
    foreach (array_slice($demandees, 0, 60) as $l) {
        if (!is_array($l)) { continue; }
        $id = (int) ($l['id'] ?? 0); $q = is_numeric($l['q'] ?? null) ? (float) $l['q'] : 0.0;
        if ($id <= 0 || $q <= 0) { continue; }
        if ($q > 200) { return [[], 'quantité trop grande pour un échantillon (200 au plus par produit)']; }
        if (!isset($index[$id])) { return [[], 'un produit n’est pas au catalogue du magasin (n° ' . $id . ')']; }
        $p = $index[$id];
        $out[] = ['id' => $id, 'produit' => $p['nom'], 'cat' => $p['cat'], 'q' => (string) (floor($q) == $q ? (int) $q : $q), 'prix' => $p['prix'], 'cout' => $p['cout']];
    }
    return [$out, $out === [] ? 'choisissez au moins un produit' : null];
}

/** Le coût matière des lignes (coût de la recette × quantité), null quand aucun coût n'est connu. Fonction pure. */
function smCoutLignes(array $lignes): ?float
{
    $t = 0.0; $vu = false;
    foreach ($lignes as $l) { if (isset($l['cout']) && is_numeric($l['cout'])) { $t += (float) $l['cout'] * (float) $l['q']; $vu = true; } }
    return $vu ? round($t, 2) : null;
}

/**
 * Le corps de la commande TF Buddy (POST /client-orders) pour un échantillon (la vue smEchVue) : la commande
 * spécifique « ÉCHANTILLON COMMERCIAL », offerte, nouvelle, pour la date de l'échantillon (ou demain 10 h). Fonction pure.
 */
function smCommandeCorps(array $e, string $maintenant): array
{
    $quand = $e['le'] ? date('Y-m-d H:i:00', (int) strtotime((string) $e['le'])) : date('Y-m-d 10:00:00', (int) strtotime($maintenant . ' +1 day'));
    $produits = [];
    foreach ($e['contenu'] as $l) {
        if ((int) ($l['id'] ?? 0) <= 0) { continue; }
        $q = (float) $l['q']; $pu = (float) ($l['prix'] ?? 0); $valeur = round($pu * $q, 2);
        $produits[] = ['id_product' => (int) $l['id'], 'name' => (string) $l['produit'], 'quantity' => $q, 'unit_gross_price' => $pu,
            'item_discount_value' => $valeur, 'total_gross_value_after_discount' => 0];
    }
    $qui = $e['prospect'] . ($e['contact'] !== '' ? ' (' . $e['contact'] . ')' : '');
    $ou = $e['mode'] === 'retrait' ? 'retrait au magasin par le commercial' : 'livraison' . ($e['adresse'] !== '' ? ' : ' . $e['adresse'] : '');
    $comment = SM_CMD_MARQUE . ' — ' . $qui . ' — ' . $ou . ($e['note'] !== '' ? ' — ' . $e['note'] : '') . ' — offert, portail Commercial n° ' . $e['id'];
    return ['id_shop' => (int) $e['shopId'], 'comment' => mb_substr($comment, 0, 1000), 'pick_up_datetime' => $quand,
        'id_order_status' => SM_CMD_STATUT_NOUVELLE, 'products' => $produits];
}

/** Un POST à l'API de TF Buddy en formulaire (comme son contrat le documente), avec le compte admin du cockpit. */
function smErpPostFormulaire(string $chemin, array $donnees): array
{
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return ['code' => 0, 'corps' => null, 'erreur' => 'le compte admin de l’ERP n’est pas configuré']; }
    $envoyer = static function (?string $jeton) use ($chemin, $donnees): array {
        if ($jeton === null) { return [0, null, 'jeton admin indisponible']; }
        $ch = curl_init(ErpApi::config()['base'] . $chemin);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded', 'Authorization: Bearer ' . $jeton],
            CURLOPT_POSTFIELDS => http_build_query($donnees)]);
        $brut = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        return $brut === false ? [0, null, 'TF Buddy injoignable : ' . $err] : [$code, json_decode((string) $brut, true), null];
    };
    [$code, $corps, $err] = $envoyer(ErpApi::jetonSonde());
    if ($code === 401) { [$code, $corps, $err] = $envoyer(ErpApi::jetonSonde(true)); }
    if ($err === null && ($code < 200 || $code >= 300)) {
        $det = is_array($corps) ? (string) ($corps['description'] ?? $corps['message'] ?? '') : '';
        $err = 'HTTP ' . $code . ($det !== '' ? ' : ' . mb_substr($det, 0, 240) : '');
    }
    return ['code' => $code, 'corps' => $corps, 'erreur' => $err];
}

/** Passe la commande d'un échantillon dans le système de commande du magasin ; garde son numéro ou l'erreur. */
function smEchCommander(int $id): array
{
    $r = Db::row('SELECT * FROM sm_echantillon WHERE id = ?', [$id]);
    if ($r === null) { return ['ok' => false, 'erreur' => 'échantillon introuvable']; }
    if ($r['commande_id'] !== null) { return ['ok' => true, 'commande' => (int) $r['commande_id'], 'deja' => true]; }
    $e = smEchVue($r, smMagasins());
    $corps = smCommandeCorps($e, date('Y-m-d H:i:s'));
    if ($corps['products'] === []) { return ['ok' => false, 'erreur' => 'aucun produit du catalogue du magasin : la commande ne part pas dans TF Buddy']; }
    $w = smErpPostFormulaire('/client-orders', $corps);
    $num = is_array($w['corps']) ? (int) ($w['corps']['inserted_id'] ?? $w['corps']['id'] ?? $w['corps']['data']['id'] ?? 0) : 0;
    if ($w['erreur'] !== null || $num <= 0) {
        $err = $w['erreur'] ?? 'TF Buddy n’a pas rendu de numéro de commande';
        Db::exec('UPDATE sm_echantillon SET commande_erreur = ? WHERE id = ?', [mb_substr($err, 0, 255), $id]);
        return ['ok' => false, 'erreur' => $err];
    }
    Db::exec('UPDATE sm_echantillon SET commande_id = ?, commande_erreur = "", statut = IF(statut = "a_envoyer", "commande", statut), envoye_le = COALESCE(envoye_le, NOW()) WHERE id = ?', [$num, $id]);
    journalAdd('Commercial', 'Commercial', null, 'Échantillon ' . $id . ' commandé au magasin ' . $e['magasin'] . ' : commande TF Buddy n° ' . $num);
    return ['ok' => true, 'commande' => $num];
}

/** Les routes du catalogue du magasin ; null pour une autre adresse. */
function smRouteCommande(string $method, string $p): mixed
{
    if ($method === 'GET' && $p === '/catalogue-magasin') { return ep_sm_catalogue_magasin(); }
    return null;
}
