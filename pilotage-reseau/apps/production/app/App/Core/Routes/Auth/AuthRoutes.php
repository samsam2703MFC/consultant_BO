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

    // [300] Logowanie kodem QR (makieta 55) : kod dla tej przeglądarki i oczekiwanie na zatwierdzenie telefonem.
    $r->addRoute('GET', '/ajax/login-qr', ['controller' => App\Controllers\Web\Auth\LoginController::class, 'method' => 'qr']);
    $r->addRoute('GET', '/ajax/login-qr/status', ['controller' => App\Controllers\Web\Auth\LoginController::class, 'method' => 'qrStatus']);
};
