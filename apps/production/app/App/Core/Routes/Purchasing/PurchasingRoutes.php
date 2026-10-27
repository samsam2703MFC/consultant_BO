<?php
use FastRoute\RouteCollector;

return function (RouteCollector $r) {
    $c = App\Controllers\Web\Purchasing\PurchasingController::class;

    $r->addRoute('GET', '/purchasing', ['controller' => $c, 'method' => 'overview']);
    $r->addRoute('GET', '/purchasing/materials/{id:\d+}', ['controller' => $c, 'method' => 'material']);
    $r->addRoute('GET', '/ajax/purchasing/materials/{id:\d+}/simulate', ['controller' => $c, 'method' => 'simulate']);

    $r->addRoute('GET', '/purchasing/offers', ['controller' => $c, 'method' => 'offers']);
    $r->addRoute('POST', '/ajax/purchasing/offers', ['controller' => $c, 'method' => 'addOffer']);
    $r->addRoute('POST', '/ajax/purchasing/offers/{id:\d+}/apply', ['controller' => $c, 'method' => 'applyOffer']);
    $r->addRoute('POST', '/ajax/purchasing/offers/{id:\d+}/reject', ['controller' => $c, 'method' => 'rejectOffer']);

    $r->addRoute('GET', '/purchasing/tenders', ['controller' => $c, 'method' => 'tenders']);
    $r->addRoute('POST', '/ajax/purchasing/tenders', ['controller' => $c, 'method' => 'createTender']);
    $r->addRoute('GET', '/purchasing/tenders/{id:\d+}', ['controller' => $c, 'method' => 'tender']);
    $r->addRoute('GET', '/ajax/purchasing/tenders/{id:\d+}/preview', ['controller' => $c, 'method' => 'previewTender']);
    $r->addRoute('POST', '/ajax/purchasing/tenders/{id:\d+}/send', ['controller' => $c, 'method' => 'sendTender']);
    $r->addRoute('POST', '/ajax/purchasing/tenders/{id:\d+}/replies', ['controller' => $c, 'method' => 'recordReply']);
    $r->addRoute('POST', '/ajax/purchasing/tenders/{id:\d+}/award', ['controller' => $c, 'method' => 'awardTender']);

    $r->addRoute('GET', '/purchasing/nc', ['controller' => $c, 'method' => 'nc']);
    $r->addRoute('POST', '/ajax/purchasing/nc', ['controller' => $c, 'method' => 'saveNc']);
    $r->addRoute('PATCH', '/ajax/purchasing/nc/{id:\d+}', ['controller' => $c, 'method' => 'saveNc']);
};
