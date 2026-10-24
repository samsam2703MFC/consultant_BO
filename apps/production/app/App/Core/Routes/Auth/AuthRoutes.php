<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/', [
        'controller' => App\Controllers\Web\Auth\LoginController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('GET', '/logout', [
        'controller' => App\Controllers\Web\Auth\LogoutController::class,
        'method'     => 'logout'
    ]);

    $r->addRoute('POST', '/', [
        'controller' => App\Controllers\Web\Auth\LoginController::class,
        'method'     => 'login'
    ]);
};
