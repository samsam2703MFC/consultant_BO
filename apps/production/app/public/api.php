<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . "/../App/Core/init.php";

DEBUG ? ini_set('display_errors', 1) : ini_set('display_errors', 0);

// bootstrap DI container
$container = require __DIR__ . '/../App/Core/bootstrap.php';

use App\Core\Routes\ApiRoutes;
use FastRoute\Dispatcher;

$dispatcher = ApiRoutes::getDispatcher();

$httpMethod = $_SERVER['REQUEST_METHOD'];

// 1) bierz REDIRECT_URL jeśli jest, inaczej path z REQUEST_URI
$uri = $_SERVER['REDIRECT_URL'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// 2) znormalizuj wielokrotne ukośniki
$uri = preg_replace('#/{2,}#', '/', $uri);

// 3) jeśli ktoś trafi w /api.php/coś, utnij prefix skryptu
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if ($scriptName !== '' && str_starts_with($uri, $scriptName)) {
    $uri = substr($uri, strlen($scriptName));
    $uri = $uri === '' ? '/' : $uri;
}

// 4) kosmetyka
$uri = '/' . ltrim($uri, '/');

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);

switch ($routeInfo[0]) {
    case Dispatcher::FOUND:
        [$_, $handler, $vars] = $routeInfo;
        $controllerFQCN = $handler['controller'];
        $method         = $handler['method'];

        /* Device authentication is intentionally disabled for the internal API.
        if (($handler['auth'] ?? null) === 'device') {
            $deviceId = trim((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
            $apiKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
            if ($deviceId === '' || $apiKey === '') {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode(['status' => 'error', 'description' => 'MISSING_DEVICE_CREDENTIALS']);
                exit;
            }
            try {
                $authenticator = $container->get(\App\Services\Api\InternalDeviceAuthenticator::class);
                \App\Core\Api\InternalApiContext::setDevice($authenticator->authenticate($_SERVER));
            } catch (\App\Core\Api\InternalApiAuthenticationException $exception) {
                header('Content-Type: application/json');
                http_response_code($exception->httpStatus());
                echo json_encode(['status' => 'error', 'description' => $exception->getMessage()]);
                exit;
            } catch (Throwable $exception) {
                error_log('[internal-api] authentication failure: ' . $exception->getMessage());
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['status' => 'error', 'description' => 'AUTHENTICATION_FAILED']);
                exit;
            }
        }
        */

        try {
            $controller = $container->get($controllerFQCN);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Dependency injection failed']);
            exit;
        }

        if (method_exists($controller, $method)) {
            $result = call_user_func_array([$controller, $method], $vars);

            if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                $result->send();
            } else {
                header('Content-Type: application/json');
                echo json_encode($result);
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Method not found in controller']);
        }

        break;

    case Dispatcher::METHOD_NOT_ALLOWED:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;

    case Dispatcher::NOT_FOUND:
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        break;
}
