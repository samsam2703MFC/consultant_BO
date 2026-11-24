<?php
use FastRoute\RouteCollector;

// [400] Asystent « Receptury i dane produktów » (Kierownik produkcji — co miesiąc) : przegląd → decyzja → zatwierdź.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Product\RecipesMonthController::class;
    $r->addRoute('GET', '/recipes/month', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/recipes/month', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/recipes/month/decision', ['controller' => $c, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/recipes/month/finish', ['controller' => $c, 'method' => 'finish']);
};
