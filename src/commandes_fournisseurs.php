<?php
declare(strict_types=1);

/**
 * Commandes passées aux fournisseurs de matière première (SLFood, Bruyerre…),
 * lues dans l'API du panel avec le compte consultant du cockpit.
 *
 * Routes du swagger (/api/swagger/openapi.json, lu le 08/11/2026) :
 *  - GET /material-suppliers                       la liste des fournisseurs ;
 *  - GET /material-suppliers/{id}/orders           ses commandes (SupplierAppRoutes.php:271) ;
 *  - GET /material-suppliers/{id}/orders/{order}   le détail d'une commande (:277) ;
 *  - GET /shops/{id}/orders                        les commandes d'un magasin (orderRoutes.php:31).
 *
 * Le swagger ne documente NI le corps ni l'autorisation de ces routes
 * (« Runtime route registered from a static route source ») : on ne présume
 * pas des champs. La réponse est normalisée sur des noms candidats, et le
 * code HTTP de chaque appel est rendu (`sources`) — si une route refuse le
 * jeton consultant, ça se lit au lieu de donner une liste vide.
 *
 * GET /centrale/commandes-fournisseurs
 *   ?fournisseur=slfood   filtre sur le nom (sans casse ni espaces)
 *   ?depuis=AAAA-MM-JJ&jusqua=AAAA-MM-JJ
 *   ?detail=1             ajoute les lignes de chaque commande (un appel par commande)
 *   ?magasins=1           interroge aussi /shops/{id}/orders
 *   ?format=csv           CSV « ; » (Excel), une ligne par commande, ou par article avec detail=1
 */

function cfNorm(string $s): string
{
    return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? '';
}

/** Première valeur non vide parmi des clés candidates (accepte « a.b »). */
function cfPick(array $r, array $cles): mixed
{
    foreach ($cles as $k) {
        $v = $r;
        foreach (explode('.', $k) as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) { $v = null; break; }
            $v = $v[$p];
        }
        if ($v !== null && $v !== '' && $v !== []) { return $v; }
    }
    return null;
}

/** Texte d'une valeur qui peut être un objet ({name: …}) ou rien. */
function cfStr(mixed $v): string
{
    if (is_array($v)) { $v = cfPick($v, ['name', 'display_name', 'label', 'code', 'value']); }
    return is_scalar($v) ? (string) $v : '';
}

/** Une liste, quel que soit l'emballage (data, items, orders…). */
function cfListe(mixed $r): array
{
    if (!is_array($r)) { return []; }
    if (array_is_list($r)) { return $r; }
    foreach (['data', 'items', 'orders', 'results', 'rows'] as $k) {
        if (isset($r[$k]) && is_array($r[$k])) {
            return array_is_list($r[$k]) ? $r[$k] : cfListe($r[$k]);
        }
    }
    return [];
}

/** Nombre de pages annoncé par la réponse, s'il y en a un. */
function cfPages(mixed $r): int
{
    if (!is_array($r)) { return 1; }
    $n = cfPick($r, ['last_page', 'total_pages', 'meta.last_page', 'meta.total_pages',
        'pagination.last_page', 'pagination.total_pages', 'data.last_page']);
    return is_numeric($n) ? max(1, min(200, (int) $n)) : 1;
}

/** Toutes les pages d'une route de liste (page=1..n), avec le code du premier appel. */
function cfToutesPages(string $chemin): array
{
    $r = PanelApi::sondeGet($chemin, 30);
    $liste = cfListe($r['corps']);
    $pages = $r['code'] >= 200 && $r['code'] < 300 ? cfPages($r['corps']) : 1;
    for ($p = 2; $p <= $pages; $p++) {
        $sep = str_contains($chemin, '?') ? '&' : '?';
        $rp = PanelApi::sondeGet($chemin . $sep . 'page=' . $p, 30);
        $liste = array_merge($liste, cfListe($rp['corps']));
    }
    return ['code' => $r['code'], 'erreur' => $r['erreur'], 'liste' => $liste, 'pages' => $pages,
        'cles' => isset($liste[0]) && is_array($liste[0]) ? array_keys($liste[0]) : []];
}

function cfCommande(array $o, string $fournisseur, ?int $fid, array $magasins): array
{
    $sid = cfPick($o, ['id_shop', 'shop_id', 'shop.id']);
    $date = cfStr(cfPick($o, ['order_date', 'ordered_at', 'created_at', 'date', 'sent_at']));
    return [
        'id' => cfPick($o, ['id', 'id_order', 'order_id']),
        'numero' => cfPick($o, ['number', 'order_number', 'reference', 'document_number']),
        'fournisseurId' => $fid ?? cfPick($o, ['id_supplier', 'supplier_id', 'supplier.id']),
        'fournisseur' => $fournisseur !== '' ? $fournisseur : cfStr(cfPick($o, ['supplier.name', 'supplier_name'])),
        'magasinId' => $sid,
        'magasin' => cfStr(cfPick($o, ['shop.name', 'shop_name'])) ?: ($magasins[is_numeric($sid) ? (int) $sid : 0] ?? ''),
        'statut' => cfStr(cfPick($o, ['status', 'state'])),
        'date' => $date,
        'livraisonPrevue' => cfPick($o, ['supplier_planned_delivery_date', 'expected_date', 'delivery_date', 'planned_delivery_date']),
        'livree' => cfPick($o, ['delivered_on', 'delivered_at']),
        'total' => cfPick($o, ['total_net', 'total', 'net_value', 'value', 'amount', 'total_value', 'estimated_value']),
        'nbLignes' => cfPick($o, ['items_count', 'lines_count']) ?? (is_array($o['items'] ?? null) ? count($o['items']) : null),
    ];
}

function cfLigne(array $it): array
{
    return [
        'matiereId' => cfPick($it, ['id_material', 'material_id', 'material.id']),
        'matiere' => cfPick($it, ['material.name', 'material_name', 'name', 'product_name', 'product.name']),
        'sku' => cfPick($it, ['supplier_sku', 'sku', 'product_code']),
        'quantite' => cfPick($it, ['quantity', 'qty', 'ordered_quantity', 'product_quantity']),
        'quantiteRecue' => cfPick($it, ['received_quantity', 'delivered_quantity', 'final_quantity']),
        'unite' => cfPick($it, ['unit.name', 'unit_name', 'unit']),
        'prixUnitaire' => cfPick($it, ['unit_price_net', 'unit_price', 'price_net', 'price']),
        'total' => cfPick($it, ['total_net', 'total', 'value']),
    ];
}

function ep_ca_commandes_fournisseurs(): array
{
    if (!PanelApi::configured()) {
        return ['etat' => 'attente', 'source' => 'API panel — compte consultant non configuré (Mon compte)',
            'commandes' => [], 'sources' => []];
    }
    $filtre = cfNorm((string) ($_GET['fournisseur'] ?? ''));
    $depuis = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['depuis'] ?? '')) ? (string) $_GET['depuis'] : '';
    $jusqua = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['jusqua'] ?? '')) ? (string) $_GET['jusqua'] : '';
    $detail = ($_GET['detail'] ?? '') === '1';

    $magasins = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops ORDER BY id') as $s) { $magasins[(int) $s['id']] = (string) $s['name']; }
    } catch (Throwable $e) { /* les noms viendront de la réponse, ou resteront vides */ }

    $sources = [];
    $fournisseurs = [];
    foreach (analyseListe(PanelApi::get('/material-suppliers') ?? []) as $f) {
        $id = (int) ($f['id'] ?? 0);
        $nom = (string) ($f['name'] ?? '');
        if ($id <= 0) { continue; }
        if ($filtre !== '' && !str_contains(cfNorm($nom), $filtre)) { continue; }
        $fournisseurs[$id] = $nom;
    }

    $commandes = [];
    $brutes = [];   // [fournisseurId, commandeId] pour le détail
    foreach ($fournisseurs as $fid => $nom) {
        $r = cfToutesPages('/material-suppliers/' . $fid . '/orders');
        $sources[] = ['route' => "/material-suppliers/$fid/orders", 'fournisseur' => $nom, 'code' => $r['code'],
            'erreur' => $r['erreur'], 'nb' => count($r['liste']), 'pages' => $r['pages'], 'champs' => $r['cles']];
        foreach ($r['liste'] as $o) {
            if (!is_array($o)) { continue; }
            $c = cfCommande($o, $nom, $fid, $magasins);
            $c['_source'] = 'fournisseur';
            $commandes[] = $c;
        }
    }

    if (($_GET['magasins'] ?? '') === '1') {
        foreach (array_keys($magasins) as $sid) {
            $r = cfToutesPages('/shops/' . $sid . '/orders');
            $sources[] = ['route' => "/shops/$sid/orders", 'magasin' => $magasins[$sid], 'code' => $r['code'],
                'erreur' => $r['erreur'], 'nb' => count($r['liste']), 'pages' => $r['pages'], 'champs' => $r['cles']];
            foreach ($r['liste'] as $o) {
                if (!is_array($o)) { continue; }
                $fidO = cfPick($o, ['id_supplier', 'supplier_id', 'supplier.id']);
                $nomO = $fidO !== null ? ($fournisseurs[(int) $fidO] ?? '') : '';
                if ($filtre !== '' && $nomO === '' && !str_contains(cfNorm(cfStr(cfPick($o, ['supplier.name', 'supplier_name']))), $filtre)) { continue; }
                $c = cfCommande($o + ['id_shop' => $sid], $nomO, $fidO !== null ? (int) $fidO : null, $magasins);
                $c['_source'] = 'magasin';
                $commandes[] = $c;
            }
        }
    }

    // Période, puis dédoublonnage : une commande vue par le fournisseur ET le magasin ne compte qu'une fois.
    $vues = [];
    $commandes = array_values(array_filter($commandes, function ($c) use ($depuis, $jusqua, &$vues) {
        $d = substr((string) $c['date'], 0, 10);
        if ($depuis !== '' && $d !== '' && $d < $depuis) { return false; }
        if ($jusqua !== '' && $d !== '' && $d > $jusqua) { return false; }
        $cle = $c['fournisseurId'] . '/' . $c['id'];
        if ($c['id'] !== null && isset($vues[$cle])) { return false; }
        $vues[$cle] = true;
        return true;
    }));
    usort($commandes, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

    if ($detail) {
        $chemins = [];
        foreach ($commandes as $i => $c) {
            if ($c['id'] !== null && $c['fournisseurId'] !== null) {
                $chemins[$i] = '/material-suppliers/' . (int) $c['fournisseurId'] . '/orders/' . (int) $c['id'];
            }
        }
        foreach (PanelApi::getParallele($chemins, 4, 30) as $i => $d) {
            $d = is_array($d) && isset($d['data']) && is_array($d['data']) && !array_is_list($d['data']) ? $d['data'] : $d;
            $items = is_array($d) ? cfListe($d['items'] ?? $d['lines'] ?? $d['order_items'] ?? $d['products'] ?? []) : [];
            $commandes[$i]['lignes'] = array_map(fn ($it) => is_array($it) ? cfLigne($it) : [], $items);
            if ($commandes[$i]['nbLignes'] === null) { $commandes[$i]['nbLignes'] = count($items); }
        }
    }

    $parFournisseur = [];
    foreach ($commandes as $c) {
        $k = $c['fournisseur'] !== '' ? $c['fournisseur'] : ('#' . $c['fournisseurId']);
        $parFournisseur[$k]['nb'] = ($parFournisseur[$k]['nb'] ?? 0) + 1;
        $parFournisseur[$k]['total'] = round(($parFournisseur[$k]['total'] ?? 0) + (is_numeric($c['total']) ? (float) $c['total'] : 0), 2);
    }
    return [
        'etat' => 'ok',
        'source' => 'API panel — /material-suppliers/{id}/orders' . (($_GET['magasins'] ?? '') === '1' ? ' + /shops/{id}/orders' : ''),
        'filtre' => ['fournisseur' => $filtre, 'depuis' => $depuis, 'jusqua' => $jusqua],
        'nb' => count($commandes),
        'parFournisseur' => $parFournisseur,
        'commandes' => $commandes,
        'sources' => $sources,
    ];
}

/** Le même contenu en CSV (séparateur « ; », BOM UTF-8). */
function ep_ca_commandes_fournisseurs_csv(): void
{
    $r = ep_ca_commandes_fournisseurs();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commandes-fournisseurs-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $tete = ['id', 'numero', 'fournisseur', 'magasin', 'statut', 'date', 'livraisonPrevue', 'livree', 'total', 'nbLignes'];
    $avecLignes = ($_GET['detail'] ?? '') === '1';
    $teteL = ['matiere', 'sku', 'quantite', 'quantiteRecue', 'unite', 'prixUnitaire', 'totalLigne'];
    fputcsv($out, $avecLignes ? array_merge($tete, $teteL) : $tete, ';');
    foreach ($r['commandes'] as $c) {
        $base = array_map(fn ($k) => !isset($c[$k]) ? '' : (is_scalar($c[$k]) ? $c[$k] : json_encode($c[$k], JSON_UNESCAPED_UNICODE)), $tete);
        if (!$avecLignes || empty($c['lignes'])) { fputcsv($out, $base, ';'); continue; }
        foreach ($c['lignes'] as $l) {
            fputcsv($out, array_merge($base, [$l['matiere'] ?? null, $l['sku'] ?? null, $l['quantite'] ?? null,
                $l['quantiteRecue'] ?? null, $l['unite'] ?? null, $l['prixUnitaire'] ?? null, $l['total'] ?? null]), ';');
        }
    }
    fclose($out);
}
