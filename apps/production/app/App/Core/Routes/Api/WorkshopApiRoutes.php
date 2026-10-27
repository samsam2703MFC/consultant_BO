<?php
declare(strict_types=1);

namespace App\Core\Routes\Api;

use App\Controllers\Api\Workshop\AuthController;
use App\Controllers\Api\Workshop\DataSheetController;
use App\Controllers\Api\Workshop\DeliveryController;
use App\Controllers\Api\Workshop\DriverController;
use App\Controllers\Api\Workshop\EmployeeController;
use App\Controllers\Api\Workshop\ExecutionController;
use App\Controllers\Api\Workshop\GroupController;
use App\Controllers\Api\Workshop\ItemController;
use App\Controllers\Api\Workshop\ManagerController;
use App\Controllers\Api\Workshop\OpenApiController;
use App\Controllers\Api\Workshop\ParamController;
use App\Controllers\Api\Workshop\PlanningController;
use App\Controllers\Api\Workshop\SchedulingController;
use App\Controllers\Api\Workshop\ShiftTemplateController;
use App\Controllers\Api\Workshop\TeamController;
use App\Controllers\Api\Workshop\InvoiceController;
use App\Controllers\Api\Workshop\SupplyController;
use App\Controllers\Api\Workshop\TimeStudyController;
use App\Controllers\Api\Workshop\WeekController;
use App\Controllers\Api\Workshop\WorkstationController;

/**
 * Routes de l'API du module atelier, sous {API_BASE_URL}/api/v1/production.
 * permission : null = public, 'auth' = toute personne connectée, sinon la permission exigée.
 */
final class WorkshopApiRoutes
{
    public const PREFIX = '/api/v1/production';

    /** @return list<array{0:string,1:string,2:class-string,3:string,4:?string}> */
    public static function routes(): array
    {
        $read = 'prod.referentiels.read';
        $write = 'prod.referentiels.write';
        return [
            ['GET', '/openapi.json', OpenApiController::class, 'spec', null],
            ['POST', '/auth/login', AuthController::class, 'login', null],
            ['POST', '/auth/refresh', AuthController::class, 'refresh', null],
            ['POST', '/auth/logout', AuthController::class, 'logout', null],
            ['GET', '/auth/me', AuthController::class, 'me', 'auth'],

            ['GET', '/referentiels/groups', GroupController::class, 'list', $read],
            ['POST', '/referentiels/groups', GroupController::class, 'create', $write],
            ['GET', '/referentiels/groups/{id:\d+}', GroupController::class, 'get', $read],
            ['PATCH', '/referentiels/groups/{id:\d+}', GroupController::class, 'update', $write],

            ['GET', '/referentiels/workstations', WorkstationController::class, 'list', $read],
            ['POST', '/referentiels/workstations', WorkstationController::class, 'create', $write],
            ['GET', '/referentiels/workstations/{id:\d+}', WorkstationController::class, 'get', $read],
            ['PATCH', '/referentiels/workstations/{id:\d+}', WorkstationController::class, 'update', $write],

            ['GET', '/referentiels/shift-templates', ShiftTemplateController::class, 'list', $read],
            ['POST', '/referentiels/shift-templates', ShiftTemplateController::class, 'create', $write],
            ['GET', '/referentiels/shift-templates/{id:\d+}', ShiftTemplateController::class, 'get', $read],
            ['PATCH', '/referentiels/shift-templates/{id:\d+}', ShiftTemplateController::class, 'update', $write],

            ['GET', '/referentiels/teams', TeamController::class, 'list', $read],
            ['POST', '/referentiels/teams', TeamController::class, 'create', $write],
            ['GET', '/referentiels/teams/{id:\d+}', TeamController::class, 'get', $read],
            ['PATCH', '/referentiels/teams/{id:\d+}', TeamController::class, 'update', $write],
            ['GET', '/referentiels/teams/{id:\d+}/members', TeamController::class, 'members', $read],
            ['PUT', '/referentiels/teams/{id:\d+}/members', TeamController::class, 'replaceMembers', $write],

            ['GET', '/referentiels/employees', EmployeeController::class, 'list', $read],
            ['GET', '/referentiels/employees/{id:\d+}', EmployeeController::class, 'get', $read],
            ['PATCH', '/referentiels/employees/{id:\d+}', EmployeeController::class, 'update', 'prod.employees.write'],

            ['GET', '/referentiels/params', ParamController::class, 'list', $read],
            ['GET', '/referentiels/params/{code:[a-z0-9_.]+}', ParamController::class, 'get', $read],
            ['PATCH', '/referentiels/params/{code:[a-z0-9_.]+}', ParamController::class, 'update', 'prod.params.write'],

            // Tableau à remplir : données réelles de l'atelier (postes, shifts, équipes, tournées, paramètres).
            ['GET', '/referentiels/data-sheet', DataSheetController::class, 'export', $read],
            ['POST', '/referentiels/data-sheet/preview', DataSheetController::class, 'preview', $write],
            ['POST', '/referentiels/data-sheet/apply', DataSheetController::class, 'apply', $write],

            ['GET', '/produits/items', ItemController::class, 'list', 'prod.products.read'],
            ['POST', '/produits/items', ItemController::class, 'create', 'prod.products.write'],
            ['GET', '/produits/candidates', ItemController::class, 'candidates', 'prod.products.read'],
            ['GET', '/produits/erp-options', ItemController::class, 'erpOptions', 'prod.products.read'],
            ['GET', '/produits/stock-overview', ItemController::class, 'stockOverview', 'prod.products.read'],
            ['GET', '/produits/stock-suggestions', ItemController::class, 'stockSuggestions', 'prod.products.read'],
            ['POST', '/produits/stock-suggestions/apply', ItemController::class, 'applyStockSuggestions', 'prod.products.write'],
            ['GET', '/produits/items/{id:\d+}', ItemController::class, 'get', 'prod.products.read'],
            ['PATCH', '/produits/items/{id:\d+}/settings', ItemController::class, 'updateSettings', 'prod.products.write'],
            ['PUT', '/produits/items/{id:\d+}/product', ItemController::class, 'updateProduct', 'prod.products.write'],
            ['PUT', '/produits/items/{id:\d+}/logistics', ItemController::class, 'updateLogistics', 'prod.products.write'],
            ['GET', '/produits/items/{id:\d+}/production-summary', ItemController::class, 'productionSummary', 'prod.products.read'],
            ['GET', '/produits/items/{id:\d+}/stock-suggestion', ItemController::class, 'stockSuggestion', 'prod.products.read'],
            ['PUT', '/produits/items/{id:\d+}/routing', ItemController::class, 'replaceRouting', 'prod.products.write'],
            ['PUT', '/produits/items/{id:\d+}/bom-losses', ItemController::class, 'saveBomLosses', 'prod.products.write'],
            ['POST', '/produits/items/{id:\d+}/validate', ItemController::class, 'validate', 'prod.products.write'],
            ['GET', '/produits/items/{id:\d+}/duration', ItemController::class, 'duration', 'prod.products.read'],

            ['GET', '/produits/routing-steps/{id:\d+}/time-studies', TimeStudyController::class, 'list', 'prod.products.read'],
            ['POST', '/produits/routing-steps/{id:\d+}/time-studies', TimeStudyController::class, 'record', 'prod.execution.write'],
            ['PATCH', '/produits/time-studies/{id:\d+}', TimeStudyController::class, 'setExcluded', 'prod.execution.write'],
            ['POST', '/produits/routing-steps/{id:\d+}/apply-standard', TimeStudyController::class, 'applyStandard', 'prod.products.write'],

            ['GET', '/planning/demand', PlanningController::class, 'demand', 'prod.plan.read'],
            ['GET', '/planning/plans', PlanningController::class, 'list', 'prod.plan.read'],
            ['POST', '/planning/plans', PlanningController::class, 'create', 'prod.plan.validate'],
            ['GET', '/planning/plans/{id:\d+}', PlanningController::class, 'get', 'prod.plan.read'],
            ['PATCH', '/planning/plans/{id:\d+}/lines/{lineId:\d+}', PlanningController::class, 'adjustLine', 'prod.plan.validate'],
            ['POST', '/planning/plans/{id:\d+}/validate', PlanningController::class, 'validate', 'prod.plan.validate'],
            ['POST', '/planning/plans/{id:\d+}/schedule', SchedulingController::class, 'schedule', 'prod.plan.validate'],
            ['GET', '/planning/operations', SchedulingController::class, 'operations', 'prod.plan.read'],
            ['PATCH', '/planning/operations/{id:\d+}', SchedulingController::class, 'updateOperation', 'prod.gantt.move'],
            ['GET', '/planning/load', SchedulingController::class, 'load', 'prod.plan.read'],
            ['GET', '/planning/optimizer', SchedulingController::class, 'optimizer', 'prod.plan.read'],
            ['POST', '/planning/optimizer/apply', SchedulingController::class, 'applyOptimizer', 'prod.plan.validate'],
            ['POST', '/planning/simulate', SchedulingController::class, 'simulate', 'prod.plan.read'],
            ['PUT', '/planning/staffing', SchedulingController::class, 'saveStaffing', 'prod.plan.validate'],
            ['GET', '/planning/week', WeekController::class, 'get', 'prod.plan.read'],
            ['GET', '/planning/sheet', WeekController::class, 'sheet', 'prod.plan.read'],
            ['GET', '/planning/team-sheet', WeekController::class, 'teamSheet', 'prod.plan.read'],
            ['GET', '/planning/team-sheet/pdf', WeekController::class, 'teamSheetPdf', 'prod.plan.read'],
            ['GET', '/planning/team-sheet/day', WeekController::class, 'teamDay', 'prod.plan.read'],
            ['GET', '/planning/team-sheet/day/pdf', WeekController::class, 'teamDayPdf', 'prod.plan.read'],
            // Kierownik produkcji : karta stanowiska, cele, raport miesięczny, premia, rozmowa, audyt kompetencji.
            ['GET', '/manager/plan', ManagerController::class, 'plan', 'prod.manager.read'],
            ['PUT', '/manager/plan', ManagerController::class, 'savePlan', 'prod.manager.write'],
            ['PUT', '/manager/objectives', ManagerController::class, 'saveObjectives', 'prod.manager.write'],
            ['POST', '/manager/objectives/validate', ManagerController::class, 'validateObjectives', 'prod.manager.write'],
            ['GET', '/manager/report', ManagerController::class, 'report', 'prod.manager.read'],
            ['PUT', '/manager/values', ManagerController::class, 'saveValue', 'prod.manager.report'],
            ['POST', '/manager/haccp', ManagerController::class, 'addHaccp', 'prod.manager.report'],
            ['PATCH', '/manager/haccp/{id:\d+}', ManagerController::class, 'updateHaccp', 'prod.manager.report'],
            ['DELETE', '/manager/haccp/{id:\d+}', ManagerController::class, 'deleteHaccp', 'prod.manager.write'],
            ['PUT', '/manager/launches/{id:\d+}', ManagerController::class, 'saveLaunch', 'prod.manager.report'],
            ['PUT', '/manager/actions', ManagerController::class, 'saveActions', 'prod.manager.report'],
            ['GET', '/manager/bonus', ManagerController::class, 'bonus', 'prod.manager.read'],
            ['GET', '/manager/interview', ManagerController::class, 'interview', 'prod.manager.read'],
            ['GET', '/manager/audits', ManagerController::class, 'audits', 'prod.manager.read'],
            ['POST', '/manager/audits', ManagerController::class, 'addAudit', 'prod.manager.report'],
            // Dostawy surowców (Produkcja) : kto dostarcza co i kiedy — dla planujących produkcję (bez zamawiania).
            ['GET', '/supply/deliveries', DeliveryController::class, 'week', 'prod.plan.read'],
            // Zaopatrzenie : potrzeby z produkcji, surowce (tryb, stany, inwentaryzacja), zamówienia do dostawców, przyjęcia.
            ['GET', '/supply/needs', SupplyController::class, 'needs', 'prod.supply.read'],
            ['GET', '/supply/materials', SupplyController::class, 'materials', 'prod.supply.read'],
            ['PATCH', '/supply/materials/{id:\d+}', SupplyController::class, 'saveMaterial', 'prod.supply.write'],
            ['POST', '/supply/materials/{id:\d+}/count', SupplyController::class, 'addCount', 'prod.supply.write'],
            ['POST', '/supply/materials/counts', SupplyController::class, 'addCounts', 'prod.supply.write'],
            ['POST', '/supply/materials/limits', SupplyController::class, 'suggestLimits', 'prod.supply.write'],
            ['GET', '/supply/orders', SupplyController::class, 'orders', 'prod.supply.read'],
            ['POST', '/supply/orders', SupplyController::class, 'createOrders', 'prod.supply.write'],
            ['GET', '/supply/orders/{id:\d+}', SupplyController::class, 'order', 'prod.supply.read'],
            ['PATCH', '/supply/orders/{id:\d+}', SupplyController::class, 'updateOrder', 'prod.supply.write'],
            ['PUT', '/supply/orders/{id:\d+}/lines', SupplyController::class, 'saveLines', 'prod.supply.write'],
            ['GET', '/supply/orders/{id:\d+}/preview', SupplyController::class, 'preview', 'prod.supply.read'],
            ['POST', '/supply/orders/{id:\d+}/send', SupplyController::class, 'send', 'prod.supply.write'],
            ['POST', '/supply/orders/{id:\d+}/receive', SupplyController::class, 'receive', 'prod.supply.write'],
            ['POST', '/supply/orders/{id:\d+}/cancel', SupplyController::class, 'cancel', 'prod.supply.write'],
            ['DELETE', '/supply/orders/{id:\d+}', SupplyController::class, 'delete', 'prod.supply.write'],
            // Kontrola faktur zakupowych : KSeF / XML / PDF, przypisania nazw dostawcy, porównanie z zamówieniem.
            ['GET', '/supply/invoices', InvoiceController::class, 'list', 'prod.supply.read'],
            ['POST', '/supply/invoices/ksef/search', InvoiceController::class, 'ksefSearch', 'prod.supply.read'],
            ['POST', '/supply/invoices/ksef/import', InvoiceController::class, 'ksefImport', 'prod.supply.write'],
            ['POST', '/supply/invoices/ksef/sync', InvoiceController::class, 'ksefSync', 'prod.supply.write'],
            ['POST', '/supply/invoices/process-ready', InvoiceController::class, 'processReady', 'prod.supply.write'],
            ['POST', '/supply/invoices/upload', InvoiceController::class, 'upload', 'prod.supply.write'],
            ['GET', '/supply/invoices/{id:\d+}', InvoiceController::class, 'detail', 'prod.supply.read'],
            ['GET', '/supply/invoices/{id:\d+}/file', InvoiceController::class, 'file', 'prod.supply.read'],
            ['PATCH', '/supply/invoices/{id:\d+}', InvoiceController::class, 'update', 'prod.supply.write'],
            ['PUT', '/supply/invoices/{id:\d+}/lines', InvoiceController::class, 'saveLines', 'prod.supply.write'],
            ['PUT', '/supply/invoices/{id:\d+}/lines/{lineId:\d+}/map', InvoiceController::class, 'mapLine', 'prod.supply.write'],
            ['PUT', '/supply/invoices/{id:\d+}/map', InvoiceController::class, 'mapLines', 'prod.supply.write'],
            ['POST', '/supply/invoices/{id:\d+}/process', InvoiceController::class, 'process', 'prod.supply.write'],
            ['POST', '/supply/invoices/{id:\d+}/decision', InvoiceController::class, 'decide', 'prod.supply.write'],
            ['POST', '/supply/invoices/{id:\d+}/receive', InvoiceController::class, 'receive', 'prod.supply.write'],
            ['POST', '/supply/invoices/{id:\d+}/prices', InvoiceController::class, 'importPrices', 'prod.supply.write'],
            ['POST', '/supply/invoices/{id:\d+}/price-decisions', InvoiceController::class, 'decidePrices', 'prod.supply.write'],
            ['DELETE', '/supply/invoices/{id:\d+}', InvoiceController::class, 'delete', 'prod.supply.write'],
            ['GET', '/supply/mappings', InvoiceController::class, 'mappings', 'prod.supply.read'],
            ['DELETE', '/supply/mappings/{id:\d+}', InvoiceController::class, 'deleteMapping', 'prod.supply.write'],
            ['GET', '/supply/prices', InvoiceController::class, 'prices', 'prod.supply.read'],
            ['GET', '/supply/materials/{id:\d+}/prices', InvoiceController::class, 'materialPrices', 'prod.supply.read'],
            // Jednostka surowca z formularza przypisania faktury (kg / g / szt / l / ml) — od razu na karcie surowca.
            ['PATCH', '/supply/materials/{id:\d+}/unit', InvoiceController::class, 'changeMaterialUnit', 'prod.supply.write'],
            ['POST', '/planning/week/move', WeekController::class, 'move', 'prod.gantt.move'],
            ['POST', '/planning/week/distribute', WeekController::class, 'distribute', 'prod.gantt.move'],
            ['POST', '/planning/week/pin', WeekController::class, 'pin', 'prod.gantt.move'],
            ['POST', '/planning/week/time', WeekController::class, 'time', 'prod.gantt.move'],
            ['POST', '/planning/week/clear', WeekController::class, 'clear', 'prod.gantt.move'],

            ['GET', '/kiosk/devices', ExecutionController::class, 'devices', 'prod.employees.write'],
            ['GET', '/kiosk/devices/vehicles', ExecutionController::class, 'vehicles', 'prod.employees.write'],
            ['POST', '/kiosk/devices', ExecutionController::class, 'createDevice', 'prod.employees.write'],
            ['PATCH', '/kiosk/devices/{id:\d+}', ExecutionController::class, 'updateDevice', 'prod.employees.write'],
            ['PUT', '/kiosk/employees/{id:\d+}/credentials', ExecutionController::class, 'credentials', 'prod.employees.write'],
            ['GET', '/kiosk/employees', ExecutionController::class, 'employees', 'prod.employees.write'],
            ['GET', '/kiosk/employees/{id:\d+}/timesheet', ExecutionController::class, 'timesheet', 'prod.employees.write'],
            ['PATCH', '/kiosk/clocks/{id:\d+}', ExecutionController::class, 'correctClock', 'prod.employees.write'],
            ['POST', '/kiosk/clocks', ExecutionController::class, 'addClock', 'prod.employees.write'],
            ['GET', '/execution/kpis', ExecutionController::class, 'kpis', 'prod.kpis.read'],
            ['GET', '/execution/cockpit', ExecutionController::class, 'cockpit', 'prod.kpis.read'],
            // Dni z tabletów w ERP : kierownik produkcji poprawia ilości i straty po zatwierdzeniu przez zespół.
            ['GET', '/execution/days', ExecutionController::class, 'days', 'prod.plan.validate'],
            ['GET', '/execution/days/{date:\d{4}-\d{2}-\d{2}}/{group:\d+}', ExecutionController::class, 'dayDetail', 'prod.plan.validate'],
            ['PATCH', '/execution/days/{date:\d{4}-\d{2}-\d{2}}/{group:\d+}/items/{item:\d+}', ExecutionController::class, 'correctLine', 'prod.plan.validate'],
            ['PATCH', '/execution/waste/{id:\d+}', ExecutionController::class, 'cancelWaste', 'prod.plan.validate'],
            ['POST', '/kiosk/pair', ExecutionController::class, 'pair', null],
            ['GET', '/kiosk/device', ExecutionController::class, 'device', 'kiosk'],
            ['GET', '/kiosk/people', ExecutionController::class, 'people', 'kiosk'],
            // Tablette générique (atelier sans groupe) : groupe → personne → PIN ; le groupe touché part en X-Kiosk-Group.
            ['GET', '/kiosk/groups', ExecutionController::class, 'groups', 'kiosk'],
            ['POST', '/kiosk/identify', ExecutionController::class, 'identify', 'kiosk'],
            ['GET', '/kiosk/me', ExecutionController::class, 'me', 'kiosk_employee'],
            ['POST', '/kiosk/refresh', ExecutionController::class, 'refresh', 'kiosk_employee'],
            ['POST', '/kiosk/clock', ExecutionController::class, 'clock', 'kiosk_employee'],
            ['POST', '/kiosk/operations/{id:\d+}/start', ExecutionController::class, 'start', 'kiosk_employee'],
            ['POST', '/kiosk/operations/{id:\d+}/pause', ExecutionController::class, 'pause', 'kiosk_employee'],
            ['POST', '/kiosk/operations/{id:\d+}/finish', ExecutionController::class, 'finish', 'kiosk_employee'],
            ['POST', '/kiosk/signatures', ExecutionController::class, 'sign', 'kiosk_employee'],
            ['GET', '/kiosk/week', ExecutionController::class, 'week', 'kiosk'],
            // Przyjęcie dostawy na tablecie (karta « Dostawy surowców » zespołu) : bez cen.
            ['GET', '/kiosk/deliveries/{id:\d+}', ExecutionController::class, 'delivery', 'kiosk_employee'],
            ['POST', '/kiosk/deliveries/{id:\d+}/receive', ExecutionController::class, 'receiveDelivery', 'kiosk_employee'],
            ['GET', '/kiosk/production', ExecutionController::class, 'production', 'kiosk_employee'],
            ['POST', '/kiosk/production', ExecutionController::class, 'recordOutput', 'kiosk_employee'],
            ['GET', '/kiosk/production/{id:\d+}', ExecutionController::class, 'output', 'kiosk_employee'],
            ['PATCH', '/kiosk/production/{id:\d+}', ExecutionController::class, 'updateOutput', 'kiosk_employee'],
            ['PUT', '/kiosk/production/{id:\d+}/lots/{material:\d+}', ExecutionController::class, 'outputLot', 'kiosk_employee'],
            ['POST', '/kiosk/production/close', ExecutionController::class, 'closeDay', 'kiosk_employee'],
            // Zatwierdzenie linii dnia (plan → zrobione, strata ze zdjęciami na tej samej linii) ; zdjęcia strat od razu.
            ['POST', '/kiosk/production/lines', ExecutionController::class, 'validateLine', 'kiosk_employee'],
            ['POST', '/kiosk/photos', ExecutionController::class, 'uploadPhoto', 'kiosk_employee'],
            // Magazyn grupy : stan, min. / maks., ile wolno jeszcze wyprodukować (formuła Stanu magazynu ERP).
            // Stan magazynu grupy : też na ekranie głównym tabletu (bez PIN) — dane grupy, nie osoby.
            ['GET', '/kiosk/stock', ExecutionController::class, 'stock', 'kiosk'],
            ['POST', '/kiosk/waste', ExecutionController::class, 'recordWaste', 'kiosk_employee'],
            ['PATCH', '/kiosk/waste/{id:\d+}', ExecutionController::class, 'updateWaste', 'kiosk_employee'],
            ['GET', '/kiosk/recipes', ExecutionController::class, 'recipes', 'kiosk_employee'],
            ['GET', '/kiosk/recipes/{id:\d+}', ExecutionController::class, 'recipe', 'kiosk_employee'],
            ['GET', '/kiosk/recipes/components/{id:\d+}', ExecutionController::class, 'component', 'kiosk_employee'],
            ['GET', '/kiosk/machines', ExecutionController::class, 'machines', 'kiosk_employee'],
            ['POST', '/kiosk/machines/{id:\d+}/state', ExecutionController::class, 'machineState', 'kiosk_employee'],
            // Nowe produkty (produkty testowe pokazane grupie) : receptura, film, zdjęcia, notatki.
            ['GET', '/kiosk/development', ExecutionController::class, 'development', 'kiosk_employee'],
            ['GET', '/kiosk/development/summary', ExecutionController::class, 'developmentSummary', 'kiosk'],
            ['GET', '/kiosk/development/{id:\d+}', ExecutionController::class, 'developmentProduct', 'kiosk_employee'],
            ['POST', '/kiosk/development/{id:\d+}/trials', ExecutionController::class, 'developmentTrial', 'kiosk_employee'],
            ['POST', '/kiosk/development/{id:\d+}/recipe', ExecutionController::class, 'developmentRecipe', 'kiosk_employee'],
            ['GET', '/kiosk/quality', ExecutionController::class, 'quality', 'kiosk_employee'],
            ['POST', '/kiosk/quality/batches/{id:\d+}', ExecutionController::class, 'qualityCheck', 'kiosk_employee'],
            ['POST', '/kiosk/quality/reports', ExecutionController::class, 'qualityReport', 'kiosk_employee'],
            ['GET', '/kiosk/qr', ExecutionController::class, 'qr', 'kiosk'],
            // Tablette de bureau (mode office) : section → nom → QR personnel (personne + jour) validé au téléphone.
            ['GET', '/kiosk/office/sections', ExecutionController::class, 'officeSections', 'kiosk'],
            ['POST', '/kiosk/office/qr', ExecutionController::class, 'officeQr', 'kiosk'],
            ['GET', '/kiosk/office/status', ExecutionController::class, 'officeStatus', 'kiosk'],
            // Tablet kierowcy (tablet w trybie « driver ») : trasa dnia, potwierdzenia dostaw, dokumenty — po wybraniu
            // kierowcy z listy i jego PIN-ie (/kiosk/people, /kiosk/identify), jak na tablecie pracowni.
            // Tournées du jour avant l'identification : tournée → chauffeur → PIN (27/10/2026).
            ['GET', '/driver/tours', DriverController::class, 'tours', 'kiosk'],
            ['GET', '/driver/day', DriverController::class, 'day', 'kiosk_employee'],
            ['POST', '/driver/run', DriverController::class, 'run', 'kiosk_employee'],
            ['POST', '/driver/stops', DriverController::class, 'stop', 'kiosk_employee'],
            ['POST', '/driver/photos', DriverController::class, 'photo', 'kiosk_employee'],
            ['GET', '/driver/report', DriverController::class, 'report', 'kiosk_employee'],
            ['GET', '/driver/documents', DriverController::class, 'documents', 'kiosk_employee'],
            ['GET', '/kiosk/phone/people', ExecutionController::class, 'phonePeople', 'qr'],
            ['POST', '/kiosk/phone/enroll', ExecutionController::class, 'enrollPhone', 'qr'],
            ['GET', '/kiosk/phone/me', ExecutionController::class, 'phoneMe', 'phone'],
            ['POST', '/kiosk/phone/clock', ExecutionController::class, 'clock', 'phone'],
            ['POST', '/kiosk/phone/signatures', ExecutionController::class, 'sign', 'phone'],
            ['DELETE', '/kiosk/employees/{id:\d+}/phones', ExecutionController::class, 'revokePhones', 'prod.employees.write'],
            // Pytania przy wejściu / wyjściu (« OK, zatwierdzam »)
            ['GET', '/kiosk/clock-questions', ExecutionController::class, 'clockQuestions', 'prod.employees.write'],
            ['POST', '/kiosk/clock-questions', ExecutionController::class, 'createClockQuestion', 'prod.employees.write'],
            ['PATCH', '/kiosk/clock-questions/{id:\d+}', ExecutionController::class, 'updateClockQuestion', 'prod.employees.write'],
            ['DELETE', '/kiosk/clock-questions/{id:\d+}', ExecutionController::class, 'deleteClockQuestion', 'prod.employees.write'],
        ];
    }

    public static function dispatcher(): \FastRoute\Dispatcher
    {
        return \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
            foreach (self::routes() as [$method, $path, $controller, $action, $permission]) {
                $r->addRoute($method, $path, ['controller' => $controller, 'method' => $action, 'permission' => $permission]);
            }
        });
    }
}
