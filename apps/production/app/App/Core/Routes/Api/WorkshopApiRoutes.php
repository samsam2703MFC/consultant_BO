<?php
declare(strict_types=1);

namespace App\Core\Routes\Api;

use App\Controllers\Api\Workshop\AuthController;
use App\Controllers\Api\Workshop\EmployeeController;
use App\Controllers\Api\Workshop\ExecutionController;
use App\Controllers\Api\Workshop\GroupController;
use App\Controllers\Api\Workshop\ItemController;
use App\Controllers\Api\Workshop\OpenApiController;
use App\Controllers\Api\Workshop\ParamController;
use App\Controllers\Api\Workshop\PlanningController;
use App\Controllers\Api\Workshop\SchedulingController;
use App\Controllers\Api\Workshop\ShiftTemplateController;
use App\Controllers\Api\Workshop\TeamController;
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

            ['GET', '/produits/items', ItemController::class, 'list', 'prod.products.read'],
            ['POST', '/produits/items', ItemController::class, 'create', 'prod.products.write'],
            ['GET', '/produits/candidates', ItemController::class, 'candidates', 'prod.products.read'],
            ['GET', '/produits/items/{id:\d+}', ItemController::class, 'get', 'prod.products.read'],
            ['PATCH', '/produits/items/{id:\d+}/settings', ItemController::class, 'updateSettings', 'prod.products.write'],
            ['PUT', '/produits/items/{id:\d+}/logistics', ItemController::class, 'updateLogistics', 'prod.products.write'],
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
            ['POST', '/planning/week/move', WeekController::class, 'move', 'prod.gantt.move'],
            ['POST', '/planning/week/distribute', WeekController::class, 'distribute', 'prod.gantt.move'],

            ['GET', '/kiosk/devices', ExecutionController::class, 'devices', 'prod.employees.write'],
            ['POST', '/kiosk/devices', ExecutionController::class, 'createDevice', 'prod.employees.write'],
            ['PATCH', '/kiosk/devices/{id:\d+}', ExecutionController::class, 'updateDevice', 'prod.employees.write'],
            ['PUT', '/kiosk/employees/{id:\d+}/credentials', ExecutionController::class, 'credentials', 'prod.employees.write'],
            ['GET', '/kiosk/employees', ExecutionController::class, 'employees', 'prod.employees.write'],
            ['GET', '/kiosk/employees/{id:\d+}/timesheet', ExecutionController::class, 'timesheet', 'prod.employees.write'],
            ['PATCH', '/kiosk/clocks/{id:\d+}', ExecutionController::class, 'correctClock', 'prod.employees.write'],
            ['POST', '/kiosk/clocks', ExecutionController::class, 'addClock', 'prod.employees.write'],
            ['GET', '/execution/kpis', ExecutionController::class, 'kpis', 'prod.kpis.read'],
            ['GET', '/execution/cockpit', ExecutionController::class, 'cockpit', 'prod.kpis.read'],
            ['POST', '/kiosk/pair', ExecutionController::class, 'pair', null],
            ['GET', '/kiosk/people', ExecutionController::class, 'people', 'kiosk'],
            ['POST', '/kiosk/identify', ExecutionController::class, 'identify', 'kiosk'],
            ['GET', '/kiosk/me', ExecutionController::class, 'me', 'kiosk_employee'],
            ['POST', '/kiosk/clock', ExecutionController::class, 'clock', 'kiosk_employee'],
            ['POST', '/kiosk/operations/{id:\d+}/start', ExecutionController::class, 'start', 'kiosk_employee'],
            ['POST', '/kiosk/operations/{id:\d+}/pause', ExecutionController::class, 'pause', 'kiosk_employee'],
            ['POST', '/kiosk/operations/{id:\d+}/finish', ExecutionController::class, 'finish', 'kiosk_employee'],
            ['POST', '/kiosk/signatures', ExecutionController::class, 'sign', 'kiosk_employee'],
            ['GET', '/kiosk/qr', ExecutionController::class, 'qr', 'kiosk'],
            ['GET', '/kiosk/phone/people', ExecutionController::class, 'phonePeople', 'qr'],
            ['POST', '/kiosk/phone/enroll', ExecutionController::class, 'enrollPhone', 'qr'],
            ['GET', '/kiosk/phone/me', ExecutionController::class, 'phoneMe', 'phone'],
            ['POST', '/kiosk/phone/clock', ExecutionController::class, 'clock', 'phone'],
            ['POST', '/kiosk/phone/signatures', ExecutionController::class, 'sign', 'phone'],
            ['DELETE', '/kiosk/employees/{id:\d+}/phones', ExecutionController::class, 'revokePhones', 'prod.employees.write'],
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
