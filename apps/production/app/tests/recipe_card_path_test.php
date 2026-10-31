<?php
declare(strict_types=1);
/*
 * Karta produktu, krok 3 : półprodukt → receptura produktu → powrót do karty.
 * Walidacja receptury i półproduktu zwraca komunikaty (pokazywane nad formularzem) i odrzuca składnik bez ilości
 * (product_recipe_connection.quantity : int NOT NULL) ; adres powrotu do karty na kroku « Receptura ».
 *
 *   php tests/recipe_card_path_test.php
 *
 * Bez bazy : usługi tworzone bez konstruktora (walidacja nie używa repozytoriów).
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Product\ProductService;
use App\Services\Recipe\RecipeService;
use App\Services\Recipe\SemiProductService;

function expectCardPath(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

defined('ROOT') || define('ROOT', 'http://erp.test');

$recipes = (new ReflectionClass(RecipeService::class))->newInstanceWithoutConstructor();
$semi = (new ReflectionClass(SemiProductService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SemiProductService::class, 'recipeService'))->setValue($semi, $recipes);
$products = (new ReflectionClass(ProductService::class))->newInstanceWithoutConstructor();

// --- Receptura produktu
expectCardPath($recipes->validate([]) === false, 'pusta receptura odrzucona');
expectCardPath($recipes->errors() === ['Wybierz produkt', 'Brak danych dotyczących składników'], 'komunikaty pustej receptury');
$ok = ['final_product' => 1003147, 'sfp_data' => [12 => ['id' => 12, 'name' => 'Ciasto', 'quantity' => '8']], 'material_data' => [5 => ['id' => 5, 'name' => 'Mąka', 'quantity' => '120']]];
expectCardPath($recipes->validate($ok) === true && $recipes->errors() === [], 'receptura z ilościami przyjęta, komunikaty wyczyszczone');
$noQty = $ok;
$noQty['sfp_data'][12]['quantity'] = '';
expectCardPath($recipes->validate($noQty) === false && $recipes->errors() === ['Podaj ilość większą od zera : Ciasto'], 'półprodukt bez ilości odrzucony z nazwą');
$zero = $ok;
$zero['material_data'][5]['quantity'] = '0';
expectCardPath($recipes->validate($zero) === false && $recipes->errors() === ['Podaj ilość większą od zera : Mąka'], 'surowiec z ilością 0 odrzucony');
expectCardPath($recipes->validateEdit($noQty + ['id_recipe' => 7]) === false && count($recipes->errors()) === 1, 'edycja : ta sama reguła ilości');
expectCardPath($recipes->validateEdit($ok + ['id_recipe' => 7]) === true, 'edycja poprawna');

// --- Półprodukt
expectCardPath($semi->validate(['name' => '']) === false && $semi->errors() === ['Wprowadź nazwę receptury', 'Brak danych dotyczących składników'], 'pusty półprodukt : komunikaty');
$semiOk = ['name' => 'Nadzienie', 'composition_data' => [3 => ['id' => 3, 'name' => 'Krem', 'quantity' => '460']]];
expectCardPath($semi->validate($semiOk) === true && $semi->errors() === [], 'półprodukt z kompozycją przyjęty');
$semiOk['composition_data'][3]['quantity'] = 'abc';
expectCardPath($semi->validate($semiOk) === false && $semi->errors() === ['Podaj ilość większą od zera : Krem'], 'kompozycja bez liczby odrzucona');

// --- Powrót do Karty produktu
expectCardPath($products->cardUrl(1003147, 3) === 'http://erp.test/workshop/products#/product/1003147?step=3', 'adres karty na kroku 3');
expectCardPath($products->cardUrl(1003147) === 'http://erp.test/workshop/products#/product/1003147', 'adres karty bez kroku');

echo "recipe_card_path_test : OK\n";
