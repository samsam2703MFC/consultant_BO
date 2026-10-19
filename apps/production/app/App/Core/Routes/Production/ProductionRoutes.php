<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    $r->addRoute('GET', '/production/planning', [
        'controller' => App\Controllers\Web\Production\ProductionListController::class,
        'method'     => 'planning'
    ]);

    $r->addRoute('POST', '/ajax/production-list/standard', [
        'controller' => App\Controllers\Web\Production\ProductionListController::class,
        'method'     => 'ajaxGetProductionList'
    ]);

    $r->addRoute('POST', '/ajax/production-list/complaint', [
        'controller' => App\Controllers\Web\Production\ProductionListController::class,
        'method'     => 'ajaxGetComplaintProductionList'
    ]);

    $r->addRoute('POST', '/ajax/production-list/requirement/pdf', [
        'controller' => App\Controllers\Web\Production\ProductionListController::class,
        'method'     => 'ajaxGetRequirementListPDF'
    ]);
};
