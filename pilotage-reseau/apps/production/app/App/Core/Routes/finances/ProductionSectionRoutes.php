<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/production-sections/profitability', ['redirect' => '/finances/cost-analysis/section']);

    $r->addRoute('GET', '/production-sections/{idSection:\d+}/profitability/details', ['redirect' => '/finances/cost-analysis/section']);


};
