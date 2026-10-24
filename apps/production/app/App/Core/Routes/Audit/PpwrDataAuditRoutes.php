<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r): void {
    $r->addRoute('GET', '/ppwr/register', [
        'controller' => App\Controllers\Web\Audit\PpwrDataAuditController::class,
        'method' => 'index',
    ]);
    $r->addRoute('GET', '/audits/ppwr-data', ['redirect' => '/ppwr/register']);
    $r->addRoute('PATCH', '/ajax/audits/ppwr-data/materials/{id:\d+}', [
        'controller' => App\Controllers\Web\Audit\PpwrDataAuditController::class,
        'method' => 'ajaxSaveProfile',
    ]);
};
