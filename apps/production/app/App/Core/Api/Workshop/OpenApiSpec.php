<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Services\Workshop\Referentiel\WorkstationService;

/**
 * Description OpenAPI 3.0 de l'API du module atelier, construite à partir de la table des
 * routes (une route sans description fait échouer le test tfb_openapi_test).
 * Servie à GET /api/v1/production/openapi.json et copiée dans docs/api/production-v1.openapi.json.
 */
final class OpenApiSpec
{
    public static function build(string $apiBaseUrl): array
    {
        $paths = [];
        foreach (WorkshopApiRoutes::routes() as [$method, $path, , , $permission]) {
            $doc = (self::operations() + OpenApiProducts::operations() + OpenApiPlanning::operations())[$method . ' ' . $path] ?? null;
            if ($doc === null) {
                throw new \LogicException("Undocumented route {$method} {$path}");
            }
            $openPath = preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $path);
            $operation = [
                'tags' => [$doc['tag']],
                'summary' => $doc['summary'],
                'operationId' => $doc['id'],
                'responses' => self::responses($doc, $permission),
            ];
            if (!empty($doc['description'])) $operation['description'] = $doc['description'];
            $parameters = [];
            if (preg_match_all('/\{(\w+)(?::[^}]+)?\}/', $path, $m)) {
                foreach ($m[1] as $name) {
                    $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true,
                        'schema' => $name === 'code' ? ['type' => 'string', 'example' => 'load.target_pct'] : ['type' => 'integer', 'minimum' => 1]];
                }
            }
            foreach ($doc['query'] ?? [] as $name => $schema) {
                $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema];
            }
            $parameters[] = ['$ref' => '#/components/parameters/Lang'];
            $operation['parameters'] = $parameters;
            if (isset($doc['body'])) {
                $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $doc['body']]]]];
            }
            $operation['security'] = $permission === null ? [] : [['bearerAuth' => []], ['accessTokenHeader' => []]];
            if ($permission !== null && $permission !== 'auth') {
                $operation['x-permission'] = $permission;
            }
            $paths[$openPath][strtolower($method)] = $operation;
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'TFB — API produkcji / API production atelier',
                'version' => '1.0.0',
                'description' => "Moduł zarządzania produkcją w pracowni centralnej (Wrocław).\n"
                    . "Module de gestion de production de l'atelier central (Wrocław).\n\n"
                    . "Authentification : POST /auth/login renvoie un jeton d'accès (JWT, court) et un jeton de renouvellement "
                    . "(usage unique, rotation). Envoyez le jeton d'accès dans « Authorization: Bearer … », ou dans "
                    . "« X-Access-Token » si Authorization est déjà utilisé par un mot de passe navigateur.\n"
                    . "Langue des messages : en-tête Accept-Language ou ?lang=pl|fr (polonais par défaut).",
            ],
            'servers' => [$apiBaseUrl === '' ? [
                'url' => '{apiBaseUrl}' . WorkshopApiRoutes::PREFIX,
                'variables' => ['apiBaseUrl' => ['default' => 'https://erp.example', 'description' => 'API_BASE_URL (.env)']],
            ] : ['url' => rtrim($apiBaseUrl, '/') . WorkshopApiRoutes::PREFIX]],
            'tags' => [
                ['name' => 'auth', 'description' => 'Logowanie / Connexion'],
                ['name' => 'referentiels', 'description' => 'Grupy, stanowiska, zmiany, zespoły, pracownicy, parametry / Groupes, postes, shifts, équipes, employés, paramètres'],
                ['name' => 'produits', 'description' => 'Karty produkcyjne, marszruty, czasy standardowe, pomiary / Fiches de production, gammes, temps standards, chronométrage'],
                ['name' => 'planning', 'description' => 'Zapotrzebowanie i plan produkcji / Besoins et plan de production'],
                ['name' => 'docs', 'description' => 'OpenAPI'],
            ],
            'paths' => $paths,
            'components' => self::components(),
        ];
    }

    private static function responses(array $doc, ?string $permission): array
    {
        $ok = $doc['ok'] ?? 200;
        $responses = [
            (string) $ok => ['description' => 'OK', 'content' => ['application/json' => ['schema' => self::envelope($doc['response'], $doc['list'] ?? false)]]],
        ];
        foreach ($doc['errors'] ?? [] as $code) {
            $responses[(string) $code] = ['$ref' => '#/components/responses/Error' . $code];
        }
        if ($permission !== null) {
            $responses['401'] = ['$ref' => '#/components/responses/Error401'];
            if ($permission !== 'auth') $responses['403'] = ['$ref' => '#/components/responses/Error403'];
        }
        $responses['500'] = ['$ref' => '#/components/responses/Error500'];
        ksort($responses);
        return $responses;
    }

    private static function envelope(string $schema, bool $list): array
    {
        $data = ['$ref' => '#/components/schemas/' . $schema];
        $properties = [
            'status' => ['type' => 'string', 'enum' => ['ok']],
            'data' => $list ? ['type' => 'array', 'items' => $data] : $data,
        ];
        if ($list) {
            $properties['meta'] = ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']]];
        }
        return ['type' => 'object', 'required' => ['status', 'data'], 'properties' => $properties];
    }

    /** Une entrée par route : tag, résumé, corps, réponse, erreurs possibles. */
    private static function operations(): array
    {
        $crud = function (string $base, string $schema, string $input, string $tagLabel, array $query = []): array {
            return [
                "GET {$base}" => ['tag' => 'referentiels', 'id' => "list{$schema}s", 'summary' => "Lista / Liste — {$tagLabel}", 'response' => $schema, 'list' => true, 'query' => $query + ['active' => ['type' => 'boolean']], 'errors' => [422]],
                "POST {$base}" => ['tag' => 'referentiels', 'id' => "create{$schema}", 'summary' => "Utwórz / Créer — {$tagLabel}", 'body' => $input, 'response' => $schema, 'ok' => 201, 'errors' => [400, 409, 422]],
                "GET {$base}/{id:\d+}" => ['tag' => 'referentiels', 'id' => "get{$schema}", 'summary' => "Szczegóły / Détail — {$tagLabel}", 'response' => $schema, 'errors' => [404]],
                "PATCH {$base}/{id:\d+}" => ['tag' => 'referentiels', 'id' => "update{$schema}", 'summary' => "Zmień / Modifier — {$tagLabel} (częściowo / partiel)", 'body' => $input, 'response' => $schema, 'errors' => [400, 404, 409, 422]],
            ];
        };
        return [
            'GET /openapi.json' => ['tag' => 'docs', 'id' => 'openapi', 'summary' => 'Opis OpenAPI / Description OpenAPI', 'response' => 'OpenApiDocument'],
            'POST /auth/login' => ['tag' => 'auth', 'id' => 'login', 'summary' => 'Logowanie kontem ERP / Connexion avec un compte de l\'ERP', 'body' => 'LoginInput', 'response' => 'Tokens', 'errors' => [400, 401, 403, 422, 429, 503],
                'description' => 'Le compte doit avoir au moins un rôle du module (tfb_prod_user_role). Après auth.max_failed_logins échecs en auth.lockout_window_min minutes : 429.'],
            'POST /auth/refresh' => ['tag' => 'auth', 'id' => 'refresh', 'summary' => 'Odśwież tokeny / Renouveler les jetons', 'body' => 'RefreshInput', 'response' => 'Tokens', 'errors' => [400, 401, 422, 503],
                'description' => 'Usage unique : le jeton présenté est remplacé. Un jeton déjà utilisé qui revient révoque toute sa famille.'],
            'POST /auth/logout' => ['tag' => 'auth', 'id' => 'logout', 'summary' => 'Wyloguj / Déconnexion (révoque le jeton de renouvellement)', 'body' => 'RefreshInput', 'response' => 'LogoutResult', 'errors' => [400, 422]],
            'GET /auth/me' => ['tag' => 'auth', 'id' => 'me', 'summary' => 'Bieżący użytkownik, role i uprawnienia / Utilisateur courant, rôles et permissions', 'response' => 'Me'],
        ]
        + $crud('/referentiels/groups', 'Group', 'GroupInput', 'grupy produkcyjne / groupes de production')
        + $crud('/referentiels/workstations', 'Workstation', 'WorkstationInput', 'stanowiska / postes de travail',
            ['group_id' => ['type' => 'integer'], 'type' => ['type' => 'string', 'enum' => WorkstationService::TYPES]])
        + $crud('/referentiels/shift-templates', 'ShiftTemplate', 'ShiftTemplateInput', 'zmiany / modèles de shift')
        + $crud('/referentiels/teams', 'Team', 'TeamInput', 'zespoły / équipes',
            ['group_id' => ['type' => 'integer'], 'shift_template_id' => ['type' => 'integer']])
        + [
            'GET /referentiels/teams/{id:\d+}/members' => ['tag' => 'referentiels', 'id' => 'listTeamMembers', 'summary' => 'Członkowie zespołu / Membres de l\'équipe', 'response' => 'TeamMember', 'list' => true, 'errors' => [404]],
            'PUT /referentiels/teams/{id:\d+}/members' => ['tag' => 'referentiels', 'id' => 'replaceTeamMembers', 'summary' => 'Zastąp członków / Remplacer les membres', 'body' => 'TeamMembersInput', 'response' => 'TeamMember', 'list' => true, 'errors' => [400, 404, 422]],
            'GET /referentiels/employees' => ['tag' => 'referentiels', 'id' => 'listEmployees', 'summary' => 'Pracownicy pracowni / Employés de l\'atelier', 'response' => 'Employee', 'list' => true, 'query' => ['group_id' => ['type' => 'integer'], 'active' => ['type' => 'boolean']], 'errors' => [422]],
            'GET /referentiels/employees/{id:\d+}' => ['tag' => 'referentiels', 'id' => 'getEmployee', 'summary' => 'Pracownik (id ERP) / Employé (id ERP)', 'response' => 'Employee', 'errors' => [404]],
            'PATCH /referentiels/employees/{id:\d+}' => ['tag' => 'referentiels', 'id' => 'updateEmployee', 'summary' => 'Profil produkcyjny pracownika / Profil de production d\'un employé', 'body' => 'EmployeeInput', 'response' => 'Employee', 'errors' => [400, 404, 422]],
            'GET /referentiels/params' => ['tag' => 'referentiels', 'id' => 'listParams', 'summary' => 'Parametry [PARAM] / Paramètres [PARAM]', 'response' => 'Param', 'list' => true],
            'GET /referentiels/params/{code:[a-z0-9_.]+}' => ['tag' => 'referentiels', 'id' => 'getParam', 'summary' => 'Parametr / Paramètre', 'response' => 'Param', 'errors' => [404]],
            'PATCH /referentiels/params/{code:[a-z0-9_.]+}' => ['tag' => 'referentiels', 'id' => 'updateParam', 'summary' => 'Zmień wartość parametru / Modifier un paramètre', 'body' => 'ParamInput', 'response' => 'Param', 'errors' => [400, 404, 422]],
        ];
    }

    private static function components(): array
    {
        $str = fn(int $max, array $extra = []) => ['type' => 'string', 'maxLength' => $max] + $extra;
        $code = ['type' => 'string', 'pattern' => '^[A-Z0-9_]{2,32}$', 'example' => 'VIENNOISERIE'];
        $ts = ['type' => 'string', 'example' => '2026-10-19 14:58:04'];
        $nullableInt = ['type' => 'integer', 'nullable' => true];
        $errorResponses = [];
        foreach ([400 => 'INVALID_JSON', 401 => 'UNAUTHENTICATED / TOKEN_EXPIRED / TOKEN_INVALID / INVALID_CREDENTIALS / REFRESH_INVALID', 403 => 'FORBIDDEN / NO_PRODUCTION_ROLE',
                  404 => 'NOT_FOUND / ROUTE_NOT_FOUND', 409 => 'DUPLICATE_CODE / ITEM_EXISTS / NOT_ENOUGH_MEASUREMENTS / PLAN_NOT_EDITABLE / PLAN_OVERLAP / PLAN_NOT_VALIDATED', 422 => 'VALIDATION_FAILED', 429 => 'TOO_MANY_ATTEMPTS',
                  500 => 'INTERNAL_ERROR', 503 => 'AUTH_NOT_CONFIGURED'] as $status => $codes) {
            $errorResponses['Error' . $status] = ['description' => $codes, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }

        return [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                'accessTokenHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Access-Token'],
            ],
            'parameters' => [
                'Lang' => ['name' => 'lang', 'in' => 'query', 'required' => false, 'description' => 'Język komunikatów / Langue des messages (sinon Accept-Language)', 'schema' => ['type' => 'string', 'enum' => ['pl', 'fr']]],
            ],
            'responses' => $errorResponses,
            'schemas' => OpenApiProducts::schemas() + OpenApiPlanning::schemas() + [
                'Error' => ['type' => 'object', 'required' => ['status', 'description', 'message', 'request_id'], 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['error']],
                    'description' => ['type' => 'string', 'example' => 'VALIDATION_FAILED'],
                    'message' => ['type' => 'string', 'example' => 'Nieprawidłowe dane — popraw zaznaczone pola.'],
                    'request_id' => ['type' => 'string'],
                    'errors' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FieldError']],
                ]],
                'FieldError' => ['type' => 'object', 'required' => ['field', 'code', 'message'], 'properties' => [
                    'field' => ['type' => 'string', 'example' => 'robot_mode'],
                    'code' => ['type' => 'string', 'example' => 'ROBOT_MODE_REQUIRED'],
                    'message' => ['type' => 'string'],
                ]],
                'OpenApiDocument' => ['type' => 'object', 'additionalProperties' => true],
                'LoginInput' => ['type' => 'object', 'required' => ['login', 'password'], 'additionalProperties' => false, 'properties' => [
                    'login' => $str(255), 'password' => ['type' => 'string', 'format' => 'password'],
                ]],
                'RefreshInput' => ['type' => 'object', 'required' => ['refresh_token'], 'additionalProperties' => false, 'properties' => ['refresh_token' => $str(255)]],
                'Tokens' => ['type' => 'object', 'properties' => [
                    'access_token' => ['type' => 'string'], 'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
                    'expires_in' => ['type' => 'integer', 'example' => 900], 'refresh_token' => ['type' => 'string'],
                    'refresh_expires_in' => ['type' => 'integer', 'example' => 2592000],
                ]],
                'LogoutResult' => ['type' => 'object', 'properties' => ['logged_out' => ['type' => 'boolean']]],
                'Me' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'login' => ['type' => 'string'], 'display_name' => ['type' => 'string'],
                    'roles' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'group_id' => $nullableInt]]],
                    'permissions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]],
                'Group' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'code' => $code, 'name' => ['type' => 'string', 'description' => 'name_pl ou name_fr selon la langue'],
                    'name_pl' => ['type' => 'string'], 'name_fr' => ['type' => 'string'], 'color' => ['type' => 'string', 'example' => '#8D1D2C'],
                    'sort_order' => ['type' => 'integer'], 'section_id' => $nullableInt + ['description' => 'production_sections.id (koszty / coûts)'],
                    'active' => ['type' => 'boolean'], 'created_at' => $ts, 'updated_at' => $ts,
                ]],
                'GroupInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'name_pl', 'name_fr'], 'properties' => [
                    'code' => $code, 'name_pl' => $str(100), 'name_fr' => $str(100), 'color' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'],
                    'sort_order' => ['type' => 'integer', 'minimum' => 0], 'section_id' => $nullableInt, 'active' => ['type' => 'boolean'],
                ]],
                'Workstation' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'code' => $code, 'group_id' => ['type' => 'integer'], 'name' => ['type' => 'string'],
                    'name_pl' => ['type' => 'string'], 'name_fr' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => WorkstationService::TYPES],
                    'robot_mode' => ['type' => 'string', 'enum' => WorkstationService::ROBOT_MODES, 'nullable' => true],
                    'night_compatible' => ['type' => 'boolean', 'description' => 'robot autonome uniquement'],
                    'capacity_per_pass' => ['type' => 'number'], 'capacity_unit' => ['type' => 'string', 'example' => 'plaque'],
                    'hourly_cost' => ['type' => 'number'], 'photo_url' => ['type' => 'string', 'nullable' => true, 'description' => 'lien direct Cloudflare R2'],
                    'notes' => ['type' => 'string', 'nullable' => true], 'active' => ['type' => 'boolean'],
                    'shift_availability' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'shift_template_id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'available' => ['type' => 'boolean'],
                    ]]],
                    'created_at' => $ts, 'updated_at' => $ts,
                ]],
                'WorkstationInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'group_id', 'name_pl', 'name_fr', 'type'], 'properties' => [
                    'code' => $code, 'group_id' => ['type' => 'integer'], 'name_pl' => $str(100), 'name_fr' => $str(100),
                    'type' => ['type' => 'string', 'enum' => WorkstationService::TYPES],
                    'robot_mode' => ['type' => 'string', 'enum' => WorkstationService::ROBOT_MODES, 'nullable' => true, 'description' => 'obligatoire si type = robot, interdit sinon'],
                    'capacity_per_pass' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0],
                    'capacity_unit' => ['type' => 'string', 'description' => 'une des valeurs du paramètre workstation.capacity_units'],
                    'hourly_cost' => ['type' => 'number', 'minimum' => 0], 'photo_url' => ['type' => 'string', 'nullable' => true, 'format' => 'uri'],
                    'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true], 'active' => ['type' => 'boolean'],
                    'shift_availability' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['shift_template_id', 'available'], 'properties' => [
                        'shift_template_id' => ['type' => 'integer'], 'available' => ['type' => 'boolean'],
                    ]]],
                ]],
                'ShiftTemplate' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'code' => $code, 'name' => ['type' => 'string'], 'name_pl' => ['type' => 'string'], 'name_fr' => ['type' => 'string'],
                    'start_time' => ['type' => 'string', 'example' => '22:00'], 'end_time' => ['type' => 'string', 'example' => '06:00'],
                    'crosses_midnight' => ['type' => 'boolean'], 'duration_minutes' => ['type' => 'integer', 'example' => 480],
                    'active_days' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]],
                    'premium_pct' => ['type' => 'number', 'example' => 20], 'is_night' => ['type' => 'boolean'],
                    'sort_order' => ['type' => 'integer'], 'active' => ['type' => 'boolean'], 'created_at' => $ts, 'updated_at' => $ts,
                ]],
                'ShiftTemplateInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'name_pl', 'name_fr', 'start_time', 'end_time'], 'properties' => [
                    'code' => $code, 'name_pl' => $str(100), 'name_fr' => $str(100),
                    'start_time' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'], 'end_time' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'],
                    'active_days' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]],
                    'premium_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 200], 'is_night' => ['type' => 'boolean'],
                    'sort_order' => ['type' => 'integer'], 'active' => ['type' => 'boolean'],
                ]],
                'Team' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'code' => $code, 'group_id' => ['type' => 'integer'], 'shift_template_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'], 'planned_headcount' => ['type' => 'integer'], 'member_count' => ['type' => 'integer'],
                    'active' => ['type' => 'boolean'], 'created_at' => $ts, 'updated_at' => $ts,
                ]],
                'TeamInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'group_id', 'shift_template_id', 'name'], 'properties' => [
                    'code' => $code, 'group_id' => ['type' => 'integer'], 'shift_template_id' => ['type' => 'integer'], 'name' => $str(100),
                    'planned_headcount' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500], 'active' => ['type' => 'boolean'],
                ]],
                'TeamMember' => ['type' => 'object', 'properties' => [
                    'production_employee_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'surname' => ['type' => 'string'],
                    'is_lead' => ['type' => 'boolean'], 'valid_from' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    'valid_to' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    'level_order' => $nullableInt, 'level_name' => ['type' => 'string', 'nullable' => true],
                ]],
                'TeamMembersInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['members'], 'properties' => [
                    'members' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['production_employee_id'], 'properties' => [
                        'production_employee_id' => ['type' => 'integer'], 'is_lead' => ['type' => 'boolean'],
                        'valid_from' => ['type' => 'string', 'format' => 'date', 'nullable' => true], 'valid_to' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    ]]],
                ]],
                'Employee' => ['type' => 'object', 'properties' => [
                    'production_employee_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'surname' => ['type' => 'string'],
                    'section_id' => $nullableInt, 'has_profile' => ['type' => 'boolean'], 'panel_employee_id' => $nullableInt,
                    'primary_group_id' => $nullableInt, 'level_order' => $nullableInt, 'level_name' => ['type' => 'string', 'nullable' => true],
                    'level_synced_at' => ['type' => 'string', 'nullable' => true], 'night_supervisor' => ['type' => 'boolean'], 'active' => ['type' => 'boolean'],
                ]],
                'EmployeeInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'panel_employee_id' => $nullableInt, 'primary_group_id' => $nullableInt,
                    'level_order' => $nullableInt + ['minimum' => 0, 'maximum' => 100], 'level_name' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                    'night_supervisor' => ['type' => 'boolean'], 'active' => ['type' => 'boolean'],
                ]],
                'Param' => ['type' => 'object', 'properties' => [
                    'code' => ['type' => 'string', 'example' => 'load.target_pct'], 'value' => ['description' => 'typé selon value_type'],
                    'default_value' => ['description' => 'typé selon value_type'], 'is_default' => ['type' => 'boolean'],
                    'value_type' => ['type' => 'string', 'enum' => ['int', 'decimal', 'string', 'bool', 'time', 'json']],
                    'unit' => ['type' => 'string', 'nullable' => true], 'description' => ['type' => 'string'],
                    'description_pl' => ['type' => 'string'], 'description_fr' => ['type' => 'string'], 'updated_at' => $ts,
                ]],
                'ParamInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['value'], 'properties' => [
                    'value' => ['description' => 'nombre, texte, booléen, heure HH:MM ou JSON selon value_type'],
                ]],
            ],
        ];
    }
}
