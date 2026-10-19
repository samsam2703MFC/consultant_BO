<?php
namespace App\Core;

use FastRoute\Dispatcher;

class App
{
	private $controller = 'Home';
	private $method 	= 'index';

	private function splitURL()
	{
		$URL = $_GET['url'] ?? 'login';
		$URL = explode("/", trim($URL,"/"));
		return $URL;	
	}

    public function loadController()
    {
        $container = require __DIR__ . '/bootstrap.php';

        // Pobieranie URI z `$_GET['url']` lub domyślnie "/"
        $uri = isset($_GET['url']) ? '/' . trim($_GET['url'], '/') : '/';

        $method = $_SERVER['REQUEST_METHOD'];

        // Bez zalogowania dostępne są tylko logowanie (GET/POST /) i wylogowanie
        if (empty($_SESSION['USER']) && !in_array($uri, ['/', '/logout'], true)) {
            if (str_starts_with($uri, '/ajax/')) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['message' => 'Unauthorized']);
                return;
            }
            header('Location: ' . ROOT . '/');
            return;
        }

        $dispatcher = \FastRoute\simpleDispatcher(
            require __DIR__ . '/Routes/routes.php'
        );

        $routeInfo = $dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::FOUND:
                [$_, $handler, $vars] = $routeInfo;
                $controllerFQCN = $handler['controller'];
                $action         = $handler['method'];

                try {
                    $controller = $container->get($controllerFQCN);
                } catch (Exception $e) {
                    http_response_code(500);
                    echo "Błąd DI: " . $e->getMessage();
                    exit;
                }

                if (method_exists($controller, $action)) {
                    $result = call_user_func_array([$controller, $action], $vars);

                    if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                        $result->send();
                    } else {
                        echo $result;
                    }
                    return;
                }

                // brak metody – przechodzimy do starszego mechanizmu
                break;

            case Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                echo "405 Method Not Allowed";
                return;

            case Dispatcher::NOT_FOUND:
            default:
            $uri = $_SERVER['REQUEST_URI'] ?? '';

            // Jeśli to żądanie AJAX / API — zwróć 404 w JSON
            if (str_starts_with($uri, '/ajax/')) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['message' => 'Endpoint not found']);
                return;
            }


            // Nieznany adres (użytkownik jest zalogowany) — wracamy na dashboard
            header('Location: ' . ROOT . '/dashboard');
            return;

//            http_response_code(404);
//            echo "404 Not Found";
            return;
                break;
        }

//        // Starszy mechanizm: fallback, np. Home/index
//        $URL = $this->splitURL();
//
//        $filename = "../App/Controllers/" . ucfirst($URL[0]) . ".php";
//
//        if (file_exists($filename)) {
//            require $filename;
//            $this->controller = ucfirst($URL[0]);
//            unset($URL[0]);
//        } else {
//            $filename = "../App/Controllers/_404.php";
//            require $filename;
//            $this->controller = "_404";
//        }
//
//        $controller = new $this->controller;
//
//        if (!empty($URL[1]) && method_exists($controller, $URL[1])) {
//            $this->method = $URL[1];
//            unset($URL[1]);
//        }
//
//        $result = call_user_func_array([$controller, $this->method], $URL);
//
//        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
//            $result->send();
//        } else {
//            echo $result;
//        }
    }
}


