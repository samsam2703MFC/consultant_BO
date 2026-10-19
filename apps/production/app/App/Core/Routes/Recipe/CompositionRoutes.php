<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/compositions', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/compositions/new', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'newComposition'
    ]);

    $r->addRoute('POST', '/compositions/new', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'newComposition'
    ]);

    $r->addRoute('GET', '/compositions/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'editComposition'
    ]);

    $r->addRoute('POST', '/compositions/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'editComposition'
    ]);

    $r->addRoute('GET', '/compositions/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'deleteComposition'
    ]);

    $r->addRoute('POST', '/compositions/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'deleteComposition'
    ]);

    $r->addRoute('GET', '/compositions/{id:\d+}/calculation', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'calculation'
    ]);

    // ─── Kroki przygotowania kompozycji ──────────────────────────────────────

    $r->addRoute('GET', '/compositions/{id:\d+}/preparation-steps', [
        'controller' => App\Controllers\Web\Recipe\CompositionController::class,
        'method'     => 'preparationSteps'
    ]);

    $r->addRoute('POST', '/ajax/compositions/{id:\d+}/preparation-steps', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxAdd'
    ]);

    $r->addRoute('PUT', '/ajax/compositions/{recipeId:\d+}/preparation-steps/{stepId:\d+}', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxUpdate'
    ]);

    $r->addRoute('DELETE', '/ajax/compositions/{recipeId:\d+}/preparation-steps/{stepId:\d+}', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxDelete'
    ]);

    $r->addRoute('POST', '/ajax/compositions/{recipeId:\d+}/preparation-steps/{stepId:\d+}/move', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxMove'
    ]);

    $r->addRoute('POST', '/ajax/compositions/{recipeId:\d+}/preparation-steps/reorder', [
        'controller' => App\Controllers\Web\Recipe\PreparationStepController::class,
        'method'     => 'ajaxReorder'
    ]);
};
