<?php

use FastRoute\RouteCollector;

return function(RouteCollector $r) {

$r->addRoute('GET', '/wdt-documents/{id:\d+}/products', [
    'controller' => App\Controllers\Web\Document\WDTController::class,
    'method'     => 'productOverview'
]);

$r->addRoute('GET', '/ajax/wdt-documents/{id:\d+}/document', [
    'controller' => App\Controllers\Web\Document\WDTController::class,
    'method'     => 'ajaxGenerateWDTDocument'
]);

$r->addRoute('PATCH', '/ajax/wdt-documents/{id:\d+}/products/{idProduct:\d+}', [
    'controller' => App\Controllers\Web\Document\WDTController::class,
    'method'     => 'ajaxUpdateProduct'
]);

};
