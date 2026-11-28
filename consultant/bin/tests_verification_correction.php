#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de « Recettes à corriger : corriger d'ici » (13/11/2026, src/verification_correction.php).
 *
 * Sans base, sans réseau : un faux panel tient des recettes, des sous-recettes, des produits, des portions et des prix
 * par magasin ; il recalcule le coût (/cost, products/available) à chaque lecture et applique les écritures comme le
 * ferait le vrai. On y joue chaque geste de bout en bout — la ligne repérée par les règles, l'aperçu, l'écriture, la
 * relecture, la liste recalculée, l'annulation — et les cas où le panel refuse (422) ou accepte sans rien changer.
 *
 *   php bin/tests_verification_correction.php            (avec VRC_FIXTURES=dossier : écrit les réponses JSON pour
 *                                                         la capture de l'écran, docs/maquettes/recettes-correction)
 */

// --- doublures : la base -------------------------------------------------------------------------------------------
final class Db
{
    public static array $reglages = [];
    public static array $registre = [];
    public static array $corr = [];
    public static array $shops = [];
    public static function rows(string $sql, array $p = []): array
    {
        if (str_contains($sql, 'FROM shops')) { return self::$shops; }
        if (str_contains($sql, 'FROM con_verif_recette')) { return array_values(self::$registre); }
        if (str_contains($sql, 'FROM con_verif_correction')) {
            $r = array_reverse(array_values(self::$corr));
            if (str_contains($sql, 'product_id = ?')) { $r = array_values(array_filter($r, static fn ($x) => (int) $x['product_id'] === (int) $p[0])); }
            return $r;
        }
        if (str_contains($sql, "LIKE 'vfRec:%'")) {
            $o = [];
            foreach (self::$reglages as $k => $v) { if (str_starts_with($k, 'vfRec:')) { $o[] = ['key' => $k, 'value' => $v]; } }
            return $o;
        }
        return [];
    }
    public static function row(string $sql, array $p = []): ?array
    {
        if (str_contains($sql, 'MAX(id) id FROM con_verif_correction')) { return ['id' => self::$corr === [] ? 0 : max(array_keys(self::$corr))]; }
        if (str_contains($sql, 'FROM con_verif_correction WHERE id = ?')) { return self::$corr[(int) $p[0]] ?? null; }
        if (str_contains($sql, 'FROM con_verif_recette') && str_contains($sql, 'COUNT(*)')) { return ['n' => 0, 'd' => null]; }
        return null;
    }
    public static function exec(string $sql, array $p = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { self::$reglages[$p[0]] = $p[1]; return 1; }
        if (str_starts_with($sql, 'DELETE FROM ceo_app_setting')) { unset(self::$reglages[$p[0]]); return 1; }
        if (str_starts_with($sql, 'INSERT INTO con_verif_recette')) { self::$registre[$p[0]] = ['cle' => $p[0], 'premier_vu' => $p[8], 'resolu_le' => null]; return 1; }
        if (str_starts_with($sql, 'INSERT INTO con_verif_correction')) {
            preg_match('/\(([^)]*)\)\s*VALUES/', $sql, $m);
            $row = array_combine(array_map('trim', explode(',', $m[1])), $p);
            $id = (self::$corr === [] ? 0 : max(array_keys(self::$corr))) + 1;
            self::$corr[$id] = $row + ['id' => $id, 'annule_par' => null];
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE con_verif_correction SET annule_par')) { self::$corr[(int) $p[1]]['annule_par'] = $p[0]; return 1; }
        return 0;
    }
}
function setting(string $cle, mixed $defaut = null): mixed { return array_key_exists($cle, Db::$reglages) ? json_decode(Db::$reglages[$cle], true) : $defaut; }
function analyseListe($r): array { return is_array($r) ? (array_is_list($r) ? $r : (array) ($r['data'] ?? [])) : []; }
$BODY = [];
function body(): array { global $BODY; return $BODY; }
$JOURNAL = [];
function journalAdd(string $a, string $k, ?string $p, string $m): void { global $JOURNAL; $JOURNAL[] = $k . ' · ' . $p . ' · ' . $m; }

// --- le faux panel --------------------------------------------------------------------------------------------------
final class Panel
{
    public static array $recettes = [];   // rid => objet (comme GET /recipes/{id})
    public static array $sous = [];       // id => objet (comme GET /subrecipes/{id})
    public static array $produits = [];   // pid => [nom, cat, prixHt, id_recipe, divisible, part, shops[], custom[sid]]
    public static array $portions = [];   // id => [id, product_id, portion_type, portion_calculation_value, is_active] (contrat)
    public static array $portionVendue = []; // id => true : son type ne change plus (409)
    public static int $lien = 9000;        // les connection_id que le panel attribue aux lignes neuves
    public static array $prix = [];       // sid => [mid => prix]
    public static array $fournisseur = []; // sid => [mid => true] : prix tenu par un fournisseur intégré (403 SUPPLIER_MANAGED_PRICE)
    public static array $noms = [];       // mid => [nom, unité]
    public static array $stock = [];      // sid => [[mid, nom, q, u]]
    public static array $refus = [];      // chemin => message (422)
    public static array $ignore = [];     // chemin => true : 200 sans rien changer
    public static array $ecrits = [];     // [méthode, chemin, corps]
    public static array $materiaux = [];  // mid => objet (comme GET /materials/{id})
    public static array $tva = [];        // mid => lignes (comme GET /materials/{id}/vat-rates)
    public static array $champsProduit = []; // pid => les autres champs de GET /products/{id} (paramétrage des produits)
    public static array $gammes = [];     // id => [objet de la gamme, [pid]] (/product-availability-periods)
    public static array $prixProduit = []; // sid => [pid => prix TTC]
    public static array $refusChamp = []; // chemin => [clé => réponse 422] : le panel refuse ce champ, nommément

    /** Le coût d'une recette entière dans un magasin, et ses éléments comme /cost les rend. */
    public static function cout(int $sid, array $o, float $fac = 1.0, int $prof = 0): array
    {
        $els = []; $t = 0.0; $complet = true;
        foreach ((array) ($o['materials'] ?? []) as $m) {
            $mid = (int) ($m['id_material'] ?? $m['id']); $q = (float) $m['quantity'] * $fac;
            // Au panel, le prix du magasin passe avant la référence de la matière (contrat du 26/11/2026).
            $ref = self::$materiaux[$mid]['reference_unit_price_net'] ?? null;
            $px = self::$prix[$sid][$mid] ?? ($ref !== null ? (float) $ref : null);
            if ($px === null) { $complet = false; }
            // La perte : celle de la ligne ; celle de la matière seulement si le panel la lit (réglage de la doublure).
            $w = (float) ($m['waste_amount_perc'] ?? 0) + (self::$perteMatiereLue ? (float) (self::$materiaux[$mid]['waste_amount_perc'] ?? 0) : 0.0);
            $c = $px === null ? 0.0 : $q * $px * (1 + $w / 100); $t += $c;
            $els[] = ['type' => 'ingredient', 'material_id' => $mid, 'name' => self::$noms[$mid][0], 'required_quantity' => $q, 'unit_name' => self::$noms[$mid][1],
                'price_net' => $px, 'price_source' => $px === null ? 'MISSING' : (isset(self::$prix[$sid][$mid]) ? 'SUPPLIER' : 'REFERENCE'), 'calculated_req_price_net' => $c,
                'waste_percent' => $w, 'quantity_waste' => $q * $w / 100];
        }
        foreach ((array) ($o['subrecipes'] ?? []) as $s) {
            $so = self::$sous[(int) ($s['id_subrecipe'] ?? $s['id'])];
            $y = (float) ($so['yield_quantity'] ?: 1);
            [$st, $sel, $sc] = self::cout($sid, $so, $fac * (float) $s['quantity'] / $y, $prof + 1);
            $t += $st; $complet = $complet && $sc;
            $els[] = ['type' => 'sub-recipe', 'name' => $so['name'], 'elements' => $sel];
        }
        return [$t, $els, $complet];
    }

    /** Les lignes envoyées, rangées comme le panel les rend : connection_id gardé ou attribué, nom et unité repris. */
    private static function lignes(array $l, bool $sous): ?array
    {
        $o = [];
        foreach ($l as $e) {
            if (!is_array($e) || !isset($e['id'], $e['quantity'])) { return null; }
            $id = (int) $e['id'];
            if ($sous && (float) ($e['waste_amount_perc'] ?? 0) !== 0.0) { return null; }
            $x = ['connection_id' => (int) ($e['connection_id'] ?? ++self::$lien), 'id' => $id, 'quantity' => (float) $e['quantity']];
            $x += $sous ? ['name' => self::$sous[$id]['name'] ?? '?', 'unit_name' => 'l'] : ['waste_amount_perc' => (float) ($e['waste_amount_perc'] ?? 0), 'name' => self::$noms[$id][0] ?? '?', 'unit_name' => self::$noms[$id][1] ?? ''];
            $o[] = $x;
        }
        return $o;
    }

    /**
     * PATCH /recipes/{id} et /subrecipes/{id}, comme le contrat du 26/11/2026 le décrit : la branche « lignes » dès
     * qu'une liste ou une préparation est là (recette : les deux listes exigées, non vides ensemble ; sous-recette :
     * ses champs simples exigés, une liste omise vidée), qui remplace aussi les préparations ; sinon la branche
     * « champs simples » (rendement, unité). Un champ photo efface les fichiers (bug documenté) : noté comme une faute.
     */
    public static function patchRecette(bool $sous, int $id, string $c, array $b): array
    {
        $o = $sous ? (self::$sous[$id] ?? null) : (self::$recettes[$id] ?? null);
        if ($o === null) { PanelApi::$lastError = 'PATCH ' . $c . ' → HTTP 404'; return [false, ['code' => 'FAILED_UPDATE_RECIPE']]; }
        foreach (['main_photo', 'photo_1', 'photo_2', 'photo_3', 'shop_photo'] as $k) { if (array_key_exists($k, $b)) { self::$photosEffacees[] = $c; } }
        $refus = static function (string $code) use ($c): array { PanelApi::$lastError = 'PATCH ' . $c . ' → HTTP 422 : ' . $code; return [false, ['code' => $code]]; };
        $branche = array_intersect_key($b, array_flip(['materials', 'subrecipes', 'preparation_types', 'preparation_steps'])) !== [];
        if ($branche) {
            if (!$sous && (!isset($b['materials'], $b['subrecipes']) || !is_array($b['materials']) || !is_array($b['subrecipes']))) { return $refus('MISSING_ITEM_ARRAYS'); }
            if (!$sous && $b['materials'] === [] && $b['subrecipes'] === []) { return $refus('EMPTY_ITEM_SELECTION'); }
            if ($sous) { foreach (['name', 'yield_quantity', 'id_unit', 'id_storage', 'id_category'] as $k) { if (!isset($b[$k]) || $b[$k] === '') { return $refus('MISSING_' . strtoupper($k)); } } }
            $mats = self::lignes((array) ($b['materials'] ?? []), false); $subs = self::lignes((array) ($b['subrecipes'] ?? []), true);
            if ($mats === null || $subs === null) { return $refus('INVALID_RECIPE_ITEM'); }
            $o['materials'] = $mats; $o['subrecipes'] = $subs;
            $o['preparation_types'] = array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => 'type ' . (int) $t['id'], 'duration_second' => (int) $t['duration_second']], (array) ($b['preparation_types'] ?? []));
            $o['preparation_steps'] = array_map(static fn ($t, $i) => ['step_number' => $i + 1, 'step_description' => (string) $t], array_values((array) ($b['preparation_steps'] ?? [])), array_keys(array_values((array) ($b['preparation_steps'] ?? []))));
            if ($sous) { foreach (['name', 'id_unit', 'id_storage', 'id_category'] as $k) { $o[$k] = $b[$k]; } $o['yield_quantity'] = (float) $b['yield_quantity']; }
        } else {
            foreach (['yield_quantity', 'id_unit'] as $k) { if (array_key_exists($k, $b)) { $o[$k] = (float) $b[$k]; } }
        }
        if ($sous) { self::$sous[$id] = $o; } else { self::$recettes[$id] = $o; }
        return [true, null];   // 204, sans corps
    }

    public static array $photosEffacees = [];
    public static bool $perteMatiereLue = false;   // le coût lit-il la perte de la matière ? (à mesurer au panel)

    public static function get(string $c): mixed
    {
        if (preg_match('#^/shops/(\d+)/recipes/(\d+)/cost$#', $c, $m)) {
            $o = self::$recettes[(int) $m[2]] ?? null;
            if ($o === null) { return null; }
            [$t, $els, $complet] = self::cout((int) $m[1], $o);
            return ['name' => $o['name'], 'yield_quantity' => $o['yield_quantity'], 'cost_net' => $t, 'cost_complete' => $complet, 'elements' => $els];
        }
        if (preg_match('#^/shops/(\d+)/products/available$#', $c, $m)) {
            $sid = (int) $m[1]; $out = [];
            foreach (self::$produits as $pid => $p) {
                if (!in_array($sid, $p['shops'], true)) { continue; }
                $cout = null;
                if (isset($p['custom'][$sid])) { $cout = $p['custom'][$sid]; }
                elseif ($p['id_recipe']) { $o = self::$recettes[$p['id_recipe']]; $y = (float) $o['yield_quantity']; $cout = self::cout($sid, $o)[0] / ($y > 0 ? $y : 1); if ($p['divisible'] && $p['part'] > 0 && $p['part'] < 1) { $cout *= $p['part']; } }
                $out[] = ['id' => $pid, 'base_name' => $p['nom'], 'base_category_name' => $p['cat'], 'id_recipe' => $p['id_recipe'], 'recipe_cost_net' => $cout,
                    'portion_price_net' => $p['divisible'] && $p['part'] > 0 && $p['part'] < 1 ? $p['prixHt'] * $p['part'] : $p['prixHt'], 'is_divisible' => $p['divisible'] ? 1 : 0, 'portion_size' => $p['part']];
            }
            return $out;
        }
        // Mesuré en production le 26/11/2026 : GET /recipes/{id} rend id, name, id_product, les lignes et les
        // préparations, PAS le rendement ; /recipes/{id}/cost le porte.
        if (preg_match('#^/recipes/(\d+)$#', $c, $m)) {
            $o = self::$recettes[(int) $m[1]] ?? null;
            return $o === null ? null : array_diff_key($o, ['yield_quantity' => 1]) + ['preparation_types' => [], 'preparation_steps' => []];
        }
        if (preg_match('#^/recipes/(\d+)/cost$#', $c, $m)) {
            $o = self::$recettes[(int) $m[1]] ?? null;
            if ($o === null) { return null; }
            [$t, $els, $complet] = self::cout(0, $o);
            return ['id' => (int) $m[1], 'name' => $o['name'], 'yield_quantity' => number_format((float) $o['yield_quantity'], 2, '.', ''), 'cost_net' => $t, 'cost_complete' => $complet, 'elements' => $els];
        }
        if (preg_match('#^/subrecipes/(\d+)$#', $c, $m)) { return isset(self::$sous[(int) $m[1]]) ? ['data' => self::$sous[(int) $m[1]]] : null; }
        if (preg_match('#^/materials/(\d+)/vat-rates$#', $c, $m)) { return isset(self::$tva[(int) $m[1]]) ? ['data' => self::$tva[(int) $m[1]]] : null; }
        if (preg_match('#^/materials/(\d+)$#', $c, $m)) { return isset(self::$materiaux[(int) $m[1]]) ? ['data' => self::$materiaux[(int) $m[1]]] : null; }
        if ($c === '/allergens') { return [['id' => 1, 'code' => 'cereals_gluten'], ['id' => 3, 'code' => 'eggs'], ['id' => 7, 'code' => 'milk']]; }
        if ($c === '/units') { return [['id' => 1, 'name' => 'g'], ['id' => 5, 'name' => 'pcs'], ['id' => 2, 'name' => 'kg']]; }
        if ($c === '/material-categories') { return [['id' => 30, 'name' => 'Matières premières (boulangerie)'], ['id' => 31, 'name' => 'Matières premières (traiteur)']]; }
        if ($c === '/countries/BE/materials/vat-rates') { return [['rate' => 6], ['rate' => 21]]; }
        if ($c === '/recipes') { return array_map(static fn ($o) => ['id' => $o['id'], 'name' => $o['name']], array_values(self::$recettes)); }
        if (preg_match('#^/products/(\d+)/portions$#', $c, $m)) { return array_values(array_filter(self::$portions, static fn ($x) => $x['id_product'] === (int) $m[1])); }
        if (preg_match('#^/products/(\d+)$#', $c, $m)) { $p = self::$produits[(int) $m[1]] ?? null; return $p === null ? null : ['product' => ['id' => (int) $m[1], 'name' => $p['nom'], 'id_recipe' => $p['id_recipe']] + (self::$champsProduit[(int) $m[1]] ?? [])]; }
        if ($c === '/product-availability-periods') { return array_map(static fn ($g) => $g[0], array_values(self::$gammes)); }
        if (preg_match('#^/product-availability-periods/(\d+)/products$#', $c, $m)) { return isset(self::$gammes[(int) $m[1]]) ? ['data' => array_map(static fn ($pid) => ['id' => $pid, 'name' => 'p' . $pid], self::$gammes[(int) $m[1]][1])] : null; }
        if (preg_match('#^/shops/(\d+)/products/(\d+)$#', $c, $m)) { return isset(self::$prixProduit[(int) $m[1]][(int) $m[2]]) ? ['data' => ['id' => (int) $m[2], 'portion_price_gross' => number_format(self::$prixProduit[(int) $m[1]][(int) $m[2]], 2, '.', '')]] : null; }
        if ($c === '/product-categories') { return [['id' => 81125, 'name' => 'Tartes – Ø 28 cm', 'price_coefficient' => '2.7000', 'production_area_name' => 'Production Boulangerie'], ['id' => 51000, 'name' => 'Anti-Gaspi', 'price_coefficient' => '2.1500']]; }
        if ($c === '/product-storage-locations') { return [['id' => 30, 'name' => 'Comptoir Frigo - 1'], ['id' => 32, 'name' => 'Comptoir Tartes']]; }
        if (preg_match('#^/product-portions/(\d+)$#', $c, $m)) { return self::$portions[(int) $m[1]] ?? null; }
        if (preg_match('#^/shops/(\d+)/material-inventory$#', $c, $m)) {
            return array_map(static fn ($x) => ['id_material' => $x[0], 'material_name' => $x[1], 'current_quantity' => $x[2], 'unit_name' => $x[3], 'exist_in_inventory' => 1, 'category_name' => 'Sucres'], self::$stock[(int) $m[1]] ?? []);
        }
        return null;
    }

    public static function envoi(string $meth, string $c, array $b): array
    {
        self::$ecrits[] = [$meth, $c, $b];
        if (isset(self::$refus[$c])) { PanelApi::$lastError = $meth . ' ' . $c . ' → HTTP 422 : ' . self::$refus[$c]; return [false, ['description' => self::$refus[$c]]]; }
        if (isset(self::$ignore[$c])) { return [true, ['status' => 'ok']]; }
        foreach (self::$refusChamp[$c] ?? [] as $k => $rep) { if (array_key_exists($k, $b)) { PanelApi::$lastError = $meth . ' ' . $c . ' → HTTP 422'; return [false, $rep]; } }
        if (preg_match('#^/materials/(\d+)/vat-rates$#', $c, $m) && $meth === 'POST') {
            $mid = (int) $m[1]; $fait = false;
            foreach (self::$tva[$mid] as &$l) { if ($l['country_code'] === ($b['country_code'] ?? '')) { $l['vat_rate'] = number_format((float) $b['vat_rate'], 2, '.', ''); $fait = true; } }
            unset($l);
            if (!$fait) { self::$tva[$mid][] = ['country_code' => $b['country_code'], 'vat_rate' => number_format((float) $b['vat_rate'], 2, '.', '')]; }
            return [true, []];
        }
        if (preg_match('#^/materials/(\d+)$#', $c, $m) && $meth === 'PATCH') { self::$materiaux[(int) $m[1]] = array_merge(self::$materiaux[(int) $m[1]], $b); return [true, []]; }
        if (preg_match('#^/(recipes|subrecipes)/(\d+)$#', $c, $m) && $meth === 'PATCH') { return self::patchRecette($m[1] === 'subrecipes', (int) $m[2], $c, $b); }
        if (preg_match('#^/products/(\d+)$#', $c, $m) && $meth === 'PATCH') {
            $pid = (int) $m[1];
            if (array_key_exists('id_recipe', $b)) { self::$produits[$pid]['id_recipe'] = $b['id_recipe']; }
            if (array_key_exists('name', $b)) { self::$produits[$pid]['nom'] = $b['name']; }
            foreach ($b as $k => $v) { if (!in_array($k, ['id_recipe', 'name'], true) && array_key_exists($k, self::$champsProduit[$pid] ?? [])) { self::$champsProduit[$pid][$k] = $v; } }
            return [true, []];
        }
        if (preg_match('#^/products/(\d+)/availability-periods$#', $c, $m) && $meth === 'PATCH') {
            if (!isset($b['availability_periods'])) { PanelApi::$lastError = 'PATCH ' . $c . ' → HTTP 422 : MISSING_REQUIRED_FIELDS'; return [false, ['code' => 'MISSING_REQUIRED_FIELDS']]; }
            foreach (self::$gammes as $id => $g) { $l = array_values(array_diff($g[1], [(int) $m[1]])); if (in_array($id, $b['availability_periods'], true)) { $l[] = (int) $m[1]; } self::$gammes[$id][1] = $l; }
            return [true, []];
        }
        if ($c === '/cases' && $meth === 'POST') { if (!isset($b['area_key'], $b['title'], $b['description'])) { PanelApi::$lastError = 'POST /cases → HTTP 422'; return [false, ['description' => 'MISSING_REQUIRED_FIELDS']]; } return [true, ['data' => ['id' => 99, 'ref' => 'CASE-20261115-TEST1']]]; }
        if (preg_match('#^/shops/(\d+)/products/(\d+)/price$#', $c, $m) && $meth === 'PATCH') { self::$prixProduit[(int) $m[1]][(int) $m[2]] = (float) ($b['portion_price'] ?? ($b['portion_price_gross'] ?? ($b['price'] ?? 0))); return [true, []]; }
        if (preg_match('#^/product-portions/(\d+)$#', $c, $m) && $meth === 'PATCH') {
            // Contrat : portion_type, is_active, display_order, id_package ; une autre clé est ignorée (200 sans effet).
            $id = (int) $m[1];
            if (array_key_exists('portion_type', $b)) {
                if (!isset(VRC_PORTIONS[(string) $b['portion_type']])) { PanelApi::$lastError = 'PATCH ' . $c . ' → HTTP 422 : INVALID_PRODUCT_PORTION_TYPE'; return [false, ['code' => 'INVALID_PRODUCT_PORTION_TYPE']]; }
                if (!empty(self::$portionVendue[$id])) { PanelApi::$lastError = 'PATCH ' . $c . ' → HTTP 409 : PRODUCT_PORTION_TYPE_LOCKED'; return [false, ['code' => 'PRODUCT_PORTION_TYPE_LOCKED']]; }
                $f = VRC_PORTIONS[(string) $b['portion_type']];
                self::$portions[$id]['portion_type'] = (string) $b['portion_type'];
                self::$portions[$id]['portion_calculation_value'] = number_format($f, 6, '.', '');
                self::$produits[self::$portions[$id]['id_product']]['part'] = $f;
            }
            return [true, self::$portions[$id]];
        }
        if (preg_match('#^/materials/(\d+)/manual-price$#', $c, $m) && $meth === 'POST') {
            if (isset($b['shop_ids'])) {   // le contrat : {shop_ids, pack_price, package_size}
                if (!isset($b['pack_price'], $b['package_size'])) { PanelApi::$lastError = 'POST ' . $c . ' → HTTP 422'; return [false, ['description' => 'MISSING_REQUIRED_FIELDS']]; }
                foreach ($b['shop_ids'] as $sh) {
                    if (!empty(self::$fournisseur[(int) $sh][(int) $m[1]])) { PanelApi::$lastError = 'POST ' . $c . ' → HTTP 403 : SUPPLIER_MANAGED_PRICE'; return [false, ['code' => 'SUPPLIER_MANAGED_PRICE', 'description' => 'SUPPLIER_MANAGED_PRICE']]; }
                }
                foreach ($b['shop_ids'] as $sh) { self::$prix[(int) $sh][(int) $m[1]] = (float) $b['pack_price'] / (float) $b['package_size']; }
                return [true, []];
            }
            self::$prix[(int) $b['id_shop']][(int) $m[1]] = (float) $b['price']; return [true, []];
        }
        if (preg_match('#^/shops/(\d+)/recipes/recalculate$#', $c) && $meth === 'POST') { return [true, []]; }
        PanelApi::$lastError = $meth . ' ' . $c . ' → HTTP 404'; return [false, ['description' => 'route inconnue']];
    }
}
final class PanelApi
{
    public static ?string $lastError = null;
    public static ?array $dispo = null;
    public static ?string $jetonImpose = null;
    public static function configured(): bool { return true; }
    public static function config(): array { return ['base' => 'https://atelierby.tfbuddy.com/api/v1']; }
    public static function coutsMatiere(): array { return []; }
    public static function produitsDisponibles(int $sid): array { return Panel::get('/shops/' . $sid . '/products/available'); }
    public static function get(string $c): mixed { $r = Panel::get($c); return is_array($r) ? ($r['data'] ?? $r) : $r; }
    public static function getParallele(array $chemins, int $front = 4, int $timeout = 25): array { $o = []; foreach ($chemins as $k => $c) { $o[$k] = Panel::get($c); } return $o; }
    public static function envoi(string $m, string $c, array $b): array { return Panel::envoi($m, $c, $b); }
    public static function getParalleleDetail(array $chemins, int $front = 4, int $timeout = 25, bool $r = true): array { $o = []; foreach ($chemins as $k => $c) { $d = Panel::get($c); $o[$k] = ['code' => $d === null ? 401 : 200, 'data' => $d, 'ms' => 0, 'erreur' => null]; } return $o; }
    public static function liste(array $d): array { return array_is_list($d) ? array_values(array_filter($d, 'is_array')) : (isset($d['data']) && is_array($d['data']) ? self::liste($d['data']) : []); }
}
function mfMatieresApi(array $sids, bool $frais): array
{
    $out = [];
    foreach ($sids as $sid) {
        $out[$sid] = [];
        foreach (Panel::$noms as $mid => $n) {
            $loc = Panel::$prix[$sid][$mid] ?? null; $ref = Panel::$materiaux[$mid]['reference_unit_price_net'] ?? null;
            $out[$sid][(string) $mid] = ['prix' => $loc ?? ($ref !== null ? (float) $ref : null), 'src' => $loc !== null ? 'LOCAL' : ($ref !== null ? 'REFERENCE' : 'MISSING'), 'nom' => $n[0], 'unite' => $n[1],
                'reference' => $ref !== null ? (float) $ref : null, 'perte' => Panel::$materiaux[$mid]['waste_amount_perc'] ?? null,
                'type' => Panel::$materiaux[$mid]['material_type'] ?? '', 'source' => Panel::$materiaux[$mid]['source_type'] ?? '', 'cat' => ''];
        }
    }
    return $out;
}

// Les doubles du module « fiche matière » (src/matiere_fiche.php) : unités, fournisseurs liés, listes de prix.
function mfUnite(string $u): array { $u = strtolower($u); return $u === 'g' ? ['facteur' => 1000.0, 'label' => 'kg'] : ($u === 'pièce' || $u === 'pcs' ? ['facteur' => 1.0, 'label' => 'pce'] : ['facteur' => 1.0, 'label' => $u]); }
function mfParUnite(?float $prix, ?float $taille, ?float $parColis): ?float { if ($prix === null || $prix <= 0) { return null; } return $prix / (($taille > 0 ? $taille : 1.0) * ($parColis > 0 ? $parColis : 1.0)); }
function mfFournisseurs(bool $frais): array
{
    return ['f' => ['7' => ['nom' => 'SLFood', 'type' => 'CENTRAL'], '8' => ['nom' => 'Berdiff s.a.', 'type' => 'CENTRAL']],
        'm' => ['521' => [['fid' => '7', 'via' => 'catalogue', 'sku' => 'BL5', 'taille' => 5.0, 'tailleUnite' => 'kg', 'parColis' => 1.0]],
                '503' => [['fid' => '8', 'via' => 'catalogue', 'sku' => 'MAYO4', 'taille' => 4.0, 'tailleUnite' => 'pcs', 'parColis' => 1.0]],
                '504' => [['fid' => '8', 'via' => 'catalogue', 'sku' => 'PARM1', 'taille' => 1.0, 'tailleUnite' => 'kg', 'parColis' => 1.0]],
                // Deux références SLFood pour le thon : les magasins sont alignés sur l'une (16 €), l'autre est plus récente (24 €).
                '560' => [['fid' => '7', 'via' => 'catalogue', 'sku' => 'TH1', 'taille' => 1.0, 'tailleUnite' => 'kg', 'parColis' => 1.0], ['fid' => '7', 'via' => 'catalogue', 'sku' => 'TH2', 'taille' => 1.0, 'tailleUnite' => 'kg', 'parColis' => 1.0]],
                '570' => [['fid' => '7', 'via' => 'catalogue', 'sku' => 'PT8', 'taille' => 8.0, 'tailleUnite' => 'pcs', 'parColis' => 1.0]]]];
}
function mfListePrix(string $fid, int $sid, bool $frais): array
{
    // SLFood : les blancs d'œufs (pas à Corbais) et la tarte aux pommes, 61,70 € les 8 depuis le 09/10 (l'audit du 14/11).
    $c = $fid === '7' ? ($sid === 2 ? [] : ['BL5' => ['prix' => 32.5, 'taille' => 5.0, 'tailleUnite' => 'kg', 'depuis' => '2026-10-09', 'nomMatiere' => '']])
        + ['PT8' => ['prix' => 61.7, 'taille' => 8.0, 'tailleUnite' => 'pcs', 'depuis' => '2026-10-09', 'nomMatiere' => ''],
           'TH1' => ['prix' => 16.0, 'taille' => 1.0, 'tailleUnite' => 'kg', 'depuis' => '2026-08-01', 'nomMatiere' => ''], 'TH2' => ['prix' => 24.0, 'taille' => 1.0, 'tailleUnite' => 'kg', 'depuis' => '2026-11-09', 'nomMatiere' => '']]
        : ['MAYO4' => ['prix' => 10.0, 'taille' => 4.0, 'tailleUnite' => 'pcs', 'depuis' => '2026-08-21', 'nomMatiere' => ''],
           // Un colis mal décrit : 2 700 € « le kg » de parmesan (le magasin paie 27 €) — un écart ×100, pas un retard.
           'PARM1' => ['prix' => 2700.0, 'taille' => 1.0, 'tailleUnite' => 'kg', 'depuis' => '2026-08-21', 'nomMatiere' => '']];
    return ['c' => $c, 'l' => [], 'lu' => true];
}

require __DIR__ . '/../src/verification.php';
require __DIR__ . '/../src/verification_correction.php';
require __DIR__ . '/../src/verification_matieres_prix.php';
require __DIR__ . '/../src/verification_matiere_edition.php';
require __DIR__ . '/../src/verification_references.php';
require __DIR__ . '/../src/produit_parametrage.php';
require __DIR__ . '/../src/helpdesk.php';

ob_start();   // les routes posent un code HTTP : rien ne part avant la fin
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  ÉCHEC  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$FIX = getenv('VRC_FIXTURES') ?: '';
function fixture(string $nom, mixed $v): void
{
    global $FIX;
    if ($FIX !== '') { @mkdir($FIX, 0777, true); file_put_contents($FIX . '/' . $nom . '.json', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }
}

// --- le réseau fictif -----------------------------------------------------------------------------------------------
Db::$shops = [['id' => 4, 'name' => 'Atelier by - Halle'], ['id' => 3, 'name' => 'Atelier by - Gosselies'], ['id' => 2, 'name' => 'Atelier by - Corbais']];
Panel::$noms = [501 => ['Tortilla blé', 'pièce'], 502 => ['Poulet émincé', 'kg'], 503 => ['Mayonnaise', 'l'], 504 => ['Parmesan', 'kg'],
    510 => ['Bâtons chocolat', 'kg'], 511 => ['Pâte croissant', 'kg'], 512 => ['Nappage chocolat', 'kg'], 513 => ['Beurre de tourage', 'kg'],
    520 => ['Sucre glace', 'kg'], 521 => ['Blancs d’œufs', 'kg'], 530 => ['Farine T65', 'kg'], 531 => ['Olives', 'kg'], 540 => ['Pâte brisée', 'kg'], 541 => ['Appareil quiche', 'l'],
    550 => ['Tomate', 'kg'], 551 => ['Lardons', 'kg'], 560 => ['Thon', 'kg'], 570 => ['Pomme Tranches 28 Ø', 'pcs'], 599 => ['Sucre perlé', 'kg']];
foreach ([4, 3, 2] as $s) {
    Panel::$prix[$s] = [501 => 0.38, 502 => 14.0, 503 => 5.0, 504 => 27.0, 510 => 13.2, 511 => 3.5, 512 => 9.17, 513 => 8.4, 520 => 1.85, 521 => 6.2, 530 => 0.9, 531 => 11.0, 540 => 4.0, 541 => 3.2, 550 => 2.4, 551 => 12.0, 560 => 16.0, 570 => 7.7125, 599 => 3.1];
}
unset(Panel::$prix[4][520]);   // le sucre glace n'a pas de prix à Halle
Panel::$prix[2][570] = 5.095;  // Corbais en retard sur la liste SLFood (l'audit des Pommes Tranches)
$mat = static fn (int $mid, float $q) => ['id_material' => $mid, 'name' => Panel::$noms[$mid][0], 'quantity' => $q, 'unit_name' => Panel::$noms[$mid][1]];
$rec = static fn (int $id, string $nom, float $y, array $m, array $s = []) => ['id' => $id, 'name' => $nom, 'yield_quantity' => $y, 'materials' => $m, 'subrecipes' => $s];
// Une sous-recette comme GET /subrecipes/{id} la rend (contrat du 26/11/2026) : ses champs simples et ses préparations.
Panel::$sous[77] = ['id' => 77, 'name' => 'Sauce César', 'yield_quantity' => 1.0, 'id_unit' => 4, 'id_storage' => 30, 'id_category' => 5,
    'materials' => [$mat(503, 0.922)], 'subrecipes' => [],
    'preparation_types' => [['id' => 2, 'name' => 'Mixage', 'duration_second' => 120]], 'preparation_steps' => [['step_number' => 1, 'step_description' => 'Mixer']]];
Panel::$recettes = [
    13 => $rec(13, 'Wrapps Poulet Parmesan', 1, [$mat(501, 1), $mat(502, 0.08), $mat(504, 0.015)], [['id_subrecipe' => 77, 'name' => 'Sauce César', 'quantity' => 1.0, 'unit_name' => 'l']]),
    14 => $rec(14, 'Pain au Chocolat Nappé', 1, [$mat(510, 2), $mat(511, 0.06), $mat(512, 0.012)])
        + ['preparation_types' => [['id' => 3, 'name' => 'Cuisson', 'duration_second' => 900]], 'preparation_steps' => [['step_number' => 2, 'step_description' => 'Napper'], ['step_number' => 1, 'step_description' => 'Cuire']]],
    12 => $rec(12, 'Merveilleux', 1, [$mat(520, 0.03), $mat(521, 0.04)]),
    15 => $rec(15, 'Pain Tradition Olives', 0, [$mat(530, 3.2), $mat(531, 0.4)]),
    16 => $rec(16, 'Quiche Lorraine', 1, [$mat(540, 0.25), $mat(541, 0.45), $mat(551, 0.1)]),
    17 => $rec(17, 'FlipFlap Thon', 1, [$mat(501, 1), $mat(560, 0.12), $mat(550, 0.05)]),
    11 => $rec(11, 'Cookie Noix Caramel', 1, []),
    18 => $rec(18, 'Cookie Chocolat', 1, [$mat(530, 0.04), $mat(510, 0.015)]),
    19 => $rec(19, 'Pommes Tranches', 1, [$mat(570, 1), $mat(550, 0.1)]),
    418 => $rec(418, 'Pâtes Amatriciana', 1, [$mat(550, 0.12), $mat(551, 0.05)]),
];
$prod = static fn (string $nom, string $cat, float $ht, ?int $rid, array $x = []) => array_merge(['nom' => $nom, 'cat' => $cat, 'prixHt' => $ht, 'id_recipe' => $rid, 'divisible' => false, 'part' => null, 'shops' => [4, 3, 2], 'custom' => []], $x);
Panel::$produits = [
    100 => $prod('Pâtes Amatriciana', 'Plat Prepare - Chaud', 8.49, null),
    101 => $prod('Cookie Noix & Caramel', 'Cookies', 2.08, 11),
    102 => $prod('Merveilleux', 'Entremets - Individuel', 3.40, 12),
    103 => $prod('Wrapps - Poulet & Parmesan', 'Wrapps', 5.50, 13),
    104 => $prod('Pain au Chocolat Nappé', 'Viennoiserie Ind.', 1.60, 14),
    105 => $prod('Pain Tradition - Olives', 'Pain Tradition', 2.90, 15),
    106 => $prod('1/4 - Quiches', 'Quiches', 14.0, 16, ['divisible' => true, 'part' => null]),
    107 => $prod('FlipFlap - Thon', 'Sandwiches garnis', 5.40, 17, ['custom' => [4 => 0.79]]),
    108 => $prod('Cookie Chocolat', 'Cookies', 2.08, 18),
    109 => $prod('Pommes Tranches', 'Tartes – Ø 28 cm', 19.72, 19),
];
// Une portion comme le contrat la rend : un type, pas de fraction libre. La fiche du produit ne rend pas sa part.
Panel::$portions = [61 => ['id' => 61, 'id_product' => 106, 'portion_type' => 'ONE_HALF', 'portion_calculation_value' => '0.500000', 'is_active' => 1]];
Panel::$stock = [3 => [[599, 'Sucre perlé', 5.0, 'kg']]];
// Les ventes d'hier, gravées comme la moisson les laisse.
$hier = date('Y-m-d', strtotime('-1 day'));
foreach ([4, 3, 2] as $s) {
    $p = [];
    foreach (Panel::$produits as $pid => $x) { $p[(string) $pid] = [$x['nom'], 20, 20 * $x['prixHt'] * 1.06 + $pid]; }
    Db::$reglages['svP' . $s . ':' . $hier] = json_encode(['p' => [$p]]);
}

// --- 1. Les pures -------------------------------------------------------------------------------------------------
echo "Les fonctions pures\n";
verifie('fraction lue dans « 1/4 - Quiches »', vfFractionDuNom('1/4 - Quiches') === 0.25);
verifie('fraction lue dans « ½ Tarte »', vfFractionDuNom('½ Tarte') === 0.5);
verifie('pas de fraction dans « Pain 2/0 »', vfFractionDuNom('Pain 2/0') === null && vfFractionDuNom('Merveilleux') === null);
$o = ['name' => 'R', 'yield_quantity' => 1, 'materials' => [['id_material' => 1, 'quantity' => 2, 'name' => 'A'], ['material_id' => 2, 'pivot' => ['required_quantity' => 0.5], 'name' => 'B']], 'subrecipes' => [['id_subrecipe' => 9, 'quantity' => 1]]];
$m = vrcModifier($o, [['type' => 'facteur', 'cle' => 'm1', 'f' => 0.001], ['type' => 'qte', 'cle' => 'm2', 'q' => 0.25], ['type' => 'retirer', 'cle' => 's9'], ['type' => 'rendement', 'n' => 12]]);
verifie('vrcModifier : seules les clés touchées partent', array_keys($m['corps']) === ['yield_quantity', 'materials', 'subrecipes'], implode(',', array_keys($m['corps'])));
verifie('vrcModifier : ×0,001 posé à plat', $m['corps']['materials'][0]['quantity'] === 0.002);
verifie('vrcModifier : lignes dans la forme du contrat {id, quantity}, la quantité lue dans le pivot', $m['corps']['materials'][1] === ['id' => 2, 'quantity' => 0.25], json_encode($m['corps']['materials']));
verifie('vrcModifier : sous-recette retirée', $m['corps']['subrecipes'] === []);
verifie('vrcModifier : l’inverse garde l’avant', $m['inverse']['materials'] === $o['materials'] && $m['inverse']['yield_quantity'] === 1);
verifie('vrcModifier : ligne inconnue = erreur', vrcModifier($o, [['type' => 'qte', 'cle' => 'm77', 'q' => 1]])['erreurs'] !== []);
verifie('vrcConstater : relu pareil = rien', vrcConstater($m['corps'], array_merge($o, $m['corps'])) === []);
verifie('vrcConstater : relu inchangé = écart', vrcConstater($m['corps'], $o) === ['yield_quantity', 'materials', 'subrecipes']);
// La forme mesurée au panel le 13/11/2026 (GET /recipes/489) : `id` = la matière, `connection_id` = le lien, quantités en texte, pas de rendement.
$vrai = ['id' => 489, 'name' => 'Wrapps - Poulet & Parmesan', 'id_product' => 6700018,
    'materials' => [['connection_id' => 464, 'id' => 607, 'quantity' => '2.00', 'waste_amount_perc' => '0.0000', 'name' => 'Épices poulet', 'id_unit' => 3, 'unit_name' => 'g'],
                    ['connection_id' => 465, 'id' => 387, 'quantity' => '10.00', 'name' => 'Tomates semi séchées', 'id_unit' => 3, 'unit_name' => 'g']],
    'subrecipes' => [['connection_id' => 474, 'id' => 307, 'quantity' => '20.00', 'name' => 'Mayonnaise maison', 'id_unit' => 3, 'unit_name' => 'g']]];
$lu = vrcLire($vrai, 'recette', 489);
verifie('forme réelle : lignes m607, m387 et sous-recette s307, modifiable', array_column($lu['lignes'], 'cle') === ['m607', 'm387'] && $lu['sous'][0]['cle'] === 's307' && $lu['modifiable'] && $lu['cleRend'] === null);
$m = vrcModifier($vrai, [['type' => 'qte', 'cle' => 's307', 'q' => 2], ['type' => 'ajouter', 'entree' => ['connection_id' => 999, 'id' => 610, 'quantity' => '5.00', 'name' => 'Parmesan', 'id_unit' => 3], 'q' => 4]]);
verifie('forme réelle : la quantité de la sous-recette, le connection_id gardé', $m['corps']['subrecipes'][0]['quantity'] === 2.0 && $m['corps']['subrecipes'][0]['connection_id'] === 474);
verifie('forme réelle : une ligne reprise d’ailleurs part sans son connection_id', !isset($m['corps']['materials'][2]['connection_id']) && $m['corps']['materials'][2]['quantity'] === 4.0 && $m['corps']['materials'][0]['connection_id'] === 464);
verifie('forme réelle : pas de rendement lu, pas de rendement écrit', vrcModifier($vrai, [['type' => 'rendement', 'n' => 12]])['corps'] === [] && vrcModifier($vrai, [['type' => 'rendement', 'n' => 12]])['erreurs'] !== []);
verifie('forme réelle : « 2.00 » relu vaut 2', vrcConstater(['materials' => [['id' => 607, 'quantity' => 2.0]]], ['materials' => [['id' => 607, 'quantity' => '2.00']]]) === []);
$c2 = vrcModifier($vrai, [['type' => 'remplacer', 'materials' => $vrai['materials'], 'subrecipes' => []]]);
verifie('copier : les lignes du modèle partent sans leurs connection_id', !isset($c2['corps']['materials'][0]['connection_id']) && $c2['corps']['materials'][0]['id'] === 607);
// Le contrat du 26/11/2026 : le panel remplace, il ne fusionne pas. Les corps complets.
$cc = vrcCorpsContrat('recette', $vrai + ['preparation_types' => [['id' => 4, 'name' => 'Four', 'duration_second' => 60]], 'preparation_steps' => [['step_number' => 1, 'step_description' => 'Garnir']]],
    ['materials' => [['id' => 607, 'quantity' => 3.0, 'connection_id' => 464]], 'yield_quantity' => 2]);
verifie('contrat : une liste touchée, les deux partent, les préparations aussi', array_keys($cc['lignes']) === ['materials', 'subrecipes', 'preparation_types', 'preparation_steps']
    && $cc['lignes']['subrecipes'][0] === ['id' => 307, 'quantity' => 20.0, 'connection_id' => 474] && $cc['lignes']['preparation_steps'] === ['Garnir'] && $cc['lignes']['preparation_types'] === [['id' => 4, 'duration_second' => 60]], json_encode($cc));
verifie('contrat : le rendement d’une recette de produit part à part (le PATCH des lignes l’ignore)', $cc['simples'] === ['yield_quantity' => 2] && !isset($cc['lignes']['yield_quantity']));
$sr = ['id' => 307, 'name' => 'Mayonnaise maison', 'yield_quantity' => '1.00', 'id_unit' => 3, 'id_storage' => 30, 'id_category' => 5,
    'materials' => [['connection_id' => 1, 'id' => 503, 'quantity' => '0.9', 'waste_amount_perc' => '0.0000']], 'subrecipes' => [], 'preparation_steps' => []];
$cs = vrcCorpsContrat('sousRecette', $sr, ['materials' => [['id' => 503, 'quantity' => 0.8, 'connection_id' => 1]], 'yield_quantity' => 2.0]);
verifie('contrat : une sous-recette porte ses champs simples, son rendement voyage avec ses lignes', $cs['simples'] === null && $cs['lignes']['name'] === 'Mayonnaise maison'
    && $cs['lignes']['yield_quantity'] === 2.0 && $cs['lignes']['id_storage'] === 30 && $cs['lignes']['subrecipes'] === [] && $cs['erreurs'] === [], json_encode($cs));
$cs2 = vrcCorpsContrat('sousRecette', array_diff_key($sr, ['id_storage' => 1]), ['materials' => []]);
verifie('contrat : une sous-recette lue sans son stockage ne part pas', str_contains(implode(' ', $cs2['erreurs']), 'id_storage'));
verifie('contrat : une recette de produit sans aucune ligne ne part pas', vrcCorpsContrat('recette', $vrai, ['materials' => [], 'subrecipes' => []])['erreurs'] !== []);
verifie('contrat : une ligne illisible bloque l’écriture (la liste repartirait sans elle)', vrcCorpsContrat('recette', ['materials' => [['name' => 'sans id']], 'subrecipes' => []], ['subrecipes' => []])['erreurs'] !== []);
verifie('contrat : pas de perte envoyée sur une sous-recette', !array_key_exists('waste_amount_perc', vrcLigneContrat(['id' => 7, 'quantity' => 1, 'waste_amount_perc' => '0.0000'], 'subrecipes')));
verifie('contrat : les étapes en textes, dans l’ordre des numéros', vrcEtapesContrat([['step_number' => 2, 'step_description' => 'B'], ['step_number' => 1, 'step_description' => ' A '], ['step_number' => 3, 'step_description' => '']]) === ['A', 'B']);
verifie('portion : les sept types du panel, et rien d’autre', vrcTypePortion(0.25) === 'ONE_QUARTER' && vrcTypePortion(2 / 3) === 'TWO_THIRDS' && vrcTypePortion(0.3) === null
    && vrcFractionPortion(['portion_calculation_value' => '0.125000']) === 0.125 && vrcFractionPortion(['portion_type' => 'ONE_HALF']) === 0.5 && vrcFractionTexte(0.75) === '3/4');
$ef = vrcEffet([1 => ['nom' => 'Salade Tagliata', 'cout' => 0.8604, 'complet' => false, 'prix' => 0.0042], 2 => ['nom' => 'Club', 'cout' => 1.2, 'complet' => true, 'prix' => 0.0042]],
    [1 => ['nom' => 'Salade Tagliata', 'cout' => 0.9084, 'complet' => false, 'prix' => 0.005], 2 => ['nom' => 'Club', 'cout' => 1.2, 'complet' => true, 'prix' => 0.005]]);
verifie('effet : ce qui bouge d’abord, l’écart et le pourcentage', $ef[0]['rid'] === 1 && abs($ef[0]['ecart'] - 0.048) < 1e-9 && abs($ef[0]['pct'] - 5.58) < 0.01 && $ef[1]['ecart'] === 0.0, json_encode($ef));
verifie('effet : la phrase nomme la recette et compte les autres', str_contains(vrcEffetResume($ef), 'Salade Tagliata 0,860 € → 0,908 € (+5,6 %)') && str_contains(vrcEffetResume($ef), '1 sans changement'), vrcEffetResume($ef));
verifie('effet : un prix qui change sans qu’aucun food cost ne bouge est signalé', str_contains(vrcEffetResume([$ef[1]]), 'aucun n’a bougé, alors que le prix de la matière y a changé'), vrcEffetResume([$ef[1]]));

$pr = vrcProches('Pâtes Amatriciana', [418 => 'Pâtes Amatriciana', 12 => 'Merveilleux', 30 => 'Pates amatriciana (old)'], [30 => true]);
verifie('vrcProches : même nom et libre d’abord', ($pr[0]['rid'] ?? 0) === 418 && $pr[0]['pareil'] && $pr[0]['libre']);
verifie('vrcProches : écarte les noms sans rapport', !in_array(12, array_column($pr, 'rid'), true));

// --- 2. La liste et ses propositions -------------------------------------------------------------------------------
echo "La liste repère, et propose\n";
$a = vfAnalyse(50);
fixture('recettes', $a + ['cache' => null]);
$par = []; foreach ($a['lignes'] as $l) { $par[$l['cle']] = $l; }
verifie('pas de recette : Pâtes Amatriciana', isset($par['sans:100']));
verifie('unité suspecte : Pain au Chocolat Nappé, ÷1000 sur les bâtons', ($par['unite:104']['corr']['mid'] ?? 0) === 510 && ($par['unite:104']['corr']['facteur'] ?? 0) === 0.001, json_encode($par['unite:104']['corr'] ?? null));
verifie('unité suspecte dans une sous-recette : la sous-recette est nommée', ($par['unite:103']['corr']['sous'] ?? '') === 'Sauce César', json_encode(array_keys($par)));
verifie('matière sans prix : sucre glace à Halle, 1,85 € proposé', ($par['prix:102']['corr']['sid'] ?? 0) === 4 && ($par['prix:102']['corr']['matieres'][0]['propose'] ?? 0) === 1.85);
verifie('rendement à zéro : Pain Tradition', isset($par['rend:105']));
verifie('portion sans fraction : ¼ lu dans le nom', ($par['port:106']['corr']['fraction'] ?? null) === 0.25);
verifie('hors norme : FlipFlap à Halle, magasin 4', ($par['reseau:107']['corr']['sid'] ?? 0) === 4);
verifie('recette vide : Cookie Noix & Caramel', isset($par['vide:101']));
verifie('matière jamais en recette : sucre perlé', isset($par['orph:m599']));
verifie('tarif : Corbais en retard sur SLFood, Halle et Gosselies alignés', ($par['tarif:109']['corr']['matieres'][0]['shops'] ?? []) === [2]
    && ($par['tarif:109']['corr']['matieres'][0]['ref'] ?? 0) === 7.7125 && $par['tarif:109']['corr']['matieres'][0]['alignes'] === ['Halle', 'Gosselies'], json_encode($par['tarif:109'] ?? array_keys($par)));
verifie('tarif : Halle n’est plus « hors norme » parce que Corbais est en retard', !isset($par['reseau:109']) && $par['tarif:109']['magasins'] === ['Corbais']);
verifie('tarif : le coût de la pièce, avant → après', abs(($par['tarif:109']['corr']['coutAvant'] ?? 0) - 5.335) < 1e-6 && abs($par['tarif:109']['corr']['coutApres'] - 7.9525) < 1e-6, json_encode($par['tarif:109']['corr'] ?? null));
verifie('tarif : sous le seuil de 10 %, pas de signalement (blancs d’œufs 6,20 face à 6,50 : 4,6 %)', !isset($par['tarif:102']));
verifie('tarif : un écart ×100 est un colis à vérifier, il ne corrige pas le coût', ($par['tarif:103']['corr']['matieres'][0]['suspect'] ?? false) === true
    && str_contains($par['tarif:103']['detail'], 'colis') && abs(($par['tarif:103']['corr']['coutApres'] ?? 0) - $par['tarif:103']['corr']['coutAvant']) < 1e-9, json_encode($par['tarif:103'] ?? null));
verifie('tarif : deux références, le magasin aligné sur l’une n’est pas en retard (thon à 16 € face à 16 € et 24 €)', !isset($par['tarif:107']));
$th = null; foreach ($t0 = vmpOptions('kg', [['fid' => '7', 'nom' => 'SLFood', 'type' => 'CENTRAL', 'sku' => 'TH1', 'parColis' => 1.0, 'taille' => 1.0, 'tailleUnite' => 'kg'], ['fid' => '7', 'nom' => 'SLFood', 'type' => 'CENTRAL', 'sku' => 'TH2', 'parColis' => 1.0, 'taille' => 1.0, 'tailleUnite' => 'kg']], ['7' => mfListePrix('7', 4, false)], null, null, null, 'Thon') as $x) { $th[] = $x['id']; }
verifie('options : une par référence, pas fondues', $th === ['f7-TH1', 'f7-TH2'], json_encode($th));
verifie('la liste porte les adresses du panel', ($a['liens']['produit'] ?? '') !== '' && str_contains((string) ($a['liens']['recette'] ?? ''), '/panel/recipes'));

// --- 3. Les gestes de bout en bout ---------------------------------------------------------------------------------
echo "Les gestes\n";
$geste = static function (array $b) { global $BODY; $BODY = $b; return wr_verification_corriger(); };
$cles = static fn (?array $liste) => array_column((array) ($liste['lignes'] ?? []), 'cle');

// Unité suspecte : l'aperçu n'écrit rien, l'application écrit, relit, et la ligne quitte la liste.
$n0 = count(Panel::$ecrits);
$ap = $geste(['geste' => 'quantite', 'apercu' => true, 'rid' => 14, 'mid' => 510, 'facteur' => 0.001, 'cle' => 'unite:104', 'pid' => 104, 'nom' => 'Pain au Chocolat Nappé']);
fixture('apercu-unite', $ap);
verifie('aperçu : rien n’est écrit au panel', count(Panel::$ecrits) === $n0 && !empty($ap['apercu']));
verifie('aperçu : PATCH /recipes/14 et la phrase', ($ap['ecritures'][0]['route'] ?? '') === '/recipes/14' && str_contains((string) $ap['resume'], 'Bâtons chocolat : 2 kg → 0,002 kg'), (string) ($ap['resume'] ?? ''));
$r = $geste(['geste' => 'quantite', 'rid' => 14, 'mid' => 510, 'facteur' => 0.001, 'cle' => 'unite:104', 'pid' => 104, 'nom' => 'Pain au Chocolat Nappé', 'shop' => 4]);
fixture('corriger-unite', $r);
verifie('unité : écrite et relue', $r['ok'] && $r['ecritures'][0]['resultat'] === 'ok', json_encode($r['ecritures'] ?? $r));
verifie('unité : la quantité est au panel', abs(Panel::$recettes[14]['materials'][0]['quantity'] - 0.002) < 1e-9);
verifie('unité : le corps complet du contrat part (les deux listes, les préparations)', array_keys(end(Panel::$ecrits)[2]) === ['materials', 'subrecipes', 'preparation_types', 'preparation_steps'], implode(',', array_keys(end(Panel::$ecrits)[2])));
verifie('unité : les préparations repartent telles que lues, les étapes en textes dans l’ordre', end(Panel::$ecrits)[2]['preparation_steps'] === ['Cuire', 'Napper'] && end(Panel::$ecrits)[2]['preparation_types'] === [['id' => 3, 'duration_second' => 900]]
    && array_column(Panel::$recettes[14]['preparation_steps'], 'step_description') === ['Cuire', 'Napper']);
verifie('unité : les connection_id gardés, aucun champ photo', array_column(end(Panel::$ecrits)[2]['materials'], 'connection_id') !== [] || Panel::$photosEffacees === []);
verifie('unité : la ligne quitte la liste recalculée', !in_array('unite:104', $cles($r['liste']), true) && in_array('sans:100', $cles($r['liste']), true));
verifie('unité : notée au registre et au journal', count(Db::$corr) === 1 && str_contains(implode("\n", $JOURNAL), 'Correction au panel'));

// Le volet : la sous-recette Sauce César, de 1 l à 0,1 l dans le Wrapps.
$ed = (static function () { $_GET = ['rid' => '13', 'pid' => '103']; return ep_verification_edition(); })();
fixture('edition-103', $ed);
verifie('édition : la recette puis la sous-recette', count($ed['objets']) === 2 && $ed['objets'][1]['type'] === 'sousRecette' && $ed['objets'][1]['nom'] === 'Sauce César');
verifie('édition : modifiable, quantité et coût d’une pièce', $ed['objets'][0]['modifiable'] && ($ed['objets'][0]['sous'][0]['q'] ?? 0) === 1.0 && ($ed['objets'][1]['lignes'][0]['coutPiece'] ?? 0) > 4.6);
verifie('édition : les matières à ajouter viennent des recettes lues', count($ed['matieres']) >= 10);
$_GET = ['pid' => '103'];
fixture('fiche-103', ep_verification_fiche());
fixture('apercu-volet', $geste(['geste' => 'recette', 'apercu' => true, 'rid' => 13, 'pid' => 103, 'nom' => 'Wrapps - Poulet & Parmesan', 'changements' => [['type' => 'qte', 'objet' => 'recette', 'id' => 13, 'cle' => 's77', 'q' => 0.1]]]));
$r = $geste(['geste' => 'recette', 'rid' => 13, 'pid' => 103, 'nom' => 'Wrapps - Poulet & Parmesan', 'changements' => [['type' => 'qte', 'objet' => 'recette', 'id' => 13, 'cle' => 's77', 'q' => 0.1]]]);
fixture('corriger-volet', $r);
verifie('volet : la sous-recette passe à 0,1', $r['ok'] && abs(Panel::$recettes[13]['subrecipes'][0]['quantity'] - 0.1) < 1e-9, json_encode($r['ecritures'] ?? $r));
verifie('volet : le Wrapps quitte la liste', !in_array('unite:103', $cles($r['liste']), true) && !in_array('inv:103', $cles($r['liste']), true));

// Ajouter une matière reprise d'une autre recette (dans sa forme), retirer une ligne.
$r = $geste(['geste' => 'recette', 'rid' => 14, 'pid' => 104, 'nom' => 'Pain au Chocolat Nappé', 'changements' => [['type' => 'ajouter', 'objet' => 'recette', 'id' => 14, 'mid' => 530, 'source' => 18, 'q' => 0.01], ['type' => 'retirer', 'objet' => 'recette', 'id' => 14, 'cle' => 'm512']]]);
$mids = array_map(static fn ($x) => (int) ($x['id_material'] ?? $x['id']), Panel::$recettes[14]['materials']);
verifie('volet : matière ajoutée, ligne retirée', $r['ok'] && in_array(530, $mids, true) && !in_array(512, $mids, true), json_encode($mids));

// Rendement, puis son annulation.
$r = $geste(['geste' => 'rendement', 'rid' => 15, 'n' => 12, 'cle' => 'rend:105', 'pid' => 105, 'nom' => 'Pain Tradition - Olives']);
verifie('rendement : 12 au panel, la ligne part', $r['ok'] && Panel::$recettes[15]['yield_quantity'] === 12.0 && !in_array('rend:105', $cles($r['liste']), true));
$idRend = $r['ecritures'][0]['id'];
global $BODY; $BODY = ['id' => $idRend];
$u = wr_verification_annuler();
fixture('annuler', $u);
verifie('annuler : le rendement revient à 0, relu', $u['ok'] && (float) Panel::$recettes[15]['yield_quantity'] === 0.0 && in_array('rend:105', $cles($u['liste']), true), json_encode($u['ecritures'] ?? $u));
verifie('annuler : la correction n’est plus annulable', !vrcLigneRegistre(Db::$corr[$idRend])['annulable'] && !vrcLigneRegistre(Db::$corr[$u['ecritures'][0]['id']])['annulable']);
$BODY = ['id' => $idRend];
verifie('annuler deux fois : refusé', !wr_verification_annuler()['ok']);

// Rattacher une recette : les candidats, puis l'écriture sur le produit.
$_GET = ['nom' => 'Pâtes Amatriciana', 'pid' => '100'];
$c = ep_verification_candidats();
fixture('candidats-100', $c);
verifie('candidats : la 418, libre, même nom', ($c['candidats'][0]['rid'] ?? 0) === 418 && $c['candidats'][0]['libre'] && $c['candidats'][0]['pareil']);
$r = $geste(['geste' => 'rattacher', 'pid' => 100, 'rid' => 418, 'cle' => 'sans:100', 'nom' => 'Pâtes Amatriciana']);
verifie('rattacher : PATCH /products/100 {id_recipe: 418}', $r['ok'] && end(Panel::$ecrits)[1] === '/products/100' && end(Panel::$ecrits)[2] === ['id_recipe' => 418]);
verifie('rattacher : la ligne part', !in_array('sans:100', $cles($r['liste']), true));
verifie('rattacher : l’inverse remet « sans recette »', json_decode((string) Db::$corr[max(array_keys(Db::$corr))]['inverse'], true) === ['id_recipe' => null]);

// La fraction d'une portion.
$r = $geste(['geste' => 'portion', 'pid' => 106, 'shop' => 4, 'fraction' => 0.25, 'cle' => 'port:106', 'nom' => '1/4 - Quiches']);
verifie('portion : PATCH /product-portions/61 {portion_type: ONE_QUARTER} (contrat), relue', $r['ok'] && end(Panel::$ecrits)[1] === '/product-portions/61' && end(Panel::$ecrits)[2] === ['portion_type' => 'ONE_QUARTER'] && Panel::$portions[61]['portion_type'] === 'ONE_QUARTER', json_encode($r['ecritures'] ?? $r));
verifie('portion : la ligne part', !in_array('port:106', $cles($r['liste']), true));
verifie('portion : une part que le panel ne connaît pas ne part pas', !$geste(['geste' => 'portion', 'pid' => 106, 'shop' => 4, 'fraction' => 0.3, 'nom' => '1/4 - Quiches'])['ok']);
Panel::$portionVendue[61] = true;
$r = $geste(['geste' => 'portion', 'pid' => 106, 'shop' => 4, 'fraction' => 0.5, 'nom' => '1/4 - Quiches']);
verifie('portion déjà vendue : 409, et le message dit quoi faire au panel', !$r['ok'] && ($r['ecritures'][0]['http'] ?? 0) === 409 && str_contains($r['ecritures'][0]['message'], 'désactivez l’ancienne'), json_encode($r));
unset(Panel::$portionVendue[61]);

// Une ligne de la sous-recette : PATCH /subrecipes/77 complet (champs simples, deux listes, préparations), puis son annulation.
$r = $geste(['geste' => 'quantite', 'rid' => 13, 'mid' => 503, 'sous' => 'Sauce César', 'q' => 0.85, 'pid' => 103, 'nom' => 'Wrapps - Poulet & Parmesan', 'shop' => 4]);
$e = end(Panel::$ecrits);
verifie('sous-recette : PATCH /subrecipes/77 avec name, rendement, unité, stockage, catégorie et les deux listes', $r['ok'] && $e[1] === '/subrecipes/77' && $e[2]['name'] === 'Sauce César'
    && $e[2]['yield_quantity'] === 1.0 && $e[2]['id_storage'] === 30 && $e[2]['id_category'] === 5 && $e[2]['subrecipes'] === [], json_encode($r['ecritures'] ?? $r));
verifie('sous-recette : la quantité changée, ses étapes et ses types gardés', abs(Panel::$sous[77]['materials'][0]['quantity'] - 0.85) < 1e-9
    && array_column(Panel::$sous[77]['preparation_steps'], 'step_description') === ['Mixer'] && Panel::$sous[77]['preparation_types'][0]['id'] === 2);
$BODY = ['id' => $r['ecritures'][0]['id']];
$u = wr_verification_annuler();
verifie('sous-recette : l’annulation remet 0,922, par un corps aussi complet', $u['ok'] && abs(Panel::$sous[77]['materials'][0]['quantity'] - 0.922) < 1e-9 && end(Panel::$ecrits)[2]['name'] === 'Sauce César', json_encode($u['ecritures'] ?? $u));

// Une ligne et le rendement d'une recette de produit dans le même geste : deux PATCH, relus l'un et l'autre, puis annulés.
$n0 = count(Panel::$ecrits);
$r = $geste(['geste' => 'recette', 'rid' => 18, 'pid' => 108, 'nom' => 'Cookie Chocolat', 'changements' => [['type' => 'qte', 'objet' => 'recette', 'id' => 18, 'cle' => 'm530', 'q' => 0.05], ['type' => 'rendement', 'objet' => 'recette', 'id' => 18, 'n' => 2]]]);
$deux = array_slice(Panel::$ecrits, $n0);
verifie('lignes et rendement : les lignes, puis le rendement seul, tous deux relus', $r['ok'] && count($deux) === 2 && isset($deux[0][2]['materials'], $deux[0][2]['subrecipes'])
    && !isset($deux[0][2]['yield_quantity']) && $deux[1][2] === ['yield_quantity' => 2.0] && (float) Panel::$recettes[18]['yield_quantity'] === 2.0, json_encode($r['ecritures'] ?? $r));
foreach (array_reverse($r['ecritures']) as $x) { $BODY = ['id' => $x['id']]; wr_verification_annuler(); }
verifie('lignes et rendement : annulés, la recette revient à l’avant', (float) Panel::$recettes[18]['yield_quantity'] === 1.0 && abs(Panel::$recettes[18]['materials'][0]['quantity'] - 0.04) < 1e-9);

// Le prix d'une matière.
$r = $geste(['geste' => 'prix', 'shop' => 4, 'mid' => 520, 'prix' => 1.85, 'matiere' => 'Sucre glace', 'rid' => 12, 'pid' => 102, 'cle' => 'prix:102', 'nom' => 'Merveilleux']);
fixture('corriger-prix', $r);
verifie('prix : POST /materials/520/manual-price {shop_ids, pack_price, package_size} (contrat), relu', $r['ok'] && end(Panel::$ecrits)[2] === ['shop_ids' => [4], 'pack_price' => 1.85, 'package_size' => 1], json_encode(end(Panel::$ecrits)));
verifie('prix : la ligne part', !in_array('prix:102', $cles($r['liste']), true));
verifie('prix : sans retour possible (l’avant était « aucun prix »)', !$r['ecritures'][0]['annulable']);

// Le test demandé le 26/11/2026 : changer le prix d'une matière, et voir si les food costs des recettes bougent.
$px0 = Panel::$prix[4][501];
$ap = $geste(['geste' => 'prix', 'apercu' => true, 'shop' => 4, 'mid' => 501, 'prix' => $px0 + 0.12, 'matiere' => 'Tortilla blé', 'rid' => 13]);
verifie('effet du prix : l’aperçu annonce les food costs relus', str_contains((string) $ap['resume'], 'food cost de 2 recette(s) relu au panel'), (string) ($ap['resume'] ?? ''));
$r = $geste(['geste' => 'prix', 'shop' => 4, 'mid' => 501, 'prix' => $px0 + 0.12, 'matiere' => 'Tortilla blé', 'rid' => 13, 'pid' => 103, 'nom' => 'Wrapps - Poulet & Parmesan']);
fixture('corriger-prix-effet', $r);
$ef = $r['ecritures'][0]['effet'] ?? [];
verifie('effet du prix : le food cost relu dans les recettes qui portent la matière (Wrapps, FlipFlap)', $r['ok'] && array_column($ef, 'rid') !== [] && !array_diff([13, 17], array_column($ef, 'rid')), json_encode($r['ecritures'][0] ?? $r));
$w = array_values(array_filter($ef, static fn ($x) => $x['rid'] === 13))[0] ?? [];
verifie('effet du prix : le Wrapps prend 0,12 € par pièce, le registre et le message le disent', abs(($w['ecart'] ?? 0) - 0.12) < 1e-6
    && abs(($r['ecritures'][0]['coutApres'] ?? 0) - ($r['ecritures'][0]['coutAvant'] ?? 0) - 0.12) < 1e-6 && str_contains($r['ecritures'][0]['message'], 'Food cost relu au panel : 2 recette(s) sur 2 ont bougé'), $r['ecritures'][0]['message'] ?? '');
$BODY = ['id' => $r['ecritures'][0]['id']];
$u = wr_verification_annuler();
verifie('effet du prix : l’annulation remet le prix, et les food costs reviennent', $u['ok'] && abs(Panel::$prix[4][501] - $px0) < 1e-9
    && abs(array_sum(array_column($u['ecritures'][0]['effet'] ?? [], 'ecart')) + 0.24) < 1e-6, json_encode($u['ecritures'][0] ?? $u));

// Recalculer : le coût « custom » ne bouge pas → sans effet, et le message le dit.
$r = $geste(['geste' => 'recalculer', 'shop' => 4, 'pid' => 107, 'rid' => 17, 'cle' => 'reseau:107', 'nom' => 'FlipFlap - Thon']);
fixture('corriger-recalcul', $r);
verifie('recalculer : sans effet, le coût custom est nommé', !$r['ok'] && $r['ecritures'][0]['resultat'] === 'sans_effet' && str_contains($r['ecritures'][0]['message'], 'custom'));

// Hors recette, et son annulation.
$r = $geste(['geste' => 'horsRecette', 'cle' => 'orph:m599', 'nom' => 'Sucre perlé']);
verifie('hors recette : rien au panel, la matière ne remonte plus', $r['ok'] && end(Panel::$ecrits)[1] !== null && !in_array('orph:m599', $cles($r['liste']), true));
$BODY = ['id' => $r['ecritures'][0]['id']];
$u = wr_verification_annuler();
verifie('hors recette annulé : elle remonte', $u['ok'] && in_array('orph:m599', $cles($u['liste']), true));

// Le panel refuse (422), ou accepte sans rien changer.
Panel::$refus['/recipes/11'] = 'materials.0.id_unit is required';
$r = $geste(['geste' => 'copier', 'rid' => 11, 'source' => 18, 'pid' => 101, 'cle' => 'vide:101', 'nom' => 'Cookie Noix & Caramel']);
fixture('corriger-refus', $r);
verifie('refus : noté refusé, avec le message du panel, rien n’a changé', !$r['ok'] && $r['ecritures'][0]['resultat'] === 'refuse' && str_contains($r['ecritures'][0]['message'], 'id_unit') && Panel::$recettes[11]['materials'] === []);
verifie('refus : la liste n’est pas recalculée', $r['liste'] === null);
unset(Panel::$refus['/recipes/11']);
Panel::$ignore['/recipes/11'] = true;
$r = $geste(['geste' => 'copier', 'rid' => 11, 'source' => 18, 'pid' => 101, 'cle' => 'vide:101', 'nom' => 'Cookie Noix & Caramel']);
verifie('2xx sans changement : « sans effet », la relecture le dit', !$r['ok'] && $r['ecritures'][0]['resultat'] === 'sans_effet' && str_contains($r['ecritures'][0]['message'], 'materials'));
unset(Panel::$ignore['/recipes/11']);
$r = $geste(['geste' => 'copier', 'rid' => 11, 'source' => 18, 'pid' => 101, 'cle' => 'vide:101', 'nom' => 'Cookie Noix & Caramel']);
verifie('copier : la recette vide prend les lignes du modèle', $r['ok'] && count(Panel::$recettes[11]['materials']) === 2 && !in_array('vide:101', $cles($r['liste']), true));

// Aligner Corbais sur la liste SLFood : le geste « prix » que propose la ligne « tarif ».
$t = $par['tarif:109']['corr']['matieres'][0];
$r = $geste(['geste' => 'prix', 'mid' => $t['mid'], 'prix' => $t['ref'], 'shops' => $t['shops'], 'matiere' => $t['nom'], 'rid' => 19, 'pid' => 109, 'cle' => 'tarif:109', 'nom' => 'Pommes Tranches']);
verifie('tarif : aligné en un geste, la ligne part, l’ancien prix s’annule', $r['ok'] && Panel::$prix[2][570] === 7.7125 && !in_array('tarif:109', $cles($r['liste']), true) && $r['ecritures'][0]['annulable'], json_encode($r['ecritures'] ?? $r));

// Le formulaire de prix (14/11/2026) : la route et les champs saisis, la réponse brute du panel, « garder ce format ».
$n0 = count(Panel::$ecrits);
$r = $geste(['geste' => 'prix', 'mid' => 560, 'prix' => 17.5, 'shops' => [3], 'matiere' => 'Thon', 'format' => ['methode' => 'POST', 'route' => '/products/1', 'corps' => ['price' => '{prix}']]]);
verifie('formulaire : une route hors des routes de prix ne part pas', !$r['ok'] && count(Panel::$ecrits) === $n0 && str_contains($r['ecritures'][0]['message'], 'Route refusée'));
$r = $geste(['geste' => 'prix', 'mid' => 560, 'prix' => 17.5, 'shops' => [3], 'matiere' => 'Thon', 'format' => ['methode' => 'PATCH', 'route' => '/shops/{sid}/materials/avg-price', 'corps' => ['id_material' => '{mid}', 'avg_price' => '{prix}', 'valid_from' => '{date}']]]);
verifie('formulaire : refus du panel, sa réponse brute est rendue', !$r['ok'] && $r['ecritures'][0]['resultat'] === 'refuse' && str_contains((string) $r['ecritures'][0]['reponse'], 'route inconnue') && $r['ecritures'][0]['corps']['id_material'] === 560 && $r['ecritures'][0]['corps']['valid_from'] === date('Y-m-d'), json_encode($r['ecritures'][0]));
$r = $geste(['geste' => 'prix', 'mid' => 560, 'prix' => 17.5, 'shops' => [3], 'matiere' => 'Thon', 'garder' => true, 'format' => ['methode' => 'POST', 'route' => '/materials/{mid}/manual-price', 'corps' => ['id_shop' => '{sid}', 'price' => '{prix}']]]);
verifie('formulaire : le format qui prend est gardé pour tous les prix', $r['ok'] && Panel::$prix[3][560] === 17.5 && setting('vrcFormatPrix')['route'] === '/materials/{mid}/manual-price');
Panel::$prix[3][560] = 16.0;

// Les garde-fous.
verifie('geste inconnu : 400', isset($geste(['geste' => 'supprimer'])['error']));
verifie('quantité nulle : refusée avant le panel', !$geste(['geste' => 'quantite', 'rid' => 14, 'mid' => 511, 'q' => 0])['ok']);
verifie('matière absente de la recette : refusée avant le panel', !$geste(['geste' => 'quantite', 'rid' => 14, 'mid' => 999, 'q' => 1])['ok']);

// Le registre, les adresses du panel.
$_GET = [];
$g = ep_verification_registre();
fixture('registre', $g);
verifie('registre : toutes les écritures, la plus récente d’abord', count($g['lignes']) === count(Db::$corr) && $g['lignes'][0]['id'] === max(array_keys(Db::$corr)));
$BODY = ['recette' => 'https://atelierby.tfbuddy.com/panel/recipes/{rid}/edit', 'matiere' => 'http://x'];
verifie('adresses : https seulement', !wr_verification_liens()['ok']);
$BODY = ['recette' => 'https://atelierby.tfbuddy.com/panel/recipes/{rid}/edit'];
$l = wr_verification_liens();
verifie('adresses : modèle de la recette réglé, défaut du produit gardé', $l['ok'] && $l['liens']['recette'] === 'https://atelierby.tfbuddy.com/panel/recipes/{rid}/edit' && str_contains($l['liens']['produit'], '/panel/products/{pid}'));
verifie('adresses : un lien sans identifiant ne se fait pas', !isset(vrcLiensDe(['pid' => 5])['recette']) && vrcLiensDe(['pid' => 5, 'rid' => 9])['recette'] === 'https://atelierby.tfbuddy.com/panel/recipes/9/edit');

// --- 4. Les matières premières et leur prix de référence (14/11/2026) ---------------------------------------------
echo "Les matières premières\n";
$o = vmpOptions('g', [['fid' => '8', 'nom' => 'Berdiff s.a.', 'type' => 'CENTRAL', 'sku' => 'T', 'parColis' => 1.0, 'taille' => 4.0, 'tailleUnite' => 'pcs']],
    ['8' => ['c' => ['T' => ['prix' => 10.83, 'taille' => 4.0, 'tailleUnite' => 'pcs', 'depuis' => '2026-08-21']], 'lu' => true]], null, null, null, 'Poche Sauce Tikka');
verifie('options : colis de pièces pour une matière en g → conversion, prix par pièce', $o[0]['prix'] === null && $o[0]['conversion'] === 'g' && abs($o[0]['parPiece'] - 2.7075) < 1e-9);
$o = vmpOptions('g', [['fid' => '7', 'nom' => 'SLFood', 'type' => 'CENTRAL', 'sku' => 'B', 'parColis' => 1.0, 'taille' => 5.0, 'tailleUnite' => 'kg']],
    ['7' => ['c' => ['B' => ['prix' => 32.5, 'taille' => 5.0, 'tailleUnite' => 'kg', 'depuis' => '2026-10-09']], 'lu' => true]], null, null, 6.0, 'Blancs');
verifie('options : colis en kg pour une matière en g → prix par g (÷1000), puis la médiane', abs($o[0]['prix'] - 0.0065) < 1e-12 && $o[1]['type'] === 'mediane' && vmpReference($o)['id'] === 'f7-B');
unset(Panel::$prix[3][521]);
vrcOublier('matieresApi:3');
$_GET = [];
$t = ep_verification_matieres_prix();
fixture('matieres', $t);
$bl = null; foreach ($t['lignes'] as $l) { if ($l['mid'] === 521) { $bl = $l; } }
verifie('tableau : les blancs d’œufs, employés par le Merveilleux', $bl !== null && $bl['recettes'][0]['nom'] === 'Merveilleux' && $bl['nRecettes'] === 1, json_encode(array_column($t['lignes'], 'nom')));
verifie('tableau : sans prix à Gosselies seulement', $bl['drapeaux']['sansPrix'] === 1 && $bl['magasins'][1]['prix'] === null && $bl['magasins'][0]['prix'] === 6.2);
$f7 = null; foreach ($bl['options'] as $op) { if (str_starts_with($op['id'], 'f7')) { $f7 = $op; } }
verifie('tableau : SLFood en vigueur à Halle et Gosselies, pas à Corbais (liste vide)', $f7 !== null && $f7['enVigueur'] && $f7['prixMagasins']['4'] === 6.5 && $f7['prixMagasins']['2'] === null);
verifie('tableau : écart de plus de 10 % à la référence (6,20 face à 6,50 = −4,6 %, non)', $bl['drapeaux']['ecart'] === 0);
$mayo = null; foreach ($t['lignes'] as $l) { if ($l['mid'] === 503) { $mayo = $l; } }
verifie('tableau : mayonnaise en poches de pièces → unité à vérifier', $mayo !== null && $mayo['drapeaux']['conversion']);
verifie('tableau : gardé dix minutes', isset(ep_verification_matieres_prix()['cache']['le']));
// Un prix pour deux magasins d'un coup (Gosselies sans prix, Halle qui en avait un) ; l'aperçu n'écrit rien.
$n0 = count(Panel::$ecrits);
$ap = $geste(['geste' => 'prix', 'apercu' => true, 'mid' => 521, 'matiere' => 'Blancs d’œufs', 'prix' => 6.5, 'shops' => [3, 4]]);
verifie('prix à plusieurs magasins : l’aperçu dit qui a un retour possible', count(Panel::$ecrits) === $n0 && count($ap['ecritures']) === 2 && !$ap['ecritures'][0]['annulable'] && $ap['ecritures'][1]['annulable']);
$r = $geste(['geste' => 'prix', 'mid' => 521, 'matiere' => 'Blancs d’œufs', 'prix' => 6.5, 'shops' => [3, 4]]);
verifie('prix à plusieurs magasins : deux écritures relues', $r['ok'] && count($r['ecritures']) === 2 && Panel::$prix[3][521] === 6.5 && Panel::$prix[4][521] === 6.5);
verifie('prix à plusieurs magasins : le tableau sera recalculé', setting('vmpTable') === null);
$idH = $r['ecritures'][1]['id'];
verifie('prix remplacé : annulable, premier prix : non', $r['ecritures'][1]['annulable'] && !$r['ecritures'][0]['annulable']);
$BODY = ['id' => $idH];
$u = wr_verification_annuler();
verifie('annuler un prix : l’ancien prix repart, relu', $u['ok'] && Panel::$prix[4][521] === 6.2 && Panel::$prix[3][521] === 6.5, json_encode($u));

// --- 5. La fiche d'une matière, modifiable (14/11/2026) ----------------------------------------------------------
echo "La fiche d'une matière\n";
Panel::$materiaux[521] = ['id' => 521, 'name' => 'Blancs d’œufs', 'id_category' => 31, 'id_unit' => 2, 'unit_name' => 'kg', 'material_type' => 'RAW', 'source_type' => 'CENTRAL',
    'waste_amount_perc' => '0.00', 'vat_rate' => '6.00', 'is_part_of_package' => 0, 'can_be_product_addon' => 0, 'allergens' => [['id' => 3, 'code' => 'eggs']],
    'reference_unit_price_net' => null, 'suggested_base_unit_price_net' => null];
$ch = vmxChangements(Panel::$materiaux[521], ['name' => 'Blancs d’œufs', 'waste_amount_perc' => 5, 'allergens' => ['eggs', 'milk'], 'is_part_of_package' => true, 'kind' => 'simple'], vmxAllergenesRef());
verifie('matière : une clé absente du panel ne s’écrit pas', count($ch['erreurs']) === 1 && str_contains($ch['erreurs'][0], 'Genre'));
$ch = vmxChangements(Panel::$materiaux[521], ['name' => 'Blancs d’œufs', 'waste_amount_perc' => 5, 'allergens' => ['eggs', 'milk'], 'is_part_of_package' => true], vmxAllergenesRef());
$cc = array_keys($ch['corps']); sort($cc);
verifie('matière : seules les clés changées partent, dans la forme lue', $cc === ['allergens', 'is_part_of_package', 'waste_amount_perc'] && $ch['corps']['waste_amount_perc'] === 5 && $ch['corps']['is_part_of_package'] === true
    && $ch['corps']['allergens'] === [3, 7], json_encode($ch));
verifie('matière : les allergènes partent en identifiants (contrat), quelle que soit la forme lue', vmxAllergenesPour('eggs', ['eggs', 'milk'], vmxAllergenesRef()) === [3, 7] && vmxAllergenesPour('eggs', ['eggs', 'milk'], []) === 'eggs, milk');
$_GET = ['mid' => '521'];
$f = ep_verification_matiere();
fixture('matiere-521', $f);
$parCle = array_column($f['champs'], null, 'cle');
verifie('fiche : les champs rendus sont modifiables, les autres non', $parCle['name']['modifiable'] && !$parCle['kind']['modifiable'] && $parCle['allergens']['valeur'] === ['eggs']);
verifie('fiche : listes de référence et ligne du tableau', count($f['refs']['categories']) === 2 && $f['refs']['tva'] === [6.0, 21.0] && ($f['ligne']['mid'] ?? 0) === 521 && $f['par'] === 'kg');
$n0 = count(Panel::$ecrits);
$BODY = ['mid' => 521, 'apercu' => true, 'champs' => ['name' => 'Blancs d’œufs frais', 'reference_unit_price_net' => 6.5], 'magasins' => ['2' => 6.5]];
$ap = wr_verification_matiere();
fixture('apercu-matiere', $ap);
verifie('fiche : l’aperçu n’écrit rien, montre le PATCH et le prix du magasin', count(Panel::$ecrits) === $n0 && count($ap['ecritures']) === 2 && $ap['ecritures'][0]['route'] === '/materials/521');
$BODY['apercu'] = false;
$r = wr_verification_matiere();
fixture('ecrire-matiere', $r);
verifie('fiche : paramètres écrits et relus, puis le prix de Corbais', $r['ok'] && Panel::$materiaux[521]['name'] === 'Blancs d’œufs frais' && Panel::$materiaux[521]['reference_unit_price_net'] === '6.5' && Panel::$prix[2][521] === 6.5, json_encode($r));
$pm = array_values(array_filter(Panel::$ecrits, static fn ($e) => $e[1] === '/materials/521'));
verifie('fiche : le PATCH ne porte que les deux clés changées', end($pm)[2] === ['name' => 'Blancs d’œufs frais', 'reference_unit_price_net' => '6.5']);
$BODY = ['id' => $r['ecritures'][0]['id']];
$u = wr_verification_annuler();
verifie('fiche : « Annuler » remet le nom et le prix général', $u['ok'] && Panel::$materiaux[521]['name'] === 'Blancs d’œufs' && Panel::$materiaux[521]['reference_unit_price_net'] === null, json_encode($u));
Panel::$ignore['/materials/521'] = true;
$BODY = ['mid' => 521, 'champs' => ['waste_amount_perc' => 3], 'magasins' => ['3' => 7.0]];
$r = wr_verification_matiere();
verifie('fiche : paramètres sans effet → les prix ne partent pas', !$r['ok'] && $r['ecritures'][0]['resultat'] === 'sans_effet' && count($r['ecritures']) === 1 && Panel::$prix[3][521] === 6.5);
unset(Panel::$ignore['/materials/521']);

// La TVA, sur sa route (/materials/{id}/vat-rates : elle n'est pas dans la matière, mesuré le 14/11).
Panel::$tva[521] = [['id' => 9, 'country_code' => 'BE', 'vat_rate' => '6.00']];
unset(Panel::$materiaux[521]['vat_rate']);
$_GET = ['mid' => '521'];
$f = ep_verification_matiere();
$cT = array_column($f['champs'], null, 'cle')['vat_rate'];
verifie('TVA : lue sur sa route, modifiable', $cT['modifiable'] && $cT['valeur'] === 6.0);
verifie('TVA : le corps reprend la ligne lue, taux changé (sans son id)', vmxTvaCorps(Panel::$tva[521][0], 21.0) === ['country_code' => 'BE', 'vat_rate' => 21.0]);
$BODY = ['mid' => 521, 'champs' => ['vat_rate' => 21]];
$r = wr_verification_matiere();
verifie('TVA : posée, relue', $r['ok'] && Panel::$tva[521][0]['vat_rate'] === '21.00' && end(Panel::$ecrits)[1] === '/materials/521/vat-rates', json_encode($r));
$BODY = ['id' => $r['ecritures'][0]['id']];
$u = wr_verification_annuler();
verifie('TVA : « Annuler » remet 6 %', $u['ok'] && Panel::$tva[521][0]['vat_rate'] === '6.00', json_encode($u));
$BODY = ['mid' => 521, 'champs' => ['vat_rate' => 'abc']];
verifie('TVA : une valeur invalide ne part pas', !wr_verification_matiere()['ok']);


// --- Produits › Paramétrage (14/11/2026, src/produit_parametrage.php) ---------------------------------------------
Panel::$champsProduit[100] = ['tax_val_perc' => 6, 'is_active' => 1, 'webshop_active' => 0, 'suggested_sale_price' => '21.90', 'shelf_life_minutes' => 2880, 'id_storage' => 30,
    'id_category' => 81125, 'shelf_life_category' => 'SSL', 'sector_id' => null, 'min_coefficient' => null, 'storage_temperature' => 0];
Panel::$gammes = [14 => [['id' => 14, 'name' => 'Gamme Standard', 'from_md' => 101, 'to_md' => 1231, 'is_recurring' => 1, 'is_active' => 1, 'webshop_active' => 0], [100]],
    6 => [['id' => 6, 'name' => 'Automnale', 'from_md' => 901, 'to_md' => 1101, 'is_recurring' => 1, 'is_active' => 1, 'webshop_active' => 1], []]];
foreach (array_keys(Db::$reglages) as $k) { if (str_starts_with($k, 'ppm')) { unset(Db::$reglages[$k]); } }
$_GET = ['pid' => '100'];
$f = ep_produits_parametrage();
$cP = array_column($f['champs'] ?? [], null, 'cle');
verifie('paramétrage : la fiche lit le produit, ses champs rendus sont modifiables, les autres non', ($f['ok'] ?? false) && $cP['suggested_sale_price']['modifiable'] && !$cP['nutriscore']['modifiable'] && $f['gammesProduit'] === [14], json_encode($f['ok'] ?? $f));
verifie('paramétrage : listes de référence et liaisons mesurées', isset($f['refs']['categories']['81125']) && count($f['liaisons']) === count(PPM_LIAISONS) && $f['liaisons'][0]['etat'] === 'lisible');
$ch = ppmChangements(Panel::$champsProduit[100] + ['name' => 'Wrapps'], ['suggested_sale_price' => '22,5', 'webshop_active' => true, 'tax_val_perc' => '6', 'id_storage' => '32']);
verifie('paramétrage : seules les clés changées, dans la forme lue (texte décimal, entier, 0/1)', $ch['corps'] === ['suggested_sale_price' => '22.50', 'webshop_active' => 1, 'id_storage' => 32] && $ch['inverse']['suggested_sale_price'] === '21.90', json_encode($ch));
verifie('paramétrage : un champ que le panel ne rend pas ne s’écrit pas', ppmChangements(Panel::$champsProduit[100], ['nutriscore' => 'A'])['erreurs'] !== []);
$BODY = ['pid' => 100, 'apercu' => true, 'champs' => ['suggested_sale_price' => '22,50', 'webshop_active' => 1], 'gammes' => [14, 6], 'prix' => []];
$a = wr_produits_parametrage();
verifie('paramétrage : l’aperçu montre le PATCH du produit et l’envoi des gammes', ($a['apercu'] ?? false) && count($a['ecritures']) === 2 && $a['ecritures'][0]['route'] === '/products/100'
    && $a['ecritures'][1]['route'] === '/products/100/availability-periods' && $a['ecritures'][1]['corps'] === ['availability_periods' => [6, 14]], json_encode($a));
$BODY['apercu'] = false;
$r = wr_produits_parametrage();
verifie('paramétrage : écrit, relu, gammes posées', $r['ok'] && Panel::$champsProduit[100]['suggested_sale_price'] === '22.50' && in_array(100, Panel::$gammes[6][1], true), json_encode($r));
$BODY = ['id' => $r['ecritures'][0]['id']];
$u = wr_verification_annuler();
verifie('paramétrage : « Annuler » remet le prix conseillé', $u['ok'] && Panel::$champsProduit[100]['suggested_sale_price'] === '21.90', json_encode($u));
$BODY = ['pid' => 100, 'gammes' => [14], 'formats' => ['gammes' => ['methode' => 'PATCH', 'route' => '/products/{pid}/availability-periods', 'corps' => ['periods' => '{gammes}']]]];
$r = wr_produits_parametrage();
verifie('paramétrage : un format de gammes refusé rend la réponse brute du panel', !$r['ok'] && $r['ecritures'][0]['resultat'] === 'refuse' && str_contains($r['ecritures'][0]['reponse'], 'MISSING_REQUIRED_FIELDS'), json_encode($r));
$BODY = ['pid' => 100, 'gammes' => [14], 'formats' => ['gammes' => ['methode' => 'PATCH', 'route' => '/recipes/1', 'corps' => ['x' => 1]]]];
verifie('paramétrage : une route hors des gammes est refusée avant tout envoi', !wr_produits_parametrage()['ok'] && end(Panel::$ecrits)[1] !== '/recipes/1');
Panel::$prixProduit[3][100] = 19.9;
$BODY = ['pid' => 100, 'prix' => ['3' => '20,90'], 'garder' => true, 'formats' => ['prix' => ['methode' => 'PATCH', 'route' => '/shops/{sid}/products/{pid}/price', 'corps' => ['portion_price_gross' => '{prix}']]]];
$r = wr_produits_parametrage();
verifie('paramétrage : prix d’un magasin écrit et relu, format gardé', $r['ok'] && Panel::$prixProduit[3][100] === 20.9 && ppmFormat('prix')['corps'] === ['portion_price_gross' => '{prix}'], json_encode($r));
verifie('paramétrage : gammes d’une forme quelconque', ppmIdsProduits(['data' => [['id_product' => 5], ['product_id' => 6], 7]]) === [5, 6, 7] || ppmIdsProduits([['id_product' => 5], ['product_id' => 6]]) === [5, 6]);


// Un champ refusé nommément (mesuré le 14/11 : coefficient minimum contredit par une promotion active) n'emporte pas les autres.
Panel::$refusChamp['/products/100'] = ['min_coefficient' => ['code' => null, 'description' => 'Nie można zapisać minimalnego współczynnika.', 'errors' => ['min_coefficient' => 'Update rejected by active promotion #2 (4 Quarts + 1 Gratuit).',
    'promotion_id' => 2, 'promotion_name' => '4 Quarts + 1 Gratuit', 'reason' => 'INVALID_PROMOTION_DATA', 'min_coefficient_details' => ['contexts' => [['shop_id' => 3, 'sales_channel' => 'POS', 'status' => 'VIOLATED'], ['shop_id' => 4, 'sales_channel' => 'POS', 'status' => 'OK']]]]]];
$BODY = ['pid' => 100, 'champs' => ['min_coefficient' => '2,5', 'storage_temperature' => '4']];
$r = wr_produits_parametrage();
$e0 = $r['ecritures'][0] ?? []; $e1 = $r['ecritures'][1] ?? [];
verifie('paramétrage : le champ refusé est nommé, en français, avec la promotion et le magasin', count($r['ecritures']) === 2 && $e0['resultat'] === 'refuse' && str_contains($e0['message'], '« 4 Quarts + 1 Gratuit »')
    && str_contains($e0['message'], 'Coefficient minimum') && !str_contains($e0['message'], 'Halle') && str_contains($e0['reponse'], 'INVALID_PROMOTION_DATA'), json_encode($r));
verifie('paramétrage : les autres champs repartent seuls, écrits et relus', $e1['resultat'] === 'ok' && Panel::$champsProduit[100]['storage_temperature'] === 4 && Panel::$champsProduit[100]['min_coefficient'] === null && end(Panel::$ecrits)[2] === ['storage_temperature' => 4], json_encode($r));
unset(Panel::$refusChamp['/products/100']);

PanelApi::$jetonImpose = 'jeton-admin-de-test';
$BODY = ['geste' => 'prix', 'mid' => 560, 'prix' => 17.0, 'shops' => [3], 'matiere' => 'Thon'];
$r = wr_verification_corriger();
verifie('compte admin : l’écriture le note au registre', str_contains((string) ($r['ecritures'][0]['message'] ?? ''), 'compte administrateur du panel'), json_encode($r));
PanelApi::$jetonImpose = null;
verifie('prix : la route de la centrale (tarif du fournisseur pour un magasin) est permise', vrcPrixPlan(2, 659, 7.7125, ['methode' => 'POST', 'route' => '/material-suppliers/1/shops/{sid}/products/422/price', 'corps' => ['price_net' => '61.70', 'valid_from' => '{date}']])['permise']
    && vrcPrixPlan(2, 659, 7.7125, ['methode' => 'POST', 'route' => '/material-suppliers/1/shops/{sid}/products/422/price', 'corps' => ['price_net' => '61.70']])['corps'] === ['price_net' => 61.7]);


// --- Le helpdesk des franchisés (15/11/2026, src/helpdesk.php) ----------------------------------------------------
$d = hdkDemande(['id' => 5, 'ref' => 'CASE-1', 'area_key' => 'operational_activities', 'status' => 'IN_PROGRESS', 'title' => ' Verrière ', 'description' => 'x', 'shop_id' => 5,
    'shop_name' => 'Atelier by Harmonie - Sombreffe', 'requester_name' => 'H.', 'requester_email' => 'h@example.com', 'admin_reply' => null, 'created_at' => '2026-05-19 10:00:00', 'consultant_name' => 'Loic V.']);
verifie('helpdesk : une demande lue, sans le courriel du demandeur', $d['titre'] === 'Verrière' && $d['domaineNom'] === 'Activités opérationnelles' && $d['ouverte'] && !in_array('h@example.com', $d, true) && $d['statutNom'] === 'En cours');
$t = hdkConstat([['nom' => 'Verrière', 'etat' => 'a_suivre', 'constat' => 'devis bardage seulement'], ['nom' => 'Affichage', 'etat' => 'conforme', 'constat' => '']], '2026-10-26', 'Loic V.');
verifie('helpdesk : le constat de la mission, en texte pour le franchisé', str_contains($t, '26/10/2026 (Loic V.)') && str_contains($t, '• Verrière — à suivre : devis bardage seulement') && str_contains($t, '• Affichage — conforme'));
$BODY = ['shop' => 5, 'domaine' => 'xx', 'titre' => 'T', 'description' => 'D'];
verifie('helpdesk : un sujet inconnu ne part pas', !wr_helpdesk_magasin()['ok']);
$BODY = ['shop' => 5, 'domaine' => 'product', 'titre' => 'Tarte 22', 'description' => 'Une plus petite', 'passage' => true];
$r = wr_helpdesk_magasin();
verifie('helpdesk : la demande part au panel (POST /cases, contrat), le passage noté dans la description', $r['ok'] && $r['id'] === 99 && end(Panel::$ecrits)[1] === '/cases'
    && end(Panel::$ecrits)[2]['area_key'] === 'product' && end(Panel::$ecrits)[2]['shop_id'] === 5 && str_contains(end(Panel::$ecrits)[2]['description'], 'Passage du consultant souhaité'), json_encode($r));

// --- Références et pertes, par lot (26/11/2026, src/verification_references.php) ------------------------------------
echo "Références et pertes\n";
verifie('pertes courantes : persil 30, oignons 10, radicchio 15 ; rien sur un produit transformé ni un emballage', vrfPerteCourante('Persil plat')[0] === 30 && vrfPerteCourante('Oignons rouges')[0] === 10
    && vrfPerteCourante('radicchio')[0] === 15 && vrfPerteCourante('Tomates semi séchées') === null && vrfPerteCourante('Sauce tomate maison') === null && vrfPerteCourante('Tomates cerises')[0] === 3
    && vrfPerteCourante('Salade feuille de chêne')[0] === 10 && vrfPerteCourante('Bol à salade', 'PACKAGING') === null);
verifie('moyenne réseau : les prix propres des magasins, pas une référence ni un manque', vrfMoyenneReseau([4 => ['prix' => 0.004, 'src' => 'LOCAL'], 3 => ['prix' => 0.006, 'src' => 'SUPPLIER'], 2 => ['prix' => 0.01, 'src' => 'REFERENCE'], 5 => ['prix' => null, 'src' => 'MISSING']]) === ['prix' => 0.005, 'n' => 2, 'min' => 0.004, 'max' => 0.006]);
$fic = ['prix' => ['756' => ['prix' => 10.0, 'source' => 'estimé']], 'pertes' => ['282' => ['perte' => 3, 'raison' => 'pédoncules']]];
$pp = vrfProposition(['mid' => 756, 'nom' => 'Mesclun', 'facteur' => 1000.0, 'prix' => [4 => ['prix' => null, 'src' => 'MISSING']], 'reference' => null, 'perte' => 0], $fic);
verifie('proposition : sans prix au réseau, le prix de marché (10 €/kg → 0,01 €/g), et la perte courante', $pp['origine'] === 'marche' && abs($pp['prix'] - 0.01) < 1e-12 && $pp['perte'] === 10 && $pp['perteOrigine'] === 'courante' && $pp['change'], json_encode($pp));
$pp = vrfProposition(['mid' => 282, 'nom' => 'Tomates cerises', 'facteur' => 1000.0, 'prix' => [4 => ['prix' => 0.0042, 'src' => 'LOCAL'], 3 => ['prix' => 0.0042, 'src' => 'LOCAL']], 'reference' => 0.0042, 'perte' => 3], $fic);
verifie('proposition : déjà au panel (référence et perte), rien à changer', $pp['origine'] === 'reseau' && $pp['n'] === 2 && !$pp['change'] && $pp['perteOrigine'] === 'fichier', json_encode($pp));
verifie('le fichier des prix de marché : mesclun, radicchio, steak, et les pertes de la Tagliata', (vrfFichier()['prix']['761']['prix'] ?? null) === 17.0 && (vrfFichier()['pertes']['356']['perte'] ?? null) === 30);

// La Salade Tagliata de la doublure : tomates cerises chiffrées au réseau, mesclun et steak sans prix nulle part.
Panel::$noms += [282 => ['Tomates cerises', 'g'], 756 => ['Mesclun', 'g'], 761 => ['Steak de bœuf', 'g']];
foreach ([4, 3, 2] as $s) { Panel::$prix[$s][282] = 0.0042; }
foreach ([282 => ['OPEN', 'Tomates cerises'], 756 => ['CENTRAL', 'Mesclun'], 761 => ['CENTRAL', 'Steak de bœuf']] as $m => [$src, $nm]) {
    Panel::$materiaux[$m] = ['id' => $m, 'name' => $nm, 'id_category' => 31, 'id_unit' => 3, 'unit_name' => 'g', 'material_type' => 'RAW', 'source_type' => $src, 'waste_amount_perc' => 0, 'reference_unit_price_net' => null, 'allergens' => []];
}
Panel::$recettes[216] = $rec(216, 'Salade Tagliata', 1, [$mat(282, 60), $mat(756, 70), $mat(761, 100)]);
$_GET = ['rid' => '216'];
$R = ep_verification_references();
fixture('references-216', $R);
$par = []; foreach ($R['lignes'] ?? [] as $l) { $par[$l['mid']] = $l; }
verifie('références : la Tagliata lue, ses trois matières', ($R['ok'] ?? false) && count($R['lignes']) === 3 && $R['nom'] === 'Salade Tagliata' && abs(($par[282]['q'] ?? 0) - 60) < 1e-9, json_encode($R));
verifie('références : tomates au prix du réseau (4,20 €/kg), mesclun et steak au prix de marché (10 et 17 €/kg)', abs($par[282]['propose']['prix'] * 1000 - 4.2) < 1e-9 && $par[282]['propose']['origine'] === 'reseau'
    && abs($par[756]['propose']['prix'] * 1000 - 10) < 1e-9 && $par[756]['propose']['origine'] === 'marche' && abs($par[761]['propose']['prix'] * 1000 - 17) < 1e-9);
verifie('références : les pertes du fichier (tomates 3, mesclun 10, steak 5)', $par[282]['propose']['perte'] === 3 && $par[756]['propose']['perte'] === 10 && $par[761]['propose']['perte'] === 5);
$coutAvant = Panel::get('/shops/4/recipes/216/cost')['cost_net'];
$lot = ['rid' => 216, 'lignes' => [['mid' => 282, 'prix' => 4.2, 'perte' => 3], ['mid' => 756, 'prix' => 10, 'perte' => 10], ['mid' => 761, 'prix' => 17, 'perte' => 5]]];
$n0 = count(Panel::$ecrits);
$BODY = $lot + ['apercu' => true];
$ap = wr_verification_references();
verifie('références : l’aperçu, trois PATCH /materials/{id}, rien d’écrit', ($ap['apercu'] ?? false) && count($ap['ecritures']) === 3 && $ap['ecritures'][1]['route'] === '/materials/756'
    && $ap['ecritures'][1]['corps'] === ['reference_unit_price_net' => '0.01', 'waste_amount_perc' => 10] && count(Panel::$ecrits) === $n0, json_encode($ap));
$BODY = $lot;
$r = wr_verification_references();
fixture('references-tagliata', $r);
verifie('références : écrites et relues au panel (référence et perte de la matière)', $r['ok'] && Panel::$materiaux[756]['reference_unit_price_net'] === '0.01' && Panel::$materiaux[761]['waste_amount_perc'] === 5
    && Panel::$materiaux[282]['reference_unit_price_net'] === '0.0042' && Panel::$prix[4][282] === 0.0042, json_encode($r['ecritures'] ?? $r));
$e4 = array_values(array_filter($r['effet'], static fn ($m) => $m['sid'] === 4))[0] ?? [];
$t4 = array_values(array_filter($e4['lignes'] ?? [], static fn ($x) => $x['rid'] === 216))[0] ?? [];
verifie('références : le food cost de la Tagliata relu, de 0,252 € à 2,652 € (mesclun 0,70 € et steak 1,70 € comptés)', abs(($t4['avant'] ?? 0) - 0.252) < 1e-6 && abs(($t4['apres'] ?? 0) - 2.652) < 1e-6 && count($r['effet']) === 3, json_encode($e4));
verifie('références : le panel ne lit pas la perte de la matière → elle est à poser sur les lignes', str_contains((string) ($r['pertes']['message'] ?? ''), 'n’entre pas') && count($r['pertes']['aPoser']) === 3, json_encode($r['pertes']));
$r2 = $geste(['geste' => 'recette', 'rid' => 216, 'nom' => 'Salade Tagliata', 'changements' => array_map(static fn ($x) => ['type' => 'perte', 'mid' => $x['mid'], 'p' => $x['p']], $r['pertes']['aPoser'])]);
$coutPerte = Panel::get('/shops/4/recipes/216/cost')['cost_net'];
verifie('pertes sur les lignes : PATCH /recipes/216 complet, relu, et le coût les compte', $r2['ok'] && end(Panel::$ecrits)[1] === '/recipes/216' && array_column(end(Panel::$ecrits)[2]['materials'], 'waste_amount_perc') === [3.0, 10.0, 5.0]
    && abs($coutPerte - (0.252 * 1.03 + 0.7 * 1.10 + 1.7 * 1.05)) < 1e-6, json_encode($r2['ecritures'] ?? $r2));
$BODY = ['id' => $r2['ecritures'][0]['id']];
verifie('pertes sur les lignes : « Annuler » les remet à zéro', wr_verification_annuler()['ok'] && array_sum(array_map(static fn ($l) => (float) ($l['waste_amount_perc'] ?? 0), Panel::$recettes[216]['materials'])) === 0.0);
$BODY = $lot;
verifie('références : un lot déjà au panel ne réécrit rien', !wr_verification_references()['ok']);
Panel::$perteMatiereLue = true;
$BODY = ['rid' => 216, 'lignes' => [['mid' => 756, 'perte' => 12]]];
$r3 = wr_verification_references();
verifie('références : si le panel lit la perte de la matière, rien à poser sur les lignes', $r3['ok'] && str_contains((string) ($r3['pertes']['message'] ?? ''), 'entre dans le coût') && $r3['pertes']['aPoser'] === [], json_encode($r3['pertes'] ?? $r3));
Panel::$perteMatiereLue = false;
$BODY = ['id' => $r['ecritures'][1]['id']];
$u = wr_verification_annuler();
verifie('références : « Annuler » remet la matière comme avant le lot (sans référence, perte 0)', $u['ok'] && Panel::$materiaux[756]['reference_unit_price_net'] === null && (int) Panel::$materiaux[756]['waste_amount_perc'] === 0, json_encode($u));
$_GET = ['q' => 'tagliata'];
verifie('références : une recette se cherche par son nom', (ep_verification_references()['rid'] ?? 0) === 216);
$_GET = [];
$T = ep_verification_references();
verifie('références : toute la carte, la Tagliata comprise', ($T['ok'] ?? false) && count($T['lignes']) > 10 && in_array(756, array_column($T['lignes'], 'mid'), true));

// Le même food cost dans tous les magasins (26/11/2026 au soir) : Gosselies paie ses tomates plus cher, il s'aligne.
verifie('aligner : seuls les prix propres qui s’écartent', vrfAAligner([4 => ['prix' => 0.0042, 'src' => 'LOCAL'], 3 => ['prix' => 0.005, 'src' => 'LOCAL'], 2 => ['prix' => 0.009, 'src' => 'REFERENCE']], 0.0042) === [3]);
Panel::$prix[3][282] = 0.005;
$_GET = ['rid' => '216'];
$R2 = ep_verification_references();
$l282 = array_values(array_filter($R2['lignes'], static fn ($l) => $l['mid'] === 282))[0] ?? [];
verifie('aligner : la moyenne réseau compte Gosselies (4,47 €/kg), et les trois magasins s’y alignent', abs(($l282['propose']['prix'] ?? 0) * 1000 - 4.4667) < 1e-3 && ($l282['aligner'] ?? null) === [4, 3, 2] && ($R2['compteurs']['aAligner'] ?? 0) === 1, json_encode($l282['aligner'] ?? null));
$BODY = ['rid' => 216, 'apercu' => true, 'lignes' => [['mid' => 282, 'prix' => 4.2, 'perte' => 3, 'aligner' => true]]];
$ap = wr_verification_references();
verifie('aligner : l’aperçu montre le prix du magasin (route du prix), rien sur la matière déjà à jour', ($ap['apercu'] ?? false) && count($ap['ecritures']) === 1 && $ap['ecritures'][0]['route'] === '/materials/282/manual-price'
    && str_contains($ap['resume'], '1 prix de magasin aligné'), json_encode($ap));
$BODY = ['rid' => 216, 'lignes' => [['mid' => 282, 'prix' => 4.2, 'perte' => 3, 'aligner' => true]], 'sansEffet' => true];
$r = wr_verification_references();
verifie('aligner : Gosselies reprend 4,20 €/kg, relu, au registre, et le food cost est le même partout', $r['ok'] && abs(Panel::$prix[3][282] - 0.0042) < 1e-12 && $r['ecritures'][0]['annulable'] && $r['effet'] === []
    && abs(Panel::get('/shops/3/recipes/216/cost')['cost_net'] - Panel::get('/shops/4/recipes/216/cost')['cost_net']) < 1e-9, json_encode($r));
$BODY = ['id' => $r['ecritures'][0]['id']];
verifie('aligner : « Annuler » rend son prix à Gosselies', wr_verification_annuler()['ok'] && abs(Panel::$prix[3][282] - 0.005) < 1e-12);
Panel::$prix[3][282] = 0.0042;

// 27/11/2026 : « refusée par le panel : SUPPLIER_MANAGED_PRICE ». Halle paie ses tomates au tarif de la centrale, que seul
// le fournisseur change : ce n'est pas une erreur ; la référence reprend ce tarif et les autres magasins s'y alignent.
Panel::$prix[4][282] = 0.006; Panel::$fournisseur[4][282] = true;
$BODY = ['rid' => 216, 'lignes' => [['mid' => 282, 'prix' => 4.2, 'aligner' => true]], 'sansEffet' => true];
$r = wr_verification_references();
$noms = array_map(static fn ($e) => $e['resultat'], $r['ecritures']);
verifie('tarif fournisseur : le refus est une information, pas une erreur', $r['ok'] && in_array('fournisseur', $noms, true) && !in_array('refuse', $noms, true), json_encode($noms));
verifie('tarif fournisseur : la référence reprend 6 €/kg et les autres magasins s’y alignent (même food cost)', abs((float) Panel::$materiaux[282]['reference_unit_price_net'] - 0.006) < 1e-12
    && abs(Panel::$prix[3][282] - 0.006) < 1e-12 && abs(Panel::$prix[2][282] - 0.006) < 1e-12 && Panel::$prix[4][282] === 0.006
    && abs(Panel::get('/shops/3/recipes/216/cost')['cost_net'] - Panel::get('/shops/4/recipes/216/cost')['cost_net']) < 1e-9, json_encode(Panel::$prix));
verifie('tarif fournisseur : appris, il n’est plus « à aligner » et la proposition le reprend', vrfEstVerrou(vrfVerrous(), 4, 282)
    && vrfAAligner([4 => ['prix' => 0.006, 'src' => 'SUPPLIER', 'verrou' => true], 3 => ['prix' => 0.005, 'src' => 'LOCAL']], 0.005) === []
    && vrfProposition(['mid' => 282, 'nom' => 'Tomates', 'facteur' => 1000, 'prix' => [4 => ['prix' => 0.006, 'src' => 'SUPPLIER', 'verrou' => true], 3 => ['prix' => 0.005, 'src' => 'LOCAL']]], ['prix' => [], 'pertes' => []])['origine'] === 'fournisseur');
$_GET = ['rid' => '216'];
$R3 = ep_verification_references();
$l282 = array_values(array_filter($R3['lignes'], static fn ($l) => $l['mid'] === 282))[0] ?? [];
verifie('tarif fournisseur : la page le montre, rien à aligner', ($l282['propose']['origine'] ?? '') === 'fournisseur' && ($l282['aligner'] ?? null) === [] && ($R3['compteurs']['fournisseur'] ?? 0) === 1, json_encode([$l282['propose'] ?? null, $l282['aligner'] ?? null]));
// On remet la Tagliata comme avant, pour la suite.
Panel::$fournisseur = []; Db::$reglages['vrfVerrous'] = '{}';
foreach ([2, 3, 4] as $s_) { if (isset(Panel::$prix[$s_][282])) { Panel::$prix[$s_][282] = 0.0042; } }
Panel::$materiaux[282]['reference_unit_price_net'] = '0.0042';

// Les pertes sur les lignes, toute la carte, par tranches.
verifie('pertes sur les lignes : la perte de la matière, seulement si elle en porte une', vrfChangementsPerte(['materials' => [['id' => 282, 'quantity' => 60, 'waste_amount_perc' => '0.0000'], ['id' => 9, 'quantity' => 1], ['id' => 761, 'quantity' => 100, 'waste_amount_perc' => '5.0000']]], [282 => 3.0, 9 => 0.0, 761 => 5.0])
    === [['type' => 'perte', 'cle' => 'm282', 'p' => 3.0]]);
$BODY = ['depuis' => 0, 'lot' => 4, 'apercu' => true];
$pl = wr_verification_pertes_lignes();
verifie('pertes sur les lignes : l’aperçu d’une tranche n’écrit rien et dit la suite', $pl['ok'] && $pl['apercu'] && $pl['total'] === count(Panel::$recettes) && $pl['suivant'] === 4, json_encode(array_diff_key($pl, ['ecritures' => 1])));
$n0 = count(Panel::$ecrits); $depuis = 0; $tours = 0; $nOk = 0;
do { $BODY = ['depuis' => $depuis, 'lot' => 4]; $pl = wr_verification_pertes_lignes(); $nOk += $pl['nOk']; $depuis = $pl['suivant']; $tours++; } while ($depuis !== null && $tours < 20);
$w = array_column(Panel::$recettes[216]['materials'], 'waste_amount_perc', 'id');
verifie('pertes sur les lignes : toute la carte en tranches, la Tagliata prend tomates 3 % et steak 5 %', $tours === (int) ceil(count(Panel::$recettes) / 4) && $nOk >= 1 && (float) $w[282] === 3.0 && (float) $w[761] === 5.0 && (float) ($w[756] ?? 0) === 0.0, json_encode($w));
$BODY = ['depuis' => 0, 'lot' => 40];
verifie('pertes sur les lignes : une seconde passe n’écrit plus rien', wr_verification_pertes_lignes()['ecritures'] === []);

// Les réponses pour la capture de l'écran (la liste après corrections, la fiche du Wrapps).
fixture('recettes-apres', vfAnalyse(0) + ['cache' => null]);
$_GET = ['pid' => '104'];
fixture('fiche-104', ep_verification_fiche());

echo "\n$ok réussi(s), $ko en échec\n";
ob_end_flush();
exit($ko > 0 ? 1 : 0);
