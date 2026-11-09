<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    
    // Dawna rentowność kategorii (strony, widoki i kontroler usunięte) : stare adresy → Marża kategorii
    $r->addRoute('GET', '/categories/profitability', ['redirect' => '/finances/category-margin']);
    $r->addRoute('GET', '/categories/{id:\d+}/profitability-details', ['redirect' => '/finances/category-margin']);
};
