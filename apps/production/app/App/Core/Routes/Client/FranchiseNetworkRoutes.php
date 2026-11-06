<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    $r->addRoute('GET', '/client-franchise-networks', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/client-franchise-networks/new', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'newNetwork'
    ]);

    $r->addRoute('POST', '/client-franchise-networks/new', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'newNetwork'
    ]);

    $r->addRoute('GET', '/client-franchise-networks/{id:\d+}/edit', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'editNetwork'
    ]);

    $r->addRoute('POST', '/client-franchise-networks/{id:\d+}/edit', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'editNetwork'
    ]);


    $r->addRoute('GET', '/client-franchise-networks/{id:\d+}/delete', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'deleteNetwork'
    ]);

    $r->addRoute('POST', '/client-franchise-networks/{id:\d+}/delete', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'deleteNetwork'
    ]);

    $r->addRoute('GET', '/client-franchise-networks/{id:\d+}/franchises', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'overviewFranchises'
    ]);

    $r->addRoute('POST', '/ajax/client-franchise-networks/{idNetwork:\d+}/assign-client', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'ajaxAssignClientToNetwork'
    ]);

    $r->addRoute('POST', '/ajax/client-franchise-networks/{idNetwork:\d+}/deatach-client/{idClient:\d+}', [
        'controller' => \App\Controllers\Web\Client\FranchiseNetwork\FranchiseNetworkController::class,
        'method'     => 'ajaxDeatachClient'
    ]);

};
