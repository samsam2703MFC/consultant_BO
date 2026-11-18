<?php
declare(strict_types=1);

/*
 * SONDE — la composition du cougnou (18/11/2026, Sam : « est-ce qu'on a accès à la recette du produit ? cougnou :
 * farine, œuf, beurre… »). Lecture seule, rien n'est écrit.
 * La recette magasin 246 « Cougnou - Sucre » n'a qu'un ingrédient : la matière 430 « Cougnou Sucre 200 g. - Cru »,
 * livrée par le fournisseur central SLFood. La composition (farine, œufs, beurre…) se trouverait donc dans le carnet
 * de recettes du fournisseur : /material-suppliers/{id}/recipes, ses ingrédients et ses étapes.
 * Lancée par .github/workflows/sonde-recette-cougnou.yml : php -- /var/www/consulant_bo < tools/sonde_recette_cougnou.php
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php', 'erp_api.php'] as $f) { require_once "$dir/src/$f"; }

$get = static fn (string $p): array => PanelApi::sondeGet($p, 40);
$liste = static function ($c): array {
    if (!is_array($c)) { return []; }
    foreach (['items', 'data', 'recipes', 'results'] as $k) { if (isset($c[$k]) && is_array($c[$k])) { return $c[$k]; } }
    return array_is_list($c) ? $c : [];
};
$court = static fn ($v, int $n = 700): string => mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE), 0, $n);

echo "=== 1. Les fournisseurs (/material-suppliers)\n";
$r = $get('/material-suppliers');
echo '  HTTP ', $r['code'], "\n";
$fournisseurs = [];
foreach ($liste($r['corps']) as $f) {
    if (!is_array($f)) { continue; }
    $nom = (string) ($f['name'] ?? $f['company_name'] ?? $f['representative_name'] ?? '');
    $fournisseurs[(int) ($f['id'] ?? 0)] = $nom;
    echo '  fournisseur ', $f['id'] ?? '?', ' « ', $nom, " »\n";
}
// Deuxième passage : SLFood n'a que deux recettes, aucune « cougnou » — on liste les recettes de TOUS les fournisseurs.
$cibles = $fournisseurs;

foreach ($cibles as $sid => $nom) {
    if ($sid <= 0) { continue; }
    echo "\n=== 2. Recettes du fournisseur $sid « $nom » (/material-suppliers/$sid/recipes)\n";
    $r = $get("/material-suppliers/$sid/recipes");
    echo '  HTTP ', $r['code'], ($r['code'] !== 200 ? ' ' . $court($r['corps'], 300) : ''), "\n";
    $recettes = $liste($r['corps']);
    echo '  ', count($recettes), " recettes\n";
    foreach ($recettes as $rc) {
        if (!is_array($rc)) { continue; }
        $rnom = (string) ($rc['name'] ?? $rc['title'] ?? '');
        echo '    recette ', $rc['id'] ?? '?', " « $rnom »\n";
        if (stripos($rnom, 'cougnou') === false && stripos($rnom, 'couque') === false) { continue; }
        $rid = (int) ($rc['id'] ?? 0);
        echo "\n  --- recette $rid « $rnom » : ", $court(array_diff_key($rc, ['ingredients' => 1, 'steps' => 1]), 500), "\n";
        $ing = $get("/material-suppliers/$sid/recipes/$rid/ingredients");
        echo '    ingrédients (HTTP ', $ing['code'], ") :\n";
        foreach ($liste($ing['corps']) as $i) {
            if (!is_array($i)) { continue; }
            printf("      - %s : %s %s%s\n", $i['name'] ?? $i['material_name'] ?? $i['ingredient_name'] ?? '?',
                $i['quantity'] ?? $i['qty'] ?? '?', $i['unit_name'] ?? $i['unit'] ?? '', isset($i['waste_amount_perc']) ? ' (perte ' . $i['waste_amount_perc'] . ' %)' : '');
        }
        if ($liste($ing['corps']) === []) { echo '      ', $court($ing['corps']), "\n"; }
        $et = $get("/material-suppliers/$sid/recipes/$rid/steps");
        echo '    étapes (HTTP ', $et['code'], ") :\n";
        foreach ($liste($et['corps']) as $e) {
            if (!is_array($e)) { continue; }
            echo '      ', $e['step_number'] ?? $e['position'] ?? '·', '. ', $e['step_description'] ?? $e['description'] ?? $e['text'] ?? $court($e, 200), "\n";
        }
        if ($liste($et['corps']) === []) { echo '      ', $court($et['corps']), "\n"; }
        $so = $get("/material-suppliers/$sid/recipes/$rid/summary");
        echo '    résumé (HTTP ', $so['code'], ') : ', $court($so['corps'], 900), "\n";
    }
}
