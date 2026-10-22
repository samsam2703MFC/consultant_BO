<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    // Dawna « lista zamówień » i kreator « Planuj produkcję » : jedna strona « Plan zespołów » (listy dnia PDF w środku).
    $r->addRoute('GET', '/production/planning', ['redirect' => '/workshop/team-sheet']);
    $r->addRoute('GET', '/production/plan-wizard', ['redirect' => '/workshop/team-sheet']);

    // Listy dnia (PDF) na stronie Plan zespołów : dni wysyłki z oczekującymi zamówieniami (PDF-y z /ajax/production-list/*).
    $r->addRoute('GET', '/ajax/production-plan/days', [
        'controller' => App\Controllers\Web\Production\ProductionDayListsController::class,
        'method'     => 'ajaxDays'
    ]);

    $r->addRoute('GET', '/ajax/production-plan/complaints', [
        'controller' => App\Controllers\Web\Production\ProductionDayListsController::class,
        'method'     => 'ajaxComplaints'
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
