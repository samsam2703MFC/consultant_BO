<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/shipping-documents/create', [
        'controller' => App\Controllers\Web\Shipping\DocumentController::class,
        'method' => 'showShippingForm'
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
