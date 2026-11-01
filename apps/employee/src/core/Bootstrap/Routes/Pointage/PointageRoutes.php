<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Le pointage (01/11/2026) : la caméra, ce qu'elle a lu, la liste des jours, un départ oublié.
    $r->addRoute('GET', '/pointage', [
        'controller' => \App\Employee\app\Http\Controllers\Pointage\PointageController::class,
        'method'     => 'scanner'
    ]);
    $r->addRoute('POST', '/pointage', [
        'controller' => \App\Employee\app\Http\Controllers\Pointage\PointageController::class,
        'method'     => 'pointer'
    ]);
    $r->addRoute('GET', '/me/pointages', [
        'controller' => \App\Employee\app\Http\Controllers\Pointage\PointageController::class,
        'method'     => 'liste'
    ]);
    $r->addRoute('POST', '/me/pointage/depart', [
        'controller' => \App\Employee\app\Http\Controllers\Pointage\PointageController::class,
        'method'     => 'depart'
    ]);

};
