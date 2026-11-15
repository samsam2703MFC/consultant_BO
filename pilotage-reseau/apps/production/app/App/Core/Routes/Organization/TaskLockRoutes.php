<?php
use FastRoute\RouteCollector;

// [280] Zadania obowiązkowe : ekran blokady, kreator kroków zadania, prośba o odblokowanie i decyzja przełożonego.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Organization\TaskLockController::class;

    $r->addRoute('GET', '/tasks/required', ['controller' => $c, 'method' => 'required']);
    $r->addRoute('GET', '/tasks/wizard/{id:\d+}', ['controller' => $c, 'method' => 'wizard']);
    $r->addRoute('POST', '/ajax/tasks/wizard/{id:\d+}/finish', ['controller' => $c, 'method' => 'finish']);
    $r->addRoute('POST', '/ajax/tasks/{id:\d+}/unblock-request', ['controller' => $c, 'method' => 'requestUnblock']);
    $r->addRoute('GET', '/tasks/unblock/{user:\d+}/{task:\d+}', ['controller' => $c, 'method' => 'unblockPage']);
    $r->addRoute('POST', '/ajax/tasks/unblock/{user:\d+}/{task:\d+}', ['controller' => $c, 'method' => 'unblock']);
};
