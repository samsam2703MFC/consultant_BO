<?php
declare(strict_types=1);

/**
 * Réclamations fournisseur : les photos de chaque réclamation, la part du produit dans ce qui a été livré, et le
 * tableau annuel réclamé / livré (21/11/2026 — maquettes A et B de consultant/docs/maquettes/reclamations-photos-volumes,
 * retenues par Sam : « code A et B, photo obligatoire »).
 *
 * D'où viennent les chiffres :
 *  - les réclamations : le panel, GET /consultant/shops/material-complaints (la même lecture que
 *    ep_fournisseurs_reclamations, panel/src/reclamations_magasin.php) ;
 *  - les photos : les pièces jointes de la réclamation au panel, ouvertes par un lien signé à durée limitée
 *    (/attachments/{id}/presigned-url, demandé à l'ouverture de la fiche, jamais pour toute la liste), et celles prises
 *    depuis le dashboard, gardées ici (panel_reclamation_photo) ;
 *  - le livré : les FACTURES du fournisseur au magasin (21/11/2026, « avec les factures de l'ERP ») — l'ERP de
 *    production les pousse au panel à chaque expédition (material_order / material_order_item, rcSourceFactures) ; à
 *    défaut, le grand livre matière (material_inventory_log, entrées liées à une commande). Seuls comptent les produits
 *    réclamés. Lecture SQL directe de tables du panel : écart connu (REORG_PLAN.md §8), faute de route du panel.
 */

/** Les identifiants des pièces jointes d'une réclamation : entiers nus, ou objets {id | attachment_id}. */
function rcPjIds(mixed $att): array
{
    $ids = [];
    foreach ((array) $att as $a) {
        $id = is_array($a) ? ($a['attachment_id'] ?? $a['id'] ?? $a['attachmentId'] ?? null) : $a;
        if (is_numeric($id) && (int) $id > 0) { $ids[] = (int) $id; }
    }
    return array_values(array_unique($ids));
}

/** Toutes les réclamations du panel, à plat : [['shop' => id, 'c' => réclamation], …] ; null si le panel ne répond pas. */
function rcToutesPanel(): ?array
{
    if (!PanelApi::configured()) { return null; }
    $r = PanelApi::get('/consultant/shops/material-complaints');
    if (!is_array($r)) { return null; }
    $out = [];
    foreach ((array) ($r['shops'] ?? []) as $s) {
        foreach ((array) ($s['complaints'] ?? []) as $c) {
            if (is_array($c)) { $out[] = ['shop' => (int) ($s['shop_id'] ?? 0), 'c' => $c]; }
        }
    }
    return $out;
}

/** Le jour d'une réclamation (AAAA-MM-JJ), ou ''. */
function rcJour(array $c): string
{
    $j = substr((string) ($c['reported_at'] ?? $c['created_at'] ?? ''), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $j) ? $j : '';
}

/** Le prix d'achat de chaque matière (base_unit_price_net) : la même matière a le même prix d'un magasin à l'autre. */
function rcPrixMatieres(): array
{
    $prix = [];
    $shop = 3;
    try { $r = Db::row('SELECT MIN(id) id FROM shops WHERE active = 1'); if ($r && (int) $r['id'] > 0) { $shop = (int) $r['id']; } }
    catch (PDOException $e) { /* le magasin 3 fera l'affaire */ }
    $mats = PanelApi::get('/shops/' . $shop . '/materials');
    foreach ((array) (is_array($mats) ? (array_is_list($mats) ? $mats : ($mats['materials'] ?? [])) : []) as $m) {
        $id = (int) ($m['id'] ?? 0);
        $p = $m['base_unit_price_net'] ?? null;
        if ($id > 0 && is_numeric($p)) { $prix[$id] = (float) $p; }
    }
    return $prix;
}

/** Les magasins actifs : id → nom. */
function rcNomsMagasins(): array
{
    $noms = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $noms[(int) $s['id']] = (string) $s['name']; } }
    catch (PDOException $e) { /* l'identifiant fera foi */ }
    return $noms;
}

/**
 * Le livré, en unité de base : les entrées du grand livre matière liées à une commande fournisseur, entre $du et $au
 * (inclus), regroupées par magasin, matière et $grain (« jour » ou « mois »). Rend null si la table n'est pas lisible.
 *
 * @param int[]|null $matieres  restreindre à ces matières (null : toutes)
 * @return list<array{shop:int, matiere:int, cle:string, q:float}>|null
 */
function rcLivre(string $du, string $au, ?array $matieres = null, ?int $shop = null, string $grain = 'jour'): ?array
{
    if ($matieres !== null && $matieres === []) { return []; }
    $cle = $grain === 'mois' ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';
    $sql = "SELECT id_shop, id_material, $cle cle, SUM(change_quantity_in_base_unit) q FROM material_inventory_log
            WHERE id_order IS NOT NULL AND change_quantity_in_base_unit > 0 AND created_at >= ? AND created_at < ?";
    $args = [$du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00'];
    if ($matieres !== null) { $sql .= ' AND id_material IN (' . implode(',', array_map('intval', $matieres)) . ')'; }
    if ($shop !== null) { $sql .= ' AND id_shop = ?'; $args[] = $shop; }
    $sql .= ' GROUP BY id_shop, id_material, cle';
    try { $rows = Db::rows($sql, $args); } catch (PDOException $e) { return null; }
    return array_map(fn ($r) => ['shop' => (int) $r['id_shop'], 'matiere' => (int) $r['id_material'],
        'cle' => (string) $r['cle'], 'q' => (float) $r['q']], $rows);
}

/** Les bornes d'une période autour d'un jour : la semaine (lundi → dimanche), le mois, l'année. */
function rcPeriodes(string $jour): array
{
    $d = new DateTimeImmutable($jour);
    $lundi = $d->modify('monday this week');
    return [
        'semaine' => ['du' => $lundi->format('Y-m-d'), 'au' => $lundi->modify('+6 days')->format('Y-m-d'), 'nom' => 'Semaine ' . (int) $d->format('W')],
        'mois' => ['du' => $d->format('Y-m-01'), 'au' => $d->format('Y-m-t'), 'nom' => $d->format('m/Y')],
        'annee' => ['du' => $d->format('Y-01-01'), 'au' => $d->format('Y-12-31'), 'nom' => $d->format('Y')],
    ];
}

/**
 * La part du produit réclamé dans ce qui a été livré : pour le magasin sur la semaine, le mois et l'année de la
 * réclamation, et pour le réseau sur l'année. « Réclamé » additionne TOUTES les réclamations de ce magasin sur cette
 * matière dans la période (pas seulement celle de la fiche).
 */
function rcPartLivre(array $toutes, int $shop, int $matiere, string $jour): array
{
    $P = rcPeriodes($jour);
    // Les factures du fournisseur d'abord (21/11/2026), sinon le grand livre matière.
    $livres = rcLivreFactures($P['annee']['du'], $P['annee']['au'], [$matiere], null, null, 'jour');
    $source = 'factures';
    if ($livres === null) { $livres = rcLivre($P['annee']['du'], $P['annee']['au'], [$matiere]); $source = 'grand livre'; }
    $out = ['lu' => $livres !== null, 'source' => $livres !== null ? $source : null, 'periodes' => []];
    $reclame = static function (string $du, string $au, ?int $seul) use ($toutes, $matiere): float {
        $q = 0.0;
        foreach ($toutes as $x) {
            $c = $x['c']; $j = rcJour($c);
            if ((int) ($c['id_material'] ?? 0) !== $matiere || $j === '' || $j < $du || $j > $au) { continue; }
            if ($seul !== null && $x['shop'] !== $seul) { continue; }
            $q += (float) ($c['qty'] ?? 0);
        }
        return $q;
    };
    $livre = static function (string $du, string $au, ?int $seul) use ($livres): ?float {
        if ($livres === null) { return null; }
        $q = 0.0;
        foreach ($livres as $l) {
            if ($l['cle'] < $du || $l['cle'] > $au || ($seul !== null && $l['shop'] !== $seul)) { continue; }
            $q += $l['q'];
        }
        return $q;
    };
    $ligne = static function (string $nom, float $r, ?float $l): array {
        return ['nom' => $nom, 'reclame' => round($r, 2), 'livre' => $l === null ? null : round($l, 2),
            'part' => $l !== null && $l > 0 ? round(100 * $r / $l, 2) : null];
    };
    foreach ($P as $k => $p) {
        $out['periodes'][$k] = $ligne($p['nom'], $reclame($p['du'], $p['au'], $shop), $livre($p['du'], $p['au'], $shop));
    }
    $out['reseau'] = $ligne($P['annee']['nom'], $reclame($P['annee']['du'], $P['annee']['au'], null), $livre($P['annee']['du'], $P['annee']['au'], null));
    return $out;
}

/**
 * GET /fournisseurs/reclamation-fiche?shop=&id= — ce que la fiche d'une réclamation montre en plus de la liste :
 * ses photos (liens signés des pièces jointes du panel + photos du dashboard) et la part du produit dans le livré.
 */
function ep_reclamation_fiche(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $id = (int) ($_GET['id'] ?? 0);
    if ($shop <= 0 || $id <= 0) { http_response_code(400); return ['error' => 'shop et id sont requis']; }
    $toutes = rcToutesPanel();
    if ($toutes === null) { http_response_code(503); return ['error' => 'le panel n’a pas rendu les réclamations']; }
    $c = null;
    foreach ($toutes as $x) { if ($x['shop'] === $shop && (int) ($x['c']['id'] ?? 0) === $id) { $c = $x['c']; break; } }
    if ($c === null) { http_response_code(404); return ['error' => 'réclamation introuvable pour ce magasin']; }

    $photos = [];
    $req = [];
    foreach (rcPjIds($c['attachments'] ?? []) as $a) { $req[$a] = '/attachments/' . $a . '/presigned-url'; }
    foreach ($req ? PanelApi::getParallele($req) : [] as $a => $r) {
        $url = is_string($r) ? $r : null;
        foreach (['url', 'presigned_url', 'presignedUrl', 'link'] as $k) {
            if ($url === null && is_array($r) && isset($r[$k]) && is_string($r[$k])) { $url = $r[$k]; }
        }
        $photos[] = $url !== null && preg_match('#^https://#', $url)
            ? ['url' => $url, 'source' => 'panel'] : ['url' => null, 'source' => 'panel', 'erreur' => 'lien non rendu par le panel'];
    }
    $base = function_exists('rapBaseUrl') ? rtrim(rapBaseUrl(), '/') : '';
    foreach ((rcPhotosDe($shop)[$id] ?? []) as $chemin) {
        $photos[] = ['url' => ($base !== '' ? $base . '/' : '') . $chemin, 'source' => 'dashboard'];
    }

    $jour = rcJour($c);
    $mat = (int) ($c['id_material'] ?? 0);
    return ['id' => $id, 'shopId' => (string) $shop, 'photos' => $photos,
        'part' => $mat > 0 && $jour !== '' ? rcPartLivre($toutes, $shop, $mat, $jour) : null,
        'validite' => 'Les liens des photos du panel sont signés et expirent : la fiche les redemande à chaque ouverture.'];
}

/** Les colonnes d'une table de la base partagée (information_schema), gardées pour la requête. */
function rcColonnes(string $table): array
{
    static $memo = [];
    if (!isset($memo[$table])) {
        try { $memo[$table] = array_map(static fn ($c) => (string) $c['COLUMN_NAME'],
            Db::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])); }
        catch (PDOException $e) { $memo[$table] = []; }
    }
    return $memo[$table];
}

/**
 * Où lire les FACTURES du fournisseur au magasin. L'ERP de production n'est pas joignable d'ici, mais il pousse au panel,
 * à chaque expédition, les lignes de sa facture WDT (webhook material-orders/shipment/sent : produit, cartons, pièces par
 * carton, prix net du carton), rangées sur la commande du magasin (material_order / material_order_item). Les noms de
 * colonnes ne sont pas documentés : on prend la première présente de chaque liste, du plus « facturé » au plus
 * « commandé ». Rend null si la commande n'a pas de quoi compter (la source change alors pour le grand livre).
 */
function rcSourceFactures(): ?array
{
    $ci = rcColonnes('material_order_item'); $co = rcColonnes('material_order');
    if ($ci === [] || $co === [] || !in_array('id_order', $ci, true) || !in_array('id_material', $ci, true) || !in_array('id_shop', $co, true)) { return null; }
    $premier = static fn (array $cols, array $cands): ?string => array_values(array_intersect($cands, $cols))[0] ?? null;
    $packs = $premier($ci, ['shipped_pack_quantity', 'shipped_pack_qty', 'sent_pack_quantity', 'invoiced_pack_quantity', 'delivered_pack_quantity',
        'delivered_pack_qty', 'confirmed_pack_quantity', 'pack_quantity', 'quantity']);
    $taille = $premier($ci, ['shipped_pack_size', 'pack_size_in_base_unit', 'package_size_in_base_unit', 'pack_size']);
    $total = $premier($ci, ['shipped_quantity_in_base_unit', 'delivered_quantity_in_base_unit', 'total_quantity_in_base_unit']);
    $prix = $premier($ci, ['shipped_pack_price_net', 'invoiced_pack_price_net', 'pack_price_net', 'price_net', 'pack_price']);
    $dates = array_values(array_intersect(['delivered_on', 'shipped_at', 'sent_at', 'supplier_planned_delivery_date', 'expected_date', 'order_date'], $co));
    if ($dates === [] || ($packs === null && $total === null)) { return null; }
    $q = $packs !== null && $taille !== null ? "i.`$packs` * i.`$taille`" : ($total !== null ? "i.`$total`" : "i.`$packs`");
    return ['q' => $q, 'v' => $packs !== null && $prix !== null ? "i.`$packs` * i.`$prix`" : null,
        'date' => 'COALESCE(' . implode(', ', array_map(fn ($d) => "o.`$d`", $dates)) . ')',
        'fournisseur' => in_array('id_supplier', $co, true), 'statut' => in_array('status', $co, true),
        'detail' => 'commandes fournisseur du panel (factures poussées par l’ERP) : ' . ($packs ?? $total) . ($taille ? ' × ' . $taille : '')
            . ($prix ? ', prix ' . $prix : '') . ', date ' . $dates[0]];
}

/**
 * Le livré d'après les factures : quantité (unité de base) et montant net facturé, par magasin, matière et mois, pour
 * les matières données. null si la source n'est pas lisible.
 * @return list<array{shop:int, matiere:int, cle:string, q:float, v:?float}>|null
 */
function rcLivreFactures(string $du, string $au, array $matieres, ?int $shop = null, ?array $fournisseurs = null, string $grain = 'mois'): ?array
{
    $S = rcSourceFactures();
    if ($S === null) { return null; }
    if ($matieres === []) { return []; }
    $fmt = $grain === 'jour' ? '%Y-%m-%d' : '%Y-%m';
    $sql = "SELECT o.id_shop, i.id_material, DATE_FORMAT({$S['date']}, '$fmt') cle, SUM({$S['q']}) q" . ($S['v'] ? ", SUM({$S['v']}) v" : ', NULL v')
        . " FROM material_order_item i JOIN material_order o ON o.id = i.id_order
            WHERE {$S['date']} >= ? AND {$S['date']} < ? AND i.id_material IN (" . implode(',', array_map('intval', $matieres)) . ')';
    $args = [$du, date('Y-m-d', strtotime($au . ' +1 day'))];
    if ($S['statut']) { $sql .= " AND (o.status IS NULL OR o.status NOT IN ('CANCELLED', 'CANCELED', 'REJECTED', 'DRAFT'))"; }
    if ($S['fournisseur'] && $fournisseurs) { $sql .= ' AND o.id_supplier IN (' . implode(',', array_map('intval', $fournisseurs)) . ')'; }
    if ($shop !== null) { $sql .= ' AND o.id_shop = ?'; $args[] = $shop; }
    $sql .= ' GROUP BY o.id_shop, i.id_material, cle';
    try { $rows = Db::rows($sql, $args); } catch (PDOException $e) { return null; }
    return array_map(fn ($r) => ['shop' => (int) $r['id_shop'], 'matiere' => (int) $r['id_material'], 'cle' => (string) $r['cle'],
        'q' => (float) $r['q'], 'v' => $r['v'] !== null ? (float) $r['v'] : null], $rows);
}

/** La catégorie de chaque matière (liste des matières du panel, puis /material-categories pour un identifiant seul). */
function rcCategoriesMatieres(): array
{
    $shop = 3;
    try { $r = Db::row('SELECT MIN(id) id FROM shops WHERE active = 1'); if ($r && (int) $r['id'] > 0) { $shop = (int) $r['id']; } }
    catch (PDOException $e) { /* le magasin 3 fera l'affaire */ }
    $noms = [];
    foreach ((array) PanelApi::get('/material-categories') as $c) {
        if (is_array($c) && (int) ($c['id'] ?? 0) > 0) { $noms[(int) $c['id']] = trim((string) ($c['name'] ?? '')); }
    }
    $out = [];
    $mats = PanelApi::get('/shops/' . $shop . '/materials');
    foreach ((array) (is_array($mats) ? (array_is_list($mats) ? $mats : ($mats['materials'] ?? [])) : []) as $m) {
        $id = (int) ($m['id'] ?? 0);
        if ($id <= 0) { continue; }
        $c = $m['material_category_name'] ?? $m['category_name'] ?? (is_array($m['category'] ?? null) ? ($m['category']['name'] ?? null) : ($m['category'] ?? null));
        if (!is_string($c) || trim($c) === '') {
            $cid = (int) ($m['id_material_category'] ?? $m['id_category'] ?? $m['material_category_id'] ?? 0);
            $c = $noms[$cid] ?? null;
        }
        $out[$id] = is_string($c) && trim($c) !== '' ? trim($c) : 'Sans catégorie';
    }
    return $out;
}

/**
 * Le type d'une réclamation d'après son motif (21/11/2026, « rajouter s'il y a des problèmes et réclamations de
 * livraison ») : « livraison » quand le motif parle de la commande ou de l'acheminement (non-conformité avec la
 * commande, quantité incorrecte, manquant ou non reçu, casse, transport, retard), sinon « produit » (qualité, taille
 * ou poids, recette).
 */
function rcTypeReclamation(array $c): string
{
    $t = strtolower(((string) ($c['reason_code'] ?? '')) . ' ' . ((string) ($c['reason_name'] ?? '')));
    return preg_match('/order|commande|quantit|missing|manqu|non re[çc]u|not received|deliver|livr|transport|damag|cass|bris|retard|late|delay|wrong/u', $t)
        ? 'livraison' : 'produit';
}

/**
 * GET /reclamations/annuel?annee=&fournisseur=&shop= — le tableau annuel des PRODUITS RÉCLAMÉS (21/11/2026, maquettes D
 * et E : « Livré » ne compte que les produits réclamés dans l'année, pas tout le chiffre du fournisseur). Trois
 * regroupements, mois par mois : par magasin, par catégorie et par produit, avec la ligne Ensemble. Pour chaque case :
 * réclamé (pièces et €, au prix d'achat), livré (pièces et €, d'après les factures du fournisseur), accepté (hors
 * refusées), et le % réclamé ÷ livré. Sans `fournisseur`, le plus réclamé de l'année ; `*` : tous. `shop` : un magasin.
 */
function ep_reclamations_annuel(): array
{
    $annee = (int) ($_GET['annee'] ?? date('Y'));
    if ($annee < 2020 || $annee > (int) date('Y') + 1) { $annee = (int) date('Y'); }
    $seul = (int) ($_GET['shop'] ?? 0) ?: null;
    $toutes = rcToutesPanel();
    if ($toutes === null) { return ['indispo' => true, 'motif' => PanelApi::configured() ? 'le panel n’a pas rendu les réclamations' : 'compte consultant non configuré']; }

    $nomF = static fn (array $c): string => trim((string) ($c['supplier_name'] ?? '')) ?: 'Fournisseur inconnu';
    $an = array_values(array_filter($toutes, fn ($x) => str_starts_with(rcJour($x['c']), (string) $annee)));
    $parF = [];
    foreach ($an as $x) {
        $f = $nomF($x['c']);
        $parF[$f] ??= ['nom' => $f, 'n' => 0, 'ids' => []];
        $parF[$f]['n']++;
        if ((int) ($x['c']['id_supplier'] ?? 0) > 0) { $parF[$f]['ids'][(int) $x['c']['id_supplier']] = true; }
    }
    uasort($parF, fn ($a, $b) => $b['n'] <=> $a['n']);
    $choix = (string) ($_GET['fournisseur'] ?? '');
    if ($choix !== '*' && !isset($parF[$choix])) { $choix = (string) (array_key_first($parF) ?? '*'); }
    $dansPerimetre = array_values(array_filter($an, fn ($x) => ($choix === '*' || $nomF($x['c']) === $choix) && ($seul === null || $x['shop'] === $seul)));
    // Produit ou livraison : les deux comptes, toujours ; `type` restreint le tableau à l'un des deux.
    $prixT = rcPrixMatieres();
    $types = ['produit' => ['n' => 0, 'reclame' => 0.0, 'ouvertes' => 0], 'livraison' => ['n' => 0, 'reclame' => 0.0, 'ouvertes' => 0]];
    foreach ($dansPerimetre as $x) {
        $ty = rcTypeReclamation($x['c']); $pu = $prixT[(int) ($x['c']['id_material'] ?? 0)] ?? null;
        $types[$ty]['n']++;
        if ($pu !== null) { $types[$ty]['reclame'] += $pu * (float) ($x['c']['qty'] ?? 0); }
        if (trim((string) ($x['c']['production_response'] ?? '')) === '' && strtoupper((string) ($x['c']['status'] ?? '')) !== 'REJECTED') { $types[$ty]['ouvertes']++; }
    }
    foreach ($types as $k => $v) { $types[$k]['reclame'] = round($v['reclame'], 2); }
    $type = in_array($_GET['type'] ?? '', ['produit', 'livraison'], true) ? (string) $_GET['type'] : '';
    $retenues = $type === '' ? $dansPerimetre : array_values(array_filter($dansPerimetre, fn ($x) => rcTypeReclamation($x['c']) === $type));
    $fids = [];
    foreach ($choix === '*' ? $parF : [$parF[$choix] ?? ['ids' => []]] as $f) { foreach (array_keys($f['ids']) as $fid) { $fids[] = $fid; } }

    // Les produits réclamés : seuls ceux-là comptent dans le livré.
    $prods = [];
    foreach ($retenues as $x) {
        $c = $x['c']; $mid = (int) ($c['id_material'] ?? 0);
        if ($mid <= 0) { continue; }
        $prods[$mid] ??= ['id' => (string) $mid, 'nom' => trim((string) ($c['material_name'] ?? '')) ?: ('Matière ' . $mid),
            'sku' => (string) ($c['supplier_sku'] ?? ''), 'unite' => (string) ($c['unit_name'] ?? '')];
    }
    $prix = $prixT;
    $cats = rcCategoriesMatieres();
    $noms = rcNomsMagasins();
    $moisMax = $annee < (int) date('Y') ? 12 : ($annee > (int) date('Y') ? 0 : (int) date('n'));

    // Le livré : les factures, sinon le grand livre matière (dit dans `livreSource`).
    $livres = rcLivreFactures($annee . '-01-01', $annee . '-12-31', array_keys($prods), $seul, $fids ?: null);
    $source = 'factures';
    $detail = $livres !== null ? (rcSourceFactures()['detail'] ?? '') : '';
    if ($livres === null) {
        $livres = rcLivre($annee . '-01-01', $annee . '-12-31', array_keys($prods), $seul, 'mois');
        $source = $livres !== null ? 'grand livre' : null;
        $detail = $livres !== null ? 'grand livre matière du panel (entrées liées à une commande) : les factures ne sont pas lisibles' : 'aucune source du livré lisible';
        $livres = array_map(fn ($l) => $l + ['v' => null], $livres ?? []);
    }

    // Les cases : clé de regroupement → mois (1..12) → compteurs.
    $vide = static fn (): array => ['reclame' => 0.0, 'rq' => 0.0, 'n' => 0, 'nonChiffrees' => 0, 'accepte' => 0.0, 'livre' => 0.0, 'lq' => 0.0];
    $G = ['mag' => [], 'cat' => [], 'prod' => [], 'tout' => [], 'motif' => []];
    $ajoute = static function (string $grp, string $cle, int $m, array $d) use (&$G, $vide): void {
        $G[$grp][$cle][$m] ??= $vide();
        foreach ($d as $k => $v) { $G[$grp][$cle][$m][$k] += $v; }
    };
    $partout = static function (int $shop, int $mid, int $m, array $d) use ($ajoute, $cats): void {
        $ajoute('mag', (string) $shop, $m, $d);
        $ajoute('cat', $cats[$mid] ?? 'Sans catégorie', $m, $d);
        $ajoute('prod', (string) $mid, $m, $d);
        $ajoute('tout', '*', $m, $d);
    };
    foreach ($retenues as $x) {
        $c = $x['c']; $m = (int) substr(rcJour($c), 5, 2); $mid = (int) ($c['id_material'] ?? 0);
        $pu = $prix[$mid] ?? null;
        $q = isset($c['qty']) ? (float) $c['qty'] : 0.0;
        $qa = isset($c['accepted_qty']) && $c['accepted_qty'] !== null && strtoupper((string) ($c['status'] ?? '')) !== 'REJECTED' ? (float) $c['accepted_qty'] : 0.0;
        $d = ['n' => 1, 'rq' => $q, 'reclame' => $pu !== null ? $pu * $q : 0.0, 'nonChiffrees' => $pu === null ? 1 : 0, 'accepte' => $pu !== null ? $pu * $qa : 0.0];
        $partout($x['shop'], $mid, $m, $d);
        $motif = trim((string) ($c['reason_name'] ?? $c['reason_code'] ?? '')) ?: 'Motif inconnu';
        $ajoute('motif', rcTypeReclamation($c) . '|' . $motif, $m, $d);
    }
    foreach ($livres as $l) {
        $m = (int) substr($l['cle'], 5, 2);
        $pu = $prix[$l['matiere']] ?? null;
        $partout($l['shop'], $l['matiere'], $m, ['lq' => $l['q'], 'livre' => $l['v'] ?? ($pu !== null ? $pu * $l['q'] : 0.0)]);
    }

    $somme = static function (array $cells) use ($vide): array {
        $t = $vide();
        foreach ($cells as $c) { if ($c) { foreach ($t as $k => $v) { $t[$k] += $c[$k]; } } }
        return $t;
    };
    $fin = static function (?array $c): ?array {
        if ($c === null) { return null; }
        foreach (['reclame', 'accepte', 'livre', 'rq', 'lq'] as $k) { $c[$k] = round($c[$k], 2); }
        $c['part'] = $c['livre'] > 0 ? round(100 * $c['reclame'] / $c['livre'], 2) : null;
        $c['partQ'] = $c['lq'] > 0 ? round(100 * $c['rq'] / $c['lq'], 2) : null;
        return $c;
    };
    $ligne = static function (array $parMois) use ($moisMax, $fin, $somme, $vide): array {
        $mois = [];
        for ($m = 1; $m <= 12; $m++) { $mois[] = $m > $moisMax ? null : $fin($parMois[$m] ?? $vide()); }
        return ['mois' => $mois, 'total' => $fin($somme($parMois))];
    };
    $tri = static fn (array $a, array $b): int => [$b['total']['n'] > 0, $b['total']['reclame']] <=> [$a['total']['n'] > 0, $a['total']['reclame']];

    $magasins = [];
    foreach ($G['mag'] as $s => $pm) { $magasins[] = ['id' => (string) $s, 'nom' => $noms[(int) $s] ?? ('Magasin ' . $s)] + $ligne($pm); }
    usort($magasins, $tri);
    $categories = [];
    foreach ($G['cat'] as $nom => $pm) {
        $ps = [];
        foreach ($prods as $mid => $p) { if (($cats[$mid] ?? 'Sans catégorie') === $nom) { $ps[] = $p + $ligne($G['prod'][(string) $mid] ?? []); } }
        usort($ps, $tri);
        $categories[] = ['nom' => (string) $nom, 'produits' => $ps] + $ligne($pm);
    }
    usort($categories, $tri);

    // Les motifs, rangés par type : leur livré est celui de l'ensemble (le % dit le poids du motif dans le livré).
    $motifs = [];
    $toutL = $G['tout']['*'] ?? [];
    foreach ($G['motif'] as $cle => $pm) {
        [$ty, $nomM] = explode('|', (string) $cle, 2);
        foreach ($toutL as $m => $c) { $pm[$m] ??= $vide(); $pm[$m]['livre'] = $c['livre']; $pm[$m]['lq'] = $c['lq']; }
        $motifs[] = ['nom' => $nomM, 'type' => $ty] + $ligne($pm);
    }
    usort($motifs, fn ($a, $b) => [$a['type'] === 'livraison', $b['total']['n']] <=> [$b['type'] === 'livraison', $a['total']['n']]);

    $mag = [];
    foreach ($toutes as $x) { if ($x['shop'] > 0) { $mag[$x['shop']] = $noms[$x['shop']] ?? ('Magasin ' . $x['shop']); } }
    asort($mag);
    return ['annee' => $annee, 'fournisseur' => $choix, 'shop' => $seul !== null ? (string) $seul : '',
        'fournisseurs' => array_values(array_map(fn ($f) => ['nom' => $f['nom'], 'n' => $f['n']], $parF)),
        'magasinsListe' => array_map(fn ($id, $n) => ['id' => (string) $id, 'nom' => $n], array_keys($mag), array_values($mag)),
        'magasins' => $magasins, 'categories' => $categories, 'motifs' => $motifs, 'ensemble' => $ligne($G['tout']['*'] ?? []),
        'type' => $type, 'types' => $types,
        'produits' => count($prods), 'livreSource' => $source, 'livreDetail' => $detail, 'prixConnus' => count($prix),
        'source' => 'Réclamations : API panel (/consultant/shops/material-complaints). Livré : ' . $detail . '. Montants au prix d’achat.'];
}

/**
 * La photo d'une tâche contrôlée, en octets — pour la réclamation créée depuis le contrôle du cockpit : la photo du
 * contrôle devient la photo de la réclamation. Le lien vient du panel lui-même (pièce jointe de l'avancement de la
 * checklist), jamais d'une adresse fournie par le navigateur. Rend null si la tâche n'a pas de photo lisible.
 */
function rcPhotoTache(int $shop, int $task, string $date, ?callable $telecharger = null): ?string
{
    if ($shop <= 0 || $task <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
    $att = 0;
    foreach (PanelApi::shopChecklists($shop, $date) as $cl) {
        $cid = (int) ($cl['id'] ?? $cl['checklist_id'] ?? 0);
        if ($cid <= 0) { continue; }
        foreach (PanelApi::checklistProgress($shop, $cid, $date) as $p) {
            if ((int) ($p['task_id'] ?? $p['id'] ?? 0) === $task && (int) ($p['attachment_id'] ?? 0) > 0) { $att = (int) $p['attachment_id']; break 2; }
        }
    }
    $url = $att > 0 ? PanelApi::attachmentUrl($att) : null;
    if ($url === null || !preg_match('#^https://#', $url)) { return null; }
    return ($telecharger ?? 'rcTelecharger')($url);
}

/** Les octets d'une image en https (6 Mo au plus, 15 s), ou null. */
function rcTelecharger(string $url): ?string
{
    if (!function_exists('curl_init')) { return null; }
    $ch = curl_init($url);
    $buf = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $d) use (&$buf): int {
            $buf .= $d;
            return strlen($buf) > 6 * 1024 * 1024 ? 0 : strlen($d);   // au-delà de 6 Mo : on coupe
        }]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $ok !== false && $code === 200 && $buf !== '' ? $buf : null;
}
