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

    // Moce produkcyjne [234] : wykorzystanie maszyn i ludzi wobec planu tygodnia, mroźnia, ilość na zmianę, co jeszcze zmieszczę, ustawienia.
    $cc = App\Controllers\Web\Production\CapacityController::class;
    $r->addRoute('GET', '/production/capacity', ['controller' => $cc, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/production/capacity', ['controller' => $cc, 'method' => 'data']);
    $r->addRoute('GET', '/ajax/production/capacity/simulate', ['controller' => $cc, 'method' => 'simulate']);
    $r->addRoute('PUT', '/ajax/production/capacity/workstations/{id:\d+}', ['controller' => $cc, 'method' => 'saveMachine']);
    $r->addRoute('PUT', '/ajax/production/capacity/freezer', ['controller' => $cc, 'method' => 'saveFreezer']);

    // Prognoza 13 tygodni [220] : zamówienia, N-1 × trend, gamy sezonowe panelu TF Buddy (tylko odczyt), zakupy, zatwierdzenie w poniedziałek.
    $fc = App\Controllers\Web\Production\ForecastController::class;
    $r->addRoute('GET', '/production/forecast', ['controller' => $fc, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/forecast', ['controller' => $fc, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/forecast/approve', ['controller' => $fc, 'method' => 'approve']);
    $r->addRoute('POST', '/ajax/forecast/reopen', ['controller' => $fc, 'method' => 'reopen']);
    $r->addRoute('POST', '/ajax/forecast/panel-refresh', ['controller' => $fc, 'method' => 'refresh']);
    $r->addRoute('POST', '/ajax/forecast/panel-links/{id:\d+}', ['controller' => $fc, 'method' => 'link']);
    $r->addRoute('POST', '/ajax/forecast/clients/{id:\d+}', ['controller' => $fc, 'method' => 'client']);
};
