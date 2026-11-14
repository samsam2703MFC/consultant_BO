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
        if (str_contains($sql, 'FROM ceo_verif_recette')) { return array_values(self::$registre); }
        if (str_contains($sql, 'FROM ceo_verif_correction')) {
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
        if (str_contains($sql, 'MAX(id) id FROM ceo_verif_correction')) { return ['id' => self::$corr === [] ? 0 : max(array_keys(self::$corr))]; }
        if (str_contains($sql, 'FROM ceo_verif_correction WHERE id = ?')) { return self::$corr[(int) $p[0]] ?? null; }
        if (str_contains($sql, 'FROM ceo_verif_recette') && str_contains($sql, 'COUNT(*)')) { return ['n' => 0, 'd' => null]; }
        return null;
    }
    public static function exec(string $sql, array $p = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { self::$reglages[$p[0]] = $p[1]; return 1; }
        if (str_starts_with($sql, 'DELETE FROM ceo_app_setting')) { unset(self::$reglages[$p[0]]); return 1; }
        if (str_starts_with($sql, 'INSERT INTO ceo_verif_recette')) { self::$registre[$p[0]] = ['cle' => $p[0], 'premier_vu' => $p[8], 'resolu_le' => null]; return 1; }
        if (str_starts_with($sql, 'INSERT INTO ceo_verif_correction')) {
            preg_match('/\(([^)]*)\)\s*VALUES/', $sql, $m);
            $row = array_combine(array_map('trim', explode(',', $m[1])), $p);
            $id = (self::$corr === [] ? 0 : max(array_keys(self::$corr))) + 1;
            self::$corr[$id] = $row + ['id' => $id, 'annule_par' => null];
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE ceo_verif_correction SET annule_par')) { self::$corr[(int) $p[1]]['annule_par'] = $p[0]; return 1; }
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
    public static array $portions = [];   // id => [id, product_id, portion_size]
    public static array $prix = [];       // sid => [mid => prix]
    public static array $noms = [];       // mid => [nom, unité]
    public static array $stock = [];      // sid => [[mid, nom, q, u]]
    public static array $refus = [];      // chemin => message (422)
    public static array $ignore = [];     // chemin => true : 200 sans rien changer
    public static array $ecrits = [];     // [méthode, chemin, corps]
    public static array $materiaux = [];  // mid => objet (comme GET /materials/{id})
    public static array $tva = [];        // mid => lignes (comme GET /materials/{id}/vat-rates)

    /** Le coût d'une recette entière dans un magasin, et ses éléments comme /cost les rend. */
    public static function cout(int $sid, array $o, float $fac = 1.0, int $prof = 0): array
    {
        $els = []; $t = 0.0; $complet = true;
        foreach ((array) ($o['materials'] ?? []) as $m) {
            $mid = (int) $m['id_material']; $q = (float) $m['quantity'] * $fac;
            $px = self::$prix[$sid][$mid] ?? null;
            if ($px === null) { $complet = false; }
            $c = $px === null ? 0.0 : $q * $px; $t += $c;
            $els[] = ['type' => 'ingredient', 'material_id' => $mid, 'name' => self::$noms[$mid][0], 'required_quantity' => $q, 'unit_name' => self::$noms[$mid][1],
                'price_net' => $px, 'price_source' => $px === null ? 'MISSING' : 'SUPPLIER', 'calculated_req_price_net' => $c];
        }
        foreach ((array) ($o['subrecipes'] ?? []) as $s) {
            $so = self::$sous[(int) $s['id_subrecipe']];
            $y = (float) ($so['yield_quantity'] ?: 1);
            [$st, $sel, $sc] = self::cout($sid, $so, $fac * (float) $s['quantity'] / $y, $prof + 1);
            $t += $st; $complet = $complet && $sc;
            $els[] = ['type' => 'sub-recipe', 'name' => $so['name'], 'elements' => $sel];
        }
        return [$t, $els, $complet];
    }

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
        if (preg_match('#^/recipes/(\d+)$#', $c, $m)) { return self::$recettes[(int) $m[1]] ?? null; }
        if (preg_match('#^/subrecipes/(\d+)$#', $c, $m)) { return isset(self::$sous[(int) $m[1]]) ? ['data' => self::$sous[(int) $m[1]]] : null; }
        if (preg_match('#^/materials/(\d+)/vat-rates$#', $c, $m)) { return isset(self::$tva[(int) $m[1]]) ? ['data' => self::$tva[(int) $m[1]]] : null; }
        if (preg_match('#^/materials/(\d+)$#', $c, $m)) { return isset(self::$materiaux[(int) $m[1]]) ? ['data' => self::$materiaux[(int) $m[1]]] : null; }
        if ($c === '/allergens') { return [['id' => 1, 'code' => 'cereals_gluten'], ['id' => 3, 'code' => 'eggs'], ['id' => 7, 'code' => 'milk']]; }
        if ($c === '/units') { return [['id' => 1, 'name' => 'g'], ['id' => 5, 'name' => 'pcs'], ['id' => 2, 'name' => 'kg']]; }
        if ($c === '/material-categories') { return [['id' => 30, 'name' => 'Matières premières (boulangerie)'], ['id' => 31, 'name' => 'Matières premières (traiteur)']]; }
        if ($c === '/countries/BE/materials/vat-rates') { return [['rate' => 6], ['rate' => 21]]; }
        if ($c === '/recipes') { return array_map(static fn ($o) => ['id' => $o['id'], 'name' => $o['name']], array_values(self::$recettes)); }
        if (preg_match('#^/products/(\d+)/portions$#', $c, $m)) { return array_values(array_filter(self::$portions, static fn ($x) => $x['product_id'] === (int) $m[1])); }
        if (preg_match('#^/products/(\d+)$#', $c, $m)) { $p = self::$produits[(int) $m[1]] ?? null; return $p === null ? null : ['product' => ['id' => (int) $m[1], 'name' => $p['nom'], 'id_recipe' => $p['id_recipe']]]; }
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
        if (preg_match('#^/materials/(\d+)/vat-rates$#', $c, $m) && $meth === 'POST') {
            $mid = (int) $m[1]; $fait = false;
            foreach (self::$tva[$mid] as &$l) { if ($l['country_code'] === ($b['country_code'] ?? '')) { $l['vat_rate'] = number_format((float) $b['vat_rate'], 2, '.', ''); $fait = true; } }
            unset($l);
            if (!$fait) { self::$tva[$mid][] = ['country_code' => $b['country_code'], 'vat_rate' => number_format((float) $b['vat_rate'], 2, '.', '')]; }
            return [true, []];
        }
        if (preg_match('#^/materials/(\d+)$#', $c, $m) && $meth === 'PATCH') { self::$materiaux[(int) $m[1]] = array_merge(self::$materiaux[(int) $m[1]], $b); return [true, []]; }
        if (preg_match('#^/recipes/(\d+)$#', $c, $m) && $meth === 'PATCH') { self::$recettes[(int) $m[1]] = array_merge(self::$recettes[(int) $m[1]], $b); return [true, []]; }
        if (preg_match('#^/subrecipes/(\d+)$#', $c, $m) && $meth === 'PATCH') { self::$sous[(int) $m[1]] = array_merge(self::$sous[(int) $m[1]], $b); return [true, []]; }
        if (preg_match('#^/products/(\d+)$#', $c, $m) && $meth === 'PATCH') { if (array_key_exists('id_recipe', $b)) { self::$produits[(int) $m[1]]['id_recipe'] = $b['id_recipe']; } return [true, []]; }
        if (preg_match('#^/product-portions/(\d+)$#', $c, $m) && $meth === 'PATCH') {
            $id = (int) $m[1]; self::$portions[$id] = array_merge(self::$portions[$id], $b);
            self::$produits[self::$portions[$id]['product_id']]['part'] = self::$portions[$id]['portion_size'];
            return [true, []];
        }
        if (preg_match('#^/materials/(\d+)/manual-price$#', $c, $m) && $meth === 'POST') { self::$prix[(int) $b['id_shop']][(int) $m[1]] = (float) $b['price']; return [true, []]; }
        if (preg_match('#^/shops/(\d+)/recipes/recalculate$#', $c) && $meth === 'POST') { return [true, []]; }
        PanelApi::$lastError = $meth . ' ' . $c . ' → HTTP 404'; return [false, ['description' => 'route inconnue']];
    }
}
final class PanelApi
{
    public static ?string $lastError = null;
    public static ?array $dispo = null;
    public static function configured(): bool { return true; }
    public static function config(): array { return ['base' => 'https://atelierby.tfbuddy.com/api/v1']; }
    public static function coutsMatiere(): array { return []; }
    public static function produitsDisponibles(int $sid): array { return Panel::get('/shops/' . $sid . '/products/available'); }
    public static function get(string $c): mixed { $r = Panel::get($c); return is_array($r) ? ($r['data'] ?? $r) : $r; }
    public static function getParallele(array $chemins, int $front = 4, int $timeout = 25): array { $o = []; foreach ($chemins as $k => $c) { $o[$k] = Panel::get($c); } return $o; }
    public static function envoi(string $m, string $c, array $b): array { return Panel::envoi($m, $c, $b); }
}
function mfMatieresApi(array $sids, bool $frais): array
{
    $out = [];
    foreach ($sids as $sid) { $out[$sid] = []; foreach (Panel::$noms as $mid => $n) { $out[$sid][(string) $mid] = ['prix' => Panel::$prix[$sid][$mid] ?? null, 'nom' => $n[0]]; } }
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
                '570' => [['fid' => '7', 'via' => 'catalogue', 'sku' => 'PT8', 'taille' => 8.0, 'tailleUnite' => 'pcs', 'parColis' => 1.0]]]];
}
function mfListePrix(string $fid, int $sid, bool $frais): array
{
    // SLFood : les blancs d'œufs (pas à Corbais) et la tarte aux pommes, 61,70 € les 8 depuis le 09/10 (l'audit du 14/11).
    $c = $fid === '7' ? ($sid === 2 ? [] : ['BL5' => ['prix' => 32.5, 'taille' => 5.0, 'tailleUnite' => 'kg', 'depuis' => '2026-10-09', 'nomMatiere' => '']])
        + ['PT8' => ['prix' => 61.7, 'taille' => 8.0, 'tailleUnite' => 'pcs', 'depuis' => '2026-10-09', 'nomMatiere' => '']]
        : ['MAYO4' => ['prix' => 10.0, 'taille' => 4.0, 'tailleUnite' => 'pcs', 'depuis' => '2026-08-21', 'nomMatiere' => ''],
           // Un colis mal décrit : 2 700 € « le kg » de parmesan (le magasin paie 27 €) — un écart ×100, pas un retard.
           'PARM1' => ['prix' => 2700.0, 'taille' => 1.0, 'tailleUnite' => 'kg', 'depuis' => '2026-08-21', 'nomMatiere' => '']];
    return ['c' => $c, 'l' => [], 'lu' => true];
}

require __DIR__ . '/../src/verification.php';
require __DIR__ . '/../src/verification_correction.php';
require __DIR__ . '/../src/verification_matieres_prix.php';
require __DIR__ . '/../src/verification_matiere_edition.php';

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
Panel::$sous[77] = ['id' => 77, 'name' => 'Sauce César', 'yield_quantity' => 1.0, 'materials' => [$mat(503, 0.922)], 'subrecipes' => []];
Panel::$recettes = [
    13 => $rec(13, 'Wrapps Poulet Parmesan', 1, [$mat(501, 1), $mat(502, 0.08), $mat(504, 0.015)], [['id_subrecipe' => 77, 'name' => 'Sauce César', 'quantity' => 1.0, 'unit_name' => 'l']]),
    14 => $rec(14, 'Pain au Chocolat Nappé', 1, [$mat(510, 2), $mat(511, 0.06), $mat(512, 0.012)]),
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
Panel::$portions = [61 => ['id' => 61, 'product_id' => 106, 'name' => '1/4', 'portion_size' => null]];
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
verifie('vrcModifier : quantité posée dans le pivot', $m['corps']['materials'][1]['pivot']['required_quantity'] === 0.25 && !isset($m['corps']['materials'][1]['quantity']));
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
verifie('tarif : les blancs d’œufs (6,20 face à 6,50 chez SLFood) à Halle et Gosselies', isset($par['tarif:102']) && $par['tarif:102']['corr']['matieres'][0]['shops'] === [4, 3]);
verifie('tarif : un écart ×100 est un colis à vérifier, il ne corrige pas le coût', ($par['tarif:103']['corr']['matieres'][0]['suspect'] ?? false) === true
    && str_contains($par['tarif:103']['detail'], 'colis') && abs(($par['tarif:103']['corr']['coutApres'] ?? 0) - $par['tarif:103']['corr']['coutAvant']) < 1e-9, json_encode($par['tarif:103'] ?? null));
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
verifie('unité : seule la liste materials est partie', array_keys(end(Panel::$ecrits)[2]) === ['materials']);
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
$mids = array_column(Panel::$recettes[14]['materials'], 'id_material');
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
verifie('portion : PATCH /product-portions/61, relue', $r['ok'] && end(Panel::$ecrits)[1] === '/product-portions/61' && Panel::$portions[61]['portion_size'] === 0.25);
verifie('portion : la ligne part', !in_array('port:106', $cles($r['liste']), true));

// Le prix d'une matière.
$r = $geste(['geste' => 'prix', 'shop' => 4, 'mid' => 520, 'prix' => 1.85, 'matiere' => 'Sucre glace', 'rid' => 12, 'pid' => 102, 'cle' => 'prix:102', 'nom' => 'Merveilleux']);
fixture('corriger-prix', $r);
verifie('prix : POST /materials/520/manual-price {id_shop, price}, relu', $r['ok'] && end(Panel::$ecrits)[2] === ['id_shop' => 4, 'price' => 1.85], json_encode(end(Panel::$ecrits)));
verifie('prix : la ligne part', !in_array('prix:102', $cles($r['liste']), true));
verifie('prix : sans retour possible (l’avant était « aucun prix »)', !$r['ecritures'][0]['annulable']);

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
verifie('options : colis en kg pour une matière en g → prix par g (÷1000), puis la médiane', abs($o[0]['prix'] - 0.0065) < 1e-12 && $o[1]['type'] === 'mediane' && vmpReference($o)['id'] === 'f7');
unset(Panel::$prix[3][521]);
vrcOublier('matieresApi:3');
$_GET = [];
$t = ep_verification_matieres_prix();
fixture('matieres', $t);
$bl = null; foreach ($t['lignes'] as $l) { if ($l['mid'] === 521) { $bl = $l; } }
verifie('tableau : les blancs d’œufs, employés par le Merveilleux', $bl !== null && $bl['recettes'][0]['nom'] === 'Merveilleux' && $bl['nRecettes'] === 1, json_encode(array_column($t['lignes'], 'nom')));
verifie('tableau : sans prix à Gosselies seulement', $bl['drapeaux']['sansPrix'] === 1 && $bl['magasins'][1]['prix'] === null && $bl['magasins'][0]['prix'] === 6.2);
$f7 = null; foreach ($bl['options'] as $op) { if ($op['id'] === 'f7') { $f7 = $op; } }
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
verifie('matière : seules les clés changées partent, dans la forme lue', $cc === ['allergens', 'is_part_of_package', 'waste_amount_perc'] && $ch['corps']['waste_amount_perc'] === 5.0 && $ch['corps']['is_part_of_package'] === 1
    && $ch['corps']['allergens'] === [['id' => 3, 'code' => 'eggs'], ['id' => 7, 'code' => 'milk']], json_encode($ch));
verifie('matière : les allergènes en texte gardent leur forme', vmxAllergenesPour('eggs', ['eggs', 'milk'], vmxAllergenesRef()) === 'eggs, milk');
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
verifie('fiche : paramètres écrits et relus, puis le prix de Corbais', $r['ok'] && Panel::$materiaux[521]['name'] === 'Blancs d’œufs frais' && Panel::$materiaux[521]['reference_unit_price_net'] === 6.5 && Panel::$prix[2][521] === 6.5, json_encode($r));
$pm = array_values(array_filter(Panel::$ecrits, static fn ($e) => $e[1] === '/materials/521'));
verifie('fiche : le PATCH ne porte que les deux clés changées', end($pm)[2] === ['name' => 'Blancs d’œufs frais', 'reference_unit_price_net' => 6.5]);
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

// Les réponses pour la capture de l'écran (la liste après corrections, la fiche du Wrapps).
fixture('recettes-apres', vfAnalyse(0) + ['cache' => null]);
$_GET = ['pid' => '104'];
fixture('fiche-104', ep_verification_fiche());

echo "\n$ok réussi(s), $ko en échec\n";
ob_end_flush();
exit($ko > 0 ? 1 : 0);
