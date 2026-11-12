<?php
use FastRoute\RouteCollector;

// [277] Finanse › Płace : lista płac miesiąca (paski PDF, plik XML do banku, CSV dla biura rachunkowego), ustawienia płac.
return function (RouteCollector $r) {
    $c = App\Controllers\Web\Payroll\PayrollController::class;

    $r->addRoute('GET', '/payroll', ['controller' => $c, 'method' => 'index']);
    $r->addRoute('GET', '/payroll/settings', ['controller' => $c, 'method' => 'settingsPage']);
    $r->addRoute('GET', '/payroll/payslips', ['controller' => $c, 'method' => 'payslips']);
    $r->addRoute('GET', '/payroll/bank-file', ['controller' => $c, 'method' => 'bankFile']);
    $r->addRoute('GET', '/payroll/csv', ['controller' => $c, 'method' => 'csv']);

    $r->addRoute('GET', '/ajax/payroll/month', ['controller' => $c, 'method' => 'month']);
    $r->addRoute('PUT', '/ajax/payroll/adjust/{id:\d+}', ['controller' => $c, 'method' => 'saveAdjust']);
    $r->addRoute('POST', '/ajax/payroll/approve', ['controller' => $c, 'method' => 'approve']);
    $r->addRoute('POST', '/ajax/payroll/reopen', ['controller' => $c, 'method' => 'reopen']);
    $r->addRoute('GET', '/ajax/payroll/rates', ['controller' => $c, 'method' => 'rates']);
    $r->addRoute('PUT', '/ajax/payroll/rates', ['controller' => $c, 'method' => 'saveRates']);
    $r->addRoute('POST', '/ajax/payroll/rates/copy', ['controller' => $c, 'method' => 'copyRates']);
    $r->addRoute('PUT', '/ajax/payroll/settings', ['controller' => $c, 'method' => 'saveSettings']);
};
