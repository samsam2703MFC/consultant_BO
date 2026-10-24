<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // Koszty stałe działów (amortyzacja, media stałe) edytuje się w kalkulatorze kosztów
    $r->addRoute('GET', '/company-costs', ['redirect' => '/cost-calculator#dzialy']);

};
