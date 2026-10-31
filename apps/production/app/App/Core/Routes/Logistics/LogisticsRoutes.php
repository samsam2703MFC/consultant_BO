<?php
use FastRoute\RouteCollector;

return function(RouteCollector $r) {
    $c = App\Controllers\Web\Logistics\LogisticsController::class;

    $r->addRoute('GET', '/logistics', ['controller' => $c, 'method' => 'day']);
    $r->addRoute('GET', '/logistics/week', ['controller' => $c, 'method' => 'week']);
    // Plan logistyki na 12 tygodni [200] : prognoza palet, ciężarówki (przeciągnij), święta PL / BE, urlopy kierowców.
    $pl = App\Controllers\Web\Logistics\LogisticsPlanController::class;
    $r->addRoute('GET', '/logistics/plan', ['controller' => $pl, 'method' => 'index']);
    $r->addRoute('GET', '/ajax/logistics/plan', ['controller' => $pl, 'method' => 'data']);
    $r->addRoute('PUT', '/ajax/logistics/plan/loads', ['controller' => $pl, 'method' => 'saveLoads']);
    $r->addRoute('PUT', '/ajax/logistics/plan/weeks/{week:\d{4}-\d{2}-\d{2}}', ['controller' => $pl, 'method' => 'saveWeek']);
    $r->addRoute('PUT', '/ajax/logistics/plan/weeks/{week:\d{4}-\d{2}-\d{2}}/vehicles/{id:\d+}', ['controller' => $pl, 'method' => 'saveVehicleWeek']);
    $r->addRoute('POST', '/ajax/logistics/plan/weeks/{week:\d{4}-\d{2}-\d{2}}/copy', ['controller' => $pl, 'method' => 'copyWeek']);
    $r->addRoute('PUT', '/ajax/logistics/plan/coef', ['controller' => $pl, 'method' => 'saveCoef']);
    $r->addRoute('PUT', '/ajax/logistics/plan/events', ['controller' => $pl, 'method' => 'saveEvent']);
    $r->addRoute('DELETE', '/ajax/logistics/plan/events/{id:\d+}', ['controller' => $pl, 'method' => 'deleteEvent']);
    $r->addRoute('POST', '/ajax/logistics/plan/absences', ['controller' => $pl, 'method' => 'addAbsence']);
    $r->addRoute('DELETE', '/ajax/logistics/plan/absences/{id:\d+}', ['controller' => $pl, 'method' => 'deleteAbsence']);
    $r->addRoute('PUT', '/ajax/logistics/plan/holidays', ['controller' => $pl, 'method' => 'saveHoliday']);
    $r->addRoute('POST', '/ajax/logistics/plan/auto', ['controller' => $pl, 'method' => 'autoDispatch']);
    $r->addRoute('POST', '/ajax/logistics/plan/reset', ['controller' => $pl, 'method' => 'resetLoads']);
    // Kalendarz tras [207] : trasa ciężarówki (fazy, przerwy, odpoczynki), szablon trasy.
    $r->addRoute('PUT', '/ajax/logistics/plan/trips/{week:\d{4}-\d{2}-\d{2}}/{id:\d+}', ['controller' => $pl, 'method' => 'saveTrip']);
    $r->addRoute('DELETE', '/ajax/logistics/plan/trips/{week:\d{4}-\d{2}-\d{2}}/{id:\d+}', ['controller' => $pl, 'method' => 'resetTrip']);
    // Kilka tras w tygodniu [208] : numer trasy ; liczba tras ciężarówki z kierowcą (standard 1).
    $r->addRoute('PUT', '/ajax/logistics/plan/trips/{week:\d{4}-\d{2}-\d{2}}/{id:\d+}/{no:[1-4]}', ['controller' => $pl, 'method' => 'saveTrip']);
    $r->addRoute('DELETE', '/ajax/logistics/plan/trips/{week:\d{4}-\d{2}-\d{2}}/{id:\d+}/{no:[1-4]}', ['controller' => $pl, 'method' => 'resetTrip']);
    $r->addRoute('PUT', '/ajax/logistics/plan/vehicles/{id:\d+}/trips', ['controller' => $pl, 'method' => 'setVehicleTrips']);
    $r->addRoute('POST', '/ajax/logistics/plan/trips/{week:\d{4}-\d{2}-\d{2}}/rest', ['controller' => $pl, 'method' => 'tripRest']);
    $r->addRoute('PUT', '/ajax/logistics/plan/trip-template', ['controller' => $pl, 'method' => 'saveTripTemplate']);
    $r->addRoute('POST', '/ajax/logistics/plan/approve', ['controller' => $pl, 'method' => 'approve']);
    $r->addRoute('GET', '/logistics/vehicles', ['controller' => $c, 'method' => 'vehicles']);
    $r->addRoute('GET', '/logistics/tours', ['controller' => $c, 'method' => 'tours']);
    $r->addRoute('GET', '/logistics/loading-list', ['controller' => $c, 'method' => 'loadingList']);
    // Logistyka i pakowanie (28/10/2026) : paletyzacja na żywo, załadunek ciężarówki → tablet kierowcy ; pakowanie to osobny
    // tablet (/tablet/packing, sparowany jak inne tablety) — dawny adres przekierowany (dzień zachowany).
    $b = App\Controllers\Web\Logistics\LogisticsBoardController::class;
    $r->addRoute('GET', '/logistics/live', ['controller' => $b, 'method' => 'live']);
    $r->addRoute('GET', '/logistics/packing', ['redirect' => '/tablet/packing']);
    $r->addRoute('GET', '/logistics/loading', ['controller' => $b, 'method' => 'loading']);
    $r->addRoute('GET', '/ajax/logistics/live', ['controller' => $b, 'method' => 'liveData']);
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
