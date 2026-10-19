<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {


    $r->addRoute('GET', '/cost-calculator', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('GET', '/cost-calculator/{ym}', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'show'
    ]);



    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/copy-prev', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxCopyPrev'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/sync-cartons', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxSyncCartons'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/set-manual-cartons', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxSetManualCartons'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/set-carton-source', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxSetCartonSource'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/employees/add', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxAddEmployee'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/employees/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteEmployee'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/employees/{id:\d+}/update-cost', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxUpdateEmployeeCost'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/fixed-costs/add', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxAddFixedCost'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/fixed-costs/{id}/delete', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteFixedCost'
    ]);
    $r->addRoute('GET', '/ajax/cost-calculator/{monthId:\d+}/results', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxGetResults'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/media-costs/add', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxAddMediaCost'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/media-costs/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteMediaCost'
    ]);
    $r->addRoute('DELETE', '/ajax/cost-calculator/{monthId:\d+}/media-costs/{id:\d+}', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteMediaCost'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/media-allocations/upsert', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxUpsertMediaAllocation'
    ]);
    $r->addRoute('DELETE', '/ajax/cost-calculator/{monthId:\d+}/media-allocations/{id:\d+}', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteMediaAllocation'
    ]);
    $r->addRoute('GET', '/ajax/cost-media-types', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxListMediaTypes'
    ]);
    $r->addRoute('POST', '/ajax/cost-media-types/add', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxAddMediaType'
    ]);
    $r->addRoute('POST', '/ajax/cost-media-types/{id:\d+}/update', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxUpdateMediaType'
    ]);
    $r->addRoute('POST', '/ajax/cost-media-types/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteMediaType'
    ]);

    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/logistics/add', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxAddLogisticsCost'
    ]);
    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/logistics/{id:\d+}/delete', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxDeleteLogisticsCost'
    ]);

    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/lock', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxLockMonth'
    ]);

    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/unlock', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxUnlockMonth'
    ]);

    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/wdt-cartons/preview', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxPreviewWdtCartons'
    ]);

    $r->addRoute('POST', '/ajax/cost-calculator/{monthId:\d+}/wdt-cartons/sync', [
        'controller' => App\Controllers\Web\Production\Cost\CostCalculatorController::class,
        'method'     => 'ajaxSyncCartonsFromWdt'
    ]);

};
