<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    $r->addRoute('GET', '/purchases', [
        'controller' => App\Controllers\Web\Material\Purchase\PurchaseController::class,
        'method' => 'index'
    ]);

    $r->addRoute('GET', '/purchases/new', [
        'controller' => App\Controllers\Web\Material\Purchase\PurchaseController::class,
        'method' => 'insert'
    ]);

    $r->addRoute('POST', '/ajax/purchases', [
        'controller' => App\Controllers\Web\Material\Purchase\PurchaseController::class,
        'method' => 'ajaxInsert'
    ]);

    $r->addRoute('GET', '/purchases/{id:\d+}/show', [
        'controller' => App\Controllers\Web\Material\Purchase\PurchaseController::class,
        'method' => 'show'
    ]);
};