<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/schedule', [
        'controller' => \App\Employee\app\Http\Controllers\Schedule\ScheduleController::class,
        'method'     => 'index'
    ]);

};