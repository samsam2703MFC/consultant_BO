<?php
declare(strict_types=1);
/*
 * Kalkulacja receptury (strona /recipes|semi-products|compositions/{id}/calculation) : suma = pozycje pierwszego
 * poziomu, drzewo od najdroższej pozycji z udziałem w koszcie, surowce zsumowane po całym drzewie, brak ceny,
 * opakowania, ceny na kg / l. Bez bazy danych.
 *   php tests/recipe_calculation_view_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Recipe\RecipeCalculationViewService as V;

function expectCalc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// Karton : 1 karton (opakowanie), 8 szt. półproduktu (ciasto + krem z cukrem), etykieta bez ceny
$elements = [
    ['type' => 'ingredient', 'id' => 1, 'name' => 'Karton', 'unit_name' => 'szt', 'required_quantity' => 1, 'suggested_base_unit_price' => 3.71, 'price_for_required_quantity' => 3.71, 'is_packaging' => 1],
    ['type' => 'ingredient', 'id' => 2, 'name' => 'Etykieta', 'unit_name' => 'szt', 'required_quantity' => 8, 'suggested_base_unit_price' => 0, 'price_for_required_quantity' => 0, 'is_packaging' => 1],
    ['type' => 'sub-recipe', 'id' => 50, 'id_type' => 2, 'name' => 'Tarte', 'unit_name' => 'szt', 'required_quantity' => 8, 'price_for_required_quantity' => 20.0, 'elements' => [
        ['type' => 'ingredient', 'id' => 3, 'name' => 'Cukier', 'unit_name' => 'g', 'required_quantity' => 500, 'suggested_base_unit_price' => 0.004, 'price_for_required_quantity' => 2.0, 'is_packaging' => 0],
        ['type' => 'sub-recipe', 'id' => 60, 'id_type' => 1, 'name' => 'Krem', 'unit_name' => 'g', 'required_quantity' => 2000, 'price_for_required_quantity' => 18.0, 'elements' => [
            ['type' => 'ingredient', 'id' => 3, 'name' => 'Cukier', 'unit_name' => 'g', 'required_quantity' => 250, 'suggested_base_unit_price' => 0.004, 'price_for_required_quantity' => 1.0, 'is_packaging' => 0],
            ['type' => 'ingredient', 'id' => 4, 'name' => 'Śmietana', 'unit_name' => 'ml', 'required_quantity' => 1000, 'suggested_base_unit_price' => 0.017, 'price_for_required_quantity' => 17.0, 'is_packaging' => 0],
        ]],
    ]],
];

$total = V::total($elements);
expectCalc(abs($total - 23.71) < 1e-9, 'suma = pozycje pierwszego poziomu (23,71)');

$rows = V::rows($elements, $total);
expectCalc(array_column($rows, 'name') === ['Tarte', 'Krem', 'Śmietana', 'Cukier', 'Cukier', 'Karton', 'Etykieta'], 'drzewo : od najdroższej pozycji, dzieci pod rodzicem : ' . implode(',', array_column($rows, 'name')));
expectCalc($rows[0]['sub'] && $rows[0]['kind'] === 'semi' && $rows[0]['children'] === 2 && $rows[1]['kind'] === 'composition', 'półprodukt / kompozycja rozpoznane');
expectCalc($rows[1]['parent'] === $rows[0]['key'] && $rows[2]['parent'] === $rows[1]['key'] && $rows[2]['depth'] === 2, 'klucze rodziców i poziomy');
expectCalc(abs($rows[0]['unit_price'] - 2.5) < 1e-9 && abs($rows[1]['unit_price'] * $rows[1]['price_factor'] - 9.0) < 1e-9, 'cena półproduktu : koszt ÷ ilość (2,50 zł/szt ; 9,00 zł/kg)');
expectCalc(abs($rows[0]['share'] - 20 / 23.71 * 100) < 1e-9, 'udział w koszcie całej receptury');
$label = array_values(array_filter($rows, fn($r) => $r['name'] === 'Etykieta'))[0];
expectCalc($label['missing'] && $label['packaging'], 'etykieta : brak ceny, opakowanie');

$materials = V::materials($elements, $total);
expectCalc(array_column($materials, 'name') === ['Śmietana', 'Karton', 'Cukier', 'Etykieta'], 'surowce od najdroższego : ' . implode(',', array_column($materials, 'name')));
$sugar = $materials[2];
expectCalc($sugar['qty'] === 750.0 && abs($sugar['cost'] - 3.0) < 1e-9 && $sugar['uses'] === 2 && $sugar['price_unit'] === 'kg' && abs($sugar['unit_price'] * $sugar['price_factor'] - 4.0) < 1e-9, 'cukier zsumowany z 2 miejsc, cena na kg');
expectCalc($materials[0]['price_unit'] === 'l' && $materials[3]['missing'], 'ml → l ; brak ceny');
expectCalc(abs(array_sum(array_column($materials, 'cost')) - $total) < 1e-9, 'surowce łącznie = koszt receptury');

expectCalc(V::perUnit(4.45, 1710, 'g') === ['label' => 'kg', 'value' => 4.45 / 1710 * 1000], 'koszt na kg półproduktu w gramach');
expectCalc(V::perUnit(1.05, 1, 'szt')['value'] === 1.05 && V::priceUnit('') === 'j.', 'koszt na sztukę ; brak jednostki');
expectCalc(V::rows([], 0) === [] && V::materials([], 0) === [] && V::total([]) === 0.0, 'pusta receptura');

// Podłączenie : trzy strony kalkulacji na jednym widoku, stare widoki usunięte
$root = __DIR__ . '/..';
foreach (['Recipe' => 'product', 'SemiProduct' => 'semi', 'Composition' => 'composition'] as $controller => $kind) {
    $code = file_get_contents("$root/App/Controllers/Web/Recipe/{$controller}Controller.php");
    expectCalc(str_contains($code, "\$this->calculationView->build((int) \$id, '$kind')"), "$controller : nowy widok kalkulacji");
}
foreach (['recipe', 'semi_product', 'composition'] as $dir) expectCalc(!file_exists("$root/App/Views/recipe/$dir/calculation.view.php"), "stary widok $dir usunięty");
expectCalc(file_exists("$root/App/Views/recipe/calculation.twig"), 'widok recipe/calculation.twig');

echo "recipe_calculation_view_test: OK\n";
