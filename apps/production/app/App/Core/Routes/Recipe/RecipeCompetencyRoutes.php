<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // -------------------------------------------------------
    // Panel HR – przegląd kompetencji recepturowych
    // -------------------------------------------------------
    // Strona kompetencji usunięta : stary adres prowadzi do budżetu personelu.
    $r->addRoute('GET', '/hr/recipe-competencies', ['redirect' => '/staff/budget']);

    // -------------------------------------------------------
    // AJAX – CRUD kompetencji
    // -------------------------------------------------------
    $r->addRoute('GET',    '/ajax/recipe-competencies', [
        'controller' => App\Controllers\Web\Recipe\RecipeCompetencyController::class,
        'method'     => 'ajaxGetAll',
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

