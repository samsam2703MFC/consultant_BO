<?php
declare(strict_types=1);

namespace App\Core\Routes\Api;

use App\Controllers\Api\Workshop\AuthController;
use App\Controllers\Api\Workshop\EmployeeController;
use App\Controllers\Api\Workshop\GroupController;
use App\Controllers\Api\Workshop\OpenApiController;
use App\Controllers\Api\Workshop\ParamController;
use App\Controllers\Api\Workshop\ShiftTemplateController;
use App\Controllers\Api\Workshop\TeamController;
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
