<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/users', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'overview'
    ]);

    $r->addRoute('GET', '/users/new', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'newUser'
    ]);

    $r->addRoute('POST', '/users/new', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'newUser'
    ]);

    $r->addRoute('GET', '/users/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'editUser'
    ]);

    $r->addRoute('POST', '/users/{id:\d+}/edit', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'editUser'
    ]);

    $r->addRoute('GET', '/users/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'deleteUser'
    ]);

    $r->addRoute('POST', '/users/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\User\UserController::class,
        'method'     => 'deleteUser'
    ]);




};
