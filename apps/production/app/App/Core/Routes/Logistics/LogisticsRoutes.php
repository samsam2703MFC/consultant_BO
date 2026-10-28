<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $c = App\Controllers\Web\Logistics\LogisticsController::class;

    $r->addRoute('GET', '/logistics', ['controller' => $c, 'method' => 'day']);
    $r->addRoute('GET', '/logistics/week', ['controller' => $c, 'method' => 'week']);
    $r->addRoute('GET', '/logistics/vehicles', ['controller' => $c, 'method' => 'vehicles']);
    $r->addRoute('GET', '/logistics/tours', ['controller' => $c, 'method' => 'tours']);
    $r->addRoute('GET', '/logistics/loading-list', ['controller' => $c, 'method' => 'loadingList']);
    // Logistyka i pakowanie (28/10/2026) : paletyzacja na żywo, pakowanie, załadunek ciężarówki → tablet kierowcy.
    $b = App\Controllers\Web\Logistics\LogisticsBoardController::class;
    $r->addRoute('GET', '/logistics/live', ['controller' => $b, 'method' => 'live']);
    $r->addRoute('GET', '/logistics/packing', ['controller' => $b, 'method' => 'packing']);
    $r->addRoute('GET', '/logistics/loading', ['controller' => $b, 'method' => 'loading']);
    $r->addRoute('GET', '/ajax/logistics/live', ['controller' => $b, 'method' => 'liveData']);
    $r->addRoute('GET', '/ajax/logistics/packing', ['controller' => $b, 'method' => 'packingData']);
    $r->addRoute('PUT', '/ajax/logistics/packing/{id:\d+}', ['controller' => $b, 'method' => 'setPacked']);
    $r->addRoute('GET', '/ajax/logistics/loading', ['controller' => $b, 'method' => 'loadingData']);
    $r->addRoute('PUT', '/ajax/logistics/loading', ['controller' => $b, 'method' => 'saveLoading']);
    $r->addRoute('POST', '/ajax/logistics/loading/send', ['controller' => $b, 'method' => 'sendLoading']);
    $d = App\Controllers\Web\Logistics\DeliveryDocumentsController::class;
    $r->addRoute('GET', '/logistics/documents', ['controller' => $d, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/logistics/documents/day.pdf', ['controller' => $d, 'method' => 'dayPdf']);
    // Dostawy : potwierdzenia z tabletu kierowcy (odbiorca, podpis, zdjęcie, palety), PDF potwierdzenia.
    $p = App\Controllers\Web\Logistics\DeliveryProofController::class;
    $r->addRoute('GET', '/logistics/deliveries', ['controller' => $p, 'method' => 'index']);
    $r->addRoute('GET', '/logistics/deliveries/{id:\d+}/proof.pdf', ['controller' => $p, 'method' => 'proof']);
    $r->addRoute('GET', '/logistics/deliveries/media/{id:\d+}', ['controller' => $p, 'method' => 'media']);
    $r->addRoute('GET', '/logistics/deliveries/report.pdf', ['controller' => $p, 'method' => 'report']);
    $n = App\Controllers\Web\Logistics\DeliveryNoticeController::class;
    $r->addRoute('GET', '/logistics/notices', ['controller' => $n, 'method' => 'index']);
    $r->addRoute('POST', '/ajax/logistics/notices/preview', ['controller' => $n, 'method' => 'preview']);
    $r->addRoute('POST', '/ajax/logistics/notices/send', ['controller' => $n, 'method' => 'send']);
    $r->addRoute('PUT', '/ajax/logistics/assignments', ['controller' => $c, 'method' => 'assign']);
    $r->addRoute('POST', '/ajax/logistics/vehicles', ['controller' => $c, 'method' => 'addVehicle']);
    $r->addRoute('PUT', '/ajax/logistics/vehicles/{id:\d+}', ['controller' => $c, 'method' => 'saveVehicle']);
    $r->addRoute('POST', '/ajax/logistics/tours', ['controller' => $c, 'method' => 'createTour']);
    $r->addRoute('PUT', '/ajax/logistics/tours/{id:\d+}', ['controller' => $c, 'method' => 'updateTour']);
    $r->addRoute('DELETE', '/ajax/logistics/tours/{id:\d+}', ['controller' => $c, 'method' => 'deleteTour']);
    $r->addRoute('PUT', '/ajax/logistics/tours/{id:\d+}/vehicles', ['controller' => $c, 'method' => 'setTourVehicles']);
    $r->addRoute('PUT', '/ajax/logistics/tours/{id:\d+}/customers', ['controller' => $c, 'method' => 'setTourCustomers']);
    $r->addRoute('PATCH', '/ajax/logistics/products/{id:\d+}/pallet', ['controller' => $c, 'method' => 'savePalletData']);
};
