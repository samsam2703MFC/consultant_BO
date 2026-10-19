<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/recipes', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/recipes/new', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'newRecipe'
    ]);

    $r->addRoute('POST', '/recipes/new', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'newRecipe'
    ]);

    $r->addRoute('GET', '/recipes/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'editRecipe'
    ]);

    $r->addRoute('POST', '/recipes/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'editRecipe'
    ]);

    $r->addRoute('GET', '/recipes/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'deleteRecipe'
    ]);

    $r->addRoute('POST', '/recipes/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'deleteRecipe'
    ]);

    $r->addRoute('GET', '/recipes/{id:\d+}/calculation', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'calculation'
    ]);


    $r->addRoute('GET', '/ajax/recipes/final-recipes/costs/csv', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'ajaxGetRecipeFinalCostsCsv'
    ]);

    $r->addRoute('GET', '/ajax/recipes/products-without-recipe/pdf', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'ajaxGetProductsWithoutRecipePdf'
    ]);


    $r->addRoute('POST', '/ajax/settings/flat-all-recipes', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'ajaxFlatAllRecipes'
    ]);

    $r->addRoute('POST', '/ajax/settings/recalc-all-recipes', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'ajaxRecalcAllRecipes'
    ]);

    $r->addRoute('POST', '/ajax/settings/rewrite-all-labels', [
        'controller' => App\Controllers\Web\Recipe\RecipeController::class,
        'method'     => 'ajaxRewriteAllLabels'
    ]);

    // ─── Kroki przygotowania receptury ───────────────────────────────────────

    $r->addRoute('GET', '/recipes/{id:\d+}/preparation-steps', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/recipes/{id:\d+}/preparation-steps', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxAdd'
    ]);

    $r->addRoute('PUT', '/ajax/recipes/{recipeId:\d+}/preparation-steps/{stepId:\d+}', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxUpdate'
    ]);

    $r->addRoute('DELETE', '/ajax/recipes/{recipeId:\d+}/preparation-steps/{stepId:\d+}', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxDelete'
    ]);

    $r->addRoute('POST', '/ajax/recipes/{recipeId:\d+}/preparation-steps/{stepId:\d+}/move', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxMove'
    ]);

    $r->addRoute('POST', '/ajax/recipes/{recipeId:\d+}/preparation-steps/reorder', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxReorder'
    ]);
};
