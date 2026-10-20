<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    // Dawna « lista zamówień » : ten sam wybór zamówień i te same PDF-y są w kreatorze
    $r->addRoute('GET', '/production/planning', ['redirect' => '/production/plan-wizard']);

    // Kreator „Planuj produkcję” (tylko odczyt; PDF-y z tras /ajax/production-list/* poniżej)
    $r->addRoute('GET', '/production/plan-wizard', [
        'controller' => App\Controllers\Web\Production\ProductionPlanWizardController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/ajax/production-plan/preview', [
        'controller' => App\Controllers\Web\Production\ProductionPlanWizardController::class,
        'method'     => 'ajaxPreview'
    ]);

    $r->addRoute('GET', '/ajax/production-plan/complaints', [
        'controller' => App\Controllers\Web\Production\ProductionPlanWizardController::class,
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
