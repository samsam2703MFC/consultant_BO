<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // -------------------------------------------------------
    // Panel HR – przegląd kompetencji recepturowych
    // -------------------------------------------------------
    $r->addRoute('GET', '/hr/recipe-competencies', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'overview',
    ]);

    // -------------------------------------------------------
    // AJAX – CRUD kompetencji
    // -------------------------------------------------------
    $r->addRoute('GET',    '/ajax/recipe-competencies', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxGetAll',
    ]);

    $r->addRoute('POST',   '/ajax/recipe-competencies', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxCreate',
    ]);

    $r->addRoute('PUT',    '/ajax/recipe-competencies/{id:\d+}', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxUpdate',
    ]);

    $r->addRoute('DELETE', '/ajax/recipe-competencies/{id:\d+}', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxDelete',
    ]);

    // -------------------------------------------------------
    // AJAX – powiązania receptura <-> kompetencja
    // -------------------------------------------------------
    $r->addRoute('GET',  '/ajax/recipes/{recipeId:\d+}/competencies', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxGetByRecipe',
    ]);

    $r->addRoute('POST', '/ajax/recipes/{recipeId:\d+}/competencies/sync', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxSyncRecipeCompetencies',
    ]);
};

