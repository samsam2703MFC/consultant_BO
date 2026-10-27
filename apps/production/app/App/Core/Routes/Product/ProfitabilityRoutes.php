<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    
    // Dawna rentowność produktu (strony, widoki i kontroler usunięte) : stare adresy → Marża produktów
    $r->addRoute('GET', '/products/profitability', ['redirect' => '/finances/product-margin']);
    $r->addRoute('GET', '/products/{id:\d+}/profitability-details', ['redirect' => '/finances/product-margin?product_id={id}']);
};
