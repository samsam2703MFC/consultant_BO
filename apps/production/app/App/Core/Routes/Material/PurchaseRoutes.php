<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    // Faktury zakupowe : KSeF (Finanse → Faktury zakupowe KSeF). Dawne dostawy wpisane ręcznie zostają do podglądu.
    $r->addRoute('GET', '/purchases/new', ['redirect' => '/workshop/supply#tab=invoices']);

    $r->addRoute('GET', '/purchases', [
        'controller' => App\Controllers\Web\Material\Purchase\PurchaseController::class,
        'method' => 'index'
    ]);



    $r->addRoute('GET', '/purchases/{id:\d+}/show', [
        'controller' => App\Controllers\Web\Material\Purchase\PurchaseController::class,
        'method' => 'show'
    ]);
};