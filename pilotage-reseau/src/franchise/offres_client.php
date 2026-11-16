<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Vendre › Offres (15/11/2026, maquette docs/maquettes/rail-franchise/offres.png) : les offres de prix
 * du magasin à ses clients, lues et écrites dans TF Buddy par son API « Client offers », et par elle seule (demande du
 * 15/11 : « only api ») — pas de table du cockpit, pas de PDF fabriqué ici. Le compte admin ERP du cockpit (ErpApi) parle
 * à l'ERP ; le navigateur ne voit jamais le jeton.
 *
 *   GET  /exploitation/offres-client?shop=4                     la liste du magasin (GET /shops/{id}/offers?include=products,client)
 *   GET  /exploitation/offres-client/employes?shop=4            qui prépare la commande (convert-to-order : id_employee)
 *   GET  /exploitation/offres-client/pdf?shop=4&id=411&lang=fr  le PDF que TF Buddy rend (GET /offers/{id}/document)
 *   POST /exploitation/offres-client {shop, action, …}          creer (POST /offers), modifier · archiver · reactiver
 *                                                               (PATCH /offers/{id}), supprimer (DELETE), convertir
 *                                                               (POST /offers/{id}/convert-to-order)
 *
 * Le magasin de la requête est vérifié par l'accès (accesRefus) ; une offre désignée par son numéro est relue d'abord et
 * doit être de ce magasin (`id_shop`), sinon 404 — l'API de TF Buddy, elle, ne regarde pas le magasin.
 * Ce que dit le service des offres de TF Buddy (OfferService, envoyé le 15/11/2026) : la conversion pose `status` = 1 et
 * exige `pick_up_datetime` ; le PDF exige des produits et un client ; une modification ne remplace les produits que s'il
 * y en a ; ses erreurs rendent 401 même quand ce n'est pas une affaire de droits (FAILED_UPDATE_OFFER…).
 */

const OC_LISTE_TTL = 120;   // la liste gardée deux minutes (le rail en lit le compte) ; une écriture l'oublie

function ocShop(?array $b = null): int
{
    return (int) ($b !== null ? ($b['shop'] ?? 0) : ($_GET['shop'] ?? 0));
}

function ocDispo(): ?array
{
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { http_response_code(503); return ['error' => 'le compte admin de l’ERP n’est pas configuré : les offres ne se lisent pas']; }
    return null;
}

/** Le message d'une réponse refusée de l'ERP, lisible au magasin. */
function ocErreur(array $r, string $quoi): string
{
    $e = (string) ($r['erreur'] ?? ('HTTP ' . ($r['code'] ?? 0)));
    return 'L’ERP refuse ' . $quoi . ' (' . $e . ').';
}

function ocNombre($v): ?float
{
    if ($v === null || $v === '' || !is_numeric($v)) { return null; }
    return (float) $v;
}

/** Une offre de TF Buddy (OfferRecord, avec products et client) dans la forme du dashboard. */
function ocNormaliser(array $o): array
{
    $lignes = []; $totP = 0.0; $totNet = 0.0; $netOk = true;
    foreach ((array) ($o['products'] ?? []) as $p) {
        if (!is_array($p)) { continue; }
        $q = ocNombre($p['quantity'] ?? null) ?? 0.0;
        $pu = ocNombre($p['unit_price'] ?? null) ?? 0.0;
        $rm = ocNombre($p['discount_amount'] ?? null) ?? 0.0;
        $tot = ocNombre($p['final_total_price'] ?? null) ?? round($q * $pu - $rm, 2);
        $net = ocNombre($p['final_total_price_net'] ?? null);
        if ($net === null) { $netOk = false; } else { $totNet += $net; }
        $totP += $tot;
        $lignes[] = ['idProduit' => (int) ($p['id_product'] ?? 0), 'nom' => (string) ($p['product_name'] ?? ''), 'qte' => $q, 'pu' => $pu,
            'remiseValeur' => ocNombre($p['discount_value'] ?? null), 'remiseMontant' => $rm, 'remiseType' => isset($p['id_discount_type']) ? (int) $p['id_discount_type'] : null,
            'total' => $tot, 'totalNet' => $net, 'tva' => ocNombre($p['tax_val_perc'] ?? null), 'puNet' => ocNombre($p['unit_price_net'] ?? null)];
    }
    $c = is_array($o['client'] ?? null) ? $o['client'] : null;
    $client = null;
    if ($c !== null && (int) ($c['id'] ?? 0) > 0) {
        $b2b = !empty($c['is_b2b']);
        $perso = trim((string) ($c['name'] ?? $c['firstname'] ?? '') . ' ' . (string) ($c['surname'] ?? $c['lastname'] ?? ''));
        $client = ['id' => (int) $c['id'], 'b2b' => $b2b, 'nom' => $b2b && trim((string) ($c['company_name'] ?? '')) !== '' ? trim((string) $c['company_name']) : $perso,
            'contact' => $b2b ? $perso : '', 'tva' => trim((string) ($c['tax_number'] ?? '')),
            'rue' => trim((string) ($c['street'] ?? '') . ' ' . (string) ($c['street_number'] ?? '')), 'cp' => trim((string) ($c['zip'] ?? '')),
            'ville' => trim((string) ($c['city'] ?? '')), 'email' => trim((string) ($c['email'] ?? '')), 'tel' => trim((string) ($c['phone'] ?? ''))];
    }
    $frais = ocNombre($o['delivery_cost'] ?? null) ?? 0.0;
    $statut = $o['status'] ?? null;
    $actif = !array_key_exists('active', $o) || $o['active'] === null || $o['active'] === '' || (int) $o['active'] !== 0;
    $etat = (string) $statut === '1' ? 'conv' : ($actif ? 'cours' : 'arch');
    $jour = (string) ($o['event_day'] ?? '');
    return ['id' => (int) ($o['id'] ?? 0), 'shop' => (int) ($o['id_shop'] ?? 0), 'nom' => (string) ($o['name'] ?? ''),
        'description' => (string) ($o['description'] ?? ''), 'clientId' => (int) ($o['id_client'] ?? 0) ?: null, 'client' => $client,
        'retrait' => !empty($o['in_store_pickup']) && (string) $o['in_store_pickup'] !== '0',
        'livraison' => ['rue' => (string) ($o['delivery_street'] ?? ''), 'cp' => (string) ($o['delivery_zip'] ?? ''), 'ville' => (string) ($o['delivery_city'] ?? ''), 'frais' => $frais],
        'conditions' => (string) ($o['conditions'] ?? ''), 'jour' => preg_match('/^\d{4}-\d{2}-\d{2}/', $jour) ? substr($jour, 0, 10) : $jour,
        'cree' => (string) ($o['create_timestamp'] ?? ''), 'statut' => $statut, 'actif' => $actif, 'etat' => $etat,
        'lignes' => $lignes, 'totalProduits' => round($totP, 2), 'totalNet' => $netOk && $lignes ? round($totNet, 2) : null,
        'total' => round($totP + $frais, 2)];
}

/** La liste brute de l'ERP : un tableau d'offres, nu ou dans `data`. */
function ocListeBrute($corps): ?array
{
    if (!is_array($corps)) { return null; }
    if (array_is_list($corps)) { return $corps; }
    foreach (['data', 'offers', 'items'] as $k) { if (is_array($corps[$k] ?? null) && array_is_list($corps[$k])) { return $corps[$k]; } }
    return null;
}

function ocOublierListe(int $shop): void
{
    Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['ocListe:' . $shop]);
}

/** GET /exploitation/offres-client?shop=4[&frais=1] — les offres du magasin, les plus récentes d'abord, et leurs comptes. */
function ep_offres_client(): array
{
    $shop = ocShop();
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (($e = ocDispo()) !== null) { return $e; }
    $cle = 'ocListe:' . $shop;
    $g = empty($_GET['frais']) ? setting($cle) : null;
    if (is_array($g) && is_array($g['l'] ?? null) && (int) ($g['ts'] ?? 0) > time() - OC_LISTE_TTL) {
        $l = $g['l']; $lu = (int) $g['ts'];
    } else {
        $r = ErpApi::sondeGet('/shops/' . $shop . '/offers?include=products,client');
        $brut = $r['code'] >= 200 && $r['code'] < 300 ? ocListeBrute($r['corps']) : null;
        if ($brut === null) { http_response_code(502); return ['error' => ocErreur($r, 'la lecture des offres du magasin')]; }
        $l = [];
        foreach ($brut as $o) { if (is_array($o)) { $n = ocNormaliser($o); if ($n['id'] > 0) { $l[] = $n; } } }
        usort($l, static fn ($a, $b) => $b['id'] <=> $a['id']);
        $lu = time();
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['ts' => $lu, 'l' => $l], JSON_UNESCAPED_UNICODE)]);
    }
    $n = ['cours' => 0, 'conv' => 0, 'arch' => 0];
    foreach ($l as $o) { $n[$o['etat']] = ($n[$o['etat']] ?? 0) + 1; }
    return ['shop' => (string) $shop, 'offres' => $l, 'n' => $n + ['tout' => count($l)], 'lu' => date('H:i', $lu)];
}

/** L'offre n° $id, relue à l'ERP, si elle est de ce magasin : [offre normalisée | null, code HTTP, erreur]. */
function ocMienne(int $shop, int $id): array
{
    if ($shop <= 0 || $id <= 0) { return [null, 400, 'magasin ou offre manquant']; }
    $r = ErpApi::sondeGet('/offers/' . $id . '?include=products,client');
    if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($r['corps'])) { return [null, 502, ocErreur($r, 'la lecture de l’offre n° ' . $id)]; }
    $o = isset($r['corps']['data']) && is_array($r['corps']['data']) && !isset($r['corps']['id']) ? $r['corps']['data'] : $r['corps'];
    if ((int) ($o['id_shop'] ?? 0) !== $shop) { return [null, 404, 'offre introuvable pour ce magasin']; }
    return [ocNormaliser($o), 200, null];
}

/** GET /exploitation/offres-client/employes?shop=4 — l'équipe du magasin (id, prénom et initiale), pour la conversion. */
function ep_offres_client_employes(): array
{
    $shop = ocShop();
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $l = function_exists('invOperateurs') ? invOperateurs($shop) : [];
    $out = [];
    foreach ($l as $id => $nom) { $out[] = ['id' => (int) $id, 'nom' => (string) $nom]; }
    usort($out, static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    return ['shop' => (string) $shop, 'employes' => $out];
}

/** GET /exploitation/offres-client/pdf?shop=4&id=411&lang=fr — le PDF de TF Buddy, tel quel (sort, puis exit). */
function ep_offres_client_pdf(): void
{
    $json = static function (int $code, string $msg): void {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit;
    };
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { $json(503, 'le compte admin de l’ERP n’est pas configuré'); }
    $shop = ocShop(); $id = (int) ($_GET['id'] ?? 0);
    $lang = in_array($_GET['lang'] ?? 'fr', ['fr', 'nl', 'en', 'it', 'pl'], true) ? (string) ($_GET['lang'] ?? 'fr') : 'fr';
    [$o, $code, $err] = ocMienne($shop, $id);
    if ($o === null) { $json($code, (string) $err); }
    $r = ErpApi::brut('/offers/' . $id . '/document?lang=' . $lang);
    if ($r['code'] !== 200 || !str_starts_with($r['corps'], '%PDF')) {
        $json($r['code'] === 404 ? 404 : 502, $r['code'] === 404 ? 'TF Buddy ne rend pas le PDF : il faut un client et au moins un produit sur l’offre.' : ocErreur($r, 'le PDF de l’offre'));
    }
    $nom = 'Offre_' . $id . ($o['client'] ? '_' . preg_replace('/[^\w\-]+/u', '_', $o['client']['nom']) : '') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($r['corps']));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (empty($_GET['telecharger']) ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\-]/', '_', $nom) . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
    echo $r['corps'];
    exit;
}

/**
 * Le corps OfferInput tiré de ce que le dashboard envoie : les champs connus seulement, le magasin imposé, les produits
 * vérifiés contre le catalogue du magasin quand il se lit. Renvoie [corps, erreur].
 */
function ocCorps(int $shop, array $d, bool $creation): array
{
    $c = ['id_shop' => $shop];
    $txt = static fn ($v, int $max): string => mb_substr(trim((string) $v), 0, $max);
    if (array_key_exists('nom', $d)) { $c['name'] = $txt($d['nom'], 255); }
    if ($creation && ($c['name'] ?? '') === '') { return [null, 'donnez un nom à l’offre']; }
    if (array_key_exists('description', $d)) { $c['description'] = $txt($d['description'], 4000); }
    if (array_key_exists('conditions', $d)) { $c['conditions'] = $txt($d['conditions'], 4000); }
    if (array_key_exists('clientId', $d)) {
        $cid = (int) $d['clientId'];
        if ($cid > 0) { $c['id_client'] = $cid; }
    }
    if (array_key_exists('jour', $d)) {
        $j = trim((string) $d['jour']);
        if ($j !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $j)) { return [null, 'jour de l’événement illisible']; }
        $c['event_day'] = $j;
    }
    if (array_key_exists('retrait', $d)) {
        $c['in_store_pickup'] = !empty($d['retrait']) ? 1 : 0;
        $l = is_array($d['livraison'] ?? null) ? $d['livraison'] : [];
        $c['delivery_street'] = $c['in_store_pickup'] ? '' : $txt($l['rue'] ?? '', 255);
        $c['delivery_zip'] = $c['in_store_pickup'] ? '' : $txt($l['cp'] ?? '', 20);
        $c['delivery_city'] = $c['in_store_pickup'] ? '' : $txt($l['ville'] ?? '', 120);
        $f = ocNombre($l['frais'] ?? null) ?? 0.0;
        if ($f < 0 || $f > 10000) { return [null, 'frais de livraison hors limites']; }
        $c['delivery_cost'] = $c['in_store_pickup'] ? 0.0 : round($f, 2);
    }
    if (array_key_exists('lignes', $d)) {
        $cat = null;
        if (function_exists('pmProduits')) {
            $pl = pmProduits($shop);
            if (is_array($pl)) { $cat = []; foreach ($pl as $p) { $cat[(int) $p['id']] = (string) $p['nom']; } }
        }
        $prod = [];
        foreach (array_slice((array) $d['lignes'], 0, 200) as $l) {
            if (!is_array($l)) { continue; }
            $pid = (int) ($l['idProduit'] ?? 0); $q = ocNombre($l['qte'] ?? null); $pu = ocNombre($l['pu'] ?? null);
            if ($pid <= 0 || $q === null || $q <= 0 || $q > 100000 || $pu === null || $pu < 0 || $pu > 100000) { return [null, 'une ligne est incomplète : produit, quantité et prix']; }
            if ($cat !== null && !isset($cat[$pid])) { return [null, 'un produit n’est pas au catalogue du magasin (n° ' . $pid . ')']; }
            $rm = max(0.0, ocNombre($l['remiseMontant'] ?? null) ?? 0.0);
            $rv = ocNombre($l['remiseValeur'] ?? null);
            $brut = round($q * $pu, 2);
            if ($rm > $brut) { $rm = $brut; }
            $p = ['id_product' => $pid, 'product_name' => $cat[$pid] ?? $txt($l['nom'] ?? '', 255), 'quantity' => $q, 'unit_price' => round($pu, 4),
                'final_total_price' => round($brut - $rm, 2)];
            if ($rm > 0) {
                $p['discount_amount'] = round($rm, 2);
                if ($rv !== null) { $p['discount_value'] = $rv; }
                if (isset($l['remiseType']) && (int) $l['remiseType'] > 0) { $p['id_discount_type'] = (int) $l['remiseType']; }
            }
            $prod[] = $p;
        }
        if (!$prod) { return [null, 'ajoutez au moins un produit : TF Buddy ne rend pas de PDF sans produit']; }
        $c['products'] = $prod;
    }
    if ($creation) { $c += ['active' => 1, 'status' => 0, 'realised' => 0]; }
    return [$c, null];
}

/** POST /exploitation/offres-client {shop, action: creer | modifier | archiver | reactiver | supprimer | convertir, id?, offre?, …} */
function wr_offres_client(): array
{
    $b = body();
    $shop = ocShop($b);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (($e = ocDispo()) !== null) { return $e; }
    $action = (string) ($b['action'] ?? '');
    $fin = static function (int $shop, int $id): array {
        ocOublierListe($shop);
        [$o] = $id > 0 ? ocMienne($shop, $id) : [null];
        return ['ok' => true, 'id' => $id, 'offre' => $o];
    };
    if ($action === 'creer') {
        [$corps, $err] = ocCorps($shop, is_array($b['offre'] ?? null) ? $b['offre'] : [], true);
        if ($err !== null) { http_response_code(422); return ['error' => $err]; }
        $w = ErpApi::ecrire('POST', '/offers', $corps);
        if ($w['erreur'] !== null) { http_response_code(502); return ['error' => ocErreur($w, 'la création de l’offre')]; }
        $c = $w['corps'];
        $id = (int) (is_array($c) ? ($c['id'] ?? $c['data']['id'] ?? $c['offerId'] ?? 0) : (is_numeric($c) ? $c : 0));
        return $fin($shop, $id);
    }
    $id = (int) ($b['id'] ?? 0);
    [$o, $code, $err] = ocMienne($shop, $id);
    if ($o === null) { http_response_code($code); return ['error' => $err]; }
    switch ($action) {
        case 'modifier':
            if ($o['etat'] === 'conv') { http_response_code(409); return ['error' => 'l’offre est déjà convertie en commande : elle ne se modifie plus']; }
            [$corps, $err] = ocCorps($shop, is_array($b['offre'] ?? null) ? $b['offre'] : [], false);
            if ($err !== null) { http_response_code(422); return ['error' => $err]; }
            $w = ErpApi::ecrire('PATCH', '/offers/' . $id, $corps);
            if ($w['erreur'] !== null) { http_response_code(502); return ['error' => ocErreur($w, 'la modification de l’offre') . ' Une modification sans changement est refusée par TF Buddy.']; }
            return $fin($shop, $id);
        case 'archiver':
        case 'reactiver':
            $w = ErpApi::ecrire('PATCH', '/offers/' . $id, ['active' => $action === 'archiver' ? 0 : 1]);
            if ($w['erreur'] !== null) { http_response_code(502); return ['error' => ocErreur($w, $action === 'archiver' ? 'l’archivage de l’offre' : 'la remise en cours de l’offre')]; }
            return $fin($shop, $id);
        case 'supprimer':
            if ($o['etat'] === 'conv') { http_response_code(409); return ['error' => 'l’offre est convertie en commande : archivez-la plutôt'];  }
            $w = ErpApi::ecrire('DELETE', '/offers/' . $id);
            if ($w['erreur'] !== null) { http_response_code(502); return ['error' => ocErreur($w, 'la suppression de l’offre')]; }
            ocOublierListe($shop);
            return ['ok' => true, 'id' => $id, 'supprimee' => true];
        case 'convertir':
            if ($o['etat'] === 'conv') { http_response_code(409); return ['error' => 'l’offre est déjà convertie en commande']; }
            if (!$o['lignes']) { http_response_code(422); return ['error' => 'l’offre n’a pas de produit : rien à commander']; }
            $quand = trim((string) ($b['retrait'] ?? ''));
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})$/', $quand, $m)) { http_response_code(422); return ['error' => 'la date et l’heure de retrait sont obligatoires']; }
            $emp = (int) ($b['employe'] ?? 0);
            $equipe = function_exists('invOperateurs') ? invOperateurs($shop) : [];
            if ($emp <= 0 || ($equipe && !isset($equipe[(string) $emp]))) { http_response_code(422); return ['error' => 'choisissez qui prépare la commande, dans l’équipe du magasin']; }
            $w = ErpApi::ecrire('POST', '/offers/' . $id . '/convert-to-order', ['pick_up_datetime' => $m[1] . ' ' . $m[2] . ':00', 'id_employee' => $emp]);
            if ($w['erreur'] !== null) { http_response_code(502); return ['error' => ocErreur($w, 'la conversion de l’offre en commande')]; }
            return $fin($shop, $id);
    }
    http_response_code(400);
    return ['error' => 'action inconnue'];
}
