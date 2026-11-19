<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/tasks', [
        'controller' => \App\Employee\app\Http\Controllers\Task\TaskController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('GET', '/tasks/{id:\d+}', [
        'controller' => \App\Employee\app\Http\Controllers\Task\TaskController::class,
        'method'     => 'taskOverview'
    ]);

    $r->addRoute('GET', '/tasks/completion/{id:\d+}', [
        'controller' => \App\Employee\app\Http\Controllers\Task\TaskController::class,
        'method'     => 'taskCompletionOverview'
    ]);

    $r->addRoute('POST', '/tasks/{id:\d+}', [
        'controller' => \App\Employee\app\Http\Controllers\Task\TaskController::class,
        'method'     => 'markAsDoneTask'
    ]);

};