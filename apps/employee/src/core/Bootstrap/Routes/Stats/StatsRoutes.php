<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/statistiques', [
        'controller' => \App\Employee\app\Http\Controllers\Stats\StatsController::class,
        'method'     => 'index'
    ]);

};
