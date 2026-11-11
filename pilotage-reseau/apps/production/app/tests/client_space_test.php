<?php
declare(strict_types=1);
/*
 * Strona klienta : cel obrotu (zakupy × współczynnik kategorii → klienta → domyślny, kategorie « poza celem »,
 * cena sprzedaży za sztukę, sumy), parsowanie współczynnika, sortowanie bez akcentów, trasy i podłączenie
 * wiadomości « Cel obrotu » w Komunikacji. Bez bazy danych.
 *
 *   php tests/client_space_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Services\Client\ClientSpaceService;

function expectSpace(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$ref = new ReflectionClass(ClientSpaceService::class);
$service = $ref->newInstanceWithoutConstructor();
$call = function (string $method, ...$args) use ($ref, $service) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke($m->isStatic() ? null : $service, ...$args);
};

$lines = [
    ['category_id' => 10, 'category_name' => 'Viennoiserie', 'product_id' => 1, 'product_name' => 'Croissant', 'ppc' => 60, 'cartons' => 2, 'value_eur' => 100.0],
    ['category_id' => 10, 'category_name' => 'Viennoiserie', 'product_id' => 2, 'product_name' => 'Chocolatine', 'ppc' => 50, 'cartons' => 1, 'value_eur' => 40.0],
    ['category_id' => 20, 'category_name' => 'Pain', 'product_id' => 3, 'product_name' => 'Baguette', 'ppc' => 20, 'cartons' => 5, 'value_eur' => 75.0],
    ['category_id' => 30, 'category_name' => 'Produit de services', 'product_id' => 4, 'product_name' => 'Transport', 'ppc' => 1, 'cartons' => 1, 'value_eur' => 30.0],
    ['category_id' => null, 'category_name' => 'Bez kategorii', 'product_id' => 5, 'product_name' => 'Nieznany', 'ppc' => null, 'cartons' => 1, 'value_eur' => 10.0],
];
// Viennoiserie : własny współczynnik × 3 ; Pain : współczynnik klienta (2,6) ; usługi : poza celem
$coefficients = [10 => [3.0, false], 30 => [null, true]];
$cats = $call('categoriesFor', $lines, $coefficients, 2.6);
$byName = array_column($cats, null, 'name');
expectSpace(array_column($cats, 'name') === ['Bez kategorii', 'Pain', 'Produit de services', 'Viennoiserie'], 'kategorie alfabetycznie');
expectSpace($byName['Viennoiserie']['coefficient'] === 3.0 && $byName['Viennoiserie']['coefficient_source'] === 'category', 'współczynnik kategorii ma pierwszeństwo');
expectSpace($byName['Pain']['coefficient'] === 2.6 && $byName['Pain']['coefficient_source'] === 'client', 'bez współczynnika kategorii : współczynnik klienta');
expectSpace($byName['Produit de services']['excluded'] && $byName['Produit de services']['target'] === 0.0, 'kategoria poza celem : brak celu');
expectSpace(abs($byName['Viennoiserie']['target'] - 420.0) < 1e-9, 'Viennoiserie : (100 + 40) × 3 = 420');
expectSpace(array_column($byName['Viennoiserie']['items'], 'name') === ['Chocolatine', 'Croissant'], 'produkty alfabetycznie');
$croissant = $byName['Viennoiserie']['items'][1];
expectSpace($croissant['pieces'] === 120.0 && abs($croissant['piece_price'] - 100 / 120) < 1e-9 && abs($croissant['sale_price'] - 100 / 120 * 3) < 1e-9, 'cena zakupu i sprzedaży za sztukę');
expectSpace($byName['Bez kategorii']['items'][0]['piece_price'] === null && $byName['Bez kategorii']['items'][0]['sale_price'] === null, 'brak szt. w kartonie : bez ceny za sztukę');
expectSpace($byName['Produit de services']['items'][0]['sale_price'] === null, 'poza celem : bez ceny sprzedaży');

$t = $call('totals', $cats);
expectSpace(abs($t['purchases'] - 255.0) < 1e-9 && abs($t['purchases_sell'] - 225.0) < 1e-9 && abs($t['excluded'] - 30.0) < 1e-9, 'sumy zakupów : wszystkie, do odsprzedaży, poza celem');
expectSpace(abs($t['target'] - (420 + 75 * 2.6 + 10 * 2.6)) < 1e-9, 'cel = suma kategorii');
expectSpace(abs($t['coefficient'] - $t['target'] / 225.0) < 1e-9 && $t['products'] === 5, 'współczynnik średni i liczba produktów');
expectSpace($call('totals', [])['coefficient'] === null, 'bez zakupów : brak współczynnika');

expectSpace($call('decimal', '2,50') === 2.5 && $call('decimal', ' 3.1 ') === 3.1 && $call('decimal', '') === null && $call('decimal', null) === null, 'współczynnik : przecinek, kropka, puste');
try { $call('decimal', 'abc'); expectSpace(false, 'tekst zamiast liczby odrzucony'); } catch (CustomException) {}
expectSpace($call('sortKey', 'Pâtisserie') < $call('sortKey', 'Produit') && $call('sortKey', 'Pâtisserie') > $call('sortKey', 'Pain'), 'sortowanie bez akcentów');

// Podłączenie : trasy, wiadomość « Cel obrotu », migracja, karta klienta
$root = __DIR__ . '/..';
$routes = file_get_contents("$root/App/Core/Routes/Client/ClientSpaceRoutes.php") . file_get_contents("$root/App/Core/Routes/Communication/CommunicationRoutes.php");
foreach (['/clients/{id:\d+}/space', '/space/documents.pdf', '/space/goal.pdf', '/ajax/clients/space/category-coefficients', '/communication/compose/sales-goal/', '/ajax/communication/compose/sales-goal/'] as $route) {
    expectSpace(str_contains($routes, $route), "trasa $route");
}
$communication = file_get_contents("$root/App/Services/Communication/CommunicationService.php");
expectSpace(str_contains($communication, "'sales_goal' => \$this->salesGoalContexts->forCustomer") && str_contains($communication, "'sales_goal' => \$this->salesGoalPdf->generate"), 'Komunikacja : kontekst i PDF celu obrotu');
$migration = file_get_contents("$root/migrations/tfb/064_client_space.sql");
expectSpace(!str_contains($migration, 'IF NOT EXISTS ') || !preg_match('/ADD COLUMN IF NOT EXISTS/i', $migration), 'migracja zgodna z MySQL 8');
foreach (['fr', 'nl', 'pl', 'en'] as $lang) expectSpace(str_contains($migration, "'sales_goal', '$lang'"), "szablon wiadomości $lang");
$card = file_get_contents("$root/App/Views/client/client/edit.view.php");
expectSpace(str_contains($card, 'name="space_docs"') && str_contains($card, 'name="space_goal"') && str_contains($card, 'name="space_coefficient"'), 'karta klienta : opcje strony klienta');

echo "client_space_test: OK\n";
