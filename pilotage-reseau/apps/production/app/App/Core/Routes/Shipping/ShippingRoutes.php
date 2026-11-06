<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Dawny formularz « WZ + CMR » (jeden klient, zwroty) : kreator robi to samo, zwroty w osobnej karcie
    $r->addRoute('GET', '/shipping-documents/create', ['redirect' => '/shipping/wizard']);

    // Kreator „Wyślij dzień” (dokumenty tworzy POST /ajax/shipments poniżej)
    $r->addRoute('GET', '/shipping/wizard', [
        'controller' => App\Controllers\Web\Shipping\ShippingWizardController::class,
        'method' => 'index'
    ]);

    $r->addRoute('GET', '/ajax/shipments/planned-vehicles', [
        'controller' => App\Controllers\Web\Shipping\ShippingWizardController::class,
        'method'     => 'ajaxPlannedVehicles'
    ]);

    $r->addRoute('GET', '/ajax/shipments/last-carrier', [
        'controller' => App\Controllers\Web\Shipping\ShippingWizardController::class,
        'method' => 'ajaxLastCarrier'
    ]);

    $r->addRoute('POST', '/ajax/shipments', [
        'controller' => App\Controllers\Web\Shipping\ShippingController::class,
        'method' => 'ajaxNewShipping'
    ]);

    $r->addRoute('GET', '/ajax/shipments/clients', [
        'controller' => App\Controllers\Web\Shipping\ShippingController::class,
        'method' => 'ajaxGetClients'
    ]);

    $r->addRoute('GET', '/ajax/shipments/pending-products', [
        'controller' => App\Controllers\Web\Shipping\ShippingController::class,
        'method' => 'ajaxGetPendingProducts'
    ]);

    $r->addRoute('GET', '/ajax/complaints/clients', [
        'controller' => App\Controllers\Web\Shipping\ShippingController::class,
        'method' => 'ajaxGetComplaintCustomersByDate'
    ]);


    $r->addRoute('GET', '/shipments', [
        'controller' => App\Controllers\Web\Shipping\ShippingLogController::class,
        'method' => 'overview'
    ]);

    // Różnice i problemy dostaw : kolejka spraw do rozliczenia z klientem (odpowiedzialny, decyzja, termin)
    $issues = App\Controllers\Web\Shipping\ShippingIssueController::class;
    $r->addRoute('GET', '/shipments/differences', ['controller' => $issues, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/shipments/differences/{id:\d+}/lines', ['controller' => $issues, 'method' => 'lines']);
    $r->addRoute('PUT', '/ajax/shipments/differences/{id:\d+}/owner', ['controller' => $issues, 'method' => 'owner']);
    $r->addRoute('PUT', '/ajax/shipments/differences/{id:\d+}/decision', ['controller' => $issues, 'method' => 'decision']);
    $r->addRoute('POST', '/ajax/shipments/differences/{id:\d+}/close', ['controller' => $issues, 'method' => 'close']);
    $r->addRoute('POST', '/ajax/shipments/differences/{id:\d+}/reopen', ['controller' => $issues, 'method' => 'reopen']);
    $r->addRoute('POST', '/ajax/shipments/differences/{id:\d+}/nc', ['controller' => $issues, 'method' => 'nc']);
    $r->addRoute('POST', '/ajax/shipments/{id:\d+}/ksef/send', ['controller'=>App\Controllers\Web\Shipping\KsefSubmissionController::class,'method'=>'send']);
    $r->addRoute('POST', '/ajax/shipments/ksef/refresh-statuses', ['controller'=>App\Controllers\Web\Shipping\KsefSubmissionController::class,'method'=>'refreshStatuses']);
    $r->addRoute('GET', '/ajax/shipments/{id:\d+}/ksef/upo', ['controller'=>App\Controllers\Web\Shipping\KsefSubmissionController::class,'method'=>'upo']);

    $r->addRoute('GET', '/shipping-transport-documents', [
        'controller' => App\Controllers\Web\Shipping\ShippingLogController::class,
        'method' => 'transportDocuments'
    ]);

    $r->addRoute('GET', '/archive/documents/shipping/{type:wz|cmr|wdt|difference}', [
        'controller' => App\Controllers\Web\Shipping\ShippingLogController::class,
        'method' => 'archiveByType'
    ]);


    $r->addRoute('POST', '/ajax/complaints', [
        'controller' => App\Controllers\Web\Shipping\ShippingController::class,
        'method' => 'ajaxNewComplaint'
    ]);
};
