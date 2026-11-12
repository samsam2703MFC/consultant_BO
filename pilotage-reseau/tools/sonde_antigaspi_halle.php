<?php
declare(strict_types=1);

/*
 * SONDE — l'anti-gaspi des profils clients de Halle (12/11/2026, Sam : « anti-gaspi n'est pas un produit que Sonia H.
 * a pris »). Lecture seule, rien de nominatif : ni client, ni nom.
 *   1. les produits du catalogue que la règle actuelle (fpFamilleDe) range en anti-gaspi, avec leur catégorie ;
 *   2. les tickets gardés dont les familles contiennent de l'anti-gaspi : leurs lignes d'articles telles que l'ERP les
 *      rend (produit, catégorie, quantité, prix, remise) et les noms des champs d'une ligne.
 * Lancée par .github/workflows/sonde-antigaspi-halle.yml : php -- /var/www/consulant_bo < tools/sonde_antigaspi_halle.php
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php', 'fidelite.php', 'fidelite_profils.php'] as $f) { require_once "$dir/src/$f"; }
const SA_SHOP = 4;

echo "=== 1. Le catalogue : ce que la règle range en anti-gaspi\n";
$nomCat = [];
$rc = PanelApi::sondeGet('/product-categories', 30);
foreach (PanelApi::liste(is_array($rc['corps']) ? $rc['corps'] : []) as $c) { $nomCat[(int) ($c['id'] ?? 0)] = (string) ($c['name'] ?? ''); }
$rp = PanelApi::sondeGet('/products', 60);
$prod = []; $parFam = [];
foreach (PanelApi::liste(is_array($rp['corps']) ? $rp['corps'] : []) as $p) {
    $id = (int) ($p['id'] ?? 0);
    if ($id <= 0) { continue; }
    $cat = (string) ($p['category_name'] ?? ($nomCat[(int) ($p['id_category'] ?? 0)] ?? ''));
    $fam = fpFamilleDe($cat . ' ' . (string) ($p['name'] ?? ''));
    $prod[$id] = ['cat' => $cat, 'nom' => (string) ($p['name'] ?? ''), 'fam' => $fam];
    $parFam[$fam] = ($parFam[$fam] ?? 0) + 1;
    if ($fam === 'antigaspi') { printf("  produit %-6d catégorie « %s » · « %s »\n", $id, $cat, $p['name'] ?? ''); }
}
echo '  produits par famille : ', json_encode($parFam, JSON_UNESCAPED_UNICODE), "\n";
echo '  catégories : ', json_encode(array_values(array_unique(array_filter($nomCat))), JSON_UNESCAPED_UNICODE), "\n";

echo "\n=== 2. Les tickets gardés avec de l'anti-gaspi (12 au plus, les plus récents)\n";
$T = Db::rows("SELECT id, jour, familles FROM ceo_fid_ticket WHERE shop_id = ? AND familles LIKE '%antigaspi%' ORDER BY jour DESC LIMIT 12", [SA_SHOP]);
echo '  tickets concernés en tout : ', (int) (Db::row("SELECT COUNT(*) n FROM ceo_fid_ticket WHERE shop_id = ? AND familles LIKE '%antigaspi%'", [SA_SHOP])['n'] ?? 0),
    ' sur ', (int) (Db::row('SELECT COUNT(*) n FROM ceo_fid_ticket WHERE shop_id = ? AND familles IS NOT NULL', [SA_SHOP])['n'] ?? 0), " tickets aux familles lues\n";
$cles = false;
foreach ($T as $t) {
    $r = PanelApi::sondeGet('/transactions/' . (int) $t['id'] . '?include=products', 30);
    $c = is_array($r['corps']) ? $r['corps'] : [];
    $d = isset($c['data']) && is_array($c['data']) && !array_is_list($c['data']) ? $c['data'] : $c;
    echo "  ticket du {$t['jour']} · familles gardées ", $t['familles'], "\n";
    foreach ((array) ($d['products'] ?? []) as $l) {
        if (!is_array($l)) { continue; }
        if (!$cles) { echo '    (champs d\'une ligne : ', implode(', ', array_keys($l)), ")\n"; $cles = true; }
        $idp = (int) ($l['id_product'] ?? 0);
        $prix = [];
        foreach ($l as $k => $v) { if (is_scalar($v) && preg_match('/price|amount|value|discount|reduc|promo|type|is_/i', (string) $k)) { $prix[] = $k . '=' . $v; } }
        printf("    produit %-6d « %s » · catalogue : %s · q=%s · %s\n", $idp, $l['product_display_name'] ?? $l['product_name'] ?? '',
            isset($prod[$idp]) ? '« ' . $prod[$idp]['cat'] . ' » → ' . $prod[$idp]['fam'] : 'absent du catalogue → ' . fpFamilleDe((string) ($l['product_display_name'] ?? $l['product_name'] ?? '')),
            $l['quantity'] ?? '?', implode(' ', $prix));
    }
}
echo "\nfin de la sonde\n";
