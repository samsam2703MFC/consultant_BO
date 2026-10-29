<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $r->addRoute('GET', '/ajax/app-notifications', [
        'controller' => App\Controllers\Web\Notification\AppNotificationController::class,
        'method' => 'active',
    ]);
    $r->addRoute('POST', '/ajax/app-notifications/{id:\d+}/acknowledge', [
        'controller' => App\Controllers\Web\Notification\AppNotificationController::class,
        'method' => 'acknowledge',
    ]);
    $r->addRoute('POST', '/ajax/app-notifications/acknowledge-all', [
        'controller' => App\Controllers\Web\Notification\AppNotificationController::class,
        'method' => 'acknowledgeAll',
    ]);
};
