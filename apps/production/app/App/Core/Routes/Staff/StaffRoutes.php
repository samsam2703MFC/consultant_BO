<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $c = App\Controllers\Web\Staff\StaffController::class;

    $r->addRoute('GET', '/staff/budget', ['controller' => $c, 'method' => 'budget']);
    // « Kto co potrafi » usunięte : stary adres prowadzi do budżetu personelu.
    $r->addRoute('GET', '/staff/competencies', ['redirect' => '/staff/budget']);
    $r->addRoute('PATCH', '/ajax/staff/employees/{id:\d+}/pay', ['controller' => $c, 'method' => 'savePay']);
    $r->addRoute('PUT', '/ajax/staff/bonus-pct', ['controller' => $c, 'method' => 'saveDefaultPct']);
    $r->addRoute('PATCH', '/ajax/staff/sections/{id:\d+}/bonus-pct', ['controller' => $c, 'method' => 'saveSectionPct']);
};
