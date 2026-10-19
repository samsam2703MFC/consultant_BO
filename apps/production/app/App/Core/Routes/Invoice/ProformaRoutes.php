<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('POST', '/ajax/orders/{idOrder:\d+}/proforma', [
        'controller' => \App\User\Controllers\Client\OfferController::class,
        'method'     => 'insertProformaFromOrder'
    ]);
};
