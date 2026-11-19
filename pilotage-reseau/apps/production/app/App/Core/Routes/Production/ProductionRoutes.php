<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    // [247] Kreatory « lżej » : Plan na jutro (codziennie) i Wydajność i straty (co kwartał).
    $tp = App\Controllers\Web\Production\TomorrowPlanController::class;
    $r->addRoute('GET', '/production/tomorrow', ['controller' => $tp, 'method' => 'index']);
    $r->addRoute('GET', '/production/tomorrow/pdf', ['controller' => $tp, 'method' => 'pdf']);
    $r->addRoute('GET', '/ajax/production/tomorrow', ['controller' => $tp, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/production/tomorrow/move', ['controller' => $tp, 'method' => 'move']);
    $r->addRoute('POST', '/ajax/production/tomorrow/confirm', ['controller' => $tp, 'method' => 'confirm']);
    $yr = App\Controllers\Web\Production\YieldReviewController::class;
    $r->addRoute('GET', '/production/yield-review', ['controller' => $yr, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/production/yield-review', ['controller' => $yr, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/production/yield-review/finish', ['controller' => $yr, 'method' => 'finish']);

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
    // Dane maszyn [240] : przegląd + kreator maszyny (produkty, wsad, wsadów na godzinę, osób przy wsadzie).
    $r->addRoute('GET', '/ajax/production/capacity/machine-data', ['controller' => $cc, 'method' => 'machineData']);
    $r->addRoute('GET', '/ajax/production/capacity/machine-data/{id:\d+}', ['controller' => $cc, 'method' => 'machineProducts']);
    $r->addRoute('PUT', '/ajax/production/capacity/machine-data/{id:\d+}', ['controller' => $cc, 'method' => 'saveMachineProducts']);
    $r->addRoute('POST', '/ajax/production/capacity/machine-data', ['controller' => $cc, 'method' => 'createMachine']);
    $r->addRoute('PUT', '/ajax/production/capacity/machine-data/{id:\d+}/machine', ['controller' => $cc, 'method' => 'updateMachine']);

    // Prognoza 13 tygodni [220] : zamówienia, N-1 × trend, gamy sezonowe panelu TF Buddy (tylko odczyt), zakupy, zatwierdzenie w poniedziałek.
    $fc = App\Controllers\Web\Production\ForecastController::class;
    $r->addRoute('GET', '/production/forecast', ['controller' => $fc, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/forecast', ['controller' => $fc, 'method' => 'data']);
    $r->addRoute('POST', '/ajax/forecast/approve', ['controller' => $fc, 'method' => 'approve']);
    $r->addRoute('POST', '/ajax/forecast/reopen', ['controller' => $fc, 'method' => 'reopen']);
    $r->addRoute('POST', '/ajax/forecast/panel-refresh', ['controller' => $fc, 'method' => 'refresh']);
    $r->addRoute('POST', '/ajax/forecast/panel-links/{id:\d+}', ['controller' => $fc, 'method' => 'link']);
    $r->addRoute('POST', '/ajax/forecast/clients/{id:\d+}', ['controller' => $fc, 'method' => 'client']);

    // [352] Partie i identyfikowalność : Partie surowców (KSeF + przyjęcia), Kontrola wejść produkcji, Próbki (QR, zadanie dnia).
    $lc = App\Controllers\Web\Production\LotControlController::class;
    $r->addRoute('GET', '/workshop/traceability/lots', ['controller' => $lc, 'method' => 'materialLots']);
    $r->addRoute('GET', '/workshop/traceability/check', ['controller' => $lc, 'method' => 'inputCheck']);
    $r->addRoute('GET', '/workshop/traceability/samples', ['controller' => $lc, 'method' => 'samples']);
    $r->addRoute('GET', '/workshop/traceability/samples/pdf', ['controller' => $lc, 'method' => 'samplesPdf']);
    $r->addRoute('GET', '/workshop/traceability/samples/s/{code:[A-Za-z0-9]{12}}', ['controller' => $lc, 'method' => 'sampleScan']);
    $r->addRoute('PUT', '/ajax/production/material-lots/{id:\d+}', ['controller' => $lc, 'method' => 'updateLot']);
    $r->addRoute('PUT', '/ajax/production/material-lots/material/{id:\d+}', ['controller' => $lc, 'method' => 'trackMaterial']);
    $r->addRoute('GET', '/ajax/production/input-check/{id:\d+}', ['controller' => $lc, 'method' => 'batch']);
    $r->addRoute('POST', '/ajax/production/samples', ['controller' => $lc, 'method' => 'createSamples']);
    $r->addRoute('DELETE', '/ajax/production/samples/{id:\d+}', ['controller' => $lc, 'method' => 'removeSample']);
    $r->addRoute('POST', '/ajax/production/samples/store', ['controller' => $lc, 'method' => 'storeSample']);
    $r->addRoute('POST', '/ajax/production/samples/done', ['controller' => $lc, 'method' => 'finishSamples']);
    $r->addRoute('PUT', '/ajax/production/sample-rules/{category:\d+}', ['controller' => $lc, 'method' => 'sampleRule']);

    // [353] Procedury SOP : /sop (lista, asystent, zatwierdzenie — prawo « sop ») ; /procedure (procedura asystowana, każdy).
    $sop = App\Controllers\Web\Production\SopController::class;
    $r->addRoute('GET', '/sop', ['controller' => $sop, 'method' => 'index']);
    $r->addRoute('GET', '/sop/new', ['controller' => $sop, 'method' => 'create']);
    $r->addRoute('GET', '/sop/{id:\d+}/edit', ['controller' => $sop, 'method' => 'edit']);
    $r->addRoute('POST', '/ajax/sop', ['controller' => $sop, 'method' => 'save']);
    $r->addRoute('PUT', '/ajax/sop/{id:\d+}', ['controller' => $sop, 'method' => 'save']);
    $r->addRoute('POST', '/ajax/sop/{id:\d+}/approve', ['controller' => $sop, 'method' => 'approve']);
    $r->addRoute('POST', '/ajax/sop/{id:\d+}/archive', ['controller' => $sop, 'method' => 'archive']);
    $r->addRoute('POST', '/ajax/sop/photos', ['controller' => $sop, 'method' => 'upload']);
    $r->addRoute('GET', '/procedure/{id:\d+}', ['controller' => $sop, 'method' => 'show']);
    $r->addRoute('GET', '/procedure/{id:\d+}/pdf', ['controller' => $sop, 'method' => 'pdf']);
    $r->addRoute('GET', '/procedure/photo/{id:\d+}', ['controller' => $sop, 'method' => 'photo']);
    $r->addRoute('POST', '/ajax/procedure/{id:\d+}/runs', ['controller' => $sop, 'method' => 'run']);
};
