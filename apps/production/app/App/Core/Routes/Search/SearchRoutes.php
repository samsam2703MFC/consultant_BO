<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Wyszukiwarka z górnego paska (assets/js/atelier-search.js)
    $r->addRoute('GET', '/ajax/search', [
        'controller' => App\Controllers\Web\Search\SearchController::class,
        'method'     => 'ajaxSearch'
    ]);
};
