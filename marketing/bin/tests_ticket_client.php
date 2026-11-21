#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la fiche produits du ticket de caisse (marketing/src/ticket_client.php) : les fonctions pures, sans base ni
 * réseau — la clé du QR, la langue d'une boutique, la composition, la lecture des lignes d'une transaction, le résumé
 * d'une fiche technique (qui ne garde ni coût ni marge).
 *
 *   php marketing/bin/tests_ticket_client.php     (0 : tout passe ; 1 : la liste des échecs)
 */

require __DIR__ . '/../src/ticket_client.php';

$ko = 0; $n = 0;
function tcl_ok(bool $c, string $quoi): void
{
    global $ko, $n;
    $n++;
    if (!$c) { $ko++; echo "ÉCHEC : $quoi\n"; }
}

// La clé du QR : six caractères, stable, propre à la boutique et à la transaction, refusée si l'un change.
$s = 'secret-de-test';
$code = tclCode(2, 104583, $s);
tcl_ok(preg_match('/^2-104583-[A-F0-9]{6}$/', $code) === 1, 'format du code : ' . $code);
tcl_ok(tclLireCode($code, $s) === [2, 104583], 'le code se relit');
tcl_ok(tclLireCode(strtolower($code), $s) === [2, 104583], 'la clé se lit sans casse');
tcl_ok(tclLireCode('2-104584-' . substr($code, -6), $s) === null, 'un autre numéro avec la même clé est refusé');
tcl_ok(tclLireCode('4-104583-' . substr($code, -6), $s) === null, 'une autre boutique avec la même clé est refusée');
tcl_ok(tclLireCode($code, 'autre') === null, 'un autre secret refuse le code');
tcl_ok(tclLireCode($code, '') === null, 'sans secret, rien ne s’ouvre');
tcl_ok(tclLireCode('2-104583', $s) === null && tclLireCode('x-1-ABCDEF', $s) === null, 'format invalide refusé');

// La langue par le code postal.
tcl_ok(tclLangueCp('1500') === 'nl', 'Halle (1500) en néerlandais');
tcl_ok(tclLangueCp('1435') === 'fr', 'Corbais (1435) en français');
tcl_ok(tclLangueCp('6041') === 'fr' && tclLangueCp('5140') === 'fr', 'Gosselies, Sombreffe en français');
tcl_ok(tclLangueCp('9000') === 'nl' && tclLangueCp('2000') === 'nl', 'Gand, Anvers en néerlandais');
tcl_ok(tclLangueCp('1000') === 'fr', 'Bruxelles : français par défaut');

// L'étiquette et ses morceaux.
tcl_ok(tclSansPrefixe('Ingrédients: Croissant.') === 'Croissant', 'préfixe français retiré');
tcl_ok(tclSansPrefixe('Ingrediënten: Brie de Meaux, Rucola.') === 'Brie de Meaux, Rucola', 'préfixe néerlandais retiré');
tcl_ok(tclMorceaux('Fondant chocolat, Eclair, Crème pâtissière (Crème pâtissière (Poche))') === ['Fondant chocolat', 'Eclair', 'Crème pâtissière (Crème pâtissière (Poche))'], 'virgules hors parenthèses');

// La composition : allergènes de la recette reportés par position sur l'étiquette traduite.
$recette = [['nom' => 'Baguette Tradition', 'allergenes' => ['cereals_gluten']], ['nom' => 'Brie de Meaux', 'allergenes' => ['milk']], ['nom' => 'Roquette', 'allergenes' => []]];
$c = tclComposition($recette, ['fr' => 'Ingrédients: Brie de Meaux, Roquette, Baguette Tradition.', 'nl' => 'Ingrediënten: Brie de Meaux, Rucola, Stokbrood Traditie.']);
tcl_ok($c['fr']['items'] === [['Brie de Meaux', ['milk']], ['Roquette', []], ['Baguette Tradition', ['cereals_gluten']]], 'composition française et ses allergènes');
tcl_ok($c['nl']['items'][1] === ['Rucola', []] && $c['nl']['items'][2] === ['Stokbrood Traditie', ['cereals_gluten']] && $c['nl']['traduit'], 'composition néerlandaise par position');
$c = tclComposition($recette, ['fr' => 'Ingrédients: Brie de Meaux, Roquette, Baguette Tradition.', 'nl' => 'Ingrediënten: Brie.']);
tcl_ok(!$c['nl']['traduit'] && $c['nl']['items'][0][0] === 'Brie de Meaux', 'traduction incomplète : le français, marqué non traduit');
$c = tclComposition($recette, []);
tcl_ok($c['fr']['items'][0] === ['Baguette Tradition', ['cereals_gluten']] && !$c['nl']['traduit'], 'sans étiquette : la recette');
tcl_ok(tclCodesAllergenes(['Œufs', 'Lait', 'Céréales contenant du gluten', 'inconnu']) === ['cereals_gluten', 'eggs', 'milk'], 'noms d’allergènes → codes, dans l’ordre réglementaire');

// Les lignes d'une transaction, quelle que soit la clé du prix ; deux lignes du même produit n'en font qu'une.
$l = tclLignesTransaction(['products' => [
    ['id_product' => 1610006, 'quantity' => 2, 'price_gross' => '1.70', 'name' => 'Croissant'],
    ['id_product' => 2300013, 'quantity' => 1, 'total' => 6.1],
    ['id_product' => 1610006, 'quantity' => 2, 'price_gross' => '1.70'],
    ['quantity' => 1],
]]);
tcl_ok(count($l) === 2 && $l[0]['qte'] === 4.0 && $l[0]['prix'] === 1.7 && $l[0]['nom'] === 'Croissant', 'lignes regroupées, prix unitaire');
tcl_ok($l[1]['prix'] === 6.1, 'prix déduit du total de la ligne');
tcl_ok(tclDateTransaction(['created_at' => '2026-11-21 11:52:07']) === '2026-11-21T11:52:07', 'date de la transaction');

// Le résumé d'une fiche technique : les champs affichés, pas les coûts.
$f = tclResumeFiche(['object' => ['name' => 'Cannelloni - poulet & estragon ', 'shelf_life_minutes' => 4320, 'storage_description' => "2°C – 4°C\t",
    'reheating_time_minutes' => 10, 'reheating_temperature_celsius' => 180, 'expected_margin' => '70.00', 'suggested_sale_price' => '12.90'],
    'recipe' => ['elements' => [['name' => 'Parmesan', 'allergens' => ['Lait'], 'local_unit_price_net' => 0.02], ['name' => 'Ravier', 'is_part_of_package' => 1]]],
    'preparation' => ['steps' => [['step_number' => 1, 'step_description' => 'Finition : Saupoudrer de parmesan.']]],
    'competences' => [['name' => 'Cuisson des Quiches', 'description' => 'Préchauffez le four à 180 °C.'], ['name' => 'Composition Traiteur', 'description' => '']]]);
tcl_ok($f['nom'] === 'Cannelloni - poulet & estragon' && $f['stockage'] === '2°C – 4°C', 'nom et stockage nettoyés');
tcl_ok($f['rechauffe'] === ['minutes' => 10.0, 'degres' => 180.0], 'réchauffage');
tcl_ok($f['recette'] === [['nom' => 'Parmesan', 'allergenes' => ['milk']]], 'recette sans emballage, allergènes en codes');
tcl_ok($f['etapes'] === ['Finition : Saupoudrer de parmesan.'] && $f['cuisson'] === ['Préchauffez le four à 180 °C.'], 'étapes et cuisson');
$json = json_encode($f);
tcl_ok(!str_contains($json, 'margin') && !str_contains($json, 'price_net') && !str_contains($json, '70.00'), 'ni marge ni coût dans le résumé');

echo $ko === 0 ? "ok : $n vérifications\n" : "$ko échec(s) sur $n\n";
exit($ko === 0 ? 0 : 1);
