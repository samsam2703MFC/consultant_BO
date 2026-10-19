<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/company-costs', [
        'controller' => App\Controllers\Web\Company\CompanyCostController::class,
        'method'     => 'getSectionsOverview'
    ]);


    $r->addRoute('PATCH', '/ajax/company-costs/{id:\d+}', [
        'controller' => App\Controllers\Web\Company\CompanyCostController::class,
        'method'     => 'ajaxUpdateCost'
    ]);

};
