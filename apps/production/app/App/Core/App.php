<?php
namespace App\Core;

use FastRoute\Dispatcher;

class App
{
	private $controller = 'Home';
	private $method 	= 'index';

	/**
	 * Licznik stron (Ustawienia › Licznik stron) : jedno wyświetlenie strony na trasę z parametrami
	 * zastąpionymi nazwami (/products/{id}/edit), dzień i użytkownika. Błąd licznika nie zatrzymuje strony.
	 */
	private function countPageView($container, string $uri, array $vars): void
	{
		try {
			$segments = explode('/', $uri);
			foreach ($segments as &$segment) {
				$name = array_search($segment, array_map('strval', $vars), true);
				if ($name !== false && $segment !== '') $segment = '{' . $name . '}';
			}
			$route = implode('/', $segments) ?: '/';
			$container->get(\App\Repositories\Settings\PageViewRepository::class)->hit($route, $uri, (int) (user('id') ?? 0));
		} catch (\Throwable $e) {
			error_log('[page-view] ' . $e->getMessage());
		}
	}

	private function splitURL()
	{
		$URL = $_GET['url'] ?? 'login';
		$URL = explode("/", trim($URL,"/"));
		return $URL;	
	}

    public function loadController()
    {
        $tContainer = microtime(true);
        $container = require __DIR__ . '/bootstrap.php';
        erp_timing('container', $tContainer);

        // Pobieranie URI z `$_GET['url']` lub domyślnie "/"
        $uri = isset($_GET['url']) ? '/' . trim($_GET['url'], '/') : '/';

        $method = $_SERVER['REQUEST_METHOD'];

        // Bez zalogowania dostępne są tylko logowanie (GET/POST /), wylogowanie i ekran tabletu
        // produkcji (/kiosk, /kiosk/scan telefonu : sama powłoka, dane wyłącznie przez API z tokenem tabletu
        // albo kodem QR z tabletu).
        if (empty($_SESSION['USER']) && !in_array($uri, ['/', '/logout', '/kiosk', '/kiosk/scan'], true)) {
            if (str_starts_with($uri, '/ajax/')) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['message' => 'Unauthorized']);
                return;
            }
            header('Location: ' . ROOT . '/');
            return;
        }

        // Wywołania w tle, które sesję tylko czytają (dzwonek, liczniki, wyszukiwarka, token pracowni) :
        // blokada pliku sesji zwalniana od razu — inaczej równoległe wywołania strony czekają jedno na drugie.
        if (in_array($uri, ['/ajax/app-notifications', '/ajax/rail-counters', '/ajax/search', '/ajax/workshop/token'], true)
            && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $dispatcher = \FastRoute\simpleDispatcher(
            require __DIR__ . '/Routes/routes.php'
        );

        $routeInfo = $dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::FOUND:
                [$_, $handler, $vars] = $routeInfo;

                // Strona zastąpiona inną (audyt : jedna strona zamiast dwóch) : przekierowanie, zapytanie zachowane.
                if (isset($handler['redirect'])) {
                    $target = $handler['redirect'];
                    foreach ($vars as $k => $v) $target = str_replace('{' . $k . '}', rawurlencode((string) $v), $target);
                    $query = $_GET;
                    unset($query['url']);
                    header('Location: ' . ROOT . $target . ($query ? (str_contains($target, '?') ? '&' : '?') . http_build_query($query) : ''), true, 302);
                    return;
                }

                $controllerFQCN = $handler['controller'];
                $action         = $handler['method'];

                if ($method === 'GET' && !str_starts_with($uri, '/ajax/') && !empty($_SESSION['USER'])) {
                    $this->countPageView($container, $uri, $vars);
                }

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
                        $result->headers->set('Server-Timing', erp_server_timing());
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
            // Jeśli to żądanie AJAX / API — zwróć 404 w JSON ($uri bez podkatalogu aplikacji)
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


