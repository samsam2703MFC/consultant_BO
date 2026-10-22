<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Organization\PositionController::class;

    $r->addRoute('GET', '/organization/positions', ['controller' => $c, 'method' => 'positions']);
    $r->addRoute('GET', '/organization/users/{id:\d+}', ['controller' => $c, 'method' => 'userSheet']);
    $r->addRoute('GET', '/my-tasks', ['controller' => $c, 'method' => 'myTasks']);
    $r->addRoute('PATCH', '/ajax/my-tasks/{id:\d+}', ['controller' => $c, 'method' => 'setStatus']);
    $r->addRoute('POST', '/ajax/organization/positions', ['controller' => $c, 'method' => 'savePosition']);
    $r->addRoute('PATCH', '/ajax/organization/positions/{id:\d+}', ['controller' => $c, 'method' => 'savePosition']);
    $r->addRoute('POST', '/ajax/organization/positions/{id:\d+}/tasks', ['controller' => $c, 'method' => 'addTask']);
    $r->addRoute('PATCH', '/ajax/organization/tasks/{id:\d+}', ['controller' => $c, 'method' => 'updateTask']);
    $r->addRoute('PUT', '/ajax/organization/users/{id:\d+}/position', ['controller' => $c, 'method' => 'assign']);
    $r->addRoute('PUT', '/ajax/organization/users/{id:\d+}/module-role', ['controller' => $c, 'method' => 'moduleRole']);
};
