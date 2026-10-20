<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $c = App\Controllers\Web\Staff\StaffController::class;

    $r->addRoute('GET', '/staff/budget', ['controller' => $c, 'method' => 'budget']);
    $r->addRoute('GET', '/staff/competencies', ['controller' => $c, 'method' => 'competencies']);
    $r->addRoute('PATCH', '/ajax/staff/employees/{id:\d+}/pay', ['controller' => $c, 'method' => 'savePay']);
    $r->addRoute('PUT', '/ajax/staff/bonus-pct', ['controller' => $c, 'method' => 'saveDefaultPct']);
    $r->addRoute('PATCH', '/ajax/staff/sections/{id:\d+}/bonus-pct', ['controller' => $c, 'method' => 'saveSectionPct']);
    $r->addRoute('PUT', '/ajax/staff/employees/{id:\d+}/competencies/{competencyId:\d+}', ['controller' => $c, 'method' => 'setLevel']);
    $r->addRoute('POST', '/ajax/staff/competencies', ['controller' => $c, 'method' => 'addCompetency']);
    $r->addRoute('PATCH', '/ajax/staff/competencies/{id:\d+}', ['controller' => $c, 'method' => 'renameCompetency']);
    $r->addRoute('DELETE', '/ajax/staff/competencies/{id:\d+}', ['controller' => $c, 'method' => 'deleteCompetency']);
};
