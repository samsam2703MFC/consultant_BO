<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Listy produktów ERP (wszystkie / kategorii) : lista w Kartach produktów (filtry kategorii i aktywności w ERP).
    $r->addRoute('GET', '/products/all', ['redirect' => '/workshop/products#/?erp=all']);

    $r->addRoute('GET', '/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    // Kreator „Nowy produkt z recepturą” (koszt szkicu: POST /ajax/recipes/draft-cost w RecipeRoutes)
    $r->addRoute('GET', '/products/new-with-recipe', [
        'controller' => App\Controllers\Web\Product\ProductRecipeWizardController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/products/with-recipe', [
        'controller' => App\Controllers\Web\Product\ProductRecipeWizardController::class,
        'method'     => 'ajaxCreate'
    ]);

    // Receptura produktu = 1 karton [201] : lista receptur « na 1 sztukę » do sprawdzenia i decyzja (× sztuk w kartonie / już na karton).
    $r->addRoute('GET', '/ajax/products/recipe-check', [
        'controller' => App\Controllers\Web\Product\ProductRecipeWizardController::class,
        'method'     => 'ajaxUnitCheck'
    ]);

    $r->addRoute('POST', '/ajax/products/{id:\d+}/recipe-check', [
        'controller' => App\Controllers\Web\Product\ProductRecipeWizardController::class,
        'method'     => 'ajaxUnitDecide'
    ]);

    $r->addRoute('POST', '/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('GET', '/product-categories/{idCategory:\d+}/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('POST', '/product-categories/{idCategory:\d+}/products/new', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'newProduct'
    ]);

    $r->addRoute('GET', '/products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('POST', '/products/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'editProduct'
    ]);

    $r->addRoute('PATCH', '/ajax/products/{id:\d+}', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'ajaxUpdateProduct'
    ]);


    $r->addRoute('GET', '/products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'deleteProduct'
    ]);


    $r->addRoute('POST', '/products/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Product\ProductController::class,
        'method'     => 'deleteProduct'
    ]);


    $r->addRoute('GET', '/product-categories/{idCategory:\d+}/products', ['redirect' => '/workshop/products#/?erp=all&category={idCategory}']);


    $r->addRoute('GET', '/products/export', [
        'controller' => App\Controllers\Web\Product\ProductExportController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/products/export', [
        'controller' => App\Controllers\Web\Product\ProductExportController::class,
        'method'     => 'export'
    ]);
};
