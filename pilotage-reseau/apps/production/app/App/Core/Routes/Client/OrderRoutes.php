<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    
    $r->addRoute('GET', '/client-orders', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/client-orders/archive', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'archive'
    ]);
    $r->addRoute('POST', '/client-orders', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'overview'
    ]);

    // Kreator « Nowe zamówienie » (audyt 2, P4) : krok 1 klient i typ → produkty klienta → zamówienie z proformą.
    $r->addRoute('GET', '/client-orders/new', ['controller' => App\Controllers\Web\Client\Order\OrderController::class, 'method' => 'newOrderWizard']);
    $r->addRoute('POST', '/client-orders/new', ['controller' => App\Controllers\Web\Client\Order\OrderController::class, 'method' => 'newOrderWizard']);

    // Do obsłużenia : sprawy zamówień, proform i wysyłek czekające na działanie (jedna linia na sprawę)
    $r->addRoute('GET', '/client-orders/queue', ['controller' => \App\Controllers\Web\Client\Order\OrderQueueController::class, 'method' => 'index']);



    $r->addRoute('GET', '/client-orders/{id:\d+}/edit', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'editOrder'
    ]);

    $r->addRoute('POST', '/client-orders/{id:\d+}/edit', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'editOrder'
    ]);

    $r->addRoute('PATCH', '/ajax/client-orders/{id:\d+}', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'ajaxUpdateOrder'
    ]);


    $r->addRoute('GET', '/client-orders/{id:\d+}/delete', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'deleteOrder'
    ]);


    $r->addRoute('POST', '/client-orders/{id:\d+}/delete', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'deleteOrder'
    ]);

    $r->addRoute('POST', '/ajax/client-orders/picking-list', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'ajaxGetPickingList'
    ]);

    $r->addRoute('PATCH', '/ajax/client-orders/{id:\d+}/notify-integrated', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'ajaxNotifyIntegrated'
    ]);

    $r->addRoute('POST', '/ajax/client-orders/fetch-from-franchise', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'ajaxGetOrdersFromFranchise'
    ]);

    $r->addRoute('GET', '/clients/{id:\d+}/orders/create', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'createOrderForClient'
    ]);

    $r->addRoute('POST', '/clients/{id:\d+}/orders/create', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'createOrderForClient'
    ]);

    $r->addRoute('GET', '/client-orders/{id:\d+}/changelog', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'showChangelog'
    ]);


    $r->addRoute('POST', '/ajax/client-orders/{id:\d+}/push-supplement', [
        'controller' => \App\Controllers\Web\Client\Order\OrderController::class,
        'method'     => 'ajaxPushSupplement'
    ]);

};
