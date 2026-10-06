<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/me', [
        'controller' => \App\Employee\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'index'
    ]);

};