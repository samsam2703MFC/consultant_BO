<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/primes', [
        'controller' => \App\Employee\app\Http\Controllers\Primes\PrimesController::class,
        'method'     => 'index'
    ]);

};
