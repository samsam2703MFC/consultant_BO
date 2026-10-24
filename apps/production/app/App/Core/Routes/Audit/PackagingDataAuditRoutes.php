<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r): void {
    $r->addRoute('GET', '/audits/packaging-data', [
        'controller' => App\Controllers\Web\Audit\PackagingDataAuditController::class,
        'method' => 'index',
    ]);
    $r->addRoute('PATCH', '/ajax/audits/packaging-data/materials/{id:\d+}', [
        'controller' => App\Controllers\Web\Audit\PackagingDataAuditController::class,
        'method' => 'ajaxUpdateMaterial',
    ]);
    $r->addRoute('GET', '/audits/packaging-reports', [
        'controller' => App\Controllers\Web\Audit\PackagingReportController::class,
        'method' => 'index',
    ]);
    $r->addRoute('GET', '/audits/packaging-reports/pdf', [
        'controller' => App\Controllers\Web\Audit\PackagingReportController::class,
        'method' => 'downloadPdf',
    ]);
    $r->addRoute('GET', '/audits/packaging-reports/csv', [
        'controller' => App\Controllers\Web\Audit\PackagingReportController::class,
        'method' => 'downloadCsv',
    ]);
};
