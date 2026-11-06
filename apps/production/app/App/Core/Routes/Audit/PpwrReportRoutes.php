<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r): void {
    $r->addRoute('GET', '/ppwr/reports', [
        'controller' => App\Controllers\Web\Audit\PpwrReportController::class,
        'method' => 'index',
    ]);
    $r->addRoute('GET', '/ppwr/reports/pdf', [
        'controller' => App\Controllers\Web\Audit\PpwrReportController::class,
        'method' => 'downloadPdf',
    ]);
};
