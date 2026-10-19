<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/dashboard', [
        'controller' => App\Controllers\Web\Dashboard\DashboardController::class,
        'method'     => 'index'
    ]);
};
