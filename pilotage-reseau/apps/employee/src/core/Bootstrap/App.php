<?php
namespace App\Employee\core\Bootstrap;

use App\Employee\app\Http\Controllers\Auth\AuthController;

//require_once 'vendor/autoload.php';
use App\Employee\app\Http\Middleware\AuthMiddleware;
use Exception;
use FastRoute;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use function FastRoute\simpleDispatcher;


class App {
    private $namespace = '';
    private $controller = 'Auth';
    private $method = 'index';

    private array $publicControllers = [
        AuthController::class,
    ];

    public function __construct(
        private ContainerInterface $container,
        private AuthMiddleware $middleware)
    {}

    private function splitURL() {

        $URL = $_GET['url'] ?? 'auth';
        $URL = explode("/", trim($URL, "/"));
        return $URL;
    }


    public function loadController()
    {
        $uri    = '/' . trim($_GET['url'] ?? 'auth', '/');
        $method = $_SERVER['REQUEST_METHOD'];

        // 1) Ustaw dispatcher
        $dispatcher = simpleDispatcher(
            require __DIR__ . '/routes.php'
        );

        // 2) Spróbuj dopasować przez router
        $routeInfo = $dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                break; // idziemy do fallbacku
            case Dispatcher::METHOD_NOT_ALLOWED:
                header('HTTP/1.1 405 Method Not Allowed');
                exit;
            case Dispatcher::FOUND:
                [$_, $handler, $vars] = $routeInfo;
                // $handler to ['controller'=>..., 'method'=>...]
                $controllerFQCN = $handler['controller'];
                $action         = $handler['method'];
                // Wciąż możesz wstrzyknąć ServiceFactory

                try {
                    // $container zawiera zarówno ServiceFactory jak i wszystkie nowe serwisy
                    $controller = $this->container->get($controllerFQCN);
                } catch (Exception $e) {
                    http_response_code(500);
                    echo "Błąd DI: " . $e->getMessage();
                    exit;
                }

                // jeżeli potrzebujesz middleware:
                if (!in_array($controllerFQCN, $this->publicControllers, true)) {
                    $this->middleware->handle();
                }
                // wywołaj z parametrami z {shopId}, {employeeId}
                $result = call_user_func_array([$controller, $action], $vars);

                if ($result instanceof Response) {
                    $result->send();
                    exit;
                }

                return;
        }
    }
}