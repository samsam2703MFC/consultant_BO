<?php
use FastRoute\RouteCollector;

// [289] Mój urlop (makieta 50) : urlop osoby z kontem ERP z zastępcą ; zatwierdza CEO (albo administrator).
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Organization\UserLeaveController::class;
    $r->addRoute('GET', '/my-leave', ['controller' => $c, 'method' => 'page']);
    $r->addRoute('GET', '/ajax/my-leave', ['controller' => $c, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/my-leave', ['controller' => $c, 'method' => 'create']);
    $r->addRoute('PUT', '/ajax/my-leave/{id:\d+}/substitute', ['controller' => $c, 'method' => 'substitute']);
    $r->addRoute('POST', '/ajax/my-leave/{id:\d+}/approve', ['controller' => $c, 'method' => 'approve']);
    $r->addRoute('POST', '/ajax/my-leave/{id:\d+}/reject', ['controller' => $c, 'method' => 'reject']);
    $r->addRoute('DELETE', '/ajax/my-leave/{id:\d+}', ['controller' => $c, 'method' => 'cancel']);
};
